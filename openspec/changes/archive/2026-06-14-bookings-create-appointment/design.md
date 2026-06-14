# Design — Appointment Create

**status: draft**

## Context

The Nextcloud Bookings app enables customers to book services (e.g., consultations, classes, room rentals) through a calendar-based interface. The app currently has:

- `bookings-resource-calendar` — Resource register managing physical/virtual resources and their availability calendars
- `bookings-service-catalog` — Service register defining services, durations, pricing, and availability rules

What is missing: a unified interface for creating appointments that serves three personas:

1. **Admin** — scheduling appointments directly for staff workflows or on-behalf-of customers
2. **Customer** — self-booking via a public portal using available slots
3. **System** — creating appointments via REST API for integrations (CRM sync, marketing platform, webhook handlers)

This change defines the `Appointment` register and the canonical create flow, integrating with the two upstream dependencies.

## Goals

- **Single source of truth** — one `Appointment` schema, not three separate create flows that diverge over time
- **Validation before write** — check service accessibility, slot availability, and compliance with business rules before persisting
- **Customer-safe design** — customer self-service cannot over-book, double-book, or book at times the service is unavailable
- **Admin speed** — admin flow supports rapid bulk entry for staff scheduling
- **Integration-ready** — REST API surface allows third-party systems (Zapier, CRM webhooks) to create appointments without UI access

## Non-Goals

- **Cancellation, rescheduling** — phase 2 spec
- **Availability computation** — delegated to phase 2; phase 1 assumes slots are pre-selected and validated by the frontend
- **Notifications** — phase 3
- **Custom fields** — phase 2+; phase 1 ships fixed fields (startTime, endTime, notes, customerId, serviceId, resourceId)

## Decisions

### D1 — Appointment as an OpenRegister-managed Entity (per ADR-031)

**Decision**: Declare `Appointment` as a register in `lib/Settings/bookings_register.json` with full OR CRUD exposure.

**Why**: Consistent with phase 1 patterns (chart-of-accounts, invoices). Avoids app-local database tables, leverages OR's audit trail and relation engine. Reduces boilerplate.

**Alternative**: Custom `Appointment` PHP model + migration. Rejected — violates ADR-031 (prefer declarative).

### D2 — Appointment Statuses as Enum, Not State Machine (Phase 1)

**Decision**: `status` field is a simple enum: `pending_confirmation`, `confirmed`, `completed`, `cancelled`. No formal `x-openregister-lifecycle` block in phase 1.

**Why**: Simplifies initial implementation. State transitions are business-logic driven (e.g., "cancel if more than 24h before start"; "auto-confirm if no payment required"). These rules vary by business model and are out of scope for phase 1. Phase 2 can introduce `x-openregister-lifecycle` if transitions become frequent/standardized.

**Alternative**: Declare full lifecycle in phase 1. Rejected — adds guards/conditions that require business-rule input from personas (confirm with stakeholders first).

### D3 — Validate Slot Availability in the Service Layer, Not Schema

**Decision**: The create endpoint (`POST /appointments`) accepts slot parameters (startTime, endTime, resourceId, serviceId). Before write, the service layer checks:
1. Resource calendar has the slot available (no existing conflicting appointments)
2. Service duration matches the requested duration
3. Customer is not barred from booking this service

**Why**: Availability logic is context-dependent (e.g., "block off lunch hours"; "some services require pre-approval"). Expressing this in schema is limiting. Service-layer validation is clearer and testable.

**Alternative**: Pre-validate slot availability on the frontend, trust the frontend. Rejected — doesn't defend against API clients; D3 validates server-side.

### D4 — Appointments Link to Customers, Not Persons

**Decision**: Appointment.customerId references the `Customer` register (not the generic `Person` entity from ADR-000). Customer is a specialized person-with-contact-info and service-access rules.

**Why**: Bookings are customer-service relationships. Mixing generic persons + specialized customer records is error-prone. If the `Customer` register doesn't exist yet, it is created as a phase 1 prerequisite.

**Alternative**: Appointment.personId + separate customer contact table. Rejected — looser coupling, harder to enforce access rules.

### D5 — Durations Stored Separately, Computed at Create Time

**Decision**: Appointment stores `startTime` and `endTime` (epoch timestamps). Service.duration is separate. At create time, the service layer validates that `endTime - startTime == Service.duration` (or within tolerance for customization later).

**Why**: Decouples service changes from existing appointments. If a service duration changes, historical appointments are unaffected. Front-end and service layer independently compute available slots based on `Service.duration`.

**Alternative**: Store only `startTime` and look up duration from Service.duration at query time. Rejected — slower queries, brittle (if service is deleted, duration is unknown).

### D6 — Timezone Handling: Store UTC, Render Local

**Decision**: startTime and endTime are stored as ISO 8601 UTC strings. The frontend (Vue component) converts user-local time to UTC before POST; REST API clients are responsible for timezone conversion.

**Why**: UTC storage is the standard for distributed systems. No server-side timezone database needed. Customers' browsers handle their own local time.

**Alternative**: Server stores local time + timezone string (e.g., "2026-05-21T14:30:00 Europe/Amsterdam"). Rejected — requires server-side tzdata updates, adds complexity.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Appointment register CRUD | OpenRegister generic CRUD | Appointment register is defined in `lib/Settings/bookings_register.json` and exposed via OR's `GET/POST /ocs/v2.php/apps/openregister/api/objects/bookings/Appointment` endpoints |
| Relation to Service | `Service` register (from `bookings-service-catalog`) | Appointment.serviceId is a foreign key; OpenRegister's relation validator enforces referential integrity |
| Relation to Resource | `Resource` register (from `bookings-resource-calendar`) | Appointment.resourceId is a foreign key; same validation |
| Audit trail | OR audit-trail-immutable abstraction | Every appointment create/update/delete is logged with actor, before/after, timestamp, hash chain |
| Availability checking | `bookings-availability-slots` (phase 2) or frontend pre-computation | Phase 1 delegates to frontend; appointment create endpoint validates idempotently |
| Customer identity | `Customer` register (phase 1 prerequisite) | Appointment.customerId is a relation to Customer |
| Admin UI binding | Vue admin dashboard boilerplate | Standard admin layout; appointment create form is a new modal component |

## Seed Data

No seed data for appointments (appointments are created at runtime, not imported). Phase 1 assumes test fixtures are created in test suites.

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/bookings_register.json` is patched with the `Appointment` schema (additive)
2. `src/components/AppointmentCreate.vue` is added (admin interface)
3. `src/views/PortalBooking.vue` is added (customer self-service interface)
4. `lib/Controller/AppointmentApiController.php` is added (REST API)
5. Database migration creates indexes on `(resourceId, startTime, endTime)` for uniqueness and conflict detection
6. `src/manifest.json` is patched with one new navigation entry + modal binding

Down-direction: drop appointment register from manifest, keep records queryable (non-destructive).

## Open Questions

1. **Customer pre-auth in self-service** — when a customer accesses the portal and selects a slot, are they auto-logged-in, or do they authenticate? If unauthenticated booking is allowed, how is the appointment tied back to the customer? (Need product decision.)
2. **Payment integration** — should the create endpoint block until payment clears, or create a `pending_payment` status? (Out of scope for phase 1; phase 3 defines payment flow.)
3. **Notification trigger** — should creating an appointment automatically trigger an email to the customer, or is notification a separate step? (Phase 3 spec defines this; phase 1 does not include notification.)
4. **Recurring appointments** — can a single create request generate multiple appointments (e.g., "weekly for 4 weeks")? (Out of scope phase 1; phase 2+ handles recurring.)
5. **Resource overbooking** — can a single resource have overlapping appointments (e.g., a trainer coaching 2 people simultaneously)? (Needs business rule input before implementation.)

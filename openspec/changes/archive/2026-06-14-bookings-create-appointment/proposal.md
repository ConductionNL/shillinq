# Proposal: Booking Create Appointment

`kind: feature` — implement a unified appointment creation flow supporting admin, customer self-service, and REST API interfaces.

## Summary

Introduce the **unified appointment creation capability** for the Nextcloud Bookings app, enabling multiple user personas (administrators, customers, API clients) to create appointments through a single canonical flow. This change defines the `Appointment` register with validation rules, integrates with the existing resource calendar and service catalog (dependencies), and exposes create operations via:

1. Vue admin interface (admin-only CRUD)
2. Customer self-service portal (authenticated customer self-booking)
3. REST API (for third-party integrations)

This change conforms to the shared `nextcloud-app` spec for app structure and follows ADR-031 declarative patterns where applicable.

## Motivation

The Nextcloud Bookings app currently lacks a unified appointment creation surface. Competitor analysis (21/21 benchmarks) shows that multi-channel appointment booking (admin + customer + API) is table-stakes. Operators need:

- **Admin efficiency** — bulk or rapid appointment creation for staff scheduling
- **Customer self-service** — customers booking their own slots without operator intervention
- **System integration** — third-party systems (CRMs, marketing platforms, webhooks) creating appointments programmatically

Until appointment creation is unified, the app cannot serve production use cases.

This proposal is the **first of a multi-phase bookings feature set**:

1. `bookings-create-appointment` (this change) — core appointment creation, depends on `bookings-resource-calendar` and `bookings-service-catalog`
2. `bookings-appointment-cancellation` (phase 2) — cancellation, rescheduling, notifications
3. `bookings-availability-slots` (phase 2) — availability computation, conflict detection
4. `bookings-reminder-notifications` (phase 3) — email/SMS reminders

## Affected Projects

- [x] **Project: nextcloud-bookings** — adds 1 new register/schema (`Appointment`) to `lib/Settings/bookings_register.json`, adds admin UI in `src/components/AppointmentCreate.vue`, adds REST API endpoint `POST /ocs/v2.php/apps/bookings/api/v1/appointments`, adds test fixtures
- [x] **Project: openregister** — consumes existing OR abstractions (CRUD, validation, relations to `Service` and `Resource`); no new OR features required

## Scope

### In Scope

- **One new capability spec** (`bookings-create-appointment`) defining the `Appointment` entity, validation rules, and workflow states
- **Appointment register schema** — declares minimum fields (startTime, endTime, serviceId, customerId, notes, status, duration) with relations to `Service` and `Resource` via the existing service catalog and resource calendar
- **Admin Vue component** — `src/components/AppointmentCreate.vue` bound to the admin dashboard for staff appointment management
- **Customer self-service interface** — Portal-accessible form allowing customers to select service + time slot and create their own appointment
- **REST API endpoint** — `POST /ocs/v2.php/apps/bookings/api/v1/appointments` accepting appointment creation payload, returning appointment object + confirmation details
- **Validation rules** — slot availability check, service accessibility, customer eligibility, duration compliance
- **Appointment lifecycle states** — `pending_confirmation`, `confirmed`, `completed`, `cancelled` with state-machine transitions
- **Audit trail** — every create/update operation logged via OpenRegister's audit trail

### Out of Scope

- **Cancellation & rescheduling** — owned by phase 2 spec `bookings-appointment-cancellation`
- **Conflict detection & availability slots** — owned by phase 2 spec `bookings-availability-slots`
- **Notifications & reminders** — owned by phase 3 spec `bookings-reminder-notifications`
- **Payment processing** — out of scope; assumed handled by integrations (Mollie, Stripe) in downstream specs
- **Custom fields / dynamic forms** — simple fixed fields in phase 1; extensibility (T2+) recorded on roadmap

## Approach

Three deltas, adding ADDED Requirements to one brand-new spec:

**`bookings-create-appointment`** — declares:
1. `Appointment` register with required fields for appointment details, relations to `Service` and `Resource`
2. Validation rules (availability check, service access, duration compliance)
3. Create workflow (REQ-BCA-NNN series)
4. Appointment state machine (pending_confirmation → confirmed → completed / cancelled)
5. Audit trail integration

The spec follows conduction-schema format (RFC 2119, `### REQ-BCA-NNN`, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

**Existing dependencies assumed available**:
- `bookings-resource-calendar` — provides `Resource` register with calendar slots
- `bookings-service-catalog` — provides `Service` register with service definitions, pricing, duration

No new external dependencies (payment processors, SMS services assumed handled in phase 3).

## Impact

- `lib/Settings/bookings_register.json` — adds 1 schema (`Appointment`); declares relations to `Service` and `Resource`
- `src/components/AppointmentCreate.vue` — new file, admin appointment creation form
- `src/views/PortalBooking.vue` — new file, customer self-service booking portal
- `src/api/appointmentApi.js` — new file, REST API client
- `lib/Controller/AppointmentApiController.php` — new file, handles `POST /ocs/v2.php/apps/bookings/api/v1/appointments`
- Tests — 12+ unit + integration tests covering happy path, validation failures, edge cases
- `src/manifest.json` — adds 1 navigation entry (Appointments) + create modal action

## Cross-Project Dependencies

- **OpenRegister** — depends on: register CRUD (existing), audit trail (existing), relation validation (existing), lifecycle state machine (via ADR-031 if using `x-openregister-lifecycle`; optional for phase 1)

## Risks

### Risk 1: Availability Computation Complexity

**Severity**: Medium
**Description**: Determining whether a slot is available requires querying `Resource` calendar + existing `Appointment` records + service duration. If availability computation is naive (N+1 queries), performance degrades.
**Mitigation**: Phase 1 delegates slot selection to the frontend (customer picker shows pre-computed available slots from `bookings-availability-slots` spec); appointment create endpoint assumes a valid slot and validates idempotently. Availability logic lands in phase 2 spec.

### Risk 2: Double-Booking Under Concurrent Load

**Severity**: High
**Description**: Two requests creating appointments for the same slot simultaneously may both pass validation and create conflicting appointments.
**Mitigation**: Appointment table includes a unique constraint `(resourceId, startTime, endTime)` at the database level. The service layer retries transactionally on conflict; frontend displays a "slot just booked, please select another" message. Phase 2 spec defines conflict resolution (e.g., waitlist).

### Risk 3: Relation to Nonexistent Service/Resource

**Severity**: Low
**Description**: A create request references a serviceId or resourceId that doesn't exist or is archived.
**Mitigation**: OpenRegister's relation validation guard rejects the save with a clear error. No orphaned appointments.

## Rollback Strategy

Spec-only change initially. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation:

1. Drop `Appointment` register from manifest / navigation
2. Keep appointment records queryable (registers are non-destructive)
3. Revert implementation PRs in standard order

No data migration risk at the spec stage.

## Open Questions

1. **Phone & email on create** — REQ-BCA-004 defines customerId as the sole customer reference. Should phone/email be captured at create time, or sourced from the `Customer` register post-create? Decision needed before implementation.
2. **Appointment confirmation workflow** — REQ-BCA-005 allows both `pending_confirmation` and `confirmed` as initial states. Should customer self-service always require confirmation email, or is immediate confirmation acceptable for staff-only bookings? Define per persona before implementation.
3. **Timezone handling** — appointments are stored in UTC, but users see local time. Frontend conversion is browser-local; no server-side timezone awareness in phase 1. Confirm acceptable before implementation.

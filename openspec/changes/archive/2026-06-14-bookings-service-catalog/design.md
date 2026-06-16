# Design — Service Catalogue (duration, price, buffers)

**status: pr-created**

## Context

Booking-enabled applications require a service catalogue to define what can be booked,
how long it takes, what it costs, and what resource capacity it consumes. Competitor
analysis shows all 21/21 platforms (Bookly, Acuity, Booksy, Salonized, Vagaro, etc.)
implement this as a foundational schema.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the
standard Hydra pipeline; this doc explains why the shape is what it is.

## Goals

- Express the entire service-catalogue surface as **declarative metadata** —
  `Service` register + relationships + validation + lifecycle — per ADR-031.
- Make the spec a **scheduling-practitioner readable contract** — Dutch SMB booking flow
  recognisable end-to-end (service definition → slot search → resource matching → pricing).
- Support **temporal scheduling dimensions** (duration, buffers, prep time) needed by
  all dependent booking specs.
- Support **pricing dimensions** (base price + dynamic flag) enabling T2+ pricing engines
  to layer on complex rules without redesign.
- Enable **resource linking** (service → skill/room/staff type) so downstream specs can
  match availability and allocate capacity.

## Non-Goals

- No PHP service-catalogue service, no `ServiceService.php`.
- No pricing calculation — T2+.
- No booking/slot-scheduling logic — T2+.
- No nested category hierarchy — flat categories, T1.
- No multi-currency support — T5.

## Decisions

### D1 — Service is a standalone T1 foundational register, not nested under Administration

`Service` is registered in OR's global namespace, owned by the Nextcloud apps ecosystem.
Each service carries `administrationId` for multi-tenant isolation (Nextcloud multi-app
context), but the schema itself is NOT scoped to a single administration. This enables
Nextcloud deployment-wide service-reuse and template-sharing patterns.

### D2 — Temporal dimensions are primitive fields, not calculated

`duration`, `prepareTime`, `bufferBefore`, `bufferAfter` are stored as integer minutes.
No derived fields (`endTime`, `slotWidth`); dependent specs compute these via aggregation
on the stored primitives. This keeps the schema minimal and deferrable.

### D3 — Pricing is base-only in T1; dynamic flag signals complexity ahead

`basePrice` + `dynamicPricing` boolean. `dynamicPricing: true` signals to the UI and
booking-engine that the service has time-of-day, volume, or other complex pricing rules
to be applied by T2+ specs. T1 stores no rule objects; those live in dependent specs'
registers.

### D4 — Resource-type linking is a single FK (not many-to-many, for now)

`resourceTypeRef` is a string FK to some "resource type" concept — could be a room,
a skill (e.g., "hairdresser"), a staff specialty, or a generic resource class. The
resolver is deferred to `opsx-ff` discovery. For T1, one service → one resource-type
constraint; many-to-many deferred to T2.

### D5 — Categorization is a flat list of strings, not a registered entity

`serviceCategory` is a string enum (or open string, TBD in design review). Dutch SMBs
often use natural language categories ("Haircut", "Colour", "Styling", "Nails"). Rather
than creating a `ServiceCategory` register, T1 allows freetext or enum per app policy.
Nested hierarchies deferred to T2.

### D6 — Status lifecycle is minimal: draft → active → archived

Services move through three states. `draft` for incomplete definitions; `active` for
bookable; `archived` for historical data retention. No `suspended` or `inactive` in T1.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Service storage | OR `x-openregister-*` (ADR-031) | `Service` register declared in OR global namespace |
| Temporal validation | Standard constraints | REQ-SC-003: duration > 0, buffers ≥ 0, prepareTime ≥ 0 |
| Pricing storage | Minimal base-price pattern | `basePrice` + `dynamicPricing` flag; rule engine deferred |
| Resource FK | OR relationship engine | `resourceTypeRef` string FK; resolver TBD in opsx-ff |
| Category management | Freetext or enum | `serviceCategory` string; hierarchy deferred to T2 |
| Status lifecycle | OR `x-openregister-lifecycle` | `status` enum: draft → active → archived |
| Audit trail | T1 `bookings-audit-trail` (if exists) | Automatic on lifecycle transitions |

## Seed Data (Example Services)

Example Dutch SMB booking services:

### Service 1: Salon Haircut
```json
{
  "id": "svc-hair-cut",
  "name": "Haircut",
  "code": "haircut",
  "description": "Standard men's or women's haircut",
  "duration": 30,
  "prepareTime": 5,
  "bufferBefore": 0,
  "bufferAfter": 15,
  "basePrice": 25.00,
  "currency": "EUR",
  "dynamicPricing": false,
  "serviceCategory": "Hair Services",
  "resourceTypeRef": "hairdresser",
  "status": "active",
  "administrationId": "adm-1"
}
```

### Service 2: Consulting Hour
```json
{
  "id": "svc-consult-hour",
  "name": "Management Consulting — 1 hour",
  "code": "consult-1h",
  "description": "Strategic business consultation session",
  "duration": 60,
  "prepareTime": 10,
  "bufferBefore": 15,
  "bufferAfter": 15,
  "basePrice": 150.00,
  "currency": "EUR",
  "dynamicPricing": true,
  "serviceCategory": "Consulting",
  "resourceTypeRef": "consultant",
  "status": "active",
  "administrationId": "adm-1"
}
```

### Service 3: Venue Rental
```json
{
  "id": "svc-venue-rental",
  "name": "Meetingroom Rental — 4 hours",
  "code": "room-4h",
  "description": "Large meeting room (capacity 20), projector included",
  "duration": 240,
  "prepareTime": 30,
  "bufferBefore": 0,
  "bufferAfter": 30,
  "basePrice": 200.00,
  "currency": "EUR",
  "dynamicPricing": false,
  "serviceCategory": "Venue",
  "resourceTypeRef": "meeting-room",
  "status": "active",
  "administrationId": "adm-1"
}
```

### Service 4: Event Catering
```json
{
  "id": "svc-cater-buffet",
  "name": "Catering — Hot Buffet (per person)",
  "code": "buffet-hot",
  "description": "3-course hot buffet service, staff included",
  "duration": 120,
  "prepareTime": 60,
  "bufferBefore": 0,
  "bufferAfter": 30,
  "basePrice": 18.50,
  "currency": "EUR",
  "dynamicPricing": true,
  "serviceCategory": "Catering",
  "resourceTypeRef": "kitchen-staff",
  "status": "active",
  "administrationId": "adm-1"
}
```

### Service 5: Product Subscription
```json
{
  "id": "svc-monthly-plan",
  "name": "Monthly SaaS Subscription",
  "code": "saas-monthly",
  "description": "Standard plan: up to 5 users, email support",
  "duration": 0,
  "prepareTime": 0,
  "bufferBefore": 0,
  "bufferAfter": 0,
  "basePrice": 99.00,
  "currency": "EUR",
  "dynamicPricing": false,
  "serviceCategory": "Subscriptions",
  "resourceTypeRef": null,
  "status": "active",
  "administrationId": "adm-1"
}
```

## Notes

- **Duration = 0**: Valid for non-scheduled services (subscriptions, one-time purchases).
- **Buffer logic**: Salon haircut (30 min service) + 15 min buffer-after means next slot
  can't start < 30 + 15 = 45 min after this one starts.
- **Prep time**: Typically applied BEFORE the service window, so total calendar block =
  prepareTime + duration + bufferAfter.
- **Dynamic pricing**: Set to `true` if T2+ specs will layer pricing rules
  (time-of-day surcharge, volume discount, seasonal adjustment). UI/booking-engine
  MUST query the pricing-rule spec to calculate final price, not use `basePrice` directly.

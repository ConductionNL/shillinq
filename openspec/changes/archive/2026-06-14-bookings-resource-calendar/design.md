# Design: bookings-resource-calendar

## Architecture Overview

The calendar and booking system is implemented using:

- **Data Layer:** OpenRegister with three new schemas (`resource`, `calendar`, `booking`) stored in PostgreSQL
- **API Layer:** RESTful endpoints (GET/POST) following OpenRegister conventions
- **Business Logic:** PHP service layer with stateless conflict detection
- **UI Layer:** Vue 3 component for month/week/day calendar views + booking form
- **Time Zone Handling:** UTC storage, `Europe/Amsterdam` display (configurable)

### Data Model Overview

```
Resource (staff, room, chair, table, etc.)
  ├── type (staff | room | equipment | furniture | other)
  ├── name (e.g., "Jan Peeters", "Meeting Room A", "Chair 3")
  └── organization (FK to Organization)

Calendar
  ├── resource (FK to Resource)
  ├── timeZone (e.g., "Europe/Amsterdam")
  ├── workingHours (JSON: {mon: "09:00-17:00", ...})
  └── organization (FK to Organization)

Booking
  ├── calendar (FK to Calendar)
  ├── resource (FK to Resource) — redundant with calendar.resource, but denormalized for query efficiency
  ├── title (e.g., "Haircut - Client Name")
  ├── startTime (ISO 8601 UTC)
  ├── endTime (ISO 8601 UTC)
  ├── attendee (Person entity reference or free-text name)
  ├── status (pending | confirmed | cancelled)
  └── externalId (optional, for calendar sync with Google/Outlook)

ConflictDetection (logical, not persisted)
  ├── method: checkConflicts(resourceId, startTime, endTime, excludeBookingId?)
  ├── returns: [{booking, conflictType: 'overlap' | 'resource-unavailable'}]
```

## Goals / Non-Goals

**Goals:**
- Provide per-resource calendar view (staff member's schedule, room's bookings, etc.)
- Prevent double-booking via automated conflict detection
- Support multiple resource types (staff, room, equipment, furniture)
- Responsive UI that works on desktop and mobile
- API-first design for future integrations
- Seed data with realistic Dutch use cases

**Non-Goals:**
- Recurring bookings (scheduled for Tier-2)
- Staff availability rules / working hours templates (Tier-2)
- Payment processing (Tier-3)
- Notifications / reminders (Tier-3)
- Multi-resource bookings in a single transaction (Tier-2)
- Calendar sync with external services (Tier-3)

## Decisions

### Decision 1: Separate `Resource` and `Calendar` entities

`Resource` represents a bookable entity (person, room, equipment). `Calendar` is a resource-specific view with time zone and working hours settings. This separation allows:
- Multiple calendars per resource (e.g., "Jan Peeters - Mon/Wed/Fri", "Jan Peeters - Salon A Bookings")
- Organization-level settings (time zone, working hours) per calendar
- Future multi-resource calendars (Tier-2) by linking multiple resources to one calendar

Alternative (rejected): Single `Calendar` entity with a list of resources. Would require denormalization and complicate single-resource queries.

### Decision 2: UTC storage with time zone display

All `startTime` and `endTime` values are stored in UTC (ISO 8601). Display time zone is configurable per calendar (default: `Europe/Amsterdam`). This:
- Simplifies conflict detection (all times are in the same zone in the database)
- Handles DST transitions correctly on the client (Day.js library)
- Supports multi-timezone organizations in the future

Alternative (rejected): Store times in local time zone. Would require time zone metadata at query time; conflict detection logic would be fragile near DST transitions.

### Decision 3: Conflict detection inside a database transaction

When a booking is created, the conflict check runs within the same database transaction that inserts the booking. If a conflict is found, the transaction is rolled back and the user is notified. This ensures:
- No race condition between conflict check and insertion
- Atomic booking creation
- Simple, synchronous API (no job queue needed)

Alternative (rejected): Asynchronous conflict detection (background job). Would require retrying the booking creation if a conflict is found; adds complexity without benefit for small datasets.

### Decision 4: Denormalize `resource` on `Booking`

`Booking` has both `calendar.resource` (via the Calendar FK) and a direct `resource` FK. This denormalization:
- Simplifies queries: `SELECT * FROM booking WHERE resource_id = ?` is faster than a JOIN
- Maintains referential integrity: if a calendar is deleted, the resource link is still present
- Enables future multi-resource bookings (Tier-2) without schema migration

Alternative (rejected): Single FK to `calendar`, requiring a JOIN on every resource query. Would degrade performance on calendars with thousands of bookings.

### Decision 5: Status field on Booking, not a separate state machine

`Booking.status` is one of: `pending`, `confirmed`, `cancelled`. This is sufficient for Tier-1:
- `pending`: user has submitted the booking form; confirmation email not yet sent or staff approval not yet given
- `confirmed`: booking is locked in; appears on the resource's public calendar
- `cancelled`: user or staff has cancelled; booking no longer blocks the time slot

Alternative (rejected): Separate `BookingState` entity with history tracking. Premature for Tier-1; will add if needed for audit compliance (Tier-3).

### Decision 6: Time zone as a calendar property, not a system-wide setting

Each `Calendar` has its own `timeZone` (e.g., `Europe/Amsterdam`, `America/New_York`). This allows:
- Multi-timezone organizations (branches in different countries)
- Staff working across time zones (easy to see their available slots)
- Correct DST handling per location

Alternative (rejected): System-wide time zone setting. Would require code changes for multi-timezone support; the calendar-level setting is more flexible.

## Seed Data

### Resources

```json
[
  {
    "id": "res-001",
    "type": "staff",
    "name": "Jan Peeters",
    "organization": "org-001",
    "status": "active"
  },
  {
    "id": "res-002",
    "type": "staff",
    "name": "Marie Dubois",
    "organization": "org-001",
    "status": "active"
  },
  {
    "id": "res-003",
    "type": "room",
    "name": "Vergaderruimte A",
    "organization": "org-001",
    "status": "active"
  },
  {
    "id": "res-004",
    "type": "equipment",
    "name": "Behandelstoel 1",
    "organization": "org-001",
    "status": "active"
  },
  {
    "id": "res-005",
    "type": "furniture",
    "name": "Tafel 4 (hoek)",
    "organization": "org-001",
    "status": "active"
  }
]
```

### Calendars

```json
[
  {
    "id": "cal-001",
    "resource": "res-001",
    "timeZone": "Europe/Amsterdam",
    "workingHours": {
      "monday": "09:00-17:00",
      "tuesday": "09:00-17:00",
      "wednesday": "09:00-17:00",
      "thursday": "09:00-17:00",
      "friday": "09:00-17:00",
      "saturday": null,
      "sunday": null
    },
    "organization": "org-001",
    "status": "active"
  },
  {
    "id": "cal-002",
    "resource": "res-003",
    "timeZone": "Europe/Amsterdam",
    "workingHours": {
      "monday": "08:00-18:00",
      "tuesday": "08:00-18:00",
      "wednesday": "08:00-18:00",
      "thursday": "08:00-18:00",
      "friday": "08:00-18:00",
      "saturday": "09:00-13:00",
      "sunday": null
    },
    "organization": "org-001",
    "status": "active"
  },
  {
    "id": "cal-003",
    "resource": "res-004",
    "timeZone": "Europe/Amsterdam",
    "workingHours": {
      "monday": "10:00-18:00",
      "tuesday": "10:00-18:00",
      "wednesday": "10:00-18:00",
      "thursday": "10:00-18:00",
      "friday": "10:00-20:00",
      "saturday": "10:00-16:00",
      "sunday": null
    },
    "organization": "org-001",
    "status": "active"
  }
]
```

### Bookings

```json
[
  {
    "id": "bk-001",
    "calendar": "cal-001",
    "resource": "res-001",
    "title": "Klant: Anna de Wit",
    "startTime": "2026-05-21T10:00:00Z",
    "endTime": "2026-05-21T10:30:00Z",
    "attendee": "Anna de Wit",
    "status": "confirmed"
  },
  {
    "id": "bk-002",
    "calendar": "cal-001",
    "resource": "res-001",
    "title": "Klant: Kees Bakker",
    "startTime": "2026-05-21T11:00:00Z",
    "endTime": "2026-05-21T11:45:00Z",
    "attendee": "Kees Bakker",
    "status": "confirmed"
  },
  {
    "id": "bk-003",
    "calendar": "cal-001",
    "resource": "res-001",
    "title": "Klant: Sophia Vermeulen (CONFLICT: overlaps with bk-002)",
    "startTime": "2026-05-21T11:15:00Z",
    "endTime": "2026-05-21T12:00:00Z",
    "attendee": "Sophia Vermeulen",
    "status": "pending"
  },
  {
    "id": "bk-004",
    "calendar": "cal-002",
    "resource": "res-003",
    "title": "Team Meeting: Q2 Planning",
    "startTime": "2026-05-22T13:00:00Z",
    "endTime": "2026-05-22T14:30:00Z",
    "attendee": "Team",
    "status": "confirmed"
  },
  {
    "id": "bk-005",
    "calendar": "cal-002",
    "resource": "res-003",
    "title": "Client Presentation (CONFLICT: overlaps with bk-004)",
    "startTime": "2026-05-22T13:45:00Z",
    "endTime": "2026-05-22T15:00:00Z",
    "attendee": "Client XYZ",
    "status": "pending"
  },
  {
    "id": "bk-006",
    "calendar": "cal-003",
    "resource": "res-004",
    "title": "Afspraak: Manicure",
    "startTime": "2026-05-23T10:00:00Z",
    "endTime": "2026-05-23T10:30:00Z",
    "attendee": "Cliente A",
    "status": "confirmed"
  },
  {
    "id": "bk-007",
    "calendar": "cal-003",
    "resource": "res-004",
    "title": "Afspraak: Pedicure",
    "startTime": "2026-05-23T11:00:00Z",
    "endTime": "2026-05-23T11:45:00Z",
    "attendee": "Cliente B",
    "status": "confirmed"
  },
  {
    "id": "bk-008",
    "calendar": "cal-003",
    "resource": "res-004",
    "title": "Afspraak: Styling",
    "startTime": "2026-05-24T14:00:00Z",
    "endTime": "2026-05-24T15:00:00Z",
    "attendee": "Cliente C",
    "status": "confirmed"
  },
  {
    "id": "bk-009",
    "calendar": "cal-001",
    "resource": "res-001",
    "title": "Klant: Dirk Peeters",
    "startTime": "2026-05-27T14:00:00Z",
    "endTime": "2026-05-27T14:30:00Z",
    "attendee": "Dirk Peeters",
    "status": "confirmed"
  },
  {
    "id": "bk-010",
    "calendar": "cal-001",
    "resource": "res-001",
    "title": "Klant: Nina Jansen",
    "startTime": "2026-05-27T15:00:00Z",
    "endTime": "2026-05-27T15:45:00Z",
    "attendee": "Nina Jansen",
    "status": "confirmed"
  }
]
```

## Risks / Trade-offs

- [Conflict detection performance] Addressed by single-resource and date-range queries; caching added in Tier-2 if needed.
- [Time zone edge cases] Mitigated by UTC storage and client-side DST handling (Day.js).
- [Race condition on concurrent bookings] Mitigated by database transaction with resource-level lock.
- [Denormalization maintenance] `Booking.resource` must stay in sync with `Calendar.resource`. Enforced by the API layer; schema migration includes a CHECK constraint if the database supports it (PostgreSQL does).

## Migration Plan

1. Add entities to ADR-000 (Data Model).
2. Create OpenRegister `shillinq_calendars_register.json` with schemas.
3. Implement PHP API layer and conflict detection service.
4. Implement Vue calendar component and booking form.
5. Add seed data to the register.
6. Test end-to-end calendar view and conflict detection.
7. Commit and open PR targeting `development`.

Rollback: Revert the commit. The register schema can be rolled back via the Nextcloud app update process.

## Open Questions

- **Multi-organization support:** Should calendars be scoped to a single organization? (Answer: Yes, via `calendar.organization` FK.)
- **Soft delete for calendars:** Should deleted calendars be retained for audit purposes? (Answer: Yes, use `status: archived` instead of hard delete.)
- **External calendar ID:** Should bookings support `externalId` for sync with Google Calendar? (Answer: Yes, add as optional field for future Tier-3 integration.)

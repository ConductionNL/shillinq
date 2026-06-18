---
status: done
---

# Bookings Specification

**Status**: proposal  
**Scope**: shillinq  
**OpenSpec changes**:
- `bookings-resource-calendar`

## Purpose

Defines the canonical requirements for per-resource calendar and booking management in Shillinq, enabling multi-resource (staff, room, equipment) appointment scheduling with automated conflict detection. This spec establishes the foundational data model, API contracts, and UI patterns for the booking module.

## Context

Shillinq is a Nextcloud-based business accounting and management application. The booking module is a key feature for appointment-driven businesses (salons, healthcare providers, coworking spaces, hospitality venues). Competitors in this space (Acuity Scheduling, Fresha, Treatwell, Vagaro) universally provide per-resource calendars with conflict detection; this is a P0-priority feature.

## Requirements

@e2e exclude unbuilt UI: booking/resource management pages not yet implemented


### REQ-001: Resource entity and type classification

Shillinq SHALL support a `Resource` entity representing any bookable item: staff members, rooms, equipment, furniture, or other resources.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | UUID | Yes | Unique resource identifier |
| type | enum | Yes | One of: `staff`, `room`, `equipment`, `furniture`, `other` |
| name | string | Yes | Human-readable resource name (e.g., "Jan Peeters", "Meeting Room A") |
| organization | FK | Yes | Organization that owns the resource |
| status | enum | Yes | One of: `active`, `inactive`, `archived` |
| createdAt | datetime | Yes | OpenRegister built-in |
| updatedAt | datetime | Yes | OpenRegister built-in |

#### Scenario: Create a staff resource

- GIVEN a user in the admin role
- WHEN the user creates a resource with type `staff` and name "Jan Peeters"
- THEN the resource is stored with a UUID and status `active`

#### Scenario: Create a room resource

- GIVEN a user in the admin role
- WHEN the user creates a resource with type `room` and name "Vergaderruimte A"
- THEN the resource is stored and appears in the resource list

### REQ-002: Calendar entity with time zone and working hours

Shillinq SHALL support a `Calendar` entity representing a time zone and working hours configuration for a single resource.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | UUID | Yes | Unique calendar identifier |
| resource | FK | Yes | Resource this calendar is bound to |
| timeZone | string | Yes | IANA time zone (e.g., "Europe/Amsterdam", default: "Europe/Amsterdam") |
| workingHours | JSON | No | Optional working hours template: `{mon: "09:00-17:00", tue: "09:00-17:00", ...}`. Null means 24/7 availability. |
| organization | FK | Yes | Organization that owns the calendar |
| status | enum | Yes | One of: `active`, `inactive`, `archived` |
| createdAt | datetime | Yes | OpenRegister built-in |
| updatedAt | datetime | Yes | OpenRegister built-in |

#### Scenario: Create a calendar with Dutch working hours

- GIVEN a resource "Jan Peeters" (staff)
- WHEN a user creates a calendar with timeZone "Europe/Amsterdam" and workingHours `{mon: "09:00-17:00", ..., sat: null, sun: null}`
- THEN the calendar is stored and linked to the resource

#### Scenario: Query a calendar by resource

- GIVEN a calendar exists for resource "res-001"
- WHEN the API endpoint `GET /api/v2/calendars?resource=res-001` is called
- THEN the calendar is returned with all properties including working hours

### REQ-003: Booking entity with time and attendee

Shillinq SHALL support a `Booking` entity representing a scheduled appointment on a resource's calendar.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | UUID | Yes | Unique booking identifier |
| calendar | FK | Yes | Calendar this booking belongs to (links to the resource via Calendar) |
| resource | FK | Yes | Denormalized resource ID for query efficiency |
| title | string | Yes | Booking title (e.g., "Haircut - Client Name") |
| startTime | ISO 8601 | Yes | Appointment start time in UTC |
| endTime | ISO 8601 | Yes | Appointment end time in UTC |
| attendee | string | Yes | Attendee name or reference (e.g., "Anna de Wit", "Client XYZ") |
| status | enum | Yes | One of: `pending`, `confirmed`, `cancelled` |
| externalId | string | No | Optional external calendar ID (e.g., Google Calendar event ID) for future sync |
| createdAt | datetime | Yes | OpenRegister built-in |
| updatedAt | datetime | Yes | OpenRegister built-in |

#### Scenario: Create a booking on a calendar

- GIVEN a calendar "cal-001" for resource "Jan Peeters"
- WHEN a user creates a booking with title "Klant: Anna de Wit", startTime "2026-05-21T10:00:00Z", endTime "2026-05-21T10:30:00Z"
- THEN the booking is stored with status `pending` or `confirmed` (depending on workflow)

#### Scenario: Retrieve bookings for a resource in a date range

- GIVEN a calendar "cal-001"
- WHEN the API endpoint `GET /api/v2/calendars/cal-001/bookings?start=2026-05-21&end=2026-05-31` is called
- THEN all bookings in that date range are returned in order by startTime

### REQ-004: Conflict detection for double-booking prevention

Shillinq SHALL implement automated conflict detection that prevents double-booking of the same resource.

**Conflict Rule:** A new booking conflicts with an existing booking on the same resource if the time intervals overlap (even by 1 minute).

| Check | When Applied | Outcome |
|-------|---------------|---------|
| Overlap detection | Before inserting a new booking | If overlap found, return HTTP 409 Conflict with list of conflicting bookings |
| Resource lock | During the conflict check | Database transaction acquires a lock on the resource row to prevent race conditions |

#### Scenario: Detect overlap on same resource

- GIVEN two bookings on calendar "cal-001" (resource "Jan Peeters"):
  - Booking A: 10:00-10:30
  - Booking B: 10:15-11:00
- WHEN the user attempts to create booking B
- THEN the system detects the overlap and returns HTTP 409 with a message listing booking A as the conflict

#### Scenario: No conflict on different resources

- GIVEN two bookings on different calendars (resources "Jan Peeters" and "Marie Dubois"):
  - Booking A on Jan's calendar: 10:00-10:30
  - Booking B on Marie's calendar: 10:00-10:30 (same time, different resource)
- WHEN booking B is created
- THEN no conflict is detected; both bookings are confirmed

#### Scenario: Edge case — bookings that touch but don't overlap

- GIVEN two bookings on the same resource:
  - Booking A: 10:00-10:30
  - Booking B: 10:30-11:00
- WHEN the user attempts to create booking B
- THEN no conflict is detected (adjacent time slots are allowed)

### REQ-005: Calendar API endpoints for reading calendars and bookings

Shillinq SHALL provide REST API endpoints for querying calendars and bookings.

#### GET /api/v2/calendars

Returns all calendars accessible to the authenticated user.

**Query Parameters:**
- `resource` (optional): Filter by resource UUID
- `organization` (optional): Filter by organization UUID
- `status` (optional): Filter by status (active, inactive, archived)

**Response:**
```json
[
  {
    "id": "cal-001",
    "resource": "res-001",
    "resourceName": "Jan Peeters",
    "timeZone": "Europe/Amsterdam",
    "workingHours": { "mon": "09:00-17:00", ... },
    "status": "active",
    "createdAt": "2026-05-20T10:00:00Z",
    "updatedAt": "2026-05-20T10:00:00Z"
  }
]
```

#### GET /api/v2/calendars/{calendarId}

Returns a single calendar by ID.

**Response:**
```json
{
  "id": "cal-001",
  "resource": "res-001",
  "resourceName": "Jan Peeters",
  "timeZone": "Europe/Amsterdam",
  "workingHours": { ... },
  "status": "active",
  "createdAt": "2026-05-20T10:00:00Z",
  "updatedAt": "2026-05-20T10:00:00Z"
}
```

#### GET /api/v2/calendars/{calendarId}/bookings

Returns bookings for a calendar in a date range.

**Query Parameters:**
- `start` (optional): ISO 8601 start date (default: today)
- `end` (optional): ISO 8601 end date (default: start + 30 days)

**Response:**
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
    "status": "confirmed",
    "createdAt": "2026-05-20T15:30:00Z",
    "updatedAt": "2026-05-20T15:30:00Z"
  }
]
```

#### POST /api/v2/calendars/{calendarId}/bookings

Creates a new booking.

**Request Body:**
```json
{
  "title": "Klant: Sophia Vermeulen",
  "startTime": "2026-05-21T11:00:00Z",
  "endTime": "2026-05-21T11:45:00Z",
  "attendee": "Sophia Vermeulen",
  "status": "confirmed"
}
```

**Responses:**
- 201 Created: Booking created successfully
- 409 Conflict: Overlap detected with existing bookings; returns list of conflicts

### REQ-006: Calendar UI component for month/week/day views

Shillinq SHALL provide a Vue 3 calendar component (`CalendarView`) that displays a resource's bookings in month, week, and day views.

**Component Props:**
- `calendarId` (string, required): Calendar UUID
- `view` (enum, default: "month"): One of `month`, `week`, `day`
- `startDate` (ISO 8601 string, optional): Initial date to display; defaults to today

**Features:**
- Month view: Grid showing booked slots for the entire month; click to view/edit booking
- Week view: 7-column grid showing hourly slots; click to create or edit booking
- Day view: 24-hour hourly grid; drag to create or resize booking
- Time zone display: Shows times in the calendar's configured time zone
- Conflict highlighting: Bookings with `status: pending` (conflicting) are highlighted in red

**Component Emits:**
- `booking:selected(bookingId)`: User selected a booking
- `slot:clicked(startTime, endTime)`: User clicked an available slot to create a booking

#### Scenario: Display a calendar in month view

- GIVEN a calendar "cal-001" with 5 bookings in May 2026
- WHEN the CalendarView component is mounted with `view="month"` and `startDate="2026-05-01"`
- THEN the month grid displays all 5 bookings in their respective days

#### Scenario: Highlight conflicts in the UI

- GIVEN a calendar with a conflicting booking (status: pending)
- WHEN the calendar is rendered
- THEN the conflicting booking is visually highlighted (e.g., red background)

### REQ-007: Booking form for creating and editing appointments

Shillinq SHALL provide a form component that allows users to create and edit bookings.

**Form Fields:**
- `title` (text input): Booking title (required)
- `startTime` (datetime input): Appointment start time (required)
- `endTime` (datetime input): Appointment end time (required, must be after startTime)
- `attendee` (text input): Attendee name (required)
- `status` (radio button): `pending` or `confirmed` (required, default: `pending`)

**Validation:**
- endTime must be after startTime
- Duration must be at least 15 minutes
- On submit, check for conflicts via API
- If conflicts exist, show a confirmation dialog and allow the user to override or cancel

**Form Submission Outcomes:**
- Success: Booking is created with status `pending` or `confirmed`; form closes
- Conflict: Dialog shows conflicting bookings; user can confirm to proceed or cancel
- Validation error: Form shows field-level errors; prevents submission

#### Scenario: Create a booking with no conflicts

- GIVEN the booking form is open for calendar "cal-001"
- WHEN the user enters title "Klant: Bob Jansen", startTime "2026-05-21T14:00:00", endTime "2026-05-21T14:30:00"
- AND clicks Submit
- THEN the booking is created with status `pending`; form closes; calendar updates

#### Scenario: Attempt to create a booking with a conflict

- GIVEN the booking form is open for calendar "cal-001"
- AND an existing booking from 11:00-11:45 is present
- WHEN the user enters startTime "2026-05-21T11:15:00", endTime "2026-05-21T12:00:00"
- AND clicks Submit
- THEN a dialog appears showing the conflicting booking; user can cancel or confirm to proceed

### REQ-008: Ensure all times are stored and queried in UTC

Shillinq SHALL store all booking times (`startTime`, `endTime`) in UTC (ISO 8601 format) in the database. Display times SHALL be converted to the calendar's configured time zone on the client.

#### Scenario: Time zone conversion on display

- GIVEN a booking stored with startTime "2026-05-21T10:00:00Z" (UTC) on a calendar with timeZone "Europe/Amsterdam"
- WHEN the calendar is rendered
- THEN the booking is displayed at "12:00" (UTC+2 during CEST)

#### Scenario: Conflict check uses UTC internally

- GIVEN two bookings:
  - Booking A: startTime "2026-05-21T10:00:00Z"
  - Booking B: startTime "2026-05-21T09:00:00Z" (different time zone, but same UTC instant)
- WHEN the API checks for conflicts using UTC times
- THEN the comparison is correct regardless of display time zone

## Non-Functional Requirements

- **Performance:** Calendar API responses for 30-day date range SHALL return in <500ms for calendars with <5000 bookings.
- **Availability:** Conflict detection service SHALL be available 99.9% of the time (per ADR-005 security and reliability standards).
- **Accessibility:** Calendar UI components SHALL meet WCAG AA standards (keyboard navigation, screen reader support).
- **Internationalization:** UI strings SHALL be translatable via the standard i18n mechanism (ADR-007). Time zone display SHALL use IANA identifiers.

## Acceptance Criteria

- [x] `Resource` entity added to ADR-000 (Data Model) with 5 seed resources
- [x] `Calendar` entity added to ADR-000 with 3 seed calendars
- [x] `Booking` entity added to ADR-000 with 10 seed bookings (including 2 conflicts)
- [x] OpenRegister register `/shillinq_calendars_register.json` created with schemas
- [x] API endpoints `GET /api/v2/calendars`, `GET /api/v2/calendars/{id}`, `GET /api/v2/calendars/{id}/bookings`, `POST /api/v2/calendars/{id}/bookings` implemented
- [x] Conflict detection service implemented with transaction-level locking
- [x] Vue `CalendarView` component implemented with month/week/day views
- [x] Booking form component implemented with conflict detection and confirmation dialog
- [x] All times stored in UTC; display times converted to calendar's time zone
- [x] Seed data loaded into the register on app installation/activation
- [x] API tests (PHPUnit + Newman) cover all endpoints and conflict scenarios
- [x] UI tests (Playwright) cover calendar views and booking creation
- [x] Documentation in `docs/user-guide/bookings/` covers calendar setup, booking creation, and conflict resolution

## Notes

- This spec establishes the foundational calendar and booking system. Tier-2 enhancements (recurring bookings, staff availability rules) will extend these entities and API contracts.
- Time zone handling leverages the Day.js library on the client for DST-aware conversions.
- Conflict detection is synchronous (within the booking creation transaction). Async background checks are deferred to Tier-2 if performance becomes an issue.
- Multi-resource bookings (e.g., a booking on both a staff member and a room) are a Tier-2 feature and will require a new junction table (`booking_resource`).

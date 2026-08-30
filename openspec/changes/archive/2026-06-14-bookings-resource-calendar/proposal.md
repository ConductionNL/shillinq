# Proposal: bookings-resource-calendar

## Summary

Per-staff / per-room / per-chair calendar with conflict detection. This change introduces the foundational calendar and booking management system, enabling Shillinq to support multi-resource (staff, room, chair) appointment scheduling with automated conflict detection. This is the base layer for the booking module, required by 18 out of 21 competitors.

## Motivation

Nextcloud-based booking and appointment systems (salons, healthcare, coworking, hospitality) require calendar views scoped to individual resources: a specific staff member's schedule, a particular meeting room's availability, a specific chair at a salon, or a table at a restaurant. Each resource needs:

1. A per-resource calendar view showing booked and available time slots
2. Conflict detection (preventing double-booking of the same resource)
3. Support for different resource types (staff, room, equipment, furniture)
4. Time slot management with customizable durations

This change establishes the core data model, API contracts, and UI calendar component for per-resource booking. Companion features (staff availability rules, multi-day bookings, repeat appointments, payment integration) will layer on top of this foundation.

## Affected Projects

- [x] Project: `shillinq` — new `Booking`, `Calendar`, `Resource` entities in OpenRegister; new calendar API endpoints; new Vue calendar component in app UI

## Scope

### In Scope

- Define `Calendar`, `Booking`, `Resource`, and `ConflictDetection` entities in ADR-000 (Data Model)
- Create OpenRegister register for bookings with schemas for Calendar, Booking, Resource
- Implement calendar API GET endpoints: `/api/v2/calendars`, `/api/v2/calendars/{id}`, `/api/v2/calendars/{id}/bookings`
- Implement conflict detection service: checks if a proposed booking overlaps existing bookings on the same resource
- Implement calendar UI component: Vue component displaying a resource's calendar in month/week/day view
- Implement booking creation form: allows staff to create a booking with resource, time, duration, and attendee details
- Support common time zones (Europe/Amsterdam default for Dutch context)
- Seed data: 5 example resources, 3 example calendars, 10 example bookings across 2 calendars with 2 detected conflicts

### Out of Scope

- Recurring bookings / repeat appointments (scheduled for Tier-2)
- Availability rules / blockedTimes / staff availability templates (Tier-2)
- Payment processing integration (Tier-3)
- Notification/reminder emails (Tier-3)
- Multi-day or multi-resource bookings (Tier-2)
- Calendar synchronization with external services (Google Calendar, Outlook) (Tier-3)
- PR creation, merge, or archive process tasks

## Approach

All changes follow the Shillinq architecture (ADR-001 to ADR-004):

1. **Data Model (ADR-000):** Add four new entities (`Calendar`, `Booking`, `Resource`, `ConflictDetection`) to the official data model. Each entity is OpenRegister-compatible and includes UUID, status, and audit fields via OpenRegister built-ins.

2. **Register Definition:** Create `/shillinq_calendars_register.json` declaring the schemas for Calendar, Booking, and Resource.

3. **API Layer:** Implement REST endpoints for reading calendars and bookings, querying by resource and date range.

4. **Business Logic:** Implement conflict detection service as a stateless PHP service that compares time intervals.

5. **UI Components:** Create a reusable Vue calendar component (month/week/day views) that fetches bookings from the API and displays them in a resource-scoped grid.

6. **Seed Data:** Include 5 resources (staff members and rooms), 3 calendars, and 10 bookings with intentional conflicts for testing.

## New Dependencies

- None (calendar and booking management use existing OpenRegister, PHP, and Vue dependencies)

## Impact

- **Database:** 3 new OpenRegister tables (`calendar`, `booking`, `resource`) with associated audit trails
- **API surface:** 5 new REST endpoints (+1 internal conflict check method)
- **UI:** 1 new calendar component (month/week/day view), 1 new booking creation form
- **Documentation:** New guides for staff scheduling and booking management in `docs/user-guide/`

## Cross-Project Dependencies

None. This change is self-contained within shillinq. Future enhancements (Tier-2 availability rules, Tier-3 integrations) will reference this base layer.

## Risks

### Risk 1: Conflict detection performance with large datasets

**Severity:** Low — **Mitigation:** Conflict detection is scoped to a single resource and a date range (e.g., 30 days). The query uses indexed lookups on resource ID and date. If performance degrades with >1000 concurrent bookings per resource, a caching layer (Redis) is added in Tier-2.

### Risk 2: Time zone edge cases near DST transitions

**Severity:** Low — **Mitigation:** All times stored in UTC in the database; display times use `Europe/Amsterdam` by default (configurable per organization). DST transitions are handled by the JavaScript datetime library (Day.js) on the client; server logic operates in UTC.

### Risk 3: Concurrent booking creation (race condition)

**Severity:** Medium — **Mitigation:** Conflict detection runs inside a database transaction with a lock on the resource row. If two bookings are submitted simultaneously, the second write fails with a conflict message and the user is prompted to refresh the calendar.

## Rollback Strategy

All changes are in the `lib/`, `src/`, `public/`, and `openspec/` directories. Rollback: revert the commit. The register migration (if any) can be rolled back via a down migration in the next Nextcloud admin interface update.

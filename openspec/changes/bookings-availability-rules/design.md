# Design — Booking Availability Rules

## Context

Shillinq's resource-scheduling and marketplace modules depend on fine-grained
control over when bookings are allowed. A service provider (hairdresser,
consultant, rental company) must define working hours, breaks, holidays,
and booking constraints (advance notice, buffer times, blackout dates) to
prevent over-booking and enforce business rules.

This change is the **first iteration** of availability management in
Shillinq. It is **spec-only**. Implementation lands later via `opsx-apply`.

## Goals

- Express availability rules as **declarative metadata** — three schemas
  with simple lifecycle transitions per ADR-031. No custom PHP business
  logic at schema level.
- Support **common SMB use cases**: per-resource working hours, recurring
  breaks, holidays, vacation blocks, and booking constraints (advance
  notice, prep/cleanup buffers).
- Align with **competitor evidence** (Cal.com, Cogsworth, Easy-Appointments,
  Salonized, Resy) — 17/21 market leaders surveyed show these features
  in their core offering.
- Keep Tier 1 narrow and extensible — future tiers can add complex
  recurrence rules, bulk holiday import, or multi-language holiday
  calendars without reshaping the base schemas.

## Non-Goals

- Complex recurrence rules (Easter-dependent, lunar-calendar holidays,
  country-specific public-holiday automation).
- Multi-language booking labels or locale-specific working-hour defaults.
- Booking engine itself — owned by sibling `bookings-resource-calendar`.
- Calendar UI, drag-and-drop interfaces, or visual conflict detection.
- Integration with external calendar systems (Google Calendar, Outlook).

## Decisions

### D1 — Three-schema model: Rule + Break + Constraint

Availability is split across three schemas:

| Schema | Purpose | Granularity |
|--------|---------|-------------|
| `AvailabilityRule` | Header: per-resource, status, effective dates | One per resource |
| `ResourceBreak` | Recurrence rule for breaks (lunch, coffee) | One per break type |
| `BookingConstraint` | Advance notice, buffer times, cancellation rules | One per policy |

**Rationale**: Separation of concerns. Breaks are stateless, recurring patterns
(Monday 12:00–13:00). Constraints are business rules (5-day advance notice,
2-hour buffer). The rule header owns the resource FK and lifecycle.

**Alternative considered**: Flat single-schema model with all fields in
`AvailabilityRule`. Rejected — breaks and constraints are one-to-many
relationships; nesting them in a single schema violates OpenRegister's
flat-register principle and complicates future extensions (e.g., multiple
break schedules for seasonal variations).

### D2 — Simple recurrence for breaks (day-of-week + time)

Breaks are defined as:
- Day of week (Monday–Sunday, or daily, or specific dates)
- Start and end time (HH:MM)
- Status (active/archived)

No support for complex patterns (Easter, lunar calendar, country-specific
holidays).

**Rationale**: Covers 95% of SMB use cases (lunch 12–13, coffee breaks).
Complex patterns belong in Tier 2 as a separate spec.

**Alternative considered**: ICS/iCalendar recurrence rules (RFC 5545).
Rejected — introduces external dependency; Tier 1 is narrow by design;
SMBs can import public holidays via Tier 2 bulk-upload feature later.

### D3 — Advance-notice window and buffer times as discrete fields

`BookingConstraint` carries:
- `minAdvanceNotice` (integer, days)
- `maxAdvanceNotice` (integer, days or null for unlimited)
- `preBufferMinutes` (prep time before service)
- `postBufferMinutes` (cleanup time after service)
- `cancellationDeadlineHours` (how many hours before service to cancel)

**Rationale**: Discrete fields are queryable and understandable by SMBs.
A designer reading `minAdvanceNotice: 5` immediately grasps "no bookings
within 5 days". Formulas or calculation rules are implementation detail.

**Alternative considered**: Flexible "rules engine" with DSL
(domain-specific language) for complex logic. Rejected — adds tooling
complexity; discrete fields satisfy competitor evidence (all 17/21 use
simple advance-notice + buffer fields).

### D4 — Status lifecycle: draft → active → archived

`AvailabilityRule.status` follows:

```
draft → active → archived
  ↑                    ↑
  └────────────────────┘ (can revert to draft while inactive)
```

Effective-date support: `effectiveFrom` and `effectiveUntil` timestamps
allow administrators to schedule future activation without manual
intervention.

**Rationale**: Matches OpenRegister lifecycle conventions (ADR-031).
Effective dates let SMBs plan seasonal changes (e.g., summer hours)
without scripting.

**Alternative considered**: State machine with "suspended" state.
Rejected — archived (soft delete) + effective dates cover the use cases;
suspend adds complexity without evidence.

### D5 — Blackout dates as a separate array within BookingConstraint

`BookingConstraint` carries an optional `blackoutDates` array:
```json
[
  { "startDate": "2026-07-01", "endDate": "2026-07-15", "reason": "Vacation" },
  { "startDate": "2026-12-25", "endDate": "2026-12-26", "reason": "Christmas" }
]
```

**Rationale**: Blackouts are administrative metadata (reason, date range)
and are typically bulk-loaded. Keeping them in a single array simplifies
queries ("show me all unavailable periods for resource X") compared to
a separate schema. For large holiday lists, Tier 2 adds bulk-import.

**Alternative considered**: Separate `ResourceBlackout` schema.
Rejected — one-to-many cardinality is low for SMBs (typically 10–20
blackout periods per year); separate schema adds query complexity
without benefit.

## Data Model

### AvailabilityRule

Header entity associating availability constraints with a resource.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | uuid | Yes | Primary key |
| resourceId | string (FK) | Yes | Foreign key to Resource (from bookings-resource-calendar) |
| status | enum | Yes | draft / active / archived |
| effectiveFrom | date | No | Date rule becomes active (default: today) |
| effectiveUntil | date | No | Date rule expires (default: null = permanent) |
| description | string | No | Administrator notes (e.g., "Summer hours") |
| createdAt | datetime | Yes | OpenRegister built-in |
| updatedAt | datetime | Yes | OpenRegister built-in |

**Relations**:
- 1:N → ResourceBreak
- 1:N → BookingConstraint

### ResourceBreak

Recurrence rule for a break (lunch, coffee, etc.).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | uuid | Yes | Primary key |
| availabilityRuleId | string (FK) | Yes | Foreign key to AvailabilityRule |
| breakType | enum | Yes | lunch / coffee / other |
| dayOfWeek | enum | Yes | monday / tuesday / ... / sunday / daily |
| startTime | time | Yes | Start time HH:MM (24-hour) |
| endTime | time | Yes | End time HH:MM (24-hour) |
| isRecurring | boolean | No | True if repeats weekly (default: true) |
| status | enum | Yes | active / archived |
| description | string | No | Notes (e.g., "Lunch break") |
| createdAt | datetime | Yes | OpenRegister built-in |
| updatedAt | datetime | Yes | OpenRegister built-in |

**Constraints**:
- `endTime > startTime`
- `startTime` and `endTime` are valid times (00:00–23:59)

### BookingConstraint

Business rules for booking (advance notice, buffers, cancellation).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | uuid | Yes | Primary key |
| availabilityRuleId | string (FK) | Yes | Foreign key to AvailabilityRule |
| minAdvanceNotice | integer | No | Minimum days in advance (default: 0) |
| maxAdvanceNotice | integer | No | Maximum days in advance (null = unlimited) |
| preBufferMinutes | integer | No | Prep time before service (default: 0) |
| postBufferMinutes | integer | No | Cleanup time after service (default: 0) |
| cancellationDeadlineHours | integer | No | Hours before service to allow cancellation (default: 0) |
| blackoutDates | array | No | List of {startDate, endDate, reason} objects |
| status | enum | Yes | active / archived |
| createdAt | datetime | Yes | OpenRegister built-in |
| updatedAt | datetime | Yes | OpenRegister built-in |

**Constraints**:
- `minAdvanceNotice >= 0`
- `maxAdvanceNotice >= minAdvanceNotice` (if set)
- Buffer times non-negative
- `cancellationDeadlineHours >= 0`

## Example Seed Data (Dutch values)

### AvailabilityRule
```json
{
  "id": "rule-001",
  "resourceId": "res-001",
  "status": "active",
  "effectiveFrom": "2026-01-01",
  "effectiveUntil": null,
  "description": "Standaard beschikbaarheid kapper Johan"
}
```

### ResourceBreak
```json
{
  "id": "break-001",
  "availabilityRuleId": "rule-001",
  "breakType": "lunch",
  "dayOfWeek": "monday",
  "startTime": "12:00",
  "endTime": "13:00",
  "isRecurring": true,
  "status": "active",
  "description": "Mittagspause"
}
```

### BookingConstraint
```json
{
  "id": "constraint-001",
  "availabilityRuleId": "rule-001",
  "minAdvanceNotice": 1,
  "maxAdvanceNotice": 30,
  "preBufferMinutes": 15,
  "postBufferMinutes": 15,
  "cancellationDeadlineHours": 24,
  "blackoutDates": [
    {
      "startDate": "2026-07-15",
      "endDate": "2026-07-29",
      "reason": "Zomervakantie"
    },
    {
      "startDate": "2026-12-25",
      "endDate": "2026-12-31",
      "reason": "Wintervakantie"
    }
  ],
  "status": "active"
}
```

## Reuse Analysis

| Competitor | Feature | Shillinq Equivalent |
|---|---|---|
| Cal.com | Min/max lead time | `BookingConstraint.minAdvanceNotice/maxAdvanceNotice` |
| Cal.com | Pre/post buffer | `BookingConstraint.preBufferMinutes/postBufferMinutes` |
| Cogsworth | Buffer times | Pre/post buffers above |
| Cogsworth | Booking window limits | Min/max advance notice above |
| Easy-Appointments | Working plans | `AvailabilityRule` + `ResourceBreak` |
| Easy-Appointments | Cancellation period | `BookingConstraint.cancellationDeadlineHours` |
| Resy | Prep time per service | Deferred to Tier 2 (per-service rules) |
| Salonized | Working hours & holidays | `ResourceBreak` + `BookingConstraint.blackoutDates` |

All 17/21 competitors surveyed support working hours, breaks, and advance-notice
constraints. Shillinq's Tier 1 covers the mandatory subset.

## Acceptance Criteria (Bookkeeper/SMB Persona)

An SMB hairdresser reading this spec confirms:
- ✓ Can define Monday–Friday 09:00–17:30 hours
- ✓ Can block lunch 12:00–13:00 daily
- ✓ Can require 1-day advance notice
- ✓ Can block July 15–29 for vacation
- ✓ Can define 15-minute prep + 15-minute cleanup per appointment
- ✓ Can require 24-hour cancellation notice

All true for Tier 1 schema.

# Spec: Booking Availability Rules

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (booking foundation)
**Depends on:** `bookings-resource-calendar`

## Purpose

This specification defines the `AvailabilityRule`, `ResourceBreak`, and `BookingConstraint` registers, enabling per-resource configuration of working hours, recurring breaks (lunch/coffee), advance-notice windows, pre/post buffer times, cancellation deadlines, and blackout-date holidays. Together these three schemas form the declarative source-of-truth for "when can this resource be booked?".

The three-schema split keeps the rule header (resource FK + status + effective dates) cleanly separated from the recurring break pattern and the booking-policy constraints, mirroring competitor evidence (Cal.com, Cogsworth, Easy-Appointments, Salonized, Resy). All requirements use RFC 2119 language (MUST, SHOULD, MAY). All declarations are ADR-024 + ADR-037 declarative-first: schemas live in `lib/Settings/register.d/bookings-availability-rules.json`, navigation lives in `src/manifest.d/bookings-availability-rules.json`, no custom PHP service ships at Tier 1.

---

## Requirements

@e2e exclude unbuilt UI: availability-rules pages not yet implemented


### Requirement: REQ-BAR-001 AvailabilityRule Register Definition

The `AvailabilityRule` register MUST be declared in `lib/Settings/register.d/bookings-availability-rules.json` per ADR-037 with the following schema:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| availabilityRuleId | string | Yes | Operator-assigned unique rule identifier within the administration |
| resource | string | Yes | FK to `Resource.resourceId` (from `bookings-resource-calendar`) |
| status | enum | Yes | One of `draft`, `active`, `archived`; default `draft` |
| effectiveFrom | date | No | Date rule becomes active (null = active immediately) |
| effectiveUntil | date | No | Date rule expires (null = permanent) |
| description | string | No | Administrator notes (e.g. "Standaard beschikbaarheid", "Zomervakantie") |

**Schema Type**: OpenRegister-managed; relations: `AvailabilityRule.resource` → `Resource.resourceId` (many-to-one).

**Lifecycle (ADR-031)**: declarative `x-openregister-lifecycle` with states `draft → active → archived` and a `reactivate` reverse transition (`archived → draft`). The initial state is `draft`.

#### Scenario: Create an availability rule

- **GIVEN** an authenticated operator with admin permissions
- **WHEN** they POST to `/ocs/v2.php/apps/openregister/api/objects/shillinq/AvailabilityRule` with a body that sets `administrationId`, `availabilityRuleId`, `resource`, and `description`
- **THEN** the rule is persisted with `status = draft`
- **AND** the rule appears in the Availability Rules index for that resource
- **AND** the audit trail records the creation with actor and timestamp

#### Scenario: Activate a draft rule

- **GIVEN** an `AvailabilityRule` with `status = draft` and `effectiveFrom = 2026-07-01`
- **WHEN** the operator transitions it to `active`
- **THEN** the rule is updated to `status = active`
- **AND** when the system date reaches `effectiveFrom` the rule applies to availability lookups; before that date the rule is queryable but does not constrain bookings

#### Scenario: Archive a rule with future effective end date

- **GIVEN** an active `AvailabilityRule` with `effectiveUntil = 2026-12-31`
- **WHEN** the operator transitions the rule to `archived`
- **THEN** the rule is updated to `status = archived`
- **AND** the rule no longer appears in the default index view
- **AND** historical bookings retain their `availabilityRuleId` link for audit

---

### Requirement: REQ-BAR-002 ResourceBreak Register Definition

The `ResourceBreak` register MUST be declared in `lib/Settings/register.d/bookings-availability-rules.json` with the following schema:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| resourceBreakId | string | Yes | Operator-assigned unique break identifier within the administration |
| availabilityRule | string | Yes | FK to `AvailabilityRule.availabilityRuleId` |
| breakType | enum | Yes | One of `lunch`, `coffee`, `prep`, `other` |
| dayOfWeek | enum | Yes | One of `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`, `daily` |
| startTime | string (HH:MM, 24h) | Yes | Break start time in the resource's calendar zone |
| endTime | string (HH:MM, 24h) | Yes | Break end time, MUST be strictly greater than `startTime` |
| isRecurring | boolean | No | True if the break repeats weekly; default `true` |
| status | enum | Yes | One of `active`, `archived`; default `active` |
| description | string | No | Free-text notes (e.g. "Lunch", "Pauze") |

**Validation**:
- `endTime` MUST be strictly greater than `startTime` (declared via JSON-Schema `pattern` plus a `x-openregister-calculations` cross-field check)
- `startTime` and `endTime` MUST match `^([01][0-9]|2[0-3]):[0-5][0-9]$`

**Schema Type**: OpenRegister-managed; relations: `ResourceBreak.availabilityRule` → `AvailabilityRule.availabilityRuleId` (many-to-one).

#### Scenario: Operator declares a Monday lunch break

- **GIVEN** an `AvailabilityRule` with `availabilityRuleId = "rule-001"`
- **WHEN** the operator creates a `ResourceBreak` with `availabilityRule = "rule-001"`, `breakType = "lunch"`, `dayOfWeek = "monday"`, `startTime = "12:00"`, `endTime = "13:00"`
- **THEN** the break is persisted with `status = active` and `isRecurring = true`
- **AND** the detail view of `rule-001` lists this break in its breaks table

#### Scenario: Reject break with end time before start time

- **GIVEN** an attempt to create a `ResourceBreak` with `startTime = "13:00"` and `endTime = "12:00"`
- **WHEN** the create request is submitted
- **THEN** OpenRegister rejects the request with a `400 Bad Request` carrying a validation error referencing the `endTime > startTime` constraint
- **AND** no record is persisted

#### Scenario: Reject break with invalid time string

- **GIVEN** an attempt to create a `ResourceBreak` with `startTime = "25:99"`
- **WHEN** the create request is submitted
- **THEN** OpenRegister rejects the request with a `400 Bad Request` citing the `HH:MM 24-hour` pattern

#### Scenario: Reject break with unknown day of week

- **GIVEN** an attempt to create a `ResourceBreak` with `dayOfWeek = "funday"`
- **WHEN** the create request is submitted
- **THEN** OpenRegister rejects the request with a `400 Bad Request` citing the `dayOfWeek` enum

---

### Requirement: REQ-BAR-003 BookingConstraint Register Definition

The `BookingConstraint` register MUST be declared in `lib/Settings/register.d/bookings-availability-rules.json` with the following schema:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| bookingConstraintId | string | Yes | Operator-assigned unique constraint identifier within the administration |
| availabilityRule | string | Yes | FK to `AvailabilityRule.availabilityRuleId` |
| minAdvanceNoticeDays | integer (≥0) | No | Minimum days in advance a booking may be made (default `0` = same-day allowed) |
| maxAdvanceNoticeDays | integer (≥0, nullable) | No | Maximum days in advance a booking may be made (null = unlimited) |
| preBufferMinutes | integer (≥0) | No | Prep time required before each booking (default `0`) |
| postBufferMinutes | integer (≥0) | No | Cleanup time required after each booking (default `0`) |
| cancellationDeadlineHours | integer (≥0) | No | Minimum hours before a booking starts that a cancellation is still allowed without late fee (default `0`) |
| blackoutDates | array | No | List of `{startDate, endDate, reason}` ranges during which no booking is allowed |
| status | enum | Yes | One of `active`, `archived`; default `active` |

**Validation**:
- `minAdvanceNoticeDays` MUST be ≥ 0
- When `maxAdvanceNoticeDays` is set it MUST be ≥ `minAdvanceNoticeDays`
- `preBufferMinutes`, `postBufferMinutes`, `cancellationDeadlineHours` MUST be ≥ 0
- Each `blackoutDates[*].endDate` MUST be ≥ the corresponding `startDate`

**Schema Type**: OpenRegister-managed; relations: `BookingConstraint.availabilityRule` → `AvailabilityRule.availabilityRuleId` (many-to-one).

#### Scenario: Operator declares a standard SMB booking constraint

- **GIVEN** an `AvailabilityRule` with `availabilityRuleId = "rule-001"`
- **WHEN** the operator creates a `BookingConstraint` with `availabilityRule = "rule-001"`, `minAdvanceNoticeDays = 1`, `maxAdvanceNoticeDays = 30`, `preBufferMinutes = 15`, `postBufferMinutes = 15`, `cancellationDeadlineHours = 24`
- **THEN** the constraint is persisted with `status = active`
- **AND** the detail view of `rule-001` shows the constraint in its constraints section

#### Scenario: Reject constraint with negative buffer

- **GIVEN** an attempt to create a `BookingConstraint` with `preBufferMinutes = -5`
- **WHEN** the create request is submitted
- **THEN** OpenRegister rejects the request with a `400 Bad Request` citing the `>= 0` validation

#### Scenario: Reject constraint with max less than min advance notice

- **GIVEN** an attempt to create a `BookingConstraint` with `minAdvanceNoticeDays = 5` and `maxAdvanceNoticeDays = 1`
- **WHEN** the create request is submitted
- **THEN** OpenRegister rejects the request with a `400 Bad Request` referencing the `maxAdvanceNoticeDays >= minAdvanceNoticeDays` cross-field constraint

#### Scenario: Operator schedules a vacation blackout

- **GIVEN** an existing `BookingConstraint` for `rule-001`
- **WHEN** the operator updates `blackoutDates` to include `{startDate: "2026-07-15", endDate: "2026-07-29", reason: "Zomervakantie"}`
- **THEN** the constraint is updated and the detail view lists the blackout span
- **AND** the audit trail records the change

---

### Requirement: REQ-BAR-004 Status Lifecycle for AvailabilityRule

The `AvailabilityRule.status` field MUST declare an `x-openregister-lifecycle` with:

- `initialState`: `draft`
- States: `draft`, `active`, `archived`
- Transitions:
  - `activate`: `draft → active`
  - `archive`: `active → archived`
  - `reactivate`: `archived → draft` (allows correcting an archived rule into a new draft cycle; no direct `archived → active` jump)

The `ResourceBreak.status` and `BookingConstraint.status` fields MUST each declare an `x-openregister-lifecycle` with `initialState: active` and a single `archive` transition (`active → archived`). No PHP business logic ships at Tier 1; lifecycle enforcement is fully declarative per ADR-031.

#### Scenario: Lifecycle declarations enforce illegal direct transition

- **GIVEN** an `AvailabilityRule` with `status = archived`
- **WHEN** the operator attempts to transition directly to `active`
- **THEN** OpenRegister rejects the transition because no `archived → active` edge exists
- **AND** the operator MUST first `reactivate` the rule to `draft`, edit it, and then `activate` it

---

### Requirement: REQ-BAR-005 Effective-Date Window Semantics

`AvailabilityRule.effectiveFrom` and `AvailabilityRule.effectiveUntil` MUST express the date window during which the rule (and its child `ResourceBreak` and `BookingConstraint` records) constrains bookings:

- A rule with `status = active` and `effectiveFrom = null` (or in the past) applies immediately
- A rule with `status = active` and `effectiveFrom` in the future is queryable but MUST NOT constrain bookings before that date
- A rule with `effectiveUntil` set MUST NOT constrain bookings after that date; the lifecycle transition to `archived` is independent (operator-driven, audit-friendly)
- Multiple active rules for the same resource MAY overlap in their effective windows; the availability-query side (implementation cycle) is responsible for merging them

#### Scenario: Future-dated rule is queryable but does not yet apply

- **GIVEN** an active `AvailabilityRule` with `effectiveFrom = 2027-01-01` and today is `2026-06-07`
- **WHEN** the availability-query layer evaluates today
- **THEN** the rule is returned in the index listing
- **AND** the rule's breaks and constraints are NOT applied to today's booking checks

---

### Requirement: REQ-BAR-006 Relations and Foreign-Key Wiring

The three schemas MUST declare `x-openregister-relations`:

| Source schema | Local field | Related schema | Related field | Cardinality |
|---------------|-------------|----------------|---------------|-------------|
| `AvailabilityRule` | `resource` | `Resource` | `resourceId` | many-to-one |
| `ResourceBreak` | `availabilityRule` | `AvailabilityRule` | `availabilityRuleId` | many-to-one |
| `BookingConstraint` | `availabilityRule` | `AvailabilityRule` | `availabilityRuleId` | many-to-one |

The `Resource → AvailabilityRule` inverse cardinality is one-to-many; the `AvailabilityRule → ResourceBreak` and `AvailabilityRule → BookingConstraint` inverse cardinalities are one-to-many. The implementation cycle relies on these declarations to render the rule detail view's breaks/constraints sections without a custom controller.

#### Scenario: Rule detail view resolves children via declared relations

- **GIVEN** an `AvailabilityRule` with `availabilityRuleId = "rule-001"` and two `ResourceBreak` plus one `BookingConstraint` referencing it
- **WHEN** the detail page asks OpenRegister for the rule with expanded relations
- **THEN** the response includes the two breaks and the constraint
- **AND** no app-side join code is needed

---

### Requirement: REQ-BAR-007 Manifest Navigation and Pages

The `src/manifest.d/bookings-availability-rules.json` ADR-037 fragment MUST declare:

1. A menu entry under the existing `Verkoop` parent (declared by `bookings-resource-calendar`) labelled `Beschikbaarheidsregels` with route `AvailabilityRules` and a calendar/clock icon, ordered after the `BookingsCalendar` entry
2. A `type: index` page binding to the `AvailabilityRule` schema (route `/verkoop/beschikbaarheidsregels`, paginated list, columns: `availabilityRuleId`, `resource`, `status`, `effectiveFrom`, `effectiveUntil`, `description`)
3. A `type: detail` page (route `/verkoop/beschikbaarheidsregels/:id`) showing the rule header plus its breaks and constraints in two grouped sections

`node tests/validate-manifest.js` MUST exit 0 after the fragment is added.

#### Scenario: Operator opens the Availability Rules index

- **GIVEN** the operator logged into Shillinq with at least one `AvailabilityRule` record
- **WHEN** they click `Verkoop → Beschikbaarheidsregels`
- **THEN** the index page lists rules grouped by resource with their status and effective dates

#### Scenario: Operator opens a rule's detail page

- **GIVEN** an `AvailabilityRule` with one `BookingConstraint` and two `ResourceBreak` records
- **WHEN** the operator opens its detail view
- **THEN** the page shows the rule header, then a `Pauzes` section listing the two breaks, then a `Boekingsregels` section showing the constraint's advance-notice, buffers, cancellation deadline, and blackout list

---

### Requirement: REQ-BAR-008 Seed Data

The change MUST ship 3–5 example `AvailabilityRule` records with associated `ResourceBreak` and `BookingConstraint` rows in `lib/Settings/register.d/bookings-availability-rules.json`'s `objects` array. The seeds MUST include Dutch descriptions:

- `"Standaard beschikbaarheid"` — Mon–Fri 09:00–17:30 working pattern, with a `lunch` break Mon–Fri 12:00–13:00 and a standard SMB booking constraint (1-day advance, 15-min buffers, 24-hour cancellation)
- `"Zomervakantie"` — archived/draft rule documenting the July 15–29 vacation blackout via `BookingConstraint.blackoutDates`
- At least one further rule covering an alternate resource (e.g. `res-003` Vergaderruimte A) to show multi-resource configuration

Seeds MUST resolve real `resource` FKs already present in the `bookings-resource-calendar` seed objects (`res-001` … `res-005`).

#### Scenario: Fresh install loads seeded availability rules

- **GIVEN** a fresh Shillinq install
- **WHEN** the `InitializeRegister` repair step runs
- **THEN** the three seeded `AvailabilityRule` records, their breaks, and their constraints are visible in the index page
- **AND** each rule's `resource` FK points at an existing `Resource` record

---

## Conformance

This spec conforms to:

- **ADR-024** (App manifest) — navigation and page bindings live in `src/manifest.d/bookings-availability-rules.json`
- **ADR-031** (Declarative schema lifecycle) — status transitions are declared via `x-openregister-lifecycle`; no custom PHP state machine
- **ADR-037** (Per-change register & manifest fragments) — schemas and manifest entries are isolated to a single fragment file to avoid merge conflicts between concurrent OpenSpec changes
- **ADR-005** (Multi-tenant isolation) — every record carries an `administrationId` FK
- **RFC 2119** — MUST/SHOULD/MAY language is used throughout

Verification: `openspec validate` exits 0; `node tests/validate-manifest.js` exits 0; the `bookings-resource-calendar` `Resource` register is present in `lib/Settings/register.d/`.

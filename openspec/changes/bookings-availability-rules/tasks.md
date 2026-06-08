# Tasks — Booking Availability Rules

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookings-availability-rules`
> spec — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `AvailabilityRule`, `ResourceBreak`, or `BookingConstraint` schema and no `bookings-availability-rules` capability already exist (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)

- [x] Task 2: Confirm `bookings-resource-calendar` change has landed and `Resource` schema is available in `lib/Settings/shillinq_register.json` (verify FK target exists)

- [x] Task 3: Author `specs/bookings-availability-rules/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (booking foundation)` / `Depends on: bookings-resource-calendar` header, `REQ-BAR-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN — declaring the three-schema model, lifecycle transitions, recurrence rules for breaks, advance-notice and buffer constraints

- [x] Task 4: Author `proposal.md` referencing `bookings-resource-calendar` dependency, competitor evidence (17/21 market leaders), and risks (effective-date transition logic, recurrence pattern complexity) per shillinq config.yaml `rules.proposal`

- [x] Task 5: Author `design.md` with D1–D5 decision rationale (three-schema split, recurrence simplicity, discrete constraint fields, lifecycle, blackout-date handling), reuse analysis table vs. Cal.com/Cogsworth/Easy-Appointments/Salonized/Resy, and SMB bookkeeper persona acceptance criteria

- [x] Task 6: Declare the `AvailabilityRule` (header) schema in `lib/Settings/shillinq_register.json` with all fields (resourceId FK, status enum, effectiveFrom, effectiveUntil, description) typed per spec; `status` default: `draft`

- [x] Task 7: Declare the `ResourceBreak` schema in `lib/Settings/shillinq_register.json` with all fields (availabilityRuleId FK, breakType enum, dayOfWeek enum, startTime, endTime, isRecurring, status, description); add validation: `endTime > startTime`, valid time range

- [x] Task 8: Declare the `BookingConstraint` schema in `lib/Settings/shillinq_register.json` with all fields (availabilityRuleId FK, minAdvanceNotice, maxAdvanceNotice, preBufferMinutes, postBufferMinutes, cancellationDeadlineHours, blackoutDates array, status); add validation: non-negative fields, `maxAdvanceNotice >= minAdvanceNotice`

- [x] Task 9: Add `x-openregister-relations` FKs on `ResourceBreak` and `BookingConstraint`: `availabilityRuleId → AvailabilityRule.id` (one-to-many); on `AvailabilityRule`: `resourceId → Resource.id` (many-to-one, depends on `bookings-resource-calendar`)

- [x] Task 10: Add Availability Rules navigation + pages to `src/manifest.json` (menu entry `Bookings > Availability Rules`, `type: index` page binding to `AvailabilityRule`, `type: detail` page showing rule header + breaks + constraints together) per REQ-BAR-007; `node tests/validate-manifest.js` exits 0

- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with new entities: `AvailabilityRule`, `ResourceBreak`, `BookingConstraint`; add reconciliation note explaining three-schema design rationale per Tier 1 booking foundation

- [x] Task 12: Seed data: Insert 3–5 example `AvailabilityRule` records (Dutch values: "Standaard beschikbaarheid", "Zomervakantie") with associated `ResourceBreak` (lunch, coffee) and `BookingConstraint` records per design.md examples; use `openregister:repair-step` pattern for seeding

## Verification

`openspec validate` must exit clean on the change folder. SMB resource-scheduler
persona (`/test-persona-janwillem` or hairdresser equivalent) peer-reviews and
confirms:
- Working hours (Mon–Fri 09:00–17:30) are easy to configure
- Breaks (lunch 12:00–13:00) can be set per day
- Advance-notice constraint (1–30 days) is clear
- Vacation blocks (7/15–7/29) are straightforward
- Prep/cleanup buffers (15 min each) prevent double-booking

Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance
(no app-local audit; status lifecycle is declarative; manifest carries
navigation). No source code changes outside
`openspec/changes/bookings-availability-rules/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests**: `AvailabilityRule.status` transitions (draft → active →
  archived); `ResourceBreak.endTime > startTime` validation; `BookingConstraint`
  field constraints (non-negative, `max >= min`); invalid day-of-week rejection
- **PHPUnit integration tests**: Query availability for a resource on a given
  date (checks active rules, breaks, blackout dates); simulate booking check
  (respects advance-notice window, buffer times)
- **Playwright MCP browser tests**: Index page lists rules per resource; detail
  page shows breaks and constraints in read-only + edit modes; effective-date
  transitions trigger correctly; `node tests/validate-manifest.js` green
- `composer test` green at implementing PR's CI gate
- No new REST endpoints (OpenRegister exposes register CRUD generically)

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:
- `docs/user-guide/bookings/availability-rules.md` — step-by-step guide for
  SMB to configure working hours, breaks, holidays, and constraints per
  ADR-030 journeydoc convention
- Screenshot: Availability Rules index page (rule list, resource, status, effective date)
- Screenshot: Detail page (rule header, breaks table, constraints section with
  advance-notice + buffers + blackout calendar)
- Screenshot: Effective-date scheduling workflow

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Availability Rules`, `Working Hours`, `Break`, `Lunch`, `Coffee`, `Other`
- `Advance Notice`, `Buffer Time`, `Pre-Service Prep`, `Post-Service Cleanup`
- `Cancellation Deadline`, `Blackout Date`, `Vacation`, `Holiday`
- `Effective From`, `Effective Until`, `Draft`, `Active`, `Archived`
- Field names and validation messages

## Dependency Check

- [x] Verify `bookings-resource-calendar` change is merged before applying
      this spec (FK to `Resource` entity) — confirmed `Resource` schema
      present in `lib/Settings/register.d/bookings-resource-calendar.json`
      (slug `Resource`, properties `resourceId`/`type`/`name`/`organization`/
      `status`) and `x-openregister-relations` on `AvailabilityRule.resource`
      points at `relatedSchema: Resource`, `relatedField: resourceId` — FK
      target exists in the same register file the implementation cycle
      already shipped.
- [x] No circular dependencies (this spec does not introduce them) —
      verified: `bookings-resource-calendar/hydra.json` declares
      `depends_on: []` (no edge back to `bookings-availability-rules`) and
      this change's `hydra.json` lists only `bookings-resource-calendar`;
      the dependency graph is a one-way edge.

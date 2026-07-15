# Spec: bookings-recurring-series (delta — bookings-depth)

**Status:** in-progress
**Scope:** shillinq
**Kind:** feature (recurring-appointment-series leg of bookings-depth)

This delta realises the recurring appointment series that `bookings/spec.md` explicitly
deferred to Tier-2. It adds the `AppointmentSeries` schema, the `Appointment` recurrence
overlay, and `RecurringSeriesService`, which REUSES the existing `SlotService`
availability/conflict engine — it does NOT fork it.

@e2e exclude unbuilt UI: recurring-series operator pages not yet implemented; the
capability is exercised via the REST endpoint + PHPUnit.

## ADDED Requirements

### Requirement: REQ-BRS-001 AppointmentSeries register + Appointment recurrence overlay

The `AppointmentSeries` register SHALL be declared in
`lib/Settings/register.d/60-bookings-depth.json` with fields `administrationId`,
`seriesId`, `serviceId`, `resourceId`, `customerId` (optional), `startTime` (ISO-8601
UTC), `durationMinutes`, `recurrenceRule` (RRULE string), `status`
(`active|cancelled`, declarative `x-openregister-lifecycle`), `generatedCount`,
`skippedCount`. The `Appointment` register SHALL be extended (additive overlay) with
`seriesId` and `recurrenceIndex` (zero-based ordinal within its series).

#### Scenario: Create a recurring series

- **GIVEN** an authenticated operator with access to the administration
- **WHEN** they `POST /api/v1/appointment-series` with `serviceId`, `resourceId`,
  `startTime`, `durationMinutes` and a `recurrenceRule`
- **THEN** an `AppointmentSeries` is persisted with `status = active`
- **AND** one individual `Appointment` is persisted per generated occurrence, each tagged
  with `seriesId` and its zero-based `recurrenceIndex`

### Requirement: REQ-BRS-002 RRULE expansion

`OCA\Shillinq\Service\RecurringSeriesService::expandRule()` SHALL expand an RRULE-style
string into ordered UTC occurrence start instants, supporting `FREQ=DAILY|WEEKLY|MONTHLY`,
`INTERVAL` (default 1), `COUNT`, `UNTIL` (date-only inclusive), and `BYDAY` (WEEKLY). The
first occurrence SHALL be the series start. Open-ended rules SHALL be capped at
`MAX_OCCURRENCES = 366`. An unsupported or missing `FREQ` SHALL throw
`InvalidArgumentException` (the controller maps it to HTTP 400).

#### Scenario: Weekly BYDAY with COUNT

- **GIVEN** `FREQ=WEEKLY;BYDAY=MO;COUNT=4` starting Monday `2030-01-07T09:00:00Z`
- **WHEN** the rule is expanded
- **THEN** the occurrences are the four consecutive Mondays `2030-01-07`, `01-14`,
  `01-21`, `01-28` at `09:00:00Z`

#### Scenario: Daily interval and monthly day-of-month

- **GIVEN** `FREQ=DAILY;INTERVAL=2;COUNT=3` from `2030-03-01`
- **THEN** the occurrences are `2030-03-01`, `03-03`, `03-05`
- **AND GIVEN** `FREQ=MONTHLY;COUNT=3` from `2030-01-15`
- **THEN** the occurrences preserve the day-of-month: `2030-01-15`, `02-15`, `03-15`

### Requirement: REQ-BRS-003 Series generation respects existing availability/conflict rules

`RecurringSeriesService::planSeries()` SHALL, for each expanded occurrence, decide
availability by REUSING `SlotService::enumerateSlotsPublic` (resource opening/closing
hours + overlap with existing appointments) — it SHALL NOT implement a second conflict
algorithm. An occurrence whose exact start is an enumerated available slot SHALL become an
individual `Appointment` payload; an occurrence that is out of hours or overlaps an
existing (or earlier-generated) appointment SHALL be skipped with a reason. Earlier
-generated occurrences SHALL be folded back into the existing-appointment set so a later
occurrence cannot double-book the same window.

#### Scenario: Conflicting occurrence is skipped

- **GIVEN** a weekly Monday series of four occurrences and an existing appointment booking
  the `2030-01-14T09:00:00Z` window
- **WHEN** the series is planned
- **THEN** three appointments are generated and the `2030-01-14` occurrence is skipped
  with reason `unavailable`

#### Scenario: Out-of-hours occurrence is skipped

- **GIVEN** a series whose occurrences start at `08:00` while the resource opens at `09:00`
- **WHEN** the series is planned
- **THEN** every occurrence is skipped (reason `unavailable`) and no appointment is
  generated

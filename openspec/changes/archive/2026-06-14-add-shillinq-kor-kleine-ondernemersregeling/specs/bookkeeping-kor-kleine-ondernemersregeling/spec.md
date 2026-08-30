# Spec: bookkeeping-kor-kleine-ondernemersregeling

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-vat-btw-filing (T3)

## ADDED Requirements

### Requirement: REQ-KOR-001: The system SHALL administer Kleine-ondernemersregeling status as an OpenRegister-managed `KorRegime` register

For administrations of type `mkb` or `zzp`, shillinq MUST provide a
KOR opt-in/opt-out administration per Wet OB 1968 art. 25 (the
omzetgerelateerde vrijstellingsregeling). The KOR status is a
**stateful regime** with an annual €20.000 omzetdrempel; the
system MUST track YTD revenue, warn before the threshold is
crossed, and auto-switch regime when the threshold is exceeded.

Statutory basis: Wet OB 1968 art. 25 + Uitvoeringsbeschikking OB
1968 art. 25.

#### Scenario: A gemeente does not see KOR

- **GIVEN** an administration with `administrationType: "gemeente"`
- **WHEN** the KOR menu's visibility predicate evaluates
- **THEN** the KOR menu MUST NOT appear (gemeenten are not subject
  to KOR).

### Requirement: REQ-KOR-002: The `KorRegime` schema SHALL declare a fixed minimum field set

Schema.org annotation: `schema:GovernmentPermit` (KOR is an opt-in statutory exemption regime granted by the Belastingdienst; the record represents the administration's permit-like enrolment status, not just a static term).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to the administration owning the regime |
| `state` | enum | Yes | `outside` (default; not opted in), `opted-in`, `threshold-warning` (80%), `threshold-exceeded` (≥100%), `opted-out` |
| `optedInAt` | date | No | Date of formal opt-in (Belastingdienst-reported) |
| `optedOutAt` | date | No | Date of opt-out or auto-switch |
| `currentCalendarYear` | integer | Yes | The year tracked by `ytdRevenue` |
| `ytdRevenue` | number | Yes | Derived via `x-openregister-calculations` from `Invoice` (T2) within the calendar year |
| `thresholdAmount` | number | Yes | Statutory threshold (currently €20.000); read from `kor-thresholds-2026.json` seed |
| `warningPercentage` | integer | Yes | Default 80 (warning fires at 80% of threshold); from seed |
| `notes` | string | No | Operator-authored context (e.g. "Opt-out due to ICP-omzet uitsluiting") |

#### Scenario: A new MKB admin defaults to `outside`

- **GIVEN** a fresh MKB administration is created
- **WHEN** the `KorRegime` is initialised
- **THEN** the record MUST have `state: "outside"`.

### Requirement: REQ-KOR-003: KOR thresholds SHALL be seeded, not hard-coded

A `kor-thresholds-2026.json` seed file MUST ship under
`lib/Settings/seeds/` and be loaded into a `KorThreshold` register
on first install. The seed carries the current statutory threshold
(€20.000), the warning percentage (80%), and an
`effectiveFrom`/`effectiveTo` window. Per ADR-031, thresholds are
NOT baked as schema enums (they have changed historically: the
KOR before 2020 was a sliding scale; the post-2020 regime is a
fixed €20.000 ceiling).

Schema.org annotation for `KorThreshold`: `schema:DefinedTerm` (a versioned statutory parameter — a controlled-vocabulary entry keyed by `effectiveFrom`/`effectiveTo`).

#### Scenario: A future threshold change is a seed update, not a code change

- **GIVEN** a hypothetical future statute raises the threshold to
  €25.000 effective 2028
- **WHEN** a new seed file `kor-thresholds-2028.json` is shipped
- **THEN** the `KorThreshold` register MAY hold both records with
  non-overlapping `effectiveFrom`/`effectiveTo` windows; the
  lifecycle precondition (REQ-KOR-005) MUST read the active
  threshold for the current calendar year.

### Requirement: REQ-KOR-004: YTD revenue SHALL be derived via declarative aggregation, not a service method

The `ytdRevenue` field MUST be populated via
`x-openregister-calculations` aggregating `Invoice` (T2 AR
sub-ledger) records for the same `administrationId` within the
`currentCalendarYear`. Per ADR-031, no `KorRevenueCalculator`
service.

If OR's calculation engine cannot express the period-filter
aggregation (cross-period sums in a derived field), ADR-031
exception path applies: a thin single-method PHP guard
`KorThresholdGuard::currentYtdRevenue(string $adminId, int $year): float`
is permitted (~30 LOC, no state). The choice is resolved in the
implementing cycle's `opsx-ff` discovery and documented in
design.md's Declarative-vs-imperative table.

#### Scenario: An invoice issued raises YTD revenue

- **GIVEN** `KorRegime` for `mkb-a` shows `ytdRevenue: 15000` for
  2026
- **WHEN** a new invoice for €1.500 is issued
- **THEN** the next read of `ytdRevenue` MUST return €16.500.

#### Scenario: Reviewer scans for forbidden service

- **GIVEN** the shillinq codebase post-implementation
- **WHEN** scanned for `lib/Service/Kor*.php`
- **THEN** at most one file (the optional guard) SHALL exist,
  AND if present it MUST carry an ADR-031 exception annotation.

### Requirement: REQ-KOR-005: The `KorRegime` lifecycle SHALL automatically transition on threshold crossings

The lifecycle MUST declare the following transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `outside` | `opted-in` | operator action | none (KOR opt-in is operator-elected with Belastingdienst notification) |
| `opted-in` | `threshold-warning` | `ytdRevenue` reaches `thresholdAmount × warningPercentage / 100` (i.e. 80% of €20.000 = €16.000) | none |
| `threshold-warning` | `threshold-exceeded` | `ytdRevenue` reaches `thresholdAmount` (i.e. ≥ €20.000) | none |
| `threshold-warning` | `opted-in` | calendar year rollover (Jan 1) AND `ytdRevenue` resets | none |
| `threshold-exceeded` | `opted-out` | operator action OR auto-trigger per administration policy | none |
| `opted-in` | `opted-out` | operator action | none |
| `opted-out` | `outside` | operator action (after the 3-year KOR lock-out per Wet OB 1968 art. 25 lid 3) | the 3-year lock-out MUST have expired |

The threshold-crossing transitions are **automatic** — they fire
when the `ytdRevenue` calculation crosses the threshold. Per
ADR-031, this is a declarative
calculation-triggered-lifecycle-transition, not a cron job.

#### Scenario: Crossing 80% transitions to warning

- **GIVEN** `mkb-a` is in `opted-in` with `ytdRevenue: 15800`
- **WHEN** a new invoice for €500 is posted (bringing `ytdRevenue`
  to €16.300, > 80% × €20.000)
- **THEN** the regime state MUST transition to `threshold-warning`
  AND a notification (REQ-KOR-007) MUST fire.

#### Scenario: Crossing 100% transitions to exceeded

- **GIVEN** `mkb-a` is in `threshold-warning` with `ytdRevenue:
  19800`
- **WHEN** a new invoice for €500 is posted (bringing
  `ytdRevenue` to €20.300, ≥ threshold)
- **THEN** the regime state MUST transition to
  `threshold-exceeded`.

### Requirement: REQ-KOR-006: An opt-out due to threshold-exceeded MUST NOT auto-post the KOR-vrijval journal entry

When the regime transitions `threshold-exceeded → opted-out`, the
operator typically must reverse previously vrijgestelde BTW. The
system MUST surface a journal-entry template (per T1
`bookkeeping-journal-entries`) for the operator's review. The
journal entry MUST NOT auto-post — operator approval is required
(per ADR-022 approval-workflow consumption).

#### Scenario: Auto-trigger does not bypass approval

- **GIVEN** `mkb-a` transitions `threshold-exceeded → opted-out`
- **WHEN** the lifecycle's post-transition action fires
- **THEN** a `JournalEntry` of type `manual` MUST be created with
  `state: "pending"` (NOT `posted`); the operator + accountant
  MUST explicitly approve the entry before it lands in the GL.

### Requirement: REQ-KOR-007: Threshold-warning and -exceeded events SHALL emit notifications via `x-openregister-notifications`

The `outside → opted-in`, `opted-in → threshold-warning`,
`threshold-warning → threshold-exceeded`, and any →`opted-out`
transitions MUST emit notifications via
`x-openregister-notifications` declarations. Recipients:
operators with role `vat-administrator` (T3 bookkeeping-vat-btw-
filing) for the administration. Per ADR-022, no app-local
notification service.

#### Scenario: The warning notification reaches the vat-administrator

- **GIVEN** `mkb-a` transitions `opted-in → threshold-warning`
- **WHEN** the notification fires
- **THEN** an NC notification MUST appear for every user holding
  the `vat-administrator` role on `mkb-a`, with a message of the
  form "KOR-omzet bedraagt €16.300 (82% van de drempel)".

### Requirement: REQ-KOR-008: A KOR-status widget SHALL be declared via `x-openregister-widgets`

A KOR-status widget MUST be declared via `x-openregister-widgets`
on the `KorRegime` schema, consumable by `CnDashboardPage` (per
ADR-024). The widget MUST surface the current `state`, the
`ytdRevenue` progress bar against `thresholdAmount`, and the
warning/exceeded thresholds. Per ADR-031, no bespoke Vue widget
component.

#### Scenario: A dashboard renders the KOR widget

- **GIVEN** an MKB operator opens the shillinq dashboard
- **WHEN** the dashboard renders
- **THEN** the KOR widget MUST appear showing `ytdRevenue` /
  `thresholdAmount` with appropriate green/yellow/red state.

### Requirement: REQ-KOR-009: KOR administration SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Belastingen
> KOR-status` with a `type: detail` page binding to `KorRegime`
(one record per administration). Visibility MUST be predicated on
`administrationType ∈ {mkb, zzp}`.

#### Scenario: An operator opens the KOR page

- **GIVEN** an MKB operator navigates to `Belastingen > KOR-status`
- **WHEN** the page renders
- **THEN** the page MUST show the current state, opt-in/opt-out
  dates, YTD revenue, threshold, and the action buttons for
  `opt-in`/`opt-out`/`acknowledge-warning`.

### Requirement: REQ-KOR-010: Audit trail and retention SHALL be consumed from OR's abstractions

Every `KorRegime` operation MUST be audited via OR's
audit-trail-immutable (ADR-022). Retention MUST be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }`
(financial records — 7 years per AWR art. 52).

#### Scenario: A historical opt-in/opt-out is queryable

- **GIVEN** an MKB operator opted in on 2020-01-01 and opted out
  on 2023-06-15 (then back outside after 2026-06-15)
- **WHEN** the audit trail is queried
- **THEN** all three transitions MUST appear with their actor
  identifiers and timestamps.

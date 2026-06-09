# Spec: bookkeeping-zzp-tax-regime

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-general-ledger (T1)

## ADDED Requirements

### Requirement: REQ-ZZP-001 — ZZP-specific tax regime data SHALL be administered as OpenRegister-managed registers

For administrations of type `zzp` (zelfstandigen-zonder-personeel), shillinq MUST provide:

1. **`UrenRegistratie`** — a register tracking billable + non-
   billable hours per operator per day, used for the 1225-uren-
   criterium (Wet IB 2001 art. 3.6).
2. **`ZzpDeduction`** — a register tracking annual deductions
   (`zelfstandigenaftrek`, `startersaftrek`,
   `MKB-winstvrijstelling`), derived via
   `x-openregister-calculations` from `UrenRegistratie` + GL
   revenue.
3. **`IbAangifteExport`** — a per-tax-year export of fields ready
   for the IB-aangifteformulier (Belastingdienst).

No PHP `ZzpService` or `IbAangifteService` — per ADR-031, the
state machines + calculations are declarative.

Statutory basis: Wet IB 2001 art. 3.6 (urencriterium), art. 3.76
(zelfstandigenaftrek), art. 3.77 (startersaftrek), art. 3.79a
(MKB-winstvrijstelling).

#### Scenario: A non-ZZP admin does not see ZZP menus

- **GIVEN** an administration with `administrationType: "mkb"` or
  `"gemeente"`
- **WHEN** the dashboard renders
- **THEN** the ZZP menus MUST NOT appear.

### Requirement: REQ-ZZP-002 — The `UrenRegistratie` schema SHALL declare a fixed minimum field set

The `UrenRegistratie` schema MUST declare the following fields with the listed required/optional flag, used by the urencriterium tracker and by the IB-aangifte export:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to ZZP administration |
| `personId` | string | Yes | FK to the operator (typically the ZZP-er themselves) |
| `date` | date | Yes | The day of the hour record |
| `hours` | number | Yes | Decimal hours (e.g. `7.5`) |
| `category` | enum | Yes | `billable`, `non-billable-admin`, `non-billable-acquisition`, `non-billable-training`, `excluded` |
| `excludedReason` | enum | No (required if `category: "excluded"`) | `sick`, `parental-leave`, `vacation` |
| `description` | string | No | Operator-authored context |
| `projectId` | string | No | FK to a `Project` (T3 bookkeeping-consultancy-project-accounting) if applicable |

The `excludedReason` field is critical for the urencriterium —
sick/parental-leave/vacation hours are EXCLUDED from the 1225
count per Wet IB 2001 art. 3.6 lid 4.

#### Scenario: A minimal billable hour record validates

- **GIVEN** the schema
- **WHEN** an object with `administrationId: "zzp-a"`, `personId:
  "user-1"`, `date: "2026-03-15"`, `hours: 7.5`, `category:
  "billable"` is created
- **THEN** validation MUST pass.

#### Scenario: An excluded record requires a reason

- **GIVEN** the schema
- **WHEN** an object with `category: "excluded"` but no
  `excludedReason` is saved
- **THEN** the save MUST fail with a precondition error.

### Requirement: REQ-ZZP-003 — YTD hours toward the 1225-criterium SHALL be a declarative calculation

A derived field `ytdQualifyingHours` MUST be declared via `x-openregister-calculations` on a `ZzpDeduction` record summing
`UrenRegistratie.hours` for the same `(administrationId, personId,
calendarYear)` where `category ∈ {billable, non-billable-admin,
non-billable-acquisition, non-billable-training}` (all the
non-excluded categories count toward the criterium).

If OR's calculation engine cannot express the period-filter
aggregation, ADR-031 exception path applies: a thin single-method
PHP guard `UrencriteriumGuard::currentYtdHours(string $personId,
int $year): float` is permitted (~30 LOC, no state). Choice
resolved in implementing cycle's `opsx-ff`.

#### Scenario: Adding billable hours raises YTD count

- **GIVEN** `ZzpDeduction` for `user-1` shows
  `ytdQualifyingHours: 1100` for 2026
- **WHEN** 8 new billable hours are recorded
- **THEN** the next read MUST return `ytdQualifyingHours: 1108`.

#### Scenario: Excluded hours do not count

- **GIVEN** `user-1` records 40 `excluded` (sick) hours
- **WHEN** `ytdQualifyingHours` is recalculated
- **THEN** the value MUST NOT increase by those 40 hours.

### Requirement: REQ-ZZP-004 — A urencriterium-tracking widget SHALL be declared via `x-openregister-widgets`

A widget MUST be declared via `x-openregister-widgets` on
`UrenRegistratie` (or the `ZzpDeduction` derived view), showing
`ytdQualifyingHours` against the 1225 (or 800 for starters-
opvolgers) threshold, with appropriate green/yellow/red bands.
Threshold values seeded from `urencriterium-thresholds.json` per
REQ-ZZP-007.

Threshold-warning notifications MUST fire via
`x-openregister-notifications` when YTD hours fall below a
projected end-of-year value of 1225 — concretely, at the end of
each month, if `ytdQualifyingHours × (12 / month) < 1225` a
notification fires warning of dreigend onderschrijden.

#### Scenario: Mid-year underperformance warns

- **GIVEN** at end of June 2026, `user-1` has
  `ytdQualifyingHours: 500` (projected EOY: 1000 < 1225)
- **WHEN** the monthly threshold-projection workflow runs
- **THEN** an NC notification MUST appear for `user-1` warning
  "Dreigend onderschrijden urencriterium (projectie: 1000 uur)".

### Requirement: REQ-ZZP-005 — The `ZzpDeduction` schema SHALL hold derived annual deduction values

The `ZzpDeduction` schema MUST hold the per-tax-year derived deduction values listed below; every monetary derivation MUST be expressed as `x-openregister-calculations` (no `ZzpDeductionCalculator` service per ADR-031).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | |
| `personId` | string | Yes | |
| `taxYear` | integer | Yes | Calendar year |
| `ytdQualifyingHours` | number | Yes | Derived (REQ-ZZP-003) |
| `qualifiesForUrencriterium` | boolean | Yes | Derived: `ytdQualifyingHours >= 1225` (or 800 for starters-opvolgers) |
| `zelfstandigenaftrekAmount` | number | Yes | Derived per Wet IB 2001 art. 3.76 — currently €3.750 for 2024+; seeded |
| `startersaftrekAmount` | number | Yes | Derived per Wet IB 2001 art. 3.77 — currently €2.123, claimable in max 3 of the first 5 years |
| `mkbWinstvrijstellingPercentage` | number | Yes | Currently 12.7% per Wet IB 2001 art. 3.79a |
| `mkbWinstvrijstellingAmount` | number | Yes | Derived: `(profit - zelfstandigenaftrek - startersaftrek) × mkbWinstvrijstellingPercentage` |
| `totalDeduction` | number | Yes | Sum of the three above; derived |

Every monetary derivation MUST be expressed as
`x-openregister-calculations`. Per ADR-031, no
`ZzpDeductionCalculator` service.

#### Scenario: A qualifying ZZP-er with €60.000 profit gets the right deduction

- **GIVEN** `user-1` qualifies for urencriterium in 2026 and
  has €60.000 reportable profit
- **WHEN** `ZzpDeduction` for 2026 is computed
- **THEN** `zelfstandigenaftrekAmount` MUST equal the seeded value
  (currently €3.750); IF the person is also a starter,
  `startersaftrekAmount` MUST equal €2.123; AND the
  `mkbWinstvrijstellingAmount` MUST equal `(60000 - 3750 - 2123) ×
  0.127` per Wet IB 2001 art. 3.79a.

### Requirement: REQ-ZZP-006 — The IB-aangifteformulier export SHALL be an `IbAangifteExport` register

The IB-aangifteformulier export MUST be persisted as an `IbAangifteExport` register with the field set below; export aggregations MUST be declarative (`x-openregister-aggregations` on GL) and PDF/XML generation MAY be deferred to docudesk per ADR-022.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | |
| `personId` | string | Yes | |
| `taxYear` | integer | Yes | |
| `state` | enum | Yes | `draft`, `ready`, `exported` |
| `revenue` | number | Yes | Derived from GL (T1) for the year |
| `costs` | number | Yes | Derived from GL |
| `profit` | number | Yes | Derived (`revenue - costs`) |
| `ytdQualifyingHours` | number | Yes | Copy from `ZzpDeduction` |
| `deductions` | object | Yes | Copy from `ZzpDeduction` (zelfstandigenaftrek, startersaftrek, mkb-winstvrijstelling, total) |
| `attachmentUri` | string | No | docudesk URI of the rendered PDF (operator can hand-key into the Belastingdienst portal or upload the XML) |

The export aggregations MUST be declarative
(`x-openregister-aggregations` on GL); the PDF generation MAY be
deferred to docudesk's templating engine (per ADR-022 — docudesk
is the document-generation abstraction).

#### Scenario: A tax-year export aggregates the full year

- **GIVEN** `zzp-a` has closed 2026's fiscal periods (per T2
  period-close)
- **WHEN** an `IbAangifteExport` for 2026 is generated
- **THEN** `revenue`, `costs`, and `profit` MUST be aggregated
  from the 2026 GL postings; `deductions` MUST equal the values
  computed in REQ-ZZP-005.

### Requirement: REQ-ZZP-007 — Urencriterium and deduction thresholds SHALL ship as versioned seed data

Urencriterium thresholds and ZZP deduction amounts MUST ship as versioned seed JSON files under `lib/Settings/seeds/`, each record carrying `effectiveFrom`/`effectiveTo` so future revisions coexist:

`lib/Settings/seeds/urencriterium-thresholds.json` MUST hold:

| Record | Threshold | Source |
|---|---|---|
| `urencriterium-default` | 1225 | Wet IB 2001 art. 3.6 lid 1 |
| `urencriterium-starters-opvolgers` | 800 | Wet IB 2001 art. 3.6 lid 2 |

A separate `zzp-deduction-amounts-2026.json` MUST hold:

| Field | Value | Source |
|---|---|---|
| `zelfstandigenaftrek` | €3.750 (2024+) | Belastingdienst aftrekoverzicht |
| `startersaftrek` | €2.123 | Belastingdienst |
| `startersaftrekMaxYears` | 3 of first 5 | Wet IB 2001 art. 3.77 |
| `mkbWinstvrijstellingPercentage` | 0.127 (12.7%) | Wet IB 2001 art. 3.79a |

All records carry `effectiveFrom`/`effectiveTo` windows so future
revisions coexist.

#### Scenario: A future amount change is a seed update

- **GIVEN** a hypothetical 2027 statute reduces
  zelfstandigenaftrek to €2.470
- **WHEN** a new `zzp-deduction-amounts-2027.json` seed is shipped
- **THEN** the existing 2026 record MUST remain (closed by
  `effectiveTo: "2026-12-31"`) AND a new 2027 record MUST be
  added; `ZzpDeduction` for tax-year 2027 MUST use the new amount.

### Requirement: REQ-ZZP-008 — ZZP administration SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare navigation entries, predicated on `administrationType: "zzp"`:

- `Belastingen > Urenregistratie` — `type: index` on
  `UrenRegistratie` + `type: detail` for daily entry editing.
- `Belastingen > ZZP-aftrek` — `type: detail` on `ZzpDeduction`
  (one per person per year).
- `Belastingen > IB-aangifte` — `type: index` + `type: detail` on
  `IbAangifteExport`.

Visibility predicated on `administrationType: "zzp"`.

#### Scenario: A ZZP-er navigates to urenregistratie

- **GIVEN** a ZZP operator navigates to `Belastingen >
  Urenregistratie`
- **WHEN** the page renders
- **THEN** the page MUST render via `CnIndexPage` with columns
  (date, hours, category, projectId) and a "quick add row" UI.

### Requirement: REQ-ZZP-009 — Audit trail and retention SHALL be consumed from OR's abstractions

Every `UrenRegistratie`, `ZzpDeduction`, and `IbAangifteExport` operation MUST be audited via OR's audit-trail-immutable (ADR-022), and retention MUST be declared via `x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }` (financial records — 7 years per AWR art. 52). No app-local audit table.

#### Scenario: A historical hours record is queryable after 5 years

- **GIVEN** a `UrenRegistratie` from 2021
- **WHEN** queried in 2026 (within 7-year retention)
- **THEN** the record MUST be returned with the audit trail intact.

---
status: done
---

# Spec: bookkeeping-consultancy-project-accounting

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-general-ledger (T1), bookkeeping-accounts-receivable-core (T2)

## Purpose

This specification defines the requirements for bookkeeping consultancy project accounting in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: project accounting pages not yet implemented


### REQ-CPA-001: The system SHALL administer multi-project consultancy work as OpenRegister-managed registers

The system SHALL satisfy this requirement: The system SHALL administer multi-project consultancy work as OpenRegister-managed registers.

For administrations of type `mkb` or `zzp` (in particular the
consultancy operator profile — Conduction's own primary customer
profile), shillinq MUST provide multi-project tracking covering:

1. **`Project`** — the project header (offerte → active → on-hold
   → closed → archived).
2. **`ProjectAssignment`** — the per-person assignment to a project
   with rate-card reference.
3. **`BillableHour`** — already declared by T3 bookkeeping-zzp-tax-
   regime as `UrenRegistratie`; CPA reuses with an optional
   `projectId` FK.
4. **`RateCard`** — the rate-card register (junior/medior/senior/
   partner with `effectiveFrom`/`effectiveTo`).
5. **`WipBalance`** — work-in-progress snapshot per project per
   period.

No PHP `ProjectService`, `WipService`, or `RevenueRecognitionService` —
per ADR-031, the state machines, aggregations, calculations are
declarative.

Statutory basis (revenue recognition): RJ 270 (Richtlijnen voor de
Jaarverslaggeving §270 — opbrengsten) + IFRS 15 (for operators
applying full IFRS).

#### Scenario: A non-consultancy admin does not see project menus

- **GIVEN** an administration with `administrationType: "gemeente"`
- **WHEN** the dashboard renders
- **THEN** the Projecten menu MUST NOT appear (gemeenten use
  T3 bookkeeping-subsidie-verantwoording, not project accounting).

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `project_`,
  `wip_`, or `revenue_`
- **THEN** no such classes SHALL exist.

### REQ-CPA-002: The `Project` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `Project` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `projectNumber` | string | Yes | Unique per administration |
| `name` | string | Yes | Project name |
| `customerId` | string | Yes | FK to a customer contact record |
| `state` | enum | Yes | `offerte`, `active`, `on-hold`, `closed`, `archived` |
| `startDate` | date | No | Activation date |
| `endDate` | date | No | Target close date |
| `totalContractValue` | number | Yes | Total contracted revenue (operator-set) |
| `totalEstimatedCosts` | number | Yes | Total estimated costs (operator-set; revised over project life) |
| `costsIncurredToDate` | number | Yes | Derived from GL via `x-openregister-aggregations` (REQ-CPA-006) |
| `recognisedRevenue` | number | Yes | Derived via `x-openregister-calculations` (REQ-CPA-007) |
| `billedRevenue` | number | Yes | Derived from invoices (T2 AR) referencing this project |
| `wipBalance` | number | Yes | Derived: `recognisedRevenue - billedRevenue` |
| `recognitionMethod` | enum | Yes | `percentage-of-completion-cost-to-cost`, `percentage-of-completion-output`, `completed-contract` |
| `recognitionStage` | enum | Yes | From `rj-270-stages.json` seed: `initiation`, `execution`, `closeout`, `complete` |

#### Scenario: A minimal project validates

- **GIVEN** the schema
- **WHEN** an object with `administrationId: "mkb-a"`,
  `projectNumber: "PRJ-2026-001"`, `name: "OR-rollout client X"`,
  `customerId: "cust-1"`, `state: "active"`, `totalContractValue:
  100000`, `totalEstimatedCosts: 70000`, `recognitionMethod:
  "percentage-of-completion-cost-to-cost"` is created
- **THEN** validation MUST pass.

### REQ-CPA-003: The `Project` lifecycle SHALL be declarative per ADR-031

The system SHALL satisfy this requirement: The `Project` lifecycle SHALL be declarative per ADR-031.

| From | To | Trigger | Guard |
|---|---|---|---|
| (new) | `offerte` | operator action | none |
| `offerte` | `active` | operator action | `startDate` MUST be set + approval-workflow `requires` (project-administrator + customer-signed-offerte verification SHOULD apply) |
| `active` | `on-hold` | operator action | reason field MUST be set |
| `on-hold` | `active` | operator action | none |
| `active` | `closed` | operator action | `wipBalance` SHOULD be zero (or operator-justified) + final invoice issued (per T2 AR) |
| `closed` | `archived` | calendar trigger (e.g. 12 months post-close) | none |

Per ADR-031 anti-pattern list, shillinq MUST NOT author a
`ProjectLifecycleService`.

#### Scenario: A project with open WIP cannot close cleanly without justification

- **GIVEN** a `Project` in `active` state with `wipBalance: 5000`
- **WHEN** the operator triggers `close`
- **THEN** the transition MUST either fail or surface a warning
  requiring operator justification (per the schema's lifecycle
  precondition).

### REQ-CPA-004: The `ProjectAssignment` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `ProjectAssignment` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `projectId` | string | Yes | FK to `Project.id` |
| `personId` | string | Yes | FK to the assigned person |
| `rateCardId` | string | Yes | FK to a `RateCard` record (rate at assignment time) |
| `recognisedRate` | number | Yes | Snapshot of the rate at assignment (per RJ 270 §3.2.4 — see REQ-CPA-009) |
| `estimatedHours` | number | No | Operator estimate of hours this person spends on this project |
| `startDate`, `endDate` | date | Yes / No | Assignment window |
| `state` | enum | Yes | `planned`, `active`, `completed` |

#### Scenario: A planned assignment can be activated

- **GIVEN** a `ProjectAssignment` in `state: "planned"`
- **WHEN** the operator transitions to `active`
- **THEN** the save MUST succeed AND `BillableHour` records may
  then reference this assignment's `projectId` + `personId`.

### REQ-CPA-005: The `RateCard` schema SHALL declare per-level rates with effectivity windows

The system SHALL satisfy this requirement: The `RateCard` schema SHALL declare per-level rates with effectivity windows.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `level` | enum | Yes | `junior`, `medior`, `senior`, `partner` (operator-extensible via seed) |
| `currency` | string (ISO 4217) | Yes | |
| `hourlyRate` | number | Yes | Rate per hour |
| `effectiveFrom` | date | Yes | |
| `effectiveTo` | date | No | Nullable (currently-valid) |
| `_meta.source` | string | No | `"seeded"` or `"operator-edited"` |

Default rate-card templates ship as
`lib/Settings/seeds/rate-card-templates.json`. Per ADR-022, no
parallel rate-storage table.

#### Scenario: Two rate cards for the same level can have non-overlapping windows

- **GIVEN** `mkb-a` has a `partner` rate of €180/hour with
  `effectiveFrom: "2025-01-01"`, `effectiveTo: "2025-12-31"`
- **WHEN** a new record is added with `effectiveFrom: "2026-01-01"`,
  `hourlyRate: 195`
- **THEN** the save MUST succeed AND the two records coexist.

### REQ-CPA-006: Project costs SHALL be derived from GL aggregations, not service-side recompute

The `costsIncurredToDate` field MUST be populated via
`x-openregister-aggregations` over `GLLine` (T1) joined with
`Account` (T1) filtered by `accountType: "expenses"` AND tagged
to the project (via a `projectId` FK on `GLLine` — already part
of T1's optional fields, or added if not). Per ADR-031, no
`ProjectCostAggregationService`.

The project tagging on a `GLLine` happens either:
- Automatically when the line stems from a `BillableHour` with a
  `projectId` (the hours-to-GL recognition posting carries the
  project ref).
- Manually when the operator posts a journal entry with a project
  ref (e.g. reimbursable expenses).

#### Scenario: A project's costs reflect tagged GL postings

- **GIVEN** `Project` `PRJ-001` has €25.000 of GL postings on
  cost accounts tagged with its `projectId`
- **WHEN** `costsIncurredToDate` is read
- **THEN** the value MUST equal €25.000.

### REQ-CPA-007: Recognised revenue SHALL be a declarative calculation per RJ 270 / IFRS 15

The system SHALL satisfy this requirement: Recognised revenue SHALL be a declarative calculation per RJ 270 / IFRS 15.

For `recognitionMethod: "percentage-of-completion-cost-to-cost"`,
the `recognisedRevenue` field MUST be derived via
`x-openregister-calculations`:

```
recognisedRevenue = totalContractValue × (costsIncurredToDate / totalEstimatedCosts)
```

For `recognitionMethod: "percentage-of-completion-output"`, the
calculation uses an output-based percentage (operator-supplied
field `percentageComplete`).

For `recognitionMethod: "completed-contract"`, `recognisedRevenue`
remains `0` until `state: "closed"`, then becomes
`totalContractValue`.

Per ADR-031, no `RevenueRecognitionService`.

Statutory basis: RJ 270 §3.2 + IFRS 15 §B14-B19 (cost-to-cost is
explicitly enumerated as a permissible input method).

#### Scenario: A 50%-complete project recognises 50% revenue

- **GIVEN** `PRJ-001` with `totalContractValue: 100000`,
  `totalEstimatedCosts: 70000`, `costsIncurredToDate: 35000`,
  `recognitionMethod: "percentage-of-completion-cost-to-cost"`
- **WHEN** `recognisedRevenue` is read
- **THEN** the value MUST equal €50.000 (100.000 × 35.000 / 70.000).

#### Scenario: A completed-contract project recognises only on close

- **GIVEN** `PRJ-002` with `recognitionMethod:
  "completed-contract"` AND `state: "active"`
- **WHEN** `recognisedRevenue` is read
- **THEN** the value MUST equal `0`.

#### Scenario: Negative percentage is rejected

- **GIVEN** the calculation engine encounters
  `costsIncurredToDate: -5000` (somehow — typically prevented by
  T1's amount validation, but defensive coverage)
- **WHEN** the calculation runs
- **THEN** the engine MUST clamp the percentage to 0 (not produce
  negative recognised revenue).

### REQ-CPA-008: WIP balance SHALL be a derived field and SHOULD snapshot per period via scheduled workflow

`wipBalance = recognisedRevenue - billedRevenue` MUST be available
as a derived field on `Project` via `x-openregister-calculations`.
For period-end reporting, a `WipBalance` register record MUST be
generated by an OR `ScheduledWorkflow` (per ADR-031) at each
period close (T2). One record per project per period.

| `WipBalance` field | Type | Purpose |
|---|---|---|
| `projectId` | string | FK |
| `periodId` | string | FK to `FiscalPeriod` (T2) |
| `recognisedRevenue` | number | Snapshot |
| `billedRevenue` | number | Snapshot |
| `wipBalance` | number | Snapshot |
| `costsIncurredToDate` | number | Snapshot |
| `createdAt` | datetime | Set at snapshot |

#### Scenario: A period close generates WIP snapshots for every active project

- **GIVEN** `mkb-a` closes Q1 2026 (T2 period-close) with 5
  active projects
- **WHEN** the WIP snapshot workflow runs
- **THEN** 5 `WipBalance` records MUST exist with `periodId`
  matching Q1 and the correct per-project values.

### REQ-CPA-009: Multi-rate-card boundaries SHALL snapshot the rate at hour-posting time

The system SHALL snapshot the applicable rate at hour-posting time so multi-rate-card boundaries remain auditable.

When a `BillableHour` is posted with `projectId` set, the system
MUST snapshot the applicable `RateCard.hourlyRate` at write time
into a new field `BillableHour.recognisedRate`. Per RJ 270 §3.2.4
(performance obligations satisfied at transaction-price as of the
date the obligation was satisfied), revenue recognition uses the
rate effective on the date the work was performed, not the date of
invoicing.

#### Scenario: Hours logged before a rate change use the old rate

- **GIVEN** `partner` rate is €180 until 2025-12-31 and €195 from
  2026-01-01
- **WHEN** a partner logs 8 hours on 2025-12-15 (after the rate
  change but for work performed on 2025-12-15)
- **THEN** `BillableHour.recognisedRate` MUST equal 180 (the
  rate effective on the work date, per RJ 270 §3.2.4).

### REQ-CPA-010: Project P&L SHALL be a declarative aggregation, not a service

A `projectPnl` aggregation MUST be declared via
`x-openregister-aggregations` returning, per project:

- `recognisedRevenue` (from REQ-CPA-007)
- `costsIncurredToDate` (from REQ-CPA-006)
- `grossMargin` (derived: `recognisedRevenue - costsIncurredToDate`)
- `grossMarginPercentage` (derived: `grossMargin / recognisedRevenue × 100`)

Per ADR-031, no `ProjectPnlService`.

#### Scenario: A project's P&L is queryable

- **GIVEN** `PRJ-001` with `recognisedRevenue: 50000` and
  `costsIncurredToDate: 35000`
- **WHEN** the `projectPnl` aggregation is queried
- **THEN** the result MUST contain `grossMargin: 15000` and
  `grossMarginPercentage: 30`.

### REQ-CPA-011: Utilisation SHALL be a declarative calculation per person per period

A `utilization` derived field MUST be available per person per
period via `x-openregister-calculations`:

```
utilization = billableHoursThisPeriod / capacityHoursThisPeriod
```

Where `capacityHoursThisPeriod` is sourced from a per-person
`capacityHoursPerWeek` operator-set field (default 40), multiplied
by working weeks in the period. Per ADR-031, no
`UtilizationService`.

#### Scenario: A 75% utilisation surfaces

- **GIVEN** `user-1` has 130 billable hours in Q1 2026 and
  capacity of `40 hours/week × 13 weeks − 12 holiday hours =
  508 hours` for Q1, the simplest computation
- **WHEN** the utilisation aggregation runs
- **THEN** the result MUST be approximately 0.26 (rounded
  reasonably; the spec does not pin the holiday-hour shape — that
  is an administration-policy parameter).

### REQ-CPA-012: Project administration SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare navigation entries:

- `Projecten > Overzicht` — `type: index` on `Project`.
- `Projecten > Detail` — `type: detail` on `Project`, surfacing
  P&L, WIP, recognised vs billed revenue, assignments grid, hour
  rollup.
- `Projecten > Tarieven` — `type: index` on `RateCard`.
- `Projecten > Utilisatie` — `type: dashboard` showing the
  utilisation widget.

Visibility predicated on `administrationType ∈ {mkb, zzp}` AND
operator role `project-administrator` granted.

#### Scenario: A project-administrator drills into a project

- **GIVEN** a `project-administrator` opens the Projecten index
- **WHEN** they click a row
- **THEN** the detail page MUST render via `CnDetailPage` with
  the project's lifecycle state, P&L, WIP, assignments, and
  recent hours.

### REQ-CPA-013: A project-utilisation dashboard widget SHALL be declared via `x-openregister-widgets`

A widget MUST be declared via `x-openregister-widgets` showing
per-person utilisation for the current period (with green/yellow/
red bands at >80%, 50–80%, <50%). Consumable by `CnDashboardPage`.
No bespoke Vue.

#### Scenario: The dashboard renders the utilisation widget

- **GIVEN** a `project-administrator` opens the shillinq dashboard
- **WHEN** the page renders
- **THEN** the utilisation widget MUST display a row per person
  assigned to a project this period.

### REQ-CPA-014: Audit trail and retention SHALL be consumed from OR's abstractions

The system SHALL satisfy this requirement: Audit trail and retention SHALL be consumed from OR's abstractions.

Every `Project`, `ProjectAssignment`, `RateCard`, and `WipBalance`
operation MUST be audited via OR's audit-trail-immutable (ADR-022).
Retention MUST be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }`
(financial records — 7 years).

#### Scenario: A historical project remains queryable

- **GIVEN** a `Project` closed in 2020
- **WHEN** queried in 2026 (within 7-year retention)
- **THEN** the record MUST be returned with full audit trail.

# Spec: bookkeeping-consultancy-project-accounting (delta)

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (project accounting)
**Depends on:** bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1), bookkeeping-cost-centers-dimensions (T4)

> **Delta note.** The canonical T3 contract for consultancy project
> accounting already lives at
> `openspec/specs/bookkeeping-consultancy-project-accounting/spec.md`
> (RJ 270 / IFRS 15 percentage-of-completion `Project` register,
> `ProjectAssignment`, `RateCard`, `WipBalance`, `BillableHour`
> integration). This delta ADDS the **departmental and budget-roll-up**
> slice that complements that contract: a `CostProject` analytical
> register (project lifecycle + budget vs estimated costs), a
> `ProjectBudget` period allocation register, additive `CostCenter`
> budget fields, declarative budget roll-up and project P&L
> aggregations, utilization calculation, and seed templates per
> ADR-031.
>
> No PHP service class is authored. All behaviour lands as
> `x-openregister-lifecycle`, `x-openregister-relations`,
> `x-openregister-aggregations`, `x-openregister-calculations`,
> seed JSON, manifest entries, and one additive repair-step phase.

## ADDED Requirements

@e2e exclude declarative-only delta: this change adds schema metadata, seeds, and a repair-step phase. UI surfaces are already covered by the canonical T3 spec's REQ-CPA-012 + REQ-CPA-013 e2e plan.

### Requirement: REQ-CPA-101 — The system SHALL declare a `CostProject` analytical project register alongside the existing RJ 270 `Project`

For consultancy and professional-services operators, shillinq MUST
declare a `CostProject` schema in
`lib/Settings/shillinq_register.json` capturing the
**management-accounting** view of a project (authorised budget,
estimated costs, costs incurred to date, lifecycle) — distinct from
and complementing the existing RJ 270 / IFRS 15 revenue-recognition
`Project` schema declared by the canonical spec.

`CostProject` MUST declare at minimum the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `projectNumber` | string | Yes | Operator-assigned unique reference within administration |
| `name` | string | Yes | Human-readable project name |
| `description` | string | No | Project description / scope |
| `startDate` | date | No | Activation date |
| `endDate` | date | No | Target close date |
| `totalBudget` | number (integer cents) | Yes | Authorised spend ceiling |
| `totalEstimatedCosts` | number (integer cents) | Yes | Project-manager estimate |
| `costsIncurredToDate` | number (integer cents) | No | Derived via aggregation per REQ-CPA-105 |
| `administrationId` | string | No | FK to Administration |
| `organizationId` | string | No | FK to the customer/owner organization |
| `lifecycleState` | enum | Yes | One of `draft`, `active`, `on-hold`, `closed`, `archived` |
| `costCenterCode` | string | No | FK to `CostCenter.code` — associates project to a department |

Per ADR-031, no `ProjectManagementService` or `CostProjectService`
PHP class is authored — the schema declaration carries the
behaviour.

#### Scenario: A minimal CostProject validates

- **GIVEN** the `CostProject` schema declared in `shillinq_register.json`
- **WHEN** an object with `projectNumber: "CP-2026-001"`,
  `name: "Internal optimization"`, `totalBudget: 5000000` (€50.000.00 in
  cents), `totalEstimatedCosts: 4500000`, `lifecycleState: "draft"` is
  created
- **THEN** validation MUST pass.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `cost_project`, `project_budget`, or `cpa_`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-CPA-102 — The `CostProject` lifecycle SHALL be declarative per ADR-031

`CostProject` MUST declare `x-openregister-lifecycle` with field
`lifecycleState`, initialState `draft`, and the following transitions:

| From | To | Trigger |
|---|---|---|
| `draft` | `active` | operator action (`activate`) |
| `active` | `on-hold` | operator action (`putOnHold`) |
| `on-hold` | `active` | operator action (`reactivate`) |
| `active` | `closed` | operator action (`close`) |
| `closed` | `archived` | calendar trigger (typically 12 months post-close) |

Per the ADR-031 anti-pattern list, no
`CostProjectLifecycleService` MUST be authored.

#### Scenario: A draft CostProject can be activated

- **GIVEN** a `CostProject` in `lifecycleState: "draft"`
- **WHEN** the operator triggers the `activate` transition
- **THEN** the `lifecycleState` MUST become `"active"` AND OR audit
  trail MUST record the change.

#### Scenario: A closed CostProject can be archived

- **GIVEN** a `CostProject` in `lifecycleState: "closed"` for at
  least 12 months
- **WHEN** the operator (or a calendar workflow) triggers `archive`
- **THEN** the `lifecycleState` MUST become `"archived"`.

### Requirement: REQ-CPA-103 — The `ProjectBudget` register SHALL declare period-level allocations

`ProjectBudget` MUST be declared with the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `allocationNumber` | string | Yes | Operator-assigned reference |
| `amount` | number (integer cents) | Yes | Allocation amount |
| `status` | enum | Yes | `pending`, `approved`, `allocated`, `spent` |
| `projectId` | string | Yes | FK to `CostProject.id` |
| `fiscalPeriod` | string | Yes | FK to `FiscalPeriod.code` |
| `administrationId` | string | No | FK to `Administration` |

Per ADR-031, no `ProjectBudgetService` MUST be authored.

#### Scenario: A budget allocation for an active project validates

- **GIVEN** a `CostProject` `cp-1` in `active` state
- **WHEN** a `ProjectBudget` is created with `allocationNumber:
  "ALLOC-2026-Q1-001"`, `amount: 1500000`, `status: "approved"`,
  `projectId: "cp-1"`, `fiscalPeriod: "2026-Q1"`
- **THEN** validation MUST pass.

### Requirement: REQ-CPA-104 — CostCenter MUST be additively extended with budget tracking fields

The system MUST additively extend the existing `CostCenter` schema
(declared by `bookkeeping-cost-centers-dimensions`) with the
following **optional** fields (non-breaking — existing records
remain valid):

| Field | Type | Required | Purpose |
|---|---|---|---|
| `description` | string | No | Operator-readable description |
| `status` | enum | No | Alias of `lifecycleState` for `active`/`inactive` semantics |
| `budget` | number (integer cents) | No | Operator-set baseline budget |
| `spentToDate` | number (integer cents) | No | Derived via aggregation (REQ-CPA-106) |
| `allocatedBudget` | number (integer cents) | No | Derived via calculation (REQ-CPA-107) |
| `organizationId` | string | No | FK to owning Organization |

Existing `lifecycleState` (`active`/`blocked`/`archived`) is the
authoritative lifecycle field; `status` is a convenience alias for
external integrations expecting the simpler `active`/`inactive`
shape.

#### Scenario: An existing CostCenter still validates without the new fields

- **GIVEN** a `CostCenter` record `{code: "KC-100", name: "Sales",
  lifecycleState: "active", administrationId: "adm-1"}`
- **WHEN** loaded against the extended schema
- **THEN** validation MUST pass.

#### Scenario: A CostCenter with budget tracking validates

- **GIVEN** the extended `CostCenter` schema
- **WHEN** a record `{code: "KC-200", name: "Engineering",
  lifecycleState: "active", administrationId: "adm-1", budget:
  10000000, organizationId: "org-1"}` is created
- **THEN** validation MUST pass AND `spentToDate` MUST be available
  as a derived field.

### Requirement: REQ-CPA-105 — `CostProject.costsIncurredToDate` SHALL be a declarative aggregation over GL

`CostProject.costsIncurredToDate` MUST be derived via
`x-openregister-aggregations` summing `GLLine.amount` filtered by
`GLLine.subLedgerType = "cost-project"` AND `GLLine.subLedgerRef =
@self.id` AND `Account.accountType = "expenses"`.

Per ADR-031, no `CostProjectCostAggregationService` MUST be authored.

#### Scenario: A CostProject reflects tagged GL expense lines

- **GIVEN** `CostProject` `cp-1` has €15.000 of GL postings on
  expense accounts tagged with `subLedgerType: "cost-project"` AND
  `subLedgerRef: "cp-1"`
- **WHEN** the `costsIncurredToDate` aggregation is queried
- **THEN** the result MUST equal €15.000 (1500000 cents).

### Requirement: REQ-CPA-106 — `CostCenter.spentToDate` SHALL be a declarative aggregation over GL

`CostCenter.spentToDate` MUST be derived via
`x-openregister-aggregations` summing `GLLine.amount` filtered by
`GLLine.costCenterCode = @self.code` AND `GLLine.side = "debit"`,
inclusive of GL postings tagged to descendant cost centers reached
via the `parentCode` self-relation (recursive descent).

Per ADR-031, no `CostCenterSpendAggregationService` MUST be authored.

#### Scenario: A parent CostCenter sees rolled-up spend

- **GIVEN** `CostCenter` `KC-100` (Sales) is parent of `KC-100-01`
  (Account Mgmt) and `KC-100-02` (New Business)
- **AND** GL has €30.000 spend tagged to `KC-100-01` and €20.000 to
  `KC-100-02`
- **WHEN** `KC-100.spentToDate` is queried
- **THEN** the result MUST equal €50.000.

### Requirement: REQ-CPA-107 — `CostCenter.allocatedBudget` SHALL be a declarative calculation rolling up children

`CostCenter.allocatedBudget` MUST be derived via
`x-openregister-calculations` as the sum of this cost center's own
`budget` plus the `allocatedBudget` of every child cost center
(reached via the `parentCode` self-relation). The formula is
recursive: leaves return their own `budget`; non-leaves return
`budget + sum(children.allocatedBudget)`.

Per ADR-031, no `BudgetRollupService` MUST be authored.

#### Scenario: A parent CostCenter sees rolled-up budget

- **GIVEN** `CostCenter` `KC-100` has `budget: 5000000` and two
  children `KC-100-01` (`budget: 3000000`) and `KC-100-02`
  (`budget: 2000000`)
- **WHEN** `KC-100.allocatedBudget` is read
- **THEN** the result MUST equal `10000000` (own 5M + children 5M).

### Requirement: REQ-CPA-108 — `CostProject.profitAndLoss` SHALL be a declarative aggregation over revenue vs expense GL lines

`CostProject.profitAndLoss` MUST be derived via
`x-openregister-aggregations` producing:

- `revenue` — sum of `GLLine.amount` where `subLedgerType =
  "cost-project"` AND `subLedgerRef = @self.id` AND
  `Account.accountType = "revenue"`.
- `expense` — sum of `GLLine.amount` where `subLedgerType =
  "cost-project"` AND `subLedgerRef = @self.id` AND
  `Account.accountType = "expenses"`.
- `profitAndLoss` — `revenue - expense`.

Per ADR-031, no `CostProjectPnlService` MUST be authored.

#### Scenario: A CostProject P&L surfaces gross margin

- **GIVEN** `CostProject` `cp-1` has €50.000 of GL revenue postings
  and €30.000 of GL expense postings tagged to its id
- **WHEN** `profitAndLoss` is queried
- **THEN** the result MUST contain `revenue: 5000000`, `expense:
  3000000`, `profitAndLoss: 2000000`.

### Requirement: REQ-CPA-109 — Utilization SHALL be a declarative calculation per person per period

`UrenRegistratie` (the existing time-tracking register) MUST declare
an `x-openregister-calculations.utilizationPercent` formula:

```
utilizationPercent = billableHoursThisPeriod / availableHoursThisPeriod
```

Where `billableHoursThisPeriod` aggregates billable hours for the
person in the current `FiscalPeriod` and `availableHoursThisPeriod`
is operator-supplied per assignment per period (default
`capacityHoursPerWeek × workingWeeksInPeriod`).

Per ADR-031, no `UtilizationService` MUST be authored.

#### Scenario: A 65% utilization is computed declaratively

- **GIVEN** `user-1` has 130 billable hours logged in Q1 2026 and
  an availability of 200 hours
- **WHEN** the `utilizationPercent` calculation is evaluated
- **THEN** the result MUST equal `65` (130/200 × 100).

### Requirement: REQ-CPA-110 — A project-templates seed file SHALL ship and load idempotently

`lib/Settings/seeds/project-templates.json` MUST ship with:

1. SPDX `EUPL-1.2` license header (in `_meta`).
2. A `_meta` block carrying `source: "Conduction consultancy defaults"`
   and `description`.
3. At least 3 seed `CostProject` records covering: service
   engagement, product development, internal optimization.

The repair-step phase (REQ-CPA-112) MUST import these idempotently
keyed on `projectNumber` so re-runs preserve operator edits.

#### Scenario: The project-templates seed is idempotent

- **GIVEN** the seed file `lib/Settings/seeds/project-templates.json`
  shipping 3 records
- **WHEN** the repair step runs twice
- **THEN** only 3 `CostProject` records exist after the first run
  AND 0 new records are created on the second run.

### Requirement: REQ-CPA-111 — A cost-center-templates seed file SHALL ship and load idempotently

`lib/Settings/seeds/cost-center-templates.json` MUST ship with:

1. SPDX `EUPL-1.2` license header (in `_meta`).
2. A `_meta` block carrying `source: "Conduction consultancy defaults"`.
3. At least 5 top-level cost centers (Sales, Engineering,
   Operations, Finance, Administration) plus typical
   sub-departments, with codes following the `CC-NNN` /
   `CC-NNN-NN` pattern (`parentCode` linking subs to parents).

The repair-step phase (REQ-CPA-112) MUST import these idempotently
keyed on `code` so re-runs preserve operator edits.

#### Scenario: The cost-center-templates seed is idempotent

- **GIVEN** the seed file shipping the 5+ top-level + sub-departments
- **WHEN** the repair step runs twice
- **THEN** the second run MUST create 0 new records (skipped: N).

### Requirement: REQ-CPA-112 — The repair step SHALL import both seed files idempotently

The repair step `OCA\Shillinq\Repair\InitializeSettings` MUST gain a
new phase `seedConsultancyProjectAccountingTemplates()` that:

1. Loads `project-templates.json` and creates `CostProject` records
   keyed on `projectNumber`; skips records whose `projectNumber`
   already exists.
2. Loads `cost-center-templates.json` and creates `CostCenter`
   records keyed on `code`; skips records whose `code` already
   exists.
3. Skips entirely (with operator-visible warning) when
   `administration_id` is not configured — per the same C2 pattern
   used by other shillinq seeders.
4. Emits per-file summary (`X created, Y skipped`) to `IOutput`.

#### Scenario: Seeds skip when no administration is configured

- **GIVEN** a fresh install with `administration_id` unset
- **WHEN** the repair step runs
- **THEN** the consultancy-project-accounting seed phase MUST log a
  warning ("administration_id not configured — skipping
  consultancy project accounting seed") AND MUST NOT write any
  records.

### Requirement: REQ-CPA-113 — Projects navigation SHALL be reachable through `src/manifest.json`

`src/manifest.json` MUST declare:

- A menu entry binding to a `Projects` index page over the
  consultancy `Project` register (existing) plus a `CostProjects`
  index page over `CostProject` (new).
- A `CostProjectDetail` detail page surfacing
  `costsIncurredToDate`, `profitAndLoss`, and the related
  `ProjectBudget` allocations.

`node tests/validate-manifest.js` MUST exit 0 after the additions.

#### Scenario: Manifest validates after additions

- **GIVEN** the extended `src/manifest.json`
- **WHEN** `node tests/validate-manifest.js` runs
- **THEN** the validator MUST exit with status 0.

### Requirement: REQ-CPA-114 — Cost Centers navigation SHALL be reachable through `src/manifest.json`

`src/manifest.json` MUST continue to declare the `CostCenters`
index page and `CostCenterDetail` detail page over the
`CostCenter` register (already shipped by
`bookkeeping-cost-centers-dimensions`); this delta MUST NOT
regress those entries and MAY surface `budget`, `spentToDate`,
`allocatedBudget`, and `organizationId` on the detail page when
present.

#### Scenario: Cost-center detail surfaces budget tracking

- **GIVEN** a `CostCenter` with `budget` and `spentToDate` populated
- **WHEN** the operator opens the cost-center detail page
- **THEN** the page MUST render budget, spent-to-date, and
  derived `allocatedBudget` (rolled up from children).

### Requirement: REQ-CPA-115 — The system MUST consume audit trail and retention from OR's abstractions

The system MUST audit every `CostProject`, `ProjectBudget`, and
extended `CostCenter` operation via OR's audit-trail-immutable
(ADR-022). The system MUST declare retention via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2",
years: 7 }` on `CostProject` and `ProjectBudget` (financial records
7 years).

#### Scenario: An archived CostProject remains queryable

- **GIVEN** a `CostProject` archived in 2020
- **WHEN** queried in 2026 (within 7-year retention)
- **THEN** the record MUST be returned with full audit trail.

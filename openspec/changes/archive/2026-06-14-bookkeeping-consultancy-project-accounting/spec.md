# Spec — Consultancy and Departmental Project Accounting

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (project accounting)
**Depends on:** bookkeeping-chart-of-accounts, bookkeeping-general-ledger

---

## Requirements

### REQ-CPA-001: Project schema declaration

The system MUST declare a `CostProject` schema in `lib/Settings/shillinq_register.json`
with the following required fields: `projectNumber` (string), `name` (string),
`lifecycleState` (enum). Optional fields MUST include `description`, `startDate`,
`endDate`, `totalBudget`, `totalEstimatedCosts`, `costsIncurredToDate` (calculated),
`administrationId`, and `profitAndLoss` (calculated).

#### Scenario: Project schema declared in register

GIVEN the Shillinq app is installed
WHEN the register is loaded via the repair step
THEN a `CostProject` schema MUST be present in the register
AND the schema MUST include all required fields per this requirement

---

### REQ-CPA-002: Project lifecycle management

The system MUST support lifecycle transitions for `CostProject` via
`x-openregister-lifecycle`: `draft → active`, `active → on-hold`,
`on-hold → active`, `active → closed`, and `closed → archived`.

#### Scenario: Project is activated from draft

GIVEN a `CostProject` record in state `draft`
WHEN the operator triggers the `activate` transition
THEN the project's `lifecycleState` MUST change to `active`
AND GL postings and time entries MUST be permitted against the project

#### Scenario: Project is placed on hold

GIVEN a `CostProject` record in state `active`
WHEN the operator triggers the `putOnHold` transition
THEN the project's `lifecycleState` MUST change to `on-hold`
AND no new GL postings SHOULD be accepted against the project

---

### REQ-CPA-003: Cost center hierarchical navigation

The system MUST support hierarchical navigation of `CostCenter` records via
`x-openregister-relations` self-relation on `parentCode → CostCenter.code`.
This relation MUST enable roll-up aggregation from child to parent cost centers.

#### Scenario: Cost center hierarchy is navigable

GIVEN a parent `CostCenter` record with `code = "CC-001"`
AND a child `CostCenter` record with `code = "CC-001-01"` and `parentCode = "CC-001"`
WHEN the user navigates to the parent cost center detail page
THEN the child cost center MUST appear in the related cost centers list

---

### REQ-CPA-004: Time entry project tagging

The system MUST extend `UrenRegistratie` (time entry schema) with optional
`projectId` (FK to `CostProject.id`) and `taskId` (string) fields for
per-project hour logging.

#### Scenario: Time entry is tagged to a project

GIVEN an `UrenRegistratie` record
WHEN the record includes a non-null `projectId` referencing a `CostProject`
THEN the time entry MUST be associated with the referenced project
AND the project's `costsIncurredToDate` aggregation MUST include this entry's cost

---

### REQ-CPA-005: Project cost aggregation from GL

The system MUST aggregate `costsIncurredToDate` on `CostProject` by summing GL
line amounts on expense accounts tagged to the project, via
`x-openregister-aggregations`.

#### Scenario: Project costs aggregate from GL lines

GIVEN a `CostProject` record
AND one or more `GLLine` records on expense accounts with `subLedgerRef` pointing to the project
WHEN the system computes `costsIncurredToDate`
THEN the value MUST equal the sum of all expense-account GL lines tagged to this project

---

### REQ-CPA-006: Cost center spending aggregation

The system MUST aggregate `spentToDate` on `CostCenter` by summing GL line amounts
on expense accounts tagged to the cost center, via `x-openregister-aggregations`.

#### Scenario: Cost center spending is aggregated from GL

GIVEN a `CostCenter` record with `code = "CC-001"`
AND GL lines with `costCenterCode = "CC-001"` on expense accounts
WHEN the system computes `spentToDate`
THEN the value MUST equal the sum of those GL line amounts

---

### REQ-CPA-007: Cost center budget roll-up from children

The system MUST calculate `allocatedBudget` on `CostCenter` as the sum of child
cost centers' `budget` values plus any direct allocation, via
`x-openregister-calculations`.

#### Scenario: Parent cost center budget rolls up from children

GIVEN a parent `CostCenter` with `code = "CC-001"` and direct `budget = 50000`
AND two child cost centers with `budget = 25000` each
WHEN the system computes `allocatedBudget` on the parent
THEN `allocatedBudget` MUST equal 100000 (50000 + 25000 + 25000)

---

### REQ-CPA-008: Project profit and loss calculation

The system MUST calculate `profitAndLoss` on `CostProject` as recognised revenue
minus costs incurred to date, via `x-openregister-aggregations`.

#### Scenario: Project P&L is computed from GL

GIVEN a `CostProject` with GL revenue lines totalling EUR 100 000
AND GL expense lines totalling EUR 70 000
WHEN the system computes `profitAndLoss`
THEN `profitAndLoss` MUST equal EUR 30 000

---

### REQ-CPA-009: Utilization metric per assignment

The system MUST calculate `utilizationPercent` on `ProjectAssignment` as
`billableHoursThisPeriod / availableHoursThisPeriod × 100`, via
`x-openregister-calculations`. Division by zero MUST yield 0.

#### Scenario: Utilization is zero when no capacity is set

GIVEN a `ProjectAssignment` with `capacityHoursPerWeek = 0`
WHEN the system computes `utilizationPercent`
THEN `utilizationPercent` MUST equal 0

#### Scenario: Utilization is computed correctly

GIVEN a `ProjectAssignment` with `capacityHoursPerWeek = 40` over 4 working weeks
AND `billableHoursThisPeriod = 120`
WHEN the system computes `utilizationPercent`
THEN `utilizationPercent` MUST equal 75

---

### REQ-CPA-010: Seed project templates

The system MUST ship `lib/Settings/seeds/project-templates.json` with at least 3
template `CostProject` records: a service engagement, a product development, and an
internal optimization project. Each record MUST include a `@self` envelope with
`register`, `schema`, and a unique `slug`, and a `_meta` block with
`source: "Consultancy defaults"`.

#### Scenario: Project templates are seeded on install

GIVEN a fresh Shillinq installation with a configured `administrationId`
WHEN the repair step runs
THEN 3 `CostProject` template records MUST be present in the register
AND subsequent repair step runs MUST NOT create duplicate records

---

### REQ-CPA-011: Seed cost center templates

The system MUST ship `lib/Settings/seeds/cost-center-templates.json` with a
hierarchical structure of at least 5 main department `CostCenter` records (Sales,
Engineering, Operations, Finance, Administration) and at least 5 sub-departments.
Each record MUST include a `@self` envelope and a `_meta` block.

#### Scenario: Cost center templates are seeded on install

GIVEN a fresh Shillinq installation with a configured `administrationId`
WHEN the repair step runs
THEN at least 5 main and 5 sub-department `CostCenter` records MUST be present
AND the hierarchical `parentCode` relationship MUST be correctly set

---

### REQ-CPA-012: Idempotent seed import via repair step

The repair step under `lib/Repair/InitializeSettings.php` MUST import both seed
files (`project-templates.json` and `cost-center-templates.json`) idempotently.
Re-running the repair step MUST NOT create duplicate records. Operator edits to
seeded records MUST be preserved across re-runs.

#### Scenario: Repair step is idempotent on re-run

GIVEN seed files have been imported by a previous repair run
WHEN the repair step is run again
THEN no new duplicate records MUST be created
AND previously seeded records MUST be unmodified

---

### REQ-CPA-013: Projects navigation in manifest

The manifest (`src/manifest.json`) MUST include navigation entries for `CostProject`
records with an index page (`type: index`) and a detail page (`type: detail`).
Running `node tests/validate-manifest.js` MUST exit 0.

#### Scenario: Projects are accessible from main navigation

GIVEN the Shillinq app is open in the browser
WHEN the user clicks the "Projects" menu item under "Projecten"
THEN the `CostProject` index page MUST be displayed
AND each project MUST be clickable to open its detail page

---

### REQ-CPA-014: Cost Centers navigation in manifest

The manifest MUST include navigation entries for `CostCenter` records with an index
page and a detail page. The index MUST display `code`, `name`, `parentCode`, and
`lifecycleState` columns. The detail page MUST show all cost center fields including
budget aggregation and hierarchy navigation.

#### Scenario: Cost centers are accessible from main navigation

GIVEN the Shillinq app is open in the browser
WHEN the user clicks the "Cost Centers" menu item under "Dimensions"
THEN the `CostCenter` index page MUST be displayed
AND each cost center MUST be clickable to open its detail page

---

### REQ-CPA-015: ProjectBudget schema for period-level allocations

The system MUST declare a `ProjectBudget` schema with fields: `allocationNumber`
(string, required), `amount` (number, required), `status` (enum:
pending/approved/allocated/spent, required), `projectId` (string, FK to CostProject,
required), and `fiscalPeriod` (string, required).

#### Scenario: Project budget allocation is created

GIVEN a `CostProject` record in `active` state
WHEN an operator creates a `ProjectBudget` record referencing the project
THEN the budget allocation MUST be persisted
AND the `ProjectBudget` status lifecycle MUST proceed from `pending` to `approved`

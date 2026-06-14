# Spec: bookkeeping-cost-centers-dimensions

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-general-ledger (T1)

## ADDED Requirements

### Requirement: REQ-CC-001: The system SHALL store analytical dimensions as OpenRegister-managed registers declared in the app manifest

Cost centers, kostendragers (cost units), projects, and any custom
analytical dimension MUST be declared as registers in
`lib/Settings/shillinq_register.json` and surfaced through
`src/manifest.json` per ADR-024. The set of supported dimension
types MUST be open: an administration MAY add a custom dimension
register (e.g. "department", "product line", "campaign") by
declaring it the same way, without code changes to shillinq's
PHP layer (per ADR-022 — consume OR's register abstraction rather
than write a parallel dimension table).

#### Scenario: Reviewer confirms no parallel dimension table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `cost_center` / `dimension` / `cost_unit`
- **THEN** no such classes SHALL exist; all dimensions are
  OR-managed registers.

#### Scenario: A custom dimension register is consumable through the same path as the built-in ones

- **GIVEN** an operator-defined `Campaign` register declared in
  `lib/Settings/shillinq_register.json` with
  `x-openregister-purpose: dimension`
- **WHEN** the manifest declares an index/detail page for it
- **THEN** the dimension MUST be selectable from the GL line entry
  form alongside the built-in cost-center and kostendrager
  dimensions, with no shillinq PHP edits.

### Requirement: REQ-CC-002: The `CostCenter` schema SHALL declare a fixed minimum field set with hierarchy

| Field | Type | Required | Purpose |
|---|---|---|---|
| `code` | string | Yes | Operator-assigned unique reference within the administration |
| `name` | string | Yes | Human-readable name |
| `parentCode` | string | No | FK to parent `CostCenter.code` for hierarchy via `x-openregister-relations` self-relation |
| `responsibleUser` | string | No | NC user id of the cost-center owner |
| `lifecycleState` | enum | Yes | One of `active`, `blocked`, `archived` (mirrors `Account` lifecycle per REQ-CoA-005) |
| `administrationId` | string | Yes | FK to the administration |

Equivalent schemas MUST be declared for `KostenDrager` (cost unit /
cost object) and `Project`. The three share the same shape; the
distinction is semantic (per Dutch GAAP and accounting practice)
and surfaces in the UI labels.

#### Scenario: A cost-center hierarchy resolves via OR's relation engine

- **GIVEN** cost-center `KC-100 Sales` and child `KC-110 Sales NL`
- **WHEN** the child's `parentCode` is set to `KC-100`
- **THEN** OR's relation engine MUST resolve the parent on read;
  **AND** the segment P&L (per REQ-CC-005) MUST roll child amounts
  up to the parent.

### Requirement: REQ-CC-003: The `GLLine` schema SHALL carry optional dimension references additively

The T1 `GLLine` schema MUST be extended additively with the
following optional fields (the T1 `costCenter` field is the
backwards-compatible alias for `costCenterCode`):

| Field | Type | Required | Purpose |
|---|---|---|---|
| `costCenterCode` | string | No | FK to `CostCenter.code` |
| `kostenDragerCode` | string | No | FK to `KostenDrager.code` |
| `projectCode` | string | No | FK to `Project.code` |
| `dimensions` | object | No | Free-form key→value map for custom dimensions, where each key matches a registered custom dimension register and the value matches that register's `code` field |

The `dimensions` map MUST be validated per registered custom
dimension (each key MUST point at an existing custom dimension
register, each value MUST resolve to an existing record in that
register). Validation is declared via OR's relation engine, not
written in PHP.

#### Scenario: A line referencing a non-existent custom dimension key fails validation

- **GIVEN** no `Campaign` register is registered
- **WHEN** a `GLLine` is saved with `dimensions: {"campaign": "SUMMER2026"}`
- **THEN** the save MUST fail with an "unknown dimension key" error.

#### Scenario: A line referencing a missing dimension value fails validation

- **GIVEN** the `Campaign` register is registered but contains no
  record with `code: "SUMMER2026"`
- **WHEN** a `GLLine` is saved with `dimensions: {"campaign": "SUMMER2026"}`
- **THEN** the save MUST fail with an "unknown dimension value" error.

### Requirement: REQ-CC-004: Cost allocation rules SHALL be declared as schema metadata per ADR-031, not authored as service classes

A cost-allocation rule (e.g. "spread overhead 1000-1099 across
KC-100/KC-200/KC-300 by 50/30/20") MUST be declared as an
`AllocationRule` register record. The schema MUST capture:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | Yes | Operator-readable rule name |
| `sourceAccountPattern` | string | Yes | Glob or range pattern that matches source accounts (e.g. `1000-1099`) |
| `driver` | enum | Yes | One of `fixed-percentage`, `fixed-amount`, `volume`, `headcount` |
| `targets[]` | array of `{code, percentage?, amount?, source?}` | Yes | At least 2 entries; percentages MUST sum to 100 when `driver = fixed-percentage` |
| `targetDimension` | enum | Yes | One of `cost-center`, `kosten-drager`, `project` — identifies which dimension the `targets[].code` refers to |
| `cadence` | enum | Yes | One of `per-posting`, `monthly`, `period-close` |
| `lifecycleState` | enum | Yes | `active` / `paused` / `archived` |
| `administrationId` | string | Yes | FK to the administration |

When `cadence = per-posting`, the rule is evaluated by an
`x-openregister-lifecycle` action on `GLTransaction.post` that
creates additional balanced `GLLine` rows distributing the source
amount per the rule. When `cadence = monthly` or `period-close`,
an OR `ScheduledWorkflow` evaluates the rule on its cadence (per
ADR-031). No PHP `AllocationService.allocate()` ever runs the rule.

#### Scenario: Reviewer confirms no allocation service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes with method names
  matching `allocate*` / `distributeCost*` / `spread*`
- **THEN** no such classes SHALL exist; allocation MUST be schema-
  declared.

#### Scenario: A fixed-percentage rule fails validation when targets do not sum to 100

- **GIVEN** the `AllocationRule` schema
- **WHEN** a rule is saved with `driver: fixed-percentage` and
  `targets: [{code: "KC-100", percentage: 60}, {code: "KC-200", percentage: 30}]`
- **THEN** the save MUST fail with a "percentages must sum to 100"
  error.

#### Scenario: A per-posting rule splits a line on post

- **GIVEN** an active `AllocationRule` matching source account
  `4900` with `driver: fixed-percentage`, targets
  `[{code: KC-100, percentage: 50}, {code: KC-200, percentage: 50}]`,
  `cadence: per-posting`
- **WHEN** a `GLTransaction` is posted with a single line `Dr 4900 €1000`
- **THEN** the lifecycle action MUST emit two additional
  cost-center-tagged lines splitting €500/€500, keeping the
  transaction balanced.

### Requirement: REQ-CC-005: The system SHALL expose a segment P&L derived from dimension-tagged GL lines via `x-openregister-aggregations`

Per ADR-031, segment P&L (P&L broken down by cost-center / project
/ custom dimension) MUST be declared as an
`x-openregister-aggregations` on `GLLine` keyed by
(`fiscalYearId`, `accountNumber`, dimension code). The aggregation
MUST be consumable by:

- launchpad dashboard widgets (per ADR-022 — launchpad reads aggregations
  via runtime GraphQL)
- the SBR/XBRL builder (per `bookkeeping-sbr-xbrl-reporting`) when
  segment reporting is required
- the manifest detail page on a CostCenter record (which renders
  that segment's roll-up)

No PHP `SegmentReportService.getByDimension()` aggregates from
ledger lines — the aggregation is declarative.

#### Scenario: Aggregation rolls dimension amounts up to a parent

- **GIVEN** posted lines tagged `KC-110 Sales NL` and `KC-120 Sales BE`
  (both children of `KC-100 Sales`)
- **WHEN** the segment P&L aggregation is queried for `KC-100`
- **THEN** the result MUST include the sum of both children's
  amounts under `KC-100`.

### Requirement: REQ-CC-006: Cost centers and other dimensions SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare navigation entries (under
`Bookkeeping > Dimensions`) with `type: index` + `type: detail`
pages for `CostCenter`, `KostenDrager`, `Project`, and any
operator-registered custom dimension register. The
`AllocationRule` register MUST also surface as an index/detail
page. All pages MUST be rendered by the generic
`@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage`
components — no bespoke Vue files (per ADR-024 Tier-4).

#### Scenario: A newly registered custom dimension appears in the nav after manifest reload

- **GIVEN** the operator adds a `Campaign` dimension register and
  a corresponding manifest entry
- **WHEN** the manifest is reloaded
- **THEN** the `Bookkeeping > Dimensions > Campaign` entry MUST
  appear with no PHP / Vue edits.

### Requirement: REQ-CC-007: This capability SHALL pre-position `time-per-cost-center` as the data shape WBSO (T4-specialized) will consume

The fields and aggregations declared here MUST be sufficient for a
later T4-specialized WBSO capability to derive `hours-per-project`
totals without modification — i.e. the `Project` register's `code`
field, the `GLLine.projectCode` FK, and the segment P&L
aggregation MUST shape such that a WBSO time-tracking capability
can join time records to projects and aggregate hours per project
per fiscal year. This requirement names a downstream dependency;
it does not require any WBSO code in this capability.

#### Scenario: A WBSO-style projection query works without changes

- **GIVEN** a hypothetical `TimeEntry` register tagged by
  `projectCode`
- **WHEN** an aggregation joins `TimeEntry.hours` to
  `Project.code` and groups by `Project.code` and
  `Project.fiscalYearId`
- **THEN** the join MUST resolve cleanly using the dimension shape
  declared here, with no schema changes required.

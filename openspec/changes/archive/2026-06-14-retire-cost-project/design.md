# Design — Retire Cost Project

## Context

Shillinq carries two analytical project registers and a duplicated project
navigation. `Project` is the RJ 270 / IFRS 15 **revenue-recognition** register
(POC, WIP, billing, `recognisedRevenue`, `percentageComplete`,
`totalContractValue`) — genuine accounting logic that must stay. `CostProject`
is a **management-accounting** register (`totalBudget`,
`totalEstimatedCosts`, `costsIncurredToDate`, `costCenterCode`) whose own
schema description admits it derives costs/P&L from the GL and is "distinct
from the RJ 270 revenue-recognition Project register". In substance
`CostProject` is a time-boxed, budget-bearing cost collector — a variant of
`CostCenter` (the analytical `kostenplaats` dimension), which already derives
`spentToDate` / `segmentPnl` from the GL the same way and already owns the
`costCenterCode` link `CostProject` points at.

ADR-012 forbids two registers modelling the same thing. The user framed the
resolution exactly: *"can we just attach an OpenProject project to a cost
center?"* — yes. The management-accounting project view becomes **a
`CostCenter` (analytical dimension) optionally linked to an external
OpenProject delivery project**, not a second register.

## Goals

- Remove the duplicate `CostProject` register and its parallel
  `subLedgerType='cost-project'` GL-tagging scheme (ADR-012).
- Let a `CostCenter` play the time-boxed-cost-collector role via additive
  budget + date fields, reusing its existing GL-derived aggregations.
- Express "this cost center tracks a delivery project" as an **ADR-019
  integration-registry** reference to OpenProject — delivery/planning lives in
  OpenProject, budget-vs-actuals stays in shillinq derived from GL.
- Preserve the RJ 270 / IFRS 15 `Project` register untouched, with exactly one
  navigation home.
- Migrate every existing `CostProject` to a `CostCenter` without dropping
  data, idempotently and fail-safe.

## Non-Goals

- No touching the RJ 270 / IFRS 15 `Project` register's recognition fields,
  lifecycle, or aggregations (KEEP, not MODIFY).
- No re-specifying the `CostCenter` / `KostenDrager` / project-flavoured
  dimension unification — owned by `unify-analytical-dimensions`; this change
  depends on its `dimensionType` discriminator and shared budget shape.
- No building the OpenProject `IntegrationProvider` (PHP + frontend +
  OpenConnector source) — owned by an ADR-019 integration leaf; here we only
  declare the `externalProjectRef` reference property and its
  `referenceType: openproject` binding.
- No re-implementation of any accounting rule in PHP: budget-vs-actuals is
  GL-derived via existing `x-openregister-aggregations` (ADR-031); the only
  PHP shipped is the one-shot `IRepairStep` migration.
- No re-tagging historical `GLLine` rows that used
  `subLedgerType='cost-project'` (deferred follow-up).

## Reuse Analysis

| Need | Reused surface | What this change adds |
|---|---|---|
| Analytical cost grouping | `CostCenter` register (`bookkeeping-cost-centers-dimensions`) | Project-budget + date fields fold in; `dimensionType` from `unify-analytical-dimensions` |
| Costs/P&L from GL | `CostCenter.spentToDate` / `segmentPnl` aggregations | Reused verbatim for budget-vs-actuals (no new aggregation) |
| Budget roll-up | `CostCenter.allocatedBudget` calculation | Reused; project budget rolls into the hierarchy |
| Hierarchy | `CostCenter.parentCode` self-relation | Migrated cost projects nest under their old `costCenterCode` |
| Delivery / planning data | **OpenProject** via ADR-019 registry + `ExternalIntegrationRouter` | `externalProjectRef` reference property (`referenceType: openproject`) |
| External-service auth | OpenConnector (via ADR-019 external storage strategy) | Declared dependency; shillinq stores only the reference + label |
| Object CRUD + lifecycle | OR generic object surface (ADR-022) | No app-local controller/service |
| One-shot data migration | NC `IRepairStep` + OR `ObjectService` | `RetireCostProjectStep` (idempotent, fail-safe) |
| Nav removal with deep-link survival | `src/menu-layout.json` `removals` | `CostProjects` added to `removals` |

## Decisions

### D1 — `CostProject` is folded into `CostCenter`, not kept as a sibling

The two registers are the same analytical object (GL-derived costs, budget
tracking, administration-scoped). `CostProject` adds only time-boxing
(`startDate`/`endDate`) and a `projectNumber` label over `CostCenter`, while
*lacking* hierarchy roll-up. Folding it in removes the duplication (ADR-012),
deletes the competing `subLedgerType='cost-project'` GL dimension, and gives
the time-boxed-cost-collector use case hierarchy roll-up for free. The
project-flavoured `dimensionType` (from `unify-analytical-dimensions`) marks a
cost center that is acting as a former cost project, so UIs can still filter
"projects" distinctly.

### D2 — Project-budget fields land additively on `CostCenter`

`CostCenter` gains `totalBudget`, `totalEstimatedCosts` (integer cents, to
match the existing `CostProject` convention and `spentToDate`), optional
`projectNumber`, `startDate`, `endDate`. `costsIncurredToDate` is **not**
added as a stored field — it is exactly `CostCenter.spentToDate`, already a
GL-derived `x-openregister-aggregations` read. Budget variance reuses the
pattern from `CostProject.x-openregister-calculations.budgetVariance`
(`totalBudget - spentToDate`) on `CostCenter`. All fields are nullable /
optional so a plain departmental cost center is unaffected.

### D3 — `externalProjectRef` is an ADR-019 reference property, not embedded project data

The OpenProject link is a single reference (`referenceType: openproject`):
an OpenProject project id/URL plus a cached display label. Per ADR-019 the
integration registry is the single source of truth for "how to render a
linked thing of this type"; `referenceType: openproject` makes
`CnFormDialog` / `CnDetailGrid` render the OpenProject integration's
`single-entity` widget inline next to the property, and OR's
`ExternalIntegrationRouter` dispatches resolution through the OpenProject
provider (external storage strategy, OpenConnector-backed). shillinq **never**
stores OpenProject's delivery/planning data — only the reference. The registry
is permissive-on-read, so `externalProjectRef` is valid and inert before the
OpenProject provider is configured.

### D4 — The RJ 270 / IFRS 15 `Project` register stays untouched

`Project` carries real revenue-recognition accounting (`recognisedRevenue`,
`billedRevenue`, `wipBalance`, `recognitionMethod`, `percentageComplete`,
`totalContractValue`). None of that is duplicated in `CostProject` or
`CostCenter`, so `Project` is preserved verbatim. The only `Project` change is
**navigational**: it currently has two homes (`Projects` under Bookkeeping and
`ProjectenOverzicht` under People & Projects). This change collapses it to one
via `menu-layout.json`, coordinated with `shillinq-nav-ia-cleanup`, so the
distinction "revenue-recognition Project (one home) vs. cost-center-as-project
(analytical dimension)" is unambiguous in the UI.

### D5 — Nav removal uses the established `menu-layout.json` `removals` pattern

The `CostProjects` menu leaf is deleted from `src/manifest.json` and
`"CostProjects"` is added to `src/menu-layout.json` `removals`. Per the file's
own contract, a removed leaf's **page stays routable for deep links and e2e
specs** — only the navigation entry disappears. This matches the precedent
already in the file (`Consolidations`, `Verplichtingen` in `removals`). The
`CostProjectDetail` page definition is retired with the register since nothing
links to it post-migration.

### D6 — Migration is an idempotent, fail-safe `IRepairStep`

`lib/Repair/RetireCostProjectStep.php` converts each `CostProject` to a
`CostCenter` through the real OR `ObjectService`:

| `CostProject` | → | `CostCenter` |
|---|---|---|
| `projectNumber` | → | `projectNumber` (+ minted `code = CP-<projectNumber>`) |
| `name`, `description` | → | `name`, `description` |
| `startDate`, `endDate` | → | `startDate`, `endDate` |
| `totalBudget`, `totalEstimatedCosts` | → | `totalBudget`, `totalEstimatedCosts` |
| `costsIncurredToDate` | → | *dropped* (re-derived as `spentToDate`) |
| `administrationId`, `organizationId` | → | same |
| `costCenterCode` | → | `parentCode` (nest under the old department) |
| `lifecycleState` | → | mapped: `draft\|active\|on-hold → active`, `closed\|archived → archived` |
| — | → | `dimensionType = project` (from `unify-analytical-dimensions`) |
| — | → | `externalProjectRef` empty (operator links later) |

The step:
- is **idempotent** — it writes a `migratedFrom: <costProjectId>` marker on
  the created cost center and skips already-migrated sources on re-run;
- is **fail-safe** — a `CostProject` that cannot be mapped (missing required
  field, irrecoverable `code` collision) is logged and **left in place**,
  never deleted; the source object is removed only in a later step once the
  operator confirms zero unmigrated objects;
- **mints `code` namespaced** (`CP-…`) to avoid clobbering existing
  department cost-center codes; collisions get a disambiguating suffix and a
  report line, never a silent overwrite;
- **never deletes** a `CostProject` in this step — it only creates cost
  centers and marks the sources, so rollback (restore the `CostProject`
  register) is total.

### D7 — Budget-vs-actuals stays a declarative GL derivation (ADR-031)

No `CostProjectService` ever existed (ADR-031 was already honoured) and none
is introduced. The cost-center-as-project budget view is entirely declarative:
`spentToDate` (GL aggregation) vs `totalBudget` (stored) vs `budgetVariance`
(calculation). The only PHP is the one-shot migration. This keeps the change
inside ADR-022 (apps consume OR abstractions) and ADR-031 (declarative
business logic).

### D8 — i18n with ENGLISH source keys

New CostCenter project-field labels and the OpenProject-link UI use English
source keys — `t('shillinq', 'Linked OpenProject project')` → nl
`'Gekoppeld OpenProject-project'` — with `nl` translations in the same commit.

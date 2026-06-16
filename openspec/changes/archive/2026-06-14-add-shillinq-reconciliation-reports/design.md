# Design — Reconciliation Reports

**status: pr-created**

## Decisions

### D1 — Reports are aggregation queries, not a report engine

Per ADR-031, every reconciliation report (sub-ledger ↔ GL,
intercompany, variance, controller exceptions) is declared as an
`x-openregister-aggregations` query on a `SavedQuery` record. The
queries are consumed by:

- launchpad dashboard widgets via runtime GraphQL (per
  `feedback_launchpad-no-or-dependency.md`)
- the shillinq manifest detail page that surfaces the report

No PHP `ReportingService.generateReconciliation()` exists; the same
aggregation serves both consumers. The severity classification on
exception rows (`critical` / `warning` / `info`) is encoded as a
calculated field, not as PHP logic.

This is the canonical launchpad-no-shillinq-dep shape: shillinq publishes
saved queries against OR registers; launchpad discovers them through the
GraphQL schema; neither app imports the other.

**Alternative considered**: Author a PHP `ReportingService` mirroring
Exact / AFAS style. Rejected per ADR-031 — that's a parallel reporting
engine, exactly the anti-pattern the spec is designed to prevent.

### D2 — Severity classification is a calculated field

Each exception row carries a `severity` calculated field driven by
the row's data (e.g. variance magnitude × budget size, days-open ×
amount). Calculated fields are pure functions of row data, so the
classification is reproducible from row content alone — no PHP logic
hidden behind a service.

The controller exception report (REQ-RR-005) consolidates rows from
REQ-RR-002 / -003 / -004 outputs, sorts by severity descending, and
surfaces the urgent items at the top. Sort is a query-level operation
on the calculated field; no service-side sorting.

### D3 — launchpad consumes via runtime GraphQL, no install-time dep

Per `feedback_launchpad-no-or-dependency.md`, launchpad MUST NOT list
shillinq as a dependency. Saved queries declared in shillinq's
register are exposed via OR's GraphQL schema; launchpad widget code
queries that schema at runtime and renders the results. Neither app
imports the other; the contract is the GraphQL schema.

**Alternative considered**: launchpad imports shillinq as a dependency
and reads register objects directly. Rejected — that couples the BI
surface to the bookkeeping app's install lifecycle and makes launchpad
break when shillinq is uninstalled.

### D4 — Cross-administration intercompany match resolution

If OR's aggregation engine can express the cross-administration join
(REQ-RR-003) declaratively, the saved-query record is the
implementation. If not (resolved in `opsx-ff` discovery), a thin
single-method PHP guard called *by* the aggregation engine is
authored under `lib/Aggregation/IntercompanyMatchGuard.php` — single
method `matchPostings(string $groupId, string $periodId): array`,
~20 LOC, explicitly cited as an ADR-031 exception in the implementing
cycle's design doc. Same pattern for the budget-variance join if
needed (`BudgetVarianceJoinGuard.php`).

The spec stays shape-neutral; the engine-vs-guard decision lives in
the implementing cycle's discovery phase, not the spec.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Sub-ledger ↔ GL reconciliation | OR `SavedQuery` / `x-openregister-aggregations` | Saved-query records; consumed by launchpad + manifest pages |
| Intercompany match | OR `SavedQuery` joining administrations | Shape-neutral spec; engine-vs-guard decision in opsx-ff |
| Variance analysis vs budget | OR `SavedQuery` joining `GLLine` aggregations to `Budget` | Threshold check encoded as calculated field |
| Controller exception report | OR `SavedQuery` consolidating REQ-RR-002/003/004 outputs | Severity classification as calculated field; sort by severity |
| Budget storage | New `Budget` register | Schema declaration; per-account per-period budget rows |
| Severity classification | Calculated field on saved-query row | Pure function of row data; reproducible from content |
| LaunchPad consumption | launchpad runtime GraphQL on OR (per `feedback_launchpad-no-or-dependency.md`) | shillinq publishes saved queries; launchpad discovers via GraphQL schema; no install-time dep |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| RBAC | OR authorization | `controller` role for exception report access |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 2 menu entries (Reports catalog + Budgets) + matching pages |

**Net new code in implementation cycle**: 1 schema declaration
(`Budget`) + 4 saved-query records + 2 manifest entry pairs +
possibly 1-2 short PHP aggregation guards (~20 LOC each, single
method) if engine-dependency risks confirm. No new PHP service.

## Seed Data

None. Budgets are administration-specific and authored per period; no
template ships in this change. Saved-query records ship as part of the
register declaration (not as runtime seed data).

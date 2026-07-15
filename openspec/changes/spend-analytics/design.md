# Design: spend-analytics

## Verify-first findings (OpenRegister aggregation-api, read-only audit)

Read `openregister/lib/Service/Aggregation/AggregationQuery.php`,
`AggregationRunner.php`, `AggregationController.php` and
`openspec/specs/aggregation-api/spec.md`. What the engine can do **today**:

| Capability | Supported? | Evidence |
|---|---|---|
| Metrics count / sum / avg / min / max | ✅ | `AggregationQuery::METRICS` |
| Filters: eq, in, notIn, gt, gte, lt, lte, ne | ✅ | class docblock; `applyFilter` |
| **Single-field** groupBy `{field:<name>}` | ✅ | `getGroupByField(): ?string` (line 206) |
| **Multi-field** groupBy (cross-tab) | ❌ | list `["a","b"]` has no `'field'` key → grouping skipped, no error |
| dateBucket time series (single field) | ✅ | `runAdhoc` / timeseries endpoint |
| In-process consumer entry point | ✅ | `AggregationRunner::runAdhocByRef(registerRef, schemaRef, AggregationQuery)` |
| Named `aggregate{}` maps / `buckets` / `return` projections | ❌ | `run()` reads only `metric` + `field` + `groupBy.field` |

**Consequence.** shillinq's `bookkeeping-accounts-payable-core.json` declares
`agedPayablesDetail` / `agedPayablesSummary` with `groupBy:["vendorId",
"dueDateBucket"]` and rich `aggregate`/`buckets`/`return` maps. All of it is
**inert** engine-side: the second groupBy dimension is dropped and the projection
maps are never read. No shillinq PHP consumes them (grep-verified), so today they
are aspirational config, not a working report. Precedent: shillinq#425/#436 found
the same orphaned-config pattern (`fallbackGuard` never read by the engine).

## Routing decision (explicit)

> **Single-dimension spend views are built in shillinq by CONSUMING OR's
> aggregation-api. Cross-tab (multi-field groupBy) is NOT built in shillinq — it
> belongs in OpenRegister and is filed as openregister#432.**

This is the ADR-022 line this cycle is enforcing: a leaf consumes OR's data-layer
abstractions and never re-implements grouping/summing (a bespoke cross-tab folder
over hydrated rows is exactly the anti-pattern gate-23 forbids).

### Dimension → source → honoured query

| View | Source schema | Metric | groupBy (single field) | Filter |
|---|---|---|---|---|
| spend-by-supplier | `APTransaction` | sum(`totalAmount`) | `vendorId` | `state in [issued,partially-paid,overdue,disputed,paid]` |
| spend-by-category | `GLLine` | sum(`amount`) | `accountNumber` | `side=debit, subLedgerType=ap` |
| spend-by-cost-centre | `GLLine` | sum(`amount`) | `costCenterCode` | `side=debit, subLedgerType=ap` |
| spend-by-period | `GLLine` | sum(`amount`) | `periodId` | `side=debit, subLedgerType=ap` |

`vendorId`, `accountNumber`, `costCenterCode`, `periodId` are all **top-level
scalar** fields (OR groups by top-level fields only — category-in-`lines[]` on
APTransaction is nested and therefore NOT groupable, which is why the category /
cost-centre / period views read the flattened `GLLine` posting fact rather than
the invoice header).

### Cross-tab follow-up (openregister#432)

supplier × period and category × cost-centre require multi-field groupBy →
tracked on openregister#432. When it lands, a `SpendAnalyticsService::crossTab()`
can be added consuming the extended engine — still no leaf-side aggregation.

## Decisions

1. **Consume, don't compute.** `SpendAnalyticsService` builds an
   `AggregationQuery` and calls `runAdhocByRef` — no PHP `array_sum` over
   hydrated rows. OR does the SQL `GROUP BY`.
2. **Lazy OR DI.** The runner is fetched lazily from the container by FQCN
   (mirrors `FinancialDashboardService`'s ObjectService pattern) so the leaf does
   not hard-link the class at boot. Unavailable runner → the service **raises**
   (no silent fail-open / orphaned zero result); the controller maps it to 500.
3. **Single endpoint, closed enum.** One route with a validated `dimension`
   enum (no client object id → no IDOR surface). `#[NoAdminRequired]` + explicit
   anonymous guard (ADR-005); OR aggregation enforces per-row RBAC + tenancy.
4. **Blocked-not-deleted.** The inert AP cross-tab declarations are annotated as
   BLOCKED on openregister#432 rather than removed — they encode the intended
   REQ-AP-006/007 report semantics and are the concrete evidence on the OR issue.

## Seed Data

No new seed objects. The feature reads existing AP seed data shipped by
`bookkeeping-accounts-payable-core` (Dutch suppliers + a ZZP freelancer + a
government payee + AP invoices spanning lifecycle states) and the balanced
`GLTransaction`/`GLLine` postings those invoices materialise on the `issued`
transition. Correct-totals tests seed their own deterministic APTransaction /
GLLine sets in-memory (see below).

## ADR-031 (notifications)

Not applicable — spend-analytics is a read-only reporting surface. No
object-lifecycle notifications are declared or dispatched; the canonical
`x-openregister-notifications` dialect is untouched by this change.

## Verification

`tests/Unit/Service/SpendAnalyticsServiceTest.php` seeds a KNOWN AP/GL set and
asserts the exact group sums + grand totals (e.g. V1 = 100+50 = 150, category
4000 = 60+40 = 100), and asserts each view builds a single-field groupBy query
(routing guard). A faithful `InMemoryAggregationRunner` double reproduces exactly
the honoured engine slice (single scalar-field GROUP BY sum + eq/`in` filters);
OR's own engine correctness is covered by openregister's AggregationRunner suite.

# Spec: spend-analytics

**Status:** done
**Scope:** bookkeeping
**Tier:** T2 (advanced features)
**Depends on:** bookkeeping-accounts-payable-core (T2), openregister aggregation-api

## Requirements

### Requirement: REQ-SPA-001 — Single-dimension spend views SHALL consume OpenRegister's aggregation-api, not a leaf-side aggregator

The system SHALL compute each spend view by building an OpenRegister
`AggregationQuery` (metric `sum`, a numeric `field`, a filter map, and a
**single-field** `groupBy` of the shape `{field: <name>}`) and dispatching it
through `AggregationRunner::runAdhocByRef(registerRef, schemaRef, query)`. The
leaf SHALL NOT hydrate the source objects and group/sum them in PHP. When the
OpenRegister aggregation runner is unavailable the service SHALL raise (it SHALL
NOT silently return an empty or zero result).

#### Scenario: Spend views build a single-field aggregation query
- **GIVEN** a request for any spend dimension
- **WHEN** the service computes it
- **THEN** it SHALL construct an `AggregationQuery` with `metric = "sum"`, the
  dimension's numeric field, and `groupBy = {field: <one scalar field>}`
- **AND** it SHALL obtain the result from `runAdhocByRef`, not from a PHP fold
- @e2e exclude Backend consumption contract of OR's aggregation-api; verified by the SpendAnalyticsService unit test (query-construction assertions), no browser flow.

#### Scenario: Unavailable aggregation runner raises rather than fails open
- **GIVEN** OpenRegister's `AggregationRunner` cannot be resolved from the container
- **WHEN** a spend view is requested
- **THEN** the service SHALL raise a runtime error (surfaced by the controller as HTTP 500), NOT return an empty result set
- @e2e exclude Fail-closed backend behaviour; verified by unit test, no browser flow.

### Requirement: REQ-SPA-002 — The system SHALL expose spend by supplier, category, cost-centre and period over the AP sub-ledger

The system SHALL provide four single-dimension spend views:

| View | Source schema | Metric | groupBy field | Filter |
|---|---|---|---|---|
| supplier | `APTransaction` | sum(`totalAmount`) | `vendorId` | `state in [issued, partially-paid, overdue, disputed, paid]` |
| category | `GLLine` | sum(`amount`) | `accountNumber` | `side = debit, subLedgerType = ap` |
| costCentre | `GLLine` | sum(`amount`) | `costCenterCode` | `side = debit, subLedgerType = ap` |
| period | `GLLine` | sum(`amount`) | `periodId` | `side = debit, subLedgerType = ap` |

Each view SHALL return `{ dimension, groups: [{ key, amount }], total, backend }`
where `total` equals the sum of `groups[*].amount`.

#### Scenario: Spend-by-supplier sums committed invoice totals per vendor
- **GIVEN** APTransactions V1 = {100 issued, 50 paid}, V2 = {200 overdue, 999 draft}, V3 = {40 voided}
- **WHEN** the supplier view is computed
- **THEN** the groups SHALL be V1 = 150 and V2 = 200 (draft and voided excluded), and `total` SHALL be 350
- @e2e exclude Correct-totals arithmetic over a known set; verified by unit test asserting exact numbers, no browser flow.

#### Scenario: Spend-by-category sums debit AP expense postings per GL account
- **GIVEN** GLLines: 4000 debit ap {60, 40}, 4100 debit ap {25}, 1600 credit ap {125}, 4000 debit ar {500}
- **WHEN** the category view is computed
- **THEN** the groups SHALL be 4000 = 100 and 4100 = 25 (the credit line and the AR-sub-ledger line excluded), and `total` SHALL be 125
- @e2e exclude Correct-totals arithmetic over a known set; verified by unit test asserting exact numbers, no browser flow.

### Requirement: REQ-SPA-003 — The system SHALL expose the spend views over a validated read-only REST endpoint

The system SHALL expose
`GET /api/analytics/spend?administration_id=<id>&dimension=<supplier|category|
costCentre|period>`. The `dimension` parameter SHALL be validated against the
closed enum; an unknown value SHALL yield HTTP 400. Anonymous requests SHALL
yield HTTP 401. On success the endpoint SHALL return HTTP 200 with the view
payload plus a translated `label`.

`administration_id` SHALL be required and SHALL be validated as a short
identifier (`[A-Za-z0-9_.-]{1,64}`); a missing or malformed value SHALL yield
HTTP 400. The endpoint SHALL refuse an administration the caller holds no
valid `AdministrationMembership` for
(`AdministrationContextService::canAccess()`, REQ-MA-001) with **HTTP 404, never
403** — a 403 would confirm the administration exists and turn the endpoint into
an enumeration oracle for the tenant list. The refusal SHALL occur before any
aggregation is dispatched and before the `dimension` value is validated, so the
404/400 pair cannot itself become an oracle.

⚠️ This requirement REPLACES an earlier claim that the endpoint "SHALL carry no
client-supplied object identifier (no IDOR surface) and SHALL rely on
OpenRegister's aggregation RBAC + multi-tenant filtering for row-level
authorization". Both halves were verified against OpenRegister's source and do
not hold for this app's tenancy: OR's aggregation predicate is `_organisation`,
which is OpenRegister's organisation and not a shillinq *administration* (many
administrations live inside one organisation), and OR's list-RBAC reads
`Schema::getAuthorization()`, which no schema in this app declares and which OR
treats as open when absent. The absence of a client-supplied object id was true
and irrelevant — an unscoped aggregate discloses other tenants' money without
anyone naming an id.

#### Scenario: An administration the caller is not a member of is masked as 404
- **GIVEN** an authenticated user holding no valid membership for `ADM-OTHER`
- **WHEN** the client requests `?administration_id=ADM-OTHER&dimension=supplier`
- **THEN** the response status SHALL be 404 (never 403), the body SHALL read `Administration not found`, and no aggregation SHALL be dispatched
- @e2e exclude Backend tenancy guard asserted at the controller boundary, including the "no aggregation dispatched" half, which a browser flow cannot observe.

#### Scenario: Missing or malformed administration_id is rejected
- **WHEN** the client requests `?dimension=supplier` with no `administration_id`, or with `administration_id=../../../etc/passwd`
- **THEN** the response status SHALL be 400 and no aggregation SHALL be dispatched
- @e2e exclude Backend input validation; verified by controller-level assertion, no browser flow.

#### Scenario: The proven administration reaches the aggregation filter
- **GIVEN** an authenticated member of `ADM-042`
- **WHEN** the client requests `?administration_id=ADM-042&dimension=supplier`
- **THEN** the supplier aggregation SHALL be executed with `ADM-042` in its filter, so the returned groups and total contain no other administration's invoices
- @e2e exclude Asserts the filter handed to OpenRegister's aggregation-api; not observable through the UI.

#### Scenario: Unknown dimension is rejected
- **WHEN** the client requests `?dimension=__nope`
- **THEN** the response status SHALL be 400 with an error naming the allowed dimensions
- @e2e exclude Backend input validation; verified by controller-level assertion, no browser flow.

#### Scenario: Anonymous request is rejected
- **GIVEN** no authenticated user
- **WHEN** the client requests any dimension
- **THEN** the response status SHALL be 401
- @e2e exclude Backend auth gate (ADR-005); no browser flow.

### Requirement: REQ-SPA-005 — The administration scope SHALL be pushed into the aggregation for every view, and a view whose source rows cannot honour it SHALL refuse rather than return a zero

`spendBySupplier()` reads `APTransaction`, which DECLARES `administrationId`, so
that view SHALL pass the caller's administration into the aggregation filter.

`spendByCategory()` / `spendByCostCentre()` / `spendByPeriod()` read `GLLine`,
which NOW declares `administrationId` too, denormalised from the parent
`GLTransaction` by `glline-administration-scope` (REQ-GLS-001). All three SHALL
pass the caller's administration into the aggregation filter.

Until that change landed, `GLLine` declared no administration or organisation
property at all — the administration lived one hop away on the parent, and
OpenRegister's `filters` address an object's own JSON properties and cannot
join — so those three views aggregated EVERY administration in the register
while the supplier view was correctly scoped. The membership check of
REQ-SPA-003 reduced their audience from "any authenticated Nextcloud user" to
"a member of some administration" but did not isolate one administration from
another. That read is closed.

The filter SHALL remain conditional on proof that the backfill is complete
(REQ-GLS-003): filtering on a property some rows lack matches nothing for those
rows, so a GL-backed view SHALL RAISE — not fall back to the unfiltered query,
and not return the filtered one — while that proof is absent. Raising leaks
nothing and claims nothing false; the two alternatives each do one of those.

#### Scenario: The GL-backed views carry the caller's administration in their filter
- **GIVEN** the category view is computed for a member of `ADM-A`
- **THEN** the filter sent to OpenRegister SHALL be exactly `{administrationId: ADM-A, side: debit, subLedgerType: ap}`, and no `ADM-B` row SHALL contribute to any group or total
- @e2e exclude `/api/analytics/spend` has no frontend consumer today (API-only surface); enforced by SpendAnalyticsServiceTest::testGlBackedViewsExcludeOtherAdministrations, proven to fail with the filter term removed.

#### Scenario: A GL-backed view refuses while the backfill is unproven
- **GIVEN** the `GLLine` administration backfill has not been proven complete
- **WHEN** the category / cost-centre / period view is requested
- **THEN** the service SHALL raise and the endpoint SHALL return an error status, rather than a total of zero
- @e2e exclude Asserts a raise at the query-construction layer driven by an app-config gate; the browser sees only a generic error status.

### Requirement: REQ-SPA-004 — Cross-tab spend analysis SHALL be routed to OpenRegister, and no inert cross-tab declaration SHALL remain undocumented

Multi-field (cross-tab) spend analysis (e.g. supplier × period, category ×
cost-centre) SHALL NOT be implemented as a leaf-side aggregator. It SHALL be
tracked as an OpenRegister capability request (openregister#432 — honour
multi-field groupBy). Any pre-existing `x-openregister-aggregations` declaration
in this app that requests a capability the engine does not run today (multi-field
groupBy, named `aggregate`/`buckets`/`return` projections) SHALL carry a note
identifying it as blocked on that issue, so no declaration claims a working
capability it does not have.

#### Scenario: The inert AP cross-tab aggregations are documented as blocked
- **GIVEN** `agedPayablesDetail` / `agedPayablesSummary` in `bookkeeping-accounts-payable-core.json` declaring a multi-field `groupBy`
- **WHEN** their declaration is read
- **THEN** each SHALL state that the multi-field groupBy is inert engine-side and blocked on openregister#432
- @e2e exclude Declaration-hygiene / orphaned-capability discipline; verified by inspection of the register fragment, no browser flow.

### Requirement: REQ-SPA-006 — The spend views SHALL have a frontend consumer, reachable without spending a top-level menu slot

Added by change `spend-analytics-ui`.

`GET /apps/shillinq/api/analytics/spend` SHALL be consumed by a
manifest-declared page rendering all four dimensions — `supplier`, `category`,
`costCentre`, `period`. Until this requirement the endpoint had none: `grep -rn
"analytics/spend" src/` returned nothing and no `src/manifest.d/*.json` declared
it, which is why every scenario above this line is legitimately marked "no
browser flow" — there was no browser flow to have.

The page SHALL be reachable by clicking, not only by typing its URL, and SHALL
NOT add a top-level menu entry: shillinq is at ADR-097's ceiling of six
top-level clusters. Its menu entry SHALL be a leaf merged into an existing
cluster.

#### Scenario: Page reachable from Reporting Compliance
- **GIVEN** a signed-in member of an administration
- **WHEN** they expand the `ReportingCompliance` top-level entry and click the `SpendAnalytics` leaf
- **THEN** the browser SHALL land on `/reporting-compliance/spend-analytics` and the spend panel SHALL mount
- @e2e spend-analytics::page-reachable-from-reporting-compliance

#### Scenario: Four views render from the endpoint
- **GIVEN** the endpoint answers each `dimension` with `{ dimension, label, groups:[{key,amount}], total, backend }`
- **WHEN** the page loads
- **THEN** each of the four views SHALL render its groups and the endpoint's own `total`, not a recomputed one
- @e2e spend-analytics::four-views-render-from-the-endpoint

#### Scenario: The top-level menu entry count does not grow
- **GIVEN** the effective manifest built by the shared `buildManifest` pipeline
- **WHEN** this change is applied
- **THEN** `manifest.menu.length` SHALL be unchanged and the new page SHALL appear as a child of `ReportingCompliance`
- @e2e exclude a property of the BUILT manifest, not of a browser session: the effective manifest is assembled by `@conduction/nextcloud-vue`'s `buildManifest(base, fragments, menuLayout)` and the count is asserted by `tests/validate-nav-reachability.js`, which runs that exact pipeline (51 top-level entries before and after). A Playwright spec could only observe the entries the nav happens to render, which is a weaker statement, and would pass against a stale bundle.

### Requirement: REQ-SPA-007 — A spend view that did not answer SHALL be rendered as unavailable, never as a zero

Added by change `spend-analytics-ui`.

`glline-administration-scope` REQ-GLS-003 makes the category, cost-centre and
period views RAISE while the `GLLine.administrationId` backfill is unproven, and
`SpendAnalyticsController::spend()` turns that raise into `HTTP 500 { "error":
"Failed to compute spend analysis" }`.

When any view returns a non-2xx status the UI SHALL render an explicit
unavailable state carrying the server's own message, and SHALL render **no**
amount, total or table for that view. It SHALL NOT render a blank region, an
empty chart, a `0` or a `€0,00`.

This is the whole reason the raise exists. A silent zero in a bookkeeping total
is a wrong number that looks like a real one, and is worse than an error. A UI
that turns the 500 into "no data" re-arms exactly the bug the gate closes — and
that is not hypothetical: `CnChartWidget` subscribes to `useEndpointSource` and
keeps only `ep.data` / `ep.refetch`, discarding `ep.error`, so a declarative
chart widget bound to this endpoint would do precisely that.

#### Scenario: A shut gate renders as unavailable not as zero
- **GIVEN** the three GL-backed views answer HTTP 500 while `supplier` answers 200
- **WHEN** the page loads
- **THEN** each GL-backed view SHALL show its unavailable state including the server's message, SHALL show no total and no table, and the `supplier` view SHALL still show its own figures
- @e2e spend-analytics::a-shut-gate-renders-as-unavailable-not-as-zero

#### Scenario: An empty result is not an unavailable one
- **GIVEN** a view answers HTTP 200 with `groups: []`
- **WHEN** the page loads
- **THEN** it SHALL say the aggregation ran and matched no rows, and SHALL NOT render the unavailable state — the two are different claims and collapsing them loses the distinction the requirement above is built on
- @e2e spend-analytics::an-empty-result-is-not-an-unavailable-one

### Requirement: REQ-SPA-008 — The page SHALL name the administration it reports on

Added by change `spend-analytics-ui`.

The endpoint requires `administration_id` and masks a non-member as 404. The
page SHALL resolve the caller's administration from `GET
/api/administrations/context`, name it on screen, and state plainly when the
caller belongs to no administration rather than rendering four empty views.

#### Scenario: A caller with no administration is told so
- **GIVEN** the context endpoint returns no administrations
- **WHEN** the page loads
- **THEN** it SHALL say the caller is a member of no administration, and SHALL NOT issue a spend request or render any figure
- @e2e exclude reachable only by stripping every `AdministrationMembership` from the signed-in user, which is shared state that every other spec in `tests/e2e/` reads — the state discipline that lets this suite run at `workers: 1`. Enforced instead by `tests/vitest/spendAnalyticsPanel.spec.js`'s "no administration leaves the panel in its 'none' state and dispatches no spend request", which asserts both halves (the state AND that no `/analytics/spend` request is issued) and was proven to fail when the guard is removed.

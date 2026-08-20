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

### Requirement: REQ-SPA-005 — The administration scope SHALL be pushed into the aggregation for every view whose source schema can carry it, and the views that cannot SHALL say so rather than pretend

`spendBySupplier()` reads `APTransaction`, which DECLARES `administrationId`, so
that view SHALL pass the caller's administration into the aggregation filter.

`spendByCategory()` / `spendByCostCentre()` / `spendByPeriod()` read `GLLine`,
which declares NO administration or organisation property — the administration
lives one hop away on the parent `GLTransaction`, and OpenRegister's `filters`
address an object's own JSON properties and cannot join. These three views
SHALL NOT be given an `administrationId` filter term: on `GLLine` such a term
addresses a property that does not exist and matches NOTHING for every value,
which would turn a real spend figure into a silent zero in a bookkeeping total.
They SHALL instead declare, in code and here, that they aggregate every
administration in the register, with the membership check of REQ-SPA-003 as
their only containment — it reduces the audience from "any authenticated
Nextcloud user" to "a member of some administration" but does not isolate one
administration from another.

Closing that gap SHALL be done by denormalising `administrationId` onto the
`GLLine` schema and backfilling existing rows (a schema + data migration), not
by adding the unmatched filter.

#### Scenario: The GL-backed views carry no administration filter until GLLine can honour one
- **GIVEN** the category view is computed
- **THEN** the filter sent to OpenRegister SHALL be exactly `{side: debit, subLedgerType: ap}` with no administration term
- @e2e exclude Pins a deliberate, documented gap at the query-construction layer; asserts the absence of a filter term, which no browser flow can observe.

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

# Spec delta: spend-analytics (change: spend-analytics-ui)

Target capability: `openspec/specs/spend-analytics/spec.md`. This change adds
the endpoint's first frontend consumer; it adds no PHP and changes no existing
requirement.

## ADDED Requirements

### Requirement: REQ-SPA-006 — The spend views SHALL have a frontend consumer, reachable without spending a top-level menu slot

`GET /apps/shillinq/api/analytics/spend` SHALL be consumed by a
manifest-declared page rendering all four dimensions — `supplier`, `category`,
`costCentre`, `period`. Until this requirement the endpoint had none: at the
base of this change `grep -rn "analytics/spend" src/` returned nothing and no
`src/manifest.d/*.json` declared it.

The page SHALL be reachable by clicking, not only by typing its URL, and SHALL
NOT add a top-level menu entry: shillinq is at ADR-097's ceiling of six
top-level clusters. Its menu entry SHALL be a leaf merged into an existing
cluster.

#### Scenario: Page reachable from Reporting Compliance

- **GIVEN** a signed-in member of an administration
- **WHEN** they expand the `ReportingCompliance` top-level entry and click the
  `SpendAnalytics` leaf
- **THEN** the browser SHALL land on `/reporting-compliance/spend-analytics` and
  the spend panel SHALL mount

@e2e spend-analytics::page-reachable-from-reporting-compliance

#### Scenario: Four views render from the endpoint

- **GIVEN** the endpoint answers each `dimension` with
  `{ dimension, label, groups:[{key,amount}], total, backend }`
- **WHEN** the page loads
- **THEN** each of the four views SHALL render its groups and the endpoint's own
  `total`, not a recomputed one

@e2e spend-analytics::four-views-render-from-the-endpoint

#### Scenario: The top-level menu entry count does not grow

- **GIVEN** the effective manifest built by the shared `buildManifest` pipeline
- **WHEN** this change is applied
- **THEN** `manifest.menu.length` SHALL be unchanged and the new page SHALL
  appear as a child of `ReportingCompliance`

@e2e exclude a property of the BUILT manifest, not of a browser session. The
effective manifest is assembled by `@conduction/nextcloud-vue`'s
`buildManifest(base, fragments, menuLayout)` and the count is asserted by
`tests/validate-nav-reachability.js`, which runs that exact pipeline (51
top-level entries before and after this change). A Playwright spec could only
observe the entries the nav happens to render, which is a weaker statement, and
would pass against a stale bundle.

### Requirement: REQ-SPA-007 — A spend view that did not answer SHALL be rendered as unavailable, never as a zero

`glline-administration-scope` REQ-GLS-003 makes the category, cost-centre and
period views RAISE while the `GLLine.administrationId` backfill is unproven, and
`SpendAnalyticsController::spend()` turns that raise into
`HTTP 500 { "error": "Failed to compute spend analysis" }`.

When any view returns a non-2xx status the UI SHALL render an explicit
unavailable state carrying the server's own message, and SHALL render **no**
amount, total or table for that view. It SHALL NOT render a blank region, an
empty chart, a `0` or a `€0,00`.

This is the whole reason the raise exists. A silent zero in a bookkeeping total
is a wrong number that looks like a real one, and is worse than an error. A UI
that turns the 500 into "no data" re-arms exactly the bug the gate closes — and
that is not hypothetical: the library's `CnChartWidget` subscribes to
`useEndpointSource` and keeps only `ep.data` / `ep.refetch`, discarding
`ep.error`, so a declarative chart widget bound to this endpoint would do
precisely that.

#### Scenario: A shut gate renders as unavailable not as zero

- **GIVEN** the three GL-backed views answer HTTP 500 while `supplier` answers
  200
- **WHEN** the page loads
- **THEN** each GL-backed view SHALL show its unavailable state including the
  server's message, SHALL show no total and no table, and the `supplier` view
  SHALL still show its own figures

@e2e spend-analytics::a-shut-gate-renders-as-unavailable-not-as-zero

#### Scenario: An empty result is not an unavailable one

- **GIVEN** a view answers HTTP 200 with `groups: []`
- **WHEN** the page loads
- **THEN** it SHALL say the aggregation ran and matched no rows, and SHALL NOT
  render the unavailable state — the two are different claims and collapsing
  them loses the distinction the requirement above is built on

@e2e spend-analytics::an-empty-result-is-not-an-unavailable-one

### Requirement: REQ-SPA-008 — The page SHALL name the administration it reports on

The endpoint requires `administration_id` and masks a non-member as 404. The
page SHALL resolve the caller's administration from
`GET /api/administrations/context`, name it on screen, and state plainly when
the caller belongs to no administration rather than rendering four empty views.

#### Scenario: A caller with no administration is told so

- **GIVEN** the context endpoint returns no administrations
- **WHEN** the page loads
- **THEN** it SHALL say the caller is a member of no administration, and SHALL
  NOT issue a spend request or render any figure

@e2e exclude reachable only by stripping every `AdministrationMembership` from
the signed-in user, which is shared state that every other spec in
`tests/e2e/` reads — the state discipline that lets this suite run at
`workers: 1`. Enforced instead by `tests/vitest/spendAnalyticsPanel.spec.js`'s
"no administration leaves the panel in its 'none' state and dispatches no spend
request", which asserts both halves (the state AND that no `/analytics/spend`
request is issued) and was proven to fail when the guard is removed.

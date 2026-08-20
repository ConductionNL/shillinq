# Spec: budget-charts

## ADDED Requirements

### Requirement: REQ-BCH-001 — `BudgetGrid` MUST offer a per-row trend chart, for both `LedgerGroup` and `Account` leaf rows

Each row on `budget-grid-view`'s `BudgetGrid` page (`LedgerGroup`
verzamelpost rows and resolved `Account` leaf rows alike) MUST offer an
independent "view trend" toggle, distinct from the row's own children/
account expand toggle, that inline-expands `BudgetTrendChart` beneath that
row. At most one row's chart MAY be open at a time grid-wide; opening a
second row's chart MUST close the first.

#### Scenario: Opening a verzamelpost row's chart reveals a three-series chart inline

- **GIVEN** a `BudgetGrid` root `LedgerGroup` row (e.g. "Omzet")
- **WHEN** its "view trend" toggle is activated
- **THEN** a chart renders beneath that row showing Actual, Projected, and
  Begroot series, without navigating away from the grid

@e2e budget-charts::grid-row-trend-toggle-renders-chart

#### Scenario: Opening a second row's chart closes the first

- **GIVEN** one `BudgetGrid` row's chart is currently open
- **WHEN** a different row's "view trend" toggle is activated
- **THEN** the first row's chart closes and only the newly activated row's
  chart is open

@e2e exclude UI-state interaction, covered by the grid-row-trend-toggle
Playwright scenario's own assertions rather than a separate test —
verified as part of `budget-charts::grid-row-trend-toggle-renders-chart`

### Requirement: REQ-BCH-002 — `ChartOfAccountsDetail` MUST offer the same three-series chart for the viewed account

`ChartOfAccountsDetail` MUST render `BudgetTrendChart` (`scope: "account"`)
for the account the page displays. The placement mechanism (sidebar tab or
in-grid widget) MUST be resolved by the spike in `design.md` §1b before
implementation, with the documented fallback chain if the primary mechanism
does not work on this page's still-unmigrated `type: "detail"` dialect.

#### Scenario: The account detail page renders its own trend chart

- **GIVEN** an operator viewing `ChartOfAccountsDetail` for an `Account`
  with posted GL activity
- **WHEN** the chart surface is opened (tab click, or already-visible if
  shipped in-grid)
- **THEN** a chart renders showing Actual, Projected, and Begroot series
  scoped to that one account

@e2e budget-charts::account-detail-chart-renders

### Requirement: REQ-BCH-003 — The three series MUST be sourced exactly as specced by the three sibling changes, never re-derived

Actual MUST be computed from `GLTransaction`+`GLLine`+`Account` via
`budget-core-schema`'s `BudgetVsActualsReader`/`Calculator`, reused
unchanged. Projected MUST be the typed per-month result
(`actual`/`projected`/`unprojectable`) from `budget-projection-engine`'s
`BudgetProjectionService`, reused unchanged, including its `LedgerGroup`
sum-of-members rule (never an independent group-level fit) and its
`partial` tagging. Budgeted MUST be read from `BudgetLine.month01Amount..
month12Amount` for the `AnnualBudget` in force for each column's own
fiscal year — the `isDefault: true` `AnnualBudget` by default, or the
`AnnualBudget` identified by an explicit `annualBudgetId` parameter when
supplied, forward-compatible with a future scenario selector.

#### Scenario: Actual values come from GL activity, never `TrialBalanceLine`

- **GIVEN** an account with posted `GLTransaction`/`GLLine` activity in a
  given month
- **WHEN** `BudgetChartSeriesService` resolves the Actual series for that
  month
- **THEN** the value equals the GL-derived amount `BudgetVsActualsReader`
  computes, and no `TrialBalanceLine` object is read

@e2e exclude backend composition, no browser-visible surface — verified by
`BudgetChartSeriesServiceTest` asserting the collaborator is
`BudgetVsActualsReader`, not a `TrialBalanceLine` read

#### Scenario: Projected values are the engine's typed result, not re-derived

- **GIVEN** an account whose `BudgetProjectionService` result for a future
  month is `{kind: "projected", amount: 10612, rate: 0.02, validSteps: 5}`
- **WHEN** `BudgetChartSeriesService` shapes the response for that month
- **THEN** the Projected series carries exactly `10612` for that month —
  no independent growth-rate computation runs inside
  `BudgetChartSeriesService` itself

@e2e exclude backend composition, no browser-visible surface — verified by
`BudgetChartSeriesServiceTest` with a mocked `BudgetProjectionService`

#### Scenario: An explicit `annualBudgetId` overrides the default-budget resolution

- **GIVEN** two `AnnualBudget`s for the same administration and fiscal
  year, one `isDefault: true` and one not
- **WHEN** the chart series request supplies the non-default budget's id as
  `annualBudgetId`
- **THEN** the Begroot series reads that specific `AnnualBudget`'s own
  `BudgetLine`s, not the default one's

@e2e exclude backend parameter-threading, no browser-visible surface today
(no scenario UI exists yet, per `design.md` §2a/§12) — verified by
`BudgetChartSeriesServiceTest`

### Requirement: REQ-BCH-004 — An `unprojectable` result MUST render as an explicit, text-labelled state, never a zero line

Every month whose projection result is `{kind: "unprojectable", …}` MUST
render as a genuine gap in the Projected series (no plotted point), with a
distinct non-color-only marker glyph and a tooltip reading a localised
"Cannot project yet" string, and MUST render as literal "Cannot project
yet" text (not an empty cell, not "€0") in the accessible data-table
fallback. A `partial`-tagged `LedgerGroup` result MUST render its real
partial sum with a visible "partial" annotation, never silently as a
complete figure.

#### Scenario: An unprojectable month shows explicit text, not a zero value

- **GIVEN** an account with fewer than `MIN_VALID_STEPS` valid growth
  steps, so `budget-projection-engine` returns `{kind: "unprojectable",
  reason: "insufficient-data"}` for a given month
- **WHEN** that month is rendered in `BudgetTrendChart`'s data-table
  fallback
- **THEN** the cell reads "Cannot project yet" (localised), not "€0" and
  not blank

@e2e budget-charts::unprojectable-renders-as-text-not-zero

#### Scenario: A partially unprojectable `LedgerGroup` shows its real partial sum, tagged

- **GIVEN** a `LedgerGroup` with two members, one projectable and one
  `unprojectable` for a given month, per `budget-projection-engine`
  REQ-BPE-007
- **WHEN** that month renders in the chart
- **THEN** the projectable member's own value is shown, with a visible
  "partial" annotation, not withheld and not presented as a complete
  group-level figure

@e2e exclude visual annotation styling, covered by the unprojectable
Playwright scenario's own data-table assertion rather than a separate
test — the underlying `partial` tag is already covered by
`budget-projection-engine`'s own `testPartialGroupTaggedNotWithheld`

### Requirement: REQ-BCH-005 — A Trend/Cumulative toggle MUST be available, disabled (not duplicated) when trend and cumulative would render identically

`BudgetTrendChart` MUST offer a Trend/Cumulative toggle matching the visual
pattern already established by the Financial-overview dashboard's `€`/`%`
and `Hours`/`%` `views[]` toggles (two labelled, `aria-pressed` buttons
above the chart). When the chart's scope is entirely stock-typed
(`assets`/`liabilities`/`equity`), the Cumulative button MUST render
`disabled` with an explanatory tooltip, since `budget-projection-engine`
REQ-BPE-008 defines cumulative as identical to trend for those account
types. A mixed-type `LedgerGroup` MUST keep the toggle enabled and compute
its cumulative series member-by-member using each member's own correct
rule before summing.

#### Scenario: The Cumulative toggle changes rendered values for a flow account

- **GIVEN** `BudgetTrendChart` scoped to a `revenue` account with distinct
  per-month and cumulative-YTD values
- **WHEN** the Cumulative button is activated
- **THEN** the rendered data-table values change to the running
  fiscal-year-to-date sums, differing from the Trend view's per-period
  values for at least one month

@e2e budget-charts::cumulative-toggle-changes-rendering

#### Scenario: The Cumulative toggle is disabled for a stock account

- **GIVEN** `BudgetTrendChart` scoped to an `assets` account
- **WHEN** the chart renders
- **THEN** the Cumulative button is `disabled`, carrying a tooltip
  explaining that cumulative equals trend for balance-sheet accounts

@e2e exclude disabled-state rendering, no distinct browser-visible
interaction beyond the button's own `disabled` attribute — verified by
vitest against `BudgetTrendChart.vue`

### Requirement: REQ-BCH-006 — The actual→projected seam MUST be visually distinct and MUST NOT double-cover any period

The Actual and Projected series MUST be two separately named series (not
one series switching styles mid-line), each `null` outside its own
non-overlapping range per `budget-projection-engine` REQ-BPE-006's
per-account seam rule, so no month is ever plotted by both series. The
Projected series MUST render dashed and at reduced opacity relative to the
solid, full-opacity Actual series.

#### Scenario: No month is ever plotted by both the Actual and Projected series

- **GIVEN** an account with actuals through month 6 and projections for
  months 7-9
- **WHEN** the chart renders
- **THEN** months 1-6 carry only an Actual data point and months 7-9 carry
  only a Projected data point — no month carries both

@e2e exclude arithmetic/seam-placement logic, already covered by
`budget-projection-engine`'s own `testSeamNeverOverridesAnActual`/
`testSeamIsPerAccountNotGlobal`; this change's own coverage is the
rendering pass-through, verified by vitest against `BudgetTrendChart.vue`

#### Scenario: The Projected series renders visually distinct from Actual

- **GIVEN** a chart with both Actual and Projected data
- **WHEN** it renders
- **THEN** the Projected series is dashed and reduced-opacity relative to
  the solid Actual series — a shape difference, not a colour-only one

@e2e exclude visual-styling assertion, covered by the grid-row-trend-toggle
and account-detail-chart-renders Playwright scenarios' own rendering checks
rather than a dedicated style-parsing test

### Requirement: REQ-BCH-007 — `BudgetTrendChart` MUST be a single shared custom component, registered once, reused by both placements

Given the declarative `type: "chart"` dialect's lack of per-series dashed/
dimmed styling, a two-data-source merge, and point-level `unprojectable`
annotation, `BudgetTrendChart` MUST be a custom `kind: "widget"` component
registered once in `src/registry.js` and consumed, unduplicated, by both
the `BudgetGrid` inline placement and the `ChartOfAccountsDetail` placement
via a `scope: "ledgerGroup" | "account"` prop.

#### Scenario: The same component renders both placements

- **GIVEN** the `budget-charts` implementation
- **WHEN** `src/registry.js` and the two consuming manifest/component
  locations are inspected
- **THEN** exactly one `BudgetTrendChart` component exists and both
  placements import/reference it, with no second, divergent chart
  component for either surface

@e2e exclude structural/architectural requirement — verified by code
inspection and by both Playwright scenarios (`grid-row-trend-toggle-renders-chart`,
`account-detail-chart-renders`) exercising the same underlying component

### Requirement: REQ-BCH-008 — Chart data reads MUST reuse the grid's already-fetched state and MUST NOT scale with the number of open charts

`BudgetGrid`'s own already-fetched Actual and Budgeted values MUST be
reused for its inline chart without any additional query. The Projected
series (and, for `ChartOfAccountsDetail`, the Actual/Budgeted series too)
MUST be fetched via a single `GET /api/budget-charts/series` request per
`(administrationId, range, annualBudgetId)` key, cached client-side by a
module-singleton composable, so that opening a second, third, or Nth chart
on the same page visit issues zero additional network requests.

#### Scenario: Opening a second chart on the same page issues no additional network request

- **GIVEN** one `BudgetGrid` row's chart is already open (and its data
  fetched)
- **WHEN** a second row's chart is opened for the same administration and
  displayed period range
- **THEN** no new `/api/budget-charts/series` request is issued — the
  cached response from the first open is reused

@e2e exclude network-call-count assertion — verified by a Playwright
network-request-count check within `budget-charts::grid-row-trend-toggle-renders-chart`,
not a separate scenario id

#### Scenario: The chart-series endpoint's query count is bounded independent of scope

- **GIVEN** a `/api/budget-charts/series` request covering every account
  and `LedgerGroup` in an administration
- **WHEN** `BudgetChartSeriesService` resolves the response
- **THEN** the total `findAll()` calls issued by its composed collaborators
  stays within the bound each sibling already established (≤5 for
  `BudgetVsActualsReader`, ≤4 for `BudgetProjectionReader`) — the
  orchestration layer itself issues no additional `findAll()` calls of its
  own beyond `AnnualBudget` resolution

@e2e exclude query-count regression, no browser-visible surface — verified
by `BudgetChartSeriesServiceTest` against a call-counting mock, mirroring
every sibling's own `testQueryCountIndependentOf…` precedent

### Requirement: REQ-BCH-009 — Every chart MUST carry an accessible name and a structured data-table fallback

`BudgetTrendChart` MUST declare an accessible group name identifying the
scoped entity, and MUST offer a toggleable data table rendering the same
Actual/Projected/Begroot × period data as real table markup (`<caption>`,
`<th scope="col">`, `<th scope="row">`), including `unprojectable` cells as
literal text per REQ-BCH-004. The Trend/Cumulative toggle and the
`BudgetGrid` row's "view trend" action MUST be native `<button>` elements
with `aria-pressed`/`aria-expanded` respectively.

#### Scenario: The chart's accessible name identifies the scoped entity

- **GIVEN** `BudgetTrendChart` scoped to the "Personeel" `LedgerGroup`
- **WHEN** the chart's outer container is inspected via the accessibility
  tree
- **THEN** it carries an accessible name mentioning "Personeel", not a
  generic "chart" label

@e2e exclude accessibility-tree assertion, no dedicated scenario id — part
of the axe-core pass required by `tasks.md` on both consuming pages

#### Scenario: The data table renders the same information a screen-reader user can read without parsing the chart

- **GIVEN** an open `BudgetTrendChart`
- **WHEN** "View as table" is activated
- **THEN** a table renders with one column per period plus `TOTAAL`, one
  row per series (Actual/Projected/Begroot), and any `unprojectable` cell
  reading "Cannot project yet"

@e2e budget-charts::unprojectable-renders-as-text-not-zero

### Requirement: REQ-BCH-010 — Non-goals

This change MUST NOT implement the `BudgetGrid` row/column/expand model
(`budget-grid-view`), projection arithmetic (`budget-projection-engine`),
known-cost derivation (`budget-known-costs`), or scenario/modifier
switching UI (`budget-scenarios`). It MUST NOT patch
`AggregationAnnotationValidator` (foundation/openregister scope). It MUST
NOT migrate `ChartOfAccountsDetail` to the ADR-062 `widgets`/`layout`
dialect as part of this change (`design.md` §1b/§12).

#### Scenario: No grid, projection-arithmetic, known-cost, or scenario code appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no `BudgetGrid` row-tree/column-model edit beyond the per-row
  chart-toggle addition, no growth-rate/seam arithmetic, no
  `BudgetLine.source` writer, and no scenario-switching UI is present —
  only `BudgetTrendChart`, `useBudgetChartData`, `BudgetChartSeriesService`,
  `BudgetChartsController`, and their tests

@e2e exclude negative/scope-boundary requirement — verified by diff
inspection

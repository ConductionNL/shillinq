# Change: budget-charts

## Why

The user's own requirement for this wave, verbatim: *"on each grootboek or
boek we should have actual, projected and begroot in trend and cumulative so
it would be good to add those as graphs."* Three siblings already built
everything a chart needs to read but render nothing: `budget-core-schema`
gives `LedgerGroup`/`AnnualBudget`/`BudgetLine` and a PHP-primary
`BudgetVsActualsReader`/`Calculator` for actual-vs-budget;
`budget-projection-engine` gives a pure, typed `BudgetProjectionCalculator`
(actual / projected / **unprojectable**, trend + cumulative, per account and
per `LedgerGroup`) with no UI of its own; `budget-grid-view` gives the
year-basis spreadsheet grid but explicitly defers "trend/traffic-light
visualisations" as `budget-charts`' own scope. Nobody has drawn a line yet.

This change specs and implements exactly that: a shared chart component
rendering the three series (actual / projected / begroot) in two shapes
(trend, cumulative) for each grootboek (`Account`, via `ChartOfAccountsDetail`)
and each verzamelpost (`LedgerGroup`, via `budget-grid-view`'s `BudgetGrid`
page) — reusing, not redesigning, every sibling's already-specced reader and
calculator.

### What already exists that this change reuses, verified at HEAD

- `CnChartWidget` (`@conduction/nextcloud-vue` ^2.3.0, ApexCharts 4.7.0 /
  vue3-apexcharts 1.8.0) — the declarative `type: "chart"` widget dialect
  already live on the Financial overview dashboard (`src/manifest.json`
  lines ~5160-5230): `chartKind: bar|line`, `height`, `legend`,
  `valueFormat`, and — the exact house precedent for this change's own
  trend/cumulative toggle — a `views[]` array (`{key, label, series,
  valueFormat}`) already switching the `margin-chart` widget between
  `€`/`Margin %` and the `billable-hours-chart` widget between
  `Hours`/`Billable %`, purely by re-labelling and re-filtering
  already-fetched named series.
- `CashflowChartWidget.vue`
  (`src/components/dashboard/financial/CashflowChartWidget.vue`) and
  `BBVTrendChart.vue` (`src/components/Dashboard/BBVTrendChart.vue`) — the
  two existing custom (`kind: "widget"`) chart components, both registered
  because they merge more than one data source or need styling a declarative
  `series[].path` cannot express. `CashflowChartWidget` is this change's
  direct precedent for the actual→projected seam: `financialSeries.js`'s
  `forecastByMonth(weeks, afterMonth)` drops months overlapping realized
  data and the component appends the forecast as **dimmed, separately-named**
  series (`padRealized`/`padForecast`, `colors` array with a `55`-alpha
  suffix for the forecast pair) — the same "two named series, nulled outside
  their own range" shape this change reuses for actual vs. projected.
  `CashflowChartWidget`'s own header comment also records a real defect
  (`v-show`, never `v-if`, on the chart host — an unmounted-mid-`await`
  ApexCharts init throws `Element not found` onto `pageerror`) this change's
  own new component must avoid by the same rule.
- `ChartOfAccountsDetail` (`src/manifest.json:4642`, route
  `/chart-of-accounts/:id`, schema `Account`) — verified live: it is still
  the **old** `fields`/`relatedLists`/`aggregation` manifest dialect, **not**
  yet migrated to ADR-062's `config.widgets`/`config.layout` grid (only 2 of
  96 shillinq detail pages have migrated — `CustomerDetail`, `ARInvoiceDetail`
  — verified by `jq` sweep of `src/manifest.json`). It has one existing
  sidebar tab (`sidebarProps.tabs`, `id: "audit"`) rendering a
  `componentName: "openregister-audit-trail"` widget — an
  OpenRegister-library-provided component, not an app-local one. **No
  `kind: "sidebarTab"` registry entry exists anywhere in `src/registry.js`
  today**, and no `type: "detail"` page anywhere in this manifest uses the
  `slots` + `type: "custom"` widget mechanism `CashflowChartWidget` uses on
  the `Dashboard` page — both routes this change could take for injecting a
  chart into this page are **unproven on a detail page**, verified by grep,
  not assumed. `design.md` §1b makes this an explicit first-task spike with
  a stated fallback chain, not a silent assumption either mechanism works.
- `BudgetGrid` (`budget-grid-view`, route `/begroting/grid`, `type: "custom"`)
  — the only surface `LedgerGroup` verzamelposten already render on, with
  its row toggle already ADR-059-compliant
  (`tabindex="0"`/`role="button"`/`:aria-expanded`/`@click`+`@keyup.enter`+
  `@keyup.space`, per that change's own `design.md` §6). This change adds a
  **second**, independent per-row toggle (a "view trend" action, not the
  existing children/account expand toggle) rather than inventing a new
  interaction model.
- `BudgetVsActualsReader`/`Calculator` (`budget-core-schema` §6b, amended)
  and `BudgetProjectionReader`/`Calculator`/`Service`
  (`budget-projection-engine`) — both already compute directly from
  `GLTransaction`+`GLLine`+`Account`, never `TrialBalanceLine` (which has no
  persisted rows, per `TrialBalanceService.php`'s own docblock, the defect
  both siblings independently caught and corrected). This change's own new
  backend service composes these two, reusing their exact batched,
  dual-keyed-`transactionRefs`, in-memory-bucketed-by-`(accountNumber,
  monthKey)` shape — it does not re-derive the GL join a third time.

## What Changes

- **ADD** (frontend, shared): `BudgetTrendChart.vue`
  (`src/components/budget-charts/`), one custom `kind: "widget"` component
  rendering actual / projected / begroot as trend or cumulative, for either
  scope (`ledgerGroup` or `account`) — consumed from two placements below.
  `design.md` §3-§6.
- **ADD** (frontend, composable): `useBudgetChartData.js`
  (`src/components/budget-charts/`), a module-singleton fetch-once-per-
  `(administrationId, range, annualBudgetId)` cache, modelled directly on
  `useFinancialData.js`'s own shape — the mechanism that keeps every chart
  interaction on a page after the first free of additional queries.
  `design.md` §8.
- **ADD** (`BudgetGrid` placement): a per-row "view trend" icon-button
  (`LedgerGroup` rows AND resolved `Account` leaf rows), inline-expanding
  `BudgetTrendChart` beneath that row; at most one row's chart open at a
  time. `design.md` §1a.
- **ADD** (`ChartOfAccountsDetail` placement): a chart surface for the
  viewed `Account`, placement decided by `design.md` §1b's spike (sidebar
  tab now, in-grid widget once/if that page migrates to ADR-062's
  `widgets`/`layout` dialect — not this change's own scope to force).
- **ADD** (backend): `BudgetChartSeriesService`
  (`lib/Service/BudgetChartSeriesService.php`) — a thin orchestrator
  composing `BudgetVsActualsReader`/`Calculator` and `BudgetProjectionService`
  (reused, not reimplemented) into the trend+cumulative, actual/projected/
  budgeted response shape both frontend placements consume. `design.md` §7.
- **ADD** (backend, route): `BudgetChartsController::series()`,
  `GET /apps/shillinq/api/budget-charts/series`
  (`#[NoAdminRequired]`, RBAC via OpenRegister reads, same posture as
  `FinancialDashboardController`). `design.md` §7-§8.
- **ADD**: PHPUnit coverage for `BudgetChartSeriesService` (composition only
  — no arithmetic duplicated, per `design.md` §7) and vitest coverage for
  `BudgetTrendChart.vue`'s series-shaping (seam placement, unprojectable
  rendering, cumulative account-type branching) — arithmetic itself stays
  the already-tested `BudgetProjectionCalculator`'s job. `design.md` §11.
- **ADD**: Playwright e2e (`tests/e2e/budget-charts.spec.ts`) asserting
  browser-visible chart rendering and the trend/cumulative toggle — not
  arithmetic, which is `@e2e exclude`d to the PHPUnit/vitest coverage above.
  `design.md` §11.
- **Non-goals, each naming its owning change** (`design.md` §12): the grid
  itself (`budget-grid-view`), projection math (`budget-projection-engine`),
  known-cost derivation (`budget-known-costs`), scenario/modifier semantics
  (`budget-scenarios` — not yet authored beyond a placeholder `.openspec.yaml`
  as of this writing; this change ships a forward-compatible
  `annualBudgetId` override rather than blocking on it).

## Impact

- **Affected specs**: new capability `budget-charts`
  (`specs/budget-charts/spec.md`). No existing capability spec is modified —
  this change adds a component, a composable, a service, a controller/route,
  and two placements, reusing every sibling's already-specced schema and
  service surface unchanged.
- **Affected code**: 1 new Vue component + 1 composable (frontend), 1
  `BudgetGrid.vue` edit (per-row trend toggle — `budget-grid-view`'s own
  file, edited here per that change's explicit "reuses, does not
  redesign" framing), 1 `ChartOfAccountsDetail` manifest edit (shape decided
  by the §1b spike), 1 new PHP service + 1 new controller + 1 route entry,
  PHPUnit + vitest + Playwright coverage.
- **Hard dependency — all three siblings**: this change requires
  `budget-core-schema`'s `BudgetVsActualsReader`/`Calculator`,
  `budget-projection-engine`'s `BudgetProjectionService`, and
  `budget-grid-view`'s `BudgetGrid` page to exist before implementation
  starts — it composes all three, it does not stand alone.
- **Byte budget**: this change adds no new manifest page (both placements
  reuse existing pages/routes) — the only manifest bytes added are the
  `ChartOfAccountsDetail` edit (§1b) and, if `BudgetGrid`'s own manifest
  fragment carries a `computedRows`-style config block for the trend toggle
  (it does not — the toggle is component-local Vue state, not manifest
  config, per `design.md` §5), none there either. `tasks.md` still requires
  running `node tests/check-manifest-budget.js` after the
  `ChartOfAccountsDetail` edit and reporting the exact delta, same
  discipline as every sibling in this program.
- **No cross-repo impact.**

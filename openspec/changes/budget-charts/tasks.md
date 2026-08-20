# Tasks: budget-charts

## 0. Pre-flight — dependency check (`design.md` §0)
- [ ] Confirm `budget-core-schema`'s `BudgetVsActualsReader`/`Calculator`,
      `budget-projection-engine`'s `BudgetProjectionService`, and
      `budget-grid-view`'s `BudgetGrid` page have landed — this change
      composes all three and cannot start before they exist. If not yet
      landed, STOP and coordinate rather than duplicating their work here.
- [ ] Re-read `budget-known-costs` and `budget-scenarios` at their current
      state (they were being authored concurrently as of this change's own
      first draft) and confirm neither has since changed the `BudgetLine`
      monthly-amount read contract or introduced a scenario-selector this
      change should now wire into, rather than the forward-compatible
      `annualBudgetId` prop stub (`design.md` §2a).

## 1. Spike — `ChartOfAccountsDetail` chart-attachment mechanism (`design.md` §1b, REQ-BCH-002)
- [ ] Prototype a `kind: "sidebarTab"` registry entry in `src/registry.js`
      for a trivial placeholder component, add it to
      `ChartOfAccountsDetail`'s existing `sidebarProps.tabs` array (same
      shape as the existing `"audit"` entry), and confirm in a running dev
      instance that it renders. Record the result in this change's PR
      description, not silently assumed either way.
- [ ] If it renders: proceed with task group 5 (sidebar tab placement). If
      it does not: fall back to registering `ChartOfAccountsDetail` as a
      `kind: "page"` custom component per `design.md` §1b's fallback
      (recompose the existing `fields`/`relatedLists`/`aggregation` content
      by hand, matching e.g. `SupplierInvoiceDetail`'s own shape) plus the
      chart — record which path was taken and why.

## 2. Backend — `BudgetChartSeriesService` (REQ-BCH-003, REQ-BCH-008)
- [ ] Add `lib/Service/BudgetChartSeriesService.php`: constructor-injects
      `BudgetVsActualsReader`/`Calculator` (`budget-core-schema`) and
      `BudgetProjectionService` (`budget-projection-engine`) — no direct
      OpenRegister/`ObjectServiceInterface` dependency of its own beyond
      what resolving the target `AnnualBudget` requires. `resolveSeries(
      administrationId: string, from: string, to: string, ?annualBudgetId:
      string): array` — per `design.md` §7/§8a: resolves the in-force
      `AnnualBudget` per fiscal year in range (default `isDefault: true`,
      or the explicit override), calls both collaborators, shapes the
      trend + cumulative response envelope for every account with GL
      activity or `LedgerGroup` membership in the range (the response-size
      scoping rule, `design.md` §8a) — no re-derivation of the GL join or
      the growth-rate arithmetic.
- [ ] PHPUnit: `BudgetChartSeriesServiceTest` — composition-only assertions
      (right collaborators called with right arguments, response shape
      correct, `annualBudgetId` override threads through, `unprojectable`/
      `partial` tags pass through unchanged) plus the query-count
      regression against a call-counting mock of both collaborators,
      asserting no additional `findAll()` beyond `AnnualBudget` resolution
      (REQ-BCH-008's second scenario).

## 3. Backend — controller + route (REQ-BCH-003)
- [ ] Add `lib/Controller/BudgetChartsController.php`::`series()`,
      `#[NoAdminRequired]`, docblock matching
      `FinancialDashboardController`'s own RBAC/multitenancy-via-OR-reads
      reasoning. Params: `administrationId` (required),
      `from`/`to` (required, format-validated), `annualBudgetId`
      (optional).
- [ ] Add the route entry to `appinfo/routes.php`:
      `['name' => 'budgetCharts#series', 'url' =>
      '/api/budget-charts/series', 'verb' => 'GET']`, grouped near the
      existing financial-dashboard/spend-analytics read-only entries with a
      matching comment block.

## 4. Frontend — shared component + composable (REQ-BCH-004, REQ-BCH-005, REQ-BCH-006, REQ-BCH-007, REQ-BCH-009)
- [ ] Add `src/components/budget-charts/useBudgetChartData.js`: module-
      singleton fetch-once-per-`(administrationId, range, annualBudgetId)`
      cache, modelled on `useFinancialData.js` (`load()`/`reload()`,
      in-flight-promise guard).
- [ ] Add `src/components/budget-charts/BudgetTrendChart.vue`: props
      `scope: 'ledgerGroup' | 'account'`, `id`, `name`, `administrationId`,
      `range`, `annualBudgetId` (optional). Renders via `CnChartWidget`
      (`v-show`, never `v-if`, per `design.md` §1a's cited
      `CashflowChartWidget` mounting hazard). Builds the Actual/Projected
      gap-series pair per `design.md` §5, the `unprojectable` marker+
      tooltip per §3/REQ-BCH-004, the Trend/Cumulative toggle per §4/
      REQ-BCH-005 (disabled for all-stock scope), and colors per §10's
      `var(--nc-token, #fallback)` convention (no bare hex).
- [ ] Add the accessible data-table fallback (§9/REQ-BCH-009): `<table>`
      with `<caption>`, `<th scope="col">` per period + `TOTAAL`,
      `<th scope="row">` per series, `unprojectable` cells rendering
      literal localised "Cannot project yet" text. Toggle via a persistent
      "View as table" `<button aria-pressed>`.
- [ ] Add `data-testid` hooks: `budget-trend-chart`,
      `budget-trend-chart-toggle-cumulative`,
      `budget-trend-chart-view-table`, `budget-trend-chart-table-cell`
      (per series/period, for the Playwright spec's data-table
      assertions).
- [ ] Register `BudgetTrendChart` in `src/registry.js`
      (`{ kind: 'widget', component: BudgetTrendChart }`), matching the
      `CashflowChartWidget` entry's shape and docblock convention.

## 5. Frontend — `BudgetGrid` placement (REQ-BCH-001)
- [ ] Edit `src/views/BudgetGrid.vue` (`budget-grid-view`'s own file, per
      that change's "reuses, does not redesign" framing): add a "view
      trend" icon-button per row (`LedgerGroup` and resolved `Account` leaf
      rows alike), `tabindex="0"`/`role="button"`/`:aria-expanded`/
      `@click`/`@keyup.enter`/`@keyup.space` per ADR-059, matching the
      grid's own existing expand-toggle pattern. Add `openChartRowId`
      state (single ref, closes any previously open chart on a new
      selection).
- [ ] Mount `BudgetTrendChart` inline beneath the open row, `scope`/`id`
      derived from the row's own `LedgerGroup`/`Account` data, `range` from
      the grid's own currently-displayed period range.
- [ ] Add `data-testid="budget-grid-view-trend-toggle"` per row.

## 6. Frontend — `ChartOfAccountsDetail` placement (REQ-BCH-002)
- [ ] Per task group 1's spike outcome: either (a) add a new
      `sidebarProps.tabs` entry (`id: "trend"`, label "Trend", icon
      `ChartLine`, order `95`) rendering `BudgetTrendChart` with
      `scope: "account"`, `id: <accountNumber>`; or (b) if the spike
      failed, implement the `kind: "page"` fallback component recomposing
      the page's existing content plus the chart.
- [ ] `node tests/check-manifest-budget.js` — PASS, report the exact byte
      delta for the manifest edit.

## 7. e2e coverage (REQ-BCH-001 through REQ-BCH-009)
- [ ] Add `tests/e2e/budget-charts.spec.ts` covering
      `budget-charts::grid-row-trend-toggle-renders-chart` (incl. the
      second-chart-closes-first and no-additional-network-request
      assertions, folded into this scenario per `specs/budget-charts/spec.md`),
      `budget-charts::account-detail-chart-renders`,
      `budget-charts::cumulative-toggle-changes-rendering`,
      `budget-charts::unprojectable-renders-as-text-not-zero` — modelled on
      `tests/e2e/budget-grid-view.spec.ts` (SPDX header, `becomesVisible`
      helper, data-defensive `test.skip()` when no qualifying seed data
      exists for the current administration).
- [ ] Tag each Playwright test `@e2e budget-charts::<slug>` matching
      `specs/budget-charts/spec.md`'s scenario ids exactly (gate-19 /
      `hydra-gate-e2e-coverage`).
- [ ] Run an axe-core pass against both consuming pages with a chart open —
      record the result; fix any violation before shipping, per
      `design.md` §9's accessible-name/data-table/contrast requirements.

## 8. Validation
- [ ] `node tests/check-manifest-budget.js` — PASS (task group 6).
- [ ] `npm run check:nav-reachability` — PASS (no new route added; confirm
      no regression from the `ChartOfAccountsDetail` edit).
- [ ] Run PHPUnit for touched files: `BudgetChartSeriesServiceTest` — all
      green, including the query-count regression.
- [ ] Run vitest for `BudgetTrendChart.vue`: series-shaping (Actual/
      Projected gap-series split, `unprojectable` marker rendering,
      account-type-driven Cumulative-toggle disabling) — all green.
- [ ] `npx playwright test tests/e2e/budget-charts.spec.ts` — PASS.
- [ ] `openspec validate budget-charts --strict` — PASS.

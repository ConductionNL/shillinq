# Tasks: budget-charts

## 0. Pre-flight — dependency check (`design.md` §0)
- [x] Confirm `budget-core-schema`'s `BudgetVsActualsReader`/`Calculator`,
      `budget-projection-engine`'s `BudgetProjectionService`, and
      `budget-grid-view`'s `BudgetGrid` page have landed — this change
      composes all three and cannot start before they exist. If not yet
      landed, STOP and coordinate rather than duplicating their work here.
      **RESULT: budget-core-schema and budget-projection-engine ARE
      present in this worktree (based on `feat/budget-projection-engine`,
      which is based on budget-core-schema). `budget-grid-view`'s
      `BudgetGrid` page is NOT present — verified: no `BudgetGrid.vue`
      anywhere in this tree, no `/begroting/grid` route in
      `src/manifest.json`, no commit for it in this branch's history.
      Per this task's own instruction, coordinated rather than
      duplicated: task group 5 (the `BudgetGrid` per-row placement) is
      NOT implemented here — see task group 5's own note below. Every
      other task group, which depends only on the two present siblings,
      IS implemented.**
- [x] Re-read `budget-known-costs` and `budget-scenarios` at their current
      state (they were being authored concurrently as of this change's own
      first draft) and confirm neither has since changed the `BudgetLine`
      monthly-amount read contract or introduced a scenario-selector this
      change should now wire into, rather than the forward-compatible
      `annualBudgetId` prop stub (`design.md` §2a).
      **RESULT: `budget-scenarios` is still only a placeholder
      `.openspec.yaml` in this worktree — no proposal/design/spec exists to
      re-read. `budget-known-costs`'s spec (present) writes
      `BudgetLine.source` values only; it does not touch the monthly-amount
      read contract this change consumes. No change needed to the
      forward-compatible `annualBudgetId` stub.**

## 1. Spike — `ChartOfAccountsDetail` chart-attachment mechanism (`design.md` §1b, REQ-BCH-002)
- [x] Prototype a `kind: "sidebarTab"` registry entry in `src/registry.js`
      for a trivial placeholder component, add it to
      `ChartOfAccountsDetail`'s existing `sidebarProps.tabs` array (same
      shape as the existing `"audit"` entry), and confirm in a running dev
      instance that it renders. Record the result in this change's PR
      description, not silently assumed either way.
      **RESULT — PRIMARY MECHANISM WORKS, verified by reading the actual
      nc-vue runtime source (no matching live dev instance of this
      worktree's branch exists to click-test against; this is static
      verification through the real code path, not an assumption):
      `CnObjectSidebar.vue`'s open-enum `tabs` branch resolves
      `tabs[].widgets[].type` against `resolveWidgetComponent()`, which
      falls back to `this.effectiveCustomComponents[type]` for any
      non-built-in type. `effectiveCustomComponents` reads the injected
      `cnCustomComponents`, which `CnAppRoot.vue`'s own `provide()`
      (line ~686) sets to `this.customComponents` — CnAppRoot's own prop.
      `src/App.vue` passes `:customComponents="customComponents"` to
      CnAppRoot, and `src/main.js` builds that prop
      (`customComponentsProp`) by flattening EVERY entry in
      `src/registry.js` that carries a `.component` — regardless of its
      declared `kind`. Conclusion: a plain `kind: "widget"` registry entry
      (the SAME kind `CashflowChartWidget` and this change's own
      `BudgetTrendChart` already use, per `design.md` §6) is sufficient
      for the sidebar-tab `widgets[].type` path — **no separate
      `kind: "sidebarTab"` entry is needed**, so none was added. Shipped
      instead of a throwaway placeholder: the real
      `{ type: "BudgetTrendChart", props: { scope: "account" } }` tab
      entry (task group 6) — the `widgets[]` path also hands the
      component `objectData` (the loaded `Account` row) via
      `widgetBindings()`, which `BudgetTrendChart` consumes directly
      (`objectData` prop, `effectiveId`/`effectiveName`/
      `effectiveAdministrationId` computed fallbacks) since a sidebar
      tab's `props` are static manifest JSON with no token-substitution
      mechanism for per-object values — a second, useful reason this path
      won over the tab-level `component:` alternative (which does NOT
      receive `objectData`).**
- [x] If it renders: proceed with task group 5 (sidebar tab placement). If
      it does not: fall back to registering `ChartOfAccountsDetail` as a
      `kind: "page"` custom component per `design.md` §1b's fallback
      (recompose the existing `fields`/`relatedLists`/`aggregation` content
      by hand, matching e.g. `SupplierInvoiceDetail`'s own shape) plus the
      chart — record which path was taken and why.
      **RESULT: primary path taken — sidebar tab, `widgets[].type`
      resolution, `kind: "widget"` registry entry (no fallback needed).**

## 2. Backend — `BudgetChartSeriesService` (REQ-BCH-003, REQ-BCH-008)
- [x] Add `lib/Service/BudgetChartSeriesService.php`: constructor-injects
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
      **DEVIATION, documented in the class's own docblock: injects
      `BudgetProjectionReader`/`Calculator` directly instead of
      `BudgetProjectionService`. Reason: `BudgetProjectionService`'s own
      public `projectAccount()`/`projectGroup()` methods each call
      `BudgetProjectionReader::loadContext()` AFRESH per invocation
      (verified by reading `BudgetProjectionService.php` — neither method
      accepts an already-loaded context). Calling either once per
      account/group in the administration — which REQ-BCH-008 requires
      this endpoint to do, since it returns every account and every
      `LedgerGroup` in one response — would multiply the query cost by
      the entity count, reproducing exactly the "16-18 queries/page,
      surfacing only as e2e timeouts" failure class this task brief
      itself flags. Injecting the Reader (loaded ONCE) + Calculator
      (pure, stateless) instead, and mirroring
      `projectAccountFromContext()`'s own already-public-method-only glue
      loop, keeps 100% of the GL-join and growth-rate arithmetic in the
      sibling classes — nothing is re-derived, only the orchestration
      shape is inlined for single-load reuse. Verified by
      `testQueryCountStaysFlatRegardlessOfAccountCount`: 10 total
      `findAll()` calls (5 `BudgetVsActualsReader` + 4
      `BudgetProjectionReader` + 1 `AnnualBudget` resolution), unchanged
      whether the fixture has 1 or 20 accounts.**
- [x] PHPUnit: `BudgetChartSeriesServiceTest` — composition-only assertions
      (right collaborators called with right arguments, response shape
      correct, `annualBudgetId` override threads through, `unprojectable`/
      `partial` tags pass through unchanged) plus the query-count
      regression against a call-counting mock of both collaborators,
      asserting no additional `findAll()` beyond `AnnualBudget` resolution
      (REQ-BCH-008's second scenario).
      **7 tests, 16 assertions — see Verification section below.**

## 3. Backend — controller + route (REQ-BCH-003)
- [x] Add `lib/Controller/BudgetChartsController.php`::`series()`,
      `#[NoAdminRequired]`, docblock matching
      `FinancialDashboardController`'s own RBAC/multitenancy-via-OR-reads
      reasoning. Params: `administrationId` (required),
      `from`/`to` (required, format-validated), `annualBudgetId`
      (optional).
      **Posture strengthened beyond `FinancialDashboardController`'s own
      ("has at least one membership somewhere"): since this endpoint
      already takes a caller-supplied `administrationId`, it uses
      `SpendAnalyticsController`'s own stricter, more-recently-hardened
      pattern instead —
      `AdministrationContextService::canAccess(administrationId)`, masked
      404 (never 403) on a denied administration, matching that
      controller's own gate-7-driven correction.**
- [x] Add the route entry to `appinfo/routes.php`:
      `['name' => 'budgetCharts#series', 'url' =>
      '/api/budget-charts/series', 'verb' => 'GET']`, grouped near the
      existing financial-dashboard/spend-analytics read-only entries with a
      matching comment block.

## 4. Frontend — shared component + composable (REQ-BCH-004, REQ-BCH-005, REQ-BCH-006, REQ-BCH-007, REQ-BCH-009)
- [x] Add `src/components/budget-charts/useBudgetChartData.js`: module-
      singleton fetch-once-per-`(administrationId, range, annualBudgetId)`
      cache, modelled on `useFinancialData.js` (`load()`/`reload()`,
      in-flight-promise guard).
- [x] Add `src/components/budget-charts/BudgetTrendChart.vue`: props
      `scope: 'ledgerGroup' | 'account'`, `id`, `name`, `administrationId`,
      `range`, `annualBudgetId` (optional). Renders via `CnChartWidget`
      (`v-show`, never `v-if`, per `design.md` §1a's cited
      `CashflowChartWidget` mounting hazard). Builds the Actual/Projected
      gap-series pair per `design.md` §5, the `unprojectable` marker+
      tooltip per §3/REQ-BCH-004, the Trend/Cumulative toggle per §4/
      REQ-BCH-005 (disabled for all-stock scope), and colors per §10's
      `var(--nc-token, #fallback)` convention (no bare hex).
      **Also accepts an `objectData` prop (falls back for `id`/`name`/
      `administrationId` when not directly supplied) and defaults
      `range` to a trailing-12+3-months window when omitted — both
      needed for the sidebar-tab placement (task group 1's spike
      result), which hands the component the loaded `Account` object
      rather than pre-shaped scalar props. Series-shaping logic itself
      lives in the pure module `src/components/budget-charts/
      budgetChartSeries.js`, imported by the component — see task group 7.**
- [x] Add the accessible data-table fallback (§9/REQ-BCH-009): `<table>`
      with `<caption>`, `<th scope="col">` per period + `TOTAAL`,
      `<th scope="row">` per series, `unprojectable` cells rendering
      literal localised "Cannot project yet" text. Toggle via a persistent
      "View as table" `<button aria-pressed>`.
- [x] Add `data-testid` hooks: `budget-trend-chart`,
      `budget-trend-chart-toggle-cumulative`,
      `budget-trend-chart-view-table`, `budget-trend-chart-table-cell`
      (per series/period, for the Playwright spec's data-table
      assertions).
- [x] Register `BudgetTrendChart` in `src/registry.js`
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

  **NOT IMPLEMENTED — hard blocker, not an oversight: `src/views/
  BudgetGrid.vue` does not exist in this worktree/branch.
  `budget-grid-view` (the sibling this task group edits, per its own
  "reuses, does not redesign" framing) has not landed here — verified by
  `find`/`grep` across the whole tree, and consistent with the task
  brief's own statement that this worktree was deliberately based on
  `feat/budget-projection-engine` only. Task 0's own pre-flight
  instruction ("If not yet landed, STOP and coordinate rather than
  duplicating their work here") was followed literally for this one task
  group: inventing a `BudgetGrid.vue` here would mean authoring the row/
  column/expand model REQ-BCH-010 explicitly forbids this change from
  touching, under a guess at a design `budget-grid-view`'s own (unread,
  not-yet-existing) implementation might not match. `BudgetTrendChart`
  and `useBudgetChartData` are built ready for this placement — once
  `BudgetGrid.vue` lands, wiring it in is exactly the three sub-tasks
  above, no changes needed to this change's own new files. The
  `budget-charts::grid-row-trend-toggle-renders-chart` Playwright
  scenario (task group 7) is written against the exact contract these
  three sub-tasks specify, and will start passing the moment
  `BudgetGrid.vue` ships with it — it currently skips honestly rather
  than being omitted (see that file's own header note).**

## 6. Frontend — `ChartOfAccountsDetail` placement (REQ-BCH-002)
- [x] Per task group 1's spike outcome: either (a) add a new
      `sidebarProps.tabs` entry (`id: "trend"`, label "Trend", icon
      `ChartLine`, order `95`) rendering `BudgetTrendChart` with
      `scope: "account"`, `id: <accountNumber>`; or (b) if the spike
      failed, implement the `kind: "page"` fallback component recomposing
      the page's existing content plus the chart.
      **(a) taken — `id: "trend"`, order `95`, `widgets: [{ type:
      "BudgetTrendChart", props: { scope: "account" } }]`. `id`/`name`/
      `administrationId` are NOT set as static props (no per-object
      token substitution exists for `widgets[].props`) — the component
      derives them from the `objectData` prop `widgetBindings()` already
      supplies for every widget, built-in or custom.**
- [x] `node tests/check-manifest-budget.js` — PASS, report the exact byte
      delta for the manifest edit.
      **PASS. Delta: +14 lines / ~281 bytes (`git diff --stat
      src/manifest.json`). Total: manifest.json=452,947B +
      manifest.d/=650,045B = 1,102,992B, budget=1,126,300B (97.9%
      utilised, 23,308B headroom remaining).**

## 7. e2e coverage (REQ-BCH-001 through REQ-BCH-009)
- [x] Add `tests/e2e/budget-charts.spec.ts` covering
      `budget-charts::grid-row-trend-toggle-renders-chart` (incl. the
      second-chart-closes-first and no-additional-network-request
      assertions, folded into this scenario per `specs/budget-charts/spec.md`),
      `budget-charts::account-detail-chart-renders`,
      `budget-charts::cumulative-toggle-changes-rendering`,
      `budget-charts::unprojectable-renders-as-text-not-zero` — modelled on
      `tests/e2e/budget-grid-view.spec.ts` (SPDX header, `becomesVisible`
      helper, data-defensive `test.skip()` when no qualifying seed data
      exists for the current administration).
      **`tests/e2e/budget-grid-view.spec.ts` does not exist either (same
      blocker as task group 5) — modelled instead on
      `tests/e2e/budget-line-commitments.spec.ts` and `tests/e2e/
      budget-core-schema.spec.ts`'s own `gotoRoute()`/`becomesVisible()`
      conventions. The grid scenario uses a new, soft
      `gotoRouteOrSkip()` (skips when the route does not resolve, rather
      than the hard-asserting `gotoRoute()` the other three scenarios
      use) specifically so this ONE scenario's missing dependency cannot
      fail the whole file. Written, NOT executed, per this batch's own
      instruction — TypeScript syntax verified via `esbuild` (compiles
      cleanly); no live Playwright run performed.**
- [x] Tag each Playwright test `@e2e budget-charts::<slug>` matching
      `specs/budget-charts/spec.md`'s scenario ids exactly (gate-19 /
      `hydra-gate-e2e-coverage`).
- [ ] Run an axe-core pass against both consuming pages with a chart open —
      record the result; fix any violation before shipping, per
      `design.md` §9's accessible-name/data-table/contrast requirements.
      **NOT RUN — no live deployment of this worktree's code exists to
      test against (this worktree is not bind-mounted into any running
      Nextcloud container, and deploying it there is out of this batch's
      scope/time budget). Flagged as an explicit follow-up before merge,
      not silently skipped: run `axe-core` against `ChartOfAccountsDetail`
      → Trend tab (and, once task group 5 lands, `BudgetGrid`'s inline
      chart) on a real dev instance running this branch.**

## 8. Validation
- [x] `node tests/check-manifest-budget.js` — PASS (task group 6).
- [x] `npm run check:nav-reachability` — PASS (no new route added; confirm
      no regression from the `ChartOfAccountsDetail` edit).
      **PASS: 0 new orphans (21 baselined, 0 stale warnings).**
- [x] Run PHPUnit for touched files: `BudgetChartSeriesServiceTest` — all
      green, including the query-count regression.
- [x] Run vitest for `BudgetTrendChart.vue`: series-shaping (Actual/
      Projected gap-series split, `unprojectable` marker rendering,
      account-type-driven Cumulative-toggle disabling) — all green.
      **32 new tests across `budgetChartSeries.spec.js` (17, the pure
      shaping module) and `budgetTrendChart.spec.js` (15, the component's
      own computed properties bound via the codebase's established
      fake-`this` pattern, `useBudgetChartData` mocked).**
- [ ] `npx playwright test tests/e2e/budget-charts.spec.ts` — PASS.
      **NOT RUN, per this batch's explicit instruction ("Write the
      Playwright spec, do NOT execute it").**
- [x] `openspec validate budget-charts --strict` — PASS.

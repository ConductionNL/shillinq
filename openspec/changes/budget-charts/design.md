# Design: budget-charts

## 0. Method

Verified directly against this checkout (2026-08-20), same discipline as
the three sibling changes this one builds on. `budget-core-schema`,
`budget-projection-engine`, and `budget-grid-view`'s `proposal.md`/
`design.md`/`tasks.md`/`specs/` were read in full before writing this
document; `budget-known-costs` (proposal+design+spec present at read time)
was skimmed for anything affecting the shape of `BudgetLine` reads (finding:
nothing — it only changes *how* `BudgetLine.source` values other than
`manual` get written, never the monthly-amount read contract this change
consumes) and `budget-scenarios` (only a placeholder `.openspec.yaml`
present at read time — genuinely absent, per the task brief's own
instruction, treated as a non-goal below, not blocked on). Nothing in this
document redesigns a sibling's schema, reader, or calculator; every "ADD"
below is new surface, not a resurfacing of an already-specced one under a
different name.

## 1. Placement

### 1a. `BudgetGrid` (verzamelpost — and grootboek leaf rows)

`BudgetGrid`'s own row model (`budget-grid-view design.md` §1b) already
renders two row shapes: `LedgerGroup` rows (verzamelposten, expandable to
child `LedgerGroup`s or resolved member `Account`s) and, at the leaves,
resolved `Account` rows. The user's own requirement ("each grootboek **or**
boek") covers both — this change's chart surface therefore attaches to
**both** row kinds identically, not only to `LedgerGroup` rows.

**Interaction**: each row gets a second, independent toggle — a "view
trend" icon-button (`ChartLine` MDI icon) — distinct from the row's
existing children/account expand toggle (`budget-grid-view design.md` §6).
Clicking it inline-expands `BudgetTrendChart` in a full-width strip
beneath that row, pushing subsequent rows down exactly as a child-row
expansion already does — this reuses the grid's existing vertical-expand
mechanic rather than introducing a modal or a separate page, so a user can
compare the chart against the row's own already-visible column figures
without losing table context.

**At most one chart open at a time, grid-wide.** `BudgetGrid.vue` holds a
single `openChartRowId` ref; opening a second row's chart closes the first.
This is a deliberate constraint, not an incidental one: it bounds both the
number of live ApexCharts instances (avoiding the exact "unmounted
mid-flight" hazard `CashflowChartWidget.vue`'s own header comment records
for `vue3-apexcharts@1.8.0` — the fix there was `v-show`, not `v-if`,
because `v-if` risks detaching the chart host while `init()`'s awaited
`nextTick` is still pending; `BudgetTrendChart` follows the identical rule)
and, per §8, the number of concurrently-relevant projection-payload slices a
user is actively looking at.

**Toggle affordance mirrors ADR-059** exactly (`budget-grid-view design.md`
§6): `tabindex="0"`, `role="button"`, `:aria-expanded`, `@click`,
`@keyup.enter`, `@keyup.space`.

### 1b. `ChartOfAccountsDetail` (grootboek) — spike required, fallback chain stated

**Verified, not assumed**: `ChartOfAccountsDetail` (`src/manifest.json:4642`)
is one of 94 of 96 shillinq detail pages still on the **old**
`fields`/`relatedLists`/`aggregation` dialect — it has no `config.widgets`/
`config.layout` at all. Its one sidebar tab (`sidebarProps.tabs`, `id:
"audit"`) renders `componentName: "openregister-audit-trail"` — an
OpenRegister-library-provided built-in, not an app-registered component.
Two mechanisms this change could use to attach a chart are both **unproven
on any `type: "detail"` page in this codebase**:

1. **In-grid widget via `slots` + `type: "custom"`** — proven only on
   `type: "dashboard"` pages (`CashflowChartWidget`, `Dashboard` page's
   `slots: {"widget-cashflow-chart": "CashflowChartWidget"}`). Whether
   `CnDetailPage` (the `type: "detail"` renderer) resolves the same `slots`
   map the same way `CnDashboardPage` does is not exercised anywhere in
   this manifest — `node_modules` is not installed in this checkout to
   verify against the package source directly.
2. **Sidebar tab via a `kind: "sidebarTab"` registry entry** —
   `src/registry.js`'s own header comment lists `"sidebarTab"` among its
   five supported kinds, but **zero entries of that kind exist anywhere in
   the file**, verified by grep. The only precedent for an app-local
   component in a sidebar tab (`componentName: "reconciliation-closure-summary"`,
   found in `src/manifest.json:16913`) has **no matching registry entry
   either** — it is either resolved by some mechanism this change's author
   could not verify in this checkout, or it is itself a pre-existing latent
   defect. Either way, it is not safe to treat as a working precedent
   without checking it first.

**Decision — sidebar tab, via a first-task spike, with a two-tier
fallback**, stated explicitly rather than gambled on:

1. **First task, before any chart code is written**: prototype a
   `kind: "sidebarTab"` registry entry for a trivial placeholder component
   and confirm it renders inside `ChartOfAccountsDetail`'s existing
   `sidebarProps.tabs` array (same array shape as the existing `"audit"`
   entry — `{id, label, icon, order, widgets: [{type, componentName,
   props}]}`). If this resolves correctly, ship the chart there: a new tab
   (`id: "trend"`, label "Trend", icon `ChartLine`, `order` after `"audit"`
   at e.g. `95`) hosting `BudgetTrendChart` scoped to this page's own
   `Account`.
2. **Fallback if the spike fails**: register `ChartOfAccountsDetail` itself
   as a `kind: "page"` custom Vue component — this app's own **dominant**
   precedent for "a detail page needs content the built-in dialect cannot
   express" (10+ existing examples: `PeriodCloseDetail`,
   `SupplierInvoiceDetail`, `BudgetBBVMappingDetail`,
   `ThreeWayMatchExceptionPanel`, `VendorPerformanceDetail`, …). The
   replacement component recomposes the page's own existing `fields`/
   `relatedLists`/`aggregation` config (unchanged content, just rendered by
   hand instead of by the generic `type: "detail"` renderer) plus the chart
   — heavier than option 1, but proven.
3. **Not chosen, and named as an explicit non-decision**: migrating
   `ChartOfAccountsDetail`'s body to ADR-062's `widgets`/`layout` dialect so
   the chart becomes a true in-grid citizen (the placement ADR-062 itself
   governs) is **not** this change's own scope — it is a small page (10
   fields, 1 related list, 1 aggregation) and a bounded migration, but
   redesigning an already-shipped page's dialect is a different kind of
   change than "add a chart," and doing it as a drive-by here would make
   this change's diff much harder to review for the one thing it actually
   claims to add. **If a future change does migrate this page**, the
   ADR-062-correct placement for this same chart is a full-width body row
   below the existing GL-postings related list: `gridX: 0, gridY: 14,
   gridWidth: 12, gridHeight: 9` (matching `CustomerDetail`'s own
   `gridWidth: 12` convention for its full-width `object-list` row, sized
   taller — `gridHeight: 9` vs. that row's `5` — to leave room for the
   toggle control, legend, and both series-set renderings; this number is
   an estimate to verify against nc-vue's actual px-per-grid-row constant
   at that time, not a measured value, exactly as `budget-core-schema`
   §11.3 and `budget-grid-view`'s own byte estimates are stated as
   estimates, not measurements).

Either outcome (tab or `kind: "page"` recomposition) renders the identical
`BudgetTrendChart` component with `scope: "account"` — the placement
mechanism changes, the chart contract does not.

## 2. The three series and their exact sources

| Series | Source | Reused from |
|---|---|---|
| **Actual** | `GLTransaction`+`GLLine`+`Account`, bucketed by `(accountNumber, monthKey)` from `postingDate` — never `TrialBalanceLine` | `budget-core-schema`'s `BudgetVsActualsReader` (grootboek scope: read directly; verzamelpost scope: `BudgetVsActualsReader`'s own §3d parent-rollup rule, own `BudgetLine`-wins-over-children-sum) |
| **Projected** | The typed `{kind: "actual"\|"projected"\|"unprojectable", …}` result per REQ-BPE-001–010, per month, per account — summed per `LedgerGroup` per REQ-BPE-007's "sum of member projections, never an independent group-level fit," tagged `partial` when any member is `unprojectable` | `budget-projection-engine`'s `BudgetProjectionService` (reader + calculator), called through unchanged |
| **Budgeted (begroot)** | `BudgetLine.month01Amount..month12Amount` for the `AnnualBudget` in force for each column's own fiscal year — the `isDefault: true` `AnnualBudget` by default, or the `AnnualBudget` named by an explicit `annualBudgetId` override when supplied (§2a) | `budget-core-schema`'s `AnnualBudget`/`BudgetLine` schema, read directly — no new arithmetic |

### 2a. Scenario-awareness — forward-compatible, not scenario-aware today

`budget-scenarios` (multiple non-default `AnnualBudget`s per fiscal year,
what-if deltas) has no authored `proposal.md`/`design.md` in this checkout —
genuinely absent, per the task brief's own instruction to treat it as a
named non-goal rather than block. This change cannot implement "reflects
the selected scenario" because there is no selector to reflect yet. What it
does instead: `BudgetTrendChart` accepts an optional `annualBudgetId` prop
(and `useBudgetChartData`/`BudgetChartSeriesService`/the
`/api/budget-charts/series` endpoint all thread it through unchanged) —
absent, it resolves `budget-core-schema`'s own `isDefault: true`
`AnnualBudget` for each column's fiscal year (§2.2's one-default invariant,
already enforced by `AnnualBudgetDefaultGuard`), matching every other
sibling's own default-resolution behaviour (`budget-grid-view design.md`
§2b). Present, it reads that specific `AnnualBudget`'s own `BudgetLine`s
instead. This is the entire scenario surface this change ships: a plumbed-
through override parameter, not a scenario switcher UI — `budget-scenarios`,
once authored, wires its own selector's chosen `AnnualBudget` id into this
same prop rather than this change guessing at a UI that does not exist yet.

## 3. `unprojectable` MUST render as an explicit state, never a zero line

Per `budget-projection-engine`'s own REQ-BPE-004/§3: `unprojectable` is a
distinct typed tag (`{kind: "unprojectable", reason: "insufficient-data" |
"no-history", validSteps}`), not `amount: 0` and not `amount: null` treated
as zero. Rendering it as a flat zero line would read as "the engine
forecast a decline to nothing" — a fabricated claim the engine explicitly
refuses to make (REQ-BPE-004's own scenario: *"no `amount` field is
present"*). `BudgetTrendChart` renders it as:

- **Visually**: no plotted point for that month on the Projected series (a
  genuine gap, not a zero — ApexCharts renders a `null` array element as a
  break in the line, which is the correct visual for "we have nothing to
  show here," not "we are showing zero"), plus a small marker glyph (a
  muted dash `—` icon, `var(--color-text-maxcontrast)`) at that x-position
  so the gap reads as *deliberate*, not as missing/broken data.
- **On hover/focus**: the tooltip for that point reads the localised
  string *"Cannot project yet"* (`t('shillinq', 'Cannot project yet')`),
  never a blank tooltip and never `"0"`.
- **In the accessible data-table fallback** (§9): the cell's text content
  is literally "Cannot project yet" (or the `insufficient-data`/
  `no-history` reason, localised), not an empty cell and not `"€0"` — the
  same information via a second, always-available channel, per ADR-062
  rule 6's "never the uuid, never a bare '…'" convention applied here to
  "never a bare blank, never a fabricated zero."
- **`partial`-tagged group results** (REQ-BPE-007, a verzamelpost where
  only some resolved members were unprojectable that month) render the
  partial sum normally but carry a small "partial" badge/tooltip
  annotation on that point — the number shown is real (the sum of what
  *could* be computed), the tag exists so a reader does not mistake it for
  a complete figure.

## 4. Trend vs. cumulative toggle

### 4a. The house precedent, applied — not the mechanism

The Financial-overview dashboard's `margin-chart`/`billable-hours-chart`
widgets already ship this exact interaction as a declarative `content.views`
array (`{key: "value", label: "€", series: […], valueFormat: "currency"}` /
`{key: "pct", label: "%", …}`) — a small labelled segmented control above
the chart, switching which already-fetched named series render and how
they format, with no additional fetch. `BudgetTrendChart`'s own
Trend/Cumulative control **matches this exact visual/UX contract** (two
labelled buttons, `aria-pressed` reflecting the active one, positioned
identically above the chart body) — but because `BudgetTrendChart` is a
registered custom widget (§6), not a declarative `type: "chart"` widget, it
does not consume `content.views` from the manifest; the two series-sets are
switched by local component state (`mode: ref('trend' | 'cumulative')`)
computed from the same already-fetched payload (§8), never a second fetch.
This is stated explicitly so a future reader does not go looking for a
`views[]` block in this change's manifest edits and conclude one was
forgotten — the config mechanism doesn't apply here, only the interaction
pattern was reused.

### 4b. Account-type-dependent cumulative — the toggle is disabled, not duplicated

Per `budget-projection-engine` REQ-BPE-008: for flow accounts
(`revenue`/`expenses`) cumulative is a running fiscal-year-to-date sum,
continuous across the actual/projected seam; for stock accounts
(`assets`/`liabilities`/`equity`), cumulative **equals** trend exactly (a
closing balance is already a running position — re-summing it would
double-count). `ChartOfAccountsDetail` can render a chart for **any**
account type (unlike `BudgetGrid`, whose rows are, in practice, almost
entirely P&L/flow-typed since `budget-core-schema`'s amended default seed
ships no balance-sheet `LedgerGroup`s), so this branch is a real, not
theoretical, case here.

**Decision**: when the chart's own `accountType` (or, for a `LedgerGroup`,
every resolved member's `accountType` — mixed-type groups are rare per
`budget-grid-view design.md` §2d but not schema-forbidden) is entirely
stock-typed, the Cumulative toggle button renders `disabled`, with a
`title`/tooltip explaining why ("Cumulative equals trend for balance-sheet
accounts"), rather than rendering an active toggle that produces two
identical charts. A mixed-type `LedgerGroup` (some stock, some flow
members) is the one case where cumulative is genuinely different from
trend for **part** of the sum — the toggle stays enabled in that case, and
the cumulative series is computed member-by-member using each member's own
correct rule before summing (mirroring `budget-grid-view design.md` §2d's
"never a single row-wide sign/rule applied to a mixed sum" principle,
applied here to the cumulative rule instead of the deviation sign).

## 5. The actual→projected seam — visually distinct, never doubled

Reusing `CashflowChartWidget`'s own `padRealized`/`padForecast` shape
exactly: the chart's underlying data is **two** named series per metric —
`"Actual"` (real value through the account's own `lastActualMonth`, `null`
after) and `"Projected"` (`null` through `lastActualMonth`, computed value
after, per REQ-BPE-006's per-account seam — `null`, not `0`, for any
in-window month **before** the account's earliest data too, i.e. an
`unprojectable`-tagged month per §3 also renders as a gap in this series,
distinguished from the "not yet reached" gap only by the marker glyph +
tooltip §3 describes). Because REQ-BPE-006 guarantees "no month is ever
both," these two series' non-null ranges never overlap — there is never a
month where both an actual point and a projected point are plotted for the
same account, satisfying "never two series covering the same period twice"
by construction, not by a rendering-layer filter bolted on afterward.

**Visual distinction**: the Projected series renders with `strokeDashArray:
6` (dashed) and reduced opacity (`0.55`, matching `CashflowChartWidget`'s
own dimming convention) relative to the Actual series' solid, full-opacity
line — the same "dimmed projection columns appended to the realized line"
visual language the cashflow chart already established, applied to a line
series instead of columns since trend/cumulative here render as lines (the
Actual/Projected/Begroot triad needs three simultaneously-legible series;
columns for three overlapping series read far worse than for cashflow's
two). The Begroot (budgeted) series renders as a third line, solid,
undashed, in a visually distinct color from both Actual and Projected
(§10) — it is not part of the actual→projected seam at all (a budget
figure exists for future months regardless of whether they are actual or
projected, per `budget-grid-view design.md` §2b's "a future month's
planned budget contributes even though it hasn't happened yet").

## 6. Declarative vs. custom — one decision, both placements

**Decision: custom (`kind: "widget"`), for both the `BudgetGrid` inline
chart and the `ChartOfAccountsDetail` chart — the same shared
`BudgetTrendChart.vue` component, not two.** Per the task's own stated
ground truth, declarative `type: "chart"` widgets support only flat
`series[].path` with a uniform `chartKind: bar|line` — dashed/dimmed
per-series styling, an actual→projected seam, and a budget series that is
not a flat reference band all require a custom component today. Three
concrete reasons, not just the stated constraint:

1. **Dashed/dimmed styling** (§5) needs a per-series `strokeDashArray`/
   opacity the declarative dialect's `chartKind`/`views` vocabulary has no
   field for.
2. **Two genuinely separate data sources**, exactly `CashflowChartWidget`'s
   own justification for staying custom: actual+budgeted come from
   `BudgetVsActualsReader`/`BudgetLine` (already-fetched grid state, or a
   thin `BudgetChartSeriesService` composition for the detail page), while
   projected comes from a structurally different computation
   (`BudgetProjectionService`, a growth-rate extrapolation, not an
   OpenRegister read) — merging two sources into one chart is textbook
   `CashflowChartWidget` territory, not a single `endpointSource`.
3. **The `unprojectable` marker+tooltip** (§3) needs point-level rendering
   logic (a distinct glyph, a distinct tooltip string per point) no
   declarative `series[].path` array can express — an array position is
   either a number or absent; it cannot carry "and here's why it's absent."

`BudgetTrendChart` is registered once (`kind: "widget"` in `src/registry.js`,
mirroring `CashflowChartWidget`'s own entry exactly) and consumed from both
placements (§1) with different `scope`/`id` props — reuse, not
duplication.

## 7. Backend — `BudgetChartSeriesService`, a composition layer

Following every sibling's own reader/calculator (or, here, service)
composition convention:

- **`BudgetChartSeriesService`** (`lib/Service/BudgetChartSeriesService.php`)
  — a thin orchestrator with two collaborators injected:
  `BudgetVsActualsReader`/`Calculator` (`budget-core-schema`) and
  `BudgetProjectionService` (`budget-projection-engine`). It does **not**
  reimplement the GL join, the growth-rate arithmetic, or the `LedgerGroup`
  membership resolution — all three already exist, specced and tested, in
  the classes it composes. Its own job is exactly three things:
  1. Resolve the requested `AnnualBudget` (default or `annualBudgetId`
     override, §2a) per fiscal year in the requested range.
  2. Call `BudgetVsActualsReader`/`Calculator` for actual+budgeted, and
     `BudgetProjectionService` for the projected series (per account or per
     `LedgerGroup`, per the request scope).
  3. Shape the three results into the trend/cumulative response envelope
     the frontend consumes (§8's exact JSON shape) — a formatting step, not
     an arithmetic one; the cumulative math itself (§4b's account-type
     branching) is `BudgetProjectionCalculator`'s own already-tested
     `testFlowCumulativeIsRunningSumAcrossSeam`/`testStockCumulativeEqualsTrend`
     logic, called through, not re-derived.
- **`BudgetChartsController::series()`**
  (`lib/Controller/BudgetChartsController.php`,
  `GET /apps/shillinq/api/budget-charts/series`) — `#[NoAdminRequired]`,
  same posture as `FinancialDashboardController` (RBAC/multitenancy
  enforced by the OpenRegister reads inside the composed reader/service
  classes, no client-supplied object identifier beyond the
  already-scoped `administrationId`/`annualBudgetId` params, format-
  validated before use — no new IDOR surface, mirroring that controller's
  own docblock reasoning exactly).

Both are PHPUnit-tested for composition/orchestration only (does it call
its collaborators with the right arguments, does it shape the response
correctly) — the arithmetic itself is covered by `BudgetProjectionCalculatorTest`
and `BudgetVsActualsReaderTest`/`CalculatorTest`, already shipped by the
siblings this change composes; duplicating those assertions here would be
testing someone else's already-tested code a second time.

## 8. Query budget and fetch-sharing design

### 8a. The response is whole-administration, not per-chart-scoped

`BudgetProjectionReader`'s own query cost (REQ-BPE-009) is a **flat ≤4
`findAll()` calls, independent of how many accounts or `LedgerGroup`s are
requested** — internally it always fetches every `Account` and all
`GLTransaction`/`GLLine` activity for the administration to build its
in-memory index, whether the caller asked about one account or fifty.
Given that, scoping the endpoint response to "just this one row's chart"
would cost the *same* internal work as returning the whole administration's
dataset, for strictly less value. **Decision: `GET
/api/budget-charts/series` takes no `scope`/`id` filter — it returns every
account's and every root `LedgerGroup`'s trend+cumulative triad
(actual/projected/budgeted) for the requested `administrationId` +
period range + optional `annualBudgetId`, in one response.** Both
placements slice into the same cached payload client-side by account
number / `LedgerGroup` id.

**Response-size scoping, stated explicitly, not left unbounded**: "every
account" means every account with at least one posted `GLTransaction` line
in the requested range, or that is a resolved member of a seeded
`LedgerGroup` — dormant/unused accounts with no GL activity and no
`LedgerGroup` membership are omitted from the response (they would render
`unprojectable`/zero for every series anyway, and a shillinq chart of
accounts can carry hundreds of RGS-derived rows most administrations never
post to).

### 8b. Fetch-once-per-page-visit, lazy on first chart interaction

`useBudgetChartData(administrationId, range, annualBudgetId)`
(`src/components/budget-charts/useBudgetChartData.js`) is a module-singleton
composable, modelled directly on `useFinancialData.js`'s own shape
(module-scoped refs, an in-flight-promise guard, `load()`/`reload()`): the
**first** `BudgetTrendChart` a user opens on a given page triggers the
fetch; every subsequent chart-open on the **same page visit**, for the same
`(administrationId, range, annualBudgetId)` key, reuses the already-resolved
cache — zero additional network requests. Reloading the range (or the
`annualBudgetId` override, once `budget-scenarios` wires one) invalidates
the cache and refetches once.

### 8c. Total cost, stated per surface, honestly

- **`BudgetGrid`**: actual + budgeted for every row are **already** in the
  page's own state (`BudgetGridReader`'s own ≤7-call batch,
  `budget-grid-view design.md` §1c) — this change adds **zero** additional
  queries for those two series. Opening the **first** row's chart adds one
  `/api/budget-charts/series` call (≤4 `findAll()` internally, per §8a);
  every subsequent chart-open on the same page adds zero more. **Total
  added cost for a `BudgetGrid` page visit: one additional request, paid
  once, regardless of how many rows' charts a user opens.**
- **`ChartOfAccountsDetail`**: this page does not currently fetch
  `BudgetLine`/`LedgerGroup`/projection data at all (its existing 2-3
  calls — object fetch, `GLLine` related list, `accountBalance`
  aggregation — cover none of it). Opening the chart (tab or in-grid,
  §1b) for the **first** time on a page visit costs the single
  `/api/budget-charts/series` call above (still ≤4 internal `findAll()`s,
  §8a — the reader's cost does not grow because this page only cares about
  one account; it was always computing every account's data internally).
  **Total added cost for a `ChartOfAccountsDetail` page visit: one
  additional request, paid once, lazily, only if the chart is actually
  opened.**
- **Not claimed**: this bound does **not** hold *across* page visits —
  `BudgetGrid` and `ChartOfAccountsDetail` are different routes/sessions,
  each independently pays its own one-time cost when visited. Stated
  explicitly so a reviewer does not read "fetch-once" as "fetched once,
  globally, for the whole app session," which it is not.

## 9. Accessibility (WCAG 2.2 AA)

- **Accessible name**: `BudgetTrendChart`'s outer container is
  `role="group"` with `:aria-label` set to a localised, per-entity string
  (`t('shillinq', 'Trend chart for {name}: actual, projected and budgeted
  amounts', { name })`) — announced when a screen-reader user's focus
  enters the chart region, not left to the SVG internals to convey.
- **Data-table fallback — the primary accessible path, not a courtesy
  add-on.** ApexCharts' own tooltip/legend interaction is pointer/hover-
  oriented and not fully keyboard-navigable in this version; a real
  `<table>` (real `<caption>`, `<th scope="col">` per month + `TOTAAL`,
  `<th scope="row">` per series) rendering the exact same Actual/Projected/
  Begroot × month data — including `unprojectable` cells as literal
  "Cannot project yet" text, per §3 — is the standard robust technique for
  WCAG 1.1.1 on complex multi-series data, and is what actually makes this
  chart's information keyboard- and screen-reader-reachable, not merely
  present in the DOM. Toggled via a persistent "View as table" button
  (`aria-pressed`), matching `BudgetLineCommitments.vue`'s already-
  established "explicit text, not colour alone" convention
  (`budget-grid-view design.md` §2d cites it directly) extended here from
  "text-labelled state" to "structured data, not chart-only."
- **The Trend/Cumulative toggle** (§4) and the "view trend" row action
  (§1a) are real `<button>` elements (`aria-pressed`/`aria-expanded`
  respectively) — native semantics per ADR-059 Decision 3, not
  hand-rolled `div`+click-handler controls.
- **Seam and unprojectable states are never colour-only**: the dashed
  stroke (§5) is a shape difference, not only an opacity/colour one
  (axe-core does not catch "meaning conveyed only visually inside an SVG,"
  so this is a design requirement stated here, not something the automated
  gate alone would enforce); the data-table fallback conveys the identical
  Actual/Projected boundary as two differently-labelled table rows,
  reachable without colour vision or chart-parsing at all.
- **Contrast**: the dimmed/dashed Projected line's reduced opacity (§5,
  `0.55`) must still clear WCAG 1.4.11's 3:1 non-text contrast against the
  chart's background for graphical objects conveying information — this is
  stated as a verification task (`tasks.md`), not assumed from
  `CashflowChartWidget`'s own precedent, which was not itself audited for
  this.

## 10. Color tokens — ADR-053, built correctly here even though the precedent isn't

Both existing chart precedents (`CashflowChartWidget.vue`,
`BBVTrendChart.vue`) hardcode bare hex (`'#46ba61'`, `'#e04224'`,
`'#0082c9'`) — pre-existing debt against ADR-053 §1/§3, **not** this
change's own files, and out of this change's scope to fix (per this repo's
own "scope debt scopes to the repos/lines you are changing" convention —
this change does not edit either file). ADR-053's own `useChartColors()`
composable (§3, the intended long-term fix) is **not yet shipped** —
verified: it appears nowhere in this checkout's source, and ADR-053's own
Status is "Proposed," not accepted/shipped. `BudgetTrendChart` therefore
cannot depend on it, but **does not repeat the bare-hex pattern either** —
it sources every series color as `var(--nc-token, #fallback)` (ADR-053 §4's
explicitly allowlisted exception: "`var(--nc-token, #fallback)` fallback
positions" are legal today, unlike a bare literal):

```
Actual:    var(--color-success, #46ba61)
Projected: var(--color-success, #46ba61) at 0.55 opacity + dashed stroke
Budgeted:  var(--color-primary-element, #0082c9)
```

When `useChartColors()` ships, swapping these three lines for the
composable's output is a same-file, no-redesign follow-up — noted, not
built here (§13).

## 11. e2e coverage

New Playwright spec `tests/e2e/budget-charts.spec.ts` (SPDX header,
`becomesVisible` helper, `test.describe` `(REQ-BCH-…)` suffix,
data-defensive `test.skip()` when no `LedgerGroup`/`Account`/`BudgetLine`/
posted `GLTransaction`+`GLLine` seed data exists for the current
administration), modelled on `tests/e2e/budget-grid-view.spec.ts`'s own
conventions:

1. `budget-charts::grid-row-trend-toggle-renders-chart` — clicking a
   `BudgetGrid` row's "view trend" action reveals a chart with three
   visibly distinct series (asserted via the data-table fallback's row
   labels — "Actual"/"Projected"/"Begroot" — not by parsing SVG paths).
2. `budget-charts::account-detail-chart-renders` — `ChartOfAccountsDetail`'s
   chart surface (tab or in-grid, per §1b's spike outcome) renders the same
   three series for the viewed account.
3. `budget-charts::cumulative-toggle-changes-rendering` — clicking the
   Cumulative button changes the rendered data-table values (a running sum
   differs from the per-period trend for at least one seeded flow account)
   — asserting the toggle actually swaps data, not just its own pressed
   state.
4. `budget-charts::unprojectable-renders-as-text-not-zero` — an account/
   `LedgerGroup` with seeded history below `MIN_VALID_STEPS` shows "Cannot
   project yet" in the data-table fallback for the relevant month, not
   "€0" and not a blank cell.

Tag each test `@e2e budget-charts::<slug>` matching
`specs/budget-charts/spec.md`'s scenario ids (gate-19 /
`hydra-gate-e2e-coverage`).

Backend-only, `@e2e exclude`:

- `BudgetChartSeriesService`'s own orchestration (PHPUnit, §7).
- Every degenerate-case/growth-rate/seam/cumulative-rule arithmetic
  assertion — already covered by `BudgetProjectionCalculatorTest`
  (`budget-projection-engine`) and `BudgetVsActualsReaderTest`/
  `CalculatorTest` (`budget-core-schema`); this change's own vitest
  coverage for `BudgetTrendChart.vue` tests series-*shaping* (does the
  component correctly split a typed result into the Actual/Projected
  gap-series pair, does the account-type check correctly disable the
  Cumulative toggle) with fixed, already-computed inputs — not the
  arithmetic that produced those inputs, which is not this component's
  job to re-verify.
- The `/api/budget-charts/series` query-count bound (§8a) — a PHPUnit
  call-counting regression test against a mocked
  `ObjectServiceInterface`/collaborator, mirroring every sibling's own
  `testQueryCountIndependentOf…` precedent, not a browser assertion.

## 12. Non-goals (each names its owning change)

- **The grid itself** (row tree, column model, expand/collapse,
  `TOTAAL` cumulative column, computed subtotal rows) — `budget-grid-view`.
  This change only adds a per-row chart toggle to an already-shipped page.
- **Projection arithmetic** (growth-rate derivation, degenerate-case
  rules, the seam rule itself) — `budget-projection-engine`. This change
  only renders the already-computed typed result.
- **Known-cost derivation** (`BudgetLine.source = "contract"|"recurring"`)
  — `budget-known-costs`. This change reads `BudgetLine`'s monthly amounts
  source-agnostically; it does not care how a given `BudgetLine` was
  populated.
- **Scenario/modifier semantics and any scenario-selector UI** —
  `budget-scenarios`, not yet authored beyond a placeholder as of this
  writing. This change ships only the forward-compatible `annualBudgetId`
  override (§2a).
- **Migrating `ChartOfAccountsDetail` to ADR-062's `widgets`/`layout`
  dialect** — named explicitly in §1b as *not* this change's own scope,
  even though it would be the more architecturally correct placement for
  the chart; a future change's own decision, not forced here.
- **Patching `AggregationAnnotationValidator`** — foundation/openregister
  scope, already out of bounds per `budget-core-schema design.md` §6a; this
  change's own reads never depend on a declarative cross-schema
  aggregation (it composes the siblings' own PHP-primary readers).

## 13. Open questions — needing a product call

1. **`ChartOfAccountsDetail` placement — tab vs. eventual in-grid widget**
   (§1b). The sidebar-tab decision here is a pragmatic, low-risk choice
   given the unverified state of both mechanisms on a `type: "detail"`
   page — but a sidebar tab is narrower than a full-width body row, which
   may render three series + a toggle + a data-table fallback more
   cramped than the user would want for what they explicitly called out as
   a flagship visual. Worth a product/design look once the spike (§1b task
   1) reports back which mechanism actually works.
2. **`useChartColors()` adoption timing** (§10) — this change ships
   correct-but-manual `var(--nc-token, #fallback)` colors; whether to
   revisit once ADR-053's composable ships, or leave as-is until a broader
   fleet sweep touches every chart component at once (including the two
   pre-existing bare-hex offenders this change does not fix), is an
   ADR-053-rollout sequencing call, not decided here.
3. **`BudgetGrid`'s "one chart open at a time" constraint** (§1a) — chosen
   for query/DOM-cost bounding, but a user comparing two verzamelposten's
   trends side-by-side cannot currently do so without closing one to open
   the other. Whether a future revision should allow N concurrently open
   (now that §8 shows the *query* cost is already flat regardless of how
   many charts are open, the DOM/rendering cost is the only remaining
   constraint) is a UX call worth revisiting once real usage is observed,
   not pre-optimised here.
4. **Response-size scoping rule** (§8a — "at least one posted
   `GLTransaction` line, or a `LedgerGroup` member") is this change's own
   invented cutoff, not something any sibling specified. If a real
   administration's chart of accounts turns out to have a materially
   different shape than assumed here, this rule may need revisiting —
   flagged, not assumed correct for every deployment.

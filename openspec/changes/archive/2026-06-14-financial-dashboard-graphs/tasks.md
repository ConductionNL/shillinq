# Tasks: financial-dashboard-graphs

## 1. Data layer

- [x] 1.1 `src/components/dashboard/financial/financialSeries.js` —
  pure computation module: month bucketing, account classification
  (revenue / expenses / liquid assets), monthly turnover + margin +
  cashflow series, billable-hours series, KPI aggregation, open
  AR/AP row mapping, CashflowWeek → monthly forecast roll-up.
- [x] 1.2 `src/components/dashboard/financial/useFinancialData.js` —
  fetch-once composable over the OpenRegister objects API (register
  slug `shillinq`; schemas Account, GLTransaction, GLLine,
  ARInvoice, APTransaction, UrenRegistratie, CashflowWeek) with a
  module-scoped promise cache shared by all widgets.

## 2. Widgets

- [x] 2.1 `FinanceKpisWidget.vue` — six-tile KPI strip.
- [x] 2.2 `TurnoverChartWidget.vue` — 12-month turnover bars.
- [x] 2.3 `MarginChartWidget.vue` — revenue/cost columns + margin
  line, €/% toggle.
- [x] 2.4 `CashflowChartWidget.vue` — realized in/out + net line,
  dimmed CashflowWeek forecast months appended.
- [x] 2.5 `BillableHoursChartWidget.vue` — stacked billable vs
  non-billable, total/% toggle.
- [x] 2.6 `OpenInvoicesTableWidget.vue` — shared debtors/creditors
  table (`props.kind`), overdue flagging, row links.

## 3. Wiring

- [x] 3.1 Register the six components as `kind: "widget"` entries in
  `src/registry.js` (ADR-036 justification in docblock).
- [x] 3.2 Replace the Dashboard page's eight `stats-block` widgets
  in `src/manifest.json` with the seven new widgets + layout +
  `slots` map; keep page id `Dashboard`, route `/`, type
  `dashboard`.
- [x] 3.3 Add new UI strings to `l10n/en.json` + `l10n/nl.json`
  (English keys per i18n policy).

## 4. Example data

- [x] 4.1 `scripts/seed-demo-financials.py` — idempotent REST
  seeder: chart of accounts, 12 months of balanced posted GL
  transactions, customers/vendors, AR/AP invoices (paid + open +
  overdue mix), hour registrations, 13-week CashflowWeek forecast.
- [~] 4.2 Run against the dev instance; verify every widget renders
  non-empty. (manual live-instance step; seed script committed, exercised by e2e fixtures)

## 5. Tests

- [x] 5.1 Vitest unit tests for `financialSeries.js` (bucketing,
  classification, margin %, open-invoice filtering, forecast
  roll-up, fetch-once behaviour).
- [x] 5.2 Playwright e2e spec `tests/e2e/financial-dashboard.spec.js`
  covering the spec scenarios (KPI strip, charts render, toggles
  switch view, overdue flag in tables).

## 6. Verify

- [~] 6.1 `npm run build` clean; dashboard live-verified at
  localhost:8080 with seeded data; screenshot attached to PR. (build verified; live screenshot is a manual step)
- [x] 6.2 Hydra gates green (spec coverage via `@spec` tags on all
  new modules/methods; gate-19 `@e2e` coverage on all scenarios).

# Design: reporting-financial-statements

Read at shillinq development `79f438f33` and nextcloud-vue development on
2026-09-27.

## Context

- **Generators.** `lib/Reporting/Generator/ProfitLossReportGenerator.php` classifies accounts (`classify()`, line 246) and derives movements per administration (`deriveMovements()`, line 309) with no date filter. `BalanceSheetReportGenerator::groupByType()` (line 232) buckets assets, liabilities and equity, skips P&L accounts, and uses the stored balance or all movements. Both are document generators reached from `GenerateReportDialog.vue` through `/api/reporting/generate`; the open change `reports-via-docudesk` moves their rendering to docudesk.
- **Reports page.** `src/manifest.json:2536` declares the `Reports` page of `type: reports` with cards naming routes (ADR-112, ADR-114).
- **Trial balance.** `TrialBalanceLines` (`src/manifest.json:5827`) has no row route.
- **Line stamps.** `reporting-segment-results` stamps each `GLLine` with `accountClass` and `countsInResult` when its transaction posts or is reversed, and declares `signedAmount`.
- **Route sentinels.** nextcloud-vue's `CnPageRenderer` resolves `@route.<param>` in page config (`CnPageRenderer.vue:1132`), and `CnIndexPage` interpolates `@route.<name>` in filters (`CnIndexPage.vue:1187`).

## Goals / Non-Goals

**Goals**
- P&L over a range with comparison columns.
- Balance sheet on any date that balances, with the running result in equity.
- Every figure clicks through to its lines.
- Documents show the same figures as the screen.

**Non-Goals**
- Cash flow, annual accounts, segment statements.

## Decisions

### D1. One calculator, two readers

`lib/Reporting/StatementCalculator.php`:
`balancesAsOf(administrationId, date)` and
`resultsBetween(administrationId, from, to)`, both over `GLLine` records
that count in results (`countsInResult`) and whose transaction
`postingDate` falls in range, grouped by account and summed on
`signedAmount`. The balance sheet adds one equity line "Resultaat lopend
boekjaar" equal to the result from the start of the fiscal year to the
date, unless that year is closed. Both generators and both endpoints call
it; the generators stop reading stored balances.

Alternative considered: aggregations only. Rejected: an as-of date and a
fiscal-year boundary per administration are parameters the aggregation
endpoint does not take.

### D2. Two pages, two cards

`FinancialStatementProfitLoss` (route `/reports/profit-and-loss`) and
`FinancialStatementBalanceSheet` (route `/reports/balance-sheet`), custom
pages reading `GET /api/statements/profit-and-loss?from&to&compare=` and
`GET /api/statements/balance-sheet?asOf=`. Two cards on `Reports` name
them. `compare` takes `previous-period` and `previous-year`; each
comparison column shows the amount and the difference.

### D3. The drilldown is a route

A page `LedgerLinesForAccount`, route
`/ledger/lines/:accountNumber/:from/:to`, is an index over `GLLine` with
`defaultFilters` `accountNumber: "@route.accountNumber"` and a date range
from `@route.from` and `@route.to`, each row linking to its
`GeneralLedgerDetail`. Every account figure on both statements links there
with its own range (for the balance sheet, fiscal year start to the as-of
date). The trial balance lines get the same row route.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Statement figures | Imperative, one calculator (ADR-031 exception: report generation) | Date and fiscal-year parameters per request. |
| Drilldown | Declarative: an index page with route-derived filters | Page configuration with the existing sentinel. |

## Seed Data

Adviesbureau Van Dijk, fiscal year 2026 open: revenue 8000 EUR 180,000 to
30 September, costs 4000 to 4999 EUR 132,000, so the running result is
EUR 48,000. The balance sheet on 2026-09-30 shows "Resultaat lopend
boekjaar" EUR 48,000 under equity and balances. The same period of 2025 had
revenue EUR 150,000 and costs EUR 120,000 for the comparison column.

## Risks / Trade-offs

- [Large administrations make the calculator slow] → it reads by account and date with bounded queries (ADR-058) and caches per request; the endpoint takes one administration and one range.

## Migration Plan

None.

## Open Questions

None.

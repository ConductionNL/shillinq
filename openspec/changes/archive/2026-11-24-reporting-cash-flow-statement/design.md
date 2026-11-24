# Design: reporting-cash-flow-statement

Read at shillinq development `83d19fc8d` on 2026-09-28.

## Context

- **Accounts.** `Account` in `lib/Settings/shillinq_register.json` has `accountType` (`assets`, `liabilities`, `equity`, `revenue`, `expenses`) and nothing that says whether a balance account is cash, working capital, a fixed asset or a loan.
- **Declared record.** `CashFlowStatement` in `lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json` (required `reportId`, `cashFlowDate`, `method`, `currency`, `status`) hangs off an `AnnualReport`. Main spec REQ-T9-005 in `openspec/specs/bookkeeping-titel-9-jaarrekening/spec.md` describes the indirect method; nothing implements it.
- **Statement calculator.** The open change `reporting-financial-statements` adds `lib/Reporting/StatementCalculator.php` with `balancesAsOf()` and `resultsBetween()` over posted lines, the ledger lines drilldown page `LedgerLinesForAccount` and the statement pages under `/reports/`. This change depends on it.
- **Reports page.** `src/manifest.json:2536`, `type: reports`, cards naming routes (ADR-112).
- **Forecast.** `lib/Service/CashflowExportService.php` and `CashflowPdfRenderer.php` export the 13-week forecast; untouched.

## Goals / Non-Goals

**Goals**
- A cash flow statement for any period that reconciles to the change in cash.
- The same figures on screen and in the annual accounts.

**Non-Goals**
- Direct method, consolidation, forecasting.

## Decisions

### D1. Classify balance accounts once

`Account.cashFlowCategory`, optional enum: `cash`, `working-capital`,
`fixed-assets`, `financing`, `equity`. Revenue and expense accounts need no
category. Depreciation is read from the fixed asset postings
(`DepreciationSchedule` runs in
`lib/Settings/register.d/bookkeeping-fixed-assets-depreciation.json`
credit the asset's `accumulatedDepreciationAccountNumber`, classified
`fixed-assets`); the calculator adds back the credit movements on those
accounts that came from depreciation runs.
The seed chart of accounts gets categories. A check page lists balance
accounts without a category.

Alternative considered: derive the category from the account number range.
Rejected: charts differ per administration and a wrong guess is invisible.

### D2. One calculator on top of the statement calculator

`lib/Reporting/CashFlowCalculator.php::between(administrationId, from, to)`:
- operating = `resultsBetween(from, to)` result, plus depreciation, minus the increase of `working-capital` balances (`balancesAsOf(to)` minus `balancesAsOf(from - 1 day)`);
- investing = minus the change of `fixed-assets` balances excluding depreciation;
- financing = the change of `financing` and `equity` balances excluding the period result;
- reconciliation = change of `cash` balances; any difference is shown as "Niet ingedeeld" with the accounts behind it.

### D3. Page, card, document

A page `FinancialStatementCashFlow` (route `/reports/cash-flow`) reads
`GET /api/statements/cash-flow?from&to`; a card on `Reports` names it.
Every figure links to `LedgerLinesForAccount` for its accounts and range.
`AnnualAccountsReportGenerator` gains a kasstroomoverzicht section from the
same calculator and writes the `CashFlowStatement` record with
`method: indirect`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Cash flow figures | Imperative, one calculator (ADR-031 exception: report generation) | Period parameters per request. |
| Account category | Declarative: a schema property | Plain data. |

## Seed Data

Adviesbureau Van Dijk 2026-01-01 to 2026-09-30: result EUR 48,000,
depreciation EUR 6,000, debtors up EUR 10,000, creditors up EUR 4,000,
a laptop fleet bought for EUR 12,000, a loan repayment of EUR 8,000. Cash
goes from EUR 20,000 to EUR 48,000, so operating EUR 48,000, investing
EUR -12,000, financing EUR -8,000, change in cash EUR 28,000.

## Risks / Trade-offs

- [Accounts reclassified during a period] → the category is read at calculation time for both dates; the statement notes the date it was produced.

## Migration Plan

`cashFlowCategory` is optional; existing accounts show in the check list until classified.

## Open Questions

None.

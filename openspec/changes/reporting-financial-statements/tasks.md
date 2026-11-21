# Tasks: reporting-financial-statements

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Calculator

- [x] 1.1 `lib/Reporting/StatementCalculator.php` with `balancesAsOf` and `resultsBetween`, including the running result line (REQ-RFS-001, REQ-RFS-002). Verify: PHPUnit with the seed, including a posting after the date and a closed year.
- [x] 1.2 Two read endpoints with route auth and administration checks (REQ-RFS-001, REQ-RFS-002). Verify: PHPUnit; route-auth and IDOR gates.

## 2. Pages

- [x] 2.1 Profit and loss page with comparison columns and a Reports card (REQ-RFS-001). Verify: vitest; `npm run check:manifest`. — `FinancialStatementProfitLoss` (`/reports/profit-and-loss`) in `src/manifest.d/reporting-financial-statements.json`: a dashboard with the header date range, reading `GET /api/statements/profit-and-loss` with `compare=previous-period,previous-year`, so both comparison columns and their differences are always shown beside the amount (a page filter cannot switch a table's column keys, so the comparison is not optional on screen); stat tiles for revenue, expenses, the result and last year's result; revenue and expense tables per account. A Profit and loss card on `Reports`. Strings in `l10n/en.json` and `nl.json`. Test: `tests/vitest/financialStatementPages.spec.js`; `npm run check:manifest` passes.
- [x] 2.2 Balance sheet page with the as-of date and a Reports card (REQ-RFS-002). Verify: vitest; `npm run check:manifest`. — `FinancialStatementBalanceSheet` (`/reports/balance-sheet`): reads `GET /api/statements/balance-sheet` with `asOf` set to the end date of the header range; tiles for total assets and liabilities plus equity, so the two can be seen to balance; tables for assets, liabilities and equity (the running result line comes from the calculator). A Balance sheet card on `Reports`. Same test file; `npm run check:manifest` passes.
- [x] 2.3 `LedgerLinesForAccount` page with route filters, links from both statements and a row route on `TrialBalanceLines` (REQ-RFS-003). Verify: nav reachability; Playwright step below. — `LedgerLinesForAccount` (`/ledger/lines/:id`) in `src/manifest.d/reporting-financial-statements.json`: a total tile and a lines table reading `GET /api/statements/lines?key=@route.id` (`FinancialStatementController::accountLines`, `StatementCalculator::accountLines`), each line opening its `GeneralLedgerDetail`. The row-route widgets of nextcloud-vue 2.181.0 pass one `id` parameter only, so the route carries one key instead of design D3's three parameters, the way `CashFlowLines` does: every statement row has a `linesKey` `account~from~to` (the statement's range; for the balance sheet the fiscal year start to the as-of date, as D3 says), and the five account tables route to the page with `rowIdField: linesKey`. `TrialBalanceLines` has `rowRoute: LedgerLinesForAccount`; its row id is the trial balance line's, which the endpoint resolves within the administration to that account's lines in that period. The lines read the way the figure does (debit positive for expense and asset accounts, credit positive for revenue, liabilities and equity), so they add up to it. Tests: `StatementAccountLinesTest` (the 4100 Huisvesting figure of EUR 36,000 opens its one line, revenue, a balance sheet figure, the running result has no key, a trial balance line, refusals), `FinancialStatementControllerTest::testTheLinesBehindAnAccountFigure` and `testAccountLinesRefusals`, `tests/vitest/financialStatementPages.spec.js`. `check:manifest` passes; `check:nav-reachability` lists no new orphan for this page (its seven failures are the same on development).

## 3. Documents

- [x] 3.1 Point `ProfitLossReportGenerator` and `BalanceSheetReportGenerator` at the calculator (REQ-RFS-004). Verify: PHPUnit comparing document totals with endpoint totals.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/reporting-financial-statements.spec.ts`: compare, as-of date, click a figure. Verify: passes locally.
- [x] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`. — every label of the three pages is in `l10n/en.json` and `nl.json` (the vitest checks each page label has a Dutch translation); `test:l10n` and `test:l10n-parity` pass.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

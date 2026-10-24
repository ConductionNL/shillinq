# Tasks: reporting-financial-statements

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Calculator

- [ ] 1.1 `lib/Reporting/StatementCalculator.php` with `balancesAsOf` and `resultsBetween`, including the running result line (REQ-RFS-001, REQ-RFS-002). Verify: PHPUnit with the seed, including a posting after the date and a closed year.
- [ ] 1.2 Two read endpoints with route auth and administration checks (REQ-RFS-001, REQ-RFS-002). Verify: PHPUnit; route-auth and IDOR gates.

## 2. Pages

- [ ] 2.1 Profit and loss page with comparison columns and a Reports card (REQ-RFS-001). Verify: vitest; `npm run check:manifest`.
- [ ] 2.2 Balance sheet page with the as-of date and a Reports card (REQ-RFS-002). Verify: vitest; `npm run check:manifest`.
- [ ] 2.3 `LedgerLinesForAccount` page with route filters, links from both statements and a row route on `TrialBalanceLines` (REQ-RFS-003). Verify: nav reachability; Playwright step below.

## 3. Documents

- [ ] 3.1 Point `ProfitLossReportGenerator` and `BalanceSheetReportGenerator` at the calculator (REQ-RFS-004). Verify: PHPUnit comparing document totals with endpoint totals.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/reporting-financial-statements.spec.ts`: compare, as-of date, click a figure. Verify: passes locally.
- [ ] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

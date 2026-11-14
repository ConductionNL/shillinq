# Tasks: reporting-cash-flow-statement

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Data

- [ ] 1.1 `Account.cashFlowCategory` in the register, categories on the seed chart, and a check page for balance accounts without one (REQ-RCF-001). Verify: register schema validation test with the real fragment; `npm run check:manifest`.

## 2. Calculator

- [ ] 2.1 `lib/Reporting/CashFlowCalculator.php` over `StatementCalculator`, with the "Niet ingedeeld" line (REQ-RCF-002). Verify: PHPUnit with the seed, including an unclassified account.
- [ ] 2.2 `GET /api/statements/cash-flow` with route auth and the administration check (REQ-RCF-002). Verify: PHPUnit; route-auth and IDOR gates.

## 3. Page and document

- [ ] 3.1 `FinancialStatementCashFlow` page with a Reports card and figure links (REQ-RCF-003). Verify: vitest; nav reachability.
- [ ] 3.2 The kasstroomoverzicht section in `AnnualAccountsReportGenerator` writing `CashFlowStatement` (REQ-RCF-004). Verify: PHPUnit comparing document totals with endpoint totals.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/reporting-cash-flow-statement.spec.ts`: open the statement, check the reconciliation, click a figure. Verify: passes locally.
- [ ] 4.2 Dutch and English strings. Verify: `npm run test:l10n`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

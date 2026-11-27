# Tasks: banking-connected-accounts

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Accounts

- [x] 1.1 Add `ledgerAccountNumber` and `bankConnectionId` to `BankAccount` in `register.d/bookkeeping-multi-currency.json`, with seed values (REQ-BCON-001). Verify: `npm run check:registers` and a re-import with no failed schemas.
- [x] 1.2 Show connection state, last sync and ledger account on `BankAccounts` and `BankAccountDetail`, and add the "Connect" header action that opens the REQ-BC-006 hand-off (REQ-BCON-001). Verify: Playwright on the seeded accounts.

## 2. Intake

- [x] 2.1 Move the save loop of `BankStatementImportController::import()` into `lib/Service/Bank/StatementIntakeService.php`, with batch and end-to-end duplicate skips (REQ-BCON-002). Verify: the existing import tests stay green and new PHPUnit covers both skips.
- [x] 2.2 Add `lib/Listener/BankfeedSyncedListener.php` for `nl.conduction.bankfeed.transactions.synced`, reading the batch by `batchUri` and calling the intake (REQ-BCON-002). Verify: PHPUnit with a fixture batch; a live run against integriq's log provider writes one statement.

## 3. Booking on arrival

- [x] 3.1 Add the exact-match step to the intake: one candidate, same amount, same reference, then write and confirm the `ReconciliationMatch` as `system:bankfeed` (REQ-BCON-003). Verify: PHPUnit for one candidate, two candidates and a partial amount.
- [x] 3.2 Unregister `BankfeedReconciliationJob` from `appinfo/info.xml` and delete it with its tests (REQ-BCON-003). Verify: `occ background-job:list` no longer shows it after upgrade on a local instance.

## 4. Cash position

- [x] 4.1 Add `FinancialSeriesCalculator::cashPositionByAccount()` and `GET /api/v1/cash-position` with an administration access check (REQ-BCON-004). Verify: PHPUnit that the per-account sum equals `computeKpis()['cashPosition']`; hydra gates route-auth and no-admin-idor pass.
- [x] 4.2 Point the `group-cash-position` widget in `src/manifest.d/30-treasury-ihb.json` at the endpoint and add the cash position column to `BankAccounts` (REQ-BCON-004). Verify: Playwright reads EUR 334,300.00 on the seed data.

## 5. Docs

- [x] 5.1 Release note naming the integriq dependency, the retired job and what books itself. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/banking-connected-accounts/tasks.md#task-N` on every new method, Dutch and English strings for every new label.

# Tasks: ledger-posting-path

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Inventory

- [ ] 1.1 Confirm the design.md inventory table against the register at the build sha (a script that walks every `x-openregister-lifecycle` action), and record any new declaration. Verify: the script's output is pasted in the PR body.

## 2. Handlers

- [ ] 2.1 Add `lib/Lifecycle/Action/MaterialiseGlTransactionAction.php` with the JournalEntry mapper, idempotency and the balance refusal; register it under `materialise-gl-transaction` in `lib/AppInfo/Application.php` (REQ-LPP-004). Verify: PHPUnit for balanced, unbalanced and repeated runs.
- [ ] 2.2 Add the `APInvoice`, `ExpenseClaimEntry` and `InventoryValuation` mappers (REQ-LPP-006). Verify: one PHPUnit case per mapper with a balanced and an unbalanced source.
- [ ] 2.3 Add `lib/Lifecycle/Action/EvaluateAllocationRulesAction.php` and register it under `evaluate-allocation-rules` (REQ-LPP-001, REQ-LPP-002). Verify: PHPUnit for a matching rule, no matching rule and a repeated run.

## 3. Declarations

- [ ] 3.1 Remove the `materialise-gl-transaction` declaration from `StockMove.post` in `register.d/inventory-stock-movement-ledger.json` and from `Payroll.issue` in `register.d/bookkeeping-detachering-payroll-administratie.json` (REQ-LPP-006). Verify: `npm run check:registers` and a PHPUnit case asserting one COGS transaction per dispatched move.

## 4. End to end

- [ ] 4.1 Playwright `tests/e2e/ledger-posting-path.spec.ts`: post a balanced transaction from GeneralLedgerDetail, see the refusal on an unbalanced one, post in a second open year (REQ-LPP-001, REQ-LPP-003). Verify: the spec passes against a local instance.
- [ ] 4.2 Live check of the humaniq hand-off: run `occ humaniq:glpost:run` for an approved run on an instance with both apps and post the entry (REQ-LPP-005). Verify: the posted transaction id is recorded in the PR body.

## 5. Docs

- [ ] 5.1 Release note naming the seeded allocation rules that start running, and the two removed declarations. Verify: the note is in the PR body and `docs/` where the repo keeps release notes.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/ledger-posting-path/tasks.md#task-N` on every new method, Dutch and English strings for any new message.

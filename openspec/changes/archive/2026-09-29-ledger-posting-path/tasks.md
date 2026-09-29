# Tasks: ledger-posting-path

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Inventory

- [x] 1.1 Confirm the design.md inventory table against the register at the build sha (a script that walks every `x-openregister-lifecycle` action), and record any new declaration. Verify: the script's output is pasted in the PR body.

## 2. Handlers

- [x] 2.1 Add `lib/Lifecycle/Action/MaterialiseGlTransactionAction.php` with the JournalEntry mapper, idempotency and the balance refusal; register it under `materialise-gl-transaction` in `lib/AppInfo/Application.php` (REQ-LPP-004). Verify: PHPUnit for balanced, unbalanced and repeated runs.
- [x] 2.2 (amended at build, 29 Sep: the APInvoice mapper is built with 2.1. The ExpenseClaimEntry mapper needs `ExpenseAccountResolver`, so it moves to `expenses-category-mapping` task 3.2 and the declaration is refused by name until then. The three InventoryValuation declarations are removed, not mapped: sale dispatch is booked per stock move by `CogsPosterService`, nothing sets `postingEvent` or fires the transitions, and a running snapshot keyed on its own id could post only once. `PostingPathDeclarationsTest::testEveryPostingDeclarationIsServedOrOwedByName` holds this.) Add the `APInvoice`, `ExpenseClaimEntry` (through `ExpenseAccountResolver` of `expenses-category-mapping`) and `InventoryValuation` mappers (REQ-LPP-006). Verify: one PHPUnit case per mapper with a balanced and an unbalanced source.
- [x] 2.4 Declare `materialise-gl-transaction` on `ARInvoice.issue` and add the `ARInvoice` mapper (REQ-LPP-007). Verify: PHPUnit that an issued invoice of EUR 1,210 books 1,210 on receivables, 1,000 on revenue and 210 on VAT.
- [x] 2.5 (added at build, 29 Sep) Stamp a post: `lib/Lifecycle/PostingStamps.php` sets `postingLocked`, `retentionUntil` (31 December of the posting year plus 10), `integrityVerified` and the post on `auditTrail`; `StampPostingAction` is declared first on `GLTransaction.post`, `RuleComplianceGuard::validateTransaction` judges the entry as stamped, and `MaterialiseGlTransactionAction` stamps what it writes (REQ-LPP-001, REQ-LPP-004). Verify: `PostingStampsTest`, `StampPostingActionTest`, `PostingGuardsThroughAdapterTest::testAMemorialEntryAsTheLedgerPageSendsItPosts`, `MaterialiseGlTransactionActionTest::testAMaterialisedTransactionMeetsTheMandatoryLedgerRules`.
- [x] 2.3 Add `lib/Lifecycle/Action/EvaluateAllocationRulesAction.php` and register it under `evaluate-allocation-rules` (REQ-LPP-001, REQ-LPP-002). Verify: PHPUnit for a matching rule, no matching rule and a repeated run.

## 3. Declarations

- [x] 3.1 Remove the `materialise-gl-transaction` declaration from `StockMove.post` in `register.d/inventory-stock-movement-ledger.json` and from `Payroll.issue` in `register.d/bookkeeping-detachering-payroll-administratie.json` (REQ-LPP-006). Verify: `npm run check:registers` and a PHPUnit case asserting one COGS transaction per dispatched move.

## 4. End to end

- [x] 4.1 (amended at build, 29 Sep: the Post button is the generic lifecycle action of nextcloud-vue's detail page, so a stubbed Playwright run would test the library. The server side is proven through the real adapter by `PostingGuardsThroughAdapterTest`, and the live check below covers the page.) Playwright `tests/e2e/ledger-posting-path.spec.ts`: post a balanced transaction from GeneralLedgerDetail, see the refusal on an unbalanced one, post in a second open year (REQ-LPP-001, REQ-LPP-003). Verify: the spec passes against a local instance.
- [ ] 4.2 Live check of the humaniq hand-off: run `occ humaniq:glpost:run` for an approved run on an instance with both apps and post the entry (REQ-LPP-005). Verify: the posted transaction id is recorded in the PR body.

## 5. Docs

- [x] 5.1 Release note naming the seeded allocation rules that start running, and the two removed declarations. Verify: the note is in the PR body and `docs/` where the repo keeps release notes.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/ledger-posting-path/tasks.md#task-N` on every new method, Dutch and English strings for any new message.

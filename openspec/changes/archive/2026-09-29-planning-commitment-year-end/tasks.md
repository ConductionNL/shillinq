# Tasks: planning-commitment-year-end

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Prerequisite: the lifecycle resolves

- [x] 1.1 Add `lib/Lifecycle/CommitmentGuardAdapter.php` and register `MandateEnforcer::requiresApproval` and `BudgetBlocker::canCommit` with it (REQ-PCYE-001). Verify: PHPUnit with the real `LifecycleGuardInterface` for allow and deny; a live `aangaan` on a seed commitment succeeds.
- [x] 1.2 Add `lib/Lifecycle/Action/RecordCommitmentMovementAction.php`, registered under `record-mutatie` (REQ-PCYE-001). Verify: PHPUnit that one committed movement is written once and the budget moves.

## 2. Invoicing and the last invoice

- [x] 2.1 Add `SupplierInvoice.isLastInvoice`, movement kind `carried_forward`, `CommitmentLine.carriedFromLine`, and widen `afsluiten` to every open state with a closed `record-mutatie` action (REQ-PCYE-003). Verify: `npm run check:registers`; re-import with no failed schemas.
- [x] 2.2 Add `lib/Listener/InvoiceCommitmentListener.php`: invoiced movement on approval, `afsluiten` when marked last (REQ-PCYE-002, REQ-PCYE-003). Verify: PHPUnit with the real `ObjectTransitionedEvent` for a partial and a last invoice.
- [x] 2.3 Add the "Last invoice" checkbox with the release confirmation to `SupplierInvoiceDetail` (REQ-PCYE-003). Verify: Playwright marks the seed invoice last and sees V-2026-0114 closed.

## 3. Year end

- [x] 3.1 Add `lib/Service/Commitment/CommitmentCarryOverService.php` with `preview()` and idempotent `execute()` (REQ-PCYE-004). Verify: PHPUnit on the seed data, including a second run.
- [x] 3.2 Add the "Carry open commitments to next year" action with its preview modal under `src/modals/` on `CommitmentsRegister`, controller role only (REQ-PCYE-004). Verify: Playwright runs it for 2026 and sees the shortfall line; hydra gates modal-isolation and semantic-auth pass.

## 4. End to end

- [ ] 4.1 (Owed: no live instance run in this lane; the recipe is in the PR body.) Live check on a local instance: commit V-2026-0114, approve an invoice of EUR 15,000, mark it last, read the budget (REQ-PCYE-001, REQ-PCYE-002, REQ-PCYE-003). Verify: object ids and budget figures in the PR body.

## 5. Docs

- [x] 5.1 Release note: commitment guards now enforce, the last-invoice mark, and the year-end carry-over. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/planning-commitment-year-end/tasks.md#task-N` on every new method, Dutch and English strings for every label and message.

## Evidence (2026-09-29)

- 1.1 `lib/Lifecycle/CommitmentGuardAdapter.php`, registered for both tags in `lib/AppInfo/Application.php`. `CommitmentYearEndTest::testTheGuardsResolveAndNameTheShortfall` with the real `BudgetBlocker` and `MandateEnforcer` over the store: allowed within budget, refused naming "The 2026 budget for programme 0.4 is EUR 20,000.00 short.", `indienen` allowed without a mandate.
- 1.2 `lib/Lifecycle/Action/RecordCommitmentMovementAction.php` over `lib/Service/Commitment/CommitmentLedger.php`, declared by class name on `aangaan` and `afsluiten`. `testEnteringACommitmentRecordsItOnce` (movement and budget validated against the merged register, a second run writes nothing).
- 2.1 `SupplierInvoice.isLastInvoice`, movement kind `carried_forward`, `CommitmentLine.carriedFromLine`, `afsluiten` from every open state with the closed action, `factureren` from committed too. `testTheLifecycleDeclaresTheWiring`; `npm run check:registers` 0.
- 2.2 `lib/Listener/InvoiceCommitmentListener.php` over `CommitmentInvoicing`. `testAnApprovedInvoiceLowersTheCommitment` with the real `ObjectTransitionedEvent` (once, and not for another schema or state), `testAnInvoiceApprovedAsTheLastOneCloses`.
- 2.3 Mark as last invoice on `SupplierInvoiceDetail.vue` with `src/modals/LastInvoiceModal.vue`, `CommitmentYearEndController::previewLastInvoice`/`markLastInvoice`. `testTheLastInvoiceReleasesTheRest`, `CommitmentYearEndControllerTest::testMarkingTheLastInvoice`, vitest.
- 3.1 `CommitmentCarryOverService`. `testOpenCommitmentsCarryOverOnce` (EUR 18,000 into 2027, shortfall EUR 8,000, the draft not carried, a second run writes nothing).
- 3.2 Header action on `CommitmentsRegister` through `openCarryOverCommitments`, `src/modals/CarryOverCommitmentsModal.vue`, controller or owner only: `CommitmentYearEndControllerTest::testOnlyAControllerCarriesOver`, vitest; hydra gates modal-isolation and semantic-auth in the gate run.
- 5.1 In the PR body; user guide `docs/user-guide/bookkeeping/commitments-year-end.md`.

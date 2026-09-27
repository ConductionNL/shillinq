# Tasks: planning-commitment-year-end

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Prerequisite: the lifecycle resolves

- [ ] 1.1 Add `lib/Lifecycle/CommitmentGuardAdapter.php` and register `MandateEnforcer::requiresApproval` and `BudgetBlocker::canCommit` with it (REQ-PCYE-001). Verify: PHPUnit with the real `LifecycleGuardInterface` for allow and deny; a live `aangaan` on a seed commitment succeeds.
- [ ] 1.2 Add `lib/Lifecycle/Action/RecordCommitmentMovementAction.php`, registered under `record-mutatie` (REQ-PCYE-001). Verify: PHPUnit that one committed movement is written once and the budget moves.

## 2. Invoicing and the last invoice

- [ ] 2.1 Add `SupplierInvoice.isLastInvoice`, movement kind `carried_forward`, `CommitmentLine.carriedFromLine`, and widen `afsluiten` to every open state with a closed `record-mutatie` action (REQ-PCYE-003). Verify: `npm run check:registers`; re-import with no failed schemas.
- [ ] 2.2 Add `lib/Listener/InvoiceCommitmentListener.php`: invoiced movement on approval, `afsluiten` when marked last (REQ-PCYE-002, REQ-PCYE-003). Verify: PHPUnit with the real `ObjectTransitionedEvent` for a partial and a last invoice.
- [ ] 2.3 Add the "Last invoice" checkbox with the release confirmation to `SupplierInvoiceDetail` (REQ-PCYE-003). Verify: Playwright marks the seed invoice last and sees V-2026-0114 closed.

## 3. Year end

- [ ] 3.1 Add `lib/Service/Commitment/CommitmentCarryOverService.php` with `preview()` and idempotent `execute()` (REQ-PCYE-004). Verify: PHPUnit on the seed data, including a second run.
- [ ] 3.2 Add the "Carry open commitments to next year" action with its preview modal under `src/modals/` on `CommitmentsRegister`, controller role only (REQ-PCYE-004). Verify: Playwright runs it for 2026 and sees the shortfall line; hydra gates modal-isolation and semantic-auth pass.

## 4. End to end

- [ ] 4.1 Live check on a local instance: commit V-2026-0114, approve an invoice of EUR 15,000, mark it last, read the budget (REQ-PCYE-001, REQ-PCYE-002, REQ-PCYE-003). Verify: object ids and budget figures in the PR body.

## 5. Docs

- [ ] 5.1 Release note: commitment guards now enforce, the last-invoice mark, and the year-end carry-over. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/planning-commitment-year-end/tasks.md#task-N` on every new method, Dutch and English strings for every label and message.

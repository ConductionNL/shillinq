# Tasks: banking-payment-run

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Invoices become payable

- [ ] 1.1 Add `BalanceGuard::isInvoiceBalanced(array $object)`, point `APTransaction.issue.requires` at it, and register the literal tag through `RegisterRequiresGuardAdapter` in `lib/AppInfo/Application.php` (REQ-BPR-001). Verify: PHPUnit for a balanced and an unbalanced invoice, and a live `issue` of a seeded invoice that reaches state issued.

## 2. Payment block

- [ ] 2.1 Add `paymentBlocked` and `paymentBlockReason` to `APTransaction` and `Payee` in `register.d/bookkeeping-accounts-payable-core.json`, with seed objects (REQ-BPR-003, REQ-BPR-004). Verify: `npm run check:registers` and a re-import that reports no failed schemas.
- [ ] 2.2 Add "Block payment" and "Release payment" header actions to `APTransactionDetail` and `PayeeDetail`, the reason asked in a modal under `src/modals/` (REQ-BPR-003, REQ-BPR-004). Verify: Playwright blocks and releases an invoice and sees the reason on the page and in the audit trail.
- [ ] 2.3 Add `lib/PaymentRun/PaymentBlockChecker.php` and call it from `PaymentRunDuplicateGuard::check()`, denying with the blocked invoice numbers (REQ-BPR-005). Verify: PHPUnit for a blocked invoice, a blocked payee, a disputed invoice and a clean run.

## 3. Proposal

- [ ] 3.1 Add `lib/PaymentRun/PaymentRunProposalService.php` with the selection and skip reasons of design.md D2 (REQ-BPR-002). Verify: PHPUnit over the seed data yields one line and two skipped invoices with reasons.
- [ ] 3.2 Add `POST /api/v1/payment-runs/propose` to `PaymentRunController` with `#[NoAdminRequired]` and an administration access check, and a "Propose payment run" header action with its modal on `PaymentRuns` (REQ-BPR-002). Verify: hydra gates route-auth and no-admin-idor pass; Playwright proposes a run and opens it in draft.

## 4. Execution date per payment

- [ ] 4.1 Add optional `paymentLines[].requestedExecutionDate` to `PaymentRun`, fill it from the proposal when "pay on the due date" is ticked (REQ-BPR-006). Verify: PHPUnit on the proposal for both settings.
- [ ] 4.2 Group lines by requested date in `SepaPain001Generator::render()`, one `PmtInf` per date (REQ-BPR-006). Verify: PHPUnit validates the output against the pain.001.001.03 XSD for one and for three dates.

## 5. Docs

- [ ] 5.1 Release note: invoices can now be issued, what a block does and does not stop, and that REQ-FPCR-004 must call `PaymentBlockChecker`. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/banking-payment-run/tasks.md#task-N` on every new method, Dutch and English strings for every new label and message.

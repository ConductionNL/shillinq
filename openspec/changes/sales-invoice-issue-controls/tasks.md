# Tasks: sales-invoice-issue-controls

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Numbering

- [ ] 1.1 Add `InvoiceNumberSequence` and the repair step that seeds it from current numbers (REQ-SIIC-001). Verify: `npm run check:registers`; PHPUnit for the repair.
- [ ] 1.2 `AssignInvoiceNumberAction` on `ARInvoice.issue` with the lock, and the billable kind in `InvoiceGenerationService::generateInvoiceNumber()` (REQ-SIIC-001). Verify: PHPUnit for concurrency (two callers), year reset and pattern formatting.
- [ ] 1.3 Read-only number in the page config and the update refusal (REQ-SIIC-002). Verify: PHPUnit for the refusal; vitest for the field.

## 2. Approval

- [ ] 2.1 Add the approval states, transitions and `salesInvoiceApprovalThreshold` (REQ-SIIC-003). Verify: `npm run check:registers`.
- [ ] 2.2 `SalesInvoiceApprovalGuard` (`canApprove`, `canIssue`) and the delegation from `RuleComplianceGuard::validateInvoice` (REQ-SIIC-003). Verify: PHPUnit per scenario.
- [ ] 2.3 Buttons on `ARInvoiceDetail` and an approvals list filtered to invoices awaiting approval (REQ-SIIC-003). Verify: `npm run check:manifest`.

## 3. Report

- [ ] 3.1 `InvoiceNumberAuditService` and its card on the Reports page (REQ-SIIC-004). Verify: PHPUnit for gaps and duplicates.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/sales-invoice-issue-controls.spec.ts`: issue with a number, approval by another user. Verify: passes locally.
- [ ] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

# Tasks: sales-cancellation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Cancellation reasons

- [ ] 1.1 Add `cancellation` and `customerMasterId` to `RecurringInvoiceProfile` in `lib/Settings/register.d/sales-cancellation.json`, the guard `CancellationReasonGuard::requireReason` on both end transitions, and the reason field on the End action of `RecurringInvoiceProfileDetail` (REQ-SCX-001). Verify: `npm run check:registers`; PHPUnit for a staff end without a code, with a code, and a customer end without one.
- [ ] 1.2 Add the repair step that fills `customerMasterId` on existing profiles and lists the unresolved ones, and copy it into generated invoices in `RecurringInvoiceGenerator::buildArInvoicePayload()` (REQ-SCX-003). Verify: PHPUnit for a resolved and an unresolved profile, and for the generated invoice's `customerId`.
- [ ] 1.3 Add the aggregation and the Cancellation reasons card and page on the Reports page (REQ-SCX-002). Verify: `npm run check:manifest`; Playwright on the seeded Sportschool De Kracht data.

## 2. Portal

- [ ] 2.1 Add the `subscriptions` collection and the `cancel-subscription` action to `PortalContributionProvider`, and `POST /api/portal/subscriptions/cancel` verifying the subject assertion and ownership (REQ-SCX-003). Verify: PHPUnit including another customer's profile refused; the portal contribution contract tests.
- [ ] 2.2 Add the `withdraw` action to the customer manifest and `POST /api/portal/withdrawals` accepting either a portal subject or a signed appointment link (REQ-SCX-004). Verify: PHPUnit for a valid subject, a valid link, an expired link and a tampered link.

## 3. Withdrawal

- [ ] 3.1 Add `withdrawalExempt` and its reason to the booking service, and `WithdrawalService::isWithdrawable()` with the reason for every false answer (REQ-SCX-005). Verify: PHPUnit for each rule of design.md D5.
- [ ] 3.2 Add `Withdrawal` and `WithdrawalService::withdraw()`: cancel with zero fee through `CancellationService`, credit, refund through `DepositPaymentAdapterInterface::initiateRefund()` or record the refund as due, and the notification to staff (REQ-SCX-006). Verify: PHPUnit for a deposit refunded, a dormant adapter and a policy that would charge a late fee.
- [ ] 3.3 Mail the acknowledgement with date and time, and put the signed withdrawal link in the booking confirmation mail in place of the undeclared `confirmationApi.portal` link (REQ-SCX-004, REQ-SCX-006). Verify: PHPUnit with a mailer double asserting the link and the acknowledgement text.
- [ ] 3.4 Show the withdrawal link on the widget's confirmation step (`src/components/widget/`) (REQ-SCX-004). Verify: Vitest for the rendered link; a live check with the embedded widget recorded in the PR body.

## 4. Cross-project and docs

- [ ] 4.1 Open the portaliq issue for rendering `cancel-subscription` and `withdraw` and for the guest page of a signed link, with the label text of design.md D3 (REQ-SCX-003, REQ-SCX-004). Verify: the issue link in the PR body.
- [ ] 4.2 User guide pages on cancellation reasons and withdrawals, including how to mark a service exempt, and a release note. Verify: the pages are in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-cancellation/tasks.md#task-N` on every new method, English source strings with Dutch translations for every label, mail and message.

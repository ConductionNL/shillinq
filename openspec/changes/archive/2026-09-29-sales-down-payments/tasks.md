# Tasks: sales-down-payments

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Posting ground

- [x] 1.1 Check on the build sha whether `ledger-posting-path` added an `ARInvoice` mapper and a declaration on `ARInvoice.issue`; if not, add both, with the down-payment and deduction rules of design.md D4 (REQ-SDP-002, REQ-SDP-003). Verify: PHPUnit for a standard invoice, a down payment and a final invoice with a deduction, each asserting a balanced transaction on the named accounts.
- [x] 1.2 Add the `advancesAccount` posting setting per administration, seeded to 2310 (REQ-SDP-002). Verify: PHPUnit on the seeded administration.

## 2. Down payment

- [x] 2.1 Add the `downPayment` group to `ARInvoice` in `lib/Settings/register.d/sales-down-payments.json`, with `referenceSemanticType` on the order reference (REQ-SDP-001). Verify: `npm run check:registers`.
- [x] 2.2 Add `DownPaymentService::raise()` (percentage or amount, proportional VAT split, stored totals, type code 386) and `POST /api/ar-invoices/down-payments` with the administration check first (REQ-SDP-001). Verify: PHPUnit for a single-rate and a mixed-rate order and a cross-administration refusal.

## 3. Final invoice

- [x] 3.1 Add `DownPaymentService::deductOnto()` and the guard `DownPaymentGuard::requireOpenDeductions` on `ARInvoice.issue`, stamping `deductedOnInvoiceId` on issue (REQ-SDP-003, REQ-SDP-004). Verify: PHPUnit for a deduction, a double deduction and deductions above the total.
- [x] 3.2 Add the New down-payment invoice action on `AccountsReceivable` and the deductions panel with the order's down-payment position on `ARInvoiceDetail` (REQ-SDP-001, REQ-SDP-003, REQ-SDP-005). Verify: `npm run check:manifest`; Playwright for the Keuken Eiland example end to end.

## 4. E-invoice

- [x] 4.1 Map type code 386, negative deduction lines and billing references in `ArInvoiceUblMapper` (REQ-SDP-006). Verify: PHPUnit validating both documents against the NLCIUS fixtures used by `EInvoiceValidationService`.

## 5. Docs

- [x] 5.1 User guide page on down payments and a release note naming the advances account setting. Verify: the page is in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-down-payments/tasks.md#task-N` on every new method, English source strings with Dutch translations.

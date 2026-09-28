# Tasks: receivables-object-request-settlement

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Request type and reference

- [ ] 1.1 Add `event-fee` to the `requestType` enum and to `ObjectPaymentRequestValidator::REQUEST_TYPES`, and `paymentReference`, `invoiceRequested` and `receiptSentAt` to `PaymentRequest`; bump the schema version (REQ-ORS-001, REQ-ORS-002). Verify: PHPUnit `ObjectPaymentRequestValidatorTest::testEventFeeIsAccepted`, `testDuplicateOpenReferenceIsRefused` and `testShortReferenceIsRefused`.
- [ ] 1.2 Accept `paymentReference` and `invoiceRequested` in `PaymentRequestLeafProvider::create()`. Verify: PHPUnit `PaymentRequestLeafProviderTest::testCreateKeepsReferenceAndInvoiceFlag`.

## 2. Bank match

- [ ] 2.1 `ObjectRequestBankMatcher` and `BankLineObjectRequestListener` on `ObjectCreatedEvent` for `BankStatementLine`; add `payment-request` to `ReconciliationMatch.targetType` (REQ-ORS-003). Verify: PHPUnit `ObjectRequestBankMatcherTest` with one exact match, a partial amount, two requests and no reference.
- [ ] 2.2 Settle the request on a confirmed `payment-request` match: `bank-transfer` settlement, `settledAt`, receipt booked against the bank account, or the invoice settled when one stands behind it (REQ-ORS-004). Verify: PHPUnit `BankLineObjectRequestListenerTest::testConfirmedMatchSettlesAndBooksOnce`.
- [ ] 2.3 Live check: import a CAMT.053 file with a line quoting `WC26-0042` for the open amount. Verify: Playwright `tests/e2e/object-request-bank-match.spec.ts` "a quoted reference settles the request" and "a partial amount waits for the bookkeeper".

## 3. Receipt

- [ ] 3.1 `ObjectRequestSettledListener` and `ObjectRequestReceiptMailer`, once per request through `receiptSentAt` (REQ-ORS-005). Verify: PHPUnit `ObjectRequestSettledListenerTest::testProviderCaptureMailsTheDebtorOnce`, `testManualSettlementMails` and `testNoEmailNoMail`.

## 4. Invoice on request

- [ ] 4.1 `ObjectRequestInvoiceService` called from `create()` when `invoiceRequested` is true: debtor's `CustomerMaster`, one issued `ARInvoice`, `invoiceReference` on the request (REQ-ORS-006). Verify: PHPUnit `ObjectRequestInvoiceServiceTest::testInvoiceStandsBehindTheRequest` and `PaymentReconciliationServiceTest::testCaptureSettlesTheInvoiceAndBooksNoObjectReceipt`.

## 5. Close

- [ ] 5.1 English and Dutch strings for the mail and the admin setting; docs page on requests for other apps. Run `openspec validate receivables-object-request-settlement --strict`.
- [ ] 5.2 Tell larpinq the leaf payload keys and the `event-fee` type, so `registration-payments-through-shillinq` stops using `other`.

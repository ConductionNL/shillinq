# Tasks: receivables-object-request-refund-and-credit

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Schema

- [ ] 1.1 Add the states `refund_requested`, `refunded`, `credited` with their transitions, the `refunds` list and `credit` in `settledVia` to `PaymentRequest`; add `DebtorCredit` in `register.d/debtor-credit.json`; bump versions (REQ-ORC-002, REQ-ORC-003). Verify: PHPUnit on the register import; `npm run check:schema-l10n` exit 0.

## 2. Events

- [ ] 2.1 `PaymentRefundRequestedEvent`, `PaymentCreditRequestedEvent` and their listeners with the D2 checks, registered in `Application::register()` (REQ-ORC-001). Verify: PHPUnit `PaymentRefundRequestedListenerTest` on the real event class: accepted for its own subject, refused for another app's subject, refused before settlement, refused twice.

## 3. Refund

- [ ] 3.1 `ObjectRequestRefundService` approve and mark-paid with their bookings and the `paymentRefundAccount` setting (REQ-ORC-002). Verify: PHPUnit `ObjectRequestRefundServiceTest::testApproveBooksTheReversal` and `testMarkPaidMovesToRefunded`.
- [ ] 3.2 "Refunds to pay" page and its two actions, gated on `payment.administer`. Verify: Playwright `tests/e2e/object-request-refund.spec.ts` "a finance user pays a refund and the request reads refunded".

## 4. Credit

- [ ] 4.1 `DebtorCreditService` and the credit booking with `paymentCreditAccount` (REQ-ORC-003). Verify: PHPUnit `DebtorCreditServiceTest::testCreditIsKeyedByCustomerMasterOrEmail` and `testDebtorWithoutKeyGetsNoCredit`.
- [ ] 4.2 Apply open credit in `PaymentRequestLeafProvider::create()` (REQ-ORC-004). Verify: PHPUnit `PaymentRequestLeafProviderTest::testCreditPaysTheNextRequestFirst` and `testCreditCoveringTheWholeAmountSettlesAtOnce`.

## 5. Close

- [ ] 5.1 English and Dutch strings, docs on refunds and credit for other apps; run `openspec validate receivables-object-request-refund-and-credit --strict`.
- [ ] 5.2 Tell larpinq to dispatch these two events from `registration-cancel-transfer-refund` and to read `refunded` and `credited` from the object event.

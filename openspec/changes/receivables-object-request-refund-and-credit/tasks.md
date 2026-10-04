# Tasks: receivables-object-request-refund-and-credit

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Schema

- [x] 1.1 Add the states `refund_requested`, `refunded`, `credited` with their transitions, the `refunds` list and `credit` in `settledVia` to `PaymentRequest`; add `DebtorCredit` in `register.d/debtor-credit.json`; bump versions (REQ-ORC-002, REQ-ORC-003). Verify: PHPUnit on the register import; `npm run check:schema-l10n` exit 0. (3 Oct 2026: PaymentRequest 0.8.0 with `refund_requested`, `refunded`, `credited`, transitions `requestRefund`, `markRefunded`, `credit`, `refunds` and `credit` in `settledVia`; the version also moves in `billing-inherited-defects.json`, the last fragment that sets it. `DebtorCredit` 0.1.0 in `register.d/debtor-credit.json`, declared on the register. `check:registers` 0, `check:schema-l10n` 0 with the baseline lowered to 12203; the payloads the services write validate against the merged schemas in `PaymentRefundRequestedListenerTest`.)

## 2. Events

- [x] 2.1 `PaymentRefundRequestedEvent`, `PaymentCreditRequestedEvent` and their listeners with the D2 checks, registered in `Application::register()` (REQ-ORC-001). Verify: PHPUnit `PaymentRefundRequestedListenerTest` on the real event class: accepted for its own subject, refused for another app's subject, refused before settlement, refused twice. (3 Oct 2026: `lib/Event/PaymentSettlementCommandEvent.php` with the two subclasses, `accept()`/`refuse()`, `isHandled()`/`getResult()`/`getError()`; both listeners delegate to `ObjectRequestCommandService`, wired in `ObjectRequestSettlementRegistration`. The subject has no `app` key on requests raised through the leaf, so the owner is `subject.app`, or `requestedBy: app:<id>`, or the subject's register. Tests on the real event classes: `testARefundIsAcceptedForItsOwnSubject`, `testRefusedForAnotherAppBeforeSettlementAndTwice`, `testTheAppWiresBothListeners`.)

## 3. Refund

- [x] 3.1 `ObjectRequestRefundService` approve and mark-paid with their bookings and the `paymentRefundAccount` setting (REQ-ORC-002). Verify: PHPUnit `ObjectRequestRefundServiceTest::testApproveBooksTheReversal` and `testMarkPaidMovesToRefunded`. (4 Oct 2026: `ObjectRequestRefundService::approve()` books debit the revenue account, credit `paymentRefundAccount`; `markPaid()` books debit refunds payable, credit the bank account given, sets the refund `paid` with the bank reference and the request `refunded`; both through a draft JournalEntry and `postDirect`. Served by `ObjectRequestRefundController` at `POST /api/payment-requests/{id}/refund/approve` and `/refund/paid`, gated on `payment.administer`. Tests start from a request the real listener put in `refund_requested` and validate every save against the merged register.)
- [ ] 3.2 "Refunds to pay" page and its two actions, gated on `payment.administer`. Verify: Playwright `tests/e2e/object-request-refund.spec.ts` "a finance user pays a refund and the request reads refunded".

## 4. Credit

- [x] 4.1 `DebtorCreditService` and the credit booking with `paymentCreditAccount` (REQ-ORC-003). Verify: PHPUnit `DebtorCreditServiceTest::testCreditIsKeyedByCustomerMasterOrEmail` and `testDebtorWithoutKeyGetsNoCredit`. (3 Oct 2026: `DebtorCreditService` books debit the request's `revenueAccount` (or the type's mapped account), credit `paymentCreditAccount`, through a draft JournalEntry and `postDirect`, records the DebtorCredit and moves the request to `credited`; called by the credit listener. Tests in `tests/Unit/Listener/PaymentRefundRequestedListenerTest.php`: `testCreditIsKeyedByCustomerMasterOrEmail`, `testDebtorWithoutKeyGetsNoCredit`.)
- [x] 4.2 Apply open credit in `PaymentRequestLeafProvider::create()` (REQ-ORC-004). Verify: PHPUnit `PaymentRequestLeafProviderTest::testCreditPaysTheNextRequestFirst` and `testCreditCoveringTheWholeAmountSettlesAtOnce`. (4 Oct 2026: `DebtorCreditService::applyOpenCredit()`, called by the leaf after validation and before the save, for create and createAsApp. Open DebtorCredit of the same debtor key and administration, oldest first by OpenRegister's created stamp; each use books debit `paymentCreditAccount`, credit the request's revenue account, lowers `remaining` (`used` at zero). One settlement with the new method `credit` (PaymentRequest 0.9.0) and `settledVia: credit` when covered whole. Credit that cannot be booked (no credit or revenue account) stays open and the request stays payable. Also tested: another debtor's or administration's credit is not used, `testCreditStaysOpenWhenItCannotBeBooked`.)

## 5. Close

- [ ] 5.1 English and Dutch strings, docs on refunds and credit for other apps; run `openspec validate receivables-object-request-refund-and-credit --strict`.
- [ ] 5.2 Tell larpinq to dispatch these two events from `registration-cancel-transfer-refund` and to read `refunded` and `credited` from the object event.

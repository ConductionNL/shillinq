# Tasks: inbound-payable-from-another-app

## 1. Payable

- [x] 1.1 `InboundPayable` schema in `register.d/inbound-payables.json`, declared on the register, catalogue keys in `l10n/en.json` and `l10n/nl.json` (REQ-IPY-001). Verify: `check:registers`, `check:schema-l10n` 0. (11 Oct 2026: schema 0.1.0; schema-l10n baseline 12203 to 12197.)
- [x] 1.2 `PayableRaisedEvent`, `PayableRaisedListener`, `InboundPayableService::raise()` with the checks and the retry rule (REQ-IPY-001). Verify: PHPUnit `InboundPayableServiceTest::testAnAppRaisesAPayable`, `testABadCommandIsRefusedAndWritesNothing`, `testARetryAnswersTheFirstPayableAndAClashIsRefused`.
- [x] 1.3 Approve, mark paid and reject with their bookings, served by `InboundPayableController` on `payment.administer` (REQ-IPY-002, REQ-IPY-003). Verify: `testApproveBooksTheExpenseAndCallsBack`, `testMarkPaidClearsAgainstTheBankAndCallsBack`, `testRejectBooksNothingAndCallsBackWithTheReason`, `testTheExpenseAccountFallsBackToTheAppThenRefuses`, `testStepsNeedTheAdministerActionAndTheListenerIsWired`.
- [x] 1.4 `InboundPayableCallbackSender`: signed post after each step, no secret no post (REQ-IPY-004). Verify: `testNoSecretMeansNoUnsignedCallback`; every saved payload validates against the merged register.
- [x] 1.5 Developer docs `docs/api/inbound-payable.md`.

## 2. Follow-up

- [ ] 2.1 "Payouts to pay" menu entry on the InboundPayable index with the three row actions. Verify: Playwright on the live instance.
- [x] 2.3 `PayableCancelRequestedEvent`, `PayableCancelRequestedListener`, `InboundPayableService::cancel()`: the raising app withdraws a requested payable; schema 0.2.0 adds `cancelled`, `cancelledAt`, `cancelledReason` (REQ-IPY-005). Verify: `testTheAppWithdrawsARequestedPayable`, `testWithdrawingIsRefusedOnceFinanceActed`, `testStepsNeedTheAdministerActionAndTheListenerIsWired`.
- [ ] 2.2 Tell dossiq to raise its dwangsom payout through `PayableRaisedEvent` (refactor lane R3, C16 part 2).

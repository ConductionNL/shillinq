# Tasks: shillinq-payment-webhook-controller-test-coverage

## 1. New controller test

- [ ] 1.1 Create `tests/Unit/Controller/PaymentRequestWebhookControllerTest.php`, mirroring
  `tests/Unit/Controller/DepositWebhookControllerTest.php`'s constructor-mocking pattern
  (`IRequest`, `IAppConfig`, `PaymentReconciliationService`, `LoggerInterface` doubles).
- [ ] 1.2 `testValidSignatureKnownEventDispatchesReconciliation` — valid HMAC signature +
  well-formed Mollie (or Stripe) payload → `handle()` returns 200 and the reconciliation
  service double receives the expected call.
- [ ] 1.3 `testIdempotentNoopReturns202` — reconciliation service double reports already-applied
  → `handle()` returns 202, mirroring `DepositWebhookControllerTest::testIdempotentNoopReturns202`.
- [ ] 1.4 `testInvalidSignatureReturns400AndNoDispatch` — tampered/invalid HMAC → `handle()`
  returns 400 status `invalid-signature` AND the reconciliation service double receives NO
  call (assert zero invocations, not just the response code).
- [ ] 1.5 `testMissingSecretFailsClosed` — `IAppConfig` double returns no configured secret for
  the gateway → `handle()` rejects (fail-closed), matching the docblock's stated contract and
  `DepositWebhookControllerTest::testMissingSecretFailsClosed`.
- [ ] 1.6 `testUnknownGatewayReturns404` — `{gateway}` outside `['mollie', 'stripe']` → 404
  `unknown-gateway`, and signature verification / body parsing MUST NOT run (assert the
  `IAppConfig` double is never queried for this case).
- [ ] 1.7 `testEmptyBodyReturns400` and `testMalformedPayloadReturns400` — empty raw body and
  invalid-JSON body respectively → 400 with the matching status string.
- [ ] 1.8 `testUnparseableEventReturns400` — valid signature + valid JSON but
  `extractEvent()` cannot derive a `paymentIntentId` → 400 `unparseable-event`.
- [ ] 1.9 Add `@spec openspec/changes/shillinq-payment-webhook-controller-test-coverage/specs/ar-invoice-payment-links/spec.md (REQ-APL-009)` to the new test class docblock.
- [ ] 1.10 Confirm test names/assertions distinguish "rejected before touching the
  reconciliation service" (1.4, 1.5, 1.6) from "accepted and dispatched" (1.2, 1.3) — the
  point of this change is proving the fail-closed gate actually gates, not just that the
  controller returns *some* response.

## 2. Validation

- [ ] 2.1 `vendor/bin/phpunit tests/Unit/Controller/PaymentRequestWebhookControllerTest.php`
  passes.
- [ ] 2.2 Full `composer test` (or the app's standard PHPUnit target) still passes — no
  regression in `DepositWebhookControllerTest` or `PaymentReconciliationServiceTest`.
- [ ] 2.3 `openspec validate "shillinq-payment-webhook-controller-test-coverage" --type change
  --strict` passes.

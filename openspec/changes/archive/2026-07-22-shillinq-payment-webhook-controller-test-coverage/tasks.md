# Tasks: shillinq-payment-webhook-controller-test-coverage

## 1. New controller test

- [x] 1.1 Create `tests/Unit/Controller/PaymentRequestWebhookControllerTest.php`, mirroring
  `tests/Unit/Controller/DepositWebhookControllerTest.php`'s constructor-mocking pattern
  (`IRequest`, `IAppConfig`, `PaymentReconciliationService`, `LoggerInterface` doubles).
- [x] 1.2 `testValidSignatureKnownEventDispatchesReconciliation` — valid HMAC signature +
  well-formed Mollie payload → `handle()` returns 200 and the reconciliation service double
  receives the expected `reconcile('mollie', [...])` call. Also added
  `testStripeSucceededWithV1HeaderDispatchesReconciliation` covering the other supported
  gateway's happy path (Stripe `t=..,v1=<sig>` header parsing).
- [x] 1.3 `testIdempotentNoopReturns202` — reconciliation service double reports
  `RESULT_NOOP` → `handle()` returns 202.
- [x] 1.4 `testInvalidSignatureReturns400AndNoDispatch` — tampered HMAC → `handle()` returns
  400 status `invalid-signature` AND `reconcile()` asserted with `expects($this->never())`.
- [x] 1.5 `testMissingSecretFailsClosed` — `IAppConfig` double returns `''` for the gateway
  secret → `handle()` rejects 400 `invalid-signature` (fail-closed), reconcile() never called.
- [x] 1.6 `testUnknownGatewayReturns404` — `{gateway}` outside `['mollie','stripe']` → 404
  `unknown-gateway`; asserted the `IAppConfig` secret-lookup log is empty (`secretQueried ===
  []`), proving verifySignature/body-parsing never runs for this case.
- [x] 1.7 `testEmptyBodyReturns400` and `testMalformedPayloadReturns400` added.
- [x] 1.8 `testUnparseableEventReturns400` — valid signature + valid JSON, unhandled Mollie
  status (`open`) → 400 `unparseable-event`.
- [x] 1.9 `@spec` tag present in the test class docblock.
- [x] 1.10 Rejection-path tests (1.4, 1.5, 1.6, 1.8, malformed/empty-body) all assert
  `reconcile()` is never invoked; acceptance-path tests (1.2, 1.3, Stripe happy path) assert
  the exact `reconcile()` call args via `expects($this->once())->with(...)`.

## 2. Validation

- [x] 2.1 `vendor/bin/phpunit tests/Unit/Controller/PaymentRequestWebhookControllerTest.php`
  passes: 10 tests, 25 assertions, green (`phpunit-unit.xml` config, `php:8.3-cli` container).
- [x] 2.2 Ran together with `DepositWebhookControllerTest` +
  `PaymentReconciliationServiceTest`: 33 tests, 73 assertions, all green — no regression.
  (Full-suite `phpunit-unit.xml` run separately surfaces 16 errors/4 failures in
  `ShillinqNotificationsFragmentTest`, confirmed PRE-EXISTING and unrelated — reproduces
  identically on the untouched main checkout, targets `shillinq-notifications.json` /
  `bookkeeping-purchase-order-3way-01-schemas-and-registers.json`, neither touched by this
  change or by `migrate-legacy-notification-dialect`.)
- [x] 2.3 `openspec validate shillinq-payment-webhook-controller-test-coverage
  --strict` run from this worktree (CLI available at
  `~/.npm-global/bin/openspec`): "Change
  'shillinq-payment-webhook-controller-test-coverage' is valid", exit 0.

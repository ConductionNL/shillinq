# Change: shillinq-payment-webhook-controller-test-coverage

## Why

`lib/Controller/PaymentRequestWebhookController.php` is the shared, `#[PublicPage]` +
`#[NoCSRFRequired]` HTTP entry point (route `/api/v1/payment-requests/webhook/{gateway}`,
`appinfo/routes.php:512`) that Mollie/Stripe call with async payment-confirmation events for
BOTH `PaymentRequest` (AR invoice payment links) and `DepositPayment` (booking deposits). Its
own docblock (lines 85-89) states plainly: *"a #[PublicPage] webhook MUST verify a shared
secret/signature before doing ANY work"* — this is the exact HMAC-over-raw-body signature gate
(`verifySignature()`) plus the fail-closed-when-unconfigured behaviour that ADR-005 requires
for unauthenticated endpoints.

There is **zero test file** for this controller: `find tests -iname
"PaymentRequestWebhookControllerTest.php"` returns nothing, and no other test file references
the class. This is not a coverage gap on a peripheral helper — it's the actual signature-gated
network boundary of the payment-reconciliation feature, the piece unit tests on
`PaymentReconciliationService` (which IS tested — `tests/Unit/Service/
PaymentReconciliationServiceTest.php`) cannot exercise, because that service test calls the
service directly and never goes through `handle()`, `verifySignature()`, the raw-body read, or
the gateway/event-extraction dispatch.

The asymmetry is stark and self-evidently avoidable: the older, structurally identical sibling
endpoint — `DepositWebhookController` — DOES have a controller test
(`tests/Unit/Controller/DepositWebhookControllerTest.php`, 281 lines, 9 test methods covering
valid-signature success, idempotent no-op, invalid signature, missing-secret fail-closed,
multi-gateway header variants, unknown gateway, malformed payload, unhandled event, and
not-found reconciliation). `PaymentRequestWebhookController`'s own docblock (lines 5-12)
explicitly says it "models the proven `DepositWebhookController` exactly" — so the identical
security-relevant behaviour exists, untested, on the newer of the two twin endpoints. Per the
"phantom green" framing: the pipeline reports green (PHPUnit passes) while the fail-closed
signature gate on a live public payment endpoint has never been asserted to actually reject an
unsigned or badly-signed request.

## What Changes

- **ADDED** `REQ-APL-009` — `PaymentRequestWebhookController::handle()` SHALL have unit test
  coverage proving: (a) a request with a valid signature and a known event is accepted and
  dispatched, (b) a request with an invalid/missing signature is rejected with 400 and no
  reconciliation side effect occurs, (c) the endpoint fails closed (rejects) when no shared
  secret is configured for the gateway, (d) an unknown `{gateway}` route param returns 404
  before any body/signature processing, (e) a malformed JSON body returns 400, mirroring the
  existing `DepositWebhookControllerTest` coverage shape for its sibling endpoint.
- New test file `tests/Unit/Controller/PaymentRequestWebhookControllerTest.php`, modeled
  directly on `tests/Unit/Controller/DepositWebhookControllerTest.php`'s structure and mocking
  approach (same `IAppConfig` / `LoggerInterface` / reconciliation-service doubles pattern).
- No production code changes are required by this proposal — `PaymentRequestWebhookController`
  already implements the fail-closed behaviour per its docblock; this closes the proof gap,
  not a functional gap. If writing the tests surfaces an actual behavioural bug (e.g. a
  signature bypass path), that becomes a follow-up fix, not part of this change's scope.

## Impact

- Affected spec: `ar-invoice-payment-links` (ADDED `REQ-APL-009`).
- Affected code: new test file only; no `lib/` changes anticipated.
- Security-relevant: this is the untested half of a signature-gated public payment endpoint —
  closing it is the highest-value test addition found in this sweep (lens: test/e2e reality).

# ar-invoice-payment-links Specification

## Purpose
TBD - created by archiving change shillinq-payment-webhook-controller-test-coverage. Update Purpose after archive.
## Requirements
### Requirement: REQ-APL-009 — PaymentRequestWebhookController's signature-gated dispatch SHALL have controller-level test coverage

`PaymentRequestWebhookController::handle()` SHALL have unit test coverage exercising the
controller itself — the `#[PublicPage]` + `#[NoCSRFRequired]` HTTP entry point at
`/api/v1/payment-requests/webhook/{gateway}` (raw body read, signature verification, event
extraction, dispatch) — not only the downstream `PaymentReconciliationService` it calls.
Coverage SHALL prove both the accept path and every reject path fail closed without invoking
reconciliation: valid signature + known event → dispatched; invalid/missing signature → 400
with zero reconciliation-service invocations; unconfigured gateway secret → rejected
(fail-closed); unknown `{gateway}` → 404 before any signature/body processing; malformed JSON
body → 400.

#### Scenario: Valid signature and known event reaches reconciliation

- **WHEN** `handle()` receives a raw body with a valid HMAC signature for the configured
  gateway secret and a well-formed, parseable event
- **THEN** the reconciliation service is invoked exactly once with the extracted event and the
  response reflects its result (200 success or 202 idempotent no-op)

#### Scenario: Invalid signature rejects without touching reconciliation

- **WHEN** `handle()` receives a raw body whose signature does not match the configured secret
- **THEN** the response is 400 `invalid-signature` AND the reconciliation service receives no
  invocation

#### Scenario: Unconfigured secret fails closed

- **WHEN** no shared secret is configured for the requested gateway
- **THEN** `handle()` rejects the request (fails closed) rather than treating the absence of a
  configured secret as "skip verification"

#### Scenario: Unknown gateway short-circuits before signature processing

- **WHEN** `{gateway}` is outside `['mollie', 'stripe']`
- **THEN** `handle()` returns 404 `unknown-gateway` and never reads the configured secret or
  attempts signature verification


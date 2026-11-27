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

### Requirement: A user takes an invoice's payment on the spot on their phone (REQ-RPPO-005)

On `ARInvoiceDetail` of an open invoice, a user with a registered payment
device SHALL be able to take the open amount as a point-of-sale payment on that
device through integriq, and the capture SHALL settle the invoice as any
provider payment does. When the provider cannot push the payment to the device,
the page SHALL show the checkout as a QR code instead.

#### Scenario: A plumber is paid at the door

- GIVEN a plumber with a registered phone and open invoice 2026-0601 of EUR 145.20
- WHEN they choose Take payment now on the invoice page and the customer taps their card on the phone
- THEN the invoice page shows paid within the minute, with the provider payment id

### Requirement: A counter sale is a simplified invoice issued on capture (REQ-RPPO-006)

A user SHALL be able to record a counter sale on a phone-sized page with lines,
VAT per line and an optional customer, take its payment as in REQ-RPPO-005,
and on capture shillinq SHALL issue it as a paid simplified invoice. A failed or
abandoned payment SHALL leave a cancelled draft and nothing booked.

#### Scenario: A market stall sells a tea tin

- GIVEN a shop assistant on the Counter sale page with one line Theeblik groot EUR 12.50 at 21 percent VAT
- WHEN the customer pays on the assistant's phone
- THEN a paid simplified invoice of EUR 12.50 on the counter-sales debtor exists

#### Scenario: The customer walks away

- GIVEN the same counter sale
- WHEN the payment is abandoned
- THEN the draft is cancelled and no invoice is issued

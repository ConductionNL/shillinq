# Spec: orphan-auth-remediation (delta)

## ADDED Requirements

### Requirement: REQ-OAR-001 — Inbound Mollie/Stripe webhook verification is owned solely by the fail-closed controller gate

The Mollie payment adapter port (`MolliePaymentAdapterInterface`) SHALL be an outbound-only
port — `createPayment()` + `isDormant()` — and SHALL NOT declare an inbound `verifyWebhook()`
method. Inbound payment-confirmation webhook HMAC verification SHALL be performed exclusively by
the `#[PublicPage]` webhook controllers (`PaymentRequestWebhookController`,
`DepositWebhookController`) via a single shared `verifySignature()` implementation (REQ-APL-004
"ONE shared surface — never a fork"). The gate SHALL fail closed: a request with a forged,
missing, or unconfigured-secret signature SHALL be rejected with HTTP 400 before any
payment-reconciliation side effect occurs.

#### Scenario: Forged Mollie webhook signature is rejected before reconciliation

- **WHEN** the Mollie webhook endpoint receives a raw body whose signature does not match the
  configured `mollie` shared secret
- **THEN** the response is 400 `invalid-signature` AND the payment-reconciliation service is
  never invoked (no invoice/deposit state mutation)

#### Scenario: Unconfigured Mollie secret fails closed

- **WHEN** no shared secret is configured for the `mollie` gateway
- **THEN** the webhook endpoint rejects the request (fails closed) rather than treating the
  absent secret as "skip verification", and reconciliation is never invoked

#### Scenario: The removed adapter method is not re-introduced

- **WHEN** the Mollie port and its dormant default are inspected
- **THEN** neither `MolliePaymentAdapterInterface` nor `LogMolliePaymentAdapter` declares a
  `verifyWebhook()` method

### Requirement: REQ-OAR-002 — Retained dormant/DTO auth-verb methods are intentional and non-regressive

Two auth-verb-named methods flagged by gate-6 SHALL be retained as documented seams, because
neither gates a live financial mutation:

- `CsrdEsrsXbrlAdapterInterface::validateMandatoryDataPoints()` (and its dormant
  `LogCsrdEsrsXbrlAdapter` implementation) — part of the dormant CSRD/ESRS XBRL submission port
  whose activation depends on the unmerged cross-app `bookkeeping-sbr-xbrl-reporting`
  dependency. On a dormant binding it SHALL return `VALIDATION_BLOCKED` so a deferred adapter
  cannot publish an unvalidated report. The live CSRD lifecycle controls are enforced separately
  by `CsrdEsrsGuard`.
- `SmsSendResult::isDelivered()` — a PII-free DTO status accessor (`status ∈ {sent, pending}`),
  not an authorization check; retained as a supported, test-covered public accessor.

#### Scenario: A dormant CSRD XBRL binding cannot publish an unvalidated report

- **WHEN** `validateMandatoryDataPoints()` is called on the dormant `LogCsrdEsrsXbrlAdapter`
- **THEN** it returns a `VALIDATION_BLOCKED` outcome with a non-empty missing-mandatory list,
  never a clean validation

#### Scenario: The SMS delivery accessor reports accepted/queued status

- **WHEN** an `SmsSendResult` carries status `sent` or `pending`
- **THEN** `isDelivered()` returns true, and false for `failed` or `skipped`

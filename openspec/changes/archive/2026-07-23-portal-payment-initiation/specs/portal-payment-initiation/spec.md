# portal-payment-initiation Specification (delta)

---
status: proposed
---

## Purpose

Shillinq lets an external, accountless debtor PAY their own open AR invoice from
**portaliq**, the shared external portal (hydra ADR-046, contribution contract
v2.2). Today the `customer` manifest surfaces AR invoices and a "Pay my
invoices" collection READ-ONLY. This change adds the write leg: a
bearer-subject-scoped, A6-endpoint-forward initiation endpoint that verifies the
target invoice belongs to the subject, mints an iDEAL payment session through
the existing Mollie provider and returns its checkout URL; a `pay`
`endpoint-forward` action surfaced as a `rowAction` on the open-invoice rows;
and a subject-visible confirmation written by the existing signature-verified
webhook on settlement. Every path fails closed and the paid amount is always the
server-side invoice amount, never the client's.

## ADDED Requirements

### Requirement: A payment-provider port drives iDEAL through the existing Mollie adapter (REQ-SPPI-001)

Shillinq MUST expose a `PaymentProviderInterface` port whose shipped binding
delegates to the existing
`OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface`
(`createPayment()` → `MolliePaymentResult{paymentId, checkoutUrl}`). The port
MUST request iDEAL (`method: 'ideal'`) as the payment method for the portal
pay-now flow (iDEAL is the required Dutch MKB rail). The Mollie API key and
test-mode flag MUST be sourced from app config (never hardcoded). When the bound
provider is dormant (`isDormant()` true — no live binding configured) the port
MUST return a deferred outcome carrying NO checkout URL, and the initiation
endpoint MUST surface that honestly (a `deferred`/`503` result) rather than a
fabricated URL. The port MUST NOT be a second, forked Mollie client — it wraps
the one verified adapter.

#### Scenario: The port mints an iDEAL session through the Mollie adapter

- GIVEN a live-bound `PaymentProviderInterface` and a server-resolved invoice amount and currency
- WHEN the initiation flow requests a payment session
- THEN the port calls `MolliePaymentAdapterInterface::createPayment()` with `method: 'ideal'`, the server-side amount/currency, and the app-config API key/test-mode
- AND it returns the Mollie `checkoutUrl` + `paymentId`
- AND when the bound adapter is dormant it returns a deferred outcome with no checkout URL and the endpoint responds `503`/`deferred` rather than a fabricated URL
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: A bearer-subject-scoped initiation endpoint returns a checkout URL for an owned invoice (REQ-SPPI-002)

Shillinq MUST ship a `#[PublicPage]` + `#[NoCSRFRequired]` receiver at an
instance-local endpoint under `/apps/shillinq/api/portal/payments/` that
portaliq forwards the `pay` action to server-to-server. A `PortalAssertionVerifier`
MUST validate the inbound `X-Portal-Subject` header as portaliq's frozen A6
assertion BEFORE any other work: verify the HS256 signature against the
portaliq-managed shared signing secret; reject any token whose header `alg` is
not exactly `HS256` (defeating `none`/alg-confusion); require `iss = portaliq`,
`use = assertion`, and a present, unexpired `exp`; and require the frozen claim
set. A missing, malformed, wrongly-signed, expired or wrong-`use` assertion — or
an unconfigured shared secret — MUST fail closed with `401` before any
OpenRegister read or PSP call. On a valid assertion the endpoint MUST require
`audience = customer` (else `403`), resolve the target invoice / payment-request
from the client-supplied opaque id, mint or reuse a `PaymentRequest`, drive the
provider port, and relay `{ checkoutUrl }` as JSON, returning `502` on a
downstream/OpenRegister failure without leaking internals.

#### Scenario: An invalid assertion is rejected before any work

- GIVEN a POST to the portal payment initiation endpoint
- WHEN the `X-Portal-Subject` header is absent, has `alg` other than `HS256`, has `iss` other than `portaliq`, has `use` other than `assertion`, is expired, or its signature does not match the shared secret, or no shared secret is configured
- THEN the receiver responds `401` and performs no OpenRegister read, no PaymentRequest write and no PSP call
- @e2e exclude e2e added in apply phase - spec-only PR

#### Scenario: A verified owning subject receives a checkout URL

- GIVEN a valid `customer` assertion carrying the subject's `customerMasterId` scope claim, and a body `invoiceId` naming an open AR invoice whose `customerId` equals that `customerMasterId`
- WHEN the receiver processes the `pay` action
- THEN it mints (or reuses a pending) `PaymentRequest` for that invoice, drives the provider port with `method: 'ideal'`, persists the `paymentIntentId`, and relays `{ checkoutUrl }`
- AND a downstream/PSP/OpenRegister failure is relayed as `502` with no raw exception text
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: Ownership is server-derived and a non-owned invoice is unreachable (REQ-SPPI-003)

The receiver MUST derive the owning identity ONLY from the verified assertion's
`customerMasterId` scope claim, NEVER from the request body, query or
`Authorization` header. It MUST treat the client-supplied `invoiceId` /
`paymentRequestId` ONLY as an opaque OpenRegister object id, MUST reject any
value that is a full URL or contains a path/scheme/host (SSRF hardening), and
MUST NEVER use it to build an outbound request. Before minting a session the
receiver MUST resolve, via OpenRegister, the `ARInvoice` whose `id` equals the
target AND whose `customerId` equals the assertion-derived `customerMasterId`
AND whose `state` is a payable state (`issued`, `partially-paid` or `overdue`).
When no such invoice exists (foreign owner, non-payable/settled state, or a
non-existent id) the receiver MUST fail closed with a single uniform
not-authorised result, MUST NOT reveal whether the invoice exists (no existence
oracle), and MUST NOT call the PSP. A body-supplied `customerId` / `amount` /
`customerMasterId` MUST NEVER influence the resolution or the charged amount.

#### Scenario: A debtor cannot pay an invoice they do not own

- GIVEN a valid `customer` assertion resolving to `customerMasterId` M
- AND a body `invoiceId` whose AR invoice `customerId` is NOT M, or is in a settled/non-payable state, or does not exist
- WHEN the receiver processes the `pay` action
- THEN it fails closed with the identical not-authorised response for a foreign invoice, a non-payable invoice, and a non-existent id (no existence oracle)
- AND the PSP is never called and no `PaymentRequest` is written
- AND an `invoiceId` value that is a full URL or contains a path is rejected before any lookup
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: The charged amount is the server-side invoice amount (REQ-SPPI-004)

The amount and currency sent to the PSP MUST be read from the server-resolved
`ARInvoice` (or its linked `PaymentRequest`) — never from the request body. A
client that supplies an `amount` in the body MUST be charged the invoice's own
outstanding amount regardless. The minted `PaymentRequest.amount` MUST equal the
server-side outstanding amount.

#### Scenario: A client-supplied amount is ignored

- GIVEN a valid owning `customer` assertion for an open invoice with outstanding amount A
- AND a request body that also carries a smaller `amount`
- WHEN the receiver mints the payment session
- THEN the `PaymentRequest.amount` and the PSP payload amount are A (the server invoice amount), and the body `amount` is ignored entirely
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: The signature-verified webhook settles idempotently and writes a subject confirmation (REQ-SPPI-005)

On a captured payment the existing signature-verified, idempotent webhook path (`PaymentRequestWebhookController` and `PaymentReconciliationService`, `REQ-APL-004`) MUST settle the payment and write the subject a confirmation. It MUST transition the `PaymentRequest` to a captured/paid state, settle the linked `ARInvoice` (`state` becomes `paid`), and — added by this change — write a subject-safe
`confirmationSummary` onto the `PaymentRequest` that the debtor reads through the
existing read-only `paymentRequests` portal collection. The webhook MUST remain
idempotent (a replayed event is a no-op) and MUST NOT trust any amount supplied
by the caller: settlement reconciles the PSP event against the stored
`PaymentRequest`. The `confirmationSummary` MUST be added to the
`paymentRequests` collection field whitelist so the subject can see it.

#### Scenario: Settlement pays the invoice and shows the debtor a confirmation

- GIVEN a minted `PaymentRequest` for an owned invoice and a captured PSP webhook event with a valid signature
- WHEN the webhook reconciles the event
- THEN the `PaymentRequest` moves to captured/paid, the linked `ARInvoice.state` becomes `paid`, and a subject-safe `confirmationSummary` is written and exposed through the `paymentRequests` collection
- AND a replayed webhook event is an idempotent no-op that does not double-settle or overwrite the confirmation
- AND the settlement amount is reconciled from the stored `PaymentRequest`, never from a client-supplied amount
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: The customer manifest declares a pay action as a rowAction on open invoices (REQ-SPPI-006)

`OCA\Shillinq\Portal\PortalContributionProvider`'s `customer` manifest MUST
declare exactly one contract-v2 `endpoint-forward` action `pay`
(`{id, label, type: 'endpoint-forward', endpoint, method: 'POST', minTrust}`)
whose `endpoint` is an instance-local RELATIVE path under
`/apps/shillinq/api/portal/payments/` (leading slash, no scheme, no host, no
`..`). The manifest MUST reference `pay` as a `rowAction` on the open-invoice
rows of the `salesInvoices` and/or `paymentRequests` collections so portaliq
renders a per-row pay-now control (a settled/non-payable row MUST NOT offer it).
`minTrust` MUST track the AR surface. The `supplier` and `accountant` manifests'
`actions` MUST remain empty. The provider MUST stay a plain, dependency-free
class (no portaliq import, no `implements`, no constructor) — it only adds
pure-data action + rowAction declarations.

#### Scenario: The customer manifest carries the pay action and rowAction

- GIVEN a constructed `PortalContributionProvider` and a subject with `audience: 'customer'`
- WHEN `getContribution($subject)` is called
- THEN the returned manifest's `actions` contains exactly a `pay` action of type `endpoint-forward` with an instance-local relative `endpoint` under `/apps/shillinq/api/portal/payments/`, method `POST`, and a `minTrust` tracking the AR surface
- AND the `salesInvoices` / `paymentRequests` collections reference `pay` as a `rowAction` gated to open/payable rows
- AND the `supplier` and `accountant` manifests' `actions` stay empty
- @e2e exclude e2e added in apply phase - spec-only PR

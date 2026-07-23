# Design: portal-payment-initiation

## Context

hydra ADR-046 makes **portaliq** the ONE external portal for people without a
Nextcloud account. Contribution contract v2.2 defines **A6 endpoint
bearer-forward actions**: an app declares actions `{id, label, endpoint, method,
minTrust?}` (type `create` | `update` | `endpoint-forward`) on a manifest, and a
collection may reference an action as a `rowAction`; portaliq exposes
`POST /portal/api/actions/{appId}/{actionId}`, authorises against the SUBJECT's
own aggregated manifest, re-checks `minTrust`, then forwards the call
server-to-server to the declared instance-local endpoint, attaching a
short-lived HS256 `X-Portal-Subject` assertion and NEVER the client's
`Authorization` header, and relays the domain app's JSON status + body.

Shillinq's `customer` manifest (`portal-contribution`) already exposes the
`paymentRequests` collection (schema `PaymentRequest`, reached via a reverse
`via` join through `ARInvoice.customerId`, scoped by the
`claims.shillinq.customerMasterId` CustomerMaster object UUID) and a
`salesInvoices` collection (schema `ARInvoice`, scoped by `customerId`). Both are
read-only (`actions: []`). This change adds the write leg: an initiation action.

### Verified facts (HEAD, shillinq)

- **The Mollie provider exists and returns a checkout URL.**
  `MolliePaymentAdapterInterface::createPayment(array $payload): MolliePaymentResult`
  takes `amount{value,currency}, description, redirectUrl, webhookUrl,
  method (ideal|creditcard|bancontact|sepadirectdebit), metadata{invoiceId,
  administrationId, correlationId}` and returns a result carrying the Mollie
  `paymentId` + `checkoutUrl`. `isDormant()` reports whether it is the log-only
  `LogMolliePaymentAdapter` stub (activated by binding the openconnector
  `mollie-payments` source + API key in `Application::register()`).
- **The webhook exists and settles.** `PaymentRequestWebhookController::handle()`
  (route `POST /api/v1/payment-requests/webhook/{gateway}`, `#[PublicPage]` +
  `#[NoCSRFRequired]`) HMAC-verifies the raw body against
  `deposit_webhook_secret_{gateway}` (fail-closed when unset), decodes the
  Mollie/Stripe event, and hands off to
  `PaymentReconciliationService::reconcile()`. A Mollie `paid` maps to
  `OUTCOME_CAPTURED`; reconciliation is idempotent (`RESULT_NOOP` on replay) and
  `settleLinkedInvoice()` flips the linked AR invoice.
- **`PaymentRequest` schema** (`lib/Settings/register.d/ar-invoice-payment-links.json`)
  properties: `invoiceReference, amount, currency, paymentGateway,
  paymentIntentId, state, expiresAt, failureReason, settlementReference,
  gatewayFeeAmount, capturedAt, administrationId, paymentLink`.
- **`ARInvoice.state`** enum: `draft, issued, partially-paid, paid, overdue,
  disputed, written-off, voided`. "Open / payable" = `issued`, `partially-paid`,
  `overdue`. `ARInvoice.customerId` is the CustomerMaster object UUID
  (`format: uuid`, `$ref: CustomerMaster`).
- **No portal inbox/message schema** exists in shillinq (grep of `lib/Settings`
  for a customer-facing message/inbox collection is empty) — hence the
  confirmation is a summary on the `PaymentRequest`, not an inbox row.

## The initiation chain (server-derived, never client)

```
portaliq SPA (accountless debtor, trust per AR surface)
  → clicks "pay" on an open salesInvoices/paymentRequests row
  → POST /portal/api/actions/shillinq/pay   body: {invoiceId | paymentRequestId}
  → portaliq authorises against the subject's OWN manifest, re-checks minTrust
  → forwards to /apps/shillinq/api/portal/payments/initiate
        header  X-Portal-Subject: <HS256 assertion carrying customerMasterId scope>
        body    {invoiceId | paymentRequestId}          (client-supplied opaque id)
  → shillinq receiver:
        1. PortalAssertionVerifier: HS256 vs shared secret; alg=HS256 only;
           iss=portaliq; use=assertion; unexpired; frozen claim set → else 401
        2. audience==customer                                        → else 403
        3. customerMasterId := verified assertion scope claim (NEVER body) → else 403
        4. target := body.invoiceId (opaque id; reject URL/path → SSRF)
        5. invoice := OR find ARInvoice where id==target
                       AND customerId==customerMasterId
                       AND state in {issued,partially-paid,overdue} → else uniform 403/404
        6. paymentRequest := reuse pending PR for invoice OR mint one
                             (amount, currency read from the SERVER invoice)
        7. result := PaymentProviderInterface::createPayment(method='ideal', ...)
        8. persist paymentIntentId on the PR; return {checkoutUrl}; 502 on downstream fail
```

Steps 1–5 make ownership entirely server-derived. Step 5 is the anti-IDOR
boundary: knowing another debtor's invoice id buys nothing because the invoice's
`customerId` must equal the verified `customerMasterId`. Step 6 reads the amount
from the invoice, never the request body — a client can never dictate what it
pays (or pay a smaller amount than owed).

## Declarative vs imperative

- **Declarative (pure data):** the `pay` action declaration + the `rowAction`
  reference on the open-invoice rows. Like the rest of
  `PortalContributionProvider`, they are constants — no I/O — keeping the
  provider a plain, duck-typed, dependency-free class (ADR-046 A1).
- **Imperative (justified external-integration exception, ADR-031):** the
  `PortalPaymentInitiationController` + `PortalAssertionVerifier` +
  `PortalPaymentSessionService` verify a cryptographic assertion, query
  OpenRegister for the owned invoice, and call the PSP. A signed
  server-to-server assertion and a PSP call cannot be expressed declaratively;
  this is the A6 consumer half of a cross-app protocol, mirroring the existing
  webhook's documented single-method exception.

## The provider port

`PaymentProviderInterface` is a thin port —
`createSession(PaymentSessionRequest): PaymentSessionResult` and
`isDormant(): bool` — that the session service depends on. Its shipped binding
delegates to the existing `MolliePaymentAdapterInterface` (Mollie API key +
test-mode read from app config), so iDEAL is live once the openconnector
`mollie-payments` binding is configured and DORMANT (deferred outcome, no
checkout URL, honest 503/`deferred` to the portal) otherwise. The port keeps the
door open for additional PSPs without touching the receiver, but only the Mollie
iDEAL binding ships here.

## Confirmation without an inbox

Shillinq's customer portal has no message/inbox schema. On a captured webhook,
`PaymentReconciliationService` already stamps `PaymentRequest.state`,
`settlementReference` and `capturedAt`; this change adds a subject-safe
`confirmationSummary` string (e.g. "Invoice INV-2026-014 paid on 2026-07-23,
reference tr_xxx") written on settlement and adds it to the existing
`paymentRequests` collection field whitelist so the debtor sees a plain-language
receipt through the read-only collection they already have. No new schema, no
inbox, no register-version churn beyond the one added scalar.

## Security Considerations

- **Fail closed on every path** (ADR-005): `401` (missing/invalid/expired/
  wrong-`alg`/wrong-`iss`/wrong-`use` assertion, or no shared secret), `403`
  (wrong audience, not the owner, malformed target), `404`/`403` uniform where an
  oracle would leak, `502`/`503` (downstream/PSP failure or dormant provider). No
  path falls open to minting a session without a verified, owning assertion.
- **Amount integrity:** the amount is ALWAYS the server invoice amount; a
  body-supplied amount is ignored. The webhook independently never trusts
  client-supplied amounts (it reconciles the PSP event against the stored PR).
- **No cross-debtor IDOR:** the invoice's `customerId` must equal the verified
  `customerMasterId`; a body-supplied customer id is ignored.
- **Idempotent initiation:** a repeat `pay` on an invoice with a still-pending PR
  reuses that PR rather than minting duplicate sessions.
- **SSRF:** the receiver makes no outbound request to a client-controlled URL;
  the `invoiceId` is used only as an OR object id and is rejected if it looks
  like a URL/path.
- **alg-confusion / `none` defeated:** the verifier accepts ONLY `alg == HS256`.
- **No client secrets or endpoints** are introduced by the manifest — only a
  relative endpoint path and the action's `method`.

## Seed Data

This change adds NO new OpenRegister schema or register — it reuses `ARInvoice`
and `PaymentRequest` verified at HEAD, adding one subject-safe
`confirmationSummary` scalar to `PaymentRequest`. Unit tests construct the
controller/service directly (no container), build synthetic assertions on the
nil-UUID pattern so fixtures are self-evidently fake, and mock the
`PaymentProviderInterface` so no test contacts a real PSP.

## Open questions (apply-time confirmations)

1. **Scope-claim forwarding.** As with the docudesk portal-signing work, the
   frozen A6 assertion must carry (or the receiver must resolve) the subject's
   `customerMasterId` scope claim server-side; the receiver fails closed (`403`)
   when no owner-identifying claim is present, so shipping early is safe.
2. **Shared-secret sourcing.** Confirm the verifier reads portaliq's instance
   signing secret the same way the receiver-side apps do — an ops decision at
   apply.
3. **redirectUrl.** portaliq owns the return URL the debtor lands on after iDEAL;
   confirm the return-URL contract at apply (the endpoint accepts it from the
   forwarded assertion/config, never from the raw client body).

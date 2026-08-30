---
kind: code
depends_on: [portal-contribution, ar-invoice-payment-links]
---

# Proposal: portal-payment-initiation

## Why

Shillinq's Wave-2 customer portal (archived `customer-invoice-portal-wave2`,
canonical `portal-contribution` spec) lets a debtor SEE their own AR invoices
and a "Pay my invoices" `paymentRequests` collection through **portaliq**, the
shared external portal for people WITHOUT a Nextcloud account (hydra ADR-046,
contribution contract v2.2). What it does NOT do is let the debtor actually
**start a payment**: the `paymentRequests` collection surfaces a
pre-computed `paymentLink` field, but that link is minted by the internal AR
collection lifecycle, not by the subject. There is no subject-initiated,
ownership-scoped endpoint that turns "here is my open invoice" into "here is a
checkout URL I can pay right now". iDEAL is the must-have rail — 65 tenders in
the corpus demand iDEAL specifically — and the MKB debtor expects a one-click
pay-now button, not a link that may be stale or absent.

The payment plumbing already exists and is honest at HEAD, so this change is
deliberately thin and CONSUMES rather than rebuilds it (hence the `depends_on`):

- `MolliePaymentAdapterInterface::createPayment()`
  (`lib/Service/External/Mollie/MolliePaymentAdapterInterface.php`, verified at
  HEAD) already returns a structured `MolliePaymentResult` carrying the
  Mollie-side `paymentId` + `checkoutUrl`, accepts `method: 'ideal'`, and is
  dormant-by-default (`LogMolliePaymentAdapter`) until a live binding is wired.
- `PaymentRequestWebhookController`
  (`lib/Controller/PaymentRequestWebhookController.php`, route
  `POST /api/v1/payment-requests/webhook/{gateway}`, `REQ-APL-004`) is a
  `#[PublicPage]` + `#[NoCSRFRequired]` webhook that HMAC-verifies the PSP
  signature over the raw body, fail-closes when no secret is configured, is
  idempotent, and settles the `PaymentRequest` + its linked AR invoice through
  `PaymentReconciliationService`.

What is missing is the SUBJECT-FACING initiation half and the pay-now action
wiring. This change adds a bearer-subject-scoped, A6-endpoint-forward-compatible
initiation endpoint that creates a payment session for a row the subject
verifiably OWNS and returns its checkout URL; a `pay` endpoint-forward action on
the customer manifest, surfaced as a `rowAction` on the open-invoice rows; and a
subject-visible confirmation written on settlement. Without it the portal's
"Pay my invoices" collection is a read-only teaser — the debtor can look but not
pay.

## What Changes

- **A `PaymentProviderInterface` seam + iDEAL-first provider.** Formalise the
  provider port the initiation endpoint consumes. The existing
  `MolliePaymentAdapterInterface` (Mollie API key + test-mode via app config) IS
  the shipped iDEAL provider; this change binds the initiation endpoint to it (no
  fork). iDEAL is the required method for the Dutch MKB rail.
- **A bearer-subject-scoped initiation endpoint.** A new controller exposes a
  `#[PublicPage]` + `#[NoCSRFRequired]` receiver at
  `/api/portal/payments/initiate` that portaliq's A6 endpoint-forward calls
  server-to-server under the frozen `X-Portal-Subject` assertion. It resolves the
  subject's verified `customerMasterId` scope claim SERVER-SIDE, verifies the
  target AR invoice / payment request belongs to that CustomerMaster, mints (or
  reuses a pending) `PaymentRequest`, drives `createPayment()` with the
  server-side invoice amount, and returns `{ checkoutUrl }`.
- **Confirmation on settlement.** `PaymentReconciliationService`, on a captured
  webhook, writes a subject-safe confirmation summary the debtor sees through the
  existing read-only `paymentRequests` collection (Shillinq has no portal
  inbox/message schema — see Out of Scope), in addition to flipping the invoice
  to `paid`.
- **Portal contribution gains a `pay` action + rowAction.** Extend
  `lib/Portal/PortalContributionProvider.php` (still plain, dependency-free) so
  the `customer` manifest declares a contract-v2 `endpoint-forward` action `pay`
  and references it as a `rowAction` on the open-invoice rows of the
  `salesInvoices` / `paymentRequests` collections; `minTrust` tracks the AR
  surface. The `supplier` / `accountant` manifests stay read-only.
- **Refund path is a named follow-up, out of scope here** (see Out of Scope).

## Capabilities

### Added Capabilities

- `portal-payment-initiation`: an accountless debtor pays their own open AR
  invoice from portaliq's SPA — a fail-closed, ownership-scoped initiation
  endpoint mints an iDEAL payment session through the existing Mollie provider,
  returns the checkout URL as an A6 endpoint-forward action surfaced as a
  pay-now rowAction, and the existing signature-verified webhook settles it and
  writes the debtor a confirmation.

## Affected Projects

- [x] Project: `shillinq` — extend `lib/Portal/PortalContributionProvider.php` (`pay` action + rowAction); new `lib/Controller/PortalPaymentInitiationController.php` + `lib/Portal/PortalAssertionVerifier.php`; a `PaymentProviderInterface` port bound to the existing `MolliePaymentAdapterInterface`; a session-creation service (`PortalPaymentSessionService`); a confirmation write on `PaymentReconciliationService`; route in `appinfo/routes.php`; unit tests under `tests/unit/Portal/`; this OpenSpec change.
- Consumes (verified at HEAD, not re-implemented): `MolliePaymentAdapterInterface::createPayment()`, `PaymentRequestWebhookController` (`REQ-APL-004`), `PaymentReconciliationService`.
- Contract: `apps-extra/portaliq` — the A6 "Endpoint bearer-forward actions" + "Frozen assertion wire format" requirements this receiver is templated against; runtime consumer that forwards the action.
- Reference: `hydra` ADR-046 (portaliq external portal, contribution contract v2.2, A6).
- Depends on: `portal-contribution` (customer manifest, `customerMasterId` scope), `ar-invoice-payment-links` (Mollie provider + webhook + reconciliation).

## Out of Scope

- Any portal UI, session, auth edge or rendering — portaliq owns the entire
  external surface (ADR-046); Shillinq ships zero portal frontend, only the
  receiver + action declaration.
- Any change to portaliq itself; this receiver is templated against portaliq's
  FROZEN assertion wire format and its A6 forward.
- **Refunds / partial reversals.** A `refund` action (portal-initiated or
  operator-initiated) and its reconciliation are a deliberately deferred
  follow-up — the settle path here is capture-only.
- A dedicated portal inbox/message schema. Shillinq's customer portal has no
  message collection (unlike decidesk/procest); the settlement confirmation is
  delivered as a subject-safe summary on the existing `paymentRequests` row, not
  a new inbox surface.
- SEPA direct-debit / recurring mandates, card-on-file, and non-Mollie PSPs
  (the port allows them; only the Mollie iDEAL binding ships).

## Success Criteria

- `openspec validate portal-payment-initiation --strict` exits 0.
- The initiation endpoint returns a checkout URL ONLY for a row whose
  server-resolved owner matches the verified assertion's `customerMasterId`; a
  foreign, non-existent, or non-owned target yields the identical fail-closed
  result (no existence oracle) and never calls the PSP.
- The payment amount sent to the PSP is read from the server-side invoice /
  payment-request, never from the client body.
- The provider is driven with `method: 'ideal'`; a dormant provider returns a
  deferred outcome without contacting Mollie and the endpoint degrades honestly.
- The webhook settlement (existing `REQ-APL-004` path) additionally writes a
  subject-visible confirmation and the debtor reads it through the existing
  `paymentRequests` collection.
- The `customer` manifest declares exactly one `pay` `endpoint-forward` action
  with an instance-local relative endpoint, referenced as a rowAction on open
  invoices; `supplier` / `accountant` manifests keep empty `actions`.
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the unit suite pass
  on the new files with zero new violations.

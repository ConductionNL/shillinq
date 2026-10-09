# Design: receivables-payment-links

Read at shillinq development `79f438f33`, integriq development and portaliq
development on 2026-09-27.

## Context

**The request.** `PaymentRequest` (`lib/Settings/register.d/ar-invoice-payment-links.json:11`,
version 0.4.0) carries `invoiceReference` or a `subject`, `amount`,
`paymentGateway`, `paymentIntentId`, `paymentLink`, `state` (`pending` to
`captured`), `settledAt` and a `settlements` list. Requests are raised by the
leges intake (`PaymentRequestActionController::raiseLeges()`, :303), the case
leaf's `create` (`lib/Integration/PaymentRequestLeafProvider.php`), the school
contribution raise, and the portal pay flow. `PaymentRequestActionController::send()`
(:102-150) mails `paymentLink` and writes `linkSentAt`, a property the schema
does not declare. Nothing writes `paymentLink`: the matrix is right.

**The provider port.** `lib/AppInfo/Application.php:497` aliases
`MolliePaymentAdapterInterface` to `LogMolliePaymentAdapter`, and
`PaymentProviderInterface` is a `MolliePaymentProvider` over whatever that
alias resolves to. The comment above the binding says the port "turns live
automatically the moment the openconnector `mollie-payments` source is bound,
no further change needed here" (paraphrased; the source uses a dash). That is
not so: the alias is static and nothing rebinds it. `lib/Settings/connections.json`
lists `mollie` as `reportedOnly`.

**The portal pay flow.** portaliq's `onAction()` forwards `pay`
(portaliq `src/portal/App.jsx:225-238`, `lib/Service/PortalActionForwarder.php:89`)
to `portalPaymentInitiation#initiate` (`appinfo/routes.php:772`), which runs
`PortalPaymentSessionService::initiate()` (`lib/Service/Payment/PortalPaymentSessionService.php:180`).
It mints or reuses a pending request and calls `$this->provider->createSession()`
(:219); with the log adapter the session is dormant and the portal is told
"deferred" (:234). The checkout URL, when there is one, is returned to the
portal and never stored.

**Settlement.** `PaymentReconciliationService::reconcile(gateway, event)`
(`lib/Service/PaymentReconciliationService.php:249`) takes
`{paymentIntentId, outcome, settlementReference, ...}`, finds the request (or
deposit) by `paymentIntentId`, flips its state idempotently, settles the linked
`ARInvoice` to `paid`, and books an object request's receipt against the
clearing and revenue accounts. Its callers are shillinq's own public webhook
`PaymentRequestWebhookController` (`/api/v1/payment-requests/webhook/{gateway}`,
`appinfo/routes.php:740`) and the polling fallback.

**integriq.** `PaymentIntentService::createPayment()` (integriq
`lib/Service/PaymentIntentService.php:145`) resolves the payment source,
creates the provider payment, persists a `payment_intent` and returns
`paymentIntentId`, `providerPaymentId`, `checkoutUrl` and `dormant`; it is
reachable only as `POST /api/payments` with a Nextcloud session (integriq
`appinfo/routes.php:157`, whose comment calls it the "production binding for
shillinq's MolliePaymentAdapterInterface::createPayment, a follow-up"). Its
verified webhook emits a CloudEvent `nl.conduction.payment.status` through
`emitStatusEvent()` (:418) with data `{paymentIntentId: <provider payment id>,
outcome, errorCode, errorMessage, settlementReference, gatewayFeeAmount}`, the
exact input `reconcile()` reads. No shillinq code listens for it.

**The invoice document.** `InvoicePdfGenerator::renderHtml()`
(`lib/Service/InvoicePdfGenerator.php:287`) prints parties, lines, VAT and
`paymentTerms`, and no link.

## Goals / Non-Goals

**Goals**

- Every open payment request and invoice has a working iDEAL or card link when a live provider is configured in integriq.
- A payment made through that link marks the request captured and the invoice paid, without anyone pressing settle.
- The link on a PDF or in a mail works for as long as the invoice is open.

**Non-Goals**

- A second provider rail next to integriq.
- Refunds, and retiring shillinq's own webhook.

## Decisions

### D1. Create the payment through an integriq command event

`IntegriqPaymentAdapter` implements `MolliePaymentAdapterInterface`. It
`class_exists()`-guards integriq's payment command event, dispatches it with
provenance (`sourceApp` shillinq, the request as subject, amount, currency,
description, redirect URL, metadata with the request id) and reads
`providerPaymentId`, `paymentIntentId` and `checkoutUrl` from the result slot.
Absent integriq, an unhandled event or a log-provider source make it answer
dormant with the reason. It is bound at `Application.php:497`, and the
misleading comment goes.

Alternatives considered: call `POST /apps/integriq/api/payments` over HTTP
(rejected: the portal forward and a background job carry no Nextcloud session,
and ADR-041 names server-side HTTP between apps as not the pattern); resolve
integriq's `PaymentIntentService` from the container (rejected: cross-container
service resolution, which ADR-041 forbids).

### D2. The request stores what reconciliation needs

On creation the request stores `paymentIntentId` = the provider payment id
(the key integriq's status event carries), `providerPaymentId` (same value,
named), `paymentLink` = the checkout URL and `checkoutExpiresAt`. A request is
given its link when raised by leges, the case leaf and the contribution raise,
and when the portal pay flow mints one; `PortalPaymentSessionService` persists
the checkout URL it receives.

### D3. Hear integriq's payment status

A listener on OpenRegister's `ObjectCreatedEvent` resolves the object's
register and schema to slugs, and for `type = nl.conduction.payment.status` in
integriq's `event` schema calls `PaymentReconciliationService::reconcile('mollie',
data)`. When `sales-einvoice-exchange` has added its `IntegriqCloudEventListener`,
this is one more type branch there rather than a second listener.

### D4. A stable, signed pay URL on the document

`PayLinkService::forInvoice(invoice)` returns a URL to portaliq's guest pay
page with a token signed over the invoice id and the administration (HMAC with
the instance secret, no expiry before the invoice is paid or written off). The
invoice PDF prints it under the totals as "Betaal online" with the URL, and the
invoice mail of `sales-invoice-sending` carries it. When followed, portaliq
forwards `pay` with the token; `PortalPaymentSessionService` verifies it,
reuses a pending request whose checkout has not expired or creates a new one
through D1, and redirects to the checkout. A paid invoice answers "This invoice
has been paid".

Alternative considered: print the provider's checkout URL. Rejected: a
checkout expires long before a 30-day invoice is due.

### D5. Say so when there is no live provider

With a dormant adapter a request gets no link and shows "Online payment is not
set up: configure a payment source in integriq". The Send payment link action
is disabled with that reason, the PDF prints no link, and the portal pay action
answers deferred as today.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Creating the provider payment | Imperative, `IntegriqPaymentAdapter` | A cross-app command with a result, per ADR-041. |
| Recording the outcome | Imperative, the existing `PaymentReconciliationService` behind a listener | The reconciliation and its idempotency exist; the gap is the channel. |
| Request state changes and their notification | Declarative, unchanged: `x-openregister-lifecycle` and `x-openregister-notifications` on `PaymentRequest` | Already declared by `ar-invoice-payment-links` and `case-payment-requests`. |
| The signed pay URL | Imperative, `PayLinkService` | Signing and verification are code. |

## Seed Data

No schema is added. `PaymentRequest` gains `providerPaymentId`,
`checkoutExpiresAt` and a declared `linkSentAt`.

Seed objects for the administration "Gemeente Voorbeeld" (leges) and
"Adviesbureau Kade B.V." (invoice):

- A leges request "Omgevingsvergunning kappen, 1 boom" EUR 97.50 for case Z-2026-00188, provider payment id tr_example0002, state captured, settled via provider.
- An invoice 2026-0412 of EUR 302.50 with a signed pay link and a pending request whose checkout expired, to show a fresh checkout being opened.

## Risks / Trade-offs

- [integriq has not shipped the command event] → the adapter stays dormant and says so; nothing regresses from today.
- [The signed URL leaks] → it pays one invoice of one administration and shows only the amount, number and supplier; paying someone else's invoice harms nobody, and the token can be rotated per administration.
- [Two capture routes (integriq's status and shillinq's own webhook) report the same payment] → `reconcile()` is idempotent on the request state.

## Migration Plan

No data migration. Open requests without a link get one the next time they are
sent or paid. Rollback is rebinding the log adapter.

## Open Questions

- Where portaliq serves the guest page for a signed link (a route of its own, or a generic signed-subject page) is portaliq's call.

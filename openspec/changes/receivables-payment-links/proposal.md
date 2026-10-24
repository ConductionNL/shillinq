---
kind: code
depends_on: [case-payment-requests]
---

# Proposal: receivables-payment-links

## Summary

Shillinq keeps payment requests, mails their link, lets a portal user press
Pay now, and settles a request when a provider reports it paid. None of it
moves money, because no code ever creates a payment link: the payment port is
bound to a log-only Mollie adapter, and the status integriq reports is never
heard. This change creates the link through integriq's live payment
providers, records paid automatically from integriq's status events, and
prints a pay link on the invoice and in its mail that keeps working until the
invoice is paid.

## Motivation

Three rows share one missing half, the provider behind the request. The
OpenSpec pass of 2026-09-27 decided `build` for all three
(`openspec/parity/gap-decisions.json`).

**`sal-pay-link`** (shillinq matrix), "Put a pay now link for iDEAL or card on
the invoice." Rated partial, built. The matrix evidence: "PaymentRequest page
(src/manifest.d/ar-invoice-payment-links.json) and
lib/Controller/PaymentRequestActionController.php:102-150 mails an existing
paymentLink; nothing in lib writes paymentLink (grep "'paymentLink' =>" only
reads it, lib/Integration/PaymentRequestLeafProvider.php:222); Mollie is bound
to LogMolliePaymentAdapter (lib/AppInfo/Application.php:497) and
connections.json marks mollie reportedOnly; the invoice PDF
(lib/Service/InvoicePdfGenerator.php renderHtml) carries no link". Note: "no
provider creates the iDEAL/card link and it is not printed on the invoice." No
demand row. Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Met Exact Online voeg je eenvoudig en kosteloos een betaallink toe aan je verkoopfacturen en de begeleidende email".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207092-directe-betaallink-in-de-e-mail-van-de-factuur, the email links to the online invoice with a pay button; https://www.moneybird.nl/prijzen/ lists iDEAL at EUR 0,29.
- snelstart: https://www.snelstart.nl/ondernemer/instap, "Maak het je klanten makkelijk met een betaallink van Mollie. Direct op je factuur in SnelStart".
- odoo: odoo/odoo@19.0 `addons/account_payment/models/account_move.py:177` `_get_portal_payment_link`; iDEAL at `addons/payment/data/payment_method_data.xml:1404`.

**`rec-payment-request`** (shillinq matrix), "Send a payment request for a case
or intake and see when it is paid." Rated partial, built. The matrix evidence:
"PaymentRequests page ... and the case leaf
src/integrations/ShillinqPaymentRequestsPanel.vue call
/api/payment-requests/{id}/send and /settle ...; raiseLeges creates the request
at intake; paid state comes from manual settle or the provider webhook, but no
code creates the payment link (Mollie bound to LogMolliePaymentAdapter,
lib/AppInfo/Application.php:497)". Note: "online payment and automatic paid
status need a real provider adapter that is not bound." No demand row. One
competitor rates it yes:

- moneybird: https://helpcenter.moneybird.nl/nl/articles/286722-betaalverzoek-versturen, "hoe je in plaats van een factuur ook een betaalverzoek naar je klant kunt sturen".

**`cmp-cas-pay`** (portaliq matrix, `openspec/parity/capabilities.json` in
ConductionNL/portaliq), "Pay an invoice, fee or tax assessment from the
portal." Rated partial, built. Its built evidence: "portaliq's generic
endpoint-action forwarder is the whole mechanism: src/portal/App.jsx:225-238
onAction() ... shillinq supplies the target: shillinq appinfo/routes.php:764
portalPaymentInitiation#initiate ...; shillinq's Mollie adapter is
LogMolliePaymentAdapter by default". Note: "Whether a real payment happens
depends entirely on shillinq's Mollie adapter, which logs instead of charging
by default." The decision names the same code path as `sal-pay-link`. One
competitor rates it yes:

- nl-portal: `frontend/packages/user-interface/src/components/Task.tsx:61` OGONEBETALING task starts payment; `backend/payment-direct/src/main/kotlin/nl/nlportal/payment/direct/api/DirectPaymentController.kt:32`.

This change covers all three rows. The provider stays integriq's
`live-payment-providers`, as `case-payment-requests` keeps "Mollie through
integriq" as the one rail; shillinq's half is asking integriq for the link,
hearing the outcome, and printing the link.

## Affected Projects

- [ ] Project: `shillinq`: an integriq-backed payment adapter bound in place of the log-only one, a listener for integriq's payment status, a stable signed pay link on the invoice PDF and mail, and the request pages showing it.
- [ ] Project: `integriq`: a typed ADR-041 command event for creating a payment. No code in this repo.
- [ ] Project: `portaliq`: renders the guest pay page a signed link opens. No code in this repo.

## Scope

### In Scope

- Creating the provider payment when a request is raised or a pay action starts, and storing the checkout link and the provider payment id on the `PaymentRequest`.
- Binding the adapter so the portal pay action, the case panel and the payment request page all use it.
- Recording paid, failed and voided from integriq's `nl.conduction.payment.status` CloudEvents through the existing `PaymentReconciliationService`.
- A stable, signed pay link per open invoice, printed on the invoice PDF and put in the invoice mail, which on use opens a fresh checkout for the amount still open.
- A clear answer, not a fake link, when no live provider is configured.

### Out of Scope

- Payment providers other than those integriq's `live-payment-providers` offers.
- Retiring shillinq's own gateway webhook (`PaymentRequestWebhookController`); it stays until the integriq path is proven, then ADR-091 retires it in its own change.
- A QR code on the invoice (`sales-invoice-document` in this OpenSpec pass covers `sal-qr`).
- Refunds.

## Approach

`IntegriqPaymentAdapter` implements the existing `MolliePaymentAdapterInterface`
by dispatching integriq's typed payment command (ADR-041) and reading the
checkout link and provider payment id from its result slot; it is bound in
`Application::register()` in place of `LogMolliePaymentAdapter`, and reports
dormant when integriq is absent, the event is unhandled or integriq's source
uses the log provider. A listener on OpenRegister's `ObjectCreatedEvent` takes
integriq's payment status CloudEvents, whose data envelope is already
shillinq's reconciliation input, and calls `PaymentReconciliationService::reconcile()`.
A `PayLinkService` signs a per-invoice pay URL for the PDF and the mail.
Details are in design.md.

## New Dependencies

None.

## Impact

- Code: new `IntegriqPaymentAdapter`, `IntegriqPaymentStatusListener` (or a branch of the CloudEvent listener `sales-einvoice-exchange` adds), `PayLinkService`; the binding in `lib/AppInfo/Application.php`; `InvoicePdfGenerator::renderHtml()` and the invoice mail print the link.
- Schemas: `PaymentRequest` gains `providerPaymentId`, `checkoutExpiresAt` and declares the `linkSentAt` the send action already writes (additive).
- API: `POST /api/portal/payments/initiate` also accepts a signed pay link forwarded by portaliq.

## Cross-Project Dependencies

- integriq `live-payment-providers`: needs a typed command event (for example `PaymentRequestedEvent` in `OCA\Integriq\Event`) whose listener calls `PaymentIntentService::createPayment()` (integriq `lib/Service/PaymentIntentService.php:145`) and writes `paymentIntentId`, `providerPaymentId` and `checkoutUrl` into the result slot. Today creation is only `POST /apps/integriq/api/payments` behind a Nextcloud session (integriq `appinfo/routes.php:157`), which a portal forward or a background job does not have.
- integriq: `PaymentIntentService::emitStatusEvent()` (:418) already emits the data envelope shillinq's reconciliation reads (`paymentIntentId`, `outcome`, `settlementReference`, `errorMessage`); no change.
- portaliq: a guest page that forwards the `pay` action for a signed link, the same guest mechanism `sales-cancellation` asks portaliq for.
- `case-payment-requests` (this repo) says shillinq "consumes its REST surface"; for creation this change uses the typed event instead, for the session reason above.

## Risks

### Risk 1: A link that no longer works on an invoice already sent
**Severity:** High. **Mitigation:** the printed link is shillinq's signed pay URL, not the provider's checkout URL, which expires within hours; opening it creates or reuses a live checkout for what is still open, and after payment it says the invoice is paid.

### Risk 2: A paid invoice is not recognised
**Severity:** High. **Mitigation:** the provider payment id integriq's status carries is stored on the request at creation, and reconciliation matches on it; a capture that finds no request is logged with the id for a bookkeeper to match.

### Risk 3: A test setup charges real money
**Severity:** Medium. **Mitigation:** integriq's source decides live or test; the request records whether the adapter answered from a live source, and the page shows "test payment" for anything else.

## Rollback Strategy

Rebind `LogMolliePaymentAdapter`. Requests and invoices keep their links and
states; new requests fall back to no link, which is today's behaviour.

## Open Questions

- Should the printed link use the provider's own long-lived payment-link object instead of shillinq's signed URL? That ties invoices to one provider's feature; this change keeps the provider behind the signed URL.

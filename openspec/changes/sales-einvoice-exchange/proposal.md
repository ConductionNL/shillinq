---
kind: code
depends_on: []
---

# Proposal: sales-einvoice-exchange

## Summary

Send e-invoice builds a correct UBL invoice and marks it queued, and then
nothing leaves the instance: shillinq announces the invoice on a Nextcloud
event name integriq does not listen to, stores no payload, and listens for
delivery status on another name integriq does not emit. This change moves
shillinq's half of the Peppol exchange onto the channel integriq uses, both
ways: the outbound request, the delivery status (including a customer's
rejection, which then reaches the bookkeeper), and an inbound self-billed
invoice that a customer made on the seller's behalf, which becomes a sales
invoice to review.

## Motivation

Three rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) rest on one
hand-off. The OpenSpec pass of 2026-09-27 decided `build` for all three
(`openspec/parity/gap-decisions.json`).

**`sal-peppol-send`**, "Send a sales invoice as a UBL e-invoice over Peppol."
Rated partial, built. The matrix evidence: "Send e-invoice button ... runs
lib/Service/EInvoice/EInvoiceService.php: UBL via ArInvoiceUblMapper, local
port LogPeppolTransmissionAdapter (log-only), then
IEventDispatcher::dispatch('nl.conduction.peppol.outbound.requested') at :270.
integriq's PeppolOutboundConsumer ... listens only to OpenRegister
ObjectCreatedEvent for an object in register `integriq` schema event ... so the
named NC event shillinq dispatches never reaches it." Note: "The UBL is built
and the invoice goes to 'queued', but the hand-off to integriq uses a
different event channel than integriq listens on, so nothing is transmitted."
No demand row. Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Met Exact Online is het mogelijk om elektronische verkoopfacturen te verzenden via het Peppol-netwerk".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/227719-facturen-versturen-via-peppol, "Via Peppol stuur je een factuur ... rechtstreeks naar het boekhoudprogramma van je klant".
- snelstart: https://www.snelstart.nl/productnieuws/e-facturen-sturen-aan-de-overheid, "E-facturatie verloopt via het beveiligde PEPPOL-netwerk".
- odoo: odoo/odoo@19.0 `addons/account_peppol/__manifest__.py:5` "send/receive documents with PEPPOL", countries include `nl`.

**`sal-einvoice-rejection`**, "Be told when a customer rejects an e-invoice
you sent." Rated partial, built. The matrix evidence: "PeppolDeliveryStatusListener.php:85
turns a rejected delivery status into a notification and applies the declared
delivery transitions; the status events come from integriq". Note: "The
listener is wired; whether rejections ever arrive depends on the integriq
Peppol path." Demand: changelog
https://support.exactonline.com/community/s/article/All-All-HNO-Content-rn-whatsnewlandingpage?language=en_GB.
Two competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Content-rn-whatsnewlandingpage?language=en_GB (May 2026), "Now receive email notifications for rejected sales invoices sent via Peppol".
- odoo: odoo/odoo@19.0 `addons/account_peppol_response/models/account_edi_proxy_user.py:134` logs "The Peppol receiver of this document has rejected it" with the reason.

**`sal-self-billing`**, "Receive self-billed invoices that a customer made on
your behalf and book them as sales." Rated partial, built. The matrix
evidence: "ARInvoice carries selfBilled and selfBillingMention, and
lib/Standards/Checks/InvoiceMentionsTailChecks.php:65 requires the
self-billing mention when the flag is set; there is no import of an invoice
the customer made." Note: "A sales invoice can be marked self-billed;
receiving the customer-made invoice is not supported." Demand: changelog
https://support.exactonline.com/community/s/article/All-All-HNO-Content-rn-whatsnewlandingpage?language=en_GB.
One competitor rates it yes:

- exact-online: same changelog (December 2025), "Easily receive self-billed invoices from your customers".

Reading the code showed that two of these claims are weaker than the matrix
says; design.md has the detail. The rejection notification has no notifier to
render it, and `ARInvoice` declares neither `selfBilled` nor
`selfBillingMention`: only the check reads them.

This change covers all three rows. Peppol transport stays integriq's
(ADR-091); shillinq's half is the hand-off on integriq's channel, taking the
delivery status back, and turning a received self-billed invoice into a sales
invoice.

## Affected Projects

- [ ] Project: `shillinq`: the outbound hand-off, a stored payload, two listeners on integriq's CloudEvent channel, a notifier, the self-billing fields and the review of a received self-billed invoice.

## Scope

### In Scope

- Publish the outbound request as the CloudEvent object integriq's `PeppolOutboundConsumer` matches, with a payload reference that resolves to a stored file.
- Stop calling the log-only local transmission port on the invoice path, so `queued` means handed over.
- Take `nl.conduction.peppol.delivery.status` CloudEvents from integriq's event register and apply them to the invoice.
- Render the rejection notification (a registered notifier) and show rejected e-invoices on `AccountsReceivable`.
- Refuse the send with a clear message when integriq is not installed, and offer email instead.
- A self-billing agreement per customer, and `selfBilled` and `selfBillingMention` on `ARInvoice`.
- Take `nl.conduction.peppol.inbound.received` CloudEvents: a self-billed invoice from a customer with an agreement becomes a draft `ARInvoice` to review; every other inbound invoice goes to the existing supplier invoice intake.

### Out of Scope

- The access point, participant lookup and transport retries. integriq owns them.
- Answering a received self-billed invoice with a Peppol invoice response. See Open Questions.
- Credit notes over Peppol.
- The purchase-order Peppol path (`purchaseOrder#transmitPeppol`), which keeps using its port.

## Approach

`EInvoiceService` writes one `event` object into integriq's register through
OpenRegister's `ObjectService`, with the type and data envelope
`PeppolTransmissionService::handleOutboundRequested()` reads, after storing the
hybrid PDF in the administration's Nextcloud Files. The invoice goes to
`queued` only when that write succeeded. One new listener on OpenRegister's
`ObjectCreatedEvent` takes integriq's CloudEvents by type and register and
hands delivery status to the existing `PeppolDeliveryStatusListener` logic and
inbound documents to a new `SelfBilledInvoiceIntake`. Details are in
design.md.

## New Dependencies

None.

## Impact

- Schemas: `ARInvoice` gains `selfBilled`, `selfBillingMention` and a `selfBilling` group; `CustomerMaster` gains `selfBillingAgreement` (all additive).
- Code: `EInvoiceService` (hand-off and storage), a new `IntegriqCloudEventListener`, `SelfBilledInvoiceIntake`, `EInvoiceNotifier`; `PeppolDeliveryStatusListener` keeps its transition table and loses its named-event registration.
- Manifest: a rejected quick filter on `AccountsReceivable`, a review action on `ARInvoiceDetail` for a received self-billed invoice.

## Cross-Project Dependencies

- integriq `peppol-access-point-connector`: `PeppolOutboundConsumer` (integriq `lib/Service/PeppolOutboundConsumer.php`, registered at integriq `lib/AppInfo/Application.php:268`) compares `$object->getRegister()` with the slug `integriq` and `getSchema()` with `event`. OpenRegister stamps numeric ids onto the entity (shillinq's own `lib/Service/ListenerSchemaResolver.php` documents this), so the consumer may never match any event, integriq's own included. If the live check in task 1.3 confirms that, integriq resolves the slug before comparing. No other integriq change is needed for the outbound or status half.
- integriq: the inbound CloudEvent carries a `payloadReference`. Shillinq reads it when it names a Nextcloud file the instance can read. If integriq's reference is anything else, integriq needs to store the inbound UBL where the receiving app can read it.

## Risks

### Risk 1: An invoice shows queued while nothing was handed over
**Severity:** High. **Mitigation:** `queued` is written after the event object is saved; a failed write leaves `not-sent` with the reason. The log-only local port is no longer called on this path.

### Risk 2: A self-billed invoice is booked that the seller never agreed to
**Severity:** High. **Mitigation:** only a customer with a current `selfBillingAgreement` produces an invoice, it lands in `draft` for review, and nothing posts to the ledger until the bookkeeper issues it.

### Risk 3: The self-billed invoice's number collides with the own sequence
**Severity:** Medium. **Mitigation:** the customer's number is kept in `selfBilling.buyerInvoiceNumber` and the invoice is excluded from the own-sequence rule of `sales-invoice-issue-controls` (same OpenSpec pass), which has to know about the flag.

## Rollback Strategy

Revert the PR. The schema changes are additive. Invoices handed over stay
`queued` or carry whatever status integriq reported; self-billed invoices
already reviewed stay ordinary issued invoices.

## Open Questions

- Should rejecting a received self-billed invoice send a Peppol invoice response back to the customer? That needs integriq to send the response document; this change records the rejection locally only.
- The fleet end state for a command to integriq is a typed ADR-041 event such as integriq's `DeliveryRequestedEvent`. integriq routes that event to subscriptions, not to its Peppol service, so this change uses the channel the Peppol consumer reads today.

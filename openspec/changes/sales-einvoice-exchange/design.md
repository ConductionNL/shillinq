# Design: sales-einvoice-exchange

Read at shillinq development `79f438f33` and integriq development on
2026-09-27 (integriq read through `gh api ...?ref=development`).

## Context

**Outbound, shillinq side.** The Send e-invoice button on `ARInvoiceDetail`
(`src/components/ar-invoice/AREInvoiceActions.vue`, helpers in
`arEInvoiceActions.js`) calls `POST /api/ar-invoices/{invoiceNumber}/send-einvoice`
(`appinfo/routes.php:283`), which runs `EInvoiceService::sendEInvoice()`
(`lib/Service/EInvoice/EInvoiceService.php:133`). It validates, builds the
NLCIUS UBL (`ArInvoiceUblMapper::toNlciusXml()`), builds the hybrid PDF, then:

1. `storeArtefact()` (:309) hashes the PDF, logs it and returns
   `docudesk://file/<hash>`. The bytes are discarded; the URI names nothing.
2. `$this->peppolPort->submit()` (:186) calls `PeppolTransmissionPortInterface`,
   which `lib/AppInfo/Application.php:451` binds to
   `LogPeppolTransmissionAdapter`. It returns a made-up transmission id.
3. The invoice is saved with `deliveryStatus = queued` and that id.
4. `emitOutboundRequested()` (:252) dispatches a `GenericEvent` under the name
   `nl.conduction.peppol.outbound.requested` (:270).

**Outbound, integriq side.** `PeppolOutboundConsumer` (integriq
`lib/Service/PeppolOutboundConsumer.php`) is registered for OpenRegister's
`ObjectCreatedEvent` only (integriq `lib/AppInfo/Application.php:268`). It
reacts to an object whose register is `integriq`, whose schema is `event` and
whose `type` is `nl.conduction.peppol.outbound.requested`, and passes
`data` to `PeppolTransmissionService::handleOutboundRequested()` (integriq
`lib/Service/PeppolTransmissionService.php:187`), which needs `objectUri`,
`recipientPeppolId`, `documentType`, and uses `sourceApp` and
`payloadFileUri`. The named event shillinq dispatches is never seen. The
matrix is right on this point.

**Status and inbound, integriq side.** integriq emits every CloudEvent through
`EventService::emitCloudEvent()` (integriq `lib/Service/EventService.php:2584`),
which saves an object in register `integriq`, schema `event`. Delivery status
has type `nl.conduction.peppol.delivery.status` with `data` `{objectUri,
transmissionId, status, timestamp, detail}` (PeppolTransmissionService
:95, :493). Inbound documents have type `nl.conduction.peppol.inbound.received`
with `data` `{senderPeppolId, documentType, payloadReference}` (:102, :390).

**Status, shillinq side.** `PeppolDeliveryStatusListener`
(`lib/Listener/PeppolDeliveryStatusListener.php`) is registered against the
event NAME `nl.conduction.peppol.delivery.status` as a `GenericEvent`
(`lib/AppInfo/Application.php:340-350`). integriq never dispatches that name,
so the listener has never run. Its body is sound: an allowed-transition table,
a save of `deliveryStatus` and `deliveryDetail`, and on `rejected` a
notification to every `ar-controller` member with subject
`einvoice_delivery_rejected` (:85). **Matrix correction:** no `INotifier` in
shillinq renders that subject (only `DeadlineReminderNotifier` and
`PosStockUnmatchedLineNotifier` are registered, `Application.php:752,760`), so
Nextcloud discards the notification at display time even when a rejection
arrives. The row's "reached on: Nextcloud notification after Send e-invoice"
does not hold.

**Inbound, shillinq side.** `PeppolInboundUblInvoiceListener`
(`lib/Listener/PeppolInboundUblInvoiceListener.php`, registered at
`Application.php:294-297`) waits for a `PeppolInboundMessage` object "published
by openconnector". integriq writes no such schema, so received supplier
invoices do not arrive either. The UBL parser it feeds,
`SupplierInvoiceService::parseUblInvoice()` (`lib/Service/SupplierInvoiceService.php:572`),
is reusable.

**Self-billing.** **Matrix correction:** `ARInvoice` declares neither
`selfBilled` nor `selfBillingMention`. A search of `lib/Settings/` finds
neither key; only `lib/Standards/Checks/InvoiceMentionsTailChecks.php:65`
(rule `vatdir-art226-10a`) reads them. Nothing records an agreement with a
customer to self-bill.

**The id trap.** OpenRegister stamps numeric register and schema ids onto an
`ObjectEntity`, not slugs; `lib/Service/ListenerSchemaResolver.php` exists in
shillinq because every slug comparison in its listeners failed for that
reason. integriq's consumer compares against slugs, so its match needs a live
check (task 1.3).

## Goals / Non-Goals

**Goals**

- Send e-invoice results in an integriq transmission, and `queued` means it was handed over.
- The invoice shows sent, delivered, failed or rejected as integriq reports it, and an `ar-controller` sees a rejection with its reason.
- A self-billed invoice a customer sends over Peppol becomes a sales invoice after review, only for a customer with an agreement.

**Non-Goals**

- Anything inside integriq's transmission lifecycle.
- A second outbound document type.

## Decisions

### D1. Publish on integriq's CloudEvent register, not a Nextcloud event name

`EInvoiceService` saves one object through OpenRegister's `ObjectService` in
register `integriq`, schema `event`, with `type`
`nl.conduction.peppol.outbound.requested`, `source` `/apps/shillinq/ar-invoices`,
`subject` the invoice uuid and `data` `{sourceApp, objectType, objectUri,
recipientPeppolId, documentType, payloadFileUri, administrationId}`, the same
envelope it builds today. If integriq is not installed or the write fails, the
send answers "Peppol sending is not available on this instance" and offers
email (`sales-invoice-sending` REQ-SIS-002).

Alternatives considered: keep dispatching the Nextcloud event and ask integriq
to listen to it (rejected: integriq already has a consumer, and a second
channel is how this broke); integriq's ADR-041 `DeliveryRequestedEvent`
(preferred end state, rejected for now because integriq routes it to
subscriptions, not to `PeppolTransmissionService`).

### D2. The payload is a stored file, and the local port leaves the invoice path

`storeArtefact()` writes the hybrid PDF and the UBL XML into the
administration's Nextcloud Files folder (`Shillinq/<administration>/e-invoices/<year>/`)
and returns the stored XML's Files path as the reference, which is the
document integriq transmits. `sendEInvoice()` no longer calls `PeppolTransmissionPortInterface`;
`transmissionId` is written when integriq's status carries it. The port stays
bound for the purchase-order path.

Alternative considered: store through docudesk. Rejected for this change:
storage is not generation (ADR-075), and Files is readable by integriq on the
same instance without a new contract.

### D3. One listener takes integriq's CloudEvents by type

`IntegriqCloudEventListener` listens to `ObjectCreatedEvent`, resolves the
object's register and schema to slugs (the `ListenerSchemaResolver` approach,
widened to integriq's register), and dispatches on `type`:
`nl.conduction.peppol.delivery.status` to the existing transition logic of
`PeppolDeliveryStatusListener` (moved into a service the listener calls), and
`nl.conduction.peppol.inbound.received` to `SelfBilledInvoiceIntake`. The
named-event registration at `Application.php:340-350` is removed.

### D4. A notifier renders the rejection, and the list shows it

`EInvoiceNotifier` implements `INotifier` for subject
`einvoice_delivery_rejected`, links to `ARInvoiceDetail`, and is registered in
`Application::register()`. Nextcloud mails notifications to users who enabled
that, which covers the Exact evidence. `AccountsReceivable` gains a quick
filter "Rejected e-invoices" on `deliveryStatus = rejected`.

### D5. Self-billing needs an agreement, and lands as a draft

`CustomerMaster.selfBillingAgreement` holds `agreedOn`, `validUntil` and an
optional `documentUri` (the signed agreement), as article 224 of the VAT
Directive requires one. `SelfBilledInvoiceIntake` reads the payload, and when
the UBL's `CustomizationID` is the Peppol self-billing customization
(`urn:fdc:peppol.eu:2017:poacc:selfbilling:3.0`) or its `InvoiceTypeCode` is
389, it:

1. finds the administration whose VAT id or Peppol id is the seller party;
2. finds the `CustomerMaster` whose Peppol id, VAT id or KvK number is the buyer party;
3. refuses, with a visible `SelfBilledInvoiceRefusal` record, when either is missing or the agreement is absent or expired;
4. writes an `ARInvoice` in `draft` with `selfBilled: true`, `selfBillingMention` "Factuur uitgereikt door afnemer", the lines and VAT breakdown from the UBL, and `selfBilling` `{buyerInvoiceNumber, receivedAt, payloadFileUri, senderPeppolId}`; idempotent on sender and buyer invoice number.

The bookkeeper reviews it on `ARInvoiceDetail` and issues it, which books it
like any sales invoice, or rejects it with a reason. Every other inbound
invoice is passed to `SupplierInvoiceService::ingestUBLInvoice()`, the path
`PeppolInboundUblInvoiceListener` was meant to feed.

Alternative considered: book the self-billed invoice straight to `issued`.
Rejected: the seller is liable for VAT on a document someone else wrote, and
each invoice has to be checked against what was delivered.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Outbound hand-off | Imperative, `EInvoiceService` | Building UBL and storing files is code; the hand-off is one object write. |
| Delivery status transitions | Imperative, existing transition table in `PeppolDeliveryStatusListener` logic | A cross-app event mapped onto a sub-state; the table stays as it is. |
| Rejection notification | Imperative raise (unchanged) plus a registered `INotifier` | The raise exists; the gap is rendering. |
| Self-billed intake | Imperative, `SelfBilledInvoiceIntake` | Parsing a received document and matching parties has no declarative form. |
| Review of a self-billed invoice | Declarative: the existing `ARInvoice` lifecycle (`issue` from `draft`) plus a guard `requireSelfBillingAgreement` on `issue` when `selfBilled` is true | The state machine exists; the rule is a guard on it. |

## Seed Data

`ARInvoice` gains `selfBilled` (boolean, default false), `selfBillingMention`
(string) and `selfBilling` (object). `CustomerMaster` gains
`selfBillingAgreement` (object). `SelfBilledInvoiceRefusal` is added with
`receivedAt`, `senderPeppolId`, `buyerInvoiceNumber`, `reason`,
`payloadFileUri`, `administrationId`.

Seed objects for the administration "Akkerbouwbedrijf Jansen":

- `CustomerMaster` "Aardappelverwerking De Kuil B.V.", VAT id NL000099998B57, `selfBillingAgreement` agreed on 2026-01-05, valid until 2026-12-31.
- `ARInvoice` in draft, `selfBilled` true, buyer invoice number SB-2026-00042, one line "Levering consumptieaardappelen week 38, 30.000 kg" EUR 6,000 at 9 percent VAT, total EUR 6,540.
- `SelfBilledInvoiceRefusal` for a document from an unknown buyer, reason "No customer with this VAT id".

## Risks / Trade-offs

- [integriq's consumer never matches because of the id trap] → task 1.3 proves the path live; the fix is integriq's and is named in the proposal.
- [Writing into another app's register] → the write is the documented input of integriq's consumer and carries shillinq as `sourceApp`; it is one object per send and no other integriq data is touched.
- [A payload reference shillinq cannot read] → the intake records a refusal with the reference, so nothing disappears.

## Migration Plan

No data migration. Invoices that sit at `queued` from the old path were never
handed over; a one-off `occ shillinq:einvoice:requeue` lists them and, when
confirmed, sends them again through the new path. Rollback is reverting the PR.

## Open Questions

- Answering a rejected self-billed invoice through a Peppol invoice response needs integriq; out of scope here.

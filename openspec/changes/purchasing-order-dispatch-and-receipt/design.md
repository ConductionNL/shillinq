# Design: purchasing-order-dispatch-and-receipt

Read at shillinq development `79f438f33` and integriq development on
2026-09-27.

## Context

**The order.** `PurchaseOrder` is declared in
`lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json`
with lifecycle field `statusCode` (draft, approved, sent, partial_received,
fully_received, invoiced, closed, cancelled) and transition `send` from
approved to sent, plus `peppolSentAt`, `peppolMessageId` and
`peppolFallbackReason`. `PurchaseOrderForm.vue:371` creates it through
`POST /api/purchase-orders`; `PurchaseOrderDetail.vue:367` and `:374` call
`POST /api/purchase-orders/{id}/transmit/peppol` and `/transmit/email`
(`appinfo/routes.php:276` and `:277`). Pages: `PurchaseOrders` and
`PurchaseOrderDetail` (`src/manifest.json:13313`, `:13364`).

**A matrix-adjacent defect.** `PurchaseOrderService` writes a field
`lifecycleState` (line 338 on create with draft or `pending_approval`, and
lines 447, 535 and 585 with sent) that the schema does not declare, while
the declared lifecycle field `statusCode` is never moved by the service.
`pending_approval` is not a declared state. `PurchaseOrderDetail.vue` reads
`lifecycleState` (lines 41, 218, 228, 294). So the declared `send`
transition, and any guard or notification on it, never runs.

**Dispatch.** `sendToPeppol()` (`lib/Service/PurchaseOrderService.php:483`)
calls `lookupParticipant()` and `submitOrder()` on
`PeppolTransmissionAdapterInterface`, which the constructor defaults to
`LogPeppolTransmissionAdapter` (`lib/Service/Peppol/LogPeppolTransmissionAdapter.php`);
`lib/AppInfo/Application.php:451` binds the shared
`PeppolTransmissionPortInterface` to the same log adapter. With no
participant, or on a throw, it falls back to `sendToPDFEmail()` (line 565),
which calls `LogPurchaseOrderMailer::sendPurchaseOrderEmail()` and then
sets sent regardless.

**integriq's side.** `peppol-access-point-connector` on integriq
development exposes `GET /api/peppol/participants/{peppolId}` returning
`{exists, supportedDocTypes}` (REQ-001, "the production binding for
shillinq's PeppolTransmissionAdapterInterface::lookupParticipant"),
consumes `nl.conduction.peppol.outbound.requested` with
`{sourceApp, objectType, objectUri, recipientPeppolId, documentType,
payloadFileUri}` idempotently per `objectUri` and `documentType` (REQ-003),
and emits `nl.conduction.peppol.delivery.status`. The email channel is
integriq's `Service\CallService`, which `lib/Service/ConfirmationMailer.php`
(line 148) already resolves through `FleetAppId::getService()`.

**Service receipts.** `SvcReceipt` and `SvcReceiptLine`
(`register.d/bookkeeping-purchase-order-3way-12-service-receipt.json`,
lifecycle field `statusCode`: draft, confirmed, accepted, rejected) are
driven by `ServiceReceiptService` through `POST /api/service-receipts`,
`/{id}/lines`, `/{id}/confirm` and `/{id}/accept` (`appinfo/routes.php:307`
to `:310`). A line is confirmed by percentage complete, quantity or amount,
and the service derives `quantityReceived` so `ThreeWayMatchingEngine`
scores it like a goods line. No manifest page and no Vue component reaches
these endpoints. The purchasing menu (`src/manifest.json:1075` onwards) has
Purchase Orders, Goods Receipts and Supplier Invoices.

## Goals / Non-Goals

**Goals**

- An order the user sends reaches the supplier, by Peppol when the supplier is a participant and by email otherwise, and the page says which and whether it arrived.
- The order's state lives in its declared lifecycle.
- A budget holder confirms a delivered service, fully or in part, from a screen.

**Non-Goals**

- Running a Peppol access point or a mail server.
- Changing the three-way match.

## Decisions

### D1. Peppol through integriq's event, not a synchronous call

`IntegriqPeppolOrderAdapter::lookupParticipant()` calls integriq's
participant endpoint for the supplier's Peppol id and returns it when
`exists` is true and the Order document type is supported.
`submitOrder()` stores the UBL Order under
`/Shillinq/PurchaseOrders/<administrationId>/` and emits
`nl.conduction.peppol.outbound.requested` with `documentType` Order and the
file URI, and returns the object URI as the message reference. The order
gets `dispatchChannel` peppol and `dispatchStatus` pending.
`PeppolDeliveryStatusListener` moves it to sent through the `send`
transition when the status is sent, or records `dispatchError` and offers
email when it failed.

Alternative considered: call integriq's provider synchronously and mark
sent on return. Rejected: integriq's contract is event-driven with retry
and dead-lettering, and a synchronous call would bypass both.

### D2. Email through integriq's channel, sent only when accepted

`IntegriqPurchaseOrderMailer` renders the order PDF, builds the payload
`ConfirmationMailer` builds (recipient, subject, body, attachment), and
hands it to integriq's `CallService`. The order moves to sent only when the
hand-off returns true. When integriq is not installed the button is shown
disabled with "Email sending is not configured", instead of logging and
claiming success.

Alternative considered: Nextcloud's `IMailer` directly. Rejected: the
fleet's outbound mail, its log and its sender identity are integriq's, and
shillinq already sends its booking confirmations that way.

### D3. One state field

The service writes `statusCode` and requests transitions (`approve`,
`send`) instead of `lifecycleState`, and the detail component reads
`statusCode`. How an order reaches approved is the subject of
`purchasing-approval-delegation`, which moves the approval chain onto
OpenRegister; this change only stops writing `lifecycleState`. A repair
step copies an existing `lifecycleState` of sent into `statusCode` for
orders where the declared field lags.

Alternative considered: declare `lifecycleState` as a second field.
Rejected: two state fields for one object is how this drifted.

### D4. Service receipts get declarative pages plus one form

`ServiceReceipts` (index on `SvcReceipt`) and `ServiceReceiptDetail`
(detail with lines and Confirm, Accept and Reject actions calling the
existing endpoints) are manifest pages. `ServiceReceiptForm` is a custom
page, like `GoodsReceiptNoteForm`, because a line takes one of three
confirmation modes. A menu entry "Service receipts" sits after Goods
Receipts, and `PurchaseOrderDetail` offers "Confirm service" when the order
has service lines.

Alternative considered: fold service lines into the goods receipt form.
Rejected: services have no quantity check or stock move, and the service
already models them apart.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Order state | Declarative `statusCode` lifecycle, transitions requested by the service | The lifecycle exists and was bypassed. |
| Handing the order to integriq | Imperative adapters behind the existing ports | External transport is integriq's; the ports already exist. |
| Delivery status | Imperative listener that requests the declared `send` transition | An event from another app has no declarative consumer. |
| Service receipt lists and details | Declarative manifest pages | Plain schemas and existing endpoints. |
| Service receipt entry | Custom page | Three confirmation modes per line. |

## Seed Data

Three fields are added to `PurchaseOrder`. Seed for "Gemeente Voorbeeld":
order PO-2026-031 to "Drukkerij Van der Meer B.V." (Peppol id
0106:12345678) for 5,000 folders; order PO-2026-032 to "Adviesbureau
Groen" (no Peppol id, email inkoop@example.nl) for 40 hours of advice, a
service line; a service receipt for PO-2026-032 confirming 25 of 40 hours
in September.

## Risks / Trade-offs

- [Existing orders read `lifecycleState`] → the repair step aligns them before the component switches fields.
- [integriq not installed] → both send buttons say why they are unavailable; nothing is marked sent.

## Migration Plan

A repair step copies `lifecycleState` sent into `statusCode` where
`statusCode` is still approved. No other data migration.

## Open Questions

None.

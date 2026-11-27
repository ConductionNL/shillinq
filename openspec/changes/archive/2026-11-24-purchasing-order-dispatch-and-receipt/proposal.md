---
kind: code
depends_on: []
---

# Proposal: purchasing-order-dispatch-and-receipt

## Summary

A purchase order can be raised and marked sent, but nothing reaches the
supplier: both the Peppol and the PDF-and-email path are bound to log-only
adapters, and the order is marked sent anyway. A service delivered against
an order can be confirmed only through the API. This change hands the order
to integriq's Peppol connector and email channel and marks it sent only when
integriq says it left, puts the order's state on its declared lifecycle, and
gives service receipts a screen.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the purchase order screen. The
OpenSpec pass of 2026-09-27 decided `build` for both. This change covers
both.

**`pur-po`**, "Raise a purchase order and send it to the supplier." Rated
partial, built. The matrix evidence:
"src/components/purchase-order/PurchaseOrderForm.vue:371 POSTs
/api/purchase-orders (PurchaseOrderController.php:101);
PurchaseOrderDetail.vue:367/374 call transmit/peppol and transmit/email,
but Application.php:451 binds LogPeppolTransmissionAdapter and
PurchaseOrderService defaults to LogPurchaseOrderMailer". The note:
"Raising the PO works; 'send to supplier' marks it sent but both Peppol and
PDF+email are log-only adapters, nothing reaches the supplier." No demand
row. Three competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-psa-purchase-psapurch-crtpurorderst?language=en_GB, "Go to Purchase > Orders > Purchase orders > Create".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/een-bestelling-maken-en-boeken-in-snelstart-12, "Deze bestelling kun je daarna rechtstreeks naar je leverancier mailen".
- odoo: odoo/odoo@19.0 `addons/purchase/models/purchase_order.py:545` `action_rfq_send` mails the RFQ/PO to the vendor.

**`pur-receipt`**, "Record goods or services received against an order,
including partial deliveries." Rated partial, built. The matrix evidence
ends: "service receipts (/api/service-receipts*, ServiceReceiptService.php)
have no page". The note: "Goods including partial deliveries: yes;
services: API only, no screen reaches it." No demand row. Three competitors
rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-wholesale-wd-crtgoodsrcptt?language=en_GB, "If you only received part of your order, the status will change to Partial".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/een-bestelling-maken-en-boeken-in-snelstart-12, "Het ontvangst van de bestelling boeken ... (backorder)".
- odoo: odoo/odoo@19.0 `addons/purchase_stock/models/purchase_order_line.py:14` `qty_received` per line, "services via manual qty_received".

## Affected Projects

- [ ] Project: `shillinq`: the purchase order service and its two adapters, two listeners for integriq events, the order detail component, and three service receipt pages.

## Scope

### In Scope

- Peppol: looking up the supplier through integriq's participant endpoint and handing the UBL Order to integriq as a `nl.conduction.peppol.outbound.requested` event.
- Email: rendering the order PDF and handing it to integriq's email channel, the same channel `ConfirmationMailer` uses.
- Marking an order sent only on integriq's confirmation, and showing a failure with the reason.
- Driving the order's state through its declared `statusCode` lifecycle.
- Service receipt index, detail and entry pages, reachable from the purchasing menu and from an order with service lines.

### Out of Scope

- The Peppol access point, the SMP lookup and mail delivery. integriq (`peppol-access-point-connector`, ADR-091).
- Order approval routing and delegation. That is `purchasing-approval-delegation`.
- Goods receipts, which already work with partial deliveries.

## Approach

Replace the two log-only bindings with adapters that call integriq, keep
the log adapters for development, and make the service stop claiming a
delivery that has not happened. The service receipt pages use the existing
endpoints. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/PurchaseOrderService.php`: `sendToPeppol()`, `sendToPDFEmail()` and creation write `statusCode` through the declared transitions.
- `lib/Service/PurchaseOrder/IntegriqPeppolOrderAdapter.php` and `IntegriqPurchaseOrderMailer.php` (new); bindings in `lib/AppInfo/Application.php`.
- `lib/Listener/PeppolDeliveryStatusListener.php` (new).
- `lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json`: `dispatchChannel`, `dispatchStatus`, `dispatchError` on `PurchaseOrder`.
- `src/components/purchase-order/PurchaseOrderDetail.vue` and new manifest pages `ServiceReceipts`, `ServiceReceiptDetail`, `ServiceReceiptForm`.

## Cross-Project Dependencies

- integriq `peppol-access-point-connector` (spec on integriq development): `GET /api/peppol/participants/{peppolId}` (REQ-001), the `nl.conduction.peppol.outbound.requested` consumer (REQ-003) and the `nl.conduction.peppol.delivery.status` event. Consumed as specified; no change needed.
- integriq email channel: `Service\CallService` resolved through `FleetAppId::getService($container, 'integriq', 'Service\CallService')`, as `lib/Service/ConfirmationMailer.php` already does. If integriq's outbound mail work (`outbound-communication-log`) replaces that entry point, this adapter follows it.

## Risks

### Risk 1: Orders that showed sent were never received
**Severity:** Medium. **Mitigation:** the release note says so; orders sent before this change keep their state, and the order page shows the dispatch as "recorded before delivery was confirmed" when no delivery status exists.

### Risk 2: An order is transmitted twice
**Severity:** Medium. **Mitigation:** the event carries the order's object URI and document type, on which integriq's consumer is idempotent (REQ-003), and the send buttons are hidden while a dispatch is pending.

## Rollback Strategy

Rebind the two interfaces to the log adapters. Orders then record the
attempt without delivery, as today. The service receipt pages can stay.

## Open Questions

None.

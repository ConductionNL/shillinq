# Tasks: purchasing-order-dispatch-and-receipt

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. State

- [x] 1.1 Make `PurchaseOrderService` write `statusCode` through the declared transitions, switch `PurchaseOrderDetail.vue` to `statusCode`, and add a repair step for orders whose `lifecycleState` is sent (REQ-PODR-003). Verify: PHPUnit on create and send; the repair step run twice on a local instance changes the seed order once.
  - Done: `PurchaseOrderService` creates at `statusCode` draft and every send (`blockSendUntilApproved()`, the email path, the Peppol delivery status) now goes through the declared `send` transition; nothing writes `lifecycleState`. `PurchaseOrderDetail.vue` reads `statusCode`. Repair step `AlignPurchaseOrderStatusCode` (in `info.xml`). PHPUnit: `PurchaseOrderServiceTest`, `PurchaseOrderIntegrationTest` (send asks the lifecycle for `send`), `AlignPurchaseOrderStatusCodeTest`. Not done here: the repair step run twice on a live instance.

## 2. Peppol

- [x] 2.1 Add `dispatchChannel`, `dispatchStatus` and `dispatchError` to `PurchaseOrder` (REQ-PODR-001, REQ-PODR-002). Verify: `npm run check:registers` and a re-import with no failed schemas.
- [x] 2.2 Add `IntegriqPeppolOrderAdapter` (participant lookup, file store, outbound event) and bind `PeppolTransmissionAdapterInterface` to it when integriq is installed (REQ-PODR-001). Verify: PHPUnit with a fake integriq endpoint for participant, non-participant and error.
- [x] 2.3 Add `PeppolDeliveryStatusListener` for `nl.conduction.peppol.delivery.status` requesting `send` or recording the failure (REQ-PODR-001). Verify: PHPUnit with the real event payload shape for sent and failed.

## 3. Email

- [x] 3.1 Add `IntegriqPurchaseOrderMailer` (PDF, payload, hand-off through the integriq email channel) and bind `PurchaseOrderMailerInterface` to it (REQ-PODR-002). Verify: PHPUnit for accepted, refused and integriq absent.
- [x] 3.2 Show the dispatch channel, status and error on `PurchaseOrderDetail.vue`, and disable a channel that is unavailable with its reason (REQ-PODR-001, REQ-PODR-002). Verify: Playwright on the two seed orders with integriq's log providers.
  - Done: `GET /api/purchase-orders/dispatch-channels` (`PurchaseOrderService::dispatchChannels()`: Peppol when a real adapter is bound, email when the mailer has a channel, each with its reason). `PurchaseOrderDetail.vue` shows dispatch channel, status and error, and disables an unavailable channel with its reason (button title and a hint line). Tests: `PurchaseOrderDispatchChannelsTest`, two `PurchaseOrderControllerTest` cases, `tests/vitest/purchaseOrderDetailDispatch.spec.js`; en/nl strings. Not done here: the Playwright run on the seed orders.

## 4. Service receipts

- [x] 4.1 Add manifest pages `ServiceReceipts` and `ServiceReceiptDetail` with Confirm, Accept and Reject actions on the existing endpoints, and the "Service receipts" menu entry (REQ-PODR-004). Verify: nav reachability gate passes; Playwright opens the seed receipt.
  - Done: `src/manifest.d/purchasing-order-dispatch-and-receipt.json`: "Service receipts" after Goods Receipts in the purchasing menu, index `ServiceReceipts` on `SvcReceipt` opening detail `ServiceReceiptDetail` (fields, lines as a related collection, audit trail). Confirm and Accept are `api-call` header actions on `/api/service-receipts/{id}/confirm` and `/accept`; Reject is the declared `reject` transition, which now asks for a required `rejectionReason` (new `SvcReceipt` property). en/nl strings. `check:manifest`, `check:manifest-shell`, `check:registers` pass. Not done here: the nav reachability gate and Playwright on the seed receipt.
- [ ] 4.2 Add the custom page `ServiceReceiptForm` with the three confirmation modes and the "Confirm service" action on `PurchaseOrderDetail` for orders with service lines (REQ-PODR-004). Verify: Playwright confirms 25 of 40 hours, accepts the receipt and sees the order partially received.

## 5. End to end

- [ ] 5.1 Live check against integriq's log Peppol provider: send PO-2026-031, see pending, then sent after the status event (REQ-PODR-001). Verify: the event ids and the order state are pasted in the PR body.

## 6. Docs

- [ ] 6.1 Release note: orders marked sent before this change were not delivered, and what now needs integriq. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-N` on every new method, Dutch and English strings for every label and message.

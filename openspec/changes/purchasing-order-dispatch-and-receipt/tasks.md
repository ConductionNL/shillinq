# Tasks: purchasing-order-dispatch-and-receipt

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. State

- [ ] 1.1 Make `PurchaseOrderService` write `statusCode` through the declared transitions, switch `PurchaseOrderDetail.vue` to `statusCode`, and add a repair step for orders whose `lifecycleState` is sent (REQ-PODR-003). Verify: PHPUnit on create and send; the repair step run twice on a local instance changes the seed order once.

## 2. Peppol

- [ ] 2.1 Add `dispatchChannel`, `dispatchStatus` and `dispatchError` to `PurchaseOrder` (REQ-PODR-001, REQ-PODR-002). Verify: `npm run check:registers` and a re-import with no failed schemas.
- [ ] 2.2 Add `IntegriqPeppolOrderAdapter` (participant lookup, file store, outbound event) and bind `PeppolTransmissionAdapterInterface` to it when integriq is installed (REQ-PODR-001). Verify: PHPUnit with a fake integriq endpoint for participant, non-participant and error.
- [ ] 2.3 Add `PeppolDeliveryStatusListener` for `nl.conduction.peppol.delivery.status` requesting `send` or recording the failure (REQ-PODR-001). Verify: PHPUnit with the real event payload shape for sent and failed.

## 3. Email

- [ ] 3.1 Add `IntegriqPurchaseOrderMailer` (PDF, payload, hand-off through the integriq email channel) and bind `PurchaseOrderMailerInterface` to it (REQ-PODR-002). Verify: PHPUnit for accepted, refused and integriq absent.
- [ ] 3.2 Show the dispatch channel, status and error on `PurchaseOrderDetail.vue`, and disable a channel that is unavailable with its reason (REQ-PODR-001, REQ-PODR-002). Verify: Playwright on the two seed orders with integriq's log providers.

## 4. Service receipts

- [ ] 4.1 Add manifest pages `ServiceReceipts` and `ServiceReceiptDetail` with Confirm, Accept and Reject actions on the existing endpoints, and the "Service receipts" menu entry (REQ-PODR-004). Verify: nav reachability gate passes; Playwright opens the seed receipt.
- [ ] 4.2 Add the custom page `ServiceReceiptForm` with the three confirmation modes and the "Confirm service" action on `PurchaseOrderDetail` for orders with service lines (REQ-PODR-004). Verify: Playwright confirms 25 of 40 hours, accepts the receipt and sees the order partially received.

## 5. End to end

- [ ] 5.1 Live check against integriq's log Peppol provider: send PO-2026-031, see pending, then sent after the status event (REQ-PODR-001). Verify: the event ids and the order state are pasted in the PR body.

## 6. Docs

- [ ] 6.1 Release note: orders marked sent before this change were not delivered, and what now needs integriq. Verify: the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-N` on every new method, Dutch and English strings for every label and message.

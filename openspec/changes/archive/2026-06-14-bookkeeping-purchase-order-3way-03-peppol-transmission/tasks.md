# Tasks — Member 03: Peppol Transmission (code)

## Transmission service methods

- [x] Implement `PurchaseOrderService::sendToPeppol()` — transform PO → UBL Order, submit to openconnector Peppol Access Point, record peppol_message_id + peppol_sent_at
- [x] Implement `PurchaseOrderService::sendToPDFEmail()` — fallback if supplier not Peppol-registered; log peppol_fallback_reason
- [x] Enforce the approval-complete precondition before any transmission (reuse member 02 send-block guard)
- [x] Transition PO lifecycle to `sent` on successful transmission (either path)

## Vue wiring

- [x] Add "Send to Peppol / PDF+email" button to `PurchaseOrderForm.vue` and reflect transmission status

  Implementation note: the form (`PurchaseOrderForm.vue`) is the *create*
  surface — the persisted PO does not exist yet, so the actual transmission
  control lives on `PurchaseOrderDetail.vue`. The form gained a notice
  pointing the operator at the detail view; the detail view ships paired
  `Send via Peppol` / `Send via PDF+email` buttons, a Transmission section
  that reflects `peppolMessageId` / `peppolSentAt` / `peppolFallbackReason`,
  and a guard that disables the buttons until every approver has signed.

## Tests

- [x] Write integration test: PO creation → Peppol transmission → verify peppol_message_id recorded
- [x] Write integration test: non-Peppol supplier → PDF+email fallback → verify peppol_fallback_reason recorded

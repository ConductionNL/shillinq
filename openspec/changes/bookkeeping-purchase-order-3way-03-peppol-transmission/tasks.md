# Tasks — Member 03: Peppol Transmission (code)

## Transmission service methods

- [ ] Implement `PurchaseOrderService::sendToPeppol()` — transform PO → UBL Order, submit to openconnector Peppol Access Point, record peppol_message_id + peppol_sent_at
- [ ] Implement `PurchaseOrderService::sendToPDFEmail()` — fallback if supplier not Peppol-registered; log peppol_fallback_reason
- [ ] Enforce the approval-complete precondition before any transmission (reuse member 02 send-block guard)
- [ ] Transition PO lifecycle to `sent` on successful transmission (either path)

## Vue wiring

- [ ] Add "Send to Peppol / PDF+email" button to `PurchaseOrderForm.vue` and reflect transmission status

## Tests

- [ ] Write integration test: PO creation → Peppol transmission → verify peppol_message_id recorded
- [ ] Write integration test: non-Peppol supplier → PDF+email fallback → verify peppol_fallback_reason recorded

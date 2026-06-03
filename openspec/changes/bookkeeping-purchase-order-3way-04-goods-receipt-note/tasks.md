# Tasks — Member 04: Goods Receipt Note (code)

## GoodsReceiptNoteService

- [ ] Implement `createGRN()` — accepts po_ids[], received_at, received_by, carrier, delivery_note_ref
- [ ] Implement `addGRNLine()` — po_line_id, quantity_received/accepted/rejected, rejection_reason, batch_ref
- [ ] Implement `qualityCheckPass()` — transition GRN to "quality_checked"
- [ ] Implement `acceptGRN()` — finalize GRN, update PO status, fire GR/IR posting trigger (logic in member 09)
- [ ] Implement `uploadPhotos()` — store delivery-condition photos via docudesk

## Inventory integration

- [ ] On GRN accept: credit inventory for quantity_accepted at PO-line gl_account
- [ ] On GRN reject: do NOT decrement inventory for quantity_rejected

## Vue views

- [ ] Create `GoodsReceiptNoteForm.vue` (mobile-optimised): PO selection (single/multi-PO), per-line qty entry, rejection-reason picker, carrier + delivery-note entry, photo upload + preview
- [ ] Create `GoodsReceiptNoteDetail.vue`: header + line-item table (received/accepted/rejected), photo gallery, link to related ThreeWayMatches

## Tests

- [ ] Write unit tests for GRN line allocation (partial receipt, multi-PO matching)
- [ ] Write integration test: GRN creation → stock mutation (accepted credited, rejected not)

# Tasks — Member 04: Goods Receipt Note (code)

## GoodsReceiptNoteService

- [x] Implement `createGRN()` — accepts po_ids[], received_at, received_by, carrier, delivery_note_ref
- [x] Implement `addGRNLine()` — po_line_id, quantity_received/accepted/rejected, rejection_reason, batch_ref
- [x] Implement `qualityCheckPass()` — transition GRN to "quality_checked"
- [x] Implement `acceptGRN()` — finalize GRN, update PO status, fire GR/IR posting trigger (logic in member 09)
- [x] Implement `uploadPhotos()` — store delivery-condition photos via docudesk

## Inventory integration

- [x] On GRN accept: credit inventory for quantity_accepted at PO-line gl_account
- [x] On GRN reject: do NOT decrement inventory for quantity_rejected

## Vue views

- [x] Create `GoodsReceiptNoteForm.vue` (mobile-optimised): PO selection (single/multi-PO), per-line qty entry, rejection-reason picker, carrier + delivery-note entry, photo upload + preview
- [x] Create `GoodsReceiptNoteDetail.vue`: header + line-item table (received/accepted/rejected), photo gallery, link to related ThreeWayMatches

## Tests

- [x] Write unit tests for GRN line allocation (partial receipt, multi-PO matching)
- [x] Write integration test: GRN creation → stock mutation (accepted credited, rejected not)

---
kind: code
depends_on: [bookkeeping-purchase-order-3way-03-peppol-transmission]
chain:
  - bookkeeping-purchase-order-3way-01-schemas-and-registers
  - bookkeeping-purchase-order-3way-02-purchase-order-core
  - bookkeeping-purchase-order-3way-03-peppol-transmission
  - bookkeeping-purchase-order-3way-04-goods-receipt-note
  - bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion
  - bookkeeping-purchase-order-3way-06-matching-engine
  - bookkeeping-purchase-order-3way-07-multi-po-consolidation
  - bookkeeping-purchase-order-3way-08-exception-workflow
  - bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing
  - bookkeeping-purchase-order-3way-10-vendor-performance
  - bookkeeping-purchase-order-3way-11-audit-trail-export
---

# Proposal: bookkeeping-purchase-order-3way-04-goods-receipt-note

Member 4 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-03-peppol-transmission`.
This `kind: code` member implements the **Goods Receipt Note** capture —
the warehouse-side source of truth for what was physically received —
consuming the `GoodsReceiptNote` / `GoodsReceiptLine` registers from
member 01 and reading PO lines from member 02.

## Why (carried from the giant)

REQ-PO3W-003: a magazijn-medewerker receiving 180 of 200 ordered chairs
must record line-level quantities (received / accepted / rejected),
rejection reasons, carrier + delivery note, and delivery photos via a
mobile interface. Receiving partial shipments updates PO status to
partial_received and mutates inventory for accepted quantities — the
middle leg of the 3-way match.

## What this member does

- `GoodsReceiptNoteService`: `createGRN()`, `addGRNLine()`,
  `qualityCheckPass()`, `acceptGRN()`, `uploadPhotos()`
- Inventory integration: on accept, credit inventory for
  quantity_accepted at the PO line gl_account; never decrement for
  rejected quantities
- `GoodsReceiptNoteForm.vue` (mobile-optimised) +
  `GoodsReceiptNoteDetail.vue`
- Unit tests for GRN line allocation (partial receipt, multi-PO);
  integration test for GRN creation → stock mutation

The GR/IR GL posting on accept is specified in member 09; this member
calls into it via the lifecycle transition but the posting logic itself
lands in 09.

## Scope

### In Scope
- `GoodsReceiptNoteService` (create, add line, quality check, accept, photos)
- inventory-stock-tracking integration for accepted-quantity credit
- `GoodsReceiptNoteForm.vue`, `GoodsReceiptNoteDetail.vue`
- GRN line-allocation unit tests + stock-mutation integration test

### Out of Scope
- GR/IR GL posting logic — member 09
- Invoice ingestion + matching — members 05-08
- Vendor scoring, audit export — members 10-11

## Impact
- `lib/Service/GoodsReceiptNoteService.php`
- `src/components/GoodsReceiptNoteForm.vue`, `src/components/GoodsReceiptNoteDetail.vue`
- `tests/` GRN line allocation + stock mutation

## Cross-Project Dependencies
- **inventory-stock-tracking** — credit inventory on GRN accept; reserve expected receipts
- **docudesk** — stores delivery-condition photos

---
kind: code
depends_on: [bookkeeping-purchase-order-3way-04-goods-receipt-note]
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

# Proposal: bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion

Member 5 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-04-goods-receipt-note`.
This `kind: code` member ingests **supplier invoices** — from a
Peppol-received UBL Invoice or a PDF via OCR — into the `SupplierInvoice`
register declared in member 01, capturing line items and OCR confidence.
This is the third leg of the 3-way match arriving for matching.

## Why (carried from the giant)

REQ-PO3W-004 (ingestion side) and REQ-PO3W-007 (OCR extraction): a
Peppol UBL Invoice or a PDF invoice from a supplier must become a
structured `SupplierInvoice` record with line items and an
ocr_confidence_score, ready for the matching engine. Lines with low OCR
confidence will route to manual confirmation downstream.

## What this member does

- openconnector integration: subscribe to Peppol-received UBL Invoice
  events; call OCR for PDF-attached invoices and store ocr_confidence_score
- `SupplierInvoiceService`: `ingestUBLInvoice()` (parse UBL → invoice +
  line records), `ingestPDFInvoice()` (OCR → invoice), `setStatus()`
- `SupplierInvoiceDetail.vue` (header, line items, OCR confidence
  indicator, related match status)
- Unit tests: UBL → SupplierInvoice mapping; integration test:
  Peppol-received UBL Invoice → SupplierInvoice creation

## Scope

### In Scope
- `SupplierInvoiceService` ingestion (UBL + PDF/OCR) + status transitions
- openconnector UBL/OCR subscription wiring
- `SupplierInvoiceDetail.vue`
- UBL-mapping unit test + Peppol-ingestion integration test

### Out of Scope
- The matching algorithm itself — member 06
- Multi-PO consolidation matching — member 07
- Exception workflow, GL, vendor scoring, audit — members 08-11

## Impact
- `lib/Service/SupplierInvoiceService.php`
- `src/components/SupplierInvoiceDetail.vue`
- `tests/` UBL mapping + Peppol ingestion

## Cross-Project Dependencies
- **openconnector** — Peppol incoming UBL Invoice receipt + OCR extraction

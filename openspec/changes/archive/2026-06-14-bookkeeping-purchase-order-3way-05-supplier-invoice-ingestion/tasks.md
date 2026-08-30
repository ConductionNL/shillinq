# Tasks — Member 05: Supplier Invoice Ingestion (code)

## openconnector integration

- [x] Subscribe to Peppol-received UBL Invoice events from openconnector
- [x] Extract UBL fields → SupplierInvoice record (invoice_number, dates, amounts, currency, line items)
- [x] Call openconnector OCR service for PDF-attached invoices; store ocr_confidence_score

## SupplierInvoiceService

- [x] Implement `ingestUBLInvoice()` — parse UBL Invoice XML, create SupplierInvoice + line-item records, record ubl_source_uri + peppol_received_at
- [x] Implement `ingestPDFInvoice()` — receive PDF, call OCR extraction, create SupplierInvoice
- [x] Implement `setStatus()` — transition invoice through lifecycle states (starting at `received`)

## Vue view

- [x] Create `SupplierInvoiceDetail.vue`: header (supplier, invoice_number, dates, amounts), line-item table, OCR confidence indicator, related ThreeWayMatch status

## Tests

- [x] Write unit tests: UBL Invoice → SupplierInvoice mapping
- [x] Write integration test: Peppol-received UBL Invoice → SupplierInvoice creation

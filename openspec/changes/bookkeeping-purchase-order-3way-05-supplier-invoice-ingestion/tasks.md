# Tasks — Member 05: Supplier Invoice Ingestion (code)

## openconnector integration

- [ ] Subscribe to Peppol-received UBL Invoice events from openconnector
- [ ] Extract UBL fields → SupplierInvoice record (invoice_number, dates, amounts, currency, line items)
- [ ] Call openconnector OCR service for PDF-attached invoices; store ocr_confidence_score

## SupplierInvoiceService

- [ ] Implement `ingestUBLInvoice()` — parse UBL Invoice XML, create SupplierInvoice + line-item records, record ubl_source_uri + peppol_received_at
- [ ] Implement `ingestPDFInvoice()` — receive PDF, call OCR extraction, create SupplierInvoice
- [ ] Implement `setStatus()` — transition invoice through lifecycle states (starting at `received`)

## Vue view

- [ ] Create `SupplierInvoiceDetail.vue`: header (supplier, invoice_number, dates, amounts), line-item table, OCR confidence indicator, related ThreeWayMatch status

## Tests

- [ ] Write unit tests: UBL Invoice → SupplierInvoice mapping
- [ ] Write integration test: Peppol-received UBL Invoice → SupplierInvoice creation

# Spec Delta: Supplier Invoice Ingestion

## ADDED Requirements

### Requirement: Ingest supplier invoices from Peppol UBL and PDF/OCR

The system SHALL create a `SupplierInvoice` record (with line items) from
either a Peppol-received UBL Invoice or a PDF invoice processed through
OCR. For UBL invoices the system SHALL parse the UBL Invoice XML into
invoice header fields (invoice_number, invoice_date, due_date, totals,
currency) and line items, recording ubl_source_uri and peppol_received_at.
For PDF invoices the system SHALL call the OCR extraction service and
store ocr_confidence_score. A newly ingested invoice SHALL enter
lifecycle state `received`, ready for matching.

#### Scenario: Peppol UBL Invoice becomes a SupplierInvoice

- **GIVEN** a Peppol-received UBL Invoice arrives via openconnector from ErenteSchreuders
- **WHEN** `ingestUBLInvoice()` processes the UBL Invoice XML
- **THEN** the system creates a SupplierInvoice (invoice_number, dates, totals, currency, line items), records ubl_source_uri + peppol_received_at, and sets status to `received`

#### Scenario: PDF invoice records OCR confidence

- **GIVEN** a PDF-attached invoice arrives without UBL structure
- **WHEN** `ingestPDFInvoice()` calls the OCR extraction service
- **THEN** the system creates a SupplierInvoice from the extracted fields and stores ocr_confidence_score for downstream confidence gating

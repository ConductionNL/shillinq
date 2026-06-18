---
status: done
---

# shillinq-bill-import-modal Specification

## Purpose
Provides a dashboard-launched modal for importing supplier invoices from UBL/e-invoice XML or CSV files, parsed deterministically server-side into SupplierInvoice records without leaving the Financial overview. PDF uploads are honestly routed to a deferred OCR path rather than fabricating extractions; the review step pre-fills parsed values and gates save on required fields, duplicate invoices are rejected with an inline warning, and a successful import refreshes the dashboard payables widget.
## Requirements
### Requirement: REQ-BIM-001 — The system SHALL provide a dashboard-launched upload step that accepts a UBL/e-invoice XML or CSV supplier-invoice file (PDF accepted but routed to the honest OCR-deferral path)

The BillImportModal MUST be launched from the Financial overview
dashboard's **Import bill** action without navigating away from the
dashboard. Step 1 (Upload) MUST accept a drag-and-drop or file-picker
selection of:

- UBL 2.1 / e-invoice XML (`application/xml`; parsed deterministically
  server-side — no OCR);
- CSV with the documented header
  `supplier,invoiceNumber,invoiceDate,amount,vatAmount`;
- PDF — accepted by the picker but routed to the OCR-deferral path
  described in REQ-BIM-002 (NOT a fake extraction).

The upload posts the file to `POST /api/v1/supplier-invoices/import`,
which resolves the administration server-side (never trusting a
client-supplied administrationId) per ADR-005.

#### Scenario: UBL file uploaded from the dashboard

- **WHEN** the user opens the BillImportModal from the Financial overview and uploads a UBL e-invoice XML
- **THEN** the modal posts the file to `POST /api/v1/supplier-invoices/import` with `format=ubl`
- **AND** the server parses the UBL deterministically and the modal advances to the review step pre-filled with the parsed supplier, invoice number, date, amount and VAT

#### Scenario: CSV file uploaded from the dashboard

- **WHEN** the user uploads a CSV whose header is `supplier,invoiceNumber,invoiceDate,amount,vatAmount`
- **THEN** the server parses each row deterministically and creates a SupplierInvoice per row with `sourceFormat=csv` and `statusCode=received`

@e2e exclude Modal + multipart upload exercised by the controller PHPUnit suite and the billImportModal.js vitest logic module; no Playwright harness for the file-drop wizard in this change.

### Requirement: REQ-BIM-002 — The UBL/CSV extraction path SHALL be deterministic while the PDF-OCR confidence-scoring path is honestly deferred

The system SHALL extract UBL/e-invoice XML and CSV deterministically and
MUST honestly defer the PDF-OCR confidence-scoring path. The structured
extraction for UBL/e-invoice XML and CSV is fully implemented:
`SupplierInvoiceService::ingestUBLInvoice`
parses the UBL header identifiers, dates and monetary totals with no
OCR, and the CSV path maps the documented header to SupplierInvoice
fields. The PDF-OCR path — extracting supplier/number/date/totals and a
per-field confidence score from a scanned PDF — requires an unbundled OCR
engine that is NOT shipped with this change. Therefore a PDF upload MUST
NOT produce a fabricated extraction; the server MUST return HTTP 422 with
a deferral marker and the modal MUST surface an honest "PDF OCR
extraction is not yet available — upload a UBL/e-invoice XML or CSV
instead" message.

#### Scenario: UBL extraction is deterministic and built

- **WHEN** a UBL e-invoice XML is imported
- **THEN** the supplier, invoice number, invoice date and VAT/grand totals are extracted deterministically by the UBL parser with no OCR and no confidence scoring

#### Scenario: PDF upload reports OCR is unavailable and asks for UBL/CSV

- **WHEN** a PDF is uploaded to the import endpoint
- **THEN** the server returns HTTP 422 with `{ deferred: "pdf-ocr" }` and the message "PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV."
- **AND** the modal reports that OCR is unavailable and asks the user to upload a UBL/e-invoice XML or CSV instead — it does NOT show fabricated confidence-scored fields

@e2e exclude PDF-OCR confidence-scoring leg is honestly deferred (no OCR engine bundled); the 422 deferral is asserted by the controller PHPUnit suite.

### Requirement: REQ-BIM-003 — The review step SHALL pre-fill from the parsed/extracted values and require the mandatory fields before save

After a successful UBL/CSV parse, Step 2 (Review & confirm) MUST pre-fill
an editable form with the parsed supplier, invoice number, invoice date,
amount and VAT amount. The user MUST be able to correct any field. Save
MUST be blocked until the required fields `supplier`, `invoiceNumber`,
`invoiceDate` and `glAccount` are all present.

#### Scenario: Review form pre-fills and gates on required fields

- **WHEN** the parse succeeds and the review step renders
- **THEN** the supplier, invoice number, invoice date, amount and VAT amount are pre-filled and editable
- **AND** the Save action stays disabled until supplier, invoiceNumber, invoiceDate and glAccount are all set

@e2e exclude Review-step gating is covered by the billImportModal.js logic-module vitest tests.

### Requirement: REQ-BIM-004 — On save the modal SHALL close and refresh the dashboard payables widget without a full-page navigation

On a successful import the BillImportModal MUST close and emit
`cn:widget:refresh` for the `widget-open-creditors` payables widget so the
Financial overview reloads the open-payables figure without a full-page
navigation.

#### Scenario: Successful import refreshes the payables widget

- **WHEN** an import succeeds
- **THEN** the modal closes and emits `cn:widget:refresh` with `{ widget: "widget-open-creditors" }`

@e2e exclude Event-bus refresh emission is asserted by the billImportModal.js vitest tests.

### Requirement: REQ-BIM-005 — A duplicate supplier invoice SHALL return HTTP 409 and the modal stays open with an inline warning

A duplicate import SHALL NOT silently create a second record. When an
imported invoice matches an existing
`(administrationId, invoiceNumber, supplierId)` SupplierInvoice, the
import endpoint MUST return HTTP 409 with the message "This invoice
number already exists for this supplier" instead of silently creating a
duplicate. The modal MUST surface that message inline and remain open so
the user can correct the invoice number or cancel.

#### Scenario: Duplicate invoice number for a supplier returns 409

- **WHEN** an imported invoice's `(administrationId, invoiceNumber, supplierId)` already exists
- **THEN** the import endpoint returns HTTP 409 with the error "This invoice number already exists for this supplier"
- **AND** the modal shows that message inline and stays open

@e2e exclude Duplicate-detection 409 path is asserted by the controller PHPUnit suite and the billImportModal.js vitest tests.


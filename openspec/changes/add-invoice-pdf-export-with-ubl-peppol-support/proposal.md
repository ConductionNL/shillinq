---
status: proposed
source: issue-61
features: [invoice-pdf-export, ubl-xml-embed, zugferd-factur-x, peppol-bis-3, kvk-btw-validation, peppol-network-send]
---

# Add Invoice PDF Export with UBL/Peppol Support — Shillinq

## Summary

Implements PDF/A-3 invoice export with embedded UBL 2.1 XML for Peppol e-invoicing compliance. Users can export any finalised invoice as a PDF/A-3 document that conforms to the ZUGFeRD/Factur-X profile, with the UBL 2.1 XML attachment embedded directly in the PDF so that Peppol-compatible readers can extract the structured data. Dutch KvK and BTW number validation is enforced before export. The invoice can be downloaded for manual sending or transmitted directly via the Peppol network using Peppol BIS 3.0 identifiers.

## Demand Evidence

Top features by relevance to this change:

- **Invoice PDF export with embedded UBL XML** — finance teams and their customers increasingly require e-invoices that are both human-readable (PDF) and machine-processable (UBL XML). PDF/A-3 with ZUGFeRD/Factur-X is the European standard for this dual-format document.
- **Peppol BIS 3.0 compliance** — Dutch public-sector buyers mandate Peppol e-invoicing for suppliers. Shillinq must be able to assign Peppol participant IDs to contacts and transmit invoices via a Peppol access point.
- **Dutch KvK + BTW validation** — Dutch business registration numbers (KvK) and VAT numbers (BTW) follow specific formats that must be validated before any e-invoice is generated to avoid rejection by the recipient's system.

Key stakeholder pain points addressed:

- **Finance Manager / Invoicing Clerk**: manual PDF creation from invoice data, no structured XML embedded, inability to send directly via Peppol without exporting to a separate tool.
- **Municipal Supplier (Dutch)**: invoices rejected by buyer's Peppol access point due to invalid KvK/BTW numbers or missing UBL attachment.

## What Shillinq Already Has

After the `core` and `accounts-payable-receivable` changes:

- `Invoice` schema with full lifecycle and `ublFormat`, `peppolId` fields on `Contact`
- `Contact` schema with `kvkNumber`, `vatNumber`, `iban` fields
- `InvoiceController` for invoice lifecycle management
- `UblIngestionService` for inbound UBL/Peppol e-invoice parsing

### What Is Missing

- No PDF generation capability
- No UBL 2.1 XML export (only ingestion)
- No PDF/A-3 conformance or ZUGFeRD/Factur-X profile
- No Peppol outbound transmission
- No KvK/BTW format validation beyond storage
- No "Export PDF" UI action on invoice detail view

## Scope

### In Scope

1. **UblExportService** — PHP service that serialises a Shillinq `Invoice` object (with its `InvoiceLine` items, linked `Contact`, and `Account`) into a valid UBL 2.1 XML document conforming to the Peppol BIS Billing 3.0 specification. Validates KvK and BTW number formats before export; returns structured errors if validation fails.

2. **InvoicePdfService** — PHP service that renders an invoice HTML template to PDF using mPDF and then converts the output to PDF/A-3b conformance, embedding the UBL 2.1 XML produced by `UblExportService` as a file attachment named `factur-x.xml` with the ZUGFeRD/Factur-X `AF` relationship type and `text/xml` MIME type.

3. **PeppolTransmissionService** — PHP service that posts the generated PDF/A-3 and embedded UBL XML to a configured Peppol access point (AS4) endpoint. Supports lookup of the recipient's Peppol participant ID via the SML/SMP lookup. Returns transmission status and stores it on the `Invoice` object as `peppolStatus`.

4. **KvkBtwValidationService** — PHP service with two pure validation methods: `validateKvk(string): bool` (8-digit Dutch KvK format) and `validateBtw(string): bool` (NL + 9 digits + B + 2 digits pattern, e.g. `NL123456789B01`). Used by `UblExportService` before XML generation.

5. **InvoiceExportController** — OCS/REST controller with two endpoints:
   - `GET /api/v1/invoices/{id}/export/pdf` — returns the PDF/A-3 binary as `application/pdf`
   - `POST /api/v1/invoices/{id}/export/peppol` — triggers Peppol transmission and returns `{peppolStatus, transmissionId}`

6. **Frontend: Export PDF button** — an "Export PDF" action button on the `InvoiceDetail` view, enabled only when the invoice is in `sent`, `partial`, `paid`, or `overdue` status. Triggers a browser download of the PDF/A-3 file.

7. **Frontend: Peppol Send dialog** — a modal dialog on the `InvoiceDetail` view with a "Send via Peppol" button. Displays the resolved recipient Peppol ID (from the linked `Contact.peppolId`), a confirmation step, and shows transmission status after send. Disabled if `Contact.peppolId` is not set or if `Contact.kvkNumber`/`Contact.vatNumber` fail validation.

### Out of Scope

- Inbound UBL/Peppol ingestion (already in `accounts-payable-receivable`)
- PDF templates beyond the standard Shillinq invoice layout
- Peppol access point hosting (the service calls an external access point via AS4)
- XRECHNUNG (German) or EN 16931 profiles beyond Peppol BIS 3.0
- Bulk PDF export of multiple invoices

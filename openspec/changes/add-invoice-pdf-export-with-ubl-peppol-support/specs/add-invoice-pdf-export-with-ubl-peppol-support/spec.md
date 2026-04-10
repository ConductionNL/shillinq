---
status: proposed
---

# Invoice PDF Export with UBL/Peppol Support — Shillinq

## Purpose

Defines functional requirements for Shillinq's invoice PDF export feature: generating PDF/A-3 documents with embedded UBL 2.1 XML conforming to the ZUGFeRD/Factur-X profile, Peppol BIS 3.0 outbound transmission, Dutch KvK and BTW number validation, and the supporting UI actions on the invoice detail view.

Stakeholders: Finance Manager, Invoicing Clerk, Municipal Supplier (Dutch).

User stories addressed: Export invoice as PDF/A-3 with embedded UBL XML, Send invoice via Peppol network, Validate Dutch KvK and BTW numbers before e-invoice generation.

## Requirements

### REQ-PDF-001: PDF/A-3b Generation with Embedded UBL 2.1 XML [must]

The system MUST generate a PDF/A-3b conformant document from a finalised `Invoice` object. The UBL 2.1 XML MUST be embedded as a file attachment named `factur-x.xml` with the PDF `AF` (Associated Files) relationship and MIME type `text/xml`. The embedded XML MUST conform to the ZUGFeRD 2.x / Factur-X EN 16931 profile at minimum, and to Peppol BIS Billing 3.0 when the recipient has a `peppolId`. The PDF MUST include the XMP metadata block referencing the `urn:factur-x.eu:1p0:en16931` namespace.

**Scenarios:**

1. **GIVEN** an invoice with `paymentStatus: sent` and a linked contact with valid `kvkNumber` and `vatNumber` **WHEN** `GET /api/v1/invoices/{id}/export/pdf` is called **THEN** the response has `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="INV-{invoiceNumber}.pdf"`, and the binary body is a valid PDF/A-3b document.

2. **GIVEN** the PDF/A-3b document is generated **WHEN** it is opened in a PDF reader that supports PDF/A-3 **THEN** the embedded file `factur-x.xml` is accessible as an attachment and is a well-formed UBL 2.1 `Invoice` XML document.

3. **GIVEN** an invoice with `paymentStatus: draft` **WHEN** `GET /api/v1/invoices/{id}/export/pdf` is called **THEN** the API returns HTTP 422 with error `"Invoice must be finalised (sent, partial, paid, or overdue) before export"`.

4. **GIVEN** the generated PDF **WHEN** its XMP metadata is inspected **THEN** the `fx:DocumentType`, `fx:DocumentFileName`, `fx:Version`, and `fx:ConformanceLevel` XMP fields MUST be present and set to `INVOICE`, `factur-x.xml`, `1.0`, and `EN 16931` respectively.

5. **GIVEN** the invoice has 10 line items **WHEN** the PDF is generated **THEN** all 10 `InvoiceLine` items appear in the PDF table and all 10 `cac:InvoiceLine` elements are present in the embedded UBL XML.

### REQ-PDF-002: UBL 2.1 XML Serialisation with Peppol BIS 3.0 Compliance [must]

`UblExportService` MUST serialise the Shillinq `Invoice` (including all `InvoiceLine` items, linked `Contact`, linked `Account`) into a valid UBL 2.1 `Invoice` XML document. The XML MUST include `cbc:CustomizationID` set to `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` when the receiving contact has a `peppolId`. All monetary amounts MUST use `currencyID` matching the invoice `currency` field (default `EUR`). Tax subtotals MUST group by `cac:TaxCategory/cbc:ID` (S for standard rate, Z for zero-rated, E for exempt).

**Scenarios:**

1. **GIVEN** an invoice with `currency: EUR`, two lines with `taxRate: 21`, and a contact with `peppolId: 0106:NL12345678` **WHEN** `UblExportService::export(Invoice $invoice): string` is called **THEN** the returned string is valid XML against the UBL 2.1 Invoice schema, `cbc:CustomizationID` is `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0`, and both `cac:InvoiceLine` elements are present.

2. **GIVEN** an invoice line with `taxRate: 21` **WHEN** the UBL XML is generated **THEN** `cac:ClassifiedTaxCategory/cbc:ID` is `S` and `cac:ClassifiedTaxCategory/cbc:Percent` is `21`.

3. **GIVEN** an invoice line with `taxRate: 0` **WHEN** the UBL XML is generated **THEN** `cac:ClassifiedTaxCategory/cbc:ID` is `Z`.

4. **GIVEN** the UBL XML is generated for an invoice with `invoiceType: credit` **WHEN** the XML root element is inspected **THEN** the root element MUST be `CreditNote` (UBL 2.1 `CreditNote` document type) instead of `Invoice`.

5. **GIVEN** the `Contact` linked to the invoice has `peppolId` not set **WHEN** `UblExportService::export()` is called **THEN** `cbc:CustomizationID` is set to `urn:cen.eu:en16931:2017` (EN 16931 without Peppol extension).

### REQ-PDF-003: Dutch KvK and BTW Number Validation [must]

`KvkBtwValidationService` MUST validate Dutch Chamber of Commerce (KvK) and VAT (BTW) numbers before UBL export. KvK MUST be exactly 8 digits. BTW MUST match the pattern `NL[0-9]{9}B[0-9]{2}` (e.g. `NL123456789B01`). Validation errors MUST be returned as structured HTTP 422 responses with field-level messages in Dutch. Validation MUST be applied to both the seller (`Account`) and buyer (`Contact`) sides of the invoice.

**Scenarios:**

1. **GIVEN** a contact with `kvkNumber: 1234567` (only 7 digits) **WHEN** PDF export is requested **THEN** HTTP 422 is returned with `{"field":"contact.kvkNumber","message":"KvK-nummer moet uit 8 cijfers bestaan"}`.

2. **GIVEN** a contact with `vatNumber: NL123456789B01` (valid) **WHEN** PDF export is requested **THEN** validation passes and export proceeds.

3. **GIVEN** a contact with `vatNumber: BE0123456789` (Belgian format, not Dutch) **WHEN** PDF export is requested **THEN** HTTP 422 is returned with `{"field":"contact.vatNumber","message":"BTW-nummer moet het formaat NLxxxxxxxxx Bxx hebben"}`.

4. **GIVEN** the seller `Account` has no `kvkNumber` set **WHEN** PDF export is requested **THEN** HTTP 422 is returned with `{"field":"account.kvkNumber","message":"KvK-nummer van de verkoper is verplicht voor e-facturering"}`.

5. **GIVEN** both seller and buyer have valid KvK and BTW numbers **WHEN** PDF export is requested **THEN** validation passes with no errors.

### REQ-PDF-004: Peppol Outbound Transmission [must]

`PeppolTransmissionService` MUST transmit the generated PDF/A-3 and embedded UBL XML to a configured Peppol access point (AS4). The recipient's Peppol participant ID MUST be resolved from `Contact.peppolId`. After transmission, `Invoice.peppolStatus` MUST be updated to `transmitted` on success or `failed` on error, with `Invoice.peppolTransmissionId` storing the access point's transmission reference. A Nextcloud notification MUST be sent to the invoicing user on both success and failure.

**Scenarios:**

1. **GIVEN** a finalised invoice linked to a contact with `peppolId: 0106:NL12345678` **WHEN** `POST /api/v1/invoices/{id}/export/peppol` is called **THEN** `PeppolTransmissionService` posts the UBL XML to the configured AS4 endpoint, `Invoice.peppolStatus` is set to `transmitted`, `Invoice.peppolTransmissionId` is stored, and the response returns HTTP 200 with `{"peppolStatus":"transmitted","transmissionId":"..."}`.

2. **GIVEN** the Peppol access point returns an error **WHEN** transmission is attempted **THEN** `Invoice.peppolStatus` is set to `failed`, the error details are stored in `Invoice.peppolError`, and the invoicing user receives a Nextcloud notification "Peppol verzending mislukt voor factuur {invoiceNumber}: {error}".

3. **GIVEN** a contact without `peppolId` **WHEN** `POST /api/v1/invoices/{id}/export/peppol` is called **THEN** HTTP 422 is returned with `{"message":"Contactpersoon heeft geen Peppol-deelnemers-ID"}`.

4. **GIVEN** transmission succeeds **WHEN** the invoicing user's Nextcloud notifications are checked **THEN** a notification exists: "Factuur {invoiceNumber} succesvol via Peppol verzonden naar {contact.displayName}".

5. **GIVEN** the Peppol access point URL is not configured in admin settings **WHEN** transmission is attempted **THEN** HTTP 503 is returned with `{"message":"Peppol-toegangspunt niet geconfigureerd. Stel de AS4-eindpunt-URL in via de beheerdersinstellingen"}`.

### REQ-PDF-005: Export PDF UI Action [must]

The `InvoiceDetail` view MUST include an "Exporteer PDF" (Export PDF) action button. The button MUST be enabled only when `invoice.paymentStatus` is one of `sent`, `partial`, `paid`, or `overdue`. Clicking the button MUST trigger a browser file download of the PDF/A-3 document. A loading indicator MUST be shown during PDF generation.

**Scenarios:**

1. **GIVEN** an invoice with `paymentStatus: sent` **WHEN** the `InvoiceDetail` view is open **THEN** the "Exporteer PDF" button is visible and enabled.

2. **GIVEN** an invoice with `paymentStatus: draft` **WHEN** the `InvoiceDetail` view is open **THEN** the "Exporteer PDF" button is visible but disabled, with a tooltip "Factuur moet worden verstuurd voor export".

3. **GIVEN** the user clicks "Exporteer PDF" on a valid invoice **WHEN** the PDF is generated **THEN** the browser downloads a file named `INV-{invoiceNumber}.pdf` and no error is shown.

4. **GIVEN** KvK or BTW validation fails **WHEN** the user clicks "Exporteer PDF" **THEN** a NcDialog error modal opens listing the field-level validation errors in Dutch.

### REQ-PDF-006: Peppol Send UI Dialog [should]

The `InvoiceDetail` view MUST include a "Verzend via Peppol" (Send via Peppol) action button, enabled only when the linked contact has a non-empty `peppolId` and the invoice is in `sent`, `partial`, `paid`, or `overdue` status. Clicking it MUST open a confirmation dialog showing the resolved Peppol ID and invoice details. After confirmation, the dialog MUST show transmission status.

**Scenarios:**

1. **GIVEN** a finalised invoice linked to a contact with `peppolId` set **WHEN** the "Verzend via Peppol" button is clicked **THEN** a modal opens showing "Factuur verzenden naar: {contact.displayName} ({contact.peppolId})" with Confirm and Cancel buttons.

2. **GIVEN** the user confirms in the Peppol dialog **WHEN** transmission succeeds **THEN** the dialog shows "Verzonden" with the transmission ID and the invoice `peppolStatus` badge in the detail view updates to `transmitted`.

3. **GIVEN** the linked contact has no `peppolId` **WHEN** the `InvoiceDetail` view is open **THEN** the "Verzend via Peppol" button is disabled with tooltip "Stel een Peppol-ID in op het contactrecord om via Peppol te verzenden".

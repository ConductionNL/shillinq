# Design: Invoice PDF Export with UBL/Peppol Support — Shillinq

## Architecture Overview

This change adds an outbound e-invoicing layer to Shillinq. All PDF generation and UBL serialisation happen in PHP services; the frontend calls two new OCS endpoints and handles the response. No new OpenRegister schemas are required — new fields are added to the existing `Invoice` and `Contact` schemas. The mPDF library is used for PDF/A-3b generation with embedded file attachments.

```
Browser (Vue 2.7 + Pinia)
    │
    └─ Shillinq OCS API
            ├─ InvoiceExportController
            │       ├─ GET  /api/v1/invoices/{id}/export/pdf     → binary PDF/A-3b
            │       └─ POST /api/v1/invoices/{id}/export/peppol  → {peppolStatus, transmissionId}
            │
            └─ PHP Services
                    ├─ KvkBtwValidationService   (pure validation, no I/O)
                    ├─ UblExportService           (Invoice → UBL 2.1 XML string)
                    ├─ InvoicePdfService          (UBL XML + HTML template → PDF/A-3b)
                    └─ PeppolTransmissionService  (PDF/A-3b → AS4 access point)
                            │
                            └─ External: Peppol AS4 access point (configured in admin settings)
```

## Data Model Changes

### Invoice schema additions

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| peppolStatus | string | No | — | Enum: transmitted, failed. Set by `PeppolTransmissionService` |
| peppolTransmissionId | string | No | — | Transmission reference from the AS4 access point |
| peppolError | string | No | — | Error message when `peppolStatus: failed` |

### Contact schema additions

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| peppolId | string | No | — | Peppol participant ID, e.g. `0106:NL12345678` |

> `kvkNumber` and `vatNumber` already exist on `Contact` from the `accounts-payable-receivable` change. No additions needed there.

### AppSettings schema additions

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| peppolAs4EndpointUrl | string | No | — | URL of the configured Peppol AS4 access point |
| peppolAs4ClientCert | string | No | — | Path to the AS4 client certificate (PEM) |

## PHP Services

### KvkBtwValidationService

```
lib/Service/KvkBtwValidationService.php
```

Pure stateless service. No constructor injection required.

```php
public function validateKvk(string $kvk): bool
// Returns true iff $kvk matches /^\d{8}$/

public function validateBtw(string $btw): bool
// Returns true iff $btw matches /^NL\d{9}B\d{2}$/i

public function validateForExport(array $invoice, array $contact, array $account): array
// Returns array of ['field' => string, 'message' => string] validation errors.
// Validates: account.kvkNumber, account.vatNumber, contact.kvkNumber (if company), contact.vatNumber (if company)
```

### UblExportService

```
lib/Service/UblExportService.php
```

Depends on `KvkBtwValidationService` and `ObjectService` (for fetching related `InvoiceLine` items).

```php
public function export(string $invoiceId): string
// Fetches Invoice, all InvoiceLine items, Contact, Account from ObjectService.
// Runs KvkBtwValidationService::validateForExport(); throws ValidationException on errors.
// Returns UBL 2.1 XML string. Uses DOMDocument for XML construction.
// Sets cbc:CustomizationID based on Contact.peppolId presence.
// Maps invoiceType: credit → CreditNote root element.
// Groups InvoiceLine items into cac:TaxSubtotal by taxRate category (S/Z/E).
```

**UBL 2.1 element mapping:**

| Shillinq field | UBL 2.1 element |
|---|---|
| `invoice.invoiceNumber` | `cbc:ID` |
| `invoice.invoiceDate` | `cbc:IssueDate` |
| `invoice.dueDate` | `cbc:DueDate` |
| `invoice.currency` | `cbc:DocumentCurrencyCode` |
| `account.kvkNumber` | `cac:AccountingSupplierParty/.../cbc:CompanyID @schemeID="0106"` |
| `account.vatNumber` | `cac:AccountingSupplierParty/.../cbc:CompanyID @schemeID="9944"` |
| `contact.kvkNumber` | `cac:AccountingCustomerParty/.../cbc:CompanyID @schemeID="0106"` |
| `contact.vatNumber` | `cac:AccountingCustomerParty/.../cbc:CompanyID @schemeID="9944"` |
| `contact.peppolId` | `cac:AccountingCustomerParty/.../cbc:EndpointID @schemeID` (scheme derived from ID prefix) |
| `invoiceLine.description` | `cac:Item/cbc:Name` |
| `invoiceLine.quantity` | `cbc:InvoicedQuantity` |
| `invoiceLine.unitPrice` | `cac:Price/cbc:PriceAmount` |
| `invoiceLine.lineTotal` | `cbc:LineExtensionAmount` |
| `invoiceLine.taxRate` | `cac:ClassifiedTaxCategory/cbc:Percent` |

### InvoicePdfService

```
lib/Service/InvoicePdfService.php
```

Depends on `UblExportService`. Uses `mPDF\Mpdf` (Composer dependency).

```php
public function generatePdfA3(string $invoiceId): string
// 1. Calls UblExportService::export($invoiceId) → $ublXml
// 2. Renders invoice HTML from Twig template at templates/invoice-pdf.html.twig
// 3. Initialises mPDF with PDFA=3b mode
// 4. Attaches $ublXml as embedded file: filename=factur-x.xml, mime=text/xml, AFRelationship=Alternative
// 5. Writes Factur-X XMP metadata block
// 6. Returns PDF binary string via $mpdf->Output('', 'S')
```

**mPDF configuration:**
- `mode: PDFA-3b`
- `default_font: DejaVu Sans` (Unicode, embedded, satisfies PDF/A font requirements)
- `margin_*: 15mm`
- Embedded file relationship type: `Alternative` (ZUGFeRD/Factur-X requirement)

**Twig template** (`templates/invoice-pdf.html.twig`): renders invoice header (seller, buyer, invoice number, dates), line items table (description, qty, unit price, tax rate, line total), and totals (subtotal, tax, grand total). Uses Nextcloud brand colours and NL-Design token spacing.

### PeppolTransmissionService

```
lib/Service/PeppolTransmissionService.php
```

Depends on `InvoicePdfService`, `ObjectService`, `IConfig` (for AS4 endpoint URL), `INotificationManager`.

```php
public function transmit(string $invoiceId): array
// 1. Fetches Invoice + Contact from ObjectService
// 2. Validates Contact.peppolId is set; throws if not
// 3. Fetches AS4 endpoint URL from IConfig; throws PeppolNotConfiguredException if not set
// 4. Calls InvoicePdfService::generatePdfA3($invoiceId) → $pdfBinary
// 5. POSTs $pdfBinary to AS4 endpoint with AS4 SOAP envelope
// 6. On success: updates Invoice.peppolStatus = 'transmitted', Invoice.peppolTransmissionId = response ID
// 7. On failure: updates Invoice.peppolStatus = 'failed', Invoice.peppolError = error message
// 8. Sends Nextcloud notification to invoice creator
// Returns ['peppolStatus' => string, 'transmissionId' => string|null]
```

## OCS Controller

### InvoiceExportController

```
lib/Controller/InvoiceExportController.php
```

Registered in `appinfo/routes.php` under prefix `/api/v1/invoices/{id}/export`.

```php
/**
 * @NoAdminRequired
 * @NoCSRFRequired
 */
public function pdf(string $id): Response
// Calls InvoicePdfService::generatePdfA3($id)
// Returns DataDownloadResponse with Content-Type: application/pdf
// Filename: INV-{invoiceNumber}.pdf

/**
 * @NoAdminRequired
 */
public function peppol(string $id): JSONResponse
// Calls PeppolTransmissionService::transmit($id)
// Returns 200 JSONResponse with {peppolStatus, transmissionId}
// Returns 422 JSONResponse on ValidationException
// Returns 503 JSONResponse on PeppolNotConfiguredException
```

## Frontend

### Store additions (`src/store/modules/invoice.js`)

```js
exportPdf(invoiceId)
// GET /api/v1/invoices/{invoiceId}/export/pdf
// Triggers browser download via Blob URL

sendViaPeppol(invoiceId)
// POST /api/v1/invoices/{invoiceId}/export/peppol
// Returns { peppolStatus, transmissionId }
// Updates invoice.peppolStatus in store
```

### InvoiceDetail view additions (`src/views/invoice/InvoiceDetail.vue`)

- **"Exporteer PDF" button** — shown in the action bar alongside existing actions. Disabled when `invoice.paymentStatus === 'draft' || invoice.paymentStatus === 'void'`. Calls `invoice.exportPdf(id)`; shows `NcLoadingIcon` during request; shows `NcDialog` with field errors on 422.

- **"Verzend via Peppol" button** — shown next to "Exporteer PDF". Disabled when `!invoice.contact?.peppolId`. On click: opens `PeppolSendDialog`.

### PeppolSendDialog component (`src/components/invoice/PeppolSendDialog.vue`)

New component. Displays:
1. Confirmation step: contact name, Peppol ID, invoice number and amount
2. Calls `invoice.sendViaPeppol(id)` on confirm
3. Success state: shows transmission ID and "Verzonden" label
4. Error state: shows error message with retry option

## Composer Dependencies

Add to `composer.json`:

```json
"mpdf/mpdf": "^8.2"
```

mPDF supports PDF/A-3b with embedded file attachments and ZUGFeRD/Factur-X XMP metadata out of the box.

## Admin Settings Additions

Two new fields in the Shillinq admin settings form (`src/views/settings/AdminSettings.vue`):

| Setting | Label (NL) | Type |
|---|---|---|
| `peppolAs4EndpointUrl` | Peppol AS4 eindpunt-URL | text input |
| `peppolAs4ClientCert` | Pad naar AS4 clientcertificaat (PEM) | text input |

These are read by `PeppolTransmissionService` via `IConfig::getAppValue('shillinq', 'peppolAs4EndpointUrl')`.

## Error Handling

| Scenario | HTTP status | Response |
|---|---|---|
| Invoice not finalised | 422 | `{"message":"Invoice must be finalised..."}` |
| KvK/BTW validation failure | 422 | `{"errors":[{"field":"...","message":"..."}]}` |
| Contact has no peppolId | 422 | `{"message":"Contactpersoon heeft geen Peppol-deelnemers-ID"}` |
| AS4 endpoint not configured | 503 | `{"message":"Peppol-toegangspunt niet geconfigureerd..."}` |
| AS4 transmission error | 502 | `{"message":"Peppol-verzending mislukt: {detail}"}` |
| Invoice not found | 404 | Standard Nextcloud 404 |

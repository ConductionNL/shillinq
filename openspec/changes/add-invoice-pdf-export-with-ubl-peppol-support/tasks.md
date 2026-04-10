# Tasks: add-invoice-pdf-export-with-ubl-peppol-support

## 1. Composer Dependency

- [ ] 1.1 Add `mpdf/mpdf` to `composer.json`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-001`
  - **files**: `composer.json`
  - **acceptance_criteria**:
    - GIVEN `composer.json` is updated
    - THEN `mpdf/mpdf` MUST appear under `require` with constraint `^8.2`
    - AND `composer.lock` MUST be regenerated consistently

## 2. OpenRegister Schema Updates

- [ ] 2.1 Add `peppolStatus`, `peppolTransmissionId`, `peppolError` to the `invoice` schema in `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed
    - THEN `invoice` schema MUST include `peppolStatus` with enum `["transmitted","failed"]` (not required)
    - AND `peppolTransmissionId` MUST be type string, not required
    - AND `peppolError` MUST be type string, not required

- [ ] 2.2 Add `peppolId` to the `contact` schema in `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed
    - THEN `contact` schema MUST include `peppolId` as type string, not required, with description "Peppol participant ID, e.g. 0106:NL12345678"

- [ ] 2.3 Add `peppolAs4EndpointUrl` and `peppolAs4ClientCert` to the `appSettings` schema in `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN `appSettings` schema MUST include `peppolAs4EndpointUrl` (string, not required)
    - AND `peppolAs4ClientCert` (string, not required)

## 3. PHP Services

- [ ] 3.1 Implement `KvkBtwValidationService`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-003`
  - **files**: `lib/Service/KvkBtwValidationService.php`
  - **acceptance_criteria**:
    - GIVEN `validateKvk('12345678')` is called THEN it returns `true`
    - GIVEN `validateKvk('1234567')` (7 digits) THEN it returns `false`
    - GIVEN `validateKvk('1234567A')` (non-digit) THEN it returns `false`
    - GIVEN `validateBtw('NL123456789B01')` THEN it returns `true`
    - GIVEN `validateBtw('NL12345678B01')` (short) THEN it returns `false`
    - GIVEN `validateBtw('BE0123456789')` THEN it returns `false`
    - GIVEN `validateForExport` is called with a missing `account.kvkNumber` THEN it returns an error entry with `field: "account.kvkNumber"` and Dutch message

- [ ] 3.2 Implement `UblExportService`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-002`
  - **files**: `lib/Service/UblExportService.php`
  - **acceptance_criteria**:
    - GIVEN a valid invoice with `invoiceType: sales` and two lines WHEN `export($invoiceId)` is called THEN the returned XML is well-formed UBL 2.1 with root element `Invoice`
    - AND `cbc:CustomizationID` is `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` when `Contact.peppolId` is set
    - AND `cbc:CustomizationID` is `urn:cen.eu:en16931:2017` when `Contact.peppolId` is empty
    - GIVEN `invoiceType: credit` THEN root element is `CreditNote`
    - GIVEN a line with `taxRate: 21` THEN `cac:ClassifiedTaxCategory/cbc:ID` is `S`
    - GIVEN a line with `taxRate: 0` THEN `cac:ClassifiedTaxCategory/cbc:ID` is `Z`
    - GIVEN KvK/BTW validation fails THEN `ValidationException` is thrown with structured errors

- [ ] 3.3 Implement `InvoicePdfService`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-001`
  - **files**: `lib/Service/InvoicePdfService.php`, `templates/invoice-pdf.html.twig`
  - **acceptance_criteria**:
    - GIVEN a valid invoice WHEN `generatePdfA3($invoiceId)` is called THEN a non-empty binary string is returned
    - AND the binary begins with `%PDF-` (valid PDF header)
    - AND when the PDF is parsed, an embedded file named `factur-x.xml` with MIME type `text/xml` is present
    - AND the embedded XML is the UBL output of `UblExportService`
    - GIVEN an invoice with `paymentStatus: draft` THEN `InvalidStatusException` is thrown

- [ ] 3.4 Implement `PeppolTransmissionService`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-004`
  - **files**: `lib/Service/PeppolTransmissionService.php`
  - **acceptance_criteria**:
    - GIVEN `Contact.peppolId` is empty THEN `ValidationException` is thrown with Dutch message
    - GIVEN AS4 endpoint URL is not configured THEN `PeppolNotConfiguredException` is thrown
    - GIVEN the AS4 POST succeeds THEN `Invoice.peppolStatus` is updated to `transmitted` and `Invoice.peppolTransmissionId` is stored
    - GIVEN the AS4 POST fails THEN `Invoice.peppolStatus` is updated to `failed` and `Invoice.peppolError` is stored
    - GIVEN transmission succeeds THEN a Nextcloud notification is sent with message containing `invoiceNumber` and `contact.displayName`
    - GIVEN transmission fails THEN a Nextcloud notification is sent with the error detail

## 4. OCS Controller

- [ ] 4.1 Implement `InvoiceExportController` with `pdf` and `peppol` actions
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-001`
  - **files**: `lib/Controller/InvoiceExportController.php`
  - **acceptance_criteria**:
    - GIVEN a valid `GET /api/v1/invoices/{id}/export/pdf` request THEN response has `Content-Type: application/pdf` and non-empty body
    - AND `Content-Disposition` header is `attachment; filename="INV-{invoiceNumber}.pdf"`
    - GIVEN a `ValidationException` THEN HTTP 422 is returned with `{"errors": [...]}`
    - GIVEN a `PeppolNotConfiguredException` on the peppol endpoint THEN HTTP 503 is returned
    - GIVEN a valid `POST /api/v1/invoices/{id}/export/peppol` THEN HTTP 200 with `{peppolStatus, transmissionId}`

- [ ] 4.2 Register routes in `appinfo/routes.php`
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the app is loaded THEN `GET /api/v1/invoices/{id}/export/pdf` routes to `InvoiceExportController::pdf`
    - AND `POST /api/v1/invoices/{id}/export/peppol` routes to `InvoiceExportController::peppol`

## 5. Frontend

- [ ] 5.1 Add `exportPdf` and `sendViaPeppol` actions to `src/store/modules/invoice.js`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-005`
  - **files**: `src/store/modules/invoice.js`
  - **acceptance_criteria**:
    - GIVEN `exportPdf(id)` is called THEN it calls `GET /api/v1/invoices/{id}/export/pdf` and triggers a browser file download
    - GIVEN the API returns 422 THEN the action throws an error with `errors` array for the caller to display
    - GIVEN `sendViaPeppol(id)` is called THEN it calls `POST /api/v1/invoices/{id}/export/peppol` and returns `{peppolStatus, transmissionId}`
    - GIVEN success THEN `invoice.peppolStatus` is updated in the Pinia store

- [ ] 5.2 Add "Exporteer PDF" button to `InvoiceDetail` view
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-005`
  - **files**: `src/views/invoice/InvoiceDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `invoice.paymentStatus` is `sent`, `partial`, `paid`, or `overdue` THEN the button is enabled
    - GIVEN `invoice.paymentStatus` is `draft` or `void` THEN the button is disabled with tooltip in Dutch
    - GIVEN the button is clicked THEN `exportPdf` store action is called and a browser download is triggered
    - GIVEN 422 response THEN an `NcDialog` opens listing field errors in Dutch
    - GIVEN PDF generation is in progress THEN an `NcLoadingIcon` is shown on the button

- [ ] 5.3 Implement `PeppolSendDialog` component
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-006`
  - **files**: `src/components/invoice/PeppolSendDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the dialog opens THEN it shows contact name, Peppol ID, invoice number and total amount
    - GIVEN the user clicks Confirm THEN `sendViaPeppol` store action is called
    - GIVEN success THEN dialog shows "Verzonden" with the transmission ID
    - GIVEN error THEN dialog shows the error message with a Retry button
    - GIVEN the user clicks Cancel THEN the dialog closes without making any API call

- [ ] 5.4 Add "Verzend via Peppol" button to `InvoiceDetail` view
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-006`
  - **files**: `src/views/invoice/InvoiceDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `contact.peppolId` is set and invoice is finalised THEN the button is enabled
    - GIVEN `contact.peppolId` is empty THEN the button is disabled with tooltip "Stel een Peppol-ID in op het contactrecord om via Peppol te verzenden"
    - GIVEN the button is clicked THEN `PeppolSendDialog` opens

- [ ] 5.5 Add Peppol admin settings fields to `AdminSettings.vue`
  - **spec_ref**: `specs/add-invoice-pdf-export-with-ubl-peppol-support/spec.md#REQ-PDF-004`
  - **files**: `src/views/settings/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings page is open THEN `peppolAs4EndpointUrl` and `peppolAs4ClientCert` text input fields are visible under a "Peppol-instellingen" section
    - GIVEN valid values are saved THEN they are persisted via the settings store

## 6. Tests

- [ ] 6.1 Unit tests for `KvkBtwValidationService`
  - **files**: `tests/Unit/Service/KvkBtwValidationServiceTest.php`
  - **acceptance_criteria**:
    - Tests cover valid/invalid KvK (8 digits, non-digits, too short, too long)
    - Tests cover valid/invalid BTW (correct NL format, Belgian format, too short)
    - `validateForExport` tested with missing `account.kvkNumber`, missing `contact.vatNumber`, all valid

- [ ] 6.2 Unit tests for `UblExportService`
  - **files**: `tests/Unit/Service/UblExportServiceTest.php`
  - **acceptance_criteria**:
    - Mock `ObjectService` returns fixture Invoice, InvoiceLine, Contact, Account objects
    - Assert root element is `Invoice` for sales invoice and `CreditNote` for credit invoice
    - Assert `cbc:CustomizationID` value based on `Contact.peppolId` presence
    - Assert tax category `S` for 21% rate and `Z` for 0% rate

- [ ] 6.3 Unit tests for `InvoicePdfService`
  - **files**: `tests/Unit/Service/InvoicePdfServiceTest.php`
  - **acceptance_criteria**:
    - Mock `UblExportService`; assert `generatePdfA3` returns a string starting with `%PDF-`
    - Assert `InvalidStatusException` is thrown for `paymentStatus: draft`

- [ ] 6.4 Unit tests for `InvoiceExportController`
  - **files**: `tests/Unit/Controller/InvoiceExportControllerTest.php`
  - **acceptance_criteria**:
    - Assert `pdf()` returns `DataDownloadResponse` with correct Content-Type and filename
    - Assert `peppol()` returns 422 JSON on `ValidationException`
    - Assert `peppol()` returns 503 JSON on `PeppolNotConfiguredException`

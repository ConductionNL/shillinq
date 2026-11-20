# Tasks: sales-invoice-document

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Foundations

- [ ] 1.1 Read docudesk's template rendering (development) for a QR function and record the finding in the PR; add `bacon/bacon-qr-code` only if there is none (REQ-SID-005). Verify: the finding with file and line in the PR body; `composer audit` if a package is added.
- [x] 1.2 Add `InvoiceLayout`, `CustomerMaster.language` and `ARInvoice.documentLanguage` in a `register.d` fragment with a default layout per administration (REQ-SID-001, REQ-SID-003). Verify: `npm run check:registers`, `npm run check:seeds`. Built: `lib/Settings/register.d/zz-sales-invoice-document.json` adds `InvoiceLayout` 0.1.0 (administrationId, logoFileId, primaryColor and accentColor as hex with AA-passing defaults #0F4C81 and #B8461B, variant classic/modern/compact, footerText, showQr default true, audit trail on), `CustomerMaster.language` (0.5.0) and `ARInvoice.documentLanguage` (0.20.0), both nl/en/de/fr, and one default classic layout per seeded administration. Named zz- so its versions are the last declared; the app version and `register-versions.lock.json` moved, and `LinkedObjectsFragmentTest` / `PlatformMobilePwaFragmentTest` now accept a later fragment that moves ARInvoice further up. `npm run check:seeds` passes; `npm run check:registers` reports only the pre-existing ReorderProposal audit-trail gap. Test: `SalesInvoiceDocumentFragmentTest`.

## 2. Template and renderer

- [ ] 2.1 Add the `sales-invoice` template to `docudesk-templates.json` with the three variants (REQ-SID-002). Verify: the template validator the repo runs on that file.
- [ ] 2.2 Make `InvoicePdfGenerator::generatePdf()` a docudesk consumer with the absence exception, returning PDF bytes instead of HTML; leave `renderHtml()`, `buildHybridPdfBytes()` and `assemblePdf()` to the hybrid path (REQ-SID-002). Verify: PHPUnit with docudesk present and absent, asserting the served file starts with `%PDF`.
- [x] 2.3 Language labels and `NumberFormatter` amounts in the assembled data (REQ-SID-003, REQ-SID-004). Verify: PHPUnit for nl and en with EUR and GBP. Built: `InvoiceDocumentLanguage` (labels of the document resolved through the app catalogue in the document language, nl/en/de/fr with Dutch fallback; `money()` with `NumberFormatter` for the language's locale and the invoice currency) and `InvoiceDocumentData::assemble()`, the data the `sales-invoice` template will receive in 2.2: labels, invoice header, parties, lines and totals formatted in the invoice currency, the language taken from `documentLanguage`, else the customer's `language`, else the administration's. Label strings in en, nl, de and fr. Tests: `InvoiceDocumentLanguageTest` (en GBP £4,800.00 with no euro sign, nl and de EUR), `InvoiceDocumentDataTest`.
- [x] 2.4 EPC and pay-link QR payloads (REQ-SID-005). Verify: PHPUnit against the EPC069-12 field order. Built: `EpcQrPayload::forTransfer()` (EPC069-12 v002: BCD, 002, 1, SCT, BIC, name ≤70, IBAN, EUR amount with two decimals, empty purpose and structured reference, the invoice number as unstructured remittance ≤140; only for EUR, a valid IBAN and an open amount between 0.01 and 999,999,999.99) and the pay link from `PayLinkService::forOpenInvoice()`, both in `InvoiceDocumentData`'s `qr` and both off when the layout's `showQr` is false. They are passed as strings; whether docudesk renders the QR or shillinq passes an SVG is task 1.1. Tests: `EpcQrPayloadTest` (the field order on invoice 2026-0042, EUR24200.00), `InvoiceDocumentDataTest`.

## 3. Currency in generation

- [x] 3.1 Currency from order or customer in `InvoiceGenerationService` at the three fixed places (REQ-SID-004). Verify: PHPUnit for GBP.

## 4. Pages, end to end and strings

- [ ] 4.1 Invoice layout settings page with preview and the contrast check (REQ-SID-001). Verify: `npm run check:manifest`; vitest for the contrast check.
- [ ] 4.2 Playwright `tests/e2e/sales-invoice-document.spec.ts`: set a layout, produce an English GBP invoice and a Dutch EUR invoice with QR. Verify: passes locally with docudesk installed.
- [ ] 4.3 Dutch and English strings, and invoice labels in de and fr. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

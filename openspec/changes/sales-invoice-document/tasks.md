# Tasks: sales-invoice-document

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Foundations

- [ ] 1.1 Read docudesk's template rendering (development) for a QR function and record the finding in the PR; add `bacon/bacon-qr-code` only if there is none (REQ-SID-005). Verify: the finding with file and line in the PR body; `composer audit` if a package is added.
- [ ] 1.2 Add `InvoiceLayout`, `CustomerMaster.language` and `ARInvoice.documentLanguage` in a `register.d` fragment with a default layout per administration (REQ-SID-001, REQ-SID-003). Verify: `npm run check:registers`, `npm run check:seeds`.

## 2. Template and renderer

- [ ] 2.1 Add the `sales-invoice` template to `docudesk-templates.json` with the three variants (REQ-SID-002). Verify: the template validator the repo runs on that file.
- [ ] 2.2 Make `InvoicePdfGenerator::generatePdf()` a docudesk consumer with the absence exception; remove `renderHtml()` and `assemblePdf()` (REQ-SID-002). Verify: PHPUnit with docudesk present and absent.
- [ ] 2.3 Language labels and `NumberFormatter` amounts in the assembled data (REQ-SID-003, REQ-SID-004). Verify: PHPUnit for nl and en with EUR and GBP.
- [ ] 2.4 EPC and pay-link QR payloads (REQ-SID-005). Verify: PHPUnit against the EPC069-12 field order.

## 3. Currency in generation

- [ ] 3.1 Currency from order or customer in `InvoiceGenerationService` at the three fixed places (REQ-SID-004). Verify: PHPUnit for GBP.

## 4. Pages, end to end and strings

- [ ] 4.1 Invoice layout settings page with preview and the contrast check (REQ-SID-001). Verify: `npm run check:manifest`; vitest for the contrast check.
- [ ] 4.2 Playwright `tests/e2e/sales-invoice-document.spec.ts`: set a layout, produce an English GBP invoice and a Dutch EUR invoice with QR. Verify: passes locally with docudesk installed.
- [ ] 4.3 Dutch and English strings, and invoice labels in de and fr. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

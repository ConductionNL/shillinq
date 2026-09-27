# Design: sales-invoice-document

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Renderer.** `lib/Service/InvoicePdfGenerator.php` builds HTML in `renderHtml()` (line 287, `<html lang="nl">` at line 320, `€` literals at 297, 311, 341-343, Dutch `number_format` in `fmtMoney()` line 377) and emits PDF bytes itself (`assemblePdf()` line 238, `buildHybridPdfBytes()` line 173). ADR-075 bans hand-rolled PDF byte emission in a leaf app and names shillinq's reporting stack as a known violation. `InvoiceApiController` (`lib/Controller/InvoiceApiController.php:357`) serves the PDF at `/api/v1/invoices/{id}/pdf`.
- **Docudesk channel.** The open change `reports-via-docudesk` moved the report generators to `DocumentService::generateDocument(templateId, dataRefs, options)`, probing docudesk first and throwing `OCA\Shillinq\Reporting\DocudeskUnavailableException` when it is absent (its design D4). Templates are declared in `lib/Settings/docudesk-templates.json` (`templateId`, `title`, `description`, ...); there is no sales invoice template.
- **Currency.** `ARInvoice.currency` is honoured by the UBL mapper (`lib/Service/EInvoice/ArInvoiceUblMapper.php:92-127`). `InvoiceGenerationService` fixes `'currency' => 'EUR'` at lines 154, 160 and 537.
- **Customer.** `CustomerMaster` (`register.d/add-shillinq-bookkeeping-compliance.json` and fragments) has no language.
- **Payment link.** `PaymentRequest.paymentLink` holds a link when one exists (`register.d/ar-invoice-payment-links.json`); `receivables-payment-links` makes a provider fill it.

## Goals / Non-Goals

**Goals**
- A branded invoice PDF per administration, in the customer's language and the invoice currency, with a scan-to-pay code, rendered through docudesk.

**Non-Goals**
- Sending, creating links, the hybrid PDF, a visual designer.

## Decisions

### D1. The layout is a record per administration

New schema `InvoiceLayout`: `administrationId`, `logoFileId` (a Nextcloud
file reference), `primaryColor`, `accentColor` (hex, checked for WCAG AA
contrast against white), `variant` (`classic`, `modern`, `compact`),
`footerText`, `showQr` (default true). A settings page "Invoice layout"
edits it with a preview.

### D2. One template, three variants, four languages

`docudesk-templates.json` gains `templateId: sales-invoice`, taking the
invoice, its lines, the creditor, the recipient, the layout, the language
and the QR payloads as data. Labels come from translation keys resolved in
the recipient's language before the data is handed over, so the template
holds no language.

### D3. The generator becomes a docudesk consumer

`InvoicePdfGenerator::generatePdf()` assembles the data and calls
`generateDocument('sales-invoice', ...)`, with the same probe and exception
as `reports-via-docudesk`. `renderHtml()` and `assemblePdf()` are removed;
`generateHybridPdf()` stays until docudesk can embed the XML.

### D4. Currency and language come from the invoice and the customer

Amounts are formatted with `NumberFormatter` for the recipient's language
and the invoice currency. `InvoiceGenerationService` takes the currency from
the order or, failing that, `CustomerMaster.currency`, falling back to the
administration's `functionalCurrency`. `CustomerMaster.language` (default
the administration's `defaultLanguage`) is also written on the invoice as
`documentLanguage` for the email in `sales-invoice-sending`.

### D5. Two QR payloads

An EPC069-12 SEPA credit transfer payload (`BCD`, version `002`, charset
`1`, `SCT`, BIC, creditor name, IBAN, `EUR` amount of the open amount,
remittance text the invoice number) when the invoice currency is EUR and
the administration has an IBAN; the pay link URL when a `PaymentRequest`
for the invoice has one. Task 1.1 reads docudesk's template functions: if it
renders a QR from a string, the payloads are passed as data; if not,
shillinq encodes an SVG with `bacon/bacon-qr-code` and passes the SVG.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Layout and language | Declarative: schema fields and a settings page | Data. |
| Rendering | Imperative, a docudesk consumer (ADR-031 exception: document generation) | ADR-075 channel. |
| QR payloads | Imperative, in the generator | A formatted string from invoice data. |

## Seed Data

Adviesbureau Van Dijk: layout `modern`, primary colour `#1B4F72`, accent
`#F39C12`, logo "vandijk-logo.svg", footer "KvK 12345678, IBAN NL00 BANK
0123 4567 89 (placeholder)". Customers: Gemeente Voorbeeld (nl, EUR) and
Brightside Consulting Ltd, London (en, GBP). An invoice to Brightside of
GBP 4,800 renders in English with pound amounts and no EPC QR (not EUR);
an invoice to Gemeente Voorbeeld of EUR 24,200 renders in Dutch with an EPC
QR for EUR 24,200 and reference 2026-0042.

## Risks / Trade-offs

- [Colours that fail contrast] → the settings page refuses a primary colour below 4.5:1 against white (ADR-010, NL Design System).
- [A logo file the recipient cannot load] → the logo is embedded as data, never linked.

## Migration Plan

Each administration gets a default `classic` layout without a logo, which
renders like today's invoice but through docudesk.

## Open Questions

None.

---
kind: code
depends_on: [reports-via-docudesk]
---

# Proposal: sales-invoice-document

## Summary

The invoice a customer receives carries the sender's logo and colours, is
written in the customer's language, shows the invoice's own currency, and
has a code the customer scans to pay. Shillinq's invoice PDF is one fixed
Dutch layout in euros, emitted as hand-built PDF bytes. This change renders
the invoice through docudesk from a template per administration, in the
customer's language and the invoice currency, with a scan-to-pay code.

## Motivation

Four sales rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) all land on the
invoice document. Sales is a core area of the matrix; the OpenSpec pass of
2026-09-27 decided `build` for all four.

**`sal-branded-invoice`**, "Create a sales invoice with your own logo,
colours and layout." Rated partial, built. Matrix evidence:
"lib/Service/InvoicePdfGenerator.php:287-330 renderHtml emits one fixed
layout (inline CSS, Helvetica, #222) with no logo, colour or template
input". All five competitors rate it yes, for example moneybird
(https://helpcenter.moneybird.nl/nl/articles/223823-huisstijl-instellen,
"Per huisstijl stel je in: je bedrijfslogo, het lettertype"), snelstart
(https://kennisplein.snelstart.nl/snelstartpolaris/starten-met-factuursjablonen),
twinfield (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/verkoopfactuur-3041176,
"Een logo toevoegen"), exact-online and odoo
(`addons/web/models/base_document_layout.py:53`, logo and colours).

**`sal-qr`**, "Print a scan-to-pay QR code on the invoice." Rated no, built
state none: "InvoicePdfGenerator.php renders no QR". Two competitors rate
it yes: moneybird
(https://helpcenter.moneybird.nl/nl/articles/207915-qr-code-op-je-facturen,
"Zet een betaal QR-code op je facturen") and odoo
(`addons/account_qr_code_sepa/models/res_bank.py:38`, the EPC SEPA
transfer QR).

**`sal-foreign-currency`**, "Invoice a customer in their own currency."
Rated partial, built: "The AR record and e-invoice honour the currency, the
PDF and the time-and-expense generator do not." Evidence:
"InvoicePdfGenerator.php renderHtml prints a hard-coded '€' and
InvoiceGenerationService.php:154,160,537 fixes currency to EUR". Four
competitors rate it yes: exact-online, moneybird
(https://helpcenter.moneybird.nl/nl/articles/223746-vreemde-valuta-toevoegen),
twinfield (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/valuta-s-3041130)
and odoo (`account_move.py:526`).

**`sal-customer-language`**, "Send invoices and their emails in the
customer's own language." Rated no, built state none: "CustomerMaster has no
language/locale property ...; lib/Service/InvoicePdfGenerator.php:320
hard-codes <html lang=\"nl\"> and Dutch number formatting (:371)". Three
competitors rate it yes: moneybird
(https://helpcenter.moneybird.nl/nl/articles/207102-facturen-versturen-in-andere-talen),
twinfield ("Factuursjablonen in meerdere talen") and odoo
(`addons/account/views/report_invoice.xml:671`, the PDF in the partner's
language).

## Affected Projects

- [ ] Project: `shillinq`: an invoice layout per administration, a sales invoice template in docudesk's template list, the customer's language, currency throughout, and a scan-to-pay code.

## Scope

### In Scope

- `InvoiceLayout` per administration: logo, primary and accent colour, layout variant, footer text.
- The sales invoice PDF rendered through docudesk's template contract, with the layout, the customer's language and the invoice currency.
- `CustomerMaster.language` (nl, en, de, fr) used for the document and handed to the invoice email.
- The time-and-expense generator taking the currency of the customer or the order instead of EUR.
- An EPC SEPA transfer QR on every invoice with an IBAN, and a pay-link QR when the invoice has a payment link.

### Out of Scope

- Sending the invoice by email (`sales-invoice-sending`) and creating payment links (`receivables-payment-links`).
- The Factur-X hybrid PDF (`InvoicePdfGenerator::generateHybridPdf`), which keeps its current path until docudesk offers embedding an XML attachment in a PDF/A-3.
- A drag-and-drop template designer.

## Approach

Follow `reports-via-docudesk`: shillinq assembles the data, docudesk renders
the template, and docudesk's absence is a visible outcome. Details are in
design.md.

## New Dependencies

A QR encoder, only if docudesk's template rendering has no QR function:
`bacon/bacon-qr-code` (BSD-2-Clause, pure PHP), subject to the composer
cooldown (ADR-093). Task 1.1 decides by reading docudesk.

## Impact

- `lib/Service/InvoicePdfGenerator.php`: `generatePdf()` becomes a docudesk consumer.
- `lib/Settings/docudesk-templates.json`: a `sales-invoice` template.
- `lib/Settings/register.d/`: `InvoiceLayout`, `CustomerMaster.language`.
- `lib/Service/InvoiceGenerationService.php`: currency.
- `src/manifest.d/`: an invoice layout settings page.

## Cross-Project Dependencies

- docudesk: `DocumentService::generateDocument(templateId, dataRefs, options)` as `reports-via-docudesk` consumes it (ADR-075). No docudesk change is required unless task 1.1 finds no QR function, in which case shillinq encodes the QR itself.

## Risks

### Risk 1: Invoices cannot be produced without docudesk
**Severity:** Medium. **Mitigation:** the absence is shown on the invoice page with the reason, as `reports-via-docudesk` D4 does for reports; the UBL e-invoice path does not need docudesk and keeps working.

### Risk 2: A QR that pays the wrong amount
**Severity:** Medium. **Mitigation:** the EPC payload is built from the invoice's open amount and number only, and is covered by a test against the EPC069-12 format.

## Rollback Strategy

Switch `generatePdf()` back to the in-process renderer. Layouts and
languages stay as unused data.

## Open Questions

None.

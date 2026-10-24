---
kind: code
depends_on: []
---

# Proposal: sales-invoice-sending

## Summary

An issued sales invoice cannot leave shillinq by email, several invoices
cannot be made and sent in one go, and a customer carries no payment term or
delivery method of its own. This change adds a send action that mails the
invoice PDF to the customer, a batch that creates one invoice per selected
customer and sends them all, and two customer defaults (payment term in days
and delivery method) that every invoice path reads.

## Motivation

Three rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) share one missing
surface: the way out from an issued `ARInvoice` to the customer. The OpenSpec
pass of 2026-09-27 decided `build` for all three
(`openspec/parity/gap-decisions.json`). No row carries a demand origin; the
competitor evidence is the case.

**`sal-email-pdf`**, "Send the invoice to the customer by email as a PDF from
the app." Rated no, built state none. The matrix evidence: "IMailer is used
only in lib/Controller/PaymentRequestActionController.php:123-135 (payment
request mail), ConfirmationMailer and SmsReminderDispatcher; no service or
button mails an invoice PDF. src/components/ar-invoice/arEInvoiceActions.js:64
even tells the user to 'use PDF + email instead' outside the app." Note: "The
PDF can be opened (/api/v1/invoices/{id}/pdf) but not emailed from the app."
All five competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-sales-invoices-slsinv-trackslsinvt, "Met Verzend en volgen kun je een verkoopfactuur per e-mail naar een klant verzenden".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207868-verzenden-per-e-mail, invoices sent by email with subject "Factuur [nummer] van [bedrijfsnaam]" and a PDF attachment.
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/een-verkoopfactuur-maken, "Je kunt de factuur direct per e-mail versturen"; https://www.snelstart.nl/ondernemer/inzicht lists "Facturen afdrukken en mailen" from inStap.
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/een-nieuwe-verk-3041188, "ontvangt de debiteur een e-mail met een PDF bijlage of een e-mail met een link naar de factuur".
- odoo: odoo/odoo@19.0 `addons/account/models/account_move.py:6123` `action_send_and_print`; `addons/account/models/account_move_send.py:816` renders the PDF and mails it.

**`sal-batch`**, "Create and send a batch of invoices in one go." Rated no,
built state none. The matrix evidence: "lib/Controller/InvoiceApiController.php
generate/post/pdf act on one invoice; no batch route in appinfo/routes.php for
invoices; the only multi-invoice generator is RecurringInvoiceGenerator,
triggered by a declaration." The decision adds that the school contribution
bulk raise (`extracurricular-fee-to-shillinq`, REQ-SCON-001) is called by
another app and does not send. Three competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-sales-orders-slsord-invoicet?language=en_GB, "You can also create multiple invoices at once"; https://support.exactonline.com/community/s/article/All-All-HNO-Task-sales-invoices-slsinv-trackslsinvt, "Selecteer een of meerdere facturen ... Klik op Verzenden".
- snelstart: https://www.snelstart.nl/ondernemer/inzicht, "Meerdere relaties in één keer factureren" and "de facturen te maken en versturen".
- odoo: odoo/odoo@19.0 `addons/account/wizard/account_move_send_batch_wizard.py:9` sends many moves in one go.

**`sal-customer-terms`**, "Set default payment terms and delivery method per
customer." Rated no, built state none. The matrix evidence: "CustomerMaster
properties (add-shillinq-bookkeeping-compliance.json, sepa, peppol, icp
fragments) contain no payment term or delivery method; the Customers index
column 'paymentTermDays' in src/manifest.json points at a field that does not
exist on the schema." Note: "Only defaultMandateId and peppolParticipantId
exist per customer; payment terms live per invoice." All five competitors rate
it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-generic-fingen-subelectroinvviasimplrinvt, set the "Methode voor versturen facturen" per customer; https://start.exactonline.nl/docs/HlpRestAPIResources.aspx lists `cashflow/PaymentConditions`.
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207517-instellingen-bij-contacten, a fixed "Verzendmethode" per contact and a workflow that sets "het vervaltermijn".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/starten-met-herinneringen-en-aanmaningen, "Vul ook de betalingstermijn in"; https://kennisplein.snelstart.nl/snelstartpolaris/incassobestanden, "Stel per klant de betalingstermijn en incasseren in".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/verkoopfactuur-3041176, "de instellingen in de betalingscondities op de klant"; https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/instellingen-vo-3041174, "E-mailadres voor facturering".
- odoo: odoo/odoo@19.0 `addons/account/models/partner.py:559` `property_payment_term_id` and `:577` `invoice_sending_method` per customer.

This change covers all three rows.

## Affected Projects

- [ ] Project: `shillinq`: a send service and action on `ARInvoiceDetail`, a batch send and a batch create on `AccountsReceivable`, two `CustomerMaster` fields and the invoice paths that read them.

## Scope

### In Scope

- Send one issued `ARInvoice` by email with its PDF attached, from `ARInvoiceDetail`, and record when, to whom and how it was sent.
- Route a send by the customer's delivery method: email mails the PDF, peppol hands over to the existing `EInvoiceService`, post and none record that nothing was mailed.
- Send a selection of issued invoices from `AccountsReceivable` in one action, as a queued job that reports an outcome per invoice.
- Create a batch: one set of lines and a list of customers become one invoice per customer, idempotent per customer, then optionally issued and sent.
- `CustomerMaster.paymentTermDays`, `CustomerMaster.deliveryMethod` and an optional `CustomerMaster.invoiceEmail`, editable on `CustomerDetail`.
- The due date of a new invoice follows the customer's payment term in the quick draft, the time-and-expense generator and a new recurring profile.
- The `Customers` index columns point at fields `CustomerMaster` declares.
- The recurring profile's `deliveryChannel = email` reaches the send path for an auto-issued invoice.

### Out of Scope

- The invoice document itself (layout, logo, language, QR code). That is `sales-invoice-document` in the same OpenSpec pass.
- The Peppol hand-off to integriq. That is `sales-einvoice-exchange`.
- Postal delivery. A `post` customer is recorded, not mailed.
- Read receipts or open tracking of a sent mail.
- Moving the school contribution raise onto the new batch. It already works for its callers.

## Approach

One service, `InvoiceSendingService`, sends one invoice. It resolves the
recipient from the customer, renders the PDF through the existing
`InvoicePdfGenerator`, mails it through Nextcloud's `IMailer` and writes the
outcome onto the invoice. The batch send is a `QueuedJob` that calls it per
invoice. The batch create follows the shape the school contribution raise
proved: a caller-supplied charge and a list of debtors, one invoice each,
idempotent on a batch id and customer, at most 200 per call. The customer
defaults are two schema fields with defaults, read where each path sets a
due date. Details are in design.md.

## New Dependencies

None. `IMailer` is Nextcloud's own mailer and is already injected in
`PaymentRequestActionController`.

## Impact

- Schemas: `CustomerMaster` gains three optional fields; `ARInvoice` gains a `sending` group and `InvoiceBatch` is added (all additive).
- Code: new `InvoiceSendingService`, `InvoiceBatchService`, `SendInvoicesJob` and `InvoiceSendingController`; changes in `InvoiceGenerationService`, `InvoiceQuickDraftModal.vue` and the recurring profile modal.
- Manifest: a send action on `ARInvoiceDetail`, a selectable `AccountsReceivable` with a bulk send action, a batch page, corrected `Customers` columns.
- API: three new routes.

## Cross-Project Dependencies

None. The Peppol branch of the router calls the existing
`EInvoiceService::sendEInvoice`; whether that reaches integriq is
`sales-einvoice-exchange`.

## Risks

### Risk 1: An invoice is mailed twice
**Severity:** Medium. **Mitigation:** the send records `sending.sentAt`; a second send of the same invoice asks for an explicit resend, and the batch job skips an invoice that already carries `sentAt` unless the batch says resend.

### Risk 2: A mail server that silently accepts and drops
**Severity:** Medium. **Mitigation:** `IMailer::send` failures are recorded per invoice as `sending.lastError` and shown on the batch outcome; nothing reports sent without `IMailer` returning.

### Risk 3: A wrong payment term on existing invoices
**Severity:** Low. **Mitigation:** the term is a default for new invoices only; issued invoices keep their due date.

## Rollback Strategy

Revert the PR. The schema changes are additive, invoices already sent keep
their `sending` group as history, and the send action disappears.

## Open Questions

- Should the email body carry the payment link from `receivables-payment-links` once that lands? This change leaves a placeholder in the template for it.

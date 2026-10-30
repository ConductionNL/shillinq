---
kind: code
depends_on: []
---

# Proposal: receivables-sepa-direct-debit

## Summary

Shillinq has the SEPA direct debit data model and its scheme rules, but no screen reaches it and no code writes the pain.008 file a bank needs. This change adds the mandate and collection pages, proposes a batch from open invoices whose customer has an active mandate, writes and validates the pain.008 file, and marks an invoice paid when its collection succeeds.

## Motivation

The build-all pass of 2026-09-29 decided `build` for these `building` rows of
the shillinq capability matrix (`openspec/parity/capabilities.json`), because
no change covered their missing half. Each row keeps `built.state: building`
with `built.change` naming this change until it ships.

**`rec-direct-debit`**, "Collect from customers by SEPA direct debit under a mandate." Shillinq rated no, built state `building`. The matrix evidence for the built half: "SepaMandate, DirectDebitCollection, DirectDebitBatch (pain008Xml field) schemas in lib/Settings/register.d/bookkeeping-sepa-direct-debit.json with guards (lib/Lifecycle/MandateGuard.php, SequenceTypeGuard.php) and MandateDormancyExpiryJob, but no manifest page uses any of them and no code writes a pain.008 file (grep pain.008 in lib: none); the Mandates page is the commitments register, not SEPA"

- exact-online (yes): https://www.exact.com/nl/producten/boekhouden/features-en-prijzen: "Exact Online ondersteunt Europese incasso om betalingen van klanten uit andere landen te incasseren, conform de SEPA-standaarden"; https://start.exactonline.nl/docs/HlpRestAPIResources.aspx lists cashflow/DirectDebitMandates
- moneybird (yes): https://helpcenter.moneybird.nl/nl/articles/207903-facturen-automatisch-incasseren-via-sepa-incasso : 'Heb je SEPA-incasso ingesteld? Dan kun je facturen automatisch incasseren ... van factuur verzenden tot batch verwerken'; mandates can be requested (changelog 'bepaal-betaalmethoden-bij-het-uitvragen-van-een-incassomandaat'); pricing lists SEPA-incasso in every package
- snelstart (yes): https://kennisplein.snelstart.nl/snelstartpolaris/incassobestanden : 'Vanaf het pakket inBalans kun je in SnelStart Polaris incassobestanden aanmaken ... CORE (voor particulieren en bedrijven) of B2B' with the creditor Incasso-ID, per customer incasso setting
- twinfield (yes): https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/betalen, -incas-3041200: "Kies het gewenste betaaltype . Standaard is dit SEPA Incasso Nederland ... Machtigingskenmerk : Vul een uniek kenmerk voor de incassomachtiging in"; https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/betalen, -incas-3041054 creates the collection run
- odoo (yes): https://www.odoo.com/documentation/19.0/applications/finance/accounting/payments/sepa_payments.html SDD mandate 'a legal document authorizing a company to debit funds', collections in a batch that generates a PAIN.008.001.02 XML; Community only references it (odoo/odoo@19.0 addons/account_edi_ubl_cii/models/account_edi_cii.py:619 sdd_mandate_id if installed; toggle module_account_sepa_direct_debit

## What is already built

- `SepaMandate`, `DirectDebitCollection`, `DirectDebitBatch`, `RTransaction`, `PreNotification` and the `ARInvoice.paymentMethod` and `directDebitMandateId` fields (`lib/Settings/register.d/bookkeeping-sepa-direct-debit.json`).
- Guards: `lib/Lifecycle/MandateGuard.php`, `SequenceTypeGuard.php` (first or recurring), `PreNotificationGuard.php`; `lib/BackgroundJob/MandateDormancyExpiryJob.php` expires mandates unused for 36 months; `lib/Service/SepaAuditService.php`.
- The main spec `bookkeeping-sepa-direct-debit` already requires pain.008.001.02 generation (REQ-SDD-005); nothing implements it (no `pain.008` in `lib/`).
- The payment run writes pain.001 through `lib/PaymentRun/Generator/SepaPain001Generator.php`, the model for the new generator.

## What this change adds

- Pages `SepaMandates`, `DirectDebitBatches` and batch detail with its collections, under Receivables.
- `DirectDebitBatchService::propose` builds a batch of collections for open invoices with `paymentMethod: direct-debit` and an active mandate, due by the collection date, with the sequence type from `SequenceTypeGuard`.
- `SepaPain008Generator` writes the pain.008.001.02 file into `DirectDebitBatch.pain008Xml`, validated against the XSD, with the creditor identifier from the administration settings.
- A succeeding collection registers the payment on its invoice through `InvoiceSettlementService`; a rejected one leaves the invoice open and records the reason.

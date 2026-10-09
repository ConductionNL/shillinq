---
kind: code
depends_on: [ledger-posting-path, banking-payment-run]
---

# Proposal: purchasing-supplier-invoice-intake

## Summary

A UBL invoice imports without typing, but only an invoice backed by a
purchase order ever reaches the ledger, after a three-way match. An invoice
without an order stays received for good. A typed invoice is not checked for
a duplicate number, and nothing compares the bank account on an invoice with
the one on the supplier record. This change books an invoice without an
order through the AP sub-ledger, warns on a duplicate number however the
invoice arrived, and holds payment of an invoice whose IBAN differs from the
supplier's until someone verifies it.

## Motivation

Three rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the supplier invoice intake.
The OpenSpec pass of 2026-09-27 decided `build` for all three. This change
covers all three.

**`pur-ubl-import`**, "Import a supplier's UBL e-invoice and book it without
typing." Rated partial, built. The matrix evidence:
"lib/Controller/SupplierInvoiceImportController.php:190 importUbl ->
SupplierInvoiceService::ingestUBLInvoice (SupplierInvoiceService.php:186)
saves a SupplierInvoice with statusCode=received; no GL posting on import,
the ledger entry only comes from GRIRClearingListener on SupplierInvoice
matching->matched (3-way match)". The note: "Import without typing works;
'book it' only happens for PO-backed invoices after a 3-way match, a non-PO
invoice is never posted automatically." No demand row. Four competitors
rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Met Exact Online kun je elektronische facturen inlezen en aanmaken ... UBL, Peppol BISv3 en SI-UBL 2.0".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207121-ubl-facturen-verwerken, "Moneybird ondersteunt UBL 2.1".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/inkoopfacturen-automatisch-inlezen, "Inkoopfactuur als UBL-bestand inlezen".
- odoo: odoo/odoo@19.0 `addons/account_edi_ubl_cii/models/account_edi_common.py:564` `_import_invoice_ubl_cii` builds the bill from a UBL file.

**`pur-duplicate-check`**, "Be warned when a supplier invoice number was
already booked for that supplier." Rated partial, built: "duplicateExists()
rejects a UBL import whose invoice number already exists for that supplier
(HTTP 409) ... Manual entry on SupplierInvoices has no such check." The
note: "Only on UBL import, not on a typed invoice." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. Two competitors
rate it yes:

- moneybird: https://www.moneybird.nl/changelog/waarschuwing-bij-documenten-met-hetzelfde-kenmerk/, "Moneybird waarschuwt je nu ook op het overzicht Inkomend voor documenten van hetzelfde contact met dubbele kenmerken".
- odoo: odoo/odoo@19.0 `addons/account/models/account_move.py:759` `duplicated_ref_ids`, and https://www.odoo.com/odoo-19-release-notes, "the warning banner remains visible even after posting".

**`pur-iban-check`**, "Be warned when a supplier invoice names a different
bank account than the supplier record." Rated no, built state none. The
note: "SupplierQualification stores an iban, but nothing compares an
incoming invoice's bank account with it; the only IBAN matching is the
one-off repair lib/Repair/MigrateProductVendorMasterToPipelinq.php:344."
Tender demand: https://www.tenderned.nl/aankondigingen/overzicht/416109. No
competitor rates it yes; odoo and moneybird are partial (odoo holds payment
to a new account at `addons/account/models/res_partner_bank.py:293`).

## Affected Projects

- [ ] Project: `shillinq`: the UBL parser, the supplier invoice schema and pages, one transition and one action handler on `SupplierInvoice`, a mapper for `APTransaction` in the posting handler, and two checks shared by import and typed entry.

## Scope

### In Scope

- Resolving the supplier of an imported invoice to a `Payee` by KvK or VAT number.
- Reading the payee IBAN from the UBL `PaymentMeans`.
- Coding each line of an invoice without an order to an expense account, defaulting from the payee.
- A "Book without order" transition that hands the invoice to the AP sub-ledger as an `APTransaction`, which is issued and posted.
- One duplicate check, used by the UBL import, the CSV import and a typed invoice, shown as a warning on the invoice and required to be acknowledged before booking.
- One IBAN check against the payee record and `SupplierQualification`, shown as a warning, which payment blocks the resulting AP transaction until someone releases it.

### Out of Scope

- Receiving invoices over Peppol. The access point is integriq's (`peppol-access-point-connector`, ADR-091).
- OCR of PDF invoices. That is filinq's extraction, already consumed (`receipt-extraction-consume`).
- Making invoices backed by an order payable through payment runs. They settle through `SupplierInvoice.pay` today; see Open Questions.
- Checking an IBAN against the bank's account holder (SurePay style). That is an external service and would be integriq's.

## Approach

The two checks move out of the import controller into one service each.
"Book without order" is a declared lifecycle transition with a guard and an
action; the action writes the `APTransaction`, whose `issue` posts through
the `materialise-gl-transaction` handler with a new `APTransaction` mapper.
Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/SupplierInvoiceService.php`: payee resolution and IBAN parsing.
- `lib/Service/Purchasing/SupplierInvoiceChecks.php` (new): the duplicate and IBAN checks.
- `lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json`: fields and one transition on `SupplierInvoice`.
- `lib/Lifecycle/Action/HandToAccountsPayableAction.php` (new) and an `APTransaction` mapper in the handler `ledger-posting-path` adds.
- `src/manifest.json` `SupplierInvoices` and `SupplierInvoiceDetail`: warning columns, the coding fields and the transition button.

## Cross-Project Dependencies

- `ledger-posting-path` (open change in this repo): the `materialise-gl-transaction` handler that the `APTransaction` mapper plugs into.
- `banking-payment-run` (open change in this repo): makes `APTransaction.issue` passable and adds the `paymentBlocked` field the IBAN check sets.
- OpenRegister: the lifecycle engine as it stands. No change needed there.

## Risks

### Risk 1: A PO-backed invoice is posted twice
**Severity:** High. **Mitigation:** "Book without order" is refused when any line links to a purchase order; those invoices keep the three-way match and GR/IR path.

### Risk 2: A fraudulent IBAN change is paid
**Severity:** High. **Mitigation:** a mismatch payment blocks the AP transaction with the reason, and `banking-payment-run` refuses to export a blocked line. Releasing the block is audit-trailed.

### Risk 3: A real second invoice with the same number is held up
**Severity:** Low. **Mitigation:** the duplicate check warns and asks for a reason; it does not delete or reject a typed invoice.

## Rollback Strategy

Remove the transition from the schema fragment and the action registration.
Invoices already handed over stay as valid AP transactions. The checks can
stay: they only add warnings.

## Open Questions

- Should an invoice backed by an order also become an `APTransaction` after its three-way match, so that it is payable through a payment run? Its ledger entry already comes from GR/IR clearing, so the hand-over would have to skip posting. Left for a follow-up change.

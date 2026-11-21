---
kind: code
depends_on: []
---

# Proposal: tax-vat-number-check

## Summary

Before a bookkeeper books an intra-EU sale at zero percent or reclaims VAT
on a foreign purchase, the other party's VAT number has to be valid in the
EU's VIES register. Shillinq checks it only for a customer, only when an
e-invoice is sent, and the lookup it has for a person to use is not on any
page. This change puts the check on the customer and supplier records,
runs it when a supplier invoice arrives, and shows the result where the
number is used.

## Motivation

One tax row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`tax-vat-number-check`**, "Check a customer's or supplier's VAT number
against the EU VIES register." Rated partial, built. Matrix evidence:
"lib/Service/EInvoice/EInvoiceValidationService.php:6 runs the VIES VAT
number check through lib/Service/ViesService.php before an e-invoice is
sent, from the Send e-invoice button (see sal-peppol-send); the standalone
lookup route /api/icp/vat-id-lookup (appinfo/routes.php:482) is not called
from src/." Note: "Checked for customers when sending an e-invoice only; not
for suppliers." Changelog demand:
https://www.snelstart.nl/productnieuws/snelstart-polaris-release-notes-30-oktober-snelstart.
Three competitors rate it yes:

- moneybird: https://helpcenter.moneybird.nl/nl/articles/207677-icp-aangifte-doen-vanuit-moneybird, "Moneybird zal automatisch het ingevulde btw nummer voor je controleren".
- snelstart: https://www.snelstart.nl/changelog/controleer-een-btw-nummer-met-de-vies-check.
- odoo: odoo/odoo@19.0 `addons/base_vat/models/res_partner.py:202` `_compute_vies_valid` checks partners against VIES.

## Affected Projects

- [ ] Project: `shillinq`: a VIES check on customer and supplier records, on incoming supplier invoices, and the result shown where the number is used.

## Scope

### In Scope

- A Check VAT number action on the customer detail page and on the supplier financial profile, calling the existing lookup through `ViesService`.
- `VendorMaster.vatId` with the same validation fields `CustomerMaster` already has, and `SupplierInvoice.sellerVatId`.
- A check when a supplier invoice carrying a foreign EU VAT number is received.
- The result (valid, invalid, not reachable, with the date) shown on the record and on invoices that use it.
- Folding `CustomerMaster.vatID` into `vatId`.

### Out of Scope

- Blocking a posting on an invalid number. The result is shown; the ICP and zero-rate checks already decide.
- Non-EU tax number registers.

## Approach

Reuse `ViesService` (its outage handling and 30-day reuse stay), put a button
on two pages, and call it from the supplier invoice intake. Details are in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-icp-opgaaf.json` and the supplier fragments: fields.
- `lib/Controller/IcpController.php`: `lookupVatId` reached from two pages.
- `lib/Service/SupplierInvoiceService.php`: the intake check.
- `src/manifest.json`: `CustomerDetail` and the vendor profile page.

## Cross-Project Dependencies

None. Supplier identity lives in pipelinq (spec `shillinq-product-vendor-to-pipelinq`); the VAT number sits on shillinq's financial profile `VendorMaster`, keyed by the contact.

## Risks

### Risk 1: VIES is often unreachable
**Severity:** Low. **Mitigation:** `ViesService` already reuses a valid result younger than 30 days during an outage and queues a retry (`ViesOutageRetryJob`); the page shows "not reachable, last valid on" instead of invalid.

## Rollback Strategy

Hide the actions and stop the intake call. Stored results stay.

## Open Questions

None.

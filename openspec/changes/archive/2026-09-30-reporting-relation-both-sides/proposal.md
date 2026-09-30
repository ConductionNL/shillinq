---
kind: code
depends_on: []
---

# Proposal: reporting-relation-both-sides

## Summary

When an organisation both buys from and sells to a business, shillinq keeps it
twice, once as a customer and once as a supplier, and nothing shows the two
sides together. This change links the customer record and the supplier record
of the same organisation, suggested from equal KvK or VAT numbers and confirmed
by a user, and shows every invoice sent to and received from that relation with
the open amounts on both sides and the net position, on both record pages and
in a report of all such relations.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). The OpenSpec pass
of 2026-09-27 decided `build` (`openspec/parity/gap-decisions.json`).

**`rep-relation-both-sides`**, "See every invoice sent to and received from a
relation that is both customer and supplier." Rated no, built state none.
Matrix note: "Customers (CustomerMaster) and suppliers are separate records and
no report joins sent and received invoices per relation." Demand: tender
https://www.tenderned.nl/aankondigingen/overzicht/416109. Two competitors rate
it yes:

- moneybird: https://helpcenter.moneybird.nl/nl/articles/207977-omzet-per-contact-en-kosten-per-contact, "Omzet per contact" and "Kosten per contact" for the same contact, as one contact record serves "klanten ... en leveranciers".
- odoo: odoo/odoo@19.0 `addons/account/views/account_menuitem.xml:33` Journal Items filtered on one partner shows its receivable and payable lines together; a Partner Ledger action with both trade filters at `addons/account/views/account_move_views.xml:1908`.

This change covers the row.

## Affected Projects

- [ ] Project: `shillinq`: a link between `CustomerMaster` and `Payee`, a suggestion list, a both-sides read service and endpoint, a section on both detail pages, and a report card.

## Scope

### In Scope

- A link from a `CustomerMaster` to the `Payee` of the same organisation, one to one per administration.
- Suggested links where KvK number or VAT number are equal, confirmed or dismissed by a user, and linking by hand.
- A both-sides view on `CustomerDetail` and `PayeeDetail`: invoices sent (`ARInvoice`), invoices received (`APTransaction`, `SupplierInvoice`), open receivable, open payable and net position.
- A report of all linked relations for a period: sales, purchases, open receivable, open payable, net, exportable as CSV.
- Each side shown only to a user who may read that side's invoices.

### Out of Scope

- Offsetting open receivables against open payables (verrekenen). The report shows the net; booking an offset is a separate capability.
- Merging the two records into one party record. OpenRegister's master-data surface (ADR-045) is where a single party would live.
- `BillableInvoice` and the quote-order `Invoice` schema; both reach the ledger through `ARInvoice` or not at all.

## Approach

`CustomerMaster` gains `payeeId`. A `RelationLinkService` proposes links by
equal identifiers and writes a confirmed one. `RelationBothSidesService` reads
the invoices of both records within the administration and a period, under
the caller's RBAC per schema, and totals them; one endpoint serves the two
detail sections and the report. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `CustomerMaster` gains `payeeId`, `payeeLink` and `dismissedPayeeSuggestions` (additive).
- Code: new `RelationLinkService`, `RelationBothSidesService`, `RelationController`.
- Manifest: a Both sides section on `CustomerDetail` and `PayeeDetail`, a Relations both ways card on the Reports page with its page, a suggestions list.
- API: `GET /api/relations/both-sides` and `GET /api/relations/{customerId}/both-sides`.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Two different organisations linked by a shared number
**Severity:** Medium. **Mitigation:** a suggestion is never applied without a user confirming it; the link shows which number matched and who confirmed it.

### Risk 2: A user sees purchase invoices they may not read
**Severity:** High. **Mitigation:** the service reads each side through OpenRegister with the caller's RBAC; a side the caller may not read is left out and the page says so.

## Rollback Strategy

Revert the PR. The `payeeId` field stays on customers and nothing reads it.

## Open Questions

- Should the report include relations that are only suggested, not confirmed? This change includes confirmed links only.

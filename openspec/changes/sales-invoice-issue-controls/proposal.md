---
kind: code
depends_on: []
---

# Proposal: sales-invoice-issue-controls

## Summary

Two controls sit on the moment a sales invoice goes out. It gets the next
number of an unbroken series, which the tax office requires, and above a set
amount a second person approves it first. Shillinq numbers only the
time-and-expense invoice, from a count of records that breaks on a deletion
or two users at once, and leaves the AR invoice number to be typed; it has no
approval step for sales invoices at all. This change assigns the number at
issue from a locked sequence and adds an approval step with a four-eyes
check.

## Motivation

Two sales rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). Sales is a core
area of the matrix; the OpenSpec pass of 2026-09-27 decided `build` for both.

**`sal-numbering`**, "Number invoices in an unbroken sequence without doing
it by hand." Rated partial, built. Matrix evidence:
"lib/Service/InvoiceGenerationService.php:760-772 numbers BillableInvoice as
BIL-<year>-<count+1> from a findAll count per administration (not a locked
sequence; a deleted or concurrent invoice breaks it, count is not per year);
ARInvoice.invoiceNumber is a free text field". All five competitors rate it
yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-sales-invoices-slsinv-detslsinvnrt, the invoice number is assigned when the invoice is made.
- moneybird: https://helpcenter.moneybird.nl/nl/articles/223818-correcte-factuurnummering, "Moneybird nummert de eerste factuur ... automatisch met het huidige jaartal en volgnummer 0001".
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/een-verkoopfactuur-maken, the invoice number field is filled automatically.
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/instellingen-vo-3041174, "Factuurnummers worden ingesteld met behulp van een tijdspanne ... Voorvoegsel ... Jaar".
- odoo: odoo/odoo@19.0 `addons/account/models/sequence_mixin.py:425` assigns the next number on post, and `account_move.py:797` `_query_has_sequence_holes` detects gaps.

**`sal-approval`**, "Have an invoice approved by a second person before it
goes out." Rated no, built state none. Matrix evidence: "ARInvoice lifecycle
states draft/issued/paid/overdue/disputed/written-off have no approval
state". exact-online rates yes
(https://support.exactonline.com/community/s/article/All-All-HNO-Task-sales-orders-slsord-invoicet?language=en_GB,
sales invoice approval on the Professional plan); odoo is partial
(`account_move.py:317`, a "Reviewed" flag set after posting).

## Affected Projects

- [ ] Project: `shillinq`: an invoice number sequence per administration, number assignment at issue, an approval step before issue, and a gap report.

## Scope

### In Scope

- `InvoiceNumberSequence` per administration: a pattern with year and counter, reset per fiscal year.
- The number assigned when an AR invoice is issued, and when a time-and-expense invoice is generated, under a lock.
- The invoice number read-only once assigned.
- `awaiting-approval` and `approved` states on `ARInvoice`, required above the administration's approval threshold, approved by someone other than the creator.
- A check listing gaps and duplicates in a year's numbers.

### Out of Scope

- Separate series per journal or per branch beyond one sequence per administration and invoice kind.
- Multi-step approval chains for sales invoices; one approver is what the row asks for.

## Approach

A lifecycle action on `issue` takes the next number under a lock. Approval is
two transitions and a guard on `issue`. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`: `ARInvoice` states and transitions; a new fragment for `InvoiceNumberSequence` and the threshold setting.
- `lib/Lifecycle/`: the numbering action and the approval guard.
- `lib/Service/InvoiceGenerationService.php`: `generateInvoiceNumber()` uses the sequence.
- `src/manifest.json` `ARInvoiceDetail`: the new buttons.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Existing invoices carry typed numbers
**Severity:** Medium. **Mitigation:** the sequence of each administration starts after the highest number of the current year found in its issued invoices; typed numbers of earlier years stay.

### Risk 2: An issue fails after the number is taken
**Severity:** Low. **Mitigation:** the number is taken inside the transition; a failed transition rolls back the counter under the same lock, and the gap report shows any gap that still occurs.

## Rollback Strategy

Remove the numbering action and the approval guard; the fields and states
stay unused.

## Open Questions

None.

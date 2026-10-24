---
kind: code
depends_on: [hours-to-humaniq]
---

# Proposal: sales-time-and-expense-billing

## Summary

The Generate invoice page turns hours and expenses into invoice lines, but
only after the user types their record ids into two text boxes, and an expense
passed on to a client is always billed at cost because the markup rules are
kept and never applied. This change replaces the text boxes with a list of the
unbilled hours on the chosen project and the unbilled pass-through expenses of
the chosen customer, applies the pass-through markup rules to each expense,
and bills the expense at the VAT rate that applies to a recharge.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) meet on the Generate
invoice page. The OpenSpec pass of 2026-09-27 decided `build` for both
(`openspec/parity/gap-decisions.json`). Neither row carries a demand origin.

**`sal-time-to-invoice`**, "Turn logged hours and expenses into invoice
lines." Rated partial, built. The matrix evidence:
"lib/Service/InvoiceGenerationService.php:90-130 draftInvoice loads
UrenRegistratie (:511) and ExpenseClaimEntry (:570) records, rates them via
RateCardResolver and writes BillableInvoiceLine rows;
src/components/invoice/InvoiceGenerator.vue:103,211 takes time-entry and
expense ids as comma-separated text typed by the user". Note: "The conversion
works, but the user must paste record ids; there is no picker or list of
unbilled hours and expenses." Three competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/projectadministratie/features-en-prijzen, "Je kunt eenvoudig gewerkte uren vanuit de urenregistratie in een heldere en transparante factuur omzetten"; https://support.exactonline.com/community/s/article/All-All-HNO-Concept-psa-timebilling-psatb-invoiceproposalsc, approved hour and cost entries become invoice proposals.
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207503-geregistreerde-uren-factureren, "zoekt Moneybird naar de uren waarvoor nog geen factuur gemaakt is".
- odoo: odoo/odoo@19.0 `addons/sale_timesheet/models/sale_order_line.py:11` invoices logged hours; `addons/sale_expense/models/account_move_line.py:16` re-invoices expenses.

**`exp-reinvoice`**, "Pass an expense on to a client, with or without a
markup." Rated partial, built. The matrix evidence:
"src/components/invoice/InvoiceGenerator.vue:102 takes 'Expense IDs
(comma-separated)' -> InvoiceGenerationService.php:570 loadExpenses bills
ExpenseClaimEntry cost; BillingModelEngine.php:53 supports markupPercent but
InvoiceGenerationService.php:199 only copies a line markup (default 0) and
PassThroughMarkupRule is read only by the unregistered
ExpenseReimbursementGuard". Note: "Passing an expense on at cost works by
typing its id; markup rules are kept but never applied." Two competitors rate
it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Content-rn-whatsnewlandingpage, "Je kunt nu inkoopboekingen doorbelasten om projectgerelateerde kosten aan een klant door te berekenen via projectfacturatie".
- odoo: odoo/odoo@19.0 `addons/sale/models/product_template.py:22` `expense_policy` (at cost or sales price) and `addons/sale_expense/models/account_move_line.py:16`.

design.md corrects one detail of the second row: the markup rule is read by
`SettlementGuard::matchMarkupRule()`, which nothing calls, and the declared
lookup that should fill `markupRateApplied` cannot be evaluated by
OpenRegister, so `ExpenseReimbursementGuard` reads a field nothing writes.

This change covers both rows. Hours are humaniq's under ADR-107 decision 6, so
the hours half reads them the way `hours-to-humaniq` decides.

## Affected Projects

- [ ] Project: `shillinq`: a billable-work endpoint, a picker on `BillableInvoiceGenerate`, a markup resolver, item-level expense lines and the recharge VAT rule.

## Scope

### In Scope

- A list of unbilled hours for the chosen project and period, with the rate and amount each would bill at, to tick instead of typing ids.
- A list of unbilled pass-through expense items (receipts, mileage, per diem) for the chosen customer, with cost, markup and amount.
- Applying `PassThroughMarkupRule` by its priority, percentage or fixed, and locking the applied rate when the expense claim is submitted.
- Billing an expense at cost when no rule matches.
- The VAT rate of a recharged expense: the rate of the main supply, or outside the VAT base for a disbursement paid in the client's name.
- Item-level expense references on `BillableInvoice`, so one claim can be billed in parts.

### Out of Scope

- Where hours are recorded. `hours-to-humaniq` moves them to humaniq.
- Approving hours. The picker lists hours the hours source marks approved.
- New billing models.
- Posting the expense claim to the ledger (`ledger-posting-path` and `expenses-category-mapping` in this OpenSpec pass).

## Approach

A read endpoint gathers billable work: hours through one `BillableHoursSource`
interface, whose implementation `hours-to-humaniq` supplies, and pass-through
expense items from shillinq's own register, both minus what a draft or posted
`BillableInvoice` already references. The Vue page shows two tick lists and
sends the ticked references to the existing `draftInvoice()`. A
`PassThroughMarkupResolver` takes the priority logic out of `SettlementGuard`
and runs at claim submit and at billing. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `BillableInvoice` gains `expenseItemRefs`; `BillableInvoiceLine` gains `recharge` (`markupRate`, `markupAmount`, `costAmount`, `vatTreatment`) (additive). The unevaluable `markupLookup` calculation on the expense items is replaced by a lifecycle action on `ExpenseClaimEntry.submit`.
- Code: new `BillableWorkService`, `BillableHoursSource`, `PassThroughMarkupResolver`; changes in `InvoiceGenerationService::loadExpenses()`, `BillingModelEngine::expenseLine()`, `InvoiceGenerator.vue`; `SettlementGuard::computeMarkupAmount()` delegates to the resolver.
- API: `GET /api/v1/invoices/billable-work`.

## Cross-Project Dependencies

- humaniq, through `hours-to-humaniq` (this repo): task 3.3 of that change ("Shillinq reads hours from humaniq for the ledger, in the shape task 1.1 chose") provides the hours read that `BillableHoursSource` wraps. This change needs a billable marker and an approval state on the hour; if humaniq's `TimeEntry` does not carry them, `hours-to-humaniq` has to add them.
- planninq owns projects (`UrenRegistratie.projectId` names a planninq project). No change there.

## Risks

### Risk 1: Hours billed twice across the move to humaniq
**Severity:** High. **Mitigation:** "unbilled" is computed from references on `BillableInvoice`, which keep working whichever app holds the hour, and `hours-to-humaniq` keeps the old read path until the new one gives the same numbers.

### Risk 2: A markup rate changes after the client was told the price
**Severity:** Medium. **Mitigation:** the rate is locked on the expense item when the claim is submitted, as the schema already intends (`lockOn: ExpenseClaimEntry.submit`), and billing reads the locked rate.

### Risk 3: Wrong VAT on a recharge
**Severity:** Medium. **Mitigation:** the default follows the main supply, a disbursement must be marked as such by the user, and the invoice line shows which treatment was used.

## Rollback Strategy

Revert the PR. The id text boxes return; invoices already drafted keep their
lines and references.

## Open Questions

- Does humaniq's `TimeEntry` carry a billable flag, or does billability follow the planninq project? `hours-to-humaniq` answers this in its design.

---
kind: code
depends_on: []
---

# Proposal: planning-commitment-year-end

## Summary

Two tender rows ask what happens to a commitment at the end of its life and
at the end of the year: the last invoice on an order should release what is
left, and open commitments should move to the next fiscal year under their
own number. Neither can work today, because a commitment cannot be
committed at all: its transitions name guard tags and an action nobody
registered, so they abort. This change registers those first, records
invoiced amounts against the commitment, closes it on a last invoice, and
carries open lines over at year end.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the commitment register. The
OpenSpec pass of 2026-09-27 decided `build` for both. This change covers
both. The matrix row `pln-commitments` itself is deferred, but its missing
guards block both rows, so registering them is a prerequisite task here.

**`pln-commitment-carryover`**, "Carry open commitments over to the next
fiscal year under their original number." Rated no, built state none. The
note: "CommitmentLine carries financialYear, but no transition, job or
service moves open remainders to the next year; searched carry, rollover,
overhevel, doorschuiven." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. No competitor
rates it yes; odoo is no ("An open PO simply stays open across years;
there is no commitment number to carry").

**`pur-last-invoice-close`**, "Mark an approved invoice as the last one on
an order and have the remaining commitment released." Rated no, built state
none. The note: "Commitment has a manual afsluiten transition that releases
the remainder (lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json:321),
but no invoice approval marks a last invoice or triggers it, and a
commitment only reaches committed through goedkeuren whose guard tags abort
(see pln-commitments)." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. No competitor
rates it yes; odoo is partial (`addons/purchase/models/purchase_order.py:651`
`button_lock` freezes an order by hand).

## Affected Projects

- [ ] Project: `shillinq`: guard and action registrations, the commitment lifecycle, an invoice-to-commitment listener, a year-end carry-over service with its page action.

## Scope

### In Scope

- Registering `MandateEnforcer::requiresApproval` and `BudgetBlocker::canCommit` as resolvable guard tags, and a handler for the `record-mutatie` action.
- Recording an `invoiced` movement against the commitment when an invoice on its order is booked.
- A "last invoice" mark on a supplier invoice that closes the commitment and releases the remainder to the budget.
- Letting `afsluiten` run from every open state, not only committed.
- A year-end carry-over of open commitment lines into the next fiscal year, with a preview and a list of lines the next year's budget cannot absorb.

### Out of Scope

- The budget amendment that funds carried-over commitments. That is a `Begrotingswijziging`, and its screen is `planning-budget-editing`.
- Delivery and payment movements on the commitment.
- The commitment approval chain itself (REQ-VPL-013, already declared).

## Approach

Make the declared lifecycle resolvable, then add two small pieces of
behaviour on top of it. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/AppInfo/Application.php`: two guard tags and one action alias.
- `lib/Lifecycle/CommitmentGuardAdapter.php` and `lib/Lifecycle/Action/RecordCommitmentMovementAction.php` (new).
- `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`: `afsluiten` from every open state, movement kind `carried_forward`.
- `lib/Listener/InvoiceCommitmentListener.php` and `lib/Service/Commitment/CommitmentCarryOverService.php` (new).
- `SupplierInvoice.isLastInvoice` in the purchase order fragment.
- `src/manifest.d/bookkeeping-verplichtingenadministratie.json`: a year-end action on `CommitmentsRegister`.

## Cross-Project Dependencies

- `purchasing-supplier-invoice-intake` (open change in this repo): adds `lines[].accountNumber` to `SupplierInvoice`. When present, invoice lines map to commitment lines by ledger account; without it the amount is spread in proportion. Not a hard prerequisite.
- OpenRegister: the lifecycle engine and `LifecycleActionRegistry` as they stand. No change needed there.

## Risks

### Risk 1: Registering the guards turns silent aborts into real refusals
**Severity:** Medium. **Mitigation:** that is the intent. The release note says commitments now enforce mandate and budget, and names the override mandate for budget excess.

### Risk 2: A carry-over runs twice
**Severity:** High. **Mitigation:** each carried line records the line it came from; a second run finds it and writes nothing.

### Risk 3: A last invoice closes a commitment with deliveries still expected
**Severity:** Medium. **Mitigation:** marking the last invoice asks for confirmation showing the amount that will be released.

## Rollback Strategy

Remove the registrations; commitment transitions abort again, as today.
Movements already written stay as audit records.

## Open Questions

None.

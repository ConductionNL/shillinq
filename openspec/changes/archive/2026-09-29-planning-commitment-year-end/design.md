# Design: planning-commitment-year-end

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The lifecycle.** `Commitment` is declared in
`lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json:7`
with lifecycle field `status` (draft, in_approval, committed,
partially_delivered, partially_invoiced, partially_paid, closed,
cancelled). Its transitions:

| Transition | From, to | requires | actions |
|---|---|---|---|
| `indienen` (line 270) | draft to in_approval | `MandateEnforcer::requiresApproval` | none |
| `aangaan` (line 277) | draft to committed | `BudgetBlocker::canCommit` | `record-mutatie` (line 286) |
| `goedkeuren` (line 296) | in_approval to committed | `BudgetBlocker::canCommit` | none, plus the approval chain of REQ-VPL-013 |
| `afsluiten` (line 321) | committed to closed | none | none |

Neither guard tag is registered in `lib/AppInfo/Application.php`; its own
comment (line 781) lists `MandateEnforcer` and `BudgetBlocker` among the
guards that "are NOT registered, every one of those transitions also
hard-fails today" (shillinq#433). Registering them through the existing
`RegisterRequiresGuardAdapter` would not work either: it calls the wrapped
method with the object as the only argument, and both methods take
`(string $commitmentNumber, ?array $object = null)`
(`lib/Lifecycle/MandateEnforcer.php:118`, `lib/Lifecycle/BudgetBlocker.php:89`),
so a strict-types call with an array first argument throws and denies. No
handler is registered for `record-mutatie`. `afsluiten` only leaves
committed, so a commitment that has been partly invoiced cannot be closed.

**Lines and movements.** `CommitmentLine` carries `financialYear`,
`amount_excl_vat`, `invoiced_amount`, `remaining_committed` and
`afgesloten`. `CommitmentMovement` kinds are committed, increased,
decreased, performance_received, invoiced, paid, closed, cancelled and
reclaimed. `CommitmentBudget` has `financialYear`, `authorised_amount`,
`realised_amount`, `outstanding_commitments` and `free_capacity`. No PHP
writes `CommitmentMovement` or `remaining_committed` except
`CommitmentMaterialisationService` (`lib/Service/Commitment/CommitmentMaterialisationService.php:556`),
which sets `remaining_committed` when it creates a commitment from an
approved purchase order or an active contract (REQ-VPL-010), using
`sourceReference` for the order number.

**Pages.** `CommitmentsRegister` (`/commitments`) and `CommitmentDetail`
(`/commitments/:id`) in `src/manifest.d/bookkeeping-verplichtingenadministratie.json`,
plus the custom `BudgetLineCommitments`. A second page with the same id
`CommitmentDetail` is declared in `src/manifest.d/20-tenderned-integratie.json`
on route `/inkoop/commitments/:id`; the duplicate id is noted for the
manifest owners and is not changed here.

## Goals / Non-Goals

**Goals**

- A commitment can be committed, approved and closed through its declared transitions.
- The remaining commitment is the order amount minus what was invoiced.
- A last invoice releases the remainder to the budget in one step.
- At year end, every open line continues in the next year under the same commitment number, and the controller sees which next-year budgets fall short.

**Non-Goals**

- Funding the next year's budget.
- Delivery and payment movements.

## Decisions

### D1. A guard adapter that passes the commitment number

`CommitmentGuardAdapter` implements `LifecycleGuardInterface` and calls the
wrapped method as `(commitmentNumber, object)`. The two literal tags are
registered with it in `Application::register()`, with deny messages naming
the mandate or the budget shortfall.

Alternative considered: change both methods to take the object only.
Rejected: `CommitmentMaterialisationService` calls them with a number and
an object today, and the adapter keeps that caller unchanged.

### D2. `record-mutatie` is a lifecycle action handler

`RecordCommitmentMovementAction`, registered under `record-mutatie`,
writes one `CommitmentMovement` of the declared `kind` for the commitment
total and increases `outstanding_commitments` on each matching
`CommitmentBudget` (programme, cost centre, financial year of each line).
Idempotent on commitment and kind.

### D3. Invoiced amounts come from the booked invoice

`InvoiceCommitmentListener` listens for a `SupplierInvoice` reaching
approved, finds the commitment whose `sourceReference` is the invoice's
order number, and writes an `invoiced` movement per commitment line the
invoice lines map to (by ledger account and cost centre, else
proportionally), lowering `remaining_committed` and moving the commitment
to partially_invoiced. It also lowers `outstanding_commitments` and raises
`realised_amount` on the budget.

Alternative considered: compute remaining from invoices on read.
Rejected: the budget's free capacity must change at the moment of
invoicing for `BudgetBlocker` to see it.

### D4. The last invoice closes through `afsluiten`

`SupplierInvoice` gets `isLastInvoice`. When it is set on an approved
invoice with a commitment, the listener of D3, after recording the
invoiced amount, requests `afsluiten`. `afsluiten` is widened to every open
state (committed, partially_delivered, partially_invoiced,
partially_paid), and its new action `record-mutatie` with kind closed
writes the released remainder as a negative movement, sets each line's
`remaining_committed` to 0 and `afgesloten` true, and lowers the budgets'
`outstanding_commitments` by the same amount.

Alternative considered: a separate "release" transition. Rejected: the
schema already describes `afsluiten` as "restant released back to budget".

### D5. Carry-over is a previewed, idempotent service

`CommitmentCarryOverService::preview(administrationId, fromYear)` lists
every open line of `fromYear` with its remaining amount and the free
capacity of the matching `fromYear + 1` budget. `execute()` writes, per
line, a new `CommitmentLine` on the same commitment with `financialYear`
`fromYear + 1`, `amount_excl_vat` the remaining amount and
`carriedFromLine` the old line, closes the old line, and writes two
movements of the new kind `carried_forward` (negative on the old line,
positive on the new). It skips a line that already has a successor. The
action "Carry open commitments to next year" on `CommitmentsRegister`
opens the preview and then runs it.

Alternative considered: refuse to carry a line the next budget cannot
absorb. Rejected: a signed commitment is a legal obligation whatever the
budget says; the shortfall list is what the controller takes into the
budget amendment.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| When a commitment may be committed, approved or closed | Declarative transitions, guards registered | The declarations exist; they need resolvable executors. |
| Recording a movement on a transition | Imperative lifecycle action handler | A declared action needs an executor. |
| Invoiced amount and last-invoice close | Imperative listener that requests the declared `afsluiten` | Cross-schema reaction to another schema's transition. |
| Year-end carry-over | Imperative service behind a page action | Writes across lines, movements and budgets with a preview. |

## Seed Data

Adds movement kind `carried_forward`, `CommitmentLine.carriedFromLine` and
`SupplierInvoice.isLastInvoice`. Seed for "Gemeente Voorbeeld":
commitment V-2026-0114 from order PO-2026-031, EUR 20,000 excl. VAT on
programme 0.4 Overhead, 2026; an invoice of EUR 15,000 marked as the last;
commitment V-2026-0120, EUR 48,000 for road maintenance, EUR 30,000
invoiced by 31 December 2026, with a 2027 budget of which EUR 10,000 is
free.

## Risks / Trade-offs

- [Mapping invoice lines to commitment lines] → by ledger account and cost centre; when that fails the amount is spread over the open lines in proportion, and the movement says so.
- [Real refusals where there were aborts] → named in the release note.

## Migration Plan

No data migration. Commitments stuck in draft can be committed once the
guards resolve.

## Open Questions

None.

## As built (2026-09-29)

- D2: the action is declared by class name (`OCA\Shillinq\Lifecycle\Action\RecordCommitmentMovementAction`), the form OpenRegister resolves for `StampPostingAction` and `HandToAccountsPayableAction`, instead of a `record-mutatie` alias.
- D1: the adapter also serves `Requisition.approve`, which names `BudgetBlocker::canCommit` too (`register.d/purchase-requisition.json`); it passes the requisition id as the number, as `RequisitionService::approveRequisition()` does. That transition was refused before as an unregistered tag.
- D3: the order comes from the invoice's `matchedPoIds`; the commitment is the one whose `sourceReference` is the order's `poNumber`. `factureren` is widened to leave committed as well, since an invoice often arrives before any receipt is recorded.
- D4: marking the last invoice after approval is an endpoint (`POST /api/v1/supplier-invoices/{id}/last-invoice`) that sets the flag and runs `afsluiten`; an invoice approved while already marked closes through the listener.
- D5: the shortfall compares the carried amount per programme with the next year's free capacity before the carry-over, and the carry-over writes the outstanding amount onto that budget, so its free capacity can go below zero, as the design intends.
- Seed data: no new seed objects; the demo data generator covers the schemas (gate 101).


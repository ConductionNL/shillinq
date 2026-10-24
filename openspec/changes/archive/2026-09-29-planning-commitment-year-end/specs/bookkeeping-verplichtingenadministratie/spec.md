# bookkeeping-verplichtingenadministratie Specification (delta)

## Purpose

A commitment can be committed and closed, a last invoice releases what is
left, and open commitments move to the next year under their own number.
From shillinq matrix rows `pln-commitment-carryover` and
`pur-last-invoice-close`; the prerequisite comes from the deferred row
`pln-commitments`.

## ADDED Requirements

### Requirement: A commitment's declared transitions resolve their guards and action (REQ-PCYE-001)

The guard tags `MandateEnforcer::requiresApproval` and
`BudgetBlocker::canCommit` SHALL be registered so that `indienen`,
`aangaan` and `goedkeuren` run their checks and allow or refuse with a
message, and the `record-mutatie` action SHALL be served so that `aangaan`
records a committed movement and raises the outstanding commitments on the
matching budget. None of these transitions MUST abort because a tag or
action cannot be resolved.

#### Scenario: A budget holder signs a commitment within mandate and budget

- GIVEN draft commitment V-2026-0114 of EUR 20,000 on programme 0.4 with EUR 60,000 free in 2026, signed by a budget holder with a EUR 50,000 mandate
- WHEN the budget holder presses Verplichting aangaan on the commitment page
- THEN the commitment shows status committed
- AND the 2026 budget for programme 0.4 shows EUR 40,000 free

#### Scenario: A commitment beyond the budget is refused with the reason

- GIVEN a draft commitment of EUR 80,000 on a budget with EUR 60,000 free
- WHEN the budget holder presses Verplichting aangaan
- THEN the transition is refused with a message naming the EUR 20,000 shortfall

### Requirement: A booked invoice on an order lowers its commitment (REQ-PCYE-002)

When a supplier invoice for an order with a commitment is approved,
shillinq SHALL record an invoiced movement against the commitment, lower
each affected line's remaining amount, move the commitment to partially
invoiced, and move the amount from outstanding commitments to realised on
the budget.

#### Scenario: A first invoice reduces what is still committed

- GIVEN committed V-2026-0114 of EUR 20,000 from order PO-2026-031
- WHEN a supplier invoice of EUR 15,000 for PO-2026-031 is approved
- THEN the commitment page shows EUR 15,000 invoiced and EUR 5,000 remaining, status partially invoiced

### Requirement: Marking the last invoice closes the commitment and releases the rest (REQ-PCYE-003)

A supplier invoice SHALL carry a "last invoice" mark. When an approved
invoice with that mark belongs to an order with a commitment, shillinq
SHALL close the commitment through `afsluiten`, record the released
remainder as a closed movement, set every line's remaining amount to zero,
and return the remainder to the budget's free capacity. `afsluiten` SHALL
be allowed from every open state. Setting the mark SHALL show the amount
that will be released and ask for confirmation.

#### Scenario: The last invoice releases EUR 5,000

- GIVEN V-2026-0114 with EUR 5,000 remaining after an invoice of EUR 15,000
- WHEN the buyer marks that invoice as the last one and confirms the release of EUR 5,000
- THEN the commitment shows status closed with a closed movement of EUR 5,000
- AND the 2026 budget for programme 0.4 shows EUR 5,000 more free capacity

### Requirement: Open commitments carry over to the next year under their number (REQ-PCYE-004)

Shillinq SHALL offer the controller a year-end action that previews every
open commitment line of a closing fiscal year with its remaining amount and
the free capacity of the next year's matching budget, and then carries each
line into the next year: a new line on the same commitment for the
remaining amount, the old line closed, and a carried-forward movement on
both. Lines whose remaining amount exceeds the next year's free capacity
SHALL be listed as shortfalls and still carried. Running the action again
MUST NOT carry a line twice.

#### Scenario: Road maintenance continues into 2027

- GIVEN commitment V-2026-0120 of EUR 48,000 with EUR 30,000 invoiced in 2026 and EUR 10,000 free on the 2027 budget
- WHEN the controller runs Carry open commitments to next year for 2026 and confirms the preview
- THEN V-2026-0120 has a 2027 line of EUR 18,000 and its 2026 line is closed
- AND the preview listed V-2026-0120 as a shortfall of EUR 8,000 on the 2027 budget

#### Scenario: A second run changes nothing

- GIVEN the 2026 carry-over has run
- WHEN the controller runs it again for 2026
- THEN the preview shows no open lines and nothing is written

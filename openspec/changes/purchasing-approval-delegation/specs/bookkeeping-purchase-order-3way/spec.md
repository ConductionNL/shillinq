# bookkeeping-purchase-order-3way Specification (delta)

## Purpose

A purchase order is approved by the people its amount requires, and an
approver can hand a pending approval to a colleague who decides it on
their behalf. From shillinq matrix row `pur-approval-delegate`.

## ADDED Requirements

### Requirement: Purchase order approval runs on OpenRegister's approval chain (REQ-PAD-001)

The `approve` transition of `PurchaseOrder` SHALL be gated by a declared
OpenRegister approval chain with amount tiers: a teamleider for any amount,
a facility manager from EUR 10,000 and a procurement manager from EUR
50,000, with separation of duties. The purchase order page SHALL show each
step with its role, state, decider and time, and SHALL let a user who may
decide the pending step approve or reject it. Shillinq MUST NOT keep a
second approval chain on the order.

#### Scenario: A facility manager completes the approval of a large order

- GIVEN order PO-2026-040 of EUR 12,500 requested by Jeroen Bakker, with its teamleider step approved
- WHEN Karin de Wit, member of facility_manager, presses Approve on the order page
- THEN the order shows state approved and both steps with their deciders and times
- AND Send via Peppol and Send by email become available

#### Scenario: The requester cannot approve their own order

- GIVEN order PO-2026-041 of EUR 450 requested by Jeroen Bakker, who is also a teamleider
- WHEN Jeroen Bakker opens the order page
- THEN no Approve button is shown to him, and a direct call to approve the step is refused

### Requirement: An approver hands a pending approval to a colleague (REQ-PAD-002)

When the approval steps of a purchase order are OpenRegister tasks, the
purchase order page SHALL offer the user who may decide a pending step to
hand it to a named colleague with a mandate. The colleague SHALL then be
able to decide it, the order SHALL show the decision as made on behalf of
the original approver, and a hand-over without a mandate MUST be refused.
Separation of duties SHALL still apply to the colleague.

#### Scenario: A teamleider hands an approval to a colleague before leave

- GIVEN a pending teamleider step on PO-2026-040 that Pieter Smit may decide
- WHEN he chooses Hand to a colleague, names Karin de Wit and gives the mandate Vervanging tijdens verlof 30 september tot 11 oktober
- THEN Karin de Wit sees the approval on the order page and approves it
- AND the order shows the step approved by Karin de Wit on behalf of Pieter Smit with that mandate

### Requirement: The approval audit export names who acted on whose behalf (REQ-PAD-003)

The purchase order audit export SHALL list, for every approval step, the
decider, the approver on whose behalf the decision was made when it was
delegated, the mandate and the time.

#### Scenario: An auditor sees a delegated approval

- GIVEN the delegated approval of PO-2026-040
- WHEN the controller exports the purchase order audit trail for September 2026
- THEN the row for PO-2026-040 shows decider Karin de Wit, on behalf of Pieter Smit, and the mandate

# expense-reimbursement-or-passthrough Specification (delta)

## Purpose

Pass-through markup rules are applied to the expenses they cover and locked
when the claim is submitted. From shillinq matrix row `exp-reinvoice`.

## ADDED Requirements

### Requirement: The matching markup rule is applied and locked at submit (REQ-STEB-004)

When an `ExpenseClaimEntry` is submitted, shillinq SHALL write on each of its
pass-through items the `PassThroughMarkupRule` that matches with the highest
priority (customer and category, then customer, then category, then global,
within the administration and the item's fiscal year), the rate applied and
the markup amount: cost times the rate for a percentage rule, the fixed value
for a fixed rule. The values MUST NOT change after submission.

#### Scenario: A customer-and-category rule beats a customer rule

- GIVEN a rule of 10 percent for Woningcorporatie Het Anker on travel and a rule of 5 percent for Het Anker on everything
- WHEN S. de Vries submits a claim with a pass-through train receipt of EUR 42.80 for Het Anker
- THEN the receipt shows markup rule travel for Het Anker, rate 10 percent and markup EUR 4.28

#### Scenario: A rule changed after submission does not reprice the claim

- GIVEN the submitted claim above
- WHEN a bookkeeper raises the travel rule for Het Anker to 15 percent on the Pass-through markup rules page
- THEN the receipt still shows rate 10 percent and markup EUR 4.28

### Requirement: An expense without a matching rule is passed on at cost (REQ-STEB-005)

A pass-through item for which no rule matches SHALL be billed at its cost, and
the item SHALL show that no markup rule applied.

#### Scenario: Parking without a rule

- GIVEN no markup rule covers category parking for Woningcorporatie Het Anker
- WHEN the parking receipt of EUR 12.50 is billed
- THEN the invoice line is EUR 12.50 and the receipt shows that no markup rule applied

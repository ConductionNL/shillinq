# bookkeeping-rechtmatigheidsverantwoording Specification (delta)

## Purpose

The lawfulness paragraph is computed from the books. From shillinq matrix rows `pub-rechtmatigheid`, `pub-audit-protocol`, `pub-ensia`.

## ADDED Requirements

### Requirement: The paragraph totals are computed (REQ-RV-011)

The app SHALL compute, for a year, the total expenses including reserve mutations, the tolerance amounts for errors and uncertainties, the sums of the findings, and whether both sums stay within tolerance.

#### Scenario: A controller computes the paragraph

- GIVEN EUR 200,000,000 of expenses in 2026, a tolerance of 1 percent for errors and one error finding of EUR 2,500,000
- WHEN the controller chooses compute on the 2026 paragraph
- THEN the error tolerance reads EUR 2,000,000 and the errors EUR 2,500,000
- AND the paragraph says the errors exceed the tolerance

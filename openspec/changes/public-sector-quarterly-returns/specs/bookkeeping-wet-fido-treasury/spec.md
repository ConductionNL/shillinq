# bookkeeping-wet-fido-treasury Specification (delta)

## Purpose

The Fido limits are computed each quarter. From shillinq matrix rows `pub-bcf`, `pub-fido`.

## ADDED Requirements

### Requirement: The cash limit is computed (REQ-FDO-010)

The app SHALL compute, for a quarter, the cash limit from the budget total and the statutory percentage, the average net floating debt, and the headroom.

#### Scenario: A treasurer computes the third quarter

- GIVEN a budget total of EUR 100,000,000 and an average net floating debt of EUR 6,000,000 in 2026-Q3
- WHEN the treasurer chooses compute on the 2026-Q3 quarterly report
- THEN the cash limit reads EUR 8,500,000 and the headroom EUR 2,500,000

### Requirement: The interest risk norm is shown on the dashboard (REQ-FDO-011)

The app SHALL compute the interest risk norm for the year against the refinancing and rate revisions of long-term loans, and SHALL show it with the cash limit on the treasury dashboard.

#### Scenario: A treasurer reads the dashboard

- GIVEN loans refinancing EUR 12,000,000 in 2026 and a norm of EUR 20,000,000
- WHEN the treasurer opens the treasury dashboard
- THEN the interest risk norm shows EUR 8,000,000 of room

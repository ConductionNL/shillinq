# bookkeeping-vat-btw-filing Specification (delta)

## Purpose

The running VAT position before filing, and the supplementary return after.
From shillinq matrix rows `tax-vat-position` and `tax-suppletie`.

## ADDED Requirements

### Requirement: The VAT position of the open period is shown per box (REQ-VPS-001)

The VAT reports page SHALL show, for the open period, the base and VAT per
return box from the posted lines so far and the amount payable or
refundable, SHALL list the lines behind a box, and SHALL give for a closed
period the same figures as its prepared return.

#### Scenario: A bookkeeper checks the position mid-quarter

- GIVEN posted sales in Q4 2026 so far of EUR 20,000 at 21% and purchases with EUR 1,500 input VAT
- WHEN the bookkeeper opens the VAT reports page for 2026-Q4
- THEN box 1a shows base EUR 20,000 and VAT EUR 4,200
- AND box 5b shows EUR 1,500 and the payable amount shows EUR 2,700

#### Scenario: The lines behind a box

- GIVEN the same position
- WHEN the bookkeeper clicks box 1a
- THEN the posted lines that make up EUR 4,200 are listed with their entries

### Requirement: A filed period is corrected with a supplementary return (REQ-VPS-002)

The app SHALL let a user check a filed return for drift against the books,
SHALL let a user prepare a supplementary return from a detected correction
with the per-box differences, and SHALL file it or offer it for download.

#### Scenario: A late purchase invoice for a filed quarter

- GIVEN the Q2 2026 return was filed and a purchase invoice dated June with EUR 1,800 input VAT was posted afterwards
- WHEN the bookkeeper chooses "Check for corrections" on the Q2 return
- THEN a correction shows on the corrections page with box 5b EUR 1,800
- AND "Prepare supplementary return" shows it above the EUR 1,000 threshold with the correction posting staged

### Requirement: Drift is looked for after a period close (REQ-VPS-003)

The app SHALL check the filed returns of the fiscal year for drift when a
period of that year is closed.

#### Scenario: Closing September finds a Q2 drift

- GIVEN the late June invoice above
- WHEN the bookkeeper closes September 2026
- THEN the corrections page lists the Q2 correction without anyone running a check

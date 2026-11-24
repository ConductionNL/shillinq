# bookkeeping-journal-entries Specification (delta)

## Purpose

A mixed business and private cost is booked in two parts, with only the
deductible VAT reclaimed. From shillinq matrix row
`tax-partial-deductibility`.

## ADDED Requirements

### Requirement: A cost line with a deductible share posts in two parts (REQ-TVRD-004)

When a journal entry or AP transaction posts a cost line whose deductible
percentage, taken from the line or else from its account, is below 100,
shillinq SHALL post the deductible share of the net amount to the cost
account with the input VAT on that share, and the private share of the net
amount plus the VAT on it to the account's private-share account marked
non-deductible. The posted transaction SHALL still balance, and a line at
100 percent SHALL post as before.

#### Scenario: A fuel bill is split 70 to 30

- GIVEN account 4520 Autokosten at 70 percent deductible with private share to 1810 Privé-opnames
- WHEN the bookkeeper posts a fuel invoice of EUR 100.00 plus EUR 21.00 VAT on 4520
- THEN the general ledger shows 4520 debit EUR 70.00, input VAT debit EUR 14.70, 1810 debit EUR 36.30 marked non-deductible, and the creditor credit EUR 121.00

#### Scenario: A line override wins over the account

- GIVEN the same account at 70 percent
- WHEN a line on 4520 carries a deductible percentage of 100 for a business-only trip
- THEN the line posts in full to 4520 with EUR 21.00 input VAT

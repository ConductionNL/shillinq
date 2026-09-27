# bookkeeping-bank-reconciliation Specification (delta)

## Purpose

An unmatched bank line is paired by hand with the invoices it pays, or
booked to a ledger account, and a confirmed match settles its invoice. From
shillinq matrix row `bnk-manual-match`.

## ADDED Requirements

### Requirement: A bank line is matched to open invoices by hand (REQ-BMM-001)

Shillinq SHALL offer a "Match by hand" action on every unmatched bank
statement line, on the bank statement page and on the unmatched items page.
It SHALL list open sales invoices for a credit line and open AP
transactions for a debit line, searchable by number, counterparty and
amount, and let the user select one or more. Confirming SHALL write a
confirmed `ReconciliationMatch` with the user as confirmer and mark the line
matched. A selection whose total exceeds the line amount MUST be refused; a
lower total SHALL be recorded as a partial match naming the remainder.

#### Scenario: A bookkeeper pairs a supplier payment with its invoice

- GIVEN a bookkeeper on the bank statement page with an unmatched debit line of EUR 2,420.00 to Schoonmaakbedrijf De Vries, remittance factuur sept
- WHEN they choose Match by hand, search De Vries, select AP transaction DV-7781 of EUR 2,420.00 and confirm
- THEN the line shows matched
- AND DV-7781 shows state paid

#### Scenario: A selection larger than the line is refused

- GIVEN an unmatched credit line of EUR 1,000.00
- WHEN the bookkeeper selects two invoices of EUR 800.00 each and confirms
- THEN the match is refused with a message that the selection exceeds the line by EUR 600.00

#### Scenario: A part payment is recorded as partial

- GIVEN an unmatched credit line of EUR 1,000.00 and open sales invoice VF-2026-0901 of EUR 1,500.00
- WHEN the bookkeeper selects VF-2026-0901 and confirms
- THEN the match is recorded as partial with a remainder of EUR 500.00

### Requirement: A bank line is booked to a ledger account by hand (REQ-BMM-002)

The same action SHALL let the user book a line to a ledger account with an
optional VAT code and a description. Shillinq SHALL write and post a
balanced `JournalEntry` between the bank's ledger account and the chosen
account, with the VAT split out when a code is given, and record the
confirmed match against the resulting ledger transaction.

#### Scenario: Monthly bank costs are booked from the statement

- GIVEN an unmatched debit line of EUR 12.50 with remittance Kosten zakelijk pakket
- WHEN the bookkeeper chooses Ledger account 4910 Bankkosten and confirms
- THEN a posted journal entry debits 4910 and credits the bank ledger account for EUR 12.50
- AND the line shows matched

### Requirement: A confirmed match settles the invoice it names (REQ-BMM-003)

When a `ReconciliationMatch` is confirmed, by a person, a rule or the bank
feed, shillinq SHALL move each invoice it names to paid through that
invoice's own lifecycle transition: `mark-paid` or `pay-overdue` for a
sales invoice, `matchFull` or, for a partial match, `matchPartial` for an
AP transaction. An invoice that is not in a payable state SHALL be left
unchanged and the reason logged. Settling SHALL happen once per match.

#### Scenario: An overdue sales invoice is paid by a matched line

- GIVEN overdue sales invoice VF-2026-0850 of EUR 605.00
- WHEN a match between a EUR 605.00 line and VF-2026-0850 is confirmed
- THEN VF-2026-0850 shows state paid

#### Scenario: A second confirmation event changes nothing

- GIVEN a match whose invoice is already paid
- WHEN the confirm event for that match is delivered again
- THEN the invoice stays paid and no second transition is recorded

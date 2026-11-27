# bookkeeping-bank-reconciliation Specification (delta)

## Purpose

A payment provider's payout is taken apart into the payments, refunds and fees
inside it, matched to the invoices they settle, booked, and reconciled with
the bank line that brought it. From shillinq matrix row `rec-psp-payouts`.

## ADDED Requirements

### Requirement: A payout integriq reports is recorded with its transactions (REQ-RPPO-001)

For every settlement integriq reports, shillinq SHALL keep one `ProviderPayout`
with its reference, date, gross, fees, refunds, chargebacks and net amounts and
one line per transaction, and MUST NOT keep a second record for the same
settlement reference.

#### Scenario: The weekly Mollie payout arrives

- GIVEN integriq reports settlement st_example0001 of 2026-10-02 with 14 payments, one refund and EUR 4.06 fees
- WHEN the report is received
- THEN the Provider payouts page lists st_example0001 with net EUR 585.49 and 15 lines

### Requirement: Each payout line is matched to what it paid (REQ-RPPO-002)

Each payment line SHALL be matched by provider payment id to the payment request,
booking deposit or web shop invoice it paid, and each refund or chargeback line
to the invoice of its original payment. A line without a match SHALL be shown
as unmatched with its payment id and amount, and the bookkeeper SHALL be
notified.

#### Scenario: One payment nobody raised in shillinq

- GIVEN a payout line for provider payment tr_example0099 that no request, deposit or invoice carries
- WHEN the payout is matched
- THEN the payout page shows that line as unmatched with EUR 18.00 and tr_example0099
- AND the other lines link to their invoices

### Requirement: Captures and payouts are booked through the clearing account (REQ-RPPO-003)

When a provider capture settles an invoice-backed request, shillinq SHALL post
debit the clearing account and credit debtors for the invoice. When a payout is
matched, shillinq SHALL post one balanced transaction: debit the bank account
for the net amount, debit the fee account for the fees, credit the clearing
account for the gross amount, and book refunds and chargebacks against the
debtors they concern.

#### Scenario: The ledger shows the fees

- GIVEN payout st_example0001 matched
- WHEN it is booked
- THEN the general ledger shows EUR 585.49 debited to the bank, EUR 4.06 to 4720 Bankkosten, and the clearing account reduced by the gross

### Requirement: The payout's bank line is reconciled to the payout (REQ-RPPO-004)

A bank statement line whose amount equals a booked payout's net amount and
whose remittance names its settlement reference SHALL be matched to that payout
with one reconciliation match, and that match MUST NOT book the line a second
time.

#### Scenario: The bookkeeper opens the bank statement

- GIVEN the ING statement line "MOLLIE B.V. st_example0001" of EUR 585.49
- WHEN the bookkeeper opens the statement on the Bank reconciliation page
- THEN the line is shown as reconciled to payout st_example0001 and needs no action

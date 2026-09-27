# bookkeeping-treasury-ihb Specification (delta)

## Purpose

The combined cash position is summed per bank account and in total,
instead of a hard-coded zero. From shillinq matrix row `bnk-multi-account`.

## ADDED Requirements

### Requirement: The cash position is shown per bank account and combined (REQ-BCON-004)

Shillinq SHALL compute, per administration, the cash position of every
`BankAccount`: the posted ledger balance of its `ledgerAccountNumber`, and
its last known bank balance with the date it was known. It SHALL show the
combined total on the group liquidity dashboard's "Group cash position"
widget and per account on the bank accounts page. Liquid ledger accounts
that no bank account names SHALL be shown on one line so the combined total
equals the cash position on the main dashboard. The widget MUST NOT show a
fixed value.

#### Scenario: A controller reads the combined cash position

- GIVEN posted ledger balances of EUR 84,300.00 on 1100 (ING) and EUR 250,000.00 on 1110 (Rabobank)
- WHEN the controller opens the group liquidity dashboard
- THEN Group cash position shows EUR 334,300.00
- AND the bank accounts page shows EUR 84,300.00 for ING and EUR 250,000.00 for Rabobank with their bank balance dates

#### Scenario: A liquid account without a bank account still counts

- GIVEN a posted balance of EUR 500.00 on kas 1000 that no bank account names
- WHEN the controller opens the group liquidity dashboard
- THEN the breakdown shows EUR 500.00 on the line other liquid accounts
- AND the combined total includes it

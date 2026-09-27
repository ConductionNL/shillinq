# bookkeeping-bank-connectors Specification (delta)

## Purpose

A bank account knows its connection and its ledger account, and
transactions integriq pulls arrive as statement lines. From shillinq matrix
rows `bnk-multi-account` and `bnk-integrated-account`; the pull itself is
integriq's (row `bnk-psd2`).

## ADDED Requirements

### Requirement: A bank account links to its ledger account and its connection (REQ-BCON-001)

A `BankAccount` SHALL carry `ledgerAccountNumber` and `bankConnectionId`.
The bank accounts page SHALL show per account whether it is connected, when
it last synced, and its ledger account. A "Connect" action on the bank
account detail page SHALL start the consent hand-off to integriq that
`BankConnection` already declares, and shillinq MUST NOT store any consent
token.

#### Scenario: A controller sees which accounts are connected

- GIVEN a controller of Gemeente Voorbeeld on the bank accounts page, with a connected ING account on ledger 1100 and an unconnected Rabobank account on ledger 1110
- WHEN the page loads
- THEN the ING row shows connected with its last sync time and ledger 1100
- AND the Rabobank row shows not connected and ledger 1110

### Requirement: Transactions from the bank feed arrive as statement lines (REQ-BCON-002)

Shillinq SHALL listen for `nl.conduction.bankfeed.transactions.synced`,
read the batch its `batchUri` names, and write one `BankStatement` with its
lines for the account whose IBAN matches `accountIban`, through the same
intake code the statement file import uses. A batch already written SHALL
NOT be written again, and a line whose end-to-end reference already exists
for that account SHALL be skipped.

#### Scenario: Twelve transactions arrive from the morning pull

- GIVEN a connected ING account NL20INGB0001234567
- WHEN integriq emits a synced event for that IBAN with a batch of twelve transactions
- THEN the bank reconciliation page shows a new statement for the account with twelve lines and source feed

#### Scenario: The same batch arrives twice

- GIVEN a batch already written as a statement
- WHEN the synced event for the same batchUri arrives again
- THEN no second statement is written

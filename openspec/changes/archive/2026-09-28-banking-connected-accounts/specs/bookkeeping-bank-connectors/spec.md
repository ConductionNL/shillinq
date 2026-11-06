# bookkeeping-bank-connectors Specification (delta)

## Purpose

A bank account knows its connection and its ledger account, and
transactions integriq pulls arrive as statement lines. From shillinq matrix
rows `bnk-multi-account` and `bnk-integrated-account`; the pull itself is
integriq's (row `bnk-psd2`).

## ADDED Requirements

### Requirement: A bank account links to its ledger account and its connection (REQ-BCON-001)

A `BankAccount` SHALL carry `ledgerAccountNumber` and `bankConnectionId`.
The bank accounts page SHALL show per account when the bank feed last
synced it (empty for an account that is not connected) and its ledger
account. A "Connect a bank" action on the bank accounts page SHALL hand off
to integriq's connections, where the consent is given, and shillinq MUST NOT
store any consent token.

#### Scenario: A controller sees which accounts are connected

- GIVEN a controller of Gemeente Voorbeeld on the bank accounts page, with a connected ING account on ledger 1100 and an unconnected Rabobank account on ledger 1110
- WHEN the page loads
- THEN the ING row shows its last sync time and ledger 1100
- AND the Rabobank row shows no last sync and ledger 1110

### Requirement: Transactions from the bank feed arrive as statement lines (REQ-BCON-002)

Shillinq SHALL listen for `nl.conduction.bankfeed.transactions.synced`,
read the batch its `batchUri` names, and write one `BankStatement` with its
lines for the account whose IBAN matches `accountIban`, through the same
intake code the statement file import uses. A batch already written SHALL
NOT be written again, and a line whose end-to-end reference already exists
for that account SHALL be skipped.

@e2e exclude the feed has no browser surface; asserted by BankfeedIntakeServiceTest::testTwelveTransactionsArriveAsOneStatement from the real ObjectCreatedEvent

#### Scenario: Twelve transactions arrive from the morning pull

- GIVEN a connected ING account NL20INGB0001234567
- WHEN integriq emits a synced event for that IBAN with a batch of twelve transactions
- THEN the bank reconciliation page shows a new statement for the account with twelve lines and source feed

@e2e exclude asserted by BankfeedIntakeServiceTest::testTheSameBatchTwiceWritesOneStatement

#### Scenario: The same batch arrives twice

- GIVEN a batch already written as a statement
- WHEN the synced event for the same batchUri arrives again
- THEN no second statement is written

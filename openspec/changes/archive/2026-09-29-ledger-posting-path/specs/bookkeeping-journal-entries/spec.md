# bookkeeping-journal-entries Specification (delta)

## Purpose

A posted journal entry becomes exactly one balanced ledger transaction,
including the payroll journal humaniq hands in. From shillinq matrix rows
`led-double-entry` and `ppl-payroll-journal`.

## ADDED Requirements

### Requirement: A posted journal entry materialises one balanced transaction (REQ-LPP-004)

Shillinq SHALL register a lifecycle action handler under the name
`materialise-gl-transaction`. When `JournalEntry.post` or
`JournalEntry.postDirect` runs, the handler SHALL write one `GLTransaction`
with one `GLLine` per entry line, set the entry's `glTransactionId`, and
MUST refuse the transition when debit does not equal credit.

#### Scenario: A bookkeeper posts a journal entry without approval

- GIVEN a draft journal entry with approvalState not-required and two balanced lines
- WHEN the bookkeeper presses Post without approval
- THEN the entry shows state posted and links to one new general ledger transaction with the same two lines

#### Scenario: Posting the same entry twice writes one transaction

- GIVEN a journal entry whose materialised transaction already exists
- WHEN the materialise handler runs again for that entry
- THEN no second transaction is written

### Requirement: A payroll journal handed in by humaniq posts to the ledger (REQ-LPP-005)

A `JournalEntry` that humaniq writes for an approved payroll run SHALL post
through the same handler as any other journal entry, without a
payroll-specific path in shillinq.

#### Scenario: The September payroll lands in the ledger

- GIVEN humaniq has written a balanced journal entry for the September payroll run (gross wages EUR 5,000, loonheffing EUR 1,450, net pay EUR 3,550)
- WHEN the entry is posted
- THEN the general ledger shows one posted transaction with those three amounts on the mapped accounts

### Requirement: No declared transition posts to the ledger twice (REQ-LPP-006)

Where a PHP poster already writes the `GLTransaction` for a transition,
that transition SHALL NOT also declare `materialise-gl-transaction`. Every
remaining declaration SHALL be served by a mapper for its source schema, or
name a source schema whose mapper another change owes, and a declaration on
a schema without a mapper MUST fail loudly. A transaction the handler writes
SHALL carry the same posting stamps as an entry posted from the ledger page.

#### Scenario: A stock issue books its cost of goods sold once

- GIVEN a stock move posted through the dispatch path, which `CogsPosterService` books
- WHEN the move reaches posted
- THEN exactly one cost of goods sold transaction exists for it

#### Scenario: An expense claim is refused until its accounts resolve

- GIVEN an expense claim entry being posted before `expenses-category-mapping` is built
- WHEN the posting action runs
- THEN the post is refused with a message naming ExpenseClaimEntry
- AND no transaction is written

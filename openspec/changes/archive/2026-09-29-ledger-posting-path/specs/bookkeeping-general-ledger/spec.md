# bookkeeping-general-ledger Specification (delta)

## Purpose

A balanced transaction posts from the ledger pages, in any open fiscal
year, and allocation rules run on it. From shillinq matrix rows
`led-double-entry` and `led-open-years`.

## ADDED Requirements

### Requirement: A balanced transaction posts from the general ledger page (REQ-LPP-001)

Shillinq SHALL register a lifecycle action handler under the name
`evaluate-allocation-rules`, so that the `post` transition of
`GLTransaction` completes when its `requires` guard passes. The transition
MUST NOT abort because the declared action has no handler.

#### Scenario: A bookkeeper posts a balanced memorial entry

- GIVEN a bookkeeper on the general ledger detail page of a draft transaction with a debit line of EUR 1,200 on 4000 and a credit line of EUR 1,200 on 1100
- WHEN they press Post transaction
- THEN the transaction shows state posted
- AND no error about an unresolved lifecycle action appears

#### Scenario: A posted entry is locked, kept and on the audit trail

- GIVEN the balanced memorial entry of the scenario above, dated 2026-09-20
- WHEN the bookkeeper presses Post transaction
- THEN the posted transaction is locked against edits and marked intact
- AND it is kept until 2036-12-31
- AND its audit trail names the bookkeeper and the moment of posting

#### Scenario: An unbalanced transaction is still refused

- GIVEN a draft transaction with debit EUR 1,200 and credit EUR 1,000
- WHEN the bookkeeper presses Post transaction
- THEN the transition is refused with the message that the entry is not balanced
- AND the transaction stays in draft

### Requirement: Active per-posting allocation rules run when a transaction posts (REQ-LPP-002)

When `GLTransaction.post` runs, the `evaluate-allocation-rules` handler SHALL
append the balanced `GLLine` pairs prescribed by every `AllocationRule` in
state `active` with cadence `per-posting` whose `sourceAccountPattern`
matches a line of the transaction. It SHALL write nothing when no rule
matches, and it SHALL NOT append the same rule's lines twice for one
transaction.

#### Scenario: A rent allocation splits a posted cost over two departments

- GIVEN an active per-posting allocation rule that moves 40 percent of account 4000 to cost centre Burgerzaken and 60 percent to Sociaal Domein
- WHEN a transaction with EUR 1,200 debit on 4000 posts
- THEN the posted transaction carries allocation lines of EUR 480 and EUR 720 that balance
- AND the transaction still balances in total

#### Scenario: A transaction no rule matches posts unchanged

- GIVEN no active rule matches account 1100
- WHEN a transaction on 1100 and 1200 posts
- THEN it posts with exactly the lines it had

### Requirement: Posting works in every open fiscal year (REQ-LPP-003)

A transaction whose date falls in any `FiscalYear` in state `open` SHALL
post through the same path, with no limit on how many fiscal years are open
at once.

#### Scenario: A bookkeeper books a late invoice in last year while this year is open

- GIVEN fiscal years 2025 and 2026 both open
- WHEN the bookkeeper posts a balanced transaction dated 2025-12-20
- THEN the transaction posts and counts in the 2025 trial balance

### Requirement: An issued sales invoice posts to the ledger (REQ-LPP-007)

When an AR invoice is issued, shillinq SHALL write one balanced general ledger
transaction debiting the receivables control account for the invoice total
and crediting revenue and VAT per line.

#### Scenario: A bookkeeper issues an invoice and it reaches the ledger

- GIVEN a draft AR invoice of EUR 1,000 plus EUR 210 VAT to Bakkerij Jansen
- WHEN the bookkeeper presses Issue on the AR invoice detail page
- THEN the general ledger shows one posted transaction with EUR 1,210 debit on receivables, EUR 1,000 credit on revenue and EUR 210 credit on VAT

# bookkeeping-general-ledger Specification (delta)

## Purpose

Lines on an open-item balance account are cleared against each other, and
groups that do not net to zero are visible. From shillinq matrix row
`led-open-item-clearing`.

## ADDED Requirements

### Requirement: A balance account can be kept by open item (REQ-LOIC-001)

`Account` SHALL carry `openItemManaged`, settable only on accounts of a
balance type. The open items page SHALL list the uncleared posted lines of
such an account.

#### Scenario: A bookkeeper opens the kruisposten account

- GIVEN account 1950 Kruisposten marked open-item managed with three uncleared posted lines
- WHEN the bookkeeper opens the open items page for 1950
- THEN the three lines are listed with date, description, side and amount

### Requirement: A bookkeeper clears selected lines into a group (REQ-LOIC-002)

The open items page SHALL let a person select posted lines of one account
and clear them into a `ClearingGroup`, recording who and when, and SHALL let
them undo a clearing. A line MUST NOT be in two groups at once.

#### Scenario: Clearing a savings transfer

- GIVEN the two EUR 5,000 lines of "Spaaropdracht 0903" on 1950
- WHEN the bookkeeper selects both and presses Clear
- THEN both lines leave the uncleared list and one group with balance EUR 0 is shown under cleared

### Requirement: Groups that do not net to zero are listed (REQ-LOIC-003)

The page SHALL list every group on the account whose signed balance is not
zero, and every reopened group.

#### Scenario: A group left EUR 250 short

- GIVEN a group cleared with lines of EUR 5,000 debit and EUR 4,750 credit
- WHEN the bookkeeper views groups not netting to zero on 1950
- THEN that group is listed with a balance of EUR 250

### Requirement: The page suggests exact pairs (REQ-LOIC-004)

The page SHALL suggest pairs of uncleared lines with equal amounts, opposite
sides and the same reference, and SHALL NOT clear any suggestion without a
person confirming it.

#### Scenario: A suggestion is confirmed

- GIVEN the two EUR 5,000 lines are uncleared
- WHEN the bookkeeper opens the suggestions on 1950
- THEN the pair is suggested
- AND nothing is cleared until they press Clear on the suggestion

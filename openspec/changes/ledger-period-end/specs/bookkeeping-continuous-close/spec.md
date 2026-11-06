# bookkeeping-continuous-close Specification (delta)

## Purpose

Prepaid costs and deferred revenue are released period by period, and
computed accruals exist in the ledger. From shillinq matrix row
`led-deferrals`.

## ADDED Requirements

### Requirement: An invoice line spanning several periods is spread over them (REQ-LPE-004)

Shillinq SHALL let a person create a `DeferralSchedule` from an AP or AR
invoice line whose service period spans more than one fiscal period. On
creation it SHALL post one journal entry moving the full amount from the
result account to the balance account.

#### Scenario: A bookkeeper spreads an annual licence

- GIVEN a posted purchase invoice line of EUR 1,200 on 4300 for service period 2026-01-01 to 2026-12-31
- WHEN the bookkeeper chooses Spread over periods on that line with balance account 1900
- THEN a deferral schedule with twelve releases of EUR 100 is shown
- AND the ledger shows EUR 1,200 moved from 4300 to 1900

### Requirement: The nightly close releases each ended period (REQ-LPE-005)

The nightly soft close SHALL post, for every active `DeferralSchedule`, the
release of each ended period that has no journal entry yet, and SHALL set
the schedule to completed when the last release posts. The releases MUST
add up to the schedule total exactly.

#### Scenario: January's share is released after January ends

- GIVEN the licence schedule and the nightly run of 2026-02-01
- WHEN the run completes
- THEN a posted entry moves EUR 100 from 1900 to 4300 in period 2026-01
- AND a second run the same night writes nothing more

### Requirement: A computed accrual is booked and reversed (REQ-LPE-006)

For every `AutoAccrualPosting` the nightly soft close writes, it SHALL write
and post the `JournalEntry` whose id it records, and SHALL post the reversal
at the start of the next period when the rule's `reversalPattern` asks for
one.

#### Scenario: The energy accrual reaches the ledger

- GIVEN the active rule Energie of EUR 350 per month
- WHEN the nightly run closes September
- THEN the general ledger shows a posted EUR 350 debit on 4010 and credit on 1920 for September
- AND October's first run posts the reversal

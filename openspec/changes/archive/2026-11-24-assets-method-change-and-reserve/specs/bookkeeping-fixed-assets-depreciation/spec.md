# bookkeeping-fixed-assets-depreciation Specification (delta)

## Purpose

Depreciation is posted monthly, a change of method or life is applied
prospectively, extra depreciation is booked, and a disposal gain can go to a
reinvestment reserve. From shillinq matrix rows `pln-asset-method-change`
and `led-reinvestment-reserve`.

## ADDED Requirements

### Requirement: Depreciation is posted each period (REQ-AMCR-001)

A scheduled run SHALL post every unposted `DepreciationSchedule` line of an
active asset whose period has ended, as one journal entry per
administration and period, and SHALL link each line to it. Earlier periods
SHALL be posted only through an explicit action that shows the amounts
first.

#### Scenario: September's depreciation is posted

- GIVEN the oven with a monthly amount of EUR 750 after its revision
- WHEN the run executes on 2026-10-01
- THEN the general ledger shows a posted EUR 750 debit on the depreciation expense account and credit on accumulated depreciation for September
- AND the schedule line for September shows posted

### Requirement: A change of method or life recalculates future depreciation (REQ-AMCR-002)

The fixed asset detail page SHALL offer Revise depreciation on an active
asset, taking a new method or useful life, a date and a reason. Unposted
schedule lines after that date SHALL be recalculated from the book value on
that date; posted lines MUST NOT change.

#### Scenario: A controller shortens the oven's life

- GIVEN the oven with a book value of EUR 45,000 on 2026-07-01 and 90 of its 120 months left
- WHEN the controller revises the useful life to 90 months in total from 2026-07-01, leaving 60 months with reason "slijtage door nachtproductie"
- THEN the schedule shows EUR 750 per month from July 2026
- AND the lines for 2024 to June 2026 are unchanged

### Requirement: Extra depreciation is booked at once (REQ-AMCR-003)

The fixed asset detail page SHALL offer Extra depreciation with an amount, a
date and a reason, which SHALL post one line and recalculate the remaining
schedule from the lower book value.

#### Scenario: Water damage lowers the oven's value

- GIVEN the oven with a book value of EUR 42,750
- WHEN the controller books extra depreciation of EUR 5,000 on 2026-10-15 with reason "waterschade"
- THEN a posted EUR 5,000 depreciation entry exists and the remaining monthly amount is lower

### Requirement: A disposal gain can form a reinvestment reserve (REQ-AMCR-004)

The disposal dialog SHALL offer to add the gain to a reinvestment reserve.
When chosen, the gain SHALL be credited to the reserve account instead of
the gain account, and a `ReinvestmentReserve` SHALL record the amount and an
expiry at the end of the third year after the year it was formed.

#### Scenario: Selling the old van

- GIVEN the old van with a book value of EUR 8,000
- WHEN the entrepreneur disposes it for EUR 14,000 and chooses Add the gain to a reinvestment reserve
- THEN a reserve of EUR 6,000 expiring 2029-12-31 is listed
- AND no disposal gain is booked to profit

### Requirement: A reserve is applied to a replacement or released (REQ-AMCR-005)

Activating a new asset SHALL offer to apply an open reserve, lowering the new
asset's fiscal cost basis by the amount applied and debiting the reserve
account. An open reserve past its expiry SHALL be released to fiscal profit
by the scheduled run.

#### Scenario: The reserve pays for part of the new van

- GIVEN the open reserve of EUR 6,000
- WHEN the entrepreneur activates the new van of EUR 42,000 and applies the reserve
- THEN the new van's fiscal cost basis is EUR 36,000 and the reserve shows applied

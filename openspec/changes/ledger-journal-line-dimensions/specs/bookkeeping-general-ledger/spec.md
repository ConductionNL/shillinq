# bookkeeping-general-ledger Specification (delta)

## Purpose

A journal entry line carries a cost centre, a cost carrier and a project, and posting keeps them on the ledger line. Requested by humaniq's `payroll-cost-allocation`.

## ADDED Requirements

### Requirement: Journal entry lines carry dimension codes onto the ledger (REQ-LJD-001)

A `JournalEntry` line SHALL accept `costCenterCode`, `costCarrierCode` and `projectCode`. Posting the entry SHALL copy each code present on a line onto the `GLLine` made from it.

#### Scenario: Humaniq's payroll journal keeps its cost centres

- GIVEN humaniq's draft payroll journal with a gross debit of 6,000.00 on CC-100 and 4,000.00 on CC-200
- WHEN a bookkeeper posts it
- THEN the ledger holds two gross lines with `costCenterCode` CC-100 and CC-200
- AND the cost centre report shows 6,000.00 and 4,000.00

### Requirement: Posting refuses an unknown or inactive code (REQ-LJD-002)

The journal entry post guard SHALL refuse the post when a line's code names no active `AnalyticalDimension` of the matching type in the entry's administration on the entry date, and SHALL name the line and the code in the refusal. Lines without codes SHALL post as before.

#### Scenario: A cost centre humaniq knows but shillinq does not

- GIVEN a draft journal entry with a line on cost centre KP-900, which no dimension in the administration carries
- WHEN a bookkeeper posts it
- THEN the post is refused with "Line 3: cost centre KP-900 does not exist or is not active in this administration"
- AND the entry stays a draft

# bookkeeping-accounts-receivable-core Specification (delta)

## Purpose

A sales invoice is numbered from an unbroken series when it is issued, and
above a threshold it is approved by a second person first. From shillinq
matrix rows `sal-numbering` and `sal-approval`.

## ADDED Requirements

### Requirement: An issued invoice takes the next number of its series (REQ-SIIC-001)

When an AR invoice is issued, shillinq SHALL assign the next number of the
administration's sales sequence for the invoice's fiscal year, under a lock,
and a time-and-expense invoice SHALL take its number from the billable
sequence. A number MUST NOT be assigned twice, and a draft SHALL carry no
number.

#### Scenario: Two bookkeepers issue at the same moment

- GIVEN the sales sequence of Adviesbureau Van Dijk for 2026 at 41
- WHEN two bookkeepers each press Issue on a different draft on the AR invoice detail page at the same time
- THEN one invoice is 2026-0042 and the other 2026-0043

#### Scenario: A deleted draft leaves no gap

- GIVEN a draft invoice that is deleted before it is issued
- WHEN the next invoice is issued
- THEN it takes the next number after the last issued one

### Requirement: An assigned number cannot be edited (REQ-SIIC-002)

The invoice number SHALL be read-only once assigned, and a change to it on an
invoice that is not a draft MUST be refused.

#### Scenario: A bookkeeper tries to renumber an issued invoice

- GIVEN issued invoice 2026-0042
- WHEN a bookkeeper tries to change its number on the AR invoice detail page
- THEN the field cannot be edited

### Requirement: Invoices above the threshold need a second person's approval (REQ-SIIC-003)

An invoice whose total exceeds the administration's approval threshold MUST
NOT be issued from draft. It SHALL be sent for approval, approved or
rejected with a reason, and approval MUST be refused to the user who created
the invoice.

#### Scenario: A large invoice waits for approval

- GIVEN a draft invoice of EUR 24,200 created by j.devries and a threshold of EUR 10,000
- WHEN j.devries presses Issue
- THEN issuing is refused and Request approval is offered
- AND after a.bakker approves it, Issue succeeds

#### Scenario: The creator cannot approve

- GIVEN that invoice awaiting approval
- WHEN j.devries presses Approve
- THEN the approval is refused with the reason that the creator cannot approve

#### Scenario: A small invoice issues directly

- GIVEN a draft invoice of EUR 1,210
- WHEN the bookkeeper presses Issue
- THEN it is issued without approval

### Requirement: Gaps in a year's numbers are reported (REQ-SIIC-004)

A report SHALL list missing and duplicate numbers in a year's issued sales
invoices per administration.

#### Scenario: A controller checks 2026

- GIVEN issued invoices 2026-0001 to 2026-0043 with 2026-0017 missing
- WHEN the controller opens the invoice number check for 2026
- THEN it lists 2026-0017 as missing and no duplicates

# rate-card-management Specification (delta)

## Purpose

Every hour logged on a project carries the billable rate the client pays,
resolved from the rate card on the person's project assignment, and the project
page shows the rated hours. From shillinq matrix row `ppl-time`.

## ADDED Requirements

### Requirement: An hour resolves its billable rate by tier from the assignment's rate card (REQ-BRPH-001)

The system SHALL resolve the billable rate of an hour from the `RateCardTemplate`
named by the `ProjectAssignment` of the hour's person on the hour's project,
using the `RateCardVersion` effective on the hour's date and the first active
`RateSchedule` in the order user, role, project, client, blended. Each
resolution MUST be written as a `RateRecord` naming the tier and the schedule.

#### Scenario: A personal rate wins over the role rate

- GIVEN rate card Standaard 2026 with role senior at EUR 120 and user Alice at EUR 130
- AND Alice is assigned to project Renovatie Kade 12 with billing role senior and that rate card
- WHEN Alice's 6 hours of 14 October 2026 on that project are resolved
- THEN the rate is EUR 130 from the user tier and the value is EUR 780
- AND a RateRecord records tier user and the schedule of Alice's rate

#### Scenario: The role rate applies when the person has no personal rate

- GIVEN Bram is assigned to the same project with billing role senior and has no user-tier schedule
- WHEN Bram's 4.5 hours are resolved
- THEN the rate is EUR 120 from the role tier and the value is EUR 540

#### Scenario: The client rate applies to an assignment without a role

- GIVEN Chris is assigned with no billing role and the card has client Het Anker at EUR 95
- WHEN Chris's 2 hours are resolved
- THEN the rate is EUR 95 from the client tier

#### Scenario: A rate change mid-month prices each hour by its own date

- GIVEN Alice's user rate is EUR 130 until 15 October 2026 and EUR 135 from 16 October
- WHEN her hours of 15 and 16 October are resolved
- THEN the first is rated EUR 130 and the second EUR 135

### Requirement: An hour without a rate says why and is never billed at a default (REQ-BRPH-002)

The system MUST NOT price an hour at a fallback amount. When no rate resolves,
the answer SHALL be "no rate" with the reason: no assignment, no rate card on the
assignment, no version effective on the date, or no matching schedule. Drafting an
invoice with such an hour ticked SHALL be refused with HTTP 422 naming the person
and the date, and no invoice SHALL be written.

#### Scenario: A person without an assignment has no rate

- GIVEN Dana logged 1 hour on Renovatie Kade 12 and has no assignment to it
- WHEN the hour is resolved
- THEN the answer is no rate with reason "Dana is not assigned to this project"

#### Scenario: Drafting an invoice with an unrated hour is refused

- GIVEN Dana's hour is ticked on the Generate invoice page together with Alice's hours
- WHEN the project manager chooses Save as draft
- THEN the request is refused with HTTP 422 and the message names Dana and 14 October 2026
- AND no BillableInvoice is created

### Requirement: The project page shows rated hours and the unbilled value (REQ-BRPH-003)

`ProjectDetail` SHALL show a Billable hours card listing the project's hours in a
chosen month with person, date, hours, rate, tier, value and billed state, and
SHALL total the unbilled hours, the unbilled value and the hours without a rate.
When no hours source is connected the card MUST say so instead of showing zero.

#### Scenario: A project manager sees what October is worth

- GIVEN the October hours of Alice, Bram, Chris and Dana above and none of them invoiced
- WHEN the project manager opens Renovatie Kade 12 and the Billable hours card for October 2026
- THEN the card lists four rows and totals 12.5 rated unbilled hours worth EUR 1,510 and 1 hour without a rate

#### Scenario: Billed hours show their invoice

- GIVEN Alice's hours sit on posted invoice 2026-T-0031
- WHEN the project manager opens the card
- THEN Alice's row reads "Billed on 2026-T-0031" and her value is not in the unbilled total

#### Scenario: Without an hours source the card says so

- GIVEN no hours source is connected on the instance
- WHEN the project manager opens the project
- THEN the card says hours are not available because the hours app is not connected

### Requirement: An assignment carries a billing role and shows its current rate (REQ-BRPH-004)

`ProjectAssignment` SHALL carry an optional `billingRoleId` matched against the
role tier, and its `recognisedRate` SHALL show the rate the assignment resolves
to today. An invoice line MUST take its rate from the hour's own resolution,
never from `recognisedRate`.

#### Scenario: Setting a role updates the shown rate

- GIVEN Chris's assignment has no billing role and shows EUR 95
- WHEN the project manager sets billing role senior on it
- THEN the assignment shows EUR 120 in the Hours per assignment list

### Requirement: The Generate invoice page prices each hour by its own rate (REQ-BRPH-005)

The Generate invoice page SHALL use each hour's resolved rate for time and
material lines and MUST NOT ask for one rate card for the whole invoice when the
billing model is time and material. Each written line SHALL keep the rate in
`rateApplied` so a later rate change does not alter it.

#### Scenario: Two people on one invoice keep their own rates

- GIVEN Alice's 6 hours at EUR 130 and Bram's 4.5 hours at EUR 120 are ticked
- WHEN the invoice is saved as draft
- THEN its lines total EUR 1,320 and carry rateApplied 130 and 120

#### Scenario: A later rate change leaves a drafted line alone

- GIVEN the draft above exists
- WHEN Alice's user rate changes to EUR 140 from 1 October 2026
- THEN the draft line still reads EUR 130 until the line is removed and the hours re-ticked

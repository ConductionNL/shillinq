# fees-payments-and-the-contract-register Specification (delta)

---
status: proposed
---

## Purpose

The fee a case type carries and where it comes from, a payment settled by
hand, a batch submitted to a provider, and a contract that knows its
party and its cases. Extends `leges-at-intake`, `case-payment-requests`,
`payment-run-sepa-export` and the CLM `Contract`. Requested by the dossiq
competitor programme, discovery cluster 55.

## ADDED Requirements

### Requirement: REQ-FPCR-001 A fee cites the regulation it comes from

A `feeSchedule` entry SHALL carry `legalBasis` with the regulation
identifier, the article and the date that article took effect. The basis
SHALL be shown wherever the amount is shown to an administrator, and SHALL
be carried onto any payment request raised from the schedule. A schedule
entry saved without a `legalBasis` SHALL be refused.

Candidate C-intake-44, `must`, one driven passer (xxllnc-zaken) and one
documented (mozard).

#### Scenario: An administrator traces an amount to a council decision

- **GIVEN** a fee of 245.00 EUR for `bouwvergunning` with a legal basis naming article 2.3 of the legesverordening 2026
- **WHEN** an administrator opens the fee
- **THEN** the article, the regulation and its effective date are shown beside the amount

#### Scenario: A fee without a basis is refused

- **GIVEN** a new schedule entry with an amount and no `legalBasis`
- **WHEN** it is saved
- **THEN** validation refuses it and names the missing basis

### Requirement: REQ-FPCR-002 A fee may differ per intake channel

A `feeSchedule` entry SHALL carry `amounts`, a list of
`{intakeChannel, amount, currency}`. At most one entry SHALL omit
`intakeChannel`, and that entry is the default. A lookup SHALL take the
entry matching the case's intake channel, and the default where no entry
matches. A schedule with neither a matching channel nor a default SHALL be
refused at save.

Candidate C-intake-44, the channel half.

#### Scenario: The counter charges more than the website

- **GIVEN** a schedule with 245.00 for channel `web` and a default of 265.00
- **WHEN** a case arrives through the counter
- **THEN** the request is raised for 265.00

#### Scenario: A schedule with no default and no channel match is refused

- **GIVEN** a schedule whose only entry names channel `web`
- **WHEN** an administrator saves it
- **THEN** validation refuses it and names the missing default

### Requirement: REQ-FPCR-003 A payment is settled by hand

A `PaymentRequest` SHALL accept a `settlement` append carrying `method`
(`cash`, `pin`, `bank-transfer`, `waived`, `other`), `amount`,
`reference`, `actor`, `settledAt` and `reason`. A settlement SHALL NOT
overwrite the provider state. The request's reported state SHALL be derived
from the provider state and the settlements together, and a request settled
beyond its amount SHALL report the overpayment rather than hiding it.
Recording a settlement SHALL require the payment administration right.

Candidate C-intake-7, `should`, one driven passer (xxllnc-zaken).

#### Scenario: A pin payment at the counter is recorded against the case

- **GIVEN** an open request of 245.00 on a case
- **WHEN** a clerk records a settlement of 245.00 by `pin` with a terminal reference
- **THEN** the request reports as paid, and names the clerk, the method and the reference

#### Scenario: A provider capture after a manual settlement is not lost

- **GIVEN** a request settled by hand for its full amount
- **WHEN** the provider later reports a capture of the same amount
- **THEN** both are readable and the request reports an overpayment of that amount

#### Scenario: A user without the right cannot settle

- **GIVEN** a user without the payment administration right
- **WHEN** they record a settlement
- **THEN** the request is refused with 403

### Requirement: REQ-FPCR-004 A payment run is submitted to a provider

An approved `PaymentRun` SHALL be submittable to a payment provider through
integriq's `live-payment-providers` instead of being exported as a file.
Each `paymentLine` SHALL carry the provider's own identifier and status
back. A run SHALL NOT be submittable twice, and a partially accepted run
SHALL report per line which lines the provider refused and why. The
pain.001.001.03 export SHALL remain available.

Candidate C-intake-38, `should`, one documented passer (atabix).
Documented, never counted in a driven tally (D21).

#### Scenario: A batch of subsidy payments goes to the provider

- **GIVEN** an approved run of 40 lines
- **WHEN** an operator submits it to the configured provider
- **THEN** each line carries a provider identifier and a status
- **AND** the run records the submission with its actor and time

#### Scenario: A refused line is named

- **GIVEN** the same run with one line the provider refuses
- **WHEN** the result is read back
- **THEN** that line reports refused with the provider's reason, and the other 39 are unaffected

#### Scenario: A run cannot be submitted twice

- **GIVEN** a run already submitted
- **WHEN** an operator submits it again
- **THEN** the request is refused and names the first submission

### Requirement: REQ-FPCR-005 A contract knows its party and its cases

The `Contract` schema SHALL carry `linkedObjects`, a list of references to
objects raised under the contract, beside its existing
`counterpartyReference`. A contract SHALL report `incurredCost`, the sum of
the costs booked against those objects, stamped with the time it was
computed. Removing a link SHALL recompute the sum and SHALL leave the
underlying objects untouched.

Candidate C-parties-and-contacts-1, `should`, one driven passer (glpi) and
one documented (easy-redmine).

#### Scenario: A maintenance contract lists the cases raised under it

- **GIVEN** a contract with three cases linked
- **WHEN** a user opens the contract
- **THEN** the three cases are listed with their costs, and the total is shown with its computation time

#### Scenario: Unlinking a case leaves the case alone

- **GIVEN** the same contract
- **WHEN** one case is unlinked
- **THEN** the total drops by that case's cost and the case itself is unchanged

### Requirement: REQ-FPCR-006 A case reads its contract without copying it

A case app SHALL be able to name the contract a case is handled under, and
SHALL read the contract's term, counterparty, status and remaining value
through the leaf, holding only the reference. A contract that is
`expiring` or `expired` SHALL be reported as such to the reading app. A
reference to a contract the reader may not see SHALL return not found, not
a partial record.

Candidate C-deadlines-10, `should`, one documented passer (decos-join).
Documented, never counted in a driven tally (D21). The alerting half is
already shipped by `compliance-deadline-calendar` REQ-CDC-005 and is not
rebuilt here.

#### Scenario: A handler sees the contract behind the case

- **GIVEN** a case naming a contract that expires in 30 days
- **WHEN** the handler opens the case
- **THEN** the contract's counterparty, term and `expiring` status are shown, read from shillinq

#### Scenario: A contract the reader may not see is not leaked

- **GIVEN** a case naming a contract the reader has no access to
- **WHEN** the case is opened
- **THEN** the leaf returns not found and no contract fields are shown

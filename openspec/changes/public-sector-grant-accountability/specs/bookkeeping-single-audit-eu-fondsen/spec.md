# bookkeeping-single-audit-eu-fondsen Specification (delta)

## Purpose

EU fund spending is declared with its evidence. From shillinq matrix rows `pub-subsidies`, `pub-sisa`, `pub-eu-funds`.

## ADDED Requirements

### Requirement: A declaration bundles eligible expenditure with evidence (REQ-EUF-012)

The app SHALL propose, for an EU project and a period, a declaration of the eligible expenditures that carry certified evidence, with the EU share, and SHALL list every left-out expenditure with the reason.

#### Scenario: A project leader proposes the quarterly declaration

- GIVEN three eligible expenditures in the quarter, one without a certified supporting document
- WHEN the project leader proposes a declaration on the EU project page
- THEN a draft declaration holds two expenditures with their total and EU share
- AND the third is listed as left out because its evidence is missing

### Requirement: Submitting a declaration submits its expenditures (REQ-EUF-013)

The app SHALL submit every expenditure in a declaration when the declaration is submitted.

#### Scenario: A project leader submits the declaration

- GIVEN a draft declaration with two expenditures
- WHEN the project leader submits it
- THEN the declaration and both expenditures show state submitted

### Requirement: An irregularity at or above the reporting threshold needs an external reference (REQ-EUF-007)

The system SHALL record an irregularity on an EU-funded project with its
source, nature, amount concerned and recovery amount. When the amount
concerned reaches the reporting threshold (EUR 10,000 by default, set in
the app configuration), the irregularity SHALL NOT escalate until it
carries the reference of the external report. While it is open, the system
SHALL refuse new declarations on the projects it concerns.

#### Scenario: Double funding above the threshold blocks the project

- **GIVEN** the same EUR 15,400 invoice is declared under two funds
- **WHEN** a controller records an irregularity of nature double funding
- **THEN** escalation asks for the external report reference and a recovery amount
- **AND** a new declaration on either project is refused until the correction is booked

#### Scenario: A small irregularity escalates without a reference

- **GIVEN** an irregularity of EUR 2,000
- **WHEN** the controller escalates it
- **THEN** it escalates without an external report reference

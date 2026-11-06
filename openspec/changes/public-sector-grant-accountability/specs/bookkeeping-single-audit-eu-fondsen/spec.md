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

# bookkeeping-iv3-reporting Specification (delta)

## Purpose

The Iv3 report shows what the work on cases cost per taakveld, from the domain app's classification of its case types (decision 261, ADR-107 decisions 2 and 6).

## ADDED Requirements

### Requirement: The Iv3 report shows case costs per taakveld from the case type classification (REQ-IV3-010)

For a quarter the Iv3 report SHALL show, per taakveld, the cost of the hours booked on objects of each configured cost source, where the taakveld is read from the object's type. Each object SHALL be costed through `SubjectCostService`. A refinement code SHALL be counted under its `aggregatesUnder` parent. An object whose cost is incomplete SHALL be counted in hours and listed as incomplete, never added to the cost. Objects whose type has no taakveld SHALL form one row "Geen taakveld" naming those types. These costs SHALL be a section and CSV of their own and SHALL NOT be added to the lasten derived from GL lines.

#### Scenario: Old and new codes land in one row

- GIVEN in Q3 a dossiq case whose type carries `6.71` with hours costing EUR 1,000.00 and one whose type carries `6.71a` with hours costing EUR 500.00
- WHEN the controller opens the Iv3 report for Q3
- THEN the case cost section shows one row 6.71 with 2 cases and EUR 1,500.00
- AND the CSV holds the same row
- @e2e exclude report arithmetic, asserted by PHPUnit; the page is covered by the live task

#### Scenario: An unpriced hour withholds the cost, not the case

- GIVEN a case on `8.3` with hours by a person who has no cost rate
- WHEN the Iv3 report for the quarter is generated
- THEN the case is listed as incomplete with its hours and adds nothing to the 8.3 cost
- @e2e exclude aggregation policy, asserted by PHPUnit

#### Scenario: Case costs do not change the ledger totals

- GIVEN a quarter with GL lines and case costs
- WHEN the report is generated with and without the dossiq source
- THEN the GL lasten and baten per taakveld are identical
- @e2e exclude report arithmetic, asserted by PHPUnit

### Requirement: A cost source is configuration and an absent app is shown, not zero (REQ-IV3-011)

A cost source SHALL be declared as configuration: the app, register and schema of the objects, the property pointing at their type and the property on the type that holds the taakveld. Shillinq SHALL ship one source, dossiq's case (`caseType`, `iv3TaskField`). When the source's app, or humaniq for the hours, is not installed, the case cost section SHALL say which app is missing and SHALL show no figure.

#### Scenario: Dossiq is not installed

- GIVEN Shillinq without dossiq
- WHEN the controller opens the Iv3 report
- THEN the case cost section says "Zaakkosten per taakveld komen uit Dossiq. Dossiq is niet geïnstalleerd." and shows no amount
- @e2e exclude degraded state, asserted by PHPUnit and the live task

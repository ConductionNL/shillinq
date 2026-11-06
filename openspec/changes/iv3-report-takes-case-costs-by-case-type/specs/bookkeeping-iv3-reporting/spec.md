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

### Requirement: The same case cost source is grouped per case type per period (REQ-IV3-012)

Shillinq SHALL offer a report "Zaakkosten per zaaktype" that groups the cost of the hours booked on objects of each configured cost source by the object's type, for a period the controller picks (a month, a quarter or a year). It SHALL read the same source rows as the case cost section of REQ-IV3-010, so the cost and hours over all types for a period SHALL equal the cost and hours over all taakvelden for that period. Each row SHALL name the type by its title and show the number of objects, the hours and the cost. A type SHALL be grouped whether or not it carries a taakveld. An object whose cost is incomplete SHALL be counted in hours and listed under its type as incomplete, never added to the cost. When the source's app, or humaniq for the hours, is not installed, the report SHALL say which app is missing and SHALL show no figure. The report SHALL export to CSV with the same rows. Its costs SHALL NOT be added to the lasten derived from GL lines.

#### Scenario: Cases are grouped by their case type

- GIVEN in Q3 two dossiq cases of type "Omgevingsvergunning" with hours costing EUR 1,000.00 and EUR 250.00, and one case of type "Bezwaar" with hours costing EUR 400.00
- WHEN the controller opens "Zaakkosten per zaaktype" for Q3
- THEN the report shows a row "Omgevingsvergunning" with 2 cases and EUR 1,250.00 and a row "Bezwaar" with 1 case and EUR 400.00
- AND the total of EUR 1,650.00 equals the total of the case cost section of the Iv3 report for Q3
- @e2e exclude report arithmetic, asserted by PHPUnit; the page is covered by the live task

#### Scenario: A case with an incomplete cost is listed, not summed

- GIVEN in Q3 a case of type "Bezwaar" with hours by a person who has no cost rate, next to a case of the same type costing EUR 400.00
- WHEN the report for Q3 is generated
- THEN the row "Bezwaar" shows EUR 400.00, counts the hours of both cases and lists the first case as incomplete
- @e2e exclude aggregation policy, asserted by PHPUnit

#### Scenario: A missing app is named, not shown as zero

- GIVEN Shillinq without dossiq, or without humaniq
- WHEN the controller opens "Zaakkosten per zaaktype"
- THEN the report says "Zaakkosten per zaaktype komen uit Dossiq. Dossiq is niet geïnstalleerd." or "Uren komen uit Humaniq. Humaniq is niet geïnstalleerd." and shows no amount, not EUR 0.00
- @e2e exclude degraded state, asserted by PHPUnit and the live task

#### Scenario: The grouping exports to CSV

- GIVEN the report for Q3 with the rows "Omgevingsvergunning" and "Bezwaar" and one incomplete case
- WHEN the controller exports it to CSV
- THEN the CSV holds one line per case type with period, type, number of cases, hours, cost and number of incomplete cases, and the same totals as the page
- @e2e exclude export format, asserted by PHPUnit

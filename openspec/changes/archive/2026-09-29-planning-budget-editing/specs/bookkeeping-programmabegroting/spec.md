# bookkeeping-programmabegroting Specification (delta)

## Purpose

Budget amendments and the multi-year estimate have pages. From shillinq
matrix rows `pln-budget-amendments` and `pln-multi-year-budget`.

## ADDED Requirements

### Requirement: A budget amendment is recorded with status, reason, files and history (REQ-PBE-004)

Shillinq SHALL provide index and detail pages for `Begrotingswijziging`
showing its number, reason, amounts, council resolution and status, with the
determine action, attachments and the change history.

#### Scenario: A controller records an amendment

- GIVEN a controller on the budget amendments page of Gemeente Voorbeeld
- WHEN they add BW-2026-03 with reason "Extra formatie Wmo-consulenten na raadsbesluit", EUR 180,000 on Sociaal Domein, and attach the council letter
- THEN the amendment is listed as draft with the attachment on its detail page

#### Scenario: The history shows the determination

- GIVEN BW-2026-03 in draft
- WHEN the controller presses Determine after entering resolution 2026-114
- THEN the status shows determined and the history lists who determined it and when

### Requirement: The multi-year estimate has pages (REQ-PBE-005)

Shillinq SHALL provide index and detail pages for `MeerjarenBudget` and
`Meerjarenraming`, reachable from the public-sector menu for administrations
with a BBV variant.

#### Scenario: A controller reads the 2027 to 2030 estimate

- GIVEN Meerjarenraming records for 2027 to 2030
- WHEN the controller opens the multi-year estimate page
- THEN each year shows structural and incidental revenue, expenses and balance, and whether it is sluitend

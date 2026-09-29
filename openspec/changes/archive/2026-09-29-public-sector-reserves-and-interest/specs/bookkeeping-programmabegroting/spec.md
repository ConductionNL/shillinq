# bookkeeping-programmabegroting Specification (delta)

## Purpose

Reserves carry their yearly mutations and a multi-year overview, and
interest over asset book values is charged to tasks each year. From shillinq
matrix rows `pub-reserves` and `pub-asset-interest`.

## ADDED Requirements

### Requirement: A reserve's additions and withdrawals are recorded per year (REQ-PSRI-001)

Shillinq SHALL provide reserve index and detail pages and a
`ReserveMutation` per addition or withdrawal with year, amount, programme,
council resolution and status planned or realised. Realising a mutation
SHALL post it to the ledger.

#### Scenario: A controller records a withdrawal

- GIVEN a controller on the detail page of "Reserve onderhoud sportaccommodaties"
- WHEN they add a withdrawal of EUR 250,000 for 2026 with council resolution 2026-088 and realise it
- THEN the reserve's 2026 withdrawals show EUR 250,000 and a posted journal entry is linked

### Requirement: Reserves have a multi-year overview (REQ-PSRI-002)

A multi-year overview SHALL show, per reserve and year, the opening balance,
additions, withdrawals and closing balance for the last realised year and
four years ahead, marking planned figures and flagging a closing balance
under the floor or over the ceiling.

#### Scenario: The overview shows the plan

- GIVEN planned additions of EUR 150,000 a year for 2027 to 2030
- WHEN the controller opens the reserves multi-year overview
- THEN the maintenance reserve's row shows each year's closing balance growing by EUR 150,000, marked planned

### Requirement: Interest over asset book values is charged to tasks (REQ-PSRI-003)

Shillinq SHALL provide a yearly interest allocation run that, with the
omslagrente percentage the controller enters, calculates interest over each
investment's book value at 1 January charged to its taakveld and over the
balance of each reserve marked for interest, and SHALL post it as one
journal entry crediting taakveld 0.5 Treasury.

#### Scenario: The 2026 interest run

- GIVEN omslagrente 1.2 percent, Sporthal De Wielewaal at EUR 4,500,000 on taakveld 5.2 and the maintenance reserve at EUR 800,000 marked for interest
- WHEN the controller calculates and posts the 2026 run on the interest allocation page
- THEN the run shows EUR 54,000 on taakveld 5.2 and EUR 9,600 added to the reserve
- AND the general ledger shows the posted entry

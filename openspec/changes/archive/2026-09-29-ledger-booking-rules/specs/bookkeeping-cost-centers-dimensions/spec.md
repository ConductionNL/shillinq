# bookkeeping-cost-centers-dimensions Specification (delta)

## Purpose

An administration blocks combinations of account, cost centre and project
that may not be booked together. From shillinq matrix row
`led-dimension-block`.

## ADDED Requirements

### Requirement: An administration keeps posting restrictions (REQ-LBR-004)

Shillinq SHALL provide a `PostingRestriction` schema per administration with
`accountPattern`, `costCenterCode`, `projectCode`, `reason`, `validFrom`,
`validTo` and a lifecycle, and a settings page to list, add and retire
restrictions.

#### Scenario: A controller adds a restriction

- GIVEN a controller on the posting restrictions settings page of Gemeente Voorbeeld
- WHEN they add account pattern 4600, cost centre KP-100, project P-2026-014 with a reason and save
- THEN the restriction is listed as active with its reason

### Requirement: A blocked combination cannot be posted (REQ-LBR-005)

When a journal entry or a general ledger transaction made by a person is
posted, the post guard MUST refuse it if a line's account starts with an
active restriction's `accountPattern` and the line's cost centre and project
match the restriction's (an empty value on the restriction matches any),
on the posting date. The refusal SHALL name the restriction's reason.

#### Scenario: A bookkeeper books a sports subsidy on the wrong cost centre

- GIVEN the active restriction for 4600, KP-100 and P-2026-014
- WHEN a bookkeeper posts a journal entry with a line on 4600 for cost centre KP-100 and project P-2026-014
- THEN the post is refused with the reason "Sportakkoord-subsidies lopen via Sociaal Domein, niet via bestuursondersteuning"

#### Scenario: The same line on the allowed cost centre posts

- GIVEN the same restriction
- WHEN the line is on cost centre KP-300 Sociaal Domein
- THEN the entry posts

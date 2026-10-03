# app-administration Specification (delta)

## Purpose

An administrator's own record types in the shillinq register get list and
detail pages inside shillinq. From shillinq matrix row
`plt-custom-entities`.

## ADDED Requirements

### Requirement: An administrator chooses which own record types show (REQ-CRT-001)

The admin settings SHALL list the schemas in the shillinq register that the
app did not ship, and SHALL let an administrator switch each one on or off
for display in shillinq.

#### Scenario: An administrator shows a parking space register

- GIVEN an administrator who added a schema "Parkeerplaats" to the shillinq register in Open Register
- WHEN they open the shillinq admin settings
- THEN "Parkeerplaats" is listed as an own record type with a switch
- AND shipped schemas such as "Invoice" are not listed

### Requirement: Shown record types have list and detail pages (REQ-CRT-002)

For every shown record type the app SHALL offer a card on the own records
page, a list page of its records and a detail page per record.

#### Scenario: A user adds a parking space

- GIVEN "Parkeerplaats" is shown
- WHEN a user opens "Eigen gegevens" and chooses the Parkeerplaats card
- THEN the list of parking spaces opens
- AND the user can add a record with nummer and huurder and open its detail page

### Requirement: Only shown record types can be opened (REQ-CRT-003)

The generic pages and the endpoints SHALL refuse a schema that is not on
the shown list, and only an administrator SHALL change the list.

#### Scenario: A typed URL for a shipped schema

- GIVEN a user who types the URL of the own records list with schema "Invoice"
- WHEN the page loads
- THEN it shows an empty state saying the record type is not available

#### Scenario: A non-admin tries to change the list

- GIVEN a bookkeeper without admin rights
- WHEN they send a change to the shown record types
- THEN the request is refused with 403

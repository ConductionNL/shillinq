# Spec Delta: bookings-pipelinq-integration (member 04 — klantbeeld read)

## ADDED Requirements

### Requirement: The adapter SHALL load klantbeeld transaction history with pagination

The adapter SHALL fetch transaction history via GET
`/api/v1/contacts/{externalId}/klantbeeld` supporting `limit`
(default 5, max 100) and `offset` query parameters, returning each
transaction's date, description, amount, currency, and status.

#### Scenario: Klantbeeld loaded successfully

- **GIVEN** a valid Contact and pipelinq reachable
- **WHEN** the adapter loads klantbeeld
- **THEN** GET `/api/v1/contacts/{externalId}/klantbeeld` SHALL be
  called
- **AND** up to 5 most recent transactions SHALL be returned
- **AND** each SHALL carry date, description, amount, status.

#### Scenario: Klantbeeld pagination

- **GIVEN** a customer with more than 5 transactions
- **WHEN** the next page is requested via offset
- **THEN** the adapter SHALL fetch the next set of transactions.

### Requirement: The adapter SHALL handle empty or unavailable klantbeeld gracefully

The adapter SHALL treat an empty transaction list as a valid result
and SHALL surface a klantbeeld outage distinctly from a Contact
outage, so the profile can still render.

#### Scenario: Empty klantbeeld

- **GIVEN** a Contact with no transactions
- **WHEN** the adapter loads klantbeeld
- **THEN** an empty result SHALL be returned
- **AND** no error SHALL be logged.

#### Scenario: Klantbeeld unavailable but Contact available

- **GIVEN** the Contact fetch succeeded but klantbeeld returns 5xx
- **WHEN** the adapter loads klantbeeld
- **THEN** the adapter SHALL return an "unavailable" marker
- **AND** the Contact profile SHALL remain usable.

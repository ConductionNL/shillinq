# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 11 — testing)

## ADDED Requirements

### Requirement: The BBV capability SHALL be covered by unit and integration tests

The system SHALL include unit tests for `ComplianceService` (spend
levels, multi-account aggregation, rounding tolerance, fiscal-year
scoping) and an integration test asserting that dashboard data matches
the computed aggregation and updates as GL transactions are recorded.

#### Scenario: Aggregation integration test passes against real fixtures

- **GIVEN** the member-01 scaffold materialises programmes, mappings,
  and GL fixtures
- **WHEN** the integration test runs
- **THEN** the dashboard data SHALL equal the computed aggregation
- **AND** recording additional GL spend SHALL move the programme's
  status as asserted.

### Requirement: The BBV UI flows SHALL be covered by browser and smoke tests

The system SHALL include browser tests for the dashboard widgets,
mapping index search/add/navigation, mapping detail create/edit/delete,
fiscal-year scoping, and validation/error handling, plus smoke tests
that all BBV routes respond 200 and seed data is loaded.

#### Scenario: CRUD and validation flows are verified end to end

- **GIVEN** a running instance with the BBV capability and seed data
- **WHEN** the browser suite runs the create, edit, and delete flows
- **THEN** each flow SHALL succeed
- **AND** an attempt to over-allocate a GL account SHALL be rejected
- **AND** all BBV routes SHALL respond 200 OK.

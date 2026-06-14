# Spec Delta: bookings-pipelinq-integration (member 06 — profile card UI)

<!--
@e2e exclude UI tested via pure-JS render helpers (selectProfileState,
selectHistoryState, buildProfileFields, nextPageParams in
tests/unit/customer-bridge-profile-helpers.test.mjs) + the slice-05
BookingDetailController contract tests. A Playwright suite would need
a seeded Appointment + a linked pipelinq Contact + a stubbed
klantbeeld upstream; those fixtures land with slice 10
(bookings-pipelinq-customer-bridge-10-integration-e2e-tests).
-->

## ADDED Requirements

### Requirement: The detail view SHALL render a read-only customer profile card

The booking detail view SHALL render a customer profile card from the
injected Contact data, displaying organization or individual fields,
omitting missing optional fields, and offering no edit affordance.

#### Scenario: Organization profile card layout

- **GIVEN** a booking linked to an organization Contact
- **WHEN** the detail view renders
- **THEN** the card SHALL show the legal name, KvK, contact person,
  email (mailto), phone (tel), address, and a link to the pipelinq
  Contact opening in a new tab.

#### Scenario: Individual profile card layout

- **GIVEN** a booking linked to an individual Contact
- **WHEN** the detail view renders
- **THEN** the card SHALL show given + family name, email (mailto),
  phone (tel), and address.

#### Scenario: Missing optional fields are omitted

- **GIVEN** a Contact missing optional fields (e.g. no phone)
- **WHEN** the card renders
- **THEN** missing fields SHALL be omitted with no empty labels.

#### Scenario: Profile card is read-only

- **GIVEN** the profile card is displayed
- **WHEN** a user attempts to edit a field
- **THEN** editing SHALL be disabled
- **AND** a hint SHALL say "Edit customer details in pipelinq".

### Requirement: The detail view SHALL render the transaction-history section with fallbacks

The detail view SHALL render up to 5 recent transactions with "Load
more" pagination and SHALL show distinct fallback messages for empty,
unavailable, not-found, and unlinked states.

#### Scenario: History renders with pagination

- **GIVEN** klantbeeld returned transactions
- **WHEN** the history section renders
- **THEN** up to 5 transactions SHALL be shown with date, description,
  amount, status
- **AND** a "Load more" control SHALL fetch the next page.

#### Scenario: Fallback messages

- **GIVEN** the controller passed an empty / unavailable / not-found /
  unlinked state
- **WHEN** the detail view renders
- **THEN** the matching message SHALL be shown ("No previous
  transactions" / "History unavailable" / "Customer not found" /
  "Customer not linked to pipelinq").

# Spec Delta: bookings-pipelinq-integration (member 01 — config + contact link)

## ADDED Requirements

### Requirement: The system SHALL store the pipelinq connection configuration securely

The system SHALL expose a pipelinq API endpoint URL (plaintext app
config) and a pipelinq API token. The token SHALL be stored in the
Nextcloud secrets store, never persisted in plaintext, and never
written to logs.

#### Scenario: Configuration is persisted securely

- **GIVEN** an admin has entered and saved pipelinq credentials
- **WHEN** the settings are saved
- **THEN** the API token SHALL be stored in the Nextcloud secrets store
  (not plaintext)
- **AND** the endpoint URL SHALL be stored in app config
- **AND** on page reload the token field SHALL be masked.

### Requirement: The system SHALL provide an admin settings interface for the pipelinq connection

The system SHALL render an admin-only settings page with an endpoint
input, a masked token input, and a "Test Connection" action that
reports success or failure.

#### Scenario: Configure pipelinq endpoint and token

- **GIVEN** the Nextcloud admin is logged in
- **WHEN** the admin opens the pipelinq integration settings
- **THEN** an endpoint input and a masked token input SHALL be shown
- **AND** a "Test Connection" button SHALL be available.

#### Scenario: Test connection reports the outcome

- **GIVEN** the admin has entered an endpoint and token
- **WHEN** the admin clicks "Test Connection"
- **THEN** a pipelinq health-check request SHALL be issued
- **AND** a success message SHALL be shown on 200 OK
- **AND** an error message with the HTTP status SHALL be shown on
  failure (timeout, 401, 404, 5xx).

### Requirement: The booking record SHALL carry an optional pipelinqContactId link

The booking record SHALL declare an optional `pipelinqContactId` field
that stores a pipelinq Contact `externalId`. The field SHALL be
declared in the OpenRegister-managed booking schema (not a raw table
alteration) and SHALL be nullable, allowing many bookings to reference
the same contact.

#### Scenario: Booking references a pipelinq Contact

- **GIVEN** the booking schema declares an optional `pipelinqContactId`
- **WHEN** a booking is linked to a pipelinq Contact
- **THEN** the booking's `pipelinqContactId` SHALL hold the Contact
  `externalId`
- **AND** later detail views SHALL be able to fetch the profile using
  this id.

#### Scenario: Booking without a pipelinq Contact

- **GIVEN** a booking with `pipelinqContactId` unset
- **WHEN** the booking detail view loads
- **THEN** no customer profile card SHALL be required
- **AND** booking operations SHALL proceed normally.

### Requirement: The system SHALL provide a pipelinq integration-test scaffold

The system SHALL provide an integration-test scaffold backed by a
pipelinq API mock server returning canned Contact, klantbeeld, and
timeline responses, reusable by later chain members.

#### Scenario: Mock server serves canned responses

- **GIVEN** the integration-test scaffold is configured
- **WHEN** a test issues a request against the pipelinq mock
- **THEN** the mock SHALL return the canned Contact / klantbeeld /
  timeline fixture for the requested route.

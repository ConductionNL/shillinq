# Spec Delta: bookings-pipelinq-integration (member 10 — integration & E2E tests)

## ADDED Requirements

### Requirement: The integration suite SHALL verify the read and write paths end to end

The integration suite SHALL verify that creating a booking publishes a
timeline event, that the profile card displays Contact + klantbeeld in
the detail view, and that pipelinq unavailability degrades gracefully.

#### Scenario: Create booking publishes a timeline event

- **GIVEN** the pipelinq mock server and a booking with a valid contact
- **WHEN** the booking is created
- **THEN** a POST to the timeline endpoint SHALL be asserted with the
  correct payload
- **AND** the booking SHALL be saved.

#### Scenario: Profile card displays in the detail view

- **GIVEN** the mock returns Contact + klantbeeld
- **WHEN** the booking detail view loads
- **THEN** the profile card SHALL render name, email, phone
- **AND** up to 5 transactions SHALL display.

#### Scenario: Graceful degradation when pipelinq is unavailable

- **GIVEN** the mock returns 5xx
- **WHEN** a booking is created and its detail loaded
- **THEN** the booking SHALL be saved, the event queued for retry, and
  the card SHALL show an error message.

### Requirement: The E2E suite SHALL verify admin config and the full lifecycle sequence

The E2E suite SHALL verify that an admin can configure the pipelinq
endpoint and that the booking lifecycle triggers all four timeline
events.

#### Scenario: Admin configures the pipelinq endpoint

- **GIVEN** the settings page
- **WHEN** the admin enters endpoint + token and clicks "Test
  Connection"
- **THEN** a success message SHALL show and the settings SHALL persist
  across reload.

#### Scenario: Lifecycle triggers all four timeline events

- **GIVEN** a booking
- **WHEN** it is created, confirmed, cancelled, and completed
- **THEN** booking.created, booking.confirmed, booking.cancelled, and
  booking.completed events SHALL all be published.

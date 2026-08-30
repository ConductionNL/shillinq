# Spec Delta: bookings-pipelinq-integration (member 08 — lifecycle events + auth)

## ADDED Requirements

### Requirement: The system SHALL publish confirmed, cancelled, and completed timeline events

The system SHALL publish a timeline event for each booking state
transition — `booking.confirmed`, `booking.cancelled` (including any
cancellation reason), and `booking.completed` — using the shared
publish + retry pattern.

#### Scenario: booking.confirmed event published

- **GIVEN** a booking moving to confirmed
- **WHEN** the confirmation is saved
- **THEN** a `booking.confirmed` event SHALL be published with the same
  metadata as creation.

#### Scenario: booking.cancelled event published

- **GIVEN** a confirmed booking being cancelled
- **WHEN** the cancellation is saved
- **THEN** a `booking.cancelled` event SHALL be published
- **AND** any cancellation reason SHALL be included in metadata.

#### Scenario: booking.completed event published

- **GIVEN** a booking marked completed
- **WHEN** the completion is recorded
- **THEN** a `booking.completed` event SHALL be published.

### Requirement: The system SHALL treat timeline auth failures as permanent

On a 401 from the timeline endpoint, the system SHALL log an ERROR,
SHALL NOT retry, SHALL notify an admin if notifications are enabled,
and SHALL allow the booking operation to complete.

#### Scenario: Timeline publish fails with an auth error

- **GIVEN** a booking state change triggers timeline publishing
- **AND** the pipelinq token is invalid
- **WHEN** the POST returns 401 Unauthorized
- **THEN** the booking SHALL still be committed
- **AND** the event SHALL NOT be retried
- **AND** an ERROR SHALL be logged ("Invalid pipelinq API token")
- **AND** an admin notification SHALL be sent if enabled.

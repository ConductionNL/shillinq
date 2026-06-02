# Spec Delta: bookings-pipelinq-integration (member 07 — timeline publish core)

## ADDED Requirements

### Requirement: The adapter SHALL publish a timeline event to pipelinq

The adapter SHALL publish a timeline event via POST `/api/v1/timeline`
using a fixed JSON payload (type, externalId, timestamp, contactId,
metadata), with a 3 second timeout and the shared retry + circuit
breaker, returning success on 201 and failure otherwise.

#### Scenario: Timeline event published successfully

- **GIVEN** a constructed timeline event and pipelinq reachable
- **WHEN** the adapter publishes it
- **THEN** POST `/api/v1/timeline` SHALL be called with the fixed
  payload
- **AND** a 201 Created SHALL be treated as success.

#### Scenario: Transient publish failure is retried

- **GIVEN** the timeline POST returns a transient 5xx
- **WHEN** the adapter publishes the event
- **THEN** it SHALL retry up to 3 times with exponential backoff
- **AND** SHALL return failure if all attempts fail.

### Requirement: The system SHALL publish a booking-created timeline event on persist

The system SHALL construct and publish a booking-created timeline
event when a booking referencing a valid pipelinqContactId is
persisted. The booking commit SHALL proceed even when the publish
fails.

#### Scenario: booking.created event published

- **GIVEN** a new booking with a valid `pipelinqContactId`
- **WHEN** the booking is persisted
- **THEN** a `booking.created` payload SHALL be constructed
- **AND** POST `/api/v1/timeline` SHALL be called
- **AND** a 201 response SHALL confirm the event was recorded.

#### Scenario: Publish failure does not block the booking

- **GIVEN** the `booking.created` publish fails
- **WHEN** the booking is being persisted
- **THEN** the booking SHALL still be committed
- **AND** the event SHALL be handed off for async retry.

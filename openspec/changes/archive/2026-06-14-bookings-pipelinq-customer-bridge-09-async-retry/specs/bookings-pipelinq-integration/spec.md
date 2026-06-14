# Spec Delta: bookings-pipelinq-integration (member 09 — async retry)

## ADDED Requirements

### Requirement: The system SHALL retry failed timeline events via a background job

The system SHALL queue a failed timeline event for asynchronous retry
via a background job that re-attempts publishing with exponential
backoff (1m, 5m, 30m) up to 3 retries.

#### Scenario: Failed event queued for retry

- **GIVEN** a timeline event publish fails transiently
- **WHEN** the event is queued for async retry
- **THEN** a job SHALL be registered with event type, booking id,
  contact id, retry count 0, and the next retry time.

#### Scenario: Async job retries and succeeds

- **GIVEN** a queued timeline event and pipelinq now available
- **WHEN** the retry job executes
- **THEN** POST `/api/v1/timeline` SHALL be called again
- **AND** on 201 the job SHALL be removed and a DEBUG entry logged.

### Requirement: The system SHALL dead-letter exhausted timeline events

When a queued event exhausts its retries, the system SHALL move it to a
dead-letter queue, log an ERROR, and allow an admin to re-publish it
manually.

#### Scenario: Async job exhausts retries

- **GIVEN** a queued event with max retries 3
- **WHEN** all 3 retries fail
- **THEN** the job SHALL move to the dead-letter queue
- **AND** an ERROR SHALL be logged with event details and booking id.

#### Scenario: No job queue available

- **GIVEN** a sync publish failure and no job queue available
- **WHEN** the lifecycle handler attempts to queue
- **THEN** a WARNING SHALL be logged indicating manual retry is
  required.

# Spec Delta: bookings-pipelinq-integration (member 02 — HTTP adapter core)

## ADDED Requirements

### Requirement: The adapter SHALL provide a resilient HTTP transport with bounded retries

The `PipelinqContactAdapter` SHALL issue pipelinq requests with a 3
second timeout and SHALL retry transient failures up to 3 times with
exponential backoff (1s, 2s, 4s). Client errors (4xx other than
retryable cases) SHALL NOT be retried.

#### Scenario: Transient failure is retried with backoff

- **GIVEN** a pipelinq request returns a transient 5xx error
- **WHEN** the adapter issues the request
- **THEN** it SHALL retry up to 3 times with exponential backoff
- **AND** each attempt SHALL be logged for ops visibility.

#### Scenario: Non-transient client error is not retried

- **GIVEN** a pipelinq request returns a non-retryable client error
- **WHEN** the adapter receives it
- **THEN** the adapter SHALL NOT retry
- **AND** SHALL surface the error to the caller.

### Requirement: The adapter SHALL fail fast via a circuit breaker

The adapter SHALL open a circuit breaker after 5 consecutive failures,
fail fast while open, transition to half-open after 5 minutes, and log
each state transition at WARNING.

#### Scenario: Circuit breaker opens after repeated failures

- **GIVEN** 5 consecutive pipelinq request failures
- **WHEN** the circuit breaker opens
- **THEN** subsequent calls SHALL fail fast without an HTTP request
- **AND** the state transition SHALL be logged at WARNING.

#### Scenario: Circuit breaker resets after the cooldown

- **GIVEN** an open circuit breaker
- **WHEN** 5 minutes have elapsed
- **THEN** the breaker SHALL move to half-open
- **AND** the next request SHALL test the circuit and close it on
  success.

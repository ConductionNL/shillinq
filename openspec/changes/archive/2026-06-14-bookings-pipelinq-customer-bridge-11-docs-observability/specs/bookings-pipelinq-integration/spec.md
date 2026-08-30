# Spec Delta: bookings-pipelinq-integration (member 11 — docs, observability & logging)

## ADDED Requirements

### Requirement: The integration SHALL emit structured logs across success and failure paths

The integration SHALL log at DEBUG on success, at WARNING on transient
failures and circuit-breaker transitions, and at ERROR on permanent
failures, with all entries visible in `nextcloud.log`.

#### Scenario: Successful Contact fetch logged

- **GIVEN** a booking detail loads successfully
- **WHEN** the pipelinq Contact is fetched and cached
- **THEN** a DEBUG entry SHALL record "Loaded pipelinq Contact
  {externalId} (from API / from cache)".

#### Scenario: API error logged with context

- **GIVEN** a pipelinq API call fails
- **WHEN** the error occurs
- **THEN** a WARNING SHALL record the method, endpoint, status,
  response (first 500 chars), retry attempt, and externalId.

#### Scenario: Circuit-breaker state change logged

- **GIVEN** the circuit breaker changes state
- **WHEN** the transition occurs
- **THEN** a WARNING SHALL record the new state and timestamp.

### Requirement: The integration SHALL provide admin and developer documentation

The integration SHALL ship an admin configuration/troubleshooting guide
and a developer architecture guide, and SHALL record the feature in the
changelog.

#### Scenario: Admin guide covers configuration and troubleshooting

- **GIVEN** the published documentation
- **WHEN** an admin consults the pipelinq integration guide
- **THEN** it SHALL cover finding the endpoint + token, entering
  credentials, testing the connection, and troubleshooting connection
  and "customer data unavailable" issues.

#### Scenario: Observability hooks exist where a dashboard is available

- **GIVEN** an ops dashboard is available
- **WHEN** the integration runs
- **THEN** alerts SHALL fire on circuit-breaker open and dead-letter
  growth
- **AND** response-time and error-rate dashboards SHALL be available.

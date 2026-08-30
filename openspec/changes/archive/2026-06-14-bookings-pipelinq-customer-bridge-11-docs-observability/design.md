# Design — Member 11: docs, observability & logging

## Scope

Documentation, monitoring/alerting, the structured logging contract,
and the changelog. Chain tail.

## Logging contract (ADR-006)

The adapter + handlers SHALL emit:
- DEBUG — successful Contact fetch / cache hit / successful publish.
- WARNING — transient API failure (method, endpoint, status, response
  first 500 chars, retry attempt, externalId), circuit-breaker state
  transitions.
- ERROR — permanent failures (401 auth, dead-letter exhaustion).

All visible in `nextcloud.log`.

## Observability

Where an ops dashboard exists: alert on circuit-breaker open and
dead-letter growth; dashboards for pipelinq response-time percentiles
and error-rate (5xx/4xx) breakdown.

## Documentation (ADR-009)

- Admin guide: finding the pipelinq endpoint + token, entering
  credentials, testing the connection, troubleshooting "Connection
  failed" and "Customer data unavailable".
- Developer guide: adapter overview, HTTP init + error handling, cache
  management, circuit breaker, async retry, extending event types.
- Changelog entry for the feature.

# Tasks — Member 11: docs, observability & logging

Sourced from the giant's Phase 6 (Documentation & Deployment) plus the
giant's cross-cutting logging/observability requirement.

## Logging

- [x] Record DEBUG on successful Contact fetch / cache hit / successful publish
- [x] Record WARNING on transient API failure (method, endpoint, status, body first 500 chars, retry attempt, externalId)
- [x] Record WARNING on circuit-breaker state transitions
- [x] Record ERROR on permanent failures (401 auth, dead-letter exhaustion)

## Admin guide

- [x] Write admin guide: "Configuring pipelinq Integration" (endpoint + token, entering credentials, testing connection, troubleshooting)

## Developer guide

- [x] Write developer guide: "pipelinq Integration Architecture" (adapter overview, HTTP init + error handling, cache, circuit breaker, async retry, extending event types)

## Observability

- [x] Alert on circuit-breaker open (5 consecutive failures)
- [x] Alert on dead-letter queue growth
- [x] Dashboard: pipelinq response-time percentiles + error-rate breakdown

## Changelog

- [x] Update CHANGELOG with the pipelinq customer-profile integration feature

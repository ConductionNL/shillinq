# Tasks — Member 11: docs, observability & logging

Sourced from the giant's Phase 6 (Documentation & Deployment) plus the
giant's cross-cutting logging/observability requirement.

## Logging

- [ ] Record DEBUG on successful Contact fetch / cache hit / successful publish
- [ ] Record WARNING on transient API failure (method, endpoint, status, body first 500 chars, retry attempt, externalId)
- [ ] Record WARNING on circuit-breaker state transitions
- [ ] Record ERROR on permanent failures (401 auth, dead-letter exhaustion)

## Admin guide

- [ ] Write admin guide: "Configuring pipelinq Integration" (endpoint + token, entering credentials, testing connection, troubleshooting)

## Developer guide

- [ ] Write developer guide: "pipelinq Integration Architecture" (adapter overview, HTTP init + error handling, cache, circuit breaker, async retry, extending event types)

## Observability

- [ ] Alert on circuit-breaker open (5 consecutive failures)
- [ ] Alert on dead-letter queue growth
- [ ] Dashboard: pipelinq response-time percentiles + error-rate breakdown

## Changelog

- [ ] Update CHANGELOG with the pipelinq customer-profile integration feature

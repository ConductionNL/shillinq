---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-01-config-contact-link]
chain:
  - bookings-pipelinq-customer-bridge-01-config-contact-link
  - bookings-pipelinq-customer-bridge-02-http-adapter-core
  - bookings-pipelinq-customer-bridge-03-contact-read
  - bookings-pipelinq-customer-bridge-04-klantbeeld-read
  - bookings-pipelinq-customer-bridge-05-detail-controller-inject
  - bookings-pipelinq-customer-bridge-06-profile-card-ui
  - bookings-pipelinq-customer-bridge-07-timeline-publish-core
  - bookings-pipelinq-customer-bridge-08-lifecycle-events
  - bookings-pipelinq-customer-bridge-09-async-retry
  - bookings-pipelinq-customer-bridge-10-integration-e2e-tests
  - bookings-pipelinq-customer-bridge-11-docs-observability
---

# Proposal: bookings-pipelinq-customer-bridge-02-http-adapter-core

Member 2 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-01-config-contact-link`. Successor:
`bookings-pipelinq-customer-bridge-03-contact-read`.

This member builds the **HTTP adapter core**: the
`PipelinqContactAdapter` class skeleton with HTTP client init,
dependency injection, a shared retry policy (exponential backoff), and
a circuit breaker. It reads the endpoint + token declared by member
01. No request methods yet — those land in members 03/04/07.

## Why

Every read and write path in this chain shares one resilient HTTP
client with retry + circuit-breaker semantics. Building that core once
(rather than per method) keeps the later members thin and avoids
duplicated transport logic. Per the giant's risk analysis, pipelinq
unavailability must degrade gracefully — fail-fast via a circuit
breaker rather than cascading latency into booking operations.

## What Changes

- Create the `PipelinqContactAdapter` class with constructor-injected
  HTTP client (`IHTTPClientService`), `IConfig`, `ILogger`, and a cache
  layer.
- Implement the shared retry policy: exponential backoff 1s/2s/4s,
  max 3 attempts.
- Implement the circuit breaker: open after 5 consecutive failures,
  half-open after 5 minutes, log state transitions.

## Out of Scope (this member)

Contact fetch (03), klantbeeld fetch (04), timeline publishing (07).

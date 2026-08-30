---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-09-async-retry]
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

# Proposal: bookings-pipelinq-customer-bridge-10-integration-e2e-tests

Member 10 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-09-async-retry`. Successor:
`bookings-pipelinq-customer-bridge-11-docs-observability`.

This member adds the **integration and end-to-end tests** that
exercise the full read + write + resilience paths against the
member-01 pipelinq mock server. It is the verification capstone before
the docs member.

## Why

Unit tests in members 02–09 cover the adapter pieces in isolation; the
integration + E2E tests confirm the behaviours compose correctly:
create-booking → timeline publishes, profile card displays, graceful
degradation, admin config flow, and the full lifecycle event sequence.
Per ADR-008 these are Newman (API) / Playwright (UI) where applicable.

## What Changes

- Integration tests: create booking → timeline publishes; profile card
  displays in detail; graceful degradation when pipelinq is
  unavailable.
- E2E tests: admin configures the pipelinq endpoint; booking lifecycle
  triggers all four timeline events.

## Out of Scope (this member)

Docs, monitoring, changelog (11).

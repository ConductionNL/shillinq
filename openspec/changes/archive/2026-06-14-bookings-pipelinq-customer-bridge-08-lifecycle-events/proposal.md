---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-07-timeline-publish-core]
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

# Proposal: bookings-pipelinq-customer-bridge-08-lifecycle-events

Member 8 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-07-timeline-publish-core`.
Successor: `bookings-pipelinq-customer-bridge-09-async-retry`.

This member extends the lifecycle handler to the remaining booking
state transitions (**confirmed, cancelled, completed**) and adds
**auth-error handling** for timeline publishing. It reuses the publish
core from member 07.

## Why

A complete CRM timeline needs every booking state transition, not just
creation. Auth failures (revoked/expired token) are permanent and must
be handled distinctly from transient errors: no retry, an ERROR log,
an admin notification — while the booking operation still completes.

## What Changes

- Publish `booking.confirmed`, `booking.cancelled` (with cancellation
  reason if present), and `booking.completed` events, each via the
  member-07 publish + retry pattern.
- Auth-error handling: on 401, log ERROR ("Invalid pipelinq API
  token"), do NOT retry, send an admin notification if available; the
  booking operation still completes.

## Out of Scope (this member)

Async retry job + dead-letter queue (09).

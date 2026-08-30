---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-06-profile-card-ui]
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

# Proposal: bookings-pipelinq-customer-bridge-07-timeline-publish-core

Member 7 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-06-profile-card-ui`. Successor:
`bookings-pipelinq-customer-bridge-08-lifecycle-events`.

This member opens the **write path**: the
`PipelinqContactAdapter::publishTimelineEvent($event)` method and the
`booking.created` lifecycle handler that uses it. It reuses the member-02
transport (retry + circuit breaker).

## Why

Booking events must appear in the pipelinq customer-360 timeline for
CRM visibility. The first event (`booking.created`) establishes the
publish contract — fixed JSON payload, synchronous POST within the
transaction boundary, best-effort with the shared retry/circuit-breaker
— that member 08 extends to the other lifecycle states.

## What Changes

- Implement `publishTimelineEvent($event)` — POST `/api/v1/timeline`
  with the fixed payload (type, externalId, timestamp, contactId,
  metadata), 3s timeout, shared retry + circuit breaker, return
  success/failure, log all attempts.
- Implement the `booking.created` handler: build the payload on
  persist and publish it; on failure, hand off for async retry
  (member 09).

## Out of Scope (this member)

confirmed/cancelled/completed handlers + auth-error handling (08);
async retry job (09).

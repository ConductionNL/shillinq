---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-08-lifecycle-events]
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

# Proposal: bookings-pipelinq-customer-bridge-09-async-retry

Member 9 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-08-lifecycle-events`. Successor:
`bookings-pipelinq-customer-bridge-10-integration-e2e-tests`.

This member adds **async retry & resilience** for failed timeline
events: a background job, queue integration with the lifecycle handler,
and a dead-letter queue. It is the async fallback the sync publish
path (07/08) hands off to.

## Why

Per the giant's D3, timeline events publish synchronously but the
booking must commit even when pipelinq is down. The events therefore
need an eventual-delivery mechanism: a background job that retries with
exponential backoff and, after exhausting retries, parks the event in a
dead-letter queue for admin follow-up.

## What Changes

- `PipelinqTimelineRetryJob` background job: retry
  `publishTimelineEvent()`, exponential backoff (1m, 5m, 30m), up to 3
  retries, then dead-letter.
- Integrate the queue with the lifecycle handler: queue on sync
  failure (or log WARNING if no queue), retry count 0 on first queue.
- Dead-letter queue / failed-job handler: list failed events, "Retry
  now" action, per-failure ERROR log.

## Out of Scope (this member)

Integration/E2E tests (10), docs/observability (11).

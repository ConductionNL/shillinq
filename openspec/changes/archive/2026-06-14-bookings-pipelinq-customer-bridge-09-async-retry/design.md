# Design — Member 09: async retry & resilience

## Scope

Background retry job + queue integration + dead-letter queue. The
async fallback for member-07/08 sync publish failures.

## Decisions carried from the giant

- **D3** — sync publish with async fallback. This member is the
  fallback half.

## Behaviour

- `PipelinqTimelineRetryJob` (Nextcloud `IJobList`): accepts event
  details (type, bookingId, contactId, metadata, retryCount);
  `execute()` re-calls `publishTimelineEvent()`. On success: remove +
  DEBUG. On failure: increment retryCount, re-queue with exponential
  backoff (1m, 5m, 30m). After 3 retries: move to dead-letter + ERROR.
- Lifecycle handler: on sync failure, queue the job (retryCount 0) if a
  job queue is available; otherwise log WARNING (manual retry required).
- Dead-letter queue: list failed events for an admin view, provide a
  "Retry now" action, log each failure with booking id + event type.

## Reuse

- Nextcloud `IJobList` for the background job (ADR-003).

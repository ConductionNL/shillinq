# Tasks — Member 09: async retry & resilience

Sourced from the giant's Phase 4 (async retry & resilience).

## Background job

- [x] Create `PipelinqTimelineRetryJob` background job class
- [x] Constructor: accept event details (type, bookingId, contactId, metadata, retryCount)
- [x] `execute()`: call `publishTimelineEvent()` again
- [x] On success: remove job from queue, log DEBUG
- [x] On failure: increment retryCount, re-queue with backoff (1m, 5m, 30m)
- [x] On max retries (3): move to dead-letter queue, log ERROR

## Queue integration

- [x] On sync publish failure: queue `PipelinqTimelineRetryJob` if a queue is available
- [x] If queue unavailable: log WARNING (manual retry required)
- [x] Pass retry count = 0 on initial queue

## Dead-letter queue

- [x] List failed timeline events (for admin dashboard)
- [x] Provide "Retry now" action to manually re-queue
- [x] Log each failure with booking id + event type

## Tests

- [x] Test job queuing on sync failure
- [x] Test exponential backoff calculation
- [x] Test successful retry on 2nd/3rd attempt
- [x] Test dead-letter queue after max retries and manual retry trigger

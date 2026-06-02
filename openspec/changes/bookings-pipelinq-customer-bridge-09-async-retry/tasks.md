# Tasks — Member 09: async retry & resilience

Sourced from the giant's Phase 4 (async retry & resilience).

## Background job

- [ ] Create `PipelinqTimelineRetryJob` background job class
- [ ] Constructor: accept event details (type, bookingId, contactId, metadata, retryCount)
- [ ] `execute()`: call `publishTimelineEvent()` again
- [ ] On success: remove job from queue, log DEBUG
- [ ] On failure: increment retryCount, re-queue with backoff (1m, 5m, 30m)
- [ ] On max retries (3): move to dead-letter queue, log ERROR

## Queue integration

- [ ] On sync publish failure: queue `PipelinqTimelineRetryJob` if a queue is available
- [ ] If queue unavailable: log WARNING (manual retry required)
- [ ] Pass retry count = 0 on initial queue

## Dead-letter queue

- [ ] List failed timeline events (for admin dashboard)
- [ ] Provide "Retry now" action to manually re-queue
- [ ] Log each failure with booking id + event type

## Tests

- [ ] Test job queuing on sync failure
- [ ] Test exponential backoff calculation
- [ ] Test successful retry on 2nd/3rd attempt
- [ ] Test dead-letter queue after max retries and manual retry trigger

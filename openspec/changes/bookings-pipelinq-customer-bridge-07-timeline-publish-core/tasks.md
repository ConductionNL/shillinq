# Tasks — Member 07: timeline publish core

Sourced from the giant's Phase 3 (publishTimelineEvent + booking.created handler).

## publishTimelineEvent

- [ ] Implement `PipelinqContactAdapter::publishTimelineEvent($event)`
- [ ] Construct HTTP POST to `/api/v1/timeline` (3s timeout)
- [ ] Body: JSON event payload (type, externalId, timestamp, contactId, metadata)
- [ ] Reuse retry logic: 3 attempts with exponential backoff
- [ ] Reuse circuit breaker (open after 5 consecutive failures)
- [ ] Return true on success, false on failure; log all attempts

## booking.created handler

- [ ] Hook the `booking.created` event
- [ ] Construct payload: type=booking.created, externalId, timestamp, metadata
- [ ] Call `publishTimelineEvent($event)`
- [ ] On failure, hand off for async retry (member 09)
- [ ] On success, log a DEBUG entry

## Tests

- [ ] Mock timeline API: successful POST 201
- [ ] Mock timeline API: 5xx (retry and eventually fail)
- [ ] Test the booking.created payload structure

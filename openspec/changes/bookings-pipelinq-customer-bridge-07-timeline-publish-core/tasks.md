# Tasks — Member 07: timeline publish core

Sourced from the giant's Phase 3 (publishTimelineEvent + booking.created handler).

## publishTimelineEvent

- [x] Implement `PipelinqContactAdapter::publishTimelineEvent($event)`
- [x] Construct HTTP POST to `/api/v1/timeline` (3s timeout)
- [x] Body: JSON event payload (type, externalId, timestamp, contactId, metadata)
- [x] Reuse retry logic: 3 attempts with exponential backoff
- [x] Reuse circuit breaker (open after 5 consecutive failures)
- [x] Return true on success, false on failure; log all attempts

## booking.created handler

- [x] Hook the `booking.created` event
- [x] Construct payload: type=booking.created, externalId, timestamp, metadata
- [x] Call `publishTimelineEvent($event)`
- [x] On failure, hand off for async retry (member 09)
- [x] On success, log a DEBUG entry

## Tests

- [x] Mock timeline API: successful POST 201
- [x] Mock timeline API: 5xx (retry and eventually fail)
- [x] Test the booking.created payload structure

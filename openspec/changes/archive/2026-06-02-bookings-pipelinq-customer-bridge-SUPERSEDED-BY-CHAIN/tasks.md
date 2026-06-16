# Tasks — Bookings ↔ pipelinq Customer Integration

## Phase 1: Setup & Configuration (Foundation)

- [ ] Create `src/Service/PipelinqContactAdapter.php` class with HTTP client initialization
  - [ ] Inject `IHTTPClientService` (or Guzzle) via constructor
  - [ ] Inject `IConfig` for reading endpoint + token
  - [ ] Inject `ILogger` for error logging
  - [ ] Inject cache layer (Redis or simple in-memory) for Contact TTL management

- [ ] Create `src/Setting/PipelinqConfig.php` to manage endpoint + token storage
  - [ ] Add `getPipelinqEndpoint()` method
  - [ ] Add `getPipelinqToken()` method
  - [ ] Add `setPipelinqEndpoint($url)` method
  - [ ] Add `setPipelinqToken($token)` method (store in secrets store if available)
  - [ ] Add `testConnection()` method that calls pipelinq health check endpoint

- [ ] Create admin settings UI (e.g., `templates/admin-settings.php` or extend existing)
  - [ ] Text input for pipelinq API endpoint
  - [ ] Password input for API token
  - [ ] "Test Connection" button wired to `PipelinqConfig::testConnection()`
  - [ ] Success/error feedback messages

- [ ] Unit test `PipelinqConfig` with mocked HTTP responses
  - [ ] Test endpoint getter/setter
  - [ ] Test token storage (verify it's not logged)
  - [ ] Test connection validation (success case)
  - [ ] Test connection validation (failure cases: timeout, 401, 404, 5xx)

## Phase 2: Read Path — Customer Profile

- [ ] Implement `PipelinqContactAdapter::getContact($externalId)` method
  - [ ] Construct HTTP GET request to `/api/v1/contacts/{externalId}`
  - [ ] Add timeout (3 seconds)
  - [ ] Parse JSON response
  - [ ] Cache result for 5 minutes (by externalId)
  - [ ] Return Contact object (or array) with: legalName, email, phone, address, kvkNumber

- [ ] Implement cache layer for Contact data
  - [ ] Use Redis if available; fall back to in-memory array
  - [ ] TTL: 5 minutes per Contact ID
  - [ ] Cache key format: `pipelinq:contact:{externalId}`
  - [ ] Provide `clearCache()` method for manual invalidation

- [ ] Implement retry logic in `PipelinqContactAdapter`
  - [ ] Exponential backoff: 1s, 2s, 4s (max 3 attempts)
  - [ ] Circuit breaker pattern: open after 5 consecutive failures, retry after 5 min
  - [ ] Log each attempt (DEBUG for success, WARNING for failure)

- [ ] Implement `PipelinqContactAdapter::getKlantbeeld($externalId, $limit = 5)` method
  - [ ] Construct HTTP GET request to `/api/v1/contacts/{externalId}/klantbeeld`
  - [ ] Query param: `limit` (default 5, max 100)
  - [ ] Query param: `offset` (for pagination)
  - [ ] Parse transactions array
  - [ ] Return array of transaction objects with: date, description, amount, currency, status
  - [ ] No local caching (transactions are immutable; cache only within session)

- [ ] Extend booking entity (database schema) with optional `pipelinqContactId` field
  - [ ] Add migration: `ALTER TABLE oc_bookings ADD pipelinqContactId VARCHAR(255) NULL`
  - [ ] Update ORM entity class (if using Doctrine or similar)
  - [ ] Nullable, unique=false (multiple bookings can link to same contact)

- [ ] Modify booking detail controller to inject customer profile data
  - [ ] Route: `/bookings/{id}` or equivalent
  - [ ] If `booking.pipelinqContactId` is set:
    - [ ] Call `PipelinqContactAdapter::getContact($id)`
    - [ ] Call `PipelinqContactAdapter::getKlantbeeld($id)`
    - [ ] Pass both to template
  - [ ] Handle errors gracefully: set `contactError` = error message if APIs fail
  - [ ] Add cache busting (e.g., `?nocache=1`) for dev/test

- [ ] Create booking detail view template (or extend existing)
  - [ ] Customer profile card component displaying Contact data
    - [ ] Organization name (bold) or person name (given + family)
    - [ ] Email (mailto: link)
    - [ ] Phone (tel: link, if present)
    - [ ] KvK number (if organization)
    - [ ] Address (formatted)
    - [ ] Link to pipelinq Contact detail (opens in new tab)
  - [ ] Transaction history section
    - [ ] List of 5 most recent transactions (from klantbeeld)
    - [ ] Pagination controls for "Load more"
    - [ ] Fallback messages if Contact not found or history unavailable
  - [ ] CSS styling (use Nextcloud theme / existing booking styles)

- [ ] Unit tests for read path
  - [ ] Mock HTTP responses for Contact found, not found, timeout
  - [ ] Mock HTTP responses for klantbeeld success, empty, timeout
  - [ ] Test cache hit / cache miss
  - [ ] Test retry logic (succeed on 2nd attempt)
  - [ ] Test circuit breaker (open after 5 failures)
  - [ ] Test template rendering with valid and invalid Contact data

## Phase 3: Write Path — Timeline Events

- [ ] Implement `PipelinqContactAdapter::publishTimelineEvent($event)` method
  - [ ] Construct HTTP POST request to `/api/v1/timeline`
  - [ ] Request body: JSON event payload (type, externalId, timestamp, contactId, metadata)
  - [ ] Add timeout (3 seconds)
  - [ ] Retry logic: 3 attempts with exponential backoff
  - [ ] Circuit breaker: open after 5 consecutive failures
  - [ ] Return true on success, false on failure
  - [ ] Log all attempts

- [ ] Create booking lifecycle event handler
  - [ ] Hook: `booking.created` event
  - [ ] Construct event payload: type=booking.created, externalId, timestamp, metadata
  - [ ] Call `PipelinqContactAdapter::publishTimelineEvent($event)`
  - [ ] If fails, queue for async retry (see Phase 4)
  - [ ] If succeeds, log DEBUG entry

- [ ] Extend lifecycle event handler for other state transitions
  - [ ] `booking.confirmed` → type=booking.confirmed
  - [ ] `booking.cancelled` → type=booking.cancelled (include cancellation reason if present)
  - [ ] `booking.completed` → type=booking.completed
  - [ ] Each state transition follows the same publish + retry pattern

- [ ] Add auth error handling for timeline publishing
  - [ ] On 401 Unauthorized: log ERROR with "Invalid pipelinq API token"
  - [ ] Do NOT retry (auth errors are permanent)
  - [ ] Send admin notification if available
  - [ ] Booking operation still completes

- [ ] Unit tests for write path
  - [ ] Mock timeline API: successful POST 201
  - [ ] Mock timeline API: 5xx error (retry and eventually fail)
  - [ ] Mock timeline API: 401 unauthorized (no retry)
  - [ ] Test event payload structure for each booking state
  - [ ] Test circuit breaker behavior
  - [ ] Test logging at each step

## Phase 4: Async Retry & Resilience

- [ ] Create background job class for retrying failed timeline events
  - [ ] Job identifier: `PipelinqTimelineRetryJob`
  - [ ] Constructor: accept event details (type, bookingId, contactId, metadata, retryCount)
  - [ ] `execute()` method: call `PipelinqContactAdapter::publishTimelineEvent()` again
  - [ ] On success: remove job from queue, log DEBUG
  - [ ] On failure: increment retryCount, re-queue with exponential backoff (1m, 5m, 30m)
  - [ ] On max retries exhausted (3): move to dead-letter queue, log ERROR

- [ ] Integrate job queue with lifecycle event handler
  - [ ] When timeline event publish fails synchronously:
    - [ ] If job queue available: queue `PipelinqTimelineRetryJob`
    - [ ] If job queue unavailable: log WARNING (manual retry required)
  - [ ] Pass retry count = 0 on initial queue

- [ ] Create dead-letter queue / failed-job handler
  - [ ] List failed timeline events (for admin dashboard)
  - [ ] Provide "Retry now" action to manually re-queue
  - [ ] Log each failure with booking ID + event type for ops follow-up

- [ ] Unit tests for async retry
  - [ ] Test job queuing on sync failure
  - [ ] Test exponential backoff calculation
  - [ ] Test successful retry on 2nd/3rd attempt
  - [ ] Test dead-letter queue after max retries
  - [ ] Test manual retry trigger

## Phase 5: Integration & E2E Testing

- [ ] Integration test: create booking → timeline event publishes
  - [ ] Setup: pipelinq API mock server
  - [ ] Create booking with valid pipelinqContactId
  - [ ] Assert: HTTP POST to timeline was called with correct payload
  - [ ] Assert: booking is saved in Nextcloud
  - [ ] Assert: timeline event confirmation logged

- [ ] Integration test: customer profile card displays in detail view
  - [ ] Setup: pipelinq API mock returning Contact + klantbeeld
  - [ ] Load booking detail view
  - [ ] Assert: profile card renders with name, email, phone
  - [ ] Assert: transaction history displays up to 5 entries

- [ ] Integration test: graceful degradation (pipelinq unavailable)
  - [ ] Setup: pipelinq API mock returning 5xx
  - [ ] Create booking
  - [ ] Assert: booking is saved (non-blocking)
  - [ ] Assert: timeline event is queued for retry
  - [ ] Load booking detail
  - [ ] Assert: profile card shows error message
  - [ ] Assert: transaction history is hidden

- [ ] E2E test: admin configures pipelinq endpoint
  - [ ] Navigate to settings page
  - [ ] Enter endpoint + token
  - [ ] Click "Test Connection"
  - [ ] Assert: success message displayed
  - [ ] Assert: settings persisted (page reload confirms)

- [ ] E2E test: booking lifecycle triggers all timeline events
  - [ ] Create booking → booking.created published
  - [ ] Confirm booking → booking.confirmed published
  - [ ] Cancel booking → booking.cancelled published
  - [ ] Complete booking → booking.completed published
  - [ ] Assert: all 4 events appear in pipelinq timeline (manual or API verification)

## Phase 6: Documentation & Deployment

- [ ] Write admin guide: "Configuring pipelinq Integration"
  - [ ] How to find pipelinq API endpoint + token
  - [ ] How to enter credentials in Nextcloud settings
  - [ ] How to test connection
  - [ ] Troubleshooting: "Connection failed" messages
  - [ ] Troubleshooting: "Customer data unavailable" in booking detail

- [ ] Write developer guide: "pipelinq Integration Architecture"
  - [ ] Overview of `PipelinqContactAdapter` class
  - [ ] HTTP client initialization + error handling
  - [ ] Cache management (TTL, invalidation)
  - [ ] Circuit breaker pattern (when it opens, how it resets)
  - [ ] Async retry logic (exponential backoff)
  - [ ] How to extend with new timeline event types

- [ ] Add monitoring / alerting (if ops dashboard available)
  - [ ] Alert on circuit breaker open (5 consecutive failures)
  - [ ] Alert on dead-letter queue growth (failed timeline events)
  - [ ] Dashboard: pipelinq API response times (percentile latencies)
  - [ ] Dashboard: pipelinq API error rates (5xx, 4xx breakdown)

- [ ] Update CHANGELOG
  - [ ] Version: TBD
  - [ ] Feature: "pipelinq customer profile integration"
  - [ ] Details: read Contact + klantbeeld, publish timeline events

- [ ] Create PR checklist / code review guidelines
  - [ ] All specs implemented per REQ-BPC-* requirements
  - [ ] All unit tests passing (> 80% coverage for adapter class)
  - [ ] All integration tests passing
  - [ ] Logging verified in nextcloud.log (expected messages present)
  - [ ] Admin settings UI tested manually
  - [ ] pipelinq API contracts verified (with pipelinq team)
  - [ ] Error messages are user-friendly (not exposing internal details)
  - [ ] No hardcoded pipelinq URLs or tokens
  - [ ] Cache TTL and circuit breaker times are reasonable (documented in code)

## Definition of Done

A spec implementation is complete when:

1. **All 10 requirements (REQ-BPC-001 to REQ-BPC-010) are implemented and tested**
   - Read path (Contact + klantbeeld) working end-to-end
   - Write path (timeline events) working end-to-end
   - Configuration interface tested
   - Error handling + retry logic tested

2. **All phases (1-6) are complete**
   - Setup & config done
   - Read & write paths implemented
   - Async retry infrastructure in place
   - Integration tests passing
   - Documentation updated

3. **Code quality gates pass**
   - Unit test coverage > 80% for new code
   - All integration tests passing
   - Code review approved by maintainer
   - No hardcoded secrets or pipelinq URLs
   - Logging is appropriate (DEBUG, INFO, WARNING, ERROR levels used correctly)

4. **Operational readiness**
   - Admin guide published
   - Monitoring / alerting configured (if applicable)
   - Rollback plan documented and tested
   - Team trained on troubleshooting

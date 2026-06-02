# Specs — Bookings ↔ pipelinq Customer Integration

## Overview

This spec defines the data contracts, API integration patterns, and UI
requirements for bridging the Nextcloud Bookings app with pipelinq
customer management.

---

## REQ-BPC-001: Load pipelinq Contact by externalId

**Objective**: Fetch customer profile from pipelinq API when booking
detail view loads.

#### Scenario: Contact found in pipelinq

```gherkin
GIVEN a booking with pipelinqContactId = "org-kvk-12345678"
AND pipelinq API is reachable
WHEN the booking detail view loads
THEN HTTP GET /api/v1/contacts/org-kvk-12345678 is called
AND the Contact response includes: legalName, email, phone, address
AND the contact data is cached locally (TTL 5 minutes)
```

#### Scenario: Contact not found in pipelinq

```gherkin
GIVEN a booking with pipelinqContactId = "org-unknown"
AND pipelinq API is reachable
WHEN the booking detail view loads
AND HTTP GET /api/v1/contacts/org-unknown returns 404
THEN the customer profile card shows "Customer not found"
AND the externalId is displayed for manual reference
AND no error is logged (404 is expected)
```

#### Scenario: pipelinq API timeout

```gherkin
GIVEN a booking with valid pipelinqContactId
AND pipelinq API is slow (response > 3 seconds)
WHEN the booking detail view loads
AND HTTP timeout of 3 seconds is exceeded
AND retry count reaches 3 attempts
THEN the customer profile card shows "Unable to load customer data"
AND booking operations proceed (non-blocking)
AND a WARNING is logged for ops visibility
```

#### Scenario: pipelinq API returns invalid JSON

```gherkin
GIVEN a booking with valid pipelinqContactId
AND pipelinq API returns malformed JSON (e.g., 200 OK + garbage)
WHEN the booking detail view loads
AND JSON parsing fails
THEN the customer profile card shows fallback UI
AND a WARNING is logged with the raw response for debugging
AND retry is not attempted (client error, not transient)
```

---

## REQ-BPC-002: Load klantbeeld transaction history

**Objective**: Fetch and display customer transaction history from
pipelinq klantbeeld 360.

#### Scenario: Klantbeeld loaded successfully

```gherkin
GIVEN a booking with a valid Contact loaded (from REQ-BPC-001)
WHEN the booking detail view renders the customer profile card
THEN HTTP GET /api/v1/contacts/{externalId}/klantbeeld is called
AND the response includes transactions array
AND up to 5 most recent transactions are displayed
AND each transaction shows: date, description, amount, status
```

#### Scenario: Klantbeeld pagination

```gherkin
GIVEN a customer with > 5 transactions in klantbeeld
WHEN the detail view displays the first 5 transactions
AND the user clicks "Load more" or pagination control
THEN the next 5 transactions are fetched
AND pagination state is tracked in the UI (client-side or session)
```

#### Scenario: Empty klantbeeld (no transactions)

```gherkin
GIVEN a Contact with no transaction history in pipelinq
WHEN the booking detail view loads klantbeeld
AND the transactions array is empty
THEN the history section shows "No previous transactions"
AND no error is logged (empty history is valid)
```

#### Scenario: Klantbeeld unavailable but Contact available

```gherkin
GIVEN a booking with valid Contact loaded
AND klantbeeld API returns 5xx server error
AND Contact fetch succeeded
WHEN the detail view loads
THEN the customer profile card displays (from Contact data)
AND the transaction history section shows "History unavailable (server error)"
AND booking operations proceed
```

---

## REQ-BPC-003: Display customer profile card in booking detail

**Objective**: Render customer context inline in the booking detail
view for quick reference.

#### Scenario: Customer profile card layout (organization)

```gherkin
GIVEN a booking linked to an organization Contact
AND Contact data is loaded from pipelinq
WHEN the booking detail view renders
THEN the customer profile card displays:
  - Organization legal name (bold, large)
  - KvK number (if present)
  - Primary contact person (givenName + familyName)
  - Email (with mailto: link)
  - Phone (with tel: link, if present)
  - Address (formatted)
  - Link to pipelinq Contact detail (external, opens in new tab)
```

#### Scenario: Customer profile card layout (individual)

```gherkin
GIVEN a booking linked to an individual Contact
WHEN the booking detail view renders
THEN the customer profile card displays:
  - Given name + family name (bold)
  - Email (with mailto: link)
  - Phone (with tel: link, if present)
  - Address (if present)
```

#### Scenario: Missing or masked fields

```gherkin
GIVEN a Contact with incomplete data (e.g., no phone)
WHEN the profile card renders
THEN missing fields are omitted (no empty labels)
AND optional fields without values show no placeholder
```

#### Scenario: Profile card is read-only

```gherkin
GIVEN the customer profile card is displayed
WHEN the user attempts to edit any field in the card
THEN the edit action is disabled
AND a tooltip or hint says "Edit customer details in pipelinq"
```

---

## REQ-BPC-004: Publish booking lifecycle events to pipelinq timeline

**Objective**: Automatically notify pipelinq of booking state changes
via timeline events.

#### Scenario: booking.created event published

```gherkin
GIVEN a new booking is created in Nextcloud
AND the booking references a valid pipelinqContactId
WHEN the booking is persisted to the database
THEN an event payload is constructed:
  {
    "type": "booking.created",
    "externalId": "<booking-uuid>",
    "timestamp": "<iso-8601>",
    "contactId": "<pipelinqContactId>",
    "metadata": {
      "bookingNumber": "<local-booking-id>",
      "service": "<service-type>",
      "guestCount": <number>,
      "eventDate": "<iso-date>",
      "venue": "<location>"
    }
  }
AND HTTP POST /api/v1/timeline is called with the payload
AND response code 201 Created confirms the event was recorded
```

#### Scenario: booking.confirmed event published

```gherkin
GIVEN a booking in draft/pending state
WHEN the booking is confirmed (user clicks "Confirm" and saves)
THEN HTTP POST /api/v1/timeline is called with type: "booking.confirmed"
AND the payload includes the same metadata as booking.created
AND pipelinq timeline shows the confirmation event with timestamp
```

#### Scenario: booking.cancelled event published

```gherkin
GIVEN a confirmed booking
WHEN the booking is cancelled (user clicks "Cancel" and confirms)
THEN HTTP POST /api/v1/timeline is called with type: "booking.cancelled"
AND cancellation reason (if entered) is included in metadata
AND pipelinq timeline shows the cancellation with timestamp
```

#### Scenario: booking.completed event published

```gherkin
GIVEN a confirmed booking with eventDate in the past
WHEN the booking is marked as completed (manual or automatic after event)
THEN HTTP POST /api/v1/timeline is called with type: "booking.completed"
AND pipelinq timeline is updated
```

---

## REQ-BPC-005: Handle pipelinq API errors gracefully (write path)

**Objective**: Ensure booking operations proceed even if pipelinq
timeline publishing fails.

#### Scenario: Timeline event publish fails (transient error)

```gherkin
GIVEN a booking is being created
AND timeline event POST to pipelinq returns 5xx
WHEN the HTTP request times out or fails
THEN the booking is committed to Nextcloud database (transaction completes)
AND the timeline event is queued for async retry (if job queue available)
AND the user sees a SUCCESS notification ("Booking created")
AND a background job retries the timeline event up to 3 times
AND if all retries fail, a WARNING is logged for manual follow-up
```

#### Scenario: Timeline event publish fails (auth error)

```gherkin
GIVEN a booking state change triggers timeline event publishing
AND the pipelinq API token is invalid (expired or revoked)
WHEN HTTP POST returns 401 Unauthorized
THEN the booking is still committed to Nextcloud
AND the event is NOT retried (auth error is permanent)
AND a ERROR is logged with "Invalid pipelinq API token; check config"
AND an admin notification is sent (if admin notifications are enabled)
```

#### Scenario: pipelinq circuit breaker opens

```gherkin
GIVEN 5 consecutive timeline event publishing failures
WHEN the circuit breaker opens (fail-fast mode)
AND a subsequent booking state change triggers timeline publishing
THEN the HTTP call is not attempted (circuit open)
AND the event is queued for retry
AND after 5 minutes, the circuit breaker resets (half-open)
AND the next event attempt tests the circuit; if successful, it closes
```

---

## REQ-BPC-006: Configuration interface for pipelinq connection

**Objective**: Allow admins to configure pipelinq API endpoint and
authentication.

#### Scenario: Configure pipelinq endpoint and token

```gherkin
GIVEN the Nextcloud admin is logged in
WHEN the admin navigates to "Settings > Bookings > pipelinq Integration"
THEN two input fields are displayed:
  - "pipelinq API Endpoint" (e.g., https://api.pipelinq.nl/v1)
  - "API Token" (password field, masked)
AND a "Test Connection" button is available
```

#### Scenario: Test connection succeeds

```gherkin
GIVEN the admin has entered valid pipelinq endpoint + token
WHEN the admin clicks "Test Connection"
THEN HTTP GET /api/v1/contacts/test (or similar health check) is called
AND response 200 OK is received
AND a green success message shows "Connected to pipelinq successfully"
```

#### Scenario: Test connection fails

```gherkin
GIVEN invalid endpoint or token
WHEN the admin clicks "Test Connection"
AND HTTP request fails (timeout, 401, 404, etc.)
THEN an error message shows the HTTP status + response body
AND the settings are not saved until connection is valid
```

#### Scenario: Configuration is persisted securely

```gherkin
GIVEN the admin has entered and saved pipelinq credentials
WHEN the settings are saved
THEN the API token is stored in Nextcloud secrets store (not plaintext)
AND the endpoint URL is stored in app config (plaintext OK)
AND on page reload, the token field is masked (not displayed)
```

---

## REQ-BPC-007: Booking data model includes optional pipelinqContactId

**Objective**: Link bookings to pipelinq Contacts.

#### Scenario: Booking references pipelinq Contact

```gherkin
GIVEN the booking entity has an optional `pipelinqContactId` field
WHEN a new booking is created
AND the customer is looked up from pipelinq
THEN the booking's pipelinqContactId is set to the Contact.externalId
AND future detail views can fetch customer profile using this ID
```

#### Scenario: Booking without pipelinq Contact

```gherkin
GIVEN a booking with pipelinqContactId = NULL
WHEN the booking detail view loads
THEN the customer profile card is not displayed
AND a message shows "Customer not linked to pipelinq"
AND booking operations proceed normally (no blocking)
```

---

## REQ-BPC-008: Logging and observability

**Objective**: Provide ops visibility into pipelinq integration health.

#### Scenario: Successful Contact fetch logged

```gherkin
GIVEN a booking detail loads successfully
WHEN pipelinq Contact is fetched and cached
THEN a DEBUG-level log entry is recorded:
  "Loaded pipelinq Contact <externalId> (from API / from cache)"
```

#### Scenario: API error logged with context

```gherkin
GIVEN a pipelinq API call fails
WHEN the error occurs
THEN a WARNING-level log entry records:
  - HTTP method + endpoint
  - HTTP status code + response body (first 500 chars)
  - Retry attempt number
  - ExternalId being queried (if applicable)
AND the log is visible in `nextcloud.log`
```

#### Scenario: Circuit breaker state changes logged

```gherkin
GIVEN the circuit breaker changes state (open → closed, half-open, etc.)
WHEN the state transition occurs
THEN a WARNING-level log entry records the new state
AND timestamp of the transition
```

---

## REQ-BPC-009: Cache invalidation and expiry

**Objective**: Manage local cache of pipelinq Contact data.

#### Scenario: Contact cache expires after 5 minutes

```gherkin
GIVEN a Contact is fetched and cached with TTL 5 minutes
WHEN 5 minutes elapse
THEN the cached entry is considered stale
AND the next booking detail load fetches fresh Contact from pipelinq API
```

#### Scenario: Manual cache invalidation (admin action)

```gherkin
GIVEN a Contact record is cached
WHEN an admin requests "Clear pipelinq cache"
THEN all cached Contact entries are deleted immediately
AND subsequent requests fetch fresh data from pipelinq
```

#### Scenario: Cache gracefully degrades if backend unavailable

```gherkin
GIVEN a Contact is cached locally
AND pipelinq API is unavailable
WHEN a booking detail loads
AND the cache entry is still valid
THEN the cached Contact is displayed (no API call attempted)
AND the user sees up-to-date data without latency penalty
```

---

## REQ-BPC-010: Async timeline event retry logic

**Objective**: Ensure timeline events are eventually published even if
initial publish fails.

#### Scenario: Failed event queued for retry

```gherkin
GIVEN a booking state change triggers timeline event publishing
AND the initial POST to pipelinq fails (transient error)
WHEN the event is queued for async retry
THEN a job is registered with:
  - Event type (booking.created, etc.)
  - Booking ID
  - Contact ID
  - Retry count (initially 0)
  - Next retry time (exponential backoff: 1m, 5m, 30m)
```

#### Scenario: Async job retries and succeeds

```gherkin
GIVEN a timeline event is queued for retry
WHEN the retry job executes at the scheduled time
AND the pipelinq API is now available
THEN HTTP POST /api/v1/timeline is called again
AND response 201 Created confirms success
AND the job is removed from the retry queue
AND a DEBUG log entry records successful publish
```

#### Scenario: Async job exhausts retries

```gherkin
GIVEN a timeline event is queued with max retries = 3
WHEN all 3 retries fail
THEN the job is moved to a dead-letter queue
AND an ERROR log entry records:
  - Event details
  - Final error message
  - Booking ID for manual follow-up
AND an admin can manually trigger re-publish if desired
```

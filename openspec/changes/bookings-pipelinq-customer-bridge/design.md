# Design — Bookings ↔ pipelinq Customer Bridge

## Decisions

### D1 — pipelinq Contact lookup is by externalId, not email or phone

The booking must carry a stable, unique reference to a pipelinq
Contact. KvK number (for businesses) or a pipelinq-assigned
externalId (for individuals) is the canonical identifier. Email and
phone are query filters only (for human reference), not primary keys.

**Alternative considered**: Use email as the primary identifier. Rejected
— emails change, are non-unique across some CRM systems, and can be
redacted. KvK number for organizations is Dutch-standard and stable.
For individuals, pipelinq-assigned externalId is opaque but immutable.

### D2 — Customer profile card is read-only in booking detail view

The booking detail view displays pipelinq Customer data as a
contextual card, not an edit form. All customer mutations remain in
pipelinq; the bookings app is a read-only consumer.

**Alternative considered**: Embed a customer edit form in the booking
detail. Rejected — violates separation of concerns, creates data
consistency risk, and duplicates pipelinq's UI. Booking app respects
pipelinq as the authoritative CRM.

### D3 — Timeline events publish synchronously, with async fallback

Booking lifecycle events (CREATED, CONFIRMED, CANCELLED, COMPLETED)
are published to pipelinq timeline synchronously within the transaction
boundary. If the POST to pipelinq timeline fails, the booking is still
committed; the event is queued for async retry (via background worker
or cron).

**Alternative considered**: Async-only publishing via message queue.
Rejected — adds complexity (need to track which events were published)
and delays CRM visibility. Sync+fallback is simpler and provides
real-time timeline updates for happy path.

### D4 — pipelinq is read-only for Customer; Bookings app drives event lifecycle

Customer creation, modification, and deletion is pipelinq's
responsibility. The Bookings app is a **read-only consumer** for
customer context and an **event publisher** for booking events.

No bidirectional sync or conflict resolution: if a customer is deleted
in pipelinq, existing bookings in Nextcloud remain; they simply show
"Customer data unavailable" when the detail view loads.

**Alternative considered**: Two-way sync with conflict resolution.
Rejected — architectural complexity, unclear win/loss resolution,
state explosion. Single-writer (pipelinq for customer, Bookings app
for booking) eliminates ambiguity.

### D5 — HTTP adapter uses Guzzle with exponential backoff + circuit breaker

The `PipelinqContactAdapter` uses Guzzle HTTP client (or Nextcloud's
`IHTTPClientService` if available) with:
- Timeout: 3 seconds
- Retry: up to 3 attempts with exponential backoff (1s, 2s, 4s)
- Circuit breaker: after 5 consecutive failures, open for 5 minutes
  (fail-fast to avoid cascading latency)
- Logging: all failures logged at WARNING level for ops visibility

**Alternative considered**: Sync-or-die (block booking creation if
pipelinq is down). Rejected — poor availability (pipelinq unavailability
cascades to booking unavailability). Graceful degradation is acceptable
per business requirement.

### D6 — Contact profile cache: 5 minute TTL, per-contact-ID

pipelinq Contact data (name, email, phone, org) is cached locally for 5
minutes to reduce API load and latency. Cache key is pipelinq
Contact.externalId. Klantbeeld history is immutable; cached indefinitely
within the session.

**Alternative considered**: No cache (always fetch fresh). Rejected —
N+1 API calls if user loads the same booking detail multiple times in
a session. No cache (async pre-fetch). Rejected — complexity and
eventual consistency semantics.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| HTTP client | Nextcloud `IHTTPClientService` or Guzzle | Wrap in adapter class; consistent with Nextcloud standards |
| Config storage | Nextcloud `IConfig` | Store pipelinq endpoint + token in app config |
| Error logging | Nextcloud `ILogger` | Log pipelinq failures; visible in nextcloud.log |
| Booking data model | Existing booking entity (assumed) | Extend with optional `pipelinqContactId` field |
| Caching | PHP array in-memory or Redis (if available) | Simple TTL cache; no persistence needed for contact data |
| Async job queue | Nextcloud `IJobList` (if available) | Fallback timeline event publishing if sync fails |
| UI rendering | Existing booking detail template | Add customer profile card component; CSS/Twig from shared Nextcloud theme |

**Net new code in implementation cycle**: `PipelinqContactAdapter` class
(~150 lines), detail view extension (template fragment + JS for profile
card), config interface. No new database schema.

## Seed Data

### Example Contact Profile (Dutch organization)

```json
{
  "externalId": "org-kvk-12345678",
  "type": "organization",
  "legalName": "Bakkerij de Zon B.V.",
  "kvkNumber": "12345678",
  "email": "info@bakkerij-de-zon.nl",
  "phone": "+31 6 12345678",
  "address": "Zonneplein 10, 1234 AB Amsterdam",
  "contact": {
    "givenName": "Jan",
    "familyName": "Jansen",
    "email": "jan@bakkerij-de-zon.nl"
  }
}
```

### Example klantbeeld Transaction History

```json
{
  "contactId": "org-kvk-12345678",
  "transactions": [
    {
      "id": "txn-001",
      "date": "2026-05-20",
      "description": "Booking confirmation: catering for 50 ppl, 2026-06-15",
      "amount": 1250.00,
      "currency": "EUR",
      "status": "confirmed"
    },
    {
      "id": "txn-002",
      "date": "2026-05-15",
      "description": "Booking deposit received",
      "amount": 500.00,
      "currency": "EUR",
      "status": "settled"
    },
    {
      "id": "txn-003",
      "date": "2026-04-10",
      "description": "Previous booking final payment",
      "amount": 800.00,
      "currency": "EUR",
      "status": "settled"
    }
  ]
}
```

### Example Booking → Timeline Event Payload

```json
{
  "type": "booking.created",
  "externalId": "booking-uuid-xxx",
  "timestamp": "2026-05-21T09:30:00+02:00",
  "contactId": "org-kvk-12345678",
  "metadata": {
    "bookingNumber": "BK-2026-05-001",
    "service": "catering",
    "guestCount": 50,
    "eventDate": "2026-06-15",
    "venue": "Amsterdam"
  }
}
```

No seed data beyond examples: Contact data is maintained in pipelinq;
the Bookings app reads on-demand.

## Error Scenarios

### Scenario 1: pipelinq unavailable at detail-view load time

- Booking detail loads successfully
- Customer profile card shows "Unable to load customer data. Try again?"
- Timeline history section hidden
- Booking operations (confirm, cancel) proceed normally
- Circuit breaker opens after 5 consecutive failures; subsequent calls
  fail fast

### Scenario 2: Contact not found in pipelinq

- Booking links to externalId "org-kvk-unknown"
- API returns 404 Contact Not Found
- Detail view shows "Customer not found" with customer ID displayed
- Timeline history unavailable
- Booking operations proceed; user can manually investigate

### Scenario 3: Klantbeeld API temporarily slow

- Contact fetch returns within 3s timeout
- Klantbeeld fetch takes > 3s; request times out after 3 attempts
- History section shows "Transaction history unavailable (timeout)"
- Contact profile card displays (cached or just-fetched)
- Booking operations proceed

### Scenario 4: Timeline event publish fails (booking created)

- Booking is committed to Nextcloud database
- Timeline POST to pipelinq fails (5xx server error)
- Event is logged and queued for async retry
- If async queue unavailable, logged as WARNING for manual intervention
- Booking is visible in Nextcloud; timeline update is eventual (within
  5 minutes via retry)

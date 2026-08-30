# Design — Member 07: timeline publish core

## Scope

`publishTimelineEvent($event)` + the `booking.created` handler.
Reuses the member-02 transport.

## Decisions carried from the giant

- **D3** — events publish synchronously within the transaction
  boundary; if the POST fails, the booking is still committed and the
  event is handed to async retry (member 09). Async-only publishing
  rejected (complexity + delayed CRM visibility).
- **Risk 3** — fixed JSON payload contract; pipelinq rejects
  mismatches with 422; adapter logs + retries transient failures.

## Payload contract

```json
{
  "type": "booking.created",
  "externalId": "<booking-uuid>",
  "timestamp": "<iso-8601>",
  "contactId": "<pipelinqContactId>",
  "metadata": { "bookingNumber": "...", "service": "...",
    "guestCount": 0, "eventDate": "...", "venue": "..." }
}
```

## Behaviour

- POST `/api/v1/timeline`, 3s timeout, retry up to 3 with exponential
  backoff (member 02), circuit breaker shared.
- Return true on 201, false otherwise; log all attempts.
- `booking.created` handler builds the payload on persist and calls
  the method; on failure, defers to member-09 async retry.

## Security (ADR-005)

- Token sent as auth header only, never logged; payloads carry no
  secrets.

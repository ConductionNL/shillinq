# Design — Member 03: Contact read path

## Scope

`getContact($externalId)` + the 5-minute per-contact cache and
invalidation. Consumes the member-02 transport (retry + circuit
breaker).

## Decisions carried from the giant

- **D6** — Contact data cached locally 5 minutes per contact id; cache
  key is the pipelinq `externalId`. No-cache and async-prefetch
  alternatives rejected (N+1 / complexity).
- **D1** — lookup is by `externalId`, supplied by the booking's
  `pipelinqContactId` (member 01).

## Behaviour

- GET `/api/v1/contacts/{externalId}` with 3s timeout via the member-02
  client.
- Parse JSON into a Contact shape: `legalName`, `email`, `phone`,
  `address`, `kvkNumber`.
- 404 is an expected outcome (Contact not found) — surfaced to the
  caller without logging an error.
- Malformed JSON → fallback shape + WARNING, no retry (client error).
- On a valid cache hit while pipelinq is unavailable, serve the cached
  Contact (no API call).

## Security (ADR-005)

- The cached Contact holds only data already returned by pipelinq; no
  credentials cached. Cache keys are namespaced per `externalId`.

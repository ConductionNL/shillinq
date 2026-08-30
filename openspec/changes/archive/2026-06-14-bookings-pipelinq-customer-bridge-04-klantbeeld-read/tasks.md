# Tasks — Member 04: klantbeeld read path

Sourced from the giant's Phase 2 (getKlantbeeld).

## getKlantbeeld

- [x] Implement `PipelinqContactAdapter::getKlantbeeld($externalId, $limit = 5)`
- [x] Construct HTTP GET to `/api/v1/contacts/{externalId}/klantbeeld` (3s timeout)
- [x] Add `limit` query param (default 5, max 100)
- [x] Add `offset` query param for pagination
- [x] Parse the transactions array
- [x] Return transaction objects with date, description, amount, currency, status
- [x] No persistent cache (immutable; session-scoped only)

## Graceful handling

- [x] Treat an empty transactions array as a valid "no transactions" result
- [x] Return an "unavailable" marker when klantbeeld 5xx while Contact succeeded

## Tests

- [x] Mock klantbeeld success / empty / timeout responses
- [x] Test pagination (offset advances the window)
- [x] Test klantbeeld unavailable while Contact available

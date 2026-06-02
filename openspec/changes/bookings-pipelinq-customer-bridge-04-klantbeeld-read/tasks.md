# Tasks — Member 04: klantbeeld read path

Sourced from the giant's Phase 2 (getKlantbeeld).

## getKlantbeeld

- [ ] Implement `PipelinqContactAdapter::getKlantbeeld($externalId, $limit = 5)`
- [ ] Construct HTTP GET to `/api/v1/contacts/{externalId}/klantbeeld` (3s timeout)
- [ ] Add `limit` query param (default 5, max 100)
- [ ] Add `offset` query param for pagination
- [ ] Parse the transactions array
- [ ] Return transaction objects with date, description, amount, currency, status
- [ ] No persistent cache (immutable; session-scoped only)

## Graceful handling

- [ ] Treat an empty transactions array as a valid "no transactions" result
- [ ] Return an "unavailable" marker when klantbeeld 5xx while Contact succeeded

## Tests

- [ ] Mock klantbeeld success / empty / timeout responses
- [ ] Test pagination (offset advances the window)
- [ ] Test klantbeeld unavailable while Contact available

# Tasks — Member 03: Contact read path

Sourced from the giant's Phase 2 (getContact + cache).

## getContact

- [ ] Implement `PipelinqContactAdapter::getContact($externalId)`
- [ ] Construct HTTP GET to `/api/v1/contacts/{externalId}` (3s timeout)
- [ ] Parse JSON response into a Contact shape
- [ ] Return Contact with legalName, email, phone, address, kvkNumber
- [ ] Treat 404 as expected "not found" (no error logged)
- [ ] Treat malformed JSON as fallback + WARNING (no retry)

## Cache layer

- [ ] Use Redis if available; fall back to in-memory array
- [ ] TTL: 5 minutes per Contact id; key `pipelinq:contact:{externalId}`
- [ ] Provide `clearCache()` for manual invalidation
- [ ] Serve a still-valid cached Contact when pipelinq is unavailable

## Tests

- [ ] Mock Contact found / not found / timeout responses
- [ ] Test cache hit / cache miss
- [ ] Test cache expiry after TTL and manual invalidation
- [ ] Test graceful cache degradation when backend is unavailable

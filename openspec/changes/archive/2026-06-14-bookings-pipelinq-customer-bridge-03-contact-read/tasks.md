# Tasks — Member 03: Contact read path

Sourced from the giant's Phase 2 (getContact + cache).

## getContact

- [x] Implement `PipelinqContactAdapter::getContact($externalId)`
- [x] Construct HTTP GET to `/api/v1/contacts/{externalId}` (3s timeout)
- [x] Parse JSON response into a Contact shape
- [x] Return Contact with legalName, email, phone, address, kvkNumber
- [x] Treat 404 as expected "not found" (no error logged)
- [x] Treat malformed JSON as fallback + WARNING (no retry)

## Cache layer

- [x] Use Redis if available; fall back to in-memory array
- [x] TTL: 5 minutes per Contact id; key `pipelinq:contact:{externalId}`
- [x] Provide `clearCache()` for manual invalidation
- [x] Serve a still-valid cached Contact when pipelinq is unavailable

## Tests

- [x] Mock Contact found / not found / timeout responses
- [x] Test cache hit / cache miss
- [x] Test cache expiry after TTL and manual invalidation
- [x] Test graceful cache degradation when backend is unavailable

## Implementation notes (as shipped)

- The adapter exposes `getContact(string $externalId): PipelinqContact`
  — a read-through facade over the slice-02 `protected request()`
  transport. The 3s timeout, 1s/2s/4s retry schedule and the 5-failure
  circuit breaker are inherited unchanged.
- The 5-minute TTL is exposed as
  `PipelinqContactAdapter::CONTACT_CACHE_TTL_SECONDS = 300`; the cache
  key prefix lives on `CONTACT_CACHE_KEY_PREFIX = 'pipelinq:contact:'`
  so `clearCache()` can wipe every Contact entry via
  `ICache::clear($prefix)` without touching unrelated caches.
- "Redis if available; fall back to in-memory array" is delivered by
  the injected `ICache` — Nextcloud's `ICacheFactory` already swaps in
  Redis / APCu / Memcached when configured, and falls back to its
  in-memory implementation otherwise.
- 404 outcomes are surfaced as a `PipelinqContact` with
  `isFound()=false` so callers never see a `null` on a public method.
  The fallback DTO is cached (5-minute TTL) so a repeat lookup with
  the same unknown id does not re-hit pipelinq inside the TTL window.
- Malformed JSON is the only outcome that ever logs a slice-03 WARNING;
  the fallback DTO is NOT cached so the next lookup retries (a fix
  upstream should be picked up immediately).
- Graceful degradation: on a transport failure other than 404 /
  malformed JSON, the adapter re-reads the cache and serves any
  still-valid entry; if the cache is empty the transport exception
  propagates unchanged to the caller.

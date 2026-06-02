# Tasks — Member 02: HTTP adapter core

Sourced from the giant's Phase 1 (adapter init) and Phase 2 (retry +
circuit breaker).

## Adapter class + DI

- [ ] Create `PipelinqContactAdapter` class with HTTP client initialization
- [ ] Inject `IHTTPClientService` (HTTP transport) via constructor
- [ ] Inject `IConfig` for reading endpoint + token (member 01 keys)
- [ ] Inject `ILogger` for error logging
- [ ] Inject cache layer (Redis or in-memory) for later TTL management

## Retry policy

- [ ] Implement exponential backoff: 1s, 2s, 4s (max 3 attempts)
- [ ] Do not retry non-transient client errors
- [ ] Log each attempt (DEBUG on success, WARNING on failure)

## Circuit breaker

- [ ] Open the breaker after 5 consecutive failures
- [ ] Fail fast while open; transition to half-open after 5 minutes
- [ ] Log each circuit-breaker state transition at WARNING

## Tests

- [ ] Unit-test retry logic (succeeds on 2nd attempt)
- [ ] Unit-test circuit breaker (opens after 5 failures, half-open after cooldown)

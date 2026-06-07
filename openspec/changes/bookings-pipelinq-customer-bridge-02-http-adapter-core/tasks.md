# Tasks — Member 02: HTTP adapter core

Sourced from the giant's Phase 1 (adapter init) and Phase 2 (retry +
circuit breaker).

## Adapter class + DI

- [x] Create `PipelinqContactAdapter` class with HTTP client initialization
- [x] Inject `IHTTPClientService` (HTTP transport) via constructor
- [x] Inject `IConfig` for reading endpoint + token (member 01 keys)
- [x] Inject `ILogger` for error logging
- [x] Inject cache layer (Redis or in-memory) for later TTL management

## Retry policy

- [x] Implement exponential backoff: 1s, 2s, 4s (max 3 attempts)
- [x] Do not retry non-transient client errors
- [x] Log each attempt (DEBUG on success, WARNING on failure)

## Circuit breaker

- [x] Open the breaker after 5 consecutive failures
- [x] Fail fast while open; transition to half-open after 5 minutes
- [x] Log each circuit-breaker state transition at WARNING

## Tests

- [x] Unit-test retry logic (succeeds on 2nd attempt)
- [x] Unit-test circuit breaker (opens after 5 failures, half-open after cooldown)

# Design — Member 02: HTTP adapter core

## Scope

The `PipelinqContactAdapter` transport core: construction, DI, retry
policy, circuit breaker. Carries the giant's D5 decision.

## Decisions carried from the giant

- **D5** — adapter uses Nextcloud `IHTTPClientService` (preferred over
  raw Guzzle for stack consistency, ADR-003) with: 3s timeout, up to 3
  retries with exponential backoff (1s/2s/4s), circuit breaker opening
  after 5 consecutive failures for 5 minutes, all failures logged at
  WARNING.

## Reuse

| Capability | Existing | Strategy |
|---|---|---|
| HTTP client | NC `IHTTPClientService` | wrap in adapter |
| Config | NC `IConfig` (member 01 keys) | read endpoint + token |
| Logging | NC `ILogger` | log transport failures |
| Cache | in-memory / Redis | injected here, used by member 03 |

## Security (ADR-005)

- The token read from the secrets store is sent only as an auth header;
  never logged. Log lines redact credentials.
- Circuit-breaker state transitions logged at WARNING for ops
  visibility.

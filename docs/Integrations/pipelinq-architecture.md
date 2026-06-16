---
sidebar_position: 3
title: Pipelinq — Integration Architecture
description: Developer guide for the pipelinq customer-bridge — adapter, cache, circuit breaker, async retry, observability.
---

# Pipelinq Integration Architecture

This document is the developer-facing companion to the [admin guide](./pipelinq-admin.md).
It walks the structure of the customer-bridge integration so a contributor can
extend the supported event types, debug an outage, or hook a new dashboard
into the observability surface.

The integration was shipped as the 11-slice
`bookings-pipelinq-customer-bridge` chain (ADR-032). Every component
documented here is owned by exactly one chain slice — the spec deltas
under `openspec/specs/bookings-pipelinq-integration/` are the
authoritative requirement source.

## 1. High-level view

```
┌──────────────────┐    ObjectCreatedEvent / ObjectTransitionedEvent
│  OpenRegister    │ ───────────────────────────────────────────────┐
│  (Appointment)   │                                                │
└──────────────────┘                                                ▼
                                                          ┌────────────────────┐
                                                          │ BookingCreated…    │
                                                          │ BookingTransitioned│
                                                          │ TimelinePublish    │
                                                          │ Listener (slice 7/8)│
                                                          └─────────┬──────────┘
                                                                    │ TimelineEventDto
                                                                    ▼
┌──────────────────┐   Contact + Klantbeeld   ┌──────────────────────────────────┐
│  Booking detail  │ ◄─────────────────────── │ PipelinqContactAdapter           │
│  controller (5)  │                          │  + RetryPolicy (slice 2)         │
└──────────────────┘                          │  + CircuitBreaker (slice 2)      │
        │ JSON                                │  + ICache (slice 3)              │
        ▼                                     │  + TimelineRetryQueue (slice 7)  │
┌──────────────────┐                          └──────────────────┬───────────────┘
│  Profile + KB    │                                             │
│  Vue card (6)    │                                             ▼ HTTPS
└──────────────────┘                                  ┌──────────────────┐
                                                     │ pipelinq backend │
                                                     └──────────────────┘
```

## 2. Component layout

All sources live in `lib/Service/Pipelinq/`, `lib/Listener/`,
`lib/Controller/`, and `lib/Settings/`.

| Component                              | Slice | Responsibility                                                                                       |
| -------------------------------------- | ----- | ---------------------------------------------------------------------------------------------------- |
| `PipelinqConfig`                       | 01    | Reads / writes the endpoint + token in `IAppConfig`. Health-check used by the admin "Test" button.   |
| `PipelinqSettingsController`           | 01    | Admin REST surface (`GET/POST /api/pipelinq/settings`, `POST .../test`).                             |
| `RetryPolicy`                          | 02    | 1s / 2s / 4s backoff; max 3 attempts; transient = 408 / 429 / 5xx.                                   |
| `CircuitBreaker`                       | 02    | Three-state breaker (closed / open / half-open). Opens after 5 consecutive failures, 5-minute cooldown. |
| `PipelinqContactAdapter`               | 02    | The transport. `IClientService`-backed; bearer token; retry loop; breaker; structured logging.       |
| `PipelinqTransportException`           | 02    | Safe-to-log transport-layer failure. Carries the HTTP status code.                                   |
| `PipelinqContact`                      | 03    | Contact DTO + fallback `notFound()` instance.                                                        |
| `getContact()` on the adapter           | 03    | Read-through cache (`ICache`, 5-minute TTL). Stale-cache fallback when pipelinq is unavailable.     |
| `KlantbeeldResult` / `KlantbeeldTransaction` | 04 | Pageable history envelope. Always-resolvable, never throws.                                          |
| `fetchKlantbeeld()` on the adapter      | 04    | `GET /contacts/{id}/klantbeeld?limit=&offset=`; clamps limit to 1..100; returns `unavailable()` on 5xx. |
| `BookingDetailController` injection     | 05    | Hydrates the booking detail with Contact + klantbeeld on render.                                    |
| Profile card Vue component             | 06    | UI rendering of the profile + history panel; degrades gracefully on `unavailable`.                  |
| `TimelineEventDto`                     | 07    | Immutable event payload (`type`, `externalId`, `timestamp`, `contactId`, `metadata`).               |
| `publishTimelineEvent()` on the adapter | 07    | `POST /timeline`; success → DEBUG; failure → false + WARNING + retry queue hand-off.                |
| `BookingCreatedTimelinePublishListener` | 07    | Listens to `ObjectCreatedEvent`; emits `booking.created`.                                           |
| `BookingTransitionedTimelinePublishListener` | 08 | Emits `booking.confirmed` / `.cancelled` / `.completed` from `ObjectTransitionedEvent`.            |
| `TimelineRetryQueue` (interface)       | 07    | Port for "publish failed; please try again later".                                                  |
| `LoggingTimelineRetryQueue`            | 07    | Default binding. Logs the deferral + updates the dead-letter-count gauge.                            |
| Persistent retry queue + background job | 09   | Replaces the logging binding with a durable queue + `BackgroundJob` worker. Drains the dead-letter. |
| Integration e2e tests                  | 10    | Playwright + Newman coverage of the round-trip — contact load, lifecycle publish, retry, recovery.  |
| `CustomerBridgeMetricsService`         | 11    | Counters + gauges in `ICache`. `snapshot()` + the AppHost `IMetricsProvider` (`metrics(): MetricSample[]`).                          |
| AppHost `GET /api/metrics`             | —     | Engine-owned Prometheus exposition (GenericMetricsController). Merges the provider series + implicit `shillinq_info`/`shillinq_up`.   |

## 3. Sequence — Contact read

```
User opens booking detail
        │
        ▼
BookingDetailController
        │  getContact(externalId)
        ▼
PipelinqContactAdapter::getContact
        │  cache.get(pipelinq:contact:{id})
        ├──── hit ──► DEBUG "cache hit"; metrics.contact.success(fromCache=true); return cached
        │  miss
        ▼
request("GET", /api/v1/contacts/{id})
        │  attempt=1..3 with breaker + retry
        ├──── 200 ──► DEBUG "request succeeded"; metrics.contact.success(false); cache.set(TTL=5m)
        ├──── 404 ──► DEBUG "contact not found"; metrics.contact.fallback("not_found"); cache fallback DTO
        ├──── 401 ──► ERROR "auth rejected"; metrics.permanent.failure("auth"); throw
        ├──── 5xx exhausted ──► ERROR "retry budget exhausted"; metrics.permanent.failure("dead_letter"); breaker.recordFailure
        └──── transport / breaker open ──► WARNING; try stale cache; metrics.contact.cache.stale OR re-throw
```

## 4. Sequence — Timeline publish (booking.created)

```
OpenRegister persists Appointment
        │
        ▼  ObjectCreatedEvent
BookingCreatedTimelinePublishListener
        │  pipelinqContactId present?
        │   └─ no ──► return (slice 6 already labels "not linked")
        │  yes
        ▼
PipelinqContactAdapter::publishTimelineEvent
        │  request("POST", /api/v1/timeline)
        ├──── 2xx ──► INFO "publish succeeded"; metrics.timeline.publish.success; return true
        └──── failure ──► WARNING "publish failed"; return false
                                    │
                                    ▼
                          retryQueue.enqueue(event)
                                    │
                          metrics.timeline.publish.deferred
                          metrics.dead_letter.count += 1
```

The listener's `handle()` is wrapped in a `try { … } catch (Throwable)`
so the booking commit can NEVER fail because of a downstream publish
issue. That is decision **D3** of the giant.

## 5. HTTP transport (slice 2 detail)

The adapter is the single place that:

* reads the endpoint + token from `IAppConfig`,
* attaches `Authorization: Bearer <token>`,
* sets `timeout=3s` and `connect_timeout=3s` (decision D5),
* runs the retry loop using `RetryPolicy`,
* consults `CircuitBreaker::allowRequest()` before every attempt,
* records `recordSuccess()` / `recordFailure()` after each outcome,
* logs every success at DEBUG and every failure at WARNING,
* emits ERROR-level log lines on the two "needs human attention" cases:
  `HTTP 401` (rotate the token) and `retry budget exhausted` (the
  request has been abandoned).

### Error handling

`PipelinqTransportException` carries the status code. Convenience:

* `isCircuitOpen()` — TRUE when the breaker short-circuited the call.
* `statusCode()` — the HTTP status that ended the call, or `0` for
  transport-layer failures (DNS, connect, timeout).

Callers classify on `statusCode()` rather than parsing message strings.

### Bearer-token hygiene (ADR-005)

The token is read from `IAppConfig`, attached only as a request header,
and NEVER reaches a log line, an exception message, or a response body.
Every log call carries only the safe fields (method, endpoint host +
path, status, retry attempt, externalId/contactId).

## 6. Cache (slice 3 detail)

Keys: `pipelinq:contact:{externalId}`. TTL: 300 seconds. Backend: the
ICache instance the DI container provides (APCu / Redis / Memcached in
production; in-memory in tests). Values are JSON-encoded
`PipelinqContact::toArray()` because the cache backends only round-trip
scalars reliably.

`clearCache()` wipes the whole prefix. It is wired to the admin
"Clear pipelinq cache" action and exposed for tests so we never have to
sleep for 5 minutes.

The cache layer is intentionally **read-through** rather than
write-through: it stores `PipelinqContact::notFound(...)` instances for
404 responses too, so a missing customer does not cause a re-request on
every page render.

## 7. Circuit breaker (slice 2 detail)

* Threshold: 5 consecutive failures.
* Cooldown: 300 seconds (5 minutes).
* States: `closed` (normal traffic) → `open` (fail fast) → `half_open`
  (one probe call allowed after the cooldown). The probe must call
  `recordSuccess()` or `recordFailure()` to either close or re-open the
  breaker.
* The state machine is pure (no logging) and unit-testable with a fake
  clock. State changes are surfaced via an `onTransition` callback the
  adapter wires to a WARNING log line AND
  `CustomerBridgeMetricsService::recordCircuitState()`.

## 8. Async retry queue (slice 7 + 9)

Slice 7 ships the `TimelineRetryQueue` interface and a default
`LoggingTimelineRetryQueue` binding that simply logs the deferral and
updates the dead-letter gauge. Slice 9 replaces the binding with a
persistent queue + a `BackgroundJob` worker that drains the queue,
re-issuing each event through `publishTimelineEvent()` with the same
retry policy and circuit breaker. The queue is intentionally a port so
slice 9 can land WITHOUT touching the listener.

The `enqueue()` method MUST be cheap and non-throwing — the caller is
already past the "booking commit succeeded" point, so raising here would
mask the original publish failure with a queueing failure.

## 9. Structured logging contract (ADR-006)

Required log levels and the trigger that emits them:

| Level   | Trigger                                                 | Fields                                                                    |
| ------- | ------------------------------------------------------- | ------------------------------------------------------------------------- |
| DEBUG   | Cache hit / successful Contact fetch / successful publish | `app`, `externalId`, `method`, `path`, `attempt`, `status`               |
| INFO    | Successful timeline publish                              | `app`, `type`, `externalId`, `contactId`                                  |
| WARNING | Transient HTTP failure (per attempt) / malformed JSON   | `app`, `method`, `path`, `attempt`, `status`, `transient`                |
| WARNING | Circuit-breaker state transition                         | `app`, `from`, `to`, `reason`                                             |
| WARNING | Stale-cache served because pipelinq is unavailable      | `app`, `externalId`, `status`                                             |
| WARNING | Timeline publish deferred to retry queue                 | `app`, `type`, `externalId`, `contactId`                                  |
| ERROR   | HTTP 401 — token rejected, needs admin rotation          | `app`, `method`, `path`, `status` (=401)                                  |
| ERROR   | Retry budget exhausted (request abandoned)               | `app`, `method`, `path`, `status` (last seen)                             |

All entries appear in `nextcloud.log`. No log entry EVER carries the
bearer token, the response body beyond the first 500 chars, or any other
unbounded upstream string.

## 10. Observability (slice 11)

The `CustomerBridgeMetricsService` aggregates counters / gauges in
`ICache` so the values survive request boundaries. It is wired
NULL-safely into the adapter and the listener.

Since the OpenRegister AppHost adoption it is also the app's
`IMetricsProvider` (registered under the alias
`OCA\OpenRegister\AppHost\IMetricsProvider::shillinq`): its `metrics()`
method emits the series below as `MetricSample` objects, and the manifest
`observability.metrics` `{"kind":"provider"}` descriptor merges them into
the engine-owned Prometheus exposition at `GET /api/metrics` (the AppHost
PrometheusRenderer adds the `shillinq_` prefix). The former bespoke JSON
`GET /api/metrics` (an ADR-006 violation) and the redundant
`GET /api/metrics/pipelinq` Prometheus endpoint have both been removed.

Available series (see the [admin guide](./pipelinq-admin.md#5-observability)
for the full list):

* `shillinq_pipelinq_contact_success_total`
* `shillinq_pipelinq_contact_fallback_total`
* `shillinq_pipelinq_contact_cache_hit_total`
* `shillinq_pipelinq_contact_cache_stale_total`
* `shillinq_pipelinq_timeline_publish_success_total`
* `shillinq_pipelinq_timeline_publish_deferred_total`
* `shillinq_pipelinq_permanent_failure_total`
* `shillinq_pipelinq_retry_attempts_total`
* `shillinq_pipelinq_retry_depth_max`
* `shillinq_pipelinq_dead_letter_count`
* `shillinq_pipelinq_circuit_state{state="…"}`

Counters NEVER reset between requests — only the admin "Reset metrics"
action (or `CustomerBridgeMetricsService::reset()`) clears them.
Response-time and error-rate percentiles are NOT recorded in the service
itself — those are calculated by the upstream Prometheus / Grafana
pipeline from the success/fallback/retry counters. The recommended
PromQL is documented in the [admin alerts table](./pipelinq-admin.md#6-recommended-alerts).

## 11. Extending the integration

### Adding a new event type

1. Add the type constant to `TimelineEventDto::TYPE_*`.
2. Add a listener under `lib/Listener/` (one per source event).
3. Register the listener in `Application::register()` next to slices 7/8.
4. Wire the metrics tap (`recordTimelinePublishSuccess` /
   `recordTimelinePublishDeferred`) the same way the existing listeners do.
5. Add an `openspec` change that documents the new requirement under
   `bookings-pipelinq-integration` and add a `@spec` tag to the listener.

### Adding a new read endpoint

1. Add a `protected` method on the adapter that calls `$this->request(…)`.
2. Add a DTO under `lib/Service/Pipelinq/` for the response shape.
3. Add a unit test that exercises the success, the 404 fallback, and the
   open-breaker / transport-failure cases — the existing
   `PipelinqContactReadTest` is the template.
4. Update the metrics service with the new counters and document them in
   both the admin guide and this file.

### Replacing the cache backend

`ICache` is injected, so swapping APCu for Redis is a `Server.php`
binding change with no adapter modification. The cache key prefix
(`pipelinq:contact:`) is exposed as a constant; downstream tooling that
inspects the cache should use that constant, not a literal.

## 12. Tests

Unit tests live under `tests/Unit/Service/Pipelinq/`:

* `CircuitBreakerTest` — state machine + clock injection.
* `RetryPolicy` is exercised via the adapter tests.
* `PipelinqContactAdapterTest` — base request loop + retry + breaker.
* `PipelinqContactReadTest` — read-through cache + fallback paths.
* `PipelinqKlantbeeldTest` — pageable history + 5xx fallback.
* `PipelinqTimelinePublishTest` — success + failure publish paths.
* `TimelineEventDtoTest`, `KlantbeeldResultTest`, `PipelinqConfigTest`.

Integration e2e coverage is owned by slice 10 — see the openspec change
`bookings-pipelinq-customer-bridge-10-integration-e2e-tests`.

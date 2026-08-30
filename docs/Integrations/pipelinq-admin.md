---
sidebar_position: 2
title: Pipelinq — Configuring the Customer Bridge
description: Admin guide for the pipelinq customer-profile integration — endpoint, token, testing, troubleshooting.
---

# Configuring the pipelinq Integration

The pipelinq customer-bridge enriches a booking with the linked customer's
profile and klantbeeld-360 history, and publishes booking lifecycle events
(`booking.created`, `booking.confirmed`, `booking.cancelled`,
`booking.completed`) back to pipelinq's timeline. Both directions run over
a single bearer-token-authenticated HTTP integration. This guide covers
the admin-facing setup and triage flow.

The architecture, retry policy, circuit breaker, dead-letter behaviour and
metrics contract are documented separately in
[Pipelinq Integration Architecture](./pipelinq-architecture.md).

## 1. Pre-requisites

* A pipelinq deployment reachable from the Nextcloud host.
* A pipelinq API token with at least these scopes:
  * `contacts:read` — required for the booking-detail profile + klantbeeld panel.
  * `timeline:write` — required for the booking-lifecycle publish.
* Network policy allowing the Nextcloud host to reach the pipelinq
  endpoint on HTTPS port 443 (or whichever port the endpoint specifies).

## 2. Finding the endpoint and token

In pipelinq:

1. Sign in as an admin.
2. Open **Settings → API & Tokens**.
3. Copy the **API base URL** — this is the value Shillinq stores as
   `pipelinq_endpoint` (for example `https://pipelinq.example.com`).
4. Create a token with the `contacts:read` and `timeline:write` scopes.
   Copy the token value once — pipelinq does not show it again.

Treat the token like a password. It is stored in Nextcloud's `IAppConfig`
and never logged.

## 3. Entering the credentials in Shillinq

In Nextcloud:

1. Sign in as an admin.
2. Open **Administration settings → Shillinq → Pipelinq integration**.
3. Paste the **Endpoint** value from step 2.3.
4. Paste the **Token** value from step 2.4.
5. Click **Save**.

The form posts to `POST /apps/shillinq/api/pipelinq/settings`. Saving an
empty token field PRESERVES the previously stored token; saving a single
space CLEARS it (useful when rotating).

## 4. Testing the connection

Use the **Test Connection** button in the same settings panel. The button
issues `POST /apps/shillinq/api/pipelinq/settings/test`, which performs
the same authenticated handshake the live integration uses, against the
stored endpoint + token. Three outcomes:

| Result          | What it means                                    |
| --------------- | ------------------------------------------------ |
| Green / 200     | Endpoint reachable, token accepted.              |
| Yellow / 401    | Endpoint reachable, token rejected.              |
| Red / connect   | Endpoint unreachable (DNS, firewall, TLS).       |

Each failure is logged at WARNING in `nextcloud.log` with the safe-to-log
fields (`method`, `endpoint host + path`, `status`, retry attempt). The
bearer token is never logged.

## 5. Observability

When the integration is wired the following series are populated by the
`CustomerBridgeMetricsService` and exposed at:

* `GET /apps/shillinq/api/metrics` — **Prometheus text exposition**
  (`text/plain; version=0.0.4`), admin-gated. Since the OpenRegister
  AppHost adoption this is the single canonical metrics endpoint: the
  customer-bridge series below are merged into the engine-owned exposition
  via the `CustomerBridgeMetricsService` `IMetricsProvider`, alongside the
  implicit `shillinq_info` and `shillinq_up` gauges.

> **Breaking change (AppHost adoption).** `GET /api/metrics` previously
> returned a JSON snapshot (`{app, metrics, pipelinq}`) — an ADR-006
> contract violation. It now returns Prometheus 0.0.4 text. Any external
> dashboard that polled the JSON shape must switch to scraping the
> Prometheus output. The former separate `GET /api/metrics/pipelinq`
> Prometheus endpoint has been removed: its series now live in the main
> `/api/metrics` exposition.

The Prometheus series are (names unchanged from the previous
`/api/metrics/pipelinq` exposition):

* `shillinq_pipelinq_contact_success_total` — Contact reads that
  succeeded (cache hit or live fetch).
* `shillinq_pipelinq_contact_fallback_total` — fallback DTO served (404
  or malformed JSON).
* `shillinq_pipelinq_contact_cache_hit_total` — served from the local
  5-minute TTL cache.
* `shillinq_pipelinq_contact_cache_stale_total` — stale cache served
  because pipelinq was unavailable.
* `shillinq_pipelinq_timeline_publish_success_total` — synchronous
  publishes that pipelinq acknowledged.
* `shillinq_pipelinq_timeline_publish_deferred_total` — publishes handed
  to the retry queue.
* `shillinq_pipelinq_permanent_failure_total` — 401 auth rejections +
  retry-budget exhaustion.
* `shillinq_pipelinq_retry_attempts_total` — sum of every retry attempt
  the adapter issued.
* `shillinq_pipelinq_retry_depth_max` — deepest retry depth observed
  since the last reset.
* `shillinq_pipelinq_dead_letter_count` — current dead-letter queue size
  (populated by slice 09's persistent queue; until that lands the
  in-process logging queue updates it best-effort).
* `shillinq_pipelinq_circuit_state{state="closed|open|half_open"} 1` —
  current circuit-breaker state.

## 6. Recommended alerts

Where an ops dashboard exists, the following alerts are recommended.

| Alert                       | Condition                                                              | Severity | Why                                                              |
| --------------------------- | ---------------------------------------------------------------------- | -------- | ---------------------------------------------------------------- |
| Circuit breaker open        | `shillinq_pipelinq_circuit_state{state="open"} == 1` for > 1 minute    | High     | Five consecutive failures — pipelinq is unreachable.             |
| Dead-letter growth          | `increase(shillinq_pipelinq_dead_letter_count[15m]) > 10`              | Medium   | Events are piling up faster than the retry worker can drain.     |
| Auth rejected               | `increase(shillinq_pipelinq_permanent_failure_total[5m]) > 0` AND tag=`auth` | High | Token is invalid — admin must rotate.                            |
| Contact error rate          | `rate(shillinq_pipelinq_contact_fallback_total[5m]) > 0.1`             | Low      | More than 10% of profile loads are falling back.                 |

## 7. Troubleshooting

### "Connection failed" / 5xx / timeout on **Test Connection**

* Re-check the endpoint URL has no typo and uses the right scheme
  (`https://`).
* Verify network reachability from the Nextcloud host:
  `curl -I https://pipelinq.example.com/api/v1/health`.
* If reachable from a shell but not the test action, check the Nextcloud
  outbound HTTP proxy settings (`overwriteprotocol`, `proxy`,
  `proxyuserpwd` in `config.php`).
* `nextcloud.log` will carry a WARNING line per attempt with the
  endpoint host + path + status code.

### "Customer data unavailable" on a booking detail

* The booking detail still renders — only the pipelinq profile + history
  panel is hidden. Confirm the booking has a non-empty
  `pipelinqContactId` field; without it there is nothing to fetch and
  this is expected.
* When `pipelinqContactId` IS set, check `nextcloud.log` for one of:
  * `pipelinq contact not found` (DEBUG) — the id does not exist in
    pipelinq. Fix the booking row.
  * `pipelinq unavailable; serving cached contact` (WARNING) — pipelinq
    is reachable but slow / 5xx; the cached profile is shown.
  * `pipelinq request short-circuited (breaker open)` (WARNING) — the
    breaker tripped. Wait 5 minutes for the cooldown or restart the PHP
    workers to reset state, then re-check pipelinq health.
* If the breaker is repeatedly opening, the upstream is the root cause —
  triage pipelinq, not Shillinq.

### "Token rejected" / `pipelinq authentication rejected` (ERROR)

* A pipelinq token has been rotated or revoked. Issue a new token in
  pipelinq, paste it into the Shillinq admin form, and save.
* Once a new token is saved the breaker auto-closes on the next
  successful call; no manual reset is needed.

### Stale profile data

* Profile reads use a 5-minute TTL cache. Use the **Clear pipelinq
  cache** admin action (or `occ shillinq:pipelinq:cache:clear`) to force
  a refresh before the TTL elapses.

### Events not appearing in pipelinq

* Check `nextcloud.log` for `pipelinq timeline publish deferred (no
  persistent queue yet)` — this means the synchronous publish failed and
  the event waits for slice 09's persistent retry queue. Until that
  ships, the deferral is best-effort; investigate the upstream failure
  signalled by the immediately preceding `pipelinq timeline publish
  failed` WARNING line.

## 8. Disabling the integration

To stop publishing events without uninstalling Shillinq:

1. Clear the endpoint OR the token in the admin form (a single space
   value clears the field).
2. The adapter detects the missing endpoint/token and immediately raises
   a transport exception. The booking-created listener swallows that,
   logs a WARNING, and the booking commit is unaffected.

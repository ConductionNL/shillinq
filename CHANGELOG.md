# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Waterschappen BBV variant capstone (chain
  `bookkeeping-waterschappen-bbv-variant` member 12 of 12 — docs +
  quality):
  - **Developer + admin guide** `docs/Technical/waterschappen-bbv-variant.md`
    — capability scope, component table per chain slice, data-flow
    diagram, configuring programmes + mappings, dashboard widget
    catalogue, audit-export usage, extension recipes.
  - **README snippet** describing the BBV variant scope and pointing at
    the technical guide.
  - **Deduplication check (ADR-012)** — verified no second
    GL-account-linkage, compliance dashboard, budget-mapping UI, or
    aggregation implementation exists in Shillinq; `BBVProgramme` and
    `BudgetBBVMapping` are the sole register-d schemas defining the
    surface.
  - **Quality + Hydra gates** — `composer check:strict`,
    `npm run lint`, SPDX header on every new file's main docblock,
    translation-key consistency, and the full Hydra mechanical gate
    suite (route-auth, semantic-auth, nc-input-labels,
    modal-isolation, and the rest) at zero findings.
- Pipelinq customer-profile integration capstone (chain
  `bookings-pipelinq-customer-bridge` member 11 of 11):
  - **Admin guide** `docs/Integrations/pipelinq-admin.md` — endpoint +
    token setup, "Test Connection" outcomes, observability series,
    recommended alerts (circuit-breaker open, dead-letter growth, auth
    rejected, contact error rate), troubleshooting walk-through, safe
    disable.
  - **Developer guide** `docs/Integrations/pipelinq-architecture.md` —
    component table per chain slice, contact-read + timeline-publish
    sequence diagrams, transport / cache / circuit-breaker / async-retry
    architecture, ADR-006 structured-logging contract (DEBUG / INFO /
    WARNING / ERROR triggers), Prometheus series catalogue, extension
    recipes.
  - **Observability surface** — `CustomerBridgeMetricsService`
    aggregates counters (contact success / fallback / cache hit / stale
    served, timeline publish success / deferred, retry attempts,
    permanent failures tagged `auth` / `dead_letter`) and gauges (retry
    depth max, dead-letter count, circuit-breaker state) in `ICache`.
    Exposed via the existing admin-gated `GET /api/metrics` JSON
    endpoint (new `pipelinq:` block) and a new
    `GET /api/metrics/pipelinq` Prometheus exposition endpoint.
  - **ERROR-level logging** for the two ADR-006 "needs human attention"
    cases on the adapter: HTTP 401 (token rejected — admin must rotate)
    and retry-budget exhaustion (request abandoned). Both increment the
    permanent-failure counter so a dashboard can alert on either signal.
  - Metrics taps in `PipelinqContactAdapter`,
    `BookingCreatedTimelinePublishListener`, and
    `LoggingTimelineRetryQueue` — every dependency is nullable so the
    upgrade is a no-op for any caller that has not yet wired the
    metrics service.

## [0.1.4] - 2026-05-31

### Added
- Reverse-spec `app-administration`: captured and annotated the observed
  application-administration surface (settings read/write, forced register
  re-import, public health endpoint, admin-only metrics endpoint, generic
  OpenRegister object store) against REQ-Admin-001 through REQ-Admin-005
  (ADR-003 retrofit; no runtime code modified).

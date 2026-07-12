# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Time & expense invoice intake (`time-expense-invoice-intake`) — new
  authenticated, idempotent `POST /apps/shillinq/api/billing/time-intake`
  ingress endpoint that accepts a batch of externally-approved time entries
  from another Conduction app (pipelinq's `time-billing-handoff-emit`
  change) and materialises them into one draft `BillableInvoice` (T&M),
  unblocking pipelinq's invoicing capability from `blocked-on-prereq`:
  - New `BillingIntakeController::timeIntake()` — server-resolved
    administration + personId (ADR-005), never a client-supplied
    `administrationId`.
  - New `TimeIntakeService::ingest()` — validates the batch, materialises
    `UrenRegistratie` rows stamped with `externalId`/`sourceApp`/
    `sourceBatchId`, and delegates invoice construction to the existing,
    unmodified `InvoiceGenerationService::draftInvoice()`.
  - Idempotency: replaying the same `batchId` returns `duplicated: true`;
    a `batchId` reused with a different payload returns `409`; a
    cross-batch duplicate `externalId` returns `422`.
  - New `TimeIntakeBatch` schema + `externalId`/`sourceApp`/`sourceBatchId`
    provenance fields on `UrenRegistratie`, shipped as a `register.d`
    fragment (`lib/Settings/register.d/time-expense-invoice-intake.json`)
    per ADR-037 — the canonical register is untouched.
  - PHPUnit coverage: `tests/Unit/Service/TimeIntakeServiceTest.php`,
    `tests/Unit/Controller/BillingIntakeControllerTest.php`.
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

### Fixed
- Register-config log noise on every OpenRegister config import
  (`fix-log-noise-schemas`):
  - All six `x-openregister-widgets` annotations used custom widget types
    (`stats-block`, `progress-with-state`, `utilisation-chart`,
    `state-with-actions`, `line-with-threshold`) rejected by OpenRegister's
    `WidgetAnnotationValidator`, and none carried the required `dataSource`
    object. Mapped to supported types (`stats`, `chart`, `tile`) and added
    `dataSource` objects with the client-side `statistics` mode
    (`nearing-retention`, `korStatus`, `utilisatieWidget`,
    `urencriteriumTracker`, `depositStatus`, `schatkistPosition`).
  - `StandardsPolicy` fragment
    (`register.d/add-shillinq-accounting-standards-policy.json`) was missing
    the mandatory `slug`, so the fragment was skipped on every import.
  - Removed the retired `KostenDrager` tombstone from
    `register.d/add-shillinq-audit-trail.json` — it merged into the config as
    a slug-less schema fragment and errored on every import (the schema was
    folded into `AnalyticalDimension(dimensionType=cost-object)` per
    REQ-ADIM-201).
  - `ThreeWayMatch.divergenceDetails.items` `expected`/`actual` properties had
    no `type` (rejected by OpenRegister's `PropertyValidatorHandler`); now
    `string`.
  - Dropped the invalid `"format": "float"` from three `number` properties in
    `shillinq_register.json` (`BankStatement.amount`,
    `MatchingRule.confidenceScore`, `ReconciliationMatch.confidence`).
  - Declared the missing `RJ270Stage` schema as a new ADR-037 fragment
    (`register.d/add-shillinq-rj270-stages.json`) —
    `SettingsService::seedRj270Stages()` had been seeding into a schema that
    was never declared, so every `InitializeSettings` run logged
    "Shillinq: RJ-270 stages seeding failed".

## [0.1.4] - 2026-05-31

### Added
- Reverse-spec `app-administration`: captured and annotated the observed
  application-administration surface (settings read/write, forced register
  re-import, public health endpoint, admin-only metrics endpoint, generic
  OpenRegister object store) against REQ-Admin-001 through REQ-Admin-005
  (ADR-003 retrofit; no runtime code modified).

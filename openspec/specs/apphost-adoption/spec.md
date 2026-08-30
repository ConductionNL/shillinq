---
status: done
---

# apphost-adoption Specification

## Purpose
Serves shillinq's health and Prometheus metrics endpoints through the OpenRegister AppHost engine, so operators get real database and OpenRegister availability checks and admin-only Prometheus exposition instead of hardcoded placeholders. Exposes the imperative pipelinq customer-bridge counters and circuit-breaker state via a registered metrics provider, and lets the AppHost generic classes serve the dashboard, admin settings, and canonical routes while preserving shillinq's bespoke settings, preferences, seeding, and deep-link plumbing.
## Requirements
### Requirement: Health Endpoint With Real Checks

Shillinq SHALL serve `GET /apps/shillinq/api/health` through the AppHost engine with a `database` check (critical) and an `orAvailable` check (severity `degraded`), replacing the hardcoded `{"status":"ok"}` literal. The endpoint SHALL remain publicly accessible (ADR-006) and follow the `adr006` status-code policy.

#### Scenario: Healthy instance reports real check results

- **GIVEN** a healthy instance with OpenRegister enabled
- **WHEN** `GET /apps/shillinq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `checks.database = "ok"`, and `checks.openregister = "ok"` in the standard AppHost shape
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: OpenRegister unavailable degrades instead of lying

- **GIVEN** OpenRegister is disabled or unresolvable
- **WHEN** `GET /apps/shillinq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "degraded"` and `checks.openregister` reporting the failure (generic message, no internals leaked)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Database failure is critical

- **GIVEN** the database check fails
- **WHEN** `GET /apps/shillinq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status = "error"` per the `adr006` status-code policy
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Prometheus Metrics Exposition

Shillinq SHALL serve `GET /apps/shillinq/api/metrics` through the AppHost engine in Prometheus text exposition format 0.0.4 (`Content-Type: text/plain; version=0.0.4`), admin-only, replacing the JSON snapshot response. The legacy JSON shape (`{app, metrics, pipelinq}`) SHALL NOT be served from this URL after adoption.

#### Scenario: Metrics are Prometheus text with implicit series

- **GIVEN** an instance with shillinq enabled
- **WHEN** `GET /apps/shillinq/api/metrics` is called by an admin
- **THEN** the response MUST be `text/plain; version=0.0.4` containing `shillinq_info{...}` and `shillinq_up` series, and MUST NOT be a JSON document
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Metrics remain admin-only

- **GIVEN** an authenticated non-admin user
- **WHEN** `GET /apps/shillinq/api/metrics` is called
- **THEN** the request MUST be rejected (the engine-owned admin posture cannot be drifted by the app)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Customer-Bridge Metrics Via Provider Escape Hatch

The pipelinq customer-bridge counters and circuit-breaker/in-flight state (genuinely imperative — not expressible as a declarative descriptor) SHALL be exposed through a `{"name":"customer_bridge","source":{"kind":"provider"}}` manifest descriptor, with `CustomerBridgeMetricsService` registered as shillinq's `IMetricsProvider` under the service alias `OCA\OpenRegister\AppHost\IMetricsProvider::shillinq`.

#### Scenario: Provider series merge into the generic metrics response

- **GIVEN** customer-bridge activity has incremented publish/retry/dead-letter counters and the circuit breaker holds a known state
- **WHEN** `GET /apps/shillinq/api/metrics` is called by an admin
- **THEN** the Prometheus output MUST contain every customer-bridge series the retired `/api/metrics/pipelinq` exposition emitted (publish success/deferred, retries, dead-letter depth, circuit-breaker state), sample-for-sample, merged alongside the implicit `shillinq_info`/`shillinq_up` series
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Unbound provider degrades gracefully

- **GIVEN** the customer-bridge integration is wired off and no provider resolves for the alias
- **WHEN** `GET /apps/shillinq/api/metrics` is called by an admin
- **THEN** the response MUST still be valid Prometheus exposition containing the implicit series, without a 500
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Mechanical Boilerplate Served By AppHost Generics

The dashboard page + SPA catch-all (`dashboard#page` / `dashboard#catchAll`), the admin-settings form, and the settings section SHALL be served by the AppHost generic classes (aliased in `Application::register()`; info.xml references the generic settings classes), with unchanged URLs, route names, and user-observable behaviour; the local `DashboardController`, `AdminSettings`, and `SettingsSection` copies SHALL be deleted. The canonical route set SHALL be emitted by `Routes::standard($extra)` with every shillinq-specific route passed as `$extra`. App-specific routes, listeners, adapter-port bindings, the notifier, and the seeding logic in `SettingsService` / `InitializeSettings` SHALL be preserved.

Bespoke plumbing that the current OR engine cannot reproduce SHALL be KEPT and resolve to shillinq's own classes via the canonical route names: `PreferencesController` (no `GenericPreferencesController` ships in OR `development`), `SettingsController` + `SettingsService` (bespoke `shillinq_register.json` + `register.d/*.json` fragment-merge config loading, which the generic `importFromApp` does not reproduce), `InitializeSettings` (13-phase domain seeding + ScheduledWorkflow registration), and `DeepLinkRegistrationListener` (dynamic register-slug resolution from app config). `Bootstrap::register()` SHALL NOT be used, because it would alias those onto the generics and change their behaviour.

#### Scenario: App UI is unaffected by the generic controllers

- **GIVEN** the AppHost generics serve the dashboard + admin-settings routes and shillinq's kept controllers serve settings + preferences
- **WHEN** a user opens the shillinq SPA (including a deep link through the catch-all) and an admin opens the shillinq admin settings
- **THEN** the app MUST render and behave exactly as before adoption — proven by the existing behavioural Playwright suite running green against the adopted build

#### Scenario: Settings API parity via the kept controller

- **GIVEN** the canonical `settings#index|create|load` routes resolve to shillinq's KEPT `SettingsController` + `SettingsService`
- **WHEN** `GET /api/settings` and `POST /api/settings/load` are called by an admin
- **THEN** the responses MUST match the pre-adoption contract exactly (the `register` / `rgs_template` / `administration_id` keys, the fragment-merge register import, and OR availability), with admin-only posture preserved
- @e2e exclude API-only endpoint — covered by the existing shillinq unit + behavioural suites


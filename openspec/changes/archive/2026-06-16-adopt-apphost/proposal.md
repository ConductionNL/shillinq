---
kind: code
---

# Proposal: Shillinq Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

Shillinq is the fleet's named ADR-006 **contract-violation story** in the 2026-06-12 observability inventory:

1. **Metrics violate ADR-006**: `MetricsController::index()` (`GET /api/metrics`) returns **JSON** (`{app, metrics: [], pipelinq: {...}}`) instead of Prometheus text exposition. A second, separate endpoint (`GET /api/metrics/pipelinq`) renders the same customer-bridge series in Prometheus 0.0.4 via `CustomerBridgeMetricsService::renderPrometheus()` — two endpoints, two formats, one of them non-compliant.
2. **Health checks nothing**: `HealthController::index()` returns a hardcoded `{"status":"ok","app":"shillinq"}` with **zero checks**. A dead database or a disabled OpenRegister — which shillinq hard-requires for every register — still reports "ok" to probes.
3. **Boilerplate drift**: shillinq carries its own drifted copies of the fleet skeleton — `DashboardController` (77 lines), `PreferencesController` (177), `SettingsController` (104), `AdminSettings` (plain `ISettings`, pre-#299 pattern), `SettingsSection`, `DeepLinkRegistrationListener`, the boilerplate core of `SettingsService` (`getSettings`/`updateSettings`/`getRegisterSlug`/`isOpenRegisterAvailable`/`loadConfiguration*` inside a 2,994-line file that is otherwise app-specific seeders), and the canonical route rows in a 440-line `routes.php`.

## Proposed Change

Adopt the OpenRegister AppHost (per `apphost-observability-engine` + `apphost-boilerplate-controllers`): declare observability in `src/manifest.json`, route the canonical endpoints to the AppHost generics, and delete the local boilerplate. Probe/scrape URLs do not change.

### Observability — the violations get fixed, and health gets real

```jsonc
"observability": {
  "health": {
    "checks": [
      { "id": "database", "type": "database" },
      { "id": "openregister", "type": "orAvailable", "severity": "degraded" }
    ]
  },
  "metrics": [
    { "name": "customer_bridge", "source": { "kind": "provider" } }
  ]
}
```

- **Health upgrade (call it out — this is a real improvement, not just parity)**: from a hardcoded literal with zero checks to an actual `SELECT 1` database check plus an OpenRegister-availability check (degraded, not critical, so a momentarily disabled OR reports `degraded` over HTTP 200 instead of flapping probes). ADR-006 status-code policy (`adr006`) applies: 503 on critical failure.
- **Metrics fix**: `GET /api/metrics` becomes Prometheus text 0.0.4 served by `GenericMetricsController` — exposition format is engine-owned, so shillinq can never drift back to JSON. The implicit `shillinq_info` / `shillinq_up` metrics appear for free (today: neither exists).
- **Provider escape hatch — the canonical imperative example**: the customer-bridge circuit-breaker / in-flight state is one of only 4 genuinely imperative metric sources in the whole fleet (named as such in the engine proposal). `CustomerBridgeMetricsService` becomes shillinq's `IMetricsProvider`, registered under the service alias `OCA\OpenRegister\AppHost\IMetricsProvider::shillinq` (ADR-035 discovery pattern); the `{"kind":"provider"}` descriptor merges its series into the generic Prometheus response. Its existing `renderPrometheus()` already emits the right exposition lines; `snapshot()` and the unit suite stay.
- **Breaking change — JSON shape retired**: the JSON snapshot contract (`{app, metrics, pipelinq}`) of `GET /api/metrics` is **replaced** by Prometheus exposition. A 2026-06-12 repo search found no in-app consumer (no `src/` fetch, no Vue widget, no Newman assertion on the JSON shape — only the controller unit tests and `docs/Integrations/pipelinq-admin.md`, which documents the JSON endpoint for ops dashboards). Task 0.2 re-verifies before deletion and the docs are rewritten. Any external dashboard polling the JSON shape must move to the Prometheus output.
- **The extra `/api/metrics/pipelinq` route**: with the provider merged into the main endpoint it is redundant. Tasks decide its fate (default: keep it for one release as an `$extra` route serving the same provider exposition, documented as deprecated, because `docs/Integrations/pipelinq-admin.md` advertises it as the canonical scrape URL and external scrape configs may reference it).

### Boilerplate adoption

`Application::register()` calls `AppHost\Bootstrap::register($context, 'shillinq')`; `appinfo/routes.php` becomes `return \OCA\OpenRegister\AppHost\Routes::standard($extra)` with shillinq's large app-specific route set (~200 routes: bookkeeping, bookings, inventory, DBA, WBSO, …) passed as `$extra`. All app-specific listeners, adapter-port bindings, and the notifier in `Application.php` stay exactly as they are.

### Deletions (enumerated)

| File | Fate |
|---|---|
| `lib/Controller/HealthController.php` | **Delete** — replaced by `GenericHealthController` alias |
| `lib/Controller/MetricsController.php` | **Delete** — replaced by `GenericMetricsController` alias + provider |
| `lib/Controller/DashboardController.php` | **Delete** — `GenericDashboardController` (page + catchAll) |
| `lib/Controller/PreferencesController.php` | **Delete** — `GenericPreferencesController` |
| `lib/Controller/SettingsController.php` | **Delete** — `GenericSettingsController` |
| `lib/Settings/AdminSettings.php` | **Delete** — `GenericAdminSettings` (also upgrades to the IDelegatedSettings #299 pattern) |
| `lib/Sections/SettingsSection.php` | **Delete** — `GenericSettingsSection` |
| `lib/Listener/DeepLinkRegistrationListener.php` | **Delete** — `GenericDeepLinkRegistrationListener` |
| `lib/Service/SettingsService.php` | **Shrink, not delete** — boilerplate core (settings get/update, register-slug resolution, OR availability, `loadConfiguration*`) moves to `AppHostSettingsService`; the ~30 app-specific `seed*` methods remain in a shillinq subclass/seeder per the AppHost extension-first design |
| `lib/Repair/InitializeSettings.php` | **Shrink, not delete** — base register-import behaviour from `GenericInitializeSettings`; shillinq's seed/workflow-registration methods remain (other Repair steps untouched) |
| `appinfo/routes.php` canonical rows | **Delete rows** — dashboard page/catchAll, settings ×3, preferences ×2, health, metrics come from `Routes::standard()` |
| `tests/Unit/Controller/{Health,Metrics}ControllerTest.php` | **Delete** — behaviour now covered by the OR AppHost Newman contract collection; `CustomerBridgeMetricsServiceTest` stays |

## Impact

- **Deleted**: ~640 lines of controller/section/listener boilerplate plus the boilerplate core of `SettingsService`/`InitializeSettings`; **modified**: `src/manifest.json`, `appinfo/routes.php`, `lib/AppInfo/Application.php`, `CustomerBridgeMetricsService` (implements `IMetricsProvider`), docs.
- **Verification**: shillinq's existing **107-test behavioural Playwright suite** plus its Newman collections (`tests/integration/`) must stay green; the OR AppHost Newman contract collection asserts the new endpoint contracts. Health/metrics output captured before/after (parity is intentionally *not* the bar for health and metrics format — those are upgrades; the bar is the documented new contract).
- **Risk**: the JSON-shape replacement breaks any undiscovered external consumer — mitigated by task 0.2's consumer search and the docs rewrite. Engine-level risk is owned by the OR Newman contract collection.
- **Delivery**: shillinq is on the racing-PR list — **Codeberg PR only, never direct push** to `development`.

## Dependencies

Chained: `apphost-observability-engine`, `apphost-boilerplate-controllers` (both in `openregister/openspec/changes/`).

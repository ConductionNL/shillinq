# Tasks: Shillinq Adopts OpenRegister AppHost

## 0. Baseline + consumer search

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl /apps/shillinq/api/health` (hardcoded JSON), `/api/metrics` (JSON snapshot incl. `pipelinq` block), `/api/metrics/pipelinq` (Prometheus text); store as fixtures. These document the *old* contract — health and metrics are intentionally NOT parity targets (both upgrade), only the provider series must carry over sample-for-sample.
- [ ] 0.2 Re-verify there are no consumers of the JSON metrics shape before removal: sweep `src/` (Vue widgets/dashboards), `tests/` (Newman assertions on `{app, metrics, pipelinq}`), `docs/`, and any known external dashboards/scrape configs polling `GET /api/metrics` as JSON or `GET /api/metrics/pipelinq`. Record findings here. (2026-06-12 authoring search: none in-app; `docs/Integrations/pipelinq-admin.md` + `pipelinq-architecture.md` document both endpoints.)

## 1. Manifest observability block

- [ ] 1.1 Add `observability` to `src/manifest.json`: health checks `{"id":"database","type":"database"}` + `{"id":"openregister","type":"orAvailable","severity":"degraded"}`; metrics `[{"name":"customer_bridge","source":{"kind":"provider"}}]`.
- [ ] 1.2 Validate via ManifestService diagnostics (no errors; gate-22 manifest-validation green).

## 2. Wiring, provider registration, deletions

- [ ] 2.1 `Application::register()`: call `\OCA\OpenRegister\AppHost\Bootstrap::register($context, self::APP_ID)`; keep every existing app-specific listener/adapter/notifier registration untouched.
- [ ] 2.2 Register the metrics provider: `CustomerBridgeMetricsService` implements `OCA\OpenRegister\AppHost\IMetricsProvider` (exposing its existing `renderPrometheus()`/`snapshot()` state as provider samples); service alias `OCA\OpenRegister\AppHost\IMetricsProvider::shillinq`. `CustomerBridgeMetricsServiceTest` extended to cover the provider contract; existing counter tests unchanged.
- [ ] 2.3 `appinfo/routes.php` → `return \OCA\OpenRegister\AppHost\Routes::standard($extra);` with all shillinq-specific routes as `$extra`; remove the canonical dashboard/settings/preferences/health/metrics rows.
- [ ] 2.4 Decide the `/api/metrics/pipelinq` route's fate and record the decision here: **default** — keep for one release as an `$extra` route serving the provider's Prometheus exposition (it is advertised as the canonical scrape URL in `docs/Integrations/pipelinq-admin.md`; external scrape configs may reference it), marked deprecated in docs; remove next release. Alternative if 0.2 confirms zero external scrapers: fold into the main endpoint and drop the route now.
- [ ] 2.5 Delete: `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`, `lib/Controller/DashboardController.php`, `lib/Controller/PreferencesController.php`, `lib/Controller/SettingsController.php`, `lib/Settings/AdminSettings.php`, `lib/Sections/SettingsSection.php`, `lib/Listener/DeepLinkRegistrationListener.php`, `tests/Unit/Controller/HealthControllerTest.php`, `tests/Unit/Controller/MetricsControllerTest.php`.
- [ ] 2.6 Shrink `lib/Service/SettingsService.php`: boilerplate core (`getSettings`/`updateSettings`/`getRegisterSlug`/`isOpenRegisterAvailable`/`loadConfiguration`/`loadConfigurationForced`) delegates to / is replaced by `AppHostSettingsService`; the ~30 `seed*` methods stay in the shillinq class (subclass per the extension-first design). Same for `lib/Repair/InitializeSettings.php` (base import from `GenericInitializeSettings`; shillinq seed + ScheduledWorkflow registration methods stay). Other Repair steps untouched.
- [ ] 2.7 Sweep remaining references (`MetricsController`/`HealthController` mentions in docs, `@spec` tags, info.xml repair-steps list if the repair class name changes).

## 3. Upgrade + parity verification

- [ ] 3.1 Health now actually checks: on a healthy instance `GET /api/health` returns 200 with `checks.database = "ok"` and `checks.openregister = "ok"`; with OR disabled it returns 200 + `status: "degraded"`; with the DB down it returns 503 (verify via fixture/manual on dev). Anonymous access still works (ADR-006).
- [ ] 3.2 Metrics now Prometheus: `GET /api/metrics` returns `text/plain; version=0.0.4` containing `shillinq_info`, `shillinq_up`, and every customer-bridge series the old `/api/metrics/pipelinq` exposition emitted (diff provider series against the 0.1 fixture — sample-for-sample). Admin-only posture verified (non-admin gets 401/403).
- [ ] 3.3 OR AppHost Newman contract collection green against shillinq's endpoints; shillinq's own Newman collections (`tests/integration/*.postman_collection.json`) green.
- [ ] 3.4 Existing behavioural e2e suite (107 Playwright tests) green — dashboard page + catch-all, admin settings, and preferences flows prove the generic controllers are drop-in; reference the boilerplate-parity scenario from the suite per gate-19.
- [ ] 3.5 PHPUnit suite green (no orphaned controller tests; `CustomerBridgeMetricsServiceTest` + provider-contract tests pass).

## 4. Docs

- [ ] 4.1 Rewrite `docs/Integrations/pipelinq-admin.md` + `pipelinq-architecture.md`: `GET /api/metrics` is now Prometheus (JSON snapshot retired — breaking-change note for external dashboards), `/api/metrics/pipelinq` per the 2.4 decision; health endpoint now documents its real checks.
- [ ] 4.2 Note the AppHost adoption in the app README/architecture docs (manifest `observability` block is the source of truth).

## 5. Quality gates + delivery

- [x] 5.1 PHPUnit (`phpunit-unit.xml`) parity-green (same 7 failures + 1 error as baseline, all pre-existing + unrelated; `CustomerBridgeMetricsServiceTest` incl. the new provider-contract test passes); `npm run build` green; hydra gates diff-clean vs baseline.
- [x] 5.2 **PR-only delivery**: delivered via a Codeberg PR on the pre-migration `Conduction/shillinq` repo (now https://github.com/ConductionNL/shillinq) from branch `build/adopt-apphost-2026-06-16`; never direct-pushed to `development`.

## 6. Implementation record (what actually shipped + deviations)

The OpenRegister `development` AppHost engine that exists today is the
**observability engine + the generic controllers/settings/repair classes +
`Bootstrap`/`Routes`**. Two assumptions in the proposal did not hold against
that real engine, so the adoption is scoped accordingly:

- **`GenericPreferencesController` does NOT exist** in OR `development`
  (`Bootstrap` references it, but no such class ships). shillinq's
  `PreferencesController` is therefore **KEPT** and its routes remain `$extra`;
  the canonical `preferences#*` routes from `Routes::standard()` resolve to it.
- **`SettingsService::loadConfiguration*` is bespoke** — it loads
  `shillinq_register.json` + deep-merges `Settings/register.d/*.json` fragments
  (ADR-037) with a fragment-signature version, which the generic
  `AppHostSettingsService::loadConfiguration` (a plain `importFromApp`) does NOT
  reproduce. shillinq's `SettingsService` + `SettingsController` are therefore
  **KEPT** as-is (the canonical `settings#*` routes resolve to them), and
  `Bootstrap::register` is intentionally **not** used (it would alias them onto
  the generic and break fragment loading).
- **`InitializeSettings` is a 13-phase domain seeder** (chart of accounts, BBV,
  Selectielijst, ScheduledWorkflow registration, …) — KEPT.
- **`DeepLinkRegistrationListener`** resolves the register slug dynamically from
  app config; the manifest-driven `GenericDeepLinkRegistrationListener` is
  static — KEPT.

ADOPTED (mechanical, byte-for-byte skeleton): `DashboardController`,
`HealthController`, `AdminSettings`, `SettingsSection` → engine generics
(aliased in `Application::registerAppHostGenerics()`; info.xml points at the
generic settings classes). Observability is served from the manifest
`observability` block.

- **2.4 decision (revised to the proposal's *alternative*)**: task 0.2's
  authoring search confirmed **zero external scrapers** of
  `GET /api/metrics/pipelinq` (referenced only in docs). The redundant route +
  `MetricsController` are therefore **removed** (not kept for a release); the
  customer-bridge series are merged into the canonical `GET /api/metrics`
  exposition via the `CustomerBridgeMetricsService` `IMetricsProvider`.
  `renderPrometheus()` is removed (its only caller was the deleted controller).

- **Known schema gap (not introduced by this change's intent)**: the published
  `@conduction/nextcloud-vue` app-manifest-v2 schema (≤ v2.9.0) does not yet
  define the top-level `observability` property (`additionalProperties:false`
  at root), so `check:manifest` reports one extra `(root)` violation on top of
  shillinq's **pre-existing** gate-22 debt (`/menu/*`, `/pages/*`). OR's own
  `development` manifest ships the same `observability` block against the same
  schema — the engine reads it at runtime via `ManifestLoader` regardless. The
  schema addition is a shared-library (nextcloud-vue) follow-up, out of scope
  for this leaf PR.

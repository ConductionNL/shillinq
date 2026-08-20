# Tasks: integration-config-to-openconnector

## 0. Pre-work — resolve the open questions before touching code
- [x] Resolve `design.md` §6.1 (the `bzk-sisa` vs `bzk-sisa-upload-2026` slug
      mismatch) against whatever is actually provisioned in a real
      openconnector instance before writing the 15-entry
      `openconnector-sources.json`. Do not guess.
      **Resolved (design.md §8.1):** queried the live dev instance
      (`localhost:8080`, OpenRegister generic object API,
      `register: openconnector, schema: source`) — 262 sources total,
      neither `bzk-sisa` nor `bzk-sisa-upload-2026` provisioned (0/262).
      Per the orchestrator's binding ruling, since neither slug exists
      live, the JSON's pre-existing `bzk-sisa-upload-2026` slug is kept
      unchanged; the controller's `bzk-sisa` is also left unchanged. The
      mismatch is documented, not silently fixed.
- [x] Confirm `design.md` §6.2 (the ADR-067 rule 2 declaration mechanism —
      `optionalIntegrations` + `CapabilityProviderInterface` vs a narrower
      app-local declaration) with whoever owns ADR-067/ADR-022 before
      implementing REQ-ICO-005.
      **Resolved (design.md §8.2)** by the orchestrator's binding ruling:
      the app-manifest v2 schema (`tests/schemas/app-manifest-v2.schema.json`)
      has `additionalProperties: false` at the root and no
      `optionalIntegrations`-style key, so the mechanism is the ADR-018
      features/roadmap declaration (`docs/features.json` +
      `openspec/features.overlay.json`).

## 1. `openconnector-sources.json` — declare all 15 slugs (REQ-ICO-001)
- [x] For each of the 15 families in `design.md` §1's table, add a stub entry
      to `lib/Settings/openconnector-sources.json` following the existing
      `bzk-sisa-upload-2026` entry's shape: `slug` (= the family's
      `sourceSlug`, resolved by task 0), `title`, `description` (stating the
      credentials-live-in-openconnector discipline), `version`, `featureFlag`
      (from the controller), `type`, `configuration` (documentation-only —
      `authMethod`, `endpointPlaceholder`, `protocol`, `contentType`; no real
      credential), `capabilities` (derived from the family's `description` in
      the controller). `auditEvent` is optional — only add it where a real
      audit-event name already exists elsewhere in the codebase for that
      family; do not invent one.
      All 15 entries added (14 new + the pre-existing `bzk-sisa-upload-2026`
      kept as-is per task 0). `auditEvent` only kept on the pre-existing
      `bzk-sisa-upload-2026` entry (`sisa.submitted`) — grepped the repo for
      any other family's real audit-event name and found none, so no new
      `auditEvent` was invented for the other 14.
- [x] Update the file's `_meta.imported` timestamp per the existing
      convention.
      Updated to `2026-08-20T00:00:00Z`.

## 2. Manifest collapse (REQ-ICO-002, REQ-ICO-004)
- [x] Rewrite `src/manifest.d/external-adapters-w8.json`: remove all 15
      `ExternalAdapterDetail` page entries and their corresponding
      `menu[].children[]` leaves in the same edit. Keep the
      `ExternalAdaptersStatus` page id and `/external-adapters` route
      (`design.md` §2/§3 churn-avoidance reasoning) — do not rename unless a
      strong reason emerges during implementation.
- [x] Convert the `ExternalConnections` menu group from a 16-child parent
      into a single leaf node carrying `route` directly (see `design.md` §3's
      drafted shape).
- [x] `wc -c` the resulting fragment and confirm it lands near the ~1,000
      byte estimate in `design.md` §7 (informational — not a hard gate).
      1,514 bytes (design.md §7 updated with the exact before/after).
- [x] Delete `src/views/external-adapters/ExternalAdapterDetail.vue` — no
      route references it after this change.
      Also removed its `src/registry.js` import/registration, its 14 ids
      from `src/menu-layout.json#settingsSection`, and regenerated the
      generated `src/manifest.d.shell.json` projection via
      `node scripts/generate-manifest-shell.js`.

## 3. Backend — provisioning resolution (REQ-ICO-003)
- [x] Add `resolveProvisioning(string $sourceSlug): array` to
      `ExternalAdaptersAdminController` (or a new injected service if the
      controller is getting too large), following the exact defensive shape
      of the existing `resolveDormancy()` (lines 534–549): try/catch every
      Throwable, log at `warning`, never let one family's lookup failure
      break the whole `#index` response.
- [x] Resolve the OpenRegister object-service via DI (same pattern as the
      existing `ContainerInterface $container` injection) and query
      `register: 'openconnector', schema: 'source', slug: $sourceSlug`.
      Read-only — never surface `configuration.headers` or any credential
      field from the resolved object.
      Uses `$container->get('OCA\OpenRegister\Service\ObjectService')` then
      `->setRegister('openconnector')->setSchema('source')->findAll(['filters'
      => ['slug' => $sourceSlug], 'limit' => 1])` — the `findAll()` +
      slug-filter shape used elsewhere in this repo (e.g.
      `lib/Service/SlotService.php`, `lib/Repair/LoadCbsSeedsStep.php`),
      which returns an empty array on a miss rather than throwing (unlike
      `find()`). Only the object id is read from a match.
- [x] Extend `#index`'s JSON response with each row's `provisioning: {
      status, openconnectorObjectId?, deepLink }` per `design.md` §3's
      shape. `deepLink` is `/apps/openconnector/sources` (no per-slug
      deep-link exists yet — `design.md` §5.3 cross-repo task).
- [x] Decide (per `design.md` §6.3) whether `#show` (lines 506–519) stays as
      a still-useful per-family JSON endpoint or is removed as dead code now
      that no page deep-links to it.
      **Removed** per the orchestrator's binding ruling (design.md §8.3) —
      the method and its `appinfo/routes.php` route entry are both deleted.

## 4. ADR-067 rule 2 declaration (REQ-ICO-005)
- [x] Implement whatever mechanism task 0's second pre-work item settled on,
      enumerating all 15 `sourceSlug` values as declared-unimplemented
      integrations.
      One consolidated `docs/features.json` (+ `openspec/features.overlay.json`)
      entry, slug `external-connections-integration-config`, carrying a new
      `integrations[]` array of all 15 `{ sourceSlug, title, status:
      "unimplemented" }` objects (design.md §8.2).

## 5. Frontend roster component (REQ-ICO-002)
- [x] Rewrite `src/views/external-adapters/ExternalAdaptersStatus.vue` (or
      its replacement) to render one row per family from the extended
      `#index` response: title, `sourceSlug`, dormant/live badge (existing),
      provisioning status badge + deep link (new).
      Also replaces the removed per-family route's activation-recipe
      content with an in-row expand/collapse disclosure (no info lost by
      dropping the route). `openDetail`/`routeIdForAdapter` (no longer
      meaningful — there is no per-family route to push to) were removed.
- [x] Ensure the empty/error states (OR unavailable → all rows `unknown`,
      per REQ-ICO-003's third scenario) render without a page-level crash.
      The controller's `resolveProvisioning()` never throws out of `#index`
      (fail-soft to `unknown` per row); the component renders every row
      exactly as it renders any other status. Covered by
      `tests/e2e/workflows/external-adapters-admin.spec.ts`'s third test via
      a mocked all-`unknown` response.

## 6. e2e (REQ-ICO-007)
- [x] Replace `tests/e2e/external-adapters.spec.ts`,
      `tests/e2e/visual/external-adapters.visual.spec.ts`, and
      `tests/e2e/workflows/external-adapters-admin.spec.ts` with coverage of
      the roster page. Reuse the existing dismiss-overlay / deep-link-vs-nav-
      fallback helpers already written in those files — they are still
      correct patterns, only the target surface changes.
- [x] Assert: 15 rows render; every row's deep-link `href` is well-formed;
      at least one row (the one whose `sourceSlug` is provisioned per task
      0/1) shows `provisioned` status; no shillinq-origin console error or
      5xx during the journey (existing pattern from the removed specs).
      Task 0 established 0/15 families are provisioned live on the dev box
      today, so the `provisioned`-state assertion is driven by a mocked
      `GET /api/admin/external-adapters` response
      (`tests/e2e/workflows/external-adapters-admin.spec.ts`) rather than a
      live fixture — see design.md §8.1's note on why.
- [x] Grep the final `tests/e2e/**` tree for any remaining
      `/external-adapters/<family-id>` path literal and remove it.
      Zero remaining (verified by grep, and enforced going forward by
      `external-adapters.spec.ts`'s own "no test file references a removed
      per-adapter route" test).
- [x] Tag new Playwright tests with `@e2e integration-config-to-
      openconnector::<scenario-slug>` matching `spec.md`'s scenario ids
      exactly (gate-19 / `hydra-gate-e2e-coverage`).
      All 7 non-excluded scenarios across REQ-ICO-002/003/007 tagged; slugs
      independently computed against gate-19's own `_slugify()` to confirm
      an exact match (see the apply report).
      Note: these specs were written but **not executed** per the
      implementation brief (no `npx playwright test` run) — syntax was
      checked with `tsc --noEmit`.

## 7. Validation
- [x] `openspec validate integration-config-to-openconnector --strict` —
      PASS.
- [ ] Run the roster page e2e locally against a real backend.
      **Not run** — explicitly out of scope for this apply pass (Playwright
      execution excluded per the implementation brief). Left for the
      verify/CI phase.
- [x] `grep -rn "config.adapterId" src/manifest.d/` — zero matches after
      task 2.
- [x] Confirm no `lib/Service/External/**` file, and no
      `depositWebhook`/`paymentRequestWebhook`/`payrollWebhook` controller
      method, appears in the implementation diff (REQ-ICO-006).

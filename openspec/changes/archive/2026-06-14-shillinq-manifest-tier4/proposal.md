# Shillinq — adopt the Tier-4 app-manifest shell

## Summary

Shillinq currently renders its UI from a hand-written `vue-router` config
(`src/router/index.js`), a hand-written navigation component
(`src/navigation/MainMenu.vue`), a bespoke `App.vue` (with its own
"OpenRegister is required" empty state) and a bespoke dashboard view
(`src/views/Dashboard.vue`). The `feature/adopt-app-manifest` branch
added `src/manifest.json` but kept all of that custom shell code (Tier-1
adoption — `useAppManifest` only). This change finishes the job: shillinq
adopts the **Tier-4** ADR-024 manifest shell (`CnAppRoot` from
`@conduction/nextcloud-vue`), so the manifest becomes the single source
of truth for pages, navigation and the dependency check, and shillinq's
custom frontend routing/shell code drops to (effectively) zero. Decidesk
and procest are the reference Tier-4 consumers.

## Motivation

ADR-024 makes `CnAppRoot` the default UI shell across the fleet — per-app
router boilerplate, sidebar wiring, dependency-check logic and page
dispatch should all come from the library, driven by `src/manifest.json`,
not be re-rolled per app. Shillinq is a near-greenfield scaffold, so this
is the cheapest moment to converge it: the only custom views today are a
placeholder dashboard and the standard admin-settings shim.

## Affected Projects

- [x] Project: shillinq — frontend bootstrap, App shell, manifest, deps
- [ ] No other projects affected (no backend or cross-app changes)

## Scope

### In Scope

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.35` (the release that
  ships `CnAppRoot` + the declarative `index`/`detail`/`dashboard`/
  `settings`/`logs` page types and the abstract sidebar).
- Rewrite `src/main.js` to the Tier-4 bootstrap pattern: build the
  `vue-router` config from `manifest.pages`, register library icons +
  translations, mount `App` with `manifest` / `customComponents` /
  `pageTypes` props (mirrors `procest/src/main.js`).
- Rewrite `src/App.vue` to mount `<CnAppRoot>` with the bundled manifest.
  Drop the bespoke "OpenRegister is required" UI — `CnAppRoot` does the
  dependency check from `manifest.dependencies`.
- Add `src/customComponents.js` exporting `{}` — shillinq has no
  custom-fallback pages; the file exists as the documented escape hatch.
- Keep `src/manifest.json` as the source of truth: `Dashboard`
  (`type: "dashboard"`) and `Settings` (`type: "settings"`) pages, plus
  the `menu[]` (Dashboard / Documentation / Settings) and
  `dependencies: ["openregister"]`.
- Delete the now-dead custom shell files: `src/router/index.js`,
  `src/navigation/MainMenu.vue`, `src/views/Dashboard.vue`.
- Keep the admin-settings entry (`src/settings.js` →
  `src/views/settings/AdminRoot.vue`) — it is rendered by Nextcloud's
  settings framework via `AdminSettings.php`, not by the in-app router,
  and is the canonical NC pattern (decidesk/procest keep theirs).
- Keep the Pinia stores (`src/store/**`) — the admin-settings store still
  backs `AdminRoot.vue`; `initializeStores()` stays as a safety net in
  `App.vue::created()` per the decidesk pattern.
- Add a `tests/validate-manifest.js`-driven check that every
  `manifest.pages[].id` is reachable as a route (the branch already added
  this test; keep/extend it).

### Out of Scope

- Building out shillinq's actual business-administration domain
  (bookkeeping, invoicing, procurement, contracts) — that lands as
  separate per-capability changes; this change only migrates the *shell*.
- Touching the PHP backend, registers or schemas.
- Reworking the admin-settings view's internal data-loading (the
  `document.getElementById('shillinq-settings').dataset.version` read in
  `AdminRoot.vue` is an existing pattern; switching it to `loadState` is
  tracked separately).

## Approach

Mirror `procest`/`decidesk`: `main.js` builds routes from the manifest
and mounts `App.vue`, which is a thin `<CnAppRoot>` wrapper. `CnAppRoot`
reads `manifest.dependencies` (dependency check / empty state),
`manifest.menu` (default `CnAppNav`) and dispatches each route to the
right `Cn{Dashboard,Settings,Index,Detail,Logs}Page` based on
`page.type`. See `design.md` for the file-by-file before/after.

## New Dependencies

None new — only a version bump of `@conduction/nextcloud-vue`
(`^1.0.0-beta.31` → `^1.0.0-beta.35`).

## Impact

- `src/main.js`, `src/App.vue` — rewritten.
- `src/customComponents.js` — new (empty registry).
- `src/router/index.js`, `src/navigation/MainMenu.vue`,
  `src/views/Dashboard.vue` — deleted.
- `package.json` / `package-lock.json` — `@conduction/nextcloud-vue` bump.
- Built bundle (`js/shillinq-main.js`) — regenerated.

## Cross-Project Dependencies

Depends on `@conduction/nextcloud-vue@1.0.0-beta.35` being published
(it is — procest/pipelinq/scholiq already consume it).

## Risks

### Risk 1: `CnAppRoot` props / slot contract drift

**Severity**: Low
**Mitigation**: Copy the exact prop names and bootstrap shape from
`procest/src/main.js` + `procest/src/App.vue` (same library version).
Browser-verify the app boots, the nav renders, the dashboard page renders
and the settings page renders before merge.

### Risk 2: `loadTranslations` 404 in the dev container breaks boot

**Severity**: Low
**Mitigation**: Use the fire-and-forget `tryLoadTranslations()` helper
from `procest/src/main.js` — boot never awaits translation loading.

## Rollback Strategy

Revert the commit. The `feature/adopt-app-manifest` (Tier-1) state and
the pre-manifest state are both reachable via git history; nothing in the
backend or data layer changes.

## Open Questions

None — the pattern is fully established by decidesk/procest.

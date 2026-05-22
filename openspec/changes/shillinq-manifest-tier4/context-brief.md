# Shillinq — adopt the Tier-4 app-manifest shell

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer / Over

**Rationale:** App-shell scaffolding, not user-facing flow.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

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



## Design

# Design — Shillinq Tier-4 manifest shell

## Reference implementations

`procest/src/main.js` + `procest/src/App.vue` + `procest/src/customComponents.js`
(same library version, `@conduction/nextcloud-vue@^1.0.0-beta.35`) and
`decidesk/src/App.vue` (the canonical Tier-4 consumer). Shillinq's
bootstrap is a strict subset of procest's — no `mapFormatters`, no legacy
`sidebarState` aliases.

## File-by-file before → after

### `package.json`

| Dep | Before | After |
|---|---|---|
| `@conduction/nextcloud-vue` | `^1.0.0-beta.31` | `^1.0.0-beta.35` |

(`package-lock.json` regenerated by `npm install`.)

### `src/main.js` — rewritten

The Tier-4 bootstrap (copied from `procest/src/main.js`, app id
`shillinq`, no `mapFormatters`):

1. SPDX header (`EUPL-1.2`, `Copyright (C) 2026 Conduction B.V.`).
2. `Vue.mixin({ methods: { t, n } })`, `Vue.use(PiniaVuePlugin)`,
   `Vue.use(VueRouter)`.
3. `registerIcons()`; `registerTranslations()` in a try/catch (non-fatal).
4. `tryLoadTranslations()` — fire-and-forget, boot never awaits it.
5. `RoutePageRenderer = { ...CnPageRenderer }`; `routesFromManifest()`
   maps each `manifest.pages[]` to `{ name: page.id, path: page.route,
   component: RoutePageRenderer, props: page.route.includes(':') }` plus a
   `{ path: '*', redirect: '/' }` catch-all.
6. `new VueRouter({ mode: 'history', base: generateUrl('/apps/shillinq'),
   routes })`.
7. `new Vue({ pinia, router, render: h => h(App, { props: { manifest,
   customComponents: { ...customComponents }, pageTypes:
   { ...defaultPageTypes } } }) }).$mount('#content')`.

Imports removed: `router from './router/index.js'`, `initializeStores`
(moves to App.vue's `created()` only — App.vue already had it). The
`useAppManifest` call added on `feature/adopt-app-manifest` is removed —
`CnAppRoot` consumes the manifest directly via the `manifest` prop, and
the `@resolve:` sentinel handling lives in the library.

### `src/App.vue` — rewritten

```vue
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
  <CnAppRoot
    :manifest="manifest"
    :custom-components="customComponents"
    :page-types="pageTypes"
    app-id="shillinq"
    :translate="translateForApp"
    :permissions="permissions" />
</template>
```

Script: `name: 'App'`, `components: { CnAppRoot }`, `provide()` returns
`{ objectSidebarState: this.objectSidebarState }` (a `Vue.observable`
plain object — kept for future index/detail sidebars, matches the
decidesk/procest channel), `props: { manifest (required), customComponents
(default {}), pageTypes (default {}) }`, `data()` returns
`objectSidebarState` observable, `computed.permissions` returns
`window.OC?.currentUser?.permissions ?? []` (no admin injection — shillinq
has no `permission: "admin"` menu entries yet), `async created()` →
`await initializeStores()`, `methods.translateForApp(key)` →
`ncT('shillinq', key)`.

The bespoke "OpenRegister is required" `NcEmptyContent` block is **gone** —
`CnAppRoot` renders the dependency-missing state itself from
`manifest.dependencies: ["openregister"]`.

### `src/customComponents.js` — new

```js
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for shillinq's manifest-driven shell.
// EMPTY ON PURPOSE — every shillinq page is a declarative manifest
// page type (dashboard / settings, and index/detail once the domain
// pages land). Adding an entry here means a page that does NOT fit a
// built-in type; that requires an explicit justification in the design
// doc of the change that adds it. Deleting entries is the right
// direction (ADR-024). See @conduction/nextcloud-vue →
// docs/migrating-to-manifest.md.
export default {}
```

### `src/manifest.json` — kept (already on the branch)

No structural change. It already declares:
- `dependencies: ["openregister"]`
- `menu`: `Dashboard` (route), `Documentation` (href, section settings),
  `Settings` (route, section settings)
- `pages`: `Dashboard` (`type: "dashboard"`, route `/`, 4 `stats-block`
  widgets in a 4-column grid) and `Settings` (`type: "settings"`, route
  `/settings`, `saveEndpoint: "/index.php/apps/shillinq/api/settings"`,
  a `version-info` widget section + a `register` string field).

(Optional polish, not required: bump `version` to reflect the renderer
migration. Left at `1.0.0` — the page set itself is unchanged.)

### Files deleted

- `src/router/index.js` — routes now come from `routesFromManifest()`.
- `src/navigation/MainMenu.vue` — navigation now comes from
  `manifest.menu` via `CnAppNav` (inside `CnAppRoot`).
- `src/views/Dashboard.vue` — the `/` page is now `CnDashboardPage`
  driven by `manifest.pages[Dashboard].config`.

### Files kept (unchanged)

- `src/settings.js` + `src/views/settings/{AdminRoot,Settings,UserSettings}.vue`
  — Nextcloud admin/user settings, mounted by `AdminSettings.php` on
  `#shillinq-settings`, not by the in-app router. Canonical NC pattern;
  decidesk/procest keep theirs.
- `src/pinia.js`, `src/store/store.js`, `src/store/modules/{settings,object}.js`
  — the settings store backs `AdminRoot.vue`; `objectStore.configure()`
  is the generic CRUD helper future domain pages will use.
- `src/assets/app.css`, `appinfo/**`, `lib/**`, `templates/**`, `tests/**`.

## Manifest-reachability check

`tests/validate-manifest.js` already schema-validates `src/manifest.json`.
Add (or confirm) a tiny assertion in `tests/Unit` (or extend
`validate-manifest.js`) that every `pages[].id` is unique and that every
`menu[].route`, if present, matches a `pages[].id` — i.e. the manifest is
internally consistent so `routesFromManifest()` produces a working router.
This is the regression gate ADR-024/ADR-029 expects from a manifest
adoption.

## Seed Data

N/A — this change introduces and modifies no OpenRegister schemas or
registers. Shillinq's `lib/Settings/shillinq_register.json` is untouched.

## Quality gates

- ESLint / Stylelint clean (`npm run lint`).
- `node tests/validate-manifest.js` exits 0.
- PHP side untouched, so `composer check:strict` is unaffected.
- Browser smoke (dev container, `localhost:8080/index.php/apps/shillinq`):
  app boots, left nav shows Dashboard + Settings (+ Documentation in the
  settings section), `/` renders the dashboard widgets, `/settings`
  renders the settings page, the admin-settings page still renders under
  Personal/Admin settings.



## Tasks

# Tasks — Shillinq Tier-4 manifest shell

## 1. Dependencies

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.35` in
  `package.json`.
- [x] 1.2 Run `npm install`; commit the updated `package-lock.json`.
- [x] 1.3 Add `sass` / `sass-loader` devDeps and an `.scss` + image-asset
  rule to `webpack.config.js` so the aliased monorepo-dev library source
  (which uses `<style lang="scss">`) builds. (Production CI builds against
  the bundled npm `dist`, which already inlines its styles.)

## 2. Bootstrap → Tier-4

- [x] 2.1 Rewrite `src/main.js` to the Tier-4 pattern (port from
  `procest/src/main.js`, app id `shillinq`, no `mapFormatters`): SPDX
  header, `registerIcons()` + guarded `registerTranslations()`,
  `tryLoadTranslations()` fire-and-forget, `routesFromManifest()` over
  `manifest.pages`, `new VueRouter({ mode: 'history', base:
  generateUrl('/apps/shillinq') })`, mount `App` with `manifest` /
  `customComponents` / `pageTypes` props. Removed the `useAppManifest`
  call and the `router/index.js` import.
- [x] 2.2 Rewrite `src/App.vue` to a thin `<CnAppRoot>` wrapper (port
  from `procest/src/App.vue`, app id `shillinq`, no legacy `sidebarState`
  alias): `provide` `objectSidebarState`, `props` manifest/
  customComponents/pageTypes, `permissions` computed, `created()` →
  `await initializeStores()`, `translateForApp()`. Deleted the bespoke
  "OpenRegister is required" `NcEmptyContent` UI.
- [x] 2.3 Add `src/customComponents.js` exporting `{}` with the
  "empty on purpose" header comment.

## 3. Remove dead custom shell code

- [x] 3.1 Delete `src/router/index.js`.
- [x] 3.2 Delete `src/navigation/MainMenu.vue` (the now-empty
  `src/navigation/` dir is removed too).
- [x] 3.3 Delete `src/views/Dashboard.vue`.
- [x] 3.4 `grep src/` for any remaining import of the deleted files —
  clean, none.

## 4. Manifest sanity

- [x] 4.1 `node tests/validate-manifest.js` — Ajv validation PASS
  (schema v1.5.0, 0 errors).
- [x] 4.2 Extended `tests/validate-manifest.js` with a consistency check
  (`consistencyCheck()` / `finishOk()`): `pages[].id` unique; every
  `menu[].route` that is set matches a `pages[].id`. PASS.

## 5. Build & quality

- [x] 5.1 `npm run build` — webpack compiles, produces
  `js/shillinq-main.js` (~6.4 MiB) + `js/shillinq-settings.js`. Only
  benign warnings: 2× asset-size, plus the library's own
  `leaflet.markercluster` (guarded dynamic import) and
  `../../store/index.js` (guarded `require` inside `_getObjectStore`)
  warnings, identical to procest/pipelinq.
- [x] 5.2 `npm run lint` (ESLint) clean — no findings.
- [x] 5.3 PHP side untouched (no changes under `lib/`, `appinfo/`,
  `templates/`) — `composer check:strict` unaffected.

## 6. Browser verification (dev container) — DEFERRED

> Shillinq is not currently mounted/installed in the `nextcloud` dev
> container (only `procest`, `pipelinq`, `larpingapp`, etc. are). Browser
> verification happens when shillinq is deployed / on the PR. Checklist
> kept for that step:

- [ ] 6.1 Mount/enable `shillinq` in the dev container; clear OPcache.
- [ ] 6.2 Open `/index.php/apps/shillinq` — app boots; left nav shows
  **Dashboard** + (settings section) **Documentation** + **Settings**.
- [ ] 6.3 `/` renders `CnDashboardPage` with the 4 stats-block widgets.
- [ ] 6.4 `/settings` renders `CnSettingsPage` (version-info + register).
- [ ] 6.5 With OpenRegister disabled, `CnAppRoot`'s dependency-missing
  state shows (not a blank screen).
- [ ] 6.6 Personal/Admin → Shillinq settings page still renders.
- [ ] 6.7 Screenshot for the PR.

## 7. Wrap-up

- [ ] 7.1 Update `openspec/changes/shillinq-manifest-tier4/tasks.md`
  checkboxes as completed.
- [ ] 7.2 Hand off PR creation to the Hydra coordination flow (not part
  of this change's tasks).

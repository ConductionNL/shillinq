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

## 6. Browser verification (dev container)

> Verified 2026-06-08 on the `nextcloud` dev container at
> `http://localhost:8080`. Shillinq is mounted at
> `/var/www/html/custom_apps/shillinq` (compose bind from
> `apps-extra/shillinq`) and is installed (`occ app:list` →
> `shillinq: 0.6.6`). The 4 PNGs in `screenshots/` are the evidence.

- [x] 6.1 Mount/enable `shillinq` in the dev container; clear OPcache.
  Already bind-mounted via compose; `occ upgrade` ran (NC was in
  upgrade-needed state from an earlier pipelinq upgrade); `apache2ctl
  graceful` clears OPcache on each rebuild. `/index.php/apps/shillinq/`
  → HTTP 200.
- [x] 6.2 Open `/index.php/apps/shillinq` — app boots; left nav renders
  via the v2 manifest. Top entries observed: **Dashboard**,
  **Bookkeeping** (with **Chart of Accounts** sub-item),
  **Documentation**, **Features & roadmap**, plus the **Settings**
  section button. (The manifest has grown since this checklist was first
  written; the load-bearing items for the Tier-4 shell — Dashboard +
  Settings — both render.) Screenshot: `screenshots/dashboard.png`.
- [x] 6.3 `/` renders `CnDashboardPage` with the 4 stats-block widgets
  (**Open items**, **Due this week**, **Completed**, **Team members**,
  each showing `0 sample`). Screenshot: `screenshots/dashboard.png`.
- [x] 6.4 `/settings` (via the in-app nav) renders `CnSettingsPage`
  with the **Configuration** card (Register selector + `OpenRegister
  register ID` helper text) and the **Version** card (Application
  information block). Screenshot: `screenshots/in-app-settings.png`.
- [x] 6.5 With OpenRegister disabled (`occ app:disable openregister`),
  `CnAppRoot`'s dependency-missing state shows (an `NcEmptyContent`
  note with the missing-app icon, descriptive text, and an action link
  to `/index.php/settings/apps/integration/openregister`). Not a blank
  screen. OpenRegister re-enabled after the check. Screenshot:
  `screenshots/dependency-missing.png`.
- [x] 6.6 Personal/Admin → Shillinq admin settings page
  (`/index.php/settings/admin/shillinq`) renders unchanged
  (Version Information `Shillinq 0.6.6` / Up to date, Support, and
  Configuration sections — served by the PHP `AdminSettings` +
  `SettingsSection` registrations in `appinfo/info.xml`, which the
  Tier-4 frontend migration did not touch). Screenshot:
  `screenshots/admin-settings.png`.
- [x] 6.7 Screenshot evidence captured at
  `openspec/changes/shillinq-manifest-tier4/screenshots/`:
  `dashboard.png`, `in-app-settings.png`, `dependency-missing.png`,
  `admin-settings.png`.

## 7. Wrap-up

- [x] 7.1 Update `openspec/changes/shillinq-manifest-tier4/tasks.md`
  checkboxes as completed.
- [x] 7.2 Hand off PR creation to the Hydra coordination flow (not part
  of this change's tasks).

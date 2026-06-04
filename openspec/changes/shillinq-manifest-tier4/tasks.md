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

- [x] 7.1 Update `openspec/changes/shillinq-manifest-tier4/tasks.md`
  checkboxes as completed.
- [x] 7.2 Hand off PR creation to the Hydra coordination flow (not part
  of this change's tasks).

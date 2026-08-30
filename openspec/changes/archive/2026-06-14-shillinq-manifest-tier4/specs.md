# Specifications — Shillinq Tier-4 manifest shell

## Requirement: Tier-4 Library Upgrade

**REQ-001-SHQ**  
Bump `@conduction/nextcloud-vue` from `^1.0.0-beta.31` to `^1.0.0-beta.35` in package.json and install dependencies.

### Acceptance Criteria

**GIVEN** the current package.json has `@conduction/nextcloud-vue@^1.0.0-beta.31`  
**WHEN** the dependency is upgraded to `^1.0.0-beta.35`  
**THEN** `npm install` completes successfully and `package-lock.json` is updated to reflect the new version

---

## Requirement: Bootstrap Pattern Rewrite

**REQ-002-SHQ**  
Rewrite `src/main.js` following the Tier-4 bootstrap pattern from procest, establishing manifest-driven routing.

### Acceptance Criteria

**GIVEN** shillinq has an existing `src/router/index.js` with hand-written routes  
**WHEN** `src/main.js` is rewritten to:
- Include SPDX header (EUPL-1.2, Copyright Conduction B.V. 2026)
- Register Vue plugins: PiniaVuePlugin, VueRouter
- Call `registerIcons()` and guarded `registerTranslations()`
- Call fire-and-forget `tryLoadTranslations()`
- Build routes from `manifest.pages` via `routesFromManifest()`
- Create VueRouter with `mode: 'history'` and base `/apps/shillinq`
- Mount App with props: manifest, customComponents, pageTypes  

**THEN** the app bootstraps without errors and routes are driven by the manifest

---

## Requirement: App Shell Rewrite to CnAppRoot

**REQ-003-SHQ**  
Rewrite `src/App.vue` to render `<CnAppRoot>` component with manifest, eliminating bespoke shell code.

### Acceptance Criteria

**GIVEN** shillinq has a custom App.vue with manual routing and "OpenRegister is required" empty state  
**WHEN** `src/App.vue` is rewritten to:
- Render `<CnAppRoot>` with props: manifest, custom-components, page-types, app-id="shillinq", translate, permissions
- Provide `objectSidebarState` observable for future index/detail sidebars
- Compute permissions from `window.OC?.currentUser?.permissions`
- Call `initializeStores()` in `created()` hook
- Implement `translateForApp(key)` method using `ncT('shillinq', key)`
- Remove the bespoke "OpenRegister is required" NcEmptyContent block  

**THEN** CnAppRoot handles all routing, dependency checks, and menu rendering from the manifest

---

## Requirement: Custom Components Registry

**REQ-004-SHQ**  
Create `src/customComponents.js` as an empty custom-component registry with documentation.

### Acceptance Criteria

**GIVEN** the Tier-4 pattern requires a customComponents registry  
**WHEN** `src/customComponents.js` is created with:
- SPDX header (EUPL-1.2, Copyright Conduction B.V. 2026)
- Comment explaining that the registry is intentionally empty
- Comment stating that adding entries requires explicit justification in design docs
- Export default `{}`  

**THEN** the registry is in place as the documented escape hatch for future non-standard pages

---

## Requirement: Delete Dead Shell Code

**REQ-005-SHQ**  
Delete custom shell files that are now redundant under Tier-4 manifest-driven rendering.

### Acceptance Criteria

**GIVEN** manifest-driven rendering via CnAppRoot handles routing and navigation  
**WHEN** the following files are deleted:
- `src/router/index.js` (routes now from `routesFromManifest()`)
- `src/navigation/MainMenu.vue` (navigation now from `manifest.menu`)
- `src/views/Dashboard.vue` (page now `CnDashboardPage` from library)  

**THEN** no imports of deleted files remain in the codebase (verified by grep)

---

## Requirement: Manifest Structural Integrity

**REQ-006-SHQ**  
Ensure the manifest is internally consistent: every menu.route maps to a pages[].id and every page is unique.

### Acceptance Criteria

**GIVEN** `src/manifest.json` declares pages and menu entries  
**WHEN** `tests/validate-manifest.js` runs with extended consistency checks:
- Every `pages[].id` is unique
- Every `menu[].route` (if set) matches a `pages[].id`
- JSON schema validation passes (Ajv v1.5.0)  

**THEN** validation exits 0 with no errors, confirming the manifest drives a working router

---

## Requirement: Build & Linting Clean

**REQ-007-SHQ**  
Ensure the refactored frontend builds without errors and passes linting standards.

### Acceptance Criteria

**GIVEN** the source tree has been rewritten with new main.js, App.vue, and customComponents.js  
**WHEN** the following checks run:
- `npm run build` completes with only benign (existing library) warnings
- `npm run lint` (ESLint) reports no findings
- PHP side (`lib/`, `appinfo/`, `templates/`) is unchanged  

**THEN** the build is clean and linting passes

---

## Requirement: Dev Container Smoke Tests (Deferred)

**REQ-008-SHQ**  
Verify that the app boots, navigates, and renders pages correctly in the browser (deferred to deployment).

### Acceptance Criteria

**GIVEN** shillinq is mounted and enabled in the nextcloud dev container  
**WHEN** the app is tested in the browser:
- `localhost:8080/index.php/apps/shillinq` loads without error
- Left nav shows **Dashboard**, **Documentation** (settings section), **Settings**
- Route `/` renders `CnDashboardPage` with 4 stats-block widgets
- Route `/settings` renders `CnSettingsPage` with version-info and register
- With OpenRegister disabled, `CnAppRoot` shows dependency-missing state
- Personal/Admin → Shillinq settings page still renders (admin-settings.php)  

**THEN** all smoke tests pass and a screenshot is captured for the PR

---

## Requirement: Admin Settings Pattern Preservation

**REQ-009-SHQ**  
Keep the admin-settings entry unchanged, rendered by Nextcloud's settings framework.

### Acceptance Criteria

**GIVEN** shillinq has admin/user settings via `src/settings.js` and `AdminSettings.php`  
**WHEN** this change is implemented  
**THEN** the admin-settings files are left untouched:
- `src/settings.js` remains unchanged
- `src/views/settings/{AdminRoot,Settings,UserSettings}.vue` remain unchanged
- Admin settings continue to mount on `#shillinq-settings` via AdminSettings.php (Nextcloud canonical pattern)

---

## Requirement: Pinia Store Preservation

**REQ-010-SHQ**  
Keep Pinia stores in place to back admin-settings and support future domain pages.

### Acceptance Criteria

**GIVEN** shillinq uses Pinia stores for state management  
**WHEN** this change is implemented  
**THEN** stores remain unchanged:
- `src/pinia.js` unchanged
- `src/store/store.js` unchanged
- `src/store/modules/{settings,object}.js` unchanged
- `initializeStores()` called in App.vue's `created()` hook (safety net per decidesk pattern)
- `objectStore.configure()` available for future CRUD operations on domain pages

---

## Non-Functional Requirement: No Backend Changes

**REQ-011-SHQ**  
This change only touches the frontend shell; no PHP backend, registers, or schemas are modified.

### Acceptance Criteria

**GIVEN** the change scope is frontend bootstrap only  
**WHEN** the change is implemented  
**THEN**:
- PHP backend under `lib/` is untouched
- `lib/Settings/shillinq_register.json` is untouched
- No OpenRegister schemas are added or modified
- `composer check:strict` is unaffected

---

## Rollback Criterion

**REQ-012-SHQ**  
The change must be reversible by a single git revert.

### Acceptance Criteria

**GIVEN** the commit is on feature/adopt-app-manifest or main  
**WHEN** `git revert <commit>` is run  
**THEN** the codebase returns to the Tier-1 state (with useAppManifest) or pre-manifest state, with no data loss

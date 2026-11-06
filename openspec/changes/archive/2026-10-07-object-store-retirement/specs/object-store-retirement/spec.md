# Spec: object-store-retirement (delta)

## ADDED Requirements

### Requirement: REQ-OSR-001 — No hand-rolled Pinia object store

The app MUST NOT contain a hand-rolled `defineStore(...)` Pinia store that
reimplements OpenRegister object CRUD (fetch/register/configure actions
against `/apps/openregister/api/objects`). Any generic or per-feature
OR-shaped object data need MUST route through `createObjectStore` from
`@conduction/nextcloud-vue` (ADR-015, ADR-026).

#### Scenario: `src/store/modules/object.js` no longer exists

- **GIVEN** the shillinq frontend source tree
- **WHEN** `src/store/modules/object.js` is looked up
- **THEN** the file does not exist
- @e2e exclude verified by file-existence check
  (`test -f src/store/modules/object.js` exits non-zero), not a browser flow
  — there is no UI surface for "a deleted source file."

#### Scenario: No source file defines `defineStore('object', ...)`

- **GIVEN** the shillinq frontend source tree
- **WHEN** `grep -rn "defineStore('object'" src/` runs
- **THEN** it returns zero matches
- @e2e exclude verified by static grep, not a browser flow — a source-level
  invariant with no distinct runtime symptom of its own (the runtime
  consequence, if this regressed back, is covered by the boot scenarios
  below).

### Requirement: REQ-OSR-002 — The dropped store's raw `fetch()` call is gone, not relocated

`object.js`'s `fetchObjects()` action called `fetch()` directly with a
hand-rolled `OC.requestToken` header instead of the shared `@nextcloud/axios`
instance (ADR-004, ADR-015 "API calls & CSRF"). Deleting the file MUST NOT
be paired with re-adding an equivalent raw `fetch()` elsewhere in its place.

#### Scenario: No raw `fetch()` remains in the object-store code path

- **GIVEN** `src/store/store.js` after this change
- **WHEN** `grep -n "fetch(" src/store/store.js` runs
- **THEN** it returns zero matches
- @e2e exclude verified by static grep on the specific file this change
  edits — a source-level check; the raw `fetch()` this requirement retires
  never had an observable browser-side symptom of its own (it silently
  returned `[]` on the very rare case that `this.baseUrl` was queried, which
  never happened — REQ-OSR-003 covers actual boot behaviour).

### Requirement: REQ-OSR-003 — Store bootstrap keeps working without the object store

`src/store/store.js`'s `initializeStores()` MUST continue to successfully
bring up the settings store (the one real consumer) after the object store
is removed, and every existing caller of `initializeStores()` MUST continue
to mount without error.

#### Scenario: The Dashboard page (App.vue) boots cleanly without the object store

- **GIVEN** `src/App.vue`'s `created()` hook calling `initializeStores()`
  from the edited `src/store/store.js`
- **WHEN** a user navigates to `/apps/shillinq/`
- **THEN** the Dashboard SPA mounts, renders its surface, and no
  shillinq-origin console error / page error / 5xx is recorded
- @e2e object-store-retirement::dashboard-boots-without-object-store — see
  `tests/e2e/spec-coverage/dashboard-settings.spec.ts`, test `'Dashboard —
  root SPA mounts with the Dashboard surface'`, which already exercises this
  exact `created()` → `initializeStores()` path and already asserts
  `assertNoShillinqFailures`. Tagged by this change rather than duplicated;
  see `design.md` §5.

#### Scenario: The admin Settings page (AdminRoot.vue) boots cleanly without the object store

- **GIVEN** `src/views/settings/AdminRoot.vue`'s `created()` hook calling
  `initializeStores()` from the edited `src/store/store.js`
- **WHEN** an admin navigates to `/settings/admin/shillinq`
- **THEN** the platform admin-settings surface mounts, `storesReady` becomes
  `true`, `Settings.vue` renders its register-form field (sourced from
  `useSettingsStore`, unaffected by this change), and no shillinq-origin
  console error / page error / 5xx is recorded
- @e2e object-store-retirement::admin-settings-boots-without-object-store —
  see `tests/e2e/spec-coverage/dashboard-settings.spec.ts`, test `'Settings
  — the platform admin settings section mounts'`, which already exercises
  this exact `created()` → `initializeStores()` path. Tagged by this change
  rather than duplicated; see `design.md` §5.

### Requirement: REQ-OSR-004 — Non-goals

This change MUST NOT modify `src/store/modules/budgetBBVMappingStore.js`
(the already-sanctioned `createObjectStore` reference in this app) or
`src/store/modules/settings.js` (a separate store with its own, unrelated
raw-`fetch()` finding — recorded in `proposal.md` Non-goals, not fixed here).

#### Scenario: The sanctioned `createObjectStore` consumer is untouched

- **WHEN** this change's diff is inspected
- **THEN** `src/store/modules/budgetBBVMappingStore.js` has zero lines
  changed
- @e2e exclude verified by `git diff src/store/modules/budgetBBVMappingStore.js`
  showing no changes — a diff-inspection check, not a browser flow.

#### Scenario: `settings.js`'s raw fetch is left as a recorded, separate finding

- **WHEN** this change's diff is inspected
- **THEN** `src/store/modules/settings.js` has zero lines changed
- @e2e exclude verified by `git diff src/store/modules/settings.js` showing
  no changes — a diff-inspection check, not a browser flow; the finding
  itself is prose in `proposal.md`, not something a Playwright spec asserts.

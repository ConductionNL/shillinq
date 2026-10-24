# Tasks: object-store-retirement

## 1. Blast-radius investigation (before any code change)
- [x] `grep -rln "useObjectStore\|store/modules/object\|store/object\.js" src/` — hits: `src/store/modules/object.js` (definition), `src/store/store.js` (only consumer).
- [x] `grep -rn "objectStore\." src/` — one hit: `src/store/store.js:18` (`objectStore.configure({...})`).
- [x] `grep -rn "fetchObjects\|registerObjectType\b" src/` — `fetchObjects`/`registerObjectType` are only ever *defined* in `object.js`; the one other `registerObjectType` call site (`budgetBBVMappingStore.js:73`) is against the platform's own `createObjectStore` instance, not this store.
- [x] Traced both callers of `initializeStores()` (`src/App.vue`, `src/views/settings/AdminRoot.vue`) — both discard the return value (`await initializeStores()`), never touch `objectStore`.
- [x] Traced `Settings.vue` (the component `AdminRoot.vue`'s comment claims needs "register data" from the object store) — it imports `useSettingsStore` directly, never the object store.
- [x] Confirmed zero test coverage: `tests/vitest/` has one store spec (`settingsStore.spec.js`), none for `object.js`. `vitest.config.js`'s own header comment independently states the app "delegates object CRUD to OpenRegister via `@conduction/nextcloud-vue` store factories."
- [x] Conclusion: fully dead code (configured, never read). Recorded in `proposal.md` Investigation.

## 2. Platform API verification (before assuming ADR-026's `createObjectStore` exists)
- [x] Resolved `node_modules/@conduction/nextcloud-vue` (symlinked into this worktree) — version `2.3.0`, matching the pinned `^2.3.0` in `package.json`.
- [x] `grep -n "createObjectStore" node_modules/@conduction/nextcloud-vue/dist/esm/index.js` — present: `export { createObjectStore, useObjectStore } from './store/useObjectStore.js';`.
- [x] Checked `@conduction/nextcloud-vue/store` subpath (the import path ADR-026's code examples use) — does not resolve in this version (no `exports` field, no `store/` directory at package root). The factory is real; the ADR's example import path is stale. This app's own `budgetBBVMappingStore.js` already imports correctly from the package root (`import { createObjectStore } from '@conduction/nextcloud-vue'`) and is in active use — confirms the factory works today at the pinned version, no nc-vue release wait needed.
- [x] Conclusion recorded in `proposal.md` / `design.md`: `createObjectStore` exists and works; no replacement call is added by this change regardless (see task 3 — Drop-as-deletion, not Drop-as-replacement).

## 3. Chosen exit: Drop (ADR-026 Pattern 1) — implementation
- [x] Deleted `src/store/modules/object.js` in full (removes the hand-rolled store AND its raw `fetch()`/`OC.requestToken` call in one edit — ADR-015 + ADR-004/ADR-015 CSRF violations both close by removal).
- [x] `src/store/store.js`: removed the `useObjectStore` import, the `const objectStore = useObjectStore()` line, the `objectStore.configure({...})` block, `objectStore` from the returned object, `useObjectStore` from the re-export. Removed the now-unused `generateUrl` import (its only use was building the object store's URLs).
- [x] `src/App.vue`: no code change (already discarded `initializeStores()`'s return value); comment already accurate, left as-is.
- [x] `src/views/settings/AdminRoot.vue`: corrected the `created()` docblock — was "Bring up the Pinia stores (object + settings)...", now "Bring up the Pinia stores (settings)..." since there is no object store and `Settings.vue` reads register data from `useSettingsStore`, not the object store.

## 4. e2e coverage (ADR-020 e2e-coverage gate)
- [x] Confirmed `tests/e2e/spec-coverage/dashboard-settings.spec.ts`'s `Dashboard` and `Settings` tests already exercise the two `initializeStores()` call sites this change edits, and already assert `assertNoShillinqFailures` (no shillinq-origin console/page error).
- [x] Tagged both tests with `@e2e object-store-retirement::dashboard-boots-without-object-store` and `@e2e object-store-retirement::admin-settings-boots-without-object-store` respectively, with a short comment explaining why (proves the edited `initializeStores()` still boots both callers). No new spec file — reuses existing coverage per `design.md` §5.

## 5. Verification
- [x] `npx vitest run` — before: 18 files / 196 tests / 0 failures. After: record below.
- [x] `npx eslint src` — clean before and after (unused `generateUrl` import would have failed `no-unused-vars` had it been left in).
- [x] `npx prettier --check` on changed files.
- [x] `node tests/validate-manifest.js` — unaffected (manifest JSON source, not the store layer).
- [x] `npm run check:nav-reachability` — unaffected (nav/route reachability, not the store layer).
- [x] `npm run test:l10n`, `npm run test:l10n-parity`, `npm run test:l10n-dutch-tokens` — unaffected (no new/changed user-visible strings).
- [x] Hydra gates via `run-hydra-gates.sh --app-dir .` with `HYDRA_GATE_BASE_REF=origin/development`.
- [x] `openspec validate object-store-retirement --strict`.

(Exact command output captured in the PR/session record, not duplicated verbatim here — see the task runner's final report.)

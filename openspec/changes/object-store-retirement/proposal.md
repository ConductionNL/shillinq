# Change: object-store-retirement

## Why

ADR-015 (`hydra/openspec/architecture/adr-015-common-patterns.md`, "Store
registration (Vue/Pinia)") is explicit:

> Use platform `createObjectStore` — do NOT build custom stores (hand-rolled
> object.js)

shillinq ships exactly that: `src/store/modules/object.js`, an 87-line
hand-rolled Pinia store (`defineStore('object', {...})`) that reimplements a
slice of OpenRegister object-CRUD — `configure()`, `registerObjectType()`,
`fetchObjects()` — instead of using the platform factory. Its `fetchObjects()`
action also does its own manual authenticated request:

```js
const response = await fetch(url.toString(), {
	headers: { requesttoken: OC.requestToken },
})
```

This is a second, independent violation. ADR-015 ("API calls & CSRF") and
ADR-004 (frontend rules, line 3) both say the same thing in the same words:

> Use `axios` from `@nextcloud/axios` for ALL API calls — it auto-attaches the
> CSRF token
> NEVER use raw `fetch()` for mutations — missing requesttoken causes silent
> 403 failures

(ADR-004's copy: "API calls: `axios` from `@nextcloud/axios` — auto-attaches
CSRF token. NEVER raw `fetch()` for mutations.")

ADR-026 (`store-migration-patterns`) defines the three sanctioned exits —
Drop, Thin-wrap, Hybrid — and gives a decision tree:

> 1. Does anything outside this app's source import the store by name?
>    - No → **Drop** (Pattern 1).

## Investigation — blast radius (before designing the fix)

`grep -rn "useObjectStore\|fetchObjects\|registerObjectType" src/` finds
exactly four hits, all inside `src/store/`:

- `src/store/modules/object.js` — the store's own definition
  (`useObjectStore`, `registerObjectType`, `fetchObjects`).
- `src/store/store.js` — imports it, instantiates it, calls
  `objectStore.configure({ baseUrl, schemaBaseUrl })`, re-exports
  `useObjectStore`.

Nothing else in `src/` imports `useObjectStore` from `./modules/object.js`,
nothing calls `.fetchObjects()`, and nothing reads `.objects[type]`. The only
two callers of `store.js`'s `initializeStores()` — `src/App.vue` and
`src/views/settings/AdminRoot.vue` — both discard the return value
(`await initializeStores()`); neither ever touches the `objectStore` instance
the function returns. `src/views/settings/Settings.vue`, the one component
whose comment in `AdminRoot.vue` mentions "so the embedded Settings form can
read register data", imports `useSettingsStore` directly from
`./modules/settings.js` and never touches the object store at all.

**Conclusion: `object.js` is configured but never functionally used.** It is
not near-dead — it is fully dead. `objectStore.configure()` sets two URL
strings that nothing downstream ever reads, because nothing ever calls
`fetchObjects()`. `tests/vitest/vitest.config.js`'s own header comment
independently confirms this framing: "Shillinq delegates object CRUD to
OpenRegister via `@conduction/nextcloud-vue` store factories" — i.e. the app
already considers the platform factory, not this file, to be how OR object
CRUD happens. No vitest spec exercises `object.js` (`tests/vitest/` has one
store spec, `settingsStore.spec.js`, for the *other* module) — zero test
coverage is lost by removing it.

## Platform API verification — `createObjectStore` (before assuming it exists)

ADR-026 names `createObjectStore` from `@conduction/nextcloud-vue/store`.
This app pins `"@conduction/nextcloud-vue": "^2.3.0"`; the resolved package
(`node_modules/@conduction/nextcloud-vue`, version `2.3.0`, symlinked into
this worktree from the shared checkout) has no `exports` field and no
`store/` subpath at all — `@conduction/nextcloud-vue/store` does not resolve
in this version. The factory is real, but the ADR's own import path is
stale: `dist/esm/index.js` exports it from the package root —

```
export { createObjectStore, useObjectStore } from './store/useObjectStore.js';
```

— confirmed present, not merely mentioned in a doc. This app already proves
the pattern works at the pinned version: `src/store/modules/budgetBBVMappingStore.js`
(slice 06 of `bookkeeping-waterschappen-bbv-variant`) already does

```js
import { createObjectStore } from '@conduction/nextcloud-vue'
```

and is in active use. No nc-vue-release-ordered wait is needed; the only
correction needed relative to ADR-026's prose is the import path
(`@conduction/nextcloud-vue`, not the `/store` subpath), which this change
does not need to touch since it deletes the hand-rolled store rather than
replacing it with a new `createObjectStore` call — see "Chosen exit" below.

## Chosen exit: Drop (ADR-026 Pattern 1)

Per the decision tree, question 1 ("Does anything outside this app's source
import the store by name?") is **No** — in fact nothing *inside* the app's
source functionally imports it either, beyond the dead `configure()` call.
Pattern 1 (Drop) is "Replace the custom store entirely with the lib
factory. Use when nothing outside the app's own source imports the store by
name." Because the store has zero real consumers, there is no factory call
to migrate *to* — the correct Drop here is deletion, not a replacement
`createObjectStore('shillinq-objects', …)` call that would itself have zero
consumers. Where this app genuinely needs OR-shaped object CRUD, it already
uses the sanctioned per-slice pattern (`budgetBBVMappingStore.js`) — that
precedent is what any future feature needing generic object CRUD should
follow, not a revived shared store.

Thin-wrap was rejected: it exists to protect import paths external code
depends on, and nothing depends on this one. Hybrid was rejected: it is for
apps with a genuine non-OR backend needing to coexist with OR-shaped data;
this store has no non-OR half — it was always meant to talk to OpenRegister
and simply never got a caller.

## What Changes

- **REMOVED** `src/store/modules/object.js` — the hand-rolled
  `defineStore('object', …)` store, including its raw `fetch()` call
  (ADR-004/ADR-015 CSRF violation), deleted in its entirety.
- **MODIFIED** `src/store/store.js` — drops the `useObjectStore`
  import/instantiation/`configure()` call/re-export; `initializeStores()` now
  only brings up `useSettingsStore`. `generateUrl` import removed (its only
  use was building the object store's `baseUrl`/`schemaBaseUrl`).
- **MODIFIED** `src/App.vue`, `src/views/settings/AdminRoot.vue` — doc
  comments that referenced "the object + settings store" / "object store" are
  corrected to reflect that only the settings store is bootstrapped now. No
  behavioral change: both already discarded `initializeStores()`'s return
  value.
- **MODIFIED** `tests/e2e/spec-coverage/dashboard-settings.spec.ts` — tags
  the two existing tests that already exercise both `initializeStores()`
  call sites (Dashboard → `App.vue`, Settings → `AdminRoot.vue`) with this
  change's `@e2e` scenario references, since they already assert no
  shillinq-origin console/page errors on boot — the exact regression class a
  broken store removal would produce.
- **NOT CHANGED**: `src/store/modules/budgetBBVMappingStore.js` (the
  sanctioned `createObjectStore` reference already in this app),
  `src/store/modules/settings.js` (a separate store; its own raw `fetch()`
  calls are a pre-existing, unrelated ADR-004 violation — out of scope, see
  Non-goals below), `src/store/modules/inventoryMobileScanner.js`.

## Non-goals

- **`src/store/modules/settings.js`'s raw `fetch()` calls** (lines 28, 59)
  are a real, separate ADR-004/ADR-015 violation, but they belong to a
  different store with a different consumer (`Settings.vue`) and are not
  part of the object-store blast radius this change investigated. Fixing
  them here would silently expand this change's scope past what it was
  asked to retire; they are recorded here as a finding for a follow-up
  change, not fixed in this one, per the "scope debt to the repos/files the
  task touches" convention this programme follows.
- No change to `budgetBBVMappingStore.js` or any other already-sanctioned
  `createObjectStore` consumer.
- No new generic/shared object store is introduced. If a future feature
  needs one, it should follow `budgetBBVMappingStore.js`'s per-slice
  pattern, not resurrect a shared `object.js`.

## Impact

- Affected spec: new capability `object-store-retirement`.
- Affected code: `src/store/modules/object.js` (deleted), `src/store/store.js`,
  `src/App.vue`, `src/views/settings/AdminRoot.vue` (comment-only),
  `tests/e2e/spec-coverage/dashboard-settings.spec.ts` (tagging only).
- No backend (PHP) changes — this store never had a matching PHP endpoint of
  its own; it called OpenRegister's own `/apps/openregister/api/objects` and
  `/apps/openregister/api/schemas` directly, exactly as `createObjectStore`
  does.
- No runtime/user-facing behavior changes on any page: the store was
  configured but never read from, so its removal is observationally a no-op.
  Both pages that bootstrap the Pinia stores (`App.vue` → Dashboard,
  `AdminRoot.vue` → admin Settings) already have Playwright coverage
  (`dashboard-settings.spec.ts`) asserting they mount without a shillinq-
  origin console/page error; this change tags those tests as the proof this
  removal does not regress boot.

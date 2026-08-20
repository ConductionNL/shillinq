# Design: object-store-retirement

## 1. ADRs this change implements — quoted, not paraphrased

**ADR-015 (`adr-015-common-patterns.md`), "Store registration (Vue/Pinia)":**

> - Register each entity type ONCE in `src/store/store.js` via
>   `createObjectStore`
> - NEVER register in both `OBJECT_TYPES` and `ENTITY_STORES` — pick one
>   pattern
> - Type names: kebab-case (`action-item`), NOT camelCase (`actionItem`)
> - Use platform `createObjectStore` — do NOT build custom stores
>   (hand-rolled object.js)

The literal filename `object.js` in the ADR's own wording is not a
coincidence with this app's `src/store/modules/object.js` — it is the
canonical example of the pattern this rule forbids.

**ADR-015, "API calls & CSRF":**

> - Use `axios` from `@nextcloud/axios` for ALL API calls — it
>   auto-attaches the CSRF token
> - NEVER use raw `fetch()` for mutations — missing requesttoken causes
>   silent 403 failures
> - Pattern: `import axios from '@nextcloud/axios'` + `const { data } =
>   await axios.post(url, payload)`

**ADR-004 (`adr-004-frontend.md`), line 3 (identical rule, frontend ADR's own
copy):**

> API calls: `axios` from `@nextcloud/axios` — auto-attaches CSRF token.
> NEVER raw `fetch()` for mutations. Loading state with `try/finally`.

`object.js`'s `fetchObjects()` is a GET, not a mutation, but it still hand-
rolls the CSRF header (`headers: { requesttoken: OC.requestToken }`) instead
of using the shared axios instance that already does this — exactly the
"custom store... does its own manual `OC.requestToken` handling" pattern the
task brief calls out. Removing the whole file removes this violation as a
side effect of Drop; there is no standalone axios migration to do because the
function this fetch lived in has no caller to preserve.

**ADR-026 (`adr-026-store-migration-patterns.md`) decision tree:**

> 1. Does anything outside this app's source import the store by name?
>    - No → **Drop** (Pattern 1).
> 2. Does this app have non-OR backends (PHP controllers, external APIs,
>    GraphQL gateways)?
>    - Yes → **Hybrid** (Pattern 3) for the non-OR half...
>    - No → already covered by Drop / Thin-wrap.

Answered in `proposal.md`'s Investigation section: No to both. Drop applies.

## 2. Why Drop-as-deletion, not Drop-as-replacement

ADR-026's Pattern 1 example replaces the custom store with a live
`createObjectStore('decidesk-objects', { plugins: [...] })` call because
decidesk's consumers actually called `useObjectStore().fetchObject(id)`,
`saveObject(payload)`, etc. — real call sites that needed a working
replacement with the same import path pointing at real functionality.

shillinq's `object.js` has no such call sites. `grep -rn
"fetchObjects\|registerObjectType" src/` outside `object.js` itself and
`budgetBBVMappingStore.js` (which calls the platform's own
`registerObjectType`, not this store's) returns nothing. Writing a
replacement `createObjectStore(...)` call with the same zero consumers would
reproduce the exact defect class this change exists to close — dead
platform-store wiring nobody reads — just with a compliant-looking factory
call instead of a hand-rolled one. The honest Drop here is deletion:
`src/store/store.js`'s `initializeStores()` keeps doing the one thing
anything actually depends on (`settingsStore.fetchSettings()`) and drops the
dead half.

## 3. Why `src/store/store.js` loses its `generateUrl` import too

`generateUrl` was imported for exactly one purpose in this file — building
`objectStore`'s `baseUrl` (`/apps/openregister/api/objects`) and
`schemaBaseUrl` (`/apps/openregister/api/schemas`) arguments to `.configure()`.
With that call removed, the import becomes unused; `npm run lint`
(`eslint src`) would flag it (`no-unused-vars`) if left in place, so it is
removed in the same edit rather than left for a follow-up lint pass.

## 4. Why `App.vue` / `AdminRoot.vue` need comment-only edits

Neither file's *code* changes: both call `await initializeStores()` and
discard the return value already — dropping `objectStore` from that return
value is invisible to either caller at the code level. Their *comments*,
however, explicitly narrate the object store's presence:

- `App.vue`: "Pinia stores still need to come up so the admin-settings store
  (AdminRoot.vue) and any future custom components keep working." — accurate
  as written (doesn't name the object store), left alone.
- `AdminRoot.vue`: "Bring up the Pinia stores (object + settings) so the
  embedded Settings form can read register data" — this one is now false:
  there is no object store, and (per the Investigation) `Settings.vue`
  reads register data from `useSettingsStore`, never the object store. This
  docblock is corrected to say "(settings)" only, matching what `Settings.vue`
  actually consumes.

## 5. e2e coverage strategy — reuse, not duplicate

The task brief's warning ("if removing the store changes runtime behaviour on
any page, that page needs an e2e assertion proving it still works") is
answered by the Investigation: removal does not change runtime behaviour on
any page, because nothing read from the store. The two pages that *do* run
the code path being edited — `App.vue`'s `created()` and `AdminRoot.vue`'s
`created()`, both of which call `initializeStores()` — already have
Playwright coverage in `tests/e2e/spec-coverage/dashboard-settings.spec.ts`:
the `Dashboard` test drives `App.vue`'s mount, the `Settings` test drives
`AdminRoot.vue`'s mount, and both assert `assertNoShillinqFailures` (no
shillinq-origin console error / page error / 5xx) — which is precisely what
would fire if `initializeStores()` threw after this change (e.g. from a typo
in the edited `store.js`). Rather than adding a parallel, redundant spec file
asserting the same boot path a second time, this change tags those two
existing tests with this change's `@e2e` scenario references. This matches
this repo's own convention of citing pre-existing coverage rather than
manufacturing a duplicate test when an existing one already proves the
property (see `frontend-bundle-hygiene`'s `@e2e exclude` usage for
build-artifact properties with no browser-observable equivalent — the
inverse case, cited for contrast: here a real browser flow already exists,
so it is tagged, not excluded).

## 6. Non-goal: `settings.js`'s raw `fetch()`

`src/store/modules/settings.js` (lines 28, 59) has its own raw `fetch()`
calls with the same `OC.requestToken` hand-rolling. This is a real ADR-004/
ADR-015 violation, but it is a different store (`useSettingsStore`), serving
a different, real consumer (`Settings.vue`'s save/load flow), with no
relationship to the object-store-retirement blast radius this change
investigated. Folding it into this change would violate the "why" this
change exists (ADR-015 §"Store registration" + the raw-fetch clause
specifically inside the hand-rolled *object* store) and inflate the diff past
what a reviewer can hold in one pass. Recorded here, left for its own
change.

# Adopt live updates in app-local UI (nc-vue beta.212)

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 installs `liveUpdatesPlugin` by
default on every `createObjectStore` store (lazy — inert until the first
`subscribe()` call) and fixes the first-subscription transport. OpenRegister
already pushes `or-collection-*` / `or-object-*` events for every
OpenRegister-backed object, so adopting live updates is a frontend-only change:
views subscribe while mounted and re-render from the store's refetched cache.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- Wire a live collection subscription into `BudgetBBVMappingIndex.vue` — the
  one app-local list view backed by a `createObjectStore` store
  (`budget-bbv-mapping`): subscribe on create, bridge the plugin-refetched
  collection cache (rows + pagination) into the view via a watcher, release on
  destroy with epoch-guarded in-flight handling (openregister reference
  pattern).
- Add the `realtime-updates` adoption spec.

## Out of Scope (documented skips)

- The app-local generic `useObjectStore` in `src/store/modules/object.js` is a
  hand-rolled `defineStore('object', …)`, NOT `createObjectStore` — it has no
  `subscribe()` capability, so nothing consuming it can be wired without first
  migrating the store to the shared factory (a separate, larger change).
- Manifest-driven index/detail pages are rendered by the shared library
  (`CnPageRenderer` → `CnIndexPage` / `CnDetailPage`). `CnIndexPage` has no
  subscription support and `CnPageRenderer` does not pass an `objectStore`
  instance to `CnDetailPage` (whose auto-subscribe requires it), so live
  updates for manifest pages must land in `nextcloud-vue`, not per-app.
- `BudgetBBVMappingDetail.vue` and the remaining custom views fetch through
  raw axios / bespoke app endpoints, not through a `createObjectStore` store —
  no store cache to subscribe against.
- `useInventoryDb.js` uses IndexedDB's unrelated `createObjectStore` API
  (browser storage, not the shared library factory).

## Impact

- Affected specs: `realtime-updates` (new)
- Affected code: `package.json`,
  `src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue`

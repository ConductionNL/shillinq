# Tasks — adopt-live-updates-ui

## 1. Dependency bump

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` and reinstall

## 2. Wire subscriptions

- [x] 2.1 Subscribe `BudgetBBVMappingIndex.vue` to the `budgetBBVMapping` collection on create (refetch-hint semantics, cache → rows/pagination bridge watcher)
- [x] 2.2 Release the subscription on destroy with epoch guard for in-flight subscribes
- [x] 2.3 Audit remaining `src/` consumers — the generic app-local object store is hand-rolled (`defineStore`, no `subscribe()`), manifest pages are library-rendered, the BBV detail view and other custom views use raw axios/bespoke endpoints. Skips documented in the proposal.

## 3. Verify

- [x] 3.1 `npm run lint` clean on touched files
- [x] 3.2 `npm run test:unit` green
- [x] 3.3 `npm run build` green

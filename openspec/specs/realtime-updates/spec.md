# realtime-updates Specification

## Purpose

Adopt the live-updates capability of `@conduction/nextcloud-vue` (>= 1.0.0-beta.212,
where `liveUpdatesPlugin` is installed by default on every `createObjectStore`
store) so app-local views backed by a `createObjectStore` store refresh without
a manual reload. The canonical realtime-updates contract (event keys
`or-collection-{register-slug}-{schema-slug}` and `or-object-{uuid}`, notify_push
transport with visibility-gated polling fallback, events as refetch hints only)
is owned by OpenRegister (`openregister/openspec/specs/realtime-updates/spec.md`);
this spec covers only ShillinQ's frontend adoption.

## Requirements

### Requirement: Store-backed app-local list views MUST subscribe to live collection updates

App-local list views that fetch an OpenRegister collection through a
`createObjectStore` store MUST subscribe to the corresponding
`or-collection-*` event key via `store.subscribe(type)` while mounted, and
MUST release the subscription on destroy. Events are refetch hints only — the
view MUST NOT apply event payloads directly, but re-render from the store's
refetched collection cache (last-used params, current page preserved).

#### Scenario: Budget BBV mapping index refreshes when a mapping changes elsewhere

@e2e exclude Push-transport timing is not deterministically observable in e2e; the subscribe/unsubscribe lifecycle is covered by the shared library's unit tests and the page itself by the existing waterschappen-bbv e2e flows.

- **GIVEN** the budget BBV mapping index is open and subscribed to the `budgetBBVMapping` collection
- **WHEN** a mapping object is created, updated or deleted by another session
- **THEN** the liveUpdatesPlugin refetches the collection with the last-used params and the table re-renders the fresh rows and pagination without a manual reload

#### Scenario: Subscription is released on unmount

@e2e exclude Subscription-handle bookkeeping is internal state with no UI surface; covered by the shared library's unit tests.

- **GIVEN** the budget BBV mapping index holds a live collection subscription
- **WHEN** the user navigates away and the component is destroyed
- **THEN** the subscription handle is released and any in-flight subscribe resolution is dropped via the epoch guard instead of leaking

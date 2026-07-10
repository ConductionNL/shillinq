# Change: shillinq-manifest-boot-payload-reduction

## Why

`src/main.js:18,73-75` bundles the ENTIRE manifest surface into the `main` webpack chunk and
merges it synchronously before the first paint:

```js
import bundledManifest from './manifest.json'          // 424,089 bytes (src/manifest.json)
...
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))   // 74 fragment files, 599,480 bytes total
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)
```

Measured on disk at HEAD (2026-07-07):
- `src/manifest.json` — 434,089 bytes (`du -b`)
- `src/manifest.d/*.json` — 74 fragment files, 599,480 bytes combined (`du -bc`)
- **Total: 1,033,569 bytes (~1.01 MB) of manifest JSON shipped in the `main` bundle on
  EVERY page load**, regardless of which of the app's ~15 top-level feature areas
  (bookkeeping, bookings, inventory, purchase-order-3way, WBSO, treasury, …) the visiting
  user actually opens.

This is a **double-ship**: the base manifest and every fragment are webpack-bundled
statically (`import` + `require.context`, both resolved at build time into the `main` chunk —
confirmed via `webpack.config.js:16-31`, which declares a single `main` entry with no
per-route split for manifest data). Nothing is code-split or lazy-loaded by feature area.
On top of the transfer cost, `buildManifest()` (`nextcloud-vue/src/utils/buildManifest.js:25-38,
100-109`) then walks EVERY fragment's `pages[]` against an `Array.prototype.findIndex` scan of
the accumulating merged-pages array (`mergePages`, O(n²) in total fragment page count) on
every single app boot — there is no memoization, no build-time pre-merge, and no
runtime cache (e.g. `sessionStorage`) of the merged result.

A concrete example of avoidable weight inside that payload: `src/manifest.d/` contains a
duplicate-feature pair — `bookings-resource-calendar.json` (481 bytes, unprefixed, declares a
standalone "Verkoop → Bookings calendar" menu leaf routing to `BookingsCalendarPage`) and
`10-bookings-resource-calendar.json` (8,005 bytes, declares the full "Bookings → Resources /
Calendars / Bookings" IA that superseded it). Both still merge into the live manifest and both
menu entries render, so the legacy 481-byte fragment is dead-weight duplicate navigation, not
just dead-weight bytes.

## What Changes

- **ADDED** `REQ-MBP-001` — the merged manifest payload delivered in the `main` webpack chunk
  MUST NOT exceed a defined byte budget, and manifest fragments MUST be code-split so a user
  who never opens (e.g.) WBSO or treasury pages never downloads those fragments' JSON.
- **ADDED** `REQ-MBP-002` — the legacy unprefixed `bookings-resource-calendar.json` fragment
  MUST be removed (or explicitly merged into `10-bookings-resource-calendar.json`) so the
  duplicate "Bookings calendar" navigation entry stops shipping and rendering.
- Not prescribing the exact split mechanism here (design.md covers the options: dynamic
  `import()` per menu-group with route-level manifest fetch vs. a build step that
  pre-merges `manifest.d/` into `manifest.json` and drops the runtime `buildManifest()` call
  entirely) — that decision belongs to the implementer, informed by `nextcloud-vue`'s
  existing `useAppManifest` async backend-merge stub (ADR-036), which several other fleet
  apps already lean on for a `/api/manifest/{appId}` runtime-loaded manifest peer.
- **BREAKING**: if the fix removes eager bundling of `manifest.d/`, first navigation into a
  previously-unopened feature area gains a network round-trip it didn't have before; this
  MUST be masked with a loading state, not surfaced as an error.

## Impact

- Affected spec: new capability `manifest-boot-performance` (this app has no existing spec
  covering manifest boot cost — checked `openspec/specs/apphost-adoption/spec.md`, which
  covers `register.d/` config-fragment merging, a different mechanism).
- Affected code: `src/main.js` (lines 18, 73-75), `webpack.config.js` (entry/split config),
  `src/manifest.d/bookings-resource-calendar.json` (removal candidate).
- Cross-cutting: `buildManifest()` / `mergePages()` (`nextcloud-vue/src/utils/buildManifest.js`)
  is shared library code used by every manifest-v2 app (ADR-036) — its O(n²) merge and
  lack of memoization is a fleet-wide concern, not shillinq-specific. This change scopes
  fixes to what shillinq owns (bundling strategy, dead fragment removal); the shared-lib
  algorithmic cost is called out as a CROSS-CUTTING candidate, not fixed here.

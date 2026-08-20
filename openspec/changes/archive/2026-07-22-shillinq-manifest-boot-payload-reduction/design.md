# Design: shillinq-manifest-boot-payload-reduction

## Decision needed (task 2.1)

Two split mechanisms were on the table:

**(a) Dynamic `import()` per menu-group, route-gated.** Each `manifest.d/*.json`
fragment's routes would only be added to the VueRouter instance when the user
navigates into that fragment's menu group (e.g. via `router.beforeEach` +
`router.addRoute()`), and the fragment JSON would ship as its own webpack
chunk (magic comment `webpackChunkName`). This is the "real" fix — it
actually stops shipping bytes for feature areas a user never opens.

**(b) Build-time pre-merge.** A build step runs `buildManifest()` once at
build time (not runtime) to produce a single flattened `manifest.merged.json`,
and `src/main.js` imports that instead of doing the `require.context()` +
runtime merge. This removes the O(n²) `mergePages()` cost from every boot
(cross-cutting win) but does **not** reduce the shipped byte count — it is a
CPU fix, not a bytes fix, and does not address REQ-MBP-001 on its own.

## Why this pass does NOT implement (a)

Implementing (a) correctly requires:
1. Restructuring `routesFromManifest()` (`src/main.js:79-96`) so the initial
   `VueRouter` instance is built from a MINIMAL route set (just enough to
   resolve the current URL + top-level menu shells), with the rest of each
   fragment's routes added lazily via `router.addRoute()` on
   navigation-into-group.
2. A loading-state UI for the "first navigation into a previously-unopened
   feature area" round-trip — the proposal itself flags this as
   **BREAKING** and requires it be masked, not surfaced as an error. There is
   no existing loading-shell component for this in `src/` today; one would
   need to be designed and built.
3. Deciding how deep-links (a user opening `/wbso/chart-of-accounts` directly,
   never having navigated through the WBSO menu) resolve their fragment
   BEFORE the router can match the route — this needs an async
   `router.beforeEach` guard with fallback/404 handling for a fragment that
   fails to load.
4. Re-validating `useAppManifest`'s existing async backend-merge stub
   (ADR-036, referenced by the proposal) against a NEW use case (per-fragment
   partial loads) it was not designed for — several other fleet apps use it
   for a single-shot `/api/manifest/{appId}` fetch, not incremental
   per-feature-area fetches.

Each of these is a genuine design/UX decision with fleet-wide precedent
implications (this app's manifest pattern is shared via `@conduction/
nextcloud-vue`'s `buildManifest()`/ADR-036), not a mechanical refactor. Given
the scope of this change ("migrate legacy notification dialect" fleet sweep,
of which this manifest-payload item was one line item among several), I did
not implement (a) in this pass to avoid shipping a half-built router change
that either breaks deep-linking or silently regresses a feature area's
reachability.

## What this pass DOES ship

- **REQ-MBP-002 (done)**: removed the confirmed duplicate
  `bookings-resource-calendar.json` fragment (481 bytes) — its "Verkoop →
  Bookings calendar" menu entry and `BookingsCalendarPage` page/component
  were dead-weight duplicate navigation over the same `CalendarView` grid the
  newer `10-bookings-resource-calendar.json` fragment's `BookingsCalendarView`
  page already serves via a cleaner Resources → Calendars IA. Verified via
  the newer fragment's own inline note ("Page id renamed from BookingsCalendar
  — that id belongs to the param-less Verkoop calendar page ... the two
  collided and shadowed each other") and by tracing `CalendarPage.vue`'s
  render tree (it wraps the SAME `CalendarView` component with a manual
  dropdown selector). Also removed the now-orphaned `CalendarPage.vue` view
  and its `registry.js` import/entry, and corrected a stale `registry.js`
  comment that mis-described `BookingsCalendarPage` as the customer-
  confirmation flow (it was not — that's `BookingsConfirmationPortal`,
  a separate component).
- **REQ-MBP-001 (partial — tripwire, not the fix)**: added
  `tests/check-manifest-budget.js` (`npm run check:manifest-budget`), which
  fails when `src/manifest.json` + `src/manifest.d/*.json`'s combined byte
  size exceeds a budget (default 1,050,000 bytes — just above the
  post-cleanup measured total of 1,033,088 bytes, leaving headroom for
  organic growth before it starts failing builds). This stops the payload
  from silently growing further while option (a) above remains a follow-up
  design/implementation task; it does not itself reduce the current payload.
  Not yet wired into a CI workflow — matching the existing, already-unwired
  `check:manifest` / `check:registers` sibling scripts in `package.json`
  (this repo does not currently run any `check:*` npm script from
  `.forgejo`/`.github` workflows; wiring all three together is a
  fleet/CI-config follow-up, not scoped to this change).

## Follow-up (not done here)

- Wire `check:manifest` / `check:manifest-budget` / `check:registers` into
  the app's CI workflow.

## Addendum — option (a) implemented via a route-static, page-lazy variant (task 2.2)

The four risk items above assumed option (a)'s literal shape: a MINIMAL
initial route table (`router.addRoute()` on navigation-into-group) plus a
new loading-state component plus an async deep-link guard plus
re-validating `useAppManifest` for a new per-fragment-fetch use case. On
closer reading of `CnPageRenderer`'s actual dependency shape
(`node_modules/@conduction/nextcloud-vue/src/components/CnPageRenderer/
CnPageRenderer.vue`: `pageById` is a `Map<id, page>` built from
`manifest.pages`, and `resolvedProps` reactively reads `currentPage.config`)
a materially lower-risk variant sidesteps three of those four items
entirely, and was implemented:

- **Routes stay 100% static from boot** — a new build step
  (`scripts/generate-manifest-shell.js`, wired as `prebuild`/`predev`/
  `prewatch`/`pretest:unit`) projects every `manifest.d/*.json` fragment down
  to a SLIM shell (`id`/`route`/`type`/`title` per page, full `menu` tree —
  ~15-20% of a fragment's bytes; `config`, the bulk, is dropped) into the
  committed `src/manifest.d.shell.json`. `src/main.js` builds `mergedManifest`
  (now `Vue.observable()`-wrapped) and the ENTIRE route table from the shell,
  not the full fragments — so risk item 1 (route restructure) and risk item 3
  (async deep-link guard / 404 fallback) do not apply: every route is known
  and registered upfront exactly as before, deep links always resolve.
- **Only each page's `config` is deferred**, fetched via
  `require.context('./manifest.d/', false, /\.json$/, 'lazy')` (webpack
  code-splits each fragment into its own chunk — verified: 80 separate
  `shillinq-src_manifest_d_*_json.js` chunk files emitted by `npm run build`,
  none of the 80 fragments' full content present in `shillinq-main.js`) and
  merged in place via `src/utils/mergeFragmentIntoManifest.js`'s
  `mergeFullFragmentIntoManifest`, gated by a `router.beforeEach` guard that
  awaits the load before calling `next()`.
- **No bespoke loading-state component (risk item 2) was needed**: vue-router
  keeps the FROM view mounted until an async `beforeEach` guard's `next()`
  resolves, so the round-trip is naturally masked for the fast/common case; a
  failed/slow load is caught and logged (`console.warn`), then `next()` is
  still called with the slim page data rather than blocking navigation —
  satisfying the proposal's BREAKING-caveat requirement to mask, not
  surface, the round-trip without new UI.
- **`useAppManifest` (risk item 4) is untouched** — this is a purely local
  `main.js` + one new util + one new build script change; the shared
  `@conduction/nextcloud-vue` ADR-036 async backend-merge path is not
  exercised or modified.
- **The load-bearing subtlety** — Vue 2 does not auto-track a brand-new
  object property (the slim page objects never declare `config`) — is
  handled with `Vue.set()` in `mergeFullFragmentIntoManifest`, and PROVEN
  (not just asserted) by `tests/vitest/mergeFragmentIntoManifest.spec.js`'s
  reactivity test: a Vue `computed` reading `page.config` — the same
  dependency shape as `CnPageRenderer.resolvedProps` — transitions from
  `undefined` to the merged value after the lazy merge runs, with no
  `nextTick`/remount involved.
- **What was NOT live-browser-verified** — matching this change's own
  established bar (task 1.3 / 3.1 already accept "no running instance
  available in this isolated worktree"): an actual in-browser navigation
  into a lazy-loaded feature area was not click-tested. What IS verified in
  this worktree: `npm run build` succeeds and emits one chunk per fragment
  (not bundled into `main`), `node tests/validate-manifest.js` passes,
  `npx vitest run` passes (167 tests, including the reactivity proof above),
  and ESLint is clean on every changed source file.

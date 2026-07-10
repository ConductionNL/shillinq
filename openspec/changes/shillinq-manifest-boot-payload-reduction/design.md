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

- Implement option (a) (or re-evaluate against `useAppManifest`) as its own
  change, with a design review covering the loading-state UX and deep-link
  guard behaviour called out above.
- Wire `check:manifest` / `check:manifest-budget` / `check:registers` into
  the app's CI workflow.

# Tasks: shillinq-manifest-boot-payload-reduction

## 1. Remove the confirmed duplicate fragment

- [x] 1.1 Diffed the two fragments and confirmed supersession: the legacy
  `bookings-resource-calendar.json` "Verkoop → Bookings calendar" leaf routes to
  `BookingsCalendarPage` (`src/views/bookings/CalendarPage.vue`), which is a thin dropdown-
  selector wrapper around the SAME `CalendarView` grid that the newer
  `10-bookings-resource-calendar.json`'s `BookingsCalendarView` page renders via the cleaner
  "Bookings → Resources / Calendars / BookingList" IA. The newer fragment's own inline
  `_note` documents the collision explicitly ("Page id renamed from BookingsCalendar — that id
  belongs to the param-less Verkoop calendar page ... the two collided and shadowed each other").
- [x] 1.2 Deleted `src/manifest.d/bookings-resource-calendar.json`. Also removed the now-
  orphaned `src/views/bookings/CalendarPage.vue` and its `src/registry.js` import + registry
  entry (`BookingsCalendarPage`), and corrected a stale registry comment that mis-described
  `BookingsCalendarPage` as the customer-confirmation flow (it isn't — that's the separate
  `BookingsConfirmationPortal`). Chose deletion over folding because the newer Bookings IA
  already provides the calendar entry point.
- [x] 1.3 `npm run build` (production) succeeds (exit 0). `node tests/validate-manifest.js`
  passes (223 pages, 0 Ajv errors, 0 consistency issues) — confirming exactly one calendar
  route family remains and all menu.route → page.id links still resolve. (Live in-browser nav
  spot-check NOT done — no running instance available in this isolated worktree.)

## 2. Establish a manifest payload budget + CI check

- [x] 2.1 Documented the split-mechanism decision in `design.md`: option (a) route-gated
  dynamic `import()` is the real fix but requires a router restructure + a first-navigation
  loading-state UI (the proposal's own BREAKING caveat) + a deep-link async guard + re-
  validating `useAppManifest` — each a genuine design/UX decision with fleet-wide (ADR-036)
  implications, NOT a mechanical refactor. Deferred to a dedicated follow-up change rather
  than ship a half-built router change that could regress deep-linking. Recorded the full
  rationale + follow-up in design.md.
- [x] 2.2 Implemented a lower-risk variant of option (a) that sidesteps 3 of the 4 risk items
  in 2.1 (full rationale in design.md's "Addendum — option (a) implemented" section):
  `scripts/generate-manifest-shell.js` (new, wired as `prebuild`/`predev`/`prewatch`/
  `pretest:unit`) projects every `manifest.d/*.json` fragment to a slim shell
  (`id`/`route`/`type`/`title` + full `menu`; `config` dropped — ~80% of a fragment's bytes)
  into committed `src/manifest.d.shell.json`. `src/main.js` now builds the ENTIRE route table
  from the shell (routes stay 100% static — no router restructure, no deep-link guard needed)
  and wraps `mergedManifest` in `Vue.observable()`; each fragment's full `config` is fetched via
  `require.context('./manifest.d/', false, /\.json$/, 'lazy')` — verified: `npm run build` emits
  80 separate `shillinq-src_manifest_d_*_json.js` chunks, none bundled into `shillinq-main.js` —
  gated by a `router.beforeEach` guard and merged in place via new
  `src/utils/mergeFragmentIntoManifest.js` (`Vue.set` per key, since the slim objects never
  pre-declare `config` — Vue 2 doesn't auto-track brand-new properties). No bespoke loading-state
  UI needed: vue-router keeps the FROM view mounted until the guard's `next()` resolves, masking
  the round-trip for the common case; a failed/slow load is logged and still calls `next()`
  rather than blocking. `useAppManifest`/ADR-036 untouched (purely local change).
  Reactivity PROVEN, not just asserted: `tests/vitest/mergeFragmentIntoManifest.spec.js` wires a
  real Vue `computed` reading `page.config` (the same dependency shape as
  `CnPageRenderer.resolvedProps`) and shows it transitions `undefined` → merged value after the
  lazy merge, with no `nextTick`/remount. 18 new vitest tests (10 merge/reactivity + 8 shell
  generator), all green. Live in-browser navigation into a lazy-loaded feature area NOT
  click-tested — no running instance available in this isolated worktree (same bar already
  accepted by 1.3/3.1 above).
- [x] 2.3 Added `tests/check-manifest-budget.js` + `npm run check:manifest-budget` — a tripwire
  that fails when combined `src/manifest.json` + `src/manifest.d/*.json` bytes exceed a budget
  (default 1,050,000B, just above the post-cleanup 1,033,088B total). Ran it: PASS. This stops
  the payload growing further while (a) remains pending; it does not itself reduce current
  bytes. NOT wired into a CI workflow — matching the existing already-unwired
  `check:manifest`/`check:registers` siblings (no `check:*` npm script is run from
  `.forgejo`/`.github` today); wiring all three is a CI-config follow-up noted in design.md.
- [x] 2.4 Re-measured: BEFORE `manifest.json`=434,089B + `manifest.d/`=599,480B (74 files) =
  1,033,569B. AFTER removing the 481B duplicate fragment: `manifest.json`=434,089B +
  `manifest.d/`=598,999B (73 files) = 1,033,088B. (Both figures are `check:manifest-budget`'s
  SOURCE-file total — unchanged in meaning by 2.2, since that tripwire still guards against
  fragment bytes growing unbounded on disk. `manifest.d/` has since grown to 80 files/630,819B
  from other merged work; `check:manifest-budget` still PASSes against its 1,050,000B budget.)
  Task 2.2 changes what actually SHIPS at boot, a different, now-real measurement: with the
  shell in place, first-paint JSON is `manifest.json` (461,681B, current HEAD) +
  `manifest.d.shell.json` (124,329B) = 586,010B — down from the pre-2.2 1,092,500B eager total,
  and each of the 80 feature-area fragments (avg ~7.9KB) loads only when its pages are visited,
  satisfying REQ-MBP-001 Scenario 2 (per-fragment, not whole-set, lazy load).

## 3. Validation

- [x] 3.1 `npm run build` (production) succeeds (exit 0; the two >244KiB entrypoint warnings
  are the pre-existing bundle-size advisories, not errors). `postbuild` copy step ran.
  `validate-manifest.js` green (all routes reachable). `vitest run` green (82 tests).
  ESLint clean on the changed `src/registry.js`. Live per-route in-browser spot-check
  (bookings/bookkeeping/inventory/purchase-order) NOT done — needs a running instance.
  RE-RUN after task 2.2's implementation: `npm run build` still exit 0 (`shillinq-main.js`
  11.6 MiB, down from 12 MiB; 80 new per-fragment chunks emitted, confirmed none bundled into
  `main`); `validate-manifest.js` PASS (229 pages, 0 errors); `npx vitest run` green (167 tests
  — 149 pre-existing + 18 new); ESLint clean on `src/main.js`,
  `src/utils/mergeFragmentIntoManifest.js`, `scripts/generate-manifest-shell.js` (the repo's
  pre-existing, unrelated `n/no-unpublished-import` lint gap on `vitest` imports affects every
  `tests/vitest/*.spec.js` file equally, including the 2 new ones — not introduced by this task).
- [x] 3.2 `openspec validate shillinq-manifest-boot-payload-reduction --strict` — PASS ("Change
  'shillinq-manifest-boot-payload-reduction' is valid"). The `openspec` CLI IS available in this
  environment (`/home/rubenlinde/.npm-global/bin/openspec`); the earlier "not available" note
  reflected a different, more isolated execution context.

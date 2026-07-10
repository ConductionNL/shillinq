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
- [~] 2.2 NOT implemented (the code-split itself) — see 2.1. Deferred with written rationale;
  behaviour unchanged (no regression), payload not yet reduced beyond the 1.2 dead-fragment
  removal.
- [x] 2.3 Added `tests/check-manifest-budget.js` + `npm run check:manifest-budget` — a tripwire
  that fails when combined `src/manifest.json` + `src/manifest.d/*.json` bytes exceed a budget
  (default 1,050,000B, just above the post-cleanup 1,033,088B total). Ran it: PASS. This stops
  the payload growing further while (a) remains pending; it does not itself reduce current
  bytes. NOT wired into a CI workflow — matching the existing already-unwired
  `check:manifest`/`check:registers` siblings (no `check:*` npm script is run from
  `.forgejo`/`.github` today); wiring all three is a CI-config follow-up noted in design.md.
- [x] 2.4 Re-measured: BEFORE `manifest.json`=434,089B + `manifest.d/`=599,480B (74 files) =
  1,033,569B. AFTER removing the 481B duplicate fragment: `manifest.json`=434,089B +
  `manifest.d/`=598,999B (73 files) = 1,033,088B.

## 3. Validation

- [x] 3.1 `npm run build` (production) succeeds (exit 0; the two >244KiB entrypoint warnings
  are the pre-existing bundle-size advisories, not errors). `postbuild` copy step ran.
  `validate-manifest.js` green (all routes reachable). `vitest run` green (82 tests).
  ESLint clean on the changed `src/registry.js`. Live per-route in-browser spot-check
  (bookings/bookkeeping/inventory/purchase-order) NOT done — needs a running instance.
- [ ] 3.2 `openspec validate` not run — no `openspec` CLI available in this isolated
  worktree/container.

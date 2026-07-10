# Tasks: shillinq-manifest-boot-payload-reduction

## 1. Remove the confirmed duplicate fragment

- [ ] 1.1 Diff `src/manifest.d/bookings-resource-calendar.json` (481 bytes) against
  `src/manifest.d/10-bookings-resource-calendar.json` (8,005 bytes) — confirm the "Verkoop →
  Bookings calendar" menu leaf and `BookingsCalendar` page are fully superseded by the
  "Bookings → Resources/Calendars/BookingList" IA (they route to the same underlying
  `BookingsCalendarPage`/`BookingsCalendarView` view family).
- [ ] 1.2 Delete `src/manifest.d/bookings-resource-calendar.json`, OR — if the "Verkoop" menu
  placement is intentionally kept as a shortcut — fold its menu leaf into
  `10-bookings-resource-calendar.json` under a single fragment so there is one source of
  truth per feature.
- [ ] 1.3 Rebuild (`npm run build` / dev watch) and manually verify in the app nav that
  exactly one "Bookings calendar" entry remains and its route still resolves.

## 2. Establish a manifest payload budget + CI check

- [ ] 2.1 Decide and document (in `design.md`) the split mechanism: (a) dynamic `import()` of
  each `manifest.d/*.json` fragment gated by the corresponding menu group's route, so
  unopened feature areas never download their fragment; or (b) a build-time script that
  pre-merges `manifest.d/` into `manifest.json` via `buildManifest()` and drops the runtime
  `require.context` + merge call from `src/main.js` entirely (trades bundle size for losing
  per-fragment code-splitting — only viable if (a) is rejected).
- [ ] 2.2 Implement the chosen split in `src/main.js` / `webpack.config.js`.
- [ ] 2.3 Add a CI-checked size budget (e.g. a small script comparing the built `main` chunk's
  manifest-JSON contribution against a byte threshold) so a future fragment addition that
  blows the budget fails the build instead of silently growing forever.
- [ ] 2.4 Re-measure `du -b src/manifest.json src/manifest.d/*.json` after the change and
  record the new total in the PR description for before/after comparison.

## 3. Validation

- [ ] 3.1 `npm run build` (production mode) succeeds and the app boots with all existing
  routes reachable (spot-check bookings, bookkeeping, inventory, purchase-order pages).
- [ ] 3.2 `openspec validate "shillinq-manifest-boot-payload-reduction" --type change --strict`
  passes.

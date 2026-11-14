# Change: nav-reachability-gate

## Why

`src/menu-layout.json`'s own `_removals_note` documents a near-miss: a 160-entry
`removals` list was drafted and then withdrawn because 140 of those ids were the
**only** navigation path to their page — nothing in this repo's CI would have
caught the orphaning before it shipped (the note cites e2e coverage at 11.6% of
pages). ADR-044 (menu-architecture, `hydra/openspec/architecture/adr-044-menu-
architecture.md`) decision 5 makes this a hard rule: *"A navigation refactor
MUST NOT drop any page route or any reachable function... `removals` may retire
ONLY a duplicate navigation entry whose page is still reachable by another
route."* Nothing mechanical enforces that rule inside this repo today.

A queued follow-up change, `nav-six-clusters`, will collapse the effective
manifest's 29 top-level menu entries into 6 — a wholesale restructure, not a
handful of declarative `removals[]` edits. It depends on this change landing
first, because a restructure of that size is unsafe to attempt without a
mechanical backstop that can say "you just orphaned page X."

**This is not a hypothetical risk.** Running the real `buildManifest(base,
fragments, menuLayout)` pipeline (`@conduction/nextcloud-vue`) against this
repo's current `src/manifest.json` + 81 `src/manifest.d/*.json` fragments +
`src/menu-layout.json` (measured 2026-08-19, via a sibling checkout with
`node_modules` installed — this repo's own `node_modules` is not installed;
manifest inputs confirmed byte-identical) produces:

- **595** unique page ids, **88** top-level menu groups, **358** menu nodes at
  any depth (**333** carrying a `route`, **57** lifted into the settings
  foldout via `settingsSection`).
- **34** page ids already unreachable today under the reachability relation
  this change defines (see `design.md`) — with `menu-layout.json#removals`
  sitting at `[]`. Some of these are legitimate (customer-facing portal links,
  action-triggered dialogs); others look like real IA gaps, e.g. `RateCards` /
  `RateCardDetail`, `RateSchedules` / `RateScheduleDetail`, `RateAuditTrail` /
  `RateRecordDetail`, `AansluitingResultaten` / `AansluitingResultDetail`,
  `WBSOActivityCodes` / `WBSOActivityCodeDetail`, `Deposits` / `DepositDetail`,
  and `InnovatieboxElections` / `InnovatieboxElectionDetail` — seven
  index/detail pairs whose index page has no menu entry anywhere in the
  effective manifest. Full list and per-id breakdown in `design.md`.

**Existing coverage is narrower than it looks.** Hydra's fleet-wide gate 30
(`hydra-gate-effective-manifest-crossref`, already enabled on this repo via
`enable-hydra-gates: true` in `.github/workflows/code-quality.yml`) assembles
the same effective manifest and enforces an ADR-044 "no-functionality-loss
removals invariant" — but only for ids **literally listed** in
`menu-layout.json#removals`. It does not check a menu node that is deleted,
merged, or re-homed by directly editing a fragment or the base manifest's
`menu[]` without ever touching `removals[]` — exactly the shape a 29→6
restructure is likely to take. This change is the general form of the same
invariant, scoped to shillinq's own CI: **every page whose route currently
exists must be reachable from some surviving nav entry, no matter how the
nav change was made.** It complements gate 30; it does not replace it.

## What Changes

- **ADDED** `REQ-NAVR-001` through `REQ-NAVR-007` (capability `nav-
  reachability`, full text in `specs/nav-reachability/spec.md`): a Node script
  that builds the EFFECTIVE manifest via the real `buildManifest()` pipeline
  (never a re-implementation — this repo already has one drifted
  reimplementation risk pattern to avoid, per the shared-library lesson in
  `manifest-boot-payload-reduction`), computes a precisely defined
  reachability relation over it, compares the result against a checked-in,
  reason-bearing, ratchet-only allow-list of today's known non-nav-reachable
  pages, and fails on any NEW orphan.
- **ADDED** a git-independent cause-attribution pass: on failure, the script
  reports not just the orphaned page id but WHICH `menu-layout.json` mechanism
  (`relocations`, `removals`, `settingsSection`) — or "no menu entry in any
  fragment" — caused the loss, by replaying the manifest through
  `buildManifest`'s own exported stage functions (`mergeMenuItems`,
  `applyMenuRelocations`, `applyMenuRemovals`, `applySettingsSection`) one
  stage at a time.
- **ADDED** a negative-fixture unit test proving the check can fail: a small
  synthetic manifest + menu-layout fixture where a `removals` entry retires
  the ONLY path to a page must produce a non-empty orphan list. A check that
  has never been observed to fail is unproven, per this repo's own working
  norms.
- **ADDED** `npm run check:nav-reachability`, wired into
  `.github/workflows/code-quality.yml`'s `frontend-checks` array alongside the
  existing `check:manifest` / `check:manifest-budget` / `check:registers` /
  `check:seeds` / `check:fragment-required` legs.
- **Explicitly out of scope**: this change does not restructure shillinq's
  menu (that is `nav-six-clusters`), does not add per-page e2e tests, and does
  not modify `@conduction/nextcloud-vue` or hydra's gate 30.

## Impact

- Affected spec: new capability `nav-reachability` (this app has no existing
  spec covering general page-reachability; `shillinq-nav-ia-cleanup` fixed
  specific past IA defects but created no mechanical gate, and gate 30 covers
  only the narrower removals-list case — see Why).
- Affected code (to be created by the implementer): `tests/validate-nav-
  reachability.js` (CLI + exported pure functions), `tests/nav-reachability-
  baseline.json` (ratchet allow-list), `tests/vitest/validateNavReachability
  .spec.js` (unit tests incl. the negative fixture), `package.json` (new
  `check:nav-reachability` script), `.github/workflows/code-quality.yml`
  (`frontend-checks` array).
- No production code changes. No changes to `src/manifest.json`,
  `src/manifest.d/*.json`, or `src/menu-layout.json` in this change — triaging
  and fixing the 34 currently-measured orphans (where warranted) is follow-up
  work the gate makes visible, not something this change does.
- Dependency: `nav-six-clusters` depends on this change merging first.

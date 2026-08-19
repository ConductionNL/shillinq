# Spec: nav-reachability (delta)

## ADDED Requirements

### Requirement: REQ-NAVR-001 — The reachability check MUST assemble the effective manifest via the real `buildManifest()` pipeline, never a re-implementation

The check MUST build shillinq's effective manifest by calling
`buildManifest(base, fragments, menuLayout)` and its individually exported
stage functions (`mergeMenuItems`, `applyMenuRelocations`,
`applyMenuRemovals`, `applySettingsSection`) from `@conduction/nextcloud-vue/
src/utils/buildManifest.js`, loaded via dynamic `import()`. The check MUST NOT
contain its own re-implementation of fragment merging, relocation, removal,
or settings-section promotion. If the module cannot be resolved (checked
under `node_modules/@conduction/nextcloud-vue/...` first, then
`../nextcloud-vue/src/utils/buildManifest.js` as a sibling-worktree fallback),
the check MUST exit non-zero — it MUST NOT fall back to a structural lint or
any other approximation of the merge semantics.

#### Scenario: The library resolves and the check runs

- **WHEN** `node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`
  is present
- **THEN** the check imports it, calls `buildManifest(base, fragments,
  menuLayout)` with `base` from `src/manifest.json`, `fragments` from every
  `src/manifest.d/*.json` file (sorted by filename, matching
  `require.context(...).keys().sort()` semantics — the same order
  `scripts/generate-manifest-shell.js` already uses), and `menuLayout` from
  `src/menu-layout.json`
- **THEN** the check proceeds to compute reachability against the returned
  `{ pages, menu }`

#### Scenario: The library cannot be resolved — fail closed

- **WHEN** neither `node_modules/@conduction/nextcloud-vue/src/utils/
  buildManifest.js` nor the sibling-worktree fallback path exists
- **THEN** the check prints an error naming the missing dependency and exits
  with a non-zero code
- **THEN** the check does NOT attempt any structural-lint or re-implemented
  fallback

### Requirement: REQ-NAVR-002 — Reachability MUST be computed by the defined menu-plus-link-field relation

A page is reachable if EITHER: (a) some node in the effective manifest's
`menu[]`, at any depth including `settingsSection`-flattened top-level items,
carries a `route` equal to the page's `id`; OR (b) the page is reachable by
induction — some other reachable page's `config` carries one of the fields
`indexRoute`, `detailRoute`, `rowRoute`, `clickRoute`, or `viewAllRoute` whose
value equals this page's `id`. The check MUST compute the transitive closure
of rule (b) starting from the set established by rule (a), repeating until no
further page becomes reachable. `href`/`action` menu nodes (no `route`) MUST
NOT be treated as page links.

#### Scenario: A page reachable only via a direct menu route

- **GIVEN** the effective manifest's `menu[]` contains a node with `route:
  "Dashboard"` and a page with `id: "Dashboard"`
- **WHEN** reachability is computed
- **THEN** `Dashboard` is in the reachable set

#### Scenario: A detail page reachable via its index's `detailRoute`

- **GIVEN** an index page `Invoices` is reachable and its `config.detailRoute`
  is `InvoiceDetail`
- **WHEN** reachability is computed
- **THEN** `InvoiceDetail` is in the reachable set even though no menu node
  names it directly

#### Scenario: An index page reachable via its detail's `indexRoute`

- **GIVEN** a page `FooDetail` is reachable (by any rule) and its
  `config.indexRoute` is `Foo`
- **WHEN** reachability is computed
- **THEN** `Foo` is in the reachable set

#### Scenario: A page with no menu entry and no inbound link is orphaned

- **GIVEN** a page `Ghost` whose `id` appears in no `menu[]` node's `route` at
  any depth, and no other page's `config` names `Ghost` in any link field
- **WHEN** reachability is computed
- **THEN** `Ghost` appears in the orphan list

### Requirement: REQ-NAVR-003 — A checked-in, reason-bearing ratchet baseline MUST gate only NEW orphans

The check MUST compare the computed orphan set against
`tests/nav-reachability-baseline.json`, a map of page id to a non-empty reason
string. An orphaned page id present in the baseline MUST NOT fail the check.
An orphaned page id absent from the baseline MUST fail the check. A baseline
id that is no longer orphaned MUST be reported as a warning (not a failure)
so it can be pruned by a human, without blocking the build. The baseline file
MUST carry an `_meta` block documenting that entries may be removed or have
their reason corrected, but a new entry MUST correspond to a genuinely new,
justified exception — not a bulk suppression of a fresh regression.

#### Scenario: A baselined orphan does not fail the build

- **GIVEN** page id `LegacyWizardStep` is orphaned and present in
  `tests/nav-reachability-baseline.json` with a non-empty reason
- **WHEN** the check runs
- **THEN** the check exits 0 for this id's contribution to the result

#### Scenario: A new orphan not in the baseline fails the build

- **GIVEN** page id `NewlyOrphaned` is orphaned by the current effective
  manifest and does NOT appear in `tests/nav-reachability-baseline.json`
- **WHEN** the check runs
- **THEN** the check exits non-zero and names `NewlyOrphaned` in its output

#### Scenario: A stale baseline entry warns but does not fail

- **GIVEN** page id `NowReachable` is listed in the baseline but the current
  effective manifest's reachability computation no longer places it in the
  orphan set
- **WHEN** the check runs
- **THEN** the check prints a warning naming `NowReachable` as a stale
  baseline entry
- **THEN** this warning alone does not cause a non-zero exit

### Requirement: REQ-NAVR-004 — Failure output MUST name the orphaned page id and attribute the cause to a menu-layout stage

For each NEW orphan (REQ-NAVR-003), the check MUST report which stage of the
menu pipeline first lost reachability for that page, computed by replaying
`mergeMenuItems`, `applyMenuRelocations`, `applyMenuRemovals`, and
`applySettingsSection` one stage at a time (not by inspecting git history) and
finding the earliest stage at which the page drops out of the reachable set.
The reported cause MUST be one of: no menu entry in any base/fragment
`menu[]`; `relocations`; `removals`; or `settingsSection`.

#### Scenario: An orphan caused by a `removals` entry is attributed to removals

- **GIVEN** page `OnlyPath`'s sole reaching menu node has `id: "OnlyPathLeaf"`
  and `menu-layout.json#removals` contains `"OnlyPathLeaf"`
- **WHEN** the check runs and finds `OnlyPath` newly orphaned
- **THEN** the failure output for `OnlyPath` names the cause as `removals` and
  names the removed id `OnlyPathLeaf`

#### Scenario: An orphan with no menu entry anywhere is attributed to a pre-existing gap

- **GIVEN** page `NeverLinked` has no reaching menu node in the base manifest
  merged with every fragment, before any `menu-layout.json` stage runs
- **WHEN** the check runs and finds `NeverLinked` newly orphaned
- **THEN** the failure output attributes the cause as "no menu entry in any
  base/fragment `menu[]`", not to `relocations`/`removals`/`settingsSection`

### Requirement: REQ-NAVR-005 — A negative-fixture test MUST prove the check can fail

The change MUST include a unit test, independent of this repo's real
595-page manifest, using a small synthetic `{ base, fragments, menuLayout }`
fixture in which `menuLayout.removals` retires the only menu entry reaching a
given page. Running the check's reachability + baseline-diff logic against
that fixture (with an empty baseline) MUST produce a non-empty new-orphan
result naming that page. A sibling fixture with the same shape but no
`removals` entry applied MUST produce zero new orphans, proving the negative
case is caused by the removal and not by an unrelated defect in the fixture.

#### Scenario: The negative fixture reports the orphan

- **GIVEN** a synthetic fixture where page `Solo` is reachable only via menu
  node `SoloLeaf`, and `menuLayout.removals` contains `"SoloLeaf"`
- **WHEN** the check's core logic runs against this fixture with an empty
  baseline
- **THEN** the result's new-orphan list contains `Solo`

#### Scenario: The positive control on the same fixture reports no orphan

- **GIVEN** the same fixture as above but with `menuLayout.removals` set to
  `[]`
- **WHEN** the check's core logic runs against this fixture with an empty
  baseline
- **THEN** the result's new-orphan list is empty

### Requirement: REQ-NAVR-006 — The check MUST run via `npm run` and in this repo's CI

The check MUST be runnable as `npm run check:nav-reachability`, backed by
`node tests/validate-nav-reachability.js`. The script name MUST be added to
the `frontend-checks` array in `.github/workflows/code-quality.yml` alongside
the existing `check:manifest`, `check:manifest-budget`, `check:registers`,
`check:seeds`, and `check:fragment-required` legs, so a PR that changes
`src/manifest.json`, `src/manifest.d/*.json`, or `src/menu-layout.json` runs
it in CI the same way those siblings run.

#### Scenario: The script is invocable locally

- **WHEN** a developer runs `npm run check:nav-reachability`
- **THEN** `node tests/validate-nav-reachability.js` executes and exits 0 or
  non-zero per REQ-NAVR-003

#### Scenario: CI runs the check as a frontend-check leg

- **WHEN** `.github/workflows/code-quality.yml`'s `frontend-checks` input is
  inspected
- **THEN** it includes `"check:nav-reachability"` alongside the existing
  manifest-related legs

### Requirement: REQ-NAVR-007 — Non-goals

This capability MUST NOT restructure shillinq's navigation (menu
consolidation is the separate `nav-six-clusters` change, which depends on
this one), MUST NOT add per-page e2e test coverage, and MUST NOT modify
`@conduction/nextcloud-vue` or hydra's `hydra-gate-effective-manifest-
crossref` (gate 30). This gate is additive and app-local; it complements gate
30's narrower removals-only invariant rather than replacing it.

#### Scenario: The change ships with zero manifest content edits

- **WHEN** this change's diff is inspected
- **THEN** `src/manifest.json`, `src/manifest.d/*.json`, and
  `src/menu-layout.json` are unchanged
- **THEN** only `tests/`, `package.json`, and `.github/workflows/code-
  quality.yml` are touched

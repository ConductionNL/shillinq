# Tasks: nav-reachability-gate

## 1. Core reachability module
- [x] `tests/validate-nav-reachability.js` — resolve `@conduction/nextcloud-vue/src/utils/buildManifest.js` via dynamic `import()` (node_modules first, `../nextcloud-vue/src/utils/buildManifest.js` sibling-worktree fallback per `validate-manifest.js`'s candidate-list pattern); fail closed (exit 1, no lint fallback) if neither resolves.
- [x] Load `src/manifest.json`, every `src/manifest.d/*.json` (filter `.endsWith('.json')` — `src/manifest.d/README.md` exists and is NOT a fragment, same trap `check-manifest-budget.js`/`generate-manifest-shell.js` already guard against), sorted by filename, and `src/menu-layout.json`. Call `buildManifest(base, fragments, menuLayout)`.
- [x] Implement `computeReachable(manifest)` exactly per `design.md` §2 (menu-route base case + `indexRoute`/`detailRoute`/`rowRoute`/`clickRoute`/`viewAllRoute` closure to a fixed point). Export it (module.exports) so the vitest fixture test (task 3) can import it directly, not just shell out to the CLI.
- [x] Implement the 4-stage cause-attribution replay per `design.md` §5 (`mergeMenuItems` → `applyMenuRelocations` → `applyMenuRemovals` → `applySettingsSection`, reachability computed after each stage). Export it alongside `computeReachable`.

## 2. Ratchet baseline
- [x] Re-run the reachability computation against a REAL `npm ci` in this repo (not the sibling-checkout measurement `design.md` §3 used) and get the current, verified orphan list — the §3 count of 34 is a starting hypothesis, not to be copied blind.
- [x] For each orphan, read its `type` + the component/action that actually opens it (index page action button, mobile-scanner entry route, customer-portal link, etc.) before writing a `tests/nav-reachability-baseline.json` reason. Where a page looks like a genuine, previously-invisible IA gap (the seven index/detail pairs flagged in `design.md` §3 are the leading candidates — `RateCards`, `RateSchedules`, `RateAuditTrail`, `AansluitingResultaten`, `WBSOActivityCodes`, `Deposits`, `InnovatieboxElections` and their detail pages, plus `SubsidieAanvragen`/`SubsidieTerugvorderingen`, `AccountingStandardsPolicy`, `BewaartermijnenDashboard`), flag it explicitly in the PR description as a candidate follow-up rather than silently baselining it with a weak reason.
- [x] Write `tests/nav-reachability-baseline.json` with the `_meta` block from `design.md` §4 and one reason-bearing entry per accepted exception.
- [x] Implement the baseline diff in the CLI script: new-orphan → fail with attributed cause; stale baseline entry → warn only (REQ-NAVR-003).

## 3. Negative-fixture proof (REQ-NAVR-005)
- [x] `tests/vitest/validateNavReachability.spec.js` — a small synthetic `{ base, fragments, menuLayout }` fixture (not the real manifest) where `menuLayout.removals` retires the only menu entry reaching one page. Assert `computeReachable`/the baseline-diff logic reports that page as a new orphan.
- [x] Sibling assertion on the SAME fixture with `removals: []` — assert zero new orphans, proving the failure in the first assertion is caused by the removal, not a fixture defect (positive control, matching this repo's own working norm that a check must be shown able to pass AND fail on deliberately varied input).
- [x] Unit-test the cause-attribution replay directly: assert a removals-caused orphan is attributed `"removals"` and a page absent from every fragment's `menu[]` from the start is attributed `"no menu entry in any base/fragment menu[]"`, not `"removals"`.

## 4. Wiring
- [x] Add `"check:nav-reachability": "node tests/validate-nav-reachability.js"` to `package.json` `scripts`.
- [x] Add `"check:nav-reachability"` to the `frontend-checks` array in `.github/workflows/code-quality.yml` (currently `["check:manifest", "check:manifest-budget", "check:markers", "check:registers", "check:seeds", "check:fragment-required", "test:l10n", "format"]`).

## 5. Validation
- [x] `npm run check:nav-reachability` locally — PASS against the seeded baseline (task 2), confirming the ratchet mechanism itself works before relying on CI.
- [x] `npx vitest run tests/vitest/validateNavReachability.spec.js` — green, including the negative fixture and its positive-control sibling.
- [x] Temporarily add one real, currently-reachable page's id to `menu-layout.json#removals` locally (no other change), re-run `npm run check:nav-reachability`, confirm it FAILS and correctly attributes the cause to `removals` — then revert the local edit. This is the "prove it on the real pipeline, not just the synthetic fixture" check; do not commit the temporary edit.
- [x] `openspec validate nav-reachability-gate --strict` — PASS.

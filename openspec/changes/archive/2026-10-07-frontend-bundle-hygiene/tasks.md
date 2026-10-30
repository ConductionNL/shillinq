# Tasks: frontend-bundle-hygiene

## 1. Baseline measurement (before any code change)
- [x] `npm run build` (production) against the unmodified `webpack.config.js`. Recorded: `js/` total 71,926,834 B (68.6 MiB); 30 `.map` files; 161 files total; `shillinq-main.js` 12,472,922 B (11.89 MiB, the entire `main` entrypoint — single file, no split); `shillinq-settings.js` 3,500,787 B (3.34 MiB, entire `adminSettings` entrypoint); `shillinq-cn-icons-mdi.js` 2,808,750 B; `shillinq-cn-icons-rvo.js` 1,912,957 B; `widget.js` ~96 KiB (`du -h`, not byte-exact — widget is unaffected by this change's edits so an exact pre-change figure was not re-captured after `js/` was overwritten by task 3).

## 2. `devtool` fix (REQ-FBH-001)
- [x] `webpack.config.js`: `webpackConfig.devtool = isDev ? 'cheap-source-map' : false`, matching pipelinq (`webpack.config.js:24`) / openregister (`webpack.config.js:13`), the reference ADR-061 decision 3 names explicitly.

## 3. `splitChunks` first attempt — measured, found regressing, corrected (REQ-FBH-002, REQ-FBH-003)
- [x] Added `optimization.splitChunks` with outer `chunks: (chunk) => chunk.name !== 'widget'` (excludes the self-contained embed entry, mirroring openregister's `integrationGlobal` exclusion) and two cacheGroups (`ncVue`, `vendor`), both `chunks: 'initial'` (protects nc-vue's own dynamic-import chunks from being swept in — the pipelinq RVO-icon-set regression).
- [x] Rebuilt with `minChunks` left at its cacheGroup default (1). Measured: `main` entrypoint 9.91 MiB (main.js 3,128,719 B + shared-nc-vue.js 6,587,044 B + shared-vendor.js 670,464 B); `adminSettings` entrypoint **8.44 MiB — a regression from the 3.34 MiB baseline**, because `minChunks: 1` extracted the UNION of both entries' nc-vue usage (main's ~595-page footprint dwarfs adminSettings' handful of settings screens) into one chunk both entries then had to load whole.
- [x] Added `minChunks: 2` to both cacheGroups (design.md §2 point 3) so only modules reachable from BOTH entries get extracted, leaving each entry's exclusively-used code in place as before.
- [x] Rebuilt again. Measured (final, see task 5 table): `main` entrypoint 9.73 MiB (down from 11.89 MiB baseline); `adminSettings` entrypoint 2.88 MiB (down from 3.34 MiB baseline — now a real reduction, not a regression); `shillinq-shared-nc-vue.js` dropped from 6.28 MiB (union) to 972 KiB (true overlap only); widget entrypoint unaffected (single-file, 87.1 KiB per webpack's own stats: `Entrypoint widget 87.1 KiB = widget.js`).

## 4. Icons-bundle investigation (task brief item 4) — no code change
- [x] Traced `shillinq-cn-icons-mdi.js` to `node_modules/@conduction/nextcloud-vue/src/components/CnIconBrowser/CnIconBrowserPanel.vue:897`'s `import(/* webpackChunkName: "cn-icons-mdi" */ '@mdi/js')` — nc-vue's own dynamic import, not shillinq's. Confirmed via `grep -rln "IconPicker\|IconBrowser\|CnIcon" src/` that no shillinq component imports it directly; `src/icons.js` (shillinq's own icon registry) imports per-icon from `vue-material-design-icons` instead, already tree-shaken.
- [x] Confirmed via webpack's own entrypoint stats (both before and after this change) that `shillinq-cn-icons-mdi.js` and `shillinq-cn-icons-rvo.js` are listed only in the "asset size limit" warning, never in the "entrypoint size limit" warning's `main`/`adminSettings` file lists — i.e. already async-only, not part of any entry's initial payload.
- [x] Verified the new `minChunks: 2` cacheGroups did not change this: `cn-icons-mdi.js` 2,808,750 B → 2,808,677 B and `cn-icons-rvo.js` 1,912,957 B → 1,912,884 B (73-byte diff each, consistent with webpack module-id renumbering elsewhere in the graph, not absorption — an absorbed chunk would disappear from the asset list entirely, not shrink by 73 bytes).
- [x] Conclusion recorded in `design.md` §3: correctly isolated already; not dead code (backs a real icon-search feature); not further tree-shakeable (a search-the-whole-set feature needs the whole set); left unchanged.

## 5. Final measured before/after table
- [x] All numbers below are from `stat -c '%s'` / `du -sb` / `find -name '*.map' | wc -l` on the actual build output, both builds using `NODE_ENV=production npx webpack --config webpack.config.js`.

| Metric | Before | After | Δ |
|---|---|---|---|
| `js/` total | 71,926,834 B (68.6 MiB) | 30,839,392 B (29.4 MiB) | **−41.1 MB (−57%)** |
| `.map` files | 30 | 0 | **−30** |
| `js/` file count | 161 | 129 | −32 |
| `main` entrypoint total | 12,472,922 B (11.89 MiB) | 10,207,574 B (9.73 MiB) | **−2.26 MB (−18%)** |
| `adminSettings` entrypoint total | 3,500,787 B (3.34 MiB) | 3,015,982 B (2.88 MiB) | **−0.48 MB (−14%)** |
| `widget` entrypoint | ~96 KiB (single file) | 89,147 B / 87.1 KiB (single file, per webpack stats) | unchanged shape (still 1 file, no shared-chunk dependency) |
| `shillinq-cn-icons-mdi.js` | 2,808,750 B | 2,808,677 B | unchanged (still async-only) |
| `shillinq-cn-icons-rvo.js` | 1,912,957 B | 1,912,884 B | unchanged (still async-only) |

## 6. Verification
- [x] `node tests/validate-manifest.js` — PASS (Ajv validation PASS, consistency check PASS). Unaffected by this change (manifest JSON source, not webpack output).
- [x] `node tests/check-manifest-budget.js` — PASS (total=1,123,373 B, budget=1,126,300 B). Unaffected by this change for the same reason.
- [x] `npx vitest run` — PASS: 18 test files, 205 tests, 0 failures. No test in this repo exercises `webpack.config.js` directly (neither pipelinq nor openregister has one either); build-artifact correctness is verified by direct inspection above, matching this repo's existing pattern for build-config changes.
- [x] Widget self-containment (REQ-FBH-003) — verified via webpack's own stats output: `Entrypoint widget 87.1 KiB = widget.js`, a single file with no `shillinq-shared-*` dependency listed.
- [x] `postbuild`/`postdev` copy hook — unmodified; verified by code inspection that `cp -r js/. ../openregister/custom_apps/shillinq/js/` copies the directory's contents rather than a hardcoded file list, so the new/renamed chunk files require no hook change. The target directory does not exist in this worktree (`../openregister` is not checked out here), so the `[ -d ... ] && ... || true` guard correctly no-ops locally — matches this repo's own documented behaviour for a dev-only integration point with no CI coverage.
- [x] `git diff src/main.js` — zero lines changed, confirming the ADR-061 decision 2 non-goal (REQ-FBH-004) was not touched.
- [x] `grep -r "vue-apexcharts" src/ package.json` and `grep -rn "from 'lodash'" src/` — zero matches each, confirming shillinq has neither of the other two ADR-061 Amplifier-2 patterns to fix.
- [x] `openspec validate frontend-bundle-hygiene --strict` — PASS.

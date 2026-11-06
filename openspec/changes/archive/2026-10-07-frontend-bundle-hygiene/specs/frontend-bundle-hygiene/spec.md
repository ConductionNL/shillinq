# Spec: frontend-bundle-hygiene (delta)

## ADDED Requirements

### Requirement: REQ-FBH-001 — Production builds MUST NOT emit source maps

`webpack.config.js` MUST set `devtool` to `false` for a production build
(`NODE_ENV=production`). Development builds (`NODE_ENV=development`) MAY
continue to use `cheap-source-map` or another fast, dev-only devtool. A
production `npm run build` MUST NOT emit any `*.js.map` file into `js/`.

#### Scenario: A production build emits zero source-map files

- **GIVEN** `webpack.config.js` with `devtool: isDev ? 'cheap-source-map' :
  false`
- **WHEN** `NODE_ENV=production npx webpack --config webpack.config.js` runs
  to completion
- **THEN** `find js/ -name '*.map'` returns zero files
- @e2e exclude verified by direct build inspection (`find js/ -name
  '*.map' | wc -l` before/after this change, recorded in `tasks.md`
  Validation) — a build-artifact check, not a browser flow; source maps are
  never requested by a normal page load so there is no user-facing behaviour
  for a Playwright spec to assert here.

#### Scenario: A development build still gets fast source maps

- **GIVEN** the same `webpack.config.js`
- **WHEN** `NODE_ENV=development npx webpack --config webpack.config.js`
  runs
- **THEN** `webpackConfig.devtool` evaluates to `'cheap-source-map'`,
  unchanged from before this change
- @e2e exclude verified by code inspection of the `isDev ? 'cheap-source-map'
  : false` ternary — the development branch is a literal no-op vs. the
  pre-change value; a config-level check, not a browser flow.

### Requirement: REQ-FBH-002 — Shared framework code MUST be split out of the `main` and `adminSettings` entries into cached chunks

`webpackConfig.optimization.splitChunks` MUST define `cacheGroups` that pull
Vue, `@nextcloud/vue`, `@conduction/nextcloud-vue`, and Pinia out of the
`main` and `adminSettings` entry bundles into one or more separately-cached
chunk files, so the two entries stop independently bundling their own full
copy of the shared framework. Each such cacheGroup MUST set `minChunks: 2`
(or otherwise restrict extraction to modules reachable from at least two of
the entries under consideration) — it MUST NOT use the cacheGroup default of
`minChunks: 1`, which extracts the UNION of every eligible entry's usage
into one chunk that every one of those entries must then load in full,
regardless of whether that entry actually uses the extracted code. This is
not a hypothetical: measured on this app's own asymmetric `main` (595 pages)
vs. `adminSettings` (a handful of settings screens) pair, `minChunks: 1`
inflated `adminSettings`'s entrypoint total from 3.34 MiB to 8.44 MiB — see
`design.md` §2 point 3. The `chunks` option on each such cacheGroup MUST be
`'initial'` (or otherwise scoped to exclude dynamically-imported modules) —
it MUST NOT be `'all'` or unset in a way that lets an asynchronously-imported
module (e.g. a `webpackChunkName`-tagged dynamic `import()` inside a library
component) be absorbed into the new eager
chunk.

#### Scenario: `main` and `adminSettings` stop each bundling their own copy of the framework

- **GIVEN** the `ncVue` and `vendor` cacheGroups defined in
  `webpackConfig.optimization.splitChunks.cacheGroups`
- **WHEN** a production build runs
- **THEN** `js/shillinq-shared-nc-vue.js` and `js/shillinq-shared-vendor.js`
  are emitted
- **THEN** neither `shillinq-main.js` nor `shillinq-settings.js` contains a
  second, independent copy of the modules matched by those cacheGroups'
  `test` patterns
- @e2e exclude verified by build-artifact inspection (presence of the two
  new chunk files; webpack's own stats output listing them as initial chunks
  of both the `main` and `adminSettings` entrypoints) — recorded in

#### Scenario: Neither entrypoint's own total regresses versus the pre-change build

- **GIVEN** the corrected `minChunks: 2` cacheGroups
- **WHEN** a production build runs and webpack's stats output reports each
  entrypoint's combined asset size
- **THEN** `main`'s entrypoint total is no larger than its pre-change total
  (`shillinq-main.js` alone, no split)
- **THEN** `adminSettings`'s entrypoint total is no larger than its
  pre-change total (`shillinq-settings.js` alone, no split) — this is the
  specific regression `minChunks: 1` caused and `minChunks: 2` fixes
- @e2e exclude verified by comparing webpack's entrypoint-size-limit stats
  output before this change, after the uncorrected `minChunks: 1` attempt,
  and after the `minChunks: 2` correction — three build-artifact
  measurements recorded in `tasks.md` Validation; a build-configuration
  regression check, not a browser flow.
  `tasks.md` Validation; not a runtime/browser-observable behaviour change
  (the app renders identically either way) so there is no new user flow for
  a Playwright spec to cover.

#### Scenario: A dynamically-imported nc-vue chunk is NOT absorbed into the new eager vendor chunk

- **GIVEN** `@conduction/nextcloud-vue`'s `CnIconBrowserPanel.vue` contains
  `import(/* webpackChunkName: "cn-icons-mdi" */ '@mdi/js')`, and nc-vue's
  icon-set modules contain similar `webpackChunkName`-tagged dynamic imports
  (e.g. `cn-icons-rvo`)
- **WHEN** a production build runs with the `ncVue`/`vendor` cacheGroups from
  this requirement active
- **THEN** `js/shillinq-cn-icons-mdi.js` and `js/shillinq-cn-icons-rvo.js`
  (or their content-hashed equivalents) remain separate, async-only chunks —
  webpack's own "entrypoint size limit" stats output MUST NOT list either
  file as part of the `main` or `adminSettings` entrypoint's asset list
- @e2e exclude verified by inspecting webpack's stats output's entrypoint
  asset lists before and after this change (recorded in `tasks.md`
  Validation, this is the specific regression pipelinq's own
  `webpack.config.js` comment documents hitting with its RVO icon set) — a
  build-configuration correctness property, not a browser-observable flow.

### Requirement: REQ-FBH-003 — The embeddable widget entry MUST remain self-contained

The `widget` webpack entry (`src/components/widget/WidgetEmbed.js`, the
booking self-service widget's script-tag embed loader, REQ-WSW-004) MUST be
excluded from the `splitChunks` cacheGroups added by REQ-FBH-002. A
production build's `js/widget.js` MUST contain everything the widget needs
to run when loaded as the sole `<script>` tag on a third-party page — it
MUST NOT depend on `shillinq-shared-nc-vue.js`, `shillinq-shared-vendor.js`,
or any other chunk this change introduces.

#### Scenario: `widget.js` is unaffected by the new splitChunks configuration

- **GIVEN** `optimization.splitChunks.chunks` is `(chunk) => chunk.name !==
  'widget'`
- **WHEN** a production build runs
- **THEN** `js/widget.js`'s byte size and module contents are unchanged from
  a build of the same source without the REQ-FBH-002 cacheGroups (module
  graph identical; only `devtool`-driven map removal, REQ-FBH-001, affects
  its output)
- **THEN** `js/widget.js` is not listed as a chunk of `shillinq-shared-nc-
  vue.js` or `shillinq-shared-vendor.js`, and vice versa
- @e2e exclude verified by comparing `js/widget.js` size before/after this
  change (recorded in `tasks.md` Validation) — the widget's actual runtime
  behaviour (mounting into a partner site's container div) already has
  Playwright coverage elsewhere in this repo's widget test suite,
  unaffected by an internal bundling change with no functional difference;
  this scenario is specifically about the build artifact staying
  self-contained, not a new user-facing flow.

### Requirement: REQ-FBH-004 — Non-goals

This change MUST NOT modify `src/main.js`'s manifest-reactivity handling
(the `reactive()` wrap at `src/main.js:133`, kept as a documented deviation
from ADR-061 decision 2 — see `design.md` §5), MUST NOT modify
`@conduction/nextcloud-vue`'s packaging (ADR-061 decision 1 is a separate,
library-side change), and MUST NOT remove or replace
`shillinq-cn-icons-mdi.js` or any other nc-vue-owned dynamic chunk — `design.
md` §3 records the investigation finding that it is already correctly
isolated. This change MUST NOT alter the `postbuild`/`postdev` → `../
openregister/custom_apps/shillinq/js/` copy hook in `package.json`.

#### Scenario: The manifest reactivity code is byte-for-byte unchanged

- **WHEN** this change's diff is inspected
- **THEN** `src/main.js` has zero lines changed
- @e2e exclude verified by `git diff src/main.js` showing no changes; a
  diff-inspection check, not a browser flow.

#### Scenario: The dev-instance copy hook keeps working with the new chunk set

- **GIVEN** `../openregister/custom_apps/shillinq/js/` exists (the shared
  dev instance)
- **WHEN** `npm run build` (or `npm run dev`) completes and its `postbuild`/
  `postdev` script runs
- **THEN** every file in `js/`, including the new `shillinq-shared-nc-
  vue.js` / `shillinq-shared-vendor.js` chunks, is copied into that
  directory — the hook copies the directory's contents (`cp -r js/. ...`)
  rather than a hardcoded file list, so it requires no change to keep
  working
- @e2e exclude verified by code inspection of the unmodified `postbuild`
  script (`cp -r js/. ...`, a directory copy with no per-file enumeration)
  plus a local `npm run build` + manual check that the destination directory
  (when present) receives every new chunk file; this is a local dev-tooling
  path with no CI runner for the sibling `openregister` checkout, so it
  cannot be exercised in this repo's own CI and is verified locally instead.

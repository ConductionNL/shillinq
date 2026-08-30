# Change: frontend-bundle-hygiene

## Why

ADR-061 (`frontend-bundle-and-boot-hygiene`,
`hydra/openspec/architecture/adr-061-frontend-bundle-and-boot-hygiene.md`)
names shillinq as one of the fleet's two amplifier cases, alongside the
shared nc-vue root cause it does not ask this app to fix. Two things are
wrong in `webpack.config.js` specifically:

**Production ships full source maps.** Line 10:
```js
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'
```
ADR-061 decision 3 is explicit: *"Production `webpack.config.js` MUST NOT use
`devtool: 'source-map'` (use `false` or a hidden/nosources variant) —
pipelinq/openregister are the reference."* Both reference apps set
`devtool: isDev ? 'cheap-source-map' : false` with the same rationale
recorded in their own comments: full production source maps add "significant
memory and time on top of compilation" and emit tens of MB of `.map` files
that ship to every browser that requests them (nothing in Nextcloud's static
file serving gates `.map` requests behind auth). Measured on this repo before
this change: 30 `.map` files, `js/` totalling ~69 MB combined with the JS
itself, of which the maps are the majority.

**No `splitChunks` cacheGroups despite 4+ entry points.** `webpackConfig.entry`
declares three entries (`main`, `adminSettings`, `widget`); the base
`@nextcloud/webpack-vue-config` a fourth (`main`, overridden). None of them
share code today because webpack 5's default `splitChunks.chunks` is
`'async'` — only dynamically-imported modules get split into shared chunks;
each of these three *initial* entry chunks independently bundles its own copy
of Vue, `@nextcloud/vue`, `@conduction/nextcloud-vue`, Pinia and every other
static import. `js/shillinq-main.js` alone measured **~12.4 MB** before this
change.

pipelinq and openregister have already solved the general shape of this
problem for their own multi-entry builds (`pipelinq/webpack.config.js:259-295`,
`openregister/webpack.config.js:59-68`): a `vendor`/`ncVue` cacheGroup pulls
the shared framework code out of each *initial* entry into one cached chunk,
while an entry that must stay self-contained is excluded from splitting via
`chunks: (chunk) => chunk.name !== '<entry>'`. shillinq has exactly that
excluded-entry case already: `widget` (`src/components/widget/WidgetEmbed.js`)
is a UMD bundle partner sites embed via a single `<script src=".../widget.js">`
tag (REQ-WSW-004) — it must ship as one self-contained file, with no
dependency on a second `<script>` tag for a shared vendor chunk a third-party
page was never told to load.

**The `js/` output feeds the shared dev instance.** `package.json`'s
`postbuild`/`postdev` hooks copy `js/` into
`../openregister/custom_apps/shillinq/js/` when that directory exists. Any
webpack config change that alters chunk filenames or splits code across more
files must keep that copy working — Nextcloud loads shillinq's scripts by the
naming convention the base config produces, not a hardcoded list.

## What Changes

- **MODIFIED** `webpack.config.js`: `devtool` is `false` in production
  (`isDev ? 'cheap-source-map' : false`), matching pipelinq/openregister.
  Zero `.map` files ship from a production build.
- **ADDED** `optimization.splitChunks` cacheGroups splitting the `main` and
  `adminSettings` entries' shared framework code (Vue, `@nextcloud/vue`,
  `@conduction/nextcloud-vue`, Pinia, `@vueuse`, `vue-router`, `vue-i18n`)
  into two cached chunks (`shillinq-shared-nc-vue.js`,
  `shillinq-shared-vendor.js`), following pipelinq's `ncVue`/`vendor`
  cacheGroup split. `chunks: 'initial'` on every cacheGroup (not `'all'`) so
  the split does not sweep nc-vue's own dynamically-imported chunks (the RVO
  icon set, the `cn-icons-mdi` icon-browser bundle — see below) into the
  eager vendor chunk, which is the exact regression pipelinq's own comment
  documents hitting and fixing.
- **MODIFIED** the outer `optimization.splitChunks.chunks` predicate excludes
  the `widget` entry by name (`chunk.name !== 'widget'`), mirroring
  openregister's `integrationGlobal` exclusion for the same reason: an
  embeddable script tag must stay self-contained.
- **INVESTIGATED, NOT CHANGED**: `shillinq-cn-icons-mdi.js` (~2.8 MB). Finding
  (see `design.md` §3): this chunk is emitted by
  `@conduction/nextcloud-vue`'s own `CnIconBrowserPanel.vue`
  (`import(/* webpackChunkName: "cn-icons-mdi" */ '@mdi/js')`) — it is
  ALREADY an async, separately-loaded chunk, not part of any entry's initial
  payload, and shillinq's own icon usage (`src/icons.js`) already imports
  per-icon from `vue-material-design-icons`, which is tree-shakeable and
  unrelated to this chunk. No shillinq source file imports `CnIconBrowser`/
  `CnIconPicker` directly; the chunk exists in `js/` only because nc-vue's
  side-effecting barrel pulls the component's module (not its `@mdi/js`
  payload — that stays behind the dynamic `import()`) into whatever entry
  imports the nc-vue barrel. Leaving `@mdi/js` un-split here is correct: an
  icon *browser* needs the full icon set to search across, so this is not
  dead code the way the manifest-validator or ApexCharts amplifiers in
  ADR-061's Context section are — it is a legitimately large feature,
  correctly isolated behind a dynamic import already. The only risk this
  change introduces is `chunks: 'initial'` accidentally absorbing it into the
  new vendor chunk; verified not to happen (see Verification below).
- **NON-GOAL**: ADR-061 decision 2 (manifest MUST be passed to Vue as a
  frozen/`markRaw`/`shallowRef` object) is explicitly NOT touched.
  `src/main.js:133` wraps the built manifest in `reactive()` deliberately —
  documented in the surrounding comment block as required so
  `mergeFullFragmentIntoManifest`'s in-place lazy-fragment merge (the
  `shillinq-manifest-boot-payload-reduction` change) is picked up by
  `CnPageRenderer`'s reactive `resolvedProps` computed. Reverting to
  `markRaw`/`shallowRef` would silently break lazy fragment loading; that is
  a separate, considered trade-off this change does not revisit.
- **NON-GOAL**: ADR-061 decision 1 (nc-vue `sideEffects: false` + per-component
  subpath exports) is a library-side fix tracked as nc-vue's own
  `bundle-tree-shaking-and-code-splitting` change. shillinq is a consumer;
  nothing in `package.json` or `src/` changes to work around it.
- **NON-GOAL**: no dead-dependency removal, no lodash import-path changes, no
  `vue-apexcharts` replacement — ADR-061's "Amplifier 2" dead/duplicated-dep
  findings named other apps (openconnector, softwarecatalog, openbuild,
  opencatalogi, larpingapp) specifically, not shillinq. A `depcheck`-style
  audit of shillinq's own `package.json` was out of scope for this change and
  is not attempted.

## Impact

- Affected spec: new capability `frontend-bundle-hygiene` (no prior spec
  covers webpack production-build configuration in this repo).
- Affected code: `webpack.config.js` only. No `src/` changes, no
  `package.json` script changes, no `appinfo/info.xml` changes — Nextcloud
  auto-loads `js/shillinq-main.js` by convention; the new split-chunk files
  are additional *initial* chunks of the `main`/`adminSettings` entrypoints
  and are picked up automatically by the base `@nextcloud/webpack-vue-config`
  runtime/manifest wiring, the same way pipelinq's and openregister's split
  chunks are.
- The `postbuild`/`postdev` → `../openregister/custom_apps/shillinq/js/` copy
  hook is unmodified; it copies whatever `js/` contains, so it continues to
  work with more/smaller files instead of fewer/larger ones.
- No backend (PHP) changes.

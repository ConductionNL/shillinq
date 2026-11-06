# Design: frontend-bundle-hygiene

## 1. ADR-061 decisions this change implements — quoted, not paraphrased

From `hydra/openspec/architecture/adr-061-frontend-bundle-and-boot-hygiene.md`:

**Decision 3 (Production builds ship no source maps; no dead deps):**
> Production `webpack.config.js` MUST NOT use `devtool: 'source-map'` (use
> `false` or a hidden/nosources variant) — pipelinq/openregister are the
> reference.

Both named reference apps set `devtool` to the literal `false`, not a hidden
variant — see `pipelinq/webpack.config.js:24` and
`openregister/webpack.config.js:13`, both `webpackConfig.devtool = isDev ?
'cheap-source-map' : false`, with the identical rationale in both files'
comments (memory/time cost of full source maps at build time, tens of MB of
`.map` files shipped). ADR-061 gives `false` OR a hidden/nosources variant as
acceptable; this change follows the two apps ADR-061 itself names as "the
reference" rather than introducing a third variant (`hidden-source-map` /
`nosources-source-map`) with no fleet precedent. `false` fully matches the
decision text's first-listed option.

**Enforcement section** (context, not implemented by this change):
> New hydra gate `frontend-bundle-hygiene`: fail on `devtool: 'source-map'`
> in a production webpack config; on a dependency declared in `package.json`
> with zero `import`/`require` in `src/`; on a full-barrel `import _ from
> 'lodash'`; and on a direct `vue-apexcharts` import when `CnChartWidget` is
> available.

That gate is hydra's own infrastructure (lives in the `conduction/hydra-gates`
package, not this app). This change makes shillinq's `webpack.config.js`
already compliant with the gate's `devtool` check; it does not implement the
gate itself, and this app has no `vue-apexcharts` import or full-barrel
`lodash` import to trip the other two checks (verified — see §4 Non-goals
below).

**Decision 2 (manifest reactivity)** is explicitly NOT implemented here — see
§5.

## 2. Why `splitChunks` needs a `chunks` predicate, not just cacheGroups

Webpack 5's `optimization.splitChunks.chunks` default is `'async'`: only
*dynamically*-imported modules are eligible for splitting into shared chunks.
Static (`import`) dependencies of an *initial* chunk (an entry point's own
bundle) are left exactly where they're imported. `webpackConfig.entry`
declares three top-level entries and none of them share code as a result:

| Entry | Bundle | Bundles its OWN copy of |
|---|---|---|
| `main` | `shillinq-main.js` | Vue, `@nextcloud/vue`, `@conduction/nextcloud-vue`, Pinia, `vue-router`, `@vueuse` |
| `adminSettings` | `shillinq-settings.js` | the same set, independently |
| `widget` | `widget.js` | the same set, independently — by design (see below) |

`@nextcloud/webpack-vue-config`'s base config sets only
`splitChunks.automaticNameDelimiter` — it never overrides `chunks`, so the
`'async'` default stands. This is the literal cause of the ADR-061 audit
finding: "NO `splitChunks` cacheGroups despite 4+ entry points."

**The fix has three layers**, all needed:

1. **Outer `splitChunks.chunks` predicate** — `(chunk) => chunk.name !==
   'widget'`. This decides which chunks are eligible for splitting AT ALL.
   `widget` must be excluded completely: it is a UMD bundle
   (`src/components/widget/WidgetEmbed.js`, REQ-WSW-004) partner sites embed
   with a single `<script src="https://.../widget.js">` tag — see the
   docstring at the top of that file for the four embed methods it serves.
   Splitting its framework code into `shillinq-shared-vendor.js` would mean a
   third-party page's one `<script>` tag stops working, because the widget
   would now `import` a second file the embedding page was never told to
   load. openregister already solved the identical shape of problem for its
   own `integrationGlobal` entry (injected on every NC page via
   `addInitScript` — same self-containment requirement, different reason) with
   the same predicate form: `chunks: (chunk) => chunk.name !==
   'integrationGlobal'` (`openregister/webpack.config.js:60`).

2. **Per-cacheGroup `chunks: 'initial'`** — this is the layer that protects
   the icons-bundle finding in §3. Without it, even after the outer predicate
   lets `main`/`adminSettings` through, a cacheGroup with no `chunks`
   override (or `chunks: 'all'`) would ALSO match modules pulled in by a
   dynamic `import()` reachable from those entries — sweeping nc-vue's own
   lazily-loaded chunks (the RVO icon set, the `cn-icons-mdi` bundle) into
   the new eager vendor chunk. pipelinq hit exactly this: its own comment at
   `webpack.config.js:269-274` documents "nc-vue's RVO icon set (~1.9 MB)
   landed here in full, loaded on every page, instead of being fetched when
   its picker tab is opened" before they added `chunks: 'initial'` to the
   cacheGroup. This change adopts the same value from the start rather than
   rediscovering the same regression.

3. **`minChunks: 2` on every cacheGroup — found by measuring, not by
   copying pipelinq.** pipelinq's own `ncVue`/`vendor` cacheGroups do not set
   `minChunks` (defaults to 1: any matching module in an eligible chunk gets
   extracted, whether or not it is actually used by more than one entry).
   The first version of this change copied that default and measured a real
   regression: `main` (595 pages, most of the nc-vue barrel reachable) and
   `adminSettings` (a handful of settings screens, a much smaller slice of
   the barrel) use very different-sized subsets of nc-vue. At `minChunks: 1`
   the cacheGroup extracted the UNION of both entries' usage into one 6.28 MB
   chunk that BOTH entries then had to load in full — `adminSettings`'s own
   entrypoint total went from 3.34 MiB to **8.44 MiB**, a straight
   regression, not the "not necessarily a win" the task brief warned about
   but an outright loss. `minChunks: 2` restricts extraction to modules
   actually reachable from *both* entries; each entry's exclusively-used
   modules stay where they were, exactly as before this change, and only
   genuinely duplicated code moves. §6 and `tasks.md` Validation record the
   corrected before/after numbers measured with this setting in place.
   pipelinq's own entries are more homogeneous (a set of dashboard widgets
   with much more overlapping nc-vue usage), which is presumably why the
   union-without-minChunks approach didn't visibly regress there — that does
   not carry over to shillinq's much more asymmetric `main`/`adminSettings`
   pair, and this is exactly why the pattern was verified by measuring this
   app's own build rather than trusted by analogy.

## 3. `shillinq-cn-icons-mdi.js` (~2.8 MB) — investigation finding

**Finding: this chunk is already correctly isolated. No change needed or
made to it.**

Traced the chunk name to its source:
`node_modules/@conduction/nextcloud-vue/src/components/CnIconBrowser/
CnIconBrowserPanel.vue:897`:
```js
import(/* webpackChunkName: "cn-icons-mdi" */ '@mdi/js')
```
This is a dynamic `import()` with an explicit webpack magic-comment chunk
name — nc-vue's own code, not shillinq's. It means:

- `@mdi/js` (the full icon-path package, ~7000+ named exports — needed
  because `CnIconBrowserPanel` is a *search-across-every-icon* picker UI, so
  it genuinely needs the whole set, not a tree-shaken subset) is ALREADY
  emitted as its own async chunk, separately from any entry's initial
  payload.
- Confirmed empirically: `shillinq-cn-icons-mdi.js` does NOT appear in
  webpack's own "entrypoint size limit" warning list (which enumerated only
  `main` and `adminSettings` — see `tasks.md` Validation for the exact
  build output), only in the separate "asset size limit" list. Assets appear
  there without being part of an entrypoint precisely when they are
  async-only chunks nothing initial pulls in directly.

Separately, shillinq's OWN icon usage (`src/icons.js`) is unrelated to this
chunk: it imports every icon it uses individually from
`vue-material-design-icons` (e.g. `import AccountArrowRightOutline from
'vue-material-design-icons/AccountArrowRightOutline.vue'`, ~270 named
imports, one `.vue` file per icon) — already the tree-shakeable, per-icon
import pattern ADR-061's lodash guidance asks for in spirit. `grep`ing
`src/` for `IconPicker`/`IconBrowser`/`CnIcon` finds only this file; no
shillinq component imports `CnIconBrowser`/`CnIconPicker` directly. The
`cn-icons-mdi` chunk exists in `js/` only because nc-vue's side-effecting
barrel (`"sideEffects": true`, ADR-061 root cause / decision 1 — a library
fix out of scope here) pulls `CnIconBrowserPanel.vue`'s *module* into
whichever entry imports the nc-vue barrel; the `@mdi/js` payload itself stays
behind the dynamic import regardless.

**Conclusion**: not dead code (it backs a real icon-search feature,
presumably reachable from some nc-vue-provided admin/config UI), not
tree-shakeable further (a search-the-whole-icon-set feature needs the whole
icon set), and already correctly chunk-split so it never loads unless that
feature is opened. The only way this change could have made it worse is the
`chunks: 'all'` mistake described in §2 point 2 — verified not to have
happened (Validation task 5).

## 4. Non-goals verified, not assumed

- **`vue-apexcharts` direct import**: `grep -r "vue-apexcharts" src/
  package.json` — zero matches. shillinq has no ApexCharts amplifier to fix.
- **Full-barrel lodash**: `grep -r "from 'lodash'" src/` and `grep -n
  '"lodash"' package.json` — zero matches; lodash is not even a declared
  dependency.
- **Dead/duplicated deps generally**: out of scope per the task brief (a
  `depcheck`-style audit); ADR-061's Amplifier 2 examples name other apps
  specifically, not shillinq.

## 5. Non-goal: ADR-061 decision 2 (manifest `markRaw`/`shallowRef`)

`src/main.js:112-135` documents, in its own comment block, a deliberate,
already-considered choice to wrap the built manifest in `reactive()` rather
than `markRaw`/`shallowRef`:

> `mergedManifest` is wrapped in `reactive()` so that the later in-place
> merge (`mergeFullFragmentIntoManifest`) is picked up by `CnPageRenderer`'s
> reactive `resolvedProps` computed (it reads `currentPage.config`; Vue 3's
> Proxy tracks the brand-new key too — see
> `src/utils/mergeFragmentIntoManifest.js` for the full contract).

This is the `shillinq-manifest-boot-payload-reduction` change's own lazy
fragment-loading mechanism (ships a slim manifest shell at boot; fetches each
fragment's full `config` only when the router first navigates into one of its
pages, then merges it into the already-mounted manifest in place). Switching
to `markRaw`/`shallowRef` — ADR-061 decision 2's literal ask — would make
that in-place merge invisible to Vue's reactivity system and silently break
lazy fragment loading; every lazily-loaded page's `config` would never render
after its fragment fetch resolves. This is a real, already-made trade-off
between two ADR-061 amplifiers (Amplifier 1's manifest-reactivity guidance
vs. this same app's own prior lazy-loading fix), not an oversight, and this
change does not revisit it. Per the task brief, this is called out as a
documented deviation and left alone.

## 6. What "the entrypoint total" means here, and what NOT to claim

The task's hazard note is explicit: "moving bytes into a second chunk that
the same page also loads is not a win." Two different reductions are at play
in this change and must not be conflated:

- **Source-map removal** is an unconditional win: `.map` files are never
  requested by a normal page load (only by a browser devtools session that
  explicitly opts in), so removing them from `js/` removes bytes nothing was
  shipping to a real user's page weight in the first place — it *does*
  reduce disk footprint, `postbuild` copy time, and (in the rare case a
  browser eagerly fetches source maps, e.g. some DevTools configurations)
  network bytes, but it does not change what a real end-user's page-load
  request count looks like.
- **`splitChunks` deduplication only wins for code genuinely shared across
  ≥2 entries — the naive union approach measured as a net loss.** The
  a-priori prediction was that `adminSettings` would shrink substantially
  because it "duplicates a full copy of the same framework code `main`
  already bundles." That prediction was WRONG, and disproving it by
  measuring (not asserting) is the whole reason §2 point 3 exists:
  `adminSettings` does NOT use anywhere near the same subset of nc-vue that
  `main` does (a handful of settings screens vs. 595 pages), so a
  `minChunks: 1` union chunk made `adminSettings` load code it never needed
  before — its entrypoint total went UP, from 3.34 MiB to 8.44 MiB, on the
  first build of this change. `minChunks: 2` (§2 point 3) corrects this by
  extracting only the modules actually reachable from both entries.
  `tasks.md`'s Validation section records the real measured before/after for
  every entrypoint with the corrected config — read those numbers, not this
  paragraph, as the claim of record. The general lesson: a shared-chunk
  cacheGroup must be validated per-entrypoint on the actual app, because
  whether it helps or hurts a given entry depends on how much that entry's
  own usage overlaps the chunk's contents, which is an empirical property of
  this app's code, not something a reference config from a differently-
  shaped app (pipelinq's more homogeneous multi-widget entries) guarantees
  by analogy.

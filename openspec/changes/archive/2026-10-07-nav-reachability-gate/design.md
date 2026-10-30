# Design: nav-reachability-gate

## 1. Never re-implement `buildManifest`

`@conduction/nextcloud-vue`'s `src/utils/buildManifest.js` is the single
shared pipeline every manifest-v2 app must use (ADR-044 decision 1: "No app
may re-implement `mergeMenuItems` / `applyMenuRelocations` /
`applyMenuRemovals` / `applySettingsSection` inline"). A gate that scores
reachability against a hand-rolled re-implementation of the merge semantics
would drift from the real pipeline the first time `buildManifest` changes
(exactly the failure mode this org's own working notes call out repeatedly:
"a re-implementation is a second copy waiting to drift"). This gate imports
and calls the real functions, full stop.

**Import mechanics (verified 2026-08-19).** `require('@conduction/nextcloud-
vue')` — the package barrel — fails under plain Node with
`ERR_PACKAGE_PATH_NOT_EXPORTED`, because the barrel's CJS build
(`dist/nextcloud-vue.cjs.js`) transitively pulls in `@nextcloud/vue` Vue
components that have no Node-resolvable `exports` map. The individual utility
module resolves fine on its own:

```js
const {
  buildManifest,
  mergeMenuItems,
  applyMenuRelocations,
  applyMenuRemovals,
  applySettingsSection,
} = await import('@conduction/nextcloud-vue/src/utils/buildManifest.js')
```

Verified against this repo's actual `src/manifest.json` + `src/manifest.d/*.json`
+ `src/menu-layout.json` (byte-identical copy in a sibling checkout with
`node_modules` installed): `buildManifest()` returns `{ pages: [...595], menu:
[...88 top-level] }`, matching the "595 pages, 29 top-level entries, 358 nav
items" baseline this change was commissioned against (the 358 figure is menu
NODES at all depths — 88 top-level + nested children + the 57
`settingsSection`-lifted items after flattening; see §3).

Use dynamic `import()` inside an async `main()`, not a synchronous
`require()` of the ESM file. Node's synchronous `require()`-of-ESM support
(confirmed working during design verification) is a recent, version-sensitive
feature; `import()` from a CJS-style script has been stable since Node 12 and
is what this repo's CI Node version is guaranteed to support without a flag.

**Fail closed, no structural-lint fallback.** `tests/validate-manifest.js`
degrades to a hand-written structural lint when Ajv or the schema can't be
resolved — a legitimate choice there, because a schema-shape lint is a
genuinely different, independently useful check. There is no equivalent
fallback here: a re-implemented merge/reachability pass IS the thing this
design refuses to ship. If `@conduction/nextcloud-vue/src/utils/
buildManifest.js` cannot be resolved (checked at `node_modules/@conduction/
nextcloud-vue/...` first, then `../nextcloud-vue/src/utils/buildManifest.js`
as a sibling-worktree fallback, matching `validate-manifest.js`'s candidate-
list pattern), the script MUST exit 1 with a clear "gate cannot run without
the real pipeline" message — mirroring gate 30's own fail-closed posture
("mirroring the fleet-sweep guard that refuses to run fail-open without Ajv").

## 2. The reachability relation (precise definition)

Given the effective manifest `{ pages, menu } = buildManifest(base, fragments,
menuLayout)`:

**Base case — direct nav reachability.** Walk `menu[]` recursively through
`children[]` (arbitrary depth in the data model; measured max depth in this
repo today is 2 — one top-level group, one leaf level — matching
`CnAppNav`'s documented one-level rendering, but the walk does not hard-code
that limit). Every node carrying a `route` field whose value matches a
`pages[].id` is added to the reachable set. This covers ordinary menu leaves,
`settingsSection`-lifted flat entries (`buildManifest` tags them `section:
"settings"` but they keep their `route`), and fixed entries like the
`FeaturesRoadmap` roadmap page (ADR-018) — anything that ends up as a `menu[]`
node with a route, regardless of which layout mechanism put it there, counts.
`href` / `action` nodes are not page routes and are skipped.

**Inductive case — parent/child page-link closure.** A page's `config` block
uses five fields to link to another page, confirmed by scanning every page in
this repo's effective manifest: `indexRoute` (detail → its index, present on
231 of 240 detail pages), `detailRoute` (index → its detail, present on 247 of
276 index pages), and `rowRoute` / `clickRoute` / `viewAllRoute` (dashboard/
report/custom pages linking to a target page). If page `P` is reachable and
`P.config[field]` (for `field` in that five-name set) names another page's
`id`, that page is reachable too. This is intentionally symmetric in effect —
a reachable detail page makes its index reachable via `indexRoute`, and a
reachable index page makes its detail reachable via `detailRoute` — because
both directions are genuinely one click apart in the rendered UI (breadcrumb
back-link / row click respectively).

**Fixed point.** Repeat the inductive step until no new page is added.
Converges in at most `|pages|` passes; in practice near-instant for 595 pages
each contributing ≤5 candidate edges.

```js
const LINK_FIELDS = ['indexRoute', 'detailRoute', 'rowRoute', 'clickRoute', 'viewAllRoute']

function computeReachable(manifest) {
  const pageIds = new Set(manifest.pages.map((p) => p.id))
  const reachable = new Set()

  ;(function collectMenuRoutes(nodes) {
    for (const n of nodes) {
      if (n.route && pageIds.has(n.route)) reachable.add(n.route)
      if (Array.isArray(n.children)) collectMenuRoutes(n.children)
    }
  })(manifest.menu)

  let changed = true
  while (changed) {
    changed = false
    for (const page of manifest.pages) {
      if (!reachable.has(page.id)) continue
      const cfg = page.config || {}
      for (const field of LINK_FIELDS) {
        const target = cfg[field]
        if (target && pageIds.has(target) && !reachable.has(target)) {
          reachable.add(target)
          changed = true
        }
      }
    }
  }

  return { reachable, orphans: [...pageIds].filter((id) => !reachable.has(id)) }
}
```

**Explicit non-goal:** OpenRegister's runtime `openregister-related-objects` /
`openregister-related-list` sidebar widgets link to whatever object happens to
be related at query time — not a static page-to-page edge the manifest can
express. This relation deliberately does not attempt to model them; a page
reachable ONLY through a live related-object link needs a baseline entry (§4),
not a special-cased static rule.

## 3. What the relation found at HEAD (2026-08-19 measurement)

Running the algorithm above against this repo's real effective manifest
(sibling checkout, real `buildManifest`, `removals: []`) found **34** orphaned
page ids. They fall into two rough categories on inspection of each page's
`type` and `config` — this categorization is evidence for scoping the ratchet
baseline (§4), not something this change resolves:

- **Seven index/detail pairs (14 ids) with a plausible real gap**: the index
  page itself has no menu entry anywhere in the effective manifest, so neither
  it nor its detail page (linked only via `indexRoute`/`detailRoute` to each
  other) is reachable. `RateCards`/`RateCardDetail`, `RateSchedules`/
  `RateScheduleDetail`, `RateAuditTrail`/`RateRecordDetail`,
  `AansluitingResultaten`/`AansluitingResultDetail`, `WBSOActivityCodes`/
  `WBSOActivityCodeDetail`, `Deposits`/`DepositDetail`,
  `InnovatieboxElections`/`InnovatieboxElectionDetail`. `SubsidieAanvragen` and
  `SubsidieTerugvorderingen` (2 more index pages, same shape) share their
  `detailRoute` target `SubsidieDetail` with a third, menu-reachable index
  page, so `SubsidieDetail` itself is NOT orphaned — only the two filtered
  index views are. `AccountingStandardsPolicy` and `BewaartermijnenDashboard`
  are two more single (non-paired) pages with no menu entry.
- **Entry points that are plausibly NOT meant to be menu-reachable (18 ids)**:
  `type: "form"` create-dialog pages (`VATReturnCreateDialog`,
  `ReimbursementPolicyCreateDialog`, `PassThroughMarkupRuleCreateDialog`,
  `RetainerPoolCreateDialog` — opened from an action button on their owning
  index page, not from nav), mobile-scanner action pages
  (`MobileScannerReceive/Transfer/Pick/Count`), and pages reached via an
  external link or another page's row click rather than the main menu
  (`BookingsCalendarView`, `BookingsForm`, `BookingsConfirmationPortal` — a
  customer-facing link, not app nav at all — `GoodsReceiptNoteDetail`,
  `VendorPerformanceDetail`, `BillableInvoiceDetail`, `OrderDetail`).

This split is informed, not verified line-by-line against every calling
component — that verification is exactly the baseline-seeding task handed to
the implementer in `tasks.md`, not resolved here.

**Caveat on the exact count.** This measurement ran against a byte-identical
copy of this repo's manifest inputs in a sibling checkout (this repo's own
`node_modules` is not installed). The implementer MUST re-run the script
against a real `npm ci` in this repo before seeding the baseline — the count
may drift by the time this change is implemented as fragments continue to
land.

## 4. Ratchet baseline — the mechanism that keeps the gate usable

A gate that is red from day one because of legitimate non-nav pages gets
disabled, not fixed (this repo's own `check:seeds` / `check:fragment-required`
scripts hit exactly this problem and solved it the same way: "the baseline is
a hard ceiling that may only be lowered"). This gate needs a per-id mechanism,
not just a count, because requirement 4 (failure output) must name which ids
are new versus already-known.

`tests/nav-reachability-baseline.json`:

```json
{
  "_meta": {
    "spdx-license": "EUPL-1.2",
    "spdx-copyright": "2026 Conduction B.V.",
    "description": "Page ids the reachability check (tests/validate-nav-reachability.js) currently finds unreachable from any menu entry, each with why it is not a regression. Entries may only be REMOVED (a page becomes reachable, or is deleted) or have their reason corrected — never added without the id first appearing as a genuinely new, justified exception. A NEW orphan not listed here fails the gate."
  },
  "exceptions": {
    "VATReturnCreateDialog": "type:form dialog opened from the VAT Returns index page's action button, not menu-reachable by design",
    "BookingsConfirmationPortal": "customer-facing confirmation link sent by email; not part of app navigation"
  }
}
```

**Gate logic:**
1. Compute `orphans` via §2 against the current effective manifest.
2. `newOrphans = orphans.filter(id => !(id in baseline.exceptions))`.
3. `staleExceptions = Object.keys(baseline.exceptions).filter(id =>
   !orphans.includes(id))` — an id in the baseline that is no longer orphaned
   (fixed, or deleted). Report as a WARN, not a failure — the ratchet may only
   tighten, and a human should prune it, but a stale entry does not itself
   indicate a regression.
4. Exit 1 if `newOrphans.length > 0`; exit 0 otherwise (stale-exception warns
   still print but do not fail the build).

This makes the negative fixture (§5) and the real-world regression case
identical in shape: any id that becomes orphaned without an accompanying,
reason-bearing baseline entry fails the build.

## 5. Cause attribution on failure

Requirement 4 (the caller's brief) asks for "the orphaned page ids + which
removal/relocation orphaned them." Diffing against git history is one option
but couples the gate to git plumbing and a base-ref convention this repo's
`check:*` scripts don't otherwise need (they're not diff-scoped — see §6).
Instead, replay the SAME real library functions stage by stage and see at
which stage each orphan's reachability was lost — no git required, and every
stage is a function `buildManifest` itself calls internally, re-exported
individually:

```js
const preLayout = { menu: [] }
mergeMenuItems(preLayout.menu, base.menu || [])
for (const frag of fragments) {
  if (Array.isArray(frag.menu)) mergeMenuItems(preLayout.menu, frag.menu)
}
// preLayout.menu now equals buildManifest()'s own `merged.menu` immediately
// before it calls applyMenuLayout() — the exact intermediate state.

const afterRelocations = applyMenuRelocations(clone(preLayout.menu), menuLayout.relocations)
const afterRemovals = applyMenuRemovals(clone(afterRelocations), menuLayout.removals)
const afterSettings = applySettingsSection(clone(afterRemovals), menuLayout.settingsSection)
// afterSettings === the final effective menu buildManifest() would return.
```

For each `newOrphan`, compute reachability (§2's algorithm, reusable against
any `{ pages, menu }` shape) at each of the four stages and report the first
stage where it drops out:
- Unreachable already at `preLayout` → **"no menu entry in any base/fragment
  `menu[]`"** (a pre-existing gap, not caused by `menu-layout.json`).
- Reachable at `preLayout`, not at `afterRelocations` → **"relocations"**
  (moved under a target group that dissolved, or a relocation chain that
  dead-ended — `applyMenuRelocations` already handles a missing target by
  keeping the node at top level, so this fires only for a genuine structural
  loss, e.g. a relocated group whose children collided and one shadowed
  another).
- Reachable at `afterRelocations`, not at `afterRemovals` → **"removals"** —
  `menu-layout.json#removals` retired the only entry reaching this page. This
  is the case gate 30 already partially covers (§ Why in `proposal.md`); this
  attribution makes the SAME finding visible in this gate's own report
  without depending on gate 30 having run.
- Reachable at `afterRemovals`, not at `afterSettings` → **"settingsSection"**
  (should be rare — `applySettingsSection` relocates, it doesn't delete — but
  report it if it ever happens rather than assuming it can't).

## 6. CI wiring

Follows the existing `frontend-checks` leg pattern exactly (each leg is a
self-contained `node` script, run in its own fresh-checkout job by the shared
`quality.yml` workflow):

```yaml
frontend-checks: '["check:manifest", "check:manifest-budget", "check:markers", "check:registers", "check:seeds", "check:fragment-required", "check:nav-reachability", "test:l10n", "format"]'
```

No diff-scoping: like its `check:*` siblings, this runs in full every time
(the ratchet baseline is what keeps it fast to reason about and cheap to
pass, not a diff filter). `npm run check:nav-reachability` → `node tests/
validate-nav-reachability.js`.

## 7. Relationship to hydra gate 30

Both build the same effective manifest via the same real `buildManifest()`
call. They are not redundant:

| | gate 30 (`hydra-gate-effective-manifest-crossref`) | this gate (`check:nav-reachability`) |
|---|---|---|
| Where it runs | hydra's shared gate package, PR-time (builder/reviewer), diff-scoped to manifest-input paths | this repo's own CI, always |
| What it checks re: reachability | ONLY ids literally present in `menu-layout.json#removals` | EVERY page in the effective manifest |
| Misses | a menu node deleted/re-homed directly in a fragment's `menu[]`, never touching `removals[]` — the likely shape of `nav-six-clusters` | (by design, nothing in scope — see §2's one explicit non-goal) |
| Also checks | menu-route→page-id, action targets, register/schema slug resolution, deepLink correspondence (unrelated to reachability) | nothing else — single-purpose |

This change does not touch gate 30, hydra, or `@conduction/nextcloud-vue`.

## 8. Open questions for the implementer

1. **Baseline seeding.** The 34 ids in §3 are a starting hypothesis from a
   single measurement pass, not a verified triage. Each entry needs its
   `config`/calling component actually read before it earns a baseline
   `reason` string — do not bulk-copy the §3 list into the baseline file
   without that check.
2. **Whether the 7 index/detail pairs in §3 are worth fixing now versus
   accepting as pre-existing debt with a baseline reason** is a product call
   this change does not make — flagging them is the point of the gate.
3. **`pageTemplates`/`pageInstances` scaffold-expanded pages** (manifest-
   entity-scaffold-templating) are not present in this repo's manifest today;
   `buildManifest()` materializes them before returning, so the reachability
   algorithm sees only concrete pages either way — no special handling should
   be needed, but there is no current fixture to prove it.

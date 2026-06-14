# Design — shillinq Nav / IA Cleanup

## Scope boundary

This change touches **only** navigation labels, navigation layout, the nav
active-state matcher, and one dashboard widget's card chrome. It adds **no**
schema, **no** new page, **no** new route, and renames **no** route. Every
edit is reversible and behaviour-preserving for deep links and existing e2e
specs. The intent is to make a correct app *read* as correct.

## Decision 1 — relabel `Journals` → "Manual Journals" / "Memoriaalboekingen"

### Why a label, not a merge
`GeneralLedger`→`GLTransaction` and `Journals`→`JournalEntry` are two distinct
layers, verified against the model:

- `GLTransaction` is the **immutable, system-materialised** double-entry
  ledger. Nothing is hand-keyed here; every line is the consequence of a
  posting elsewhere.
- `JournalEntry` is the **human-authored** memoriaalboeking. Posting a journal
  entry materialises **exactly ONE** balanced `GLTransaction`
  (REQ-JE-001/007). It is the *source*, the GL is the *consequence*.

So they must NOT be merged. The defect is purely the word "Journals" sitting
next to "General Ledger" reading as "two ledgers". The Dutch accounting term
*memoriaalboeking* is the precise name for a manual journal, and "Manual
Journals" is its standard English rendering.

### What changes
- `src/manifest.json`: the Bookkeeping menu entry `id: "Journals"` —
  `"label": "Journals"` → `"label": "Manual Journals"`.
- `src/manifest.json`: the `Journals` **page** `"title": "Journals"` →
  `"title": "Manual Journals"` (page id `Journals`, line ~4418).
- i18n: add source string `"Manual Journals"` with nl translation
  `"Memoriaalboekingen"` (i18n keys are the ENGLISH source string per the
  fleet i18n rule). The Dutch label rides on the existing l10n pipeline.

### What does NOT change
- Menu id `Journals`, route `Journals`, page id `Journals`, `indexRoute`
  `Journals`, schema `JournalEntry`. No route or deep link breaks.

### Alternative rejected
*Merge the two leaves under one "Ledger" group.* Rejected — it would hide the
manual-entry surface behind the immutable ledger and contradict
REQ-JE-001/007, where the journal is the authored source of the GL.

## Decision 2 — single nav home for `Project` (People & Projects wins)

### The duplicate
`Project` is bound by two pages reachable from two nav locations:

| Nav path | Group id | Leaf id | Page | Binds |
|---|---|---|---|---|
| Bookkeeping → Projects | `Bookkeeping` | `Projects` | `Projects` | `Project` |
| People & Projects → Overzicht | `Projecten` | `ProjectenOverzicht` | `ProjectenOverzicht` | `Project` (+ `Tarieven`, `Utilisatie`) |

### Why "People & Projects" is the canonical home
The root `Projecten` group already clusters the consultancy-project context —
rate cards (`Tarieven`) and utilisation (`Utilisatie`) — that an
RJ270/IFRS15 revenue-recognition `Project` needs alongside its overview. The
Bookkeeping `Projects` leaf is a bare second entry point with no surrounding
context. Keep the contextual home; retire the bare one.

> Note: this does NOT touch `CostProjects` (schema `CostProject`, the
> *analytical* management-accounting project register) — that is a distinct
> register from the RJ270 `Project` and keeps its own Bookkeeping leaf.

### What changes
- `src/menu-layout.json`: add `"Projects"` to the existing `removals` array
  (currently `["Consolidations", "Verplichtingen"]`). `applyMenuRelocations`
  in `main.js` drops the duplicate Bookkeeping menu entry AFTER fragments
  merge.

### Deep-link preservation (the established `removals` contract)
Per the `_meta` contract already documented in `menu-layout.json`:
*"removals: leaf menu-entry ids retired as duplicate navigation — their PAGES
stay routable for deep links and e2e specs."* The `Projects` **page** (route
`Projects`, `/…/projects`) remains fully registered and reachable; a saved
bookmark, an inbound deep link, or an e2e test hitting route `Projects` still
resolves. Only the redundant left-nav entry disappears. No `relocations` entry
is needed because the canonical home (`ProjectenOverzicht`) already exists.

### Alternative rejected
*Keep Bookkeeping → Projects, remove People & Projects.* Rejected — that would
orphan `Tarieven` / `Utilisatie` from their project context and break the
consultancy IA grouping.

## Decision 3 — fix double-active nav state in Purchasing & Inventory

### Symptom & root cause
Opening either detail view lights up two leaves at once. The two detail routes
are siblings under `/inkoop`:

- `PurchaseOrders` index `/inkoop/purchase-orders` → `PurchaseOrderDetail`
  `/inkoop/purchase-orders/:id`
- `GoodsReceipts` index `/inkoop/goods-receipts` → `GoodsReceiptDetail`
  `/inkoop/goods-receipts/:id`

The nav active-state resolver marks a leaf active when the current path
*starts with* the leaf's route path. Because both leaves share the `/inkoop`
prefix and the renderer evaluates the active class per ancestor segment, a deep
detail path can satisfy the prefix test for more than one leaf (and, where a
detail route is also reachable under a second registration, both detail leaves
match). This is the same family as shillinq's earlier **duplicate-route-name
404** — two nav targets resolving to overlapping paths — which was fixed by
postfix-disambiguating the route name.

### The fix (specified, not implemented here)
1. **Exact-match active state.** The active-state resolver MUST mark a leaf
   active only when the current route's *owning leaf id* equals that leaf
   (exact route identity), or when the active route's declared parent index
   equals the leaf's route — NOT on bare path-prefix string matching. A
   detail page maps to its index leaf via its `indexRoute` /
   `detailRoute` binding, so exactly one nav leaf is active.
2. **Unique route registration.** Confirm each detail route
   (`PurchaseOrderDetail`, `GoodsReceiptDetail`) is registered exactly once,
   with a unique `id` and `route` — no second registration of the same detail
   route under a different leaf.

### What changes
- The manifest-v2 nav renderer active-state logic (`main.js` /
  `applyMenuRelocations` neighbourhood, or the CnAppNav active-class binding) —
  exact route identity, not prefix.
- `src/manifest.json`: verify/repair the `detailRoute` ↔ `indexRoute` binding
  on the `PurchaseOrders`/`PurchaseOrderDetail` and
  `GoodsReceipts`/`GoodsReceiptDetail` page pairs so each detail resolves to a
  single owning index leaf.

## Decision 4 — kill cards-in-cards on the Cashflow Forecast dashboard

### Symptom & root cause
`CashflowDashboard` (`/cashflow/dashboard`, `type: dashboard`) declares
`buffer-status` as a `type: stats-block` widget. The manifest-v2 dashboard
renderer wraps EVERY declared widget in a `CnWidgetWrapper` (card chrome:
border + padding + title bar). `CnStatsBlock` itself already renders a card.
Result: a KPI tile inside a card inside a card — double chrome. This is the
hydra dashboard-antipattern family (hydra-gate-dashboard-antipattern,
hydra#316) at the widget-tile level.

### The fix (specified)
For the `stats-block` widget type the renderer MUST place the `CnStatsBlock`
tile **directly on the dashboard grid surface** — i.e. NOT wrap a stats-block
in the outer `CnWidgetWrapper` card. Stat tiles carry their own card chrome;
the wrapper is redundant only for self-carding widget types (stats-block),
while `table` / `chart` widgets keep the wrapper. The fix is renderer-level
(a `noWrapper` / self-carding flag keyed on widget `type`), so it corrects
every shillinq dashboard's stats-block at once rather than per page.

### Audit requirement
Other shillinq `type: dashboard` pages also declare `stats-block` widgets
(e.g. the Stock Levels dashboard `auto-po-pending`, and the financial
overview `Dashboard`). The change adds a requirement to audit every
`type: dashboard` page for `stats-block` widgets and confirm none renders a
double card after the renderer fix.

### Alternative rejected
*Change `CnStatsBlock` to drop its own card.* Rejected — `CnStatsBlock` is a
shared `@conduction/nextcloud-vue` component used card-first across the fleet
(e.g. `BBVKPICards`); changing it would regress every other consumer. The
correct seam is the dashboard renderer's wrapper decision.

## Migration / rollout

No data migration — there is no schema or object change. No `lib/Repair` step.
Rollout is a frontend rebuild + l10n recompile:

1. Apply the `manifest.json` label/title edits and the `menu-layout.json`
   `removals` addition.
2. Apply the renderer fixes (active-state matcher; stats-block wrapper flag).
3. `npm run build`; recompile l10n so `Memoriaalboekingen` ships.
4. ⚠️ shillinq's built bundle has **no `?v=` cache-buster** (recorded gotcha):
   end users need Ctrl+Shift+R; verification fetches must use no-store / a fresh
   browser context to avoid reading the stale bundle.

## Risks

- **Stale-bundle false negative** — the no-`?v=` gotcha can make a correct
  rebuild look unchanged in a warm browser. Mitigate with no-store verification.
- **Active-state over-correction** — making the matcher *too* exact could leave
  a detail page with NO active leaf. Mitigate: a detail route resolves to its
  index leaf via `indexRoute`/`detailRoute`, so exactly one leaf is active.
- **Deep-link regression on `Projects` removal** — guarded by the `removals`
  contract (page stays routable) and an explicit e2e requirement
  (REQ-NAVIA-006) that the deep link still resolves.
- **i18n drift** — the nl label must be keyed on the English source string
  `"Manual Journals"`, not on Dutch, or external translators can't contribute.

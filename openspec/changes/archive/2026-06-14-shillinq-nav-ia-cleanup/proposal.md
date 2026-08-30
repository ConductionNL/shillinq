# Proposal: shillinq-nav-ia-cleanup

`kind: config + ui` per ADR-037 (modular-config-fragments + canonical nav
layout in `src/menu-layout.json`) and the hydra dashboard-antipattern family
(hydra#316). Four pure information-architecture / UI corrections to the
**existing** shillinq menu and dashboard surface — **no new domain entities,
no new register schemas, no new pages, no route renames**. Two are label /
nav-layout edits (`src/manifest.json` label, `src/menu-layout.json` removal),
two are renderer-level UI fixes (nav active-state matcher, dashboard widget
card nesting). Per ADR-012 (deduplication) the explicit purpose of items 1 and
2 is to *remove* duplicate-looking navigation, not add capability.

## Summary

Today the shillinq menu and dashboards carry four cosmetic / IA defects that
make a correct app read as buggy or redundant:

1. **"Journals" reads as a duplicate of "General Ledger".** The Bookkeeping
   group shows both `GeneralLedger` (schema `GLTransaction` — the immutable,
   system-materialised double-entry ledger) and `Journals` (schema
   `JournalEntry` — the human-authored *memoriaalboeking* whose posting
   materialises exactly ONE balanced `GLTransaction`, REQ-JE-001/007). They
   are genuinely different layers, but the label "Journals" next to "General
   Ledger" reads as two ledgers. **Relabel the menu entry and page title to
   "Manual Journals" (nl: "Memoriaalboekingen")** — label-only; the menu id
   `Journals`, the route `Journals`, and the schema `JournalEntry` are all
   unchanged.

2. **`Project` has two navigation homes.** The same `Project` schema is
   reachable from BOTH the Bookkeeping group leaf `Projects`
   (page `Projects`, route `Projects`) AND the root "People & Projects" group
   `Projecten` → `ProjectenOverzicht` (page `ProjectenOverzicht`, also binding
   `Project`, alongside `Tarieven` / `Utilisatie`). Two nav homes for one
   entity is a navigation duplicate. **Make "People & Projects" the canonical
   project home** (it already groups the rate-card / utilisation context a
   consultancy project needs) and retire the Bookkeeping `Projects` leaf via a
   `src/menu-layout.json` `removals` entry. Per the established `removals`
   pattern the `Projects` *page* stays routable for deep links and e2e specs;
   only the duplicate menu entry disappears.

3. **Double-active nav highlight in Purchasing & Inventory.** Opening a goods
   receipt or a purchase order renders BOTH the `GoodsReceiptDetail` and the
   `PurchaseOrderDetail` nav state as active at once. Root cause is a
   prefix-style active-state match: the two detail routes
   (`/inkoop/goods-receipts/:id` and `/inkoop/purchase-orders/:id`) live under
   sibling `/inkoop/...` paths and the active-state resolver matches on path
   *prefix* rather than the leaf's own route, so a deep route lights up more
   than one ancestor leaf. Specify an exact / unique active-state match — the
   same class of bug as shillinq's earlier duplicate-route-name 404
   (postfix-disambiguation fix, recorded in the dashboard/route gotchas).

4. **Cards-in-cards on the Cashflow Forecast dashboard.** The `CashflowDashboard`
   page (route `/cashflow/dashboard`, `type: dashboard`) declares its
   `buffer-status` KPI as a `stats-block` widget. The manifest-v2 dashboard
   renderer wraps every widget in a `CnWidgetWrapper` card, and `CnStatsBlock`
   draws its own card chrome — so the KPI tile renders with double card
   borders/padding. Specify that `stats-block` tiles sit directly on the
   dashboard surface (suppress the outer wrapper card for the stats-block widget
   type), and add a requirement to audit shillinq's other `type: dashboard`
   pages for the same nesting. This is the dashboard-antipattern family
   (hydra-gate-dashboard-antipattern, hydra#316) applied to KPI tiles.

**Depends on:**
- `bookkeeping-journal-entries` (the `JournalEntry` / memoriaalboeking model and
  the GL-vs-journal distinction — REQ-JE-001/007; relabel target)
- `bookkeeping-general-ledger` (the `GLTransaction` ledger the relabel
  disambiguates against)
- The root "People & Projects" group (`Projecten` / `ProjectenOverzicht`) and the
  Bookkeeping `Projects` page — both already exist (consolidated register)
- `bookkeeping-purchase-order-3way-*` (the `PurchaseOrders` / `GoodsReceipts`
  index + detail pages whose nav state is being corrected)
- `zzp-cashflow-13wk` (the `CashflowDashboard` page + `CashflowBufferPolicy` /
  `CashflowForecastHorizon` widgets — REQ-CF-015)
- ADR-037 (`src/menu-layout.json` canonical layout, `src/manifest.d/*` fragments)
- hydra#316 dashboard-antipattern gate (the cards-in-cards class)

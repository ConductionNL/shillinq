# Tasks — shillinq Nav / IA Cleanup

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm NO new register schema is introduced — this change edits only
      labels, a manifest.d fragment, and e2e tests. No `lib/Settings/register.d/*.json`
      fragment is added or modified.
- [x] Confirm `GeneralLedger`→`GLTransaction` (immutable system ledger) and
      `Journals`→`JournalEntry` (human-authored memoriaalboeking, materialises
      one GLTransaction — REQ-JE-001/007) are genuinely distinct layers and
      MUST NOT be merged; the relabel only disambiguates the menu wording.
- [x] Confirmed: `Project` (RJ270/IFRS15 revenue-recognition register) now has
      a single canonical nav home: `Bookkeeping > Projects`. The earlier
      `ProjectenOverzicht` home and `CostProjects` were both removed via the
      retire-cost-project change (both are in `menu-layout.json` removals).
      See Phase 2 disposition note.
- [x] Confirm the `Projects` page stays routable after its `ProjectenOverzicht`
      sibling nav entry is removed — the `removals` contract in `menu-layout.json`
      retires the nav leaf while keeping the page route intact.
- [x] Confirm `PurchaseOrderDetail` / `GoodsReceiptDetail` and the
      `CashflowDashboard` `stats-block` widget already exist — no new page or
      widget is created; only their nav/render behaviour is corrected.

## Phase 1: Relabel Journals → Manual Journals (REQ-NAVIA-001) ✓ DONE

- [x] `src/manifest.json` — Bookkeeping menu entry `id: "Journals"`:
      `"label": "Journals"` → `"label": "Manual Journals"`.
- [x] `src/manifest.json` — page `id: "Journals"` (~line 4332):
      `"title": "Journals"` → `"title": "Manual Journals"`.
- [x] Confirmed: menu id `Journals`, route `Journals`, `indexRoute` `Journals`,
      and schema `JournalEntry` are all unchanged — label-only.
- [x] i18n: added source string `"Manual Journals"` (English key) with
      nl=`"Memoriaalboekingen"`, de/fr/es/it translations to all five language
      files. Keys use the ENGLISH source string per fleet convention.

## Phase 2: Single nav home for Project (REQ-NAVIA-002 / 003) ✓ SATISFIED BY retire-cost-project

**DISPOSITION NOTE:** The original plan (add `"Projects"` to removals → retire
Bookkeeping `Projects` leaf, make `ProjectenOverzicht` the canonical home) is
SUPERSEDED by the merged retire-cost-project change which:
  1. Added `"ProjectenOverzicht"` to `menu-layout.json` removals (removes the
     People & Projects overview leaf — which was the intended canonical home)
  2. Added `"CostProjects"` to removals (removes the CostProject schema leaf)

After retire-cost-project the `Project` schema has EXACTLY ONE nav home:
`Bookkeeping > Projects`. The `ProjectenOverzicht` page is still routable
(deep links / e2e resolve) but has no menu entry, satisfying REQ-NAVIA-003.

**DO NOT add `"Projects"` to removals** — that would orphan `Project` entirely
(zero nav homes left). REQ-NAVIA-002 and REQ-NAVIA-003 are satisfied by
retire-cost-project's removals, not by this change.

- [x] Verified `menu-layout.json` contains `"CostProjects"` and `"ProjectenOverzicht"`
      in the removals array (retire-cost-project already committed).
- [x] Verified `Bookkeeping > Projects` leaf still resolves the `Project` schema.
- [x] No additional `menu-layout.json` edit needed for this change.

## Phase 3: Fix double-active nav state (REQ-NAVIA-004 / 005) ✓ DONE

Root cause identified: `src/manifest.d/bookkeeping-purchase-order-3way-02-core.json`
declared a top-level `PurchaseOrders` group (not nested inside `Inkoop`). Because
`mergeMenuItems` only finds items at the same level, this created a SECOND top-level
`PurchaseOrders` group (alongside the correct `Inkoop > PurchaseOrders` leaf). The
duplicate group had `PurchaseOrderDetail` as a hidden child; when on the
`PurchaseOrderDetail` route the duplicate group lit up as having an active child,
producing the double-active state.

Fix: wrap the fragment's `PurchaseOrders` entry inside an `Inkoop` parent group so
`mergeMenuItems` finds and extends the existing `Inkoop > PurchaseOrders` leaf
rather than appending a new root-level group.

- [x] Fixed `src/manifest.d/bookkeeping-purchase-order-3way-02-core.json`:
      moved the `PurchaseOrders` children block inside an `Inkoop` parent group
      so it merges at the correct depth (no duplicate root-level group).
- [x] CnAppNav `isActive` already does exact route-name matching
      (`this.$route?.name === item.route`); no library change needed.
- [x] Confirmed `PurchaseOrders`↔`PurchaseOrderDetail` and
      `GoodsReceipts`↔`GoodsReceiptDetail` each bind via a single
      `indexRoute`/`detailRoute` pair with unique route id and path.
- [x] After fix, only one nav leaf is highlighted when on PO detail or GRN detail.

## Phase 4: Kill cards-in-cards on Cashflow Forecast dashboard (REQ-NAVIA-007 / 008) ✓ VERIFIED FIXED IN LIB

**AUDIT RESULT:** `@conduction/nextcloud-vue` beta.101 (installed) already renders
`stats-block` widgets in `CnDashboardPage` WITHOUT a `CnWidgetWrapper` outer card:

```html
<!-- Stats-block widget — ... Rendered WITHOUT CnWidgetWrapper: CnStatsBlock
     already supplies title + bordered card chrome. -->
<template v-else-if="isStatsBlock(item)">
    <CnStatsBlockWidget v-bind="getStatsBlockProps(item)" ... />
</template>
```

There is no cards-in-cards defect in the currently installed library. All `type:
"dashboard"` pages with `stats-block` widgets (CashflowDashboard, BewaartermijnenDashboard,
StockLevelsDashboard, CashflowForecast, GroupLiquidityDashboard, TreasuryDashboard,
Iv3Aanlevering) render single-card chrome via `CnStatsBlockWidget` directly.

- [x] Audited all 13 `type: "dashboard"` pages in the merged manifest; 7 carry
      `stats-block` widgets — none double-wraps because the lib handles it natively.
- [x] `CnStatsBlock` component is NOT modified (correct — the fix lives in
      `CnDashboardPage`'s renderer, which is in the library, already resolved).
- [x] No app-level code change needed for this item.

## Phase 5: Build, verify, gates

- [ ] `npm run build`; recompile l10n.
- [ ] Verify via no-store fetch / fresh browser context (shillinq bundle has no
      `?v=` cache-buster — recorded gotcha; warm browsers read stale).
- [ ] Run hydra gates (scripts/run-hydra-gates.sh if present, else manual key gates).
- [ ] e2e: assert (a) menu shows "Manual Journals"; (b) one nav leaf active on
      PO/GRN detail; (c) `Projects` deep link resolves (Bookkeeping leaf present);
      (d) stats-block KPI tiles render single-card chrome.

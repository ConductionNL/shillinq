# Tasks — shillinq Nav / IA Cleanup

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm NO new register schema is introduced — this change edits only
      labels, `menu-layout.json`, the nav active-state matcher, and one
      dashboard widget's card chrome. No `lib/Settings/register.d/*.json`
      fragment is added or modified.
- [ ] Confirm `GeneralLedger`→`GLTransaction` (immutable system ledger) and
      `Journals`→`JournalEntry` (human-authored memoriaalboeking, materialises
      one GLTransaction — REQ-JE-001/007) are genuinely distinct layers and
      MUST NOT be merged; the relabel only disambiguates the menu wording.
- [ ] Confirm `Project` (RJ270/IFRS15 revenue-recognition register) already has
      a contextual home under root group `Projecten` / page `ProjectenOverzicht`
      (with `Tarieven` + `Utilisatie`); the Bookkeeping `Projects` leaf is the
      redundant second entry point. Confirm `CostProjects`→`CostProject`
      (analytical management-accounting register) is NOT affected.
- [ ] Confirm the `Projects` page stays routable after nav removal (the
      documented `removals` contract in `menu-layout.json` `_meta`) — no deep
      link or e2e spec breaks.
- [ ] Confirm `PurchaseOrderDetail` / `GoodsReceiptDetail` and the
      `CashflowDashboard` `stats-block` widget already exist — no new page or
      widget is created; only their nav/render behaviour is corrected.

## Phase 1: Relabel Journals → Manual Journals (REQ-NAVIA-001)

- [ ] `src/manifest.json` — Bookkeeping menu entry `id: "Journals"`:
      `"label": "Journals"` → `"label": "Manual Journals"`.
- [ ] `src/manifest.json` — page `id: "Journals"` (~line 4418):
      `"title": "Journals"` → `"title": "Manual Journals"`.
- [ ] Leave menu id `Journals`, route `Journals`, `indexRoute` `Journals`, and
      schema `JournalEntry` untouched.
- [ ] i18n: add source string `"Manual Journals"` (English key) with nl
      `"Memoriaalboekingen"`; run the l10n extract/compile so the Dutch label
      ships. Verify keys are the ENGLISH source string, not Dutch.

## Phase 2: Single nav home for Project (REQ-NAVIA-002 / 003)

- [ ] `src/menu-layout.json` — add `"Projects"` to the `removals` array →
      `["Consolidations", "Verplichtingen", "Projects"]`.
- [ ] Confirm no `relocations` entry is needed (canonical home
      `ProjectenOverzicht` already exists under group `Projecten`).
- [ ] Verify after `applyMenuRelocations` that the Bookkeeping `Projects` menu
      leaf is gone but the `Projects` page route still resolves (deep link).

## Phase 3: Fix double-active nav state (REQ-NAVIA-004 / 005)

- [ ] Locate the nav active-state resolver (CnAppNav active-class binding /
      `main.js` neighbourhood) and replace path-PREFIX matching with exact
      route-identity matching (active leaf = current route's owning leaf, or
      the index leaf its `detailRoute`/`indexRoute` binds to).
- [ ] `src/manifest.json` — verify `PurchaseOrders`↔`PurchaseOrderDetail` and
      `GoodsReceipts`↔`GoodsReceiptDetail` each bind via a single
      `indexRoute`/`detailRoute` pair; confirm each detail route id + path is
      registered exactly once (no duplicate registration).
- [ ] Manually open a PO detail and a GRN detail; confirm exactly ONE nav leaf
      is highlighted in each case.

## Phase 4: Kill cards-in-cards on Cashflow Forecast dashboard (REQ-NAVIA-007 / 008)

- [ ] In the manifest-v2 dashboard renderer, suppress the outer
      `CnWidgetWrapper` card for widgets of `type: "stats-block"` (self-carding
      widget types render directly on the grid surface). `table` / `chart` /
      `alert` widgets keep the wrapper.
- [ ] Verify the `CashflowDashboard` `buffer-status` tile now renders with a
      single card (no double border/padding).
- [ ] Audit every shillinq `type: dashboard` page for `stats-block` widgets
      (incl. the Stock Levels `auto-po-pending` and the financial overview
      `Dashboard`) and confirm none renders a double card after the fix.

## Phase 5: Build, verify, gates

- [ ] `npm run build`; recompile l10n.
- [ ] Verify via no-store fetch / fresh browser context (shillinq bundle has no
      `?v=` cache-buster — recorded gotcha; warm browsers read stale).
- [ ] Run `hydra-gate-dashboard-antipattern` and confirm the Cashflow dashboard
      no longer trips the cards-in-cards family.
- [ ] e2e: assert (a) menu shows "Manual Journals"; (b) one nav leaf active on
      PO/GRN detail; (c) `Projects` deep link still resolves with the
      Bookkeeping leaf gone; (d) the buffer-status KPI tile has single card
      chrome.

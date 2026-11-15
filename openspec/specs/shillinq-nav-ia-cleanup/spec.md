---
status: done
---

# shillinq-nav-ia-cleanup Specification

## Purpose
Corrects shillinq's navigation information architecture by disambiguating the manual-journal entry as "Manual Journals" distinct from the General Ledger, giving the Project schema exactly one navigation home while keeping its page deep-linkable, and ensuring each route marks exactly one active nav leaf with no duplicate detail-route registrations. The capability also fixes dashboard KPI rendering so stats-block tiles render with single card chrome instead of nested cards, and adds e2e coverage for these corrections.

## Requirements

### Requirement: REQ-NAVIA-001 — The system SHALL label the manual-journal menu entry "Manual Journals" (nl "Memoriaalboekingen") so it does not read as a duplicate of the General Ledger

The Bookkeeping menu entry `id: "Journals"` and the page `id: "Journals"` title MUST read **"Manual Journals"** in English and **"Memoriaalboekingen"** in Dutch. The change is label-only: the menu id `Journals`, the route
`Journals`, the `indexRoute` `Journals`, and the bound schema `JournalEntry`
MUST be unchanged. The i18n key MUST be the English source string
`"Manual Journals"` (not the Dutch translation). `GeneralLedger`→`GLTransaction`
(immutable system ledger) and `Journals`→`JournalEntry` (human-authored
memoriaalboeking) MUST remain two separate menu entries / pages — they are
NOT merged.

#### Scenario: Menu shows the disambiguated label
- **Given** a user opens the shillinq Bookkeeping menu group
- **When** the navigation renders
- **Then** the entry bound to schema `JournalEntry` reads "Manual Journals"
  (Dutch UI: "Memoriaalboekingen") and sits as a distinct entry alongside
  "General Ledger" (schema `GLTransaction`)

#### Scenario: Route, id and schema are unchanged
- **Given** the relabel has been applied
- **When** the navigation and page registry are inspected
- **Then** the menu id is still `Journals`, the route is still `Journals`, and
  the page still binds schema `JournalEntry` — only the human-readable label
  and page title changed

### Requirement: REQ-NAVIA-002 — The system SHALL expose `Project` under exactly one navigation home — a `Projects` page inside the Bookkeeping cluster

The `Project` schema (RJ270/IFRS15 revenue-recognition register) MUST be
reachable from a single navigation home: the page id `Projects`, relocated
into the Bookkeeping cluster (`nav-six-clusters`). The former `Projecten`
top-level group and its `ProjectenOverzicht` page MUST NOT survive as a
second navigation home for the `Project` schema — `ProjectenOverzicht` MUST
be deleted, not merely unlinked from the menu, so a future manifest edit
cannot silently resurrect the duplicate the way the withdrawn `removals`
entry did. `Utilisatie` (the `Projecten` group's other child, a distinct
schema) relocates alongside `Projects` into the Bookkeeping cluster as a
companion card; it is not merged with `Projects` and is unaffected by this
requirement. The `CostProjects` leaf (schema `CostProject`, the distinct
analytical management-accounting register, already removed by
`retire-cost-project`) remains unaffected.

#### Scenario: Only one Project nav entry remains
- **GIVEN** the restructured `src/manifest.json` + `src/manifest.d/*.json` +
  `src/menu-layout.json` (no `ProjectenOverzicht` page definition anywhere
  in any fragment)
- **WHEN** `buildManifest()` runs
- **THEN** the only navigation home for the `Project` schema is the
  `Projects` page inside the Bookkeeping cluster, and this holds regardless
  of `menu-layout.json#removals`'s contents (there is no page left for a
  withdrawn or re-added removals entry to accidentally resurrect)

@e2e nav-six-clusters::preset-deep-links-resolve

#### Scenario: Utilisatie is relocated, not merged
- **GIVEN** the restructured manifest
- **WHEN** the `Utilisatie` page is inspected
- **THEN** it still exists as its own page, now reachable as a companion
  card inside the Bookkeeping cluster landing page, and its schema/route are
  unchanged from before this change

@e2e nav-six-clusters::preset-deep-links-resolve

#### Scenario: CostProjects is untouched
- **GIVEN** the restructured manifest
- **WHEN** the Bookkeeping cluster renders
- **THEN** the `CostProjects` leaf (schema `CostProject`) is still absent
  (per `retire-cost-project`, unaffected by this change) and `Projects`
  (schema `Project`) is present exactly once

### Requirement: REQ-NAVIA-003 — The system SHALL keep the `Projects` page routable for deep links after its menu entry is removed

Removing the `Projects` menu leaf MUST NOT deregister the `Projects` page.
Per the documented `menu-layout.json` `removals` contract, the page (route
`Projects`) stays fully routable so saved bookmarks, inbound deep links, and
e2e specs that navigate to route `Projects` continue to resolve.

@e2e exclude deep-link resolution covered by REQ-NAVIA-006 e2e scenario; this requirement is the routability invariant

#### Scenario: Deep link to a removed-from-nav page still resolves
- **Given** the `Projects` menu leaf has been removed via `removals`
- **When** a user navigates directly to the `Projects` route (e.g. a saved
  bookmark or inbound link)
- **Then** the `Projects` page loads normally and lists `Project` objects — it
  is not a 404

### Requirement: REQ-NAVIA-004 — The system SHALL mark exactly one navigation leaf active for any active route

The navigation active-state resolver MUST mark a leaf active by exact route
identity — the active route's owning leaf, or the index leaf its
`detailRoute`/`indexRoute` binding maps to — and MUST NOT mark a leaf active on
bare path-prefix string matching. For any single active route, at most one nav
leaf is in the active state.

#### Scenario: A purchase-order detail lights up only its own leaf
- **Given** a user opens a purchase order detail (`/inkoop/purchase-orders/:id`)
- **When** the navigation renders the active state
- **Then** only the `PurchaseOrders` leaf is highlighted and the
  `GoodsReceipts` leaf is NOT highlighted

#### Scenario: A goods-receipt detail lights up only its own leaf
- **Given** a user opens a goods receipt detail (`/inkoop/goods-receipts/:id`)
- **When** the navigation renders the active state
- **Then** only the `GoodsReceipts` leaf is highlighted and the
  `PurchaseOrders` leaf is NOT highlighted

### Requirement: REQ-NAVIA-005 — The system SHALL register each Purchasing detail route exactly once

Each detail page in the Purchasing & Inventory area (`PurchaseOrderDetail` route `/inkoop/purchase-orders/:id`, `GoodsReceiptDetail` route `/inkoop/goods-receipts/:id`) MUST be registered exactly once with a unique `id` and `route`, and each MUST bind to a single
owning index leaf via `detailRoute`/`indexRoute`. No detail route may be
registered twice or under two index leaves — the same defect class as
shillinq's earlier duplicate-route-name 404.

#### Scenario: No duplicate detail-route registration
- **Given** the manifest page registry
- **When** the `PurchaseOrderDetail` and `GoodsReceiptDetail` page definitions
  are inspected
- **Then** each route id and path appears exactly once and binds to exactly one
  index leaf (`PurchaseOrders` / `GoodsReceipts` respectively)

### Requirement: REQ-NAVIA-006 — The system SHALL provide e2e coverage for the relabel, single-active nav, and deep-link preservation

The change MUST add e2e assertions that: (a) the menu shows "Manual Journals";
(b) opening a PO detail and a GRN detail each highlights exactly one nav leaf;
(c) the `Projects` deep link still resolves after the Bookkeeping `Projects`
menu leaf is removed.

#### Scenario: e2e verifies the IA corrections
- **Given** the rebuilt shillinq frontend
- **When** the e2e suite runs
- **Then** it asserts the "Manual Journals" label is present, exactly one nav
  leaf is active on each Purchasing detail page, and a direct visit to the
  `Projects` route renders the page

### Requirement: REQ-NAVIA-007 — The system SHALL render dashboard `stats-block` KPI tiles directly on the dashboard surface without an outer wrapper card

The manifest-v2 dashboard renderer MUST NOT wrap a widget of
`type: "stats-block"` in the outer `CnWidgetWrapper` card, because
`CnStatsBlock` carries its own card chrome — wrapping it produces a
card-in-card (double border + padding). Widgets of `type: "table"`,
`"chart"`, and `"alert"` keep the wrapper. On the `CashflowDashboard` page
(route `/cashflow/dashboard`) the `buffer-status` stats-block MUST render with
a single card. The shared `CnStatsBlock` component MUST NOT be modified (it is
card-first across the fleet, e.g. `BBVKPICards`); the fix is the renderer's
wrapper decision.

#### Scenario: Cashflow buffer-status KPI has single card chrome
- **Given** a user opens the Cashflow Forecast dashboard
  (`/cashflow/dashboard`)
- **When** the `buffer-status` stats-block widget renders
- **Then** it shows a single card (one border, one padding box) — not a card
  nested inside another card

### Requirement: REQ-NAVIA-008 — The system SHALL audit every shillinq dashboard for stats-block card nesting

The change MUST audit every shillinq page of `type: "dashboard"` that declares
one or more `type: "stats-block"` widgets (including the Stock Levels
`auto-po-pending` tile and the financial overview `Dashboard`) and confirm none
renders a double card after the renderer fix. This is the
hydra-gate-dashboard-antipattern family (hydra#316) applied to KPI tiles.

#### Scenario: No dashboard renders a nested stats-block card
- **Given** the renderer no longer wraps stats-block widgets
- **When** every `type: dashboard` page with a stats-block widget is opened
- **Then** each stats-block tile renders with single card chrome and
  hydra-gate-dashboard-antipattern reports no cards-in-cards finding

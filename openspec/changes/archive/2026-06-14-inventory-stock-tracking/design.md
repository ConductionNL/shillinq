# Design — Inventory Stock-on-hand per Item per Location

## Context

Inventory is a material asset requiring real-time, multi-location tracking per Dutch corporate accounting standards (CTR) and IAS 2. The Tryton / Odoo pattern — stock quantity snapshots per (item, location) with atomic updates from purchase receipts and sales orders — prevents quantity divergence and enables drill-down from financial reports to physical inventory.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire stock-tracking surface as **declarative metadata** — schema + seed data + manifest entries — per ADR-031.
- Provide the **state snapshot** for stock quantities (on-hand, reserved, in-transit) per item per location, serving as the ledger query target for downstream stock movements.
- Enable **multi-location** inventory across warehouses, distribution centers, and retail locations (via Location FK).
- Consume OR's **relations** and **audit trail** abstractions — zero parallel stock table in PHP.
- Make the spec a **competent-accountant-readable contract** — Dutch SMB warehouse operators should recognize stock levels (on-hand, reserved, available) end-to-end.
- Declare the **state model** (on-hand, reserved, in-transit, available) so downstream stock-move (T2) and reorder-automation (T3) specs can build on a consistent foundation.

## Non-Goals

- No PHP `InventoryStockService`; no bespoke `reserveQuantity()` / `issueStock()` methods. Stock updates are triggered by downstream specs (StockMove posting, reorder execution).
- No barcode/serial number tracking — future `inventory-lot-batch-expiry` and `inventory-mobile-scanner` capabilities.
- No reorder automation — future `inventory-reorder-automation` spec.
- No cycle counting reconciliation logic — future `inventory-cycle-count` spec.
- No cost allocation (FIFO, average, weighted average) — future `inventory-valuation-fifo-avg` spec.
- No cycle-count variance analysis — deferred to financial reporting (T3).

## Decisions

### D1 — Stock quantity is declared in four states: on-hand, reserved, in-transit, available

**Why four states?**

A single `quantity` field (physical on-hand) is insufficient for modern supply chain:

- **on-hand** (quantityOnHand) — physical inventory in the location.
- **reserved** (quantityReserved) — allocated to sales orders, production plans, or pending shipments (not available for new allocations).
- **in-transit** (quantityInTransit) — en route from supplier or between locations (not yet received, not available).
- **available** (quantityAvailable) — computed: quantityOnHand - quantityReserved. This is the quantity that can be allocated to new orders.

This allows the warehouse operator to see at a glance: "I have 100 units on-hand, 25 are reserved for pending orders, 10 are in-transit from the supplier, so I have 75 available for new sales."

**Alternative considered:** Single quantity field + separate Reservation register. Rejected — operational friction; operators need the snapshot available at a glance without joining tables.

### D2 — InventoryStock is the state snapshot, not the transaction ledger

**Why separate from StockMove?**

`InventoryStock` is a **denormalized aggregate**: the current balance per (product, location).
`StockMove` (downstream spec) is the **transaction ledger**: every movement (receipt, transfer, issue, repack).

Why not store them in one table? Because:
- Operational queries ("how many units of this product in warehouse X?") hit InventoryStock (fast).
- Audit queries ("show me all movements that led to this balance") drill through StockMove (join on product+location, filter by date).
- Recomputation is cheap: sum all StockMove debits/credits per (product, location) to regenerate InventoryStock if corruption is suspected.
- Downstream specs can update InventoryStock via OR's materialisation extension without duplicating ledger logic.

**Alternative considered:** Single ledger-only table; compute balances on-the-fly. Rejected — query performance and reporting complexity; operators need snapshots.

### D3 — UnitCost is tracked on InventoryStock for standard costing; FIFO/average variance calculated downstream

**Why unitCost on the snapshot?**

InventoryStock.unitCost records the **standard or average cost per unit** at the time of last receipt. This is sufficient for:
- Quick P&L impact (cost of goods sold = issued quantity × unitCost).
- Balance sheet valuation (on-hand quantity × unitCost = inventory asset).

FIFO/average/weighted-average **variance** (difference between standard cost and actual cost method) is calculated in the financial-reporting tier (T3, `inventory-valuation-fifo-avg` spec), not here.

**Alternative considered:** Store full history of costs per receipt batch (lot tracking). Rejected — complexity; future `inventory-lot-batch-expiry` spec handles that.

### D4 — Location is a FK, not an enum; operators define location master data

**Why FK?**

InventoryStock.location points to the Location entity (from `budget-planning-control` spec). This allows:
- Operators to define their own warehouse/site structure (no hardcoded locations).
- Location attributes (address, manager, capacity) to be reused for budgeting, shipping, reporting.
- Multi-level hierarchy in future (warehouse → aisle → bin) by extending Location.

Location can represent any physical container: main warehouse, regional distribution center, retail store, repair depot, even temporary storage (e.g., "returned items bin").

**Alternative considered:** Location as a string (free-text location name). Rejected — no referential integrity; cascading updates break; reporting becomes fragile.

### D5 — InventoryStock is updated by downstream StockMove transactions via OR materialisation

**How does it stay in sync?**

When `inventory-stock-movement-ledger` (downstream spec) is implemented, every posted StockMove triggers a balanced GL entry (per ADR-031 declarative GL). The same OR materialisation extension updates InventoryStock:

```
ON StockMove.status = "posted":
  UPDATE InventoryStock
  SET quantityOnHand = quantityOnHand - StockMove.quantity WHERE location = sourceLocation
  UPDATE InventoryStock
  SET quantityOnHand = quantityOnHand + StockMove.quantity WHERE location = destinationLocation
  UPDATE InventoryStock
  SET unitCost = StockMove.unitCost (for receipts)
```

Until StockMove is implemented, InventoryStock is seeded manually and updated ad-hoc. This is acceptable for T1; synchronization is enforced once downstream specs land.

### D6 — Product and Location are required FKs; no orphan stock records

**Why required?**

Every InventoryStock record must reference:
- **product** — which item is tracked (FK to Product from `inventory-product-catalog`).
- **location** — which warehouse/site (FK to Location).

A stock record with no product or location is meaningless. Both are required; NULL is not allowed.

### D7 — OrganizationId provides multi-tenancy scope

**Why organizationId?**

Nextcloud is multi-tenant. Organization A's "Warehouse Amsterdam" should not collide with Organization B's "Warehouse Amsterdam". Every InventoryStock record carries `organizationId` FK. Queries are automatically scoped by organization via OpenRegister's multi-tenancy controls.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Stock quantity tracking | Not yet implemented in Nextcloud | Declare `InventoryStock` register with quantityOnHand, quantityReserved, quantityInTransit, quantityAvailable (computed) |
| State model (on-hand, reserved, in-transit) | Observed in competitors; no OR abstraction | Field-level declaration; downstream specs (StockMove, reorder-automation) enforce the workflow |
| Product reference | `Product` register (from `inventory-product-catalog`) | InventoryStock.product → Product FK |
| Location reference | Location entity (from `budget-planning-control`) | InventoryStock.location → Location FK |
| Stock updates from transactions | OR `x-openregister-materialisation` (ADR-031) | StockMove posting (downstream spec) triggers materialisation to update InventoryStock |
| Cost tracking | Manual entry + PO reference | InventoryStock.unitCost (entered or auto-filled from receipt); downstream `inventory-valuation-fifo-avg` calculates variance |
| Audit trail | T1 `audit-trail-immutable` (OR built-in) | Automatic on every InventoryStock change |
| Manifest navigation | T1 manifest pattern | 3 entries (Stock Levels, Stock by Location, Reserve Stock) + their pages |
| Multi-tenancy | OR `x-openregister-multitenancy` (ADR-024) | InventoryStock.organizationId FK; queries scoped by organization |

**Net new code in implementation cycle**: 1 schema declaration + 3 manifest entry pairs + seed data generation. Zero PHP service classes (per ADR-031).

## Seed Data

Three realistic Dutch warehouse scenarios:

**Warehouse: Amsterdam** (head office distribution center)
- Product: Dell XPS 13 (from `inventory-product-catalog` seeds)
  - Location: Amsterdam Warehouse
  - quantityOnHand: 15
  - quantityReserved: 3
  - quantityInTransit: 5 (from supplier, expected 2026-05-25)
  - quantityAvailable: 12
  - unitCost: 1899.00 EUR
  - lastRestockDate: 2026-05-15
  - status: active

- Product: Office Toner Cart HP LaserJet (from `inventory-product-catalog` seeds)
  - Location: Amsterdam Warehouse
  - quantityOnHand: 150
  - quantityReserved: 20
  - quantityInTransit: 0
  - quantityAvailable: 130
  - unitCost: 45.00 EUR
  - lastRestockDate: 2026-05-10
  - status: active

**Warehouse: Rotterdam** (regional logistics hub)
- Product: Dell XPS 13
  - Location: Rotterdam Warehouse
  - quantityOnHand: 8
  - quantityReserved: 2
  - quantityInTransit: 0
  - quantityAvailable: 6
  - unitCost: 1899.00 EUR
  - lastRestockDate: 2026-05-12
  - status: active

- Product: Office Toner Cart HP LaserJet
  - Location: Rotterdam Warehouse
  - quantityOnHand: 75
  - quantityReserved: 10
  - quantityInTransit: 50 (from Amsterdam, expected 2026-05-22)
  - quantityAvailable: 65
  - unitCost: 45.00 EUR
  - lastRestockDate: 2026-04-28
  - status: active

**Warehouse: Utrecht** (retail outlet)
- Product: Dell XPS 13
  - Location: Utrecht Store
  - quantityOnHand: 3
  - quantityReserved: 1
  - quantityInTransit: 0
  - quantityAvailable: 2
  - unitCost: 1899.00 EUR
  - lastRestockDate: 2026-05-18
  - status: active

- Product: Office Toner Cart HP LaserJet
  - Location: Utrecht Store
  - quantityOnHand: 40
  - quantityReserved: 0
  - quantityInTransit: 0
  - quantityAvailable: 40
  - unitCost: 45.00 EUR
  - lastRestockDate: 2026-05-14
  - status: active

(Plus 3-5 additional products from the product catalog, e.g., packaging boxes, USB drives, notebooks, with varying stock levels across locations)

## Example Data (Dutch context)

Location entities (from Budget spec, seed by `budget-planning-control`):
- "Amsterdam Warehouse" (Hoofddorpweg 10A, 1131 PA Amsterdam) — capacity: 5000 units, manager: Jan de Vries
- "Rotterdam Warehouse" (Betuweweg 45, 3196 KE Rotterdam-Pernis) — capacity: 8000 units, manager: Maria González
- "Utrecht Store" (Neude 3, 3512 AD Utrecht) — capacity: 500 units, manager: Erik Vermeulen

Product entities (from inventory-product-catalog seed):
- SKU: LAPTOP-DELL-XPS13, Name: "Dell XPS 13 (FHD)", Category: "it_hardware", unitPrice: 1899.00 EUR
- SKU: TONER-HP-CF283A, Name: "HP LaserJet Toner Cartridge CF283A", Category: "office_supplies", unitPrice: 45.00 EUR
- SKU: BOX-CARDBOARD-S, Name: "Cardboard Box Small (10x10x10cm)", Category: "packaging", unitPrice: 0.75 EUR
- SKU: NOTEBOOK-A4-100, Name: "Notebook A4 100 sheets", Category: "office_supplies", unitPrice: 3.50 EUR
- SKU: USB-DRIVE-32GB, Name: "USB Drive 32GB Kingston", Category: "it_hardware", unitPrice: 12.00 EUR

## Design Trade-offs

1. **Denormalized snapshot vs. ledger-only**: InventoryStock trades normalized space (one row per product+location) for operational performance. Ideal for operators asking "how much do I have?" Acceptable because downstream specs (StockMove) maintain referential consistency.

2. **Decimal precision vs. integer simplicity**: Quantities are decimals (0.1, 0.5) not integers. Supports fractional units (0.5 L, 2.25 kg). Trade-off: rounding logic is deferred downstream.

3. **Manual seeding vs. auto-calculation**: Initial InventoryStock is seeded (not calculated from StockMove) because StockMove is downstream. Once implemented, the relationship is enforced. Acceptable for T1 where operators input initial stock counts manually (physical inventory).

## Reuse of OpenRegister Patterns

Per ADR-022 + ADR-031:
- No custom PHP service; all CRUD via OpenRegister's generic ObjectService.
- Schema-driven UI (CnIndexPage, CnDetailPage) — no bespoke Vue components.
- Audit trail automatic (auditTrail field).
- RBAC per OR's PropertyRbacHandler.
- Relations to Product and Location via OR's relation system.
- Multi-tenancy via organizationId + OR's scoping.
- Materialisation hook for StockMove → InventoryStock updates (when downstream spec lands).

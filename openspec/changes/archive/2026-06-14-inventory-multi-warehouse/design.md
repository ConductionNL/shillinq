# Design — Hierarchical Inventory Locations

## Context

Multi-warehouse inventory management requires hierarchical organization of storage
locations (warehouse → zone → bin) and real-time visibility of stock across locations.
Dutch SMB operators manage average 3.2 active locations per organization; larger
operations manage up to 8+ sites. The Brightpearl real-time model and ERPNext
warehouse-group pattern enable nested organization and rollup reporting without
requiring a bespoke warehouse service.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire location hierarchy as **declarative metadata** — schema + type
  enum + parent-child FK — per ADR-031.
- Enable **multi-level organization** of inventory storage (warehouse, zone, bin)
  with rollup stock visibility up the hierarchy.
- Support **inter-location transfers** between any two locations with optional
  GL integration.
- Consume existing **InventoryStock** entity and optional **GL materialisation**
  patterns — no parallel warehouse table in PHP.
- Make the spec a **competent-warehouse-manager-readable contract** — Dutch SMB
  warehouse operations recognizable end-to-end (warehouse definition, zone setup,
  bin organization, inter-warehouse transfer, in-transit tracking, GL posting).

## Non-Goals

- No PHP `WarehouseService` or `LocationService`; no bespoke location CRUD methods.
- No automated putaway/slotting rules — future `inventory-putaway-rules` capability.
- No barcode/serial number tracking — future `inventory-barcode-sku` capability.
- No yard/dock management — future `inventory-yard-management` capability.
- No 3PL multi-client segregation — future spec for 3PL operators.

## Decisions

### D1 — Location hierarchy is parent-child FK with type enum

A `Location` has optional `parentLocationId` FK to another Location; `locationType`
enum (warehouse, zone, bin, in-transit). Physical meaning: warehouse contains zones;
zones contain bins; in-transit is a virtual location for transfers in progress.
Hierarchy depth validated (max 4 recommended). No parallel warehouse table; Location
is the single source of truth.

### D2 — In-transit is a special location type for transfer-in-progress stock

In-transit location (type='in-transit') is a virtual holding location for stock
being transferred between warehouses. Stock in-transit is not GL-posted until
physically received (stays in "goods-in-transit" GL account per
`inventory-stock-movement-ledger` pattern). Separate visibility from received stock
(operator can query in-transit separately).

### D3 — Rollup stock visibility via aggregation over child locations

`InventoryStock.quantity` at warehouse level = SUM(InventoryStock.quantity for all
bins under that warehouse). No separate warehouse-level stock table; queries aggregate
up the hierarchy using Location parent-child FK. Index on (parentLocationId, type)
for fast hierarchy traversal.

### D4 — Inter-location transfer is a workflow, not a schema change

A transfer between two locations is a stock movement (if `inventory-stock-movement-ledger`
is present) or a manual InventoryStock adjustment (simpler case). No parallel transfer
table; Location hierarchy just enables the routing logic. Transfer workflows are
optionally GL-integrated for warehouse reclassification GL posting.

### D5 — Location code is a human-readable identifier per warehouse

Every warehouse Location has a `locationCode` (e.g., "W-01", "WAREHOUSE-MAIN",
"DC-EAST"). Zones inherit warehouse context (e.g., zone "Z-01" under warehouse "W-01"
is implicitly "W-01-Z-01"). Bins are most-granular (e.g., "W-01-Z-01-B-100"). Codes
are immutable after creation; no code-change cascade.

### D6 — Rollup reporting includes filters for location type and depth

Operator can query: total stock in warehouse (all descendants), stock per zone
(bin-level only), in-transit stock (where destinationLocation.type='in-transit').
No new reporting service; standard InventoryStock queries filtered by Location type
and hierarchy.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Location entity | Budget spec (simple Location entity exists) | Extend Location schema with parentLocationId, locationType, locationCode |
| Hierarchy navigation | OR parent-child FK pattern | Standard SQL: SELECT * FROM locations WHERE parentLocationId = X |
| Rollup stock aggregation | OR `x-openregister-aggregations` | Aggregation query: SUM(InventoryStock.quantity) for all descendants |
| In-transit holding | Optional `inventory-stock-movement-ledger` | In-transit location type holds stock; move ledger defines the transfer pattern |
| GL integration | T1 `add-shillinq-general-ledger` (optional) | Transfer GL posting uses Location type + GL account mapping per item category |
| Stock queries | InventoryStock from `inventory-stock-tracking` | Queries filtered by Location FK; aggregation up hierarchy for rollup |
| Audit trail | OR built-in `auditTrail` field | Automatic on Location create/update; tracks hierarchy changes |
| Manifest navigation | T1 manifest pattern | Hierarchy tree view (Warehouse Locations), transfer form, in-transit index |

**Net new code in implementation cycle**: 1 Location schema extension (3 fields) +
2 aggregation queries (warehouse rollup, in-transit visibility) + 2-3 manifest entry
pairs. Zero PHP service classes (per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Location hierarchy | Declarative (parent-child FK) | Pure data structure |
| Rollup aggregation | Declarative (OR aggregations or SQL SUM) | Pure mapping: children → parent total |
| In-transit holding | Declarative (location type enum) | No computation; metadata only |
| Transfer workflow | Optional GL integration (if GL present) | GL posting is declarative rule; transfer is stock movement pattern |
| Location code generation | Operator-managed (no auto-generation) | Immutable identifier; no cascade logic |

No service class authored in this envelope (per ADR-031).

## Seed Data

3-5 example locations per organization type:

**Example 1: Single warehouse (small SMB)**
```
- Warehouse: "W-01" (Amsterdam Central)
  - Zone: "Z-01" (Receiving)
  - Zone: "Z-02" (Shelving)
  - Zone: "Z-03" (Dispatch)
```

**Example 2: Multi-warehouse network (mid-market)**
```
- Warehouse: "W-MAIN" (Amsterdam Central)
  - Zone: "Z-01" (Receiving)
  - Zone: "Z-02" (Shelving A-M)
  - Zone: "Z-03" (Shelving N-Z)
  - Bin: "B-100", "B-101", ... (under each zone)
- Warehouse: "W-EAST" (Rotterdam)
  - Zone: "Z-01" (Receiving)
  - Zone: "Z-02" (Shelving)
- In-Transit: "IN-TRANSIT-MAIN" (virtual location for W-MAIN → W-EAST transfers)
```

**Example 3: High-velocity warehouse (ecommerce)**
```
- Warehouse: "W-FULFILLMENT" (Logistics center)
  - Zone: "Z-RECEIVING" (Goods-in)
  - Zone: "Z-QC" (Quality check)
  - Zone: "Z-SHELVING" (Active inventory)
  - Zone: "Z-PACKING" (Pick-to-order)
  - Bin: "B-1001", "B-1002", ... (under Z-SHELVING)
- Warehouse: "W-OVERFLOW" (Seasonal)
  - Zone: "Z-01" (Long-term hold)
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Location hierarchy depth explosion | Recommend max depth 4; validation in implementation + ADR-032 review. UI limit. |
| Rollup performance at scale (1M+ locations) | Caching at zone/warehouse level. DB indices on (parentLocationId, type). 10-20ms queries at Dutch SMB scale. |
| In-transit location semantics with GL | In-transit is virtual (no GL post until receipt). Clarified in REQ-LOC-004. |
| Code immutability breaks rename workflows | Codes are immutable by design (audit trail, GL reference, barcodes). Operator can create new location + migrate stock if needed. |
| Hierarchical queries on large warehouse (1000+ bins) | Batch aggregation or materialized views in implementation cycle if gates trip. |
| Transfer without move ledger is manual | Allowed (simple case); with move ledger, transfers are atomic. No breakage. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with extended `Location` schema
   (3 new fields: parentLocationId, locationType, locationCode).
2. `openspec/architecture/adr-000-data-model.md` is updated with Location entity
   definition reflecting hierarchy and type enum.
3. `src/manifest.json` is patched with 3 new menu entries + their pages (Warehouse
   Locations tree, inter-warehouse transfers, in-transit stock).
4. Seed data (example warehouses, zones, bins) is populated in administration setup
   or migration script for new organizations.

Down-direction: registers are non-destructive — reverting removes the manifest
entries and extends Location back to simple schema. InventoryStock remains queryable
at bin level.

## Open Questions

1. **Location hierarchy depth limit** — max 4 (warehouse → zone → aisle → bin)?
   Resolved in ADR-032 / architecture review.
2. **In-transit visibility** — separate `inTransitQuantity` field on InventoryStock
   or rolled into `quantity` with status flag? Depends on `inventory-stock-movement-ledger`.
   Resolved during implementing cycle's UX design.
3. **Transfer GL posting** — warehouse-to-warehouse posts reclassification GL or
   stays off-GL? Resolved in implementing cycle's GL architect review.
4. **Location code uniqueness scope** — per administration or globally? Resolved
   during implementing cycle's data model review.
5. **Rollup reporting** — warehouse stock rollup includes in-transit or only received?
   User preference or admin config? Resolved in implementing cycle's UX design.
6. **Location code format** — is there a naming standard (W-NN, DC-ABBR, etc.) or
   operator-free text? Resolved in implementing cycle's UX + data governance review.

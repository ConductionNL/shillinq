# Proposal: inventory-stock-tracking

`kind: foundational` — inventory stock levels (on-hand, reserved, in-transit, available) per item per location. Enables multi-warehouse stock management with Tryton-style Stock Move primitive.

## Summary

Introduce the **inventory stock-on-hand tracking** capability as the foundational T1 layer for multi-warehouse inventory management. This change declares one new register — `InventoryStock` — tracking quantity on-hand, reserved, in-transit, and available per item per warehouse location. The register integrates with the Product catalog (from `inventory-product-catalog`) and Location master data, providing the stock quantity ledger that downstream capabilities (stock movements, reorder automation, cycle counting) depend on.

Stock levels are managed as immutable transactions (via StockMove in downstream specs), with this register serving as the aggregated state snapshot. The design follows the Tryton/Odoo double-entry pattern: every stock movement atomically updates source and destination location balances, preserving data integrity across multi-warehouse scenarios.

This is a foundational change with **one upstream dependency**: `inventory-product-catalog` (declared in context-brief). All downstream inventory workflows depend on this tracking layer being in place.

This change conforms to the shared `nextcloud-app` spec for app structure and OpenAPI 3.0 register format.

## Motivation

Market intelligence research (2026-05-20) covering 30 competitor implementations shows **100% of competitors (22/22)** provide multi-location inventory tracking with:
- Real-time stock quantities per item per warehouse
- Multi-state tracking (on-hand, reserved, in-transit)
- Automatic balance updates from purchase receipts and sales orders
- Stock variance reporting and cycle count reconciliation
- Integration with financial reporting (inventory asset valuation)

Currently, Nextcloud has no inventory stock tracking. The `InventoryStock` entity exists in `adr-000-data-model.md` as a basic outline, but is not registered in any app. This proposal lays the foundation: a declarative, OpenRegister-backed stock tracker that every downstream capability (stock movements, reorder automation, COGS posting) can reference and update.

## Affected Projects

- [x] Project: inventory-management (or shillinq) — adds 1 new register (`InventoryStock`), adds manifest navigation entries for Stock Levels, Stock by Location, Reserve Stock management
- [x] Project: inventory-product-catalog — this change **consumes** the `Product` register declared by that spec
- [x] Project: inventory-stock-movement-ledger — **downstream spec** will declare `StockMove` to update InventoryStock balances
- [ ] Project: openregister — no source changes; this change consumes existing OR abstractions (RBAC, relations, aggregations)

## Scope

### In Scope

- One new foundational spec (`inventory-stock-tracking`) — see the `specs/` folder.
- One new register: `InventoryStock` (item reference, location reference, quantity on-hand, quantity reserved, quantity in-transit, available, last restock date, unit cost, status).
- Stock quantity state model: on-hand (physical inventory), reserved (allocated to sales orders / production), in-transit (en route from supplier), available (on-hand minus reserved).
- Multi-location support via Location FK, allowing per-warehouse stock snapshots.
- Product relationship support (FK to Product register from `inventory-product-catalog`).
- Manifest navigation entries (Inventory > Stock Levels, Stock by Location, Reserve Stock) using `type: index` / `type: detail` page renderers.
- Seed data: 3-5 realistic stock snapshots per location (Dutch warehouses: Amsterdam, Rotterdam, Utrecht with varying stock levels).
- Audit trail consumed from OpenRegister's audit-trail-immutable abstraction per ADR-022.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services, Vue components, controllers, tests, and CI changes are deliberately not in this proposal; the task list references them but implementation lands via a separate `opsx-apply` cycle.
- **Stock movement execution** — owned by downstream `inventory-stock-movement-ledger` spec (how movements update stock).
- **Reorder automation** — owned by downstream `inventory-reorder-automation` spec.
- **Cycle counting** — owned by downstream `inventory-cycle-count` spec.
- **Barcode/serial number tracking** — owned by future `inventory-lot-batch-expiry` and `inventory-mobile-scanner` specs.
- **Cost allocation by FIFO/average** — owned by downstream `inventory-valuation-fifo-avg` spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-stock-tracking`** — declares one register:

1. **`InventoryStock`** — the stock level snapshot per item per location. Fields: product (FK to Product), location (FK to Location), quantityOnHand (decimal), quantityReserved (decimal), quantityInTransit (decimal), quantityAvailable (computed: quantityOnHand - quantityReserved), unitCost (decimal), lastRestockDate (datetime), status (active/discontinued), organizationId (FK).

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-IST-*` for traceability (IST = Inventory Stock Tracking).

## New Dependencies

One: `inventory-product-catalog` (already declared in intelligence-db as available). This change consumes the `Product` register from that spec. No other app dependencies.

## Impact

- New register in the designated app (`shillinq`, `inventory-management`, or both).
- `lib/Settings/shillinq_register.json` (or equivalent) — adds 1 schema (`InventoryStock`).
- `src/manifest.json` — adds 3 navigation entries (Stock Levels, Stock by Location, Reserve Stock) and corresponding page bindings.
- Seed data: 3-5 realistic stock records per warehouse location under `lib/Settings/seeds/` (Dutch locations: Amsterdam, Rotterdam, Utrecht warehouses).
- Repair step — seeds initial stock snapshots on first install, idempotently.
- No new PHP services. No new Vue components (manifest-driven generic pages only).

## Cross-Project Dependencies

- **inventory-product-catalog** — depends on `Product` register; `InventoryStock.product` FK points to `Product`.
- **Location entity** — from Budget spec (`budget-planning-control`, already in adr-000); `InventoryStock.location` FK points to `Location`.
- **OpenRegister** — uses existing `x-openregister-relations` (for Product / Location links), audit-trail-immutable (ADR-022), RBAC (ADR-022). No new OR features required.
- **@conduction/nextcloud-vue** — uses existing `CnIndexPage` / `CnDetailPage` manifest renderers; no custom components.

## Risks

### Risk 1: Stock quantity precision and rounding

**Severity**: Low
**Mitigation**: All quantity fields are stored as decimals (not integers) to support fractional units (e.g., 0.5 L milk, 2.25 kg flour). Rounding policies (truncate, round-half-up) are deferred to downstream specs (valuation, COGS). This spec declares the field precision; no computational rounding logic here.

### Risk 2: Location scope unclear (warehouse vs. bin vs. aisle)

**Severity**: Medium
**Mitigation**: Location can represent any physical container (warehouse, building, aisle, bin). The spec intentionally leaves granularity to the operator's master data (Location name / code). ADR-000 Location entity already defines (name, code, address, region). Downstream specs (e.g., barcode scanner) can layer sub-locations if needed. For T1, one InventoryStock per (product, location) pair is sufficient.

### Risk 3: Synchronization lag: InventoryStock vs. StockMove ledger

**Severity**: Medium
**Mitigation**: InventoryStock is the **state snapshot** updated by StockMove transactions (downstream). Until StockMove is implemented, InventoryStock is seeded manually. Once StockMove lands, every posted move updates the InventoryStock aggregate (via OR's materialisation extension). The spec does not enforce synchronization logic — that is a downstream concern.

### Risk 4: Reserved quantity conflict in multi-user environment

**Severity**: Low
**Mitigation**: InventoryStock carries a `version` field (OpenRegister built-in). Concurrent updates use optimistic locking (compare-and-swap). Conflicts bubble up to the operator; resolution is manual or deferred to downstream workflow (e.g., split shipments). This spec declares the state; conflict resolution is implementation-level.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact because no implementation lands until `opsx-apply` is run on the spec. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR; registers are non-destructive — stock snapshots remain queryable but unreferenced.

## Open Questions

1. **App placement** — should the stock tracking land in `inventory-management`, `shillinq`, or a dedicated `stock-tracking` app? Defer to implementation planning.
2. **Reorder point / safety stock fields** — should InventoryStock carry `reorderPoint` and `safetyStock` (for automation), or are those product attributes? Provisional: product attributes (Task 4 of `inventory-product-catalog` design); deferred to Phase 2 if not included.
3. **Lot / batch tracking** — should InventoryStock track by lot number or expiry date, or is that a downstream `inventory-lot-batch-expiry` responsibility? Provisional: downstream capability; this spec is lot-agnostic.
4. **Negative quantities (backorder)** — can quantityOnHand go negative (indicating backorders), or is that enforced at the application level? Provisional: allow negatives (field type is decimal, no constraint); enforcement deferred to downstream workflows.

---
status: done
---

# Spec: inventory-stock-tracking

**Status:** implemented
**Scope:** shillinq
**Tier:** T1 (foundational)
**Depends on:** inventory-product-catalog

## Purpose

This specification defines the requirements for inventory stock tracking in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

@e2e exclude pure backend/data: stock-movement ledger, valuation and reservation logic are schema + service behaviour — not browser-testable

## Requirements

### REQ-IST-001: The system SHALL store inventory stock levels as an OpenRegister-managed `InventoryStock` register

The inventory stock snapshot MUST be declared as a register (per ADR-024) with the `InventoryStock` schema as the canonical entity. No custom PHP model, no custom database table, no parallel storage. The register is exposed through OpenRegister's generic CRUD HTTP surface; the app adds no per-app endpoint.

#### Scenario: Operator inspects stock levels via the OpenRegister API

- **GIVEN** the inventory stock-tracking is installed and the repair step has seeded initial stock levels
- **WHEN** an authenticated operator calls `GET /index.php/apps/openregister/api/objects/[app]/InventoryStock`
- **THEN** the response MUST list the `InventoryStock` records, paginated per OR's standard list contract, with no app-side controller in the call path.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the app's codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml` table declarations naming `inventory_stock` / `stock_levels` / `warehouse_inventory`
- **THEN** no such classes or declarations SHALL exist.

### REQ-IST-002: The `InventoryStock` schema SHALL declare a fixed minimum field set

The `InventoryStock` schema MUST declare the following fields with the typing below. Additional fields MAY be added later (additive only).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `product` | string (FK to Product) | Yes | Reference to the Product (from inventory-product-catalog); uniqueness constraint on (product, location, organizationId) |
| `location` | string (FK to Location) | Yes | Reference to the Location (warehouse, distribution center, store, etc. from budget-planning-control); cannot be null |
| `quantityOnHand` | decimal | Yes | Physical quantity in the location (default 0.00) |
| `quantityReserved` | decimal | No (default 0.00) | Quantity allocated to pending orders / production plans; not available for new allocations |
| `quantityInTransit` | decimal | No (default 0.00) | Quantity en route from supplier or between locations; not yet received |
| `quantityAvailable` | decimal | No (computed) | Computed: `quantityOnHand - quantityReserved` (read-only snapshot at query time) |
| `unitCost` | decimal | No | Standard or average cost per unit (currency same as Product.currency); used for P&L and balance sheet valuation |
| `lastRestockDate` | datetime | No | Timestamp of the most recent stock receipt or adjustment |
| `status` | enum | Yes | One of `active`, `discontinued` (mirrors Product.status; for query efficiency) |
| `organizationId` | string | Yes | FK to the organization owning this stock record (multi-tenant scoping) |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `createdAt`, `updatedAt`, `owner`, `auditTrail`, `relations`, …) are not redeclared per `adr-000-data-model.md`'s top-of-file note.

#### Scenario: Schema validator accepts a minimal stock record

- **GIVEN** the `InventoryStock` schema is loaded
- **WHEN** an object `{product: "prod-laptop", location: "loc-amsterdam", quantityOnHand: 15, status: "active", organizationId: "org-1"}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects a missing product or location

- **GIVEN** the schema
- **WHEN** an object with `product: null` is validated
- **THEN** validation MUST fail with a required-field error.

#### Scenario: quantityAvailable is computed correctly

- **GIVEN** a stock record with `quantityOnHand: 100` and `quantityReserved: 25`
- **WHEN** the record is retrieved
- **THEN** the response MUST include `quantityAvailable: 75` (computed on-the-fly, not stored).

#### Scenario: Uniqueness constraint on (product, location, organizationId)

- **GIVEN** organization `org-1` has a stock record for product `LAPTOP-001` in location `Amsterdam Warehouse`
- **WHEN** attempting to create another record in `org-1` for the same (product, location) pair
- **THEN** the save MUST fail with a uniqueness-violation error. A different organization `org-2` OR a different location MAY have its own record for the same product without conflict.

### REQ-IST-003: Stock quantity states SHALL express four independent dimensions

The four quantity fields — on-hand, reserved, in-transit, available — MUST be independent and non-overlapping. Every unit of stock is counted in exactly one state at a time.

- **on-hand** — physically in the location, not allocated.
- **reserved** — allocated to sales orders, production plans, or pending shipments (subset of on-hand or in-transit).
- **in-transit** — en route from supplier or between locations (not yet in destination's on-hand count).
- **available** — derived: on-hand minus reserved (the quantity available for new allocations).

#### Scenario: Warehouse operator interprets stock at a glance

- **GIVEN** a stock record: `quantityOnHand: 100, quantityReserved: 25, quantityInTransit: 10`
- **WHEN** the operator views the record
- **THEN** the operator MUST understand: "I have 100 units here, 25 are spoken for, 10 more are coming; I can allocate 75 new units".

#### Scenario: In-transit quantity does not double-count

- **GIVEN** a transfer of 10 units from Amsterdam to Rotterdam
- **WHEN** the transfer is in-transit
- **THEN** Amsterdam's `quantityOnHand` MUST NOT yet decrease (decrease happens on receipt); Rotterdam's `quantityInTransit` MUST increase by 10.

### REQ-IST-004: The system SHALL reference Products from the inventory-product-catalog register

Every `InventoryStock` record MUST carry a `product` FK pointing to a Product record from the `inventory-product-catalog` spec. Product deletion is handled per OR's referential integrity rules (typically: prevent deletion if child records exist, or cascade soft-delete to `discontinued`).

#### Scenario: Stock record references a valid product

- **GIVEN** a product `LAPTOP-DELL-XPS13` exists in the Product register
- **WHEN** creating a stock record with `product: "LAPTOP-DELL-XPS13"`
- **THEN** the save MUST succeed; the FK constraint MUST be enforced.

#### Scenario: Attempting to reference a non-existent product fails

- **GIVEN** no product with ID `invalid-prod-xyz` exists
- **WHEN** attempting to create a stock record with `product: "invalid-prod-xyz"`
- **THEN** the save MUST fail with a foreign-key-violation error.

### REQ-IST-005: The system SHALL reference Locations from the budget-planning-control register

Every `InventoryStock` record MUST carry a `location` FK pointing to a Location entity from the budget-planning-control spec. Locations represent physical warehouses, distribution centers, stores, or other holding sites. The operator defines location master data via Location CRUD; InventoryStock is scoped by location.

#### Scenario: Stock record references a valid location

- **GIVEN** a location `Amsterdam Warehouse` exists in the Location register
- **WHEN** creating a stock record with `location: "Amsterdam Warehouse"`
- **THEN** the save MUST succeed; the FK constraint MUST be enforced.

#### Scenario: Different locations have independent stock for the same product

- **GIVEN** product `LAPTOP-DELL-XPS13` has stock records in both `Amsterdam Warehouse` and `Rotterdam Warehouse`
- **WHEN** an operator queries stock for `LAPTOP-DELL-XPS13` by location
- **THEN** the system MUST return separate records per location, not aggregated.

### REQ-IST-006: Unit cost SHALL be recorded at the time of receipt for standard costing

The system SHALL satisfy this requirement: Unit cost SHALL be recorded at the time of receipt for standard costing.

The `unitCost` field on `InventoryStock` records the **standard or average cost per unit** at the time of most recent receipt (or manual adjustment). This cost is used for P&L (COGS = issued quantity × unitCost) and balance sheet valuation (inventory value = on-hand quantity × unitCost).

FIFO, weighted-average, or other costing variance calculations are performed downstream in the financial-reporting tier; this spec declares the snapshot cost only.

#### Scenario: Cost is recorded from a purchase receipt

- **GIVEN** a purchase order for 50 units of `TONER-HP-CF283A` at 45.00 EUR per unit is received
- **WHEN** the receipt creates an InventoryStock record (or updates existing)
- **THEN** the record MUST carry `unitCost: 45.00` (exact method for recording cost TBD in StockMove implementation).

#### Scenario: Cost is used for valuation

- **GIVEN** a stock record: `quantityOnHand: 100, unitCost: 45.00`
- **WHEN** an accountant calculates inventory asset value
- **THEN** the calculation MUST use: inventory value = 100 × 45.00 = 4500.00 EUR.

### REQ-IST-007: Stock records SHALL support multi-tenancy via organizationId

Every `InventoryStock` record MUST carry an `organizationId` FK linking to the owning organization. Queries MUST be scoped by organization: an operator in organization A SHALL NOT see stock from organization B without explicit cross-org permission.

The uniqueness constraint (REQ-IST-002) includes `organizationId`: different organizations MAY have identically-scoped stock records (same product, same location name) without conflict.

#### Scenario: Multi-tenant isolation enforced

- **GIVEN** organization `org-A` with stock for `LAPTOP-001` in `Amsterdam Warehouse`, and organization `org-B` with stock for `LAPTOP-001` in `Amsterdam Warehouse`
- **WHEN** an operator in `org-A` queries InventoryStock
- **THEN** the response MUST include only `org-A` records; `org-B` records MUST NOT be visible.

### REQ-IST-008: Quantity computation (`quantityAvailable`) MUST be consistent at query time

The `quantityAvailable` field is **computed and read-only**: `quantityOnHand - quantityReserved`. It MUST NOT be stored; it MUST be calculated on every retrieval to ensure consistency.

#### Scenario: Available quantity reflects current state

- **GIVEN** a stock record with `quantityOnHand: 100` and `quantityReserved: 25`
- **WHEN** the reservation is updated to `quantityReserved: 30`
- **THEN** a subsequent query MUST return `quantityAvailable: 70` (100 - 30), not the old cached value.

### REQ-IST-009: Stock levels SHALL be seeded with realistic Dutch warehouse data on first install

The repair step or migration MUST seed initial `InventoryStock` records for realistic Dutch warehouse scenarios (Amsterdam, Rotterdam, Utrecht locations) with varying stock levels, cost, and reservation states. Seed data MUST be idempotent: re-running the repair step MUST NOT duplicate seeded records.

Seed data MUST reference products from the `inventory-product-catalog` seed (laptops, toner, packaging, notebooks, USB drives) and locations from the `budget-planning-control` seed (warehouses in major Dutch cities).

#### Scenario: Initial seed populates stock levels

- **GIVEN** a fresh install with the inventory tracking module
- **WHEN** the repair step runs
- **THEN** the `InventoryStock` register MUST contain ~10–15 seed records across 3 locations (Amsterdam, Rotterdam, Utrecht) with realistic Dutch product names and quantities.

#### Scenario: Seed data includes mixed states

- **GIVEN** the seeded data
- **WHEN** querying stock records
- **THEN** the response MUST include records with varying `quantityReserved` (some zero, some non-zero) and `quantityInTransit` (some zero, some non-zero) to demonstrate the state model.

#### Scenario: Repair re-run does not duplicate

- **GIVEN** stock levels are seeded and an operator has added a custom stock record
- **WHEN** the repair step is re-run
- **THEN** the `InventoryStock` register MUST NOT duplicate seeded records, and the custom record MUST remain.

### REQ-IST-010: Inventory stock SHALL be reachable through the app manifest navigation

`src/manifest.json` MUST declare navigation entries (Inventory > Stock Levels, Stock by Location, Reserve Stock, or equivalent) with `type: index` page bindings to the `InventoryStock` register and `type: detail` pages for individual stock records. Pages MUST be rendered by the generic `@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage` components driven by manifest config — no bespoke Vue files.

#### Scenario: The stock levels index page lists all stock records

- **GIVEN** the manifest declares the Stock Levels page
- **WHEN** an operator opens `/index.php/apps/[app]/stock-levels` (or equivalent)
- **THEN** the page MUST render via `CnIndexPage` showing seeded/created stock records with default columns (product, location, quantityOnHand, quantityReserved, quantityAvailable, lastRestockDate).

#### Scenario: The detail page allows editing stock quantities

- **GIVEN** a stock record exists
- **WHEN** the operator drills into it
- **THEN** the detail page MUST render via `CnDetailPage` showing all fields from REQ-IST-002 (product, location, quantityOnHand, quantityReserved, quantityInTransit, unitCost, lastRestockDate, status, organizationId) and allowing edits.

#### Scenario: Stock by Location page filters by location

- **GIVEN** the manifest declares a "Stock by Location" page
- **WHEN** an operator selects a location (e.g., "Amsterdam Warehouse")
- **THEN** the page MUST display only stock records for that location, with sortable columns by product and quantity.

### REQ-IST-011: Status field MUST mirror Product.status for query efficiency

The system SHALL satisfy this requirement: Status field MUST mirror Product.status for query efficiency.

Every `InventoryStock` record carries a `status` field (active/discontinued) that mirrors the linked Product's status. This avoids a JOIN when filtering "show me only active stock" queries.

The `status` MUST be populated at `InventoryStock` creation time (from Product.status) and updated if the product is later marked discontinued. Enforcement is delegated to the application layer or downstream specs.

#### Scenario: Active stock is visible by default

- **GIVEN** a stock record for an active product
- **WHEN** querying with filter `status: "active"`
- **THEN** the record MUST be returned.

#### Scenario: Discontinued stock is retained for history

- **GIVEN** a product is marked `discontinued`
- **WHEN** related stock records are updated to `status: "discontinued"`
- **THEN** queries with `status: "discontinued"` MUST return the records (for historical reporting), and they are excluded from new transactions (per downstream spec enforcement).

### REQ-IST-012: Last restock date MUST be set on receipt or manual adjustment

The system SHALL satisfy this requirement: Last restock date MUST be set on receipt or manual adjustment.

The `lastRestockDate` field records the timestamp of the most recent stock movement (receipt, adjustment, or transfer IN) that increased `quantityOnHand`. This supports "aging" queries (e.g., "which locations haven't received stock in 30 days?") and replenishment planning.

#### Scenario: Receipt updates last restock date

- **GIVEN** a purchase receipt for a product on 2026-05-20
- **WHEN** the receipt updates InventoryStock (via downstream StockMove posting)
- **THEN** `lastRestockDate` MUST be set to 2026-05-20.

#### Scenario: Manual adjustment updates last restock date

- **GIVEN** a physical inventory count finds a discrepancy
- **WHEN** an operator adjusts the stock level manually
- **THEN** `lastRestockDate` MUST be updated to the adjustment timestamp.

### REQ-IST-013: Reserved and in-transit quantities MUST NOT exceed on-hand or reasonable limits

The system SHALL satisfy this requirement: Reserved and in-transit quantities MUST NOT exceed on-hand or reasonable limits.

Application-level validation (deferred to implementation) SHOULD prevent:
- `quantityReserved > quantityOnHand` (overstocking on reserve).
- `quantityOnHand + quantityInTransit < 0` (negative total).

Enforcement is application-level, not schema-level (the schema allows negative numbers for edge cases like returns or adjustments). Concrete validation rules are defined in the implementing cycle's tests.

#### Scenario: Overly aggressive reservation is prevented

- **GIVEN** a stock record with `quantityOnHand: 50`
- **WHEN** the implementing app attempts to set `quantityReserved: 75` (no current validation)
- **THEN** the implementation's business logic MUST reject the operation with a "insufficient available quantity" error.

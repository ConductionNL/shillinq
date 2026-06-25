---
status: done
---

# Spec: inventory-multi-warehouse

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (inventory + operations)
**Depends on:** `inventory-stock-tracking` (InventoryStock queries),
optional: `add-shillinq-general-ledger` (GL integration for transfers)

## Purpose

This specification defines the requirements for inventory multi warehouse in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/schema: multi-warehouse register — not browser-testable


### REQ-LOC-001: Location entity SHALL support hierarchical warehouse organization with parent-child FK

Location hierarchy MUST be expressed via the extended `Location` entity in
`lib/Settings/shillinq_register.json` per ADR-024:

- `Location` — physical or virtual storage location with optional parent-child
  relationship, location type (warehouse, zone, bin, in-transit), and location code.
  Every location captures: name, code (unique per administration), address, location
  type, parent location reference, and creation timestamp.

This capability enables hierarchical organization of inventory storage (warehouse →
zone → bin → item) and rollup stock visibility up the hierarchy per Dutch SMB and
international competitor patterns (Brightpearl, ERPNext). Querying InventoryStock
at warehouse level MUST return aggregated quantities from all bins under that warehouse
(via OR's aggregation extension or SUM query over location hierarchy).

#### Scenario: Reviewer confirms no parallel warehouse table in PHP

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `warehouse`, `warehouse_*`,
  `storage_location`, or `hierarchy`
- **THEN** no such classes SHALL exist; Location is the single source of truth.

#### Scenario: Location hierarchy supports parent-child references

- **GIVEN** Location with id="loc-w01" (warehouse) and Location with
  id="loc-w01-z01" (zone)
- **WHEN** loc-w01-z01.parentLocationId = "loc-w01" is saved
- **THEN** validation MUST pass; hierarchy persists. Parent location remains editable
  by administration.

### REQ-LOC-002: Location schema SHALL include hierarchy and type fields

The system SHALL satisfy this requirement: Location schema SHALL include hierarchy and type fields.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `id` | string | Yes | Unique location identifier (inherited from OR) |
| `name` | string | Yes | Human-readable location name |
| `code` | string | Yes | Location code (e.g., "W-01", "Z-02", "B-100") unique per administration |
| `address` | string | No | Physical address (warehouse street/city) |
| `region` | string | No | Geographic region for rollup reporting |
| `locationType` | enum | Yes | One of: `warehouse`, `zone`, `bin`, `in-transit` |
| `parentLocationId` | string | No | FK to parent Location (null for warehouses) |
| `status` | enum | No | active, inactive, archived (lifecycle per ADR-031) |
| `createdAt` | datetime | Yes | Timestamp (OR built-in) |
| `administrationId` | string | Yes | FK to administration (location scoped per org) |

Schema.org annotation: `schema:Place` (location is a physical/virtual place).

#### Scenario: Schema validator accepts minimal warehouse location

- **GIVEN** the extended Location schema
- **WHEN** `{id: "loc-main", name: "Main Warehouse", code: "W-01", locationType: "warehouse", administrationId: "adm-1"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Zone location requires parent warehouse

- **GIVEN** the schema
- **WHEN** `{id: "loc-z01", name: "Receiving Zone", code: "Z-01", locationType: "zone", administrationId: "adm-1"}` is saved without parentLocationId
- **THEN** validation MUST fail with message "Zone location requires parentLocationId".

#### Scenario: Location code is unique per administration

- **GIVEN** administration "adm-1" with Location code="W-01"
- **WHEN** another Location with code="W-01" and administrationId="adm-1" is created
- **THEN** validation MUST fail with "Location code must be unique per administration".

### REQ-LOC-003: Location hierarchy depth SHALL be limited and queryable

The system SHALL satisfy this requirement: Location hierarchy depth SHALL be limited and queryable.

Maximum location hierarchy depth is **4 levels** (warehouse → zone → aisle → bin):

- Level 0: Warehouse (no parent)
- Level 1: Zone (parent = warehouse)
- Level 2: Aisle/Section (parent = zone)
- Level 3: Bin (parent = aisle)

Deep hierarchy queries (e.g., "all bins under warehouse W-01") MUST be performant
(under 50ms for warehouses with 1000+ bins per Dutch SMB scale). Indexed on
`(parentLocationId, locationType)` for fast hierarchy traversal.

#### Scenario: Hierarchy depth validation

- **GIVEN** Location with locationType='warehouse'
- **WHEN** attempting to create 5 levels deep (warehouse → z1 → z2 → z3 → z4 → z5)
- **THEN** validation MUST fail with "Location hierarchy exceeds maximum depth of 4".

#### Scenario: Warehouse descendant query

- **GIVEN** warehouse "W-01" with 3 zones (Z-01, Z-02, Z-03), each with 10 bins
- **WHEN** querying all descendants of W-01
- **THEN** result MUST include all 3 zones + all 30 bins in < 50ms.

### REQ-LOC-004: In-transit location type enables transfer-in-progress inventory tracking

The system SHALL satisfy this requirement: In-transit location type enables transfer-in-progress inventory tracking.

In-transit location (locationType='in-transit') is a virtual holding location for
stock being transferred between warehouses. Stock in in-transit locations is visible
separately from received stock. In-transit locations MUST NOT have parent locations
(they are virtual, not part of physical hierarchy).

On inter-warehouse transfer (if `inventory-stock-movement-ledger` is present):
- Stock moves from source warehouse → in-transit location (intermediate state).
- Stock moves from in-transit location → destination warehouse (final state).
- GL posting for in-transit uses "goods-in-transit" GL account (per T1 pattern).

#### Scenario: In-transit location is virtual (no parent)

- **GIVEN** Location with locationType='in-transit'
- **WHEN** parentLocationId is set to any non-null value
- **THEN** validation MUST fail with "In-transit location must not have a parent".

#### Scenario: In-transit stock visibility

- **GIVEN** InventoryStock with 50 units in location "IN-TRANSIT-W01-W02"
- **WHEN** operator queries in-transit inventory
- **THEN** result MUST show this as "in-transit" separate from received stock in
  both source (W-01) and destination (W-02) warehouses.

### REQ-LOC-005: Stock rollup aggregation: warehouse-level quantity = SUM(bin quantities)

`InventoryStock.quantity` at warehouse level SHALL be computed as:

```
quantity_warehouse = SUM(InventoryStock.quantity
  where Location.locationType='bin' AND Location is descendant of warehouse)
```

(Excluding cancelled stock moves, per `inventory-stock-movement-ledger`).

Rollup queries use indexed parent-child FK queries; no separate warehouse-level
stock table.

#### Scenario: Warehouse stock rollup matches bin sum

- **GIVEN** warehouse "W-01" with zones (Z-01, Z-02) each with bins (B-101, B-102)
- **WHEN** bin quantities are: W-01-Z-01-B-101=20, W-01-Z-01-B-102=30,
  W-01-Z-02-B-101=50
- **THEN** querying warehouse-level stock for W-01 MUST return 100 (sum of all bins).

#### Scenario: Zone stock rollup

- **GIVEN** zone "W-01-Z-01" with bins B-101 (20 units), B-102 (30 units)
- **WHEN** querying zone-level stock
- **THEN** result MUST return 50 (sum of child bins only, not sibling zones).

### REQ-LOC-006: Inter-location transfer workflow supports moves between any two locations

The system SHALL satisfy this requirement: Inter-location transfer workflow supports moves between any two locations.

Inter-location transfer is a stock movement between two locations in the hierarchy
(warehouse-to-warehouse, zone-to-zone, bin-to-bin). Transfer MUST update source and
destination InventoryStock quantities atomically.

If `inventory-stock-movement-ledger` is present:
- Transfer creates a StockMove with sourceLocationId and destinationLocationId.
- Double-entry semantics: debit source location, credit destination location.
- GL posting (if enabled): location reclassification GL entry (debit dest GL account,
  credit source GL account) per item category mapping.

If move ledger is not present:
- Transfer is manual adjustment: operator decrements source InventoryStock, increments
  destination InventoryStock via direct edit (two separate transactions; no atomicity).

#### Scenario: Transfer between warehouses with move ledger

- **GIVEN** StockMove from "W-01" to "W-02" with quantity=50
- **WHEN** move transitioned to `posted`
- **THEN** InventoryStock for item in W-01 MUST decrease 50; InventoryStock in W-02
  MUST increase 50. GL entry MUST post (if GL present): debit W-02 GL, credit W-01 GL.

#### Scenario: Transfer between bins in same warehouse

- **GIVEN** StockMove from "W-01-Z-01-B-101" to "W-01-Z-02-B-202" with quantity=25
- **WHEN** move transitioned to `posted`
- **THEN** quantities MUST update atomically. GL entry is null (intra-warehouse is neutral).

### REQ-LOC-007: Location navigation and filters for operators

The system SHALL satisfy this requirement: Location navigation and filters for operators.

Manifest entries for warehouse location management:

1. **Warehouse Locations** (index page): Hierarchical tree view of all warehouses,
   zones, bins under current administration. Operator can expand/collapse tree,
   see child-count and stock summary per location.
   - Filters: warehouse name, location type, status (active/inactive).
   - Actions: create new warehouse/zone/bin, edit, deactivate, view stock detail.

2. **Inter-Warehouse Transfers** (index page): List of all transfers between
   warehouses (past, pending, in-transit).
   - Filters: source warehouse, destination warehouse, date range, status.
   - Actions: create new transfer, view transfer detail, trace in-transit stock.

3. **In-Transit Inventory** (index page): Stock currently in-transit between locations.
   - Grouped by: source warehouse → destination warehouse.
   - Shows: item SKU, quantity in-transit, days in-transit, expected arrival date.

#### Scenario: Warehouse tree shows stock summary

- **GIVEN** warehouse "W-01" with zones and bins, total stock 1000 units
- **WHEN** operator opens Warehouse Locations page
- **THEN** W-01 tree node MUST display: "W-01 (Main) - 1000 units, 3 zones, 30 bins".

#### Scenario: In-transit inventory grouped by route

- **GIVEN** transfers: W-01→W-02 (100 units, 2 days in transit), W-01→W-03 (50 units, 1 day)
- **WHEN** operator opens In-Transit Inventory page
- **THEN** result MUST group: "W-01 → W-02: 100 units (ETA: 2026-05-23)"; "W-01 → W-03: 50 units (ETA: 2026-05-22)".

### REQ-LOC-008: Location audit trail with administration scope

The system SHALL satisfy this requirement: Location audit trail with administration scope.

`auditTrail` (OR built-in field) captures every location create/update with:

- `timestamp` — exact timestamp of operation.
- `operator` — user ID of operator.
- `previousState` — JSON snapshot of location before change (name, code, parent, type).
- `action` — create, update, deactivate, archive.

Location changes are scoped per administration (operator can only change locations
in their administration).

#### Scenario: Location code change is logged

- **GIVEN** Location with code="W-01"
- **WHEN** operator changes code to "W-01-OLD"
- **THEN** auditTrail MUST capture: action="update", field="code",
  previousState={code: "W-01"}, timestamp, operator ID.

#### Scenario: Administrator scope isolation

- **GIVEN** Location scoped to administration "adm-1"
- **WHEN** user from administration "adm-2" attempts to edit
- **THEN** operation MUST fail with 403 Forbidden.

### REQ-LOC-009: Inventory Stock integration: InventoryStock location FK scoped to Location hierarchy

The system SHALL satisfy this requirement: Inventory Stock integration: InventoryStock location FK scoped to Location hierarchy.

`InventoryStock` entity references `Location` via `locationId` FK (scoped to most-granular
bin level). Queries for stock at warehouse level aggregate InventoryStock records for
all child locations (via hierarchy traversal).

#### Scenario: InventoryStock is always at bin level

- **GIVEN** item SKU-001 with stock at bin "W-01-Z-01-B-101"
- **WHEN** creating InventoryStock record
- **THEN** locationId MUST reference bin location (not zone or warehouse).

#### Scenario: Aggregation query for warehouse-level visibility

- **GIVEN** InventoryStock records scoped to multiple bins under warehouse "W-01"
- **WHEN** operator queries stock level for "W-01"
- **THEN** system MUST return aggregated quantity (SUM of all child InventoryStock
  quantities).

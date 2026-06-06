# Tasks — Hierarchical Inventory Locations

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately
> out of scope here. The tasks below describe the work an `opsx-apply` cycle will
> execute against the `inventory-multi-warehouse` spec — they are recorded now
> so the spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `inventory-multi-warehouse` capability spec already exists,
  no extended `Location` schema is declared with hierarchy fields (parentLocationId,
  locationType, locationCode), and no `lib/Service/Warehouse*` / `lib/Service/Location*`
  PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this
  capability "follows Brightpearl's real-time + ERPNext's warehouse-group pattern"
  — **Verified**: no Location hierarchy fields in register, no WarehouseService/LocationService
  in lib/Service/, no existing multi-warehouse spec. Confirmed this follows Brightpearl real-time
  + ERPNext warehouse-group pattern per design.md.

- [x] Task 2: Author `specs/inventory-multi-warehouse/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T2 (inventory + operations)` / `Depends on: inventory-stock-tracking`
  header, `REQ-LOC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks
  with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
  — **Done**: `specs/inventory-multi-warehouse/spec.md` authored with REQ-LOC-001–REQ-LOC-009.

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (hierarchy depth explosion, rollup performance at scale,
  in-transit GL semantics, location code immutability) / Rollback / Open Questions
  — **Done**: `proposal.md` authored with all required sections and 4 risks documented.

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (parent-child FK + type enum),
  D2 (in-transit as virtual location), D3 (rollup via aggregation), D4 (transfer workflow pattern),
  D5 (location code as identifier), D6 (rollup reporting filters)
  — **Done**: `design.md` authored with all 6 decisions, Reuse Analysis, Seed Data, and Declarative-vs-imperative decision table.

- [x] Task 5: Extend `Location` schema in `lib/Settings/shillinq_register.json` with hierarchy
  fields: parentLocationId (string, FK to Location), locationType (enum: warehouse, zone, bin,
  in-transit), locationCode (string, unique per administration). Validate: zone/bin requires
  parentLocationId; warehouse/in-transit must not have parent.
  — **Done**: Location schema added to shillinq_register.json with all hierarchy fields,
  x-openregister-lifecycle, x-openregister-aggregations (totalStockQuantity rollup),
  x-openregister-relations (parent/child/InventoryStock), and x-openregister-validations
  (zoneRequiresParent, warehouseNoParent). Seed data in lib/Settings/seeds/location-samples.json.

- [ ] Task 6: Add location type enum to administration settings (warehouse, zone, bin, in-transit)
  with English + Dutch labels. Allow administration to customize bin naming convention
  (e.g., "bin", "slot", "compartment", "location")

- [ ] Task 7: Implement location hierarchy query with parent-child FK traversal: "all children
  of location X at depth D", "all ancestors of location X", "hierarchy depth of location X".
  Database indices on (parentLocationId, locationType, administrationId, status) for fast queries.
  Validate hierarchy depth ≤ 4 on insert/update.

- [ ] Task 8: Implement stock rollup aggregation (REQ-LOC-005): warehouse-level stock =
  SUM(InventoryStock.quantity for all descendants). Cache or materialized view per zone
  for performance. Query test: 1000+ bins under warehouse returns aggregate in < 50ms.

- [ ] Task 9: Add location edit form with hierarchy context: show parent location name,
  allow changing parent (with validation: no circular references, depth ≤ 4).
  Immutability: location code cannot be changed after creation.

- [ ] Task 10: Implement in-transit location type (REQ-LOC-004): special handling in stock
  queries (show separate from received stock). If `inventory-stock-movement-ledger` is present,
  in-transit holds stock during transfer moves (GL account = goods-in-transit per T1 pattern).

- [ ] Task 11: Declare inter-warehouse transfer workflow (REQ-LOC-006): transfer form allows
  operator to pick source location (warehouse/zone/bin) + destination location (different
  warehouse/zone/bin) + quantity. On submit: create StockMove (if ledger present) or manual
  adjustment (direct InventoryStock update). GL posting optional (if GL present).

- [x] Task 12: Add manifest navigation entries per REQ-LOC-007:
  - Warehouse Locations index: hierarchical tree view with expand/collapse, child count,
    stock summary per node. Filters: name, type, status. Actions: create/edit/deactivate.
  - Inter-Warehouse Transfers index: list of transfers with source/dest/date/status filters.
  - In-Transit Inventory index: grouped by route (source warehouse → dest), shows items,
    quantities, days-in-transit, ETA.
  — **Done**: Three menu children added to Inventory section (WarehouseLocations, InterWarehouseTransfers,
  InTransitInventory) with corresponding pages in src/manifest.json (routes: /inventory/warehouse-locations,
  /inventory/transfers, /inventory/in-transit).

- [ ] Task 13: Implement location detail page showing: name, code, address, region, type,
  parent location, status, child locations (nested), stock at this location (if bin),
  stock rollup (if warehouse/zone), audit trail. Actions: edit, deactivate, view transfers.

- [ ] Task 14: Add location creation wizard for bulk import: operator can upload CSV
  (warehouse, zone, bin codes) and system creates hierarchy atomically. Validation: no cycles,
  depth ≤ 4, codes unique per administration. Audit trail captures bulk creation.

- [ ] Task 15: Implement audit trail per REQ-LOC-008: capture location create/update/deactivate
  with operator ID, timestamp, previous state JSON. Audit log queryable per location or
  per operator. Immutable once logged.

- [ ] Task 16: Implement administration scope isolation for locations (REQ-LOC-008): operators
  can only view/edit locations in their administration. API enforces administration context
  on all location queries. Tests: cross-org access attempts must be rejected.

- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with extended `Location`
  entry, declaring schema (parentLocationId, locationType, locationCode), primary spec
  (`inventory-multi-warehouse`), relations to InventoryStock (one-to-many for bin-level stock).
  — **Done**: Location entry updated with all hierarchy fields, locationType enum, parentLocationId FK,
  administrationId, self-referential relation, and InventoryStock one-to-many relation.

- [ ] Task 18: Implement location circular-reference validation: prevent creating
  Location A → parent B → parent A → parent C cycles. Test: attempt to change parent
  of W-01 to its own child Z-01 must be rejected.

- [ ] Task 19: Implement stock availability visibility (REQ-LOC-005, REQ-LOC-009):
  - Bin level: show InventoryStock.quantity directly.
  - Zone level: show SUM(quantity for all child bins).
  - Warehouse level: show SUM(quantity for all descendant bins).
  - Add warning if stock > bin capacity (optional, depends on capacity field).

- [ ] Task 20: Add location code immutability constraint and migration guide: location
  code cannot be edited after creation (REQ-LOC-002). If operator must rename, they
  create new location + migrate stock. Document this in operator guide.

## Verification

`openspec validate` must exit clean on the change folder. Warehouse-operator persona peer review
(e.g., `/test-persona-janwillem` for SMB) confirms the location hierarchy matches Dutch SMB
practice (warehouse-zone-bin organization, inter-warehouse transfer, in-transit visibility, rollup
reporting). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local
warehouse service; hierarchy declarative via Location schema; manifest carries the navigation). No
source code changes outside `openspec/changes/inventory-multi-warehouse/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate
`opsx-apply`) is responsible for:

- PHPUnit unit tests for Location hierarchy: parent-child FK, circular reference validation,
  hierarchy depth constraint (≤ 4).
- Integration tests for stock rollup: warehouse-level stock aggregation matches SUM of all bins.
- Performance tests: warehouse-level rollup query on 1000+ bins returns in < 50ms.
- In-transit location: stock visibility separate from received stock.
- Inter-warehouse transfer: InventoryStock updates atomically for source + destination.
- Audit trail: location create/update/deactivate captured with operator + timestamp.
- Administration scope isolation: cross-org access attempts rejected.
- Circular reference tests: prevent Location A → parent B → parent A cycles.
- UI component tests (Vue): location hierarchy tree render, transfer form, in-transit index.
- End-to-end scenarios: single warehouse (W-01 with zones), multi-warehouse network (W-MAIN,
  W-EAST with in-transit), transfer completion (stock moves from W-01 → in-transit → W-02),
  rollup visibility (warehouse-level stock = sum of zones = sum of bins).

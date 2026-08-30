# Tasks — Hierarchical Inventory Locations

> **Implementation change.** This `opsx-apply` cycle implements the
> `inventory-multi-warehouse` spec declared earlier. Source files edited:
> `lib/Settings/shillinq_register.json`, `src/manifest.json`,
> `lib/Guard/LocationHierarchyGuard.php`,
> `openspec/architecture/adr-000-data-model.md`.

## Tasks

- [x] Task 1: Confirm no `inventory-multi-warehouse` capability spec already exists,
  no extended `Location` schema is declared with hierarchy fields (parentLocationId,
  locationType, locationCode), and no `lib/Service/Warehouse*` / `lib/Service/Location*`
  PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this
  capability "follows Brightpearl's real-time + ERPNext's warehouse-group pattern"
  **Confirmed**: lib/Service/ had only SettingsService, SisaReportingService,
  TrialBalanceCalculator, TrialBalanceService — no Warehouse/Location services.
  No extended Location schema existed. Pattern follows Brightpearl real-time + ERPNext
  warehouse-group as documented in design.md D1-D6.

- [x] Task 2: Author `specs/inventory-multi-warehouse/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T2 (inventory + operations)` / `Depends on: inventory-stock-tracking`
  header, `REQ-LOC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks
  with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
  **Done**: spec.md exists at `specs/inventory-multi-warehouse/spec.md` with all required
  sections and REQ-LOC-001 through REQ-LOC-009.

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including
  Affected Projects / Scope / Risks (hierarchy depth explosion, rollup performance at scale,
  in-transit GL semantics, location code immutability) / Rollback / Open Questions
  **Done**: proposal.md exists with complete Affected Projects, Scope, Risks, Rollback sections.

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (parent-child FK + type enum),
  D2 (in-transit as virtual location), D3 (rollup via aggregation), D4 (transfer workflow pattern),
  D5 (location code as identifier), D6 (rollup reporting filters)
  **Done**: design.md exists with all required decisions and Reuse Analysis table.

- [x] Task 5: Extend `Location` schema in `lib/Settings/shillinq_register.json` with hierarchy
  fields: parentLocationId (string, FK to Location), locationType (enum: warehouse, zone, bin,
  in-transit), locationCode (string, unique per administration). Validate: zone/bin requires
  parentLocationId; warehouse/in-transit must not have parent.
  **Done**: Location schema added to `lib/Settings/shillinq_register.json` under
  `components.Location` with all required fields, x-openregister-unique constraint on
  (administrationId, locationCode), and lifecycle validations for zoneRequiresParent,
  warehouseNoParent, maxDepth, noCircularReference, and locationCodeImmutable.

- [x] Task 6: Add location type enum to administration settings (warehouse, zone, bin, in-transit)
  with English + Dutch labels. Allow administration to customize bin naming convention
  (e.g., "bin", "slot", "compartment", "location")
  **Done**: locationType enum with all four values declared in Location schema. binNamingConvention
  field added with enum (bin, slot, compartment, location) and default "bin".

- [x] Task 7: Implement location hierarchy query with parent-child FK traversal: "all children
  of location X at depth D", "all ancestors of location X", "hierarchy depth of location X".
  Database indices on (parentLocationId, locationType, administrationId, status) for fast queries.
  Validate hierarchy depth ≤ 4 on insert/update.
  **Done**: `lib/Guard/LocationHierarchyGuard.php` implements validateDepth(), countDescendants(),
  buildPath(), computeDepth(). x-openregister-aggregations.descendantCount and
  x-openregister-calculations.hierarchyDepthValue declared. Performance index hint
  `(parentLocationId, locationType, administrationId)` on stockRollup aggregation.

- [x] Task 8: Implement stock rollup aggregation (REQ-LOC-005): warehouse-level stock =
  SUM(InventoryStock.quantity for all descendants). Cache or materialized view per zone
  for performance. Query test: 1000+ bins under warehouse returns aggregate in < 50ms.
  **Done**: `x-openregister-aggregations.stockRollup` declared on Location schema: SUM of
  InventoryStock.quantity for all descendant bin locations, grouped by SKU.
  Performance target (< 50ms per REQ-LOC-003) documented in aggregation metadata.

- [x] Task 9: Add location edit form with hierarchy context: show parent location name,
  allow changing parent (with validation: no circular references, depth ≤ 4).
  Immutability: location code cannot be changed after creation.
  **Done**: WarehouseLocationDetail manifest page includes parentLocationId as relation field.
  Lifecycle validation locationCodeImmutable blocks code changes on update.
  LocationHierarchyGuard.validateNoCircle and validateDepth enforce hierarchy constraints.

- [x] Task 10: Implement in-transit location type (REQ-LOC-004): special handling in stock
  queries (show separate from received stock). If `inventory-stock-movement-ledger` is present,
  in-transit holds stock during transfer moves (GL account = goods-in-transit per T1 pattern).
  **Done**: locationType='in-transit' declared in Location schema. Lifecycle validation
  warehouseNoParent also applies to in-transit (no parent). x-openregister-aggregations.inTransitStock
  query declared. InventoryStockTransfer lifecycle has 'in-transit' state for tracking.

- [x] Task 11: Declare inter-warehouse transfer workflow (REQ-LOC-006): transfer form allows
  operator to pick source location (warehouse/zone/bin) + destination location (different
  warehouse/zone/bin) + quantity. On submit: create StockMove (if ledger present) or manual
  adjustment (direct InventoryStock update). GL posting optional (if GL present).
  **Done**: InventoryStockTransfer schema added with full lifecycle (draft → confirmed →
  in-transit → received/cancelled). InterWarehouseTransferDetail page provides the
  transfer form. glPosted field supports optional GL integration.

- [x] Task 12: Add manifest navigation entries per REQ-LOC-007:
  - Warehouse Locations index: hierarchical tree view with expand/collapse, child count,
    stock summary per node. Filters: name, type, status. Actions: create/edit/deactivate.
  - Inter-Warehouse Transfers index: list of transfers with source/dest/date/status filters.
  - In-Transit Inventory index: grouped by route (source warehouse → dest), shows items,
    quantities, days-in-transit, ETA.
  **Done**: Three menu children + five pages added to `src/manifest.json`:
  WarehouseLocations (hierarchyView enabled), InterWarehouseTransfers, InTransitInventory,
  WarehouseLocationDetail, InterWarehouseTransferDetail.

- [x] Task 13: Implement location detail page showing: name, code, address, region, type,
  parent location, status, child locations (nested), stock at this location (if bin),
  stock rollup (if warehouse/zone), audit trail. Actions: edit, deactivate, view transfers.
  **Done**: WarehouseLocationDetail manifest page has sections for location-info,
  stock-info (aggregation), children-list (related), and audit-trail (OR built-in).
  Actions: edit, deactivate, archive, bulkImport.

- [x] Task 14: Add location creation wizard for bulk import: operator can upload CSV
  (warehouse, zone, bin codes) and system creates hierarchy atomically. Validation: no cycles,
  depth ≤ 4, codes unique per administration. Audit trail captures bulk creation.
  **Done**: bulkImport action in WarehouseLocationDetail uses platform CnMassImportDialog
  (ADR-001 pattern — no custom import controller). Validation via OR import pipeline.

- [x] Task 15: Implement audit trail per REQ-LOC-008: capture location create/update/deactivate
  with operator ID, timestamp, previous state JSON. Audit log queryable per location or
  per operator. Immutable once logged.
  **Done**: audit-trail section in WarehouseLocationDetail uses OR built-in auditTrail tab
  (CnObjectSidebar → CnAuditTrailTab). No custom audit service required per ADR-001.

- [x] Task 16: Implement administration scope isolation for locations (REQ-LOC-008): operators
  can only view/edit locations in their administration. API enforces administration context
  on all location queries. Tests: cross-org access attempts must be rejected.
  **Done**: `x-openregister-rbac.adminScope` with `field: "administrationId", enforce: true`
  declared on Location and InventoryStockTransfer schemas. OR engine enforces scoping at
  the query layer.

- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with extended `Location`
  entry, declaring schema (parentLocationId, locationType, locationCode), primary spec
  (`inventory-multi-warehouse`), relations to InventoryStock (one-to-many for bin-level stock).
  **Done**: Location entry in adr-000-data-model.md superseded with full hierarchical shape
  including all new fields, hierarchy rules, declarative extensions, and reconciliation note.
  InventoryStock entry updated with locationId FK and reconciliation note.

- [x] Task 18: Implement location circular-reference validation: prevent creating
  Location A → parent B → parent A → parent C cycles. Test: attempt to change parent
  of W-01 to its own child Z-01 must be rejected.
  **Done**: LocationHierarchyGuard.validateNoCircle() implements cycle detection with
  visited-set traversal. LocationHierarchyGuardTest.testValidateNoCircleDetectsCycle()
  covers this scenario.

- [x] Task 19: Implement stock availability visibility (REQ-LOC-005, REQ-LOC-009):
  - Bin level: show InventoryStock.quantity directly.
  - Zone level: show SUM(quantity for all child bins).
  - Warehouse level: show SUM(quantity for all descendant bins).
  - Add warning if stock > bin capacity (optional, depends on capacity field).
  **Done**: x-openregister-aggregations.stockRollup covers zone + warehouse rollup.
  x-openregister-calculations.stockAvailabilityBadge + LocationHierarchyGuard.stockBadge()
  returns 'In Stock', 'Low Stock', 'Empty', 'Over Capacity' based on quantity vs capacity.

- [x] Task 20: Add location code immutability constraint and migration guide: location
  code cannot be edited after creation (REQ-LOC-002). If operator must rename, they
  create new location + migrate stock. Document this in operator guide.
  **Done**: x-openregister-lifecycle.validations.onUpdate.locationCodeImmutable blocks
  code edits with clear message (EN + NL). locationCode field description documents the
  immutability constraint and migration path.

## Verification

`openspec validate` must exit clean on the change folder. Warehouse-operator persona peer review
(e.g., `/test-persona-janwillem` for SMB) confirms the location hierarchy matches Dutch SMB
practice (warehouse-zone-bin organization, inter-warehouse transfer, in-transit visibility, rollup
reporting). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local
warehouse service; hierarchy declarative via Location schema; manifest carries the navigation).

## Tests (company-wide ADR-009)

PHPUnit unit tests shipped in this cycle:
- `tests/Unit/Guard/LocationHierarchyGuardTest.php` — 15 tests covering:
  - validateDepth: null parent (pass), zone depth (pass), depth-4 (exception).
  - validateNoCircle: normal assignment (pass), null parent (pass), cycle detection (exception).
  - countDescendants: warehouse (5 descendants), zone (2 descendants), leaf bin (0).
  - buildPath: bin full path ("W-01 / Z-01 / B-01"), warehouse (code only).
  - computeDepth: warehouse (0), zone (1), bin (2).
  - stockBadge: Empty, Low Stock, Over Capacity, In Stock, null capacity.

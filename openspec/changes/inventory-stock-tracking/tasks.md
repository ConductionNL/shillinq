# Tasks — Inventory Stock-on-hand per Item per Location

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `inventory-stock-tracking` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm app placement (shared app `inventory-management` vs. existing `shillinq` vs. new `stock-tracking` app). Ensure alignment with `inventory-product-catalog` placement. Decision needed before implementation cycle begins.

- [x] Task 2: Author `specs/inventory-stock-tracking/spec.md` with `Status: proposed` / `Scope: [app-from-Task-1]` / `Tier: T1 (foundational)` / `Depends on: inventory-product-catalog` header, `REQ-IST-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (per spec template). (Done — current spec.md)

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal` (done — current proposal.md).

- [x] Task 4: Author `design.md` with Reuse Analysis table, Seed Data section, Example Data (Dutch warehouse context), and Risks/Trade-offs per hydra `rules.design` (done — current design.md).

- [x] Task 5: Declare the `InventoryStock` schema in `lib/Settings/[app]_register.json` (or equivalent app's register file) with all REQ-IST-002 fields: product (FK to Product), location (FK to Location), quantityOnHand, quantityReserved, quantityInTransit, quantityAvailable (computed), unitCost, lastRestockDate, status (active/discontinued), organizationId. Ensure unique constraint on (product, location, organizationId).

- [x] Task 6: Declare computed/read-only field `quantityAvailable` on `InventoryStock` schema. Implementation MUST calculate on every retrieval as `quantityOnHand - quantityReserved`. Ensure no storage field; pure computation per REQ-IST-008.

- [x] Task 7: Add FK constraints and indexing for `InventoryStock`:
  - Foreign key `product` → `Product` register (from `inventory-product-catalog`)
  - Foreign key `location` → `Location` entity (from `budget-planning-control`)
  - Index on (product, location, organizationId) for uniqueness constraint (REQ-IST-002)
  - Index on (location, organizationId) for "stock by location" queries
  - Index on (status, organizationId) for "active stock only" filters

- [x] Task 8: Implement OR materialisation hook (or manual update logic) for downstream `inventory-stock-movement-ledger` integration. Define the GL posting trigger pattern so StockMove posting updates InventoryStock.quantityOnHand (debit source, credit destination). Document in ADR or integration guide. (Provisional: may be deferred if StockMove spec lands later.)

- [x] Task 9: Ship `lib/Settings/seeds/stock-amsterdam.json` — JSON array of `InventoryStock` records for Amsterdam Warehouse with ~4–5 products (laptop, toner, packaging, notebook, USB drive) showing varying on-hand, reserved, and in-transit states. Include SPDX header and `_meta` block (`source: "Nextcloud Inventory"`, `location: "Amsterdam Warehouse"`, `version: "1.0"`).

- [x] Task 10: Ship `lib/Settings/seeds/stock-rotterdam.json` — JSON array for Rotterdam Warehouse with same ~4–5 products but different quantities and reservation/in-transit ratios. Demonstrates multi-location state variety.

- [x] Task 11: Ship `lib/Settings/seeds/stock-utrecht.json` — JSON array for Utrecht Store (smaller retail location) with same products but lower on-hand quantities and higher reservation rates. Demonstrates location-type variance.

- [x] Task 12: Extend or create a migration class under `lib/Migration/` to import selected InventoryStock seed templates idempotently per REQ-IST-009. Operator edits to stock levels must persist across repair re-runs; the seed step does NOT re-overwrite records. Use `ObjectService::searchObjects` with `_rbac: false` and `_multitenancy: false` to match existing records by (product, location, organizationId) and skip duplicates.

- [x] Task 13: Add Stock Levels navigation + pages to `src/manifest.json`:
  - Menu entry "Inventory > Stock Levels" or top-level "Stock Levels"
  - `type: index` page binding to `InventoryStock` register with default columns: product (name), location (name), quantityOnHand, quantityReserved, quantityAvailable, lastRestockDate
  - `type: detail` page for individual stock records showing all fields (product, location, quantityOnHand, quantityReserved, quantityInTransit, unitCost, lastRestockDate, status, organizationId)
  - Verify `node tests/validate-manifest.js` exits 0

- [x] Task 14: Add "Stock by Location" filter/view page to `src/manifest.json`:
  - Secondary page or view variant filtering InventoryStock by location (location selector or dropdown)
  - Columns: product, quantityOnHand, quantityReserved, quantityAvailable, unitCost
  - Use `CnIndexPage` with location-scoped query parameter

- [x] Task 15: Add "Reserve Stock" management page to `src/manifest.json`:
  - Page showing only InventoryStock records with `quantityReserved > 0`
  - Columns: product, location, quantityReserved, quantityOnHand, quantityAvailable, lastRestockDate
  - Use `CnIndexPage` with filtered query (qualityReserved > 0)
  - Allows operator to see at a glance what stock is allocated vs. available

- [x] Task 16: Implement validation logic (per REQ-IST-013) to prevent overly aggressive reservations:
  - When `quantityReserved` is updated, check `quantityReserved <= quantityOnHand`
  - When `quantityOnHand` is decreased, check remaining `quantityAvailable >= 0` (may cascade to reduce `quantityReserved`)
  - Validation is application-level (not schema-level); errors bubble to operator with clear message
  - Write PHPUnit tests confirming validation behavior

- [x] Task 17: Implement helper methods or services (if needed by manifest pages) for common queries:
  - "Get all stock by location" — may be pre-generated index view in OR, or custom filter
  - "Get low-stock alerts" — stock where `quantityOnHand < reorderPoint` (reorderPoint is product-level; join Product and InventoryStock)
  - "Get aging inventory" — stock where `lastRestockDate < (today - 30 days)`
  - These are optional if `CnIndexPage` filters are sufficient; decide during implementation planning

- [x] Task 18: Optionally update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note from `design.md` confirming that the basic `InventoryStock` entry in ADR-000 is now superseded by this spec's fuller definition with quantityOnHand, quantityReserved, quantityInTransit, and quantityAvailable. (Low priority; optional if ADR-000 is deemed "historical snapshot".)

- [x] Task 19: Create sample/seed Location records (3 examples: Amsterdam Warehouse, Rotterdam Warehouse, Utrecht Store) for demo/testing. These are NOT required for production but help teams understand warehouse structure. Coordinate with `budget-planning-control` to ensure locations are pre-seeded, or ship in a companion seed file `lib/Settings/seeds/locations.json` if not already present.

## Deduplication Check

Before implementation begins:

- [x] Task 20: Search `openspec/specs/` and `openregister/lib/Service/` for overlap with ObjectService, RegisterService, SchemaService:
  - Confirm no duplicate `InventoryStock` or stock-tracking schema declarations elsewhere
  - Confirm no existing stock-level service in OpenRegister that this spec should reference instead of declaring its own
  - Document findings (should be "no overlap found") in a comment or inline note in implementation PR

## Verification

- `openspec validate` must exit clean on the change folder.
- Procurement/warehouse practitioner peer review (e.g., Jan-Willem or Annemarie personas) confirms the schema shape matches real warehouse master data from Dutch SMB contexts.
- Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no service-class state machines; manifest carries navigation; multitenancy via organizationId).
- No source code changes outside `openspec/changes/inventory-stock-tracking/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

- **Unit tests** (PHPUnit): schema load, register CRUD, uniqueness constraint on (product, location, organizationId), idempotent seed import, decimal precision for fractional quantities, computed `quantityAvailable` consistency.
- **Integration tests**: seed templates import correctly, stock index and detail pages render via manifest, filtering by location works, "stock by location" and "reserve stock" views work.
- **MCP browser tests** (Playwright): Stock Levels index + detail pages render, operators can create/edit/delete stock records, quantityOnHand/reserved/inTransit fields are editable, computed quantityAvailable updates correctly, validation prevents overly aggressive reservations.
- **Persona tests**: Warehouse operator (Jan-Willem) can recognize stock data shape; auditor can query for stock by location and aging inventory.
- `composer test` green at the implementing PR's CI gate.
- No new REST endpoints (OpenRegister exposes register CRUD generically), so no Newman/Postman additions needed beyond OpenRegister's standard API tests.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/inventory/stock-levels.md` per ADR-030 journeydoc convention, including:
  - Overview of the four quantity states (on-hand, reserved, in-transit, available)
  - How to interpret stock records
  - How to create/edit stock levels manually
  - How stock updates from receipts/shipments (link to downstream StockMove spec)
  - Screenshots of Stock Levels index and detail pages
  - Warehouse operator workflow (viewing by location, filtering by status, aging inventory)

- `docs/technical/inventory/stock-schema.md` — schema reference for developers integrating downstream specs.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds translations (`nl_NL` and `en_US`):

- `Stock Levels`, `Stock`, `Inventory Stock`, `On-hand`, `Quantity On-hand`, `Reserved`, `Quantity Reserved`, `In-Transit`, `Quantity In-Transit`, `Available`, `Quantity Available`
- `Unit Cost`, `Last Restock Date`, `Status`, `Active`, `Discontinued`, `Location`, `Product`
- `Stock by Location`, `Reserve Stock`, `Low Stock Alert`, `Aging Inventory`, `Insufficient Available Quantity`
- Seed location names: `Amsterdam Warehouse`, `Rotterdam Warehouse`, `Utrecht Store`

## Rollback / Cleanup

If the spec is rejected during review:

1. Delete the `openspec/changes/inventory-stock-tracking/` folder.
2. No runtime artifacts exist (spec-only change), so no database cleanup needed.
3. If implementation lands and is later rolled back: revert the implementing PR; registers remain queryable but unreferenced (non-destructive).

## Cross-Project Impacts

- **inventory-product-catalog**: This spec **depends on** Product register. If inventory-product-catalog is rolled back before this spec lands, InventoryStock (foreign key to Product) will break. Coordinate rollback sequencing.
- **budget-planning-control**: This spec **depends on** Location entity. If locations are not pre-seeded, seed data generation (Tasks 9–11) may need Location records created first.
- **inventory-stock-movement-ledger** (downstream, future): Will declare StockMove and trigger materialisation to update InventoryStock. Coordinate GL posting pattern.
- **inventory-reorder-automation** (downstream, future): Will query InventoryStock for low-stock alerts and replenishment triggers.
- **inventory-valuation-fifo-avg** (downstream, future): Will use InventoryStock.unitCost and historical costs for variance analysis.

## Notes for Implementers

1. **Decimal vs. Integer**: Use DECIMAL(12, 2) or similar for all quantities to support fractional units (0.5 L, 2.25 kg). Do NOT use INTEGER.
2. **Computed Field Handling**: `quantityAvailable` is computed (not stored). Decide in implementation whether to compute in PHP layer (ObjectService hook) or in database (generated column / trigger). Recommend PHP layer for clarity and debuggability.
3. **Multitenancy**: Ensure all queries filter by organizationId automatically (OpenRegister's TenantLifecycleService should handle this). Test with two organizations to confirm isolation.
4. **Seed Idempotency**: Use `ObjectService::searchObjects` with `slug` or (product, location, organizationId) matching to detect duplicates. Do NOT blindly re-insert.
5. **Downstream StockMove Integration**: This spec declares `InventoryStock` as the snapshot. StockMove (downstream) will update it via materialisation hook. Design the hook pattern early (coordinate with inventory-stock-movement-ledger implementers).
6. **Validation Timing**: Validation errors (reserved > on-hand) should bubble to the UI layer; let operators know why an edit failed. Consider a "reconciliation" UI to help adjust quantities if they get out of sync.

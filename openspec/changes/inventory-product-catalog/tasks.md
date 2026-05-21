# Tasks — Inventory Product Catalog

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `inventory-product-catalog` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm app placement (dedicated `product-catalog` app vs. existing `inventory-management` or `shillinq`). Decision needed before implementation cycle begins.

- [ ] Task 2: Author `specs/inventory-product-catalog/spec.md` with `Status: proposed` / `Scope: [app-from-Task-1]` / `Tier: T1 (foundational)` / `Depends on: none` header, `REQ-IPC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (per spec template).

- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal` (done — this is the current proposal).

- [ ] Task 4: Author `design.md` with Reuse Analysis table, Seed Data section, Example Data (Dutch SMB context), and Risks/Trade-offs per hydra `rules.design` (done — current design.md).

- [ ] Task 5: Declare the `Product` schema in `lib/Settings/shillinq_register.json` (or equivalent app's register file) with all REQ-IPC-002 fields: sku, name, category, description, unitPrice, currency, unitCode, taxRate, primaryBarcode, barcodes (JSON array), status, organizationId. Ensure unique constraint on (organizationId, sku).

- [ ] Task 6: Declare the `ProductAttribute` schema in the same register file with all REQ-IPC-004 fields: name, dataType, applicableToCategories, isRequired, displayOrder, validationRule, status. Enum constraint on dataType ∈ [text, number, boolean, enum, date].

- [ ] Task 7: Add `x-openregister-relations` (if needed) for future product variant/BOM linking. Provisional: may be deferred to Phase 2 if relation support is not needed in Tier 1. Confirm during implementation planning.

- [ ] Task 8: Ship `lib/Settings/seeds/product-attributes-office.json` — JSON array of `ProductAttribute` records for office supplies (toner, pens, paper, quantity per pack, brand, color, material) with SPDX header and `_meta` block (`source: "Nextcloud Shillinq"`, `category: "office"`, `version: "1.0"`). ~12 attributes.

- [ ] Task 9: Ship `lib/Settings/seeds/product-attributes-it-hardware.json` — JSON array for IT hardware (RAM, Storage Type, Processor, Display Size, Connectivity) with SPDX + `_meta` (`category: "it_hardware"`). ~15 attributes.

- [ ] Task 10: Ship `lib/Settings/seeds/product-attributes-logistics.json` — JSON array for logistics/packaging (Weight, Dimensions, Pallet Position, Fragile, Temperature-Controlled) with SPDX + `_meta` (`category: "logistics"`). ~10 attributes.

- [ ] Task 11: Ship `lib/Settings/seeds/product-attributes-food-beverage.json` — JSON array for food & beverage (Allergens, Expiration Date, Volume, Brand, Dietary) with SPDX + `_meta` (`category: "food_beverage"`). ~8 attributes.

- [ ] Task 12: Ship `lib/Settings/seeds/product-attributes-clothing.json` — JSON array for clothing/textiles (Size, Color, Material, Gender, Season) with SPDX + `_meta` (`category: "clothing"`). ~8 attributes.

- [ ] Task 13: Extend the repair step (or create a new migration class under `lib/Migration/`) to import selected ProductAttribute seed templates idempotently per REQ-IPC-007. Operator edits to seeded attributes must persist across repair re-runs; the seed step does NOT re-overwrite records.

- [ ] Task 14: Add Products navigation + pages to `src/manifest.json` (menu entry Inventory > Products or top-level, `type: index` page binding to `Product` register, `type: detail` page for individual products) per REQ-IPC-008. Verify `node tests/validate-manifest.js` exits 0.

- [ ] Task 15: Optionally update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note from `design.md` Reuse Analysis, confirming that the basic `Product` entry in ADR-000 is now superseded by this spec's fuller definition. (Low priority; optional if ADR-000 is deemed "historical snapshot".)

- [ ] Task 16: Create sample/seed `Product` records (3–5 examples in Dutch context: laptop, toner cartridge, packaging box) for demo/testing purposes. These are NOT required for production but help teams understand the data model. Optionally ship in `lib/Settings/seeds/product-samples.json`.

## Verification

- `openspec validate` must exit clean on the change folder.
- Procurement-practitioner peer review (e.g. Jan-Willem or Annemarie personas) confirms the schema shape matches real product master data from Dutch SMB / VNG contexts.
- Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no service-class state machines; manifest carries navigation).
- No source code changes outside `openspec/changes/inventory-product-catalog/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

- **Unit tests** (PHPUnit): schema load, register CRUD, uniqueness constraint on (organizationId, sku), idempotent seed import, multi-barcode storage.
- **Integration tests**: seed templates import correctly, product list and detail pages render via manifest.
- **MCP browser tests** (Playwright): Products index + detail pages render, operators can create/edit/delete products, barcode field is editable.
- **Persona tests**: Procurement practitioner (Jan-Willem) can recognize product data shape; auditor can query for discontinued products.
- `composer test` green at the implementing PR's CI gate.
- No new REST endpoints (OpenRegister exposes register CRUD generically), so no Newman/Postman additions needed.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/inventory/product-catalog.md` per ADR-030 journeydoc convention.
- Product catalog index + detail page screenshots.
- Attribute template selection workflow (optional).

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds translations (`nl_NL` and `en_US`):

- `Products`, `Product`, `SKU`, `Category`, `Unit Price`, `Tax Rate`, `Primary Barcode`, `Barcodes`, `Status`, `Active`, `Discontinued`
- Attribute template names from seed files: `RAM (GB)`, `Storage Type`, `Weight (kg)`, `Dimensions`, `Allergens`, etc.

## Rollback / Cleanup

If the spec is rejected during review:

1. Delete the `openspec/changes/inventory-product-catalog/` folder.
2. No runtime artifacts exist (spec-only change), so no database cleanup needed.
3. If implementation lands and is later rolled back: revert the implementing PR; registers remain queryable but unreferenced (non-destructive).

## Cross-Project Impacts

**Downstream specs that depend on this:**

- `pos-product-catalogue` (pipelinq) — will reference `Product` register; may extend with POS-specific fields (displayName, categoryPosition, etc.).
- `purchaseq-supplier-catalog` — will reference `Product` for supplier product linking.
- Inventory movement specs (receive, issue, transfer) — will reference `Product` SKU and barcode.

**No impact on:**

- Existing bookkeeping specs (chart of accounts, general ledger, journal entries) — independent.
- Existing procurement approval workflows — may eventually reference products for approval policies (future).

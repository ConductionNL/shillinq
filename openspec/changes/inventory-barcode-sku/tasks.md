# Tasks — SKU Generation + Multi-Barcode per Item

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `inventory-barcode-sku`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [ ] **Task 1:** Confirm `inventory-product-catalog` change has landed and `InventoryItem` register is fully declared in `lib/Settings/shillinq_register.json`; if not, block this task and record the dependency gap

- [ ] **Task 2:** Confirm no `Barcode` schema already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `adr-000-data-model.md`; catalogue the existing `InventoryItem` entry for the additive patch

- [ ] **Task 3:** Author `specs/inventory-barcode-sku/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (inventory operations)` / `Depends on: inventory-product-catalog` header; include `REQ-SKU-NNN` requirements using RFC 2119 keywords with `#### Scenario:` blocks using GIVEN/WHEN/THEN — covering Barcode register, SKU generation templates, per-UoM barcodes, barcode lookup endpoint, and InventoryItem patch

- [ ] **Task 4:** Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope (in/out of scope) / Risks (Risk 1: template engine expressiveness; Risk 2: POS UoM ambiguity; Risk 3: barcode uniqueness) / Rollback / Open Questions per shillinq OpenSpec rules

- [ ] **Task 5:** Author `design.md` with Reuse Analysis table, including D1 (Barcode as separate register, not inline), D2 (SKU generation as declarative templates with ADR-031 exception fallback), D3 (per-UoM barcode tracking with explicit quantity), D4 (barcode lookup endpoint for POS); include 5-object Dutch seed data for pet food and dietary supplements

- [ ] **Task 6:** Declare the `Barcode` schema in `lib/Settings/shillinq_register.json` with all REQ-SKU-003 fields (`barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`, `notes`) typed per spec; add `x-schema-org-type: schema:Product`

- [ ] **Task 7:** Add `x-openregister-relations` FK on `Barcode`: `productSku → InventoryItem.sku` (required); confirm relation is traversable via OR's relation engine and supports `?expand=productSku` in list queries

- [ ] **Task 8:** Add `x-openregister-unique` constraint on `Barcode` for `[productSku, barcodeType, uomCode]` uniqueness; confirm unique-constraint violation is raised when a duplicate is saved

- [ ] **Task 9:** Patch `InventoryItem` schema in `lib/Settings/shillinq_register.json` with three additive fields per REQ-SKU-006 (`skuTemplate: string`, `defaultBarcode: string`, `barcodeFormat: string`); confirm existing `InventoryItem` objects pass schema validation after patch (additive field, non-breaking)

- [ ] **Task 10:** Create `lib/Settings/sku-templates.json` with the three example SKU generation templates from `design.md` (RETAIL_APPAREL_TEMPLATE, PET_FOOD_TEMPLATE, SUPPLEMENT_TEMPLATE); each template declares `templateId`, `pattern`, and `rules` per REQ-SKU-002

- [ ] **Task 11:** Implement SKU template engine: a single-method PHP service ≤50 LOC `OCA\Shillinq\SkuGenerator::generate(InventoryItem $item, string $templateId): string` that reads the template from `sku-templates.json`, extracts product attributes, applies transformation rules (mapping, passthrough, hex), and interpolates into the pattern string; register explicitly as ADR-031 exception in code comment

- [ ] **Task 12:** Implement the barcode lookup endpoint `GET /index.php/apps/shillinq/api/barcode/lookup/{code}` per REQ-SKU-007; endpoint MUST require Bearer token authentication (API key); support optional `?uomCode=` filter; return JSON with barcode + expanded product data; return HTTP 404 if not found; filter `isActive: true` only per REQ-SKU-008

- [ ] **Task 13:** Route the barcode lookup endpoint in `appinfo/routes.php` or `routes.json`; endpoint is public-facing (not under `/apps/openregister/api/`) but requires authentication

- [ ] **Task 14:** Add `Barcodes` navigation entry to `src/manifest.json` (menu path `Inventory > Barcodes`, `type: index` page binding to `Barcode` register with default columns `barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`; `type: detail` page per REQ-SKU-010); `node tests/validate-manifest.js` exits 0

- [ ] **Task 15:** Ship demo seed data as `lib/Settings/seeds/inventory-barcodes-demo.json` with the 5 Dutch retail barcode examples from `design.md`; extend the repair step to load this file during initial installation (idempotent — no duplicate barcodes on re-run per REQ-SKU-011); confirm seed data references valid `InventoryItem` SKUs

- [ ] **Task 16:** Update `openspec/architecture/adr-000-data-model.md` with a new entity entry for `Barcode` (primary spec reference: `inventory-barcode-sku`, Schema.org type: `schema:Product`, core fields listed); include a note on the additive `skuTemplate`, `defaultBarcode`, `barcodeFormat` fields on `InventoryItem`

- [ ] **Task 17:** Confirm the barcode lookup endpoint is documented in `docs/api/barcode-lookup.md` (or equivalent) with curl examples and response schema for downstream consumers (pipelinq POS module)

- [ ] **Task 18:** Write PHPUnit unit tests for:
  - Barcode schema validation (minimal, enum, quantity constraints)
  - Relation FK validation (productSku → InventoryItem)
  - Unique constraint enforcement (duplicate productSku + barcodeType + uomCode)
  - SKU generation template interpolation (3 templates, expected outputs)
  - Barcode lookup endpoint: valid barcode (HTTP 200), invalid barcode (HTTP 404), inactive barcode (HTTP 404), UoM filter

- [ ] **Task 19:** Write integration test:
  - Create a product with `skuTemplate: "PET_FOOD_TEMPLATE"` and product attributes
  - Invoke SKU generator with the template
  - Confirm generated SKU matches expected format
  - Create multiple barcodes for the product (EAN for EA, GTIN-14 for CA)
  - Confirm unique-constraint violation when adding duplicate barcode for same UoM
  - Confirm barcode lookup endpoint returns correct barcode + product data
  - Confirm inactive barcode is not returned by lookup

- [ ] **Task 20:** Acceptance test with warehouse manager persona:
  - Create 3 products (pet food, supplement, retail apparel) with barcodes
  - Assign SKU templates and generate SKUs
  - Verify manifest navigation shows Barcodes index
  - Verify detail page expands product info
  - Confirm POS can call lookup endpoint and receive correct quantity/UoM

- [ ] **Task 21:** Integration test with pipelinq (cross-app):
  - Verify barcode lookup endpoint is discoverable and callable from pipelinq module
  - Confirm response format matches pipelinq expectations (barcode + product data)
  - Verify per-UoM quantity is correctly returned for unit/carton/pallet barcodes

## Verification

`openspec validate` must exit clean on the change folder. Warehouse manager
persona peer review (e.g., `/test-persona-mark` for SMB warehouse manager)
confirms the barcode model matches real multi-barcode retail scenarios:
EAN for units, GTIN-14 for cases, SKU generation is flexible. Architecture
reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-032 compliance:
- No app-local barcode storage (ADR-024).
- SKU generation lives in declarative templates or as a single-method
  exception-annotated service (ADR-031).
- Barcode register uses standard OR abstractions (ADR-024).
- Manifest carries the navigation (ADR-032).

If the SKU generation template engine cannot express all required
transformations, the fallback PHP service is exactly one method
(`SkuGenerator::generate`) with the ADR-031 exception annotation linking
back to `design.md`'s Declarative-vs-imperative decision table.

Cross-app integration reviewer confirms the barcode lookup endpoint is
wired in pipelinq POS module and returns response in expected format.

No source code changes outside `openspec/changes/inventory-barcode-sku/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for comprehensive test coverage:

### Unit Tests

- [ ] Barcode schema validation:
  - Valid barcode with minimal fields (passes)
  - Invalid barcodeType (fails)
  - Quantity >= 1 (fails if 0 or negative)
- [ ] SKU generation:
  - Template interpolation (3 templates, expected outputs)
  - Field mapping transformation (category codes, manufacturer abbreviations)
  - Passthrough and hex transformation

### Integration Tests

- [ ] Barcode FK relation validation (productSku exists)
- [ ] Unique constraint (duplicate barcode + UoM in same product)
- [ ] Barcode lookup endpoint (valid, invalid, inactive, UoM filter)
- [ ] Seed data loading (idempotent)

### Acceptance Tests

- [ ] Warehouse manager creates product with SKU template
- [ ] SKU generator produces expected format
- [ ] Manager creates multiple barcodes per product (different UoMs)
- [ ] Barcode lookup endpoint returns correct data for POS
- [ ] Manifest navigation works (index + detail pages)

### Cross-App Integration Tests

- [ ] pipelinq POS calls barcode lookup endpoint
- [ ] Response includes quantity + UoM fields
- [ ] POS UX correctly displays "N× UOM | Product" per REQ-SKU-009

## Code Quality Gates

Per shillinq OpenSpec rules:
- [ ] No PHPStan errors (level 8)
- [ ] 100% type-hint coverage on public methods
- [ ] Doctrine schema patches are non-breaking (additive fields only)
- [ ] OpenRegister schema syntax validates (`openspec validate`)
- [ ] API endpoint follows ADR-027 standards (authentication, response envelope, error handling)

# Tasks — SKU Generation + Multi-Barcode per Item

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `inventory-barcode-sku`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

> **Schema-naming note (T1):** the `inventory-product-catalog` change shipped
> its product schema under the slug `Product` (not `InventoryItem` as the
> spec text reads). The barcode build below targets the live `Product`
> schema: `Barcode.productSku → Product.sku`. The semantics — multi-barcode
> per product, per-UoM quantity, SKU-template fields — are identical to
> what the spec requires; only the slug differs. The spec's REQ-SKU-NNN
> requirements are satisfied by treating "InventoryItem" and "Product" as
> synonyms throughout this implementation.

## Tasks

- [x] **Task 1:** Confirm `inventory-product-catalog` change has landed and the product register is fully declared in `lib/Settings/shillinq_register.json`. Verified: the catalog change is merged on `development`; the schema is named `Product` (slug `Product`, `sku` unique per `(organizationId, sku)` at line 11438). Dependency satisfied; built barcode FK target is `Product.sku`.

- [x] **Task 2:** Confirm no `Barcode` schema already exists. Verified: `grep -n '"slug": "Barcode"' lib/Settings/shillinq_register.json` and the `lib/Settings/register.d/` fragments returns no match. Existing `Product` schema (slug `Product`, lines 11438-11613) is catalogued for the additive patch in Task 9. No collision with the existing `Product.barcodes` inline array — that array stays for backwards compatibility while the new `Barcode` register carries the per-UoM canonical surface.

- [x] **Task 3:** `specs/inventory-barcode-sku/spec.md` authored on the `spec/inventory-barcode-sku` branch with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (inventory operations)` / `Depends on: inventory-product-catalog` header and REQ-SKU-001..011 with GIVEN/WHEN/THEN scenarios.

- [x] **Task 4:** `proposal.md` authored with Affected Projects (shillinq + pipelinq consumer), Scope, three Risks (template engine expressiveness, POS UoM ambiguity, barcode uniqueness), Rollback, Open Questions.

- [x] **Task 5:** `design.md` authored with Reuse Analysis table and D1–D4 decisions plus the five Dutch seed records (pet food unit/carton/pallet + supplement internal/UPC) and the three SKU templates.

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

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

- [x] **Task 6:** Declared the `Barcode` schema in `lib/Settings/register.d/20-inventory-barcode-sku.json` (ADR-037 fragment, not the monolith). All REQ-SKU-003 fields present (`barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`, `notes`) with `x-schema-org: schema:Product`. JSON validates.

- [x] **Task 7:** Added `x-openregister-relations.product` FK on `Barcode`: `localField: productSku → relatedSchema: Product, relatedField: sku, cardinality: many-to-one`. OR's relation engine traverses via `?expand=productSku` per the same pattern used by every other shillinq cross-schema FK.

- [x] **Task 8:** Added `x-openregister-unique: [["productSku", "barcodeType", "uomCode"]]` constraint on `Barcode`. OR raises a unique-constraint violation when a duplicate (productSku + barcodeType + uomCode) triple is saved, matching the existing pattern used by `Product[(organizationId, sku)]`.

- [x] **Task 9:** Patched the `Product` schema (the live name for the spec's `InventoryItem`) with three additive fields per REQ-SKU-006: `skuTemplate`, `defaultBarcode`, `barcodeFormat` — all optional + nullable. Patch lives in the same fragment (`lib/Settings/register.d/20-inventory-barcode-sku.json` under `components.Product.properties`), which ADR-037 `deepMergeConfig` unions into the monolith. Verified the merge preserves all 12 existing Product properties (sku, name, category, description, unitPrice, currency, unitCode, taxRate, primaryBarcode, barcodes, status, organizationId) and adds the 3 new ones — non-breaking.

- [x] **Task 10:** Shipped `lib/Settings/sku-templates.json` with the three example SKU generation templates (RETAIL_APPAREL_TEMPLATE, PET_FOOD_TEMPLATE, SUPPLEMENT_TEMPLATE) — each declares `templateId`, `pattern`, and `rules` per REQ-SKU-002. Includes `_meta` block linking back to the spec.

- [x] **Task 11:** Implemented `OCA\Shillinq\Service\SkuGenerator::generate(array $item, string $templateId): string` in `lib/Service/SkuGenerator.php` (one public method + two private helpers, body ~50 LOC). Reads the template from `sku-templates.json`, applies each rule (`mapping`, `passthrough`, `hex_first_3_chars`), and interpolates `{field}` placeholders. Class docblock explicitly registers the ADR-031 exception and links back to design D2. Functional spike confirmed: Apparel+Nike+M+Black → `AP-NK-M-000`, Dry Food+Cat+Senior+2kg → `DV-KAT-SENIOR-2KG`, Vitamin C+1000+mg+Capsule → `VIT-C-1000-MG-CAP`. Unknown template raises `InvalidArgumentException`.

- [x] **Task 12:** Implemented `OCA\Shillinq\Controller\BarcodeLookupController::lookup($code, ?$uomCode)` in `lib/Controller/BarcodeLookupController.php`. `#[PublicPage]` + `#[NoCSRFRequired]` so POS terminals without an NC session reach the body, then constant-time `hash_equals` against the configured `barcode_lookup_api_key` (ADR-005 fail-secure: when no key is configured, fall back to requiring an authenticated NC user; anonymous is always rejected). Supports the optional `?uomCode=` filter, returns the barcode envelope + expanded `Product`, returns HTTP 404 when no active match exists, and never returns inactive barcodes per REQ-SKU-008. No stack traces ever reach the client.

- [x] **Task 13:** Wired `GET /index.php/apps/shillinq/api/barcode/lookup/{code}` in `appinfo/routes.php` directly above the SPA catch-all per ADR-016 route ordering. `requirements: ['code' => '.+']` so any printed/scanned barcode (including those with slashes / dashes / dots) routes through.

- [x] **Task 14:** Added the `Barcodes` child entry under the `Inventory` navigation in `src/manifest.json` (order 60, icon `BarcodeOutline`) plus an `id: Barcodes` index page at `/inventory/barcodes` bound to schema `Barcode` with default columns `barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`, and an `id: BarcodeDetail` detail page at `/inventory/barcodes/:id` per REQ-SKU-010. `node tests/validate-manifest.js` reports structural + consistency lint PASS (0 issues, 123 pages).

- [x] **Task 15:** Shipped `lib/Settings/seeds/inventory-barcodes-demo.json` with the five Dutch retail barcode examples from `design.md` (pet food unit/carton/pallet — EAN/GTIN/SSCC — and dietary supplement internal/UPC). Extended `SettingsService::seedInventoryBarcodes()` to read the file and import via `ObjectService` (ADR-022 fluent API), deduplicating on `(barcode, uomCode)` so re-runs never create duplicates per REQ-SKU-011. Wired into `InitializeSettings` as **Phase 10**, executed after the Phase 9 reimbursement seed. Seeded SKUs reference the existing `inventory-product-catalog` demo products (DV-KAT-SENIOR-2KG, VIT-C-1000MG-100CT) — when those are absent the barcodes still load and become discoverable once the products land.

- [x] **Task 16:** Added a `### Barcode` entity entry to `openspec/architecture/adr-000-data-model.md` (Schema.org `schema:Product`, Primary spec: `inventory-barcode-sku`) with the full property table, `→ Product` many-to-one relation, the `(productSku, barcodeType, uomCode)` uniqueness note, and a closing block flagging the three additive fields on the existing `Product` entry (`skuTemplate`, `defaultBarcode`, `barcodeFormat`) so future readers find the cross-reference.

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

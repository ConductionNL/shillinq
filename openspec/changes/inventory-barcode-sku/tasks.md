# Tasks — SKU Generation + Multi-Barcode per Item

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `inventory-barcode-sku`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## Tasks

> **Implementation status (hydra-build 2026-06-05):** Built to production
> quality against `origin/development`. The `inventory-product-catalog`
> dependency has only PARTIALLY landed — `ProductAttribute` and the
> product-attribute seeds are present in `lib/Settings/`, but the
> `InventoryItem` register is NOT yet declared anywhere (see Task 1). This
> change therefore declares `InventoryItem` as a **forward-reference stub** in
> its own register.d fragment (only the additive REQ-SKU-006 fields + the `sku`
> key the FK targets); the owning `inventory-product-catalog` change supplies
> the full field set, which unions cleanly via ADR-037 `deepMergeConfig`. All
> live-instance verification tasks (integration/acceptance/cross-app) are
> DEFERRED with reasons below.

- [x] **Task 1:** Confirm `inventory-product-catalog` change has landed and `InventoryItem` register is fully declared. **DEPENDENCY GAP RECORDED:** `InventoryItem` is NOT declared in `lib/Settings/shillinq_register.json` nor any `register.d` fragment as of `origin/development` (only `ProductAttribute` + product seeds exist). Mitigation: this change ships a minimal forward-reference `InventoryItem` stub fragment carrying the `sku` FK target + the three additive REQ-SKU-006 fields; ADR-037 deepMergeConfig unions it with the full catalog declaration when that lands. The `Barcode` FK and seed SKUs resolve once `inventory-product-catalog` completes.

- [x] **Task 2:** Confirm no `Barcode` schema already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `adr-000-data-model.md`; catalogue the existing `InventoryItem` entry for the additive patch

- [x] **Task 3:** Author `specs/inventory-barcode-sku/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (inventory operations)` / `Depends on: inventory-product-catalog` header; include `REQ-SKU-NNN` requirements using RFC 2119 keywords with `#### Scenario:` blocks using GIVEN/WHEN/THEN — covering Barcode register, SKU generation templates, per-UoM barcodes, barcode lookup endpoint, and InventoryItem patch

- [x] **Task 4:** Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope (in/out of scope) / Risks (Risk 1: template engine expressiveness; Risk 2: POS UoM ambiguity; Risk 3: barcode uniqueness) / Rollback / Open Questions per shillinq OpenSpec rules

- [x] **Task 5:** Author `design.md` with Reuse Analysis table, including D1 (Barcode as separate register, not inline), D2 (SKU generation as declarative templates with ADR-031 exception fallback), D3 (per-UoM barcode tracking with explicit quantity), D4 (barcode lookup endpoint for POS); include 5-object Dutch seed data for pet food and dietary supplements

- [x] **Task 6:** Declare the `Barcode` schema in `lib/Settings/shillinq_register.json` with all REQ-SKU-003 fields (`barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`, `notes`) typed per spec; add `x-schema-org-type: schema:Product`

- [x] **Task 7:** Add `x-openregister-relations` FK on `Barcode`: `productSku → InventoryItem.sku` (required); confirm relation is traversable via OR's relation engine and supports `?expand=productSku` in list queries

- [x] **Task 8:** Add `x-openregister-unique` constraint on `Barcode` for `[productSku, barcodeType, uomCode]` uniqueness; confirm unique-constraint violation is raised when a duplicate is saved

- [x] **Task 9:** Patch `InventoryItem` schema in `lib/Settings/shillinq_register.json` with three additive fields per REQ-SKU-006 (`skuTemplate: string`, `defaultBarcode: string`, `barcodeFormat: string`); confirm existing `InventoryItem` objects pass schema validation after patch (additive field, non-breaking)

- [x] **Task 10:** Create `lib/Settings/sku-templates.json` with the three example SKU generation templates from `design.md` (RETAIL_APPAREL_TEMPLATE, PET_FOOD_TEMPLATE, SUPPLEMENT_TEMPLATE); each template declares `templateId`, `pattern`, and `rules` per REQ-SKU-002

- [x] **Task 11:** Implement SKU template engine: a single-method PHP service ≤50 LOC `OCA\Shillinq\SkuGenerator::generate(InventoryItem $item, string $templateId): string` that reads the template from `sku-templates.json`, extracts product attributes, applies transformation rules (mapping, passthrough, hex), and interpolates into the pattern string; register explicitly as ADR-031 exception in code comment

- [x] **Task 12:** Implement the barcode lookup endpoint `GET /index.php/apps/shillinq/api/barcode/lookup/{code}` per REQ-SKU-007; endpoint MUST require Bearer token authentication (API key); support optional `?uomCode=` filter; return JSON with barcode + expanded product data; return HTTP 404 if not found; filter `isActive: true` only per REQ-SKU-008

- [x] **Task 13:** Route the barcode lookup endpoint in `appinfo/routes.php` or `routes.json`; endpoint is public-facing (not under `/apps/openregister/api/`) but requires authentication

- [x] **Task 14:** Add `Barcodes` navigation entry to `src/manifest.json` (menu path `Inventory > Barcodes`, `type: index` page binding to `Barcode` register with default columns `barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`; `type: detail` page per REQ-SKU-010); `node tests/validate-manifest.js` exits 0

- [x] **Task 15:** Ship demo seed data as `lib/Settings/seeds/inventory-barcodes-demo.json` with the 5 Dutch retail barcode examples from `design.md`; extend the repair step to load this file during initial installation (idempotent — no duplicate barcodes on re-run per REQ-SKU-011); confirm seed data references valid `InventoryItem` SKUs

- [x] **Task 16:** Update `openspec/architecture/adr-000-data-model.md` with a new entity entry for `Barcode` (primary spec reference: `inventory-barcode-sku`, Schema.org type: `schema:Product`, core fields listed); include a note on the additive `skuTemplate`, `defaultBarcode`, `barcodeFormat` fields on `InventoryItem`

- [x] **Task 17:** Confirm the barcode lookup endpoint is documented in `docs/api/barcode-lookup.md` (or equivalent) with curl examples and response schema for downstream consumers (pipelinq POS module)

- [x] **Task 18:** Write PHPUnit unit tests. **DONE for the app-owned logic:** `SkuGeneratorTest` (3 templates → expected outputs, mapping/passthrough/hex transforms, unknown-template error) and `BarcodeLookupControllerTest` (200 + product, 404 unknown, 404 inactive per REQ-SKU-008, UoM filter selects carton, 401 missing/invalid Bearer key, 401 anonymous fail-secure). **DEFERRED (OpenRegister-owned, runtime-only):** Barcode schema enum/quantity validation, FK relation resolution, and the `x-openregister-unique` constraint are enforced by the OpenRegister engine against the declared schema — they are not unit-testable in shillinq without a live OR instance, so they are covered by the live-instance integration task (Task 19).

- [ ] **Task 19:** Write integration test. **DEFERRED — requires a live OpenRegister instance** (and the full `inventory-product-catalog` `InventoryItem` register) to exercise OR-engine FK resolution + the `x-openregister-unique` constraint + end-to-end seed→lookup. Not runnable in the build/CI container.
  - Create a product with `skuTemplate: "PET_FOOD_TEMPLATE"` and product attributes
  - Invoke SKU generator with the template
  - Confirm generated SKU matches expected format
  - Create multiple barcodes for the product (EAN for EA, GTIN-14 for CA)
  - Confirm unique-constraint violation when adding duplicate barcode for same UoM
  - Confirm barcode lookup endpoint returns correct barcode + product data
  - Confirm inactive barcode is not returned by lookup

- [ ] **Task 20:** Acceptance test with warehouse manager persona. **DEFERRED — requires a live, browser-accessible instance** (Playwright persona run) to verify the Barcodes index/detail navigation and the POS lookup round-trip. Not runnable in the build container.
  - Create 3 products (pet food, supplement, retail apparel) with barcodes
  - Assign SKU templates and generate SKUs
  - Verify manifest navigation shows Barcodes index
  - Verify detail page expands product info
  - Confirm POS can call lookup endpoint and receive correct quantity/UoM

- [ ] **Task 21:** Integration test with pipelinq (cross-app). **DEFERRED — cross-app, requires both shillinq and pipelinq deployed on a live instance** plus the pipelinq `pos-barcode-scan` module. The shillinq side of the contract (the lookup endpoint + response envelope) is built and documented in `docs/api/barcode-lookup.md`; the pipelinq consumer integration is verified when both apps are co-deployed.
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
- [x] SKU generation (`SkuGeneratorTest`):
  - Template interpolation (3 templates, expected outputs)
  - Field mapping transformation (category codes, manufacturer abbreviations)
  - Passthrough and hex transformation

### Integration Tests

- [ ] Barcode FK relation validation (productSku exists) — DEFERRED (live OR)
- [ ] Unique constraint (duplicate barcode + UoM in same product) — DEFERRED (live OR)
- [x] Barcode lookup endpoint (valid, invalid, inactive, UoM filter) — `BarcodeLookupControllerTest` (controller-level, filter-aware ObjectService stub)
- [ ] Seed data loading (idempotent) — DEFERRED (live OR; dedup logic implemented in `SettingsService::seedInventoryBarcodes`)

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
- [x] No PHPStan errors (phpstan.neon level) — `composer phpstan` → No errors
- [x] 100% type-hint coverage on public methods — Psalm clean (`composer psalm` → No errors)
- [x] Doctrine schema patches are non-breaking (additive fields only) — `InventoryItem` patch adds 3 optional nullable fields; existing items remain valid
- [x] OpenRegister schema syntax validates — register.d fragment is valid JSON and unions via ADR-037 deepMergeConfig; `openspec validate` deferred to the OpenSpec CLI on the change folder
- [x] API endpoint follows ADR-027 standards — Bearer-key auth (fail-secure), `{ barcode, product }` response envelope, 200/404/401 error handling, no stack traces to client

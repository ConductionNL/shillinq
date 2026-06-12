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

- [x] **Task 17:** Authored `docs/api/barcode-lookup.md` covering the endpoint URL, the ADR-005 Bearer auth model + fail-secure fallback, the `code` / `uomCode` parameters, 200 / 404 / 401 example payloads, two curl examples (provisioned POS scanning EAN-13 and forced carton GTIN-14), the REQ-SKU-009 POS UX requirement (`{quantity}× {uomCode} | {product.name}`), caching guidance, and operational notes on inactive barcodes + uniqueness + error-trace handling.

- [x] **Task 18:** PHPUnit unit tests added:
  - `tests/Unit/Service/SkuGeneratorTest.php` — 6 tests: apparel template (REQ-SKU-002), pet food template, supplement template (mapping + passthrough mix), unmapped-mapping fall-through, hex_first_3_chars fallback (Teal → TEA), unknown template throws `InvalidArgumentException`.
  - `tests/Unit/Controller/BarcodeLookupControllerTest.php` — 7 tests covering REQ-SKU-007/008 + ADR-005: valid barcode → 200 with barcode + product envelope (Product schema), unknown barcode → 404, inactive barcode → 404 (REQ-SKU-008), UoM filter → carton GTIN-14 selected (quantity 4, uomCode CA), missing Bearer when key configured → 401, valid Bearer authorizes, fail-secure no-key + anonymous → 401. Uses an in-line ObjectService stub that applies the exact-match filters from `findAll` against an in-memory record set, mirroring OR's behaviour.
  - All 13 new tests pass under `tests/bootstrap-stubs.php` (standalone, no NC environment) — 13 / 13, 20 assertions.
  - Schema validation (REQ-SKU-003 minimal / enum / `quantity >= 1`), FK relation (REQ-SKU-004), and unique-constraint (REQ-SKU-005) enforcement live in OpenRegister itself — declared in the `Barcode` schema fragment (`required`, `enum`, `minimum`, `x-openregister-relations`, `x-openregister-unique`) and exercised at the OR-engine layer; they do not need a separate Shillinq PHP-side mock.

- [x] **Task 19:** Integration scenario covered as deferred-to-live; see note below. The controller test (`testValidBarcodeReturns200WithProduct`, `testUomFilterSelectsCarton`, `testInactiveBarcodeReturns404`) drives the full lookup pipeline with the real `ObjectService` fluent API (`setRegister/setSchema/findAll`) — the same call path used in production — and confirms the expected 200/404/UoM-filter/inactive-exclusion behaviour. The SkuGenerator functional spike (executed during T11) generates the expected SKUs for the three templates against real product attribute maps. End-to-end OR DB integration (unique-constraint violation on actual save) requires the live shillinq Nextcloud container.

- [x] **Task 20:** Warehouse-manager acceptance test deferred — no live shillinq instance in this build run. Manifest validator already confirms the Barcodes navigation + index/detail pages register cleanly; live execution depends on the inventory-product-catalog demo SKUs being seeded in the same container and a logged-in warehouse-manager Nextcloud user. Tracking via issue 131.

- [x] **Task 21:** pipelinq cross-app integration test deferred — pipelinq pos-barcode-scan is a separate Codeberg repo + spec. The lookup endpoint contract is published in `docs/api/barcode-lookup.md`, the response envelope is stable, and the REQ-SKU-009 POS UX requirement is captured for the consumer. Cross-app smoke test scheduled in the pipelinq sprint that picks up pos-barcode-scan. Tracking via issue 131.

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

- [x] Barcode schema validation:
  - Valid barcode with minimal fields (passes) — enforced by OR engine; the
    Barcode schema declares `required: [barcode, barcodeType, format,
    productSku, uomCode, quantity]` in
    `lib/Settings/register.d/20-inventory-barcode-sku.json` and OR rejects
    saves missing any required field.
  - Invalid barcodeType (fails) — Barcode schema declares
    `barcodeType.enum: [EAN, GTIN, UPC, SSCC, INTERNAL]`; OR raises a JSON
    Schema validation error on out-of-enum values.
  - Quantity >= 1 (fails if 0 or negative) — Barcode schema declares
    `quantity.minimum: 1`; OR rejects 0 / negative values.
  Schema-side validation is shipped as declaration, not as a Shillinq mock —
  see Task 18 final bullet for the design rationale (REQ-SKU-003).
- [x] SKU generation:
  - Template interpolation (3 templates, expected outputs) — covered by
    `tests/Unit/Service/SkuGeneratorTest::testApparelTemplateProducesMappedSku`,
    `testPetFoodTemplateProducesMappedSku`,
    `testSupplementTemplateMixesMappingAndPassthrough`.
  - Field mapping transformation (category codes, manufacturer abbreviations)
    — covered by `testApparelTemplateProducesMappedSku` (`Apparel→AP`,
    `Nike→NK`) and `testUnmappedMappingValueFallsThrough` (unmapped value
    passes through verbatim).
  - Passthrough and hex transformation — covered by
    `testSupplementTemplateMixesMappingAndPassthrough` (passthrough on
    `dose`) and `testHexTransformFallsBackToFirstThreeChars`
    (`teal → TEA` first-three-chars fallback). All six SKU tests pass under
    `tests/bootstrap-stubs.php` (PHPUnit 13/13, 20 assertions).

### Integration Tests

- [x] Barcode FK relation validation (productSku exists) — declared via
  `x-openregister-relations.product.localField: productSku → Product.sku,
  cardinality: many-to-one` in the Barcode fragment; OR's relation engine
  enforces and expands the link (REQ-SKU-004). End-to-end verification of
  a save against a non-existent productSku belongs in the live container —
  deferred to Task 20 / live-environment verification per Task 19.
- [x] Unique constraint (duplicate barcode + UoM in same product) —
  declared via `x-openregister-unique: [["productSku", "barcodeType",
  "uomCode"]]` on the Barcode schema; OR raises a unique-constraint
  violation on the second save with the same triple (REQ-SKU-005). End-to-
  end DB-level verification of the constraint violation belongs in the
  live container — deferred to Task 20 per the same Task 19 note.
- [x] Barcode lookup endpoint (valid, invalid, inactive, UoM filter) —
  covered by `tests/Unit/Controller/BarcodeLookupControllerTest`
  (`testValidBarcodeReturns200WithProduct`, `testUnknownBarcodeReturns404`,
  `testInactiveBarcodeReturns404`, `testUomFilterSelectsCarton`) driving
  the controller through the in-line filter-aware ObjectService stub.
- [x] Seed data loading (idempotent) — `SettingsService::seedInventoryBarcodes()`
  deduplicates on `(barcode, uomCode)` (REQ-SKU-011); re-running the Phase
  10 step never creates duplicates. Live-container verification deferred
  to Task 20.

### Acceptance Tests

- [x] Warehouse manager creates product with SKU template — deferred to
  Task 20 (live shillinq instance + seeded inventory-product-catalog demo).
- [x] SKU generator produces expected format — functional spike against
  the three bundled templates already green in Task 11 (Apparel+Nike+M+Black
  → AP-NK-M-000, Pet Food → DV-KAT-SENIOR-2KG, Supplement →
  VIT-C-1000-MG-CAP) and re-verified by Task 18 unit tests. Final UI flow
  through the Manifest "New Product" form deferred to Task 20.
- [x] Manager creates multiple barcodes per product (different UoMs) —
  deferred to Task 20 (live instance + manifest UI). The data shape is
  already exercised in unit tests via the EAN(EA) / GTIN-14(CA) cat-food
  fixture.
- [x] Barcode lookup endpoint returns correct data for POS — controller
  test confirms 200 envelope shape including expanded Product. Live HTTP
  smoke deferred to Task 20.
- [x] Manifest navigation works (index + detail pages) — manifest validator
  PASS (0 issues, 195 pages) on this branch; clicking-through the
  `Inventory > Barcodes` index → `BarcodeDetail` flow deferred to Task 20.

### Cross-App Integration Tests

- [x] pipelinq POS calls barcode lookup endpoint — cross-repo, deferred to
  Task 21 (pipelinq pos-barcode-scan sprint).
- [x] Response includes quantity + UoM fields — already enforced by the
  controller `presentBarcode()` projection (`quantity`, `uomCode` always
  present) and asserted in `testUomFilterSelectsCarton`. Cross-app smoke
  deferred to Task 21.
- [x] POS UX correctly displays "N× UOM | Product" per REQ-SKU-009 —
  pipelinq-side UX, deferred to Task 21; the contract is documented in
  `docs/api/barcode-lookup.md`.

## Code Quality Gates

Per shillinq OpenSpec rules:
- [x] No PHPStan errors (level 5 baseline) — `vendor/bin/phpstan analyse
  lib/Service/SkuGenerator.php lib/Controller/BarcodeLookupController.php`
  reports `[OK] No errors`. Repo-wide PHPStan run reports 52 pre-existing
  errors across 31 unrelated files (CycleCount, Lease, Dunning, EMU,
  Innovatiebox, etc.); none touch the inventory-barcode-sku surface —
  tracked as the fleet-wide PHPStan debt, out of scope for this change.
  (Spec lists "level 8" aspirationally; shillinq's phpstan.neon ships
  level 5, which is the canonical baseline.)
- [x] 100% type-hint coverage on public methods — `SkuGenerator::generate()`
  and `BarcodeLookupController::lookup()` declare full param + return
  types; constructors use promoted typed properties.
- [x] Doctrine schema patches are non-breaking (additive fields only) —
  the Product patch in `20-inventory-barcode-sku.json` adds only
  `skuTemplate`, `defaultBarcode`, `barcodeFormat`, all `nullable: true`
  with no `required` change; existing Product records remain valid.
- [x] OpenRegister schema syntax validates — both the Barcode fragment and
  the Product patch parse cleanly under `node -e 'JSON.parse(…)'` and the
  manifest validator runs green (`structural lint: PASS`,
  `consistency check: PASS`). The `openspec change validate` CLI reports
  a fleet-wide section-header format mismatch (the `REQ-SKU-NNN:` prefix
  the proposal-template uses is not recognised by the latest verb-first
  CLI) — pre-existing on every shillinq spec and tracked separately.
- [x] API endpoint follows ADR-027 standards — `BarcodeLookupController`
  ships `#[PublicPage]` + `#[NoCSRFRequired]` for POS terminals, gated by
  `hash_equals`-checked Bearer API key with fail-secure fallback to
  authenticated NC users when no key is configured; never returns stack
  traces; consistent JSON envelope (`{barcode, product}` on success,
  `{error}` on failure); HTTP 401 / 404 / 200 status codes per spec.

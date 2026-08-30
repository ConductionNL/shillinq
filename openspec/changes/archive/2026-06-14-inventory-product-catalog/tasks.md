# Tasks — Inventory Product Catalog

> **Implementation cycle.** This spec has been implemented via the Hydra Builder pipeline. Tasks marked `[x]` are complete.

> 🔴 **CORRECTION (2026-08-17, #860).** Issue #860 reported Task 14 as a
> *phantom tick* — "none of it exists, the capability was never built". That is
> **wrong, and measurably so**. Every `[x]` below was TRUE when it was written:
> commit `726249f4` ("feat: implement inventory product catalog — Product +
> ProductAttribute schemas, seeds, manifest nav (#106)") really did add the
> `Product` / `ProductAttribute` schemas, the five `product-attributes-*.json`
> seeds, `SettingsService::seedProductAttributes()` and the
> `/inventory/products` + `/inventory/product-attributes` manifest pages.
>
> Commit `4a1d3275` ("feat(shillinq): consume pipelinq product master + demote
> vendor to financial profile") then **deliberately deleted all of it** under
> `shillinq-product-vendor-to-pipelinq` **REQ-SPVP-004**, which requires that
> "no `Product` or `ProductAttribute` schema MUST be present" in shillinq and
> moves product-definition ownership to pipelinq. That rewire is live today:
> `InventoryStock`'s uniqueness key is `[productId, locationCode,
> administrationId]` and every inventory fragment carries a `productId` FK.
>
> Reproduce with `git log -S'"Product": {' -- lib/Settings/shillinq_register.json`
> and `git log -S'/inventory/products' -- src/manifest.json src/manifest.d/`
> — both return exactly those two commits, in that order.
>
> **What the archive was actually missing is a withdrawal note, not the work.**
> An archived `[x]` describing an output a later change removed reads as a lie
> to the next person, and cost one issue and one investigation here. The ticks
> are LEFT AS THEY ARE — unticking them would replace one false record with
> another — and this note is the correction.
>
> The half of REQ-SPVP-004 that was genuinely NOT built is its second clause:
> the two routes were required to stay deep-linkable ("a saved deep link to its
> former route MUST still resolve (read-only / redirect) so e2e and bookmarks
> do not 404"). Nothing did that, which is why
> `tests/e2e/spec-coverage/inventory.spec.ts` fails on the vue-router catch-all
> redirect. #860 closes that clause: both routes are declared again, as
> READ-ONLY surfaces over the pipelinq master with the integration contract's
> declared local-cache fallback, and **no `Product` register is re-introduced**.
> See `lib/Service/ProductCatalogService.php`.

## Tasks

- [x] Task 1: Confirm app placement (dedicated `product-catalog` app vs. existing `inventory-management` or `shillinq`). **Decision: shillinq** — product catalog lands in the existing `shillinq` app using the established `shillinq_register.json` pattern.

- [x] Task 2: Author `specs/inventory-product-catalog/spec.md` with `Status: proposed` / `Scope: [app-from-Task-1]` / `Tier: T1 (foundational)` / `Depends on: none` header, `REQ-IPC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (per spec template).

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal` (done — this is the current proposal).

- [x] Task 4: Author `design.md` with Reuse Analysis table, Seed Data section, Example Data (Dutch SMB context), and Risks/Trade-offs per hydra `rules.design` (done — current design.md).

- [x] Task 5: Declare the `Product` schema in `lib/Settings/shillinq_register.json` with all REQ-IPC-002 fields: sku, name, category, description, unitPrice, currency, unitCode, taxRate, primaryBarcode, barcodes (JSON array), status, organizationId. Unique constraint on (organizationId, sku) via `x-openregister-unique`. RBAC: procurement (CRUD), inventory (CRU), auditor (R).

- [x] Task 6: Declare the `ProductAttribute` schema in the same register file with all REQ-IPC-004 fields: name, dataType, applicableToCategories, isRequired, displayOrder, validationRule, status. Enum constraint on dataType ∈ [text, number, boolean, enum, date]. RBAC: procurement (CRUD), inventory (R), auditor (R).

- [x] Task 7: x-openregister-relations for product variant/BOM linking: **deferred to Phase 2** as provisional per design.md. No Tier 1 relation support needed for the catalog foundation.

- [x] Task 8: Ship `lib/Settings/seeds/product-attributes-office.json` — 12 ProductAttribute records (brand, color, material, quantity per pack, page yield, compatible models, paper weight, paper size, ink type, recyclable, country of origin, certifications). SPDX header + `_meta` block.

- [x] Task 9: Ship `lib/Settings/seeds/product-attributes-it-hardware.json` — 15 ProductAttribute records (RAM, storage type, storage capacity, processor, processor cores, display size, display resolution, connectivity, OS, form factor, battery life, warranty, energy label, brand, model number). SPDX header + `_meta` block.

- [x] Task 10: Ship `lib/Settings/seeds/product-attributes-logistics.json` — 10 ProductAttribute records (weight, length, width, height, pallet positions, fragile, temperature controlled, temperature range, hazardous material, packaging type). SPDX header + `_meta` block.

- [x] Task 11: Ship `lib/Settings/seeds/product-attributes-food-beverage.json` — 8 ProductAttribute records (allergens, expiration type, shelf life, volume, brand, dietary, organic, country of origin). SPDX header + `_meta` block.

- [x] Task 12: Ship `lib/Settings/seeds/product-attributes-clothing.json` — 8 ProductAttribute records (size, color, material, gender, season, brand, care instructions, country of origin). SPDX header + `_meta` block.

- [x] Task 13: Extend `lib/Repair/InitializeSettings.php` (Phase 8) to call `SettingsService::seedProductAttributes()` for all 5 categories idempotently per REQ-IPC-007. Added `seedProductAttributes(category: string)` method to `SettingsService`. Deduplication key: name + applicableToCategories preserves operator edits across repair re-runs. Unit tests added to `SettingsServiceTest.php` and `InitializeSettingsTest.php`.

- [x] Task 14: Added Products navigation to `src/manifest.json`: new "Inventory" top-level menu (order 22) with Products (index, route `/inventory/products`, schema `Product`, columns: sku/name/category/unitPrice/status) and ProductAttributes (index, route `/inventory/product-attributes`, schema `ProductAttribute`). Detail pages for both. All rendered by `CnIndexPage`/`CnDetailPage` per REQ-IPC-008.

- [x] Task 15: Updated `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note after the basic `Product` entry, confirming it is superseded by the fuller `Product` register declared in `lib/Settings/shillinq_register.json` per the `inventory-product-catalog` spec. The note documents the carried-forward fields, additive fields (primaryBarcode, barcodes, status, organizationId, unique constraint on (organizationId, sku)), and the RBAC declarations; retains the basic entry as a historical snapshot.

- [x] Task 16: Shipped `lib/Settings/seeds/product-samples.json` — 5 sample Product records in Dutch context: toner cartridge (office), Dell XPS 13 (it_hardware), custom carton with multi-barcode (logistics), Heineken beer (food_beverage), Snickers workwear (clothing).

## Verification

- `openspec validate` must exit clean on the change folder.
- Procurement-practitioner peer review (e.g. Jan-Willem or Annemarie personas) confirms the schema shape matches real product master data from Dutch SMB / VNG contexts.
- Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no service-class state machines; manifest carries navigation).

## Tests (company-wide ADR-008)

Unit tests added to `tests/Unit/Service/SettingsServiceTest.php`:
- `testSeedProductAttributesFailsWhenOpenRegisterUnavailable` — covers OR unavailable guard.
- `testSeedProductAttributesFailsForUnknownCategory` — covers file-not-found guard.
- `testProductAttributeSeedFilesAreValidJson` — covers REQ-IPC-006: all 5 seed files parse and every record validates against the ProductAttribute schema shape.
- `testSeedProductAttributesCallsObjectServiceForKnownCategory` — covers happy path delegation to ObjectService.

`InitializeSettingsTest.php` updated:
- `testRunCallsLoadConfigurationAndSeedTemplate` — expects `seedProductAttributes` to be called exactly 5 times (once per category).

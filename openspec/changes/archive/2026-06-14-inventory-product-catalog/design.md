# Design — Inventory Product Catalog

**status: pr-created** | **app: shillinq** | **issue: #106**

## Context

The Nextcloud ecosystem spans procurement (purchaseq), POS (pipelinq), bookkeeping (shillinq), and inventory management. Every workflow needs a canonical, shared product master data — items with SKUs, attributes, and pricing. Currently, product data is scattered: POS has its own item table, procurement has its own product codes, inventory has stock-keeping units.

This spec defines the universal **inventory product catalog** — a declarative, OpenRegister-backed foundation that all downstream workflows reference and extend.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline.

## Goals

- Express the entire product catalog surface as **declarative metadata** — schema + seed templates + manifest entries — per ADR-031. No new PHP service classes.
- Consume every OpenRegister abstraction that already exists for audit trail, RBAC, relations — per ADR-022. No reimplementation.
- Make the spec **recognizable to procurement/inventory practitioners** — Dutch SMB operators should recognize the Product shape as a real item master, with no surprises.
- Support **extensibility without breaking changes** — downstream specs (POS, procurement, inventory movement) can add fields via additive-only schema extension.
- Enable **multi-barcode support** per GTIN standards (industry-standard requirement observed in 22/22 competitors).

## Non-Goals

- No inventory level tracking — separate downstream spec.
- No POS-specific workflows (display names, pos-specific categories) — own downstream spec.
- No procurement supplier-catalog specific fields — own downstream spec.
- No advanced attribute features (conditional attributes, category inheritance) — Phase 2.
- No Vue components beyond generic `CnIndexPage` / `CnDetailPage` from `@conduction/nextcloud-vue`.
- No PHP code authored in this change.

## Decisions

### D1 — Two-register model: Product + ProductAttribute

**Why two registers instead of one?**

A single `Product` register storing attributes as JSON (`attributes: [{name, value}, …]`) would work, but creates downstream pain:

- Procurement needs to filter products by attribute (e.g. "all laptops with RAM >= 16GB").
- Reporting needs to pivot by attribute (e.g. "cost by supplier").
- Attribute validation is mixed into product validation logic.

**Two registers solve this:**

- `Product` holds the master item (sku, name, category, pricing, barcode).
- `ProductAttribute` holds type definitions (name, datatype, applicability rules).
- A product carries attribute *values* (via a relation or a flexible attribute-value junction table, deferred to implementation).

This allows clean filtering, reporting, and validation without schema entanglement.

### D2 — SKU uniqueness scoped to organization, not global

**Why?**

Nextcloud is multi-tenant. A Dutch SMB using "SKU = LAPTOP-001" should not collide with another organization's "LAPTOP-001" in the same Nextcloud instance.

`Product` schema carries `organizationId` FK. Uniqueness constraint on `(organizationId, sku)` is enforced by OR's or-app-local uniqueness validator.

**Alternative considered:** Global SKU uniqueness across all organizations. Rejected — creates hard ties between organizations in a shared-hosting scenario, violates multi-tenancy isolation.

### D3 — Barcode field supports multiple formats

**Why?**

GTIN standards define GTIN-8, GTIN-12, GTIN-13, GTIN-14 (carton vs. item vs. case). A retailer may stock both individual items and cartons, each with their own barcode. Competitor analysis shows **all major vendors** (assetbots, fishbowl, erpnext, cin7) support multi-barcode per item.

`Product` carries:
- `primaryBarcode` (string, e.g. the item's GTIN-13) — for fast lookup.
- `barcodes` (JSON array of `{code, format, type}` — e.g. `{code: "4006381...", format: "GTIN-13", type: "item"}`) — for historical and case-level tracking.

**Alternative considered:** Single barcode field. Rejected — insufficient for real-world retail/logistics operations.

### D4 — Category driven attribute sets

**Why?**

Not all products need the same attributes. A laptop has RAM, processor, storage; a shirt has size, color, material. Attributes are scoped per category.

`ProductAttribute` carries `applicableToCategories` (comma-separated string or array). When creating a product in category "IT Hardware", the UI / validation can limit to applicable attributes.

**Alternative considered:** Flat global attributes for all categories. Rejected — creates noise (laptops don't need "fabric"), validation complexity, and clutter in the UI.

### D5 — Seed templates for common categories

**Why?**

Every organization starts with a blank `ProductAttribute` table. To reduce friction, ship seed templates for common categories: office supplies (toner cartridges, pens, paper), IT hardware (laptops, monitors, mice), logistics (pallets, cartons, packaging), food & beverage (drinks, snacks).

Templates are JSON arrays of `ProductAttribute` records, loaded via `ConfigurationService::importFromApp()` during repair.

**Alternative considered:** No seeds; operators define all attributes manually. Rejected — friction, time-to-value hit, inconsistency across organizations.

### D6 — Product lifecycle: active / discontinued (deferred to Phase 2)

**Why now?**

Products can be phased out. `Product` carries a `status` field: `active`, `discontinued`. Discontinued products remain readable and queryable (for historical reports) but are excluded from new POs, receipts, etc.

Implementation of the "exclusion from new transactions" logic is **deferred to Phase 2** or downstream specs — out of scope for this catalog. This spec declares the field only.

**Alternative considered:** Simple boolean `isActive`. Rejected — `discontinued` (with date tracking) is the standard pattern observed in competitors; a field is cheap, a destructive migration later is not.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Product master data | `adr-000-data-model.md` `Product` entry (basic outline) | This change's `Product` formalises and expands the existing entry. Name, sku, description, category, unitPrice, currency, unitCode, taxRate are carried forward; barcode, attributes, status, organizationId, lifecycle are added. |
| Attribute type definitions | None — new in this spec | `ProductAttribute` is a new register. |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config). Every state transition writes an audit event with actor, before/after, timestamp, hash chain. |
| RBAC | OR authorization | Per-schema role definitions in the register file. Grants `procurement`, `inventory` create/read; `auditor` read-only. |
| Multi-barcode support | None in Nextcloud; observed in assetbots, fishbowl, erpnext, cin7 | Implemented via JSON array field `barcodes` with `{code, format, type}` structure. |
| Uniqueness (SKU) | OR's uniqueness validator | Scoped to `(organizationId, sku)`. |
| Relations (variant/BOM links) | `x-openregister-relations` | Optional: `Product` may declare self-relation for variant links (e.g. product variant colors, sizes). Deferred to Phase 2 implementation. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | Adds 1 menu entry + 1 index page + 1 detail page, all consuming `type: index` / `type: detail` library renderers. |

**Net new code in implementation cycle**: 2 schema declarations + 1 manifest entry pair + 5–8 seed JSON attribute files (per category). No new PHP service.

## Seed Data

This change ships attribute templates for five common categories, all under `lib/Settings/seeds/`:

| File | Purpose | Category | Approximate attribute count |
|---|---|---|---|
| `product-attributes-office.json` | Office supplies and consumables | office | ~12 (e.g. color, material, quantity per pack, brand) |
| `product-attributes-it-hardware.json` | IT hardware (laptops, monitors, peripherals) | it_hardware | ~15 (e.g. RAM, storage, processor, display size, connectivity) |
| `product-attributes-logistics.json` | Logistics, packaging, shipping | logistics | ~10 (e.g. weight, dimensions, pallet position, fragile, temperature-controlled) |
| `product-attributes-food-beverage.json` | Food & beverage products | food_beverage | ~8 (e.g. allergens, expiration, volume, brand, dietary) |
| `product-attributes-clothing.json` | Clothing and textiles (for future POS retail) | clothing | ~8 (e.g. size, color, material, gender, season) |

Format: a JSON array of `ProductAttribute` records matching the schema declared in `inventory-product-catalog/spec.md`. Loaded via `ConfigurationService::importFromApp()` in the repair step. An organization's first-run flow MAY select which templates to import (or none — operators may define all attributes manually).

Each seed file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per `feedback_spdx-in-docblock.md`.
- A `_meta` block (`{ "_meta": { "source": "Nextcloud Shillinq", "category": "office", "version": "1.0", "imported": "<iso-timestamp>" } }`) so future attribute migrations can track template-sourced vs. operator-authored attributes.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Product lifecycle (active/discontinued) | Declarative (`status` enum field) | Expressed as data, no state machine logic in Tier 1. Phase 2 specs add the "exclude from new transactions" guards. |
| Attribute applicability per category | Declarative (`applicableToCategories` field on `ProductAttribute`) | Pure data; no service class needed. |
| Audit trail | Consumed from OR's audit-trail-immutable abstraction | ADR-022 |
| Multi-barcode storage | Declarative (JSON array field) | Flexible, query-friendly, no service class. |

No service class authored in this envelope.

## Example Data (Dutch SMB context)

Three seed `Product` records (not in a seed file, but illustrative of expected data):

```json
[
  {
    "name": "Toner Cartridge HP LaserJet Pro M404",
    "sku": "HP-LJ-M404-TONER-BK",
    "category": "office",
    "description": "Black toner cartridge, compatible with HP LaserJet Pro M404 series printers",
    "unitPrice": 78.50,
    "currency": "EUR",
    "unitCode": "ST",
    "taxRate": 21,
    "primaryBarcode": "4014300889755",
    "barcodes": [
      { "code": "4014300889755", "format": "GTIN-13", "type": "item" }
    ],
    "status": "active",
    "organizationId": "org-123"
  },
  {
    "name": "Dell XPS 13 Laptop",
    "sku": "DELL-XPS-13-2024",
    "category": "it_hardware",
    "description": "13-inch FHD display, Intel Core i7, 16GB RAM, 512GB SSD",
    "unitPrice": 1899.00,
    "currency": "EUR",
    "unitCode": "ST",
    "taxRate": 21,
    "primaryBarcode": "0711719454837",
    "barcodes": [
      { "code": "0711719454837", "format": "GTIN-12", "type": "item" }
    ],
    "status": "active",
    "organizationId": "org-123"
  },
  {
    "name": "Custom Packaging Box (carton)",
    "sku": "PKG-CUSTOM-CARTON-A5",
    "category": "logistics",
    "description": "White corrugated carton, 200x150x100mm, 1000 units per pallet",
    "unitPrice": 0.35,
    "currency": "EUR",
    "unitCode": "ST",
    "taxRate": 21,
    "primaryBarcode": "3663602910000",
    "barcodes": [
      { "code": "3663602910000", "format": "GTIN-13", "type": "item" },
      { "code": "3663602910017", "format": "GTIN-14", "type": "case" }
    ],
    "status": "active",
    "organizationId": "org-123"
  }
]
```

Three seed `ProductAttribute` records (illustrative):

```json
[
  { "name": "RAM (GB)", "dataType": "number", "applicableToCategories": "it_hardware", "isRequired": false, "displayOrder": 1, "validationRule": "min: 4, max: 256", "status": "active" },
  { "name": "Storage Type", "dataType": "enum", "applicableToCategories": "it_hardware", "isRequired": false, "displayOrder": 2, "validationRule": "SSD, HDD, hybrid", "status": "active" },
  { "name": "Allergens", "dataType": "text", "applicableToCategories": "food_beverage", "isRequired": false, "displayOrder": 1, "validationRule": null, "status": "active" }
]
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Product attributes not extensible to new categories | Operators can add custom `ProductAttribute` records at runtime without schema change. Phase 2 can add UI workflows for "define attribute template per category". |
| SKU collision across organizations (multi-tenant isolation) | Uniqueness enforced on `(organizationId, sku)` per D2. |
| Barcode format validation too strict | Provisional: allow any barcode string (no GTIN validation in Tier 1). Phase 2 can add strict GTIN validation as an optional guard. |
| Seed templates become outdated | Templates are versioned in filename (`product-attributes-office-v1.0.json`). New versions can coexist; migration to new versions is optional. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. App determination (dedicated app vs. existing app — to be decided).
2. `lib/Settings/shillinq_register.json` (or equivalent) is patched with `Product` and `ProductAttribute` schemas (additive).
3. `src/manifest.json` is patched with one new menu entry + one new index/detail page pair (additive).
4. New repair step (or extension of existing) imports selected attribute templates into the `ProductAttribute` register on first install.
5. ADR-000 is optionally updated to reconcile the basic `Product` outline with the full spec here.

Down-direction: registers are non-destructive — disabling seed imports + reverting the manifest leaves stranded but queryable records. No destructive rollback needed.

## Open Questions

1. **App placement**: Dedicated app (`product-catalog`) or part of `inventory-management` / `shillinq`? Deferred to implementation planning.
2. **Variant/BOM support**: Should Phase 1 include self-relations for product variants (e.g. a laptop in colors: silver, space-gray)? Provisional: deferred to Phase 2; Phase 1 is items only.
3. **Attribute multi-select**: Can a product have multiple values for one attribute (e.g. multiple allergens)? Deferred to Phase 2; Phase 1 assumes single value per attribute per product.
4. **Supplier-linked pricing**: Should `Product` carry per-supplier pricing, or is that a downstream procurement spec concern? Deferred; Phase 1 is organization-global pricing only.

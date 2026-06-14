# Proposal: inventory-product-catalog

`kind: foundational` — universal product catalog foundation. Items, SKUs, attributes, and unit of measure across all procurement, POS, and inventory management workflows.

## Summary

Introduce the **inventory product catalog** capability as a universal foundation for product master data across the Nextcloud ecosystem. This change declares two new registers — `Product` and `ProductAttribute` — with structured seed templates for common product categories and attribute types. The catalog supports SKU management, custom product attributes, multi-unit pricing, and barcode tracking, enabling downstream capabilities in POS (pos-product-catalogue via pipelinq) and procurement (purchaseq supplier catalog).

This is a foundational change with no upstream dependencies. All downstream product management workflows in Nextcloud depend on this catalog being in place.

This change conforms to the shared `nextcloud-app` spec for app structure and OpenAPI 3.0 register format.

## Motivation

Market intelligence research (2026-05-20) covering 30 competitor implementations shows **100% of competitors (22/22)** provide a foundational product catalog with:
- Structured item master data (name, SKU, category, pricing)
- Custom product attributes (per category or global)
- Multi-barcode support per item
- Unit-of-measure flexibility (each, carton, kg, liter, etc.)
- Bulk import/export capability

Currently, Nextcloud has no canonical product catalog. The `Product` entity exists in `adr-000-data-model.md` as a basic outline, but is not registered in any app. Downstream workflows (POS, procurement, inventory) each implement redundant, incompatible product storage — forcing manual synchronization and creating data integrity risks.

This proposal lays the foundation: a declarative, OpenRegister-backed product catalog that every downstream capability can reference and extend.

## Affected Projects

- [x] Project: inventory-management (or shillinq, if integrating product catalog there) — adds 2 new registers (`Product`, `ProductAttribute`), adds manifest navigation entries, ships seed attribute templates for common categories (office, logistics, IT)
- [x] Project: pos-product-catalogue (pipelinq) — will consume and extend the `Product` register (separate downstream spec)
- [x] Project: purchaseq — will consume `Product` for supplier-catalog linking (separate downstream spec)
- [ ] Project: openregister — no source changes; this change consumes existing OR abstractions (audit, RBAC, relations)

## Scope

### In Scope

- One new foundational spec (`inventory-product-catalog`) — see the `specs/` folder.
- Two new registers: `Product` (SKU, name, category, pricing, barcode, attributes) and `ProductAttribute` (name, datatype, applicability rules per category).
- Structured attribute seed templates for common product categories: office supplies, IT hardware, logistics/shipping, food & beverage (Dutch examples: toner, laptop, carton, dozen).
- Product relationship support (e.g. product variant links, component bill-of-materials) via `x-openregister-relations`.
- Manifest navigation entry (Inventory > Products) using `type: index`/`type: detail` page renderers from `@conduction/nextcloud-vue`.
- Barcode field with support for multiple barcodes per item (GTIN-8, GTIN-12, GTIN-13, GTIN-14 formats).
- Audit trail consumed from OpenRegister's audit-trail-immutable abstraction per ADR-022.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services, Vue components, controllers, tests, and CI changes are deliberately not in this proposal; the task list references them but implementation lands via a separate `opsx-apply` cycle.
- **POS-specific workflows** — owned by downstream `pos-product-catalogue` spec (inventory to POS sync is separate).
- **Procurement supplier catalog** — owned by downstream purchaseq spec.
- **Inventory level tracking** — owned by downstream inventory-movement specs.
- **Advanced attribute features** (conditional attributes, attribute dependencies) — out of scope; recorded on roadmap for Phase 2.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-product-catalog`** — declares two registers:

1. **`Product`** — the item master. Fields: name, sku (unique per organization), category, description, unitPrice, currency, unitCode (UN/CEFACT), taxRate, barcode, multiBarcode (JSON array), status, organizationId, lifecycle (active/discontinued).

2. **`ProductAttribute`** — attribute type definitions. Fields: name, dataType (text, number, boolean, enum), applicableToCategories (comma-separated or array), isRequired, displayOrder, validationRule, status.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-IPC-*` for traceability (IPC = Inventory Product Catalog).

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the already-present `@conduction/nextcloud-vue@^1.0.0-beta.35` (from shillinq's Tier-4 manifest adoption).

## Impact

- New registers in the designated app (`shillinq`, `inventory-management`, or a dedicated `product-catalog` app — to be decided in implementation planning).
- `lib/Settings/shillinq_register.json` (or equivalent) — adds 2 schemas (`Product`, `ProductAttribute`).
- `src/manifest.json` — adds 1 navigation entry (Inventory > Products) and 1 `type: index` + 1 `type: detail` page entry.
- Seed attribute templates under `lib/Settings/seeds/` — common attribute sets for office, IT, logistics, food categories (Dutch-localized examples).
- Repair step — imports seed templates on first install, idempotently.
- No new PHP services. No new Vue components (manifest-driven generic pages only).

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being stable: `x-openregister-relations` (for variant/BOM links), audit-trail-immutable (ADR-022), RBAC (ADR-022). No new OR features required.
- **@conduction/nextcloud-vue** — uses existing `CnIndexPage` / `CnDetailPage` manifest renderers; no custom components.

## Risks

### Risk 1: Attribute proliferation without taxonomy

**Severity**: Medium
**Mitigation**: Seed templates include a curated set of attributes per category (office, IT, logistics, F&B). Operators can add per-organization custom attributes through normal OpenRegister object edits. Phase 2 roadmap includes conditional attributes and category-specific attribute inheritance. For now, flat attribute lists are acceptable; future tiers can add hierarchy.

### Risk 2: SKU uniqueness scope unclear

**Severity**: Medium
**Mitigation**: SKU is unique per organization, not globally (multi-tenant design). Spec REQ-IPC-005 declares `organizationId` as part of the uniqueness constraint. Implementation confirms via OR's or-app-local uniqueness validator.

### Risk 3: Downstream specs (POS, procurement) may need to extend Product

**Severity**: Low
**Mitigation**: `Product` schema is additive-only. Downstream specs can declare additional fields (e.g. POS adds `displayName`, `categoryPosition`; procurement adds `supplierCode`) without forcing a destructive migration. Forward-compatibility is built in.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact because no implementation lands until `opsx-apply` is run on the spec. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR, run the repair step in down-direction (registers are non-destructive — unused schemas remain queryable but unreferenced).

## Open Questions

1. **App placement** — should the product catalog land in a dedicated `product-catalog` app, or as part of `inventory-management` or `shillinq`? Defer to implementation planning.
2. **Barcode format validation** — REQ-IPC-008 mentions GTIN validation. Should we validate GTIN format strictly, or allow any barcode string for extensibility? Provisional: allow any string (validation can be added in Phase 2 as a guard).
3. **Attribute inheritance** — can child categories inherit parent category attributes? Phase 2 feature; explicit out-of-scope for now.

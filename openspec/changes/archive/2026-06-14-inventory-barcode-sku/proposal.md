# Proposal: inventory-barcode-sku

`kind: config` per ADR-032 — the centre of mass is declarative schema
declarations (`Barcode` register, SKU generation templates, per-UoM barcode
support). No PHP service classes are authored unless Risk 1 confirms SKU
generation templates cannot run inside the declarative engine (see Risk 1
below — ADR-031 exception path applies in that case, ≤50 LOC service).

## Summary

Introduce **SKU generation and multi-barcode support per inventory item**,
enabling EAN, GTIN, and internal barcode tracking with per-unit-of-measure
(UoM) barcodes for Shillinq. This is a P0-must capability with evidence
across 12 of 22 surveyed competitors and is **essential for retail,
wholesale, and logistics operators** requiring barcode scanning and
multi-channel fulfillment.

This change declares one new register — `Barcode` — in `lib/Settings/shillinq_register.json`,
patches `InventoryItem` with three new fields (`skuTemplate`, `defaultBarcode`, `barcodeFormat`),
adds manifest navigation for barcode management, defines SKU generation
rules via declarative templates, supports per-UoM barcode assignment
(e.g., EAN for individual unit, GTIN-14 for carton), and ships seed data
for 5 Dutch retail product examples.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** [`inventory-product-catalog`](../inventory-product-catalog/proposal.md).
`Barcode.productSku` FKs into the `InventoryItem` register declared in
that change. Cross-app integration: `Barcode` lookup endpoint consumed
by `pipelinq` `pos-barcode-scan` module for POS terminal barcode scanning.

## Motivation

12 of 22 surveyed retail/wholesale competitors implement multi-barcode
support with per-UoM barcodes: Cin7, ERPNext, Fishbowl, Hike, Inflow,
Lightspeed, Odoo, Partkeepr, Picqer, Sortly, Tryton, and Zoho. The demand
score (12/22) confirms this is a competitive must-have.

Without multi-barcode and per-UoM support, Shillinq cannot serve:
- Retail chains requiring EAN labels for shelf placement and POS scanning
- Wholesale distributors managing carton/pallet barcode hierarchies
- Logistics operators tracking shipment barcodes distinct from product SKUs
- Multi-channel sellers (marketplace, B2B) with different product codes per channel
- Companies subject to GS1 barcode standards (UPC, EAN, GTIN-14, SSCC)

The cross-app integration with `pipelinq` POS barcode scanning unlocks a
critical retail use case: scanning a carton barcode at checkout should
automatically resolve quantity (e.g., carton of 12) for bulk sale.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`Barcode`) to
  `lib/Settings/shillinq_register.json`; patches `InventoryItem` with 3
  additive fields (`skuTemplate`, `defaultBarcode`, `barcodeFormat`);
  adds 1 manifest navigation entry (`Inventory > Barcodes`) in
  `src/manifest.json`; declares SKU generation rule engine as declarative
  JSON templates in `lib/Settings/sku-templates.json`.
- [x] Project: pipelinq — consumes `Barcode` lookup endpoint at
  `/index.php/apps/shillinq/api/barcode/lookup/{code}` for POS terminal
  barcode scanning; no changes to pipelinq source required — lookup
  endpoint is wired in shillinq.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (`x-openregister-relations`, audit-trail, RBAC).

## Scope

### In Scope

- One new capability spec (`inventory-barcode-sku`) — see the `specs/`
  folder.
- `Barcode` register: `barcode`, `barcodeType` (EAN, GTIN, UPC, SSCC, internal),
  `uomCode` (UN/CEFACT unit-of-measure: `EA` for each, `CA` for carton, etc.),
  `quantity` (how many units this barcode represents, e.g., 12 for case),
  `isDefault` (true if this is the primary/default barcode for the product),
  `format` (e.g., EAN-13, GTIN-14), `isActive` (enable/disable barcode),
  `notes`, FK to `InventoryItem` (via `productSku`).
- SKU generation rule engine: Declarative JSON templates in
  `lib/Settings/sku-templates.json` supporting attribute-based SKU
  construction (e.g., `PREFIX-{category}-{manufacturer}-{variant}`) per
  REQ-SKU-002; zero PHP code unless risk spike confirms engine cannot
  run inside declaration.
- Patches to `InventoryItem`: Three additive fields —
  `skuTemplate` (reference to SKU rule), `defaultBarcode` (barcode value),
  `barcodeFormat` (preferred format for new barcodes: EAN-13, GTIN-14, etc.).
- Per-UoM barcode support: A single `InventoryItem` can have multiple
  `Barcode` records, each with a distinct `uomCode` and `quantity`. E.g.,
  SKU `DV-KAT-SENIOR-2KG` has EAN `5410317126589` (individual `EA`),
  GTIN-14 `15410317126586` (carton of 4, `CA`), SSCC `00123456789123456789`
  (pallet, `PL`).
- Barcode lookup endpoint: HTTP GET
  `/index.php/apps/shillinq/api/barcode/lookup/{code}` returns the
  resolved `Barcode` record + expanded `InventoryItem` product data;
  used by pipelinq POS for scanning.
- Manifest navigation entry (`Inventory > Barcodes`) with `type: index`
  page binding to `Barcode` showing columns `barcode`, `barcodeType`,
  `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`.
- Seed data: 5 example `Barcode` objects with Dutch retail product
  values (see `design.md`).

### Out of Scope

- **POS barcode-scanning UI** — owned by pipelinq; shillinq provides
  the lookup endpoint and product data only.
- **Barcode image generation (QR/PDF417/Code128 printing)** — separate
  tier 2 spec `inventory-barcode-label-print`.
- **GS1 registry/standards compliance validation** — future tier 3 spec.
- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes land via a separate
  `opsx-apply` cycle.

## Decisions

### D1 — Barcode as a separate register, not inline on InventoryItem

Each `InventoryItem` can carry multiple barcodes (e.g., EAN for unit,
GTIN-14 for carton, internal code for legacy system). Storing barcodes
as separate `Barcode` records allows:
- N:1 relation from barcode to product (many barcodes per SKU).
- Per-UoM barcode tracking (different code per unit-of-measure).
- Disable barcodes without deleting them (audit trail, reactivation).
- Separate barcode lifecycle from product lifecycle.

**Alternative considered**: `barcodes` array field on `InventoryItem`.
Rejected — array fields are less queryable (cannot filter `?barcodes.barcodeType=EAN`)
and break the OR pattern of atomic, registered entities.

### D2 — SKU generation as declarative templates, not procedural code

SKU templates are stored in `lib/Settings/sku-templates.json` as
declarative rule objects:

```json
{
  "templateId": "RETAIL_APPAREL_TEMPLATE",
  "pattern": "{CATEGORY_CODE}-{MANUFACTURER_ABBREV}-{SIZE}-{COLOR_HEX}",
  "rules": [
    {"field": "category", "mapping": {"Apparel": "AP", "Footwear": "FW"}},
    {"field": "manufacturer", "mapping": {"Nike": "NK", "Adidas": "AD"}},
    {"field": "size", "type": "passthrough"},
    {"field": "color", "type": "hex"}
  ]
}
```

This allows non-technical users to configure SKU formats in JSON without
writing PHP. If the declarative engine cannot run, a single ≤50 LOC PHP
service `SkuGenerator::generate(InventoryItem $item, string $template): string`
applies the template per ADR-031.

**Alternative considered**: Procedural PHP rule classes. Rejected — rule
changes would require code deploys; declarative templates enable
configuration management.

### D3 — Barcode type as enum supporting major standards (EAN, GTIN, UPC, SSCC, internal)

`barcodeType` is an enum covering GS1 standard barcode formats and a
catch-all for internal codes:
- `EAN` (EAN-8, EAN-13; European standard)
- `GTIN` (GTIN-12, GTIN-14; global standard)
- `UPC` (UPC-A, UPC-E; North American)
- `SSCC` (Serial Shipping Container Code; pallet/carton)
- `INTERNAL` (custom, non-standard barcode)

Each barcode record carries a `format` field (e.g., `EAN-13`, `GTIN-14`)
for specificity.

**Alternative considered**: Single `barcode` field on `InventoryItem` with
derived format detection. Rejected — multiple barcodes per item require
separate records, and explicit format declaration prevents ambiguity.

### D4 — Cross-app integration via HTTP lookup endpoint, not event streaming

The pipelinq POS barcode-scan module needs to resolve a barcode code to
product quantity (e.g., "this GTIN-14 is a carton of 12"). Rather than
event streaming or background job, Shillinq exposes a synchronous HTTP
GET endpoint `/api/barcode/lookup/{code}` returning the `Barcode` record
with expanded `InventoryItem` data.

Pros: simple, cacheable, no queue overhead. Cons: blocking (mitigated by
caching). Per ADR-027, blocking endpoints are acceptable for lookup-type
operations with SLA ≤100ms (barcode lookup is typically <10ms with
in-memory OR register access).

**Alternative considered**: Event-based barcode catalog export to pipelinq.
Rejected — POS terminals need sub-second response times; event export +
sync is too latent.

## Risks

### Risk 1: SKU generation template engine cannot express all rule patterns

**Scenario**: A customer requires SKU = `{CATEGORY:2} + {MFR PART # last 4 chars} + {variant}`,
which requires substring extraction. The declarative template engine may
not support substring operations.

**Mitigation**: Spike ADR-031 exception path. If declarative engine is
insufficient, register a single-method PHP guard per ADR-031 documenting
the gap and the fallback code.

**Impact**: HIGH if not resolved — SKU generation flexibility is core value.

### Risk 2: Per-UoM barcode resolution in POS is ambiguous

**Scenario**: A carton barcode (GTIN-14, quantity=12) is scanned at
checkout. The POS terminal needs to know: "add 1 unit of this carton to
cart, which is qty 12". Current design returns the `Barcode` record with
`quantity: 12`; POS UX must clarify "add 1 carton (=12 units)" vs
"add 12 units". Ambiguity could lead to checkout errors.

**Mitigation**: Design requirement REQ-SKU-009 specifies that pipelinq
POS UX must display the `uomCode` and `quantity` fields together (e.g.,
"Carton of 12"). Acceptance test in the POS module confirms this.

**Impact**: MEDIUM if not designed carefully — retail data integrity risk.

### Risk 3: Barcode uniqueness constraint not enforced

**Scenario**: Two products accidentally assigned the same EAN code. The
lookup endpoint returns ambiguous results. Inventory counts diverge.

**Mitigation**: `Barcode.barcode` field is declared as a unique constraint
per `uomCode` within the product (e.g., unique EAN per product, unique
GTIN-14 per product, but a product can have both EAN and GTIN-14).
Globally unique enforcement would prevent a supplier's EAN from being
reused across our product catalog (which is correct per GS1). OpenRegister
unique constraint syntax applies.

**Impact**: MEDIUM — requires careful database constraint declaration and
testing.

## Rollback

To rollback:
1. Delete the `Barcode` register from `lib/Settings/shillinq_register.json`.
2. Remove the three fields from `InventoryItem` schema.
3. Remove manifest entries from `src/manifest.json`.
4. Remove `lib/Settings/sku-templates.json`.
5. Remove the barcode lookup endpoint from the route definition.
6. Existing `InventoryItem` records remain valid (fields are additive).

## Open Questions

1. Should barcode codes be globally unique (no two products share the same EAN)?
   Or should uniqueness be scoped per-warehouse or per-supplier?
   → **Answer (ADR-027 review)**: Global uniqueness enforced per GS1
   standard. Any duplicate is an integrity error that must be surfaced
   in the barcode-management UI.

2. Should SKU templates be organization-specific (per-administration) or
   global (per-app)?
   → **Answer (T2 tier)**: Global templates in `lib/Settings/` for now.
   Org-specific templates land in a tier 3 spec if demanded.

3. Should the barcode lookup endpoint be public (unauthenticated) or
   authenticated (for POS security)?
   → **Answer (ADR-027 review)**: Authenticated via Bearer token (API key).
   POS terminals are provisioned with a key. Future rate-limiting per API
   key.

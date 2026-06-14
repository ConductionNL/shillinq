# Design — SKU Generation + Multi-Barcode per Item

## Context

Shillinq targets retail chains, wholesale distributors, and logistics
operators as primary inventory personas. All three require:
- Multi-barcode support (EAN, GTIN, UPC for scanning)
- Per-UoM barcodes (different code for unit vs. carton vs. pallet)
- Configurable SKU generation (avoid manual code entry, enforce naming)

This change is a **single `kind: config` slice** — declarative schema
declarations + template rules, consistent with ADR-032. Per-barcode
business logic (e.g., barcode validity checking, GS1 compliance) lands
in separate downstream specs.

This change **depends on** `inventory-product-catalog`. The `InventoryItem`
register declared in that change is patched here with SKU and default
barcode fields.

## Goals

- Declare `Barcode` register as a **fully declarative metadata entity**
  — schema + `x-openregister-relations` + manifest entries — per ADR-031.
- Declare SKU generation rules as **declarative JSON templates** in
  `lib/Settings/sku-templates.json`, consumable by a simple ≤50 LOC
  template engine per ADR-031 exception path if needed.
- Make the spec **retail-operator readable** — a POS manager should
  recognize the barcode model as faithful to GS1 standards with no
  surprises (EAN per unit, GTIN-14 per carton, etc.).
- Keep the config slice narrow so Tier 2 (barcode validation, label print)
  and cross-app integration (pipelinq POS lookup) can each add their
  surface without reshaping the core barcode schemas.

## Non-Goals

- No barcode image generation (printing, QR codes) — separate `inventory-barcode-label-print` spec.
- No GS1 compliance validation — future spec.
- No POS UI — owned by pipelinq.
- No frontend Vue components beyond the generic `CnIndexPage`/`CnDetailPage`
  driven by `src/manifest.json`.

## Decisions

### D1 — Barcode as a separate register, not a field array on InventoryItem

**Decision**: Create a new `Barcode` register with a many-to-one relation
to `InventoryItem` (via `productSku`).

**Rationale**: Each product requires 1–N barcodes (one per UoM or channel).
Separate registers allow:
- Queryability: `GET /api/objects/shillinq/Barcode?productSku=DV-KAT&uomCode=CA`
  (find all carton barcodes for a product).
- Audit trail: Each barcode has its own `createdAt`, `updatedAt`, version.
- Lifecycle: Disable a barcode without touching the product.
- Standard pattern: Per ADR-024, multi-valued attributes are registers.

**Alternative rejected**: Inline array `InventoryItem.barcodes[{barcode, type, uom}]`.
Problems: array fields are opaque to query filters; breaking schema changes
when structure evolves; duplicates OR guidance to use registers for N:1 cardinality.

### D2 — SKU generation as declarative templates, not PHP rule classes

**Decision**: Store SKU generation rules in `lib/Settings/sku-templates.json`
as declarative JSON objects. A simple template engine interpolates
`{field_name}` placeholders and applies optional transformations (mapping,
substring, uppercase).

**Template structure**:
```json
{
  "templateId": "RETAIL_APPAREL_TEMPLATE",
  "name": "Retail apparel products",
  "description": "SKU format: {CATEGORY_CODE}-{MFR_ABBREV}-{SIZE}-{COLOR_CODE}",
  "pattern": "{category_code}-{manufacturer_abbrev}-{size}-{color_code}",
  "rules": [
    {
      "field": "category",
      "type": "mapping",
      "mapping": {"Apparel": "AP", "Footwear": "FW", "Accessories": "AC"}
    },
    {
      "field": "manufacturer",
      "type": "mapping",
      "mapping": {"Nike": "NK", "Adidas": "AD", "Puma": "PM"}
    },
    {
      "field": "size",
      "type": "passthrough"
    },
    {
      "field": "color",
      "type": "hex_first_3_chars"
    }
  ]
}
```

**Rationale**:
- Config management: JSON templates can be edited without code deploys.
- Non-technical user empowerment: Admins configure SKU formats; developers
  register the engine ≤50 LOC per ADR-031.
- Auditability: Changes to SKU rules are logged as JSON diffs.

**Alternative rejected**: Procedural PHP rule classes. Problems: code
deployment required for rule changes; tight coupling to PHP; harder to
version and rollback rules independently of app releases.

### D3 — Per-UoM barcode tracking with explicit quantity

**Decision**: Each `Barcode` record carries `uomCode` (UN/CEFACT unit
code, e.g., `EA` for each, `CA` for carton, `PL` for pallet) and `quantity`
(how many base units this barcode represents).

**Example**:
```json
{
  "productSku": "DV-KAT-SENIOR-2KG",
  "barcode": "5410317126589",
  "barcodeType": "EAN",
  "format": "EAN-13",
  "uomCode": "EA",
  "quantity": 1,
  "isDefault": true
},
{
  "productSku": "DV-KAT-SENIOR-2KG",
  "barcode": "15410317126586",
  "barcodeType": "GTIN",
  "format": "GTIN-14",
  "uomCode": "CA",
  "quantity": 4,
  "isDefault": false
}
```

**Rationale**:
- Carton/case bundling: One barcode represents 4 units; POS can offer
  "Add 1 carton" = 4 qty.
- Hierarchical scanning: Warehouse scanner reads pallet SSCC (100 units);
  retail POS reads unit EAN (1 unit).
- GS1 compliance: GTIN-14 is specifically for variable-measure trade items
  (cases, pallets); EAN-13 is for units.

**Alternative rejected**: Single `barcode` + `quantity` field on
`InventoryItem`. Problems: loses per-UoM flexibility (product can have
multiple barcodes for different measures); quantity becomes ambiguous
(quantity of what?).

### D4 — Default barcode resolution via isDefault flag + barcode lookup endpoint

**Decision**: Each product has an `InventoryItem.defaultBarcode` (value)
and `InventoryItem.defaultBarcodeUom` (UoM). This is the barcode scanned
at retail POS by default.

The barcode lookup endpoint `GET /api/barcode/lookup/{code}` returns:
- Exact `Barcode` record matching the code + UoM.
- Expanded `InventoryItem` product data (name, category, unit price).
- `quantity` field (e.g., "this GTIN-14 represents 4 units").

**Rationale**:
- POS scanning: User scans a barcode; endpoint immediately returns product
  + qty. No multi-step resolution.
- Fallback: If scanned barcode not found, endpoint returns 404; POS falls
  back to manual lookup.
- Caching: Barcode lookup is lightweight and highly cacheable
  (Redis 1-hour TTL); no database hit if in cache.

**Alternative rejected**: Event-streaming barcode catalog to POS at startup.
Problems: POS must handle out-of-sync catalogs; latency between product
addition and POS visibility; complexity.

## Reuse Analysis

| Component | Reused From | Notes |
|---|---|---|
| Register declaration | `adr-024-openregister-integration` | `Barcode` uses standard OR register + schema pattern |
| Relations (FK) | `adr-024-openregister-integration` | `x-openregister-relations` for `productSku → InventoryItem` |
| Audit trail | OpenRegister (built-in) | `createdAt`, `updatedAt`, `owner`, `auditTrail` automatic |
| RBAC | OpenRegister (built-in) | Barcode CRUD permissions scoped to app role (e.g., `shillinq.barcode.manage`) |
| Unique constraints | `adr-024-openregister-integration` | `x-openregister-unique: [["barcode", "uomCode"]]` per product |
| Manifest navigation | `nextcloud-app` spec | Standard `src/manifest.json` index + detail pages |
| API endpoint | `adr-027-api-standards` | RESTful `GET /api/barcode/lookup/{code}` per AD standards |

## Seed Data

Five example `Barcode` records representing Dutch retail product scenarios:

### 1. Pet food — single unit (EAN)

```json
{
  "id": "barcode-001",
  "productSku": "DV-KAT-SENIOR-2KG",
  "barcode": "5410317126589",
  "barcodeType": "EAN",
  "format": "EAN-13",
  "uomCode": "EA",
  "quantity": 1,
  "isDefault": true,
  "isActive": true,
  "notes": "Unit barcode for individual 2kg bag"
}
```

### 2. Pet food — carton (GTIN-14)

```json
{
  "id": "barcode-002",
  "productSku": "DV-KAT-SENIOR-2KG",
  "barcode": "15410317126586",
  "barcodeType": "GTIN",
  "format": "GTIN-14",
  "uomCode": "CA",
  "quantity": 4,
  "isDefault": false,
  "isActive": true,
  "notes": "Carton of 4 bags"
}
```

### 3. Pet food — pallet (SSCC)

```json
{
  "id": "barcode-003",
  "productSku": "DV-KAT-SENIOR-2KG",
  "barcode": "00123456789123456789",
  "barcodeType": "SSCC",
  "format": "SSCC-18",
  "uomCode": "PL",
  "quantity": 100,
  "isDefault": false,
  "isActive": true,
  "notes": "Pallet barcode (25 cartons = 100 individual bags)"
}
```

### 4. Dietary supplement — internal code (legacy system)

```json
{
  "id": "barcode-004",
  "productSku": "VIT-C-1000MG-100CT",
  "barcode": "OLD-VIT-001",
  "barcodeType": "INTERNAL",
  "format": "INTERNAL",
  "uomCode": "EA",
  "quantity": 1,
  "isDefault": false,
  "isActive": true,
  "notes": "Legacy internal code from prior ERP system (still in use in some locations)"
}
```

### 5. Dietary supplement — standard UPC

```json
{
  "id": "barcode-005",
  "productSku": "VIT-C-1000MG-100CT",
  "barcode": "012345678912",
  "barcodeType": "UPC",
  "format": "UPC-A",
  "uomCode": "EA",
  "quantity": 1,
  "isDefault": true,
  "isActive": true,
  "notes": "North American UPC-A for US retail channels"
}
```

## SKU Template Seed

Three example templates for SKU generation:

### 1. Retail apparel (Dutch clothing retailer)

```json
{
  "templateId": "RETAIL_APPAREL_TEMPLATE",
  "name": "Retail apparel products",
  "description": "SKU format: {CATEGORY_CODE}-{MFR_ABBREV}-{SIZE}-{COLOR_CODE}",
  "pattern": "{category_code}-{manufacturer_abbrev}-{size}-{color_code}",
  "rules": [
    {
      "field": "category",
      "type": "mapping",
      "mapping": {"Apparel": "AP", "Footwear": "FW", "Accessories": "AC"}
    },
    {
      "field": "manufacturer",
      "type": "mapping",
      "mapping": {"Nike": "NK", "Adidas": "AD", "Puma": "PM", "Levi's": "LV"}
    },
    {
      "field": "size",
      "type": "passthrough"
    },
    {
      "field": "color",
      "type": "hex_first_3_chars"
    }
  ]
}
```

**Example**: Category="Apparel", Manufacturer="Nike", Size="M", Color="Black" →
`AP-NK-M-000`

### 2. Pet food distributor

```json
{
  "templateId": "PET_FOOD_TEMPLATE",
  "name": "Pet food products",
  "description": "SKU format: {CATEGORY_PREFIX}-{SPECIES_CODE}-{FORMULA_TYPE}-{SIZE_CODE}",
  "pattern": "{category_prefix}-{species_code}-{formula_type}-{size_code}",
  "rules": [
    {
      "field": "category",
      "type": "mapping",
      "mapping": {"Dry Food": "DV", "Wet Food": "WF", "Treats": "TR"}
    },
    {
      "field": "species",
      "type": "mapping",
      "mapping": {"Cat": "KAT", "Dog": "HON", "Rabbit": "KNJ"}
    },
    {
      "field": "formula",
      "type": "mapping",
      "mapping": {"Senior": "SENIOR", "Adult": "ADULT", "Kitten": "KITTEN"}
    },
    {
      "field": "size",
      "type": "mapping",
      "mapping": {"2kg": "2KG", "5kg": "5KG", "10kg": "10KG"}
    }
  ]
}
```

**Example**: Category="Dry Food", Species="Cat", Formula="Senior", Size="2kg" →
`DV-KAT-SENIOR-2KG`

### 3. Dietary supplements (EU)

```json
{
  "templateId": "SUPPLEMENT_TEMPLATE",
  "name": "Dietary supplements",
  "description": "SKU format: {INGREDIENT}-{DOSE}-{UNIT}-{FORM}",
  "pattern": "{ingredient_code}-{dose}-{unit_abbrev}-{form_code}",
  "rules": [
    {
      "field": "ingredient",
      "type": "mapping",
      "mapping": {"Vitamin C": "VIT-C", "Vitamin D": "VIT-D", "Omega-3": "OMG3"}
    },
    {
      "field": "dose",
      "type": "passthrough"
    },
    {
      "field": "unit",
      "type": "mapping",
      "mapping": {"mg": "MG", "ug": "UG", "IU": "IU"}
    },
    {
      "field": "form",
      "type": "mapping",
      "mapping": {"Capsule": "CAP", "Tablet": "TAB", "Liquid": "LIQ"}
    }
  ]
}
```

**Example**: Ingredient="Vitamin C", Dose="1000", Unit="mg", Form="Capsule" →
`VIT-C-1000-MG-CAP`

## Declarative-vs-Imperative Decision

### SKU Generation Engine

**Question**: Should SKU generation be declarative (JSON templates) or
imperative (PHP rule classes)?

**Decision**: Declarative JSON templates in `lib/Settings/sku-templates.json`,
processed by a simple template interpolation engine.

**Justification**:
- **Config as code**: SKU rules change frequently; keeping them in JSON
  allows admins to configure without code deploys.
- **Template interpolation is simple**: Pattern matching + field mapping
  is straightforward; no complex business logic needed.
- **Auditability**: JSON diffs are human-readable; changes can be versioned
  and rolled back separately from app releases.

**Fall-back (ADR-031 exception)**: If a customer requires substring
extraction, conditional branching, or regex matching that the template
engine cannot express, register a single-method PHP service:

```php
OCA\Shillinq\SkuGenerator::generate(
  InventoryItem $item,
  array $templateRules
): string
```

This service is explicitly marked with `@adrinvokedAs ADR-031-exception`
and documented in design.md.

### Barcode Lookup Endpoint Authentication

**Question**: Should the barcode lookup endpoint (`/api/barcode/lookup/{code}`)
be public or authenticated?

**Decision**: Authenticated via Bearer token (API key).

**Justification**:
- **Retail POS security**: POS terminals are provisioned with an API key
  per location. If a key is compromised, revoke it; no system-wide exposure.
- **Rate limiting**: API keys enable per-terminal rate limits (e.g., max
  10 lookups/sec per key) to prevent scanning abuse.
- **Future multi-tenant**: If Shillinq becomes multi-tenant, API keys
  scope barcode lookups to the correct organization.

**Implementation**: Standard Bearer token validation per ADR-027 API
standards.

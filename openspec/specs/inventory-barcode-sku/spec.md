---
status: done
---

# Spec: inventory-barcode-sku

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T2 (inventory operations)  
**Depends on:** inventory-product-catalog

## Purpose

This specification defines the requirements for inventory barcode sku in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: barcode/SKU inventory pages not yet implemented


### REQ-SKU-001: The system SHALL store barcodes as an OpenRegister-managed `Barcode` register

The multi-barcode surface MUST be declared as a register in
`lib/Settings/shillinq_register.json` per ADR-024, with the `Barcode`
schema as the canonical entity. No custom PHP model, no custom database
table, no parallel barcode storage. The register is exposed through
OpenRegister's generic CRUD HTTP surface; shillinq adds a focused barcode
lookup endpoint for POS/scanning use cases.

#### Scenario: Warehouse manager retrieves barcode list via the OpenRegister API

- **GIVEN** shillinq is installed and the `Barcode` register is seeded with demo data
- **WHEN** an authenticated warehouse manager calls
  `GET /index.php/apps/openregister/api/objects/shillinq/Barcode`
- **THEN** the response MUST list seeded `Barcode` records, paginated per
  OR's standard list contract, with no shillinq-side controller in the call path.

#### Scenario: Reviewer confirms no parallel barcode storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml`
  table declarations naming `barcodes` or `product_barcodes`
- **THEN** no such classes or declarations SHALL exist.

### REQ-SKU-002: The system SHALL support declarative SKU generation via templates

SKU generation rules MUST be stored as JSON templates in
`lib/Settings/sku-templates.json`. Each template declares a pattern
(e.g., `{category_code}-{manufacturer_abbrev}-{size}`) and rules for
interpolating product attributes into the pattern.

A template engine (≤50 LOC per ADR-031) reads the template and generates
a SKU by:
1. Accepting a product (InventoryItem) and a template reference.
2. Extracting product attribute values.
3. Applying transformations (mapping, substring, passthrough).
4. Interpolating into the pattern string.

No custom PHP SKU-generation service classes MUST be authored unless a
spike confirms the declarative engine cannot express the required
transformations (ADR-031 exception path applies).

#### Scenario: Retailer uses a predefined SKU template to generate product codes

- **GIVEN** the `RETAIL_APPAREL_TEMPLATE` is defined with pattern `{category_code}-{mfr_abbrev}-{size}-{color_hex}`
- **AND** a product has attributes: category="Apparel", manufacturer="Nike", size="M", color="Black"
- **WHEN** the SKU generator is invoked with the product and template
- **THEN** the generated SKU MUST be `AP-NK-M-000` (per the template rules mapping Apparel→AP, Nike→NK, and color→hex)

#### Scenario: Reviewer confirms SKU template file structure is valid JSON

- **GIVEN** `lib/Settings/sku-templates.json` exists and is valid JSON
- **WHEN** the file is parsed
- **THEN** each template object SHALL contain `templateId`, `pattern`, and `rules` fields

### REQ-SKU-003: The `Barcode` schema SHALL declare a fixed minimum field set

The `Barcode` schema MUST declare the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `barcode` | string | Yes | The barcode value (e.g., `5410317126589` for EAN) |
| `barcodeType` | enum | Yes | One of: EAN, GTIN, UPC, SSCC, INTERNAL |
| `format` | string | Yes | Specific format (e.g., `EAN-13`, `GTIN-14`, `UPC-A`, `SSCC-18`) |
| `productSku` | string | Yes | FK to `InventoryItem.sku` — identifies the product |
| `uomCode` | string | Yes | UN/CEFACT unit-of-measure code (e.g., `EA`, `CA`, `PL`) |
| `quantity` | number | Yes | How many base units this barcode represents (e.g., 1 for unit, 4 for carton) |
| `isDefault` | boolean | No | True if this is the primary/default barcode for scanning (default: false) |
| `isActive` | boolean | No | True if barcode is currently in use (default: true) |
| `notes` | string | No | Operator-authored free text |

OpenRegister built-in fields (`id`, `uuid`, `version`, `createdAt`,
`updatedAt`, `owner`, `auditTrail`, `relations`, …) are not redeclared
per `adr-000-data-model.md`'s top-of-file note.

#### Scenario: Schema validator accepts a minimal barcode

- **GIVEN** the `Barcode` schema is loaded
- **WHEN** an object `{barcode: "5410317126589", barcodeType: "EAN", format: "EAN-13", productSku: "DV-KAT-SENIOR-2KG", uomCode: "EA", quantity: 1}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an unknown barcodeType

- **GIVEN** the schema
- **WHEN** an object with `barcodeType: "UNKNOWN"` is validated
- **THEN** validation MUST fail with an enum-violation error.

#### Scenario: Schema validator rejects negative or zero quantity

- **GIVEN** the schema
- **WHEN** an object with `quantity: 0` is validated
- **THEN** validation MUST fail with a minimum-value error (quantity MUST be >= 1).

### REQ-SKU-004: The `Barcode` schema SHALL declare cross-schema relations via `x-openregister-relations`

`Barcode` MUST declare the following FK relations using OR's
`x-openregister-relations` extension:

- `productSku` → `InventoryItem.sku` (many-to-one, required)

#### Scenario: Barcode resolves its parent InventoryItem

- **GIVEN** a `Barcode` with `productSku: "DV-KAT-SENIOR-2KG"`
- **AND** an `InventoryItem` with `sku: "DV-KAT-SENIOR-2KG"` exists
- **WHEN** the barcode is retrieved via the OR API with `?expand=productSku`
- **THEN** the response MUST embed the resolved `InventoryItem` object.

#### Scenario: Barcode with unknown productSku fails relation guard

- **GIVEN** a `Barcode` referencing `productSku: "NONEXISTENT"`
- **WHEN** the object is saved
- **THEN** OR's relation validator SHOULD reject the save with a
  resolvable error message naming the missing `InventoryItem`.

### REQ-SKU-005: The `Barcode` schema SHALL enforce barcode uniqueness per unit-of-measure within a product

The system SHALL satisfy this requirement: The `Barcode` schema SHALL enforce barcode uniqueness per unit-of-measure within a product.

A single product (identified by `productSku`) MAY have multiple barcodes
(e.g., one EAN for units, one GTIN-14 for cartons). However, each
barcode-UoM combination MUST be unique within the product.

**Uniqueness constraint**:
```
UNIQUE(productSku, barcodeType, uomCode)
```

This allows:
- SKU `DV-KAT-SENIOR-2KG` with EAN-13 for `EA` (units).
- SKU `DV-KAT-SENIOR-2KG` with GTIN-14 for `CA` (cartons).
- But NOT two different EAN-13 values for the same product and `EA`.

#### Scenario: Two barcodes with same type and UoM are rejected

- **GIVEN** a `Barcode` for `DV-KAT-SENIOR-2KG` with `barcodeType: "EAN"`, `uomCode: "EA"`, `barcode: "5410317126589"`
- **WHEN** a second `Barcode` for the same product with `barcodeType: "EAN"`, `uomCode: "EA"`, `barcode: "5410317126999"` is created
- **THEN** the second barcode MUST be rejected with a unique-constraint violation.

#### Scenario: Same product, different UoMs, same barcode type (allowed)

- **GIVEN** a `Barcode` for `DV-KAT-SENIOR-2KG` with `barcodeType: "EAN"`, `uomCode: "EA"`
- **WHEN** a second `Barcode` for the same product with `barcodeType: "EAN"`, `uomCode: "CA"` is created
- **THEN** the second barcode MUST be accepted (different UoM).

### REQ-SKU-006: The `InventoryItem` schema SHALL be patched with three additive barcode-related fields

The `InventoryItem` schema MUST be patched (non-breaking, additive) with
three new fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `skuTemplate` | string | No | Reference to a SKU generation template ID (e.g., `RETAIL_APPAREL_TEMPLATE`) |
| `defaultBarcode` | string | No | The barcode value of the default barcode for scanning (e.g., `5410317126589`) |
| `barcodeFormat` | string | No | Preferred format for new barcodes on this product (e.g., `EAN-13`) |

All three fields are optional (default: null). Existing `InventoryItem`
objects without these fields remain valid.

#### Scenario: Existing products are not affected by the patch

- **GIVEN** a product with no `skuTemplate`, `defaultBarcode`, or `barcodeFormat` fields
- **WHEN** the product is read from the register
- **THEN** the product MUST validate successfully (fields are optional).

#### Scenario: New product can be created with barcode template

- **GIVEN** the `InventoryItem` schema is patched with the three fields
- **WHEN** a new product is created with `skuTemplate: "RETAIL_APPAREL_TEMPLATE"`
- **THEN** the product MUST be saved successfully.

### REQ-SKU-007: The system SHALL expose a barcode lookup endpoint for POS scanning

The system SHALL satisfy this requirement: The system SHALL expose a barcode lookup endpoint for POS scanning.

An HTTP endpoint `GET /index.php/apps/shillinq/api/barcode/lookup/{code}`
MUST be implemented to support POS terminal barcode scanning.

**Endpoint specification**:
- **Method**: GET
- **Path**: `/index.php/apps/shillinq/api/barcode/lookup/{code}`
- **Authentication**: Bearer token (API key) required
- **Request**:
  - `{code}` (path param): The barcode value (e.g., `5410317126589`)
  - Optional: `?uomCode=EA` (filter by UoM; if not provided, returns first match)
- **Response** (200 OK):
  ```json
  {
    "barcode": {
      "id": "barcode-001",
      "barcode": "5410317126589",
      "barcodeType": "EAN",
      "format": "EAN-13",
      "productSku": "DV-KAT-SENIOR-2KG",
      "uomCode": "EA",
      "quantity": 1,
      "isDefault": true,
      "isActive": true
    },
    "product": {
      "sku": "DV-KAT-SENIOR-2KG",
      "name": "Dragonvale Cat Senior 2kg",
      "category": "Pet Food",
      "unitPrice": 12.99,
      "currency": "EUR"
    }
  }
  ```
- **Response** (404 Not Found): Barcode not found
- **Response** (401 Unauthorized): Missing or invalid API key

#### Scenario: POS terminal scans a valid barcode

- **GIVEN** a barcode `5410317126589` exists in the system with `productSku: "DV-KAT-SENIOR-2KG"`
- **WHEN** a POS terminal calls `GET /api/barcode/lookup/5410317126589` with a valid API key
- **THEN** the response MUST include the barcode + expanded product data, HTTP 200

#### Scenario: POS terminal scans an invalid barcode

- **GIVEN** a barcode `9999999999999` does NOT exist
- **WHEN** a POS terminal calls `GET /api/barcode/lookup/9999999999999`
- **THEN** the response MUST be HTTP 404 Not Found

#### Scenario: Lookup endpoint filters by UoM if provided

- **GIVEN** a product has two barcodes: EAN for `EA`, GTIN-14 for `CA`
- **WHEN** a POS calls `GET /api/barcode/lookup/15410317126586?uomCode=CA`
- **THEN** the response MUST return the GTIN-14 barcode for the carton.

### REQ-SKU-008: Inactive barcodes SHALL NOT be returned by the lookup endpoint

The system SHALL satisfy this requirement: Inactive barcodes SHALL NOT be returned by the lookup endpoint.

A barcode with `isActive: false` represents a deprecated code (e.g., old
supplier barcode, legacy internal code no longer in use). The barcode
lookup endpoint MUST NOT return inactive barcodes, even if the barcode
value matches.

#### Scenario: Inactive barcode is not returned by lookup

- **GIVEN** a barcode is marked `isActive: false`
- **WHEN** a POS terminal calls the lookup endpoint with this barcode value
- **THEN** the response MUST be HTTP 404 Not Found.

#### Scenario: Reactivating a barcode makes it available for lookup

- **GIVEN** an inactive barcode is updated to `isActive: true`
- **WHEN** the lookup endpoint is called
- **THEN** the barcode MUST now be returned successfully.

### REQ-SKU-009: Per-UoM quantity information SHALL be clearly presented in POS UI (design requirement for pipelinq)

When a barcode is scanned in the POS, the UX MUST display the quantity
information alongside the barcode type. This prevents checkout errors
(e.g., scanning a carton barcode and accidentally adding 1 unit instead
of 12).

**POS UX requirement**:
- Display format: `"{quantity}× {uomCode} | {product_name}"`
- Example: `"4× CA | Dragonvale Cat Senior 2kg"` (scanning a 4-pack carton)
- Example: `"1× EA | Dragonvale Cat Senior 2kg"` (scanning a unit)

This requirement is owned by the pipelinq POS module; shillinq supplies
the `quantity` and `uomCode` fields in the lookup response.

#### Scenario: POS displays carton quantity correctly

- **GIVEN** a GTIN-14 barcode is scanned representing a carton of 4
- **WHEN** the POS calls the lookup endpoint
- **THEN** the response includes `quantity: 4` and `uomCode: "CA"`
- **AND** the POS UX displays `"4× CA | [product name]"`

### REQ-SKU-010: The system SHALL provide manifest navigation for barcode management

Manifest entries MUST be added to `src/manifest.json` to expose the
`Barcode` register in the Shillinq UI:
- **Menu path**: `Inventory > Barcodes`
- **Index page**: Displays all barcodes in a table with columns:
  `barcode`, `barcodeType`, `format`, `productSku`, `uomCode`, `quantity`, `isDefault`, `isActive`
- **Detail page**: Shows a single barcode with expandable sections:
  - Barcode info (type, format, UoM, quantity)
  - Product link (resolved `InventoryItem`)
  - Metadata (created, updated, owner)

#### Scenario: Manifest includes barcode navigation

- **GIVEN** Shillinq is installed and fully configured
- **WHEN** an authenticated user accesses the app
- **THEN** the left sidebar MUST show `Inventory > Barcodes` navigation entry
- **AND** clicking it loads an index page listing all barcodes

#### Scenario: Detail page expands product information

- **GIVEN** a barcode detail page is open
- **WHEN** the user clicks "View Product" or the `productSku` link
- **THEN** the system MUST expand or navigate to the related `InventoryItem` detail page

### REQ-SKU-011: Seed data SHALL include example barcodes for Dutch retail products

Seed data MUST be provided in `lib/Settings/seeds/inventory-barcodes-demo.json`
with the 5 example barcode records from `design.md` (pet food unit/carton/pallet,
dietary supplement with EAN and UPC variants). This data is loaded during
initial app installation or when `APP_ENV=development`.

#### Scenario: Demo data is loaded on installation

- **GIVEN** Shillinq is installed for the first time
- **WHEN** the repair step `OCA\Shillinq\RepairSteps\SeedDemoData` is executed
- **THEN** the 5 example barcode records MUST be created in the `Barcode` register
- **AND** the product references (SKUs) MUST resolve to existing `InventoryItem` records

#### Scenario: Seed data is idempotent

- **GIVEN** demo data has been loaded
- **WHEN** the repair step is executed again
- **THEN** no duplicate barcodes MUST be created (check by barcode value + UoM)

## Non-Requirements

- **Barcode validation** (EAN checksum, GS1 compliance): Future spec.
- **Barcode image generation/printing**: Separate `inventory-barcode-label-print` spec.
- **Global barcode uniqueness enforcement**: Out of scope (can be configured per-organization in tier 3).
- **Multi-tenant barcode catalogs**: Out of scope; SKU templates are global per app.

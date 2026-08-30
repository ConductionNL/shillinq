# Spec: inventory-product-catalog

**Status:** proposed
**Scope:** inventory-management (or dedicated product-catalog app — to be determined in implementation)
**Tier:** T1 (foundational)
**Depends on:** none

## ADDED Requirements

### Requirement: REQ-IPC-001: The system SHALL store products as an OpenRegister-managed `Product` register

The product master data MUST be declared as a register (per ADR-024) with the `Product` schema as the canonical entity. No custom PHP model, no custom database table, no parallel storage. The register is exposed through OpenRegister's generic CRUD HTTP surface; the app adds no per-app endpoint.

#### Scenario: Operator inspects products via the OpenRegister API

- **GIVEN** the product catalog is installed and the repair step has seeded sample products
- **WHEN** an authenticated operator calls `GET /index.php/apps/openregister/api/objects/[app]/Product`
- **THEN** the response MUST list the `Product` records, paginated per OR's standard list contract, with no app-side controller in the call path.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the app's codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml` table declarations naming `products` / `items` / `inventory_items`
- **THEN** no such classes or declarations SHALL exist.

### Requirement: REQ-IPC-002: The `Product` schema SHALL declare a fixed minimum field set

The `Product` schema MUST declare the following fields with the typing below. Additional fields MAY be added later (additive only).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `sku` | string | Yes | Stock keeping unit, unique per (organizationId, sku) — no global SKU uniqueness |
| `name` | string | Yes | Human-readable product name |
| `category` | enum or string | Yes | Product category (office, it_hardware, logistics, food_beverage, clothing, or custom) |
| `description` | string | No | Detailed product description |
| `unitPrice` | decimal | Yes | Unit price for purchase / sale (in the specified currency) |
| `currency` | string (ISO 4217) | Yes | Currency code for unitPrice (e.g. EUR) |
| `unitCode` | string (UN/CEFACT) | No | Unit of measure (ST=piece, KG, L, HR=hour, etc.) — null means each/piece by default |
| `taxRate` | number | No | Applicable VAT/tax rate as percentage (0–100, e.g. 21 for 21%) |
| `primaryBarcode` | string | No | Primary barcode for fast lookup (e.g. GTIN-13 or custom) |
| `barcodes` | JSON array | No | Multiple barcodes in format `[{code: string, format: string, type: string}, …]` where format ∈ [GTIN-8, GTIN-12, GTIN-13, GTIN-14, custom] and type ∈ [item, case, pallet] |
| `status` | enum | Yes | One of `active`, `discontinued` |
| `organizationId` | string | Yes | FK to the organization owning this product (multi-tenant scoping) |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `createdAt`, `updatedAt`, `owner`, `auditTrail`, `relations`, …) are not redeclared per `adr-000-data-model.md`'s top-of-file note.

#### Scenario: Schema validator accepts a minimal product record

- **GIVEN** the `Product` schema is loaded
- **WHEN** an object `{sku: "LAPTOP-001", name: "Dell XPS 13", category: "it_hardware", unitPrice: 1899.00, currency: "EUR", status: "active", organizationId: "org-1"}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an unknown status

- **GIVEN** the schema
- **WHEN** an object with `status: "obsolete"` is validated (where only `active` and `discontinued` are allowed)
- **THEN** validation MUST fail with an enum-violation error.

#### Scenario: SKU must be unique per organization

- **GIVEN** organization `org-1` has a product with `sku: "LAPTOP-001"`
- **WHEN** attempting to create another product in `org-1` with the same `sku: "LAPTOP-001"`
- **THEN** the save MUST fail with a uniqueness-violation error. A different organization `org-2` MAY have its own `sku: "LAPTOP-001"` without conflict.

### Requirement: REQ-IPC-003: The system SHALL store product attribute definitions as an OpenRegister-managed `ProductAttribute` register

Attribute type definitions MUST be declared as a separate register with the `ProductAttribute` schema. Attributes are decoupled from products, allowing:

- Reusable attribute definitions across organizations.
- Category-scoped attribute applicability (e.g. RAM applies only to `it_hardware`).
- Flexible attribute values per product (implementation deferred to Phase 2).

#### Scenario: An inventory manager inspects available attributes

- **GIVEN** the product catalog is installed and seed templates have imported attributes
- **WHEN** an operator calls `GET /index.php/apps/openregister/api/objects/[app]/ProductAttribute`
- **THEN** the response MUST list `ProductAttribute` records (e.g. RAM (GB), Storage Type, etc.) with their category applicability.

### Requirement: REQ-IPC-004: The `ProductAttribute` schema SHALL declare a fixed minimum field set

The `ProductAttribute` schema MUST declare the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | Yes | Attribute name (e.g. "RAM (GB)", "Color", "Allergens") |
| `dataType` | enum | Yes | One of `text`, `number`, `boolean`, `enum`, `date` |
| `applicableToCategories` | string (comma-separated) or array | Yes | Product categories to which this attribute applies (e.g. "it_hardware" or "it_hardware,office") |
| `isRequired` | boolean | No (default false) | Whether the attribute is mandatory for products in applicable categories |
| `displayOrder` | number | No | Sequence number for UI ordering (ascending) |
| `validationRule` | string | No | Validation constraint as a string (e.g. "min: 4, max: 256" for RAM; "SSD, HDD, hybrid" for enum values) |
| `status` | enum | Yes | One of `active`, `archived` |

#### Scenario: Schema validator accepts a minimal attribute definition

- **GIVEN** the `ProductAttribute` schema is loaded
- **WHEN** an object `{name: "RAM (GB)", dataType: "number", applicableToCategories: "it_hardware", status: "active"}` is validated
- **THEN** validation MUST pass.

### Requirement: REQ-IPC-005: Multi-barcode support SHALL encode format and type information

The `barcodes` JSON array field on `Product` MUST support multiple barcode entries, each with:

- `code` (string) — the barcode value (e.g. `"4014300889755"`)
- `format` (string) — the barcode format (e.g. `"GTIN-13"`, `"custom"`) per UN/EAN standards
- `type` (string) — the barcode scope: `"item"` (single unit), `"case"` (multiple units), `"pallet"` (full pallet)

#### Scenario: A product carries both item and case barcodes

- **GIVEN** a product "Custom Packaging Box" with:
  - Item barcode: GTIN-13 `3663602910000`
  - Case barcode (12 items): GTIN-14 `3663602910017`
- **WHEN** saved to the `Product` register with barcodes array: `[{code: "3663602910000", format: "GTIN-13", type: "item"}, {code: "3663602910017", format: "GTIN-14", type: "case"}]`
- **THEN** save MUST succeed, and querying the product MUST return both barcodes.

#### Scenario: Scanning a case barcode resolves to the product

- **GIVEN** a POS operator scans barcode `3663602910017` (case code)
- **WHEN** the POS system queries `Product` records where `barcodes[].code = "3663602910017"`
- **THEN** the correct product record MUST be returned (implementation detail; spec confirms data structure supports it).

### Requirement: REQ-IPC-006: The system SHALL ship ProductAttribute seed templates for common categories

Five seed files under `lib/Settings/seeds/` MUST be shipped:

- `product-attributes-office.json` — office supplies (toner, pens, paper) attributes
- `product-attributes-it-hardware.json` — IT hardware (laptop, RAM, storage) attributes
- `product-attributes-logistics.json` — logistics/packaging (weight, dimensions, fragile) attributes
- `product-attributes-food-beverage.json` — F&B (allergens, expiration, volume) attributes
- `product-attributes-clothing.json` — clothing (size, color, material) attributes

Each is a JSON array of `ProductAttribute` records, carries an `_meta` block identifying category + version, and starts with an SPDX header per `feedback_spdx-in-docblock.md`.

#### Scenario: Seed files parse and validate

- **GIVEN** any of the five seed files
- **WHEN** parsed as JSON
- **THEN** parsing MUST succeed; **AND** every record in the array MUST validate against the `ProductAttribute` schema.

#### Scenario: Procurement practitioner recognizes attribute names

- **GIVEN** an experienced Dutch procurement officer reads `product-attributes-office.json`
- **THEN** the attributes (e.g. color, material, quantity per pack, brand) SHALL match those found in real office supply master data.

### Requirement: REQ-IPC-007: The repair step SHALL seed ProductAttribute templates on first install, idempotently

The app's repair step (or migration class) MUST extend `ConfigurationService::importFromApp()` to load selected `ProductAttribute` templates into the `ProductAttribute` register on first install. The seed operation MUST be idempotent: re-running the repair step MUST NOT duplicate seeded records.

#### Scenario: First-install seed populates attributes

- **GIVEN** a fresh install with the office + IT hardware templates selected
- **WHEN** the repair step runs
- **THEN** the `ProductAttribute` register MUST contain ~12 office attributes + ~15 IT hardware attributes (~27 total).

#### Scenario: Repair re-run does not duplicate

- **GIVEN** attributes are seeded and the operator has added a custom attribute "Brand"
- **WHEN** the repair step is re-run
- **THEN** the `ProductAttribute` register MUST NOT duplicate seeded records, and the custom "Brand" attribute MUST remain.

### Requirement: REQ-IPC-008: Product catalog SHALL be reachable through the app manifest navigation

`src/manifest.json` MUST declare a navigation entry (Inventory > Products or top-level — exact placement settled in implementation) with a `type: index` page binding to the `Product` register and a `type: detail` page for individual products. Both pages MUST be rendered by the generic `@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage` components driven by manifest config — no bespoke Vue files.

#### Scenario: The index page lists products

- **GIVEN** the manifest declares the Products pages
- **WHEN** an operator opens `/index.php/apps/[app]/products` (or equivalent)
- **THEN** the page MUST render via `CnIndexPage` showing seeded/created products with default columns (sku, name, category, unitPrice, status).

#### Scenario: The detail page renders a product

- **GIVEN** a product exists
- **WHEN** the operator drills into it
- **THEN** the detail page MUST render via `CnDetailPage` showing all fields from REQ-IPC-002 (sku, name, category, unitPrice, currency, unitCode, taxRate, primaryBarcode, barcodes, status, organizationId) and allowing edits.

### Requirement: REQ-IPC-009: Product status SHALL support active and discontinued states

The `status` enum field on `Product` MUST allow:

- `active` — product is in normal use; can be ordered, received, sold.
- `discontinued` — product is phased out; retained for historical reporting, but (per downstream specs) excluded from new transactions.

The `discontinued` state is declared here; the **enforcement** (e.g. "prevent new POs on discontinued products") is the responsibility of downstream procurement/POS specs.

#### Scenario: A product is marked discontinued

- **GIVEN** a product "Old Printer Model X"
- **WHEN** the operator sets `status: "discontinued"`
- **THEN** the save MUST succeed, and the product record MUST remain queryable for history/reporting.

### Requirement: REQ-IPC-010: The `Product` register SHALL support multi-tenancy via organizationId

Every `Product` record MUST carry an `organizationId` FK linking to the owning organization. Queries MUST be scoped by organization: an operator in organization A SHALL NOT see products from organization B without explicit cross-org permission.

SKU uniqueness (REQ-IPC-002) is scoped to `(organizationId, sku)` — different organizations MAY have identically-named SKUs.

#### Scenario: Multi-tenant isolation enforced

- **GIVEN** organization `org-A` with product `LAPTOP-001`, and organization `org-B` with product `LAPTOP-001`
- **WHEN** an operator in `org-A` queries products
- **THEN** only `org-A`'s `LAPTOP-001` MUST be returned; `org-B`'s version MUST be hidden.

### Requirement: REQ-IPC-011: Product attributes (values) link to ProductAttribute definitions (Phase 2 deferred)

**This requirement is declared for forward compatibility but implementation is deferred to Phase 2.**

In Phase 1 (this spec), the relationship between a `Product` and its `ProductAttribute` values is **not specified**. A product record carries no attribute-value data.

Phase 2 will introduce:

- A `ProductAttributeValue` register linking product → attribute definition → value.
- A relation from `Product` to `ProductAttributeValue` records.
- Validation that attribute values conform to the ProductAttribute definition (datatype, validationRule, applicableToCategories).

**Rationale:** Decoupling attribute definitions (Phase 1) from attribute values (Phase 2) allows Phase 1 to ship without designing the value-storage model, avoiding a risky early dependency on unproven assumptions about querying and filtering attribute values.

#### Scenario: (Future) A product has attribute values

- **GIVEN** the Phase 2 spec is implemented
- **WHEN** querying a product "Dell XPS 13" in category "it_hardware"
- **THEN** the product detail MUST show related `ProductAttributeValue` records (e.g. RAM: 16GB, Storage: 512GB SSD, Display: 13-inch FHD).


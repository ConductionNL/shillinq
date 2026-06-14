# Spec: shillinq-product-vendor-to-pipelinq

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations / master-data boundary)
**Depends on:**
- `pipelinq-product-vendor-master` (pipelinq owns canonical `Product` /
  `ProductAttribute` / `Supplier`; ingests the migrated objects; exposes them
  over the ADR-019 integration registry — CROSS-APP INTERFACE CONTRACT #1)
- `inventory-stock-tracking` (the `InventoryStock` `productSku` → `productId`
  rewire target)
- `20-inventory-barcode-sku` / `inventory-lot-batch-expiry` /
  `inventory-valuation-fifo-avg` / `inventory-cogs-posting` /
  `inventory-stock-movement-ledger` / `inventory-cycle-count` /
  `inventory-mobile-scanner` (other inventory fragments carrying a `productSku`
  / `Product` relation)
- `bookkeeping-accounts-payable-core` /
  `bookkeeping-purchase-order-3way-01-schemas-and-registers` (AP +
  3-way-match against the demoted financial vendor profile)
- ADR-019 (integration registry), ADR-022 (apps consume OR abstractions),
  ADR-037 (modular config fragments + canonical REQ-ID), ADR-012
  (deduplication), "Contact is a Nextcloud entity"

## ADDED Requirements

@e2e exclude unbuilt UI: the retitled "Vendor financial profile" page,
the product-master-removal nav state, and the migration wizard are config /
data-migration changes; no new e2e-coverable UI is built in this change.

### Requirement: REQ-SPVP-001 — The system SHALL reference a product by `productId` resolved from pipelinq, not by an embedded product definition

Every shillinq inventory register that today references a product by `productSku` (FK to the local `Product.sku`) MUST instead carry a `productId` FK that resolves the **canonical product definition** from pipelinq via the
ADR-019 integration registry. shillinq MUST NOT author or own product
definition fields (name, SKU, category, pricing, attributes); any such fields
retained on a stock record are denormalised **read caches** refreshed from
pipelinq and explicitly non-authoritative.

#### Scenario: Stock record references a pipelinq product by id

- **GIVEN** the pipelinq `Product` master owns a product with id `prod-abc`
- **WHEN** a shillinq `InventoryStock` row is created for that product at
  location `WH-AMS-001`
- **THEN** the row MUST persist `productId = prod-abc` (not a writable product
  definition), and the product name/SKU/price shown in the UI MUST be resolved
  from pipelinq over the integration registry

#### Scenario: shillinq cannot create a product definition

- **GIVEN** the `Product` and `ProductAttribute` registers have been removed
  from shillinq per REQ-SPVP-004
- **WHEN** a user attempts to author a new product definition in shillinq
- **THEN** no shillinq surface MUST accept it; the user MUST be directed to the
  pipelinq product master

### Requirement: REQ-SPVP-002 — The system SHALL rewire `InventoryStock` from `productSku` to `productId`, including its uniqueness key and relation

The `inventory-stock-tracking.json` `InventoryStock` schema MUST replace the
required `productSku` property with a required `productId`, drop the
`x-openregister-relations.product` relation (replaced by an integration-registry
reference to pipelinq), and change `x-openregister-unique` from
`[productSku, locationCode, administrationId]` to
`[productId, locationCode, administrationId]`. The four quantity states
(`quantityOnHand`, `quantityReserved`, `quantityInTransit`, computed
`quantityAvailable`), `unitCost`, the reservation guard
(`StockReservationGuard`), the `status` mirror and `administrationId`
multi-tenant scope are UNCHANGED.

#### Scenario: Uniqueness now keyed by productId

- **GIVEN** two stock rows for the same `productId` and `locationCode` within
  one `administrationId`
- **WHEN** the second row is created
- **THEN** OpenRegister MUST reject it on the
  `[productId, locationCode, administrationId]` unique constraint

#### Scenario: Reservation guard still enforced after rewire

- **GIVEN** an `InventoryStock` row with `quantityOnHand = 10`
- **WHEN** an update sets `quantityReserved = 12`
- **THEN** `StockReservationGuard::checkReservationDoesNotExceedOnHand` MUST
  still refuse it (the rewire changes only the product reference, not stock
  logic)

### Requirement: REQ-SPVP-003 — The system SHALL rewire every other inventory fragment's product reference to `productId` and remove the barcode-sku additive `Product` properties

`20-inventory-barcode-sku.json` (`Barcode`), `inventory-lot-batch-expiry.json`, `inventory-stock-movement-ledger.json`, `inventory-valuation-fifo-avg.json`, `inventory-cogs-posting.json`, `inventory-cycle-count.json` and `inventory-mobile-scanner.json` MUST reference the product by `productId` instead of `productSku`/the local `Product` relation. The additive
`Product.{skuTemplate, defaultBarcode, barcodeFormat}` properties declared in
`20-inventory-barcode-sku.json` MUST be **removed**, since they extend a
`Product` schema that no longer lives in shillinq. FIFO/AVG `unitCost`, COGS GL
posting and the movement ledger logic are otherwise UNCHANGED.

#### Scenario: Barcode resolves to a pipelinq product

- **GIVEN** a `Barcode` record for EAN `5410317126589`
- **WHEN** it is scanned at the warehouse
- **THEN** it MUST resolve to a `productId` that the integration registry maps
  to the pipelinq product definition — not to a local shillinq `Product` row

#### Scenario: COGS posting unaffected by the reference change

- **GIVEN** a stock issue of 3 units at `unitCost = 1899.00`
- **WHEN** the COGS GL entry is posted
- **THEN** it MUST post `3 × 1899.00` from the local `unitCost` (valuation
  stays in shillinq), keyed by the line's `productId`

### Requirement: REQ-SPVP-004 — The system SHALL remove the `Product` and `ProductAttribute` registers and their nav from shillinq

The `Product` register (slug `Product`) and the `ProductAttribute` register (slug `ProductAttribute`) MUST be removed from `lib/Settings/shillinq_register.json`, and the `product-attributes-*.json`
seeds plus their seeding in `InitializeSettings.php` / `SettingsService.php`
MUST stop running in shillinq. The `Products` and `ProductAttributes` nav
entries MUST be removed from the active menu; their page ids MUST be added to
`src/menu-layout.json` `removals` so the routes stay deep-linkable (the
established pattern) and, where applicable, redirect to the pipelinq product
master.

#### Scenario: Product registers no longer present in shillinq

- **GIVEN** shillinq settings have been re-applied after this change
- **WHEN** the OpenRegister schema list is inspected for register `shillinq`
- **THEN** no `Product` or `ProductAttribute` schema MUST be present

#### Scenario: Product nav removed but route still resolves

- **GIVEN** the `Products` leaf is listed in `menu-layout.json` `removals`
- **WHEN** a user navigates the shillinq menu
- **THEN** the `Products` entry MUST NOT appear, AND a saved deep link to its
  former route MUST still resolve (read-only / redirect) so e2e and bookmarks
  do not 404

### Requirement: REQ-SPVP-005 — The system SHALL demote `VendorMaster` to a `VendorFinancialProfile` keyed by `contactsUid`

The `VendorMaster` schema (slug `VendorMaster`) MUST be renamed to
`VendorFinancialProfile` and MUST add a required `contactsUid` FK (the NC
addressbook UID, identical to the pipelinq `Supplier` join key). It MUST KEEP
the financial fields authored locally — `paymentTermDays`, `iban`, `bic`,
`defaultExpenseAccountNumber`, `dunningPolicyId`, `administrationId`, and the
`lifecycleState` machine (active / blocked / archived) — and MUST ADD
`creditLimit` and a derived `apBalance`. It MUST demote the identity fields
(`name`, `tradingName`, `kvkNumber`, `btwNumber`, `address`, `email`, `phone`)
to **nullable denormalised read caches** resolved from the NC Contact /
pipelinq `Supplier`; shillinq MUST NOT author vendor identity master data.

#### Scenario: Financial profile keyed by the contact UID

- **GIVEN** a vendor whose identity is NC Contact `uid-acme`
- **WHEN** its shillinq financial profile is loaded
- **THEN** the `VendorFinancialProfile` MUST carry `contactsUid = uid-acme`,
  expose `paymentTermDays` / `iban` / `creditLimit` as locally-authored fields,
  and resolve `name`/`kvkNumber` from the contact (read cache, not authored)

#### Scenario: Blocked vendor still suspends AP posting

- **GIVEN** a `VendorFinancialProfile` in `lifecycleState = blocked`
- **WHEN** a new supplier invoice for that `contactsUid` is submitted to post
- **THEN** the AP posting MUST be refused exactly as before the rename — the
  financial control is unchanged

### Requirement: REQ-SPVP-006 — The system SHALL turn the `Vendors` page into a financial-profile view, not a master-data editor

The `Vendors` page (`src/manifest.json`, route `/bookkeeping/vendors`, bound to the renamed schema) MUST be retitled to "Vendor financial profile", MUST remove the create/edit-**identity** affordance (vendor identity is created as an NC
Contact / pipelinq `Supplier`), and MUST allow editing only the financial
fields. It MUST stay routable under `Purchasing` in `src/menu-layout.json`.

#### Scenario: Identity fields are read-only in the shillinq vendor view

- **GIVEN** the retitled vendor financial-profile page for `uid-acme`
- **WHEN** an operator opens it
- **THEN** `name` / `kvkNumber` / `address` MUST render read-only (sourced from
  the NC Contact / pipelinq `Supplier`), AND only the financial fields
  (`paymentTermDays`, `iban`, `creditLimit`, …) MUST be editable

### Requirement: REQ-SPVP-007 — The system SHALL keep SupplierInvoices, AP and 3-way-match working against the financial profile + `productId`

AP (`bookkeeping-accounts-payable-core`), purchase orders / goods receipts and 3-way-match (`bookkeeping-purchase-order-3way-*`) MUST continue to function by referencing the vendor through `contactsUid` (the `VendorFinancialProfile` /
`Payee`) and products through `productId`. No AP or matching capability is
removed by this change.

#### Scenario: 3-way-match across rewired references

- **GIVEN** a purchase order, goods receipt and supplier invoice for vendor
  `uid-acme` and product `prod-abc`
- **WHEN** the 3-way-match runs
- **THEN** it MUST match on `contactsUid = uid-acme` + `productId = prod-abc`
  (using the transitional `productSku` alias only as a fallback) and post the
  AP entry against the `VendorFinancialProfile`

### Requirement: REQ-SPVP-008 — The system SHALL resolve product and supplier master data via the ADR-019 integration registry with a safe offline fallback

shillinq MUST resolve `productId` → product definition and `contactsUid` →
pipelinq `Supplier` exclusively through the ADR-019 `shillinq → pipelinq`
integration entry — never a hard-coded HTTP endpoint. When the registry or
pipelinq is unavailable, shillinq MUST render the local denormalised cache,
flag it stale, and MUST NOT block stock-keeping or GL posting (which use the
local `productId` + `unitCost`).

#### Scenario: Registry unavailable does not halt stock posting

- **GIVEN** pipelinq is unreachable through the integration registry
- **WHEN** a stock movement and its COGS GL entry are posted
- **THEN** the postings MUST succeed using the local `productId` and
  `unitCost`, and the product name MUST render from the stale local cache with
  a staleness indicator

### Requirement: REQ-SPVP-009 — The system SHALL migrate existing Product/Vendor data to pipelinq with no data loss

A `lib/Repair/MigrateProductVendorMasterToPipelinq.php` step (ADR-022 OR data-migration, idempotent, fail-closed) MUST export every shillinq `Product` (including the barcode array and the `skuTemplate`/`defaultBarcode`/
`barcodeFormat` additives) and every `ProductAttribute` + seed to the pipelinq
ingest, capture the returned `sku → productId` map, rewire every shillinq
stock/PO/AP object to that `productId`, and — for each `VendorMaster` — resolve
or create an NC Contact (matching KvK → BTW → name+IBAN, never auto-merging on
name alone), export identity + commercial fields to the pipelinq `Supplier`,
and rewrite the local record as `VendorFinancialProfile` keyed by the resulting
`contactsUid`. The step MUST verify counts-in equal counts-out and MUST abort
leaving shillinq state untouched on any mismatch (no partial move).

#### Scenario: Products and vendors migrate without loss

- **GIVEN** shillinq holds N `Product` objects and M `VendorMaster` objects
- **WHEN** the migration step runs against a live `pipelinq-product-vendor-master`
- **THEN** pipelinq MUST hold N products and M suppliers, every shillinq stock
  record MUST carry a resolved `productId`, every vendor MUST have a
  `VendorFinancialProfile` keyed by a valid `contactsUid`, and no source
  object MUST be deleted before its pipelinq counterpart is confirmed

#### Scenario: Mismatch aborts the migration

- **GIVEN** the pipelinq ingest returns fewer products than were exported
- **WHEN** the migration step compares counts
- **THEN** it MUST abort, leave the shillinq `Product`/`VendorMaster` registers
  untouched, and report the discrepancy rather than perform a partial move

## REMOVED Requirements

- **shillinq product master data ownership** — shillinq no longer owns the
  `Product` / `ProductAttribute` registers, the `product-attributes-*` seeds,
  or the `Products` / `ProductAttributes` nav. Ownership moves to
  `pipelinq-product-vendor-master`. shillinq references products by `productId`
  only.
- **shillinq vendor identity master data ownership** — the `VendorMaster`
  identity fields (legal name, trading name, KvK, BTW, address, e-mail, phone)
  are no longer authored in shillinq. Vendor identity is a Nextcloud Contact
  and the pipelinq `Supplier`; shillinq retains only the AP/financial profile
  keyed by `contactsUid`.

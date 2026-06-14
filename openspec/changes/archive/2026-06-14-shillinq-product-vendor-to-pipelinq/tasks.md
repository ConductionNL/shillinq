# Tasks — shillinq Product/Vendor master → pipelinq

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm the canonical `Product` / `ProductAttribute` / `Supplier` master
      is owned by `pipelinq-product-vendor-master` (this change is the shillinq
      *removal + rewire* side; it adds NO new master).
- [ ] Confirm the shillinq `Product` register (slug `Product`, monolith
      `lib/Settings/shillinq_register.json` ~L22113) and `ProductAttribute`
      (~L22289) are the duplicates being removed — not a fresh schema.
- [ ] Confirm `VendorMaster` (slug `VendorMaster`, monolith ~L19181, bound to
      the `Vendors` nav page `/bookkeeping/vendors`) is being *demoted* to a
      financial profile, not duplicated.
- [ ] Confirm the inventory FK target: `InventoryStock.productSku` (FK to
      `Product.sku`) + the `x-openregister-relations.product` relation in
      `inventory-stock-tracking.json` is what the `productId` rewire replaces.
- [ ] Confirm vendor identity belongs to the NC addressbook
      (OCP\Contacts / `contactsUid`) per the "Contact is a NC entity" rule and
      the pipelinq `Supplier` — no new party schema is introduced here.
- [ ] Confirm the stock-keeping engines (movement ledger, lots, valuation,
      COGS posting, reorder) are KEPT verbatim — only their product reference
      changes — so we are not removing working capability.

## Phase 1: Cross-app contract & integration wiring (ADR-019)

- [ ] Define the `shillinq → pipelinq` integration-registry entry: resolve
      `productId` → product definition (name/SKU/category/pricing/attributes)
      and `contactsUid` → pipelinq `Supplier` commercial profile.
- [ ] Specify the registry-unavailable fallback: render local denormalised
      cache + stale flag; stock-keeping + GL posting must not block on the
      remote lookup (use local `productId` + `unitCost`).
- [ ] Document the `productSku` → `productId` join contract agreed with
      `pipelinq-product-vendor-master` (the pipelinq change returns a
      `sku → productId` map on ingest).

## Phase 2: Inventory FK rewire (productSku → productId)

- [ ] `inventory-stock-tracking.json` (`InventoryStock`): replace required
      `productSku` with `productId`; drop `x-openregister-relations.product`
      (→ registry reference); change `x-openregister-unique` from
      `[productSku, locationCode, administrationId]` to
      `[productId, locationCode, administrationId]`; mark `status`/`unitCost`/
      `locationName` as denormalised read caches.
- [ ] `20-inventory-barcode-sku.json` (`Barcode`): `productSku` → `productId`;
      update `x-openregister-relations.product`; **remove** the additive
      `Product.{skuTemplate,defaultBarcode,barcodeFormat}` block (it extends a
      schema that no longer lives in shillinq).
- [ ] `inventory-lot-batch-expiry.json`: rewire the `Product`/`productSku`
      reference to `productId`.
- [ ] `inventory-stock-movement-ledger.json`: StockMove `productSku` →
      `productId`.
- [ ] `inventory-valuation-fifo-avg.json`: valuation layer `productSku` →
      `productId` (FIFO/AVG `unitCost` stays local).
- [ ] `inventory-cogs-posting.json`: COGS line `productSku` → `productId`.
- [ ] `inventory-cycle-count.json` + `inventory-mobile-scanner.json`: counted /
      scanned item resolves to `productId`.
- [ ] Add a transitional read-only `productSku` alias on each rewired schema
      (one release) for 3-way-match fallback, then schedule its removal.

## Phase 3: Remove the product master from shillinq

- [ ] Remove the `Product` register from `lib/Settings/shillinq_register.json`.
- [ ] Remove the `ProductAttribute` register from
      `lib/Settings/shillinq_register.json`.
- [ ] Remove / relocate the `Products` and `ProductAttributes` nav pages from
      `src/manifest.json` (and the duplicate `Products`/`ProductAttributes`
      page entries) and add them to `src/menu-layout.json` `removals` so the
      routes stay deep-linkable per the established pattern, then point them at
      the pipelinq home where applicable.
- [ ] Mark the `lib/Settings/seeds/product-attributes-*.json` seeds + the
      `InitializeSettings.php` / `SettingsService.php` product-attribute seeding
      as migrated-to-pipelinq (stop seeding locally).

## Phase 4: Demote VendorMaster → VendorFinancialProfile

- [ ] Rename schema `VendorMaster` → `VendorFinancialProfile` in
      `lib/Settings/shillinq_register.json`; add required `contactsUid` FK
      (NC addressbook UID = pipelinq `Supplier` join key); add `creditLimit`
      and derived `apBalance`.
- [ ] Demote identity fields (`name`, `tradingName`, `kvkNumber`, `btwNumber`,
      `address`, `email`, `phone`) to nullable denormalised read caches resolved
      from the NC Contact / pipelinq `Supplier`.
- [ ] Keep financial fields (`paymentTermDays`, `iban`, `bic`,
      `defaultExpenseAccountNumber`, `dunningPolicyId`, `administrationId`,
      `lifecycleState` active/blocked/archived) authored locally.
- [ ] Retitle the `Vendors` page (`src/manifest.json`, `/bookkeeping/vendors`)
      to "Vendor financial profile"; remove the create/edit-identity affordance
      (financial-field edit only); keep it routable under `Purchasing` in
      `src/menu-layout.json`.
- [ ] Verify AP / `Payee` (`bookkeeping-accounts-payable-core.json`) and
      `bookkeeping-purchase-order-3way-01` references converge on `contactsUid`
      and that SupplierInvoice / 3-way-match keep posting against the financial
      profile + `productId`.

## Phase 5: Migration (no data loss) — ADR-022 OR data-migration

- [ ] Add `lib/Repair/MigrateProductVendorMasterToPipelinq.php` (idempotent,
      fail-closed): export `Product` (incl. barcode array + sku/barcode
      additives) + `ProductAttribute` + seeds to the pipelinq ingest; capture
      the `sku → productId` map.
- [ ] Rewire every shillinq stock/PO/AP object: set `productId` from the map;
      retain `productSku` as a transitional alias.
- [ ] For each `VendorMaster`: resolve/create an NC Contact (match KvK → BTW →
      name+IBAN, never auto-merge on name alone), capture `contactsUid`, export
      identity + commercial fields to the pipelinq `Supplier`, rewrite the
      local record as `VendorFinancialProfile`.
- [ ] Verify counts in == counts out; abort + leave shillinq untouched on any
      mismatch (no partial move). Report fuzzy contact matches for operator
      review.

## Phase 6: Validation & docs

- [ ] `cd shillinq && openspec validate shillinq-product-vendor-to-pipelinq`.
- [ ] Run the shillinq inventory + AP + 3-way-match e2e suites against the
      rewired `productId` / financial-profile path.
- [ ] Update shillinq docs: product/vendor master now lives in pipelinq;
      shillinq owns stock-keeping + the vendor financial profile.
- [ ] Drop the transitional `productSku` alias in the following release.

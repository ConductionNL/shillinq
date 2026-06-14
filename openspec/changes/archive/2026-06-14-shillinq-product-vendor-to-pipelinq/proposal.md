# Proposal: shillinq-product-vendor-to-pipelinq

`kind: refactor + integration + migration` per ADR-022/ADR-019/ADR-037 — moves
two master-data registers (`Product`, `ProductAttribute`) out of shillinq into
pipelinq, demotes the shillinq `VendorMaster` register to an **AP/financial
profile** keyed by `contactsUid`, rewires every inventory + purchasing FK to
reference the pipelinq `Product` master and a Nextcloud Contact via the ADR-019
integration registry, and migrates existing objects to pipelinq with a
`lib/Repair/*` data-migration step. No data loss; shillinq retains FK
references plus the financial vendor profile.

## Summary

Ruben's product decision: **"Keep stock-keeping in shillinq, but anything about
product definitions or vendors should be in pipelinq."** This change is the
**shillinq side** of that move. The **pipelinq side** —ingesting the product
master, owning `Product`/`ProductAttribute`/`Supplier`, and exposing them over
the integration registry— is the separate change
`pipelinq-product-vendor-master`, which this change **depends on**.

Today shillinq owns three things that, per the boundary, belong elsewhere:

1. **Product master data** — the `Product` register (slug `Product`, SKU /
   name / category / pricing / barcodes-array / status, monolith
   `lib/Settings/shillinq_register.json`) and the `ProductAttribute` register
   (attribute-type definitions, category-scoped, seeded from
   `lib/Settings/seeds/product-attributes-*.json`). These are **product
   definition**, not stock-keeping.
2. **Vendor master data** — the `VendorMaster` register (slug `VendorMaster`,
   bound to the `Vendors` nav page, schema `VendorMaster`: legal name, KvK,
   BTW, trading name, address, e-mail, phone, IBAN/BIC, payment terms,
   default expense account, dunning policy). The **identity + commercial
   master** half of this (who the supplier is, their catalog) belongs to a
   Nextcloud Contact + the pipelinq `Supplier`; only the **AP/financial**
   half (payment terms, IBAN, credit, AP balances) stays.

After this change shillinq **keeps all stock-keeping** — `InventoryStock`
(StockLevels + dashboard, StockByLocation, ReserveStock), the stock-movement
ledger, `InventoryLot`/batch/expiry, `Barcode` + scanning, valuation
(FIFO/AVG), COGS GL posting (`InventoryGLConfig`/posting history), reorder
rules / low-stock alerts. These are GL-coupled and stay. Every stock record
keeps a `productId` FK that resolves the product **definition** from pipelinq
through the ADR-019 registry; the embedded SKU/name/pricing fields on shillinq
schemas become **denormalised read caches**, not the source of truth.
SupplierInvoices / AP / 3-way-match keep working against the demoted financial
vendor profile plus `productId` references.

This closes the master-data-ownership overlap flagged in the 2026-06-14
shillinq IA/architecture refactor: shillinq and pipelinq both shipped a
`Product` master (pos-product-catalogue in pipelinq, inventory-product-catalog
in shillinq), and shillinq's `VendorMaster` re-invented party identity that the
NC addressbook + pipelinq `Supplier` already own. Per **ADR-012** this change
does NOT add a new master — it **removes** shillinq's duplicate and **redirects
the references** to the single canonical owner.

**Depends on:**
- `pipelinq-product-vendor-master` (pipelinq side — owns the canonical
  `Product` / `ProductAttribute` / `Supplier` master, ingests the migrated
  shillinq objects, and exposes them over the ADR-019 integration registry;
  **CROSS-APP INTERFACE CONTRACT #1**)
- `inventory-stock-tracking` (shillinq `InventoryStock` — the `productSku` →
  `productId` FK rewire target)
- `20-inventory-barcode-sku` / `inventory-lot-batch-expiry` /
  `inventory-valuation-fifo-avg` / `inventory-cogs-posting` /
  `inventory-stock-movement-ledger` (the other inventory fragments carrying a
  `productSku` / `Product` relation that must be rewired)
- `bookkeeping-accounts-payable-core` / `bookkeeping-purchase-order-3way-01-schemas-and-registers`
  (AP + 3-way-match that reference the vendor — kept against the financial
  profile)
- ADR-019 (integration registry), ADR-022 (apps consume OR abstractions),
  ADR-037 (modular config fragments + canonical REQ-ID), the "Contact is a
  Nextcloud entity" rule (vendor identity = NC addressbook)

## Out of scope

- Owning, ingesting, or exposing the pipelinq `Product`/`Supplier` master —
  that is `pipelinq-product-vendor-master`.
- Changing the shillinq IFRS15/16 contract accounting schemas (`Contract`,
  `LeaseContract`, … — they stay; a separate signing/decision change covers
  approvals).
- Removing the COGS / valuation / stock-movement engines — they stay in
  shillinq verbatim; only their product **reference** changes.

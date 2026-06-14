# Design — shillinq Product/Vendor master → pipelinq

## Problem

Per Ruben: **"Keep stock-keeping in shillinq, but anything about product
definitions or vendors should be in pipelinq."** shillinq today owns two
master-data surfaces that the boundary assigns elsewhere, and pipelinq ships
the same `Product` master independently — a textbook ADR-012 duplication. We
must move the *definitions* out, keep the *stock-keeping*, and rewire the FKs
without losing data or breaking AP / 3-way-match.

## Capability ownership AFTER this change

| Capability | Owner after change | Notes |
|---|---|---|
| Product definition (SKU, name, category, pricing, attributes) | **pipelinq** | `Product` + `ProductAttribute` registers move; canonical `Supplier` catalog too. |
| Product attribute templates (seeds) | **pipelinq** | `lib/Settings/seeds/product-attributes-*.json` migrate to pipelinq. |
| Vendor *identity* (legal name, KvK, BTW, address, contacts) | **Nextcloud Contact (addressbook)** | OCP\Contacts; the "Contact is a NC entity" rule. |
| Vendor *commercial / catalog* master | **pipelinq** | pipelinq `Supplier` keyed by `contactsUid`. |
| Vendor *AP / financial* profile (payment terms, IBAN/BIC, credit limit, default expense account, dunning policy, AP balances) | **shillinq** | demoted `VendorMaster` → `VendorFinancialProfile`, keyed by `contactsUid`. |
| Stock on hand / reserved / in-transit / available (`InventoryStock`) | **shillinq** | unchanged; FK `productSku` → `productId`. |
| Stock-by-location, reserve stock, stock dashboard | **shillinq** | unchanged. |
| Stock-movement ledger (StockMove) | **shillinq** | unchanged; carries `productId`. |
| Inventory lots / batches / expiry | **shillinq** | unchanged; carries `productId`. |
| Barcodes + scanning | **shillinq** | `Barcode` stays in shillinq (a scan resolves to a `productId`); barcode *master print* may also live in pipelinq POS but the warehouse barcode register is stock-side. |
| Inventory valuation (FIFO / AVG), `unitCost` | **shillinq** | unchanged; valuation is GL-coupled. |
| COGS GL posting (`InventoryGLConfig`, posting history) | **shillinq** | unchanged. |
| Reorder rules / low-stock alerts | **shillinq** | unchanged; reads product reorder thresholds it owns locally or via `productId`. |
| Purchase orders / goods receipts | **shillinq** | unchanged; lines carry `productId` + reference the financial vendor profile. |
| Supplier invoices / AP / 3-way-match | **shillinq** | unchanged; matches against `productId` + the financial vendor profile. |
| IFRS15/16 contract accounting (`Contract`, `LeaseContract`, …) | **shillinq** | out of scope; stays. |

## Key decisions

### D1 — `Product` + `ProductAttribute` registers are REMOVED from shillinq, not duplicated
The two registers (and the `barcodeFormat`/`skuTemplate`/`defaultBarcode`
additive `Product` properties from `20-inventory-barcode-sku.json`) are deleted
from shillinq's register surface. The pipelinq change owns the canonical
schemas. shillinq inventory references them only by `productId` over the
ADR-019 registry — never holds a writable product definition.

### D2 — Stock FK migrates `productSku` → `productId`
Every shillinq inventory register that today carries `productSku` (FK to
`Product.sku`) or a `Product` relation gets a `productId` FK to the pipelinq
`Product` (resolved via the integration registry). The fragments affected,
verified against the live code:

| Fragment | Field / relation today | Rewire |
|---|---|---|
| `inventory-stock-tracking.json` (`InventoryStock`) | `productSku` (req'd) + `x-openregister-relations.product` → `Product.sku` + `x-openregister-unique [productSku, locationCode, administrationId]` | `productId` (req'd) + relation drops to a registry reference; unique key becomes `[productId, locationCode, administrationId]`; `status` mirror + `unitCost` stay local |
| `20-inventory-barcode-sku.json` (`Barcode`) | `productSku` (req'd) + `x-openregister-relations.product` → `Product.sku`; additive `Product.{skuTemplate,defaultBarcode,barcodeFormat}` | `Barcode.productId`; the additive `Product.*` properties are **removed** (they extend a schema that no longer lives here) |
| `inventory-lot-batch-expiry.json` | embeds/relates `Product` | `productId` reference |
| `inventory-stock-movement-ledger.json` | `Product`/`productSku` on StockMove | `productId` |
| `inventory-valuation-fifo-avg.json` | `Product`/`productSku` valuation layer | `productId` |
| `inventory-cogs-posting.json` | `Product`/`productSku` for COGS lines | `productId` |
| `inventory-cycle-count.json` | `Product`/`productSku` for counted item | `productId` |
| `inventory-mobile-scanner.json` | scans → `Product` | resolves to `productId` |

The embedded denormalised fields shillinq keeps for list-render efficiency
(`InventoryStock.locationName`-style caches, `status` mirror, `unitCost`) are
explicitly documented as **read caches refreshed from pipelinq**, not authored
in shillinq.

### D3 — `VendorMaster` is demoted to `VendorFinancialProfile`, keyed by `contactsUid`
The `VendorMaster` schema (slug `VendorMaster`, monolith) is **renamed** to
`VendorFinancialProfile` and stripped of identity master-data. It KEEPS:
`paymentTermDays`, `iban`, `bic`, `defaultExpenseAccountNumber`,
`dunningPolicyId`, `administrationId`, `lifecycleState` (active/blocked/
archived), plus a new `creditLimit` and `apBalance` (derived). It LOSES
authorship of identity fields: `name`, `tradingName`, `kvkNumber`, `btwNumber`,
`address`, `email`, `phone` become **nullable denormalised read caches**
resolved from the NC Contact / pipelinq `Supplier`. It GAINS a required
`contactsUid` FK (the NC addressbook UID = the join key shared with the
pipelinq `Supplier`) and replaces `vendorNumber`-as-identity with
`contactsUid`-as-identity (`vendorNumber` stays as a local AP reference).

The lifecycle (block / unblock / archive) and the AP semantics ("blocked →
no new AP invoices may post") are unchanged — they are *financial* controls,
which is exactly what stays.

### D4 — The `Vendors` nav becomes a financial-profile view, not a master editor
In `src/menu-layout.json` the `Vendors` leaf stays relocated under
`Purchasing` (current state), but its bound page is retitled "Vendor financial
profile" and its create/edit affordance is removed — new vendor *identity* is
created as an NC Contact / pipelinq `Supplier`, and the financial profile is
attached. The page stays routable (read + financial-field edit only). This
follows the established `menu-layout.json` pattern where a page can stay
routable while its role changes. The AP `Payee` register
(`bookkeeping-accounts-payable-core.json`, with `contactRef`) already pointed
at a contact abstraction — it converges onto the same `contactsUid` key.

### D5 — Cross-app resolution is via the ADR-019 integration registry
shillinq never hard-codes a pipelinq URL. A `shillinq → pipelinq` integration
entry resolves: (a) `productId` → product definition (name/SKU/pricing/
attributes) for list/detail render and valuation `unitCost` seeding;
(b) `contactsUid` → pipelinq `Supplier` commercial profile. When pipelinq /
the registry is unavailable, shillinq renders the local denormalised cache and
flags it stale — stock-keeping and GL posting keep working on cached
`productId` + `unitCost` (they must not block on a remote lookup).

### D6 — Migration (no data loss)
A `lib/Repair/MigrateProductVendorMasterToPipelinq.php` step (ADR-022 OR
data-migration, idempotent, fail-closed):
1. **Export** every shillinq `Product` object (incl. the barcode-array and the
   `skuTemplate`/`defaultBarcode`/`barcodeFormat` additives) and every
   `ProductAttribute` object + the `product-attributes-*` seeds to a transfer
   payload the pipelinq change ingests; record the returned pipelinq
   `productId` per old `sku`.
2. **Rewire** every shillinq stock/PO/AP object: set `productId` from the
   sku→id map; keep `productSku` as a transitional read-only alias for one
   release, then drop.
3. **Vendor**: for each `VendorMaster`, ensure an NC Contact exists (match on
   KvK/BTW/name, else create), capture its `contactsUid`, export identity +
   commercial fields to the pipelinq `Supplier`, and rewrite the local record
   as `VendorFinancialProfile` keyed by that `contactsUid` (retain financial
   fields, null the migrated identity caches to be re-resolved).
4. **Verify**: counts in == counts out; on any mismatch the step aborts and
   leaves shillinq state untouched (no partial move).

## Alternatives considered

- **Keep `Product` in shillinq and let pipelinq read it.** Rejected — inverts
  the boundary (stock-keeper would own definitions) and leaves the ADR-012
  duplication unresolved; pipelinq is the POS/commercial owner.
- **Delete `VendorMaster` entirely, push all vendor data to pipelinq.**
  Rejected — payment terms / IBAN / credit / AP balances are *financial* and
  GL-coupled; they belong with AP in shillinq. Only identity + catalog move.
- **Embed product definition copies in each stock record.** Rejected — that is
  the duplication we are removing; we keep only a thin denormalised render
  cache, clearly marked non-authoritative.
- **Hard-code the pipelinq HTTP endpoint.** Rejected — ADR-019 mandates the
  integration registry.

## Risks & mitigations

- **Stale product cache** → stock list shows old name/price. Mitigation: D5
  refresh on registry availability + stale flag; GL posting uses local
  `unitCost`, never the remote price, so valuation is unaffected.
- **Contact-match ambiguity during vendor migration** (two vendors, same
  name). Mitigation: match priority KvK → BTW → name+IBAN; unmatched vendors
  create a fresh Contact rather than merge; the step reports every fuzzy match
  for operator review and never auto-merges on name alone.
- **3-way-match breakage** if `productId` is missing on legacy lines.
  Mitigation: transitional `productSku` alias retained one release; match
  engine falls back to alias until the rewire is confirmed for every line.
- **pipelinq change not yet deployed** → registry resolution fails. Mitigation:
  this change is gated on `pipelinq-product-vendor-master`; until it is live,
  the migration step is a no-op and the local registers remain authoritative.

## Rollout

1. Land `pipelinq-product-vendor-master` (canonical master + ingest endpoint +
   registry export).
2. Configure the `shillinq → pipelinq` ADR-019 integration entry.
3. Run `MigrateProductVendorMasterToPipelinq` (export + rewire + vendor
   demotion), verify counts.
4. Remove `Product`/`ProductAttribute` registers + the barcode-sku additive
   `Product.*` properties from shillinq; retitle the `Vendors` page; drop the
   transitional `productSku` alias in the following release.

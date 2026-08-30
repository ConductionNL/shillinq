---
status: draft
---

# Inventory Valuation (FIFO + moving-average)

## Placement & Information Architecture

**Placement type:** `SETTING+DETAIL_TAB` (compound — implement all of the following):

- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.
- **`DETAIL_TAB`** — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Beheer (default valuation method) + Voorraad / Producten → COGS-mapping tab

**Rationale:** Per-category config + per-product override.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

FIFO + moving-average valuation per item per warehouse. Minimal viable pair.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 19/22 competitors
- **Dependencies:** inventory-stock-movement-ledger

## Cross-app integration

Drives COGS posting into shillinq GL.

## Competitor Evidence (from intelligence-db)

- assetbots :: Asset Cost & Depreciation Tracking :: Straight-line depreciation per asset
- cin7-core :: FIFO / Average / FIFO-Cost Methods :: Configurable valuation per item or org
- cin7-core :: Landed Cost Allocation :: Apportion freight/duty across received items
- cin7-core :: Multi-Currency / Multi-Location :: Cost in any currency; valuation per warehouse
- erpnext-stock :: FIFO + Moving Average Valuation :: Per-warehouse, per-item valuation method; LIFO not supported by design
- erpnext-stock :: Landed Cost Voucher :: Distribute freight/duty across received items to update valuation
- fishbowl :: Landed Cost Apportionment :: Landed-cost across receipts
- fishbowl :: Multi-Currency Costing :: FX-aware costing for international procurement
- hike-pos :: Multi-Currency :: Multi-currency pricing
- inflow :: Multi-currency Costing :: Buy in USD, hold/sell in CAD with FX
- netsuite-inventory :: Costing: Avg, FIFO, LIFO, Standard, Specific :: All major valuation methods supported
- netsuite-inventory :: Landed Cost Allocation :: Distribute landed costs across receipts
- netsuite-inventory :: Vendor-Consigned Inventory (2026.1) :: Track vendor-owned stock at your facility, COGS at sale
- odoo-inventory :: Landed Cost :: Apportion freight + duty to received items
- sage-intacct :: Costing: Avg, FIFO, LIFO, Std, Specific :: All major valuation methods configurable per item
- snipe-it :: Depreciation Tracking :: Straight-line depreciation per asset class
- tryton-stock :: Cost Price History (FIFO/Average) :: Per-product configurable cost method, full history
- tryton-stock :: Product Cost Method per Product :: FIFO/LIFO/average configurable individually
- zoho-inventory :: Multi-currency + Tax :: Buy/sell across currencies with FX

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 19 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

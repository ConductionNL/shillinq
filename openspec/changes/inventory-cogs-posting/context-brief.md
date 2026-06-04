---
status: draft
---

# Inventory Auto-post COGS + Inventory-asset GL Entries

## Placement & Information Architecture

**Placement type:** `ACTION+SETTING` (compound — implement all of the following):

- **`ACTION`** — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.
- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** "Genereer COGS-boeking" action on periode + Beheer (COGS-rekening mapping)

**Rationale:** Auto-post action + mapping config.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Auto-post COGS on sale, inventory-asset on receipt, adjustment on count variance. ERPNext 'Perpetual Inventory' pattern.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 16/22 competitors
- **Dependencies:** inventory-valuation-fifo-avg

## Cross-app integration

Native integration with shillinq GL (bookkeeping-general-ledger).

## Competitor Evidence (from intelligence-db)

- afas-hrm :: Payroll cost posting to bookkeeping :: AFAS Profit single-system: salariskosten direct in grootboek zonder integratie
- assetbots :: Asset Cost & Depreciation Tracking :: Straight-line depreciation per asset
- brightpearl :: Built-in Accounting (full GL) :: Native double-entry accounting with COGS posting
- chromis-pos :: CSV export of sales + Z-report :: Export for bookkeeper import; no native GL
- cin7-core :: Built-in Accounting (or sync to Xero/QBO) :: Native books or sync to external GL
- cin7-core :: FIFO / Average / FIFO-Cost Methods :: Configurable valuation per item or org
- cin7-core :: Landed Cost Allocation :: Apportion freight/duty across received items
- cin7-core :: Multi-Currency / Multi-Location :: Cost in any currency; valuation per warehouse
- dvi-salonsoftware :: Exact Online / SnelStart koppeling :: NL boekhoudpakket-koppelingen native
- employes :: Payroll cost posting to bookkeeping :: Employes posts salariskosten naar Exact/Moneybird/Snelstart/Twinfield
- erpnext-pos :: Native double-entry GL posting on submit :: POS Invoice posts directly to GL; no export needed
- erpnext-stock :: FIFO + Moving Average Valuation :: Per-warehouse, per-item valuation method; LIFO not supported by design
- erpnext-stock :: Landed Cost Voucher :: Distribute freight/duty across received items to update valuation
- erpnext-stock :: Perpetual Inventory Accounting :: Auto GL postings on every stock movement (stock-in-hand / stock received but not billed)
- exact-online-hrm :: Payroll cost posting to bookkeeping :: Exact native auto-post of salariskosten naar Exact Online grootboek
- fishbowl :: Landed Cost Apportionment :: Landed-cost across receipts
- fishbowl :: Multi-Currency Costing :: FX-aware costing for international procurement
- fishbowl :: QuickBooks Tight Integration :: Real-time sync to QuickBooks for COGS + AR + AP
- hike-pos :: Multi-Currency :: Multi-currency pricing
- hike-pos :: Xero + QuickBooks Sync :: Bi-directional accounting sync
- inflow :: Multi-currency Costing :: Buy in USD, hold/sell in CAD with FX
- inflow :: QuickBooks / Xero Sync :: Bi-directional sync of items + COGS + bills
- korona-cloud :: DATEV, Xero, QuickBooks; CSV journal :: DATEV native (DE); EU bookkeeping export
- lightspeed-retail :: Xero, QuickBooks, Sage, Exact Online connectors :: Native NL Exact Online for Lightspeed; daily journal post
- loket :: Payroll cost posting to bookkeeping :: Loket posts salariskosten + sociale lasten + vakantiegeld reservering to NL bookkeeping packages

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

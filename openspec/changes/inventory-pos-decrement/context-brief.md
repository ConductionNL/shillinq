---
status: draft
---

# Inventory POS-driven Stock Decrement

## Purpose

POS sale in pipelinq triggers stock decrement + COGS post in shillinq. Central to ecosystem architecture.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 20/22 competitors
- **Dependencies:** inventory-stock-tracking, inventory-cogs-posting

## Cross-app integration

Consumes pipelinq.PosLine.stockMovement event.

## Competitor Evidence (from intelligence-db)

- cin7-core :: Open API + Webhooks :: REST API for custom integrations
- erpnext-stock :: Pick List + Wave/Batch Picking :: Generate pick lists from sales orders, batch multiple orders into one walk
- inflow :: API + Webhooks (Mid+ tier) :: Public REST API and event webhooks
- inflow :: B2B Showroom (self-serve orders) :: Public catalog + ordering portal for wholesale buyers
- inflow :: Pick / Pack / Ship Workflow :: Multi-stage fulfilment with scan validation
- inflow :: Showroom Cart → Quote → Invoice :: B2B portal generates quotes that convert to invoices
- lightspeed-retail :: Detailed Sales-by-Product Reports :: Sales velocity drives reorder decisions
- lightspeed-retail :: Open API + Webhooks :: REST API for integrations
- mintsoft :: 3PL Billing Engine :: Per-action billing (receive, store, pick, pack, ship)
- mintsoft :: API Access (REST) :: Open API for custom integrations
- mintsoft :: Client Portal :: Self-service portal for 3PL clients to view stock
- picqer :: Multi-Currency + Multi-Language :: NL/EN/DE/FR with EUR/USD/GBP
- picqer :: REST API + Webhooks :: Open API; widely used for custom integrations
- tryton-stock :: Customer/Supplier Locations :: Customer and supplier locations let inbound/outbound be modeled as moves
- tryton-stock :: Shipment In/Out/Internal :: Three shipment types covering receipt, dispatch, internal transfer

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 15 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

---
status: draft
---

# Inventory Lot/Batch + Expiry with FEFO

## Purpose

Lot/batch tracking with expiry dates and First-Expiry-First-Out picking. **CRITICAL for pet food and perishables.**

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/22 competitors
- **Dependencies:** inventory-stock-movement-ledger

## Competitor Evidence (from intelligence-db)

- blue-yonder-wms :: Lot Trace + Recall :: Lot trace forwards + backwards; recall workflow
- cin7-core :: Lot & Expiry Tracking :: FEFO picking for perishables, expiry alerts
- erpnext-stock :: Batch & Serial No Tracking :: Lot/batch with manufacture + expiry dates, serial per piece, FEFO picking
- fishbowl :: Lot / Serial Number Tracking :: Lot + serial with expiry; FEFO support
- inflow :: Lot/Batch + Expiration Dates :: FEFO picking; expiry warnings
- manhattan-active-wm :: Lot + Serial Tracking :: Lot/serial tracking with full trace
- netsuite-inventory :: Advanced Inventory: Lot/Serial/Bin/UOM :: Full lot, serial, bin, multi-UoM combined
- odoo-inventory :: Lot/Serial with Expiry :: Lot + serial with FEFO and expiry alerts
- sage-intacct :: Lot + Serial Tracking :: Lot + serial with full traceability
- sap-ewm :: Batch Management :: Batch tracking with FEFO; expiry/shelf-life rules
- sortly :: Date-Based Alerts (expiry/maintenance) :: Reminders before expiration or scheduled maintenance
- tryton-stock :: Lot Numbers :: Per-product lot with optional expiry; required-lot flag at product level
- zoho-inventory :: Serial + Batch Tracking :: Both modes per item; expiry support

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 13 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

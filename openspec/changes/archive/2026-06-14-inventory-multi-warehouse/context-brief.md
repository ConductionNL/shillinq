---
status: draft
---

# Inventory Hierarchical Locations (warehouse → zone → bin)

## Purpose

Hierarchical locations with inter-location transfers; in-transit warehouse pattern.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 22/22 competitors
- **Dependencies:** inventory-stock-tracking

## Competitor Evidence (from intelligence-db)

- blue-yonder-wms :: Cross-Dock + Flow-Through :: High-velocity cross-dock for retail/grocery
- blue-yonder-wms :: Putaway Strategy Configurator :: Configurable rules for putaway destination
- blue-yonder-wms :: Slotting Optimisation :: Velocity- + affinity-based bin assignment
- blue-yonder-wms :: Yard Management Integration :: Native yard + dock-door scheduling
- brightpearl :: Multi-Location Real-Time Stock :: Live stock across locations with reorder rules per loc
- brightpearl :: Warehouse Transfer Workflow :: In-transit qty visible during inter-warehouse moves
- cin7-core :: Bin Locations within Warehouse :: Sub-warehouse bin tracking with mobile picking
- erpnext-stock :: Multi-warehouse with Group Hierarchy :: Tree of warehouses with group-rollup reporting
- erpnext-stock :: Putaway Rule :: Auto-suggest target warehouse + bin based on item/qty rules
- erpnext-stock :: Stock Transfer + In-Transit Warehouse :: Two-step transfer keeps qty visible while moving between sites
- fishbowl :: Multi-Location Real-Time Tracking :: Inventory levels, locations, movements in real time
- hike-pos :: Multi-Outlet Stock Transfers :: Inter-outlet transfers with in-transit tracking
- inflow :: Unlimited Locations :: Tracks stock across unlimited sites/warehouses on every paid tier
- lightspeed-retail :: Inventory Transfer Between Stores :: In-transit qty during transfer
- lightspeed-retail :: Real-Time Multi-Location Tracking :: Live stock across stores + central warehouse
- manhattan-active-wm :: Cross-Dock Flow Through :: High-velocity cross-dock
- manhattan-active-wm :: Multi-Owner / 3PL Mode :: 3PL multi-client stock segregation
- manhattan-active-wm :: Slotting + Real-Time Re-Slot :: Dynamic re-slotting based on velocity
- manhattan-active-wm :: Yard / Dock Management :: Yard mgmt, dock door scheduling
- mintsoft :: Multi-Client (3PL Mode) :: Per-client stock segregation; client portals; 3PL billing
- mintsoft :: Pallet + Carton Tracking :: Pallet/carton hierarchy with breakdown tracking
- mintsoft :: Real-Time Multi-Location Reporting :: Live visibility across multiple warehouses
- netsuite-inventory :: Cross-Subsidiary Inventory Transfers :: Intercompany transfers with auto-elimination GL
- netsuite-inventory :: Directed Putaway :: System suggests optimal bin for receipt based on rules
- odoo-inventory :: Multi-Step Receipt/Delivery :: Configurable receive→quality→stock; pick→pack→ship steps

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

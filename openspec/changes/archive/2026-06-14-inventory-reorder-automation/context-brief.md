---
status: draft
---

# Inventory Min/Max + Reorder Point

## Purpose

Min/max levels per item per location with low-stock alerts; auto-PO generation optional.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 22/22 competitors
- **Dependencies:** inventory-stock-tracking

## Cross-app integration

Triggers purchaseq PO creation.

## Competitor Evidence (from intelligence-db)

- brightpearl :: Reports + Sales Velocity Analytics :: Velocity reports drive inventory decisions
- erpnext-stock :: Auto Re-order Level + Material Request :: Per-item min/max stock with auto material-request generation
- hike-pos :: Low-Stock Alerts :: Email + dashboard on threshold breach
- inflow :: Reorder Points (per location) :: Min/max per item per location with low-stock alerts
- lightspeed-retail :: Low Stock Reports per Store :: Per-location low-stock reports
- partkeepr :: Min-Stock Reorder Filter :: Saved view showing all parts below min level
- sortly :: Low-Stock Alerts (custom thresholds) :: Per-item min qty with email/push alerts
- vagaro :: Low-Stock Alerts :: Push + email on threshold breach
- zoho-inventory :: Auto-Reorder Point + Notification :: Email + dashboard alerts on min-stock breach

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 9 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

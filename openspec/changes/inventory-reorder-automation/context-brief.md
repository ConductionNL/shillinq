---
status: draft
---

# Inventory Min/Max + Reorder Point

## Placement & Information Architecture

**Placement type:** `SETTING+ACTION` (compound — implement all of the following):

- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.
- **`ACTION`** — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Beheer (reorder-points per SKU) + "Genereer reorder-PO" action on alert

**Rationale:** Min/max config + run action.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

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

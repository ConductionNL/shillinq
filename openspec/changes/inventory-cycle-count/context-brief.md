---
status: draft
---

# Inventory Cycle Count / Stock-take with variance

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Voorraad / Inventarisatie

**Rationale:** Cycle count page.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Physical count, cycle count, partial counts, variance posting with reason codes.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 16/22 competitors
- **Dependencies:** inventory-stock-tracking

## Competitor Evidence (from intelligence-db)

- blue-yonder-wms :: Cycle Count + Audit :: Cycle counts with variance approval
- brightpearl :: Stock Adjustments with Reason Codes :: Categorise adjustments for variance + GL impact
- cin7-core :: Stock Take with Mobile Scanner :: Scan-based stock takes (full or partial); variance posting
- erpnext-stock :: Stock Reconciliation :: Bulk physical-count upload with variance posting
- fishbowl :: Cycle Counting :: Partial counts by zone or random sampling
- hike-pos :: Stock Adjustment with Reason :: Adjust with reason code (damage, loss, count)
- hike-pos :: Stock Take (full + partial) :: Mobile scan-based counts
- inflow :: Cycle Count Mode :: Partial counts by zone/category with variance approval
- lightspeed-retail :: Stock Take with Mobile Counter :: Mobile scan-based stock takes (full or partial)
- mintsoft :: Stock Count + Variance Reports :: Scheduled and ad-hoc counts with variance posting
- netsuite-inventory :: Cycle Counting with Variance Approval :: Mobile-based cycle counts with approval workflow
- picqer :: Stock Take + Cycle Count :: Mobile-scan cycle counts; variance posting
- sage-intacct :: Stock Adjustment with Approval :: Adjustments routed through approval workflow
- tryton-stock :: Inventory Count :: Inventory document with expected vs counted, posts adjustment moves
- vagaro :: Recurring Inventory Counts :: Schedule periodic counts (weekly/monthly)
- zoho-inventory :: Inventory Adjustment Reasons :: Categorise adjustments (damage, loss, count) for GL impact

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 16 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

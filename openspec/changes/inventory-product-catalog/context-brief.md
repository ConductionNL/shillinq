---
status: draft
---

# Inventory Product Catalog (items, SKUs, attributes, UoM)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Voorraad / Producten

**Rationale:** Product catalog page.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Universal foundation — items with SKUs, attributes, unit of measure. Every competitor's bedrock.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 22/22 competitors
- **Dependencies:** none

## Cross-app integration

Used by pos-product-catalogue (pipelinq) and purchaseq supplier catalog.

## Competitor Evidence (from intelligence-db)

- assetbots :: Asset Check-In / Check-Out :: Loan equipment to people, track due-back
- assetbots :: Audit Trail per Asset :: Immutable history of all changes
- assetbots :: Built-in Barcode/QR Scanner (no hardware) :: Web/mobile scanner uses device camera, no SKU-scanner needed
- assetbots :: CSV Import + Export :: Bulk migration in/out
- assetbots :: Custom Fields per Asset Type :: Add unlimited custom fields per category
- assetbots :: Email Notifications on Events :: Configurable alerts on overdue, checked-out, etc.
- assetbots :: Integration via Zapier :: Zapier-based integration to other SaaS
- assetbots :: Label Designer + Print :: Design + bulk-print barcode labels
- assetbots :: Multi-tenant SaaS :: Multiple organisations isolated in single deployment
- assetbots :: Reporting & Dashboards :: Built-in dashboards for utilisation, location, status
- assetbots :: Reservations System :: Reserve assets for future use windows
- assetbots :: Role-Based Access Control :: Admin/manager/user roles with per-resource permissions
- blue-yonder-wms :: API + Integration Framework :: REST + queue-based integrations
- blue-yonder-wms :: Microservices Cloud Architecture :: Modern microservices on Azure
- brightpearl :: Unlimited Users on All Plans :: No per-seat fee — flat platform pricing
- cin7-core :: Auto-Generated SKUs :: SKU templates from attribute combinations
- cin7-core :: Real-Time Variant-Level Stock :: Live stock per SKU/variant across all channels; oversell prevention
- erpnext-stock :: Barcode + Multi-Barcode per Item :: Multiple GTIN/UOM barcodes per item (e.g. each + carton)
- erpnext-stock :: Inventory Dimension (custom axes) :: Slice stock by project/grade/customer in addition to warehouse
- erpnext-stock :: Stock Entry (multi-type) :: Material receipt, transfer, issue, manufacture, repack — single doctype across flows
- erpnext-stock :: Stock Reservation :: Reserve qty against sales order/production plan; soft + hard reservations
- fishbowl :: Asset Tracking Module :: Fixed-asset variant alongside inventory
- fishbowl :: Barcode Scanning (built-in + mobile) :: Native barcode scanning across receive/transfer/ship
- fishbowl :: Fishbowl Drive (cloud-hosted option) :: Cloud-native variant with same feature surface
- hike-pos :: Barcode Scanning (in/out) :: Camera or USB scanner; receive + sell

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

---
status: draft
---

# Inventory Stock-on-hand per Item per Location

## Purpose

Quantity on hand, reserved, in-transit, available per item per warehouse. Includes Tryton-style Stock Move primitive: every move debits source + credits destination location.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 22/22 competitors
- **Dependencies:** inventory-product-catalog

## Competitor Evidence (from intelligence-db)

- assetbots :: Asset Check-In / Check-Out :: Loan equipment to people, track due-back
- assetbots :: Audit Trail per Asset :: Immutable history of all changes
- assetbots :: CSV Import + Export :: Bulk migration in/out
- assetbots :: Custom Fields per Asset Type :: Add unlimited custom fields per category
- assetbots :: Email Notifications on Events :: Configurable alerts on overdue, checked-out, etc.
- assetbots :: Integration via Zapier :: Zapier-based integration to other SaaS
- assetbots :: Multi-tenant SaaS :: Multiple organisations isolated in single deployment
- assetbots :: Reporting & Dashboards :: Built-in dashboards for utilisation, location, status
- assetbots :: Reservations System :: Reserve assets for future use windows
- assetbots :: Role-Based Access Control :: Admin/manager/user roles with per-resource permissions
- blue-yonder-wms :: API + Integration Framework :: REST + queue-based integrations
- blue-yonder-wms :: Microservices Cloud Architecture :: Modern microservices on Azure
- brightpearl :: Unlimited Users on All Plans :: No per-seat fee — flat platform pricing
- cin7-core :: Real-Time Variant-Level Stock :: Live stock per SKU/variant across all channels; oversell prevention
- erpnext-stock :: Inventory Dimension (custom axes) :: Slice stock by project/grade/customer in addition to warehouse
- erpnext-stock :: Stock Entry (multi-type) :: Material receipt, transfer, issue, manufacture, repack — single doctype across flows
- erpnext-stock :: Stock Reservation :: Reserve qty against sales order/production plan; soft + hard reservations
- fishbowl :: Asset Tracking Module :: Fixed-asset variant alongside inventory
- fishbowl :: Fishbowl Drive (cloud-hosted option) :: Cloud-native variant with same feature surface
- lightspeed-retail :: Pet-Specific: Brand/Diet/Life-Stage Tags :: Free-text tags suit pet-food taxonomy (brand/diet/life-stage)
- manhattan-active-wm :: Advanced Analytics Dashboards :: Built-in BI for warehouse KPIs
- manhattan-active-wm :: Cloud-Native Microservices :: Continuous-update, no-version-lock architecture
- manhattan-active-wm :: Configurable Workflows (no-code) :: Workflow designer for custom processes
- manhattan-active-wm :: Open API + Event Streams :: REST + Kafka-based event streams
- mintsoft :: Cloud-Hosted (no on-prem) :: Fully SaaS; no specialist hardware needed

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

---
status: draft
---

# Inventory SKU + Multi-barcode per item

## Purpose

SKU generation, multi-barcode (EAN, GTIN, internal) per item; per-UoM barcode (each + carton).

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 12/22 competitors
- **Dependencies:** inventory-product-catalog

## Cross-app integration

Lookup endpoint used by pipelinq pos-barcode-scan.

## Competitor Evidence (from intelligence-db)

- assetbots :: Built-in Barcode/QR Scanner (no hardware) :: Web/mobile scanner uses device camera, no SKU-scanner needed
- assetbots :: Label Designer + Print :: Design + bulk-print barcode labels
- cin7-core :: Auto-Generated SKUs :: SKU templates from attribute combinations
- erpnext-stock :: Barcode + Multi-Barcode per Item :: Multiple GTIN/UOM barcodes per item (e.g. each + carton)
- fishbowl :: Barcode Scanning (built-in + mobile) :: Native barcode scanning across receive/transfer/ship
- hike-pos :: Barcode Scanning (in/out) :: Camera or USB scanner; receive + sell
- inflow :: Built-in Barcode Generation + Label Print :: Generate, design, print labels; ZPL printer support
- lightspeed-retail :: Barcode Label Generator :: Print barcode labels in-app; shelf-edge labels
- partkeepr :: Manufacturer + MPN tracking :: Track manufacturer part number distinct from internal SKU
- picqer :: Multi-Barcode per Item :: EAN + GTIN + internal SKU all map to same item
- sortly :: Custom QR Codes (in-app generation) :: Generate QR codes free for items without existing barcodes
- vagaro :: Barcode Scan via Camera :: Camera-based scan; no hardware needed

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 12 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

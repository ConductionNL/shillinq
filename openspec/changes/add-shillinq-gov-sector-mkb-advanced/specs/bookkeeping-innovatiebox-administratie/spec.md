# Spec: Bookkeeping — Innovatiebox Administration

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (MKB / innovation)  
**Depends on:** bookkeeping-cost-centers-dimensions, bookkeeping-vpb-corporate-tax  
**Kind:** config

## Summary

Implement innovatiebox (IP tax regime) per Wet Vpb art. 12b: IP-asset valuation register + winsttoerekening per configurable sleutel + 5%-tariff administration.

## Entities

### IPAssetValuation (new)

Intellectual property asset with valuation method and tariff.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetType | enum | Yes | Type: `s-o-certificaat`, `octrooi`, `kwekersrecht`, or `other` |
| valuation | number | Yes | Valuation amount in EUR |
| valuationMethod | enum | Yes | `forfaitair` or `afpelmethode` |
| applicableTariff | number | Yes | Tax rate (5% for innovatiebox) |
| status | enum | Yes | One of `draft`, `valued`, `approved`, `archived` |

### WinstToerekening (new)

Per-period profit attribution to IP-assets via verdeelsleutel.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ipAssetId | string | Yes | FK to IPAssetValuation |
| period | date | Yes | Reporting period end date |
| attributedWinst | number | Yes | Attributed profit in EUR |
| verdeelsleutelBasis | string | Yes | Basis for attribution (e.g., `revenue-percentage`, `cost-allocation`) |

## Requirements

### REQ-IBA-001: IPAssetValuation register

SHALL declare `IPAssetValuation` register with valuation method options (forfaitair / afpelmethode).

### REQ-IBA-002: WinstToerekening register

SHALL declare `WinstToerekening` register enabling per-period profit attribution per configurable verdeelsleutel.

### REQ-IBA-003: 5%-tarief calculation

SHALL declare `x-openregister-calculations` computing 5%-tariffed winst per IP-asset per period.

#### Scenario: 5%-tarief computed correctly

GIVEN attributed winst €100k to S&O certificaat  
WHEN 5%-tarief calc runs  
THEN taxable amount = €100k * 5% = €5k per Wet Vpb 12b.

### REQ-IBA-004: Innovatiebox bijlage template

SHALL reference docudesk template for Vpb-aangifte innovatiebox section.

### REQ-IBA-005: Manifest navigation entry

SHALL add `featureFlags.mkb-innovatiebox` navigation.

## Test Plan

- PHPUnit: valuation CRUD; winsttoerekening per sleutel.
- Integration: 5%-tarief calc matches legal requirements.

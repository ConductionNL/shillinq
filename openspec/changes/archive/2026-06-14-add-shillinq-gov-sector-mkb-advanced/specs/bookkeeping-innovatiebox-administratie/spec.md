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

## ADDED Requirements

### Requirement: REQ-IBA-001 — IPAssetValuation register

The system SHALL declare an `IPAssetValuation` register with valuation method options (forfaitair / afpelmethode).

#### Scenario: IP asset is valued via a declared method

- **GIVEN** an IP asset of type `octrooi`
- **WHEN** it is valued using the `afpelmethode`
- **THEN** the IPAssetValuation record MUST persist the valuation and applicable 5% tariff.

### Requirement: REQ-IBA-002 — WinstToerekening register

The system SHALL declare a `WinstToerekening` register enabling per-period profit attribution per configurable verdeelsleutel.

#### Scenario: Profit is attributed to an IP asset per period

- **GIVEN** an IPAssetValuation and a reporting period
- **WHEN** a WinstToerekening is created with a verdeelsleutel basis
- **THEN** the attributed winst MUST link to the IP asset for that period.

### Requirement: REQ-IBA-003 — 5%-tarief calculation

SHALL declare `x-openregister-calculations` computing 5%-tariffed winst per IP-asset per period.

#### Scenario: 5%-tarief computed correctly

GIVEN attributed winst €100k to S&O certificaat  
WHEN 5%-tarief calc runs  
THEN taxable amount = €100k * 5% = €5k per Wet Vpb 12b.

### Requirement: REQ-IBA-004 — Innovatiebox bijlage template

The system SHALL reference a docudesk template for the Vpb-aangifte innovatiebox section.

#### Scenario: Innovatiebox section renders from a docudesk template

- **GIVEN** computed 5%-tariffed winst for a period
- **WHEN** the innovatiebox bijlage is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-IBA-005 — Manifest navigation entry

The system SHALL add a `featureFlags.mkb-innovatiebox` navigation entry.

#### Scenario: Innovatiebox navigation is feature-flag gated

- **GIVEN** the `mkb-innovatiebox` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the innovatiebox entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: valuation CRUD; winsttoerekening per sleutel.
- Integration: 5%-tarief calc matches legal requirements.

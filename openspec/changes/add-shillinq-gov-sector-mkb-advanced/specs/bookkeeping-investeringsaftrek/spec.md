# Spec: Bookkeeping — Investeringsaftrek (Investment Deduction)

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (MKB / innovation)  
**Depends on:** bookkeeping-fixed-assets-depreciation  
**Kind:** config

## Summary

Implement investment deduction schemes: KIA (kleinschaligheid), EIA (energie), MIA (milieu), Vamil (vrije afschrijving). Per-asset classifier + annual tarieven seed + RvO aanvraagdossier template.

## Entities

### FixedAsset (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| aftrekType | enum | No | Deduction type: `kia`, `eia`, `mia`, `vamil`, or null (non-deductible) |

### InvesteringClassifier (new, overlay)

Deduction classification on fixed asset.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetNumber | string | Yes | FK to FixedAsset |
| aftrekType | enum | Yes | Classification |
| bedrijfsmiddelCode | string | No | RvO bedrijfsmiddel classification code |
| aanvraagDate | date | No | RvO application date |
| status | enum | Yes | One of `draft`, `requested`, `approved`, `applied`, `archived` |

## Requirements

### REQ-INV-001: InvesteringClassifier overlay

SHALL declare `InvesteringClassifier` overlay enabling KIA/EIA/MIA/Vamil tagging on fixed assets.

### REQ-INV-002: Annual tarieven seed

SHALL ship `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` with KIA thresholds, EIA/MIA percentages, Vamil bedrijfsmiddel codes per 2026 RvO release.

### REQ-INV-003: Deduction calculation block

SHALL declare `x-openregister-calculations` computing deduction amounts per scheme (KIA drempel/oploop/max, EIA/MIA percentage, Vamil full depreciation) reading seeded tarieven.

#### Scenario: KIA computation respects limits

GIVEN asset €50k with aftrekType=kia  
WHEN KIA calc runs  
THEN deduction respects 2026 drempel (threshold) and oploop (phase-in) rules.

### REQ-INV-004: RvO aanvraagdossier template

SHALL reference docudesk template for RvO aanvraagdossier preparation.

### REQ-INV-005: Manifest navigation entry

SHALL add `featureFlags.mkb-investeringsaftrek` navigation.

## Test Plan

- PHPUnit: KIA/EIA/MIA/Vamil deduction calculations per 2026 tarieven.
- Integration: RvO dossier generation.

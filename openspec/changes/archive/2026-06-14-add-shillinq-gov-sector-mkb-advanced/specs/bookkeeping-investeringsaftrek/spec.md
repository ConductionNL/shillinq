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

## ADDED Requirements

### Requirement: REQ-INV-001 — InvesteringClassifier overlay

The system SHALL declare an `InvesteringClassifier` overlay enabling KIA/EIA/MIA/Vamil tagging on fixed assets.

#### Scenario: A fixed asset is tagged with an investment scheme

- **GIVEN** a FixedAsset
- **WHEN** an InvesteringClassifier with `aftrekType=eia` is applied
- **THEN** the asset MUST be classified for EIA deduction without a parallel asset table.

### Requirement: REQ-INV-002 — Annual tarieven seed

The system SHALL ship `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` with KIA thresholds, EIA/MIA percentages, and Vamil bedrijfsmiddel codes per the 2026 RvO release.

#### Scenario: Tarieven seed loads idempotently

- **GIVEN** a fresh install with investeringsaftrek enabled
- **WHEN** the repair-step seeding runs twice
- **THEN** the 2026 tarieven MUST be present exactly once.

### Requirement: REQ-INV-003 — Deduction calculation block

SHALL declare `x-openregister-calculations` computing deduction amounts per scheme (KIA drempel/oploop/max, EIA/MIA percentage, Vamil full depreciation) reading seeded tarieven.

#### Scenario: KIA computation respects limits

GIVEN asset €50k with aftrekType=kia  
WHEN KIA calc runs  
THEN deduction respects 2026 drempel (threshold) and oploop (phase-in) rules.

### Requirement: REQ-INV-004 — RvO aanvraagdossier template

The system SHALL reference a docudesk template for RvO aanvraagdossier preparation.

#### Scenario: Aanvraagdossier renders from a docudesk template

- **GIVEN** a computed deduction for an asset
- **WHEN** the RvO aanvraagdossier is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-INV-005 — Manifest navigation entry

The system SHALL add a `featureFlags.mkb-investeringsaftrek` navigation entry.

#### Scenario: Investeringsaftrek navigation is feature-flag gated

- **GIVEN** the `mkb-investeringsaftrek` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the investeringsaftrek entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: KIA/EIA/MIA/Vamil deduction calculations per 2026 tarieven.
- Integration: RvO dossier generation.

# Spec: Bookkeeping — R&D Subsidies (MKB)

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (MKB / innovation)  
**Depends on:** bookkeeping-subsidie-verantwoording  
**Kind:** config

## Summary

Implement R&D subsidy administration variants (MIT, SBIR, EU Horizon, EFRO/REACT-EU) as overlays on existing subsidie register. Per-regeling kostencategorieën + audit-pack templates per regeling.

## Entities

### Subsidie (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subsidieRegeling | enum | No | Scheme: `mit`, `sbir`, `eu-horizon`, `efro`, `react-eu` |

## ADDED Requirements

### Requirement: REQ-RDS-001 — subsidieRegeling enum on Subsidie

The system SHALL add a `subsidieRegeling` enum field to the `Subsidie` schema.

#### Scenario: A subsidy is tagged with its R&D regeling

- **GIVEN** a Subsidie
- **WHEN** its `subsidieRegeling` is set to `eu-horizon`
- **THEN** the subsidy MUST enforce the EU Horizon kostencategorieën constraints.

### Requirement: REQ-RDS-002 — Per-regeling kostencategorieën

SHALL declare per-regeling kostencategorieën enums (e.g., EU Horizon: "personnel costs", "subcontracting", "other direct", "indirect").

#### Scenario: Kostenrubriek validation per scheme

GIVEN SBIR subsidie  
WHEN posting expense category  
THEN only SBIR-defined categories are allowed.

### Requirement: REQ-RDS-003 — MIT audit-pack template

The system SHALL reference a docudesk template for the MIT audit dossier format.

#### Scenario: MIT audit pack renders from a docudesk template

- **GIVEN** a MIT subsidy with cost records
- **WHEN** the MIT audit dossier is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-RDS-004 — EU Horizon audit-pack template

The system SHALL reference a docudesk template per EU Horizon audit requirements.

#### Scenario: EU Horizon audit pack renders from a docudesk template

- **GIVEN** an EU Horizon subsidy with cost records
- **WHEN** the EU Horizon audit dossier is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-RDS-005 — Manifest navigation entry

The system SHALL add a `featureFlags.mkb-rd-subsidies` navigation entry for R&D subsidy administration.

#### Scenario: R&D subsidies navigation is feature-flag gated

- **GIVEN** the `mkb-rd-subsidies` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the R&D subsidies entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: subsidieRegeling enum constraints; kostenrubriek validation per scheme.
- Integration: audit-pack templates render per regeling.

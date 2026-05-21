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

## Requirements

### REQ-RDS-001: subsidieRegeling enum on Subsidie

SHALL add `subsidieRegeling` enum field to `Subsidie` schema.

### REQ-RDS-002: Per-regeling kostencategorieën

SHALL declare per-regeling kostencategorieën enums (e.g., EU Horizon: "personnel costs", "subcontracting", "other direct", "indirect").

#### Scenario: Kostenrubriek validation per scheme

GIVEN SBIR subsidie  
WHEN posting expense category  
THEN only SBIR-defined categories are allowed.

### REQ-RDS-003: MIT audit-pack template

SHALL reference docudesk template for MIT audit dossier format.

### REQ-RDS-004: EU Horizon audit-pack template

SHALL reference docudesk template per EU Horizon audit requirements.

### REQ-RDS-005: Manifest navigation entry

SHALL add `featureFlags.mkb-rd-subsidies` navigation for R&D subsidy administration.

## Test Plan

- PHPUnit: subsidieRegeling enum constraints; kostenrubriek validation per scheme.
- Integration: audit-pack templates render per regeling.

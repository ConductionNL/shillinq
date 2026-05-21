# Spec: Bookkeeping — SISA (Single Information Single Audit) Reporting

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-subsidie-verantwoording  
**Kind:** config

## Summary

Enable SISA (Single Information Single Audit) reporting for specifieke uitkeringen (conditional government grants). Per-regeling indicator register + annual rollup + BZK submission via openconnector.

## Entities

### SisaRegelingIndicator (new)

Performance indicator for a specific subsidy regeling.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subsidieId | string | Yes | FK to Subsidie |
| indicator | string | Yes | Indicator name (e.g., "aantal gerealiseerde woningen") |
| targetValue | number | No | Target quantity |
| actualValue | number | No | Actual quantity achieved |
| reportingDate | date | Yes | Reporting period end date |
| verificationStatus | enum | Yes | One of `unverified`, `verified`, `amended` |

### Subsidie (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sisaRegeling | boolean | No | Is this subsidy SISA-reportable? |

## Requirements

### REQ-SISA-001: SisaRegelingIndicator register

SHALL declare the `SisaRegelingIndicator` register for per-regeling performance tracking.

### REQ-SISA-002: SiSa-controleprotocol seed

SHALL ship `lib/Settings/seeds/sisa-controleprotocol-2026.json` with ~200 indicatoren per BZK 2026 release.

### REQ-SISA-003: SiSa-bijlage annual rollup

SHALL declare an `x-openregister-aggregations` view producing SiSa-bijlage table per controleprotocol at jaarrekening.

### REQ-SISA-004: Docudesk template reference

SHALL reference docudesk template for SiSa-bijlage rendering + signing.

### REQ-SISA-005: BZK submission via openconnector

SHALL declare openconnector source row for automatic SiSa upload to BZK endpoint.

## Test Plan

- PHPUnit: indicator CRUD and verification status lifecycle.
- Integration: bijlage rollup matches controleprotocol.

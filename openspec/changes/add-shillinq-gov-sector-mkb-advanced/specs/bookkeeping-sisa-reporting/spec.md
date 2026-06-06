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

## ADDED Requirements

### Requirement: REQ-SISA-001 — SisaRegelingIndicator register

The system SHALL declare the `SisaRegelingIndicator` register for per-regeling performance tracking.

#### Scenario: Indicator records track target versus actual per regeling

- **GIVEN** a Subsidie flagged `sisaRegeling: true`
- **WHEN** a SisaRegelingIndicator is created with a target and actual value
- **THEN** the indicator MUST be persisted as an OR-managed object linked to its Subsidie via `subsidieId`.

### Requirement: REQ-SISA-002 — SiSa-controleprotocol seed

The system SHALL ship `lib/Settings/seeds/sisa-controleprotocol-2026.json` with the indicatoren per the BZK 2026 release.

#### Scenario: Controleprotocol seed imports idempotently

- **GIVEN** a fresh install with the SiSa feature enabled
- **WHEN** the repair-step seeding runs twice
- **THEN** the controleprotocol indicators MUST be present exactly once (idempotent on re-run).

### Requirement: REQ-SISA-003 — SiSa-bijlage annual rollup

The system SHALL declare an `x-openregister-aggregations` view producing the SiSa-bijlage table per controleprotocol at jaarrekening.

#### Scenario: Bijlage rollup matches the controleprotocol structure

- **GIVEN** verified indicator records for a reporting year
- **WHEN** the SiSa-bijlage aggregation runs
- **THEN** the output MUST present one row per controleprotocol indicator with the actual value rolled up.

### Requirement: REQ-SISA-004 — Docudesk template reference

The system SHALL reference a docudesk template for SiSa-bijlage rendering and signing.

#### Scenario: Bijlage rendering binds to a docudesk template

- **GIVEN** a completed SiSa-bijlage aggregation
- **WHEN** the document is generated
- **THEN** rendering MUST resolve a docudesk template URI rather than a shillinq-local renderer.

### Requirement: REQ-SISA-005 — BZK submission via openconnector

The system SHALL declare an openconnector source row for automatic SiSa upload to the BZK endpoint.

#### Scenario: BZK submission rides openconnector

- **GIVEN** a signed SiSa-bijlage
- **WHEN** submission to BZK is triggered
- **THEN** it MUST route through an openconnector source row, not an app-local HTTP client.

## Test Plan

- PHPUnit: indicator CRUD and verification status lifecycle.
- Integration: bijlage rollup matches controleprotocol.

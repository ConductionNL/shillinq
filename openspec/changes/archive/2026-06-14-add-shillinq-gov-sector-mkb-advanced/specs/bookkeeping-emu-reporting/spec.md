# Spec: Bookkeeping — EMU Reporting

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-iv3-reporting  
**Kind:** config

## Summary

Implement EMU (Economic and Monetary Union) saldo and schuld reporting per ESA 2010 classifications. Adds `EsaClassifier` overlay on accounts + declarative aggregation rules for quarterly IV3 and annual jaarrekening reporting.

## Entities

### Account (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| esaClassifier | enum | No | ESA 2010 sector code: S.1311, S.1312, S.1313, S.1314, etc. |

### EsaClassifier (new, overlay)

Maps GL accounts to ESA 2010 sectors for aggregation.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | FK to Account.accountNumber |
| esaSector | enum | Yes | ESA classification |
| effectiveFrom | date | Yes | Effective date |

## ADDED Requirements

### Requirement: REQ-EMU-001 — EsaClassifier overlay schema

The system SHALL declare the `EsaClassifier` overlay enabling per-account ESA 2010 classification.

#### Scenario: An account carries an ESA 2010 sector classification

- **GIVEN** a GL account
- **WHEN** an EsaClassifier mapping with an `esaSector` is applied from its `effectiveFrom` date
- **THEN** the account MUST resolve to that ESA 2010 sector for EMU aggregation.

### Requirement: REQ-EMU-002 — ESA-2010 seed data

The system SHALL ship `lib/Settings/seeds/esa-2010-classifier.json` with the ESA sector mappings per the ESA 2010 standard.

#### Scenario: ESA-2010 seed loads the sector mappings

- **GIVEN** a fresh install with EMU reporting enabled
- **WHEN** the repair-step seeding runs
- **THEN** the ESA-2010 sector mappings MUST be present and idempotent on re-run.

### Requirement: REQ-EMU-003 — EMU-saldo quarterly calculation

The system SHALL declare `x-openregister-calculations` (or a thin PHP guard if an engine gap is confirmed) computing EMU-saldo per sector quarterly with the inclusion/exclusion rules per regulation.

#### Scenario: EMU saldo computed correctly

- **GIVEN** Q4 2025 postings classified to ESA sectors
- **WHEN** EMU-saldo is calculated
- **THEN** the result MUST match the CBS-published benchmark.

### Requirement: REQ-EMU-004 — EMU-schuld annual aggregation

The system SHALL declare an EMU-schuld aggregation for the annual jaarrekening (debt by sector per ESA).

#### Scenario: EMU-schuld aggregates debt by ESA sector

- **GIVEN** liability postings classified to ESA sectors for a year
- **WHEN** the annual EMU-schuld aggregation runs
- **THEN** debt MUST be grouped per ESA sector matching the jaarrekening totals.

### Requirement: REQ-EMU-005 — Manifest navigation entry

The system SHALL add a `featureFlags.gov-emu` navigation entry for EMU reporting views.

#### Scenario: EMU navigation is feature-flag gated

- **GIVEN** the `gov-emu` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the EMU reporting entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: EsaClassifier seed loads; EMU calc matches benchmark.
- Integration: quarterly materialization via scheduled workflow.

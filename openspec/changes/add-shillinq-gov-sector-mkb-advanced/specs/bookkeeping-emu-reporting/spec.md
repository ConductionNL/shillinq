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

## Requirements

### REQ-EMU-001: EsaClassifier overlay schema

SHALL declare `EsaClassifier` overlay enabling per-account ESA 2010 classification.

### REQ-EMU-002: ESA-2010 seed data

SHALL ship `lib/Settings/seeds/esa-2010-classifier.json` with ~25 ESA sector mappings per ESA 2010 standard.

### REQ-EMU-003: EMU-saldo quarterly calculation

SHALL declare `x-openregister-calculations` (or thin PHP guard if engine gap confirmed) computing EMU-saldo per sector quarterly with inclusion/exclusion rules per regulation.

#### Scenario: EMU saldo computed correctly

GIVEN Q4 2025 postings classified to ESA sectors  
WHEN EMU-saldo is calculated  
THEN result matches CBS-published benchmark.

### REQ-EMU-004: EMU-schuld annual aggregation

SHALL declare EMU-schuld aggregation for annual jaarrekening (debt by sector per ESA).

### REQ-EMU-005: Manifest navigation entry

SHALL add `featureFlags.gov-emu` navigation for EMU reporting views.

## Test Plan

- PHPUnit: EsaClassifier seed loads; EMU calc matches benchmark.
- Integration: quarterly materialization via scheduled workflow.

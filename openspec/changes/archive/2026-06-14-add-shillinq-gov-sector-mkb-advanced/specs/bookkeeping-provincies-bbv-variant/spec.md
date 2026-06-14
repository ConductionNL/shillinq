# Spec: Bookkeeping — Provincies BBV Variant

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-bbv-compliance  
**Kind:** config

## Summary

Add Dutch provincies (province) sector variant to BBV compliance. Declares programma-indeling per kerntaak (core responsibility) structure, opcenten MRB + provinciale-fonds boekingen, and province-specific financial administration rules.

## Entities

### Account (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bbvVariant | enum | No | Value: `gemeente`, `waterschap`, or `provincie` |

### ProvincialeFondsPosting (new)

Province-specific fund posting (opcenten MRB, provinciefonds, decentralisatie-uitkering).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| fondsType | enum | Yes | Type: `opcenten-mrb`, `provinciefonds`, or `decentralisatie-uitkering` |
| allocationDate | date | Yes | Date fund was allocated |
| amount | MonetaryAmount | Yes | Amount in EUR |
| relatedAccount | string | No | FK to Account.accountNumber |
| status | enum | Yes | One of `draft`, `allocated`, `posted`, `archived` |

### BBVProgramma (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| variantStructure | enum | No | `gemeente-functional`, `waterschap-kostentoedeling`, or `provincie-kerntaak` |

## ADDED Requirements

### Requirement: REQ-PRB-001 — Account bbvVariant flag supports provincie

SHALL provide `bbvVariant: 'provincie'` enum value on `Account` schema.

#### Scenario: Province accounts tagged correctly

GIVEN a new provincies administration  
WHEN accounts are created with `bbvVariant: 'provincie'`  
THEN accounts filter to provincie-scoped views only.

### Requirement: REQ-PRB-002 — ProvincialeFondsPosting register

SHALL declare the `ProvincialeFondsPosting` register with kerntaak-scoped fund tracking.

#### Scenario: Fund posting lifecycle

GIVEN a provinciefonds allocation in January 2026  
WHEN posted to general ledger  
THEN the posting is immutable once status reaches `posted`.

### Requirement: REQ-PRB-003 — Provincies programma seed data

SHALL ship `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json` with ~15 kerntaken (mobiliteit, economie, ruimte, cultuur, etc.) programma-indeling entries per 2026 provincial regulations.

#### Scenario: Kerntaken seed loads on install

GIVEN a provincie selects kerntaken feature flag  
WHEN repair step runs  
THEN 15 programma entries reflecting core responsibilities are seeded.

### Requirement: REQ-PRB-004 — Opcenten MRB calculation

SHALL declare declarative aggregation rules for opcenten MRB (surcharges on vehicle registration tax) per provincial variant.

#### Scenario: MRB computation respects provincia rules

GIVEN a provincie administration with MRB entries  
WHEN MRB total is calculated  
THEN computation follows provincial variant rules.

## Test Plan

- PHPUnit: `bbvVariant: 'provincie'` flag correct;  ProvincialeFondsPosting lifecycle.
- Playwright: manifest navigation per feature flag.
- Integration: seed data loads correctly; idempotent on re-run.

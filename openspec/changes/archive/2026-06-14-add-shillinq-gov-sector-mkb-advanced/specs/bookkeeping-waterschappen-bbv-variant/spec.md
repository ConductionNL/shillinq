# Spec: Bookkeeping — Waterschappen BBV Variant

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-bbv-compliance  
**Kind:** config  

## Summary

Add Dutch waterschappen (water board) sector variant to the existing BBV (Begroting en Verantwoording) compliance layer. Declares programma-indeling per kostentoedeling structure, EMU-saldo variant calculation rules, and waterschaps-specific tax definitions (watersysteemheffing, zuiveringsheffing, verontreinigingsheffing).

## Context

Waterschappen are independent public water authorities responsible for water management. Unlike gemeenten (municipalities) and provincies, waterschappen use a distinct programma (cost allocation) structure based on `kostentoedeling` (cost allocation classes) rather than functional categories.

Per design decision D1, this is **not** a fork of BBV-compliance but a variant overlay declared as schema metadata (flag field + seed data).

## Entities

### Account (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bbvVariant | enum | No | Account scope: `gemeente` (default), `waterschap`, or `provincie` |

### WaterschapHeffingPosting (new)

A posting (transaction line) recording waterschaps-specific tax/levy collections.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| heffingType | enum | Yes | Type: `watersysteemheffing`, `zuiveringsheffing`, or `verontreinigingsheffing` |
| collectionDate | date | Yes | Date the tax was collected |
| amount | MonetaryAmount | Yes | Amount collected in EUR |
| relatedAccount | string | No | FK to Account.accountNumber (the general ledger posting) |
| status | enum | Yes | One of `draft`, `collected`, `posted`, `archived` |

### BBVProgramma (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| variantStructure | enum | No | Program structure type: `gemeente-functional`, `waterschap-kostentoedeling`, or `provincie-kerntaak` |

## ADDED Requirements

### Requirement: REQ-WSB-001 — Account-level bbvVariant overlay

SHALL provide an optional `bbvVariant` enum field on `Account` schema supporting values `gemeente`, `waterschap`, `provincie`.

#### Scenario: Waterschap account flags correctly

GIVEN a new waterschappen administration  
WHEN an account is created with `bbvVariant: 'waterschap'`  
THEN the account is tagged as waterschap-scoped and does not appear in gemeente-only reports.

### Requirement: REQ-WSB-002 — WaterschapHeffingPosting register

SHALL declare the `WaterschapHeffingPosting` register with lifecycle state management (draft → collected → posted → archived).

#### Scenario: Heffing posting lifecycle

GIVEN a heffing collected in May 2026  
WHEN posted to the general ledger  
THEN the posting status transitions from `collected` to `posted` and is immutable.

### Requirement: REQ-WSB-003 — Waterschappen programma seed data

SHALL ship a seed file `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json` containing ~30 programma entries for BBVW (BBV-Waterschappen) structure per kostentoedeling categories.

#### Scenario: Seed data loads on fresh install

GIVEN a waterschap selects the waterschappen feature flag on fresh install  
WHEN the repair step runs  
THEN the 30 programma-indeling entries appear in the administration's chart of accounts.

### Requirement: REQ-WSB-004 — EMU-saldo variant calculation

SHALL declare EMU-saldo exclusion rules for waterschappen (excluding certain heffingen per EMU-bijlage waterschappen handleiding) as a declarative aggregation filter.

#### Scenario: EMU computation respects waterschap rules

GIVEN a waterschappen administration with mixed heffing postings  
WHEN EMU-saldo is calculated quarterly  
THEN the computation excludes waterschaps-specific heffingen per regulation.

### Requirement: REQ-WSB-005 — Manifest navigation entry

SHALL add a feature-flag-controlled navigation entry in `src/manifest.json` under `featureFlags.gov-waterschap` pointing to the waterschappen-specific reporting views.

#### Scenario: Navigation appears only when flag enabled

GIVEN a gemeente with feature flag disabled  
WHEN the UI renders  
THEN no waterschappen-specific navigation entries appear.

## Scenarios

### Happy path: Waterschappen administration setup and quarterly reporting

1. Administrator enables `gov-waterschap` feature flag.
2. Fresh install triggers repair step, seeding 30 programma entries.
3. Bookkeeper creates postings using waterschappen accounts.
4. At quarter-end, EMU-saldo is calculated with waterschappen exclusion rules applied.
5. IV3-quarterly report generated correctly filtering by sector.

## Non-Goals

- Support for intercompany eliminations or consolidation with gemeente-level parents (T5 deferred).
- Custom Vue components for waterschappen-specific views (manifest navigation only).
- Migration of existing gemeente or provincie administrations into waterschappen variant.

## Risks

- **Regulatory churn**: EMU-bijlage waterschappen handleiding updates yearly. Mitigation: seed data versioned in filename (`*-2026.json`); specs reference regulation, not year-specific values.
- **Variant overlap**: A waterschap participating in a GR needs both `bbvVariant: waterschap` and `GRDeelnemer` FK. Handled by independent flag fields; no exclusive constraint.

## Open Questions

1. **EMU quarterly frequency**: Quarterly via IV3 only, or also rolling intra-period view? Proposal: quarterly + annual only via `bookkeeping-emu-reporting`. Confirm with BBV reviewer.
2. **Heffing posting lifecycle**: Should draft postings be editable, or locked once created? Proposal: draft-only editable; confirm with waterschappen finance officer.

## Migration Plan

Spec-only — no implementation in this change. Per-spec opsx-apply cycle:

1. Schema patch to `lib/Settings/shillinq_register.json`: add `bbvVariant` flag on `Account`, declare `WaterschapHeffingPosting` register.
2. Seed file: `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json` (~30 rows, seeded on repair).
3. Manifest entry: featureFlags-controlled under `gov-waterschap`.
4. Optional EMU calculation guard: if `x-openregister-aggregations` cannot express multi-sector exclusion, thin PHP `EmuCalculator` per ADR-031.

## Test Plan

- PHPUnit: variant overlay round-trips; reject unknown variant value.
- PHPUnit: heffing posting lifecycle state machine.
- Playwright: manifest entry appears/disappears per feature flag.
- Integration: seed data loads idempotent; per-administration overrides preserved.

# Spec: Bookkeeping — GR (Gemeenschappelijke Regeling) Consolidation

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-financial-statements  
**Kind:** config

## Summary

Support gemeenschappelijke regelingen (joint arrangements) with per-deelnemer toerekening (allocation) and inter-GR elimination postings. Enables a single GR jaarrekening with automatic per-member doorbelasting (cost allocation) via verdeelsleutel.

## Entities

### GRDeelnemer (new)

A member (gemeente, provincie, waterschap) participating in the GR.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the deelnemer's shillinq Administration |
| sharePercentage | number | Yes | Member's percentage share (0–100) |
| quotum | number | No | Member's fixed quota if allocation is quota-based |
| status | enum | Yes | One of `active`, `inactive`, `departed` |

### GRVerdeelsleutel (new)

The allocation key defining how GR costs are divided among deelnemers.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Key name (e.g., "60% inwoners, 40% oppervlak") |
| allocationBasis | string | Yes | Basis for allocation (e.g., `inhabitants`, `area`, `fixed-percentage`) |
| description | string | No | Detailed allocation rules |
| activeFrom | date | Yes | Effective date |
| activeTo | date | No | End date (if applicable) |

### GLLine (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eliminationFlag | boolean | No | True if this posting is inter-GR and should be eliminated in consolidated view |

## ADDED Requirements

### Requirement: REQ-GRC-001 — GRDeelnemer register

SHALL declare a `GRDeelnemer` register for managing GR member participation, with percentage or quota-based share tracking.

#### Scenario: Member allocation tracked

GIVEN a GR with three deelnemers (A: 40%, B: 35%, C: 25%)  
WHEN costs are posted to the GR  
THEN the allocation key divides them per verdeelsleutel.

### Requirement: REQ-GRC-002 — GRVerdeelsleutel register

SHALL declare a `GRVerdeelsleutel` register defining cost allocation rules per cost cluster.

#### Scenario: Verdeelsleutel applied

GIVEN a verdeelsleutel "60% inhabitants, 40% area"  
WHEN monthly costs are posted  
THEN automatic doorbelasting postings are generated per deelnemer.

### Requirement: REQ-GRC-003 — Elimination flag on GLLine

SHALL add `eliminationFlag: boolean` field to `GLLine` to mark inter-GR transactions (e.g., GR paying a deelnemer or vice versa) for exclusion from consolidated views.

#### Scenario: Inter-GR transactions eliminated

GIVEN a GR transferring funds to deelnemer A  
WHEN consolidated trial balance is generated  
THEN the transaction is excluded via `WHERE eliminationFlag = false` filter.

### Requirement: REQ-GRC-004 — Consolidated trial balance aggregation

SHALL declare an `x-openregister-aggregations` view filtering out elimination-flagged postings to produce the consolidated GR trial balance.

#### Scenario: Consolidated view excludes eliminations

GIVEN multiple inter-GR transactions marked with `eliminationFlag: true`  
WHEN consolidated trial balance is queried  
THEN these postings are excluded; balance matches deelnemer sum minus eliminations.

### Requirement: REQ-GRC-005 — Per-deelnemer doorbelasting report

SHALL provide a report showing each deelnemer's share of GR costs per verdeelsleutel.

#### Scenario: Doorbelasting report generated

GIVEN a GR with active deelnemers and a verdeelsleutel  
WHEN monthly doorbelasting is calculated  
THEN each deelnemer receives a list of their allocated costs.

### Requirement: REQ-GRC-006 — Manifest navigation entry

The system SHALL add a feature-flag-controlled navigation entry under `featureFlags.gov-gr` for GR-specific consolidation views.

#### Scenario: GR navigation is feature-flag gated

- **GIVEN** the `gov-gr` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the GR consolidation entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: GRDeelnemer and GRVerdeelsleutel CRUD; elimination flag filtering.
- PHPUnit: consolidated trial balance matches worked example.
- Integration: doorbelasting calculation per verdeelsleutel.

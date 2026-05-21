# Specification: bookkeeping-bbv-compliance

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1)

## Overview

This specification defines the BBV (Besluit Begroting en Verantwoording) compliance framework for Dutch municipal administrations, enforcing posting rules per taakveld (activity category), programma (program), paragraaf (paragraph), and authorization levels.

## Scope

- `BbvAccountMapping` register linking RGS accounts to BBV taakvelden
- `BbvTaakveld` seed data (50+ activity categories)
- Default RGS↔BBV mapping as operator-overrideable seed
- Per-transaction taakveld validation on GL posting
- Aggregations by taakveld for reporting
- Visibility: gemeente/provincie/waterschap administrations only

## ADDED Requirements

### REQ-BBV-001: BBV compliance for municipalities

Municipal administrations MUST enforce BBV-conformant posting rules via the `BbvAccountMapping` register, mapping every GL posting to a taakveld per Besluit BBV bijlage IV.

### REQ-BBV-002: BbvAccountMapping register

The `BbvAccountMapping` schema SHALL declare:
- `administrationId` (FK to Administration)
- `accountNumber` (FK to Account.accountNumber, T1)
- `taakveld` (enum from bbv-taakvelden seed, e.g. "0.1 Bestuur")
- `programmaCode` (operator-defined, e.g. "1000")
- `paragraafCode` (optional, e.g. "1001")
- `bcfCompensable` (boolean, false by default; used by BCF spec)
- `iv3Bucket` (enum, IV3-bestand specified)
- `autorisatieniveau` (enum: I, II, III per BBV)

With unique constraint on `(administrationId, accountNumber)`.

### REQ-BBV-003: Posting validation requires BBV mapping

When a GL posting is created on a municipal administration, a precondition SHALL check that the Account has a corresponding `BbvAccountMapping` entry. If missing, the posting MUST be rejected with a validation error.

#### Scenario: Reject unmapped account for municipal posting

GIVEN a gemeente administration
WHEN a GL posting is attempted on Account "4250 Subsidies cultuur" without a BbvAccountMapping entry
THEN the posting is rejected with a validation error: "Missing BBV taakveld mapping for account".

### REQ-BBV-004: BBV taakveld catalogue seed

The system SHALL ship `bbv-taakvelden-2024.json` seed containing ~50 canonical taakvelden per Commissie BBV, with:
- `code` (e.g. "0.1")
- `description` (e.g. "Bestuur")
- `programmaFocus` (hint)

### REQ-BBV-005: Default RGS↔BBV mapping seed

The system SHALL ship `rgs-to-bbv-mapping.json` containing sensible default mappings from RGS 3.5 accounts to taakvelden, seeded per gemeente type (small/medium/large) with `_meta.source = "seeded"`.

### REQ-BBV-006: Per-administration mapping override

Each gemeente administration SHOULD be seeded with the default `rgs-to-bbv-mapping.json` entries on install. Operators MAY override per-account mappings without affecting the seed (per ADR-022 pattern).

### REQ-BBV-007: BBV aggregation queries

The system SHALL expose `x-openregister-aggregations` queries grouping GL postings by taakveld for period-end reporting, enabling roll-up views like "Programma 1000 total by taakveld".

### REQ-BBV-008: IV3 Bucket assignment

Each `BbvAccountMapping.iv3Bucket` value SHALL align with the CBS IV3-bestand specification's bucket names, enabling deterministic IV3 export aggregation per REQ-IV3-003.

### REQ-BBV-009: Manifest entry

The `src/manifest.json` SHALL declare one navigation entry:
- `Overheid > BBV-mapping` (type: detail, lists BbvAccountMapping records per administration)

Visibility predicate: gemeente/provincie/waterschap administrations only.

## Non-Goals

- No automation of taakveld selection based on account semantics
- No industry-specific BBV variants (T3+ roadmap)

## Reuse

- Aggregation via OR `x-openregister-aggregations` (ADR-031)
- Per-administration data via OR's administration FK pattern (ADR-022)
- Seed import via repair step `ConfigurationService::importFromApp()` (ADR-022)

## Dependencies

- T1: Account (accountNumber FK), GLTransaction (for postings validation)
- OR: aggregations, mappings abstractions

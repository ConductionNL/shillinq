# Specification: bookkeeping-archiefwet-retention

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1)

## Overview

Records retention enforcement per Archiefwet 1995 and Selectielijst Gemeenten 2020. Declares retention rules for every record type in Shillinq (T1, T2, T3), consumed by OR's lifecycle retention abstraction per ADR-022.

## Scope

- `RetentionRule` register (OR's abstraction, seeded by shillinq)
- Selectielijst Gemeenten 2020 seed data (~30 rule entries)
- Per-schema retention declarations via `x-openregister-lifecycle.retention`
- Operator-overrideable retention per administration
- Enforcement (purge/archive/anonymise) by OR engine

## ADDED Requirements

### Requirement: REQ-ARC-001 — OR retention abstraction

This spec SHALL consume OR's `x-openregister-lifecycle.retention` abstraction per ADR-022. Retention enforcement MUST remain entirely OR's responsibility; Shillinq only declares the retention rule references and seeds the rule definitions.

#### Scenario: Spec conformance for REQ-ARC-001

- **GIVEN** the REQ-ARC-001 requirement above ("OR retention abstraction")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-002 — RetentionRule register

The `RetentionRule` schema (defined in OR, seeded by Shillinq) SHALL include:
- `selectielijstCode` (e.g. "5.1.2", Selectielijst 2020 reference)
- `description` (e.g. "Financial records - 7 year retention")
- `retentionYears` (integer, 7 for financials; null for indefinite)
- `retentionTrigger` (optional, e.g. "10 years after grant settlement")
- `disposition` (enum: destroy, archive, anonymise, keep_indefinite)
- `legalBasis` (citation: "Archiefwet 1995 art. 5, Selectielijst 2020 §5.1.2")

#### Scenario: Spec conformance for REQ-ARC-002

- **GIVEN** the REQ-ARC-002 requirement above ("RetentionRule register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-003 — Retention rule mapping

Every Shillinq schema (T1, T2, and all 9 other T3 schemas) MUST be mapped to a Selectielijst retention code in the spec's retention table. Mapping rules:

| Register | Selectielijst Code | Retention | Disposition |
|---|---|---|---|
| GLTransaction | 5.1.2 | 7 years | destroy |
| Invoice (T2) | 5.1.2 | 7 years | destroy |
| VatReturn | 5.1.2 | 7 years | archive |
| BcfClaim | 5.1.2 | 7 years | archive |
| Subsidie | 5.3.1 | 10 years after settlement | archive |
| Project | 5.2.1 | 7 years after closure | archive |
| Account | 5.1.1 | indefinite | keep |
| ... | ... | ... | ... |

#### Scenario: Spec conformance for REQ-ARC-003

- **GIVEN** the REQ-ARC-003 requirement above ("Retention rule mapping")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-004 — Selectielijst seed

The system SHALL ship `selectielijst-gemeenten-2020.json` with 30+ retention rules from Selectielijst Gemeenten 2020, each with selectielijstCode, description, retentionYears, disposition, legalBasis.

#### Scenario: Spec conformance for REQ-ARC-004

- **GIVEN** the REQ-ARC-004 requirement above ("Selectielijst seed")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-005 — Per-schema retention declaration

Every Shillinq schema MUST declare `x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }` (or other applicable rule code).

#### Scenario: Spec conformance for REQ-ARC-005

- **GIVEN** the REQ-ARC-005 requirement above ("Per-schema retention declaration")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-006 — Audit trail preservation

When OR's retention engine purges records, audit-trail-immutable hashes MUST be preserved per OR contract. Shillinq documents this expectation in the spec.

#### Scenario: Spec conformance for REQ-ARC-006

- **GIVEN** the REQ-ARC-006 requirement above ("Audit trail preservation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-007 — Days-until-retention derived field

Every retention-bound schema SHOULD carry a `daysUntilRetention` derived field computed as `(retentionUntil - today)`, and the field MUST be visible to operators for planning.

#### Scenario: Spec conformance for REQ-ARC-007

- **GIVEN** the REQ-ARC-007 requirement above ("Days-until-retention derived field")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-008 — Operator retention override

Each `RetentionRule` record per administration MAY be overridden by the operator (e.g. local archiefverordening exceptions); when overridden, the record MUST carry audit citations of the local regulation.

#### Scenario: Spec conformance for REQ-ARC-008

- **GIVEN** the REQ-ARC-008 requirement above ("Operator retention override")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-009 — Manifest entry

The `src/manifest.json` SHALL declare:
- `Administratie > Bewaartermijnen` (type: detail, shows retention rules + daysUntilRetention for each record type)

Visibility: all administrations.

#### Scenario: Spec conformance for REQ-ARC-009

- **GIVEN** the REQ-ARC-009 requirement above ("Manifest entry")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-ARC-010 — Legal compliance

This spec SHALL cite:
- Archiefwet 1995, artikel 5 (retention obligation)
- Selectielijst Gemeenten 2020 (specific retention periods)
- AVG (GDPR) where anonymisation is required per art. 17

#### Scenario: Spec conformance for REQ-ARC-010

- **GIVEN** the REQ-ARC-010 requirement above ("Legal compliance")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No app-local retention sweep job (OR's job)
- No anonymisation algorithm (OR's responsibility per GDPR compliance)

## Reuse

- Retention enforcement via OR `x-openregister-lifecycle.retention` (ADR-022)
- Seed import via repair step (ADR-022)
- Audit trail preservation via OR audit-trail-immutable (ADR-022)

## Dependencies

- All other specs (references all T1, T2, T3 schemas)
- OR: lifecycle.retention engine, audit-trail-immutable

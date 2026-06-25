---
status: done
---

# Specification: bookkeeping-archiefwet-retention

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1)

## Purpose

Records retention enforcement per Archiefwet 1995 and Selectielijst Gemeenten 2020. Declares retention rules for every record type in Shillinq (T1, T2, T3), consumed by OR's lifecycle retention abstraction per ADR-022.

## Scope

- `RetentionRule` register (OR's abstraction, seeded by shillinq)
- Selectielijst Gemeenten 2020 seed data (~30 rule entries)
- Per-schema retention declarations via `x-openregister-lifecycle.retention`
- Operator-overrideable retention per administration
- Enforcement (purge/archive/anonymise) by OR engine

## Requirements

@e2e exclude pure backend/compliance: data retention logic — not browser-testable


### REQ-ARC-001: OR retention abstraction

The system SHALL satisfy this requirement: OR retention abstraction.

This spec consumes OR's `x-openregister-lifecycle.retention` abstraction per ADR-022. Retention enforcement is entirely OR's responsibility; Shillinq only declares the retention rule references and seeds the rule definitions.

#### Scenario: Retention enforcement delegated to OR

- **GIVEN** a Shillinq schema declares a retention rule reference via `x-openregister-lifecycle.retention`
- **WHEN** a record bound to that schema reaches its retention deadline
- **THEN** OR's lifecycle retention engine performs the enforcement, and Shillinq itself runs no retention logic beyond declaring the rule reference and seeding the rule definitions

### REQ-ARC-002: RetentionRule register

The `RetentionRule` schema (defined in OR, seeded by Shillinq) SHALL include:
- `selectielijstCode` (e.g. "5.1.2", Selectielijst 2020 reference)
- `description` (e.g. "Financial records - 7 year retention")
- `retentionYears` (integer, 7 for financials; null for indefinite)
- `retentionTrigger` (optional, e.g. "10 years after grant settlement")
- `disposition` (enum: destroy, archive, anonymise, keep_indefinite)
- `legalBasis` (citation: "Archiefwet 1995 art. 5, Selectielijst 2020 §5.1.2")

#### Scenario: RetentionRule record carries required fields

- **GIVEN** the `RetentionRule` schema is seeded by Shillinq into OR
- **WHEN** a retention rule record is created
- **THEN** it includes `selectielijstCode`, `description`, `retentionYears`, `retentionTrigger`, `disposition`, and `legalBasis`

### REQ-ARC-003: Retention rule mapping

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

#### Scenario: Every schema mapped to a Selectielijst code

- **GIVEN** the full set of Shillinq schemas (T1, T2, and all 9 T3 schemas)
- **WHEN** the retention mapping table in this spec is reviewed
- **THEN** each schema has a Selectielijst retention code, retention period, and disposition (e.g. GLTransaction → 5.1.2 / 7 years / destroy, Subsidie → 5.3.1 / 10 years after settlement / archive)

### REQ-ARC-004: Selectielijst seed

The system SHALL ship `selectielijst-gemeenten-2020.json` with 30+ retention rules from Selectielijst Gemeenten 2020, each with selectielijstCode, description, retentionYears, disposition, legalBasis.

#### Scenario: Selectielijst seed shipped and imported

- **GIVEN** the app ships `selectielijst-gemeenten-2020.json`
- **WHEN** the seed is imported into the `RetentionRule` register
- **THEN** 30 or more retention rules from Selectielijst Gemeenten 2020 are present, each carrying selectielijstCode, description, retentionYears, disposition, and legalBasis

### REQ-ARC-005: Per-schema retention declaration

Every Shillinq schema MUST declare `x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }` (or other applicable rule code).

#### Scenario: Schema declares retention rule reference

- **GIVEN** a Shillinq schema definition
- **WHEN** the schema is inspected
- **THEN** it declares `x-openregister-lifecycle.retention` with a `rule` referencing the applicable Selectielijst code (e.g. `selectielijst:5.1.2`)

### REQ-ARC-006: Audit trail preservation

When OR's retention engine purges records, audit-trail-immutable hashes MUST be preserved per OR contract. Shillinq documents this expectation in the spec.

#### Scenario: Audit hashes survive a purge

- **GIVEN** records that have been purged by OR's retention engine
- **WHEN** the purge completes
- **THEN** the audit-trail-immutable hashes for those records remain preserved per the OR contract

### REQ-ARC-007: Days-until-retention derived field

The system SHALL satisfy this requirement: Days-until-retention derived field.

Every retention-bound schema SHOULD carry a `daysUntilRetention` derived field computed as `(retentionUntil - today)`, visible to operators for planning.

#### Scenario: Operator sees days until retention

- **GIVEN** a retention-bound record with a `retentionUntil` date
- **WHEN** an operator views the record
- **THEN** a `daysUntilRetention` derived field is shown, computed as `(retentionUntil - today)`

### REQ-ARC-008: Operator retention override

The system SHALL satisfy this requirement: Operator retention override.

Each `RetentionRule` record per administration MAY be overridden by the operator (e.g. local archiefverordening exceptions). Overrides carry audit citations of the local regulation.

#### Scenario: Operator overrides a retention rule per administration

- **GIVEN** an administration with a local archiefverordening exception
- **WHEN** the operator overrides the `RetentionRule` record for that administration
- **THEN** the override is applied for that administration and carries an audit citation of the local regulation

### REQ-ARC-009: Manifest entry

The `src/manifest.json` SHALL declare:
- `Administratie > Bewaartermijnen` (type: detail, shows retention rules + daysUntilRetention for each record type)

Visibility: all administrations.

#### Scenario: Bewaartermijnen manifest entry present

- **GIVEN** `src/manifest.json` for the app
- **WHEN** the manifest is loaded for any administration
- **THEN** it declares an `Administratie > Bewaartermijnen` detail entry showing retention rules and `daysUntilRetention` per record type

### REQ-ARC-010: Legal compliance

This spec SHALL cite:
- Archiefwet 1995, artikel 5 (retention obligation)
- Selectielijst Gemeenten 2020 (specific retention periods)
- AVG (GDPR) where anonymisation is required per art. 17

#### Scenario: Legal citations present in the spec

- **GIVEN** this retention specification
- **WHEN** its legal basis is reviewed
- **THEN** it cites Archiefwet 1995 artikel 5, Selectielijst Gemeenten 2020, and AVG (GDPR) art. 17 where anonymisation is required

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

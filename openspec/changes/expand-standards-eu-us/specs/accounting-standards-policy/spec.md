# accounting-standards-policy

Expands the standards catalogue for the EU + US market and adds the additive
digital-compliance model.

## MODIFIED Requirements

### Requirement: REQ-ASP-001 — StandardsPolicy schema

The system SHALL provide a `StandardsPolicy` schema (OpenRegister register
fragment per ADR-037) holding an ordered list of frameworks, each with a `key`,
an `enabled` boolean and an integer `precedence`. The `key` enum SHALL cover the
EU + US bases of accounting: `ifrs`, `ifrs-eu`, `dutch-gaap`, `de-hgb`, `fr-pcg`,
`it-oic`, `es-pgc`, `dutch-tax`, `us-gaap`, `us-tax-basis`, `us-cash-basis`,
`us-modified-cash`, `us-frf-smes`, `ipsas`, `bbv`, `us-gasb`, `us-fasab`, `esrs`,
`ifrs-sustainability`.

#### Scenario: extended enum is accepted

- **WHEN** a policy enables `de-hgb` and `us-gasb`
- **THEN** the schema accepts both keys and the resolver ranks them like any other

#### Scenario: digital-compliance keys are NOT bases of accounting

- **WHEN** an author tries to add `saf-t` or `vida` as a StandardsPolicy framework key
- **THEN** it is rejected — those are ComplianceObligation standards, not ranked bases

## ADDED Requirements

### Requirement: REQ-ASP-004 — ComplianceObligation schema (additive)

The system SHALL provide a `ComplianceObligation` schema for digital-compliance /
tax-data obligations that are **additive** (every applicable obligation must be
met), tracked per `{ jurisdiction, type, standard, status, effectiveDate }`.
Obligations SHALL NOT be ranked and SHALL NOT be resolved by the precedence
resolver.

#### Scenario: obligation is recorded per jurisdiction

- **WHEN** an administration in Poland is subject to KSeF e-invoicing from 1 Apr 2026
- **THEN** a `ComplianceObligation` exists with `jurisdiction: "PL"`, `type: "e-invoicing"`, `standard: "country-mandate"`, `status: "upcoming"`, `effectiveDate: "2026-04-01"`

#### Scenario: obligations are additive, not ranked

- **WHEN** an administration has both a SAF-T and a VAT obligation
- **THEN** both apply simultaneously — there is no "winner" and no precedence between them

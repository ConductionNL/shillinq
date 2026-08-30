# Spec: Bookkeeping — Markt en Overheid (Market vs Government Separation)

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T4-specialized (NL gov sector)  
**Depends on:** bookkeeping-cost-centers-dimensions  
**Kind:** config

## Summary

Implement Wet Markt en Overheid compliance: separate accounting for market activities (ondernemingsactiviteiten), integrale-kostprijs calculation, and transparantieadministratie proof that market activities are self-funding without cross-subsidy from public funds.

## Entities

### CostCenter (extended)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ondernemingsActiviteit | boolean | No | Is this cost center a market activity? |

## ADDED Requirements

### Requirement: REQ-MGS-001 — ondernemingsActiviteit flag on CostCenter

The system SHALL add an `ondernemingsActiviteit: boolean` field to the `CostCenter` schema.

#### Scenario: A cost center is flagged as a market activity

- **GIVEN** a CostCenter
- **WHEN** `ondernemingsActiviteit` is set to `true`
- **THEN** the cost center MUST be included in the Wet Markt en Overheid full-cost calculations.

### Requirement: REQ-MGS-002 — Integrale-kostprijs calculation block

SHALL declare `x-openregister-calculations` block computing integrale kostprijs (full cost including overhead allocation) per ondernemingsactiviteit per configurable verdeelsleutel.

#### Scenario: Full-cost calculation enforced

GIVEN an ondernemingsactiviteit cost center with overhead allocation rule  
WHEN integrale-kostprijs is calculated  
THEN result includes direct costs + proportional overhead per rule.

### Requirement: REQ-MGS-003 — Transparantieadministratie aggregation

SHALL provide aggregation view proving the ondernemingsactiviteit is self-funding (revenues ≥ full costs).

#### Scenario: Self-funding proof generated

GIVEN market activity with full costs €500k and revenues €550k  
WHEN transparantieadministratie is queried  
THEN report confirms self-funding status.

### Requirement: REQ-MGS-004 — Verdeelsleutel configuration

The system SHALL allow per-activity overhead allocation rules (percentage, headcount, area, etc.).

#### Scenario: Overhead is allocated per a configured verdeelsleutel

- **GIVEN** an ondernemingsactiviteit cost center
- **WHEN** a verdeelsleutel of type `headcount` is configured
- **THEN** the integrale-kostprijs MUST allocate overhead per that rule.

### Requirement: REQ-MGS-005 — Manifest navigation entry

The system SHALL add a `featureFlags.gov-markt-overheid` navigation entry for market activity separation views.

#### Scenario: Markt en Overheid navigation is feature-flag gated

- **GIVEN** the `gov-markt-overheid` feature flag is off
- **WHEN** the UI renders the menu
- **THEN** the market separation entry MUST NOT appear; it appears only when the flag is on.

## Test Plan

- PHPUnit: integrale-kostprijs calculation per verdeelsleutel.
- Integration: transparantieadministratie matches worked example.

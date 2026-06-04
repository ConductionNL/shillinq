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

## Requirements

### REQ-MGS-001: ondernemingsActiviteit flag on CostCenter

SHALL add `ondernemingsActiviteit: boolean` field to `CostCenter` schema.

### REQ-MGS-002: Integrale-kostprijs calculation block

SHALL declare `x-openregister-calculations` block computing integrale kostprijs (full cost including overhead allocation) per ondernemingsactiviteit per configurable verdeelsleutel.

#### Scenario: Full-cost calculation enforced

GIVEN an ondernemingsactiviteit cost center with overhead allocation rule  
WHEN integrale-kostprijs is calculated  
THEN result includes direct costs + proportional overhead per rule.

### REQ-MGS-003: Transparantieadministratie aggregation

SHALL provide aggregation view proving the ondernemingsactiviteit is self-funding (revenues ≥ full costs).

#### Scenario: Self-funding proof generated

GIVEN market activity with full costs €500k and revenues €550k  
WHEN transparantieadministratie is queried  
THEN report confirms self-funding status.

### REQ-MGS-004: Verdeelsleutel configuration

SHALL allow per-activity overhead allocation rules (percentage, headcount, area, etc.).

### REQ-MGS-005: Manifest navigation entry

SHALL add `featureFlags.gov-markt-overheid` navigation for market activity separation views.

## Test Plan

- PHPUnit: integrale-kostprijs calculation per verdeelsleutel.
- Integration: transparantieadministratie matches worked example.

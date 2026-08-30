# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 12 — docs + quality)

## ADDED Requirements

### Requirement: The BBV capability SHALL be documented and deduplicated

The system SHALL document the BBV variant via PHPDoc (with `@spec`
tags), Vue JSDoc, and a README snippet, and SHALL confirm no duplicate
GL-account linkage, compliance dashboard, budget-mapping UI, or
aggregation implementation exists elsewhere in Shillinq.

#### Scenario: No duplicate BBV implementation exists

- **GIVEN** the Shillinq codebase after the BBV chain lands
- **WHEN** scanned for a second GL-account-linkage, compliance
  dashboard, budget-mapping UI, or aggregation implementation
- **THEN** no such duplicate SHALL be found
- **AND** the BBV code SHALL carry PHPDoc, JSDoc, and a README snippet.

### Requirement: The BBV capability SHALL pass the strict quality and Hydra gates

The system SHALL pass `composer check:strict` and `npm run lint`, carry
SPDX headers in the main docblock of every new file, and pass all Hydra
mechanical gates (route-auth, semantic-auth, nc-input-labels,
modal-isolation, and the rest) with zero findings.

#### Scenario: Gates are green

- **GIVEN** the BBV chain is fully implemented
- **WHEN** `composer check:strict`, `npm run lint`, and the Hydra gate
  suite run
- **THEN** all SHALL pass with zero findings
- **AND** every new file SHALL carry an SPDX header in its main
  docblock.

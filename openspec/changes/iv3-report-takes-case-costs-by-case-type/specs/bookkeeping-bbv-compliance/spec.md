# bookkeeping-bbv-compliance Specification (delta)

## Purpose

The taakveld catalogue holds the 2023 refinement codes and how they roll up, so no other app needs its own list (ADR-107 decision 1, decision 261).

## ADDED Requirements

### Requirement: The taakveld catalogue holds the 2023 refinement codes and their parent (REQ-BBV-013)

The `BbvTaakveld` catalogue for municipalities SHALL hold the 18 official 2023 Wmo/Jeugd refinement codes (`6.71a` to `6.71d`, `6.72a` to `6.72d`, `6.73a` to `6.73c`, `6.74a` to `6.74c`, `6.81a`, `6.81b`, `6.82a`, `6.82b`), each with `aggregatesUnder` set to its pre-2023 parent (`6.71`, `6.72`, `6.81` or `6.82`), and SHALL mark those 4 parents `deprecated`. Every code SHALL carry its statutory Dutch name and an authored English label. A code that rolls up SHALL resolve to exactly one parent in the same catalogue.

#### Scenario: A refinement code names its parent

- GIVEN the municipal catalogue
- WHEN code `6.73b` is read
- THEN its `aggregatesUnder` is `6.72` and it is not deprecated
- @e2e exclude seed data, asserted by PHPUnit

#### Scenario: A deprecated parent stays usable

- GIVEN the municipal catalogue
- WHEN code `6.72` is read
- THEN it exists, is marked deprecated and has no `aggregatesUnder`
- @e2e exclude seed data, asserted by PHPUnit

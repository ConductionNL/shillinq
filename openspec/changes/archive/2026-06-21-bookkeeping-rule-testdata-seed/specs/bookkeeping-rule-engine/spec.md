# bookkeeping-rule-engine

Reproducible compliant test data and an expanded set of enforced checks.

## ADDED Requirements

### Requirement: REQ-RE-007 — Reproducible compliant test data

The system SHALL provide an idempotent test-data seeder (`RuleTestDataSeeder` +
`occ shillinq:rules:seed-testdata`) that backfills every GL transaction with a
`sourceReference` and at least two balanced GLLines, so that a freshly-seeded
local environment reports 100% compliance in the audit. The seeder SHALL be a
test/dev utility only and SHALL be safe to re-run (already-compliant transactions
are left untouched).

#### Scenario: seeder is idempotent

- **WHEN** `occ shillinq:rules:seed-testdata` is run on data that is already compliant
- **THEN** it adds nothing and reports the transactions as already compliant

#### Scenario: fresh data becomes compliant

- **WHEN** the seeder runs on transactions lacking a source reference or balanced lines
- **THEN** it backfills them and a subsequent audit reports no violations

### Requirement: REQ-RE-008 — Expanded enforced checks

The engine SHALL additionally enforce, for invoices, a valid ISO 4217 currency
code (EN 16931 BR-CL-03) and a maximum of two decimals on the net, VAT and gross
totals (EN 16931 BR-DEC-12/13/14). These checks SHALL reference real catalogue
rule ids and SHALL be satisfied by compliant data.

#### Scenario: bad currency or sub-cent total is flagged

- **WHEN** an invoice has a non-ISO currency or a total with more than two decimals
- **THEN** the engine returns the corresponding `en16931-br-cl-03` / `en16931-br-dec-*` violations, while a clean EUR invoice returns none

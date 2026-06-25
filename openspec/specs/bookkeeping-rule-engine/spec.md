# bookkeeping-rule-engine Specification

## Purpose
TBD - created by archiving change bookkeeping-rule-engine. Update Purpose after archive.
## Requirements
### Requirement: REQ-RE-001 — Rule engine evaluates objects against applicable rules

The system SHALL provide a `RuleEngine` that, given an object type and an object,
evaluates the machine-checkable rules registered for that type and returns a list
of `Violation`s — each carrying the violated rule's `id`, `severity` and `source`
taken from the `RuleCatalogue` — empty when the object is compliant. Only rules
with a registered executable check SHALL be evaluated.

#### Scenario: compliant vs non-compliant invoice

- **WHEN** `RuleEngine::evaluate("ARInvoice", invoice)` runs on an invoice missing its number and whose total-with-VAT ≠ net + VAT
- **THEN** it returns violations including `en16931-br-02` and `en16931-br-co-15`, whereas a complete, arithmetically-consistent invoice returns no violations

### Requirement: REQ-RE-002 — Applicability scoped by jurisdiction

The engine SHALL only evaluate a rule when it applies to the context jurisdiction:
the rule's own country, plus `EU`-wide rules for EU member states and `global`
rules everywhere. Rules SHALL be skipped for jurisdictions they do not bind.

#### Scenario: EU rule does not fire for a US administration

- **WHEN** the same non-compliant invoice is evaluated with `jurisdiction: "US"`
- **THEN** EU-scoped EN 16931 rules (e.g. `en16931-br-02`) are not returned, while they are returned for `jurisdiction: "NL"`

### Requirement: REQ-RE-003 — Lifecycle enforcement on issue/post

The system SHALL enforce the rules at lifecycle transitions through a
`RuleComplianceGuard` referenced from the schema `x-openregister-lifecycle`
`requires:` clause. `ARInvoice.issue` SHALL be blocked when a `mandatory` invoice
rule is violated; `mandatory` violations block while `conditional`/`recommended`
violations are logged. Balance enforcement SHALL be preserved (delegated to the
existing `BalanceGuard`).

#### Scenario: issuing a non-compliant invoice is blocked

- **WHEN** an `ARInvoice` whose total-with-VAT ≠ net + VAT is transitioned `draft → issued`
- **THEN** `RuleComplianceGuard::validateInvoice` returns false and the transition is denied

### Requirement: REQ-RE-004 — Fail-closed

The guard SHALL deny the transition when the object cannot be loaded or any error
occurs during evaluation, never allowing a transition it could not verify.

#### Scenario: evaluation error denies the transition

- **WHEN** the object cannot be loaded or an exception is thrown during evaluation
- **THEN** the guard returns false (the transition is denied) and the error is logged

### Requirement: REQ-RE-005 — Compliance audit

The system SHALL provide a read-only audit (`RuleAuditService` + `occ
shillinq:rules:audit`) that runs the `RuleEngine` over every object of each
engine-supported type in the register and reports the compliance posture: rule
coverage (how many machine-checkable corpus rules are enforceable today), objects
checked and compliant, and violations grouped by severity and by rule. The audit
SHALL NOT modify data.

#### Scenario: audit reports coverage and compliance

- **WHEN** `occ shillinq:rules:audit` is run
- **THEN** it prints the corpus size, the enforceable-rule count and coverage %, and per-type objects-checked / compliant / with-violations totals, without changing any object

### Requirement: REQ-RE-006 — GL transaction enforcement on post

The system SHALL block `GLTransaction.post` when a mandatory ledger rule is
violated, via `RuleComplianceGuard::validateTransaction` referenced from
`GLTransaction.post.requires`. The double-entry balance invariant SHALL be
preserved (delegated to `BalanceGuard`), and GLLine rows SHALL be matched to their
parent by `transactionId` equal to the transaction's id OR its `transactionNumber`.
Overriding the existing `requires` SHALL preserve the transition's allocation-rule
`actions`.

#### Scenario: incomplete transaction is blocked, complete one allowed

- **WHEN** a GL transaction with fewer than two valid lines is posted
- **THEN** `validateTransaction` returns false and the post is denied, whereas a balanced transaction with complete lines is allowed

#### Scenario: balance and allocation actions preserved

- **WHEN** the guard fragment overrides `GLTransaction.post.requires`
- **THEN** the post still enforces double-entry balance (via BalanceGuard) and the existing allocation-rule `actions` remain on the transition

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


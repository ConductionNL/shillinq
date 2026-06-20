# bookkeeping-rule-engine

The executable layer that turns the machine-checkable rule corpus into enforced
behaviour at bookkeeping lifecycle points.

## ADDED Requirements

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

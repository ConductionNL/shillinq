# bookkeeping-rule-engine

GL-post enforcement and the compliance audit, extending the rule engine.

## ADDED Requirements

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

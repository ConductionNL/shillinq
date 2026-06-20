# accounting-standards-policy

A configurable, ordered policy declaring which accounting/reporting frameworks an
administration follows and in which order of precedence, plus a single resolver
business logic consults to decide which framework wins when frameworks conflict.

## ADDED Requirements

### Requirement: REQ-ASP-001 — StandardsPolicy schema

The system SHALL provide a `StandardsPolicy` schema (declared as an OpenRegister
register fragment per ADR-037, never editing `shillinq_register.json`) holding an
ordered list of frameworks, each with a `key` (from the fixed enum `ifrs`,
`dutch-gaap`, `dutch-tax`, `us-gaap`, `ipsas`, `bbv`, `esrs`,
`ifrs-sustainability`), an `enabled` boolean and an integer `precedence`. The
policy MAY carry an `administrationId` scope plus informational `name`/`notes`.

#### Scenario: schema is registered

- **WHEN** the register is initialised
- **THEN** the `StandardsPolicy` schema exists with `frameworks[]` items exposing `key`, `enabled` and `precedence`

#### Scenario: framework key is constrained

- **WHEN** a `frameworks[].key` outside the fixed enum is supplied
- **THEN** it is rejected by the schema's enum constraint

### Requirement: REQ-ASP-002 — Administrator can declare and order frameworks

An administrator SHALL be able to, from a **Settings → Accounting standards**
section, enable the frameworks that apply to the administration and order them by
precedence, persisted as a single `StandardsPolicy` object.

#### Scenario: enable and rank

- **WHEN** the administrator enables IFRS and Dutch GAAP and ranks IFRS above Dutch GAAP
- **THEN** a `StandardsPolicy` object is saved with both frameworks `enabled: true` and IFRS at a lower (higher-priority) `precedence` than Dutch GAAP

#### Scenario: reorder updates precedence

- **WHEN** the administrator drags Dutch GAAP above IFRS
- **THEN** the persisted `precedence` values reflect the new order

### Requirement: REQ-ASP-003 — Policy resolver

The system SHALL expose `StandardsPolicyService.resolve(topic)` returning the
**highest-precedence enabled framework key** for a conflict topic, or `null` when
no framework is enabled. The ranking logic SHALL be pure and unit-tested via
`resolveFromPolicy(frameworks, topic)`. The resolver SHALL NOT yet be applied to
real posting/valuation paths (the `topic` argument is reserved for future
per-topic applicability).

#### Scenario: highest enabled wins

- **WHEN** the policy is `[IFRS enabled precedence 1, Dutch GAAP enabled precedence 2]`
- **THEN** `resolve("leases")` returns `"ifrs"`

#### Scenario: reordering changes the winner

- **WHEN** the policy is `[Dutch GAAP enabled precedence 1, IFRS enabled precedence 2]`
- **THEN** `resolve("leases")` returns `"dutch-gaap"`

#### Scenario: disabled frameworks are ignored

- **WHEN** the highest-precedence framework is `enabled: false`
- **THEN** `resolve()` returns the next enabled framework by precedence

#### Scenario: empty policy

- **WHEN** no framework is enabled
- **THEN** `resolve()` returns `null`

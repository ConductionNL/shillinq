# Spec: payment-run-four-eyes (delta)

@e2e exclude pure backend control: server-side lifecycle guard on a register transition, proven by unit tests exercising `check()` directly — not browser-testable

## ADDED Requirements

### Requirement: REQ-PR4E-001 — The PaymentRun approve transition SHALL enforce approver ≠ preparer server-side

The `PaymentRun.approve` lifecycle transition (`draft → approved`) — the hard control point before
an outgoing SEPA batch can be exported and money leaves the administration — SHALL be gated by a
server-side segregation-of-duties guard (`FourEyesPaymentRunGuard`, wired via the schema's
`transitions.approve.requires` DI tag). The guard SHALL DENY the transition when the approving
user (the authenticated caller) is the same user who prepared the batch. The preparer identity
SHALL be derived exclusively from OpenRegister's immutable audit trail (ADR-022) — the `create`
actor plus any `update` actor recorded while the batch was a draft — and SHALL NOT be stored in a
bespoke `preparedBy`/`approvedBy` field that could drift from the audit trail. The control SHALL
run on the object-save path so a direct save that changes `lifecycleState` is gated identically to
the `/transition` endpoint.

#### Scenario: The preparer cannot self-approve

- **WHEN** the same user who created (prepared) a `PaymentRun` attempts to `approve` it
- **THEN** the transition is DENIED server-side with an actionable message stating self-approval
  is not permitted, and the batch remains in `draft`

#### Scenario: A different authorised user can release the batch

- **WHEN** a controller who did not prepare or modify the batch approves a `draft` `PaymentRun`
- **THEN** the transition is ALLOWED and the batch moves to `approved`

#### Scenario: A user who modified the draft cannot approve it

- **WHEN** a user who performed an `update` on the draft batch (not only its `create` actor)
  attempts to `approve` it
- **THEN** the transition is DENIED — segregation of duties covers everyone who shaped the batch
  content, not just its creator

### Requirement: REQ-PR4E-002 — The four-eyes guard SHALL fail closed on any indeterminate check

The guard SHALL treat every case where it cannot POSITIVELY establish that the approver differs
from the preparer as a DENY, never a pass. This includes: an empty/unknown approver identity, an
unidentifiable batch (no resolvable object id), an audit trail with no rows or no determinable
`create` actor, and any exception thrown while reading the audit trail. An indeterminate
segregation check SHALL NOT be reported as satisfied.

#### Scenario: Preparer indeterminate from the audit trail

- **WHEN** the batch's audit trail yields no determinable `create` actor (empty trail, or a
  `create` row with an unknown actor)
- **THEN** the `approve` transition is DENIED (fail-closed), not allowed

#### Scenario: Unknown approver is blocked before any audit read

- **WHEN** the approving user's identity cannot be determined (empty caller uid)
- **THEN** the transition is DENIED and no audit-trail read is performed

#### Scenario: Audit-trail read failure fails closed

- **WHEN** reading the batch's audit trail throws
- **THEN** the transition is DENIED (fail-closed) rather than proceeding

### Requirement: REQ-PR4E-003 — The denial SHALL carry an actionable, localized message

When the guard denies a self-approval, the returned message SHALL state that self-approval is not
permitted and that a different authorised user must approve the batch. The guard's user-facing
messages SHALL be available in English and Dutch (`l10n/en.json`, `l10n/nl.json`).

#### Scenario: Self-approval denial message is actionable and translated

- **WHEN** a self-approval is denied
- **THEN** the message tells the user a different authorised user must approve the batch, and an
  English and Dutch translation of that message exists

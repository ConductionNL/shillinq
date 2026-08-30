# Change: payment-run-four-eyes

## Why

`PaymentRun` is the outgoing SEPA batch schema (`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`): its
`approve` transition (`draft → approved`) is the hard control point before the batch can be
`export`ed to a `pain.001` / CSV bank file and money leaves the administration. Approval is the
one place segregation of duties must bite for outgoing payments — the classic financial control
that whoever RELEASES a payment run is not whoever PREPARED it.

**Verify-first finding (the audit flagged this PARTIAL — it is real, not covered).** Traced
against HEAD:

- The `approve` transition declared only `"x-rbac-role": "controller"` and NO `requires` guard.
  Its own description called it a *"Single-approver gate (D4)"* — a single controller who both
  prepared and approves the batch satisfies it. There was **no server-side check that the
  approver differs from the preparer**.
- The `bookkeeping-ccm-rule-engine.json` register *declares* a segregation-of-duties matrix
  (`CcmSegregationMatrix`, SoD scorecard), but that is monitoring/reporting metadata — it does
  not gate the PaymentRun transition.
- `lib/Lifecycle/ComplianceValidator.php` (the nearest thing to an SoD check) hardcodes
  `'segregation' => true` (line 203) — an unconditional PASS — and applies to treasury accounts,
  not payment runs. This is exactly the "declared ≠ enforced / fabricated pass" defect class the
  cycle keeps hitting.
- No class in `lib/` compared an approver identity to a preparer identity. `grep` for
  `preparer`/`selfApprov`/`fourEyes`/`segregationOfDuties` across `lib/` and `tests/` returned
  nothing.

Conclusion: **NOT enforced.** A controller could prepare a SEPA batch and approve it themselves,
server-side, with no obstacle. This change builds the real control.

## What Changes

- **ADDED** `REQ-PR4E-001` — the `PaymentRun.approve` transition SHALL be gated server-side by a
  four-eyes segregation-of-duties guard: the approving user MUST differ from the user who
  prepared the batch. Preparer identity is derived exclusively from OpenRegister's immutable
  audit trail (ADR-022) — the `create` actor plus any `update` actor while the batch was a draft —
  never a hand-rolled parallel actor log.
- **ADDED** `REQ-PR4E-002` — the guard SHALL FAIL CLOSED: an unknown approver, an unidentifiable
  batch, an audit trail with no determinable `create` actor, or any thrown exception all DENY the
  transition. An indeterminate check is never a pass.
- **ADDED** `REQ-PR4E-003` — the denial SHALL carry an actionable, translated (EN + NL) message
  ("Self-approval is not permitted … a different authorised user must approve").
- New guard `lib/Lifecycle/FourEyesPaymentRunGuard.php` implementing OpenRegister's
  `LifecycleGuardInterface::check($object, $action, $userId)` (it needs the caller uid, which the
  shared `RegisterRequiresGuardAdapter` does not forward), registered under its own FQCN tag in
  `lib/AppInfo/Application.php` and wired via the schema's
  `transitions.approve.requires`. `PaymentRun` schema version bumped `0.1.0 → 0.2.0` so the
  re-import picks up the new `requires`.

## Impact

- Affected spec: `payment-run-four-eyes` (new canonical capability).
- Affected code: `lib/Lifecycle/FourEyesPaymentRunGuard.php` (new),
  `lib/AppInfo/Application.php` (registration), `lib/Settings/register.d/
  bookkeeping-accounts-payable-core.json` (`approve.requires` + version bump), `l10n/en.json`,
  `l10n/nl.json`, new `tests/Unit/Lifecycle/FourEyesPaymentRunGuardTest.php`.
- Security-relevant: this is a segregation-of-duties control on the outgoing-money boundary; the
  failing-path test (preparer self-approves → rejected) is the whole point.
- **Mandate/threshold dimension:** a larger batch warranting a higher-authority approver is a
  natural extension. OpenRegister's `x-openregister-approval-chains` (the merged-but-NOT-deployed
  mandaat capability) is the eventual home for amount-tiered approver authority; because it is not
  deployed in this environment, this in-app guard is the reliable four-eyes control today. The
  threshold tier is deliberately out of scope here (see design.md) and left to that capability.

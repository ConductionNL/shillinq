---
kind: code
depends_on: []
---

# Proposal: segregation-control-real-check

## Summary

`lib/Lifecycle/ComplianceValidator.php::evaluateRule()` hardcoded
`'segregation' => true` for the `segregation`-type `BankingRule` criterion,
with a comment claiming "always passes here (IBAN uniqueness is enforced at
save time by OR)". That claim is false: no save-time uniqueness constraint
exists anywhere in the schema or codebase (verified — no `unique` keyword on
`TreasuryAccount.iban`, no DB-level constraint, no other enforcement path).
The `segregation` `BankingRule` is one of three seeded, active, blocking
compliance criteria (`rule-segregation`, per REQ-SCHATKIST-010) evaluated on
every `TreasuryAccount` `activate`/`monitor`/`reactivate` lifecycle
transition — a control that feeds a `ComplianceReport` (regulatory export
artefact) and an immutable audit trail event for Dutch schatkistbankieren
(treasury banking) compliance. The control reported a pass on every account,
regardless of whether it actually satisfied the rule.

**Correction to the task brief's initial hypothesis:** the brief assumed
`segregation` meant segregation-of-duties (functiescheiding — preparer ≠
approver on a transaction). Verified against the canonical spec
(`openspec/specs/bookkeeping-schatkistbankieren/spec.md` REQ-SCHATKIST-003/
-005/-010) and the register.d source
(`lib/Settings/register.d/bookkeeping-schatkistbankieren.json` line 208:
`"check": "no other TreasuryAccount in administrationId has the same iban
when BankingRule.evaluationCriteria.checkDuplicates=true"`), `segregation`
here means **duplicate-IBAN segregation across treasury accounts within one
administration**, not functiescheiding. Functiescheiding is a real, separate
concept this app already models under `bookkeeping-ccm-rule-engine`
(REQ-CCM-005, `CcmSegregationMatrix`) — untouched by this change. This
proposal fixes the actual defect, not the initially-hypothesised one.

## Motivation

A financial-controls app aimed at Dutch government administrations
(rechtmatigheid, BADO/ENSIA, accountantscontrole) shipping a compliance
control that fabricates a pass is worse than shipping no control at all — it
launders an unverified state into a `ComplianceReport` regulatory export
artefact and an immutable audit trail event that an auditor will treat as
evidence the check ran.

## Affected Projects

- [x] Project: `shillinq` — 1 modified class (`ComplianceValidator.php`), 1
  modified test file, 1 new Codeberg issue filed for a related-but-separate
  finding (not fixed here).

## Scope

### In Scope

- Replace the hardcoded `'segregation' => true` arm in
  `ComplianceValidator::evaluateRule()` with a real duplicate-IBAN check
  (`evaluateSegregation()`): query `TreasuryAccount` objects in the same
  `administrationId` via OR's `ObjectService`, exclude the account under
  evaluation, and fail when another account shares the IBAN.
- Honest tri-state semantics: genuine duplicate → violation (false, logged
  with account/IBAN/administration/conflicting-account-ids); missing IBAN or
  administrationId, or an `ObjectService` lookup failure → indeterminate
  (false, fail-closed, logged distinctly from a violation) — never a
  fabricated pass. `evaluationCriteria.checkDuplicates=false` explicitly
  disables the check per the schema's own documented criteria shape.
- Tests proving: (a) the bad path — duplicate IBAN in the same
  administration — is rejected; (b) the clean/segregated case passes; (c) a
  no-data case (missing IBAN, or a failing lookup) is indeterminate/rejected,
  not a pass; (d) `checkDuplicates=false` genuinely short-circuits.
- Sweep `ComplianceValidator.php` and sibling `*Validator.php` files
  (`lib/Standards/`, `lib/Service/*Compliance*`) for other hardcoded/
  always-true results. Found one: `evaluateRule()`'s `default => true` arm
  silently passes any `transaction-limit`/`reporting-period` `BankingRule`
  (declared in the REQ-SCHATKIST-003 enum but not yet implemented, and not
  seeded today). Filed as shillinq#442; NOT fixed here (see Out of Scope).

### Out of Scope

- shillinq#442 (`default => true` for unimplemented `transaction-limit`/
  `reporting-period` ruleTypes) — no seed data exists for either ruleType
  today, so the practical blast radius is latent, not active; fixing it
  means either implementing two new, unrelated business rules or changing
  fail-open-by-default behaviour for ruleTypes this task was not asked to
  touch. Filed separately per the task's explicit instruction not to
  silently bundle unrelated fixes into this change.
- Functiescheiding (segregation-of-duties in the preparer≠approver sense).
  That concept already exists in this app under `bookkeeping-ccm-rule-engine`
  (REQ-CCM-005, `CcmSegregationMatrix` + `ccm-user-function-assignment`) and
  is unrelated to the `BankingRule.ruleType=segregation` criterion this
  change fixes; not touched here.
- Any other `*Validator`/`*Compliance*` file — swept, no other hardcoded
  always-true results found (see design.md).

## Approach

See design.md for the full evidence trail (schema, register.d precondition
text, canonical spec scenarios) and the tri-state (pass/fail/indeterminate)
design rationale.

## New Dependencies

None.

## Impact

- `lib/Lifecycle/ComplianceValidator.php` — `evaluateRule()` signature gains
  an `objectService` parameter (reused instead of re-fetched); new private
  `evaluateSegregation()` method (~70 lines); updated docblocks.
- `tests/Unit/Lifecycle/ComplianceValidatorTest.php` — mock `ObjectService`
  now routes `findAll()` results by schema (`BankingRule` vs
  `TreasuryAccount`); 5 new tests (duplicate-IBAN violation, clean pass,
  lookup-failure indeterminate, missing-IBAN indeterminate,
  `checkDuplicates=false` short-circuit) plus the existing 8 tests updated
  for the new mock signature.

## Cross-Project Dependencies

None — OpenRegister (`ObjectService`) is consumed read-only, per ADR-022.

## Risks

### Risk 1: A `TreasuryAccount` created directly in `active` state bypasses this check
**Severity:** Low — **Mitigation:** this is a pre-existing OpenRegister
limitation shared by every lifecycle guard in this codebase (no
`ObjectTransitionedEvent` dispatch for a lifecycle field set at object-create
time, not at a transition) — out of scope for a single-control fix; the
`monitor` transition (also gated by this same `isCompliant()` call) provides
a second enforcement point for any account that reaches `active` unchecked.

### Risk 2: `ObjectService::findAll()` filtering by `iban` behaves like the existing `administrationId`/`isActive`/`vso_locked` filters used elsewhere in this codebase
**Severity:** Low — **Mitigation:** `iban` is a declared top-level
`TreasuryAccount` schema property (REQ-SCHATKIST-002); filtering by a
declared schema property mirrors the exact pattern already used for
`BankingRule.administrationId`/`.isActive` in this same class and
`VsoLockingValidator.vso_locked` elsewhere in the app.

## Rollback Strategy

Revert `ComplianceValidator.php` and the test file; no schema, seed-data, or
register.d changes are made by this change, so no data migration is needed.

## Open Questions

None.

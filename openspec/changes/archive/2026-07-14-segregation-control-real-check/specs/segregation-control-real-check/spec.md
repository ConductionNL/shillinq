# segregation-control-real-check Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- segregation-control-real-check

## Purpose

Fixes a fabricated compliance-control result: `ComplianceValidator::
evaluateRule()` hardcoded `'segregation' => true` for the `segregation`-type
`BankingRule` criterion evaluated on every `TreasuryAccount` `activate`/
`monitor`/`reactivate` lifecycle transition (REQ-SCHATKIST-005). Per
REQ-SCHATKIST-003/-010 and the register.d precondition text
(`lib/Settings/register.d/bookkeeping-schatkistbankieren.json:208`),
`segregation` means no other `TreasuryAccount` within the same
administration may share this account's IBAN — a real, seeded, blocking
criterion that was previously never actually checked.

## ADDED Requirements

### Requirement: REQ-SCR-001: The segregation BankingRule criterion MUST be evaluated against real TreasuryAccount data, never fabricated

`ComplianceValidator::evaluateRule()` MUST NOT return a hardcoded result for
`ruleType=segregation`. It MUST delegate to a real check that queries
`TreasuryAccount` records in the account's `administrationId` and determines
whether another account shares its IBAN.

#### Scenario: The segregation arm no longer hardcodes true
- **GIVEN** `lib/Lifecycle/ComplianceValidator.php`
- **WHEN** `evaluateRule()`'s `match` statement is inspected
- **THEN** the `'segregation'` arm MUST call a real evaluation method, not a literal `true`

@e2e exclude: backend-only lifecycle-guard business logic with no dedicated UI surface to drive; covered by PHPUnit unit tests exercising the real evaluation method plus a source-inspection assertion.

### Requirement: REQ-SCR-002: The segregation check MUST report an honest pass, fail, or indeterminate result — never a fabricated pass

The segregation check MUST return one of three honest results for a given `TreasuryAccount` and its administration's active `BankingRule` records:
- **fail** (deny) when another `TreasuryAccount` in the same
  `administrationId` has the same `iban`, logging the conflicting
  account id(s), the IBAN, and the administration.
- **pass** (allow) when no other `TreasuryAccount` in the
  administration shares the IBAN, or when `evaluationCriteria.
  checkDuplicates` is explicitly `false`.
- **indeterminate**, logged distinctly from a fail/violation, and
  fail-closed (deny) when the account has no IBAN/administrationId to check
  against, or when the underlying `TreasuryAccount` lookup fails — never a
  pass.

#### Scenario: Duplicate IBAN within the same administration is rejected
- **GIVEN** a `TreasuryAccount` with IBAN `NL91ABNA0417164300` already exists in administration `adm-1`
- **AND** a `BankingRule` with `ruleType=segregation`, `severity=blocking`, `evaluationCriteria: { checkDuplicates: true }` is active for `adm-1`
- **WHEN** a second `TreasuryAccount` with the same IBAN in the same administration attempts the `activate` transition
- **THEN** `ComplianceValidator::isCompliant()` MUST return `false`
- **AND** the denial MUST be logged with both account references, the IBAN, and the administration

#### Scenario: No duplicate IBAN passes
- **GIVEN** the same active `segregation` `BankingRule`
- **AND** no other `TreasuryAccount` in the administration shares this account's IBAN
- **WHEN** the account attempts the `activate` transition
- **THEN** `ComplianceValidator::isCompliant()` MUST return `true` for this criterion

#### Scenario: Missing data or a lookup failure is indeterminate, not a pass
- **GIVEN** the same active `segregation` `BankingRule`
- **AND** either the account under evaluation has no `iban`, or the `TreasuryAccount` lookup throws
- **WHEN** the account attempts the `activate` transition
- **THEN** `ComplianceValidator::isCompliant()` MUST return `false`
- **AND** the log entry MUST be distinguishable from a genuine duplicate-IBAN violation (labelled "indeterminate")

@e2e exclude: backend-only compliance-control logic, no dedicated UI surface; covered by PHPUnit unit tests proving the bad path (duplicate), the good path (no duplicate), and both no-data paths (missing IBAN, lookup failure).

### Requirement: REQ-SCR-003: Other hardcoded/always-true results in ComplianceValidator and sibling validators MUST be surfaced, not silently fixed out-of-scope

This change MUST perform a sweep of `ComplianceValidator.php` and sibling `*Validator.php`/`lib/Standards/`/`lib/Service/*Compliance*` files for other fabricated always-true results and report any finding, without silently bundling an unrelated fix into this change.

#### Scenario: The default-arm fabrication for unimplemented ruleTypes is filed, not fixed here
- **GIVEN** `evaluateRule()`'s `default => true` arm, which silently passes any `transaction-limit`/`reporting-period` `BankingRule` (declared in the REQ-SCHATKIST-003 enum, not yet implemented, no seed data today)
- **WHEN** this change ships
- **THEN** a Codeberg issue MUST exist documenting the finding (shillinq#442)
- **AND** `evaluateRule()`'s `default` arm MUST remain unchanged by this change

@e2e exclude: a documentation/process requirement with no runtime behaviour of its own to drive; verified by the presence of the filed issue and an unchanged `default` arm in the diff.

## Non-Functional Requirements

- **Performance:** the segregation check performs at most one additional
  bounded `ObjectService::findAll()` lookup (filtered by `administrationId` +
  `iban`) per rule evaluation; no unbounded scan.
- **Accessibility:** N/A — no UI surface.
- **Internationalization:** log payloads are operator/auditor-facing backend
  strings (English); no new translated UI copy is introduced.

## Acceptance Criteria

- [ ] `evaluateRule()`'s `segregation` arm calls a real evaluation method.
- [ ] A duplicate-IBAN-in-administration scenario is denied by
  `isCompliant()`.
- [ ] A no-duplicate scenario is allowed by `isCompliant()`.
- [ ] A missing-IBAN scenario and a failing-lookup scenario are both denied
  (indeterminate, not a pass), with logging distinguishing indeterminate
  from a genuine violation.
- [ ] `checkDuplicates=false` short-circuits to a pass without a
  `TreasuryAccount` lookup.
- [ ] The sibling-validator sweep is documented and any other finding is
  filed as a separate Codeberg issue, not fixed in this change.
- [ ] Full existing unit suite remains green (no regressions).

## Notes

- Functiescheiding (segregation-of-duties in the preparer≠approver sense)
  is a separate, already-real concept in this app under
  `bookkeeping-ccm-rule-engine` (REQ-CCM-005, `CcmSegregationMatrix`) and is
  not touched by this change.
- shillinq#442 tracks the sibling `default => true` fabrication for
  `transaction-limit`/`reporting-period` ruleTypes, explicitly out of scope
  here.

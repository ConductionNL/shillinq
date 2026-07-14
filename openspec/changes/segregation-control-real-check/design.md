# Design: segregation-control-real-check

## Architecture Overview

`ComplianceValidator::isCompliant(array $account): bool` is the ADR-031
imperative exception path for `TreasuryAccount`'s `activate`/`monitor`/
`reactivate` lifecycle transitions (`lib/Settings/shillinq_register.json`
lines 14455/14471/14511: `"requires":
"OCA\\Shillinq\\Lifecycle\\ComplianceValidator::isCompliant"`). It loads
every active `BankingRule` scoped to the account's `administrationId` and
evaluates each via `evaluateRule()`, a `match` on `ruleType`. Prior to this
change:

```php
return match ($ruleType) {
    'iban-format'       => $this->evaluateIbanFormat(...),   // real check
    'approval-required' => $this->evaluateApprovalRequired(...), // real check
    'segregation'       => true,                              // FABRICATED
    default              => true,                              // unimplemented, latent (shillinq#442)
};
```

`rule-segregation` is one of exactly three seeded `BankingRule` records
(REQ-SCHATKIST-010: `rule-iban-format`, `rule-segregation`,
`rule-approval-required`), all `severity: "blocking"`, all `isActive: true`
by default — meaning `segregation` is not a dormant/unused ruleType, it is
evaluated on every single treasury-account activation, and its fabricated
`true` silently defeated the blocking severity that was supposed to gate the
transition.

## Evidence trail (why "segregation" means duplicate-IBAN, not functiescheiding)

The task brief hypothesised segregation-of-duties (preparer≠approver). Three
independent sources in this codebase say otherwise, and agree with each
other:

1. **Canonical spec**
   (`openspec/specs/bookkeeping-schatkistbankieren/spec.md`)
   REQ-SCHATKIST-003 scenario: *"Segregation rule prevents duplicate IBANs
   within administration"* — `GIVEN a BankingRule with ruleType=segregation
   and evaluationCriteria: { checkDuplicates: true } AND a TreasuryAccount
   with IBAN ... already exists WHEN a second account with the same IBAN is
   configured THEN the rule MUST fail with a "duplicate IBAN in
   administration" error.` REQ-SCHATKIST-010 names the seed rule
   `rule-segregation` — *"enforces IBAN uniqueness per administration"*.

2. **register.d precondition text**
   (`lib/Settings/register.d/bookkeeping-schatkistbankieren.json:208`,
   inside the `activate` transition's declared preconditions): `"check": "no
   other TreasuryAccount in administrationId has the same iban when
   BankingRule.evaluationCriteria.checkDuplicates=true"`. This is the exact
   business rule this change implements — it was already fully specified in
   the register.d source, just never wired into the PHP fallback that
   actually runs it.

3. **ADR-000 data model doc**
   (`openspec/architecture/adr-000-data-model.md:782`): `evaluationCriteria`
   payload shape "varies by ruleType (iban-format → pattern, **segregation →
   checkDuplicates**, approval-required → requiresTreasurerApproval, ...)".

Separately, this codebase DOES have a genuine functiescheiding
(segregation-of-duties) concept — `bookkeeping-ccm-rule-engine`'s
REQ-CCM-005 (*"Segregation-of-duties violations SHALL be detected by
comparing user function-code assignments against the SoD matrix"*),
`CcmSegregationMatrix` + `ccm-user-function-assignment` registers. That is a
different control, on a different schema, evaluated by a different
mechanism (the CCM rule engine's control-family firing, not
`ComplianceValidator`), and is untouched by this change — its
`control_family: segregation-of-duties` enum value and dedicated matrix
schema were already real, seeded (7 seed rules), and not part of this
defect.

## Fix

`evaluateRule()` now routes `segregation` to a new
`evaluateSegregation(array $criteria, array $account, object $objectService):
bool`:

1. `checkDuplicates = criteria['checkDuplicates'] ?? true`; if explicitly
   `false`, the rule is disabled by configuration → pass (per the schema's
   own documented criteria shape — this is a real "off" switch, not a
   fabricated pass, because it's an explicit operator choice recorded on the
   `BankingRule` record itself, auditable independently).
2. If the account has no `iban` or `administrationId` to check against →
   **indeterminate**, logged distinctly (`"segregation check indeterminate
   — missing iban or administrationId"`) and denies (fail-closed) — matches
   this class's existing fail-closed convention for `isCompliant()`'s own
   missing-`administrationId` case.
3. Query `TreasuryAccount` objects via the same `ObjectService` instance
   `isCompliant()` already resolved (passed through, not re-fetched from the
   container), filtered by `administrationId` + `iban` — the same filtering
   pattern already used for `BankingRule.administrationId`/`.isActive` two
   methods up in this same file.
4. A lookup failure (`ObjectService` throws) → **indeterminate**, logged
   distinctly (`"segregation check indeterminate — TreasuryAccount lookup
   failed"`), denies (fail-closed).
5. Any returned `TreasuryAccount` other than the account under evaluation
   itself (matched by `id`, falling back to `accountNumber` when `id` is
   absent) is a genuine duplicate → **violation**, logged with the account,
   IBAN, administration, and the conflicting account id(s) so an auditor can
   follow it from the `ComplianceReport`/audit-trail event back to the exact
   colliding record.
6. No duplicates found → real pass.

This gives the honest tri-state the task requires (pass / fail /
indeterminate) while keeping the public contract a `bool` (required by the
`x-openregister-lifecycle requires:` seam, which — like every other guard in
this class — can only express allow/deny; the distinction between "fail"
and "indeterminate" lives in the structured log payload, not the return
type, exactly matching how `isCompliant()`'s own missing-administrationId
and exception paths already work).

## Declarative-vs-imperative decision (ADR-031)

No new declarative primitive is introduced. This is a fix *inside* an
already-declared ADR-031 imperative exception (`ComplianceValidator` itself,
declared as the `fallback` for `activate`'s multi-criteria precondition
because OR's lifecycle engine cannot yet evaluate cross-schema conditional
rules — `register.d/bookkeeping-schatkistbankieren.json:215`). The
duplicate-IBAN check is exactly the kind of cross-object (TreasuryAccount ↔
TreasuryAccount), cross-schema lookup ADR-031 reserves the PHP-guard seam
for; expressing it declaratively would require the OR lifecycle engine to
support a "no sibling object with matching field value" precondition
primitive, which it does not today. The fix stays inside the existing
exception path rather than inventing a new one.

## Seed Data

No new schemas or seed records are introduced. The `rule-segregation` seed
`BankingRule` record already exists (REQ-SCHATKIST-010,
`lib/Settings/register.d/bookkeeping-schatkistbankieren.json` seed block,
`evaluationCriteria: { checkDuplicates: true }`) and already ships via
`ConfigurationService::importFromApp`; this change makes the PHP evaluation
of that already-seeded rule honest.

## Trade-offs

Considered fixing the sibling `default => true` fabrication (shillinq#442,
`transaction-limit`/`reporting-period` ruleTypes) in the same change, since
the root cause (an unconditional `true` arm in the same `match`) is
identical. Rejected: those two ruleTypes have zero seed data and zero
current consumers — implementing them for real means inventing two
unrelated business rules (a transaction-amount ceiling and a reporting
cadence check) neither the task nor any spec scenario asked for, which would
smuggle unrelated scope into a security/compliance fix. Filed separately
instead, per the task's explicit "do NOT silently fix unrelated ones"
instruction.

## Nextcloud Integration

- Services: none new — `ComplianceValidator` is already constructed via
  Nextcloud's container (`ContainerInterface`, `IAppConfig`,
  `LoggerInterface` — all pre-existing constructor params, unchanged).
- DI: no changes.
- Events/Hooks: consumed only — OpenRegister's `LifecycleValidationListener`
  calls `isCompliant()` via the `requires:` tag; not modified.

## Security Considerations

Fail-closed throughout (CWE-863/OWASP A01:2021): every new branch in
`evaluateSegregation()` that cannot positively confirm no-duplicate denies
the transition. The fix closes a real control-bypass — every
`TreasuryAccount` activated since `rule-segregation` was seeded (REQ-
SCHATKIST-010) passed this criterion regardless of actual duplicate-IBAN
state; any `ComplianceReport`/audit-trail event citing a `segregation` pass
prior to this change reflects a fabricated result, not a verified one. No
migration/backfill is in scope for this change (out of scope — a separate,
larger undertaking to re-evaluate and potentially flag historical
`ComplianceReport` records).

## File Structure

```
lib/
  Lifecycle/
    ComplianceValidator.php   (modified — evaluateRule() signature, +evaluateSegregation())
tests/
  Unit/Lifecycle/
    ComplianceValidatorTest.php   (modified — mock routes by schema, +6 tests)
```

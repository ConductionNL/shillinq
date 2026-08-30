# missing-lifecycle-guards Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- missing-lifecycle-guards (2026-07-13, archived)

## Purpose

Fixes shillinq#425 (CRITICAL): 17 `x-openregister-lifecycle` `requires` guard
classes referenced from `lib/Settings/register.d/*.json` /
`lib/Settings/shillinq_register.json` did not exist, plus
`PeriodCloseGuard::trialBalanceVerifies` existed on the class but not as a
method — every transition referencing one hard-fails at runtime. Also fixes
the previously-undiscovered second half of the same defect: a `requires`
tag shaped `Class::method` can never resolve via Nextcloud's container
unless the app explicitly registers that literal string as a DI service
alias — true for every one of these 16 tags prior to this change. See
`openspec/changes/archive/2026-07-13-missing-lifecycle-guards/design.md`
for the full per-guard implement-vs-remove decision table.

## Requirements
### Requirement: REQ-LCG-001: Every FQCN-shaped requires value referenced by this change MUST resolve to a real, callable guard
The system MUST satisfy three conditions for each of the 16 lifecycle-guard tags this change fixes (15 new guard classes plus one new method on the existing PeriodCloseGuard class — full list in design.md's decision table): the referenced class MUST exist; the referenced method MUST exist and be callable when the tag names one; and Application.php MUST register that exact literal tag string as a DI service alias so OpenRegister's LifecycleGuardRegistry::resolve() can produce an instance implementing LifecycleGuardInterface.

#### Scenario: A regression test enumerates every FQCN-shaped requires value and asserts it resolves
- GIVEN `lib/Settings/shillinq_register.json` and every `lib/Settings/register.d/*.json` fragment
- WHEN a test walks every `x-openregister-lifecycle.transitions.*.requires` value shaped like a namespaced class (optionally with a `::method` suffix)
- THEN the referenced class exists, the referenced method (when present) exists, and — for the 16 tags this change fixes — `lib/AppInfo/Application.php` contains a `registerService()` call for that exact literal tag string
- AND a value shaped like `OCA\Shillinq\Guard\DoesNotExist::method` would fail this test

@e2e exclude: backend-only lifecycle-guard resolution and pure business-rule logic with no dedicated UI surface to drive; covered by PHPUnit unit tests exercising the actual guard classes and a static register.d/Application.php cross-reference test (the regression guard for this whole bug class).

### Requirement: REQ-LCG-002: Each implemented guard MUST reject the documented bad path and accept the documented good path
Each of the 15 newly-implemented guard classes and the one new method on PeriodCloseGuard (full list in design.md) MUST implement the business rule already documented in its owning transition's description field, and MUST fail closed (deny) on any lookup exception.

#### Scenario: AP duplicate-invoice-number guard rejects a duplicate and accepts a unique invoice
- GIVEN an existing `APTransaction` with `invoiceNumber=INV-001`, `vendorId=v-1`, `administrationId=adm-1`
- WHEN a new `APTransaction` with the same `invoiceNumber`/`vendorId`/`administrationId` attempts the `receive` transition
- THEN `APGuard::isInvoiceNumberUnique()` returns `false` and the transition is denied
- AND WHEN an `APTransaction` with a different `invoiceNumber` attempts `receive` THEN it returns `true` and the transition proceeds

#### Scenario: Fiscal-year close guard rejects while any FiscalPeriod in the year remains open
- GIVEN a FiscalYear with two FiscalPeriod records, one `closed` and one `open`
- WHEN the FiscalYear attempts the `beginClose` transition
- THEN `FiscalYearGuard::requireAllPeriodsClosedForYear()` returns `false`
- AND WHEN every FiscalPeriod for that year is `closed` or `audit-locked` THEN it returns `true`

@e2e exclude: same as REQ-LCG-001 — backend business-rule logic, no dedicated UI surface; covered by PHPUnit unit tests with real (non-mocked) guard classes proving both the bad path (rejected) and the good path (accepted) for every guard.

### Requirement: REQ-LCG-003: A previously-silent JSON shape bug MUST be fixed so SubsidieRepaymentGuard's precondition is actually evaluated
The `requires` value on Subsidie's `afhandelenVanuitTeruggevorderd` transition MUST be a plain string (it was previously a JSON object, which OpenRegister's LifecycleValidationListener silently never evaluates since it only acts when `is_string($spec['requires'])` — a dossier could close with a non-zero outstanding repayment balance), and SubsidieRepaymentGuard::requireZeroRepaymentBalance() MUST deny the transition while any RepaymentInstallment for the Subsidie remains unpaid.

#### Scenario: Dossier close is denied while a repayment installment remains outstanding
- GIVEN a Subsidie with two RepaymentInstallment records, one `paid` and one `due`
- WHEN the Subsidie attempts the `afhandelenVanuitTeruggevorderd` transition
- THEN `SubsidieRepaymentGuard::requireZeroRepaymentBalance()` returns `false`
- AND WHEN every RepaymentInstallment for that Subsidie is `paid` THEN it returns `true`

@e2e exclude: same as REQ-LCG-001/002 — backend business-rule logic; covered by PHPUnit unit tests plus a JSON-shape assertion in the register-fixture test suite.

### Requirement: REQ-LCG-004: Two false guard declarations that referenced dead, unconsumed JSON keys MUST be removed rather than implemented
The system MUST remove the `RateResolutionGuard::resolveByTierPrecedence` (declared under `x-openregister-aggregations.resolveRate.fallbackGuard`) and `SteekproefSampler::computeDeterministicSample` (declared under `x-openregister-aggregations.steekproef.guard`) declarations from the register.d/monolith source rather than implement them, because both reference JSON keys OpenRegister's aggregation engine (AggregationRunner) never reads under any circumstance — implementing them would produce dead code that still could never be invoked.

#### Scenario: The dead fallbackGuard/guard keys no longer appear in the register source
- GIVEN `lib/Settings/shillinq_register.json`'s `RateSchedule.resolveRate` aggregation
- WHEN the aggregation's JSON is inspected
- THEN no `fallbackGuard` key is present
- AND GIVEN `lib/Settings/register.d/add-shillinq-rekenkamer-audit-pack.json`'s `steekproef` aggregation WHEN inspected THEN no `guard` key is present

@e2e exclude: a config-content assertion with no runtime/UI behaviour to drive; covered by a JSON-content unit test.


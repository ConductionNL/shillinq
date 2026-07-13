# Design: missing-lifecycle-guards

## Architecture Overview

Two independent defects stacked on top of each other, both fixed here:

1. **17 named guard classes did not exist** (+1 missing method on an
   existing class). `LifecycleValidationListener::handle()` (OpenRegister
   core) reads `spec['requires']` for the matched transition and, when it is
   a non-empty string, calls `LifecycleGuardRegistry::resolve($requires)`.
   `resolve()` throws `RuntimeException` when nothing satisfies the tag —
   read directly from `openregister/lib/Service/Lifecycle/
   LifecycleGuardRegistry.php:100-117` and confirmed the exception is never
   caught in `LifecycleValidationListener.php` (no try/catch around line
   218). **Hard-fail, not a silent bypass** — the transition throws a 500
   the moment an operator attempts it.

2. **Even with the class present, the tag never resolves**, for any
   `requires` value shaped `"Namespace\Class::method"`. OpenRegister's
   `LifecycleGuardRegistry::resolve()` calls `$candidate->get($tag)` on two
   containers (OR's own, then NC's server container — both are
   `\OC\AppFramework\Utility\SimpleContainer`). `SimpleContainer::query()`
   checks its own registered aliases first, then falls back to
   `resolve()` → `new ReflectionClass($name)`. Verified empirically inside
   the live `nextcloud` container:
   ```
   php -r '(new ReflectionClass("Foo::bar"));'
   → ReflectionException: Class "Foo::bar" does not exist
   ```
   A PHP class name cannot contain `::`, so this throws unconditionally,
   for ANY `Class::method`-shaped tag, regardless of whether the class
   exists — UNLESS the app explicitly registers that literal string as a
   service alias via `registerService($tag, ...)`. Grepping
   `lib/AppInfo/Application.php` found **zero** such registrations for any
   guard tag, including ones whose class already exists and is already
   unit-tested in isolation (`MandaatEnforcer`, `BudgetBlocker`,
   `PeriodCloseGuard`, `InventoryPostingGuard`, `KorThresholdGuard`, ...).
   Those are pre-existing, independently broken, and out of scope
   (shillinq#433) — but this file's own precedent
   (`RoleFallbackResolver::class.'::financeOfficer'` registered at
   `Application.php` ~line 549 for notification resolvers) proves the
   codebase's authors already knew arbitrary string tags need an explicit
   `registerService()` call; it was simply never done for lifecycle guards.

The fix: implement the 15 classes + 1 method (or remove the false
declarations — see the decision table below), add one small reusable
`RegisterRequiresGuardAdapter` (implements OpenRegister's
`LifecycleGuardInterface`), and register each of the 16 real tags in
`Application.php` mapped through that adapter onto each guard's existing
`bool <method>(array $object): bool` precondition method (the fleet-wide
convention already used by every guard in `lib/Guard/`/`lib/Lifecycle/`).

## Per-guard decision table (the 17 + 1)

Decided per guard on evidence — what the schema/transition actually models,
whether a spec/REQ requires the rule, and whether the JSON key is even
consumed by any OpenRegister code path. Not blanket-implemented or
blanket-deleted.

| # | Name | Where declared | Verified consumption path | Decision | Why |
|---|------|-----------------|---------------------------|----------|-----|
| 1 | `Iv3XmlValidationGuard::requireValidXml` | `Iv3Export.validate`/`.revalidate` (monolith) | `transitions.*.requires`, plain string — hard-fails | **Implement** | Real rule: export must have a generated XML attachment + aggregated buckets before being declared CBS-schema-valid. |
| 2 | `Iv3SubmissionGuard::requireApproval` | `Iv3Export.submit` (monolith) | same | **Implement** | No approval-evidence field exists on the schema (verified); implemented the two checks the data DOES support — no double-submission, artefact completeness — rather than inventing an approver field. |
| 3 | `KorLockoutGuard::requireLockoutExpired` | `KorRegime.returnToOutside` (monolith) | same | **Implement** | Concrete, well-known Dutch VAT rule: 3-year KOR re-entry lock-out (Wet OB 1968 art. 25 lid 3), computed from `optedOutAt`. |
| 4 | `ProjectActivationGuard::requireStartDate` | `Project.activate` (monolith) | same | **Implement** | Literal, already-documented rule ("startDate must be set"). |
| 5 | `ProjectTransitionGuard::requireReason` | `Project.putOnHold` (monolith) | same | **Implement** | Literal rule (`closureJustification` required). |
| 6 | `ProjectCloseGuard::requireWipJustificationOrZero` | `Project.close` (monolith) | same | **Implement** | Literal rule: zero WIP or a recorded justification. |
| 7 | `FiscalYearGuard::requireAllPeriodsClosedForYear` | `FiscalYear.beginClose` (monolith) | same | **Implement** | REQ-YEC-007, cross-schema FiscalPeriod lookup — genuinely needs a PHP guard (ADR-031 exception path). |
| 8 | `GLReversalGuard::isReversed` | `ExpenseClaimEntry.void` (monolith, live schema), `APInvoice.void`/`ARInvoice.void` (monolith, **mis-nested schemas**, shillinq#434) | same | **Implement** | T1 REQ-GL-004 ("materialised GLTransaction MUST already be reversed"). `ExpenseClaimEntry` is reachable today; the AP/AR uses are correct code waiting on shillinq#434. |
| 9 | `WriteOffReasonGuard::requireReason` | `ARInvoice.writeOff` (monolith, mis-nested, shillinq#434) | same | **Implement** | Literal rule; same #434 caveat. |
| 10 | `VatSubmissionGuard::requireApproval` | `VatReturn.submit` (register.d) | same | **Implement** | Originally suspected a silent OWASP A01 bypass — confirmed instead to hard-fail. No approval-evidence field exists (verified); implemented the threshold check literally and fail-closed (shillinq#435 tracks adding real evidence). |
| 11 | `BcfSubmissionGuard::requireApproval` | `BcfClaim.submit` (register.d) | same | **Implement** | Same shape as #10. |
| 12/13 | `APGuard::isInvoiceNumberUnique` / `::requireWriteOffReason` | `APTransaction.receive`/`.writeOff` (register.d) | same | **Implement** | #12 is a genuine AP duplicate-invoice control (REQ-AP-003 scenario 2) — this is the concrete shape of the exact OWASP A01 risk the initial triage worried about; now real and tested. #13 is a literal reason-required rule. |
| 14 | `WBSOExportValidationGuard` (bare class) | `WBSOExportLog.validate` (monolith, mis-nested, shillinq#434) | same (bare-class tag) | **Implement** | REQ-WBSO-006. Core tag-completeness check is fully real; the `isAllowed` eligibility cross-check against the ALSO-mis-nested `WBSOActivityCode` degrades gracefully (logs, doesn't deny) rather than let an unrelated bug permanently block every export. |
| 15 | `SubsidieRepaymentGuard::requireZeroRepaymentBalance` | `Subsidie.afhandelenVanuitTeruggevorderd` (monolith) | **shape bug**: `requires` was `{"guard": "..."}`, an object — `LifecycleValidationListener` only acts when `is_string($requires)`, so this was **silently skipped entirely**, not even attempted | **Implement + fix JSON shape** | This one WAS the silent bypass the initial triage suspected for the whole set — a dossier could close with money still owed. Fixed the shape to a plain string (now a real, enforced `requires`) and implemented the class against the real `RepaymentInstallment` schema. |
| 16 | `RateScheduleOverlapGuard::requireNonOverlappingWindow` | Declared under `x-openregister-lifecycle.preconditions.save` (monolith) — **`preconditions` is a key `LifecycleValidationListener` never reads at all** (only `transitions.<action>.requires`); confirmed via a fleet-wide grep of OR core for the literal string `preconditions` — zero hits outside unrelated docstrings | **Implement + relocate** | REQ-RATE-006 ("(tier, entityId) pairs MUST NOT have overlapping windows") is a real, valuable rule and the `reactivate` transition (`inactive → active`) is a genuine, already-existing hook this check can attach to for real. Relocated the `requires` there instead of leaving it under a key nothing reads. Does not catch a brand-new schedule created directly in `active` state — OpenRegister does not dispatch `ObjectTransitionedEvent` for a lifecycle field set at create time (pre-existing OR limitation, not addressed here). |
| 17 | `RateResolutionGuard::resolveByTierPrecedence` | `x-openregister-aggregations.resolveRate.fallbackGuard` (monolith) | **dead key** — grepped OR's entire `AggregationRunner`/`AggregationQuery`/`AggregationAnnotationValidator`/`AggregationCache` for any read of a `fallbackGuard`/`guard` key: zero hits. Never invoked, class-exists-or-not | **Remove** | Implementing a PHP class here would fix nothing (still never called) without also extending OR's aggregation engine (out of scope, OR is read-only per this task's brief). Removed the dead key + corrected the aggregation's description text. Filed shillinq#436 for the underlying "aggregation engine has no PHP-guard-fallback seam" gap if it's ever needed for real. |
| 18 | `SteekproefSampler::computeDeterministicSample` | `x-openregister-aggregations.steekproef.guard` (register.d fragment) | same dead-key defect as #17 (confirmed: same missing read path) — **also** a near-duplicate of the monolith's own `steekproef` aggregation, which references a THIRD, also-nonexistent class (`OCA\Shillinq\Audit\SteekproefSampler::sample`) under `_meta.engineLimitFallback` — also dead | **Remove** | Same reasoning as #17. Did not touch the monolith's separate `engineLimitFallback` reference (different aggregation key path, same dead-key defect, but outside this fragment file) — noted for shillinq#436. |

**Count:** 15 implemented (13 hard-fail fixes + 1 shape-bug fix + 1
relocation-and-implement) + 1 method added to an existing class + 2 removed
= all 18 items resolved.

## Goals / Non-Goals

**Goals:** every transition shillinq#425 names ends up either genuinely
guarded (class + method + DI registration all real) or cleanly guard-free.
Real, tested logic — no stubs, no invented business rules.

**Non-Goals:** fixing the dozens of OTHER pre-existing guards sharing the
DI-registration gap (shillinq#433); fixing the 23 mis-nested schemas
(shillinq#434); adding approval-evidence fields to VatReturn/BcfClaim
(shillinq#435); giving OR's aggregation engine a PHP-guard-fallback seam
(shillinq#436).

## Decisions

**Decision: a shared `RegisterRequiresGuardAdapter` rather than 16 bespoke
adapters, or making each guard implement `LifecycleGuardInterface`
directly.** Every existing guard in this app (`MandaatEnforcer`,
`BudgetBlocker`, `PeriodCloseGuard`, ...) already exposes plain
`bool <method>(array $object): bool` precondition methods, not
`LifecycleGuardInterface::check()`. Making all 15 new classes implement the
interface directly would (a) diverge from the established, reviewed
convention the task brief explicitly asked to mirror, and (b) require
adding EVERY new class to `phpstan.neon`'s `excludePaths` (cross-app
interface unavailable at analysis time — confirmed via the existing
`CustomerBridgeMetricsService` precedent, PHPStan requires excludePaths for
this specific error, not suppressible via `ignoreErrors`/baseline). One
shared adapter class keeps that friction to a single file/single
excludePaths entry.

**Decision: register the DI tag explicitly in `Application.php`, not by
adding a splitting/parsing layer to OpenRegister.** OpenRegister is
read-only for this task. The adapter + explicit `registerService()` per
tag is the only shillinq-side fix available, and matches this file's own
existing precedent for non-class-shaped DI tags
(`RoleFallbackResolver::class.'::financeOfficer'`).

**Decision: add two new stub files
(`tests/stubs/OpenRegister/Lifecycle/{LifecycleGuardInterface,
GuardResult}.php`) rather than mock the interface per test.** Mirrors the
exact existing pattern for `OCA\OpenRegister\Db\ObjectEntity`,
`OCA\OpenRegister\AppHost\IMetricsProvider`, etc. — `tests/bootstrap-unit.php`
already registers a `OCA\OpenRegister\` → `tests/stubs/OpenRegister/` PSR-4
mapping for exactly this purpose.

## Declarative-vs-imperative decision (ADR-031)

Every one of the 15 implemented guards is the ADR-031 **imperative
exception path**, not a new declarative primitive — each is a thin, single
purpose PHP class (one guarded transition, one precondition), matching the
existing `lib/Guard/`/`lib/Lifecycle/` convention exactly (this is precisely
what ADR-031 reserves the PHP-guard seam for: cross-schema lookups and
conditions the declarative `requires:`/aggregation DSL cannot express
today). No new declarative dialect was introduced. The two REMOVED items
(`RateResolutionGuard`, `SteekproefSampler`) were themselves an attempt at a
declarative-with-PHP-fallback pattern (`fallbackGuard`/`guard` under
`x-openregister-aggregations`) that OpenRegister's aggregation engine has
never actually implemented on either side — removing the false declaration
rather than building out a new, unrequested aggregation-engine capability is
the correct, minimal fix per this task's explicit instruction not to invent
scope to fill a slot.

## Nextcloud Integration

- Services: 15 new guard classes (`lib/Guard/*.php`, `lib/Lifecycle/*.php`),
  autowired by Nextcloud's container (no explicit `registerService()` needed
  for the guards themselves — only for their `requires`-tag aliases).
- DI: 16 new `registerService()` calls in `Application::register()`.
- Events/Hooks: none — these are OpenRegister's own
  `LifecycleValidationListener` → `LifecycleGuardRegistry::resolve()` path,
  consumed, not modified.

## Security Considerations

Every guard fails closed (any exception denies the transition rather than
permitting it — CWE-863/OWASP A01:2021), matching the codebase's own
established convention. `APGuard::isInvoiceNumberUnique` and
`VatSubmissionGuard`/`BcfSubmissionGuard::requireApproval` are the concrete
shape of the exact controls (AP duplicate-invoice detection, VAT/BCF
submission approval) the initial mis-diagnosis worried were silently
bypassed; they are now real, tested, and enforced (once shillinq#433's
registration gap for THIS change's 16 tags — fixed here — is in place).
`SubsidieRepaymentGuard` was a genuine silent bypass (JSON shape bug) and is
now fixed both in class and in JSON shape.

## Seed Data

No new schemas are introduced by this change (all 15 guards operate on
schemas that already exist and already ship seed data via their owning
register.d fragments — `Iv3Export`, `KorRegime`, `Project`, `FiscalYear`,
`ExpenseClaimEntry`, `VatReturn`, `BcfClaim`, `APTransaction`,
`RateSchedule`, `Subsidie`/`RepaymentInstallment`). No new seed data is
required.

## File Structure

```
lib/
  Guard/
    Iv3XmlValidationGuard.php          (new)
    Iv3SubmissionGuard.php             (new)
    KorLockoutGuard.php                (new)
    ProjectActivationGuard.php         (new)
    ProjectTransitionGuard.php         (new)
    ProjectCloseGuard.php              (new)
    VatSubmissionGuard.php             (new)
    BcfSubmissionGuard.php             (new)
    SubsidieRepaymentGuard.php         (new)
    RateScheduleOverlapGuard.php       (new)
  Lifecycle/
    RegisterRequiresGuardAdapter.php   (new)
    FiscalYearGuard.php                (new)
    GLReversalGuard.php                (new)
    WriteOffReasonGuard.php            (new)
    APGuard.php                        (new)
    WBSOExportValidationGuard.php      (new)
    PeriodCloseGuard.php               (modified — +1 method)
  AppInfo/
    Application.php                    (modified — +16 registerService calls)
  Settings/
    shillinq_register.json             (modified — shape fix, relocation, 2 removals)
    register.d/
      add-shillinq-rekenkamer-audit-pack.json  (modified — 1 removal)
tests/
  stubs/OpenRegister/Lifecycle/
    LifecycleGuardInterface.php        (new)
    GuardResult.php                    (new)
  Unit/Guard/*Test.php                 (new, one per lib/Guard/ class)
  Unit/Lifecycle/*Test.php             (new, one per lib/Lifecycle/ class + adapter)
  Unit/Settings/RegisterLifecycleGuardsResolveTest.php  (new — regression guard)
phpstan.neon                           (modified — excludePaths entry)
psalm.xml                              (modified — 2 referencedClass entries)
```

## Trade-offs

Considered fixing all ~40+ pre-existing broken guard registrations
(shillinq#433) in the same change, since the root-cause fix
(`RegisterRequiresGuardAdapter` + registration pattern) is identical.
Rejected: shillinq#425 is scoped to 17 named classes + 1 method; expanding
to every guard in the app would 3-5x the diff, mixing well-understood,
individually-reasoned business-rule decisions (this change) with a
mechanical bulk-registration pass better done, reviewed, and tested as its
own change.

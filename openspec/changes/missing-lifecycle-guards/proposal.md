---
kind: code
depends_on: []
---

# Proposal: missing-lifecycle-guards

## Summary

`lib/Settings/register.d/*.json` and `lib/Settings/shillinq_register.json`
declare `x-openregister-lifecycle` transitions whose `requires` clause names
17 guard classes that do not exist (e.g. `VatSubmissionGuard`,
`APGuard`, `GLReversalGuard`, `FiscalYearGuard`), plus `PeriodCloseGuard`
which exists but is missing its `trialBalanceVerifies` method. Filed as
shillinq#425 (CRITICAL). Confirmed against OpenRegister core
(`LifecycleGuardRegistry::resolve()` throws `RuntimeException` on an
unresolvable tag; `LifecycleValidationListener::handle()` does not catch
it) that these are **hard-broken transitions** — the transition errors out
at runtime — not silent control bypasses.

The investigation also surfaced a second, previously-undiscovered half of
the same defect: OpenRegister's container can only resolve a `requires` tag
that is either a literal, instantiable class name, or an explicitly
registered service alias. A tag containing `::` (the fleet-wide
"`Class::method`" convention this app's register.d uses throughout) can
never satisfy the first case — confirmed empirically that
`new ReflectionClass('Foo::bar')` always throws — so simply adding the 17
classes would still leave every transition broken unless the exact literal
tag is also registered in `Application.php`. This change fixes both halves
for the 17 classes (+1 method) shillinq#425 covers; the same registration
gap affecting dozens of other, already-existing guards elsewhere in this
app is filed separately (shillinq#433) and intentionally not fixed here.

## Motivation

A financial-controls app with 17 lifecycle transitions that throw a 500 the
moment an operator attempts them — VAT return submission, AP invoice
receipt (vendor-invoice-number dedupe), fiscal-year close, project
activation/close, KOR re-entry, IV3/BCF export, invoice write-off/void — is
a critical, user-visible defect. Two of the seventeen (`VatSubmissionGuard`,
`BcfSubmissionGuard`) were initially suspected to be a silent
financial-control bypass (OWASP A01); this change confirms and documents
that they instead hard-fail, and fixes them the same way as the rest.

## Affected Projects

- [x] Project: `shillinq` — 15 new guard classes, 1 new method on an
  existing guard, 1 new reusable adapter class, 16 new `registerService()`
  wirings in `Application.php`, 2 register.d/JSON corrections (one shape
  bug, one dead-key relocation), and 2 dead-key removals for two of the
  original 17 names that turned out not to be lifecycle guards at all.

## Scope

### In Scope

- Implement, with real (non-stub) business logic mirroring the schema's own
  documented rule, for the 13 genuinely wired `x-openregister-lifecycle`
  transition guards: `Iv3XmlValidationGuard`, `Iv3SubmissionGuard`,
  `KorLockoutGuard`, `ProjectActivationGuard`, `ProjectTransitionGuard`,
  `ProjectCloseGuard`, `FiscalYearGuard`, `GLReversalGuard`,
  `WriteOffReasonGuard`, `VatSubmissionGuard`, `BcfSubmissionGuard`,
  `APGuard` (2 methods), `WBSOExportValidationGuard`, plus
  `PeriodCloseGuard::trialBalanceVerifies`.
- Fix a genuine JSON shape bug for `SubsidieRepaymentGuard` (`requires` was
  an object, not the plain string the listener reads — a true silent
  bypass, worse than a hard-fail) and implement the class for real.
- Relocate `RateScheduleOverlapGuard` from a dead `preconditions.save` key
  (never read by any OpenRegister code path) to the `reactivate`
  transition's `requires` (a real, enforced hook), and implement the class.
- Remove two false declarations that turned out not to be part of the
  lifecycle-guard mechanism at all: `RateResolutionGuard` (aggregation
  `fallbackGuard` key — never consumed by OR's `AggregationRunner`) and
  `SteekproefSampler` (aggregation `guard` key — same defect).
- A new `RegisterRequiresGuardAdapter` (implements OpenRegister's
  `LifecycleGuardInterface`) + 16 `registerService()` calls in
  `Application.php` so every one of the 16 fixed tags genuinely resolves at
  runtime, not just exists as a class.
- A regression test enumerating every FQCN-shaped `requires` value across
  `lib/Settings/**` and asserting the class/method exist, plus (for the 16
  tags this change fixes) that `Application.php` registers the exact
  literal tag.
- Per-guard unit tests proving the bad path is rejected and the good path
  passes.

### Out of Scope

- The dozens of other pre-existing `Class::method`-shaped guards elsewhere
  in this app (`MandaatEnforcer`, `BudgetBlocker`, `PeriodCloseGuard`'s
  other 3 methods, `InventoryPostingGuard`, `KorThresholdGuard`, ...) share
  the exact same "never registered, therefore never resolves" defect. Filed
  as shillinq#433; fixing all of them is a much larger, separate change.
- The 23 schemas declared at the wrong JSON nesting level
  (`components.<Schema>` instead of `components.schemas.<Schema>`, e.g.
  `APInvoice`, `ARInvoice`, `WBSOExportLog`, `WBSOActivityCode`), which
  OpenRegister's `ImportHandler` never imports at all. Two of my 13
  implemented guards (`GLReversalGuard` on APInvoice/ARInvoice,
  `WBSOExportValidationGuard`) target schemas affected by this — the code
  is correct and will function once that bug is fixed, but the schemas
  themselves are not reachable today. Filed as shillinq#434.
- Adding an approval-evidence field to `VatReturn`/`BcfClaim` so
  over-threshold submissions can ever be unblocked (no such field exists on
  either schema today). Filed as shillinq#435.
- Giving OpenRegister's aggregation engine an actual PHP-guard-fallback seam
  (it never reads `guard`/`fallbackGuard` keys today, on any aggregation).
  Filed as shillinq#436.

## Approach

See design.md for the full per-guard implement-vs-remove decision table and
rationale, and for the DI-registration root-cause writeup.

## New Dependencies

None.

## Impact

- `lib/Guard/*.php` — 9 new classes.
- `lib/Lifecycle/*.php` — 6 new classes (incl. the shared adapter) + 1 new
  method on the existing `PeriodCloseGuard`.
- `lib/AppInfo/Application.php` — 16 new `registerService()` wirings.
- `lib/Settings/shillinq_register.json` — 1 shape fix, 1 key relocation, 2
  dead-key removals, 2 description-text corrections.
- `lib/Settings/register.d/add-shillinq-rekenkamer-audit-pack.json` — 1
  dead-key removal.
- `phpstan.neon`, `psalm.xml` — cross-app interface allowlisting for the new
  adapter (mirrors the existing `CustomerBridgeMetricsService` precedent).
- `tests/stubs/OpenRegister/Lifecycle/*.php` — 2 new stub files.
- `tests/Unit/**` — 1 new regression-guard test + one test file per new
  guard/method.

## Cross-Project Dependencies

None — OpenRegister is consumed read-only (its `LifecycleGuardInterface`/
`GuardResult` contract), never modified.

## Risks

### Risk 1: The 16 new `registerService()` tags collide with a future OpenRegister-side fix
**Severity:** Low — **Mitigation:** if OpenRegister ever adds native
`Class::method` tag-splitting to `LifecycleGuardRegistry`, these explicit
registrations simply become redundant (the container checks its own
registered aliases first), not conflicting.

### Risk 2: `GLReversalGuard`/`WBSOExportValidationGuard` cannot be exercised end-to-end today
**Severity:** Low — **Mitigation:** documented explicitly in both classes'
docblocks and in this proposal's Out of Scope; the code is correct and unit
tests prove its logic, it is simply unreachable until shillinq#434 lands.

## Rollback Strategy

Remove the 16 `registerService()` calls from `Application.php` and delete
the new guard/adapter files; the register.d/JSON edits can be reverted with
`git revert`. No data migration — no schema or object shape changes.

## Open Questions

None.

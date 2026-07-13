# Design: bookkeeping-aansluitingen

## Architecture Overview

```
Aansluiting (definition, master data)
  { aansluitingType, sourceALabel/sourceBLabel, expectedRelationship,
    toleranceCents, controlAccountNumber?, subLedgerType? }
        |
        | AansluitingController::compute(aansluitingId, period_id)
        v
AansluitingService::compute()
    1. fetch Aansluiting definition
    2. fetch existing AansluitingResult(aansluitingId, periodId), if any
       -> if status != open, return unchanged (never clobber an explanation)
    3. match(aansluitingType):
         'btw-ledger-aangifte'  -> resolveBtwLedgerAangifte()
         'subledger-gl-control' -> resolveSubledgerGlControl()
    4. AansluitingCalculator::differenceCents(sourceATotal, sourceBTotal, relationship)
    5. AansluitingCalculator::isWithinTolerance(differenceCents, toleranceCents)
    6. persist AansluitingResult
         withinTolerance == true  -> status = resolved (auto), resolvedBy = 'system'
         withinTolerance == false -> status = open

resolveBtwLedgerAangifte(administrationId, periodId):
    findFiledVatReturn(administrationId, periodId)   [NEW helper, mirrors
                                                        IcpService::vatReturnPeriod()]
    VATReturnService::computeCurrentDeclarations()   [EXISTING, unchanged —
                                                        already built for
                                                        btw-suppletie-detection]
    VATReturnService::fetchFiledDeclarations()       [EXISTING, unchanged]
    AansluitingCalculator::diffBuckets()             [NEW — generic bucket diff]
    findRelatedVatCorrection(originalVatReturnId)    [NEW helper — cross-references
                                                        VatSuppletieDetectionService's
                                                        output, does not duplicate it]

resolveSubledgerGlControl(definition, administrationId):
    controlAccountBalance(administrationId, controlAccountNumber)
        -> GLTransaction + GLLine sum, ALL periods (cumulative), mirrors
           TrialBalanceService::movementsByAccount()'s query shape minus the
           periodId filter [NEW]
    openArInvoices(administrationId) | openApTransactions(administrationId)
        -> ARInvoice.lifecycleState in {issued,overdue,disputed}
           | APTransaction.state in {received,issued,partially-paid,overdue,disputed}
           [NEW — the exact comparison PeriodCloseAssistantService::
            detectOpenSubLedger() never makes; that method only counts
            draft/unposted GLTransactions]

AansluitingService::explain()/resolve()/reopen()
    explain:  open -> explained        (requires non-blank explanationReasonText)
    resolve:  explained -> resolved    (guard: AansluitingResolutionGuard::canResolve)
    reopen:   explained|resolved -> open
```

Why a `match()` dispatch on two hand-coded resolvers rather than a
declarative aggregation-string interpreter: see Decision 1 below.

## Nextcloud Integration

- Controllers: `OCA\Shillinq\Controller\AansluitingController` (new) — POST
  compute/explain/resolve/reopen, `#[NoAdminRequired]`, mirrors
  `IcpController`'s auth + validation + `run()`-wrapper shape exactly.
- Services: `OCA\Shillinq\Service\AansluitingService` (new, imperative
  orchestrator), `OCA\Shillinq\Service\AansluitingCalculator` (new, pure —
  mirrors `TrialBalanceCalculator`/`IcpCalculator`).
- Mappers/Entities: none — all persistence via
  `OCA\OpenRegister\Service\ObjectService`, matching every sibling service.
- Events/Hooks: none. `compute()` is invoked on demand (via the controller);
  a scheduled sweep across all active `Aansluiting` definitions is
  explicitly deferred (proposal.md Out of Scope).
- Lifecycle guard: `OCA\Shillinq\Lifecycle\AansluitingResolutionGuard` (new)
  — single method `canResolve()`, referenced from
  `AansluitingResult.x-openregister-lifecycle.transitions.resolve.guard`,
  mirroring `IcpFinalizeGuard`'s structure exactly.

## Security Considerations

Every `AansluitingController` endpoint requires an authenticated Nextcloud
user (`#[NoAdminRequired]` + in-body `requireUser()` guard, ADR-005).
Administration scope is always resolved from the `Aansluiting`
definition/`AansluitingResult` record itself (server-authoritative), never
from a client-supplied parameter — a caller cannot request a
recompute/explain/resolve against a different administration's data than the
one the target record already belongs to. IDOR safety mirrors
`ReconciliationResolutionController`: every write re-fetches the target
record by id first and validates its own state (status) before mutating; no
endpoint accepts an `administrationId` body parameter that could redirect a
write. `AansluitingResolutionGuard::canResolve()` fail-closes on any
exception (CWE-863), matching `IcpFinalizeGuard`. No new secrets, no new
external calls.

## File Structure

```
lib/
  Service/
    AansluitingCalculator.php           [NEW]
    AansluitingService.php              [NEW]
  Lifecycle/
    AansluitingResolutionGuard.php      [NEW]
  Controller/
    AansluitingController.php           [NEW]
  Settings/
    register.d/
      bookkeeping-aansluitingen.json    [NEW — Aansluiting + AansluitingResult schemas]
appinfo/
  routes.php                            [MODIFIED: +4 routes]
src/
  manifest.json                         [MODIFIED: +1 nav entry, +4 pages]
tests/Unit/
  Service/AansluitingCalculatorTest.php     [NEW]
  Service/AansluitingServiceTest.php        [NEW]
  Lifecycle/AansluitingResolutionGuardTest.php [NEW]
  Controller/AansluitingControllerTest.php  [NEW]
  Settings/AansluitingenFragmentTest.php    [NEW]
```

## Seed Data

Three `Aansluiting` definitions (one btw-ledger-aangifte, one
subledger-gl-control/AR/1300, one subledger-gl-control/AP/1600 —
demonstrating both `expectedRelationship` values) and three
`AansluitingResult` example objects, one per lifecycle status (`open`,
`resolved`, `explained`), in
`lib/Settings/register.d/bookkeeping-aansluitingen.json` `.objects`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Source-total resolution (both resolvers) | **Imperative** — `AansluitingService`'s two `resolve*()` methods, via the real `ObjectService` API | The BTW-ledger side needs the same per-line `vatRate`/`reverseCharge` derivation already documented in `bookkeeping-vat-btw-filing`'s notes as **not** expressible as a declarative aggregation against `GLLine` (the fields the declared aggregation would need don't exist on that schema) — `VATReturnService` computes it in PHP instead, and this change reuses that PHP method rather than re-deriving it declaratively. The subledger side needs a status-enum check that differs per schema (`ARInvoice.lifecycleState` vs. `APTransaction.state`) — cross-schema polymorphism the aggregation engine cannot express in one declared shape. This mirrors the exact same ADR-031 exception `TrialBalanceService`, `FluxService`, and `IcpService` already document for their own engine-side fallbacks. |
| The tolerance/diff decision + bucket-level drill-down diff | **Imperative** — `AansluitingCalculator` (pure, no OR dependency) | Comparing two independently-resolved totals against a declared tolerance and producing a signed, relationship-aware delta is exactly the shape `IcpCalculator::reconcile()` and `TrialBalanceCalculator::isBalanced()` already implement as pure PHP; no declarative "compare two aggregation outputs" primitive exists in the OR aggregation engine today. |
| The `open -> explained -> resolved` status-count rollups | **Declarative** — `x-openregister-aggregations.openCountByAdministration` / `openCountByAansluiting` on `AansluitingResult` | A straightforward single-schema `groupBy` + `count` + `filter`, the same shape `bookkeeping-reconciliation-reports`'s `varianceByAccount`/`reconciliationCount` aggregations already use successfully — no cross-schema join or status-enum polymorphism involved. |
| `explained -> resolved` field-presence gate | **Imperative** — `AansluitingResolutionGuard::canResolve()` (single method, ADR-031 exception) | "The explanation fields must be non-blank" is not yet expressible as a declarative lifecycle guard clause; the same exception `IcpFinalizeGuard` and `StatementVerifyGuard` already document for their own transition gates. |
| The GL control-account cumulative balance query | **Imperative**, deliberately **not** reusing `TrialBalanceService::compute()` | `TrialBalanceService::compute()` is single-period-scoped (movement + an *optional* single prior-period carry); a balance-sheet control account's tie-out target is its life-to-date cumulative balance. Rather than inventing multi-period opening-balance chaining (out of scope, and not needed anywhere else in the codebase today), `controlAccountBalance()` sums every non-eliminated `GLLine` for the account across all `GLTransaction`s directly — the same query shape `TrialBalanceService::movementsByAccount()` already uses, minus the `periodId` filter. This is simpler and more correct for this specific comparison than forcing the framework to depend on unimplemented cross-period chaining. |

No new PHP class exceeds the single-responsibility precedent already set by
`IcpService`/`IcpCalculator` (imperative orchestrator + pure calculator
pair) and `IcpFinalizeGuard` (single-method lifecycle guard) in this
codebase; this change adds one orchestrator, one pure calculator, one
guard, and one controller, per ADR-031.

## Risks / Trade-offs

- [Risk] The two resolvers are hand-coded rather than fully data-driven
  against a generic aggregation-string interpreter → [Mitigation] deliberate
  scope discipline — the codebase has no existing generic
  aggregation-invocation API in PHP for the framework to lean on, and
  building one speculatively for a framework with exactly two concrete
  instances would be premature generalisation. Adding a third resolver (per
  the four deferred aansluitingen) is a small, explicit, well-precedented
  addition to the `match()` dispatch.
- [Risk] `IcpService::reconcile()` already implements an ICP<->rubriek-3b
  tie-out outside this framework, and `bookkeeping-reconciliation-reports`
  already owns bank-balance tie-out, so "six gaps, one framework" is
  aspirational rather than fully realised in this change → [Mitigation]
  explicitly documented as a named follow-up (proposal.md Out of Scope /
  Open Questions) rather than silently duplicating either capability; no
  existing schema, lifecycle, or service is touched by this change.
- [Risk] A recompute silently returning the untouched `explained`/`resolved`
  result (rather than erroring) could surprise an operator who expected a
  fresh number → [Mitigation] `compute()` logs an info-level message
  ("skipping recompute... reopen() first") and the manifest detail page
  surfaces the current `status` + `computedAt` so staleness is visible;
  `reopen()` is a one-click operator action.

## Migration Plan

No data migration — purely additive: one new register fragment (two new
schemas), four new PHP classes, four new routes, and manifest additions. No
existing register.d fragment, schema field, lifecycle state/transition, or
service method is modified. Deploy: merge; OpenRegister picks up the new
`Aansluiting`/`AansluitingResult` schemas via the existing register.d
auto-merge on next settings load (no explicit migration step). Rollback:
revert the PR; no existing data is affected since nothing pre-existing was
changed.

## Open Questions

- Should `IcpService::reconcile()` eventually migrate onto
  `Aansluiting`/`AansluitingResult` for a unified operator dashboard?
  Deferred to the ICP<->rubriek-3b follow-up (proposal.md Open Questions).
- Should the bank-balance tie-out extend `bookkeeping-reconciliation-reports`
  in place or gain a read-only `AansluitingResult` projection? Deferred to
  the bank-balance follow-up (proposal.md Open Questions).

## Trade-offs

Considered building the source-total resolution as a fully generic,
data-driven interpreter (e.g. an `Aansluiting.sourceARef` string like
`"aggregation:trialBalanceByAccountPeriod"` vs.
`"service:VATReturnService::computeCurrentDeclarations"`, dispatched via
reflection). Rejected for this change: it would require inventing a
resolution/execution contract the OR aggregation engine does not itself
provide (there is no existing PHP-callable "run this named aggregation and
give me the totals" API to dispatch to — every sibling service in this
codebase, including `TrialBalanceService`, `FluxService`, and `IcpService`,
calls the real `ObjectService` `find`/`findAll` API directly rather than
invoking a named aggregation from PHP). A two-branch `match()` on
`aansluitingType` is simpler, explicit, fully typed, and exactly matches how
every other multi-shape service in this codebase (e.g.
`IcpFilingService::createCorrection()`'s type dispatch) is already written.
If a third/fourth resolver later justifies a shared execution contract, that
generalisation is a natural refactor once there is real variance to
abstract over — not before.

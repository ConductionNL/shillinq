---
kind: code
depends_on: []
---

# Proposal: fx-period-end-revaluation

## Summary

`SoftCloseExecutor::delegateFxRevaluation()` (`lib/Service/SoftCloseExecutor.php`
~line 406) already probes the DI container for
`OCA\Shillinq\Service\Treasury\FxRevaluationService` and delegates FX
revaluation + interest accruals to it per REQ-CLS-002 step 2 — but that class
has never existed. `lib/Service/Treasury/` only contains
`TreasuryRateService` (the rate-lookup facade) and `TreasuryRateSnapshot`
(the typed value object); no service ever consumes them for revaluation.
Every nightly soft-close run silently reports `fxPostings: 0`, forever, with
a debug-level log line nobody reads. This change builds the missing
`FxRevaluationService`: period-end mark-to-market revaluation of every open
`FXPosition` (AR/AP/bank/cash balances held in foreign currency) at the
administratie's functional-currency closing rate, posting the unrealised
koersverschil (gain/loss) as an auditable `FxRevaluationPosting` record that
`SoftCloseExecutor` can count — restoring the call-site to actually do what
its own docblock has claimed since `bookkeeping-soft-close-flux` shipped.

## Motivation

This is a verified correctness gap, not a new feature request: the call-site,
the DI probe, the return-shape contract (`{postingCount: int}`), and the
`fxPostings` field in `SoftCloseExecutor::execute()`'s report all already
exist and are already wired end-to-end — only the delegate itself is
missing. `openspec/specs/bookkeeping-multi-currency/spec.md` has zero
occurrences of "revalu", "unrealised", or "koersverschil" — the capability
that owns multi-currency exposure has never actually specified period-end
revaluation, even though `bookkeeping-continuous-close`'s REQ-CLS-002 and
REQ-CLS-010 both assume it happens. Any administratie with an open
`FXPosition` (the `bookkeeping-treasury-ihb` T3 capability already lets a
group-treasurer record one) is silently carrying a stale `unrealisedPL` and
an incomplete trial balance at every soft-close, with no error, no alert,
and no audit trail — a controller reviewing the nightly run report has no
signal that revaluation never ran.

## Affected Projects

- [x] Project: `shillinq` — new `FxRevaluationService`, new `FxRevaluationPosting`
  register schema, spec additions to `bookkeeping-multi-currency`.

## Scope

### In Scope

- `OCA\Shillinq\Service\Treasury\FxRevaluationService` satisfying the exact
  contract `SoftCloseExecutor::delegateFxRevaluation()` already expects:
  autowireable, a `reval(string $administrationId, string $periodId): array`
  method returning at least `{postingCount: int}`.
- Period-end mark-to-market revaluation of every open `FXPosition` for the
  administratie: resolve the administratie's `functionalCurrency`, resolve a
  closing rate via the existing `TreasuryRateService::getFxSpot()` (falling
  back to the position's own manually-maintained `spotRate` when the rate
  adapter is dormant — the exact fallback `LogTreasuryRateAdapter` already
  documents), compute the incremental unrealised movement since the last
  mark, update the `FXPosition` record, and persist an auditable
  `FxRevaluationPosting` record — with target/contra GL accounts — when
  the movement is material.
- New declarative `FxRevaluationPosting` register schema
  (`lib/Settings/register.d/fx-period-end-revaluation.json`) modeled on the
  existing `AutoAccrualPosting` audit-posting pattern (lifecycle,
  audit-trail, RBAC).
- Spec additions to `openspec/specs/bookkeeping-multi-currency/spec.md`
  (new REQ-MC-00x requirements covering period-end revaluation, functional
  currency, closing-rate resolution, and auditability).
- New PHPUnit coverage proving `SoftCloseExecutor::execute()['fxPostings']`
  is `> 0` when the delegate is present and a live/manual rate resolves —
  where before this change it was unconditionally `0`.

### Out of Scope

- Realised FX gain/loss on settlement (verified absent from the codebase —
  no `koersverschil`/`exchangeGainLoss`/`realisedGainLoss` hits anywhere in
  `lib/`) is a distinct capability and is deliberately NOT built here; this
  change is the UNREALISED period-end revaluation only.
- A generic automatic reversal/materialisation scheduler for
  `AutoAccrualPosting`-style records (the existing "reverse" lifecycle
  transition on `AutoAccrualPosting` is a manual, RBAC-gated controller
  action, not a nightly auto-firing job — no such job exists anywhere in
  this codebase today). Building one is out of scope; see design.md for how
  this change achieves period-over-period true-up without depending on it.
- Live rate-adapter binding (openconnector `treasury-rates` source) — the
  shipped `LogTreasuryRateAdapter` stays the default; this change only adds
  the consumer that will use a live adapter once one is bound.
- New manifest UI (index/detail pages for `FxRevaluationPosting`) — this is
  a backend audit-trail schema consumed by the existing `CnObjectSidebar`/
  generic OpenRegister browsing surfaces, not a new operator workflow.

## Approach

Add `FxRevaluationService` in the same `OCA\Shillinq\Service\Treasury`
namespace as `TreasuryRateService`, constructor-injecting
`TreasuryRateService` + `ContainerInterface` + `IAppConfig` + `LoggerInterface`
so NC's autowiring container resolves it exactly the way `TreasuryRateService`
itself is already resolved (no explicit `registerService()` call needed —
verified this is why `container->has()` correctly returns `false` today: the
class doesn't exist, not because it needs registration). Full technical
design (revaluation math, closing-rate fallback order, cumulative-vs-reversing
decision, GL account configuration) is in design.md.

## New Dependencies

None. Consumes only existing in-app services (`TreasuryRateService`,
OpenRegister `ObjectService`).

## Impact

- `lib/Service/Treasury/FxRevaluationService.php` — new.
- `lib/Settings/register.d/fx-period-end-revaluation.json` — new (declares
  `FxRevaluationPosting`).
- `lib/Service/SoftCloseExecutor.php` — no code change; its existing
  `delegateFxRevaluation()` call-site starts actually posting.
- `openspec/specs/bookkeeping-multi-currency/spec.md` — new requirements.
- `tests/Unit/Service/Treasury/FxRevaluationServiceTest.php` — new.
- `tests/Unit/Service/SoftCloseExecutorTest.php` — extended with a
  delegate-present scenario proving `fxPostings > 0`.

## Cross-Project Dependencies

None. Self-contained within `shillinq`; consumes `bookkeeping-treasury-ihb`'s
`FXPosition` schema and `bookkeeping-multi-administratie`'s
`Administration.functionalCurrency` field, both already shipped.

## Risks

### Risk 1: Dormant rate adapter means production still reports low/zero fxPostings
**Severity:** Low — **Mitigation:** This is correct, not a bug. The shipped
`LogTreasuryRateAdapter` is dormant by design until an openconnector
`treasury-rates` source is bound; with no live rate AND no manually
maintained `FXPosition.spotRate`, the service correctly skips (no bogus
zero-rate revaluation). The test suite proves the wiring and math are
correct using a live-adapter double and a manual-fallback fixture; the
correctness fix is "the call-site now actually runs and produces real
output when data is available," not "every deployment gets non-zero
postings immediately."

### Risk 2: GL account codes for the unrealised gain/loss postings are not yet modelled per-administratie
**Severity:** Low — **Mitigation:** Documented, configurable `IAppConfig`
defaults (see design.md) rather than hardcoded magic strings; a follow-up
change can add a full per-administratie `FxRevaluationPolicy` register if
per-currency GL mapping proves necessary. This mirrors how `register()`
already resolves the register slug from `IAppConfig` with a documented
fallback.

## Rollback Strategy

Revert the PR. `FxRevaluationPosting` is purely additive (new schema, no
migration of existing data); `SoftCloseExecutor`'s call-site reverts to its
current fail-safe `container->has() === false` branch (returns 0, logs
debug) the moment the class is removed — no code change needed there to roll
back.

## Open Questions

None — the call-site contract, the FXPosition data model, and the
adapter fallback behaviour are all already fully specified by existing
shipped code; this change fills the one missing piece.

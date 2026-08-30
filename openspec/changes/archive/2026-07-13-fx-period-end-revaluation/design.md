# Design: fx-period-end-revaluation

## Architecture Overview

```
SoftCloseExecutor::execute()                         (unchanged — already wired)
  │  Step 2
  ▼
SoftCloseExecutor::delegateFxRevaluation(admin, period)  (unchanged — lib/Service/SoftCloseExecutor.php:406)
  │  container->has('OCA\Shillinq\Service\Treasury\FxRevaluationService')  → NOW true
  │  $delegate->reval($administrationId, $periodId)
  ▼
FxRevaluationService::reval()                          (NEW — lib/Service/Treasury/FxRevaluationService.php)
  │
  ├─ 1. resolve Administration.functionalCurrency (ObjectService findAll, schema Administration)
  ├─ 2. resolve period-end date from "yyyy-mm" periodId (last day of month)
  ├─ 3. findAll FXPosition WHERE administrationId = $administrationId
  ├─ 4. for each position with foreignCurrency != functionalCurrency and position != 0:
  │       a. TreasuryRateService::getFxSpot(foreignCurrency, functionalCurrency, periodEndDate)
  │       b. if snapshot.isLive()  → closingRate = snapshot value  (rateSource: live)
  │          elseif position.spotRate is set and > 0 → closingRate = position.spotRate  (rateSource: manual-fallback)
  │          else → skip position, log, no posting
  │       c. if position.spotRate was never set (first mark) → establish baseline only, no GL posting
  │       d. else compute delta = position.position × (closingRate − priorSpotRate)
  │          if |delta| below 1-cent materiality → refresh FXPosition only, no posting
  │          else → persist FxRevaluationPosting (audit) + update FXPosition (spotRate, fairValue,
  │               cumulative unrealisedPL, lastUpdated)
  │
  ▼
  return {postingCount, positionsEvaluated, functionalCurrency, periodId}
```

`FxRevaluationService` lives in the same `OCA\Shillinq\Service\Treasury`
namespace as `TreasuryRateService` and is constructor-injected with it —
NC's autowiring container resolves both without an explicit
`registerService()` call (verified: neither `TreasuryRateService` nor
`TreasuryRateSnapshot` has one either; only the interface it depends on,
`TreasuryRateAdapterInterface`, is explicitly bound in
`Application.php:509`). This is why `container->has('...FxRevaluationService')`
was `false` before this change (the class didn't exist — autoloader
resolution failure) and becomes `true` once the class exists and is
constructor-autowireable.

## Closing-rate resolution order

1. **Live adapter** — `TreasuryRateService::getFxSpot($foreignCurrency,
   $functionalCurrency, $periodEndDate)`. Live once an openconnector
   `treasury-rates` source is bound (out of scope here).
2. **Manual fallback** — `FXPosition.spotRate`, the field a group-treasurer
   already maintains by hand (`x-openregister-rbac` grants `group-treasurer`
   create/read/update on `FXPosition`). `LogTreasuryRateAdapter`'s own
   docblock says this explicitly: *"until then, FXPosition.spotRate
   manual-entry path carries the v1 value"* — this design implements exactly
   that documented fallback, not a new invention.
3. **Neither available** — skip the position for this run (log at info
   level, no posting, no error). A position with no rate source is not a
   soft-close failure; it is an incomplete data problem the treasurer needs
   to fix, and `SoftCloseExecutor` must not halt the whole nightly run over
   one unrevaluable position (consistent with REQ-CLS-002's "IFRS 16 not
   implemented yet is non-fatal" precedent already in the executor).

## Reversing-entry vs cumulative-adjustment decision

**Decision: cumulative-adjustment, computed as period-over-period delta.**
No literal "reversing" `JournalEntry` is posted for FX revaluation.

Rationale:

1. **`FXPosition` is already a cumulative model.** Its own schema
   (`lib/Settings/register.d/bookkeeping-treasury-ihb.json:915-966`) defines
   `unrealisedPL` as *"Unrealised gain/loss in reporting currency since
   entry (derived)"* and `fairValue` as *"derived: position × spotRate"* —
   both are running, cumulative-since-inception values, not per-period
   deltas. Treating GL posting as reversing-then-restating each period would
   fight the data model instead of matching it.
2. **No automatic reversal scheduler exists in this codebase today.**
   `AutoAccrualPosting.reversalState` (`posted → reversed`) is a **manual,
   RBAC-gated** `x-openregister-lifecycle` transition — a controller
   explicitly reverses a mis-posted accrual; there is no nightly job in
   `lib/BackgroundJob/` that auto-fires reversals on the first of the month.
   `bookkeeping-continuous-close`'s spec.md describes an aspirational
   auto-reversal scenario (REQ-CLS-003) that has no corresponding
   implementation anywhere in `lib/`. Building a generic scheduled-reversal
   engine is out of this change's scope (see proposal.md Out of Scope); this
   design does not create a new implicit dependency on one.
3. **Delta-based cumulative posting is mathematically equivalent and
   simpler to audit.** Each period, `FxRevaluationService` posts only the
   *incremental* movement since the position's last recorded `spotRate`:
   `delta = position × (newRate − priorRate)`. `FXPosition.unrealisedPL` is
   updated as a running total: `newUnrealisedPL = priorUnrealisedPL + delta`.
   Summed over any span of periods, the total posted equals the total
   unrealised P&L since the position's first mark — identical to what a
   reverse-then-restate scheme would produce, without depending on a
   reversal job that doesn't exist, and without ever double-counting a
   balance that was never reversed.
4. **First-time valuation posts nothing.** When `FXPosition.spotRate` is
   `null` (no prior mark exists — no baseline to compute a delta against),
   the service sets the baseline (`spotRate`, `fairValue`) and records
   `unrealisedPL: 0` without creating an `FxRevaluationPosting`. This avoids
   fabricating a spurious "gain" out of the absence of history.

`FxRevaluationPosting` still carries a `reversalState`
(`posted`/`reversed`) + `reversalId` field, mirroring `AutoAccrualPosting`,
so a controller CAN manually reverse an erroneous automated posting (audit
correction workflow) — that manual-correction capability is orthogonal to
the period-over-period true-up mechanism described above.

## GL account configuration

`FXPosition` carries no GL account reference (verified: its schema has no
`accountNumber`/`glAccount` field), and — matching the existing
`AutoAccrualPosting` precedent in this same executor (`persistAccrualPosting`
never creates an actual `JournalEntry` object; it synthesises a deterministic
`journalEntryId` string as an audit-trail linkage) — `FxRevaluationPosting`
follows the identical pattern: an append-only audit record with a
synthesised `journalEntryId`, not a literal `JournalEntry` write. The GL
account codes the posting is *attributed to* are read from `IAppConfig` with
documented defaults, mirroring how `SoftCloseExecutor::register()` already
resolves the register slug the same way:

| Config key | Default | Purpose |
|---|---|---|
| `fx_revaluation_gain_account` | `8020` | Unrealised FX gain (P&L) |
| `fx_revaluation_loss_account` | `8021` | Unrealised FX loss (P&L) |
| `fx_revaluation_adjustment_account` | `1699` | Balance-sheet FX translation adjustment (contra) |

A follow-up change can promote this to a full per-administratie /
per-currency `FxRevaluationPolicy` register if real deployments need
account mapping finer than "one gain account + one loss account +
one contra account" — deliberately deferred (see proposal.md Out of Scope
Risk 2).

## Seed Data

No new seed objects are required for `FxRevaluationPosting` — it is an
append-only audit record produced only by `FxRevaluationService::reval()`
at soft-close time, never hand-authored. The existing
`bookkeeping-treasury-ihb.json` seed already ships three `FXContract`
objects (`fx-forward-usd-cashflow`, `fx-spot-gbp-fairvalue`,
`fx-swap-chf-netinvestment`) but zero `FXPosition` seed objects — so a
fresh install has no open positions to revalue until a group-treasurer
records one (or a future change seeds a demo `FXPosition`). This is
intentional: fabricating a demo `FXPosition` here would be scope creep on a
correctness-fix change, and would also fabricate a demo revaluation
posting with a made-up rate. `tests/Unit/Service/Treasury/FxRevaluationServiceTest.php`
covers the full behaviour with explicit test fixtures instead.

## Declarative-vs-imperative decision (ADR-031)

- **Declarative:** the `FXPosition` schema (existing, unmodified) and the
  new `FxRevaluationPosting` schema (`lib/Settings/register.d/fx-period-end-revaluation.json`)
  — both plain OpenRegister objects with `x-openregister-lifecycle`,
  `x-openregister-audit-trail`, and `x-openregister-rbac` blocks. The
  existing `FXPosition.x-openregister-aggregations.groupTotal` (consolidated
  group position per currency) is untouched and continues to sum the
  `fairValue`/`unrealisedPL` fields this service now actually keeps current.
- **Imperative (ADR-031 exception):** `FxRevaluationService::reval()` itself.
  Justification, per the same exception category `bookkeeping-treasury-ihb`'s
  own design.md already claims for `TreasuryRateService` (external
  rate-adapter orchestration) and `SoftCloseExecutor` already claims for
  itself (scheduled bulk work / period-close orchestration): period-end
  revaluation requires (a) an external-rate lookup with a stateful
  live/dormant branch that cannot be expressed as a single OR calculation
  formula, (b) iteration + conditional posting across an unbounded set of
  `FXPosition` records for one administratie, and (c) reading a sibling
  schema (`Administration.functionalCurrency`) to parameterise the
  calculation per administratie. This is exactly the "scheduled bulk work"
  + "external integration" exception ADR-031 already permits, not a new
  category of imperative logic in this codebase.

## Trade-offs

- **Delta-based cumulative posting (chosen) vs literal reversing JournalEntry
  (rejected):** the reversing-entry approach is the textbook IAS 8 pattern
  and is what REQ-CLS-003's accrual scenario documents for rent accruals —
  but it depends on an automatic month-start reversal firing, which does not
  exist anywhere in this codebase (see decision above). Building that
  scheduler to support one delegate would be a large, separately-scoped
  change; the delta-based approach is mathematically equivalent and ships
  today using only patterns already proven in this file (`markSoftClosed`'s
  find-or-create-then-save).
- **`IAppConfig` GL account defaults (chosen) vs new `FxRevaluationPolicy`
  register (rejected for this change):** a full policy register is more
  correct long-term (per-currency, per-administratie account mapping) but
  is unjustified scope for a change whose primary goal is "make the
  existing call-site stop returning 0." Documented as Risk 2 + explicitly
  deferred.

## Migration Plan

Purely additive — no existing data changes shape. `FxRevaluationPosting` is
a brand-new schema; `FXPosition` records gain no new required fields (the
service only ever writes to `spotRate`, `fairValue`, `unrealisedPL`,
`lastUpdated`, all of which already exist and are already nullable/derived).
No backfill needed; the first soft-close run after deploy establishes the
baseline mark for any existing `FXPosition` with a null `spotRate` and posts
nothing for that first run (see decision point 4 above).

## Open Questions

None.

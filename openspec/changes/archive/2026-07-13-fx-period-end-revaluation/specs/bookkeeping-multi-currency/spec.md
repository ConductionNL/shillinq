# bookkeeping-multi-currency Specification (delta)

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- `fx-period-end-revaluation` — adds period-end unrealised FX revaluation of
  open `FXPosition` balances, closing-rate resolution with manual-entry
  fallback, and auditable `FxRevaluationPosting` records
  (REQ-MC-006, REQ-MC-007, REQ-MC-008).

## Purpose

Extends `bookkeeping-multi-currency` from balance *tracking* (`CurrencyBalance`
snapshots, `BankAccount.primaryCurrency`) to balance *revaluation*: at
period-end, every open foreign-currency monetary position (AR/AP, bank/cash,
via the `bookkeeping-treasury-ihb` `FXPosition` schema) must be marked to the
administratie's functional-currency closing rate, with the unrealised
gain/loss (koersverschil) posted as an auditable record that
`SoftCloseExecutor` counts in its nightly report (REQ-CLS-002, REQ-CLS-010).

## ADDED Requirements

### Requirement: REQ-MC-006 — Period-end FX revaluation SHALL mark every open `FXPosition` to the administratie's functional-currency closing rate

The system MUST implement `FxRevaluationService::reval(string $administrationId, string $periodId): array` so that, for every `FXPosition`
record belonging to `$administrationId` whose `foreignCurrency` differs from
the administratie's `Administration.functionalCurrency` and whose `position`
is non-zero:

1. Resolve the period-end date from `$periodId` (`"yyyy-mm"` → last calendar
   day of that month).
2. Resolve a closing rate per REQ-MC-007.
3. Compute the incremental unrealised movement since the position's last
   recorded `spotRate`: `delta = position × (closingRate − priorSpotRate)`.
4. Update `FXPosition.spotRate`, `.fairValue` (`position × closingRate`),
   `.unrealisedPL` (`priorUnrealisedPL + delta`), and `.lastUpdated`.
5. When `priorSpotRate` was previously unset (no baseline exists yet), only
   establish the baseline (`spotRate`, `fairValue`, `unrealisedPL: 0`) —
   MUST NOT post a movement for a position with no prior mark.
6. When `|delta|` is material (≥ one cent in the functional currency), post
   an `FxRevaluationPosting` audit record (REQ-MC-008); when immaterial or
   zero, update the `FXPosition` mark only, without posting.

The method MUST return `array{postingCount: int, positionsEvaluated: int,
functionalCurrency: string, periodId: string}` — `postingCount` is exactly
the count of `FxRevaluationPosting` records created during the call. This is
the exact return shape `SoftCloseExecutor::delegateFxRevaluation()`
(`lib/Service/SoftCloseExecutor.php`) already reads via `$result['postingCount']`.

#### Scenario: Open USD position revalues at period-end and posts a gain

- **GIVEN** administratie `adm-holding-nl` (`functionalCurrency: "EUR"`) has
  an `FXPosition` `{foreignCurrency:"USD", position:100000, spotRate:0.90,
  fairValue:90000, unrealisedPL:0}`
- **WHEN** `reval("adm-holding-nl", "2026-03")` runs and the closing rate
  resolves to `0.93`
- **THEN** the position's `fairValue` MUST become `93000`, `unrealisedPL`
  MUST become `3000`, `spotRate` MUST become `0.93`
- **AND** exactly one `FxRevaluationPosting` MUST be created with
  `unrealisedDeltaCents: 300000`, `direction: "gain"`
- **AND** the returned `postingCount` MUST be `1`

#### Scenario: New position with no prior mark establishes baseline, posts nothing

- **GIVEN** an `FXPosition` with `spotRate: null`, `position: 50000`,
  `foreignCurrency: "GBP"`
- **WHEN** `reval()` runs and a closing rate resolves
- **THEN** `spotRate` and `fairValue` MUST be set to the closing rate's mark
  and `position × closingRate`
- **AND** no `FxRevaluationPosting` MUST be created for this position
- **AND** this position MUST NOT contribute to the returned `postingCount`

#### Scenario: Immaterial movement updates the mark but does not post

- **GIVEN** an `FXPosition` whose recomputed `delta` is `0.004` (functional
  currency, below one-cent materiality)
- **WHEN** `reval()` runs
- **THEN** `FXPosition.spotRate`/`fairValue` MUST still refresh to the new
  rate
- **AND** no `FxRevaluationPosting` MUST be created
- **AND** `postingCount` MUST NOT include this position

### Requirement: REQ-MC-007 — Closing-rate resolution SHALL prefer a live rate snapshot and fall back to the position's manually-maintained `spotRate`, never fabricating a rate

`FxRevaluationService` MUST resolve the closing rate for one `FXPosition` in
this order:

1. `TreasuryRateService::getFxSpot(foreignCurrency, functionalCurrency,
   periodEndDate)` — used when `TreasuryRateSnapshot::isLive()` is `true`.
2. `FXPosition.spotRate` (the group-treasurer's own manually-maintained
   value) — used when the snapshot is dormant AND `FXPosition.spotRate` is
   set and greater than zero. This is the exact fallback
   `LogTreasuryRateAdapter`'s own docblock documents ("FXPosition.spotRate
   manual-entry path carries the v1 value").
3. Neither available — the position MUST be skipped for this run (no
   posting, no `FXPosition` mutation, an info-level log entry) and MUST NOT
   cause `reval()` to throw or halt processing of the remaining positions.

#### Scenario: Dormant rate adapter falls back to the manually-maintained spotRate

- **GIVEN** the default `LogTreasuryRateAdapter` is bound (dormant,
  `isDormant(): true`) and an `FXPosition` has `spotRate: 0.86` set by a
  group-treasurer
- **WHEN** `reval()` runs
- **THEN** the closing rate used MUST be `0.86` and the resulting
  `FxRevaluationPosting.rateSource` MUST be `"manual-fallback"`

#### Scenario: No live rate and no manual spotRate skips the position without failing the run

- **GIVEN** the rate adapter is dormant and an `FXPosition` has `spotRate:
  null`
- **WHEN** `reval()` runs for an administratie with two other revaluable
  positions
- **THEN** the unrevaluable position MUST be skipped (no exception raised)
- **AND** the other two positions MUST still be processed and counted
  normally

### Requirement: REQ-MC-008 — Every `FxRevaluationPosting` SHALL be auditable to source position, rate, and posting attribution, and SHALL make `SoftCloseExecutor.fxPostings` observably non-zero

The `FxRevaluationPosting` register (declarative, `x-openregister-audit-trail: true`) MUST carry, per record: `administrationId`, `periodId`, `positionId`
(FK to the source `FXPosition`), `foreignCurrency`, `functionalCurrency`,
`netPosition`, `priorRate` (nullable), `closingRate`, `rateSource` (`live` |
`manual-fallback`), `unrealisedDeltaCents`, `direction` (`gain` | `loss`),
`targetGLAccount`, `contraGLAccount`, `journalEntryId`, `postedAt`,
`postedBy`, `reversalId` (nullable), `reversalState` (`posted` | `reversed`).

`SoftCloseExecutor::execute()`'s `fxPostings` report field, which was
unconditionally `0` before this change (the delegate class did not exist),
MUST be `> 0` for any administratie/period where at least one `FXPosition`
produces a material revaluation movement per REQ-MC-006.

#### Scenario: SoftCloseExecutor reports non-zero fxPostings when a revaluation posts

- **GIVEN** `FxRevaluationService` is bound in the DI container and an
  administratie has one `FXPosition` with a material period-end movement
- **WHEN** `SoftCloseExecutor::execute($administrationId, $periodId, $asOf)`
  runs
- **THEN** the returned report's `fxPostings` MUST be `≥ 1` and MUST equal
  the delegate's `postingCount`
- **AND** `postingCount` (the run total) MUST include `fxPostings`

#### Scenario: A controller can trace a posting back to its source position and rate

- **GIVEN** an `FxRevaluationPosting` created by a prior soft-close run
- **WHEN** an auditor inspects it
- **THEN** `positionId` MUST resolve to the exact `FXPosition` revalued,
  `priorRate`/`closingRate`/`rateSource` MUST explain the computed delta,
  and `postedBy` MUST be `FxRevaluationService::SYSTEM_ACTOR`
  (`"SYSTEM:FxRevaluationService"` — REQ-CLS-010 permits either the
  orchestrator or "specific service" as the posting actor; this change
  attributes to the specific service that computed the revaluation)

@e2e exclude pure backend: FX revaluation posting is nightly soft-close orchestration logic with no operator-facing UI in this change — not browser-testable; covered by PHPUnit (`tests/Unit/Service/Treasury/FxRevaluationServiceTest.php`, `tests/Unit/Service/SoftCloseExecutorTest.php`).

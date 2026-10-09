# Change: budget-projection-engine

## Why

`budget-core-schema` gave `BudgetLine` twelve monthly amount fields and a
`source` enum (`manual|contract|recurring|projected|scenario`) — but
declared, not implemented, every value except `manual`. The task brief for
the begroting programme is explicit about what an operator needs to see on
each grootboek (ledger account) or verzamelpost (`LedgerGroup`): **actual,
projected and begroot (budgeted), each as a trend (per period) and a
cumulative series** — and that "projected" specifically means "average
growth in % extrapolated" from the trailing 12 months, not a guess and not
a straight-line continuation of the last value.

Nobody has pinned that arithmetic down. "Average growth, extrapolated" is
one sentence with at least six unstated decisions inside it: growth of
what (movement or balance — these are different questions for a P&L
account than a balance-sheet account), growth over what window, what a
zero or negative month does to a percentage, how many data points are too
few to trust, whether one outlier month should be allowed to dominate the
whole forward curve, and whether a verzamelpost's projection sums its
members or fits its own aggregate history. Left vague, this either ships
as a plausible-looking number nobody can audit, or as a `NaN`/`Infinity`
the first time a ledger account has a zero month — which, given
`debitMovement`/`creditMovement` are both `minimum: 0` fields that
legitimately hit zero on quiet accounts, is not a rare edge case.

This change specs (and implements as a pure, PHPUnit-tested calculator +
a query-budgeted reader) the projection engine: the exact growth-rate
arithmetic, its degenerate-case rules, the account-level vs. group-level
decision, the actual/projected seam, and the cumulative variant — for
both individual ledger accounts and `LedgerGroup` verzamelposten. It does
**not** build the grid, the charts, the known-cost derivation, or
scenarios — each is named as a non-goal below, owned by its own sibling
change.

## What Changes

- **ADD** (service, no schema change): `BudgetProjectionReader` — the only
  class that talks to OpenRegister; batches `Account`, `GLTransaction`,
  `GLLine`, and `LedgerGroup` reads into a fixed, small query count
  (target: ≤4 `findAll()` calls total, independent of how many accounts,
  groups, or months are requested), reusing the
  `BbvProgrammeBudgetReader::spendByProgramme()` in-memory-join idiom
  (`design.md` §5).
- **ADD**: `BudgetProjectionCalculator` — a pure class (no store access,
  mirroring `BbvProgrammeBudgetCalculator`'s "it reads NOTHING" docblock
  convention) implementing the growth-rate arithmetic, its degenerate-case
  rules, the account/group decision, the seam rule, and both trend and
  cumulative series construction (`design.md` §1–§4).
- **ADD**: `BudgetProjectionService` — thin orchestrator (reader +
  calculator), the integration point `budget-grid-view`/`budget-charts`
  call into; mirrors the existing `BbvProgrammeBudgetService` split.
- **ADD**: PHPUnit coverage for every degenerate case named in the task
  brief — zero months, negative months, fewer-than-minimum data points, a
  single outlier — plus the query-count assertion (`design.md` §7).
- **No schema change.** `BudgetLine.source = "projected"` is already
  declared by `budget-core-schema`; whether this engine writes that value
  back onto a `BudgetLine` row or only serves a computed series is an open
  product question (`design.md` §8, open question 1) — this change ships
  the read/compute path either way and does not block on that answer.
- **No UI, no pages, no manifest edit.** The one browser-visible
  requirement the task brief names (a projected column/series rendering
  distinctly from actuals) belongs to `budget-grid-view`/`budget-charts`;
  this change's own e2e coverage is almost entirely `@e2e exclude`
  (PHPUnit-provable numeric engine), cross-referencing rather than
  duplicating that future assertion (`design.md` §7).
- **Non-goals, each naming its owning change**: the spreadsheet-grid UI
  and any rendering of these series (`budget-grid-view`), chart components
  (`budget-charts`), `begroot`-from-known-cost derivation
  (`budget-known-costs`), and scenario/modifier support
  (`budget-scenarios`). This change also does not patch
  `AggregationAnnotationValidator` (openregister/foundation-repo scope,
  already out of bounds per `budget-core-schema design.md` §6a) and does
  not touch `budget-core-schema`'s own files — its `BudgetVsActualsReader`
  and this change's `BudgetProjectionReader` independently resolve
  `LedgerGroup` membership rather than sharing a class, precisely so this
  change does not redesign or edit a sibling that is already authored
  (`design.md` §5c names the duplication and the follow-up refactor this
  is deferring).

## Impact

- **Affected specs**: new capability `budget-projection-engine`
  (`specs/budget-projection-engine/spec.md`). No existing capability spec
  is modified — this change adds services, not schema or consumer
  renames.
- **Affected code**: 3 new PHP classes (`lib/Service/BudgetProjectionReader.php`,
  `lib/Service/BudgetProjectionCalculator.php`,
  `lib/Service/BudgetProjectionService.php`) + PHPUnit coverage
  (`tests/Unit/Service/BudgetProjectionReaderTest.php`,
  `tests/Unit/Service/BudgetProjectionCalculatorTest.php`,
  `tests/Unit/Service/BudgetProjectionServiceTest.php`). No `register.d/`
  edit, no manifest edit, no new Playwright spec of its own (see
  `design.md` §7 for the cross-reference).
- **Dependencies**: reads `Account`, `GLTransaction`, `GLLine`
  (bookkeeping foundation schemas, already live) and `LedgerGroup`,
  `AnnualBudget`, `BudgetLine` (`budget-core-schema`, sibling change — this
  change assumes those schemas exist as designed there and does not
  redefine them). No dependency on `TrialBalanceLine` as a queryable
  schema — see `design.md` §5a for why (its rows are not persisted;
  `TrialBalanceService`'s own docblock is the source for this).
- **No cross-repo impact.**

# Change: budget-scenarios

## Why

The user's own requirement, verbatim in substance: *"We should be able to
cluster these modifiers into scenarios but only one scenario can be
default. But it should for example be possible to make a scenario where we
sell product x — that means employee a+b leave the org, recurring drops x
from date x, and amount X gets transferred to the bank at date X."*

Nothing in the begroting programme so far lets an operator model a what-if
without editing real data. `budget-core-schema` gives `BudgetLine` a
`source: "scenario"` enum value nobody populates; `budget-known-costs`
gives shillinq one dated, GL-linked cost primitive (`CashflowRecurring`,
optionally tagged to a `Contract`); neither lets an operator ask "what if
this cost ended on date X, and this much cash moved on date Y — how does
the begroting change, without touching the real numbers." This change is
that: a named cluster of dated modifiers, compared side-by-side against the
real budget, never mutating it.

### What already exists that this change reuses, verified at HEAD

- **`CashflowScenario`** (`lib/Settings/register.d/zzp-cashflow-13wk.json`,
  capability `bookkeeping-cashflow-13wk`) is the wrong shape to generalise
  blindly, checked field-by-field: it is bound to one `horizonId` (a
  13-week `CashflowForecastHorizon`), its `aanpassingen[].weeks` are **week
  numbers**, not dates, its `type` enum is closed to four cashflow-specific
  values (`AR_PROJECTION_OVERRIDE`/`RECURRING_COST_ADJUSTMENT`/
  `NEW_REVENUE`/`BUFFER_POLICY_OVERRIDE`), it has **no `isDefault` field at
  all**, and its declared `result` object (`minBufferAmount`/
  `minBufferWeek`/`onderschrijdingBuffer`/`actiesuggesties`) has **no
  producer anywhere in `lib/`** — verified by grep, mirroring the identical
  "declared but never expanded" gap `budget-known-costs proposal.md`
  independently found for `CashflowRecurring` itself. `design.md` §1
  decides, explicitly, not to extend this schema (a week-numbered,
  horizon-bound shape cannot express a month-based, `AnnualBudget`-wide
  what-if without redesigning fields this change does not own) and not to
  migrate it (§1b explains why no migrator is needed — the two schemas
  never collide and no live data moves between them).
- **`src/components/cashflow/ScenarioCreator.vue`** (231 lines, a working
  adjustment-builder UI for `CashflowScenario`) is **not registered in
  `src/registry.js`** — grepped, confirmed dead code, referenced nowhere
  else in `src/`. Its form-per-adjustment-type pattern (a `<select>` for
  `type`, conditionally rendered fields per type) is a useful UI precedent
  to follow in *shape*, not a component this change reuses directly (it is
  wired to `CashflowScenario`'s own fields).
- **`BegrotingswijzigingStacker::currentStand()`**
  (`lib/Service/BegrotingswijzigingStacker.php`) is the exact "base +
  dated, determined modifiers, no persistence" evaluator the task brief
  points at: it starts from a `basisTaskFields` array, applies every
  `determined`-status `Begrotingswijziging`'s `movements[]` (each keyed by
  `taskFieldCode`, each carrying signed `baten_delta`/`lasten_delta`), and
  returns the effective stand — in integer cents internally, so reversals
  net out exactly. Read in full; `design.md` §3 generalises its shape
  (stack dated deltas onto a base, pure, no I/O) to `BudgetScenario`'s
  `LedgerGroup`/month grain, without redesigning or editing that class —
  `BudgetScenarioEvaluator` is a **new** class following the identical
  pattern, not an edit to `BegrotingswijzigingStacker` itself (a different
  capability, `bookkeeping-programmabegroting`, out of this change's own
  scope).
- **`budget-known-costs`'s `contractReference`-taggable `CashflowRecurring`
  primitive and its pure `KnownCostScheduleExpander`** — this change's
  modifiers target that exact primitive by `recurId` (§ "REUSE" below) and
  its evaluator calls that exact pure calculator to compute a hypothetical
  schedule, so a scenario's projected numbers use the identical arithmetic
  a real regeneration would produce — no second schedule-math
  implementation is written here.

## What Changes

- **ADD** (schema): `BudgetScenario` — a new schema, not an extension of
  `CashflowScenario` (§ Why). `administrationId`, `name`, `description`,
  `isDefault` (boolean), a `draft→active→archived` status lifecycle.
  `design.md` §2.
- **DECISION, stated explicitly**: `CashflowScenario` is **kept, unmodified,
  not migrated**. It serves a different domain (13-week cash horizon,
  week-numbered) with no live-data collision against `BudgetScenario`
  (different slug, no shared objects) — `design.md` §1b gives the
  `SubsidieOrderConsolidationMigrator`-style reasoning for why no migrator
  is warranted here, more simply than that precedent's own case. Its own
  `result`-has-no-producer gap and `ScenarioCreator.vue`'s dead-code status
  are **recorded as findings, handed to the orchestrator, not fixed here**
  — a different capability's pre-existing defect, out of this change's own
  scope. `design.md` §1a, §12.
- **ADD** (schema): `BudgetScenarioModifier` — one dated modifier belonging
  to one `BudgetScenario`: `RECURRING_END` (a `CashflowRecurring` row's
  schedule hypothetically capped at a date), `RECURRING_AMOUNT_CHANGE` (a
  `CashflowRecurring` row's amount hypothetically replaced from a date), or
  `LEDGER_AMOUNT_DELTA` (a one-off signed adjustment to one `LedgerGroup`
  for one month — "amount X transferred... at date X"). `design.md` §4.
- **ADD** (seed data, RULING 1, 2026-08-20): one balance-sheet `LedgerGroup`
  ("Liquide middelen," `accountRange: 1000-1099`, sourced from
  `rj270-balance-sheet.json`'s own `VLA-LIQ` section), seeded in this
  change's own fragment against `budget-core-schema`'s already-defined
  `LedgerGroup` schema — the minimal target `LEDGER_AMOUNT_DELTA` needs to
  be usable at all, since `budget-core-schema`'s default seed is P&L-only.
  **This owned and closed here, not in `budget-known-costs`**, because the
  need originates entirely from this change's own `LEDGER_AMOUNT_DELTA`
  modifier — `budget-known-costs` has no use for a balance-sheet
  `LedgerGroup` anywhere in its own scope. **Explicitly not a reversal of**
  `budget-core-schema`'s P&L-only default seed — one leaf only, no
  balance-sheet hierarchy restored. `design.md` §4c.
- **ADD** (guard): `BudgetScenarioModifierGuard` — rejects two modifiers in
  the same scenario targeting the same `CashflowRecurring` row with
  overlapping effective windows (an unresolvable conflict); otherwise
  modifiers within a scenario are additive and order-independent.
  `design.md` §5.
- **ADD** (service): `BudgetScenarioDefaultPromoter` — enforces "only one
  scenario can be default" per administration, atomically demoting the
  previous default rather than rejecting the promotion (a deliberately
  different enforcement style than `budget-core-schema`'s
  `AnnualBudgetDefaultGuard`, justified in `design.md` §3 — a scenario
  default is a low-stakes UI preference, not a fiscal-year commitment).
  Defines the zero-default state explicitly: no scenario overlay applied,
  the real `AnnualBudget`/`BudgetLine` data shown as-is. `design.md` §3.
- **ADD** (service): `BudgetScenarioEvaluator` — pure, non-destructive:
  never writes to `BudgetLine`; computes a side-by-side
  `(ledgerGroupId, month) => {base, scenario, delta}` comparison by
  starting from the real `BudgetLine` data and applying every modifier in
  a scenario, calling `budget-known-costs`'s own pure
  `KnownCostScheduleExpander` for `RECURRING_*` modifiers and summing
  `LEDGER_AMOUNT_DELTA` modifiers directly — modelled on
  `BegrotingswijzigingStacker::currentStand()`'s exact "base + stacked
  dated deltas, no I/O" shape. `design.md` §6.
- **ADD** (pages): minimal index/detail for `BudgetScenario`/
  `BudgetScenarioModifier`, plus a scenario-comparison page. `design.md`
  §9.
- **Non-goals, each naming its owning change** (`design.md` §12): the
  dated planned-cost primitive itself (`budget-known-costs` owns it, this
  change only points modifiers at it), the spreadsheet grid
  (`budget-grid-view`), projection math (`budget-projection-engine`),
  charts (`budget-charts`), payroll/HR concepts (explicitly out of scope —
  "employee leaves" is modelled purely as "these `CashflowRecurring` rows
  end at date X," never as an HR entity; payroll belongs to `hrmq`), any
  fix to `CashflowScenario`'s own `result`-has-no-producer gap or
  `ScenarioCreator.vue`'s dead-code status.

## Impact

- **Affected specs**: new capability `budget-scenarios`
  (`specs/budget-scenarios/spec.md`). No existing capability spec is
  modified — `CashflowScenario` is untouched (§ Why), so unlike
  `budget-known-costs` this change carries no MODIFIED delta against
  `bookkeeping-cashflow-13wk` or any other existing capability.
- **Affected code**: 1 new register.d fragment (`BudgetScenario`,
  `BudgetScenarioModifier`, plus one seed `LedgerGroup` object per RULING 1
  — `budget-core-schema`'s own `LedgerGroup` schema is not edited, only a
  seed object using it is added, the same cross-fragment seeding pattern
  `bookkeeping-cost-centers-dimensions.json`/`bookkeeping-provincies-bbv-
  variant.json` already use for schemas they do not themselves declare), 1
  new guard, 2 new PHP services (`BudgetScenarioDefaultPromoter`,
  `BudgetScenarioEvaluator`) + PHPUnit coverage, 1 new manifest fragment,
  new Playwright coverage.
- **Hard dependency — `budget-known-costs`**: `BudgetScenarioEvaluator`
  calls `budget-known-costs`'s `KnownCostScheduleExpander` directly (a
  pure, no-store-access class — a safe direct call, not a duplicated
  reimplementation, unlike the store-bearing readers elsewhere in this
  wave that deliberately do not share) and every `RECURRING_*` modifier
  targets `budget-known-costs`'s `contractReference`-taggable
  `CashflowRecurring` primitive by `recurId`. This change's schema/guard/
  evaluator tasks cannot start before `budget-known-costs` lands.
- **Hard dependency — `budget-grid-view`, UI only**: the scenario-selector
  control and side-by-side comparison rendering this change's own UI task
  needs a real grid to embed into or sit beside; `budget-grid-view`'s own
  `design.md` explicitly names "a scenario switcher on this grid" as its
  own non-goal, owned by this change. This change's backend (schema, guard,
  promoter, evaluator) has no such dependency and can land independently;
  only the grid-integration UI task is sequenced after `budget-grid-view`.
  `design.md` §10.
- **Byte budget**: measured 2026-08-20 (`node tests/check-manifest-budget.js`,
  this checkout): `manifest.json=452689B manifest.d/=641429B
  total=1094118B budget=1126300B` → 32,182B headroom, before either
  `budget-known-costs` or this change's own pages land. This change's
  estimated page cost (4 CRUD pages + 1 comparison page) is 1,200–2,700B —
  `tasks.md` requires re-measuring at implementation time, not relying on
  this figure.
- **No cross-repo impact.**

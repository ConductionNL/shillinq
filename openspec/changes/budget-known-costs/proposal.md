# Change: budget-known-costs

## Why

`budget-core-schema` gives shillinq `AnnualBudget`/`LedgerGroup`/`BudgetLine`
and declares `BudgetLine.source` as an enum
(`manual|contract|recurring|projected|scenario`) — but only ever writes
`manual`. The user's own requirement for this wave is explicit: *"begroot on
known cost. That could be both running contracts (tills or without an
enddate) and cost that we project (so we should for example be able to say
that a new server will be added to the hosting pool at date x and then that
should be begroot from date x)."* Nobody has yet built the thing that turns
"a contract exists" or "a cost starts on date X" into an actual `BudgetLine`
row. This change is that thing: it populates `source = "contract"` and
`source = "recurring"`.

### What already exists that this change reuses, verified at HEAD

- **`CashflowRecurring`** (`lib/Settings/register.d/zzp-cashflow-13wk.json`,
  capability `bookkeeping-cashflow-13wk`, shipped and archived
  2026-06-14) already models almost everything a "known cost" needs:
  `label`, `category` (`RECURRING_RENT`/`RECURRING_INSURANCE`/
  `RECURRING_SUBSCRIPTIONS`/`RECURRING_SOFTWARE`/`RECURRING_DGA_PAY`/
  `RECURRING_ANNUITY_PREMIUM`/`RECURRING_LEASING`/`RECURRING_OTHER`),
  `direction` (`IN`/`OUT`), `frequency` (`WEEKLY|FORTNIGHTLY|MONTHLY|
  QUARTERLY|ANNUALLY`), `dagFromMonth`/`monthOfYear` anchors,
  `standardAmount`, **`indexationRule` (`FIXED`|`CPI_PAST_YEAR`)**,
  **`validFrom`/`validTo` (null = indefinite)**, and — critically —
  **`accountNumberExpense`, an FK straight to `Account.accountNumber`**.
  It is scoped to `administrationId` **and** `enterpriseId`, never to a
  `Contract`. `design.md` §1 reads this schema field-by-field and concludes
  it is the schedule primitive this change needs, not something to
  reinvent.
- **No PHP class actually expands `CashflowRecurring`.** Verified by grep
  (`design.md` §0): the schema's own description says it is "auto-expanded
  into the 13-week horizon," but no reader/calculator anywhere in `lib/`
  does that expansion, and `tests/Unit/Service/CashflowForecastFragmentTest.php`
  only asserts schema shape, never arithmetic. Whatever mechanism was meant
  to expand it (a declarative aggregation, presumably) is unproven and,
  given `budget-core-schema design.md` §6a's already-documented
  cross-schema aggregation hazard, plausibly another instance of the same
  silent-discard failure — noted as a finding, not fixed here (out of
  `bookkeeping-cashflow-13wk` capability's scope for this change). This
  change therefore builds its own expander from scratch; it cannot lean on
  an existing one.
- **`Contract`** (`lib/Settings/register.d/contract-lifecycle-management.json`,
  capability `contract-lifecycle-management`) has `contractNumber`,
  `counterpartyReference`, `contractType`, `direction` (`inbound` = we pay /
  `outbound` = we are paid), `startDate`/`endDate` (null = indefinite),
  `renewalTerms`, `totalContractValue`, `costCenter`, `dimensions`, a
  `draft→active→expiring→expired→renewed/terminated` lifecycle. **It has no
  `accountNumber` field and no payment-schedule child object.**
  `contracts-single-home` (in-flight sibling openspec change, unmerged as of
  2026-08-20) is renaming the *other*, IFRS-15 `Contract` to
  `RevenueContract` and confirms CLM's `Contract` (the one this change
  reads) is the fleet's canonical, unambiguous `Contract` going forward —
  this change reads that schema exactly as declared, unchanged in shape,
  and does not touch `contracts-single-home`'s own files.
- **`ContractObligation`** (same fragment) has `obligationType`
  (`deliverable`/**`payment`**/`compliance`/`review`/`notice`), `dueDate`,
  `recurrence` (`none`/`monthly`/`quarterly`/`annually`) — a real deadline
  primitive, but **no amount field and no GL-account field**. Checked, per
  the task brief's explicit instruction, before inventing a schedule
  primitive: it answers *when* an obligation is due, never *how much*, so it
  cannot alone drive a monthly-phased `BudgetLine` amount. `design.md` §2
  records this finding and explains why `CashflowRecurring` — which already
  carries both an amount and a GL link — is reused instead.
- **`bookkeeping-ifrs-16-lease.json`'s lease payment schedule** declares
  `indexationRateOrSource` as free text ("Fixed percent (e.g. '2.0') or
  index reference (e.g. 'Dutch CPI')") — this codebase's own existing
  precedent for CPI indexation is an **operator-supplied rate**, not a live
  CBS feed. No CBS-index integration exists anywhere in this repo (grepped).
  `CashflowRecurring.indexationRule = CPI_PAST_YEAR` has no rate field at
  all today, so it has never been computable as declared — a genuine,
  pre-existing gap this change closes minimally (`design.md` §3), following
  the IFRS-16 precedent's shape rather than inventing a new one.
- **`SubsidieOrderConsolidationMigrator`** (`lib/Service/Migration/`) is the
  count-abort migrator precedent named in the task brief — read in full;
  its `mapObjectToRenamedSchema`/`assertCountsMatch` pattern is **not**
  reused directly here (this change renames no schema — see `design.md` §0
  for why no migrator is needed), but its reader/calculator-adjacent
  discipline (pure, narrowly scoped, fail-closed) shapes this change's own
  services.
- **`BbvProgrammeBudgetReader::spendByProgramme()`/`postedTransactionMonths()`**
  and `budget-core-schema`/`budget-projection-engine`'s own readers all
  share one batching idiom (unfiltered-by-period reads, joined in memory,
  dual-keyed `transactionRefs`) — this change's own reader follows the same
  discipline for its own, smaller read set (`design.md` §6).

## What Changes

- **ADD** (schema field, additive, non-breaking): `CashflowRecurring` gains
  **`contractReference`** (nullable string FK to `Contract`) and
  **`cpiRatePercent`** (nullable number, operator-supplied annual rate for
  `indexationRule = CPI_PAST_YEAR`). Both fields default to their current
  implicit absence — no live object's meaning changes. `design.md` §1, §3.
  **This is the "Contract→GL account link" the task brief asks for**: a
  `CashflowRecurring` row with `contractReference` set already carries its
  own `accountNumberExpense` — the same field every recurring cost already
  has — so no separate join schema is needed (`design.md` §1c explains why
  a parallel `ContractBudgetLink` schema was considered and rejected).
  **This is also the "dated planned-cost primitive"**: a `CashflowRecurring`
  row with `contractReference = null` is exactly "a cost that has no
  contract yet, budgeted from a start date" (the task brief's own hosting
  server example) — one primitive serves both cases, distinguished only by
  whether the FK is populated. `budget-scenarios` reuses this same primitive
  and is told explicitly, in this document, that `budget-known-costs` owns
  it (`design.md` §1d).
- **UPDATE**: `lib/Guard/CashflowRecurringGuard.php` gains one new
  precondition — when `contractReference` is set, `validFrom`/`validTo`
  must fall within the referenced `Contract`'s own `startDate`/`endDate`
  when those are known (open bounds where the Contract's own dates are
  null), rejecting the save otherwise (ADR-031 exception path, extending an
  existing guard rather than adding a parallel one). `design.md` §3c.
- **ADD** (schema): `BudgetLineDerivation` — a small, system-managed
  bookkeeping row (owned by this change, not touching `BudgetLine`'s own
  schema) that makes regeneration idempotent and gives an operator's manual
  edit to a derived line a detectable, respected override. `design.md` §4.
- **ADD** (services): `KnownCostReader` (impure, all store access),
  `KnownCostScheduleExpander` (pure, no store access — the schedule math:
  frequency, indexation, validFrom/validTo bounding, monthly phasing),
  `KnownCostBudgetWriter` (orchestrator: idempotent upsert into `BudgetLine`
  via the `BudgetLineDerivation` ledger, override-detection, precedence).
  `design.md` §5–§8.
- **ADD** (pages): a minimal read-only index/detail pair for
  `BudgetLineDerivation` (audit visibility: which `CashflowRecurring` rows
  fed which `BudgetLine`), nested under `budget-core-schema`'s `Budgets` nav
  group. `design.md` §10.
- **ADD**: Playwright e2e + PHPUnit coverage. `design.md` §11.
- **Non-goals, each naming its owning change** (`design.md` §12): the
  spreadsheet-grid UI (`budget-grid-view`), projection/growth-rate math
  (`budget-projection-engine`), scenario/what-if modifiers
  (`budget-scenarios` — reuses this change's `contractReference`-tagged
  `CashflowRecurring` primitive and this change's pure
  `KnownCostScheduleExpander`, named explicitly so `budget-scenarios` does
  not redefine either), charts (`budget-charts`), a live CBS-CPI feed
  (operator-supplied rate only, `design.md` §3b), and any change to
  `bookkeeping-cashflow-13wk`'s own 13-week weekly forecast (this change
  adds two additive fields to its schema and nothing else — no weekly
  arithmetic, no `CashflowWeek`/`CashflowForecastHorizon` touch).

## Impact

- **Affected specs**: new capability `budget-known-costs`
  (`specs/budget-known-costs/spec.md`); `bookkeeping-cashflow-13wk` MODIFIED
  (two additive `CashflowRecurring` fields + the extended guard
  precondition — REQ-CF-005's own scope, delta below).
- **Affected code**: 1 register.d fragment edited
  (`zzp-cashflow-13wk.json`, additive fields only), 1 new register.d
  fragment (`budget-known-costs.json`, `BudgetLineDerivation`), 1 guard
  class extended (`CashflowRecurringGuard.php`), 3 new PHP service classes
  + PHPUnit coverage, 1 new manifest fragment (2 pages), new Playwright
  coverage.
- **Hard dependency — `budget-core-schema`**: requires `LedgerGroup`/
  `AnnualBudget`/`BudgetLine` (groups 1–8) to have landed; does not require
  `budget-core-schema`'s own group 9 (its 6 CRUD pages) — same
  either-order nav convention `budget-grid-view` already established for
  the `Budgets` top-level group (`design.md` §10).
- **Byte budget**: measured 2026-08-20 (`node tests/check-manifest-budget.js`,
  this checkout, freshly run — not assumed from an earlier sibling's
  figure): `manifest.json=452689B manifest.d/=641429B total=1094118B
  budget=1126300B` → **32,182B headroom**, comfortably covering this
  change's 2 small pages (estimated 950–1,900B). `tasks.md` still requires
  re-running the check and stopping on failure, same discipline as every
  sibling.
- **No cross-repo impact.**

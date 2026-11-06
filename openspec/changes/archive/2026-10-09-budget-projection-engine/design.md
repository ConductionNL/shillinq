# Design: budget-projection-engine

## 0. Method

Verified directly against `origin/development` (2026-08-20, this
checkout), same discipline as `budget-core-schema design.md`. `Account`,
`GLTransaction`, `GLLine` field lists below are read from
`lib/Settings/shillinq_register.json` (the monolith, `python3 -c
"json.load(...)"` against `components.schemas`), not assumed. This
document builds strictly on `budget-core-schema`'s `LedgerGroup`/
`AnnualBudget`/`BudgetLine` design (its §3–§5) without redesigning it, and
on `BbvProgrammeBudgetReader`/`Calculator` and
`src/components/dashboard/financial/financialSeries.js` as the two
existing partial precedents named in the task brief.

## 1. What is projected: closing balance vs. gross movement, by account type

`Account.accountType` (verified,
`lib/Settings/shillinq_register.json:1353-1359`) is one of `assets`,
`liabilities`, `equity`, `revenue`, `expenses` — the same enum
`TrialBalanceLine.accountType` inherits (`bookkeeping-trial-balance.json:57-63`).
These split into two structurally different kinds of quantity, and
"growth" means a different thing for each:

- **Stock accounts** (`assets`, `liabilities`, `equity`) carry a balance
  that persists from period to period — `closingBalance = openingBalance +
  (debitMovement - creditMovement)` (REQ-TB-003, the exact formula
  `TrialBalanceCalculator::closingCents()` already implements). The
  meaningful thing to extrapolate is **the balance itself**: how the cash
  position, the debtor book, or the equity balance is trending, month over
  month. Projecting the *movement* of a stock account and re-deriving a
  balance from it would just reinvent the same closing-balance arithmetic
  with an extra step and an extra place to get the carry-forward wrong.
- **Flow accounts** (`revenue`, `expenses`) reset conceptually every
  period — there is no persisting "balance" an operator budgets against;
  what recurs is the *movement itself* (this month's revenue, this
  month's cost). This is exactly what
  `financialSeries.js::monthlyFinancialSeries()` and
  `FinancialSeriesCalculator` already compute for the aggregate
  revenue/costs/margin dashboard — a monthly flow number, not a running
  balance. The meaningful thing to extrapolate is **netMovement**, defined
  identically to `TrialBalanceLine`'s own signed convention:
  `netMovement = debitMovement - creditMovement` (no per-type sign flip —
  this is deliberately the same signed quantity the fact table already
  exposes under this exact name, so a `revenue` account's netMovement
  reads negative under this schema's presentation, same as its
  `closingBalance` would; the sign convention is not re-invented per
  account type, it is carried straight through).

**Which account types are projectable**: all five — every `accountType`
has a defined metric (closing balance for the three stock types, net
movement for the two flow types). Nothing is declared unprojectable by
type; a given *account* can still be unprojectable by *data* (§3).

```
projectionMetric(accountType):
  assets | liabilities | equity  -> closingBalance   (stock)
  revenue | expenses             -> netMovement       (flow)
```

## 2. The growth-rate arithmetic, exactly

### 2a. The window

For a given account, the **trailing window** is the 12 calendar months
immediately preceding — and including up to — the account's own
`lastActualMonth` (§4 defines this per-account, not globally: two
accounts can have different last-actual months). Calendar months are
derived from `GLTransaction.postingDate` (a required field), **not** from
`GLLine.periodId`. `periodId` is an optional, free-text field on `GLLine`
(`lib/Settings/shillinq_register.json` — `GLLine.required` does not
include `periodId`) and this codebase's own seed data mixes granularities
under it — verified: `grep -rn '"periodId":' lib/Settings/register.d/*.json`
finds `"2026-01"` (monthly), `"2026-Q1"`/`"2026-Q2"` (quarterly), and
`"2026-H1"` (half-year) all coexisting. A monthly trailing-window
calculation keyed on `periodId` would silently misbucket any quarterly- or
half-year-keyed row. `postingDate` is required on every `GLTransaction`
and is exactly what `BbvProgrammeBudgetReader::spendByProgramme()` /
`financialSeries.js::postedLinesByMonth()` already bucket by — this
change follows that precedent, not `TrialBalanceService`'s own
`periodId`-keyed approach (which is safe for `TrialBalanceService`
because its caller always supplies one `periodId` for one deliberate
period; it is not safe for a caller that needs to walk 12 consecutive
calendar months).

This yields up to 12 metric values `v_1..v_12` (oldest to newest,
`v_12 = lastActualMonth`'s value).

### 2b. Pairwise growth steps

For `i = 2..12`, define the step growth rate:

```
g_i = (v_i / v_{i-1}) - 1
```

12 values → **11 possible steps**. Three step outcomes:

| `v_{i-1}` | `v_i` | Outcome |
|---|---|---|
| `0` | `0` | `g_i = 0` — **included** (flat at zero is a real, computable rate) |
| `0` | non-zero | **excluded** — division by zero; growing "from nothing" has no percentage (an account going from €0 to any amount is not a growth *rate*, it is a change of state) |
| non-zero, sign differs from `v_i`'s sign | (either) | **excluded** — a ratio between opposite-signed values (e.g. a liability balance crossing from credit to debit) produces a mathematically defined but meaningless "growth rate" (a −100→+50 swing computes as −150%, which is not a rate anyone can act on); this happens for real on `expenses`/`revenue` netMovement (a reversal month can flip the sign) and on stock balances that can legitimately go negative (an overdrawn account, a contra-equity position) |
| non-zero, same sign | non-zero, same sign | `g_i = (v_i/v_{i-1}) - 1` — **included** |

A month whose GL data does not exist at all yet (§2c) never reaches this
table — it is excluded from the window before step-pairing happens, not
treated as a `0`.

### 2c. Zero vs. absent — the fleet rule, applied

**Zero** (an actual `debitMovement`/`creditMovement` of 0, or a
`closingBalance` that nets to 0) is a real, present data point — a quiet
account in a quiet month. It participates in §2b's table above.

**Absent** (no `GLTransaction` at all touches this account on or before
that month) is a period whose input does not exist. Concretely: the
reader determines, per account, the calendar month of the account's
*earliest* posted `GLTransaction` line. Any month in the nominal
trailing-12 window that falls before that earliest month is **absent**,
not zero — it is dropped from `v_1..v_12` entirely (shortening the
window, not padding it with zeros), per this repo's own working rule: *"a
period whose inputs are not available yields no rate; the caller surfaces
'cannot project yet', never a guess wearing a number's clothes."* A newly
opened ledger account with 3 months of real history has a 3-value window
(`v_1..v_3`), not a 12-value window with 9 synthetic zeros — synthetic
zeros would count as real §2b `0→0` or `0→nonzero` steps and corrupt the
rate.

### 2d. Minimum data floor

`MIN_VALID_STEPS = 3`. If the count of **included** (non-excluded) steps
from §2b is below 3, the account is **unprojectable this run** — the
calculator returns a typed "insufficient data" result (§3), never a
computed number. Because 11 is the maximum possible step count and a
window shorter than 4 present months cannot produce 3 steps even before
any exclusion, the practical floor is: *at least 4 months of actual data
present, with at least 3 of the resulting 3 (or more) pairwise steps
landing in the "included" row of §2b's table.* A single outlier or a
single zero-base step can push an account that has 4–5 months of raw
history below the floor — this is intended, not a bug: 2 valid data
points is not enough to average, let alone trim.

### 2e. Outlier trim

If the included-step count is **≥ 5**, drop the single highest and single
lowest included `g_i` before averaging (a fixed "trim one from each end,"
not a percentile-based trim — auditable by inspection: "which two months
got dropped" is always answerable). Below 5 included steps, no trimming —
removing 2 of e.g. 3 or 4 values would discard most of the already-thin
sample. This is the one-off/outlier control the task brief asks for: a
single bonus run, a single reversed correction entry, or a single
unusually large invoice month is prevented from single-handedly setting
the forward trend, without requiring a second, harder-to-audit
statistical method (winsorizing, IQR, z-score) for what is, after
`§2d`'s floor, already a small sample.

### 2f. The mean growth rate

```
ḡ = arithmetic mean of the (possibly trimmed) included g_i values
```

Arithmetic mean of the pairwise rates — **not** CAGR
(`(v_12/v_first)^(1/n) - 1`). CAGR discards every interior month and only
looks at the two endpoints, which throws away exactly the "average
*development*" the task brief asks for and is far more exposed to a
single endpoint being an outlier (which §2e already handles more directly
for the interior-aware mean). This is also not a compounded/geometric
mean of the step ratios — a plain arithmetic mean of the percentage rates
is what "average growth in %" means in the task brief's own words, and it
is the simpler, more auditable computation: `ḡ` is literally "add up the
usable month-over-month percentages and divide by how many there were."

### 2g. Extrapolation

For projected month offset `k = 1, 2, 3, …` beyond `lastActualMonth`'s
value `V₀`:

```
projected(k) = round_cents( V₀ × (1 + ḡ)^k )
```

One rate, compounded forward — not re-estimated at every step. This keeps
the projected curve a smooth, single-parameter geometric extrapolation
from the last known value, and keeps it checkable by hand: "projected
month 3 = last actual × (1+ḡ)³." `k` is bounded by
`PROJECTION_HORIZON_MONTHS = 12` (§6) — this engine never projects past
one fiscal year, matching `BudgetLine`'s own fixed 12-slot shape.

Arithmetic is carried in **integer EUR cents** throughout (matching
`BudgetLine.month01Amount..month12Amount`'s own minor-unit convention and
`TrialBalanceCalculator::toCents()`/`fromCents()`), with `ḡ` computed as a
float ratio and the final `projected(k)` rounded to the nearest cent
(PHP's default `round()`, half-away-from-zero) only at the point a value
is returned to a caller — the ratio itself is never rounded mid-chain,
so compounding 12 steps of a small `ḡ` does not accumulate rounding
drift.

### 2h. Worked degenerate-case examples (also §7's PHPUnit fixtures)

1. **Zero months**: `v = [1000, 1000, 0, 0, 1000, 1000, …]` (cents) — the
   `0→0` step is `g=0` (included); the `1000→0` step is excluded (sign
   check n/a, but `0` result with nonzero start is a valid ratio: `g =
   -1.0`, actually — re-check: `v_{i-1}=1000, v_i=0` is the "non-zero →
   zero" case, which *is* a computable ratio (`0/1000 - 1 = -1.0`, a
   defined -100%) and is **included**, not excluded. Only the reverse —
   starting from a literal `0` base — is excluded (division by zero). This
   asymmetry is deliberate and is called out explicitly in §2b's table.
2. **Negative months**: an `expenses` account with a large reversal —
   `netMovement` sequence `[500, 500, -200, 500, 500, …]` (cents) — the
   `500 → -200` step and the `-200 → 500` step both cross sign and are
   excluded; the other steps compute normally. Two steps lost to one
   anomalous month, not the whole window.
3. **Fewer than minimum data points**: an account with 3 months of
   history (`v = [1000, 1100, 1210]`) yields 2 steps, both included
   (10% each) — below `MIN_VALID_STEPS = 3` — result: unprojectable,
   `reason: "insufficient-data"`, `validSteps: 2`.
4. **A single outlier**: 12 months of steady ~2% growth with one month at
   +80% (a one-off): 11 included steps ⇒ trim applies (§2e drops the
   +80% high and the lowest of the remaining), mean computed over the
   remaining 9 — the extrapolated curve reflects the steady ~2%, not a
   rate dragged toward 80%/11 ≈ +7.3% by one month.

## 3. Result shape — never a guess wearing a number's clothes

Every computed cell (account × month) is one of three typed states, and
callers MUST branch on the state, not on whether a number is present:

```
{ kind: "actual",         amount: <int cents> }
{ kind: "projected",      amount: <int cents>, rate: <float>, validSteps: <int> }
{ kind: "unprojectable",  reason: "insufficient-data" | "no-history", validSteps: <int> }
```

`unprojectable` is not `amount: 0` and not `amount: null` treated as
zero — it is a distinct tag a UI renders as a dash/blank (`budget-grid-view`'s
concern), never summed as if it were zero (an unprojectable month silently
treated as €0 would make a thin-history account's cumulative series look
like a real decline).

## 4. The past/future seam

Reusing `forecastByMonth(weeks, afterMonth)`'s idea explicitly: **a
projected value is only computed for a month that has no actual**, per
account (`lastActualMonth` is resolved per-account, not globally — two
accounts opened in different months legitimately have different cutovers,
exactly as `forecastByMonth`'s single `afterMonth` parameter would need to
be called once per series if the underlying series had different
cutovers; this engine's reader computes `lastActualMonth` per account
rather than assuming one global cutover for every account in a request).

```
seam(account, month):
  if month has an actual value  -> "actual" (always wins, never blended,
                                    never overridden by a projection)
  else if month <= lastActualMonth of account -> "unprojectable" (no
                                    trailing data reaches this far back —
                                    §2c's "absent" case, not a future month)
  else                           -> "projected" (§2g)
```

A period with actuals always shows actuals — there is no month that is
ever both. This mirrors `forecastByMonth`'s own `if (!key || key <=
afterMonth) continue` guard precisely.

## 5. Per-account vs. per-verzamelpost (`LedgerGroup`) projection

**Decision: a `LedgerGroup`'s projected series is the sum of its member
accounts' own projected series — never an independent fit against the
group's own aggregate history.**

### 5a. Why not fit the group's own aggregate history

1. **Consistency with actuals.** `budget-core-schema`'s own
   `BudgetVsActualsReader` (design.md §6b) resolves a `LedgerGroup`'s
   *actual* value as the sum of its resolved member accounts' actuals.
   If `projected` used a different aggregation rule (fit-the-total
   instead of sum-the-members), the actual→projected seam (§4) would be
   comparing two structurally different quantities the one time it
   matters most — the boundary month.
2. **Membership is not a stable single series.** `LedgerGroup` membership
   is resolved *at evaluation time* from `accountRanges` +
   `includedAccountNumbers`/`excludedAccountNumbers`
   (`budget-core-schema design.md` §3a — reused here exactly, not
   redesigned). If an operator edits a group's range next month, "the
   group's own historical total" retroactively means a different set of
   accounts than it did when last computed — there is no single stable
   12-month series to fit a growth rate against in the first place. A
   sum of *current* members' *own* histories is always well-defined for
   the group's *current* definition, regardless of when membership last
   changed.
3. **Per-member auditability.** An operator asking "why is Voorraden's
   projection €X" gets a real answer — account-by-account rates and
   valid-step counts — rather than one opaque group-level rate. A member
   with too little history to project (§2d) surfaces as `unprojectable`
   for *that member only* (contributing `0` to the group sum with a
   caveat flag — §5b), rather than either discarding the whole group's
   projection or silently omitting that member with no trace.

### 5b. The stated trade-off

Summing member projections is noisier at the group level than a single
group-level fit would be — each member's own outlier-trim (§2e) runs
independently, so a group of many small accounts does not get one
smoothing pass, it gets N independent ones. This is accepted deliberately:
noise that is *traceable to a specific account* is preferable to a
smoother number nobody can decompose. `budget-grid-view`/`budget-charts`
MAY choose to display the group-level number more prominently and the
per-member breakdown on drill-down — a display decision, not an
engine one.

### 5c. Group sum with partial unprojectable members

```
groupProjected(group, month) =
  sum over resolved members m of:
    memberProjected(m, month).amount if kind == "projected"
    memberActual(m, month).amount    if kind == "actual"
    0                                 if kind == "unprojectable"
  , tagged "partial" if ANY member for that month was "unprojectable"
```

A group projection is never itself typed `unprojectable` outright unless
**every** resolved member is `unprojectable` for that month — partial
data contributes what it has and is tagged, not withheld. This is a
narrower, more permissive rule at the group level than at the account
level (§2d), which is intentional: a verzamelpost usually has more member
accounts than any single account has months of history, so requiring
100% of members to individually clear the floor before showing anything
would make groups fail far more often than the accounts that compose
them.

### 5d. Membership resolution is duplicated, not shared, and that is scoped deliberately

`budget-core-schema`'s `BudgetVsActualsReader` already implements
`LedgerGroup` range/include/exclude resolution in PHP (its design.md §6b).
This change's `BudgetProjectionReader` implements the **same algorithm**
independently rather than extracting a shared
`LedgerGroupMembershipResolver` both readers call — because that
extraction would mean editing `BudgetVsActualsReader`, a file this
change's brief explicitly says to build on, not redesign. Flagged as a
worthwhile follow-up refactor once both changes have landed (small,
low-risk, no behaviour change), not undertaken here.

## 6. Cumulative variants

For **flow accounts** (`revenue`/`expenses`), `cumulative` is a
fiscal-year-to-date running sum of the `trend` series (actual where
actual, projected where projected, per §4's seam — the running total is
continuous across the seam, exactly the property `forecastByMonth`
enables for the cashflow chart's "dimmed projection columns appended to
the realized line"):

```
cumulative(month_N) = sum(trend(month_1) .. trend(month_N))
```

mirroring `BbvProgrammeBudgetCalculator::trendFor()`'s own running total
(carrying the value forward through quiet months rather than resetting —
here every month contributes its own trend value, actual or projected,
never a skipped gap).

For **stock accounts** (`assets`/`liabilities`/`equity`), `cumulative` is
**defined as equal to `trend`** — the trend series *is* `closingBalance`,
which per REQ-TB-003 already carries the opening balance forward
(`openingBalance = prior month's closingBalance`), so it is already a
running, point-in-time cumulative position by construction. Summing
closing balances across months would double-count the carried balance
every single month; the engine deliberately does **not** do this. This
must be documented at the call site for `budget-grid-view`/`budget-charts`
so the "13th cumulative column" is not implemented as `Σ closingBalance`
by a consumer that assumed the flow-account rule applies uniformly.

`budgeted`'s cumulative variant follows the same account-type rule,
applied to `BudgetLine.month01Amount..month12Amount` directly — this
engine does not compute it (§8's non-goal: `budgeted` is read straight
from `BudgetLine` by the caller), but the rule must be identical so a
grid rendering all three series' cumulative rows is internally
consistent.

## 7. Query budget

### 7a. The constraint TrialBalanceService's own shape creates

`TrialBalanceService::compute()` is scoped to **one `periodId` at a time**
and internally issues 2 `findAll()` calls (`GLTransaction`, `GLLine`) plus
1 for `Account` — 3 calls per period. Calling it once per trailing month
for a 12-month window would cost **36 `findAll()` calls per account
request**, growing with the window length — acceptable for T2's
single-period trial-balance report, not for a rolling 12-month projection
computed potentially across every account in a `LedgerGroup`.

`TrialBalanceService`'s own docblock is explicit that **`TrialBalanceLine`
has no persisted rows** — *"there is NO `TrialBalanceLine` record
authored by operators; the rows are materialised on demand."* This
matters directly here: this change's reader cannot `findAll(schema:
'TrialBalanceLine')` and get real historical rows back (only 5
illustrative seed examples exist, per that schema's own seed block) — it
must compute from `GLTransaction`+`GLLine`+`Account` exactly as
`TrialBalanceService` does, just batched across the whole window instead
of called once per period.

(Cross-reference, not a fix: `budget-core-schema design.md` §6b describes
its own `BudgetVsActualsReader` as resolving actuals "from
`TrialBalanceLine`" in a way that reads as if that schema held queryable
historical rows. It does not, per the same `TrialBalanceService` docblock
this section cites. Both readers must compute from the underlying GL
facts directly; noted here as a cross-check finding for whoever
implements `budget-core-schema`'s task group 8, not silently corrected in
that file.)

### 7b. The batched approach — target ≤4 `findAll()` calls, independent of account/period count

Following `BbvProgrammeBudgetReader::spendByProgramme()`'s precedent
exactly (unfiltered-by-period `GLLine` read + administration-scoped
`GLTransaction` read, joined in memory via `transactionRefs()`'s
dual-keying):

1. `Account.findAll(filters: [administrationId])` — once. Resolves
   `accountType` per account and, together with (4), resolves
   `LedgerGroup` membership.
2. `GLTransaction.findAll(filters: [administrationId, state: 'posted'])`
   — once, **unfiltered by period/date**. Build an in-memory index:
   `transactionRef -> monthKey` (from `postingDate`, §2a), keyed by
   **both** the object id and `transactionNumber` (the
   `transactionRefs()` precedent — "keying one silently drops half the
   lines," verified live in this codebase, not assumed).
3. `GLLine.findAll(filters: [])` — once, **unfiltered by period or
   account**. For each line, resolve its month via (2)'s index (skip
   lines whose transaction is not in the index — not posted, or outside
   the administration), then bucket `debitCents`/`creditCents` by
   `(accountNumber, monthKey)` in memory — the same shape
   `postedLinesByMonth()` already builds.
4. `LedgerGroup.findAll(filters: [administrationId])` — once, only when
   the request includes group-level projections. Resolves each
   requested group's `accountRanges`/`includedAccountNumbers`/
   `excludedAccountNumbers` against (1)'s account list in memory
   (`budget-core-schema design.md` §3a's resolution, reused not
   redesigned).

From (3)'s bucketed cents, the calculator derives, per account per month:
`netMovement = debitCents - creditCents` (flow accounts, used directly)
and `closingBalance = priorMonth.closingBalance + netMovement` (stock
accounts, seeded at the window's first available month from `0` —
*"assumed 0 at first period,"* the exact phrase and rule already coded in
`TrialBalanceService::compute()`'s prior-period carry, reused here for
the same reason: no earlier data exists to source a true opening balance
from).

**Query count: 3 calls for any account-only request, 4 when any
`LedgerGroup` is requested — in both cases independent of the number of
accounts, the number of groups, and the number of months (12 or
otherwise) in scope.** This is a genuine improvement over calling
`TrialBalanceService::compute()` per period (which would cost O(periods)
calls); the target is not "a few calls," it is a bounded constant
regardless of the request's size along every one of those three axes.

## 8. Testability — the reader/calculator split, and what each is tested for

- **`BudgetProjectionReader`** — impure, the only class with
  `ObjectServiceInterface` DI (ADR-083/084, matching every existing reader
  in this codebase). Tested with a mocked `ObjectServiceInterface`
  asserting the exact call count from §7b (this is the query-budget
  regression test — a future change that reintroduces a per-account or
  per-month `findAll()` call breaks this assertion, not just a manual
  count).
- **`BudgetProjectionCalculator`** — pure, no constructor dependencies
  beyond its own constants (mirrors `BbvProgrammeBudgetCalculator`
  exactly: "it reads NOTHING," inputs in, numbers out). This is where
  every degenerate case from §2h is a named PHPUnit test:
  `testZeroToZeroStepIncluded`, `testZeroBaseStepExcluded`,
  `testSignFlipStepExcluded`, `testBelowMinimumStepsIsUnprojectable`,
  `testOutlierIsTrimmedAboveFiveSteps`, `testNoTrimBelowFiveSteps`,
  `testGroupSumsMemberProjections`, `testPartialGroupTaggedNotWithheld`,
  `testStockCumulativeEqualsTrend`, `testFlowCumulativeIsRunningSum`,
  `testSeamNeverOverridesAnActual`.
- **`BudgetProjectionService`** — orchestration only (calls reader, calls
  calculator, shapes the response); tested with both collaborators mocked,
  asserting it does not do arithmetic itself.

## 9. e2e coverage

This change ships **no new page and no new Playwright spec of its own** —
it has no browser-visible surface (§0, no UI in this change). Every
scenario in this change's own spec is `@e2e exclude`, PHPUnit-provable.

The one browser-visible assertion the task brief itself calls for — "a
projected column/series renders distinctly from actuals" — is explicitly
**not duplicated here**; it belongs to whichever of `budget-grid-view` /
`budget-charts` first renders these series, and that spec's own e2e
coverage should reference this capability
(`@spec openspec/specs/budget-projection-engine/spec.md`) rather than
this change growing a UI assertion for a UI it does not ship.

## 10. Non-goals (each names its follow-up change)

- **Rendering any of these series** (grid columns, chart lines, dimmed
  projection styling) — `budget-grid-view` / `budget-charts`. This change
  returns typed data (§3); it draws nothing.
- **`begroot`/budgeted-from-known-cost derivation** — `budget-known-costs`.
  This change reads `BudgetLine`'s existing manually-entered amounts only
  insofar as needed to determine the actual/projected seam's *comparison*
  context in a future caller; it does not compute or write budgeted
  values itself.
- **Scenario/modifier support** — `budget-scenarios`. This engine has no
  concept of a non-default `AnnualBudget` or a what-if delta.
- **Patching `AggregationAnnotationValidator`** — foundation/openregister
  scope, already out of bounds per `budget-core-schema design.md` §6a; not
  re-litigated here. This change's own reader never depends on a
  declarative cross-schema aggregation for the reasons in §7a.

## 11. Open questions

1. **Does this engine persist `BudgetLine.source = "projected"` rows, or
   only serve a computed series callers fetch live?** The task's own
   framing ("the calculator must be a PURE class... the reader owns all
   store access") describes a read pipeline, and `budget-core-schema`
   declared the `source` enum value for "later changes to populate"
   without specifying a writer contract (transactional semantics,
   refresh cadence, what happens to a `projected` row once the real
   actual posts and the seam moves). This change ships the read/compute
   path (§1–§7) either way; whether a persistence/writer service is also
   needed is a genuine product call, not decided here.
2. **Is a hard clamp on `|ḡ|` wanted** (e.g., refuse to compound a rate
   above some %/month over many months, flagging instead of projecting a
   runaway curve)? §2e's outlier trim addresses the most common cause of
   an extreme rate; it does not cap a genuinely sustained fast-growing
   account. The calculator surfaces `rate` and `validSteps` on every
   `projected` result (§3) specifically so a caller/UI can apply its own
   plausibility threshold — this change does not invent a clamp number
   with no stated basis for it.
3. **`LedgerGroupMembershipResolver` extraction** (§5d) — a small,
   deliberately deferred refactor once both this change and
   `budget-core-schema` have landed, so the duplicated range/include/
   exclude logic has one home. Not blocking, not undertaken here.
4. **Fiscal year assumed calendar-year (Jan–Dec)** — `AnnualBudget`
   (`budget-core-schema`) declares only an integer `fiscalYear`, no
   fiscal-year-start-month field, so `month01Amount..month12Amount` is
   read here as January–December. If a later change gives
   `AnnualBudget` a configurable fiscal-year start, this engine's month
   indexing (§2a, §6) needs revisiting — flagged, not assumed away.

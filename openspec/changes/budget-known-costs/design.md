# Design: budget-known-costs

## 0. Method

Verified directly against `origin/development` (2026-08-20, this checkout),
same discipline as `budget-core-schema design.md` / `budget-projection-engine
design.md`. `budget-core-schema`, `budget-projection-engine`, and
`budget-grid-view`'s `proposal.md`/`design.md`/`tasks.md`/`specs/` were read
in full before writing this document; `LedgerGroup`/`AnnualBudget`/
`BudgetLine` are used exactly as `budget-core-schema` §3–§5 defines them — no
field is added, renamed, or reinterpreted there. `CashflowRecurring`/
`Contract`/`ContractObligation` field lists below are read directly from
`lib/Settings/register.d/zzp-cashflow-13wk.json` and
`contract-lifecycle-management.json` (`python3 -c "json.load(...)"` against
`components.schemas`), not assumed.

**Why no migrator is needed here, unlike `budget-core-schema`'s `Budget`
rename.** `budget-core-schema` needed a migrator because two fragments
declared the *same* schema slug (`Budget`) and collided into one merged,
half-broken schema — live objects existed under a name that had to be split.
Nothing here collides: `CashflowRecurring` gains two *additive* nullable
fields (no rename, no removed field, no changed `required` list — every
existing live `CashflowRecurring` object remains valid against the new
schema with both new fields simply absent/null), and `BudgetLineDerivation`
is a brand-new slug no other fragment declares. There is no source slug to
re-point and no target count to guard — `SubsidieOrderConsolidationMigrator`'s
own `subsidieMigrationRequired(): false` reasoning applies for the identical
underlying reason (no orphaned objects), just without even a definitions
merge involved.

## 1. Why `CashflowRecurring`, not a new schema

### 1a. Field-by-field fit, checked before reuse

| Task-brief need | `CashflowRecurring` field | Fit |
|---|---|---|
| Running contract, possibly indefinite | `validFrom` (required), `validTo` (nullable = indefinite) | Exact — "tills or without an enddate" is literally `validTo: null` |
| Dated future cost, no contract yet | `validFrom` in the future, `validTo` null or set | Exact — nothing in the schema requires `validFrom` to be in the past |
| GL account to book against | `accountNumberExpense` → `Account.accountNumber` | Exact — already an FK to the same `Account` schema `LedgerGroup` resolves against |
| Recurs on a schedule | `frequency` (`WEEKLY..ANNUALLY`), `dagFromMonth`, `monthOfYear` | Exact, though this change only needs **monthly** granularity (§5b) |
| Indexed over time | `indexationRule` (`FIXED`\|`CPI_PAST_YEAR`) | Present, but unusable as declared — no rate field exists anywhere (§3) |
| Categorised | `category` (8-value enum) | Exact, unused by this change's own arithmetic but preserved for the operator's own reporting |
| Direction (cost vs. income) | `direction` (`IN`\|`OUT`) | Exact — mirrors `LedgerGroup`'s member `Account.accountType` sign story `budget-grid-view design.md` §2d already established |
| Tenant scope | `administrationId` (required) | Exact — same scope every `budget-core-schema` reader already filters on |

Every field the task brief's known-cost requirement needs already exists on
`CashflowRecurring` except a Contract link and a usable CPI rate. Inventing a
parallel schema (`KnownCost`, `PlannedCost`, …) that re-declares `label`/
`category`/`direction`/`frequency`/`standardAmount`/`validFrom`/`validTo`/
`accountNumberExpense` a second time would violate this repo's own "an
expression of a pattern matches the pattern" convention for no gain — it
would need its own guard reimplementing `CashflowRecurringGuard`'s window/
anchor checks, its own seed data, and would leave two schemas an operator
must choose between for the same real-world fact ("this cost recurs from
date X"). Extending the existing schema by two additive fields is smaller,
safer, and gives `budget-scenarios` (§1d) one primitive to point modifiers
at instead of two.

### 1b. Scope note: `enterpriseId` vs. `administrationId`

`CashflowRecurring` carries **both** `administrationId` (tenant/bookkeeping
scope, what every `budget-core-schema` reader filters on) and `enterpriseId`
(FK to the ZZP onderneming/corporation, a `bookkeeping-cashflow-13wk`-owned
concept this change does not use). `KnownCostReader` (§6) filters on
`administrationId` only, exactly like `BbvProgrammeBudgetReader`/
`BudgetVsActualsReader`/`BudgetProjectionReader` all already do — `enterpriseId`
is read straight through into the derived `BudgetLine`'s audit trail
(§4's `BudgetLineDerivation.contributingRecurIds`, traceable back to the
source row) but never filtered on, so this change works identically for a
`CashflowRecurring` row regardless of which onderneming it belongs to.

### 1c. Why not a `ContractBudgetLink` join schema instead

A join schema (`Contract` × `accountNumber`, its own frequency/amount/
indexation fields) was the first design considered and rejected: it would
duplicate every field `CashflowRecurring` already has, just keyed
differently, and would leave the "cost with no contract yet" case needing a
*second* schema on top of that (a join schema has nothing to join when there
is no contract). The `contractReference` FK is strictly smaller: one nullable
field, one schema, one guard, one expander for both cases. The trade-off,
stated plainly: an operator representing "a contract's cost" now creates one
`CashflowRecurring` row per contract's payment stream (not a `Contract` field
edit), which means a `Contract` with an unusual multi-stream payment
schedule (e.g., a lease with both a fixed rent stream and a separate
service-charge stream) needs two `CashflowRecurring` rows, both pointing at
the same `contractReference` — this is treated as a feature, not a
limitation: `KnownCostBudgetWriter` (§8) already sums every `CashflowRecurring`
row targeting the same `LedgerGroup`, so multiple streams per contract are
handled without any extra schema shape.

### 1d. The dated planned-cost primitive — ownership, stated explicitly

**`budget-known-costs` owns the dated planned-cost primitive: a
`CashflowRecurring` row with `contractReference = null`.** `budget-scenarios`
(sibling change) is told, in its own `design.md`, to point its modifiers at
this exact primitive (by `recurId`) rather than declaring a second one. This
sentence is the single source of truth for that ownership question — neither
change should restate or re-decide it.

## 2. `ContractObligation` — checked, not sufficient alone

`ContractObligation` (`obligationType`, `dueDate`, `recurrence`,
`responsible`, `status`) answers *when* something is due and *whether* it is
done; it has no `amount` field and no GL-account field
(verified against the full property list in `proposal.md`'s Why section).
A `payment`-typed `ContractObligation` with `recurrence: monthly` tells an
operator "a payment is due every month," never *how much* — using it alone
to drive `BudgetLine.month01Amount..month12Amount` would require inventing
an amount field on a schema this change does not own (`contract-lifecycle-
management`, a different capability), the exact kind of cross-capability
schema edit `budget-grid-view`/`budget-projection-engine` both avoided doing
to `budget-core-schema`. `ContractObligation` is left untouched by this
change; a future change MAY still use it as a deadline/compliance signal
(e.g., "obligation overdue" surfaced next to a derived `BudgetLine`), which
is explicitly out of this change's own scope (§12).

## 3. CPI indexation — an operator-supplied rate, not a live feed

### 3a. The pre-existing gap, found while reading the schema

`CashflowRecurring.indexationRule = CPI_PAST_YEAR` has **no rate field
anywhere on the schema** — verified against the full property list
(`proposal.md`'s Why section). No CBS-CPI integration exists anywhere in
this repo (`grep -rli "cpi\|consumentenprijsindex" lib/Settings/register.d/*.json`
finds three fragments; only `bookkeeping-ifrs-16-lease.json` has a
usable rate field, and it is free text, not `CashflowRecurring`'s own
concern). This means `CPI_PAST_YEAR` has never been mechanically computable
as declared, independent of anything this change does — a genuine
pre-existing gap, not introduced here, closed minimally because this change
is the first consumer that actually needs to compute an indexed amount.

### 3b. The fix: `cpiRatePercent`, following the IFRS-16 precedent's shape

`CashflowRecurring` gains **`cpiRatePercent`** (nullable number, e.g. `2.5`
meaning 2.5% per annum) — an **operator-supplied** rate, exactly the
IFRS-16 lease schedule's own `indexationRateOrSource` precedent
("Fixed percent... or index reference"), except numeric here because
`KnownCostScheduleExpander` (§7) needs to compound it programmatically, not
merely display it. `indexationRule = CPI_PAST_YEAR` with `cpiRatePercent`
null is a declared-but-unusable state the expander must handle explicitly,
never silently treating it as 0% (§7c). This change does **not** add a live
CBS-index feed — fetching a real CBS consumer-price-index figure is a
distinct integration (an external HTTP source, credentials, a refresh
cadence) with no existing precedent anywhere in this codebase to build on,
and is out of this change's own scope (§12).

### 3c. Guard extension — `contractReference` bounds

`lib/Guard/CashflowRecurringGuard.php::validateOnSave()` gains one new
private check, `hasConsistentContractWindow()`, called alongside the
existing four (amount, anchor, validity window, indexation applicability):
when `contractReference` is set and the referenced `Contract` is resolvable,
`validFrom` MUST be `>=` `Contract.startDate` (when the Contract's own
`startDate` is set) and `validTo` (when set) MUST be `<=` `Contract.endDate`
(when the Contract's own `endDate` is set) — open on whichever side the
Contract itself leaves open, fail-closed (deny save) otherwise, logged the
same way every existing check in this guard already logs
(`recurId` context, `LoggerInterface::info`). This directly serves
"honouring end dates" from the task brief: a recurring cost cannot silently
outlive — or predate — the contract it is linked to.

## 4. `BudgetLineDerivation` — the idempotency + override ledger

### 4a. The problem this solves

Re-running the expander must not double-count (task brief, verbatim). A
naive writer that, on every run, creates a fresh `BudgetLine(source:
"recurring")` for every matching `LedgerGroup` would create a second,
third, … row on every re-run — `budget-grid-view`'s own row model sums
every `BudgetLine` targeting a `LedgerGroup` (§8's coordination note to that
change), so duplicate rows would silently inflate the displayed budget on
every regeneration. Separately, the task brief asks whether an operator can
override a derived line — if they hand-edit a derived `BudgetLine`'s
amounts, the next regeneration run must not silently clobber that edit back
to the machine-computed value (an autofix that changes meaning without a
trace is exactly the failure mode this repo's own working conventions warn
against).

### 4b. Fields

```
BudgetLineDerivation
  administrationId            string,  required
  annualBudgetId                string,  required, format: uuid — FK to AnnualBudget
  ledgerGroupId                  string,  required, format: uuid — FK to LedgerGroup
  sourceType                      string,  required, enum [contract, recurring] — matches the
                                            BudgetLine.source value this derivation wrote
  budgetLineId                    string,  required, format: uuid — FK to the BudgetLine
                                            object this derivation run created/last upserted
  contributingRecurIds            array<string>, required — every CashflowRecurring.recurId
                                            summed into this line (audit trail / drill-down,
                                            per-member auditability precedent, budget-
                                            projection-engine design.md §5a.3)
  lastGeneratedMonthlyAmounts   array<integer>, required, length 12 — the exact cents this
                                            derivation last wrote, the drift-detection fingerprint
  lastGeneratedAt                 string,  required, format: date-time
  overridden                       boolean, required, default false — set true once a run
                                            detects the live BudgetLine no longer matches
                                            lastGeneratedMonthlyAmounts (§8c)
```

One `BudgetLineDerivation` row exists per `(annualBudgetId, ledgerGroupId,
sourceType)` triple that has ever been machine-generated — never per
`CashflowRecurring` row, so multiple recurring rows targeting the same
`LedgerGroup` still resolve to exactly one derivation row and one
`BudgetLine` row (§1c, §8b).

No lifecycle — this is a system-managed bookkeeping row, written only by
`KnownCostBudgetWriter`, never by an operator directly (mirrors `LedgerGroup`'s
own "configuration, not a workflow object" treatment, though for a different
reason: this is *derived* state, not operator-authored configuration).
`x-openregister-audit-trail.enabled: true` per REQ-AT-001 (every bookkeeping
schema in this codebase carries it — `tests/validate-registers.js` enforces
this).

## 5. `KnownCostReader` — batched store access

Mirrors the reader/calculator/orchestrator split every sibling change in
this wave already uses (`BbvProgrammeBudgetReader`/`Calculator`,
`BudgetVsActualsReader`/`Calculator`, `BudgetProjectionReader`/`Calculator`).
`ObjectServiceInterface` DI (ADR-083/084), the only class in this change
with store access:

1. `CashflowRecurring.findAll(filters: [administrationId])` — once.
2. `Account.findAll(filters: [administrationId])` — once. Resolves
   `accountNumberExpense` → `accountType` and, together with (3), `LedgerGroup`
   membership — the identical range + explicit include/exclude resolution
   `budget-core-schema design.md` §3a already specifies, **reimplemented**
   here rather than shared, per the same deliberate-duplication decision
   `budget-projection-engine design.md` §5d already made and justified for
   the identical reason (editing a sibling's already-spec'd reader is out of
   scope; this is a small, low-risk algorithm to reimplement once more).
3. `LedgerGroup.findAll(filters: [administrationId])` — once.
4. `AnnualBudget.findAll(filters: [administrationId])` — once. Used to
   resolve, per fiscal year touched by any in-scope `CashflowRecurring`
   row's `validFrom`/`validTo` span, whether a **default** (`isDefault:
   true`) `AnnualBudget` exists for that year (§8a).
5. `BudgetLine.findAll(filters: [annualBudgetId: ['in' => [...]]])` — once,
   scoped to the `AnnualBudget` ids resolved in (4) — the `SpendAnalyticsService.php:183`
   `in`-filter precedent, same as `budget-grid-view design.md` §1c.
6. `BudgetLineDerivation.findAll(filters: [annualBudgetId: ['in' => [...]]])`
   — once, same scoping as (5).

**Query count: exactly 6 `findAll()` calls per run, independent of the
number of `CashflowRecurring` rows, `LedgerGroup`s, or fiscal years in
scope.** A PHPUnit call-count regression test against a mocked
`ObjectServiceInterface` asserts this bound, mirroring every sibling
reader's own query-budget test (`budget-projection-engine design.md` §8,
`budget-core-schema design.md` §6b).

## 6. `KnownCostScheduleExpander` — pure, no store access

Mirrors `BbvProgrammeBudgetCalculator`'s "it reads NOTHING" contract exactly
— no constructor dependency on `ObjectServiceInterface` or any OpenRegister
type. Public surface:

```
expand(
  recurring: CashflowRecurring-shaped array,
  fiscalYear: int,
  contract: Contract-shaped array|null
): array<string, int>   // "01".."12" => cents for that fiscal year
```

### 6a. Monthly granularity, not the 13-week engine's weekly one

`BudgetLine` has 12 monthly slots; `CashflowRecurring`'s own
`dagFromMonth` (day-of-month precision) is irrelevant at this granularity —
the expander only needs *which calendar month(s)* a recurrence lands in for
the requested `fiscalYear`, never which day. This is a deliberate,
narrower re-derivation of the same schedule fields the (unbuilt, §0) 13-week
weekly expansion would have needed — this change does not build that
weekly engine and does not touch `CashflowWeek`/`CashflowForecastHorizon`.

### 6b. Frequency → months-in-scope

```
WEEKLY | FORTNIGHTLY             -> exact per-occurrence date enumeration, per month, per §6d
                                    (RULING 2, 2026-08-20 — supersedes an earlier averaged-factor
                                    draft; see §6d for the algorithm and why an average was rejected)
MONTHLY                           -> every calendar month within [validFrom, validTo] ∩ fiscalYear;
                                    standardAmount books once per in-scope month, unchanged
QUARTERLY                        -> the 3 calendar months of each quarter containing a
                                    dagFromMonth-less quarter anchor is not modelled; the
                                    amount is spread EVENLY across the 3 months of each quarter
                                    inside [validFrom, validTo] ∩ fiscalYear (standardAmount ÷ 3
                                    per month), per §6d
ANNUALLY                         -> the single calendar month `monthOfYear` within
                                    [validFrom, validTo] ∩ fiscalYear, if that month falls
                                    inside the window; standardAmount books whole in that month
```

### 6c. `validFrom`/`validTo` bounding — the exact task-brief requirement

A month strictly before `validFrom`'s calendar month, or strictly after
`validTo`'s calendar month (when `validTo` is set), contributes `0` — not
because the account is "unprojectable" (§`budget-projection-engine`'s own
concept, not reused here — a known cost with no valid data for a month
genuinely budgets nothing that month, which **is** the correct value, not a
missing one). `validTo: null` (indefinite/"tills") means every month from
`validFrom` onward, across every fiscal year, is in scope — the schedule
never self-terminates. This directly implements "running contracts (tills or
without an enddate)" and "budgeted from date X" from the task brief.

### 6d. `standardAmount` is not divided by frequency for `WEEKLY`/`FORTNIGHTLY`/`MONTHLY`

`CashflowRecurring.standardAmount`'s own description is "Base amount in
EUR" for one occurrence at the declared `frequency` — for the 13-week
engine that base amount is booked once per week/fortnight/month as it
occurs. This change's monthly `BudgetLine` slot needs one **monthly-total**
figure, so:

- `MONTHLY`: `standardAmount` books once per in-scope month, unchanged —
  already monthly.
- `WEEKLY`/`FORTNIGHTLY`: **RESOLVED 2026-08-20 (RULING 2) — exact
  per-occurrence date enumeration, never an averaged monthly factor.** An
  earlier draft of this document proposed a `needsOperatorInput` deferral
  here (open question §13.1, now struck through — see there); that was
  rejected: a 52/12 ≈ 4.33 (weekly) or 26/12 ≈ 2.17 (fortnightly) averaged
  multiplier would silently misstate any specific month, and the begroting
  grid's entire purpose is comparing a monthly budgeted figure against that
  same month's actual — a month is not "the average month," it is a real
  4-or-5-Monday month with a real cash difference between the two. The
  algorithm instead **enumerates the actual occurrence dates**:
  1. `CashflowRecurring.dagFromMonth`'s own field description states it is
     "Day of month for monthly recurrence; **null for other frequencies**"
     — verified against the schema (`proposal.md`'s Why section) — so
     `WEEKLY`/`FORTNIGHTLY` rows carry no day-of-month anchor at all. The
     anchor is instead the row's own `validFrom` date: the first occurrence
     is `validFrom` itself, and every subsequent occurrence is `validFrom +
     7×k` days (`WEEKLY`) or `validFrom + 14×k` days (`FORTNIGHTLY`) for
     `k = 1, 2, 3, …`, up to and including the last occurrence on or before
     `validTo` (or unbounded when `validTo` is null, per §6c).
  2. For a requested `fiscalYear` and calendar month, the expander counts
     how many of those exact occurrence dates fall inside that month, and
     books `standardAmount × <that count>` — a month containing 4
     Mondays and a month containing 5 Mondays (the same weekday, the same
     row, both real months within one indefinite recurrence) genuinely
     receive different totals, and MUST, per this ruling.
  3. Indexation (§6e) is applied to the per-occurrence `standardAmount`
     before multiplying by the month's occurrence count — not to the
     already-summed monthly total — so a CPI step-up mid-year still applies
     uniformly to every occurrence in every month of its fiscal year,
     exactly as §6e already specifies for `MONTHLY`/`QUARTERLY`/`ANNUALLY`.
  Seed data for this wave (§9) uses only `MONTHLY`/`QUARTERLY`/`ANNUALLY`
  recurrences, so this path is exercised by dedicated unit tests (including
  a 5-occurrence-month fixture, `tasks.md` group 5) but not by day-one seed
  data.
- `QUARTERLY`: divided evenly across the quarter's 3 months (§6b) — this
  is a stated convention (not silently assumed), consistent with how a
  begroting operator would naturally spread a quarterly bill for planning
  purposes; the *actual* GL posting (a real quarterly invoice landing in one
  real month) is unaffected — this is budgeted, not actual, figures.
- `ANNUALLY`: booked whole in `monthOfYear`, unchanged.

### 6e. Indexation — `FIXED` vs. `CPI_PAST_YEAR`

`FIXED`: `standardAmount` applies unchanged in every in-scope month,
regardless of fiscal year.

`CPI_PAST_YEAR` with `cpiRatePercent` set: the amount compounds once per
**calendar year** relative to `validFrom`'s own year —
`amountForYear(Y) = standardAmount × (1 + cpiRatePercent/100)^(Y -
validFromYear)`, applied uniformly to every in-scope month of fiscal year
`Y` (not re-derived per month — mirrors `budget-projection-engine design.md`
§2g's own "one rate, compounded forward, never re-estimated per step"
discipline, reused here for consistency across the begroting wave's
arithmetic style, integer cents, rounded once per computed value, never
mid-chain). `Y < validFromYear` never occurs (§6c already excludes months
before `validFrom`).

`CPI_PAST_YEAR` with `cpiRatePercent` null (§3b's declared-but-unusable
state): the expander returns a typed `needsOperatorInput` result — an
amount it cannot compute without more operator-supplied information — for
every in-scope month, regardless of frequency. This is now the **only**
case in this class (§6d's earlier `WEEKLY`/`FORTNIGHTLY` use of the same
typed result was superseded by RULING 2's exact-date-enumeration algorithm,
which computes a real number for those frequencies instead). The expander
never silently substitutes `FIXED`, which would be a wrong number wearing a
right one's shape.

## 7. Fiscal-year and default-`AnnualBudget` resolution

A `CashflowRecurring` row's `[validFrom, validTo]` span may cross several
fiscal years (an indefinite lease spans every year forever). For each
calendar year the span touches, `KnownCostBudgetWriter` (§8) looks up
whether a **default** `AnnualBudget` (`isDefault: true`,
`budget-core-schema` §4b) exists for that `administrationId` + fiscal year —
reusing exactly the same "each fiscal year independently resolves its own
default `AnnualBudget`" rule `budget-grid-view design.md` §2b already
established for its column model, so this change's writer and that change's
reader agree on the same rule rather than inventing a second one. **A
fiscal year with no default `AnnualBudget` is skipped — no `BudgetLine` is
written for it, and no `AnnualBudget` is created by this change** (creating
`AnnualBudget` rows is `budget-core-schema`'s own operator-driven concern,
out of this change's scope). This is not an error: an administration that
has not yet set up next year's budget simply has nothing for this change to
populate there yet; the next regeneration run after that `AnnualBudget`
is created picks it up automatically (idempotent by construction, §8).

## 8. `KnownCostBudgetWriter` — idempotent, override-aware orchestration

### 8a. Per-run algorithm

1. `KnownCostReader` loads everything (§5, 6 calls).
2. For every `CashflowRecurring` row: resolve its target `LedgerGroup`
   (§1a's `accountNumberExpense` → resolved `LedgerGroup` membership, §5
   step 2–3) and its `sourceType` (`contractReference` set ⇒ `contract`,
   else `recurring`).
3. Group rows by `(ledgerGroupId, sourceType)`. For every fiscal year with a
   default `AnnualBudget` (§7), call `KnownCostScheduleExpander::expand()`
   for every row in the group and **sum** the returned monthly cents across
   the group's rows — this is the "multiple recurring costs targeting the
   same `LedgerGroup` sum into one derived line" rule (§1c, §4b).
4. For every `(annualBudgetId, ledgerGroupId, sourceType)` combination
   produced by step 3, upsert per §8b/§8c.

### 8b. Upsert — no existing `BudgetLineDerivation`

Create a new `BudgetLine(source: sourceType, month01Amount..month12Amount:
<the summed cents>)`, then create the corresponding `BudgetLineDerivation`
row (`budgetLineId` = the new `BudgetLine`'s id,
`lastGeneratedMonthlyAmounts` = the same 12 values, `lastGeneratedAt` = now,
`overridden: false`).

### 8c. Upsert — existing `BudgetLineDerivation`

Read the current `BudgetLine` at `derivation.budgetLineId`.

- **Missing** (deleted by an operator or by direct API use): treat as
  "target gone" — create a fresh `BudgetLine`/`BudgetLineDerivation` pair
  per §8b. This is the stated reset path: deleting a derived line and
  letting the next run recreate it is how an operator returns a line to
  "fully machine-generated" after having overridden it.
- **Present, and its 12 monthly amounts equal `derivation.lastGeneratedMonthlyAmounts`
  exactly**: not overridden since the last run — overwrite its 12 amounts
  with the freshly computed sum, update `lastGeneratedMonthlyAmounts` and
  `lastGeneratedAt`. **Running this step twice in a row with no upstream
  change produces byte-identical output both times** — this is the
  idempotency property the task brief names explicitly, and the PHPUnit
  regression test in `tasks.md` asserts exactly this (run twice, assert one
  `BudgetLine` row, assert identical values, assert the second run issued
  the same 6-call query budget as the first — no accumulating cost per run).
- **Present, and its 12 monthly amounts differ from
  `derivation.lastGeneratedMonthlyAmounts`**: an operator hand-edited the
  derived line since the last run. Set `derivation.overridden = true`, do
  **not** touch the `BudgetLine`'s amounts, and do not update
  `lastGeneratedMonthlyAmounts` (so future runs keep detecting the same
  divergence, not a moving target). This is the precedence rule the task
  brief asks for: **a direct edit to a derived line, once made, always wins
  over regeneration until the line is deleted (§8c, "missing" case) or a
  future change adds an explicit reset action** (`design.md` §13.2, open
  question — not built here, since nothing in this wave's UI yet exposes an
  "overridden" indicator to act on; `BudgetLineDerivationDetail`, §10, does
  surface the flag read-only).
- **Present, and `derivation.overridden` is already `true`**: skip entirely
  — no read-back comparison, no write. An already-flagged override is left
  alone until the reset path above fires.

### 8d. Coexistence with manual lines — a coordination note for `budget-core-schema`'s consumers

`budget-core-schema` does not state what happens when more than one
`BudgetLine` targets the same `(annualBudgetId, ledgerGroupId)` with
different `source` values (its own §3d rollup rule only addresses
**parent/child** `LedgerGroup`s, a different axis). This change's own
writer never creates more than one `BudgetLine` per `(annualBudgetId,
ledgerGroupId, sourceType)`, but an operator's own **manual** `BudgetLine`
at the same `(annualBudgetId, ledgerGroupId)` can coexist alongside a
`contract`- or `recurring`-sourced one this change wrote. **This change's
own stance, stated explicitly for `budget-core-schema`'s
`BudgetVsActualsReader` and `budget-grid-view`'s `BudgetGridReader`/
`BudgetGridCalculator` to adopt**: the effective budgeted amount for a
`LedgerGroup` node is the **sum across every `BudgetLine` row targeting
it, regardless of `source`** — a manual top-up and a derived baseline add
together rather than one silently shadowing the other. This degrades
gracefully to today's implicit single-row assumption in the common case
(exactly one `BudgetLine` per node) and is the natural one-level extension
of `budget-core-schema` §3d's own "sum the children" rule applied to
siblings-by-`source` at the same node instead of children-by-`parentLedgerGroupId`.
Recorded here as a cross-reference finding for whoever implements those two
readers, per this wave's own established convention
(`budget-projection-engine design.md` §7a's identical "recorded, not
silently corrected in that file" treatment) — not silently assumed, and not
implemented by editing either sibling's own files.

## 9. Seed data

No new seed data ships in this change's own fragment beyond
`BudgetLineDerivation`'s schema declaration (a derived/system row has
nothing meaningful to seed statically — it is produced by running the
writer, not authored). `CashflowRecurring`'s existing seed rows (from
`zzp-cashflow-13wk.json`, `bookkeeping-cashflow-13wk` capability) are left
untouched; this change's two additive fields default to absent on every
existing seed row (`contractReference` null, `cpiRatePercent` null),
matching every existing row's current, already-valid `FIXED`-or-unindexed
shape.

## 10. Minimal pages + nav placement

`BudgetLineDerivations` (index, `/begroting/derivations`, schema
`BudgetLineDerivation`) / `BudgetLineDerivationDetail` (detail,
`/begroting/derivations/:id`) — **read-only in the UI** (the schema itself
is not access-restricted at the OpenRegister level, matching every other
schema in this app, but the pages ship no create/edit form since nothing
in this change's own workflow expects an operator to author a
`BudgetLineDerivation` by hand). Detail page surfaces `sourceType`,
`contributingRecurIds` (linked to their `CashflowRecurring` rows),
`lastGeneratedAt`, and `overridden` — the audit/drill-down surface the task
brief's "an operator asking why is this number this" concern needs,
mirroring `budget-projection-engine design.md` §5a.3's "per-member
auditability" principle.

Nested under the `Budgets` top-level nav group `budget-core-schema` §7b
defines, created by whichever change lands that group first — the identical
either-order convention `budget-grid-view design.md` §8 already established;
this change adds no new top-level group of its own.

## 11. e2e coverage

New Playwright spec `tests/e2e/budget-known-costs.spec.ts` (SPDX header,
`becomesVisible` helper, `test.describe('budget-known-costs — … (REQ-BKC-…)')`,
data-defensive `test.skip()` on empty seed data):

1. `budget-known-costs::recurring-cost-derives-budget-line` — after a
   `CashflowRecurring` row (no `contractReference`) targeting a seeded
   `LedgerGroup`'s account is created and the writer is run, the
   `BudgetLines` index shows a `source: "recurring"` row with the expected
   monthly amount.
2. `budget-known-costs::contract-linked-cost-tags-source-contract` — the
   same, with `contractReference` set, asserting `source: "contract"`.
3. `budget-known-costs::derivation-audit-trail-visible` — the
   `BudgetLineDerivationDetail` page lists the contributing `recurId`(s) and
   `lastGeneratedAt`.

Backend-only, `@e2e exclude`:

- `KnownCostScheduleExpander`'s full arithmetic (§6) — PHPUnit, pure
  calculator, no browser-visible surface, mirroring
  `BudgetProjectionCalculator`'s own treatment.
- The idempotent-regeneration property (§8c, run twice, byte-identical) —
  PHPUnit against `KnownCostBudgetWriter` with a mocked
  `ObjectServiceInterface`.
- The override-detection property (§8c) — PHPUnit, a fixture where the
  mocked `BudgetLine` read-back diverges from the fingerprint.
- `CashflowRecurringGuard`'s new contract-window check (§3c) — PHPUnit,
  extending the existing `CashflowRecurringGuardTest` (or a new test class
  if the existing one is not easily extended — the implementer confirms
  which by reading it first).
- The query-count regression (§5) — PHPUnit, mirroring every sibling
  reader's own test.

## 12. Non-goals (each names its follow-up change)

- **The spreadsheet-grid UI** rendering these derived lines —
  `budget-grid-view`. This change's own pages (§10) are plain audit
  CRUD-shaped read views, not the grid.
- **Projection/growth-rate math** — `budget-projection-engine`. This
  change writes `source: "contract"`/`"recurring"` only; `source:
  "projected"` remains unpopulated.
- **Scenario/modifier support** — `budget-scenarios`. This change's
  `contractReference`-taggable `CashflowRecurring` primitive and pure
  `KnownCostScheduleExpander` are the two things that change is told,
  explicitly, to reuse rather than reinvent (§1d).
- **Charts** — `budget-charts`.
- **A live CBS-CPI feed** — `cpiRatePercent` is operator-supplied (§3b);
  fetching a real index value is a distinct, unscoped integration.
- **Any change to `bookkeeping-cashflow-13wk`'s own weekly forecast** — no
  `CashflowWeek`/`CashflowForecastHorizon`/`CashflowARProjection`/
  `CashflowBufferPolicy` field is touched; the two additive
  `CashflowRecurring` fields are the entire footprint in that fragment.
- **A scheduled/cron re-run of the writer** — flagged as an open question
  (§13.4), not decided here: `Contract`'s own `x-scheduled-workflow`
  primitive (`OR.ScheduledWorkflow`) is declared specifically for
  time-based *lifecycle-state* transitions, not arbitrary batch
  recomputation, so reusing it here would be force-fitting a primitive
  outside its stated purpose rather than following an actual precedent —
  this change ships an operator-triggered run only.

## 13. Open questions

1. **RESOLVED (2026-08-20, RULING 2)** — ~~Weekly/fortnightly
   monthly-equivalent conversion~~: §6b/§6d now answer this. No averaged
   factor is used; the expander enumerates the actual occurrence dates from
   `validFrom` stepped by 7/14 days and counts how many fall in each
   requested month, because a 4-vs-5-occurrence month is a real cash
   difference an average would silently misstate, and the begroting grid's
   purpose is comparing a monthly budget to that same month's actual. Left
   in place, struck through, so a reader comparing against an earlier read
   of this document can see the question was answered, not silently
   deleted.
2. **Reset action for an overridden derived line** (§8c) — deleting the
   `BudgetLine` is today's only reset path; a dedicated "reset to
   generated" button is a plausible, small follow-up once
   `BudgetLineDerivationDetail` (§10) has real usage to learn from.
3. **`budget-core-schema`/`budget-grid-view`'s multi-source-sum consumer
   contract** (§8d) — this change states the rule its own output needs
   those readers to follow; it does not implement it in either sibling's
   files. Needs the orchestrator to confirm whoever implements those
   changes has seen this note.
4. **Scheduled re-run mechanism** (§12) — an operator-triggered run only,
   today. Whether a genuine scheduled-batch primitive should be added to
   this app (distinct from `OR.ScheduledWorkflow`'s lifecycle-transition
   purpose) is a platform question, not decided here.

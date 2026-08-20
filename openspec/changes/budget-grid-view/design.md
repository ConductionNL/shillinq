# Design: budget-grid-view

## 0. Method

Every claim below was verified directly against this checkout (2026-08-20)
— file paths, line numbers, and schema fields are read, not assumed.
`budget-core-schema`'s `proposal.md`/`design.md`/`tasks.md`/`specs/` were
read in full before writing this document; every schema referenced below
(`LedgerGroup`, `AnnualBudget`, `BudgetLine`) is used exactly as
`budget-core-schema` §3-§5 defines it — no field is added, renamed, or
reinterpreted. Where this document proposes something `budget-core-schema`
did not decide (the subtotal-row mechanism, the sign convention, the
nc-vue-vs-app-local call), it says so explicitly and grounds the decision in
a precedent already in the codebase, per this repo's own "an expression of a
pattern matches the pattern" convention — not an invented shape.

## 1. Row model

### 1a. Root rows: top-level `LedgerGroup`s

The grid's rows are the operator's current administration's `LedgerGroup`
tree (`administrationId` scoped, per `budget-core-schema` §3b — the grid
does not add its own administration selector; it reads whichever
administration `AdministratieSwitcher`
(`src/components/AdministratieSwitcher.vue`,
`bookkeeping-multi-administratie`) has currently selected, the same
session-scoped mechanism every other page in this app already uses).

Root rows are every `LedgerGroup` with `parentLedgerGroupId === null` for
that administration, ordered by `order`. This matches the user's mental
model directly: "Omzet", "Personeel", "Huisvesting", "ICT" are each a
root-level verzamelpost.

### 1b. Expand reveals children OR resolved accounts — never both

Per the user's own description ("click a verzameling to toggle it and
expand into the grootboek numbers"), and `LedgerGroup`'s own nestable shape
(`parentLedgerGroupId`, `budget-core-schema` §3b), a row's expand action
reveals exactly one of:

- **Child `LedgerGroup`s** — when one or more `LedgerGroup`s exist with
  `parentLedgerGroupId` equal to this row's id. Each child row is itself
  expandable, recursively, following the same rule.
- **Resolved member `Account`s** (the leaf case) — when the `LedgerGroup`
  has no children. Members are resolved exactly as `budget-core-schema` §3a
  already specifies (`accountRanges` ∪ `includedAccountNumbers` minus
  `excludedAccountNumbers`, evaluated in PHP) — this change's reader calls
  the same resolution logic `BudgetVsActualsReader` already implements
  (`budget-core-schema` tasks.md group 8), it is not re-derived here.

A `LedgerGroup` with children is never expected to also carry its own
directly-resolved accounts in this UI (nothing in `budget-core-schema`'s
schema forbids a group having both children AND range/include entries, but
the grid's row model shows children when present and only falls back to
resolved accounts when there are none — a group that has both is flagged as
a data-authoring smell for a future validation, not silently double-counted
here: `tasks.md` records this as an open question, `design.md` §9.3).

### 1c. Zero-query expand — the query budget

Every `LedgerGroup` for the current administration is fetched **once**,
upfront, in a single `findAll(['filters' => ['administrationId' => ...]])`
call (mirroring `TrialBalanceService::fetchAccounts()`,
`lib/Service/TrialBalanceService.php:270-274`, which does the equivalent
single-batch fetch for `Account`). The client-side tree
(parent→children index, keyed by `parentLedgerGroupId`) is built once from
that single batch. **Toggling a row's expand state is a pure client-side
operation — it issues zero additional network requests.** This is the
concrete answer to the query-budget requirement: the fleet has a recorded
incident (`reference_facet-composition-multiplies-page-queries.md`) where a
widget issued one query per rendered row (16-18 queries/page, ~1s each);
this grid's row count can be dozens of `LedgerGroup`s deep with many
expand/collapse toggles per session, and none of them may cost a query.

Member-account resolution (§1b's leaf case) is **not** a per-row query
either: the reader resolves membership for every `LedgerGroup` in the same
upfront batch, against a single `findAll` of `Account` for the
administration (same idiom, one call) — resolved member lists are computed
once server-side and shipped with the initial payload, not fetched
on-demand per expand.

**Total query budget for one grid render**, independent of row count or how
many rows the operator expands:

| Query | Count | Precedent |
|---|---|---|
| `LedgerGroup` for the administration | 1 | `TrialBalanceService::fetchAccounts()` pattern |
| `Account` for the administration (member resolution) | 1 | same |
| `BudgetLine` for the `AnnualBudget`(s) in the displayed range, `annualBudgetId` via `['in' => [...]]` | 1 | `SpendAnalyticsService.php:183`'s `'state' => ['in' => self::AP_SPEND_STATES]` — confirms OpenRegister's `findAll` filter dialect supports an `in` operator, so every fiscal year in a multi-year range is one call, not N | 
| `FiscalPeriod` for the administration, filtered to the displayed range | 1 | same batching principle |
| `TrialBalanceLine` per **past column with a matching `FiscalPeriod`** (§2), `periodId` scoped | ≤ P (P = number of past/closed columns actually displayed, typically ≤12 for a one-year view) | `TrialBalanceService::movementsByAccount()` (`lib/Service/TrialBalanceService.php:187-216`) already queries `TrialBalanceLine` per `(administrationId, periodId)` pair, one call per period, not per account — this reader follows the same shape |

So the total is `4 + P` findAll calls for the entire grid, where `P` is
bounded by the displayed column count, not by row count — expanding every
`LedgerGroup` in the tree costs nothing further.

## 2. Column model — timeframe, granularity, and the past/future boundary

### 2a. Any period range, not just a calendar year

The grid's header exposes a `startPeriod`/`endPeriod` range (any two
calendar months, inclusive) and a `granularity` (`month` default; `quarter`;
`year`). Columns are generated by iterating the range at the chosen
granularity — this is the "view the begroting in any time frame" requirement.
There is no requirement that the range be a full fiscal year or align to
any `AnnualBudget`'s own `fiscalYear`.

### 2b. Fiscal-year-crossing rule

`AnnualBudget`/`BudgetLine` are scoped to exactly one `fiscalYear`
(`budget-core-schema` §4a/§5a — one `AnnualBudget` per administration per
fiscal year, one set of `BudgetLine`s per `AnnualBudget`). When the
displayed range spans more than one fiscal year (e.g., Nov 2026 - Feb
2027), **each column independently resolves the default `AnnualBudget`**
(`isDefault: true`, per `budget-core-schema` §4b's one-default invariant)
**for its own calendar month's fiscal year** — there is no single
`AnnualBudget` object that spans the range, and this change does not
invent one. If no default `AnnualBudget` exists for a fiscal year the range
crosses into, that column's budget value renders as an explicit dash/empty
state (`—`, not `0` — the two are different: `0` says "budgeted nothing for
this account", empty says "no budget exists yet for this year at all"), and
the column still renders actuals if its `FiscalPeriod` is closed
(actuals are independent of whether a budget exists).

### 2c. The past/future boundary rule

A column is **past** — and shows actuals + deviation — when a `FiscalPeriod`
exists for the administration whose `startDate`/`endDate` exactly match the
column's own calendar span, **and** that `FiscalPeriod`'s `state` is
`closed` or `audit-locked` (`bookkeeping-period-close.json`, `state` enum
`open → closing → closed → audit-locked`). `open` and `closing` do **not**
count as past — the period's own actuals are not final yet, so the column
still shows budget only (showing partial, still-moving actuals next to a
fixed budget would misrepresent "how close we got").

This is a code-answerable rule, not a `today`-relative one, deliberately:
`FiscalPeriod.state` is the only signal in this codebase that means "this
period's books are actually closed" (`GLTransaction.post` already refuses
postings against a closed/audit-locked period per the same schema's own
description) — `today >= column end date` would show actuals for a period
that is calendar-past but not yet closed, which do not exist yet
(`TrialBalanceLine` is computed from posted `GLTransaction`s only, and a
still-open period can still receive postings).

**Granularity/cadence mismatch**: if the administration's `FiscalPeriod`
cadence does not align with the requested column granularity (e.g., the
administration only closes quarterly `FiscalPeriod`s but the grid is shown
at month granularity — the SMB seed data in `bookkeeping-trial-balance.json`
is exactly this shape, `periodId: "2026-Q1"`), there is no exact-span
`FiscalPeriod` for an individual month, so **that column falls back to
budget-only display with an explicit "actuals not available at this
granularity" indicator** rather than apportioning the quarter's actuals
across its three months — apportionment requires an allocation policy
(equal thirds? weighted by budget phasing?) this change does not invent;
it is out of scope (`design.md` §11, non-goals).

### 2d. Actuals-vs-budget deviation and the sign convention

This is the requirement the task brief explicitly warns "getting this wrong
inverts the whole screen" — so it is derived from a precedent already
encoded in this codebase, not invented: `rj270-pl.json` (`lib/Settings/
statements/rj270-pl.json:17-38`) tags every P&L section with an explicit
`sign: "credit"` (revenue-shaped: `opbrengsten`, `financieel` income rows)
or `sign: "debit"` (cost-shaped: `kosten`, `financieel` expense rows,
`belasting`) — the same distinction `Account.accountType`/
`TrialBalanceLine.accountType` (`revenue` vs `expenses`) already carries per
account.

**`LedgerGroup` itself carries no sign/kind field** (`budget-core-schema`
§3b's fixed field list has none, and this change does not add one — that
would be redesigning `budget-core-schema`'s schema). The sign is therefore
resolved **per resolved member account**, from that account's own
`accountType`, before summing into the row's total — not from a
`LedgerGroup`-level attribute:

- **`accountType: "revenue"`** (and, by the same "more is better" logic,
  any account whose booked amounts are inherently a receipt) — deviation =
  `actual − budget`. Positive = favorable (revenue came in **above**
  budget); negative = unfavorable (fell short).
- **`accountType: "expenses"`** — deviation = `budget − actual`. Positive =
  favorable (spent **less** than budgeted); negative = unfavorable
  (overspent).
- **`accountType: "assets"|"liabilities"|"equity"`** — these are
  balance-sheet stocks, not P&L flows; "budgeting a balance" does not carry
  the same favorable-direction semantics `BudgetLine`'s monthly-phased
  amounts imply for a flow. The deviation is still computed (`actual −
  budget`, no favorable/unfavorable framing applied) but is flagged as an
  **open product question** (`design.md` §11.2) rather than an invented
  convention — this only matters today because `budget-core-schema`'s own
  day-one seed is balance-sheet-shaped (§0's finding); once/if a P&L-shaped
  seed exists this case is rare in practice.

A `LedgerGroup` whose resolved members mix `revenue`/`expenses` types
(uncommon given the RJ270 section shape, but not schema-forbidden) shows a
row-level deviation that is the sum of each member's own correctly-signed
deviation — never a single row-wide sign applied to a mixed sum.

Every deviation cell renders its favorable/unfavorable state as **explicit
text** ("onder begroting" / "boven begroting" or equivalent, plus the
signed number), not colour alone — the fleet's existing
`BudgetLineCommitments.vue` precedent already does exactly this
(`t('shillinq', 'Authorized')`/etc. as text-labelled columns, "WCAG 2.1 AA
per the spec's Non-Functional Requirements" per its own header comment) and
this change follows the same rule.

## 3. `TOTAAL` — the cumulative final column

Per the task brief verbatim: "Last column of the begroting should be the
cumulative totals for the period (begroot and actuals for a running
period)." This is **two** sub-values per row in the final column, not one:

- **Begroot cumulative** — Σ budget amount across **every** column
  currently displayed (unconditional; a future month's planned budget
  contributes even though it hasn't happened yet — this is "what did we
  plan for the whole displayed range").
- **Werkelijk cumulative (running/YTD)** — Σ actual amount across only the
  columns that are **past** per §2c (closed/audit-locked `FiscalPeriod`);
  a column that is future or cadence-mismatched (§2c) contributes nothing
  to this sub-value — it genuinely does not exist yet, and showing a
  provisional number here would misrepresent "how close we got" for a
  period not yet closed.
- **Cumulative deviation** — computed from the two cumulative sub-values
  using the same §2d sign convention, not by summing each column's
  already-computed per-column deviations (arithmetically equivalent for a
  single account, but computed from the cumulative pair directly so the
  same formula path is used everywhere and a future rounding change only
  needs to be made once).

This exactly matches the spreadsheet's own "TOTAAL" column, which the user
described as carrying both a full-year budgeted figure and a running actual.

## 4. Subtotal / derived rows — a page-config concern, resolving `budget-core-schema`'s own open question

`budget-core-schema` design.md §11.1 left open whether RJ270 `level: 0`/
`level: 1` rollup rows should become their own parent `LedgerGroup`s. This
change does not decide that question for `budget-core-schema`'s schema —
instead it answers the narrower question this change actually needs: **the
user's Bruto Marge / Kosten / Bedrijfsresultaat / % rows are a presentation
concern of the grid page, not a `LedgerGroup` at all**, precedented exactly
by `rj270-pl.json`'s own `level: 0` rows (`SOM-OPB`, `SOM-KOS`, `BEDR-RES`),
which are declared in the **statement config**, not as their own
`Account`/`LedgerGroup` records:

```jsonc
// BudgetGrid page's own manifest config — NOT a LedgerGroup schema field
"computedRows": [
  { "code": "BRUTO-MARGE", "label": "Bruto Marge",
    "formula": "group:Omzet - group:Kostprijs-Omzet" },
  { "code": "KOSTEN", "label": "Kosten",
    "formula": "sum-group:kosten" },
  { "code": "BEDRIJFSRESULTAAT", "label": "Bedrijfsresultaat",
    "formula": "row:BRUTO-MARGE - row:KOSTEN" },
  { "code": "BEDRIJFSRESULTAAT-PCT", "label": "% van omzet",
    "formula": "row:BEDRIJFSRESULTAAT / group:Omzet", "asPercent": true }
]
```

`group:<code>` references a root `LedgerGroup`'s own `code` (§1a);
`row:<code>` references another computed row by its own `code` — the same
`section:<code>` cross-reference `rj270-pl.json:32` (`BEDR-RES`:
`"section:SOM-OPB - section:SOM-KOS"`) already uses. This is evaluated
client-side (or in `BudgetGridCalculator`, whichever holds the already-
resolved column values — an implementation detail, not a design decision)
purely from the already-fetched row/column values — it needs no additional
query and does not touch `LedgerGroup` at all. An administration that wants
different computed rows edits the page config (or, if this proves too rigid
in practice, is itself a candidate for a config-driven admin UI — flagged
as an open question, `design.md` §11.4, not built here).

## 5. Backend — `BudgetGridReader`/`BudgetGridCalculator`, a composition layer

Following `budget-core-schema`'s own reader/calculator split
(`BudgetVsActualsReader`/`Calculator`, itself mirroring
`BbvProgrammeBudgetReader`/`Calculator`), this change adds:

- **`BudgetGridReader`** — resolves the row tree (§1), the column list from
  the requested range/granularity (§2a), which columns are past (§2c), and
  delegates the actual `BudgetLine`↔`LedgerGroup`↔`TrialBalanceLine` value
  resolution to `budget-core-schema`'s own `BudgetVsActualsReader` — **it
  does not re-open or duplicate that join**. Its own new reads are exactly
  the four batch queries in §1c's table that `BudgetVsActualsReader` does
  not already do (the `LedgerGroup` tree fetch, the `FiscalPeriod` batch,
  and the range-scoped `BudgetLine` batch — `Account` and `TrialBalanceLine`
  reads are delegated).
- **`BudgetGridCalculator`** — pure arithmetic: per-column values, the §2d
  sign convention, the §3 cumulative pair, and the §4 computed-row formula
  evaluation. No OpenRegister calls — mirrors every existing `*Calculator`
  in this codebase (arithmetic only, `BbvProgrammeBudgetCalculator`/
  `BudgetVsActualsCalculator` precedent).

Both are PHPUnit-tested directly; the grid's own e2e coverage (§10) is
UI-shell-smoke only, per this fleet's own "Playwright stays UI-only" rule
already stated verbatim in `BudgetLineCommitments.vue`'s own header comment.

## 6. Expand/collapse interaction and keyboard operability (ADR-059)

Row toggle reuses the exact pattern already shipping in
`BudgetLineCommitments.vue:88-96`: each `LedgerGroup`/`Account` row is
`tabindex="0"`, `role="button"`, carries `:aria-expanded` reflecting its
current state, and responds to both `@click` and `@keyup.enter` (ADR-059
Decision 1: "either it is a native `<button>`/`NcButton`/`NcActionButton`,
or it carries `role` + `tabindex="0"` + a `keydown` handler for Enter/
Space"). This change additionally binds `@keyup.space` (ADR-059's own text
names Space alongside Enter; the existing precedent component only bound
Enter — this change closes that gap in its own new component rather than
also patching the precedent, which is out of this change's scope).

A grootboek (`Account`) leaf row is a **navigation** control, not a toggle
— it is a real `<router-link>` (or an `NcButton` styled as a table cell,
whichever the implementer finds renders correctly inside the table
structure) to `ChartOfAccountsDetail` (`/chart-of-accounts/:id`, verified
route id and path, §0), not a `@click` handler on a non-interactive element
— ADR-059 Decision 3 prefers the native/shared control over a hand-rolled
click handler wherever one fits, and a link is the correct semantic here
(it is a real navigation, not a state toggle).

## 7. Manifest page

`BudgetGrid` ships as `type: "custom"` (not `index`/`dashboard`) — the same
justification `BudgetLineCommitments`'s own manifest `_note` already gives
for its own page (`src/manifest.d/bookkeeping-verplichtingenadministratie
.json:150`): the grid's rows are not "objects of one schema" (`index`) nor
a single aggregation widget (`dashboard`) but a composed, expandable,
multi-source view. Route `/begroting/grid`, component `BudgetGrid`,
registered in `src/registry.js` exactly like `BudgetLineCommitments` is
today (`src/registry.js:304-309/437`).

## 8. Nav placement — see `proposal.md`'s Impact section for the sequencing note

No new decision here beyond what `proposal.md` already states: this page
nests under the `Budgets` top-level group `budget-core-schema` §7b defines,
created by whichever change lands the group first.

## 9. Open questions

1. **The seed-data gap** (`proposal.md`'s Why section) — `budget-core-schema`
   seeded `LedgerGroup` from `rj270-balance-sheet.json`, not
   `rj270-pl.json`; the user's begroting is P&L-shaped. This change does not
   fix `budget-core-schema`'s seed (out of bounds — its own schema/seed);
   `tasks.md` group 0 names the follow-up.
2. **Mixed-type balance-sheet deviation** (§2d) — no favorable/unfavorable
   framing is defined for `assets`/`liabilities`/`equity` member accounts;
   product sign-off needed before this case is common (today it is common,
   because of finding 1 above — a balance-sheet-seeded `LedgerGroup` is
   exactly what a fresh administration has).
3. **A `LedgerGroup` with both children and its own range/include entries**
   (§1b) — this change's row model shows children and ignores the group's
   own directly-resolved accounts in that case; whether that should instead
   be a validation error at `LedgerGroup` save time is `budget-core-schema`
   scope, not decided here.
4. **Computed-row config rigidity** (§4) — a fixed page-config block for
   Bruto Marge/Kosten/Bedrijfsresultaat may prove too rigid once real
   operators have varied chart-of-accounts shapes; a config-driven admin UI
   for defining computed rows is a plausible follow-up, not built here.
5. **Granularity/cadence mismatch UX** (§2c) — the exact wording/placement
   of the "actuals not available at this granularity" indicator is a design
   call, not resolved here.

## 10. e2e coverage

New Playwright spec `tests/e2e/budget-grid-view.spec.ts` (SPDX header,
`becomesVisible` helper from `./becomes-visible.js`, `test.describe`
with a `(REQ-BGV-…)` suffix, data-defensive `test.skip()` on absent seed
data) — modelled directly on `tests/e2e/budget-line-commitments.spec.ts`'s
own conventions (dismiss-wizard helper, viewport set in `beforeEach`,
`getByTestId` over CSS selectors):

1. `budget-grid-view::grid-renders-rows-and-columns` — the grid page
   resolves, root `LedgerGroup` rows render, and the month columns +
   `TOTAAL` column header are present.
2. `budget-grid-view::verzamelpost-expand-reveals-children` — clicking a
   root row's expand toggle reveals its child rows (`LedgerGroup` children
   or resolved `Account` leaves, whichever the seeded row has).
3. `budget-grid-view::expand-keyboard-operable` — the same toggle fires on
   `Enter` and `Space` via keyboard focus, not only pointer click (ADR-059,
   §6).
4. `budget-grid-view::grootboek-drill-through-navigates` — clicking an
   `Account` leaf row navigates to `ChartOfAccountsDetail`
   (`/chart-of-accounts/:id`) for that account.
5. `budget-grid-view::past-column-shows-actuals-and-deviation` — a column
   whose `FiscalPeriod` is seeded `closed`/`audit-locked` renders an actual
   amount and a text-labelled deviation, not the budget figure alone.

Backend-only, `@e2e exclude`:

- `BudgetGridReader`/`Calculator` — PHPUnit only (§5), including the sign
  convention (§2d: one PHPUnit case per `accountType`), the cumulative pair
  (§3), the fiscal-year-crossing empty-vs-zero distinction (§2b), and the
  granularity/cadence-mismatch fallback (§2c).
- The computed-row formula evaluator (§4) — unit-tested against fixed
  input row/column values, no browser surface.

## 11. Non-goals (each names its follow-up change, per `budget-core-schema`'s own precedent)

- **Projection math** (extrapolating future months, run-rate calculations)
  — `budget-projection-engine`.
- **Contract/recurring cost derivation** populating non-`manual`
  `BudgetLine.source` values — `budget-known-costs`.
- **Scenarios and modifiers** (multiple non-default `AnnualBudget`s, what-if
  deltas, a scenario switcher on this grid) — `budget-scenarios`.
- **Charts** (trend/traffic-light visualisations of this grid's data) —
  `budget-charts`.
- **Apportioning coarser-cadence actuals into a finer column granularity**
  (§2c) — would need an allocation policy; closer to
  `budget-projection-engine`'s territory than this change's.
- **Multi-administration consolidated grid** — this change is scoped to the
  operator's single currently-selected administration, matching every other
  page in the app; a rollup across administrations is unspecified scope.
- **Re-seeding `LedgerGroup`** with P&L-shaped data — named as a follow-up
  task (`tasks.md` group 0), not implemented as part of this change's own
  diff (it touches `budget-core-schema`'s register fragment, not this
  change's own files).

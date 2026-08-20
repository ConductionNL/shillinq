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

**Amendment note (2026-08-20, post-review):** two corrections were applied
after this document's first draft, both recorded in `proposal.md` §0 and
threaded through the relevant sections below with an inline "Amended" note
rather than silently rewritten: (1) `budget-core-schema`'s `LedgerGroup`
seed is now P&L-shaped, sourced from `rj270-pl.json` (§1a, §4); (2) actuals
are resolved from `GLTransaction`+`GLLine`+`Account` directly, never from
`TrialBalanceLine`, which has no persisted rows (§1c, §2c, §5).

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
that administration, ordered by `order`. `budget-core-schema`'s amended
seed (§3c/§3d there) ships 11 such roots — `Omzet`, `Kostprijs van de
omzet`, `Personeel`, `Huisvesting`, `Afschrijvingen op vaste activa`,
`Exploitatie- en machinekosten`, `Verkoopkosten`, `Algemene kosten`,
`Rentebaten`, `Rentelasten`, `Vennootschapsbelasting` — matching the user's
own mental model directly ("Omzet", "Personeel", "Huisvesting" are each a
root-level verzamelpost; RJ270 gives no natural ICT-specific range, so
"ICT" is not seeded — an operator who wants it carves it out of `Algemene
kosten`/`Exploitatie- en machinekosten` via the existing `LedgerGroup` CRUD
pages, `budget-core-schema` §7a).

### 1b. Expand reveals children OR resolved accounts — never both; a parent's own value rolls up

Per the user's own description ("click a verzameling to toggle it and
expand into the grootboek numbers"), and `LedgerGroup`'s own nestable shape
(`parentLedgerGroupId`, `budget-core-schema` §3b), a row's expand action
reveals exactly one of:

- **Child `LedgerGroup`s** — when one or more `LedgerGroup`s exist with
  `parentLedgerGroupId` equal to this row's id (`Omzet`, `Personeel`,
  `Kostprijs van de omzet` in the amended seed). Each child row is itself
  expandable, recursively, following the same rule.
- **Resolved member `Account`s** (the leaf case) — when the `LedgerGroup`
  has no children. Members are resolved exactly as `budget-core-schema` §3a
  already specifies (`accountRanges` ∪ `includedAccountNumbers` minus
  `excludedAccountNumbers`, evaluated in PHP) — this change's reader calls
  the same resolution logic `BudgetVsActualsReader` already implements, it
  is not re-derived here.

**A row with children still needs its own displayed value** (every column,
including before it is expanded) — this is `budget-core-schema` §3d's
parent-rollup rule, reused here, not redecided: a parent `LedgerGroup`'s
own budget/actual value is its own `BudgetLine` if one exists for it,
otherwise the recursive sum of its children's own resolved values. An
operator may budget "Personeel" as one typed-in figure or budget "Lonen en
salarissen"/"Sociale lasten" separately — both are valid, and "own
`BudgetLine` wins over roll-up" prevents double counting when both exist.

A `LedgerGroup` with children is never expected to also carry its own
directly-resolved accounts in this UI (nothing in `budget-core-schema`'s
schema forbids a group having both children AND range/include entries —
none of the amended seed's three parents do, by construction — but the
grid's row model shows children when present and only falls back to
resolved accounts when there are none; §9.2).

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

**Amended: actuals are batched, computed from GL activity, never per
column.** This document's first draft specified fetching `TrialBalanceLine`
once per past column (`≤ P` calls, `P` = displayed past columns). That
schema has **no persisted rows** — `TrialBalanceService.php`'s own docblock
states plainly *"there is NO `TrialBalanceLine` record authored by
operators; the rows are materialised on demand."* A `findAll` against it
would return near-nothing and silently render near-zero actuals everywhere.
The corrected shape, matching `budget-core-schema design.md` §6b (amended)
and `budget-projection-engine design.md` §7b exactly:

| Query | Count | Precedent |
|---|---|---|
| `LedgerGroup` for the administration (row tree, this reader's own) | 1 | `TrialBalanceService::fetchAccounts()` pattern |
| `FiscalPeriod` for the administration, unfiltered by period (past/future boundary, this reader's own) | 1 | same batching principle |
| `BudgetLine` for the `AnnualBudget`(s) in the displayed range, `annualBudgetId` via `['in' => [...]]` (this reader's own) | 1 | `SpendAnalyticsService.php:183`'s `'state' => ['in' => self::AP_SPEND_STATES]` |
| `Account`, `GLTransaction` (unfiltered by period, dual-keyed `transactionRefs`), `GLLine` (unfiltered), `LedgerGroup` (delegated to `BudgetVsActualsReader`) | ≤ 4 | `BbvProgrammeBudgetReader::spendByProgramme()` / `budget-core-schema design.md` §6b (amended) / `budget-projection-engine design.md` §7b — all three now share this exact batched, dual-keyed, in-memory-bucketed-by-`(accountNumber, monthKey)` shape |

**Total: at most 7 `findAll()` calls for the entire grid render — a flat
constant, independent of row count, expanded-row count, AND displayed
column count.** This is strictly better than the original (wrong)
`TrialBalanceLine`-per-column design: because GL data is fetched **once,
unfiltered by period**, and bucketed by calendar month in memory, adding
more displayed columns costs nothing further either (the old `4 + P` shape
is gone, not just corrected in its data source). The one known,
deliberate redundancy: `BudgetGridReader`'s own `LedgerGroup` fetch and
`BudgetVsActualsReader`'s internal `LedgerGroup` fetch are two separate
calls rather than one shared one — a small, bounded (not row/column-scaling)
duplication accepted rather than forcing an interface change onto
`budget-core-schema`'s already-spec'd reader; noted, not hidden.

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

A column is **past** — and shows actuals + deviation — when either:

- an exact-span `FiscalPeriod` exists for the administration (its
  `startDate`/`endDate` equal the column's own calendar span) and its
  `state` is `closed` or `audit-locked`; or
- the column's calendar span is **fully contained within** a coarser
  `FiscalPeriod` (e.g. January 2026 within a `FiscalPeriod` for Q1 2026)
  whose `state` is `closed` or `audit-locked` — **amended**, see below.

`open` and `closing` do **not** count as past under either form — the
period's own actuals are not final yet, so the column still shows budget
only (showing partial, still-moving actuals next to a fixed budget would
misrepresent "how close we got").

This is a code-answerable rule, not a `today`-relative one, deliberately:
`FiscalPeriod.state` is the only signal in this codebase that means "this
period's books are actually closed" (`GLTransaction.post` already refuses
postings against a closed/audit-locked period per the same schema's own
description) — `today >= column end date` would show actuals for a period
that is calendar-past but not yet closed, which are not final yet (a
still-open period can still receive postings).

**Amended: the "contained within a coarser period" form, and why no
apportionment is needed.** This document's first draft only allowed the
exact-span form, and treated a cadence mismatch (e.g. an administration
that only closes quarterly `FiscalPeriod`s, viewed at month granularity) as
a hard fallback to budget-only display — reasoning that showing a monthly
actual would require **apportioning** a quarterly `TrialBalanceLine` figure
across three months, which needs an allocation policy this change does not
invent. That reasoning no longer applies: since actuals are now computed
directly from `GLTransaction`/`GLLine` `postingDate`s (§1c), a month's
actual is never apportioned from a coarser figure — it is the exact sum of
that month's own GL activity, which exists regardless of what granularity
the administration's `FiscalPeriod` records happen to use. The only thing
still gated by `FiscalPeriod` is the **past/future boundary determination**
(is the period closed enough to trust) — once that is satisfied (by either
form above), the actual VALUE for the column's own exact calendar span is
always directly computable, with no apportionment problem left to solve.
This removes a limitation (and its own non-goal / open question) the first
draft carried; it does not reopen the schema.

### 2d. Actuals-vs-budget deviation and the sign convention

This is the requirement the task brief explicitly warns "getting this wrong
inverts the whole screen" — so it is derived from a precedent already
encoded in this codebase, not invented: `rj270-pl.json` (`lib/Settings/
statements/rj270-pl.json:17-38`) tags every P&L section with an explicit
`sign: "credit"` (revenue-shaped: `opbrengsten`, `financieel` income rows)
or `sign: "debit"` (cost-shaped: `kosten`, `financieel` expense rows,
`belasting`) — the same distinction `Account.accountType` (`revenue` vs
`expenses`, read directly off `Account`, never via `TrialBalanceLine`)
already carries per account.

**`LedgerGroup` itself carries no sign/kind field** (`budget-core-schema`
§3b's fixed field list has none, and this change does not add one — that
would be redesigning `budget-core-schema`'s schema; §3d/§4 below explain
why the seed's own nesting + this change's page-config formulas already
cover what a `sign` field would have been for). The sign is therefore
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
  **open product question** (§9.1) rather than an invented convention —
  now a rare case in practice, since `budget-core-schema`'s amended default
  seed is P&L-shaped and no longer ships balance-sheet groups by default;
  it only recurs if an operator manually creates one via the `LedgerGroup`
  CRUD pages.

A `LedgerGroup` whose resolved members mix `revenue`/`expenses` types
(uncommon given the RJ270 section shape, but not schema-forbidden) shows a
row-level deviation that is the sum of each member's own correctly-signed
deviation — never a single row-wide sign applied to a mixed sum. A
**parent** `LedgerGroup`'s deviation (§1b) is likewise the sum of its
children's already-correctly-signed deviations when it rolls up, or its
own member-account-derived sign when it has a directly-typed `BudgetLine`.

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
  columns that are **past** per §2c; a future column contributes nothing to
  this sub-value — it genuinely does not exist yet, and showing a
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

## 4. Subtotal / derived rows — a page-config concern, matching `rj270-pl.json`'s own separation

`budget-core-schema` §11.1 (now resolved — see that change's own design.md)
answered the parts of this question that are genuine monetary **sums**:
three of `rj270-pl.json`'s own groupings (`Omzet`, `Personeel`, `Kostprijs
van de omzet`) are seeded as real parent `LedgerGroup`s, resolved by the
§1b/§3d rollup rule, needing no schema change and no page-config formula.

**What remains a page-config concern** are the true cross-branch
subtraction/addition rows — RJ270's own `level: 0` `computed` sections
(`SOM-OPB`, `SOM-KOS`, `BEDR-RES`, `FIN-RES`, `RES-VBB`, `NET-RES`) whose
inputs are **siblings**, not parent-child, so they cannot be expressed as a
rollup-sum: the user's own Bruto Marge / Kosten / Bedrijfsresultaat / %
rows. `rj270-pl.json` itself keeps these `computed` rows in the **statement
config**, not as their own `Account`/`LedgerGroup` records — this change
follows the identical separation, in the `BudgetGrid` page's own manifest
config:

```jsonc
// BudgetGrid page's own manifest config — NOT a LedgerGroup schema field.
// One flat codespace: a formula's operands are either a root LedgerGroup's
// own `code` or another computedRows entry's own `code` — both resolve the
// same way, so no group:/row: prefix is needed to disambiguate.
"computedRows": [
  { "code": "bruto-marge", "label": "Bruto Marge",
    "formula": "omzet - kostprijs-van-de-omzet", "favorableDirection": "higher" },
  { "code": "kosten", "label": "Kosten",
    "formula": "personeel + huisvesting + afschrijvingen-op-vaste-activa + exploitatie-en-machinekosten + verkoopkosten + algemene-kosten",
    "favorableDirection": "lower" },
  { "code": "bedrijfsresultaat", "label": "Bedrijfsresultaat",
    "formula": "bruto-marge - kosten", "favorableDirection": "higher" },
  { "code": "financieel-resultaat", "label": "Financieel resultaat",
    "formula": "rentebaten - rentelasten", "favorableDirection": "higher" },
  { "code": "resultaat-voor-belastingen", "label": "Resultaat voor belastingen",
    "formula": "bedrijfsresultaat + financieel-resultaat", "favorableDirection": "higher" },
  { "code": "nettoresultaat", "label": "Nettoresultaat",
    "formula": "resultaat-voor-belastingen - vennootschapsbelasting", "favorableDirection": "higher" },
  { "code": "nettoresultaat-pct", "label": "% van omzet",
    "formula": "nettoresultaat / omzet", "asPercent": true }
]
```

This reconstructs `rj270-pl.json`'s entire `SOM-OPB → SOM-KOS → BEDR-RES →
FIN-RES → RES-VBB → NET-RES` waterfall, using the same `section:<code> ±
section:<code>` arithmetic idea `rj270-pl.json:32` (`BEDR-RES`) already
uses, adapted to this schema's own flat codespace. **Each computed row
carries its own explicit `favorableDirection`** (`"higher"` or `"lower"`,
mirroring RJ270's own per-section `sign: "credit"`/`"debit"` tag) because a
computed row has no resolved member accounts of its own to derive a
favorable direction from (§2d's per-account mechanism does not apply to
it) — this is a page-config-only field, not a `LedgerGroup` schema
addition.

This is evaluated client-side (or in `BudgetGridCalculator`, whichever
holds the already-resolved column values — an implementation detail, not a
design decision) purely from the already-fetched row/column values — it
needs no additional query and does not touch `LedgerGroup` at all. An
administration that wants different computed rows edits the page config
(or, if this proves too rigid in practice, is itself a candidate for a
config-driven admin UI — flagged as an open question, §9.3, not built
here).

**Why `Kosten` stays a computed row rather than becoming a fourth real
parent** (unlike `Omzet`/`Personeel`/`Kostprijs van de omzet`): `Kosten`'s
own six inputs (`Personeel`, `Huisvesting`, `Afschrijvingen op vaste
activa`, `Exploitatie- en machinekosten`, `Verkoopkosten`, `Algemene
kosten`) are currently root-level siblings, matching `rj270-pl.json`'s own
flat `group: "kosten"` tag (RJ270 does not nest them under anything either —
`SOM-KOS` is a `computed: "sum-group:kosten"` overlay, not a parent). This
change mirrors that choice rather than restructuring the seed to nest all
six under a new `Kosten` parent, which would change the seed
`budget-core-schema` already owns; §9.4 flags this as worth revisiting if a
future UX pass wants `Kosten` itself to be expandable the same way `Omzet`
is.

## 5. Backend — `BudgetGridReader`/`BudgetGridCalculator`, a composition layer

Following `budget-core-schema`'s own reader/calculator split
(`BudgetVsActualsReader`/`Calculator`, itself mirroring
`BbvProgrammeBudgetReader`/`Calculator`), this change adds:

- **`BudgetGridReader`** — resolves the row tree (§1), the column list from
  the requested range/granularity (§2a), which columns are past (§2c), and
  delegates the actual `BudgetLine`↔`LedgerGroup`↔GL-activity value
  resolution to `budget-core-schema`'s own `BudgetVsActualsReader` — **it
  does not re-open or duplicate that join, and it does not read
  `TrialBalanceLine`** (§1c's amendment). Its own new reads are exactly the
  three batch queries in §1c's table that `BudgetVsActualsReader` does not
  already do (the `LedgerGroup` tree fetch, the `FiscalPeriod` batch, and
  the range-scoped `BudgetLine` batch — `Account`/`GLTransaction`/`GLLine`/
  a second `LedgerGroup` read are delegated to `BudgetVsActualsReader`,
  which itself computes from `GLTransaction`+`GLLine`+`Account`, per
  `budget-core-schema design.md` §6b amended).
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

1. **Mixed-type balance-sheet deviation** (§2d) — no favorable/unfavorable
   framing is defined for `assets`/`liabilities`/`equity` member accounts;
   now a rare case in practice (`budget-core-schema`'s amended default seed
   is P&L-shaped, no longer ships balance-sheet groups by default), but
   still open for an operator-authored balance-sheet-scoped `LedgerGroup`.
2. **A `LedgerGroup` with both children and its own range/include entries**
   (§1b) — this change's row model shows children and ignores the group's
   own directly-resolved accounts in that case; the amended seed's own
   three parents never hit this (all have empty own ranges), so it is moot
   for day-one data; whether it should instead be a validation error at
   `LedgerGroup` save time is `budget-core-schema` scope, not decided here.
3. **Computed-row config rigidity** (§4) — a fixed page-config block for
   Bruto Marge/Kosten/Bedrijfsresultaat may prove too rigid once real
   operators have varied chart-of-accounts shapes; a config-driven admin UI
   for defining computed rows is a plausible follow-up, not built here.
4. **Should `Kosten` become a fourth real parent `LedgerGroup`** (§4), so it
   is expandable the same way `Omzet`/`Personeel` are, rather than a
   computed row summing six root siblings? This would mean restructuring
   `budget-core-schema`'s own seed (moving those six under a new parent) —
   a product/UX call, not decided here, and out of this change's own
   files either way (it would be `budget-core-schema`'s edit).
5. **Duplicated GL-batching logic across three readers**
   (`BudgetVsActualsReader`, `BudgetGridReader`'s delegation, and
   `budget-projection-engine`'s `BudgetProjectionReader`) — all three now
   independently implement the same `Account`+`GLTransaction`+`GLLine`
   dual-keyed-batch-and-bucket shape. A shared extraction (e.g. a
   `GlActivityBucketReader` all three compose) is a plausible future
   refactor, not attempted here — each change's own reader stays
   self-contained per its own spec, and premature extraction across three
   still-unmerged changes risks coupling them before any has landed.

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
   whose `FiscalPeriod` is seeded `closed`/`audit-locked`, backed by posted
   `GLTransaction`/`GLLine` rows for the relevant account and month, renders
   an actual amount and a text-labelled deviation, not the budget figure
   alone. **Not** backed by `TrialBalanceLine` seed data, which this change
   no longer reads.

Backend-only, `@e2e exclude`:

- `BudgetGridReader`/`Calculator` — PHPUnit only (§5), including the sign
  convention (§2d: one PHPUnit case per `accountType`), the cumulative pair
  (§3), the fiscal-year-crossing empty-vs-zero distinction (§2b), the
  parent-rollup rule (§1b), and the query-count regression (§1c's ≤7-call
  bound).
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
- **Multi-administration consolidated grid** — this change is scoped to the
  operator's single currently-selected administration, matching every other
  page in the app; a rollup across administrations is unspecified scope.

**No longer a non-goal, resolved directly instead:**
apportioning coarser-cadence actuals into a finer column granularity was
listed here in this document's first draft; §2c's amendment removed the
underlying problem (actuals are computed from GL `postingDate`s at the
column's own exact calendar span, never apportioned from a coarser figure),
so there is nothing left to defer. Re-seeding `LedgerGroup` with P&L-shaped
data was also listed here as a follow-up task; it is resolved directly in
`budget-core-schema`'s own amended `design.md` §3c/§3d — see `proposal.md`
§0 — with no remaining task in this change's own `tasks.md`.

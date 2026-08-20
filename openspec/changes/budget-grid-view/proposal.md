# Change: budget-grid-view

## Why

`budget-core-schema` gives shillinq a working data model for the begroting
(budgeting) wave — `LedgerGroup` (verzamelpost), `AnnualBudget`, `BudgetLine`
(12 monthly amounts + `source`), and a PHP-primary
`BudgetVsActualsReader`/`Calculator` pair — but that change ships only plain
CRUD list/detail pages for the three schemas (`design.md` §7a: "**not** the
spreadsheet grid"). Nobody can yet see the screen the user actually asked
for: a year-basis begroting where verzamelposten roll up grootboek numbers,
any period range is viewable, past periods show actuals-vs-budget deviation,
a cumulative column runs the totals, and rows expand/drill exactly like the
operator's own spreadsheet.

**This change is that screen** — the user-facing centerpiece of the
begroting programme. It builds strictly on `budget-core-schema`'s already
merged-or-landing schemas and services; it does not add, rename, or
otherwise redesign any schema field.

### What already exists that this change reuses, verified at HEAD

- `FiscalPeriod` (`lib/Settings/register.d/bookkeeping-period-close.json`):
  `periodId`, `administrationId`, `startDate`/`endDate`, `fiscalYear`, and a
  `state` lifecycle `open → closing → closed → audit-locked`. This is the
  only code-answerable "is this period past?" signal in the app — there is
  no other period-close flag anywhere in shillinq.
- `TrialBalanceLine` (`lib/Settings/register.d/bookkeeping-trial-balance.json`,
  read-only, computed by `TrialBalanceService`): `periodId`, `accountNumber`,
  `accountType` (`assets|liabilities|equity|revenue|expenses`, inherited
  from `Account.accountType`), `openingBalance`/`debitMovement`/
  `creditMovement`/`closingBalance`. This is the actuals source; it carries
  no date of its own, only a `periodId` string that must be resolved against
  `FiscalPeriod` for a calendar span.
- `ChartOfAccounts`/`ChartOfAccountsDetail` (`src/manifest.json`, route
  `/chart-of-accounts/:id`, schema `Account`) — the real, already-shipped
  grootboek detail page the task brief asked to verify. It exists at the
  app's core manifest level (not a `manifest.d/` fragment), confirmed by
  grep at 2026-08-20.
- `rj270-pl.json` (`lib/Settings/statements/rj270-pl.json`) already encodes
  **exactly the user's spreadsheet shape**: `level: 1` leaf sections with an
  `accountRange` (`LONE` "Lonen en salarissen", `HUIS`
  "Huisvestingskosten", …) and `level: 0` **computed rollup rows**
  (`SOM-OPB` `sum-group:opbrengsten`, `SOM-KOS` `sum-group:kosten`,
  `BEDR-RES` "Bedrijfsresultaat" `section:SOM-OPB - section:SOM-KOS`), each
  carrying an explicit `sign: "credit"|"debit"`. This is the precedent this
  change's own subtotal rows and sign convention are modelled on — see
  `design.md` §4 and §6.
- `BbvProgrammeBudgetReader::spendByProgramme()`/`postedTransactionMonths()`
  (`lib/Service/BbvProgrammeBudgetReader.php:223-374`) — the fleet's own
  precedent for joining a schema with no `date`/`administrationId` of its
  own (there: `GLLine`↔`GLTransaction`; here: `TrialBalanceLine`↔
  `FiscalPeriod`) via an in-memory index, batched once per query rather than
  once per row. `BudgetVsActualsReader` (`budget-core-schema` §6b) already
  follows this same idiom for `BudgetLine`↔`LedgerGroup`↔`TrialBalanceLine` —
  this change's own reader composes on top of it rather than re-deriving the
  join.
- `WbsoChartOfAccountsView.vue`/`WbsoAccountApiController`
  (`src/manifest.d/bookkeeping-wbso-sno-administratie.json:36-39`) — the
  **only** hierarchical expand/collapse tree in this app today, a `type:
  "custom"` page reading a bespoke `/api/wbso-sno/accounts/hierarchy`
  endpoint because `type: "index"` cannot express nesting. There is no
  reusable tree-table component anywhere in this app or in
  `@conduction/nextcloud-vue` — see the nc-vue-vs-app-local decision below.
- `BudgetLineCommitments.vue`
  (`src/views/BudgetLineCommitments.vue:88-96`) — the app's own working
  ADR-059-compliant expand-toggle pattern already in production: a table row
  with `tabindex="0"`, `role="button"`, `:aria-expanded`, `@click` **and**
  `@keyup.enter`. This change's own rows reuse this exact pattern rather than
  inventing a new one.

### A finding this change surfaces, not fixes: the day-one seed data is the wrong statement

`budget-core-schema` §3c seeds `LedgerGroup` from `rj270-balance-sheet.json`
(balance-sheet sections: `VA-IMVA`, `VA-MVA`, …, `KLS-SUS`) — not
`rj270-pl.json`. The user's begroting is unambiguously a **P&L** budget
(Omzet, Personeel, Huisvesting, ICT, Bruto Marge, Bedrijfsresultaat), which
maps onto `rj270-pl.json`'s sections (`LONE` "Lonen en salarissen" ≈
Personeel, `HUIS` "Huisvestingskosten" ≈ Huisvesting, `NETO` "Netto-omzet" ≈
Omzet), not the balance-sheet ones `budget-core-schema` actually seeded. On a
fresh administration with only `budget-core-schema`'s own seed data, this
change's grid renders a `LedgerGroup` tree that looks nothing like the
user's spreadsheet — technically correct (it renders whatever `LedgerGroup`
rows exist) but not the day-one experience implied by the task brief. This
change does **not** re-seed `LedgerGroup` (that is `budget-core-schema`'s
own schema/seed, out of bounds to redesign here) — it is recorded as an open
question (`design.md` §9.1) and a named follow-up task
(`tasks.md` group 0) to add a P&L-shaped `LedgerGroup` seed batch sourced
from `rj270-pl.json`, filed against whichever change is still open
(`budget-core-schema`, if not yet merged) or as its own tiny seed-only
change otherwise.

## What Changes

- **ADD** (frontend, app-local for now): `BudgetGrid.vue`, a `type:
  "custom"` manifest page rendering the year-basis begroting grid — rows
  from the operator's current administration's `LedgerGroup` tree (root
  verzamelposten, expandable to child `LedgerGroup`s or resolved member
  `Account`s), columns generated from a selectable period range +
  granularity (month default; quarter/year), a cumulative `TOTAAL` column,
  and per-column actuals-vs-budget deviation for closed periods. `design.md`
  §1-§7.
- **ADD** (backend): `BudgetGridReader`/`BudgetGridCalculator`
  (`lib/Service/BudgetGridReader.php`/`BudgetGridCalculator.php`), a thin
  composition layer over `budget-core-schema`'s own
  `BudgetVsActualsReader`/`Calculator` — adds period-range column
  generation, the past/future boundary check against `FiscalPeriod`, the
  cumulative-column sums, and the accountType-driven sign convention. Does
  **not** reimplement the `BudgetLine`↔`LedgerGroup`↔`TrialBalanceLine` join
  `budget-core-schema` already built. `design.md` §5, §7.
- **ADD** (manifest): one new page (`BudgetGrid`, route
  `/begroting/grid`) and one new nav child, nested under the `Budgets`
  top-level group `budget-core-schema` §7b defines (created by whichever of
  the two changes lands first — see Impact below, no duplicate group either
  way). `design.md` §8.
- **ADD** (page config, not schema): a declarative subtotal/derived-row
  block on the `BudgetGrid` page's own manifest config, modelled directly on
  `rj270-pl.json`'s `sum-group:<group>`/`section:<code> ± section:<code>`
  convention — computes Bruto Marge / Kosten / Bedrijfsresultaat / % rows
  from the already-rendered `LedgerGroup` rows without adding any field to
  `LedgerGroup` itself. `design.md` §4.
- **ADD**: Playwright e2e (`tests/e2e/budget-grid-view.spec.ts`, modelled on
  `tests/e2e/budget-line-commitments.spec.ts`) covering render, expand,
  keyboard-operable expand, drill-through to `ChartOfAccountsDetail`, and a
  past-column-shows-actuals-and-deviation assertion. `design.md` §10.
- **Non-goals, each naming its follow-up change** (`design.md` §11):
  projection math (`budget-projection-engine`), contract/recurring cost
  derivation (`budget-known-costs`), scenarios/modifiers
  (`budget-scenarios`), charts (`budget-charts`), apportioning actuals
  across a coarser `FiscalPeriod` cadence than the requested column
  granularity, and multi-administration consolidated views.
- **Cross-repo, explicitly not this change's own diff**: file an nc-vue
  absorption-candidate note against ADR-072's backlog (this app now has TWO
  hand-rolled expand/collapse trees — `WbsoChartOfAccountsView` and this
  change's `BudgetGrid` — the same-app signal ADR-072 exists to catch before
  a second unrelated APP reinvents a third one; ADR-072's own trigger is
  ≥2 apps, which this alone does not yet cross). `design.md` §3.

## Impact

- **Affected specs**: new capability `budget-grid-view`
  (`specs/budget-grid-view/spec.md`). No existing spec is modified — this
  change adds page/service surface only, no schema.
- **Affected code**: 1 new Vue component (`src/views/BudgetGrid.vue`) + a
  small tree/row-toggle helper module, 1 new manifest fragment (1 page + 1
  nav child), 2 new PHP classes (`BudgetGridReader`, `BudgetGridCalculator`)
  + PHPUnit coverage, 1 new Playwright spec, 1 `registry.js` entry.
- **Hard dependency — `budget-core-schema`**: this change requires
  `budget-core-schema`'s groups 1-8 (the `LedgerGroup`/`AnnualBudget`/
  `BudgetLine` schemas and the `BudgetVsActualsReader`/`Calculator` pair) to
  have landed before implementation starts; it does not require
  `budget-core-schema`'s own group 9 (its 6 CRUD pages) to have landed —
  see the nav-group sequencing note below.
- **Nav-group sequencing — no duplicate, either order**: `budget-core-schema`
  §7b defines a top-level `Budgets` nav group (id/label fixed, matching
  `nav-six-clusters`' reserved Cluster 4 leaf) but sequences adding it on
  manifest byte headroom. If `budget-core-schema` group 9 has already landed
  when this change ships, `BudgetGrid` nests as a new child under the
  existing group. If it has not, this change creates the `Budgets` group
  itself (same id/label, per `budget-core-schema` §7b's own convention) with
  only the grid page as its initial child; `budget-core-schema`'s group 9
  then adds its 3 index/detail children alongside it when it lands. Either
  ordering converges to the same shape — `tasks.md` group 3 makes the
  implementer check which state applies before writing the fragment.
- **Byte budget**: current measured headroom (2026-08-20,
  `node tests/check-manifest-budget.js`) is 2,927B against the same gate
  `budget-core-schema` measured against. This change adds exactly one
  `type: "custom"` page (no per-schema column config — smaller than
  `budget-core-schema`'s index/detail pages) plus one nav child; estimated
  700-1,450B, which may fit inside current headroom even before
  `nav-six-clusters`/PR #923 merges, unlike `budget-core-schema`'s own
  6-page group — but this is an estimate, not a measurement.
  `tasks.md` requires re-running `node tests/check-manifest-budget.js`
  after the manifest edit and stopping if it fails, same discipline as
  `budget-core-schema` §8.
- **No cross-repo code change**: the nc-vue absorption note above is a
  backlog/tracking item against ADR-072, not a code change in this PR — see
  the nc-vue-vs-app-local decision in `design.md` §3 for why this ships
  app-local now rather than blocking on an nc-vue release.

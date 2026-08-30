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
- **`GLTransaction`+`GLLine`+`Account` — the actuals source, NOT
  `TrialBalanceLine`.** `TrialBalanceService.php`'s own docblock states
  plainly that no `TrialBalanceLine` row is ever persisted ("the rows are
  materialised on demand"); this change's own earlier draft assumed
  otherwise and was corrected before implementation (§0's amendment note).
  `Account.accountType` (`assets|liabilities|equity|revenue|expenses`) is
  still read directly off `Account`, not inherited via a queryable
  `TrialBalanceLine` row.
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
  precedent for joining `GLLine`↔`GLTransaction` (`GLLine` carries neither
  `date` nor `administrationId`; both come from the parent `GLTransaction`,
  dual-keyed by object id **and** `transactionNumber`) via an in-memory
  index, batched once per query rather than once per row.
  `BudgetVsActualsReader` (`budget-core-schema` §6b, amended) computes
  actuals directly from `GLTransaction`+`GLLine`+`Account` using this exact
  shape — this change's own reader composes on top of it rather than
  re-deriving the join or re-reading a non-existent `TrialBalanceLine` row.
  `budget-projection-engine design.md` §7b independently specifies the same
  batched shape for its own reader; all three readers now agree.
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

### §0. Two corrections applied to this change and to `budget-core-schema`, recorded together

Two defects were found in review after this change's first draft and are
now fixed in both changes, not just noted:

1. **The seed-data gap — RESOLVED in `budget-core-schema`, not here.**
   `budget-core-schema` §3c originally seeded `LedgerGroup` from
   `rj270-balance-sheet.json` (balance-sheet sections) rather than
   `rj270-pl.json` — a defect, since the user's begroting is unambiguously a
   **P&L** budget (Omzet, Personeel, Huisvesting, ICT, Bruto Marge,
   Bedrijfsresultaat). `budget-core-schema`'s own `design.md` §3c/§3d and
   `specs/budget-core-schema/spec.md` REQ-BCS-005 are now amended to seed
   19 P&L-shaped `LedgerGroup`s from `rj270-pl.json` (including real parent
   `LedgerGroup`s for `Omzet`/`Personeel`/`Kostprijs van de omzet`, resolved
   by simple rollup-sum, §3d) — **this change does not re-seed
   `LedgerGroup` itself; it now builds on the corrected seed directly, with
   no follow-up task of its own remaining** (the former `tasks.md` group 0
   is removed; see `budget-core-schema`'s own change for the seed).
2. **The actuals source — `GLTransaction`+`GLLine`+`Account`, never
   `TrialBalanceLine`.** This change's own first draft specified reading
   actuals from `TrialBalanceLine` (`periodId`-scoped rows). That schema has
   **no persisted rows** — `TrialBalanceService.php`'s own docblock states
   "there is NO `TrialBalanceLine` record authored by operators; the rows
   are materialised on demand." A reader `findAll`-ing that schema expecting
   real historical data back would silently report near-zero actuals
   everywhere — the `budget-projection-engine` author caught the identical
   defect in `budget-core-schema`'s own `BudgetVsActualsReader` design
   independently. Both `budget-core-schema design.md` §6b and this change's
   own `design.md` §1c/§2/§5 are corrected to compute actuals directly from
   `GLTransaction`+`GLLine`+`Account`, batched, following
   `BbvProgrammeBudgetReader::spendByProgramme()`'s precedent and matching
   `budget-projection-engine design.md` §7b's own reader shape exactly — all
   three readers now agree rather than silently disagreeing about where
   actuals live.

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
  **not** reimplement the `BudgetLine`↔`LedgerGroup`↔GL-activity join
  `budget-core-schema` already built (`BudgetVsActualsReader`, computed
  from `GLTransaction`+`GLLine`+`Account`, never `TrialBalanceLine` — §0).
  `design.md` §5, §7.
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
  (`budget-scenarios`), charts (`budget-charts`), and multi-administration
  consolidated views. (Apportioning actuals across a coarser `FiscalPeriod`
  cadence was a non-goal in this document's first draft; §0's second
  correction resolved the underlying problem directly — see `design.md`
  §2c — so it is no longer deferred, it is simply not needed.)
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

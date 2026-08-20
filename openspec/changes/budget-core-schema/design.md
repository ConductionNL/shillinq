# Design: budget-core-schema

## 0. Method

Every claim below was verified directly against `origin/development`
(2026-08-20, this checkout) — file paths, line numbers, and live counts are
read, not assumed. Live object counts are read from the shared dev
instance's OpenRegister API
(`GET /apps/openregister/api/objects?register=shillinq&schema=<slug>&_limit=1`,
reading `total`, never a bare `limit=`, per this repo's own working norm).
The task brief's research findings are treated as given and cited, not
re-derived; this document adds the exact file/line evidence needed to write
buildable tasks.

## 1. The `Budget` collision — resolution and naming

### 1a. The defect, precisely

Two fragments each declare a full `components.schemas.Budget`:

| | `bookkeeping-provincies-bbv-variant.json:67` | `bookkeeping-verplichtingenadministratie.json:922` |
|---|---|---|
| `required` | `budgetName, totalAmount, programmeStructure, status, fiscalYear, administrationId` | `administrationId, financialYear, authorised_amount` |
| fiscal year field | `fiscalYear` | `financialYear` |
| amount field | `totalAmount` (number, EUR) | `authorised_amount` (integer, EUR cents) |
| programme field | `programmeStructure` (enum, 7 BBV programmes) | `programmeCode` (free string, taakveld) |
| icon | `CashMultiple` | `Wallet` |
| purpose | dashboard KPI source for `BbvProgrammeBudgetReader`/`Service`/`Calculator` | budget-blocking ceiling for `BudgetBlocker`/`CommitmentMaterialisationService`, drives `free_capacity` |

`SettingsService::deepMergeConfig()` (`lib/Service/SettingsService.php:1563-1582`)
unions `properties`, **concatenates `required` without dedup**
(`array_merge`, not a set union), and resolves every other scalar
last-write-wins by alphabetical fragment order —
`bookkeeping-provincies-bbv-variant.json` < `bookkeeping-verplichtingenadministratie.json`,
so the verplichtingen fragment's icon (`Wallet`) and `x-openregister-purpose`
win. Live-imported schema id 1114 (2026-08-20) confirms exactly this: 9-entry
`required` with `administrationId` duplicated, 17-key property union, icon
`Wallet`. `lib/Service/BbvBudgetVocabulary.php`'s own docblock records the
live failure mode: `POST .../objects/shillinq/Budget -> 400 "The required
properties (financialYear, authorised_amount) are missing."` — a BBV-shaped
create is refused because the merged `required` demands the verplichtingen
fragment's fields too. Live count: `Budget` → `total: 0` (2026-08-20,
`localhost:8080`) — evidence, not proof, that no deployment has hit this
wall with real data; §2's migrator still gates on a live re-check.

### 1b. Naming

Renaming, not merging — the two schemas share zero field names and model
genuinely different things (a fiscal-year programme allocation vs. a
commitment-blocking capacity bucket). Following the `contracts-single-home`
precedent (rename the domain-specific schema, keep the collision-causing
generic slug retired rather than reassigned to either side, so nothing else
can collide with it again):

- `bookkeeping-provincies-bbv-variant.json`'s `Budget` → **`BbvProgrammeBudget`**.
  Matches the existing class family already named for exactly this concept
  (`BbvProgrammeBudgetReader`, `BbvProgrammeBudgetService`,
  `BbvProgrammeBudgetCalculator`) — the rename makes the schema name match
  code that already existed, rather than inventing new vocabulary.
- `bookkeeping-verplichtingenadministratie.json`'s `Budget` →
  **`CommitmentBudget`**. Matches its actual role: a per-programma/per-
  boekjaar capacity ceiling consumed at commitment (`Verplichting`) time,
  not a programme-planning budget. `authorised_amount`/`free_capacity`
  (vrije_ruimte) vocabulary is preserved unchanged.

Both retain their own `title`/`icon`/`x-spec` (unchanged paths — each keeps
pointing at its own owning capability spec) and required-field lists exactly
as declared today (no field renames — this is a slug rename only, so no
`x-openregister-lifecycle` or downstream field-mapping migration is needed
beyond the object's `@self.schema` value).

## 2. Consumer blast-radius inventory + migrator

### 2a. Every literal `'Budget'` schema reference (verified 2026-08-20)

| File | Line(s) | Vocabulary | New value |
|---|---|---|---|
| `lib/Settings/register.d/bookkeeping-provincies-bbv-variant.json` | `67` (schema key), `291/308/325…` (seed `@self.schema`), `35` (`BbvComplianceDashboard` widget `config.schema`) | BBV | `BbvProgrammeBudget` |
| `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json` | `922` (schema key), `1132/1146` (seed `@self.schema`), `536` (`committedVsRealisedPerBudgetLine.join.through`) | Commitment | `CommitmentBudget` |
| `lib/Service/BbvBudgetVocabulary.php` | whole file | both (adapter) | **deleted** — §2c |
| `lib/Service/BbvProgrammeBudgetReader.php` | `64` (`SCHEMA_BUDGET` const), `108` (vocabulary DI), `176/196/201` (`vocabulary->year/programme/amount`) | BBV | `SCHEMA_BUDGET = 'BbvProgrammeBudget'`; direct field reads, vocabulary removed |
| `lib/Service/BbvProgrammeBudgetService.php` | docblock line `27` (`sum(Budget.totalAmount)`) | BBV | comment text only, `BbvProgrammeBudget.totalAmount` |
| `lib/Lifecycle/BudgetBlocker.php` | `195` (`schema: 'Budget'`, filters `programmeCode`/`financialYear`) | Commitment | `schema: 'CommitmentBudget'` |
| `lib/Service/Commitment/CommitmentMaterialisationService.php` | `515` (`schema: 'Budget'`, filters `costCentre`/`financialYear`) | Commitment | `schema: 'CommitmentBudget'` |
| `src/views/budgetLineCommitmentsHelpers.js` | `33` (`bucket?.['Budget.authorised_amount']`, `Budget.realised_amount`) | Commitment | `CommitmentBudget.authorised_amount`/`.realised_amount` — the aggregation response buckets its joined fields as `<through>.<field>`, so renaming `join.through` changes the response key, not just the JSON |
| `src/views/BudgetLineCommitments.vue` | reads the same normalised rows the helper produces | Commitment | no literal schema string in the component itself (verified — it consumes `normaliseBudgetLineRows()`'s output); no edit needed beyond the helper |
| `tests/Unit/Lifecycle/VerplichtingWorkflowTest.php` | `231/238/278/307-316` (`makeBudget()`, mock array key `'Budget'`) | Commitment | `'CommitmentBudget'` |
| `tests/Unit/Service/Commitment/CommitmentMaterialisationServiceTest.php` | `242-243/309/359/382` | Commitment | `'CommitmentBudget'` |
| `tests/Unit/Service/RequisitionServiceTest.php` | `260/297/325/376/381` | Commitment | `'CommitmentBudget'` |
| `tests/Unit/Service/VerplichtingenCommitmentAccountingFragmentTest.php` | `106/111/155/175/194` (incl. `assertSame('Budget', $agg['join']['through'])`) | Commitment | `'CommitmentBudget'` |
| `tests/Unit/Service/ProvinciesBbvFragmentTest.php` | `114-118` (`assertArrayHasKey('Budget', $schemas)`, `$schemas['Budget']`) | BBV | `'BbvProgrammeBudget'` |

**Not affected** (verified, not assumed): `BudgetBBVMapping*` (a different
schema, no name collision), `BudgetOverrunGuard` (grep confirms no schema
literal — arithmetic-only guard), `tests/check-manifest-budget.js` (byte
budget tripwire — "budget" in the filename is a coincidence, unrelated
subject).

### 2b. Migrator — `BudgetSchemaSplitMigrator`

Modelled on `lib/Service/Migration/SubsidieOrderConsolidationMigrator.php`
(`mapObjectToRenamedSchema(array $object, string $from, string $to): array`,
`migrateBatch(array $sourceObjects, string $from, string $to): array`,
`assertCountsMatch(int $sourceCount, int $migratedCount): void` throwing
`RuntimeException` on mismatch, source left intact). This case is harder
than a straight rename: one source slug (`Budget`) must split into **two**
possible target slugs depending on which vocabulary a given live object's
fields match — the exact classification `BbvBudgetVocabulary` already does
at read time (`year()`/`programme()`/`amount()` each try one vocabulary
then fall back to the other). `BudgetSchemaSplitMigrator` reuses that same
field-presence logic, but for classification rather than tolerant reading:

- `classify(array $object): ?string` — returns `'BbvProgrammeBudget'` when
  the object carries `totalAmount`/`programmeStructure`, `'CommitmentBudget'`
  when it carries `authorised_amount`/`financialYear`, `null` when neither
  (or, pathologically, both) match — an unclassifiable row.
- `migrateBatch()` calls `classify()` per object, groups by target, and
  re-points each via `mapObjectToRenamedSchema()`.
- `assertCountsMatch(sourceCount, bbvCount + commitmentCount)` — **any**
  unclassifiable row makes `bbvCount + commitmentCount < sourceCount`,
  which aborts the whole batch (RuntimeException), same fail-closed
  contract as the precedent. No partial migration, no silent drop.

Given the live count is 0 today, this migrator's own unit tests
(fixture-driven, both vocabularies + one deliberately ambiguous/malformed
row) are the acceptance evidence for groups 1–2 of `tasks.md`; the count
re-verification against the shared dev instance (and, per this repo's own
`payroll-leaves-to-hrmq` precedent, **any other real deployment before this
ships there**) is a separate task, not satisfied by the unit tests alone.

### 2c. `BbvBudgetVocabulary` — retired, not kept

Its docblock is explicit about *why* it exists: reading a `Budget` record
tolerant of either colliding vocabulary. Once the schemas are distinct,
`BbvProgrammeBudgetReader` queries `BbvProgrammeBudget` directly and every
record it gets back is BBV-shaped by construction — there is nothing left
to adapt. Deleting it (rather than keeping it as inert legacy-compat code)
follows this repo's own "scope-debt" convention: a compatibility shim for a
collision that no longer exists is not "would still be needed if the
feature were finished" — it becomes dead code the moment the rename lands,
so it is deleted in the same change, not left for a future cleanup.
`BbvProgrammeBudgetReader`'s constructor drops the `BbvBudgetVocabulary $vocabulary`
parameter; `year()`/`programme()`/`amount()` call sites become direct field
reads (`$budget['fiscalYear']`, `$budget['programmeStructure']`,
`$budget['totalAmount']`).

### 2d. Seed-slug ADR-001 compliance, fixed in passing

While editing the exact seed `@self` blocks above (§2a), the BBV fragment's
seed slugs (`bbv-prov-budget-mobiliteit-2026`, `bbv-prov-budget-water-2026`,
…) do not carry the `example-` prefix ADR-001 (`hydra/openspec/architecture/adr-001-data-layer.md`)
requires for app-owned editorial seed data — these are illustrative worked
examples, not contract/anchor data, so `seedExemption` does not apply.
Since this change is already rewriting these exact lines (the `@self.schema`
value on each), the slug prefix is corrected in the same edit
(`bbv-prov-budget-mobiliteit-2026` → `example-bbv-prov-budget-mobiliteit-2026`,
etc.) per the "scope-debt scopes to the repos/lines the task already
touches" convention — not a broader sweep of every other pre-existing
non-compliant seed slug in the codebase, which is out of this change's
scope.

## 3. `LedgerGroup` (verzamelpost)

### 3a. Synthesis, justified from precedent

Three existing shapes inform this, and they disagree, which is why this is
a synthesis rather than a copy of one:

- `rj270-balance-sheet.json`/`rj270-pl.json`/`rj270-cash-flow.json`:
  `statement.sections[]`, each `{code, label, group, level}` plus either a
  leaf `accountRange: ["0100", "0199"]` (inclusive array-pair) or a rollup
  `computed: "sum-group:<group>"`.
- `balans-rubriek-mapping.json`: `variants[].mappings[]` each
  `{glAccountRange: "1000-1099", rubriekCode, rubriekLabel}` — a
  **hyphenated string**, not an array pair, and no `effectiveFrom`/`To`.
- `ChartOfAccountsMapping` (`bookkeeping-ifrs-rj-dual-gaap.json:101`):
  row-per-source-account (not a range) with `effectiveFrom`/`effectiveTo`
  (nullable = open-ended) plus a `coveragePercent` activation gate.
- `Account` (`shillinq_register.json:1321`): always addressed by a discrete
  `accountNumber`, never a range — the base unit every grouping ultimately
  resolves to.

`LedgerGroup` needs both "a contiguous RGS range" (the RJ270/rubriek
precedent — the fast way to say "everything from 1000 to 1099") and
"specific accounts regardless of range, plus explicit carve-outs" (the
`Account`-is-always-discrete precedent, and the reality that an operator's
real chart of accounts rarely aligns perfectly to RGS ranges). The
synthesis: **members are resolved at evaluation time from an optional
account-range array plus explicit include/exclude account-number lists** —
a range is convenient default membership, explicit adds/excludes are the
escape hatch, and a range-free group (explicit accounts only) is valid too
(both arrays default empty, `accountRanges` defaults empty — a group with
none of the three has no members, which is a valid, if useless, state the
schema does not need to forbid).

### 3b. Fields

```
LedgerGroup
  administrationId      string,  required   — tenant scope (every reviewed
                                              schema in this codebase denormalises
                                              this directly, even under a parent FK)
  code                   string,  required   — short operator-facing identifier
  name                    string,  required   — display label
  order                   integer, required, default 0 — sibling ordering
  parentLedgerGroupId  string,  nullable    — self-FK, enables nesting
                                              (mirrors Account.parentAccountNumber)
  accountRanges          array<{from,to}>, default [] — inclusive numeric-string
                                              pairs, RJ270 array-pair convention
                                              (not the rubriek-mapping hyphenated
                                              string — array pair is the more
                                              structured, already-precedented shape)
  includedAccountNumbers  array<string>, default [] — explicit adds, regardless
                                              of range
  excludedAccountNumbers  array<string>, default [] — explicit carve-outs from
                                              any matching range
  effectiveFrom           string,  nullable   — ISO-8601, per ChartOfAccountsMapping
  effectiveTo              string,  nullable   — ISO-8601, null = open-ended
```

No lifecycle — a `LedgerGroup` is configuration, edited directly, not a
workflow object (consistent with `Account`, `AllocationRule`, and the
rubriek-mapping precedent, none of which carry `x-openregister-lifecycle`).

### 3c. Seed data (ADR-001 anchor, not editorial example)

Seeded from the RJ270 balance-sheet sections
(`lib/Settings/statements/rj270-balance-sheet.json`) and the small-manufacturing
variant of `balans-rubriek-mapping.json` — one `LedgerGroup` per non-computed,
non-total `level: 2` section (`VA-IMVA`, `VA-MVA`, …, `KLS-SUS`; the
`level: 0`/`level: 1` rollup rows are not seeded as `LedgerGroup`s here — they
are statement-presentation rollups, not GL groupings an operator budgets
against directly; a future `budget-projection-engine`/`budget-grid-view`
change may add parent `LedgerGroup`s for them if the grid wants a rollup
row). Each seed row carries `@self.seedExemption: "anchor"` per ADR-001 (this
is canonical BW 2:373 / RJ270 statutory reference data every administration
needs, not a deletable worked example) and a plain (non-`example-`) slug
derived from the RJ270 section code (`ledger-group-va-imva`, …).

## 4. `AnnualBudget`

### 4a. Fields + lifecycle

```
AnnualBudget
  administrationId   string,  required
  fiscalYear          integer, required
  name                  string,  required   — e.g. "Begroting 2027"
  isDefault             boolean, required, default false
  state (lifecycle)      draft -> active -> closed
```

`x-openregister-lifecycle` dialect (per `GLTransaction`'s
draft→posted→reversed shape, `shillinq_register.json:511`):

```json
"x-openregister-lifecycle": {
  "field": "state",
  "initialState": "draft",
  "states": {
    "draft":  { "label": "Draft",  "description": "Under construction; lines editable." },
    "active": { "label": "Active", "description": "The budget in force for its fiscal year; lines still editable." },
    "closed": { "label": "Closed", "description": "Locked at year-end; superseded, retained for audit." }
  },
  "transitions": {
    "activate": {
      "from": "draft", "to": "active",
      "label": "Activate budget",
      "description": "Requires no other AnnualBudget with isDefault=true for the same administrationId+fiscalYear (REQ-BCS-006).",
      "requires": "OCA\\Shillinq\\Lifecycle\\AnnualBudgetDefaultGuard::isUniqueDefault"
    },
    "close": {
      "from": "active", "to": "closed",
      "label": "Close budget",
      "description": "Year-end lock; no further line edits."
    }
  }
}
```

### 4b. The one-default invariant, aligned now

The task brief is explicit that "scenarios will need exactly one default
later" (the `budget-scenarios` follow-up change) — this change does not
build scenario switching, but the invariant it will depend on (exactly one
`AnnualBudget` with `isDefault=true` per `administrationId`+`fiscalYear`)
is enforced now, not retrofitted, via an ADR-031 exception-path guard
(`AnnualBudgetDefaultGuard`, same pattern class as `BudgetBlocker`/
`DualGaapGuard`) checked on the `activate` transition — a
declarative uniqueness constraint across sibling objects is exactly the
kind of cross-object check the declarative lifecycle DSL cannot express,
matching every other guard already in this codebase's exception-path
inventory. `isDefault` is a separate boolean from `state` deliberately:
`state` governs *editability* (draft/active/closed), `isDefault` governs
*which one wins* when more than one `active` budget could theoretically
exist for the same year (e.g. a draft alternate/scenario budget under
`budget-scenarios`) — the two axes are already distinct in the schema even
though only one axis is exercised by this change's own UI.

## 5. `BudgetLine`

### 5a. Fields

```
BudgetLine
  administrationId  string,  required
  annualBudgetId     string,  required, format: uuid — FK to AnnualBudget
  ledgerGroupId       string,  required, format: uuid — FK to LedgerGroup
  source                string,  required, enum [manual, contract, recurring, projected, scenario], default "manual"
  month01Amount .. month12Amount   integer, default 0 (each) — minor units (EUR cents), matching CommitmentBudget's own convention
  notes                 string,  nullable
```

`source` is the marker this change's own scope explicitly says "later
changes populate": `manual` (this change's only writer — an operator typing
a number), `contract` (`budget-known-costs`, derived from an active
`Contract`/`RevenueContract`), `recurring` (`budget-known-costs`, derived
from a recurring-cost schedule), `projected` (`budget-projection-engine`),
`scenario` (`budget-scenarios`). This change declares the enum and defaults
every line to `manual`; it does not implement any non-manual writer.

### 5b. The "cumulative last column" is a UI concern, not a stored field

The task brief's spreadsheet mental model ("12 monthly columns and a
cumulative last column") describes the **grid UI** (`budget-grid-view`),
not a 13th persisted field. A stored `totalAmount` derived from summing
`month01Amount..month12Amount` would either drift from its 12 sources (if
computed once and not kept in sync) or need a same-object
`x-openregister-calculations` entry to stay live. Given §6's platform
hazard finding — declarative aggregations/calculations are currently
validated wrong for *cross-schema* filters — a same-object sum has no
cross-schema filter and is plausibly unaffected, but this change does not
gamble on that distinction for a field nothing yet reads: the total is
computed client-side by whichever page renders the 13 columns, deferred to
`budget-grid-view`. `BudgetLine` itself stores only the 12 source values.

## 6. Budget-vs-actuals roll-up

### 6a. The platform hazard, with a live-in-repo positive-control finding

`x-openregister-aggregations`/`-calculations` are validated at register-load
time by `AggregationAnnotationValidator`, which checks each `where[].field`
against the *declaring* schema's own properties instead of the *target*
schema's — so any cross-schema filter is silently rejected (logged only as
a `nextcloud.log` warning, `"annotation on schema"`), and the computed field
never materialises. Two pieces of evidence for this, found while writing
this design (not asserted, checked):

1. `CommitmentBudget.outstanding_commitments`
   (`bookkeeping-verplichtingenadministratie.json:1030`-ish, the
   `x-openregister-aggregations` block on the renamed `Budget`/
   `CommitmentBudget` schema itself) sums `Verplichtingsregel.remaining_committed`
   filtered by `where: [{field: "financialYear", ...}, {field: "programme", ...}]`.
   `CommitmentBudget`'s own declared property is `programmeCode`, not
   `programme` — if the validator checks the filter field against the
   *declaring* schema (`CommitmentBudget`) as the hazard finding states,
   `programme` does not exist there either, so this annotation may already
   be silently discarded today, independent of this change's rename.
2. `committedVsRealisedPerBudgetLine` (§2a, the aggregation this change's
   own rename touches via `join.through`) groups `VerplichtingRegel` and
   joins through the renamed `Budget`/`CommitmentBudget` — a genuinely
   cross-schema filter, textbook shape for the hazard.

`tasks.md` §6 makes running the positive control (grep `nextcloud.log` for
`"annotation on schema"` after a fresh import, both before and after the
rename, and confirm whether these two aggregations return non-empty rows on
seed data) a required task. **This change does not fix either finding** if
confirmed broken — `AggregationAnnotationValidator` lives in the foundation
`openregister` app, out of this app-repo's scope, and
`committedVsRealisedPerBudgetLine`'s owning requirement (REQ-VPL-011, which
explicitly mandates *"no bespoke reporting service"*) belongs to
`verplichtingen-commitment-accounting`, not this change. Findings are
surfaced in `specs/bookkeeping-verplichtingenadministratie/spec.md`'s delta
(§ below) and handed back, per this repo's own "measure widely, change
narrowly" convention — not silently patched around and not silently
ignored.

### 6b. This change's own roll-up: PHP service primary

Given the hazard, `budget-core-schema`'s own budget-vs-actuals roll-up
specs a PHP service pair as the primary, tested path —
`BudgetVsActualsReader`/`BudgetVsActualsCalculator`, mirroring the existing
`BbvProgrammeBudgetReader`/`Calculator` split (reader does every
OpenRegister read, calculator does the arithmetic, nothing else). It joins
`BudgetLine` (by `annualBudgetId`+`ledgerGroupId`) to actuals resolved from
`TrialBalanceLine` (`periodId`, `accountNumber`, `debitMovement`/
`creditMovement`, read-only, `bookkeeping-trial-balance.json:11`) filtered
to the `LedgerGroup`'s resolved member accounts (§3a's range+explicit-adds/
excludes resolution, done in PHP, not a declarative filter) — the same
in-memory-join pattern `BbvProgrammeBudgetReader::spendByProgramme()`
already uses for `GLLine`↔`GLTransaction` (`GLLine` carries neither `date`
nor `administrationId`; both come from the parent `GLTransaction` via an
explicit `transactionId` join over two `findAll()` calls, trying both the
object UUID and `transactionNumber` since different writers populate
`transactionId` differently — the precedent this new reader follows for the
same reason: a declarative join across `BudgetLine`→`LedgerGroup`→
`TrialBalanceLine` would hit the exact filter-validation hazard in §6a).

A *declarative* `x-openregister-aggregations` entry on `BudgetLine` is
still declared, as **documentation of the intended shape**, not as the
computed path this change depends on — carrying an explicit code comment
that it is unverified/expected-discarded per §6a, plus the same positive-
control task applied to it (confirm empty/discarded, not silently assume).

## 7. Minimal pages + nav placement

### 7a. What ships

Per-schema `index`/`detail` pairs, 6 pages total, modelled on the
`DBAOpdrachten`/`DBAOpdrachtDetail` pattern
(`src/manifest.d/dba-compliance-marker.json`):

- `AnnualBudgets` (`/begroting/annual-budgets`, index, schema
  `AnnualBudget`) / `AnnualBudgetDetail` (`/begroting/annual-budgets/:id`,
  detail) — detail page's `fields` cover `fiscalYear`/`name`/`isDefault`,
  lifecycle chip, plus a child collection listing its `BudgetLine`s (FK
  `annualBudgetId`).
- `LedgerGroups` (`/begroting/ledger-groups`, index, schema `LedgerGroup`)
  / `LedgerGroupDetail` (`/begroting/ledger-groups/:id`, detail) — detail
  shows the resolved account-range + explicit include/exclude, and its
  child `LedgerGroup`s (via `parentLedgerGroupId`).
- `BudgetLines` (`/begroting/budget-lines`, index, schema `BudgetLine`) /
  `BudgetLineDetail` (`/begroting/budget-lines/:id`, detail) — plain
  CRUD-shaped list/detail; **not** the spreadsheet grid (`budget-grid-view`
  builds that as a `type: "custom"` page reusing this same schema, the way
  `BudgetLineCommitments` already reuses `Verplichtingsregel` without being
  its index page).

No dashboard/aggregation page ships here — §6's PHP roll-up service has no
consuming page in this change; its first consumer is `budget-grid-view`
(non-goal §10).

### 7b. Nav placement — forward-compatible with the reserved Cluster 4 leaf

`nav-six-clusters` (design.md §2, not yet landed — PR #923 OPEN) documents
*"A 'Budgets' leaf is reserved but not created in Cluster 4 (Banking &
Cashflow) — task brief: a later change adds it."* This change is that later
change, but the **current live manifest is still the pre-cluster, ~29-
top-level-group shape** (`nav-six-clusters` has not merged) — there is no
`Cluster 4`/`Banking & Cashflow` top-level group to place these pages under
yet. This change therefore adds its own new top-level group, **matching the
reserved leaf's name exactly** (`id: "Budgets"`, label "Budgets") to today's
flat nav, so that whenever `nav-six-clusters` (or a later relocation change)
lands, it is a single `menu-layout.json` relocation entry
(`"Budgets": "Banking & Cashflow"`) — the same mechanical fold every other
group in this codebase uses — not a re-design. This is noted as a
coordination item for whichever change lands second (mirrors
`payroll-leaves-to-hrmq`'s own §9 sequencing note for its `Payroll`/
`ExpenseSettlement` relocation), not an implementation dependency this
change's own tasks are blocked on.

## 8. Byte budget

**Current measured state** (2026-08-20,
`node tests/check-manifest-budget.js`): `manifest.json=460786B
manifest.d/=662587B total=1123373B budget=1126300B` → **2,927B headroom**.

**Estimated cost of this change's pages**: this repo's own measured
per-page estimator (median 946B, mean 1,276B) × 6 new pages (§7a) ≈
**5,676–7,656B**. The two renamed-schema fragment edits (§1–§2) are
name-only changes to existing JSON keys — no new bytes beyond the
`seedExemption`/slug-prefix fix (§2d, a handful of bytes) and the new
`LedgerGroup`/`AnnualBudget`/`BudgetLine` schema declarations themselves
(§3–§5) in `register.d/` — **not** counted against the `manifest.json` +
`manifest.d/` budget, since `check-manifest-budget.js` measures only the
frontend manifest, not `lib/Settings/register.d/`.

**This exceeds current headroom under every page-count scenario.** Stance:

1. **Preferred — land after `nav-six-clusters` (PR #923)**: frees a
   measured 29,253 bytes (`payroll-leaves-to-hrmq design.md` §7's own
   figure for a different fragment, cited here only for the *gate's*
   post-merge headroom, not this change's byte count) → ~32,180B headroom,
   comfortably covering 5,676–7,656B. `tasks.md` group 7's page-adding
   tasks are sequenced to run only once `check:manifest-budget` shows this
   much headroom.
2. **Fallback — schema-first, if forced to land before #923 merges**: land
   groups 1–6 (schema rename + migrator + `LedgerGroup`/`AnnualBudget`/
   `BudgetLine` schemas + the PHP roll-up service) without group 7's pages.
   The three new schemas remain reachable only via the OpenRegister API and
   PHPUnit-tested services until page-adding headroom exists — no gate
   requires every schema to have a page (`TrialBalanceLine` already ships
   with none, `bookkeeping-trial-balance.json`'s own `_meta.description`).
   `check:nav-reachability` is unaffected either way since no page is added
   without its own reachable menu entry in the same commit.

The implementer MUST re-run `node tests/check-manifest-budget.js` after
group 7's edits and report the exact delta, not rely on this estimate —
same discipline as every other change in this repo's history that touches
the manifest.

## 9. e2e coverage

New Playwright spec `tests/e2e/budget-core-schema.spec.ts` (SPDX header,
`becomesVisible` helper, `test.describe('budget-core-schema — … (REQ-BCS-…)')`,
data-defensive `test.skip()` on empty seed data — per the
`budget-line-commitments.spec.ts` exemplar):

1. `budget-core-schema::budgets-nav-group-reachable` — the `Budgets`
   top-level group is present in the effective manifest and its three index
   pages resolve.
2. `budget-core-schema::ledger-group-seeded-on-import` — the seeded
   `LedgerGroup` rows (§3c) are visible on the `LedgerGroups` index after a
   fresh import.
3. `budget-core-schema::budget-line-monthly-columns-editable` — a
   `BudgetLine` detail page renders the 12 monthly amount fields and an
   operator-entered value persists (schema-shape smoke test only — the real
   grid UX is `budget-grid-view`'s scope).

Backend-only, `@e2e exclude`:

- The `BudgetSchemaSplitMigrator` classify/migrate/count-abort logic —
  PHPUnit, no browser-visible surface.
- `AnnualBudgetDefaultGuard`'s one-default enforcement — PHPUnit lifecycle
  transition test (attempt to `activate` a second default, assert
  rejection), mirroring `BudgetBlocker`'s own test pattern.
- `BudgetVsActualsReader`/`Calculator` — PHPUnit only, per §6b (same
  treatment as `BbvProgrammeBudgetReader`/`Calculator`, neither of which has
  dedicated e2e coverage today — their only consumer, `BbvComplianceDashboard`,
  is covered separately).
- The §6a positive-control findings — verified by `nextcloud.log` grep +
  a direct `_limit=1`/`total` API read against the aggregation endpoint, not
  a browser assertion.

## 10. Non-goals (each names its follow-up change)

- **The real spreadsheet-grid UI** (drag-fill, multi-cell paste, inline
  monthly editing across all `BudgetLine`s of an `AnnualBudget` at once) —
  `budget-grid-view`. This change's `BudgetLines` page (§7a) is a plain
  CRUD list/detail, not the grid.
- **Projection math** (extrapolating future months from partial actuals,
  run-rate calculations) — `budget-projection-engine`. `BudgetLine.source
  = "projected"` is declared here but nothing writes it.
- **Contract/recurring cost derivation** (auto-populating `BudgetLine`
  amounts from active `Contract`/`RevenueContract` records or a recurring-
  cost schedule) — `budget-known-costs`. `source = "contract"`/`"recurring"`
  are declared, not implemented.
- **Scenarios and modifiers** (multiple non-default `AnnualBudget`s per
  fiscal year, what-if deltas) — `budget-scenarios`. §4b's one-default
  guard is built now specifically so this follow-up has an invariant to
  build against, not one it must retrofit.
- **Charts** (the BBV-style traffic-light/trend visualisations
  `BbvComplianceDashboard` already has, generalised to the cross-domain
  `AnnualBudget`/`LedgerGroup` model) — `budget-charts`.

## 11. Open questions

1. **`LedgerGroup` rollup rows** (§3c) — should the `level: 0`/`level: 1`
   RJ270 statement-total/heading rows get their own parent `LedgerGroup`s
   now, or is that genuinely `budget-grid-view`/`budget-projection-engine`
   scope? This change seeds only `level: 2` leaves; a follow-up implementer
   should confirm before assuming the parent rows are needed.
2. **§6a's two positive-control findings** — if `CommitmentBudget.outstanding_commitments`
   and/or `committedVsRealisedPerBudgetLine` are confirmed silently
   discarded, who owns filing the openregister-repo fix for
   `AggregationAnnotationValidator`, and who owns the `verplichtingen-
   commitment-accounting`-side spec correction (REQ-VPL-011's "no bespoke
   reporting service" mandate is unsatisfiable if the declarative path is
   dead)? Handed to the orchestrator, not resolved here.
3. **`Budgets` top-level nav group's icon/order** (§7b) — no icon/order
   convention was specified by the task brief; `tasks.md` picks a
   provisional value (an unused `Wallet`-adjacent icon, order placed near
   `BankingTreasury`/`Cashflow` per the Cluster-4-adjacency intent) but this
   is a product/design call, not an engineering one, and may need revision
   before this ships.
4. **Whether `BudgetLine.month01Amount..month12Amount` should instead be a
   single `phasing: array<integer>` of fixed length 12** — named fields
   were chosen for OpenRegister column/facet friendliness (an index page
   can sort/filter by `month06Amount` directly; an array element cannot),
   matching every other reviewed schema's preference for named scalar
   properties over positional arrays, but this is worth a product sign-off
   before `budget-grid-view` builds the 12-column grid against it.

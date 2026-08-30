# Design: budget-scenarios

## 0. Method

Verified directly against `origin/development` (2026-08-20, this checkout),
same discipline as every sibling in this wave. `budget-core-schema`,
`budget-projection-engine`, `budget-grid-view`, and `budget-known-costs`'s
`proposal.md`/`design.md`/`tasks.md`/`specs/` were all read in full before
writing this document; `LedgerGroup`/`AnnualBudget`/`BudgetLine` and
`budget-known-costs`'s `CashflowRecurring.contractReference`/
`KnownCostScheduleExpander` are used exactly as those changes define them —
no field is added, renamed, or reinterpreted here, and no sibling's own
file is edited by this change. `CashflowScenario`'s full field list is read
from `lib/Settings/register.d/zzp-cashflow-13wk.json`
(`components.schemas.CashflowScenario`), not assumed.
`BegrotingswijzigingStacker.php` is read in full (`lib/Service/`, 138
lines).

## 1. Why `BudgetScenario` is a new schema, not an extension of `CashflowScenario`

### 1a. Field-by-field mismatch, checked before deciding

| `CashflowScenario` field | Shape | Fits `BudgetScenario`'s need? |
|---|---|---|
| `horizonId` (required FK) | Binds to one `CashflowForecastHorizon` — a 13-week window | No — a begroting scenario spans `AnnualBudget` fiscal years, not a rolling 13-week horizon; there is no horizon to bind to |
| `aanpassingen[].weeks` | `array<integer>` — ISO week numbers | No — every begroting-side dated concept in this wave (`CashflowRecurring.validFrom/To`, `BudgetLine`'s fiscal-year months, `Contract.startDate/endDate`) is a calendar date, not a week number; converting between the two is exactly the kind of unstated-decision hazard `budget-projection-engine proposal.md`'s own Why section warns about for "average growth in %" |
| `aanpassingen[].type` | Closed 4-value enum, all cashflow-specific (`AR_PROJECTION_OVERRIDE`/`RECURRING_COST_ADJUSTMENT`/`NEW_REVENUE`/`BUFFER_POLICY_OVERRIDE`) | No — none of the three modifier kinds this change needs (§4) map onto these without redefining what the enum values mean, which would break `bookkeeping-cashflow-13wk`'s own existing consumers of this exact enum |
| `isDefault` | **Does not exist** | This change's entire "only one scenario can be default" requirement has nowhere to attach without adding a field to a schema owned by a different, already-shipped capability |
| `result` | Declared object (`minBufferAmount`/`minBufferWeek`/`onderschrijdingBuffer`/`actiesuggesties`), **no producer anywhere in `lib/`** (grepped) | Not reusable even as a shape — it is itself an unfulfilled declaration in its own capability, and its fields (a 13-week buffer minimum) have no begroting-domain meaning |

Every field that would need to change (`horizonId`'s binding,
`weeks`→dates, the `type` enum, adding `isDefault`) is a redesign of a
schema this change does not own, in the same way `budget-core-schema
design.md` §1b explicitly avoided reassigning a collision-causing slug to
either side rather than trying to merge two genuinely different concepts.
`CashflowScenario` and `BudgetScenario` model genuinely different things —
a 13-week cash-buffer stress test vs. a multi-year budget what-if — exactly
the same "share zero field names, model genuinely different things"
judgement `budget-core-schema` applied to `Budget`/`BbvProgrammeBudget`/
`CommitmentBudget`.

### 1b. Why no migrator is needed, unlike `budget-core-schema`'s rename

`budget-core-schema` needed `BudgetSchemaSplitMigrator` because two
fragments declared the identical schema *slug* (`Budget`), so live objects
existed under a name that had to be classified and split.
`CashflowScenario` and `BudgetScenario` are, from the first commit of this
change, two different slugs with no naming collision and no shared live
data — no object is ever created under one slug and later needs to be
re-pointed to the other. This is a simpler case than
`SubsidieOrderConsolidationMigrator`'s own `subsidieMigrationRequired():
false` finding (that precedent still had to *check* whether a definitions
merge orphaned objects, because both historical `Subsidie` definitions
shared one slug); here there was never a shared slug to begin with, so
there is nothing to check and nothing to migrate. `tasks.md` includes a
grep-verification task confirming no fragment or seed object references
`BudgetScenario` before this change, as the equivalent of that precedent's
own live-count check.

### 1c. `CashflowScenario`'s fate — findings recorded, not fixed

Two pre-existing defects in `bookkeeping-cashflow-13wk`, found while
reading `CashflowScenario` for this change, are recorded here and handed to
the orchestrator rather than fixed:

1. **`CashflowScenario.result` has no producer.** The schema declares a
   computed-results shape (buffer minimum, action suggestions) that no
   PHP class or declarative aggregation anywhere in `lib/` populates
   (grepped, confirmed) — a scenario can be created but never actually
   recomputed. This is a different capability's own gap, structurally
   identical to `budget-known-costs proposal.md`'s independent finding
   that no class expands `CashflowRecurring` either — two instances of the
   same "declared but never built" pattern in one fragment.
2. **`ScenarioCreator.vue` is dead code.** 231 lines of working
   adjustment-builder UI, never imported by `src/registry.js`, referenced
   nowhere else in `src/` (grepped).

Neither is this change's own capability; fixing either means editing
`bookkeeping-cashflow-13wk`'s own files, out of scope here exactly as
`budget-core-schema design.md` §6a's own aggregation-hazard findings were
surfaced against a different capability's spec rather than silently
patched. `BudgetScenario`/`BudgetScenarioEvaluator` (§6) are built so this
change's own `result`-equivalent (the side-by-side comparison) **does**
have a real producer from day one — the mistake found in
`CashflowScenario` is not repeated here.

## 2. `BudgetScenario`

### 2a. Fields + lifecycle

```
BudgetScenario
  administrationId  string,  required
  name                 string,  required
  description           string,  nullable
  isDefault             boolean, required, default false
  status (lifecycle)     draft -> active -> archived
```

`x-openregister-lifecycle`, deliberately **not** mirroring
`AnnualBudget`'s `activate` guard style — see §3 for why the enforcement
mechanism differs even though the field shape (`status` + `isDefault` as
two distinct axes) is the same pattern `budget-core-schema` §4b already
established:

```json
"x-openregister-lifecycle": {
  "field": "status",
  "initialState": "draft",
  "states": {
    "draft":    { "label": "Draft",    "description": "Being composed; modifiers editable." },
    "active":   { "label": "Active",   "description": "Available for comparison; modifiers still editable." },
    "archived": { "label": "Archived", "description": "Retired; retained for audit, no longer selectable for comparison." }
  },
  "transitions": {
    "publish": { "from": "draft", "to": "active", "label": "Publish scenario" },
    "archive": { "from": "active", "to": "archived", "label": "Archive scenario" }
  }
}
```

`isDefault` is set via `BudgetScenarioDefaultPromoter` (§3), a service
call, not a lifecycle transition — because promoting a default requires
writing to a *second* object (the previously-default scenario), which the
declarative lifecycle DSL's single-object `requires:` guard contract
cannot express (it returns a boolean about the object being transitioned,
never a side effect on another object). This is the same
"cross-object check the declarative DSL cannot express" class
`budget-core-schema design.md` §4b already names for
`AnnualBudgetDefaultGuard`, but resolved with a different shape (§3).

### 2b. Scope of the default invariant

"Only one scenario can be default" is scoped to `administrationId` alone,
**not** to any fiscal year — unlike `AnnualBudget.isDefault`
(`budget-core-schema` §4b, scoped to `administrationId` + `fiscalYear`). A
scenario's own modifiers (§4) can span multiple fiscal years (an
indefinite `RECURRING_END` modifier capping a validTo that would otherwise
run for years), so there is no single fiscal year to scope "the default
scenario" to — an administration has at most one default scenario, full
stop, mirroring the task brief's own unqualified phrasing ("only one
scenario can be default").

## 3. `BudgetScenarioDefaultPromoter` — atomic demotion, not rejection

### 3a. Why this diverges from `AnnualBudgetDefaultGuard`'s enforcement style

`budget-core-schema`'s `AnnualBudgetDefaultGuard` **rejects** a second
`activate` transition when a default already exists for the same
administration + fiscal year — a deliberate two-step, auditable friction
for a fiscal-year commitment (an operator must explicitly deactivate the
old default before promoting a new one). A scenario default is a different
kind of decision: it answers "which what-if does the grid show by default
when nobody picked one" — a UI convenience, not a financial commitment. The
task brief's own framing ("only one scenario can be default," describing a
simple toggle) matches **atomic demotion** — promoting scenario B
automatically demotes scenario A in the same action — much better than a
reject-and-retry flow, so this change does not copy
`AnnualBudgetDefaultGuard`'s style uncritically; it states the divergence
and the reason for it explicitly, per this repo's own "an expression of a
pattern matches the pattern" convention (matching a *precedent*'s
justification, not just its mechanism, when the justification itself
does not carry over).

### 3b. Why this cannot be a single declarative guard, and what it is instead

Demoting a *different* object as a side effect of promoting this one is
not expressible as an ADR-031 `requires:` precondition (which can only
return true/false about the object being transitioned) — it needs a
service method that performs two writes. `BudgetScenarioDefaultPromoter::
promote(scenarioId)`:

1. Reads the current default (`isDefault: true`) `BudgetScenario`, if any,
   for the target scenario's `administrationId`.
2. If one exists and its id differs from `scenarioId`: writes
   `isDefault: false` to it.
3. Writes `isDefault: true` (and `status: active` if not already) to the
   target scenario.
4. **Verifies, by re-reading**, that exactly one `BudgetScenario` for the
   administration now has `isDefault: true`. On any mismatch (e.g. a
   concurrent promotion from a second browser tab raced this one), logs an
   error and surfaces the inconsistency rather than silently resolving it
   — **this is a verified two-write sequence, not a database transaction**;
   OpenRegister's object store has no documented multi-object transaction
   primitive this codebase relies on anywhere else (every existing
   count-abort migrator in this app compensates for the same absence by
   checking *afterward*, never by wrapping writes in a transaction), so
   this design does not claim atomicity it cannot deliver — it states the
   verified-after-write pattern plainly and flags the residual race window
   as an open question (§13.1) rather than asserting a guarantee this
   platform does not provide.

### 3c. The zero-default state

When zero `BudgetScenario` rows have `isDefault: true` for an
administration (day one, before any scenario is promoted, or after a
verification-detected inconsistency is manually resolved), **no scenario
overlay is applied anywhere** — the grid and every other consumer show the
real `AnnualBudget`/`BudgetLine` data exactly as `budget-core-schema`/
`budget-grid-view` already render it today. This is not an error state; it
is this change's own "before this feature is used" baseline, stated
explicitly so a consumer never has to guess what "no default scenario"
means.

## 4. `BudgetScenarioModifier`

### 4a. Fields

```
BudgetScenarioModifier
  administrationId       string,  required
  scenarioId               string,  required, format: uuid — FK to BudgetScenario
  modifierType              string,  required, enum [RECURRING_END, RECURRING_AMOUNT_CHANGE, LEDGER_AMOUNT_DELTA]
  effectiveDate              string,  required, format: date — the dated-modifier anchor
                                       (mirrors BegrotingswijzigingStacker's own
                                       "dated-effective delta" contract, generalised
                                       from that class's determined/status gate to a
                                       date rather than a workflow status)
  targetRecurId              string,  nullable — FK to CashflowRecurring.recurId
                                       (budget-known-costs's primitive); required for
                                       RECURRING_END / RECURRING_AMOUNT_CHANGE, unused
                                       for LEDGER_AMOUNT_DELTA
  newStandardAmount          number,  nullable — required for RECURRING_AMOUNT_CHANGE;
                                       the replacement amount from effectiveDate forward
  targetLedgerGroupId        string,  nullable, format: uuid — FK to LedgerGroup;
                                       required for LEDGER_AMOUNT_DELTA
  amountDeltaCents            integer, nullable — required for LEDGER_AMOUNT_DELTA;
                                       signed, applied to effectiveDate's own month only
```

### 4b. The three modifier kinds, mapped to the task brief's own example

- **`RECURRING_END`** — hypothetically caps `targetRecurId`'s
  `CashflowRecurring.validTo` at `effectiveDate` (never later than its real
  `validTo`, if one is already set). Covers *"employee a+b leave the org"*:
  shillinq never models an employee — it models "the `CashflowRecurring`
  row booking that headcount's GL-recognised cost ends on date X," exactly
  the boundary the task brief itself draws ("payroll itself belongs to
  hrmq, do not invent an HR concept here"). Two employees leaving is two
  `RECURRING_END` modifiers, one per targeted `recurId`.
- **`RECURRING_AMOUNT_CHANGE`** — hypothetically replaces
  `targetRecurId`'s `standardAmount` with `newStandardAmount` from
  `effectiveDate` forward (before `effectiveDate`, the real amount still
  applies). Covers *"recurring drops X from date x"* — the operator/UI
  computes `newStandardAmount = oldAmount − X` before saving; the schema
  itself stores the resulting absolute amount, not a delta, matching
  `CashflowRecurring.standardAmount`'s own absolute-value convention rather
  than introducing a second, delta-shaped vocabulary for the same
  quantity.
- **`LEDGER_AMOUNT_DELTA`** — a signed one-off adjustment to
  `targetLedgerGroupId`'s budgeted amount for `effectiveDate`'s own month
  only (not a step change carried forward — a genuinely one-time event).
  Covers *"amount X gets transferred to the bank at date X."* **RESOLVED
  (2026-08-20, RULING 1)** — this change now seeds the minimal
  balance-sheet `LedgerGroup` this modifier needs as a target; see §4c.

### 4c. The balance-sheet `LedgerGroup` gap — closed here, minimally, and why this change (not `budget-known-costs`) owns it

**The gap, confirmed blocking.** `budget-core-schema`'s default seed is
P&L-shaped only — its own §3c amendment explicitly drops every
`rj270-balance-sheet.json` section from the default seed, reasoning that
"a begroting is a monthly-phased flow plan" and a balance-sheet stock
account "is not a begroting use case this programme has identified." That
reasoning does not hold for `LEDGER_AMOUNT_DELTA`: the task brief's own
worked example — *"amount X gets transferred to the bank at date X"* — is
precisely a balance-sheet, stock-side event (a cash/bank position moving),
and with a P&L-only seed there is **no `LedgerGroup` node this modifier can
target at all**. Ruling: this is a real, blocking gap, not a deferrable
one — an operator following the task brief's own example verbatim, on a
freshly imported administration, would find `LEDGER_AMOUNT_DELTA` has
nothing to point at.

**Ownership: `budget-scenarios`, not `budget-known-costs`.**
`budget-known-costs` has no use for a balance-sheet `LedgerGroup` anywhere
in its own scope — every `CashflowRecurring` row it reads targets an
expense/revenue account via `accountNumberExpense`, resolved to a P&L-side
`LedgerGroup`, exactly as `budget-core-schema`'s default seed already
covers. The requirement for a balance-sheet target originates entirely
from `LEDGER_AMOUNT_DELTA`, a schema this change alone declares — so this
change is the one whose own new requirement creates the need, and the one
that owns closing it. Handing this to `budget-known-costs` would mean that
change growing a seed addition purely to satisfy a sibling's modifier
type it has no other reason to know about.

**What is seeded, and how it stays minimal.** This change's own
`lib/Settings/register.d/budget-scenarios.json` fragment gains **one**
seed `LedgerGroup` object — using the `LedgerGroup` schema exactly as
`budget-core-schema` §3b already defines it, no field added or
reinterpreted — sourced from `rj270-balance-sheet.json`'s own `VLA-LIQ`
section (`"code": "VLA-LIQ", "label": "Liquide middelen", "accountRange":
["1000", "1099"]`, verified directly against that file):

```
LedgerGroup (seed, this change's own fragment)
  administrationId       — matches this wave's own seed administration convention
  code                     "VLA-LIQ"
  name                     "Liquide middelen"
  order                    0
  parentLedgerGroupId    null — root, no balance-sheet hierarchy added
  accountRanges            [{ "from": "1000", "to": "1099" }]
  includedAccountNumbers  []
  excludedAccountNumbers  []
  effectiveFrom / effectiveTo   null / null
  @self.seedExemption     "anchor"  — canonical BW 2:373/RJ270 statutory reference
                            data, same ADR-001 justification budget-core-schema's
                            own P&L seed already uses
  @self.slug               "ledger-group-vla-liq"  — matches budget-core-schema's own
                            leaf-naming convention (`ledger-group-<rj270-code-lowercased>`)
```

A fragment other than the one declaring a schema seeding an object under
that schema is an already-established, live pattern in this codebase —
verified, not assumed: `bookkeeping-cost-centers-dimensions.json` seeds
`Project` objects (a schema it does not declare) and
`bookkeeping-provincies-bbv-variant.json` seeds `BBVProgramma` objects the
same way. This change follows that exact precedent for its one
`LedgerGroup` row, rather than editing `budget-core-schema`'s own fragment
— consistent with this wave's repeated discipline of not touching a
sibling's already-spec'd files.

**This is explicitly NOT a reversal of `budget-core-schema`'s P&L-only
default seed — stated here so a later reader does not "helpfully" re-add
the whole balance sheet.** `budget-core-schema` §3c's decision to drop
`rj270-balance-sheet.json` from the *default* seed stands, unchanged, for
every reason that document gives. This change adds exactly **one** leaf —
the one node `LEDGER_AMOUNT_DELTA` needs to be usable per the task brief's
own example — not the `VA`/`VLA`/`EV`/… balance-sheet section family
`budget-core-schema` deliberately excluded. No parent `LedgerGroup`
("Vlottende activa") is added either, keeping this the smallest addition
that closes the gap rather than a partial restoration of the excluded
hierarchy. A future change that wants a fuller balance-sheet-scoped
`LedgerGroup` tree (assets, liabilities, equity beyond this one leaf) is
still doing new, deliberate work — this seed is not a foothold that
"already got most of the way there."

### 4d. Why a fourth, "RECURRING_START" kind is not declared

A brand-new recurring cost with no prior real existence (e.g., "if we sell
product X, we start a new logistics recurring cost from date Y") is
representable today without a fourth modifier kind: the operator creates a
real `CashflowRecurring` row (`budget-known-costs`'s own primitive) with
`validFrom` set to the hypothetical start date, and does **not** run
`KnownCostBudgetWriter` against it in the real budget — but this change's
own `BudgetScenarioEvaluator` (§6) only reads *existing* `CashflowRecurring`
rows by `targetRecurId`, so a row that should only ever exist inside one
scenario's hypothesis has no clean home. This is recorded as an open
question (§13.3), not solved by adding a fourth modifier kind here without
a stated design for it.

## 5. `BudgetScenarioModifierGuard` — same-target conflict, precedence for everything else

### 5a. The one case that needs an explicit conflict rule

Two modifiers in the same scenario targeting the **same** `targetRecurId`
with **overlapping** effective ranges (e.g., a `RECURRING_END` at
`2027-06-01` and a `RECURRING_AMOUNT_CHANGE` at `2027-03-01` on the same
row, both notionally "in force" during March–May 2027) have no
well-defined combined meaning — does the amount change first and then the
row ends, or does ending make the amount change moot from March onward? An
ADR-031 exception-path guard (`BudgetScenarioModifierGuard`, extending the
save precondition on `BudgetScenarioModifier`, same shape as
`CashflowRecurringGuard`) rejects this combination outright, fail-closed:
**at most one `RECURRING_*` modifier per `targetRecurId` per scenario.** An
operator who genuinely wants a phased change (amount drops, then ends
later) expresses it as two *scenarios* compared independently, or the guard
is revisited once a real use case demands a resolvable ordering — not
guessed at here.

### 5b. Everything else sums, order-independent

Any other pair of modifiers within one scenario — different `recurId`s,
or a `RECURRING_*` modifier alongside a `LEDGER_AMOUNT_DELTA` on the same
`LedgerGroup` — are **additive**, exactly mirroring
`BegrotingswijzigingStacker::applyMovements()`'s own "every wijziging's
delta accumulates onto the running position" contract (its own docblock:
*"reversals (a wijziging with negative delta) net out exactly"*). The
evaluator (§6) never needs to decide an application order between two
modifiers that do not share a `targetRecurId`, because summation is
commutative — this is the direct answer to "precedence when two modifiers
hit the same account/period": **same-`recurId` conflicts are rejected at
save time (§5a, an unambiguous-meaning requirement); everything else sums,
with no ordering rule needed because addition does not have one.**

## 6. `BudgetScenarioEvaluator` — non-destructive, generalising `BegrotingswijzigingStacker`

### 6a. The contract, read directly from the precedent

`BegrotingswijzigingStacker::currentStand(basisTaskFields, wijzigingen)`:
starts from a basis, applies every *determined*-status wijziging's signed
deltas in integer cents, returns the effective stand — **no persistence, no
I/O**, per its own docblock. `BudgetScenarioEvaluator::evaluate()` follows
the identical shape at `BudgetLine`'s own grain:

```
evaluate(
  baseBudgetLines: BudgetLine[],           // real data, already resolved per (ledgerGroupId, month)
  ledgerGroups: LedgerGroup[],
  cashflowRecurringRows: CashflowRecurring[],
  modifiers: BudgetScenarioModifier[],
  fiscalYear: int
): array<string, array{month: string, base: int, scenario: int, delta: int}>
```

1. `base[ledgerGroupId][month]` = the sum of every real `BudgetLine`'s
   amount for that node and month — reusing `budget-known-costs design.md`
   §8d's own "sum every `BudgetLine` targeting a node, regardless of
   source" consumer contract, so a scenario compares against the same
   effective baseline the grid itself will show, not a narrower one.
2. For every `RECURRING_END`/`RECURRING_AMOUNT_CHANGE` modifier: construct
   a **hypothetical** in-memory copy of its `targetRecurId`'s
   `CashflowRecurring` row (never writing to the real one) with
   `validTo`/`standardAmount` overridden per §4b, then call
   `budget-known-costs`'s own **pure** `KnownCostScheduleExpander::expand()`
   on that hypothetical row — the identical arithmetic a real regeneration
   would use, so a scenario's projection and what `KnownCostBudgetWriter`
   would actually produce if the modifier became real never silently
   diverge. Subtract the row's *un-modified* expansion (also computed via
   the same expander) from this hypothetical expansion to get the
   modifier's own per-month delta, then add that delta into
   `scenario[ledgerGroupId][month]`.
3. For every `LEDGER_AMOUNT_DELTA` modifier: add `amountDeltaCents`
   directly into `scenario[targetLedgerGroupId][effectiveDate's month]`.
4. `scenario[ledgerGroupId][month]` starts from `base[ledgerGroupId][month]`
   before any modifier deltas are added (mirrors
   `BegrotingswijzigingStacker`'s own "start from the basis, then apply
   deltas" order) — a scenario with zero modifiers evaluates to exactly the
   base, by construction, not a special-cased empty branch.
5. `delta = scenario - base` per cell, returned alongside both values —
   the side-by-side comparison the task brief and `design.md` §0's own
   non-destructive requirement both need; **`BudgetLine` is never written
   to by this class**, matching `BegrotingswijzigingStacker`'s own
   "no persistence, no I/O" contract exactly.

### 6b. Parent-`LedgerGroup` rollup carries through unchanged

A parent `LedgerGroup`'s scenario-adjusted value is computed the same way
its base value already is (`budget-core-schema` §3d: own `BudgetLine` if
one exists, else recursive sum of children) — applied to `scenario[...]`
values exactly as it is to `base[...]` values, so a modifier on a leaf
`LedgerGroup` correctly propagates to its parent's displayed comparison
without a second rollup rule being invented here.

### 6c. Query budget

`BudgetScenarioReader` (the thin impure counterpart to the pure
`BudgetScenarioEvaluator`, same reader/calculator split every sibling
change in this wave uses) batches: `BudgetScenario.findAll([administrationId])`,
`BudgetScenarioModifier.findAll([scenarioId: in [...]])`,
`CashflowRecurring.findAll([administrationId])` (delegates to
`budget-known-costs`'s own resolved rows where available, or reads
directly — an implementation detail left to the implementer since both
changes read the identical schema the identical way), `BudgetLine.findAll([annualBudgetId: in [...]])`,
`LedgerGroup.findAll([administrationId])` — 5 calls, independent of
modifier or `LedgerGroup` count, following the query-budget discipline
every reader in this wave already commits to. A PHPUnit regression test
asserts this bound.

## 7. Precedence and coexistence with real, manual, and derived `BudgetLine`s

A scenario's comparison **never** touches a real `BudgetLine` — it only
ever produces an in-memory `scenario[...]` view (§6). This directly answers
"evaluation is non-destructive... side-by-side comparison is the point":
there is nothing for a manual edit or a `budget-known-costs`-derived line to
be overridden *by* — the real data is always exactly what it was before any
scenario existed, and a scenario is compared against whatever that real
data currently is (including any `budget-known-costs`-derived
`contract`/`recurring` lines, per §6a step 1's reuse of that change's own
sum-by-node contract).

## 8. Modifier kind is not a `BudgetLine.source` writer

No `BudgetScenarioModifier` ever causes a `BudgetLine.source: "scenario"`
row to be written by this change — `budget-core-schema` declared that enum
value "for later changes to populate," and this change's own non-
destructive requirement (§6a, §7) means it never populates any `BudgetLine`
at all. **This is flagged explicitly, not silently left inconsistent**
(§13.4): `budget-core-schema`'s own `source` enum still carries an
unpopulated `"scenario"` value after this change lands, same as it does
today — this change's contribution is the *evaluator*, not a writer, and a
future change (or a UI action inside this one, "pin this scenario's
numbers as the new manual baseline") could conceivably populate
`source: "scenario"` by writing the evaluator's output into real
`BudgetLine`s, but doing so would cross this change's own non-destructive
line and is explicitly not built here.

## 9. Minimal pages + nav placement

`BudgetScenarios` (index, `/begroting/scenarios`, schema `BudgetScenario`)
/ `BudgetScenarioDetail` (detail, `/begroting/scenarios/:id` — shows
`isDefault`, `status`, a "Promote to default" action calling
`BudgetScenarioDefaultPromoter`, and a child collection of its
`BudgetScenarioModifier`s), `BudgetScenarioModifiers` (index) /
`BudgetScenarioModifierDetail` (detail) — plain CRUD, mirroring
`budget-core-schema` §7a's own minimal-pages precedent.

`BudgetScenarioComparison` (`type: "custom"`, `/begroting/scenarios/:id/compare`)
— a standalone comparison table (`LedgerGroup` rows × month columns ×
base/scenario/delta, no grid embedding) calling `BudgetScenarioEvaluator`
via `BudgetScenarioReader`. This is this change's **day-one** comparison
surface, independent of `budget-grid-view` — a plain table, not the grid
(`design.md` §10 covers the grid-embedded version as a separate, sequenced
task).

Nested under the `Budgets` top-level nav group `budget-core-schema` §7b
defines, created by whichever change lands it first — the same either-order
convention every sibling in this wave already follows.

## 10. Grid integration — sequenced after `budget-grid-view`

`budget-grid-view`'s own `design.md` explicitly names "a scenario switcher
on this grid" as its non-goal, owned by this change — so this change, not
that one, must add a scenario-selector control to `BudgetGrid.vue` once it
exists. Unlike every other either-order nav-group convention in this wave,
**this specific task is a genuine hard dependency, stated plainly**:
`BudgetGrid.vue` does not exist in this codebase yet (`budget-grid-view` is
itself an unimplemented sibling change as of this writing), so there is no
component to add a selector prop/slot to until that change lands. This
change's backend (schema, guard, promoter, evaluator, reader) and its own
standalone `BudgetScenarioComparison` page (§9) have no such dependency and
ship regardless of `budget-grid-view`'s status; only the
grid-embedded-overlay task is deferred, tracked as its own `tasks.md` group
marked "requires `budget-grid-view` to have landed `BudgetGrid.vue`."

## 11. e2e coverage

New Playwright spec `tests/e2e/budget-scenarios.spec.ts` (SPDX header,
`becomesVisible` helper, `test.describe('budget-scenarios — … (REQ-BSC-…)')`,
data-defensive `test.skip()` on empty seed data):

1. `budget-scenarios::scenario-comparison-renders-base-and-scenario` — the
   `BudgetScenarioComparison` page renders base and scenario columns for a
   seeded scenario with at least one modifier.
2. `budget-scenarios::promote-to-default-demotes-previous-default` — an
   operator promoting scenario B to default sees scenario A's `isDefault`
   flip to false in the same UI flow.
3. `budget-scenarios::modifier-crud-reachable` — the
   `BudgetScenarioModifiers` index/detail pages are reachable and an
   operator can create a `LEDGER_AMOUNT_DELTA` modifier.

Backend-only, `@e2e exclude`:

- `BudgetScenarioEvaluator`'s full arithmetic (§6) — PHPUnit, pure, no
  browser-visible surface, mirroring `BegrotingswijzigingStacker`'s own
  test treatment and `BudgetProjectionCalculator`'s.
- `BudgetScenarioDefaultPromoter`'s atomic-demotion + verification logic
  (§3) — PHPUnit against a mocked `ObjectServiceInterface`, including the
  verification-mismatch logging path.
- `BudgetScenarioModifierGuard`'s same-`recurId` conflict rejection (§5a) —
  PHPUnit lifecycle/save-precondition test.
- The query-count regression (§6c) — PHPUnit, mirroring every sibling
  reader's own test.

## 12. Non-goals (each names its owning change)

- **The dated planned-cost primitive itself** — `budget-known-costs` owns
  `CashflowRecurring.contractReference`; this change only points
  `RECURRING_*` modifiers at it by `recurId` (§4b, `proposal.md`
  restates this explicitly per the task brief's own instruction not to
  define two).
- **The spreadsheet-grid UI and its scenario-selector embedding** —
  `budget-grid-view` for the grid itself; this change's own grid-selector
  task (§10) is sequenced after it, not built as part of that change.
- **Projection/growth-rate math** — `budget-projection-engine`.
- **Charts** — `budget-charts`.
- **Payroll/HR concepts** — explicitly out of scope; "employee leaves" is
  modelled as a `RECURRING_END` modifier on a GL-recognised cost line
  only, never as an employee entity. Payroll belongs to `hrmq`.
- **Fixing `CashflowScenario.result`'s missing producer or
  `ScenarioCreator.vue`'s dead-code status** — `bookkeeping-cashflow-13wk`'s
  own pre-existing gaps, recorded (§1c) and handed to the orchestrator, not
  fixed here.
- **Writing `BudgetLine.source: "scenario"` rows** — this change's
  evaluator is read-only/non-destructive by design (§8); no writer for
  that enum value is built here.

## 13. Open questions

1. **The verification-after-write race window** (§3b) — two concurrent
   `promote()` calls (e.g. two browser tabs) could both pass their own
   read-then-write sequence before either's verification read-back runs,
   momentarily leaving zero or two defaults. This change logs and surfaces
   the inconsistency rather than preventing it outright — whether a
   stronger guarantee (e.g. an OpenRegister-level optimistic-lock/ETag
   check on the promotion writes, if the platform offers one) is needed is
   a platform question, not resolved here.
2. **RESOLVED (2026-08-20, RULING 1)** — ~~`LEDGER_AMOUNT_DELTA` targeting
   a balance-sheet `LedgerGroup`~~: §4c now answers this. This change seeds
   exactly one balance-sheet `LedgerGroup` ("Liquide middelen," sourced
   from `rj270-balance-sheet.json`'s `VLA-LIQ` section) in its own
   fragment, closing the gap without reopening `budget-core-schema`'s own
   P&L-only default seed decision. Left in place, struck through, so a
   reader comparing against an earlier read of this document can see the
   question was answered, not silently deleted.
3. **A "brand-new, scenario-only" recurring cost** (§4d) — today, a
   scenario can only reference an already-existing real `CashflowRecurring`
   row. Whether a fourth modifier kind (a fully hypothetical, never-real
   recurring cost that exists only inside one scenario's evaluation) is
   needed is left open, pending a real use case.
4. **`BudgetLine.source: "scenario"` remains permanently unpopulated by
   this change** (§8) — flagged so a future reader of `budget-core-schema`'s
   own enum does not assume every declared `source` value has a writer;
   whether a "pin this scenario as the new baseline" action should ever be
   built (and if so, whether it belongs to this change or a follow-up) is
   a product call, not decided here.

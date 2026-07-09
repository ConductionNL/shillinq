---
kind: config
depends_on: [calc-engine-scalar-functions]
chain:
  - revive-declarative-calc-layer         # THIS change (head, kind:config) — JSON-AST rewrites + materialise + aggregations on shillinq_register.json
  - revive-declarative-calc-layer-guards  # follow-up (kind:code) — the thin PHP guard services for the cross-object calcs that are genuinely imperative (ADR-031 §Exceptions)
---

# Change: revive-declarative-calc-layer

## Why

Shillinq's `lib/Settings/shillinq_register.json` declares 43 `x-openregister-calculations`
blocks, but **41 of them are dead** — they never compute. Two independent gaps cause this:

1. The expressions are written as **infix string ternaries** (e.g.
   `"@self.state = 'scheduled' AND @self.dueDate < today()"`), but OpenRegister's
   `CalculationEvaluator` only interprets the **JSON-AST** dialect (`{"if":[…]}`,
   `{"eq":[…]}`, `{"prop":…}`, `{"lit":…}`). String expressions are silently never evaluated.
2. They lack `materialise: true`, so even a correctly-shaped calc is skipped by
   `CalculationOnSaveListener` — the on-save listener only persists calcs marked to materialise.

The result: every derived field on FixedAsset book values, ZZP deduction totals, KOR YTD
revenue, retention countdowns, overdue flags, reorder points, and ~30 more silently stays
empty. Only one calc — `SalesOrderLine.maandWaarde` — was already fixed to JSON-AST +
`materialise: true` and live-verified; it is the worked reference pattern for the rest.

Now is the right time because the declarative-first rule (ADR-031) makes the schema-engine
path mandatory when OR can express the behaviour, and the OpenRegister dependency
`calc-engine-scalar-functions` (adds `max`/`min`/`coalesce`/`abs`/`round`/`year`/`monthsElapsed`
to the evaluator) unblocks the subset of calcs that need those primitives.

## What Changes

This is overwhelmingly a **register JSON config change** (declarative). The 41 dead calcs
audit into five buckets (full per-calc table in `design.md`):

- **Convertible now (23)** — rewrite to JSON-AST + add `materialise: true`, using only ops
  the evaluator already supports (`if`, arithmetic, compare, `concat`, `now`, `diffDays`,
  `dateDiff`, `prop`, `lit`). E.g. `RepaymentInstallment.isOverdue`, `MileageEntry.totalAmount`,
  `ZzpDeduction.totalDeduction`, `SisaReport.auditOpinion`.
- **Convertible after the dependency (6)** — rewrite to JSON-AST using the new scalar
  ops from `calc-engine-scalar-functions`; `materialise: true`. E.g. the three FixedAsset
  book-value calcs (`max` + `monthsElapsed`), `RateSchedule.effectiveWindowLabel` (`coalesce`),
  `InnovatieboxElection.innovationAttributedProfitDisplay` (`min`),
  `InventoryReorderRule.reorderPointCalculated` (`coalesce`).
- **Cross-object → `x-openregister-aggregations` (3)** — SUMs over OTHER schemas, which
  cannot be per-object calcs: `KorRegime.ytdRevenue` (sum of Invoices),
  `ZzpDeduction.ytdQualifyingHours` (sum of UrenRegistratie), `ZzpDeduction.taxableProfit`
  (sum of GLLine). Add an `x-openregister-aggregations` entry each (the aggregation grammar
  supports `sum` over a related register-schema collection with a filter).
- **Cross-object → ADR-031 guard service (9)** — genuinely imperative cross-object/external
  work the declarative engine cannot express: `lookup()` of external rate tables
  (`MileageEntry.ratePerKmLookup`, `PerDiem.dailyRateLookup`, `Receipt.multiCurrencyConversion`,
  three `ZzpDeduction` rate-table lookups), `sha256` hashing (`Account.emuAggregationHash`),
  and cross-schema metric folding (`ComplianceReport.complianceScore`/`criteriaResults`).
  These are **deferred to a `kind:code` follow-up** (`revive-declarative-calc-layer-guards`)
  per the ADR-032 split — this config head ships the high-value bulk (32 declarative fixes).
- **Leave alone (2)** — `SalesOrderLine.maandWaarde` (already JSON-AST + materialise, the
  reference) and `Receipt.photoValidation` (already a guard-based calc).

The config head touches **only `lib/Settings/shillinq_register.json`** — no PHP, no routes,
no frontend. The deferred guard follow-up is the only code.

## Capabilities

### New Capabilities
- `declarative-calc-layer`: the cross-cutting requirement that every shillinq
  `x-openregister-calculations` block be expressed in the evaluator-executable JSON-AST
  dialect (or relocated to `x-openregister-aggregations` / a guard) AND, for per-object
  derived fields, carry `materialise: true` so the on-save listener persists them.

### Modified Capabilities
<!-- none — the per-domain capability specs (bookkeeping-fixed-assets-depreciation,
     bookkeeping-zzp-tax-regime, bookkeeping-kor-kleine-ondernemersregeling, etc.) already
     describe the derived-field BEHAVIOUR; this change does not alter that behaviour, it
     makes the declarations actually execute. The new declarative-calc-layer capability owns
     the executability contract horizontally. -->

## Impact

- **Config:** `lib/Settings/shillinq_register.json` — 32 calc blocks rewritten to JSON-AST +
  `materialise: true` (23 now + 6 after dep) and 3 SUM calcs relocated to
  `x-openregister-aggregations`. Re-imported via `ConfigurationService::importFromApp()` in
  the repair step.
- **Dependency:** requires OpenRegister change `calc-engine-scalar-functions` to be merged
  for the 6 after-dep calcs (the 23 now-convertible and 3 aggregations do not need it).
- **Deferred code (follow-up change):** `revive-declarative-calc-layer-guards` — 9 cross-object
  calcs become thin PHP guard services (or are merged where they share a rate-table lookup).
- **No** DB schema, API, route, or frontend changes in this head.
- **Broader value:** every shillinq derived field starts computing — the app's declarative
  layer goes from ~5% live to ~93% live (39 of 41 dead calcs revived declaratively or via
  the guard follow-up).

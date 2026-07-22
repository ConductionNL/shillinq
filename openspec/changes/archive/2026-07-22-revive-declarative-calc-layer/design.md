# Design: revive-declarative-calc-layer

## Context

Shillinq is a thin client over OpenRegister (ADR-001/022) — it owns no DB tables and declares
business logic as schema metadata (ADR-031). The register
(`lib/Settings/shillinq_register.json`) declares 43 `x-openregister-calculations` blocks.
A read-only audit (Python over the JSON, no code edit) found:

- **2 already correct** — `SalesOrderLine.maandWaarde` (JSON-AST + `materialise: true`,
  live-verified) and `Receipt.photoValidation` (guard-based calc).
- **41 dead** — they never compute, for one or both of two reasons:
  1. The `expression`/`formula` is an **infix string** (e.g. `"a ? b : c"`,
     `"sum(Invoice.x)"`, `"IF … THEN …"`) — `CalculationEvaluator` only interprets the
     **JSON-AST** dialect, so a string expression is silently ignored.
  2. The block lacks `materialise: true` — `CalculationOnSaveListener` skips any calc not
     marked to materialise, so even a well-shaped per-object calc is never persisted.

This is exactly the ADR-031 declarative-first surface: where OR can express the behaviour as
schema metadata, the app MUST declare it there. The calcs were *authored* declaratively but
in a dialect the engine can't run — the fix is to make them executable, not to write services.

**Evaluator vocabulary (authoritative, from the OR `computed-fields` spec + the dependency):**
existing ops `prop, lit, concat, if, not, and, or, +, -, *, /, %, eq/ne/lt/lte/gt/gte, now,
diffDays, formatDate, dateDiff`; the `calc-engine-scalar-functions` dependency adds
`max, min, coalesce, abs, round, year, monthsElapsed`. Cross-object folding
(`sum`/`lookup`/`map` over *other* objects) is **explicitly out of scope** for the per-object
evaluator — those belong to `x-openregister-aggregations` or a guard.

## Goals / Non-Goals

**Goals**
- Make every shillinq derived field actually compute.
- Keep the high-value work declarative (JSON-AST + materialise + aggregations) in a single
  `kind:config` head touching only the register JSON.
- Cleanly separate the genuinely-imperative cross-object calcs into a `kind:code` follow-up
  so the config head can ship without waiting on guard-service implementation.

**Non-Goals**
- NOT implementing 13 guard services blindly — most cross-object SUMs become aggregations;
  only 9 calcs genuinely need imperative seams, and those ship in the follow-up.
- NOT changing the per-domain capability specs' *behaviour* (book-value formulas, deduction
  rules, etc. are unchanged) — only making the declarations executable.
- NOT touching frontend, routes, or DB.

## Decisions

### Decision 1 — Split into a config head + a code follow-up (ADR-032)

The bulk (32 of 41 dead calcs) is pure declarative register config: JSON-AST rewrites +
`materialise: true` + 3 aggregations. The remaining 9 are cross-object/external work that
needs PHP guards (ADR-031 §Exceptions). Per ADR-032's mixed-change split, this change is the
`config` head; `revive-declarative-calc-layer-guards` (`kind:code`, `depends_on` this) is the
follow-up. The config head is the high-value, low-risk win and lands first. Precedent in this
repo: `order-revenue-recognition` (config head) → `order-revenue-recognition-engine` (code).

**Alternative considered:** one `mixed` change. Rejected — ADR-032 prefers the split, and the
config head is independently shippable and verifiable.

### Decision 2 — Cross-object SUMs become aggregations, not guards

The aggregation grammar (`openregister/openspec/specs/aggregation-api/spec.md`) supports
`count|sum|avg|min|max` over a register-schema collection with a filter. The three YTD/total
SUMs map cleanly: they are `sum` of one numeric field over one related schema with a
fiscal-year/period filter. They are therefore `x-openregister-aggregations`, NOT guards.
External `lookup()` of a rate table and `sha256`/document folding cannot be expressed by the
aggregation grammar → those stay guards.

### Decision 3 — The `maandWaarde` reference pattern

The already-fixed `SalesOrderLine.maandWaarde` is the canonical template for every Bucket-1/2
conversion: `materialise: true` + `targetField` + a nested `{"if":[{"eq":[{"prop":"…"},{"lit":"…"}]}, …, …]}`
JSON-AST tree. `@self.x` infix references become `{"prop":"x"}`; literals become `{"lit":…}`;
`today()`/`now()` becomes `{"now":[]}`; `datediff(a,b)`/`dateDiffDays(a,b)` becomes
`{"diffDays":[…]}` or `{"dateDiff":[…]}` per the evaluator's date-op signatures.

## Per-calc audit table (all 43)

Legend — **Bucket**: 1=convertible now · 2=convertible after dep · 3a=cross-object aggregation ·
3b=cross-object guard (follow-up) · 4=leave alone. **Action** is what the head (1/2/3a) or
follow-up (3b) does.

| # | Schema.calc | Current expr (abridged) | Bucket | Action / rationale |
|---|---|---|---|---|
| 1 | BankConnection.consentRemainingDays | `dateDiffDays(@self.consentExpiresAt, now())` | 1 | `dateDiff`+`now` exist → JSON-AST + materialise |
| 2 | Account.daysUntilRetention | `null` | 1 | trivial `lit:null` (or derive from retention) → JSON-AST + materialise |
| 3 | RetentionRule.daysUntilRetention | `@self.effectiveTo != null ? datediff(today(),@self.effectiveTo) : null` | 1 | `if`+`ne`+`diffDays`+`now` → JSON-AST + materialise |
| 4 | kernGegevensConfig.denominatorStaleWarning | `@self.lastUpdatedAt != null ? dateDiffDays(today(),…)>365 : true` | 1 | `if`+`ne`+`diffDays`+`gt` → JSON-AST + materialise |
| 5 | FixedAsset.monthlyDepreciation | nested ternary linear/degressive | 1 | only `if`/arith/compare → JSON-AST + materialise |
| 6 | RateSchedule.isCurrentlyEffective | `status='active' AND eff<=@today AND (exp IS NULL OR exp>=@today)` | 1 | `and`/`or`/compare/`eq` → JSON-AST + materialise |
| 7 | MileageEntry.totalAmount | `@self.distance * @self.ratePerKm` | 1 | `*` → JSON-AST + materialise |
| 8 | PerDiem.allowanceAmount | `@self.nightCount * @self.dailyRate` | 1 | `*` → JSON-AST + materialise |
| 9 | PerDiem.nightCountWarning | `datediff(end,start) != nightCount ? '…' : null` | 1 | `if`+`ne`+`diffDays` → JSON-AST + materialise |
| 10 | RepaymentInstallment.isOverdue | `(state='scheduled' OR state='overdue') AND dueDate<today()` | 1 | `and`/`or`/`eq`/`lt`/`now` → JSON-AST + materialise |
| 11 | WinstToerekening.verdeelsleutelRatio | nested ratio ternary | 1 | `if`/`and`/`gt`/`/` → JSON-AST + materialise |
| 12 | ZzpDeduction.qualifiesForUrencriterium | `isStartersOpvolger ? ytd>=800 : ytd>=1225` | 1 | `if`+`gte` (reads materialised ytdQualifyingHours, see #34) → JSON-AST + materialise |
| 13 | ZzpDeduction.mkbWinstvrijstellingAmount | `(taxableProfit - zelfst - starters) * pct` | 1 | arithmetic only → JSON-AST + materialise (inputs from #36/#37/#38) |
| 14 | ZzpDeduction.totalDeduction | `zelfst + starters + mkb` | 1 | `+` → JSON-AST + materialise |
| 15 | SisaReport.auditOpinion | `IF remediation>0 THEN 'disclaimer' ELSE IF …` | 1 | nested `if`+compare → JSON-AST + materialise |
| 16 | InventoryReorderRule.isLowStock | `lifecycleState='active' AND linkedStockQuantity<=minimumLevel` | 1 | `and`/`eq`/`lte` → JSON-AST + materialise |
| 17 | Project.recognisedRevenue | (formula prose, conditions) | 1 | expressible with `if`/arith over own props → JSON-AST + materialise |
| 18 | Project.wipBalance | (formula prose) | 1 | arith over own props → JSON-AST + materialise |
| 19 | ProjectAssignment.utilization | (formula prose) | 1 | arith/`/` over own props → JSON-AST + materialise |
| 20 | UrenRegistratie.utilizationPercent | (formula, groupBy) | 1 | per-object ratio (drop groupBy framing) → JSON-AST + materialise |
| 21 | DepreciationSchedule.bookValue | (formula) | 1 | arith over own props → JSON-AST + materialise |
| 22 | DepreciationSchedule.depreciationAmount | (formula) | 1 | arith over own props → JSON-AST + materialise |
| 23 | VatReturn.teBetalenOfTeruggave | (formula) | 1 | arith/`if` over own VAT props → JSON-AST + materialise |
| 24 | FixedAsset.currentBookValue | `max(residual, cost - …*monthsElapsed(acqDate))` | 2 | needs `max`+`monthsElapsed` (dep) → JSON-AST + materialise |
| 25 | FixedAsset.commercialBookValue | `max(0, cost*(1 - rate*(monthsElapsed/12)))` | 2 | needs `max`+`monthsElapsed` (dep) → JSON-AST + materialise |
| 26 | FixedAsset.fiscalBookValue | `max(0, cost*(1 - rate*(monthsElapsed/12)))` | 2 | needs `max`+`monthsElapsed` (dep) → JSON-AST + materialise |
| 27 | RateSchedule.effectiveWindowLabel | `eff \|\| ' – ' \|\| COALESCE(exp,'open-ended')` | 2 | needs `coalesce`+`concat` (dep) → JSON-AST + materialise |
| 28 | InnovatieboxElection.innovationAttributedProfitDisplay | `route='forfaitair' ? min(pct*profit, cap) : null` | 2 | needs `min` (dep) → JSON-AST + materialise |
| 29 | InventoryReorderRule.reorderPointCalculated | `IF(usage, usage*COALESCE(lead,7)+usage*COALESCE(safety,1), …)` | 2 | needs `coalesce` (dep) → JSON-AST + materialise |
| 30 | KorRegime.ytdRevenue | `sum(Invoice.totalAmountExclVat)` | 3a | aggregation: `sum` of Invoice.totalAmountExclVat, fiscal-year filter |
| 31 | ZzpDeduction.ytdQualifyingHours | `sum(UrenRegistratie.hours)` | 3a | aggregation: `sum` of UrenRegistratie.hours, fiscal-year/qualifying filter |
| 32 | ZzpDeduction.taxableProfit | `sum(GLLine.amount)` | 3a | aggregation: `sum` of GLLine.amount, P&L/period filter |
| 33 | Account.emuAggregationHash | `sha256(contributingIds + … )` | 3b | guard — `sha256` not an evaluator op; deterministic hash service |
| 34 | Receipt.multiCurrencyConversion | `amount * lookup('ExchangeRate', …)` | 3b | guard — external rate-table `lookup` |
| 35 | MileageEntry.ratePerKmLookup | `lookup('MileageRate', …)` | 3b | guard — external rate-table `lookup` |
| 36 | PerDiem.dailyRateLookup | `lookup('PerDiemRate', …)` | 3b | guard — external rate-table `lookup` |
| 37 | ZzpDeduction.zelfstandigenaftrekAmount | `qualifies ? lookup('ZzpDeductionAmounts', …) : 0` | 3b | guard — rate-table `lookup` (per tax year) |
| 38 | ZzpDeduction.startersaftrekAmount | `(starter && <3 && qualifies) ? lookup(…) : 0` | 3b | guard — rate-table `lookup` |
| 39 | ZzpDeduction.mkbWinstvrijstellingPercentage | `lookup('ZzpDeductionAmounts', …)` | 3b | guard — rate-table `lookup` |
| 40 | ComplianceReport.complianceScore | (formula, source/filter/metric/scale) | 3b | guard — cross-schema metric folding the aggregation grammar can't express as a single sum/count |
| 41 | ComplianceReport.criteriaResults | (formula, source/filter/output) | 3b | guard — cross-schema per-criterion folding into a structured output |
| 42 | SalesOrderLine.maandWaarde | JSON-AST `{if:[…]}` + materialise | 4 | leave — the reference pattern |
| 43 | Receipt.photoValidation | guard `PhotoValidator::validate` | 4 | leave — already a guard |

**Bucket counts:** 1 → 23 · 2 → 6 · 3a → 3 · 3b → 9 · 4 → 2. Head ships 1+2+3a = 32
declarative fixes; follow-up ships 3b = 9 guards.

> Note on guard consolidation (for the follow-up): #34–#39 are all rate-table `lookup`s and
> SHOULD share one `RateLookupGuard` (or per-table thin methods) rather than 6 separate
> services. So "9 guard calcs" ≈ ~3 guard services (rate-lookup, emu-hash, compliance-fold).
> Do not pre-decide the exact service count here — that is the follow-up's design.

## ADR-031 decision rationale (declarative-vs-imperative)

- **Buckets 1, 2, 3a are declarative** and MUST be schema metadata per ADR-031 — the engine
  can express them (JSON-AST per-object for 1/2; aggregation grammar for 3a). No service.
- **Bucket 3b is an ADR-031 §Exception (2)+(1):** external rate-table lookups and
  hash/cross-schema folding are imperative work OR's calc/aggregation engines cannot model.
  Each is a thin, focused guard called *by* the engine, documented here as the exception.
  `lookup()` of an external/reference table is the textbook "behaviour spans schemas in a way
  the extension can't express" case; `sha256` is "OR's extension is missing".
- The two Bucket-4 calcs are already compliant; left untouched.

## Verification approach

1. Re-import the register: `occ maintenance:repair` (or the app's repair step calling
   `ConfigurationService::importFromApp()`); confirm import succeeds with no schema-save
   rejection (e.g. `notification-bad-message`-class errors don't apply, but JSON-AST malformed
   calcs are rejected at save).
2. For each affected schema (one representative per bucket — e.g. FixedAsset, RepaymentInstallment,
   KorRegime, ZzpDeduction), POST/seed one object via the OR objects API
   (`/api/objects/shillinq/{schema}`) with the inputs the calc reads.
3. **Assert the derived field computes:** read the object back and assert the materialised
   field is present and non-null with the expected value (e.g. `isOverdue=true` for a past
   `dueDate`; `currentBookValue` between `residualValue` and `acquisitionCost`; `ytdRevenue`
   equals the sum of seeded Invoices). `maandWaarde` is the proven baseline — replicate its
   verification shape per converted calc.
4. Bucket-2 verification is gated on `calc-engine-scalar-functions` being merged into the
   running OR; verify those after the dep lands.

## Risks / Trade-offs

- **[Bucket-1 "convertible" misjudged]** A calc flagged convertible might secretly need a
  helper OR lacks → **Mitigation:** the audit already re-checked each against the exact op
  vocabulary; any calc needing `sha256`/`lookup`/string-XML was moved to 3b. The
  per-task conversion step re-verifies expressibility before writing the AST.
- **[Aggregation filter/scope wrong]** A 3a SUM might fold the wrong period/tenant →
  **Mitigation:** verification step 3 asserts the sum equals seeded source rows; RBAC +
  multi-tenant filtering is applied by the aggregation engine itself.
- **[Dep not merged]** The 6 Bucket-2 calcs depend on `calc-engine-scalar-functions` →
  **Mitigation:** `depends_on` declared; the 23+3 non-dep fixes still ship and verify
  independently if the dep slips.
- **[Materialise on legacy objects]** Existing objects won't have the field until re-saved →
  **Mitigation:** OR ships `RematerialiseCalculationsCommand`; note in tasks for operators.

## Migration Plan

1. Land `calc-engine-scalar-functions` in OR (dependency).
2. Merge this config head → re-import register → re-materialise existing objects via
   `RematerialiseCalculationsCommand` if backfill of historical objects is wanted.
3. Land the `revive-declarative-calc-layer-guards` follow-up for the 9 cross-object calcs.
- **Rollback:** revert the register JSON to HEAD; calcs return to dead state (no data loss —
  materialised fields are derived, not source).

## Open Questions

See `DEFERRED_QUESTIONS` in the change summary — chiefly the aggregation-vs-guard judgement on
`ComplianceReport.complianceScore`/`criteriaResults`, and whether `Account.daysUntilRetention`
(currently literal `null`) should derive from a retention date or stay a placeholder.

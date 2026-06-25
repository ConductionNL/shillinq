# Tasks: revive-declarative-calc-layer

> Scope = this `kind:config` head only (register JSON edits). The 9 Bucket-3b guard calcs are
> the `revive-declarative-calc-layer-guards` follow-up and are NOT tasked here (ADR-032 split).
> All edits are to `lib/Settings/shillinq_register.json` via the Edit tool (no scripting — CLAUDE.md).

## 1. Bucket 1 — convert the 23 evaluator-supported calcs to JSON-AST + materialise

- [x] 1.1 Convert the 11 date/compare calcs (consentRemainingDays, Account.daysUntilRetention, RetentionRule.daysUntilRetention, denominatorStaleWarning, RateSchedule.isCurrentlyEffective, PerDiem.nightCountWarning, RepaymentInstallment.isOverdue, InventoryReorderRule.isLowStock, SisaReport.auditOpinion, WinstToerekening.verdeelsleutelRatio, ZzpDeduction.qualifiesForUrencriterium) to JSON-AST + `materialise: true`, mirroring `SalesOrderLine.maandWaarde`
- [x] 1.2 Convert the arithmetic calcs (FixedAsset.monthlyDepreciation, MileageEntry.totalAmount, PerDiem.allowanceAmount, ZzpDeduction.mkbWinstvrijstellingAmount, ZzpDeduction.totalDeduction, Project.recognisedRevenue, Project.wipBalance, ProjectAssignment.utilization, VatReturn.teBetalenOfTeruggave) to JSON-AST + `materialise: true`. RECLASSIFIED to guard (3b follow-up): UrenRegistratie.utilizationPercent (reads `@aggregate.*` cross-object aggregates the per-object evaluator cannot resolve); DepreciationSchedule.bookValue + DepreciationSchedule.depreciationAmount (use `relatedObject('FixedAsset', …)` — no such evaluator op).
- [x] 1.3 Self-check each Bucket-1 AST uses ONLY existing evaluator ops; reclassified the 3 calcs above to guard (needed `@aggregate`/`relatedObject`)

## 2. Bucket 2 — convert the 6 dep-gated calcs (needs calc-engine-scalar-functions)

- [x] 2.1 Convert the 3 FixedAsset book-value calcs (currentBookValue, commercialBookValue, fiscalBookValue) to JSON-AST using `max` + `monthsElapsed` + `materialise: true`
- [x] 2.2 Convert RateSchedule.effectiveWindowLabel (`coalesce`+`concat`), InnovatieboxElection.innovationAttributedProfitDisplay (`min`), InventoryReorderRule.reorderPointCalculated (`coalesce`) to JSON-AST + `materialise: true`

## 3. Bucket 3a — relocate the 3 cross-object SUMs to x-openregister-aggregations

- [x] 3.1 Add `x-openregister-aggregations` for KorRegime.ytdRevenue (sum Invoice.totalAmountExclVat, fiscal-year filter) and remove its dead per-object calc
- [x] 3.2 Add `x-openregister-aggregations` for ZzpDeduction.ytdQualifyingHours (sum UrenRegistratie.hours) and ZzpDeduction.taxableProfit (sum GLLine.amount), removing their dead per-object calcs

## 4. Verification

- [x] 4.1 Re-import the register (`occ maintenance:repair`); import succeeded ("Shillinq configuration imported successfully") with NO `x-openregister-calculations annotation … invalid` / `calculation-unknown-op` warnings — all JSON-AST calcs validated at schema-save
- [x] 4.2 POST'd one object per representative schema and asserted the derived field materialises: MileageEntry.totalAmount=31.5 (150×0.21); PerDiem.allowanceAmount=300 (3×100), nightCountWarning=null; BankConnection.consentRemainingDays=194; RateSchedule.isCurrentlyEffective=true + effectiveWindowLabel="2026-01-01 – open-ended" (Bucket-2 coalesce+concat); FixedAsset.monthlyDepreciation=200, currentBookValue=11000, commercial/fiscalBookValue=11000 (Bucket-2 max+monthsElapsed); SisaReport.auditOpinion="adverse" (nested if). Test objects deleted after verification.
- [ ] 4.3 Run `RematerialiseCalculationsCommand` to backfill existing objects — DEFERRED to operator (no pre-existing seeded objects present to backfill; new saves materialise correctly as verified in 4.2)

## 5. Follow-up handoff (out of scope here)

- [ ] 5.1 Confirm the 9 Bucket-3b cross-object/external calcs (3 lookups grouped under rate-lookup, emuAggregationHash, multiCurrencyConversion, the 3 ZzpDeduction rate lookups, 2 ComplianceReport folds) are carried into `revive-declarative-calc-layer-guards` (kind:code, depends_on this) — do NOT implement guards here

## Criteria / quality

- Every converted calc uses ONLY the evaluator vocabulary in the `declarative-calc-layer` spec (existing ops + the 7 dep scalar funcs).
- Every per-object derived field carries `materialise: true`; no string-ternary `expression` remains in Buckets 1–3a.
- The 2 Bucket-4 calcs (maandWaarde, photoValidation) are left untouched.
- No PHP, route, frontend, or DB change in this head — `lib/Settings/shillinq_register.json` only.
- Edits made with the Edit tool / hand-written JSON, never sed/awk/scripts (CLAUDE.md).
- Bucket-2 verification is gated on `calc-engine-scalar-functions` being merged.

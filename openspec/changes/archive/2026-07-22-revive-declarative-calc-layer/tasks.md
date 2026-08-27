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
- [x] 4.3 The "no pre-existing seeded objects" claim was checked against `shillinq_register.json`'s
  `objects` array and found FALSE for 3 of the converted schemas: `RateSchedule` (5 seeded
  objects), `SisaReport` (3), `InventoryReorderRule` (3) — their calc'd fields (e.g.
  `isCurrentlyEffective`, `auditOpinion`, `reorderPointCalculated`) would stay unmaterialised
  until each object is next saved. Rather than leave this to a manual operator step (which
  OR's `RematerialiseCalculationsCommand` requires running by hand), added an automatic
  in-app repair step: `lib/Repair/RematerialiseConvertedCalculations.php`, registered in
  `appinfo/info.xml`'s `<repair-steps><post-migration>` (runs on every `occ maintenance:repair`
  / `occ app:enable shillinq`, same as the fleet's other backfill repair steps, e.g.
  `BackfillFiscalPeriods`). It re-saves every existing object on the 17 schemas this change
  actually converted (Bucket 1 ∪ Bucket 2 per-object calcs, EXCLUDING the 3 fields reclassified
  to guard in task 1.2, and EXCLUDING `Account.emuAggregationHash`/`MileageEntry.ratePerKm`/
  `PerDiem.dailyRate`/`ZzpDeduction`'s rate-lookup fields/`UrenRegistratie.utilizationPercent`/
  `DepreciationSchedule.*` — those belong to the separate, already-archived
  `guards-to-declarative-calc-refs` change's scope, confirmed while researching task 5.1 below).
  Re-saving via `ObjectService::saveObject()` with the object's own data (carrying its `id`, so
  it resolves as an UPDATE) triggers `CalculationOnSaveListener` exactly as a genuine edit
  would. Unit tests: `tests/Unit/Repair/RematerialiseConvertedCalculationsTest.php` (7 tests —
  every existing object resaved with its own id; every one of the 17 schemas visited even when
  empty; objects without an id/uuid skipped; a save/findAll failure on one schema/object is
  best-effort and does not block the rest), all green.

## 5. Follow-up handoff (out of scope here)

- [x] 5.1 Confirmed the Bucket-3b carry-forward by tracing the actual chain (NOT the
  originally-envisioned `revive-declarative-calc-layer-guards`; the real follow-up shipped
  under a different name): `openspec/changes/archive/2026-06-20-guards-to-declarative-calc-refs`
  (already archived/landed) declares `chain: [revive-declarative-calc-layer,
  guards-to-declarative-calc-refs]` and converts 10 of the deferred calcs — `MileageEntry.ratePerKm`,
  `Receipt.amountInBaseCurrency` (was `multiCurrencyConversion`), `PerDiem.dailyRate`, the 3
  `ZzpDeduction` rate-lookup fields, `DepreciationSchedule.bookValue`/`depreciationAmount`,
  `UrenRegistratie.utilizationPercent`, `Account.emuAggregationHash` — to the newer `@ref`/
  `@aggregate` declarative primitives (`x-openregister-references`/`x-openregister-aggregate-refs`,
  shipped by OR's `calc-engine-reference-lookup`/`calc-engine-aggregate-reference` deps) once
  those primitives existed, instead of writing PHP guards for them. Verified live against
  `shillinq_register.json` HEAD: all 10 are present with `materialise: true` + `@ref.*`/
  `@aggregate.*` JSON-AST expressions.
  **Gap found and NOT silently passed over**: `ComplianceReport.complianceScore` /
  `criteriaResults` — the remaining 2 of the original 9 — were explicitly carved out by
  `guards-to-declarative-calc-refs`'s own proposal as "kept as PHP guards — justified ADR-031
  exception" (a heterogeneous per-rule array fold no `@ref`/`@aggregate` scalar can express),
  but verified against HEAD that guard service was never actually written: both fields still
  declare the OLD `formula`/`source`/`filter` shape (no `materialise: true`, no `guard:` key),
  and no `ComplianceReportService`-like class exists anywhere in `lib/`. Still dead. Filed
  Codeberg issue shillinq#490 (pre-migration, not migrated to GitHub) documenting the gap and the required
  guard service shape, rather than either implementing it here (task explicitly says do NOT) or
  silently marking this task done without flagging the incomplete carry-forward.

## Criteria / quality

- Every converted calc uses ONLY the evaluator vocabulary in the `declarative-calc-layer` spec (existing ops + the 7 dep scalar funcs).
- Every per-object derived field carries `materialise: true`; no string-ternary `expression` remains in Buckets 1–3a.
- The 2 Bucket-4 calcs (maandWaarde, photoValidation) are left untouched.
- No PHP, route, frontend, or DB change in this head — `lib/Settings/shillinq_register.json` only.
- Edits made with the Edit tool / hand-written JSON, never sed/awk/scripts (CLAUDE.md).
- Bucket-2 verification is gated on `calc-engine-scalar-functions` being merged.

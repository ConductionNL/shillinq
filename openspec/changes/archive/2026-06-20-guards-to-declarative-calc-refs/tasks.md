# Tasks: guards-to-declarative-calc-refs

## 1. Reference exemplar
- [x] 1.1 Confirm `MileageEntry.ratePerKm` / `totalAmount` already use `@ref.rate.*` + `materialise: true` (keep as-is).

## 2. Effective-dated rate lookups (@ref mode lookup)
- [x] 2.1 `Receipt`: add `x-openregister-references.fxRate` (FxRate, effectiveDate on rateDate); rewrite `amountInBaseCurrency` to JSON-AST reading `@ref.fxRate.rate`; set `materialise: true`.
- [x] 2.2 `PerDiem`: add `x-openregister-references.rate` (PerDiemRate, effectiveDate on calendarYear); rewrite `dailyRate` to `{ "prop": "@ref.rate.dailyRate" }`; set `materialise: true`.
- [x] 2.3 Introduce `ZzpDeductionAmounts` master schema (taxYear + zelfstandigenaftrek + startersaftrek + mkbWinstvrijstellingPercentage) and seed NL rates.
- [x] 2.4 `ZzpDeduction`: add `x-openregister-references.deduction` (ZzpDeductionAmounts by taxYear); rewrite the 3 lookup calcs to JSON-AST reading `@ref.deduction.*`; set `materialise: true`.

## 3. FixedAsset relatedObject reads (@ref mode relatedObject)
- [x] 3.1 `DepreciationSchedule`: add `x-openregister-references.asset` (FixedAsset, mode relatedObject, field assetRef).
- [x] 3.2 Rewrite `bookValue` to `@ref.asset.acquisitionCost - accumulatedDepreciation` JSON-AST; set `materialise: true`.
- [x] 3.3 Rewrite `depreciationAmount` to JSON-AST (linear / degressive branches on real fields); set `materialise: true`; note units-of-production limitation.

## 4. Aggregation calcs (@aggregate)
- [x] 4.1 `UrenRegistratie`: add `x-openregister-aggregate-refs.billable` + `.available` (sum of hours, parameterised by `@self.personId`); rewrite `utilizationPercent` to JSON-AST `round((billable/max(1,available))*100,1)`; set `materialise: true`.
- [x] 4.2 `Account`: add `x-openregister-aggregate-refs.emuContributors` over the contributing GLLine set; rewrite `emuAggregationHash` to `{ "sha256": [ { "concat": [...] } ] }`; set `materialise: true`.

## 5. Out-of-scope exceptions
- [x] 5.1 Leave `ComplianceReport.complianceScore` / `criteriaResults` as PHP guards; record in design table.

## 6. Validate & verify
- [x] 6.1 `php -r 'json_decode(...); echo json_last_error_msg();'` — register parses.
- [x] 6.2 `openspec validate guards-to-declarative-calc-refs --strict`.
- [x] 6.3 Live: re-import register; POST a sample object for one `@ref` lookup + one `@aggregate`; confirm computed values match the old guard formula; report exact values.

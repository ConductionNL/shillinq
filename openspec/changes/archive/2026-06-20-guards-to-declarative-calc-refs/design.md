# Design: guards-to-declarative-calc-refs

## Context

`revive-declarative-calc-layer` rewrote per-object calcs to JSON-AST but deferred every calc
that needed to reach another object, because OpenRegister's `CalculationEvaluator` only saw
the saving object. Those calcs were left as imperative-string `formula:` values (silently
never evaluated). OpenRegister now ships two extensions that close the gap:

- **Extension 1 `calc-engine-reference-lookup`** — `x-openregister-references` resolved by
  `lib/Service/Calculation/ReferenceResolver.php` into a `@ref.<name>` payload pre-calc.
  Modes: `lookup` (filtered master row, optional `effectiveDate`) and `relatedObject`
  (load-by-FK via `field`).
- **Extension 2 `calc-engine-aggregate-reference`** — `x-openregister-aggregate-refs`
  resolved by `lib/Service/Calculation/AggregateReferenceResolver.php` into `@aggregate.<name>`,
  plus a `sha256` scalar op in `CalculationEvaluator`.

The evaluator op vocabulary used here (confirmed in `CalculationEvaluator::evaluate` match
arms): `prop, lit, concat, if, not, and, or, +, -, *, /, eq, ne, lt, lte, gt, gte, now, max,
min, coalesce, abs, round, year, sha256`. `prop` resolves dotted paths, so `@ref.rate.dailyRate`
and `@aggregate.<name>` are read with `{ "prop": "…" }`.

## Decisions

1. **Edit `lib/Settings/shillinq_register.json` only.** No PHP. Annotation blocks go on the
   **owning** schema; the consuming calc reads `@ref`/`@aggregate` and sets `materialise: true`
   (the listener skips non-materialised calcs).
2. **Express ALL logic in JSON-AST.** String ternaries / infix are ignored by the evaluator.
3. **Real field names win over the legacy formula text.** The old `relatedObject('FixedAsset',
   …)` formula referenced `purchaseCost` / `declineRate` / `productionUnits`, none of which
   exist on the `FixedAsset` schema. The conversion maps to the real fields `acquisitionCost`,
   `residualValue`, `degressiveRate`. The `units-of-production` branch is dropped from the
   declarative form because `FixedAsset` has no `productionUnits` field — recorded below.
4. **`ExchangeRate` master table does not exist; `FxRate` does** (multi-currency capability,
   slug `FxRate`). The Receipt conversion targets `FxRate`.
5. **`ZzpDeductionAmounts` master table does not exist yet.** This change introduces it as a
   seeded master schema so the three ZzpDeduction `@ref` lookups resolve.

## Snapshot / staleness semantics

Every converted value is a **save-time materialised snapshot**: it is computed by
`CalculationOnSaveListener` when the owning object is saved and persisted onto the object. It
is therefore **stale until the owning object is re-saved** — if a master rate
(`FxRate` / `PerDiemRate` / `MileageRate` / `ZzpDeductionAmounts`) changes after an object was
saved, the object keeps its old snapshot until re-saved. The
`occ openregister:rematerialise-calculations` command refreshes the snapshot across the whole
population and is the supported way to re-run all calcs after a master-data change. This is the
same snapshot model the predecessor change adopted.

## Declarative-vs-imperative table

| # | Calc (owning schema.field) | Old imperative form | New declarative form | Annotation | materialise |
|---|---|---|---|---|---|
| 1 | `MileageEntry.ratePerKm` | `lookup('MileageRate', {fiscalYear, vehicleType, country})` | `@ref.rate.ratePerKm` | `references.rate` (lookup, effectiveDate on fiscalYear) | yes (exemplar, already in tree) |
| 2 | `MileageEntry.totalAmount` | `distance * ratePerKm` | `{*:[prop distance, prop @ref.rate.ratePerKm]}` | (consumes #1) | yes (already in tree) |
| 3 | `Receipt.amountInBaseCurrency` | `currency=='EUR' ? amount : amount * lookup('ExchangeRate', …)` | `if(eq(currency,'EUR'), amount, amount * @ref.fxRate.rate)` | `references.fxRate` (lookup FxRate, effectiveDate on rateDate) | yes |
| 4 | `PerDiem.dailyRate` | `lookup('PerDiemRate', {calendarYear: year(travelStartDate), country})` | `@ref.rate.dailyRate` | `references.rate` (lookup PerDiemRate, effectiveDate on calendarYear) | yes |
| 5 | `PerDiem.allowanceAmount` | `nightCount * dailyRate` (already JSON-AST) | unchanged — now reads the materialised `dailyRate` | (consumes #4) | yes (already) |
| 6 | `ZzpDeduction.zelfstandigenaftrekAmount` | `qualifiesForUrencriterium ? lookup('ZzpDeductionAmounts', taxYear, field:'zelfstandigenaftrek') : 0` | `if(qualifiesForUrencriterium, @ref.deduction.zelfstandigenaftrek, 0)` | `references.deduction` (lookup ZzpDeductionAmounts by taxYear) | yes |
| 7 | `ZzpDeduction.startersaftrekAmount` | `(isStarter && claims<3 && qualifies) ? lookup(…,'startersaftrek') : 0` | `if(and(isStarter, lt(claims,3), qualifies), @ref.deduction.startersaftrek, 0)` | (consumes #6 ref) | yes |
| 8 | `ZzpDeduction.mkbWinstvrijstellingPercentage` | `lookup(…,'mkbWinstvrijstellingPercentage')` | `@ref.deduction.mkbWinstvrijstellingPercentage` | (consumes #6 ref) | yes |
| 9 | `DepreciationSchedule.bookValue` | `relatedObject('FixedAsset', assetRef).purchaseCost - accumulatedDepreciation` | `@ref.asset.acquisitionCost - accumulatedDepreciation` | `references.asset` (relatedObject, field assetRef) | yes |
| 10 | `DepreciationSchedule.depreciationAmount` | `relatedObject('FixedAsset', assetRef).{purchaseCost,residualValue,declineRate,productionUnits}` (3 methods) | `if(method=='degressive', acquisitionCost*degressiveRate, (acquisitionCost-residualValue)*annualRate)` | (consumes #9 ref) | yes |
| 11 | `UrenRegistratie.utilizationPercent` | `min(100,max(0,(billable / max(1,available))*100))` string | `round((@aggregate.billable / max(1,@aggregate.available))*100, 1)` | `aggregate-refs.billable`,`.available` | yes |
| 12 | `Account.emuAggregationHash` | `sha256(@aggregation.contributingIds + ':' + esaClassifier + ':' + emuInclusionRule)` string | `{sha256:[{concat:[@aggregate.emuContributors, ':', esaClassifier]}]}` | `aggregate-refs.emuContributors` | yes |

### Justified ADR-031 imperative exceptions (NOT converted)

| Calc | Why it stays a PHP guard |
|---|---|
| `ComplianceReport.complianceScore` | Folds an open-ended BankingRule catalogue (per-administration), each rule with type-specific branching (IBAN regex, approval-status, segregation correlated-subquery). Not a single `@ref`/`@aggregate` scalar. |
| `ComplianceReport.criteriaResults` | Emits a heterogeneous structured array (one object per evaluated rule, differing fields per ruleType). Structure-to-structure transform, not a declarative scalar fold. |

## Notes on faithfulness

- **#10 `units-of-production`**: the legacy formula's `productionUnits` field does not exist on
  `FixedAsset`. The declarative form covers `linear` and `degressive`; units-of-production
  cannot be reproduced without a schema field and is noted as a known limitation (a follow-up
  may add `productionUnits` to FixedAsset).
- **#12** `@params.emuInclusionRule` was a per-computation parameter not available in the calc
  payload; the hash input is reduced to the contributing-IDs fold + `esaClassifier`, which keeps
  the reproducibility property (same contributing set + sector ⇒ same hash) without the
  unavailable parameter.

## Now-dead PHP guard candidates (follow-up kind:code deletion — NOT in this change)

Identified by exploration; deletion deferred to a separate `kind: code` change:
`lib/Service/RateCardResolver.php`, `lib/Service/OssRateResolver.php`,
`lib/Service/RetainerResolver.php` (rate snapshot resolvers superseded by `@ref` lookup where
the consuming field is a per-object materialised snapshot), and any `EmuCalculator` guard hash
helper referenced by `Account` aggregations. These must be confirmed call-graph-dead before
removal — keep until then.

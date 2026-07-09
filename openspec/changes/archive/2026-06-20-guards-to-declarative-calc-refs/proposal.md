---
kind: config
depends_on:
  - calc-engine-reference-lookup        # openregister — Extension 1: x-openregister-references (@ref, mode lookup|relatedObject, effectiveDate)
  - calc-engine-aggregate-reference     # openregister — Extension 2: x-openregister-aggregate-refs (@aggregate) + sha256 scalar op
chain:
  - revive-declarative-calc-layer       # predecessor (kind:config) — JSON-AST rewrites + materialise for per-object calcs
  - guards-to-declarative-calc-refs     # THIS change (kind:config) — cross-object guard calcs → @ref / @aggregate now the engine can reach other objects
---

# Change: guards-to-declarative-calc-refs

## Why

The predecessor change `revive-declarative-calc-layer` rewrote shillinq's per-object
calculations to the executable JSON-AST dialect, but it explicitly **deferred** the calcs
whose intent required reaching *another* object — `lookup('ExchangeRate', …)`,
`relatedObject('FixedAsset', …)`, billable-vs-available `@aggregate` ratios, and
`sha256(…)` audit hashes. At that time OpenRegister's `CalculationEvaluator` could only
see the saving object, so those calcs were left as imperative-string `formula:` values
(silently never evaluated) pending a future engine capability, or as thin PHP guard
services.

That capability has now shipped on `openregister/development` as two extensions:

1. **`calc-engine-reference-lookup` (Extension 1)** — `x-openregister-references`
   resolved by `ReferenceResolver` into a `@ref.<name>` payload before the calc runs.
   Supports `mode: lookup` (filtered master-table row with optional `effectiveDate`)
   and `mode: relatedObject` (load by a local uuid FK `field`).
2. **`calc-engine-aggregate-reference` (Extension 2)** — `x-openregister-aggregate-refs`
   resolved by `AggregateReferenceResolver` into `@aggregate.<name>` (count/sum/avg/min/max,
   optional `groupBy`), plus a new `sha256` scalar op in the evaluator.

Per ADR-031 (declarative-first), any behaviour OR can now express SHALL move out of PHP
into the schema. This change converts the previously-unreachable guard calcs to declarative
`@ref` / `@aggregate` + JSON-AST, leaving only genuinely structured multi-schema folds
(`ComplianceReport.complianceScore` / `criteriaResults`) as justified ADR-031 guard
exceptions.

This is a pure **register JSON config change** — no new or changed PHP.

## What Changes

All edits land in `lib/Settings/shillinq_register.json` only. Each converted calc gains
`materialise: true` (the listener skips non-materialised calcs), an annotation block on the
**owning** schema, and a JSON-AST `expression` reading `@ref.*` / `@aggregate.*`.

- **MileageEntry.ratePerKm** (reference exemplar, already converted in the working tree) —
  `@ref` `mode: lookup` against `MileageRate` with `effectiveDate`. Kept as-is; re-verified.
- **Receipt.amountInBaseCurrency** — was `lookup('ExchangeRate', …)`; converted to `@ref`
  `mode: lookup` against the multi-currency `FxRate` master table with `effectiveDate`
  (`ExchangeRate` does not exist as a schema; `FxRate` is the real rate table) + JSON-AST.
- **PerDiem.dailyRate** — was `lookup('PerDiemRate', …)`; converted to `@ref` `mode: lookup`
  against `PerDiemRate` with `effectiveDate` on `calendarYear` + JSON-AST `@ref.rate.dailyRate`.
- **ZzpDeduction** (3 calcs: `zelfstandigenaftrekAmount`, `startersaftrekAmount`,
  `mkbWinstvrijstellingPercentage`) — was `lookup('ZzpDeductionAmounts', …)`; the
  `ZzpDeductionAmounts` master table does not yet exist, so this change introduces it as a
  seeded master schema and three `@ref` `mode: lookup` references keyed on `taxYear` + JSON-AST.
- **DepreciationSchedule** (2 `relatedObject` reads: `bookValue`, `depreciationAmount`) —
  was `relatedObject('FixedAsset', assetRef).…`; converted to `@ref` `mode: relatedObject`
  (`field: assetRef`) reading `acquisitionCost` / `residualValue` / `degressiveRate` off the
  related `FixedAsset` + JSON-AST. (The former formula referenced `purchaseCost`/`declineRate`/
  `productionUnits`, which do not exist on FixedAsset — the conversion maps to the real fields.)
- **UrenRegistratie.utilizationPercent** — was a string `min/max` formula over
  `@aggregate.*`; converted to two `x-openregister-aggregate-refs` (billable vs available
  hours) + JSON-AST `round((billable / max(1, available)) * 100, 1)`.
- **Account.emuAggregationHash** — was `sha256(@aggregation.… )` string; converted to an
  `x-openregister-aggregate-refs` over the contributing GLLine set + JSON-AST
  `{ "sha256": [ concat(...) ] }`.

**Out of scope (kept as PHP guards — justified ADR-031 exception):**
`ComplianceReport.complianceScore` and `ComplianceReport.criteriaResults` — structured
multi-schema folds over an open-ended BankingRule catalogue with per-rule conditional logic
producing a heterogeneous array, not a single `@ref`/`@aggregate` scalar.

## Impact

- Affected file: `lib/Settings/shillinq_register.json` (declarative only).
- Affected schemas (owning): `MileageEntry`, `Receipt`, `PerDiem`, `ZzpDeduction`,
  `DepreciationSchedule`, `UrenRegistratie`, `Account` (+ new master schema `ZzpDeductionAmounts`).
- Snapshot semantics: converted values are **save-time materialised snapshots** — stale
  until the owning object is re-saved. `occ openregister:rematerialise-calculations` refreshes
  the whole population after a master-rate change.
- No PHP, controller, route, or frontend change. No notification-dialect change.

# declarative-calc-refs Specification

## Purpose
TBD - created by archiving change guards-to-declarative-calc-refs. Update Purpose after archive.
## Requirements
### Requirement: Cross-object lookup calcs SHALL use x-openregister-references

Every shillinq calc deriving a value from another schema's row SHALL declare that read as an `x-openregister-references` annotation on the owning schema and SHALL consume it via a JSON-AST `expression` reading `@ref.<name>.<field>`. The reference
SHALL use `mode: lookup` for a filtered master-table row (with `effectiveDate` when the rate
is effective-dated) or `mode: relatedObject` for a load-by-FK read (`field:` = the local uuid
FK on the saving object). The consuming calc SHALL set `materialise: true` so
`CalculationOnSaveListener` resolves the `@ref` payload and persists the result. The former
imperative-string `lookup(...)` / `relatedObject(...)` `formula` value SHALL NOT remain,
because the evaluator does not parse it and the field silently never computes.

#### Scenario: An effective-dated rate lookup is converted to @ref mode lookup

- **GIVEN** a calc `PerDiem.dailyRate` declared as the string
  `"lookup('PerDiemRate', {calendarYear: year(@self.travelStartDate), country: @self.country})"`
- **WHEN** the register is converted per this change
- **THEN** the owning `PerDiem` schema SHALL declare an `x-openregister-references` entry
  (`schema: PerDiemRate`, `mode: lookup`, filters keyed on `calendarYear` derived from
  `travelStartDate` and `country`, with an `effectiveDate` on `calendarYear`)
- **AND** the `dailyRate` calc SHALL set `materialise: true` and its `expression` SHALL be
  the JSON-AST `{ "prop": "@ref.rate.dailyRate" }`

#### Scenario: A relatedObject FK read is converted to @ref mode relatedObject

- **GIVEN** a calc `DepreciationSchedule.bookValue` declared as the string
  `"relatedObject('FixedAsset', assetRef).purchaseCost - accumulatedDepreciation"`
- **WHEN** the register is converted per this change
- **THEN** the owning `DepreciationSchedule` schema SHALL declare an
  `x-openregister-references` entry (`schema: FixedAsset`, `mode: relatedObject`,
  `field: assetRef`)
- **AND** the `bookValue` calc SHALL set `materialise: true` and its `expression` SHALL be
  the JSON-AST `{ "-": [ { "prop": "@ref.asset.acquisitionCost" }, { "prop": "accumulatedDepreciation" } ] }`
  reading the real FixedAsset field `acquisitionCost`

### Requirement: Aggregation calcs SHALL use x-openregister-aggregate-refs

Every shillinq calc folding a set of other objects SHALL declare that fold as an `x-openregister-aggregate-refs` annotation on the owning schema and SHALL consume it via a JSON-AST `expression` reading `@aggregate.<name>` (or `.<groupKey>` when `groupBy` is set). The consuming calc SHALL set `materialise: true`. A
calc that produces a stable audit hash over an aggregated set SHALL use the `sha256` scalar
op over a `concat` of the contributing values.

#### Scenario: A billable-vs-available ratio is converted to @aggregate

- **GIVEN** a calc `UrenRegistratie.utilizationPercent` declared as the string
  `"min(100, max(0, ((@aggregate.billableHoursThisPeriod ?? 0) / max(1, (@aggregate.availableHoursThisPeriod ?? 1))) * 100))"`
- **WHEN** the register is converted per this change
- **THEN** the owning `UrenRegistratie` schema SHALL declare two `x-openregister-aggregate-refs`
  (billable hours sum and available hours sum, parameterised by `@self.personId`)
- **AND** the `utilizationPercent` calc SHALL set `materialise: true` and its `expression`
  SHALL be the JSON-AST `round( (billable / max(1, available)) * 100, 1 )` reading both
  `@aggregate.*` values

#### Scenario: An audit hash is converted to @aggregate + sha256

- **GIVEN** a calc `Account.emuAggregationHash` declared as the string
  `"sha256(@aggregation.contributingIds + ':' + @self.esaClassifier + ':' + @params.emuInclusionRule)"`
- **WHEN** the register is converted per this change
- **THEN** the owning `Account` schema SHALL declare an `x-openregister-aggregate-refs` that
  folds the contributing GLLine set
- **AND** the `emuAggregationHash` calc SHALL set `materialise: true` and its `expression`
  SHALL be the JSON-AST `{ "sha256": [ { "concat": [ … ] } ] }`

### Requirement: Structured multi-schema folds SHALL remain ADR-031 guard exceptions

A calc that evaluates an open-ended rule catalogue and emits a heterogeneous structured array SHALL remain an imperative PHP guard service and SHALL be recorded as a justified ADR-031 exception in the design document. It SHALL NOT be forced into a single `@ref` or `@aggregate` declarative shape.

#### Scenario: ComplianceReport scoring stays imperative

- **GIVEN** `ComplianceReport.complianceScore` and `ComplianceReport.criteriaResults`, which
  iterate every active BankingRule for the administration with type-specific branching and
  emit a structured per-criterion array
- **WHEN** the conversion is applied
- **THEN** these two calcs SHALL NOT be converted to `@ref` / `@aggregate`
- **AND** the design document SHALL list them in the declarative-vs-imperative table as
  justified ADR-031 exceptions


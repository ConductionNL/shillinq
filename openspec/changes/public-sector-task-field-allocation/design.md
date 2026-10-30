# Design: public-sector-task-field-allocation

Read at shillinq development @655363ab0 on 2026-09-29.

## Decisions

### D1. Realisation per task field

A new `lib/Service/PublicSector/TaskFieldRealisation.php::forYear(string $administrationId, string $fiscalYear): array` sums posted `GLLine` amounts per account, maps them through `BbvAccountMapping` to task fields and returns expenses, income and the unmapped accounts. `BBVDashboardController::index` adds it to its payload; the dashboard gets a table and an unmapped-accounts list with a link to `/overheid/bbv-mapping`. Read `BBVDashboardController` first: where it already returns a per-task-field figure, reuse it instead of adding a second sum.

### D2. Joint arrangement allocation

A new schema `GRAllocationRun` (period, key, state draft or confirmed, lines: participant, share, amount) in a new fragment `lib/Settings/register.d/public-sector-task-field-allocation.json`. `lib/Service/PublicSector/JointArrangementAllocation.php::propose(string $keyId, string $periodFrom, string $periodTo)` sums the posted lines on `GRVerdeelsleutel.costClusterAccountNumbers` and splits the total over active `GRDeelnemer` rows by `share`, with the rounding remainder on the largest share so the lines add up to the cent. A `confirm` transition locks the run; an `invoice` action writes one draft `ARInvoice` per participant with the run as source. Page: `GRAllocationRuns` index plus detail, menu next to `/gr/verdeelsleutels`.

### D3. Integral cost price and cross subsidy

A `calculate` action on `CommercialActivity` (declared by FQCN, like the other lifecycle actions) reads the period's GL lines for the activity's cost centre and cost object, calls `IntegralCostPriceCalculator::sumDirectCosts`, `distributeOverhead`, `calculateVermogenskosten` and `calculateProfitMarkup`, and writes one `IntegralCostPrice`. `lib/BackgroundJob/CrossSubsidyScanJob.php` (daily) runs the `CrossSubsidyDetector` checks per active activity and writes `AlertLog` rows through `composeAlert`, skipping a signal already open for the same activity. `IntegralCostPriceLockService::lock` runs on the existing year-end path when `shouldLock` says so.

### D4. Out of scope

Iv3 and the programme budget stay as they are (rows pub-iv3 and pub-programme-budget are not part of this change).

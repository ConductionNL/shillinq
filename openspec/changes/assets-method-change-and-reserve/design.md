# Design: assets-method-change-and-reserve

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Two `FixedAsset` declarations merge.** `lib/Settings/shillinq_register.json` declares `FixedAsset` with `acquisitionCost`, `depreciationMethod`, `usefulLifeMonths`, `residualValue`, `monthlyDepreciation`, `currentBookValue`, `commercialBookValue`, `fiscalBookValue`, `commercialRate`, `fiscalRate`, the three account numbers and transitions `activate`, `dispose`, `archive`, `rejectProposal`. `register.d/bookkeeping-fixed-assets-depreciation.json` adds `purchaseCost`, `usefulLifeYears`, `declineRate`, `productionUnits` and transitions `transferInternal`, `splitTransfer`. `SettingsService::deepMergeConfig` merges them.
- **Schedules exist, postings do not.** `DepreciationSchedule` (both files) has `assetRef`, `periodStartDate`, `periodEndDate`, `depreciationAmount`, `accumulatedDepreciation`, `bookValue`, `status` and, in the fragment, `glTransactionRef`. No code posts a schedule line (matrix row `pln-fixed-assets`: "automatic depreciation posting does not exist"; re-read 2026-09-27).
- **Disposal.** `FixedAssetDisposalService::postDisposalJournal()` (`lib/Service/FixedAssetDisposalService.php:160`) writes a closing `GLTransaction` with gain or loss on the accounts in app config `fixed_asset_disposal_gain_account` and `fixed_asset_disposal_loss_account` (lines 76, 83).
- **Posting.** Journal entries post through `materialise-gl-transaction`, registered by `ledger-posting-path`.

## Goals / Non-Goals

**Goals**
- Depreciation reaches the ledger every month.
- A change of method or life and an extra depreciation reach it too, prospectively.
- A disposal gain can go to a reinvestment reserve and be applied to a replacement.

**Non-Goals**
- Upward revaluation; a full fiscal-commercial dual ledger.

## Decisions

### D1. A monthly run posts schedule lines

`lib/BackgroundJob/DepreciationRunJob.php` (ADR-069) runs daily and, for
each administration, posts every `DepreciationSchedule` line whose period
has ended, whose asset is active and whose `glTransactionRef` is empty, as
one `JournalEntry` per administration and period (debit the expense
account, credit accumulated depreciation, one line pair per asset), posted
with `postDirect`. It sets `glTransactionRef` and `status: posted` on each
line. Periods before the release are not caught up by the job; the asset
page's "Post missed depreciation" action lists them and posts on
confirmation.

### D2. A change of method or life is a transition with a recalculation

`FixedAsset` gains transition `revise` (active to active) with
`revisedMethod`, `revisedUsefulLifeMonths`, `revisionDate`,
`revisionReason`. A lifecycle action handler
`RecalculateDepreciationScheduleAction` deletes the unposted schedule lines
after `revisionDate` and writes new ones from the book value on that date
over the remaining life under the new method. Posted lines are never
touched: this is a change in estimate, applied prospectively (IAS 8.36, RJ
145).

### D3. Extra depreciation is one posted line

Transition `depreciateExtra` with `amount`, `date`, `reason` writes one
`DepreciationSchedule` line of `rateType: extra` and posts it at once, then
recalculates the remaining lines as in D2 from the lower book value.

### D4. The reinvestment reserve is its own record

New schema `ReinvestmentReserve`: `administrationId`, `disposedAssetRef`,
`formedOn`, `amount`, `expiresOn`, `appliedAmount`, `remainder`
(calculation), lifecycle `open`, `applied`, `released`. The disposal
dialog offers "Add the gain to a reinvestment reserve"; when chosen,
`FixedAssetDisposalService` credits the reserve's equity account (app
config `fixed_asset_reinvestment_reserve_account`) instead of the gain
account and creates the record. On a new asset's `activate`, an "Apply
reinvestment reserve" option lowers its `fiscalBookValue` cost basis by
the amount applied and debits the reserve account. The daily run releases
a reserve past `expiresOn` with a remainder to fiscal profit.

Alternative considered: a flag on the disposal only. Rejected: the reserve
outlives the disposal by up to three years and is applied elsewhere.

## As built (2026-10-01)

- **No code wrote schedule lines.** Only the demo seed did. `DepreciationScheduleService::ensureSchedule` writes an active asset's whole monthly plan from its acquisition month when it has none; the daily run calls it for every active asset.
- **Three shipped calculations assumed the first plan.** `DepreciationSchedule.depreciationAmount` (cost less residual times `annualRate`) overwrote every written line amount on save, and `FixedAsset.monthlyDepreciation` and `currentBookValue` derive from the original life. The fragment switches the three off (`enabled: false`); the schedule service writes the line amount, and `FixedAssetDepreciation` writes the asset's monthly amount and book value from the schedule (on a revision, an extra depreciation and in the daily run).
- **Posted lines** carry `status: posted` (added to the enum by repeating the full list) and the journal entry's id in `glTransactionRef`. The run posts the month that ended last; `GET/POST /api/fixed-assets/{id}/missed-depreciation` lists and posts earlier months, opened from the asset page's Post missed depreciation action.
- **Applying a reserve** is its own transition on an active asset (`applyReinvestmentReserve`) rather than an option inside `activate`, because the merged `activate` transition belongs to the acquisition flow. It debits the reserve account and credits the asset account, and writes `fiscalCostBasis` (a new field; `fiscalBookValue` is a materialised calculation and would be overwritten).
- **Units of production** is planned evenly over the remaining months until per-period units are recorded, so its first plan equals straight line.
- The `dispose` transition now declares `inputs` (date, proceeds, Add the gain to a reinvestment reserve), so the dialog asks for them.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Revise and extra depreciation | Declarative transitions on `FixedAsset` | Lifecycle. |
| Recalculating schedule lines | Imperative, a lifecycle action handler | Writes many related objects from a formula. |
| Monthly posting and reserve release | Imperative, a scheduled job (ADR-031 exception: scheduled bulk work) | Periodic bulk work. |
| Reserve remainder | Declarative: `x-openregister-calculations` | Derived field. |

## Seed Data

Bakkerij Jansen: oven "Rademaker deegverwerker", acquired 2024-01-01 for
EUR 60,000, straight line over 120 months, residual EUR 0. On 2026-07-01
the life is revised to 90 months in total (60 months left) with reason "slijtage door
nachtproductie"; the book value then is EUR 45,000 and the new monthly
amount EUR 750. The old delivery van, book value EUR 8,000, is sold for
EUR 14,000 on 2026-03-31: the EUR 6,000 gain goes to a reinvestment
reserve expiring 2029-12-31 and is applied to the new van bought on
2026-09-15 for EUR 42,000.

## Risks / Trade-offs

- [Two `FixedAsset` field sets (`usefulLifeMonths` and `usefulLifeYears`)] → the run reads months and falls back to years times twelve; a follow-up can retire the duplicate.
- [A reserve applied to an asset of another kind] → the application lists the reserve's disposed asset category and asks for confirmation; the law's "same economic function" test stays with the accountant.

## Migration Plan

None. The job starts with the current period.

## Open Questions

None.

# Design: tax-investment-deduction-claims

Read at shillinq development `83d19fc8d` on 2026-09-28.

## Context

- **Schemas.** `lib/Settings/register.d/bookkeeping-investeringsaftrek.json` declares `InvestmentAsset` (required `administrationId`, `fixedAssetId`, `description`, `acquisitionValue`; flags `kiaEligible`, `eiaEligible`, `miaEligible`, `vamilEligible`, `eligibilityOverride`, `energyListCode`, `environmentListCode`, `rvoReportDeadline`), `InvesteringsaftrekClaim` (required `administrationId`, `investmentAssetId`, `financialYear`, `scheme`), `KIATier`, `EnergielijstCode`, `MilieulijstCode` and `VamilDepreciation`.
- **Seeds.** `lib/Settings/seeds/investeringsaftrek-kia-tiers-2026.json`, `-energielijst-2026.json`, `-milieulijst-2026.json`.
- **Guards.** `lib/Guard/InvesteringsaftrekEligibilityGuard.php::classify()` returns the four flags with reasons (thresholds `MIN_KIA` EUR 450, `MIN_EIA_MIA_VAMIL` EUR 2,500, `MAX_KIA`); `validateCumulation()` and `isApproachingKiaPlafond()`. `lib/Guard/KiaSchalenLookup.php::computeAftrek(tiers, jaartotaal)` applies the tier bands. Both have unit tests; neither has a caller.
- **Fixed assets.** `FixedAsset` in `lib/Settings/shillinq_register.json:3546`, pages `FixedAssets` (`/fixed-assets`) and `FixedAssetDetail` (`/fixed-assets/:id`) in `src/manifest.json:4908`.

## Goals / Non-Goals

**Goals**
- Every activated fixed asset has its eligibility and reasons visible.
- A year's deduction per scheme is one click.

**Non-Goals**
- RVO filing, disinvestment additions, other years' tables.

## Decisions

### D1. The listener creates the investment asset

`lib/Listener/InvestmentAssetListener.php` on `ObjectCreatedEvent` and
`ObjectUpdatedEvent` for `FixedAsset` when its lifecycle state becomes
active (the event classes' real accessors, `getObject()` and
`getNewObject()`, per class). It creates or updates the `InvestmentAsset`
for that `fixedAssetId`, looks up the list codes by `yearNumber` of the
acquisition date, and stores the guard's flags. An `eligibilityOverride`
set by a person is never overwritten. For EIA and MIA it sets
`rvoReportDeadline` to three months after the `assignmentAwardDate`.

### D2. One service sums a year

`lib/Service/InvestmentDeductionService.php::calculateYear(administrationId, year)`:
KIA base = the sum of `acquisitionValue` of KIA-eligible assets acquired in
the year; `computeAftrek()` with that year's `KIATier` rows gives the
deduction, spread over the assets by value. EIA and MIA claims use the
list code's `deelpercentage` or `miaPercentage`. It writes or replaces the
draft `InvesteringsaftrekClaim` rows for that year and never touches a
claim whose `submittedInTaxReturn` is true. Exposed as
`POST /api/investment-deductions/{year}/calculate` (bookkeeper role,
administration check).

### D3. Two surfaces

A page `InvestmentDeductions` (`/tax/investment-deductions`), an index on
`InvesteringsaftrekClaim` filtered by year with totals per scheme and a
"Recalculate" action, under the Tax menu. A tab "Investeringsaftrek" on
`FixedAssetDetail` showing the linked `InvestmentAsset` with the reasons
and the override field.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Eligibility on activation | Imperative listener | Needs the guard and year-scoped lookups. |
| Yearly claim | Imperative service behind an action | A sum over a year with tiered bands. |
| Pages | Declarative manifest | Index and detail tab. |

## Seed Data

Bakkerij De Korenaar 2026: an oven EUR 18,000 (KIA), a delivery van
EUR 32,000 (KIA), a heat pump EUR 14,000 on Energielijst code 210101 (KIA
and EIA). KIA base EUR 64,000; the 2026 tiers give the KIA deduction for
that total.

## Risks / Trade-offs

- [An asset activated before this change] → the recalculate action also creates missing `InvestmentAsset` rows for the year's active assets.

## Migration Plan

None; existing assets are picked up by the first recalculation.

## Open Questions

None.

---
kind: code
depends_on: []
---

# Proposal: tax-investment-deduction-claims

## Summary

A bookkeeper who buys a machine, a laptop fleet or solar panels wants to see
straight away whether the purchase counts for the small-scale investment
deduction (KIA) or for the energy or environment deductions (EIA, MIA,
Vamil), and what the deduction for the year comes to. Shillinq shipped the
register schemas, the 2026 tables and the two calculators for this in June,
but nothing calls them and no page shows a deduction. This change wires
them: a fixed asset that is activated gets its eligibility worked out, the
bookkeeper can confirm or override it, and a yearly overview adds up the
deduction per scheme for the tax return.

## Motivation

One tax row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the build-all pass of 2026-09-28 under the pass rule for a row whose
archived change shipped without the capability.

**`tax-investment-deduction`**, "Work out investment deductions (KIA, EIA,
MIA) on new assets." Rated no, built state none. Matrix evidence:
"lib/Settings/register.d/bookkeeping-investeringsaftrek.json and seeds
lib/Settings/seeds/investeringsaftrek-*-2026.json exist; no manifest page
and no service reads them; the RVO adapter is log-only
(lib/Service/External/RvO/LogRvOAanvraagAdapter.php)". Matrix note
(corrections round 9, 2026-09-28): "The archived
2026-06-14-bookkeeping-investeringsaftrek (34 of 34 tasks ticked) and
2026-06-14-add-shillinq-investeringsaftrek shipped the register fragment,
the 2026 seeds, lib/Guard/KiaSchalenLookup.php and
lib/Guard/InvesteringsaftrekEligibilityGuard.php, but nothing calls either
guard and no page shows a deduction, so they shipped without the
capability."

No competitor is rated yes (odoo no, the others unknown). The row is built
because the June change promised it and the parts exist; this change is the
wiring, not new tax logic.

## Affected Projects

- [ ] Project: `shillinq`: a listener creating the `InvestmentAsset`, a claim calculator, two pages.

## Scope

### In Scope

- On activating a `FixedAsset`, an `InvestmentAsset` with the eligibility flags and their reasons from `InvesteringsaftrekEligibilityGuard`.
- The bookkeeper sets the energy or environment list code and can override a flag with a reason.
- A yearly calculation writing one `InvesteringsaftrekClaim` per scheme, KIA from the year's total through `KiaSchalenLookup`.
- An investment deductions page per year, and a tab on the fixed asset detail.

### Out of Scope

- Filing the EIA or MIA notification with RVO; the adapter stays log-only and the page shows the notification deadline.
- Disinvestment additions (desinvesteringsbijtelling).
- Tables for years other than those seeded.

## Approach

A post-save listener on `FixedAsset` calls the guard; one service sums a year
and writes claims. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Listener/`: one listener.
- `lib/Service/`: `InvestmentDeductionService`.
- `lib/Controller/`: one action endpoint to recalculate a year.
- `src/manifest.json` or `src/manifest.d/`: the overview page and the detail tab.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: The 2026 tables are applied to another year
**Severity:** Medium. **Mitigation:** the calculation looks up tiers and list codes by `yearNumber` and refuses a year without tables, saying which table is missing.

## Rollback Strategy

Remove the page and unregister the listener; the records stay as data.

## Open Questions

None.

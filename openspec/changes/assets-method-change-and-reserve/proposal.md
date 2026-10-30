---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: assets-method-change-and-reserve

## Summary

Two fixed-asset needs share one gap. A controller revises an asset's
depreciation method or useful life part way and books extra depreciation;
an entrepreneur who sells an asset at a gain parks that gain in a
reinvestment reserve and applies it to the replacement. Shillinq lets the
fields be edited but posts no depreciation at all, and books every disposal
gain straight to profit. This change posts depreciation monthly, recalculates
it after a change, books extra depreciation, and adds the reinvestment
reserve.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`pln-asset-method-change`**, "Change an asset's depreciation method or
life part way and book extra depreciation." Rated no, built state none.
Matrix note: "depreciationMethod and usefulLife are editable fields on
FixedAsset, but no periodic depreciation is posted at all (see
pln-fixed-assets), so a change of method or an extra depreciation books
nothing." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. odoo rates yes
(https://www.odoo.com/documentation/19.0/applications/finance/accounting/vendor_bills/assets.html,
"Modification of an asset", Modify Depreciation with Re-evaluate); moneybird
(https://helpcenter.moneybird.nl/nl/articles/207294-bezitting-lineair-afschrijven,
"Via Acties kun je zelf afschrijvingen toevoegen") and twinfield
(https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/twinfield-boekh-3041276,
"herwaarderen, afschrijven, verkopen en herberekenen") are partial.

**`led-reinvestment-reserve`**, "Book the gain on a sold asset to a
reinvestment reserve and apply it to a replacement asset." Rated no, built
state none. Matrix note: "No reinvestment reserve in lib/ or the register;
asset disposal books gain or loss (lib/Service/FixedAssetDisposalService.php)."
Changelog demand: https://www.moneybird.nl/changelog/herinvesteringsreserve/.
moneybird rates yes (same page, 3 juni 2026: "Je kan nu de winst van
gedesinvesteerde bezittingen naar de herinvesteringsreserve boeken via
acties. Vervolgens kan je deze herinvesteringsreserve ook gebruiken").
Ledger is a core area of the matrix.

## Affected Projects

- [ ] Project: `shillinq`: a monthly depreciation run, a change of method or life with recalculation, extra depreciation, and the reinvestment reserve.

## Scope

### In Scope

- A monthly depreciation run posting each active asset's `DepreciationSchedule` line for the period, prerequisite to the rest.
- Changing method or useful life on an active asset: future schedule lines recalculated prospectively from the current book value.
- Extra depreciation: a one-off amount booked on a chosen date with a reason.
- A `ReinvestmentReserve` formed from a disposal gain, applied to a replacement asset's fiscal cost, and released when its term ends unused.

### Out of Scope

- Revaluation upward (IFRS revaluation model).
- The fiscal and commercial split beyond the reserve: the reserve lowers the fiscal book value; commercial depreciation is unchanged.

## Approach

The run posts journal entries through the posting path of
`ledger-posting-path`. A method change is a transition that rewrites only
unposted schedule lines. The reserve is its own record with a term. Details
are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/shillinq_register.json` and `register.d/bookkeeping-fixed-assets-depreciation.json`: `FixedAsset` transitions, `ReinvestmentReserve`.
- `lib/BackgroundJob/`: the monthly run.
- `lib/Service/FixedAssetDisposalService.php`: the reserve option on disposal.
- `src/manifest.json` fixed asset detail page: actions.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: The first run posts months of catch-up depreciation
**Severity:** High. **Mitigation:** the run posts only the current period by default; catch-up for earlier periods is a separate action that shows the amounts per period before posting.

### Risk 2: A reserve kept past its term
**Severity:** Medium. **Mitigation:** the reserve carries `expiresOn` (end of the third year after the year it was formed, art. 3.54 Wet IB 2001) and the run releases an unused reserve at that date.

## Rollback Strategy

Stop the run. Posted depreciation stays and is reversible per entry.

## Open Questions

None.

---
kind: code
depends_on: []
---

# Change: wik-dunning-legal-calculations

## Why

A verify-first audit of the shipped credit-control-dunning capability against the
current (2026) Wet Incassokosten / Besluit BIK regime found the `BIKStaffelCalculator`
(the ADR-031 exception guard behind `IncassoKostenBerekening`, REQ-CCD-003) **already
correct** for the 5-slab staffel, the €40 minimum floor, the B2B/B2C rente branch, the
14-dagenbrief day-44 block, partial-payment saldo recalc and dispute-pauses-accrual — but
found **three genuine legal defects, two of which make the shipped code emit wrong figures**:

1. **No statutory maximum.** The Besluit BIK caps incassokosten at **€6.775** (reached at a
   €1.000.000 hoofdsom). `staffel()` applied only the €40 floor, so the open-ended 0,5% top
   slab accrued unbounded — a €2.000.000 claim returned €11.775 instead of the legal €6.775.
2. **Stale hard-coded rente rates.** The B2C default was `0.07` and the B2B default `0.115`;
   the **current per-1-1-2026** wettelijke rente is **4%** (AMvB 10-12-2025) and the
   handelsrente **10,15%** (Wieringa Advocaten, 5-1-2026). The rates change ~biannually, yet
   were frozen in a PHP constant with no maintained table, so every default-path calculation
   produced a legally wrong rente for 2026, and an accrual window that crossed a rate boundary
   accrued at a single flat rate.
3. **No BTW-over-incassokosten.** Art. 2 lid 2 Besluit BIK increases the fee by the VAT
   percentage (21%) when the creditor cannot offset the VAT on the collection service and
   declares this in the aanmaning. The calculator had no BTW surcharge path at all.

Wrong compliance figures are worse than a missing feature, so this change fixes all three by
**extending the existing `BIKStaffelCalculator`** (no parallel calculator is forked) and
aligning the `IncassoKostenBerekening` schema + seed with the corrected output.

## What changes

- `kind: code` — the centre of mass is the ADR-031 exception PHP guard `BIKStaffelCalculator`;
  the accompanying `IncassoKostenBerekening` schema fields + seed are the persistence contract
  for the new outputs (no new register, no new lifecycle).
- Add the **€6.775 statutory maximum** cap to `staffel()`: `toegepast = min(max(totaal, €40), €6.775)`.
- Replace the two frozen rate constants with **maintained, date-keyed rate tables**
  (`WETTELIJKE_RENTE_B2C_TABLE`, `HANDELSRENTE_B2B_TABLE`) and split a boundary-crossing
  accrual window into per-rate sub-periods (`perioden`). An explicit override tarief (contractual
  B2B, art. 6:119a lid 3 BW) still forces a flat single period.
- Add **BTW-over-incassokosten** (`btwVerrekenbaar` / `btwPercentage` inputs; `btwBedrag`,
  `toegepastInclBtw` outputs) per art. 2 lid 2 Besluit BIK; `compose()` counts the BTW-inclusive
  fee into `totaalVerschuldigd`.
- Extend the `IncassoKostenBerekening` schema (`berekening.maximum/btw*`, `wettelijkeRente.perioden`),
  bump its version `0.1.0 → 0.2.0`, correct the stale rate text, and update the €8.400 seed to the
  correct 2026 figures.

**Depends on:** nothing new. Amends [`bookkeeping-credit-control-dunning`](../../specs/bookkeeping-credit-control-dunning/spec.md)
REQ-CCD-003.

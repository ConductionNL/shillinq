# Design: wik-dunning-legal-calculations

## Honest gap analysis (verified against HEAD `b7057f5f` + current legal sources)

Each candidate was checked against the shipped code, not assumed. Verdicts:

| Candidate (WIK / Besluit BIK regime) | Verdict at HEAD | Action |
|---|---|---|
| 5-slab staffel 15/10/5/1/0,5% | **Correct** (`SLAB_BOUNDS_CENTS` + `SLAB_RATES`) | keep |
| €40 statutory minimum floor | **Correct** (`MINIMUM_CENTS = 4000`) | keep |
| B2C 14-dagenbrief day-44 block | **Correct** (`isCalculationPermitted`) + wording in REQ-CCD-006 | keep |
| B2B vs B2C rente branch | **Correct** (`rente()` type split) | keep |
| Partial-payment saldo recalc | **Correct** (staffel re-run on remaining saldo) | keep |
| Dispute pauses accrual | **Correct** (`DunningPauseDispute`) | keep |
| **€6.775 statutory maximum cap** | **MISSING** — top slab unbounded; overcharges any claim > €1.000.000 | **fix** |
| **Maintained rente rate table** | **MISSING** — frozen constants `0.07`/`0.115`, both wrong for 2026; no boundary split | **fix** |
| **BTW-over-incassokosten (art. 2 lid 2)** | **MISSING** — no surcharge path | **fix** |

Two of the three defects (cap, rates) make the shipped calculator return **wrong money**, so this
is a genuine legal-correctness change, not gold-plating. Closing as "already covered" was
considered and rejected on the evidence above.

## Cited legal sources (numbers verified 2026-07-15, never from memory)

- **Besluit vergoeding voor buitengerechtelijke incassokosten** (Stb. 2012, 141), BWBR0031432 —
  <https://wetten.overheid.nl/BWBR0031432/>. Staffel 15/10/5/1/0,5%; **minimum €40, maximum €6.775**
  (max reached at a €1.000.000 vordering). **Art. 2 lid 2/3**: when the creditor cannot offset the
  VAT on the collection service and declares this in the (for consumers mandatory) aanmaning, the
  fee is increased by the VAT percentage.
- **Rechtspraak — Staffel BIK** — confirms €40 min / €6.775 max and the band percentages.
- **Wettelijke rente B2C (art. 6:119 BW)** — AMvB 10-12-2025; per **1-1-2026 = 4%** (was 6% in 2025).
  History (source: wettelijke-rente.com): 2023-01-01 4%, 2024-01-01 7%, 2025-01-01 6%, 2026-01-01 4%.
- **Wettelijke handelsrente B2B (art. 6:119a BW)** — ECB Main Refinancing Rate + 8pp, set half-yearly;
  per **1-1-2026 = 10,15%**. History: 2024-01-01 12,50%, 2024-07-01 12,25%, 2025-01-01 11,15%,
  2025-07-01 10,15%, 2026-01-01 10,15%. Sources: Wieringa Advocaten (5-1-2026), wettelijke-rente.com.

## Approach

Extend `OCA\Shillinq\Service\BIKStaffelCalculator` (the ADR-031 exception guard — precedent
`EmuCalculator`/`RevenueRecognitionService`); no new service, no fork.

### 1. Statutory maximum cap
`MAXIMUM_CENTS = 677500`. In `staffel()`: `toegepast = min(max(totaal, MINIMUM), MAXIMUM)`. Output
gains `maximum`. The declarative aggregation mirror is updated to `MIN(MAX(totaal, 40), 6775)`.

### 2. Date-keyed rate table + per-period accrual
Two private tables (`effectiveFrom => rate`, ascending). `resolveRateOn()` picks the latest entry
≤ the date; `splitByRateBoundaries()` cuts the `[ingangsdatum, berekendOp)` window at every table
boundary it crosses, and each sub-period accrues `hoofdsom × rate × dagen / 365` in integer cents.
The result carries a `perioden[]` audit trail and a headline `tarief` = the rate in force on
`berekendOp`. An explicit `tariefB2B` / `tariefB2C` override bypasses the table (single flat period)
so a contractually agreed B2B rate (art. 6:119a lid 3 BW) is honoured. Updating a rate later is a
one-line table edit, not a logic change.

### 3. BTW-over-incassokosten
`staffel(hoofdsom, btwVerrekenbaar = true, btwPercentage = 0.21)`. When `btwVerrekenbaar === false`
the normed fee (after floor+cap) is increased by `btwPercentage`; output gains `btwVerrekenbaar`,
`btwPercentage`, `btwBedrag`, `toegepastInclBtw`. `compose()` counts `toegepastInclBtw` (not the
ex-BTW `toegepast`) into `totaalVerschuldigd`. Default `btwVerrekenbaar = true` preserves the common
case (VAT-deductible creditor → no surcharge) and keeps existing callers behaviourally identical
except for the corrected rente rate.

All arithmetic stays in integer cents (REQ-CCD-003) to avoid float drift; the public surface returns
2-decimal floats.

## Seed Data

The existing `IncassoKostenBerekening` seed `ik-inv-2026-0247` (€8.400 B2B) is corrected to the 2026
figures the calculator now produces:

- `berekening`: unchanged slabs (375 / 250 / 170), `totaal` 795, `minimum` 40, **`maximum` 6775**,
  `toegepast` 795, `btwVerrekenbaar` true, `btwBedrag` 0, `toegepastInclBtw` 795.
- `wettelijkeRente`: **`tarief` 0.1015** (was 0.115), 22 days, **`bedrag` 51.39** (was 58.13), plus a
  single-entry `perioden[]`.
- **`totaalVerschuldigd` 9246.39** (was 9253.13).

No new seed objects are required; the cap and BTW paths are exercised by the worked-example unit tests.

## Worked-example tests (known-correct legal figures)

| Test | Input | Expected | Basis |
|---|---|---|---|
| max cap | €1.000.000 / €2.000.000 staffel | toegepast €6.775 (both); €2M totaal €11.775 | Besluit BIK max |
| BTW surcharge | €8.400, btwVerrekenbaar=false | €795 + 21% = €166.95 → €961.95 | art. 2 lid 2 |
| rate boundary | €10.000 B2C, 2025-12-17 → 2026-01-16 | 15d@6% €24.66 + 15d@4% €16.44 = €41.10 | 6:119 BW table |
| B2B 2026 rate | €8.400, 22d | €51.39 @ 0.1015 | 6:119a per 1-1-2026 |
| B2C 2026 rate | €820, 31d | €2.79 @ 0.04 | 6:119 per 1-1-2026 |
| override | €8.400 B2B, 22d, tarief 0.12 | €60.76 single period | 6:119a lid 3 |

## ADR-031 compliance

The BIK staffel + multi-period rente arithmetic (integer-cent slab folding, statutory floor/cap,
boundary-split accrual, VAT surcharge) cannot be expressed by OpenRegister's declarative
`x-openregister-aggregations` grammar, which has no runtime date-window arithmetic and no per-slab
reducer. This change therefore stays inside the **documented ADR-031 exception** already established
for this register (the `bikStaffel` / `renteAccrual` aggregation blocks remain as the declarative
mirror; the PHP guard materialises the numbers). No app-owned table, no direct SQL — consistent with
ADR-022; the record is persisted via OpenRegister's `ObjectService`.

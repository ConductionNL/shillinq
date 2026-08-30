# Spec delta: bookkeeping-credit-control-dunning (wik-dunning-legal-calculations)

**Kind:** code (extends the ADR-031 `BIKStaffelCalculator` guard behind `IncassoKostenBerekening`)

This delta corrects REQ-CCD-003 for the current (2026) Wet Incassokosten / Besluit BIK regime:
the statutory **maximum** cap, a **maintained date-keyed rente rate table** with per-period
accrual, and **BTW-over-incassokosten** (art. 2 lid 2 Besluit BIK). The staffel bands, €40
minimum, B2B/B2C branch and the 14-dagenbrief day-44 block are unchanged and are restated for
context only.

## MODIFIED Requirements

### REQ-CCD-003: IncassoKostenBerekening SHALL calculate BIK-staffel per Besluit BIK + wettelijke rente per art. 6:119–119a BW

The `IncassoKostenBerekening` register MUST declare the field set of the base requirement, with
`berekening` extended to `{schaal1_0_2500, schaal2_2500_5000, schaal3_5000_10000,
schaal4_10000_200000, schaal5_200000plus, totaal, minimum, maximum, toegepast, btwVerrekenbaar,
btwPercentage, btwBedrag, toegepastInclBtw}` and `wettelijkeRente` extended with a `perioden`
array of per-rate sub-periods `{van, tot, dagen, tarief, bedrag}`.

Staffel-berekening (Besluit BIK), unchanged bands:
- €0–€2.500: 15% (minimum €40)
- €2.500–€5.000: 10% on the amount above €2.500
- €5.000–€10.000: 5% on the amount above €5.000
- €10.000–€200.000: 1% on the amount above €10.000
- €200.000+: 0,5% on the amount above €200.000

The system SHALL apply the statutory **maximum of €6.775**: the applied fee is
`toegepast = min(max(totaal, €40), €6.775)`. The maximum is reached at a €1.000.000 hoofdsom;
above that, `toegepast` MUST remain €6.775.

The system SHALL compute wettelijke rente from a **maintained, date-keyed rate table** rather than
a frozen constant, because the statutory rate changes ~biannually. Per 1-1-2026 the rates are
**B2C 4%** (art. 6:119 BW) and **B2B handelsrente 10,15%** (art. 6:119a BW). When an accrual window
crosses a statutory rate boundary the system MUST split it into sub-periods that each accrue at their
own rate; `wettelijkeRente.bedrag` is the sum over sub-periods of `hoofdsom × tarief × dagen / 365`
(computed in integer cents), and `wettelijkeRente.tarief` is the rate in force on `berekendOp`. A
caller MAY supply an explicit override tarief (e.g. a contractually agreed B2B rate per art. 6:119a
lid 3 BW), which forces a single flat period.

The system SHALL apply **BTW-over-incassokosten** per art. 2 lid 2 Besluit BIK: when the creditor
cannot offset the VAT on the collection service (`btwVerrekenbaar = false`) and declares this, the
applied fee is increased by the VAT percentage (`btwPercentage`, default 21%); `btwBedrag` and
`toegepastInclBtw = toegepast + btwBedrag` MUST be recorded. When the creditor can offset VAT
(default), no surcharge is applied. `totaalVerschuldigd` MUST count the BTW-inclusive fee
(`toegepastInclBtw`), plus `hoofdsom` and `wettelijkeRente.bedrag`.

#### Scenario: Statutory maximum €6.775 caps incassokosten on large claims

- **GIVEN** an outstanding hoofdsom of €2.000.000
- **WHEN** IncassoKostenBerekening is evaluated
- **THEN** `berekening.totaal` MUST be €11.775 (unclamped sum) but `berekening.toegepast` MUST be
  €6.775; a €1.000.000 hoofdsom MUST likewise yield `toegepast` €6.775 (the cap is reached exactly).

#### Scenario: B2B handelsrente uses the current 2026 rate (10,15%)

- **GIVEN** invoice €8.400 in verzuim, calculated over 22 days in 2026, partyType=B2B
- **WHEN** wettelijkeRente is calculated with no override
- **THEN** tarief MUST be 0.1015; type MUST be HANDELSRENTE_B2B_6_119A_BW; bedrag MUST be
  (€8.400 × 0.1015 × 22/365) = €51.39.

#### Scenario: B2C rente splits across a statutory rate boundary

- **GIVEN** invoice €10.000, partyType=B2C, rente accruing 2025-12-17 → 2026-01-16 (the 1-1-2026
  boundary where the wettelijke rente drops 6% → 4%)
- **WHEN** wettelijkeRente is calculated with no override
- **THEN** `perioden` MUST contain two sub-periods — 15 days @ 6% = €24.66 and 15 days @ 4% = €16.44 —
  and `bedrag` MUST be €41.10 (a single flat 4% would wrongly yield €32.88); `tarief` MUST be 0.04.

#### Scenario: BTW-over-incassokosten added when creditor cannot offset VAT

- **GIVEN** invoice €8.400 with staffel `toegepast` €795 and a creditor who cannot offset VAT
- **WHEN** IncassoKostenBerekening is evaluated with `btwVerrekenbaar=false`
- **THEN** `btwPercentage` MUST be 0.21, `btwBedrag` MUST be €166.95 and `toegepastInclBtw` MUST be
  €961.95; with `btwVerrekenbaar=true` (default) `btwBedrag` MUST be €0 and `toegepastInclBtw` €795.

#### Scenario: B2B incassokostenstaffel calculated correctly on €8.400

- **GIVEN** invoice €8.400, partyType=B2B, stage 3 entered on day 30
- **WHEN** IncassoKostenBerekening is evaluated
- **THEN** staffel-berekening MUST yield: 15% × €2.500 (€375) + 10% × €2.500 (€250)
  + 5% × €3.400 (€170) = €795 total, and `toegepast` €795 (below the €6.775 cap).

#### Scenario: B2C incassokostenstaffel NOT calculated before day 44

- **GIVEN** invoice €820, partyType=B2C, stage 3 on day 30
- **WHEN** IncassoKostenBerekening is triggered at day 35 (within the 14-day period)
- **THEN** the calculation MUST be BLOCKED per art. 6:96 BW (earliest permitted day 44).

#### Scenario: Partial payment recalculates staffel on remaining saldo

- **GIVEN** invoice €8.400 with IncassoKostenBerekening (€795), partial payment €3.000 on day 50
- **WHEN** the payment is booked and IncassoKostenBerekening is recalculated
- **THEN** the new calculation MUST be for remaining saldo €5.400: 15% × €2.500 (€375)
  + 10% × €2.900 (€290) = €665 (lower than the original €795).

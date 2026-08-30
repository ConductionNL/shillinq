# Tasks: wik-dunning-legal-calculations (kind: code)

Extends the existing `BIKStaffelCalculator` (ADR-031 exception guard) + aligns the
`IncassoKostenBerekening` schema/seed. No new register, no fork.

## 1. Statutory maximum cap (Besluit BIK €6.775)

- [x] 1.1 Add `MAXIMUM_CENTS = 677500` and clamp `toegepast = min(max(totaal, MINIMUM), MAXIMUM)` in `staffel()`; expose `maximum` in the returned array
- [x] 1.2 Update the declarative `bikStaffel.toegepast` aggregation expression to `MIN(MAX(totaal, 40), 6775)`

## 2. Maintained date-keyed rente rate tables + per-period accrual

- [x] 2.1 Add `WETTELIJKE_RENTE_B2C_TABLE` (2023–2026) and `HANDELSRENTE_B2B_TABLE` (2024–2026) with the cited effective-date values; repoint `DEFAULT_*` constants to the current 2026 heads (0.04 / 0.1015)
- [x] 2.2 Add `resolveRateOn()` (latest effectiveFrom ≤ date) and `splitByRateBoundaries()` (cut the accrual window at each crossed boundary)
- [x] 2.3 Rewrite `rente()` to accrue per sub-period in integer cents, sum into `bedrag`, return a `perioden[]` audit trail and a headline `tarief` = rate on `berekendOp`; an explicit override tarief forces a single flat period

## 3. BTW-over-incassokosten (art. 2 lid 2 Besluit BIK)

- [x] 3.1 Add `btwVerrekenbaar` (default true) + `btwPercentage` (default 0.21) params to `staffel()`; when not verrekenbaar, surcharge the normed fee and expose `btwVerrekenbaar`/`btwPercentage`/`btwBedrag`/`toegepastInclBtw`
- [x] 3.2 Thread the same params through `compose()`; count `toegepastInclBtw` into `totaalVerschuldigd`

## 4. Schema + seed alignment

- [x] 4.1 Add `berekening.maximum` + `berekening.btwVerrekenbaar/btwPercentage/btwBedrag/toegepastInclBtw` and `wettelijkeRente.perioden[]` to the `IncassoKostenBerekening` schema; correct the stale 11,5%/7% description text; bump schema version `0.1.0 → 0.2.0`
- [x] 4.2 Update the €8.400 seed `ik-inv-2026-0247` to the corrected 2026 figures (tarief 0.1015, rente 51.39, totaalVerschuldigd 9246.39, maximum 6775, BTW block, perioden)

## 5. Worked-example tests (known-correct legal figures — mandatory)

- [x] 5.1 `testStaffelMaximumCapAt6775` — €1M and €2M both clamp to €6.775
- [x] 5.2 `testStaffelBtwSurchargeWhenNotDeductible` — €795 + 21% = €961.95; default path adds nothing
- [x] 5.3 `testRenteSplitsAcrossRateBoundary` — €10.000 B2C spanning 1-1-2026 = €41.10 across two perioden
- [x] 5.4 Correct the pre-existing `testRenteB2B…` / `testRenteB2C…` / `testCompose…` expectations to the 2026 rates (0.1015 / 0.04) and add `testRenteHonoursExplicitOverride`

## 6. Verify

- [x] 6.1 Run the `BIKStaffelCalculator` suite green in `php:8.3-cli` (ext-zip + bcmath/soap/xsl/intl/gd, fresh composer install)
- [x] 6.2 Run the full unit suite (`phpunit-unit.xml`) — no regression vs the ~3684-green baseline (4 pre-existing Symfony\HeaderUtils env errors excluded)
- [x] 6.3 Validate the register JSON parses and the `@spec` anchors resolve

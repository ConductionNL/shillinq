# REQ-PAY-005: Vakantietoeslag-reservering opbouw

## Requirement

Het systeem moet maandelijks 8 procent van het brutoloon reserveren als vakantietoeslag, met uitbetaling standaard in mei (instelbaar per werkgever).

## Acceptance Criteria

### Scenario: Maandelijkse reservering

**GIVEN** brutoloon mei €4.940
**WHEN** periode wordt verwerkt
**THEN** moet €395,20 (8% × €4.940) worden gereserveerd
**AND** moet in `cumulatieven.vakantiegeld_reservering_ytd` worden opgeteld
**AND** moet GL-credit 17xx "Te betalen vakantiegeld" worden geboekt

### Scenario: Uitbetaling mei

**GIVEN** ondertijd gereserveerd cumulatief €4.180 per mei
**WHEN** mei-batch met "vakantietoeslag uitkeren" draait
**THEN** moet €4.180 als `brutoComponenten.vakantietoeslag_uitbetaling` op loonstrook verschijnen
**AND** moet LH op bijzondere-tarief-tabel (groen tabel) worden berekend
**AND** moet `cumulatieven.vakantiegeld_reservering_ytd` worden gereset na uitbetaling

### Scenario: Vakantiedagen-saldo

**GIVEN** werknemer met contracturenPerWeek 40, opgebouwd €4.180 vakantietoeslag
**WHEN** vakantiedagen berekend
**THEN** moet saldo vakantiedagen = €4.180 / (jaarloon / 261 werkdagen) zijn
**AND** moet in `vakantieDagenReservering.saldoEindPeriode` staan

## Related Entities

- `Werknemer` (vakantiegeldPct: 0.08)
- `LoonStrook.brutoComponenten.vakantietoeslag_uitbetaling`
- `LoonStrook.cumulatieven.vakantiegeld_reservering_ytd`
- `Werkgever` (vakantiegeldUitbetalingMaand: 5 = mei)

## Standards

- Wet minimumloon en minimumvakantiebijslag (WML) — minimale vakantiebijslag 8%
- BW art. 7:634 — vakantiedagen opbouw 4 × contracturen per week per jaar

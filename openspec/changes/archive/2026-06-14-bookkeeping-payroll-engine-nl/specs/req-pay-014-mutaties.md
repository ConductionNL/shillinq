# REQ-PAY-014: Mutaties tussen-periode-verwerking

## Requirement

Het systeem moet kunnen omgaan met mid-periode mutaties (indienst-datum 15e van de maand, uitdienst-datum 22e, contractwijziging) en de pro-rato berekening toepassen.

## Acceptance Criteria

### Scenario: Indienst halverwege maand

**GIVEN** werknemer komt in dienst per 15 mei 2026 (maand mei heeft 22 werkdagen)
**WHEN** mei-batch draait
**THEN** moet brutoloon pro-rato = (17 werkdagen / 22 werkdagen) × maandloon berekend
**AND** moeten alle premies dienovereenkomstig schalen
**AND** moet in loonstrook opgenomen "inDienstSinds: 2026-05-15"

### Scenario: Uitdienst met openstaande vakantie-uren

**GIVEN** werknemer gaat uit dienst per 30 juni met saldo 18 vakantiedagen
**WHEN** eindafrekening wordt opgesteld
**THEN** moet die 18 dagen × (jaarloon / 261) als brutoloon worden uitgekeerd
**AND** moet LH op bijzonder tarief worden toegepast
**AND** moeten alle premies t/m 30 juni afgebouwd

### Scenario: Contractwijziging mid-periode

**GIVEN** werknemer wijzigt van half-time naar fulltime per 15 mei
**WHEN** mei-batch draait
**THEN** moet pro-rato splitsing in twee sub-perioden gebeuren (1–14 mei: half-time; 15–31 mei: full-time)
**AND** mag berekening niet geblokkeerd zijn als inDienstSinds/contractType unchanged

## Related Entities

- `Werknemer` (inDienstSinds, uitDienstPer, contractType)
- `LoonStrook` (pro-rato bruto, premies, LH)
- `LoonPeriode` (werkdagen-teller per periode)

## Standards

- BW art. 7:625 — loonbetaling voorwaarden
- Wet financiering sociale verzekeringen — franchise-berekeninge pro-rato

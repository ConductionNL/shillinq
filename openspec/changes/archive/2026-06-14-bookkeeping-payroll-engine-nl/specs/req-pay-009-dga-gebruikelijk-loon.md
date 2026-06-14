# REQ-PAY-009: DGA-gebruikelijk-loon controle

## Requirement

Voor DGA's (statutair bestuurder met >5 procent aandelenbelang) moet het systeem toetsen of het loon voldoet aan de gebruikelijk-loonregeling (in 2026: minimaal €56.000 of hoger gangbaar inkomen vergelijkbare dienstbetrekking).

## Acceptance Criteria

### Scenario: DGA met te laag loon

**GIVEN** DGA Jan met bruto jaarloon €48.000 (geen specifieke uitzondering)
**WHEN** gebruikelijk-loon-toets draait
**THEN** moet waarschuwing verschijnen "DGA-loon onder norm 2026 €56.000"
**AND** moet advies "Verhoog loon of motiveer uitzondering" worden gegeven
**AND** mag loonverwerking niet geblokkeerd zijn (accountant beslist)

### Scenario: DGA met startup-uitzondering

**GIVEN** DGA in eerste 3 jaar startup met aangetoond beperkte winstgevendheid
**WHEN** toets draait
**THEN** moet de uitzonderingsroute beschikbaar zijn met evidence-upload
**AND** mag waarschuwing niet verschijnen wanneer `gebruikelijkLoonUitzondering` is ingevuld

### Scenario: DGA boven norm

**GIVEN** DGA met jaarloon €60.000 ≥ €56.000
**WHEN** toets draait
**THEN** mag geen waarschuwing verschijnen

## Related Entities

- `Werknemer` (is_dga: boolean, jaarloonBruto: number, gebruikelijkLoonNormBedrag: number, gebruikelijkLoonUitzondering: string|null)

## Standards

- Wet op de loonbelasting 1964 art. 12a — DGA-gebruikelijk-loon regeling
- Belastingdienst Handleiding art. 12a — normering en uitzonderingen

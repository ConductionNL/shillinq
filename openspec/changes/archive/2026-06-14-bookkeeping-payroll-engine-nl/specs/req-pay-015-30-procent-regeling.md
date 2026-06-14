# REQ-PAY-015: 30%-regeling expat-werknemer

## Requirement

Voor expat-werknemers met goedgekeurde 30%-regeling (per 2024 afgebouwd, in 2026 in transitie) moet het systeem de 30 procent vergoeding-correctie correct toepassen.

## Acceptance Criteria

### Scenario: 30%-regeling toegepast 2026

**GIVEN** werknemer heeft beschikking 30%-regeling tot 2027
**AND** brutoloon €8.500 per maand
**WHEN** periode wordt verwerkt
**THEN** moet 30 procent (€2.550) als belastingvrije vergoeding verschijnen in `brutoComponenten`
**AND** moet fiscaal loon €5.950 zijn (€8.500 - €2.550)
**AND** moet in `Werknemer.expat30PctRegeling` opgenomen zijn

### Scenario: 30%-regeling transitie/afbouw

**GIVEN** werknemers die 30%-regeling hebben, na 1 januari 2027 meer beperkt
**WHEN** systeem-update draait
**THEN** moet waarschuwing gegeven worden "30%-regeling loopt af 2027"
**AND** mag berekening 2026 ongewijzigd blijven

## Related Entities

- `Werknemer` (expat30PctRegeling: boolean, expat30PctRegeling_description: string)
- `LoonStrook.brutoComponenten` (optionele 30%-vergoeding)
- `LoonStrook.fiscaalLoon` (excl. 30%-vergoeding)

## Standards

- Wet op de loonbelasting 1964 art. 31aad — 30%-regeling
- Belastingplan 2024–2026 — afbouw van 30%-regeling
- Belastingdienst Handleiding — 30%-regeling-application

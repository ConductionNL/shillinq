# REQ-PAY-006: 13e maand en eindejaarsuitkering

## Requirement

Het systeem moet optioneel een 13e maand (gelijk maandloon) of eindejaarsuitkering (procentueel) ondersteunen, doorgaans uit te keren in november of december.

## Acceptance Criteria

### Scenario: 13e maand bij december-batch

**GIVEN** werknemer met dertiendeMaand=true
**WHEN** december-batch draait
**THEN** moet bij brutoComponenten een extra regel "13e maand" verschijnen
**AND** moet bedrag gelijk zijn aan maandloon (e.g., €4.940 voor reguliere medewerker)
**AND** moet LH op bijzonder tarief worden toegepast

### Scenario: Eindejaarsuitkering procentueel

**GIVEN** werknemer met eindejaarsuitkeringPct=0.50 (50%)
**WHEN** december-batch draait
**THEN** moet eindejaarsuitkering = maandloon × eindejaarsuitkeringPct verschijnen
**AND** moet LH op bijzonder tarief

### Scenario: Geen 13e maand/EJU

**GIVEN** werknemer met dertiendeMaand=false en eindejaarsuitkeringPct=0
**WHEN** december-batch draait
**THEN** mag geen extra bruto-component verschijnen

## Related Entities

- `Werknemer` (dertiendeMaand: boolean, eindejaarsuitkeringPct: number)
- `LoonStrook.brutoComponenten` (13e maand optioneel, eindejaarsuitkering optioneel)

## Standards

- BW art. 7:625 — aanvullende vergoedingen
- Belastingdienst Handboek — bijzondere bestanddelen LH-tabel

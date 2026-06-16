# REQ-PAY-008: Thuiswerkvergoeding €2,40/dag

## Requirement

Het systeem moet de thuiswerkvergoeding (€2,40 per dag in 2026) toepassen op aangegeven thuiswerkdagen, belastingvrij.

## Acceptance Criteria

### Scenario: 8 thuiswerkdagen in mei

**GIVEN** werknemer registreert 8 thuiswerkdagen in mei
**WHEN** periode wordt verwerkt
**THEN** moet €19,20 (8 × €2,40) thuiswerkvergoeding belastingvrij worden uitgekeerd
**AND** moet in `brutoComponenten.thuiswerkvergoeding` staan
**AND** mag NIET in `fiscaalLoon` worden opgenomen

### Scenario: Combinatie thuiswerk + reisvergoeding zelfde dag

**GIVEN** werknemer reist op een thuiswerkdag toch naar kantoor (e.g., vergadering)
**WHEN** periode wordt verwerkt
**THEN** mag op die dag óf thuiswerkvergoeding óf reisvergoeding worden uitgekeerd, niet beide

## Related Entities

- `Werknemer` (thuiswerkdagenPerWeek: number)
- `LoonStrook.brutoComponenten.thuiswerkvergoeding`
- `LoonStrook.fiscaalLoon` (excl. thuiswerkvergoeding)

## Standards

- Wet op de loonbelasting 1964 art. 13 — belastingvrije vergoedingen
- Belastingdienst Handboek Loonheffingen 2026 — thuiswerk-vergoeding tarief

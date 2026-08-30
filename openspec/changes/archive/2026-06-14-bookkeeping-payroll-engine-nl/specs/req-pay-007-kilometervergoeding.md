# REQ-PAY-007: Belastingvrije kilometervergoeding €0,23/km

## Requirement

Het systeem moet kilometervergoeding voor zakelijke kilometers belastingvrij verwerken tot €0,23/km (2026).

## Acceptance Criteria

### Scenario: 120 zakelijke kilometers

**GIVEN** werknemer dient 120 zakelijke km in
**WHEN** periode wordt verwerkt
**THEN** moet €27,60 (120 × €0,23) belastingvrij worden uitgekeerd
**AND** mag dit bedrag NIET in `fiscaalLoon` worden opgenomen
**AND** moet in `brutoComponenten.kilometervergoeding_belastingvrij` staan

### Scenario: €0,30 overschrijdt belastingvrij maximum

**GIVEN** werkgever betaalt €0,30/km
**WHEN** periode wordt verwerkt
**THEN** moet €0,07/km (€0,30 - €0,23) × 120 = €8,40 als belast loon worden opgenomen in `fiscaalLoon`
**AND** moet het deel onder €0,23 (€27,60) belastingvrij blijven

## Related Entities

- `LoonStrook.brutoComponenten.kilometervergoeding_belastingvrij`
- `LoonStrook.fiscaalLoon` (excl. belastingvrije kilometrage)

## Standards

- Wet op de loonbelasting 1964 art. 13 — loon in natura, belastingvrije vergoedingen
- Belastingdienst Handboek — kilometer-vergoeding 2026 tarief

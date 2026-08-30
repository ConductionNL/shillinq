# REQ-PAY-002: Loonheffingstabellen 2026 ingeladen en versie-gemarkeerd

## Requirement

Het systeem moet de officiële LH-tabellen 2026 (wit/groen, regulier/bijzonder, week/4-weken/maand/jaar, met/zonder korting) ingeladen hebben met versienummer en bron-referentie.

## Acceptance Criteria

### Scenario: Tabel-validatie tegen Belastingdienst-PDF

**GIVEN** LH-tabel 2026 wit, maand, met korting
**WHEN** regel "loon 4901–5083 → LH 1058,00 / korting 240,83" wordt opgezocht
**THEN** moet exact die regel beschikbaar zijn in `LoonheffingTabel2026.tabelRegels`
**AND** moet bron-attribuut "Belastingdienst LH-tabel 2026 januari, versienr 2025-W47" zijn
**AND** moet `geldigVan` 2026-01-01 zijn

### Scenario: Mid-jaarse tabelwijziging

**GIVEN** Belastingdienst publiceert correctietabel per 1 juli 2026
**WHEN** tabel-update wordt ingelezen als nieuwe `LoonheffingTabel2026`-record met `geldigVan: 2026-07-01`
**THEN** moet loonperiode juni 2026 gebruik de oude tabel (versie 2025-W47)
**AND** moet loonperiode juli 2026 gebruik de nieuwe tabel (versie 2026-W28)
**AND** moet audit-trail beide tabel-versies bewaren

### Scenario: Alle tabel-varianten beschikbaar

**GIVEN** werkgever met diverse werknemers (some wit, some groen; some met korting, some zonder)
**WHEN** mei-loonperiode verwerkt
**THEN** moeten minstens deze tabellen beschikbaar zijn:
  - Wit regulier, maand, met korting
  - Wit regulier, maand, zonder korting
  - Wit bijzonder, maand (vakantietoeslag)
  - Groen (starter, kenniswerkers), maand, met/zonder korting
  - Jaren-tabel (13e maand, eindejaarsuitkering)

## Related Entities

- `LoonheffingTabel2026` (jaar, kleur, periode, metKorting, tabelRegels, versienummer, bron, geldigVan, geldigTot)

## Standards

- Belastingdienst Handboek Loonheffingen 2026 — publicatie januari 2026
- Belastingdienst LH-tabellen 2026 (december 2025 publicatie) — versienr 2025-W47
- Wet op de loonbelasting 1964 art. 2c — loonheffing berekening

# REQ-PAY-013: Jaaropgave werknemer en Belastingdienst

## Requirement

Per jaar moet voor elke werknemer een jaaropgave worden gegenereerd (digitaal + PDF) met fiscaal loon, LH, ingehouden ZVW, pensioenpremie, en uitgekeerde vakantietoeslag.

## Acceptance Criteria

### Scenario: Jaaropgave 2025 voor werknemer

**GIVEN** loonperioden 2025-01 t/m 2025-12 zijn allen afgesloten
**WHEN** "Jaaropgaven genereren" wordt gedraaid in januari 2026
**THEN** moet per werknemer een jaaropgave-PDF beschikbaar zijn met:
  - Naam, adres, BSN werknemer
  - Werkgever-naam, loonheffingsnummer
  - Jaar 2025
  - Fiscaal loon JTD (totaal van cumulatieven.fiscaalloon_ytd in december)
  - Loonheffing JTD (som van alle loonstroken-LH)
  - Ingehouden ZVW JTD (som van ingehouden_wn, normaliter 0)
  - Pensioenpremie werknemer JTD (som van pensioen.premie_wn_aandeel)
  - Uitgekeerde vakantietoeslag (mei-bedrag)

### Scenario: Validatie tegen loonstroken

**GIVEN** jaaropgave 2025 gegenereerd
**WHEN** controle-run draait
**THEN** moeten cumulatieven 100 procent matchen met som van alle loonstrook-perioden
**AND** mag geen afwijking > €0,01 voorkomen (floating-point tolerantie)

### Scenario: Archivering Belastingdienst

**GIVEN** jaaropgave gegenereerd
**WHEN** jaaropgave wordt gearchiveerd
**THEN** moet kopie naar Belastingdienst kunnen worden voorbereid (SBR/XML)
**AND** moet in openregister opgeslagen met 5-jarige bewaarplicht

## Related Entities

- `LoonStrook` (alle loonperioden)
- `Werknemer` (naam, adres, BSN)
- `Jaaropgave` (impliciete entiteit, aggregaat van loonstroken)

## Standards

- Wet op de loonadministratie 1964 — jaarrekonstituering
- Belastingdienst Handboek — jaaropgave-format
- SBR Nederland — jaaropgave-taxonomie (toekomstige versie)

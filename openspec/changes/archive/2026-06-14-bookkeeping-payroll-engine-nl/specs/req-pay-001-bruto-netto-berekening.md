# REQ-PAY-001: Bruto→Netto loonberekening per werknemer per periode

## Requirement

Het systeem moet voor elke werknemer per loonperiode een volledige bruto→netto-berekening uitvoeren conform de loonheffingstabel die hoort bij zijn fiscale situatie en de in dat jaar geldende SV-premies.

## Acceptance Criteria

### Scenario: Reguliere maandloonberekening

**GIVEN** werknemer met basissalaris €4.940, witte tabel met loonheffingskorting, mei 2026
**WHEN** periode wordt verwerkt
**THEN** moet LH €1.083,40 zijn conform LH-tabel 2026 wit maand met korting (versie 2025-W47)
**AND** moet nettoloon €3.520,12 zijn (basissalaris - LH - pensioenpremie werknemer + thuiswerkvergoeding)
**AND** moet LoonStrook.fiscaalLoon €4.959,20 zijn (basissalaris + thuiswerkvergoeding, belastingvrij)

### Scenario: Werknemer zonder loonheffingskorting

**GIVEN** werknemer heeft loonheffingskorting niet geactiveerd (loonheffingstabel = "WIT_REGULIER_ZONDER_KORTING")
**WHEN** LH berekend
**THEN** moet de tabel-zonder-korting worden toegepast
**AND** moet maandbedrag substantieel hoger zijn (10–15% verschil)

### Scenario: Bruto-component aggregatie

**GIVEN** loonperiode mei 2026 met:
  - basissalaris €4.940
  - thuiswerkvergoeding €19,20 (2 werkdagen × €2,40 belastingvrij)
  - kilometervergoeding €27,60 belastingvrij (120 km × €0,23)
**WHEN** bruto→netto berekend
**THEN** moet `brutoComponenten.totaal_bruto` €4.986,80 zijn
**AND** moet alleen basissalaris + eindjaarsuitkering in `fiscaalLoon` tellen (vergoedingen belastingvrij)

## Related Entities

- `LoonStrook` (brutoComponenten, fiscaalLoon, premieloon_SV, loonheffing, nettoBetaald)
- `LoonheffingTabel2026` (tabelRegels per loonheffingstabel-kleur)
- `Werknemer` (loonheffingstabel, loonheffingstabelKorting)

## Standards

- Wet op de loonbelasting 1964 — fiscale loonbegrip
- Belastingdienst Handboek Loonheffingen 2026 — LH-tabel toepassing
- BW art. 7:610 — arbeidsovereenkomst loonbegrip

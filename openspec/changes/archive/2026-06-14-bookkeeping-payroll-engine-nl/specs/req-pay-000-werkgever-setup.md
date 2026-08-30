# REQ-PAY-000: Werkgever en werknemers-setup

## Requirement

Bij eerste activatie moet het systeem een werkgever-master inrichten (loonheffingsnummer, sectorindeling, AWF-laag-of-hoog, WKR-vrije-ruimte-budget) en per werknemer een complete master initialiseren via een wizard.

## Acceptance Criteria

### Scenario: Werkgever-onboarding

**GIVEN** nieuwe werkgever activeert payroll in Shillinq
**WHEN** setup-wizard start
**THEN** moeten KvK, loonheffingsnummer, sectorindeling (UWV-code) worden gevraagd
**AND** moet UWV-sectorindeling-validatie worden uitgevoerd (foutieve sector wordt afgewezen)
**AND** moet AWF-tarief-keuze (laag bij overwegend onbepaalde-tijd contracts, hoog anders) worden onderbouwd
**AND** moet WKR-budget voor 2026 worden ingevoerd (m.b.t. art. 31a Url LB)

### Scenario: Werknemer-import

**GIVEN** werkgever heeft master ingevuld
**WHEN** werknemers worden geïmporteerd via CSV of hrmq-integratie
**THEN** moeten voor elke werknemer BSN, naam, contract-type, uurloon/jaarloon, pensioenregeling, loonheffingstabel-kleur worden gevalideerd
**AND** moeten ontbrekende velden worden gemarkeerd als "compleeteer in formulier"
**AND** moet geen loonperiode kunnen starten zonder 100% complete werknemers-master

## Related Entities

- `Werkgever` (kvk, loonheffingsnummer, sectorcode, awfTarief, wkrBudget2026)
- `Werknemer` (bsn, voorletters, achternaam, contractType, uurloon, jaarloonSV, pensioenRegeling, loonheffingstabel)

## Standards

- Wet op de loonadministratie 1964 — administratieplicht
- UWV-handleiding sectorindeling Werkhervattingskas — sectorcode-validatie
- Belastingdienst Handboek Loonheffingen 2026 — loonheffingstabel-kleuren (WIT/GROEN)

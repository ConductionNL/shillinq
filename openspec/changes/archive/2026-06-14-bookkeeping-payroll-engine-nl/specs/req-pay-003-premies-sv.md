# REQ-PAY-003: Premies SV correct toegepast

## Requirement

Het systeem moet premies werknemersverzekeringen (AWF, AOF, AOF-uniforme opslag kinderopvang, WHK met sector-specifieke opslag, en eventuele sectorfonds) correct berekenen tot maximum premieloon 2026 €74.480.

## Acceptance Criteria

### Scenario: AWF-laag-tarief

**GIVEN** werknemer met onbepaalde tijd schriftelijk contract, werkgever AWF-laag
**WHEN** AWF berekend op SV-loon €4.940
**THEN** moet AWF 2,64% × €4.940 = €130,42 zijn (actuele 2026-tarief)
**AND** moet werkgever-aandeel in `premiesSVWerkgever.awf` staan

### Scenario: AOF basis-premie

**GIVEN** werkgever met loonsom <€905k = "klein werkgever"
**WHEN** AOF berekend
**THEN** moet AOF-klein-tarief (in 2026: 5,38%) worden toegepast

### Scenario: Sectorfonds-premie

**GIVEN** werknemer in sectorcode 32 (Overige dienstverlening)
**WHEN** Werkhervattingskas-premie berekend
**THEN** moet sector-specifieke 2026-tarief worden opgeslagen (bijv. 0,13%)
**AND** moet in `premiesSVWerkgever.whk` staan

### Scenario: Maximum premieloon €74.480

**GIVEN** werknemer met maandloon €7.000 (jaarloon €84.000 > €74.480)
**WHEN** AWF/AOF/WHK berekend
**THEN** moet alleen over €74.480/12 = €6.206,67 per maand premie betaald
**AND** moet verdere looncomponenten van deze werknemer geen premie genereren

## Related Entities

- `Werknemer` (premieGroupWW, premieGroupWGF, sectorcode)
- `LoonStrook.premiesSVWerkgever` (awf, aof_basis, uniforme_opslag_kinderopvang, wko, whk, totaal_werkgever)
- `LoonStrook.premieloon_SV` (max €74.480/12 per maand)

## Standards

- Wet financiering sociale verzekeringen (Wfsv) 2021 — premie-franchises en maximale premieloon
- UWV-handleiding sectorindeling Werkhervattingskas 2026 — sector-specifieke tarieven
- Werkhervattingskas-premies 2026 (UWV september 2025) — jaarlijkse tabel

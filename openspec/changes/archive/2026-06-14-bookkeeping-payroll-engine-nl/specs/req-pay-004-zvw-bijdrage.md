# REQ-PAY-004: ZVW-bijdrage werkgever

## Requirement

Het systeem moet ZVW-bijdrage werkgever (5,32% in 2026 voor laag-tarief, 6,57% bij hoog-tarief) toepassen tot maximaal ZVW-premieloon €71.628.

## Acceptance Criteria

### Scenario: ZVW werkgever laag-tarief

**GIVEN** reguliere werknemer in loondienst
**WHEN** ZVW berekend op €4.940
**THEN** moet 5,32% × €4.940 = €262,81 zijn
**AND** moet in `LoonStrook.zvw.afgedragen_wg_5_32pct` staan

### Scenario: ZVW hoog-tarief

**GIVEN** werkgever met zvwTarief = "HOOG"
**WHEN** ZVW berekend op €4.940
**THEN** moet 6,57% × €4.940 = €324,62 zijn

### Scenario: ZVW maximum premieloon

**GIVEN** werknemer met maandloon €6.500 (jaarloon €78.000 > €71.628)
**WHEN** ZVW berekend
**THEN** moet alleen over €71.628/12 = €5.969 per maand betaald
**AND** moet totaal ZVW per werknemer per jaar max €71.628 × tarief zijn

## Related Entities

- `Werkgever` (zvwTarief: "LAAG" | "HOOG")
- `LoonStrook.zvw` (ingehouden_wn: 0, afgedragen_wg_5_32pct, afgedragen_wg_6_57pct)

## Standards

- Zorgverzekeringswet (ZVW) art. 41 — werkgeversbijdrage
- Wfsv 2021 — ZVW-premie-maximaal-premieloon-grens 2026

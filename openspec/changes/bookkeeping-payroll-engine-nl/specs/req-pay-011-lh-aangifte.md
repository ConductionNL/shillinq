# REQ-PAY-011: LH-aangifte voorbereid voor Digipoort

## Requirement

Het systeem moet per maand een SBR/XBRL LH-aangifte voorbereiden, geldig voor verzending via Digipoort naar de Belastingdienst, vóór de uiterste afdrachtdatum (laatste dag volgende maand).

## Acceptance Criteria

### Scenario: Mei-aangifte voorbereid uiterlijk 30 juni

**GIVEN** periode 2026-05 is afgesloten op 27 mei
**WHEN** LH-batch draait
**THEN** moet `LHAfdracht` record aangemaakt worden met:
  - `werkgeverId` = werkgever
  - `periodeId` = mei-periode
  - `totaalLoonheffing` = som van alle loonstroken-LH
  - `totaalEindheffingenWKR` = WKR eindheffing 80% (via WKR-app)
  - `totaalPremiesSV` = som van alle SV-premies
  - `totaalZVW` = som van alle ZVW
  - `totaalAfdracht` = totaalLoonheffing + totaalPremiesSV + totaalZVW + WKR
  - `vervaldagAfdracht` = "2026-06-30"
  - `status` = "VOORBEREID"

### Scenario: SBR/XBRL-generatie

**GIVEN** `LHAfdracht` in status VOORBEREID
**WHEN** SBR-conversie draait
**THEN** moet SBR/XBRL-instantie aangemaakt worden
**AND** moet `sbrInstanceRef` ingevuld worden
**AND** moet XML geldig zijn tegen LA-XX-2026-taxonomie

## Related Entities

- `LHAfdracht` (werkgeverId, periodeId, totaalLoonheffing, totaalEindheffingenWKR, totaalPremiesSV, totaalZVW, totaalAfdracht, vervaldagAfdracht, status, sbrInstanceRef)

## Standards

- SBR Nederland Loonaangifte-taxonomie LA-XX-2026 — XBRL-schema
- Wet op de loonbelasting 1964 — afdracht-termijn
- Digipoort protocol — webservice-integratie

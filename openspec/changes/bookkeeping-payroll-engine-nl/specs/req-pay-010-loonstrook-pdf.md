# REQ-PAY-010: Loonstrook conform art. 626 BW

## Requirement

Per werknemer per periode moet een loonstrook PDF worden gegenereerd met alle wettelijk verplichte vermeldingen: brutoloon, alle componenten, inhoudingen LH/SV, fiscaal loon cumulatief, sociaal verzekeringsloon, pensioen, nettoloon.

## Acceptance Criteria

### Scenario: PDF-loonstrook generatie

**GIVEN** loonperiode 2026-05 is afgesloten (status=GESLOTEN)
**WHEN** loonstroken worden geproduceerd
**THEN** moet per werknemer een PDF beschikbaar zijn
**AND** moeten alle in art. 626 BW genoemde elementen aanwezig zijn:
  - Naam, adres, BSN werknemer
  - Periode en betaaldatum
  - Brutoloon + alle componenten (salaris, toelagen, etc.)
  - Inhoudingen (LH, SV-premies)
  - Netto te betalen
  - Cumulatieve fiscaal loon (fiscaalloon_ytd)
  - Cumulatieve vakantiegeld-reservering (vakantiegeld_ytd)

### Scenario: Archivering

**GIVEN** loonstrook PDF geproduceerd
**WHEN** archief-route opgeroepen
**THEN** moet loonstrook worden opgeslagen in openregister met 7-jarige bewaarplicht
**AND** moet in het personeelsdossier van de werknemer zichtbaar zijn

## Related Entities

- `LoonStrook` (alle velden)
- `Werknemer` (naam, adres, BSN)
- `LoonPeriode` (periodeStart, periodeEind, betaaldatum, status)

## Standards

- BW art. 7:626 — verplichting tot specificatie loon (loonstrook)
- Wet op de loonadministratie 1964 — bewaarplicht
- ETSI EN 319 132 — digitale ondertekening (optioneel)

# REQ-PAY-012: Grootboek automatisch geboekt

## Requirement

Per loonperiode moet automatisch een balanced loonjournaalpost worden geboekt: debet loonkosten, sociale lasten WG, pensioen WG; credit te betalen netto/LH/SV/pensioen.

## Acceptance Criteria

### Scenario: Balanced journaalpost

**GIVEN** loonperiode 2026-05 afgesloten
**WHEN** journaalpost wordt aangemaakt
**THEN** moet record `Loonjournaalpost` aangemaakt worden met:
  - `periodeId` = mei-periode
  - `datum` = periodeEind
  - `regels` array:
    - Rekening 4001 "Brutolonen" DEBET €87.420,00
    - Rekening 4010 "Sociale lasten WG" DEBET €11.213,40
    - Rekening 4020 "Pensioenpremie WG" DEBET €15.880,16
    - Rekening 1610 "Te betalen netto loon" CREDIT €61.240,50
    - Rekening 1620 "Af te dragen LH" CREDIT €18.620,10
    - Rekening 1630 "Af te dragen premies SV+ZVW" CREDIT €11.213,40
    - Rekening 1640 "Af te dragen pensioenpremie" CREDIT €23.439,56
**AND** moet debet-totaal credit-totaal evenaren (€114.513,56 beide)
**AND** moet `balanced` = true zijn
**AND** mag journaalpost direct in GL zichtbaar zijn via openregister GL-interface

## Related Entities

- `Loonjournaalpost` (periodeId, datum, regels[], balanced)
- `Account` (accountNumber, naam) — RGS 3.5 conform

## Standards

- Wet op de loonadministratie 1964 — administratieplicht
- Referentie Grootboek Schema (RGS) 3.5 — standaard rekeningschema

# Spec: bookkeeping-innovatiebox-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-cost-centers-dimensions, bookkeeping-vpb-corporate-tax

## ADDED Requirements

### REQ-IBA-001: The system SHALL declare an `IPAssetValuation` register voor immateriële activa die voor de innovatiebox kwalificeren

Per Wet Vpb art. 12b's innovatiebox mag de winst toerekenbaar aan
zelf-voortgebrachte immateriële activa belast worden tegen 5%. The
`IPAssetValuation` register MUST records declareren met fields:
`assetNaam` (string), `assetType` (enum
`s-en-o-certificaat | octrooi | kwekersrecht |
softwareprogrammatuur | model-tekening`), `wbsoVerklaringNummer`
(string, optional — FK naar S&O-administratie),
`octrooiNummer` (string, optional), `valuationMethod` (enum
`forfaitair | afpelmethode`), `valuationBedrag` (number ≥ 0),
`valuationDate` (date), `applicableTariff` (number, default 0.05),
`vpbBalansLinkId` (FK naar `VpbBalansLink` uit REQ-VPB-002). Per
ADR-031 declaratieve register — geen PHP IP-service.

#### Scenario: Een S&O-certificaat-asset wordt geregistreerd met forfaitaire waardering

- **GIVEN** een Vpb-pligtige administratie met een S&O-certificaat
- **WHEN** een `IPAssetValuation` met `assetType:
  's-en-o-certificaat'`, `valuationMethod: 'forfaitair'`,
  `valuationBedrag: 25000.00`, `applicableTariff: 0.05` wordt
  opgeslagen
- **THEN** de save MUST slagen; **AND** de asset MUST in de
  innovatiebox-administratie aggregation verschijnen (REQ-IBA-003).

### REQ-IBA-002: The system SHALL ondersteunen both forfaitaire and afpelmethode-waardering

Twee waarderingsmethoden MUST ondersteund worden:

- **Forfaitair**: vaste percentage van de winst toerekenbaar aan
  innovatie, met een wettelijk maximum (€25 000 per jaar per
  belastingplichtige onder het 2026 regime; configureerbaar via
  seed data om wettelijke wijzigingen te vangen).
- **Afpelmethode** (toerekeningsmethode): expliciete winsttoerekening
  per IP-asset via een `WinstToerekening` overlay
  (REQ-IBA-004).

The schema MUST de twee methoden onderscheiden via de
`valuationMethod` enum. Per ADR-031 de keuze van methode MUST
declaratief zijn — geen PHP method-selector.

#### Scenario: Forfaitaire waardering respecteert het wettelijke maximum

- **GIVEN** een belastingplichtige met meerdere IP-assets, totaal
  forfaitaire waardering > €25 000
- **WHEN** de innovatiebox-administratie aggregation runs
- **THEN** de toegekende innovatieboxwinst MUST geknipt zijn op
  €25 000; **AND** een audit-trail entry MUST de capping
  motiveren.

### REQ-IBA-003: The system SHALL produce de innovatiebox-administratie als een declaratieve aggregatie

The innovatiebox-administratie MUST een `x-openregister-aggregations`
declaration zijn die per fiscal year + per IP-asset de
toegerekende winst + de 5%-belaste-deel + de afgedragen Vpb-impact
samenvat. De aggregatie MUST de input uit `IPAssetValuation` +
`WinstToerekening` consume'n. Per ADR-031 geen PHP innovatiebox-
service.

#### Scenario: Aggregation produceert de 5%-tarief-impact per asset

- **GIVEN** een IP-asset met €100 000 toegerekende winst en
  `applicableTariff: 0.05` voor 2026
- **WHEN** de innovatiebox aggregation voor 2026 runs
- **THEN** de 5%-belaste-grondslag MUST €100 000 zijn; **AND** de
  Vpb-impact MUST €5 000 (5% van €100 000) zijn (ipv ~€25 000 onder
  het standaardtarief).

### REQ-IBA-004: The system SHALL declare a `WinstToerekening` overlay voor de afpelmethode

Voor `valuationMethod: 'afpelmethode'` MUST een `WinstToerekening`
register beschikbaar zijn met fields: `ipAssetId` (FK naar
`IPAssetValuation`), `periodId` (FK naar `FiscalPeriod`),
`toegerekendeWinst` (number ≥ 0), `verdeelsleutel` (enum
`omzet-aandeel | r-en-d-uren | custom-formula`), `parameters` (JSON
geconditioneerd op `verdeelsleutel`). De verdeelsleutel MUST een
declaratieve calculation zijn op het toegerekende-winst veld.

#### Scenario: Afpelmethode rekent winst per omzet-aandeel toe

- **GIVEN** een IP-asset met `verdeelsleutel: 'omzet-aandeel'`,
  totale omzet €1M, IP-omzet €300K, totale winst €200K
- **WHEN** de toerekening calculation runs voor het period
- **THEN** `toegerekendeWinst` MUST €60K zijn (30% van €200K).

### REQ-IBA-005: De innovatiebox-sectie SHALL in de Vpb-aangifte voorbereiding (uit REQ-VPB-004) verschijnen via een docudesk template

De docudesk template voor de Vpb-aangifte voorbereiding (REQ-VPB-004)
MUST een innovatiebox-sectie includ'en die per IP-asset de
waardering + toerekening + 5%-impact toont. De sectie MUST
generated worden uit de aggregatie van REQ-IBA-003 — geen PHP
sectie-renderer.

#### Scenario: Innovatiebox-sectie verschijnt in de Vpb-aangifte voorbereiding

- **GIVEN** een fiscal year met ≥1 `IPAssetValuation` record
- **WHEN** de Vpb-aangifte voorbereiding gerendered wordt
- **THEN** een innovatiebox-sectie MUST verschijnen met een rij
  per IP-asset; **AND** de totale 5%-belaste-grondslag MUST aan
  de Vpb-aangifte-totaal lijn bijdragen.

### REQ-IBA-006: Innovatiebox-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.mkb-innovatiebox`) declareren onder
`Bookkeeping > Innovatiebox` met `type: index` voor de IP-assets +
`type: detail` per asset (waardering, winsttoerekening,
historische 5%-impact). Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Innovatiebox-menu toggles with the feature flag

- **GIVEN** de manifest declareert `featureFlags.mkb-innovatiebox`
- **WHEN** de flag ON staat
- **THEN** het Innovatiebox-menu MUST verschijnen.
- **WHEN** de flag OFF staat
- **THEN** het menu MUST NOT renderen.

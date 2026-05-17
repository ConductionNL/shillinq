# Spec: bookkeeping-investeringsaftrek

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-fixed-assets-depreciation

## ADDED Requirements

### REQ-INV-001: The system SHALL declare an `InvesteringClassifier` overlay on `FixedAsset` voor de vier aftrekregimes

The `FixedAsset` register (uit T4-base
`bookkeeping-fixed-assets-depreciation`) MUST een overlay
`InvesteringClassifier` krijgen met fields: `fixedAssetId` (FK),
`aftrekType` (enum `kia | eia | mia | vamil`),
`bedrijfsmiddelCode` (string — de RvO bedrijfsmiddel-code zoals op
de EIA/MIA/Vamil-lijsten), `aanvraagDatum` (date, optional —
verplicht voor EIA/MIA/Vamil), `aanvraagNummer` (string, optional
— wordt na RvO-toekenning ingevuld), `toegekendBedrag` (number,
optional — wordt na toekenning ingevuld). Een asset MAY meerdere
classifiers dragen (KIA + MIA cumulatief op hetzelfde
bedrijfsmiddel). Per ADR-031 overlay register — geen PHP
investerings-service.

#### Scenario: Een fixed-asset wordt voor EIA + MIA geklasseerd

- **GIVEN** een `FixedAsset` voor een energiebesparende installatie
- **WHEN** twee `InvesteringClassifier` records (één voor EIA,
  één voor MIA) aangemaakt worden voor de zelfde asset
- **THEN** beide records MUST opslaan; **AND** de aftrek calculatie
  MUST beide regimes toepassen (cumulatief waar toegestaan per RvO-
  regels).

### REQ-INV-002: KIA / EIA / MIA / Vamil aftrek SHALL declaratief berekend worden tegen de jaarlijkse tarieven seed

The aftrek MUST een `x-openregister-calculations` block zijn op
`FixedAsset` die de geseede tarieven (REQ-INV-003) consume'rt en
per asset + per regime de toegestane aftrek berekent. Per ADR-031
geen PHP investerings-calculator (mogelijk uitzondering voor de
KIA-schalen functie als de calculation engine geen lookup-tables
ondersteunt, dan single-method PHP guard per ADR-031 §"PHP guards
remain a legitimate seam").

Berekenings-regels per regime:

- **KIA** (kleinschaligheidsinvesteringsaftrek): forfaitair
  percentage op het totaal-geïnvesteerd-bedrag, met drempel +
  oploop + maximum + afbouwzone (per RvO 2026-schalen).
- **EIA** (energie): 40% (default 2026, configureerbaar via seed)
  op de aanschaf-prijs van energie-bedrijfsmiddelen op de
  Energielijst.
- **MIA** (milieu): 13.5% / 27% / 36% afhankelijk van de
  Milieulijst-categorie A/B/C (configureerbaar).
- **Vamil** (vrije afschrijving): vrije afschrijving tot 75% in
  het eerste jaar voor Milieulijst-assets.

#### Scenario: KIA-aftrek volgt de drempel-oploop-maximum schaal

- **GIVEN** een MKB met €30 000 totaal geïnvesteerd in 2026 (boven
  KIA-drempel, in de oploop-zone)
- **WHEN** de aftrek-calculation runs
- **THEN** de KIA-aftrek MUST overeenkomen met de uit de seed
  geladen 2026-schaal voor €30 000 (tolerantie: €1).

### REQ-INV-003: The system SHALL ship een annual tarieven seed (`investeringsaftrek-tarieven-2026.json`)

Het seed bestand op
`lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` MUST
EUPL-1.2 SPDX in de docblock dragen, een `_meta` block (`source:
'RvO investeringsaftrek-regelingen'`, `year: 2026`), en MUST
bevatten:

- KIA drempel / oploop-zone / maximum / afbouw-zone tarieven 2026.
- EIA percentage 2026 + Energielijst-codes.
- MIA percentages 2026 (per categorie A/B/C) + Milieulijst-codes.
- Vamil-eligible bedrijfsmiddel-codes 2026.

Filename version-pinning MUST een 2027-update naast-elkaar laten
bestaan (`investeringsaftrek-tarieven-2027.json`).

#### Scenario: Seed validates en wordt idempotent geladen

- **GIVEN** een fresh install
- **WHEN** de repair-step runs
- **THEN** de tarieven MUST in de tarieven-tabel verschijnen;
  **AND** re-running MUST geen records dupliceren of operator-
  edits overschrijven.

### REQ-INV-004: The system SHALL produce een RvO aanvraagdossier per aftrek-aanvraag via docudesk

Voor EIA / MIA / Vamil-aanvragen (KIA vereist geen aparte aanvraag)
MUST een docudesk template een aanvraagdossier genereren bevattend:
asset-omschrijving, bedrijfsmiddel-code, aanschaf-prijs,
investeringsdatum, ingebruikname-datum, en bijgevoegde bewijsstukken
(facturen, technische specificaties — by docudesk attachment URI per
ADR-022). De RvO submissie MUST via een openconnector source
(REQ-INV-006).

#### Scenario: EIA aanvraagdossier verschijnt met de bewijsstukken-referenties

- **GIVEN** een `FixedAsset` + `InvesteringClassifier` met
  `aftrekType: 'eia'` + 2 bijgevoegde facturen in docudesk
- **WHEN** de operator de aanvraagdossier-render triggert
- **THEN** een docudesk document MUST verschijnen met de asset-
  velden + URI-referenties naar de 2 facturen.

### REQ-INV-005: Toegekende-bedragen MUST asynchroon ingelezen worden vanuit de RvO mededeling

Na RvO-toekenning MUST de `InvesteringClassifier.toegekendBedrag`
veld via een openconnector source row gevuld worden (RvO mededeling-
endpoint). De update MUST een audit-trail event schrijven met de
RvO-mededeling-id + datum + bedrag.

#### Scenario: Toekenning update logt audit-trail event

- **GIVEN** een EIA-aanvraag in afwachting
- **WHEN** RvO een toekenning teruggeeft via de openconnector
  feed (`toegekendBedrag: €4 000`)
- **THEN** het `InvesteringClassifier` record MUST `toegekendBedrag:
  4000` dragen; **AND** een audit-trail event MUST de mededeling-
  id + datum vastleggen.

### REQ-INV-006: RvO submissie + mededeling-feed SHALL ride openconnector — geen app-local HTTP client

Per ADR-019 MUST elke RvO-call (aanvraag-submissie + mededeling-
ophalen) via een openconnector source row gaan. Shillinq MUST de
source-id referencen vanuit de docudesk template
output-channel-declaratie + de mededeling-poll declaratie. Geen
`lib/Service/RvoClient.php`.

#### Scenario: Geen direct HTTP-client voor RvO

- **GIVEN** de shillinq codebase
- **WHEN** gescand voor direct `Http\Client\IClient` gebruik gericht
  op `rvo.nl`
- **THEN** geen zulke gebruik SHALL bestaan.

### REQ-INV-007: Investeringsaftrek SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.mkb-investeringsaftrek`) declareren onder
`Bookkeeping > Investeringsaftrek` met `type: index` van
classifiers + `type: detail` per classifier (asset-link, aanvraag,
toekenning, aftrek-impact). Per ADR-024 Tier-4, no bespoke Vue
files.

#### Scenario: Investeringsaftrek-menu toggles with the feature flag

- **GIVEN** de manifest declareert
  `featureFlags.mkb-investeringsaftrek`
- **WHEN** de flag ON staat
- **THEN** het menu MUST verschijnen.
- **WHEN** de flag OFF staat
- **THEN** het menu MUST NOT renderen.

# Spec: bookkeeping-wbso-sno-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-cost-centers-dimensions

## ADDED Requirements

### REQ-WBSO-001: The system SHALL declare een `SoProject` register voor S&O-projecten met RvO-link

Per Wet vermindering afdracht loonbelasting hoofdstuk VA (WBSO)
MUST S&O-werk per project + per medewerker geadministreerd worden.
The `SoProject` register MUST records declareren met fields:
`projectNaam` (string), `rvoProjectNummer` (string — RvO-toegekend),
`sEnOCertificaatNummer` (string — RvO-certificaat-id),
`looptijdStart` (date), `looptijdEind` (date), `costCenterId` (FK
naar `CostCenter` uit T4-base), `status` (enum
`aangevraagd | toegekend | afgerond`). Per ADR-031 declaratieve
register — geen PHP S&O-service.

#### Scenario: Een toegekend S&O-project wordt geregistreerd

- **GIVEN** een MKB met een RvO S&O-certificaat 2026/0001
- **WHEN** een `SoProject` met `rvoProjectNummer: '2026/0001'`,
  `status: 'toegekend'` opgeslagen wordt
- **THEN** de save MUST slagen; **AND** het project MUST in de
  uren-administratie referencable zijn (REQ-WBSO-002).

### REQ-WBSO-002: The system SHALL declare een `SoUrenStaat` register voor per-medewerker per-week per-project urenadministratie

The `SoUrenStaat` register MUST records declareren met fields:
`soProjectId` (FK naar `SoProject`), `medewerkerId` (string —
referentie naar Nextcloud user OF naar `Detachering` record uit
REQ-DPA-002), `weekISO` (string in ISO-8601 week-formaat, e.g.
`2026-W14`), `aantalUren` (number ≥ 0, decimaal-toegestaan tot 0.25
uur), `taakOmschrijving` (string), `state` (enum `draft |
goedgekeurd | afgesloten`). Een `x-openregister-lifecycle` MUST de
state-transities `draft → goedgekeurd → afgesloten` declareren met
approval-workflow per ADR-022 op de `goedgekeurd` transitie.

#### Scenario: Een uren-staat moet goedgekeurd worden voor afsluiten

- **GIVEN** een `SoUrenStaat` in `state: 'draft'`
- **WHEN** een operator de `afgesloten` transitie probeert zonder
  via `goedgekeurd` te gaan
- **THEN** de transitie MUST geweigerd worden ("lifecycle
  precondition: state must be goedgekeurd").

### REQ-WBSO-003: The system SHALL produce een RvO mededeling per kwartaal als docudesk document

Per RvO-WBSO-reglement MUST een mededeling van werkelijk gerealiseerde
S&O-uren + loonkosten per kwartaal aan RvO doorgegeven worden. De
mededeling MUST gegenereerd worden als een docudesk document gevuld
uit een `x-openregister-aggregations` block dat `SoUrenStaat` (met
`state ≠ 'draft'`) per kwartaal per project somt. Per ADR-031 geen
PHP mededeling-renderer.

#### Scenario: Mededeling 2026-Q1 sommeert alle goedgekeurde uren

- **GIVEN** 3 S&O-projecten met goedgekeurde uren-staten over
  weken `2026-W01..W13`
- **WHEN** de Q1-mededeling gerendered wordt
- **THEN** het docudesk document MUST per project het totale
  aantal goedgekeurde uren tonen.

### REQ-WBSO-004: The system SHALL produce een RvO kwartaalrapportage + jaarrapport via docudesk

Naast de mededeling MUST een kwartaalrapportage (operationele
voortgang per project) en een jaarrapport (jaarlijkse afsluiting +
resultaten) als docudesk documenten gegenereerd worden uit dezelfde
uren-data. Templates MUST RvO-conform layout volgen; rendering MUST
via docudesk gaan; geen app-local renderer.

#### Scenario: Jaarrapport bundelt alle 4 kwartaalmedede­lingen

- **GIVEN** vier ingediende kwartaalmedede­lingen voor 2026
- **WHEN** het jaarrapport 2026 gerendered wordt
- **THEN** het document MUST de aggregate totalen tonen die
  identiek zijn aan de sum van de vier kwartaalmedede­lingen.

### REQ-WBSO-005: RvO-submissies SHALL ride openconnector sources — geen app-local HTTP

Per ADR-019 MUST elke RvO-submissie (mededeling, kwartaalrapportage,
jaarrapport) via een openconnector source row gaan. Shillinq MUST
de openconnector source-ids referencen vanuit de docudesk template
output-channel-declaratie. Geen `lib/Service/RvoSubmissieClient.php`.

#### Scenario: Mededeling-upload flowt via openconnector

- **GIVEN** een gegenereerd docudesk mededeling-document
- **WHEN** de operator de upload triggert
- **THEN** de transmissie MUST via de openconnector source flowen;
  **AND** de RvO response MUST in de audit-trail-immutable
  vastgelegd worden per ADR-022.

### REQ-WBSO-006: De afdrachtvermindering loonheffing SHALL declaratief berekend worden uit S&O-uren × S&O-uurloon

The afdrachtvermindering loonheffing per loonaangifte-tijdvak MUST
een `x-openregister-calculations` block zijn dat `SoUrenStaat.aantalUren`
× `medewerker.sEnOUurloon` × `actueelAfdrachtPercentage`
(geseed uit RvO 2026, default 32% voor reguliere S&O en 40% voor
starters) berekent. De afdracht is een **projected** waarde —
de RvO mededeling is de **authoritative** waarde gebruikt in de
loonaangifte. Shillinq MUST beide waarden tonen voor reconciliatie.

#### Scenario: Projected en RvO-mededeling verschijnen naast elkaar in de uren-detail-view

- **GIVEN** een Q1 met €40 000 projected afdracht en een RvO
  mededeling die €38 500 teruggeeft
- **WHEN** de WBSO-detail-view voor Q1 rendert
- **THEN** beide bedragen MUST naast elkaar tonen; **AND** een
  reconciliatie-warning MUST de €1 500 delta surfacen voor de
  loonheffing-administratie.

### REQ-WBSO-007: WBSO-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.mkb-wbso`) declareren onder `Bookkeeping > WBSO`
met sub-pages voor Projecten, Uren-staten, Medede­lingen +
Kwartaalrapportages + Jaarrapport, en Afdrachtvermindering. Per
ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: WBSO-menu toggles with the feature flag

- **GIVEN** de manifest declareert `featureFlags.mkb-wbso`
- **WHEN** de flag ON staat
- **THEN** de vier WBSO sub-pages MUST verschijnen.
- **WHEN** de flag OFF staat
- **THEN** het menu MUST NOT renderen.

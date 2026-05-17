# Spec: bookkeeping-detachering-payroll-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB — payroll + detachering)
**Depends on:** bookkeeping-accounts-payable-core

## ADDED Requirements

### REQ-DPA-001: Salarisbureau-imports SHALL via openconnector sources flowen — geen app-local payroll-client

Per ADR-019 + ADR-022 MUST imports vanuit salarisbureaus (ADP,
Loket, Visma, Nmbrs) via openconnector source rows gaan. Shillinq
MUST per salarisbureau een source row in de openconnector-config
declareren (endpoint URL, OAuth2-flow, mapping-target). Geen
`lib/Service/AdpClient.php`, geen `lib/Service/LoketClient.php`,
geen vergelijkbare PHP-clients.

#### Scenario: Reviewer confirms no app-local payroll HTTP clients

- **GIVEN** de shillinq codebase
- **WHEN** gescand voor direct `Http\Client\IClient` gebruik
  gericht op ADP / Loket / Visma / Nmbrs hostnames
- **THEN** geen zulke usage SHALL bestaan.

### REQ-DPA-002: De salaris-feed SHALL materialise als balanced `JournalEntry` records van subtype `loonkosten` per medewerker per loontijdvak

Elke binnenkomende salaris-feed batch MUST per medewerker per
loontijdvak een balanced `JournalEntry` materialiseren — de
journal-entry MUST een gebalanceerde GL-transactie produceren per
T1 REQ-GL-001 (loonkosten DR / nettoloon CR / sociale-premies CR /
loonheffing CR / pensioen CR). De mapping van salarisbureau-feed
naar journal-entry-lijnen MUST een `x-openregister-mappings`
declaration zijn — geen PHP mapper-service.

#### Scenario: ADP-feed batch materialiseert een gebalanceerd journal-entry

- **GIVEN** een ADP-feed batch voor één medewerker over één
  loontijdvak met loonkosten €4 000
- **WHEN** de feed verwerkt wordt
- **THEN** een `JournalEntry` van subtype `loonkosten` MUST
  verschijnen met een gebalanceerde GL-transactie waarbij
  loonkosten-DR = nettoloon-CR + premies-CR + loonheffing-CR +
  pensioen-CR = €4 000.

### REQ-DPA-003: The system SHALL declareren een `OpdrachtgeversVerklaring` register voor Wet DBA opdrachtgeverposities

Per Wet Deregulering Beoordeling Arbeidsrelaties (DBA) MUST de
opdrachtgevers-verklaring per ZZP-opdracht administratief
vastliggen. De `OpdrachtgeversVerklaring` register MUST records
declareren met fields: `zzpId` (string — externe identificatie),
`zzpNaam` (string), `opdrachtBeschrijving` (string),
`looptijdStart` (date), `looptijdEind` (date),
`verklaringStatus` (enum `concept | overeengekomen |
beëindigd`), `modelOvereenkomst` (string — URI naar de gebruikte
Belastingdienst model-overeenkomst, optional),
`verklaringDocumentUri` (string — docudesk attachment URI),
`risicoBeoordeling` (enum `geen | laag | midden | hoog`). Per
ADR-031 declaratief — geen PHP DBA-service.

#### Scenario: Een overeengekomen verklaring met laag risico verschijnt in de DBA-administratie

- **GIVEN** een ZZP-detachering
- **WHEN** een `OpdrachtgeversVerklaring` met
  `verklaringStatus: 'overeengekomen'`, `risicoBeoordeling: 'laag'`
  opgeslagen wordt
- **THEN** de save MUST slagen; **AND** de DBA-administratie view
  MUST de verklaring tonen.

### REQ-DPA-004: The system SHALL produce de standaard opdrachtgeversverklaring als docudesk template

De Belastingdienst standaard opdrachtgeversverklaring MUST als een
docudesk template gerendered worden vanuit `OpdrachtgeversVerklaring`-
velden. De render-flow MUST per ADR-022 docudesk-side renderen —
shillinq declareert alleen het template + de field-bindings.

#### Scenario: Opdrachtgeversverklaring document wordt gegenereerd

- **GIVEN** een `OpdrachtgeversVerklaring` record
- **WHEN** de operator de "genereer document" actie triggert
- **THEN** een docudesk document MUST verschijnen met de
  verklaring-velden ingevuld; **AND** de
  `verklaringDocumentUri` MUST naar het gegenereerde document wijzen.

### REQ-DPA-005: The system SHALL declareren een `IB47Record` register voor de jaarlijkse IB47-formulier opgave aan de Belastingdienst

Voor freelance-opdrachten + andere niet-loonhoudingsplichtige
betalingen MUST jaarlijks (met monthly dry-run optie) een IB47-
formulier opgesteld worden. De `IB47Record` register MUST records
declareren met fields: `belastingjaar` (integer), `opdrachtgeverId`
(FK naar de administratie), `ontvangerNaam` (string),
`ontvangerBSN` (string — versleuteld opgeslagen per RBAC; alleen
de payroll-officer rol mag dit lezen), `ontvangerAdres` (string),
`betalingenTotaal` (number ≥ 0), `betalingTypeCode` (enum per
Belastingdienst IB47-codes). Aggregatie over een belastingjaar MUST
declaratief via `x-openregister-aggregations` per `(belastingjaar,
opdrachtgeverId)`. Per ADR-022 RBAC op personeels-data verplicht.

#### Scenario: IB47 dry-run en finale jaarbatch produceren consistente totalen

- **GIVEN** 12 maandelijkse dry-run-batches over 2026
- **WHEN** de finale jaarbatch voor 2026 gerendered wordt
- **THEN** de finale betalingen-totalen per ontvanger MUST gelijk
  zijn aan de sum van de 12 maandelijkse dry-runs (tolerantie: €0).

### REQ-DPA-006: De IB47-submissie SHALL via een openconnector source naar de Belastingdienst flowen

Per ADR-019 MUST de IB47 jaarbatch-submissie naar de Belastingdienst
via een openconnector source row gaan. De docudesk template MUST
de IB47-formulier in het door de Belastingdienst vereiste formaat
(per de IB47-XML-schema 2026) renderen. Shillinq references de
openconnector source by id vanuit de docudesk template output-channel.

#### Scenario: IB47 batch transmissie logt audit-event

- **GIVEN** een complete IB47-jaarbatch voor 2026
- **WHEN** de operator de Belastingdienst-submissie triggert
- **THEN** de payload MUST via de openconnector source flowen;
  **AND** een audit-trail event MUST de submissie-hash + response-
  status vastleggen per ADR-022.

### REQ-DPA-007: Detachering + payroll-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.mkb-detachering`) declareren onder
`Bookkeeping > Detachering en payroll` met sub-pages voor
Salaris-feeds, Opdrachtgevers-verklaringen + DBA-administratie, en
IB47-jaarbatch. Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Detachering-menu toggles with the feature flag

- **GIVEN** de manifest declareert `featureFlags.mkb-detachering`
- **WHEN** de flag ON staat
- **THEN** de drie sub-pages MUST verschijnen.
- **WHEN** de flag OFF staat
- **THEN** het menu MUST NOT renderen.

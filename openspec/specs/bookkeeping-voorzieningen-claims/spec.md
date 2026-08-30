---
status: done
---

# Specs — IAS 37 / RJ 252 Provisions, Contingent Liabilities and Contingent Assets

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (regulatory + compliance)  
**Depends on:** bookkeeping-general-ledger, bookkeeping-chart-of-accounts,
bookkeeping-financial-statements, bookkeeping-pension-ias19, bookkeeping-deferred-tax

@e2e exclude pure backend/compliance: IAS 37 / RJ 252 provision recognition,
measurement, roll-forward and disclosure are schema + lifecycle + aggregation
metadata — not browser-testable

## Purpose

This specification defines the requirements for IAS 37 / RJ 252 provisions, contingent liabilities and contingent assets in the Shillinq Nextcloud accounting application, establishing recognition, measurement, roll-forward and disclosure behaviour.

## Requirements

### Requirement: REQ-PROV-001 Three-criteria-toets bij opname (IAS 37 §35–37 / RJ 252 §301–305)

The system SHALL satisfy this requirement: enforce the three-criteria recognition test for provisions.

Het systeem MOET bij elke nieuwe `provision` afdwingen dat alle drie de
IAS 37 / RJ 252 criteria expliciet worden onderbouwd: bestaande verplichting uit
verleden gebeurtenis, waarschijnlijke uitstroom (> 50%), en betrouwbare schatting.

#### Scenario: Voorziening kan niet worden opgenomen zonder onderbouwing

- **GIVEN** een poging tot opname van een herstructureringsvoorziening van
  EUR 1,2M
- **WHEN** `recognitionRationale`, `obligatingEvent`, of
  `probabilityOfOutflow` ontbreekt óf `probabilityOfOutflow ≤ 0.5`
- **THEN** weigert het systeem de opname met foutmelding "IAS 37 / RJ 252 criteria
  niet voldaan: [specifiek criterium]"
- **AND** wordt een suggestie gedaan om de verplichting als `contingent-liability`
  op te nemen indien probability tussen 0.05 en 0.5 ligt

### Requirement: REQ-PROV-002 Best-estimate met sensitivity bandbreedte (IAS 37 §39 / RJ 252 §306)

The system SHALL satisfy this requirement: record a best estimate with a low-high sensitivity range for each provision.

Elke `provision` MOET een best-estimate hebben PLUS een lage en hoge
schattingsgrens; voor materiële voorzieningen MOET de sensitivity in de toelichting
verschijnen.

#### Scenario: Milieuvoorziening met EUR 800K best-estimate

- **GIVEN** een bodemsaneringsverplichting met expert-rapport: lage schatting
  EUR 600K, beste EUR 800K, hoge EUR 1,4M
- **WHEN** de voorziening wordt opgenomen
- **THEN** wordt `bestEstimate=800000`, `rangeLow=600000`, `rangeHigh=1400000`
  opgeslagen
- **AND** verschijnt in de jaarrekeningtoelichting een zin "Het geschatte bedrag
  ligt tussen EUR 0,6M en EUR 1,4M; beste schatting EUR 0,8M, gebaseerd op rapport
  van [expert] d.d. [datum]"

### Requirement: REQ-PROV-003 Disconteringsvoet bij materiële tijdshorizon (IAS 37 §45 / RJ 252 §310)

The system SHALL satisfy this requirement: apply a discount rate to provisions with a material time horizon.

Voor voorzieningen waarvan een materieel deel van de uitstroom > 1 jaar in de
toekomst ligt MOET het systeem een disconteringsvoet toepassen die het
tijdseffect en risico's specifiek voor de verplichting weerspiegelt.

#### Scenario: Ontmantelingsvoorziening met 10-jaars horizon

- **GIVEN** een ontmantelingsverplichting van EUR 2M die over 10 jaar zal
  worden uitgevoerd
- **AND** een risk-free rate van 2,5% (10-jaars Nederlandse staatsobligatie)
  plus 0,5% risico-opslag
- **WHEN** de voorziening wordt opgenomen
- **THEN** wordt `discountRateApplied=3.0%`, `discountedValue=1488000`
  (EUR 2M / 1.03^10)
- **AND** wordt jaarlijks via `unwindingOfDiscount` de rente bijgeboekt
  (EUR 44.640 in jaar 1: 1.488K × 3%) ten laste van financiële lasten

### Requirement: REQ-PROV-004 Mutatieoverzicht per voorziening per jaar (IAS 37 §84)

The system SHALL satisfy this requirement: produce a per-provision annual roll-forward movement schedule.

Het systeem MOET per voorziening per periode een complete mutatie tonen volgens
de structuur opening → dotatie → onttrekking → vrijval → discontering-unwinding →
schattingswijziging → koersverschillen → sluiting, conform IAS 37 §84.

#### Scenario: Garantievoorziening jaarmutatie 2026

- **GIVEN** een openingsbalans garantievoorziening EUR 320K per 1-1-2026
- **WHEN** de jaarmutatie wordt opgesteld voor periode 2026
- **THEN** toont `provision-movement`: opening EUR 320K + additions EUR 180K
  (dotatie 1,5% over omzet EUR 12M) − used EUR 95K (uitgevoerde
  garantie-reparaties) − released EUR 25K (vrijval, oude garantieperiode verstreken)
  = sluiting EUR 380K
- **AND** zijn de bewegingen elk gekoppeld aan onderliggende `linkedJournalEntries`

### Requirement: REQ-PROV-005 Herstructureringsvoorziening met gedetailleerd plan (IAS 37 §72–83 / RJ 252 §327–336)

The system SHALL satisfy this requirement: allow a restructuring provision only with a detailed communicated plan.

Een herstructureringsvoorziening MAG alleen worden opgenomen als op balansdatum
een gedetailleerd reorganisatieplan bestaat dat geldige expectations bij
betrokken partijen heeft gewekt; het systeem MOET de planonderdelen registreren
en blokkeren bij ontbreken.

#### Scenario: Voorziening reorganisatie 2026 geweigerd zonder plan

- **GIVEN** een poging tot opname van een herstructureringsvoorziening EUR 850K
  voor sluiting vestiging Eindhoven
- **WHEN** de gebruiker geen `detailedPlanDate` op of vóór balansdatum invult,
  of geen `planCommunicatedTo` (affected parties) specificeren
- **THEN** weigert het systeem de opname en toont "Herstructureringsvoorziening
  vereist gedetailleerd plan dat op balansdatum is gecommuniceerd aan getroffen
  partijen (IAS 37 §72-83 / RJ 252.327-336)"
- **AND** wordt suggestie gedaan om de verwachte sluiting als
  `contingent-liability` met `probabilityCategory=possible` op te nemen

### Requirement: REQ-PROV-006 Claims-voorziening met legal-opinion-onderbouwing (IAS 37 §37)

The system SHALL satisfy this requirement: require a confidential legal-advice memo for claims and disputes provisions.

Voor `claims-en-geschillen`-voorzieningen MOET het systeem een vertrouwelijke
`legalAdviceMemo` als file-attachment vereisen met de juridische inschatting van
waarschijnlijkheid en bedrag.

#### Scenario: Productaansprakelijkheidsclaim van EUR 1,5M

- **GIVEN** een lopende rechtszaak waarin EUR 1,5M wordt geclaimd en advocaat
  schat 60% kans op uitkering met verwachte EUR 700K
- **WHEN** de claims-voorziening wordt opgenomen
- **THEN** wordt `bestEstimate=700000`, `amountClaimed=1500000`,
  `probabilityOfOutflow=0.6` opgeslagen
- **AND** is `legalAdviceMemo` verplicht; zonder file weigert het systeem opname
- **AND** wordt het memo onder restricted access opgeslagen (alleen CFO, audit
  committee, accountant) ter bescherming van legal privilege

### Requirement: REQ-PROV-007 Onderscheid voorziening versus contingent liability (IAS 37 §27–30 / RJ 252 §297–300)

The system SHALL satisfy this requirement: distinguish provisions from contingent liabilities based on outflow probability.

Het systeem MOET op basis van `probabilityOfOutflow` automatisch een voorgesteld
pad uitstippelen: > 0.5 = voorziening op balans; 0.05-0.5 = contingent liability
in toelichting; < 0.05 = remote, geen disclosure.

#### Scenario: Belastinggeschil met 30% kans op aanslag

- **GIVEN** een fiscaal geschil met aanslag EUR 400K en 30% kans op handhaving
  in beroep
- **WHEN** de financieel manager de verplichting wil registreren
- **THEN** voorstelt het systeem `contingent-liability` met
  `probabilityCategory=possible`, niet een voorziening
- **AND** verschijnt het bedrag in de toelichting "niet uit de balans blijkende
  verplichtingen" met de beschrijving en geschatte uitkomst

### Requirement: REQ-PROV-008 Aansluiting met jaarrekening-toelichting (IAS 37 §85 / RJ 252 §408)

The system SHALL satisfy this requirement: reconcile per-provision-type aggregates with the financial-statement disclosure note.

Het systeem MOET per voorziening-type een geaggregeerd overzicht produceren dat
één-op-één aansluit met de toelichting "Voorzieningen" in de jaarrekening.

#### Scenario: Voorzieningentoelichting voor jaarrekening 2026

- **GIVEN** zes actieve voorzieningen verdeeld over 4 types: pensioen EUR 1,2M,
  jubileum EUR 220K, garantie EUR 380K, milieu EUR 800K
- **WHEN** de jaarrekening-toelichting wordt samengesteld
- **THEN** verschijnt een tabel per type met opening, dotatie, onttrekking,
  vrijval, discontering-unwinding, sluiting
- **AND** is voor elke materiële voorziening (> EUR 100K of > 1% balans) een
  narratieve toelichting opgenomen met aard, timing, onzekerheid en sensitivity
- **AND** is de som van alle voorzieningen aansluitend met `linkedAccount` saldi
  op de balans

### Requirement: REQ-PROV-009 Jaarlijkse herwaardering met schattingswijzigingen (IAS 8 / RJ 252 §311)

The system SHALL satisfy this requirement: remeasure each active provision at every reporting date with prospective estimate changes.

Het systeem MOET op elke balansdatum elke actieve voorziening laten herwaarderen
op basis van actuele informatie; schattingswijzigingen worden conform IAS 8
prospectief verwerkt via `effectOfChangeInEstimate`.

#### Scenario: Garantievoorziening verhoogd door slechte productserie

- **GIVEN** een garantievoorziening van EUR 380K per 1-1-2026 op basis van
  1,5% historische claim-rate
- **AND** een eind-2026 herziening waaruit blijkt dat een productserie 2025 een
  4% claim-rate vertoont, leidend tot verhoogde verwachte uitstroom EUR 540K
- **WHEN** de jaarlijkse herwaardering draait
- **THEN** wordt `effectOfChangeInEstimate=+160000` opgenomen in de mutatie 2026
- **AND** wordt deze schattingswijziging in de toelichting expliciet vermeld

### Requirement: REQ-PROV-010 Audit-trail en peer-review (IAS 37 §85c / RJ 252 §415)

The system SHALL satisfy this requirement: maintain an audit trail and require peer review for every provision.

Het systeem MOET voor elke voorziening minimaal één peer-reviewer (andere persoon
dan de opnemer) registreren en de review-datum vastleggen; voor materiële
voorzieningen (> EUR 100K of > 1% balanstotaal) is goedkeuring door CFO of audit
committee vereist.

#### Scenario: Milieuvoorziening EUR 800K vereist CFO-akkoord

- **GIVEN** een nieuwe milieuvoorziening van EUR 800K (materieel)
- **WHEN** de controller de voorziening wil opnemen
- **THEN** vraagt het systeem een peer-reviewer en een aanvullende CFO-goedkeuring
- **AND** wordt pas na beide goedkeuringen de status `active`
- **AND** blijft een audit-trail met wie wanneer welke schatting heeft gewijzigd

### Requirement: REQ-PROV-011 Jubileumvoorziening volgens CAO-bepalingen (IAS 19 / RJ 252 §360)

The system SHALL satisfy this requirement: base long-service-award provisions on the applicable collective labour agreement.

Jubileumvoorzieningenen voor de 25- en 40-jaars uitkeringen moeten worden
gebaseerd op de geldende CAO per branche en artikel.

#### Scenario: Jubileumvoorziening 25/40 jaar per CAO Metaal & Techniek

- **GIVEN** een onderneming in de metaal- en techniek-industrie met 145 medewerkers,
  gemiddeld 18 dienstjaren
- **WHEN** jubileumvoorziening wordt opgenomen
- **THEN** wordt `caoReference="CAO Metaal & Techniek art. 8.3"` vastgelegd
- **AND** worden `eligibleEmployees=145`, `averageServiceYears=18`,
  `probabilityOfReachingMilestone=0.75` (turnover-gecorrigeerd) ingevuld
- **AND** volgt de best-estimate uit een actuariële berekening (simpel: eligible
  × accrual-rate × avg-salary) of extern actuaris-rapport

### Requirement: REQ-PROV-012 Garantievoorziening op basis van historische claimrate (IAS 37 §39)

The system SHALL satisfy this requirement: determine warranty provisions from historical claim rates per product category.

Garantievoorzieningenen worden bepaald op basis van historische claim-rates en
verwachte uitstromen per productcategorie.

#### Scenario: Garantievoorziening op basis van 1,5% historische claimrate

- **GIVEN** een onderneming met EUR 12M omzet goederen in 2026, waarvan historische
  claim-rate 1,5% (gebaseerd op 5-jaar gemiddelde)
- **WHEN** garantievoorziening wordt opgenomen
- **THEN** wordt `revenueBaseInPeriod=12000000`, `historicalClaimRate=0.015`
  vastgelegd
- **AND** beste schatting = EUR 12M × 1,5% = EUR 180K
- **AND** rangeLow / rangeHigh bepaald per variantie-analyse (1.0%–2.5% worst-case
  per productkwaliteit)

### Requirement: REQ-PROV-013 Milieuvoorziening onder Wet Bodembescherming (Wbb)

The system SHALL satisfy this requirement: ensure environmental provisions comply with the applicable soil-protection regulations.

Milieuvoorzieningenen voor bodembescherming, asbestverwijdering en
ontmantelingsverplichtingen moeten voldoen aan regelgeving (Wbb, Wm, EU IED).

#### Scenario: Bodemsaneringsvoorziening Wbb artikel X

- **GIVEN** een locatie met geregistreerde bodemverontreiniging per
  Wet Bodembescherming
- **WHEN** milieuvoorziening wordt opgenomen
- **THEN** wordt `regulatoryFramework="Wbb"`, `contaminationLocation="Rijnmond
  facility"`, `expertConsultant="Bureau Milieutechniek B.V."`,
  `legallyRequiredCompletionDate="2026-12-31"` vastgelegd
- **AND** best-estimate gebaseerd op expert-rapport (fase 2 of 3 onderzoek +
  saneringsplan)
- **AND** ontmanteling-verplichting als component aan MVA geactiveerd (per IAS 16
  §16(c))

### Requirement: REQ-PROV-014 Pensioenvoorziening eigen beheer (IAS 19 / RJ 252 §341–370)

The system SHALL satisfy this requirement: measure self-administered pension provisions in accordance with IAS 19.

Pensioenvoorzieningenen voor eigen-beheer pensioenen (collectief of individueel)
moeten volgens IAS 19 / RJ 271 worden gemeten.

#### Scenario: Pensioenvoorziening eigen-beheer DB regeling

- **GIVEN** een onderneming met eigen-beheer DB-pensioenregeling (10 actieve
  deelnemers)
- **WHEN** pensioenvoorziening wordt opgenomen
- **THEN** wordt `provisionType=pensioen`, `pensionScheme="DB eigen beheer"`
  vastgelegd
- **AND** verwijzing naar actuariële rapport (separate `bookkeeping-pension-ias19`
  spec handelt DBO-berekening af)
- **AND** disconteringsvoet per IAS 19 market-based (AA corporates)

### Requirement: REQ-PROV-015 Contingent liability disclosure (IAS 37 §84–85 / RJ 252 §407–408)

The system SHALL satisfy this requirement: disclose low-probability obligations as contingent liabilities in the notes.

Verplichtingen waarvan de waarschijnlijkheid laag is (5%–50%) of bedrag onbetrouwbaar,
worden opgenomen als contingent liability in toelichting.

#### Scenario: Fiscaal geschil contingent liability

- **GIVEN** een fiscaal geschil met 30% kans op handhaving, bedrag EUR 400K
- **WHEN** contingent-liability wordt opgenomen
- **THEN** wordt `probabilityCategory="possible"`, `estimatedAmount=400000`
  opgeslagen
- **AND** narratieve beschrijving opgenomen in jaarrekening-toelichting "Niet uit
  de balans blijkende verplichtingen"
- **AND** geen impact op balanstotaal, wel disclosure in notes

### Requirement: REQ-PROV-016 Linked journal entries voor audit trail (GL integration)

The system SHALL satisfy this requirement: link every provision movement to underlying general-ledger journal entries.

Elke `provision` dotatie, onttrekking, en discontering-unwinding moet gekoppeld
zijn aan onderliggende journaalposten in het GL voor volledige auditability.

#### Scenario: Milieuvoorziening EUR 800K → GL posting

- **GIVEN** milieuvoorziening EUR 800K opgenomen per 31-12-2026
- **WHEN** bestuur goedkeurt opname en provision-movement record wordt gesloten
- **THEN** worden twee GL entries gegenereerd:
  - DR Account 4900 (milieulasten) EUR 800K
  - CR Account 1901 (voorziening milieu) EUR 800K
- **AND** beide entries linked in `provision-movement.linkedJournalEntries`
- **AND** audit trail toont wie, wanneer, waarom deze entries zijn geboekt

### Requirement: REQ-PROV-017 Discontering unwinding als jaarlijkse rente

The system SHALL satisfy this requirement: calculate and post the annual unwinding-of-discount interest automatically.

Jaarlijks moet het systeem de rente-component van disconteringsvoet automatisch
berekenen en bijboeken.

#### Scenario: Ontmantelings-unwinding in jaar 1

- **GIVEN** ontmantelingsverplichting EUR 2M, discountedValue EUR 1.488M per
  31-12-2025, disconteringsvoet 3%
- **WHEN** jaar 2026 wordt gesloten
- **THEN** unwindingOfDiscount = EUR 1.488M × 3% = EUR 44.640
- **AND** wordt GL-posting gegenereerd: DR 6900 (rente/financiële lasten) EUR
  44.640; CR Account 1901 (voorziening) EUR 44.640
- **AND** discountedValue mutatie: EUR 1.488M + EUR 44.640 = EUR 1.532.640

### Requirement: REQ-PROV-018 Materiaalsdrempel voor peer review

The system SHALL satisfy this requirement: require peer review and CFO approval for material provisions above the threshold.

Voorzieningen groter dan EUR 100K of groter dan 1% van balanstotaal vereisen
peer-review en CFO-goedkeuring voordat status=active.

#### Scenario: Grote milieuvoorziening goedkeuringscyclus

- **GIVEN** milieuvoorziening EUR 800K (materieel: > 1% van EUR 70M balans)
- **WHEN** controller probeert status op active te zetten zonder goedkeuring
- **THEN** weigert systeem en vraagt peer-reviewer en CFO-akkoord
- **AND** zichtbare workflow-stap in controller-dashboard
- **AND** audit trail registreert review date en approver

### Requirement: REQ-PROV-019 Schattingswijziging prospectief per IAS 8

The system SHALL satisfy this requirement: process changes in estimate prospectively in accordance with IAS 8.

Wijzigingen in schatting van bestaande voorzieningen moeten prospectief worden
verwerkt (in huidige periode en verder), niet retroactief.

#### Scenario: Garantievoorziening herziening mid-year

- **GIVEN** garantievoorziening EUR 320K eind 2025, revisie in juni 2026 stelt
  worst-case EUR 540K vast
- **WHEN** schattingswijziging wordt geregistreerd
- **THEN** wordt `effectOfChangeInEstimate=+220000` geregistreerd in
  provision-movement juni 2026
- **AND** er is geen retroactieve aanpassing van januari–mei 2026 mutaties
- **AND** disclosure in jaarrekening vermeldt schattingswijziging en grondslag

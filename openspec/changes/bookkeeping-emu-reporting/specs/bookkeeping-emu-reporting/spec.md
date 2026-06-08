# Spec: EMU-saldo & EMU-schuld Reporting

**Scope:** bookkeeping-emu-reporting
**Tier:** T2 — capability
**Status:** draft
**Applies to:** Shillinq

## Overview

Automated reporting pipeline for Dutch decentrale overheden under Wet Houdbare Overheidsfinanciën (Wet Hof). Converts BBV accrual-basis general ledger to cash-basis EMU-saldo via macro-rules (Wet Hof art. 3) and transaction-level adjustments. Generates quarterly EMU-saldo aangifte (kwartaalenquête) and annual bruto EMU-schuld position. Includes automatic CBS XBRL indiening (via openconnector), reconciliation with BBV jaarrekening, and afwijkingsalert on referentiewaarde overschrijding.

## Data Model

### EMUReport

Ingediende of in-progress aangifte voor een periode (kwartaal of jaar). Atomic versioned submission unit.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unieke aangifte ID (emu-YYYY-Qx-org-RSIN) |
| rapporterendeOrganisatie | object | Yes | RSIN, gemeentecode, naam, soort (gemeente/provincie/waterschap/GR) |
| periode | object | Yes | jaar, kwartaal, type (kwartaal-emu-saldo / jaar-emu-saldo / jaar-emu-schuld) |
| status | enum | Yes | concept / ingediend / herzien (per Wet Hof art. 10) |
| indieningsdatum | datetime | No | Timestamp indiening bij CBS |
| cbsBevestigingsnummer | string | No | CBS confirmation reference |
| emuSaldo | object | Yes | berekend (EUR), begroot (EUR), afwijking (EUR), afwijkingPercentage, valuta |
| emuSchuldUltimo | object | Yes | bruto (nominaal EUR), wettelijkeNorm (EUR), ruimte (EUR) |
| bbvAansluiting | object | Yes | saldoBatenLasten (BBV jaarrekening), totaleAdjustments, aansluitingscontrole (geslaagd/mislukt) |
| toelichting | string | No | Concerncontroller notes on significant deviations |
| createdAt | datetime | Yes | Concept generated date |
| updatedAt | datetime | Yes | Last modification date |
| createdBy | string | Yes | User ID (scheduler or manual) |
| lastModifiedBy | string | No | Concerncontroller edit timestamp |

**Relations:**
- → EMUAdjustment (one-to-many)
- → CashFlowItem (one-to-many)
- → DebtPosition (one-to-many)

### EMUAdjustment

Individuele accrual→kas correctie gekoppeld aan grootboekmutatie of macroregel. Traceerbaar per adjustment per EMU-saldo.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unieke adjustment ID (adj-YYYY-Qx-NNNNN) |
| reportId | string | Yes | FK to EMUReport |
| type | enum | Yes | eliminatie-afschrijving / eliminatie-voorzieningdotatie / eliminatie-onttrekking-reserve / toevoeging-bruto-investering / toevoeging-aflossing / eliminatie-boekwinst-desinvestering / correctie-transactiemoment / intercompany-eliminatie |
| richting | enum | Yes | saldo-verhogend / saldo-verlagend / saldo-neutraal |
| bedrag | number | Yes | Adjustment amount (EUR) |
| bron | object | Yes | grootboekrekening, omschrijving, taakveld, taakveldNaam, programma (if GL-sourced) |
| regel | string | Yes | Legal basis (e.g., "Wet Hof art. 3 lid 2") |
| toelichting | string | No | Free-text explanation (business context) |
| createdAt | datetime | Yes | Auto-generated from GL or user-created |
| createdBy | string | Yes | User ID or "scheduler" |

**Relations:**
- → EMUReport (many-to-one)
- → GLLine (many-to-one, optional)

### CashFlowItem

Kasstroomregel geclassificeerd naar IV3 (hoofdstuk-functie-categorie). Shared entity with bookkeeping-iv3-reporting; filtered for kas-basis in EMU.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unieke item ID (cf-YYYY-Qx-NNNNN) |
| reportId | string | Yes | FK to EMUReport |
| datum | date | Yes | Transaction date (kasmoment) |
| bedrag | number | Yes | Cash amount (EUR, negative = outflow) |
| iv3 | object | Yes | hoofdstuk, hoofdstukNaam, functie, functieNaam, categorie, categorieNaam (per IV3-taxonomie) |
| taakveld | string | No | Taakveld code (e.g., 4.2) per BBV-indeling |
| tegenrekening | object | No | soort (leverancier/klant/begunstigde), naam, nummer (factuurnummer/IBAN) |
| kasOfTransactiebasis | enum | Yes | kas / transactie (transaction-date vs cash-date reconciliation) |
| betaalmoment | datetime | Yes | Actual cash transaction timestamp |
| factuurmoment | datetime | No | Invoice date (if different from cash date) |

**Relations:**
- → EMUReport (many-to-one)
- → Account (many-to-one)

### DebtPosition

Uitstaande schuld per instrument per peildatum (kwartaal-ultimo of jaar-ultimo). Bruto bedrag nominaal per ESA2010.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unieke positie ID (debt-YYYY-Qx-SSS-NNNN) |
| reportId | string | Yes | FK to EMUReport |
| peildatum | date | Yes | Measurement date (kwartaaleinde of jaarultimo) |
| instrument | enum | Yes | vaste-geldlening / obligatie / kasgeldlening / schatkistbankieren-rekeningcourant / crediteurensaldo-1j+ / derivaten-passief / voorziening-juridisch |
| tegenpartij | object | Yes | naam, soort (sector-S122-bank / sector-S11-nfv / sector-S13-government), consolidatieEMU (extern / intern-S1313 / internal-entity) |
| hoofdsomOorspronkelijk | number | Yes | Original principal (EUR) |
| uitstaandeSchuld | number | Yes | Outstanding balance nominaal (EUR) |
| rentevoet | number | No | Annual interest rate (%) |
| rentevorm | enum | No | vast / variabel |
| looptijdJaren | number | No | Original term (years) |
| einddatum | date | No | Maturity date |
| telt_mee_in_EMU_schuld | boolean | Yes | ESA2010 classificatie: AF.2/3/4 = true, AF.7 (derivaten) = false |
| categorie_eurostat | string | Yes | AF.2-deposits / AF.3-securities / AF.4-loans / AF.7-derivatives / overig |
| createdAt | datetime | Yes | Record creation |
| updatedAt | datetime | Yes | Last update (e.g., from schatkistbankieren sync) |

**Relations:**
- → EMUReport (many-to-one)

## ADDED Requirements

### Requirement: REQ-EMU-001 Kwartaal-EMU-saldo aangifte produceren

The system SHALL automatically generate a quarterly EMU-saldo concept-aangifte within 5 working days of quarter-end, derived from the BBV-grootboek for the period.

Het systeem MOET per kwartaal automatisch een conceptaangifte EMU-saldo genereren binnen 5 werkdagen na het einde van het kwartaal, op basis van het BBV-grootboek over de betreffende periode.

#### Scenario: Concept-aangifte Q2 verschijnt op 5 juli

- **GIVEN** een gemeente met een bijgewerkt grootboek tot en met 30 juni 2026
- **WHEN** de scheduler op 5 juli 2026 om 06:00 draait
- **THEN** is er een `EMUReport` aangemaakt met `periode.kwartaal=2`, `periode.jaar=2026`, `status="concept"`
- **AND** zijn alle relevante grootboekmutaties van Q2 geclassificeerd als `CashFlowItem` of `EMUAdjustment`
- **AND** is `emuSaldo.berekend` ingevuld
- **AND** is een notificatie verzonden naar de concerncontroller

#### Scenario: Heropenen na BBV-naverwerking

- **GIVEN** een ingediende EMU-rapportage Q1 met `status="ingediend"`
- **WHEN** een grootboekmutatie met `boekdatum` in Q1 alsnog wordt verwerkt (bijv. memoriaalboeking voorgaand boekjaar)
- **THEN** registreert het systeem een `EMURevisie` met het verschil
- **AND** wordt de gebruiker gevraagd of een correctieaangifte moet worden voorbereid

### Requirement: REQ-EMU-002 Accrual-naar-kas conversie volgens Wet Hof

The system SHALL correctly convert the BBV saldo of baten and lasten into the EMU-kassaldo by applying the adjustments mandated by Wet Hof art. 3 and the CBS-instructie EMU-enquête. Every adjustment SHALL be traceable to its source GL line or the applied macro-rule.

Het systeem MOET het BBV saldo van baten en lasten correct converteren naar EMU-kassaldo door de in artikel 3 Wet Hof en de CBS-instructie EMU-enquête voorgeschreven adjustments toe te passen. Elke adjustment MOET traceerbaar zijn naar de bron-grootboekmutatie of de toegepaste macroregel.

#### Scenario: Afschrijving wordt geëlimineerd, investering wordt toegevoegd

- **GIVEN** een BBV-jaarrekening met EUR 5,2M afschrijvingslast en EUR 8,7M bruto investeringen MVA
- **WHEN** het systeem het EMU-saldo berekent
- **THEN** wordt EUR 5,2M opgeteld bij het saldo baten/lasten als `eliminatie-afschrijving`
- **AND** wordt EUR 8,7M afgetrokken als `toevoeging-bruto-investering`
- **AND** is het netto effect op het EMU-saldo EUR −3,5M t.o.v. BBV-saldo

#### Scenario: Voorzieningendotatie pensioen wethouders

- **GIVEN** een dotatie van EUR 450K aan voorziening pensioenverplichtingen wethouders zonder kasuitstroom
- **WHEN** de conversie draait
- **THEN** wordt deze dotatie geëlimineerd via `eliminatie-voorzieningdotatie` (saldo-verhogend)
- **AND** registreert het systeem dat een eventuele toekomstige uitkering aan oud-wethouders alsnog als kasuitgave het EMU-saldo verlaagt

### Requirement: REQ-EMU-003 EMU-saldo per CBS-template

The computed EMU-saldo SHALL be presented in the exact CBS-enquête EMU template (kwartaalenquête overheidsfinanciën decentrale overheden), including all 10 verplichte tussenregels.

Het berekende EMU-saldo MOET worden gepresenteerd in het exacte format van de CBS-enquête EMU (kwartaalenquête overheidsfinanciën decentrale overheden), inclusief alle verplichte tussenregels.

#### Scenario: Indeling volgt CBS-template kwartaal-EMU 2026

- **GIVEN** een berekend EMU-saldo
- **WHEN** de gebruiker de aangifte exporteert
- **THEN** bevat de export de regels: 1) saldo baten en lasten BBV, 2) mutatie reserves, 3) bruto investeringen MVA, 4) bijdragen van derden in investeringen, 5) desinvesteringen, 6) afschrijvingen, 7) dotaties voorzieningen ten laste exploitatie, 8) onttrekkingen voorzieningen via exploitatie, 9) boekwinst/verlies desinvesteringen, 10) EMU-saldo
- **AND** is elke regel onderbouwd met de onderliggende `EMUAdjustment` records

### Requirement: REQ-EMU-004 EMU-schuld berekenen volgens Eurostat ESA2010

The system SHALL compute the bruto EMU-schuld per Eurostat ESA2010: every outstanding debt in AF.2 (deposits, only when schatkistbankieren is negative), AF.3 (securities) and AF.4 (loans) at nominal value, ultimo periode.

Het systeem MOET de bruto EMU-schuld berekenen conform Eurostat ESA2010 classificatie: alle uitstaande schuld in de categorieën AF.2 (deposito's, alleen indien negatief schatkistbankieren), AF.3 (obligaties en overige effecten) en AF.4 (leningen) tegen nominale waarde, ultimo periode.

#### Scenario: Schatkistbankieren rekening-courant negatief

- **GIVEN** een gemeente met op 30 juni 2026 een negatief saldo schatkistbankieren van EUR 2,1M (rood staan)
- **WHEN** EMU-schuld wordt berekend
- **THEN** telt deze EUR 2,1M mee als AF.2-deposito-passief
- **AND** verschijnt het op de `DebtPosition` lijst met `instrument="schatkistbankieren-rekeningcourant"`

#### Scenario: Derivaten tellen niet mee

- **GIVEN** een renteswap met negatieve marktwaarde EUR 800K
- **WHEN** EMU-schuld wordt berekend
- **THEN** telt deze swap NIET mee in de bruto EMU-schuld (ESA2010: derivaten zijn AF.7, niet AF.2/3/4)
- **AND** wordt dit wel apart gerapporteerd voor transparantie

### Requirement: REQ-EMU-005 Intercompany-eliminatie voor gemeenschappelijke regelingen

For gemeenschappelijke regelingen (GR) and verbonden partijen in sector S.1313 (lokale overheid), the system SHALL eliminate inter-entity transactions and debt positions to prevent double-counting in the geconsolideerde EMU-rapportage.

Bij gemeenschappelijke regelingen (GR) en verbonden partijen die binnen de overheidssector S.1313 (lokale overheid) vallen, MOET het systeem onderlinge transacties en schuldposities elimineren om dubbeltelling in geconsolideerde EMU-rapportage te voorkomen.

#### Scenario: Bijdrage aan Veiligheidsregio wordt geëlimineerd op koepelniveau

- **GIVEN** Gemeente Voorbeeldam betaalt EUR 3,4M bijdrage aan Veiligheidsregio Brabant-Zuid (een GR binnen sector S.1313)
- **WHEN** de geconsolideerde EMU-rapportage van de regio wordt opgesteld
- **THEN** wordt deze bijdrage geëlimineerd: bij de gemeente verschijnt het als `intercompany-eliminatie` saldo-verhogend, bij de VR als saldo-verlagend, netto effect S.1313 = nul
- **AND** wordt de eliminatie gemarkeerd met `tegenpartij.consolidatieEMU="intern-S1313"`

### Requirement: REQ-EMU-006 Automatische CBS XBRL-indiening

The system SHALL submit the final EMU-aangifte via the SBR / CBS XBRL channel, with a digital signature from the authorised functionary, and store the confirmation response.

Het systeem MOET de definitieve EMU-aangifte kunnen indienen via de SBR-/CBS XBRL-koppeling, met digitale ondertekening door de daartoe bevoegde functionaris, en de bevestigingsrespons opslaan.

#### Scenario: Succesvolle SBR-indiening met PKIoverheid certificaat

- **GIVEN** een geaccordeerde concept-aangifte en een geldig PKIoverheid services-server certificaat
- **WHEN** de concerncontroller "Indienen bij CBS" kiest
- **THEN** wordt de aangifte als XBRL gegenereerd volgens de CBS-taxonomie voor EMU-rapportage
- **AND** wordt deze ondertekend en via de SBR/Digipoort-route ingediend
- **AND** wordt de `cbsBevestigingsnummer` opgeslagen op de `EMUReport`
- **AND** verandert `status` naar `"ingediend"`

#### Scenario: Indiening faalt door schemavalidatie

- **GIVEN** een aangifte met ontbrekende verplichte regel (bijv. geen waarde voor "mutatie reserves")
- **WHEN** de XBRL wordt aangeboden bij Digipoort
- **THEN** wordt de indiening afgewezen met de CBS-foutcode
- **AND** wordt de fout vertaald naar een Nederlandstalige melding voor de gebruiker
- **AND** blijft `status="concept"` zodat correctie mogelijk is

### Requirement: REQ-EMU-007 Vergelijking met vastgestelde meerjarenraming

The system SHALL compare the computed EMU-saldo per quarter to the established meerjarenraming (begroting) and surface both absolute and percentage variance.

Het systeem MOET het berekende EMU-saldo per kwartaal automatisch vergelijken met de voor dat jaar/kwartaal vastgestelde meerjarenraming (begroting), en zowel absolute als procentuele afwijking weergeven.

#### Scenario: Q2 EMU-saldo wijkt 27,8% af van begroot

- **GIVEN** een begroot kwartaalsaldo Q2 2026 van EUR −1,8M en een gerealiseerd saldo van EUR −2,3M
- **WHEN** de vergelijking draait
- **THEN** toont het rapport `afwijking: -500000` en `afwijkingPercentage: -27.8`
- **AND** wordt de afwijking automatisch toegelicht met de top-3 bijdragende EMU-adjustments (bijv. "versnelde dotatie voorziening pensioen wethouders EUR 450K, hogere investering MFA Centrum EUR 820K kas, lagere OZB-ontvangsten EUR 230K")

### Requirement: REQ-EMU-008 Afwijkingsalert bij overschrijding individuele EMU-referentiewaarde

The system SHALL emit an alert when the running-year EMU-saldo approaches the individual EMU-referentiewaarde (the per-entity norm published annually by the Rijk) or when the sector macro-ruimte risks exhaustion.

Het systeem MOET een alert genereren wanneer het EMU-saldo over een lopend jaar de individuele referentiewaarde (de "EMU-norm" per decentrale overheid, jaarlijks vastgesteld door het Rijk) dreigt te overschrijden, of wanneer de gezamenlijke ruimte voor de sector dreigt te worden uitgenut.

#### Scenario: Cumulatief EMU-tekort overschrijdt 80% van individuele norm

- **GIVEN** een gemeente met individuele EMU-referentiewaarde EUR 8,5M tekort
- **AND** een cumulatief tekort t/m Q3 van EUR 7,1M (= 83,5%)
- **WHEN** de Q3-rapportage wordt gegenereerd
- **THEN** verschijnt een alert "EMU-tekort 83,5% van referentiewaarde — risico op overschrijding bij ongewijzigd beleid Q4"
- **AND** wordt een prognose voor Q4 berekend op basis van geplande investeringen en aflossingen

### Requirement: REQ-EMU-009 Reconciliatie tussen EMU-rapportage en BBV-jaarrekening

The system SHALL show a closed reconciliation between the EMU-aangifte for a boekjaar and the definitive BBV-jaarrekening, with every difference traceable to an individual EMUAdjustment or a documented macro-rule.

Het systeem MOET een sluitende aansluiting tonen tussen de EMU-aangifte over een boekjaar en de definitieve BBV-jaarrekening, waarbij elk verschil herleidbaar is tot een individuele `EMUAdjustment` of een gedocumenteerde macroregel.

#### Scenario: Aansluitcontrole geslaagd voor jaarrekening 2025

- **GIVEN** een BBV-jaarrekening 2025 met saldo baten/lasten EUR 4,2M positief
- **AND** vier kwartaal-EMU-aangiften die optellen tot EMU-saldo EUR −2,3M
- **WHEN** de jaarreconciliatie draait
- **THEN** wordt het verschil van EUR 6,5M volledig verklaard door de som van alle adjustments
- **AND** toont het rapport "Aansluiting geslaagd: EUR 6,5M adjustments, 0 ongereconcilieerd"

### Requirement: REQ-EMU-010 IV3-classificatie als gedeelde taxonomie

The system SHALL classify every CashFlowItem with the IV3 taxonomy (hoofdstuk / functie / categorie) so that the IV3 kwartaalaangifte and the EMU-aangifte are produced from the same classified dataset.

Het systeem MOET alle `CashFlowItem`-records classificeren volgens de IV3-taxonomie (hoofdstuk, functie, categorie), zodanig dat de IV3-kwartaalaangifte aan CBS en de EMU-aangifte vanuit hetzelfde geclassificeerde dataset worden gegenereerd.

#### Scenario: Eén grootboekmutatie voedt zowel IV3 als EMU

- **GIVEN** een factuurbetaling van EUR 820K aan BAM voor brede school MFA Centrum
- **WHEN** de boeking wordt vastgelegd
- **THEN** krijgt het bijbehorende `CashFlowItem` automatisch IV3-classificatie hoofdstuk 8, functie 810, categorie 3.4.1
- **AND** verschijnt het in de IV3-kwartaalaangifte onder die categorie
- **AND** verschijnt het in de EMU-aangifte als `toevoeging-bruto-investering`

### Requirement: REQ-EMU-011 Periodieke synchronisatie met Schatkistbankieren

The system SHALL synchronise the schatkistbankieren rekening-courant and Ministerie van Financiën deposito positions daily (and mandatorily at every quarter-close) into DebtPosition records, so the sector-Rijk schuld view stays accurate.

Het systeem MOET dagelijks (of bij elke kwartaalafsluiting verplicht) de uitstaande positie op de schatkistbankieren-rekeningcourant en deposito's bij het Ministerie van Financiën inlezen, om de `DebtPosition`-records voor sector-Rijk transacties accuraat te houden.

#### Scenario: Dagelijkse import van schatkistbankieren-saldo

- **GIVEN** een actieve koppeling met de Agentschap-portaal API
- **WHEN** de dagelijkse synchronisatietaak draait om 02:00
- **THEN** wordt het saldo per ultimo vorige werkdag opgehaald
- **AND** wordt een `DebtPosition` met `instrument="schatkistbankieren-rekeningcourant"` bijgewerkt
- **AND** als het saldo negatief is, telt dit per direct mee in lopende EMU-schuldprognoses

### Requirement: REQ-EMU-012 Audit-trail en bewaarplicht

All EMU-rapportages, adjustments, CBS confirmations and modifications SHALL be retained immutably for the statutory bewaartermijn (10 years for financial administration of decentrale overheden per Archiefwet 1995) with a complete who/what/when audit-trail.

Alle EMU-rapportages, adjustments, CBS-bevestigingen en wijzigingen MOETEN voor de wettelijke bewaartermijn (10 jaar voor financiële administratie decentrale overheden conform Archiefwet 1995) onveranderbaar worden bewaard, met volledige audit-trail wie wat wanneer wijzigde.

#### Scenario: Accountant raadpleegt aangifte uit 2020

- **GIVEN** een accountantscontrole in 2026 die de EMU-aangifte over 2020 wil verifiëren
- **WHEN** de accountant het rapport opent
- **THEN** wordt de exact ingediende versie getoond, met cbsBevestigingsnummer, alle adjustments, gebruikte BBV-grootboekmutaties, en de digitale handtekening van de toenmalige concerncontroller
- **AND** zijn eventuele latere correctieaangiften als aparte `EMUReport`-records zichtbaar in chronologische volgorde

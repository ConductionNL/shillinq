---
status: draft
---

# bookkeeping-cbcr-pillar2

## Purpose

Lever de volledige administratie voor twee gerelateerde multinationale belastingrapportagepakketten: **Country-by-Country Reporting (CbCR)** en **Pillar Two (Global Minimum Tax)** onder het OESO/G20 BEPS-raamwerk. CbCR ontstond uit **BEPS Actie 13** (2015) en is per 1-1-2016 in NL geïmplementeerd via de **Wet aanvullende regels uitwisseling landenrapporten** (artikelen 29b-29h Wet Vpb). Het verplicht **multinationale groepen met geconsolideerde groepsopbrengsten ≥ EUR 750M** een jaarlijks landenrapport in te dienen bij de Belastingdienst, met per jurisdictie: omzet (van derden en intra-groep), winst voor belasting, betaalde Vpb (cash), gerapporteerde Vpb-last (accrual), gestort aandelenkapitaal, ingehouden winsten, FTE, en materiële vaste activa (anders dan kasmiddelen). De Belastingdienst wisselt dit rapport automatisch uit met alle jurisdicties waar de groep aanwezig is, en de fiscale autoriteit van elke jurisdictie kan het rapport gebruiken voor risicoanalyse en transfer-pricing-toezicht.

**Pillar Two** is een veel ingrijpender vervolg. Op 14 december 2022 nam de EU **Richtlijn 2022/2523** aan ter implementatie van de **OESO Global Anti-Base Erosion (GloBE) Model Rules**. Per 31 december 2023 introduceert dit het **15% effectieve minimumtarief** voor multinationale groepen ≥ EUR 750M. Het werkt via drie mechanismen: (1) **Income Inclusion Rule (IIR)** — de moedermaatschappij van een groep moet een **top-up tax** betalen op winsten van laagbelaste dochters; (2) **Undertaxed Profits Rule (UTPR)** — vanaf 2024, een vangnetregel die bovenliggende moeders treft als de IIR niet greep heeft; (3) **Qualified Domestic Minimum Top-up Tax (QDMTT)** — een jurisdictie kan zelf het verschil tot 15% bij eigen ingezeten entiteiten ophalen, ter voorkoming dat een buitenlandse IIR de heffing wegtrekt. Nederland implementeerde dit per **Wet minimumbelasting 2024** met **QDMTT** ingang 31-12-2023. De berekening per jurisdictie is technisch zwaar: **GloBE income** (commerciële winst gecorrigeerd voor ~35 specifieke items), **adjusted covered taxes** (Vpb + soortgelijke heffingen met diverse correcties), **Effective Tax Rate (ETR) per jurisdictie**, en bij ETR < 15% een **Top-up Tax** = (15% − ETR) × (GloBE income − Substance-based Income Exclusion). De exclusie betreft 5% van payroll + 5% van tangible assets (afgebouwde percentages tijdens overgangsperiode).

Beide pakketten vereisen aanlevering aan de Belastingdienst via **SBR/XBRL**: CbCR via **OESO XML schema** of XBRL, Pillar 2 via **GIR (GloBE Information Return)** XML/XBRL en de **NL aangifte minimumbelasting**. Deze spec introduceert binnen Shillinq de complete pipeline: per-jurisdictie aggregatie van financiële kerncijfers uit de geconsolideerde administratie, GloBE-correcties als declaratieve calculatie, QDMTT/IIR/UTPR berekeningsmotor, sluitende reconciliatie met de commerciële jaarrekening, en XML/XBRL-export-pakketten voor zowel CbCR als GIR. Voor multinationals met EU-moeder in NL is dit een wettelijk verplicht jaarlijks pakket dat nu vrijwel altijd door dure Big-4 advies wordt uitgevoerd (consultancy-projecten van EUR 200K-2M per jaar); deze spec brengt het binnen het administratieplatform.

## Data Model

Acht nieuwe schemas in een nieuw `multinational-tax` register.

**`group-entity-registry`** beschrijft elke entiteit in de geconsolideerde groep. Attributes: `entityName`, `legalForm`, `jurisdiction` (ISO 3166-1 alpha-2), `taxResidency` (jurisdiction code, kan afwijken van incorporation), `parentEntity` (FK self), `ultimateParentEntity` (FK self), `consolidationPercentage` (decimal — 100 voor volledig, lager voor proportional), `consolidationMethod` (full / proportional / equity / none), `mainBusinessActivity` (CBCR enum: R&D / holding / purchasing / manufacturing / sales / services / financial / insurance / other), `lei` (text), `vatNumber` (text), `cbcrIncluded` (boolean), `pillar2Included` (boolean), `excludedEntityType` (governmental / non-profit / pension-fund / investment-fund-UPE / real-estate-investment-vehicle-UPE / other / null), `firstYearInGroup` (date).

**`cbcr-jurisdiction-summary`** is de per-jurisdictie aggregatie voor CbCR. Attributes: `period` (fiscal year), `jurisdiction`, `unrelatedPartyRevenue` (decimal), `relatedPartyRevenue` (decimal), `totalRevenue` (computed), `profitBeforeTax` (decimal), `incomeTaxPaidCash` (decimal — kasbasis Vpb), `incomeTaxAccrued` (decimal — accrual, alleen current year), `statedCapital` (decimal), `accumulatedEarnings` (decimal), `numberOfEmployees` (int — FTE-equivalent, met methodebeschrijving), `tangibleAssetsOtherThanCash` (decimal — netto boekwaarde MVA exclusief kasmiddelen), `mainBusinessActivities` (array — uit groep-entities in jurisdictie). De jurisdictie-summary is de optelsom van alle `group-entity-registry` records met die jurisdictie binnen scope.

**`pillar2-jurisdiction-computation`** is de per-jurisdictie Pillar 2 berekening. Attributes: `period`, `jurisdiction`, `globeIncome` (decimal — commerciële winst + 35 voorgeschreven correcties), `globeIncomeAdjustments` (array van `{type, amount, description}` — bijv. exclusion deelnemingsbaten, exclusion stock-based comp, terugname goodwill impairments), `adjustedCoveredTaxes` (decimal — Vpb-last + soortgelijke heffingen met correcties), `coveredTaxAdjustments` (array), `etrJurisdiction` (computed: adjustedCoveredTaxes / globeIncome), `minimumRate` (decimal — 15%), `topUpTaxRate` (computed: max(0, 15% − etrJurisdiction)), `payrollCarveOut` (decimal — 5% van payroll, afgebouwd), `tangibleAssetCarveOut` (decimal — 5% van net book value MVA, afgebouwd), `substanceBasedIncomeExclusion` (computed: payrollCarveOut + tangibleAssetCarveOut), `excessProfit` (computed: max(0, globeIncome − SBIE)), `topUpTaxAmount` (computed: topUpTaxRate × excessProfit), `qdmttApplicable` (boolean), `qdmttAmount` (decimal — door jurisdictie zelf geheven), `iirAmount` (decimal — door UPE jurisdictie geheven na QDMTT-aftrek), `utprAmount` (decimal — alleen vanaf 2024), `safeHarbourApplied` (boolean en welke), `safeHarbourTest` (text — toelichting).

**`pillar2-safe-harbour`** registreert de toepasselijke safe harbour per jurisdictie per jaar. Transitional CbCR Safe Harbour (geldt voor FY2024-2026): jurisdictie kwalificeert als (1) **De minimis test** (omzet < EUR 10M EN winst < EUR 1M), of (2) **Simplified ETR test** (ETR ≥ 15% in 2024, 16% in 2025, 17% in 2026), of (3) **Routine Profits test** (winst ≤ SBIE). Attributes: `period`, `jurisdiction`, `testApplied`, `testResult` (pass / fail), `dataSource` (qualified-cbcr / qualified-financial-statements), `supportingCalculations` (json).

**`qdmtt-return`** is de Nederlandse QDMTT-aangifte. Attributes: `period`, `entity` (FK group-entity-registry, NL-resident), `taxableGlobeIncome`, `qualifyingDomesticEtr`, `qdmttPayable`, `paymentDueDate`, `filingDueDate`, `belastingdienstReference`, `xbrlSubmission` (file), `submissionStatus`, `submissionTimestamp`.

**`globe-information-return`** is de GIR (vergelijk CbCR maar voor Pillar 2, jaarlijks in te dienen door UPE-jurisdictie). Attributes: `period`, `ultimateParent` (FK), `mneGroupSummary` (json — groep-overzicht), `jurisdictionalComputations` (array FK naar `pillar2-jurisdiction-computation`), `topUpTaxAllocation` (json — IIR/UTPR/QDMTT verdeling per jurisdictie), `gloBeXmlSubmission` (file), `submissionDeadline` (typisch 15 maanden na FYE, 18 maanden voor eerste jaar).

**`cbcr-return`** is het CbCR-rapport in OESO-schema. Attributes: `period`, `reportingEntity` (FK), `jurisdictionSummaries` (array FK), `constituentEntityList` (array FK group-entity-registry), `cbcrXmlSubmission` (file), `belastingdienstReference`, `submissionDeadline` (12 maanden na FYE), `mcaaPartnerJurisdictions` (array — landen waar automatisch wordt uitgewisseld).

**`tax-treaty-overview`** is een referentieregister van DTA's (dubbelbelastingverdragen) en voorkomingen, gebruikt bij toedelen van bronheffingen aan jurisdicties in adjusted covered taxes. Attributes: `countryA`, `countryB`, `treatyName`, `treatyDate`, `withholdingRates` (object), `mliApplicability` (boolean).

## Requirements

### Requirement: REQ-CBC-001 Drempelwaarde-detectie EUR 750M

Het systeem MOET op basis van geconsolideerde groepsopbrengsten van het voorgaande boekjaar automatisch detecteren of de groep onder CbCR / Pillar 2 valt, conform Wet Vpb art. 29b en Wet Minimumbelasting 2024.

#### Scenario: Groep overschrijdt EUR 750M-drempel in FY2025

- **GIVEN** een groep met geconsolideerde groepsopbrengsten EUR 720M in FY2024 en EUR 805M in FY2025
- **WHEN** de detectie draait per 1-1-2026
- **THEN** detecteert het systeem dat voor FY2026 zowel CbCR (eerste rapportagejaar) als Pillar 2 (drempel overschreden) van toepassing zijn
- **AND** waarschuwt de controller dat de eerste CbCR ingediend moet worden voor 31-12-2027 (12 maanden na FYE 2026)
- **AND** waarschuwt dat eerste GIR ingediend moet worden binnen 18 maanden na FYE (eerste jaar transition window)

### Requirement: REQ-CBC-002 CbCR-aggregatie per jurisdictie

Het systeem MOET per jurisdictie per fiscaal jaar de zeven verplichte CbCR-velden aggregeren over alle `group-entity-registry` records in die jurisdictie.

#### Scenario: CbCR-aggregatie voor jurisdictie DE, FY2026

- **GIVEN** drie Duitse groepsentiteiten met respectievelijk omzet derden EUR 120M / EUR 45M / EUR 8M, intra-groep omzet EUR 30M / EUR 12M / EUR 0
- **WHEN** de aggregatie draait
- **THEN** ontstaat één `cbcr-jurisdiction-summary` voor jurisdiction=DE met `unrelatedPartyRevenue=173M`, `relatedPartyRevenue=42M`, `totalRevenue=215M`
- **AND** worden ook profit before tax, cash tax paid, accrued tax, stated capital, retained earnings, FTE en tangible assets gesommeerd
- **AND** wordt `mainBusinessActivities` als unieke set uit de drie entiteiten samengesteld

### Requirement: REQ-CBC-003 GloBE income met 35 verplichte correcties

Het systeem MOET per jurisdictie de GloBE income berekenen door op de commerciële winst de OESO-voorgeschreven correcties toe te passen (artikelen 3.2-3.5 GloBE Model Rules / EU-richtlijn).

#### Scenario: GloBE income NL voor FY2026

- **GIVEN** een NL-jurisdictie met commerciële winst voor belasting EUR 12M en o.a. EUR 800K deelnemingsbaten, EUR 200K stock-based comp uitgaven, EUR 150K terugneming goodwill impairment, EUR 50K excluded dividends
- **WHEN** de GloBE-calculatie draait
- **THEN** ontstaan `globeIncomeAdjustments` regels: −800K deelnemingsvrijstelling (exclude excluded dividends), +200K stock-based comp aanpassing, +150K goodwill impairment terugneming
- **AND** wordt `globeIncome` berekend en getoond met opbouwregels voor reconciliatie

### Requirement: REQ-CBC-004 ETR per jurisdictie berekenen

Het systeem MOET per jurisdictie per jaar de Effective Tax Rate berekenen als adjusted covered taxes / GloBE income, met begrenzing op 0% (geen negatieve ETR).

#### Scenario: NL-jurisdictie ETR 22% in FY2026

- **GIVEN** NL met `globeIncome=11500000` en `adjustedCoveredTaxes=2530000`
- **WHEN** de ETR-berekening draait
- **THEN** wordt `etrJurisdiction=22.0%` getoond
- **AND** geen top-up tax (ETR > 15%)

#### Scenario: Laagbelaste jurisdictie Bermuda ETR 0% triggert top-up

- **GIVEN** Bermuda-jurisdictie met `globeIncome=8000000`, `adjustedCoveredTaxes=0`, `payrollCarveOut=120000`, `tangibleAssetCarveOut=80000`
- **WHEN** de berekening draait
- **THEN** wordt `etrJurisdiction=0%`, `topUpTaxRate=15%`, `substanceBasedIncomeExclusion=200000`, `excessProfit=7800000`, `topUpTaxAmount=1170000`
- **AND** wordt dit bedrag toegewezen aan IIR (NL-moeder) tenzij Bermuda QDMTT heeft

### Requirement: REQ-CBC-005 Substance-based income exclusion (SBIE)

Het systeem MOET de SBIE berekenen als percentage van loonkosten + percentage van tangible assets, met de overgangspercentages tijdens de transition window.

#### Scenario: SBIE FY2026 op overgangspercentages

- **GIVEN** een jurisdictie met `payroll=2400000` (loonkosten in jurisdictie) en `tangibleAssetsNBV=4800000` (netto boekwaarde MVA)
- **AND** FY2026 met overgangspercentages payroll 9.6%, tangible 7.6% (afgebouwd van 10%/8% in 2023 naar 5%/5% in 2033)
- **WHEN** SBIE wordt berekend
- **THEN** wordt `payrollCarveOut=230400` (2.4M × 9.6%), `tangibleAssetCarveOut=364800` (4.8M × 7.6%), SBIE=595200
- **AND** wordt deze exclusion afgetrokken van GloBE income vóór toepassing top-up tax rate

### Requirement: REQ-CBC-006 QDMTT-prioriteit boven IIR

Bij een NL-resident groepsentiteit met laagbelaste winst MOET het systeem de QDMTT-heffing in NL toepassen vóór een eventuele IIR-claim van een hoger-gelegen moedermaatschappij in een ander land.

#### Scenario: NL-dochter met ETR 12% en buitenlandse moeder

- **GIVEN** een NL-dochter met `globeIncome=6000000`, `etrJurisdiction=12%`, en een Duitse UPE
- **AND** Wet Minimumbelasting 2024 die QDMTT verplicht stelt voor NL-residente entiteiten
- **WHEN** de toewijzing draait
- **THEN** wordt eerst de NL QDMTT-aangifte berekend: top-up 3% × (GloBE income − SBIE) en in NL geheven
- **AND** wordt het bedrag van de Duitse IIR-claim met dit QDMTT-bedrag verminderd (creditering)
- **AND** ontstaat een `qdmtt-return` record voor de NL-aangifte

### Requirement: REQ-CBC-007 Safe harbour testen toepassen

Het systeem MOET per jurisdictie testen of een transitional CbCR Safe Harbour van toepassing is (de minimis / simplified ETR / routine profits), en bij positief resultaat de volledige Pillar 2 berekening vervangen door een vereenvoudigde aangifte.

#### Scenario: De minimis test voor klein jurisdictie

- **GIVEN** jurisdictie ES met `totalRevenue=8500000` (< EUR 10M) en `profitBeforeTax=400000` (< EUR 1M)
- **WHEN** safe-harbour-testing draait voor FY2026
- **THEN** wordt `safe-harbour=de-minimis` met `testResult=pass` geregistreerd
- **AND** wordt voor ES geen volledige `pillar2-jurisdiction-computation` opgesteld, alleen een vereenvoudigde regel
- **AND** verschijnt dit in de GIR met de safe-harbour-claim

### Requirement: REQ-CBC-008 XML/XBRL-export CbCR conform OESO-schema

Het systeem MOET het CbCR-rapport exporteren in het OESO CbC XML-schema v2.0 (of nieuwer), klaar voor SBR/Digipoort-indiening bij de Belastingdienst.

#### Scenario: CbCR-indiening 2026 naar Belastingdienst

- **GIVEN** een complete `cbcr-return` met 23 jurisdictie-summaries en 47 constituent entities voor FY2026
- **WHEN** de gebruiker "Genereer CbCR XML" kiest
- **THEN** wordt een XML-bestand geproduceerd conform OESO CbC schema v2.0 met DocSpec, MessageSpec, CbcReports
- **AND** wordt de XML door de calculatie zelf gegenereerd, niet door een PHP exporter-class
- **AND** kan de XML ondertekend en via SBR/Digipoort worden ingediend; de `belastingdienstReference` wordt opgeslagen

### Requirement: REQ-CBC-009 GIR (GloBE Information Return) genereren

Het systeem MOET een GIR genereren met alle jurisdictie-computaties, safe-harbour-toepassingen, top-up-tax-toewijzingen (IIR/UTPR/QDMTT-credit), en deze indienen volgens het OESO GIR XML/XBRL-schema.

#### Scenario: GIR FY2026 voor groep van 23 jurisdicties

- **GIVEN** een complete set `pillar2-jurisdiction-computation` records voor 23 jurisdicties, waarvan 5 onder safe harbour
- **WHEN** de GIR-generatie draait
- **THEN** wordt een GIR-XML geproduceerd met sectie 1 (groep-info), sectie 2 (per-jurisdictie ETR + GloBE income), sectie 3 (top-up tax + toewijzing IIR/UTPR/QDMTT-credit per entiteit)
- **AND** wordt validatie tegen het GloBE Information Return XML-schema uitgevoerd vóór indiening
- **AND** kan de GIR worden ingediend in elke jurisdictie waar de groep gevestigd is (typisch UPE-jurisdictie of designated filing entity)

### Requirement: REQ-CBC-010 Aansluiting met geconsolideerde jaarrekening

Het systeem MOET een complete reconciliatie produceren tussen het CbCR-totaal en de geconsolideerde jaarrekening: omzet, profit before tax, en tax expense moeten met groepsniveau aansluiten (met expliciet vermelde reconciliatie-items zoals eliminations, joint ventures, IFRS-USGAAP verschillen).

#### Scenario: Reconciliatie CbCR FY2026 met geconsolideerde jaarrekening

- **GIVEN** een geconsolideerde jaarrekening met groepsomzet EUR 1,2B en profit before tax EUR 145M
- **AND** een CbCR-totaal van EUR 1,18B omzet en EUR 142M profit
- **WHEN** de reconciliatie draait
- **THEN** wordt het verschil verklaard: −20M JV pro-rata, +3M consolidation eliminations, etc.
- **AND** wordt het residuele verschil als "ongereconcilieerd" gemerkt als > EUR 1M
- **AND** is de reconciliatie verplichte bijlage bij de CbCR-indiening (Belastingdienst kan opvragen)

## Standards & Sources

Primair: **OESO BEPS Actie 13 Final Report** (2015), **OESO Transfer Pricing Documentation and Country-by-Country Reporting**, **Wet Vpb 1969 artikel 29b-29h** (NL CbCR-implementatie), **Beleidsbesluit CbCR** (Staatscourant), **MCAA** (Multilateral Competent Authority Agreement) voor uitwisseling. Voor Pillar 2: **OESO Global Anti-Base Erosion (GloBE) Model Rules** (december 2021), **GloBE Commentary** (maart 2022), **OESO Administrative Guidance** (februari 2023, juli 2023, december 2023 — doorlopend uitgebreid), **EU Richtlijn 2022/2523** (Pillar 2 implementatie EU), **Wet minimumbelasting 2024** (NL implementatie), **Memorie van toelichting Wet minimumbelasting 2024** (Tweede Kamer 36369).

Aanlevering: **OESO CbC XML schema v2.0**, **OESO GloBE Information Return XML schema** (versie groeit met OESO-iteraties), **Nederlandse Taxonomie (NT) Vpb-modules**, **SBR/Digipoort PKIoverheid services-server certificaten**. Praktijksources: Big-4 *Pillar 2 implementation guides* (KPMG *BEPS Pillar Two — A Guide*, PwC *Pillar Two Country Tracker*, Deloitte *International Tax Pillar 2 hub*, EY *Worldwide Pillar 2 Implementation Tracker*). NOB-uiting *Pillar 2 in de praktijk*, NBA-handreiking *Pillar 2 in de jaarrekening*. IBFD *International Tax Glossary* en *European Tax Handbook*. Voor implementation-tracking per land: OESO IF (Inclusive Framework) overzichten, ICRICT publicaties.

## Cross-app integration

- **bookkeeping-consolidation-commercial** — primaire bron voor jurisdictie-aggregaties: per-entiteit financiële kerncijfers worden via de consolidatiehiërarchie per jurisdictie gegroepeerd. Eliminaties tussen groepsentiteiten in dezelfde jurisdictie blijven binnenshuis; cross-jurisdictie intra-groep transacties blijven zichtbaar in CbCR als `relatedPartyRevenue`.
- **bookkeeping-deferred-tax** — bron voor `adjustedCoveredTaxes`: zowel current als deferred tax tellen mee in Pillar 2 ETR, met specifieke correcties zoals exclusion van tariefwijziging-effecten en herrekeningen.
- **bookkeeping-vpb-mkb** — NL Vpb-aangifte levert de current tax cijfers; bij groep met meerdere NL-entiteiten in fiscale eenheid wordt de Vpb-belasting toegerekend.
- **bookkeeping-financial-statements** — CbCR profit before tax stemt af op de geconsolideerde P&L; Pillar 2 disclosure (top-up tax expense, ETR per jurisdictie) verschijnt in de jaarrekeningtoelichting (IAS 12 §88 *Pillar Two income taxes* requirements per IASB amendment mei 2023).
- **bookkeeping-fixed-assets-depreciation** — bron voor `tangibleAssetsOtherThanCash` in CbCR en `tangibleAssetCarveOut` in Pillar 2 SBIE.
- **hrmq** — bron voor `numberOfEmployees` (FTE) per jurisdictie in CbCR en voor `payrollCarveOut` in Pillar 2 SBIE.
- **openconnector → Belastingdienst SBR** — indiening CbCR XML, QDMTT-aangifte en GIR via SBR/Digipoort met PKIoverheid certificaten.
- **openconnector → OESO IF** — optionele monitoring van implementation status per jurisdictie wereldwijd (voor scope-bepaling van GIR).
- **docudesk** — bewaart ingediende XML's, Belastingdienst-bevestigingen, calculatie-onderbouwingen, transfer pricing documentation, en juridische opinion-memo's onder restricted access (vertrouwelijk wegens commerciële gevoeligheid CbCR-data).
- **decidesk** — materiële Pillar 2-uitkomsten (top-up tax > EUR 500K, of nieuwe jurisdictie toetreding) routeren via audit committee voor goedkeuring vóór indiening.

## Target users

Primair de **Head of Tax** of **Global Tax Director** van een multinationale groep met geconsolideerde omzet ≥ EUR 750M. In NL-context typisch een groep met UPE in NL (zo'n 100-150 groepen in Nederland) of een groep met substantiële NL-aanwezigheid die onder QDMTT valt. Bij grotere groepen splitst de rol naar een **CbCR-coordinator** (verzamelt data uit lokale entiteiten en stelt CbCR samen), een **Pillar 2-specialist** (doet de GloBE-correcties, ETR-berekeningen en GIR-opstelling), en een **transfer pricing manager** (zorgt voor consistentie tussen CbCR en TP-documentatie). Bij MKB+ multinationals (vaak rondom de EUR 750M drempel) is dit één persoon, vaak ingehuurd Big-4-advies.

Secundair: de **CFO** van de UPE (eindverantwoordelijke voor indiening en betaling top-up tax), het **audit committee** (krijgt rapportage over Pillar 2 jurisdictie-by-jurisdictie ETR's met fiscale risicoanalyse), de **externe accountant** (controleert Pillar 2 disclosure en QDMTT-positie als onderdeel van jaarrekeningcontrole), en de **Belastingdienst** zelf (ontvangt CbCR via automatische uitwisseling en QDMTT/GIR rechtstreeks). Tertiair: andere belastingautoriteiten (krijgen CbCR via MCAA-uitwisseling voor risicoanalyse), en de **transfer pricing specialist** die CbCR-data als input voor land-by-land transfer-pricing risicoanalyse gebruikt.

Strategische waarde voor Shillinq: Pillar 2-implementatie is begin 2024 voor de meeste in-scope groepen een EUR 500K-2M consultancy-project geweest, met jaarlijkse vervolgkosten EUR 200-800K voor de complete GIR + QDMTT cyclus. Een geïntegreerd platform waarin de data al beschikbaar is (consolidatie + deferred tax + Vpb) en de GloBE-correcties declaratief worden toegepast, levert structureel meerwerk-reductie van 60-80% en hogere auditeerbaarheid. Voor multinationals die richting de EUR 750M drempel groeien (de "Pillar 2 next wave" — zo'n 300+ extra NL-groepen tussen 2025-2030) wordt Shillinq een differentiating capability ten opzichte van legacy ERP-systemen die geen native Pillar 2 module hebben.

# Spec: Country-by-Country Reporting (CbCR) & OESO Pillar Two (Global Minimum Tax)

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (regulatory + compliance)  
**Primary spec:** bookkeeping-cbcr-pillar2  

**Depends on:**
- bookkeeping-consolidation-commercial (per-jurisdictie aggregatie input)
- bookkeeping-deferred-tax (adjusted covered taxes)
- bookkeeping-vpb-mkb (Vpb current + accrual per entiteit)
- bookkeeping-fixed-assets-depreciation (tangible assets NBV per jurisdictie)
- hrmq (payroll & FTE per jurisdictie, optional)

---

## Overview

Deze spec introduceert volledige CbCR (BEPS Action 13, Wet Vpb 29b–29h) en
Pillar 2 (OESO GloBE Model Rules, Wet minimumbelasting 2024) rapportage
voor Shillinq. Het systeem detecteert EUR 750M drempel automatisch, aggregeert
per-jurisdictie financiële kerncijfers uit consolidatie, past GloBE-correcties
toe, berekent per-jurisdictie ETR en top-up tax, test safe harbour, en exporteert
CbCR XML (OESO schema) + GIR XML + NL QDMTT-aangifte.

Acht registers zijn gedeclareerd:
1. **group-entity-registry** — multinationale entiteiten + consolidatiedatails
2. **cbcr-jurisdiction-summary** — 7 CbCR velden per jurisdictie
3. **pillar2-jurisdiction-computation** — ETR, GloBE income, top-up tax per jurisdictie
4. **pillar2-safe-harbour** — safe harbour test resultaten
5. **qdmtt-return** — NL QDMTT-aangifte (Qualified Domestic Minimum Top-up Tax)
6. **globe-information-return** — GIR XML/XBRL (OESO schema)
7. **cbcr-return** — CbCR XML (OESO schema)
8. **tax-treaty-overview** — DTA referentie voor withholding correcties

De workflow is declaratief: schema metadata + aggregatie queries. Geen PHP
GloBE calculatieservice.

---

## ADDED Requirements

### Requirement: REQ-CBC-001 Drempelwaarde-detectie EUR 750M

The system MUST detect whether the group is in CbCR / Pillar 2 scope based on
prior-year consolidated revenue versus the EUR 750M threshold, per Wet Vpb
art. 29b and Wet Minimumbelasting 2024.

Het systeem MOET op basis van geconsolideerde groepsopbrengsten van het
voorgaande boekjaar automatisch detecteren of de groep onder CbCR / Pillar 2 valt,
conform Wet Vpb art. 29b en Wet Minimumbelasting 2024.

#### Scenario: Groep overschrijdt EUR 750M-drempel in FY2025

- **GIVEN** een groep met geconsolideerde groepsopbrengsten EUR 720M in FY2024
  en EUR 805M in FY2025
- **WHEN** de drempel-detectie per 1-1-2026 draait
- **THEN** detecteert het systeem dat voor FY2026 zowel CbCR (eerste rapportagejaar)
  als Pillar 2 (drempel overschreden) van toepassing zijn
- **AND** waarschuwt de controller dat eerste CbCR ingediend moet worden vóór
  31-12-2027 (12 maanden na FYE 2026)
- **AND** waarschuwt dat eerste GIR ingediend moet worden binnen 18 maanden na
  FYE (eerste jaar transition window)

### Requirement: REQ-CBC-002 CbCR-aggregatie per jurisdictie

The system MUST aggregate the seven mandatory CbCR fields per jurisdiction per
fiscal year over all `group-entity-registry` records in that jurisdiction.

Het systeem MOET per jurisdictie per fiscaal jaar de zeven verplichte
CbCR-velden aggregeren over alle `group-entity-registry` records in die jurisdictie.

#### Scenario: CbCR-aggregatie voor jurisdictie DE, FY2026

- **GIVEN** drie Duitse groepsentiteiten met respectievelijk omzet derden
  EUR 120M / EUR 45M / EUR 8M, intra-groep omzet EUR 30M / EUR 12M / EUR 0
- **WHEN** de aggregatie draait
- **THEN** ontstaat één `cbcr-jurisdiction-summary` voor jurisdiction=DE met
  `unrelatedPartyRevenue=173M`, `relatedPartyRevenue=42M`, `totalRevenue=215M`
- **AND** worden ook profit before tax, cash tax paid, accrued tax, stated capital,
  retained earnings, FTE en tangible assets gesommeerd
- **AND** wordt `mainBusinessActivities` als unieke set uit de drie entiteiten
  samengesteld

### Requirement: REQ-CBC-003 GloBE income met 35 verplichte correcties

The system MUST compute GloBE income per jurisdiction by applying the
OESO-prescribed corrections to commercial profit before tax (Art. 3.2-3.5
GloBE Model Rules / EU Directive 2022/2523).

Het systeem MOET per jurisdictie de GloBE income berekenen door op de
commerciële winst de OESO-voorgeschreven correcties toe te passen (artikelen
3.2–3.5 GloBE Model Rules / EU-richtlijn).

#### Scenario: GloBE income NL voor FY2026

- **GIVEN** een NL-jurisdictie met commerciële winst voor belasting EUR 12M en
  o.a. EUR 800K deelnemingsbaten, EUR 200K stock-based comp uitgaven, EUR 150K
  terugneming goodwill impairment, EUR 50K excluded dividends
- **WHEN** de GloBE-calculatie draait
- **THEN** ontstaan `globeIncomeAdjustments` regels: −800K deelnemingsvrijstelling
  (exclude excluded dividends), +200K stock-based comp aanpassing, +150K goodwill
  impairment terugneming
- **AND** wordt `globeIncome` berekend en getoond met opbouwregels voor reconciliatie

### Requirement: REQ-CBC-004 ETR per jurisdictie berekenen

The system MUST compute the Effective Tax Rate per jurisdiction per year as
adjusted covered taxes / GloBE income, floored at 0% (no negative ETR).

Het systeem MOET per jurisdictie per jaar de Effective Tax Rate berekenen als
adjusted covered taxes / GloBE income, met begrenzing op 0% (geen negatieve ETR).

#### Scenario: NL-jurisdictie ETR 22% in FY2026

- **GIVEN** NL met `globeIncome=11500000` en `adjustedCoveredTaxes=2530000`
- **WHEN** de ETR-berekening draait
- **THEN** wordt `etrJurisdiction=22.0%` getoond
- **AND** geen top-up tax (ETR > 15%)

#### Scenario: Laagbelaste jurisdictie Bermuda ETR 0% triggert top-up

- **GIVEN** Bermuda-jurisdictie met `globeIncome=8000000`, `adjustedCoveredTaxes=0`,
  `payrollCarveOut=120000`, `tangibleAssetCarveOut=80000`
- **WHEN** de berekening draait
- **THEN** wordt `etrJurisdiction=0%`, `topUpTaxRate=15%`, `substanceBasedIncomeExclusion=200000`,
  `excessProfit=7800000`, `topUpTaxAmount=1170000`
- **AND** wordt dit bedrag toegewezen aan IIR (NL-moeder) tenzij Bermuda QDMTT heeft

### Requirement: REQ-CBC-005 Substance-based income exclusion (SBIE)

The system MUST compute the SBIE as a percentage of payroll plus a percentage
of tangible assets, applying the FY-specific transitional carve-out percentages
during the transition window (FY2023-FY2033).

Het systeem MOET de SBIE berekenen als percentage van loonkosten + percentage
van tangible assets, met de overgangspercentages tijdens de transition window.

#### Scenario: SBIE FY2026 op overgangspercentages

- **GIVEN** een jurisdictie met `payroll=2400000` (loonkosten in jurisdictie) en
  `tangibleAssetsNBV=4800000` (netto boekwaarde MVA)
- **AND** FY2026 met overgangspercentages payroll 9.6%, tangible 7.6% (afgebouwd
  van 10%/8% in 2023 naar 5%/5% in 2033)
- **WHEN** SBIE wordt berekend
- **THEN** wordt `payrollCarveOut=230400` (2.4M × 9.6%), `tangibleAssetCarveOut=364800`
  (4.8M × 7.6%), SBIE=595200
- **AND** wordt deze exclusion afgetrokken van GloBE income vóór toepassing top-up
  tax rate

### Requirement: REQ-CBC-006 QDMTT-prioriteit boven IIR

The system MUST apply the NL QDMTT charge to low-taxed NL-resident group
entities before any IIR claim by a higher-up parent in another country
(QDMTT-priority over IIR).

Bij een NL-resident groepsentiteit met laagbelaste winst MOET het systeem de
QDMTT-heffing in NL toepassen vóór een eventuele IIR-claim van een hoger-gelegen
moedermaatschappij in een ander land.

#### Scenario: NL-dochter met ETR 12% en buitenlandse moeder

- **GIVEN** een NL-dochter met `globeIncome=6000000`, `etrJurisdiction=12%`,
  en een Duitse UPE
- **AND** Wet Minimumbelasting 2024 die QDMTT verplicht stelt voor NL-residente
  entiteiten
- **WHEN** de toewijzing draait
- **THEN** wordt eerst de NL QDMTT-aangifte berekend: top-up 3% × (GloBE income
  − SBIE) en in NL geheven
- **AND** wordt het bedrag van de Duitse IIR-claim met dit QDMTT-bedrag verminderd
  (creditering)
- **AND** ontstaat een `qdmtt-return` record voor de NL-aangifte

### Requirement: REQ-CBC-007 Safe harbour testen toepassen

The system MUST test each jurisdiction against the three transitional CbCR
Safe Harbour tests (de minimis / simplified ETR / routine profits) and, on a
pass, replace the full Pillar 2 computation with a simplified return.

Het systeem MOET per jurisdictie testen of een transitional CbCR Safe Harbour
van toepassing is (de minimis / simplified ETR / routine profits), en bij positief
resultaat de volledige Pillar 2 berekening vervangen door een vereenvoudigde aangifte.

#### Scenario: De minimis test voor klein jurisdictie

- **GIVEN** jurisdictie ES met `totalRevenue=8500000` (< EUR 10M) en
  `profitBeforeTax=400000` (< EUR 1M)
- **WHEN** safe-harbour-testing draait voor FY2026
- **THEN** wordt `safe-harbour=de-minimis` met `testResult=pass` geregistreerd
- **AND** wordt voor ES geen volledige `pillar2-jurisdiction-computation` opgesteld,
  alleen een vereenvoudigde regel
- **AND** verschijnt dit in de GIR met de safe-harbour-claim

### Requirement: REQ-CBC-008 XML/XBRL-export CbCR conform OESO-schema

The system MUST export the CbCR report in the OESO CbC XML schema (v2.0 or
newer), ready for SBR/Digipoort submission to the Belastingdienst.

Het systeem MOET het CbCR-rapport exporteren in het OESO CbC XML-schema v2.0
(of nieuwer), klaar voor SBR/Digipoort-indiening bij de Belastingdienst.

#### Scenario: CbCR-indiening 2026 naar Belastingdienst

- **GIVEN** een complete `cbcr-return` met 23 jurisdictie-summaries en 47 constituent
  entities voor FY2026
- **WHEN** de gebruiker "Genereer CbCR XML" kiest
- **THEN** wordt een XML-bestand geproduceerd conform OESO CbC schema v2.0 met
  DocSpec, MessageSpec, CbcReports
- **AND** wordt de XML door de applicatie zelf gegenereerd, niet door externe
  exporter
- **AND** kan de XML ondertekend en via SBR/Digipoort worden ingediend; de
  `belastingdienstReference` wordt opgeslagen

### Requirement: REQ-CBC-009 GIR (GloBE Information Return) genereren

The system MUST generate a GIR carrying all jurisdiction computations,
safe-harbour applications, and top-up tax allocations (IIR / UTPR / QDMTT
credit), and submit it per the OESO GIR XML/XBRL schema.

Het systeem MOET een GIR genereren met alle jurisdictie-computaties,
safe-harbour-toepassingen, top-up-tax-toewijzingen (IIR/UTPR/QDMTT-credit),
en deze indienen volgens het OESO GIR XML/XBRL-schema.

#### Scenario: GIR FY2026 voor groep van 23 jurisdicties

- **GIVEN** een complete set `pillar2-jurisdiction-computation` records voor
  23 jurisdicties, waarvan 5 onder safe harbour
- **WHEN** de GIR-generatie draait
- **THEN** wordt een GIR-XML geproduceerd met sectie 1 (groep-info), sectie 2
  (per-jurisdictie ETR + GloBE income), sectie 3 (top-up tax + toewijzing
  IIR/UTPR/QDMTT-credit per entiteit)
- **AND** wordt validatie tegen het GloBE Information Return XML-schema
  uitgevoerd vóór indiening
- **AND** kan de GIR worden ingediend in elke jurisdictie waar de groep
  gevestigd is (typisch UPE-jurisdictie of designated filing entity)

### Requirement: REQ-CBC-010 Aansluiting met geconsolideerde jaarrekening

The system MUST produce a complete reconciliation between the CbCR totals
and the consolidated financial statements (revenue, profit before tax, tax
expense), listing explicit reconciliation items (eliminations, joint
ventures, IFRS-USGAAP differences) and flagging any residual difference over
EUR 1M as unreconciled.

Het systeem MOET een complete reconciliatie produceren tussen het CbCR-totaal
en de geconsolideerde jaarrekening: omzet, profit before tax, en tax expense
moeten met groepsniveau aansluiten (met expliciet vermelde reconciliatie-items
zoals eliminations, joint ventures, IFRS-USGAAP verschillen).

#### Scenario: Reconciliatie CbCR FY2026 met geconsolideerde jaarrekening

- **GIVEN** een geconsolideerde jaarrekening met groepsomzet EUR 1,2B en
  profit before tax EUR 145M
- **AND** een CbCR-totaal van EUR 1,18B omzet en EUR 142M profit
- **WHEN** de reconciliatie draait
- **THEN** wordt het verschil verklaard: −20M JV pro-rata, +3M consolidation
  eliminations, etc.
- **AND** wordt het residuele verschil als "ongereconcilieerd" gemerkt als > EUR 1M
- **AND** is de reconciliatie verplichte bijlage bij de CbCR-indiening
  (Belastingdienst kan opvragen)

---

## Standards & Sources

Primair:
- **OESO BEPS Action 13 Final Report** (2015)
- **OESO Transfer Pricing Documentation and Country-by-Country Reporting**
- **Wet Vpb 1969 artikel 29b–29h** (NL CbCR-implementatie)
- **OESO Global Anti-Base Erosion (GloBE) Model Rules** (december 2021)
- **OESO GloBE Commentary** (maart 2022)
- **OESO Administrative Guidance** (februari 2023, juli 2023, december 2023+)
- **EU Richtlijn 2022/2523** (Pillar 2 implementatie EU)
- **Wet minimumbelasting 2024** (NL implementatie)
- **Memorie van toelichting Wet minimumbelasting 2024** (Tweede Kamer 36369)

Aanlevering:
- **OESO CbC XML schema v2.0**
- **OESO GloBE Information Return XML schema**
- **Nederlandse Taxonomie (NT) Vpb-modules**
- **SBR/Digipoort PKIoverheid services-server certificaten**

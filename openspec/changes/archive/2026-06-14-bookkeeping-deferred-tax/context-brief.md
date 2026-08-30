---
status: draft
---

# bookkeeping-deferred-tax

## Purpose

Deliver het volledige systeem voor **uitgestelde belastingen** (deferred tax assets en liabilities) en **belastingvoorzieningen** voor MKB+ en grote ondernemingen die rapporteren onder **IAS 12 Income Taxes** of de Nederlandse equivalent **RJ 272 Belastingen naar de winst**. Uitgestelde belastingen ontstaan zodra de fiscale en commerciële winstbepaling uit elkaar lopen — wat in de praktijk bij vrijwel elke onderneming met materiële vaste activa, voorzieningen, fiscale eenheid, of verliescompensatie gebeurt. Het correct opnemen en bijhouden van deze posities is conceptueel een van de moeilijkste onderdelen van de jaarrekening: de wettelijke belastingvoet (Vpb 19% tot EUR 200K, 25,8% daarboven in 2026) wordt zelden de effectieve belastingvoet, omdat blijvende verschillen (deelnemingsvrijstelling, niet-aftrekbare kosten, fiscaal ontheven baten), tijdelijke verschillen (afschrijvingstempo, voorzieningen, garantieverplichtingen, herwaarderingen), en compensabele verliezen het beeld vertekenen.

Voor de meeste Nederlandse middenstand-administraties betekent dit dat de jaarrekening een **belastinglast in de winst- en verliesrekening** moet tonen die ofwel hoger ofwel lager is dan het bedrag dat aan de Belastingdienst is verschuldigd over het lopende boekjaar, en dat het verschil als activum (vooruitbetaalde belasting of recht op toekomstige aftrek) of als verplichting (nog te betalen belasting over toekomstige winst) op de balans staat. De controllerende accountant zal die positie willen reconciliëren via een **tax-rate-reconciliation tabel** (de "ETR-toelichting") die in de toelichting bij de jaarrekening verschijnt en die de stap-voor-stap brug legt van wettelijk tarief × commercieel resultaat naar de effectieve belastingdruk.

Deze spec introduceert binnen Shillinq een uitgestelde-belastingmotor die uit het BBV-/IFRS-grootboek de tijdelijke verschillen detecteert per balanspost, een **Deferred Tax Roll-forward** bijhoudt per categorie (assets vs liabilities), de **recoverability assessment** voor compensabele verliezen automatiseert (mag een verliescompensatie-activum überhaupt worden geactiveerd?), en de **effectieve belastingvoet-reconciliatie** als declaratieve calculatie produceert voor opname in `bookkeeping-financial-statements`. Per jurisdictie (omdat fiscale eenheid en buitenlandse vaste inrichtingen elk hun eigen tariefen en losses-stack hebben) wordt het beeld apart gevoerd en in de consolidatie samengebracht. Het uiteindelijke doel: een MKB+-controller of een fiscalist die `Belastingen → Uitgestelde belastingen` opent ziet binnen één scherm de complete jaarmutatie, de aansluiting met de Vpb-aangifte, de ETR-reconciliatie, en de onderbouwing per balanspost — zonder dat één regel PHP-code is geschreven voor "deferred tax service".

## Data Model

Vijf nieuwe schemas in de `tax` register, plus extensies op `Account` en `Period`.

**`temporary-difference`** is de kernregel. Elke regel beschrijft één verschil tussen commerciële en fiscale boekwaarde op één balanspost per peildatum. Attributes: `period` (FK FiscalPeriod), `jurisdiction` (NL / DE / BE / etc.), `account` (FK Account — bron-balanspost), `category` (depreciation / provision / receivable-impairment / inventory-valuation / development-cost / fair-value-adjustment / lease-ifrs16 / pension / other), `commercialCarryingAmount` (decimal), `taxCarryingAmount` (decimal), `temporaryDifference` (computed: commercial − tax), `type` (taxable / deductible), `reversalPattern` (short-term / long-term / indefinite), `expectedReversalYear` (optional int), `taxRate` (decimal — tarief op verwacht omkeer-moment), `deferredTaxBalance` (computed: temporaryDifference × taxRate), `notes` (text). Een **taxable** verschil leidt tot een uitgestelde belastingverplichting; een **deductible** verschil leidt tot een uitgestelde belastingvordering.

**`tax-loss-carry-forward`** registreert de fiscaal compensabele verliezen per jurisdictie per ontstaansjaar. Attributes: `jurisdiction`, `originatingYear`, `originalAmount`, `utilisedAmount` (running total), `remainingAmount` (computed), `expirationYear` (NL: onbeperkt voorwaartse compensatie sinds 2022, maar maximaal 50% boven de eerste EUR 1M; voor jaren tot en met 2018 nog 6-jaars termijn), `applicableRegime` (vóór-2019 / 2019-2021-overgang / 2022-en-later), `dtaRecognised` (decimal — geactiveerd deel), `dtaRecoverabilityRationale` (text — verplicht zodra `dtaRecognised > 0`), `recoverabilityHorizon` (int — aantal jaren waarbinnen verwachte winst voldoende is), `linkedProjections` (array van FK naar `bookkeeping-budget-multi-year` records).

**`tax-rate-reconciliation`** is het ETR-overzicht per periode per jurisdictie. Attributes: `period`, `jurisdiction`, `profitBeforeTax` (decimal), `statutoryRate` (decimal), `statutoryTaxExpense` (computed), `reconciliationItems` (array van `{description, type: permanent / temporary / rate-change / prior-year-adjustment / withholding / other, amount, taxEffect}`), `effectiveTaxExpense` (computed), `effectiveTaxRate` (computed), `disclosureNarrative` (text — vrij te bewerken toelichting voor jaarrekening).

**`deferred-tax-movement`** is de jaarmutatie per `temporary-difference`-categorie. Attributes: `period`, `jurisdiction`, `category`, `openingBalance`, `originatedInPeriod` (decimal — toename door nieuw verschil), `reversedInPeriod` (decimal — afname door eerder verschil dat zich realiseert), `rateChangeAdjustment` (decimal — effect tariefwijziging op openingsbalans), `acquiredViaBusinessCombination` (decimal — uit overname), `translationAdjustment` (decimal — voor buitenlandse valuta), `recognisedInPL` (computed — som van origination, reversal, rate-change), `recognisedInOCI` (decimal — voor herwaarderingen via OCI), `closingBalance` (computed), `linkedJournalEntries` (array FK).

**`tax-provision`** is de balanspost voor lopende vennootschapsbelasting (te betalen of vooruitbetaald) plus de gecombineerde uitgestelde belastingposities per jurisdictie. Attributes: `period`, `jurisdiction`, `currentTaxPayable` (decimal), `currentTaxPrepaid` (decimal), `dtaTotal` (decimal — som van alle deductible deferred tax balances), `dtlTotal` (decimal — som van alle taxable deferred tax balances), `netDtaDtlPosition` (computed), `presentationOnBalanceSheet` (gross / net — afhankelijk van vermogen tot saldering binnen jurisdictie en termijn), `linkedVpbReturn` (FK naar Vpb-aangifte uit `bookkeeping-vpb-mkb`).

**Extensies:** `Account` krijgt `taxBasisDifferenceCategory` (optional enum) zodat boekhouders bij rekeningaanleg direct kunnen markeren of een rekening typisch tijdelijke verschillen genereert (bijv. "garantievoorziening" → automatisch deductible verschil). `FiscalPeriod` krijgt `enactedTaxRates` (object: `{jurisdiction: rate}`) zodat tariefwijzigingen die op balansdatum al "substantively enacted" zijn correct worden toegepast op uitgestelde posities — bijvoorbeeld de aankondiging in een Belastingplan dat per volgend jaar het tarief stijgt, leidt onmiddellijk tot een herwaardering van uitgestelde posities tegen het nieuwe tarief.

## Requirements

### Requirement: REQ-DT-001 Tijdelijke verschillen detecteren per balanspost

Het systeem MOET op balansdatum per relevante `Account` automatisch het verschil tussen commerciële en fiscale boekwaarde berekenen, op basis van de gekoppelde fiscale waarderingsregels.

#### Scenario: Materiële vaste activa met versnelde fiscale afschrijving

- **GIVEN** een gebouw met commerciële boekwaarde EUR 2,4M (lineair 40 jaar) en fiscale boekwaarde EUR 1,9M (willekeurige afschrijving startende ondernemer geldt niet, maar minimum 10-jaars termijn)
- **WHEN** de jaarafsluiting draait per 31-12-2026
- **THEN** ontstaat een `temporary-difference` regel met `type=taxable`, `temporaryDifference=500000`, `taxRate=25.8%`, `deferredTaxBalance=129000` (deferred tax liability)
- **AND** wordt deze regel gekoppeld aan het grootboekrekeningnummer en aan de specifieke MVA-asset

### Requirement: REQ-DT-002 Onderscheid permanent vs tijdelijk verschil

Het systeem MOET correct onderscheid maken tussen permanente verschillen (die wel ETR beïnvloeden maar geen uitgestelde belasting opleveren) en tijdelijke verschillen (die wel uitgestelde belasting opleveren).

#### Scenario: Deelnemingsvrijstelling is permanent

- **GIVEN** een ontvangen dividend van een 10%-deelneming van EUR 480K dat onder de deelnemingsvrijstelling valt
- **WHEN** de belastinglast wordt berekend
- **THEN** ontstaat GEEN `temporary-difference` regel
- **AND** verschijnt het bedrag wel in de `tax-rate-reconciliation` als reconciliation item met `type=permanent`, `taxEffect=-123840` (EUR 480K × 25,8%)

#### Scenario: Garantievoorziening is tijdelijk

- **GIVEN** een garantievoorziening van EUR 200K die commercieel wordt opgenomen maar fiscaal pas aftrekbaar is wanneer feitelijke garantie-uitgaven worden gedaan
- **WHEN** de tijdelijke-verschillen-detectie draait
- **THEN** ontstaat een `temporary-difference` regel met `type=deductible`, `temporaryDifference=-200000`, `deferredTaxBalance=51600` (DTA)

### Requirement: REQ-DT-003 Compensabele-verliezen-administratie per ontstaansjaar

Het systeem MOET fiscaal compensabele verliezen per jurisdictie en per ontstaansjaar bijhouden, inclusief het toepasselijke compensatieregime (oude 6-jaars regel, overgangsregime, of huidige onbeperkte regime met 50%-bovengrens).

#### Scenario: Verliescompensatie 2026 onder huidig regime

- **GIVEN** een open verliessaldo van EUR 3,2M uit 2024 en een fiscale winst 2026 van EUR 1,8M
- **WHEN** de verrekening wordt berekend
- **THEN** wordt de eerste EUR 1M volledig verrekend (100%) en daarboven EUR 400K (50% van EUR 800K), totaal EUR 1,4M
- **AND** wordt de `tax-loss-carry-forward` voor 2024 bijgewerkt: `utilisedAmount += 1400000`, `remainingAmount = 1800000`
- **AND** is de belastinggrondslag 2026 = EUR 400K, Vpb-last = EUR 76K (EUR 200K × 19% + EUR 200K × 25,8%)

### Requirement: REQ-DT-004 Recoverability assessment voor DTA op verliezen

Het systeem MOET voor elke geactiveerde uitgestelde belastingvordering uit compensabele verliezen een onderbouwde verwachting van toekomstige fiscale winst eisen, en deze onderbouwing bewaren als bewijs voor de accountant.

#### Scenario: DTA op verlies wordt voor 60% geactiveerd

- **GIVEN** een open verlies van EUR 5M en een meerjarenraming die EUR 3M aan toekomstige fiscale winst over 5 jaar voorspelt
- **WHEN** de recoverability-toets draait
- **THEN** wordt slechts 60% (EUR 3M / EUR 5M) van het potentiële DTA geactiveerd, namelijk EUR 3M × 25,8% = EUR 774K
- **AND** verplicht het systeem een `dtaRecoverabilityRationale` met verwijzing naar `linkedProjections`
- **AND** verschijnt het niet-geactiveerde deel als "unrecognised DTA" in de toelichting

### Requirement: REQ-DT-005 Tariefwijziging direct verwerken op uitgestelde balans

Het systeem MOET zodra een tariefwijziging "substantively enacted" is (parlementair aangenomen, ook als ingangsdatum in de toekomst ligt) alle uitgestelde belastingposities herwaarderen tegen het nieuwe tarief op verwachte omkeerdatum.

#### Scenario: Belastingplan kondigt tariefverhoging aan

- **GIVEN** een totale uitgestelde belastingverplichting van EUR 850K berekend tegen 25,8%
- **AND** een aangenomen Belastingplan dat per 2028 het hoogste Vpb-tarief verhoogt naar 27%
- **WHEN** de jaarafsluiting per 31-12-2026 draait
- **THEN** worden tijdelijke verschillen waarvan verwachte omkeer in 2028 of later ligt herwaardeerd tegen 27%
- **AND** verschijnt het effect als `rateChangeAdjustment` in de `deferred-tax-movement` regel
- **AND** wordt het effect in de ETR-tabel apart toegelicht als "rate change"

### Requirement: REQ-DT-006 Effectieve-belastingvoet-reconciliatie als calculatie

Het systeem MOET een `tax-rate-reconciliation`-record produceren per periode per jurisdictie als declaratieve `x-openregister-calculations` output, niet als PHP-rapport-service.

#### Scenario: ETR-tabel voor jaarrekening 2026

- **GIVEN** een commerciële winst voor belasting van EUR 4,2M en effectieve Vpb-last van EUR 950K
- **WHEN** de ETR-calculatie draait
- **THEN** produceert het systeem een tabel: statutory rate × profit = EUR 1,083M; +/- permanent differences (deelnemingsvrijstelling -EUR 124K, niet-aftrekbare relatiegeschenken +EUR 12K), +/- prior-year adjustments, +/- rate changes, totaal = EUR 950K = 22,6% ETR
- **AND** wordt deze tabel automatisch opgenomen in `bookkeeping-financial-statements` toelichting

### Requirement: REQ-DT-007 Per-jurisdictie aparte voering

Het systeem MOET per jurisdictie (NL, en optioneel buitenlandse vaste inrichtingen of dochters) aparte sets van temporary differences, loss carry-forwards en tax rate reconciliations voeren, zonder onderlinge verrekening behalve waar fiscale eenheid expliciet is.

#### Scenario: Vaste inrichting Duitsland separaat

- **GIVEN** een NL-moeder met een Duitse vaste inrichting (Betriebsstätte) waarop Duits Vpb-tarief 30% van toepassing is
- **WHEN** uitgestelde posities worden berekend
- **THEN** ontstaat een aparte `temporary-difference` set met `jurisdiction=DE`, `taxRate=30%`
- **AND** worden Duitse compensabele verliezen apart bijgehouden in `tax-loss-carry-forward` met `jurisdiction=DE`
- **AND** verschijnt een aparte ETR-tabel per jurisdictie naast de geconsolideerde tabel

### Requirement: REQ-DT-008 Salderingsregels DTA/DTL op balans

Het systeem MOET op de gepresenteerde balans uitgestelde vorderingen en verplichtingen salderen alleen waar IAS 12 §74 dat toestaat: binnen dezelfde belastingjurisdictie en wanneer de entiteit het wettelijke recht heeft tot saldering.

#### Scenario: Saldering binnen NL fiscale eenheid

- **GIVEN** een fiscale eenheid Vpb met DTA EUR 320K en DTL EUR 480K, beide voor NL-jurisdictie
- **WHEN** de balanspresentatie wordt berekend
- **THEN** verschijnt op de balans één netto uitgestelde belastingverplichting van EUR 160K
- **AND** is in de toelichting de bruto DTA en bruto DTL apart zichtbaar
- **AND** wordt `presentationOnBalanceSheet=net` gemarkeerd

### Requirement: REQ-DT-009 Jaarmutatie (roll-forward) per categorie

Het systeem MOET per categorie tijdelijk verschil een complete jaarmutatie tonen: openingsbalans → ontstaan → terugneming → tariefeffect → bedrijfscombinatie → koersverschillen → eindbalans.

#### Scenario: Roll-forward MVA-categorie

- **GIVEN** een openingsbalans deferred tax liability op MVA van EUR 380K per 1-1-2026
- **WHEN** de jaarmutatie wordt opgesteld
- **THEN** toont de roll-forward: opening EUR 380K + ontstaan EUR 95K (nieuw verschil 2026) − terugneming EUR 42K (omkering oude verschillen) + rate change EUR 8K = sluiting EUR 441K
- **AND** is de mutatie via P&L EUR 53K (95-42) en via tariefwijziging EUR 8K, totaal P&L-effect EUR 61K
- **AND** wordt elk component traceerbaar getoond met onderliggende grootboekmutaties

### Requirement: REQ-DT-010 Aansluiting met Vpb-aangifte

Het systeem MOET een sluitende reconciliatie produceren tussen de commerciële belastinglast (current + deferred) en het bedrag dat in de Vpb-aangifte (uit `bookkeeping-vpb-mkb`) wordt aangegeven als te betalen Vpb.

#### Scenario: Vpb-aangifte versus jaarrekening 2026

- **GIVEN** een Vpb-aangifte 2026 met aangegeven verschuldigde Vpb van EUR 540K (current tax)
- **AND** een berekende deferred tax beweging van +EUR 410K (uitgestelde belastinglast)
- **WHEN** de reconciliatie draait
- **THEN** wordt totaal belastinglast P&L = EUR 540K + EUR 410K = EUR 950K
- **AND** stemt dit overeen met de ETR-reconciliatie uit REQ-DT-006
- **AND** verschijnt het verschil EUR 410K als mutatie op de balanspost `tax-provision.netDtaDtlPosition`

## Standards & Sources

Primaire standaarden: **IAS 12 Income Taxes** (IFRS, IASB), **RJ 272 Belastingen naar de winst** (Raad voor de Jaarverslaggeving, Nederlandse equivalent voor middenstand), **IFRS for SMEs Section 29 Income Tax** (voor entiteiten die IFRS for SMEs toepassen). **Wet op de vennootschapsbelasting 1969** (Wet Vpb) artikelen 8 (winstbepaling), 20 (verliescompensatie post-2022), 20a (overgangsregime), 13 (deelnemingsvrijstelling), 15 (fiscale eenheid). **Belastingplan 2026** voor actuele tarieven (19% schijf tot EUR 200K, 25,8% daarboven; eventuele aangekondigde wijzigingen substantively enacted bij parlementaire aanname). **Besluit Fiscale eenheid 2003** voor saldering en consolidatie binnen fiscale eenheid.

Praktijkbronnen: KPMG *IFRS Handbook: Income Taxes*, PwC *Manual of Accounting — Income Taxes*, Deloitte iGAAP *Income Taxes*, EY *International GAAP — Income Tax*. NBA-handreiking 1141 *Belastingen in de jaarrekening*. NIVRA/NBA *Praktijkhandreiking Vpb in de jaarrekening MKB*. SRA *Vaktechnisch bulletin Vpb*. Voor recoverability-toets: IAS 12 §34-36, RJ 272.305-307; voor presentatie en saldering: IAS 12 §71-78. SBR-/XBRL-aangifte Vpb via Belastingdienst, NT2026 taxonomie. Voor tariefwijzigingen: IAS 12 §47-48 ("substantively enacted").

## Cross-app integration

- **bookkeeping-general-ledger** — bron voor commerciële boekwaarden op balansposten; uitgestelde belastingberekening leest grootboeksaldi per `Account` op balansdatum.
- **bookkeeping-financial-statements** — consumeert de `tax-rate-reconciliation` voor de ETR-toelichting en de `tax-provision` voor de balanspresentatie van uitgestelde belastingen.
- **bookkeeping-vpb-mkb** — levert het bedrag verschuldigde Vpb (current tax) waarmee de deferred tax brug aansluit; ook bron voor compensabele-verliezen ontstaansjaar en compensatieregime.
- **bookkeeping-consolidation-commercial** — voor groepsstructuren wordt per geconsolideerde entiteit de jurisdictie en de eigen DTA/DTL gevoerd, en in de consolidatie samengebracht zonder onderlinge saldering.
- **bookkeeping-budget-multi-year** — voor de recoverability-toets: meerjarenraming voorspelt toekomstige fiscale winst; zonder deze koppeling kan geen DTA op verliezen worden geactiveerd.
- **bookkeeping-business-combination** — bij overnames wordt de purchase price allocation gemaakt; tijdelijke verschillen op verworven activa/passiva worden via `acquiredViaBusinessCombination` in de roll-forward verwerkt.
- **bookkeeping-cbcr-pillar2** — voor multinationale groepen wordt per jurisdictie effectieve belastingdruk gemeten ten behoeve van Pillar 2 berekening; deelt dezelfde per-jurisdictie tax accounting basis.
- **openconnector → Belastingdienst SBR** — declaratieve indiening Vpb-aangifte (via `bookkeeping-vpb-mkb`) waarvan de current tax het haakje voor deze spec is.
- **docudesk** — bewaart accountantsdossier: recoverability rationale, ETR onderbouwingen, audit committee memo's over uitgestelde belastingposities.

## Target users

Primaire gebruikers zijn de **financieel controller** of **CFO** van een MKB+-onderneming (vanaf 50 medewerkers / EUR 12M omzet) die jaarrekening onder RJ of IFRS opstelt en moet rapporteren over uitgestelde belastingen. Bij grotere ondernemingen splitst de rol naar een **tax accountant** (fiscaal specialist die de tijdelijke verschillen onderkent en de ETR-reconciliatie onderhoudt) en een **group reporting manager** (die de geconsolideerde positie samenstelt). Bij entiteiten met buitenlandse activiteiten komt een **international tax manager** in beeld voor multi-jurisdictie posities. Secundaire gebruikers: de **externe accountant** voor controlewerkzaamheden (deferred tax is een van de hoogste risicogebieden in de jaarrekeningcontrole), de **belastinginspecteur** in een Vpb-controle die de aansluiting tussen aangifte en jaarrekening verifieert, de **belastingadviseur** die in de adviespraktijk de fiscale opportuniteiten en risico's beoordeelt. Strategische waarde voor Shillinq: door deferred tax als declaratieve laag bovenop GL te leveren wordt een van de duurste vakken in de Vpb-praktijk (uurtarief EUR 200-400 voor specialistisch fiscalist, tien à twintig uur per jaarrekening) deels geautomatiseerd, terwijl de auditeerbaarheid juist toeneemt doordat elke positie traceerbaar naar bron-grootboekmutatie blijft.

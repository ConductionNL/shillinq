---
status: draft
---

# bookkeeping-pension-ias19

## Purpose

Lever de volledige IAS 19 / RJ 271 pensioenadministratie voor **defined-benefit (DB) pensioenregelingen**, inclusief eigen-beheer pensioen van directeur-grootaandeelhouders (eDV / pensioen-in-eigen-beheer, hoewel sinds 2017 fiscaal uitgefaseerd, blijft commercieel aanwezig bij honderden DGA's), bedrijfstak- of ondernemingspensioenfondsen onder DB-toezegging, en hybride regelingen met DB-componenten. Voor het Nederlandse MKB ligt het zwaartepunt op de zogenaamde **collective defined contribution (CDC)** en **defined contribution (DC)** regelingen die simpeler zijn maar onder IAS 19 nog steeds disclosure vereisen. Voor grotere ondernemingen die nog een DB-belofte hebben, of een tekort/overschot bij hun pensioenuitvoerder dragen onder een **separation of administration agreement (SAA)**, is een complete IAS 19 boekhouding verplicht.

IAS 19 vereist dat de **verplichting** (de Defined Benefit Obligation, DBO) wordt gewaardeerd via de **Projected Unit Credit (PUC) methode**: per medewerker per opgebouwd dienstjaar wordt een eenheid pensioen toegekend en de bijbehorende contante waarde van de verwachte toekomstige uitkeringen wordt berekend, rekening houdend met salarisgroei, sterfte (AG-tabellen), arbeidsongeschiktheid, en de marktrente op hoogwaardige bedrijfsobligaties op de balansdatum. Het **fondsvermogen** (plan assets, indien aanwezig) wordt tegen reële waarde gemeten. Het verschil DBO − Plan Assets is de netto pensioenpositie: een **netto verplichting** als DBO > assets, een **netto vordering** als assets > DBO (in dat laatste geval gelimiteerd door de asset ceiling van IFRIC 14).

De jaarlijkse beweging valt uiteen in drie buckets: (1) **service cost** = aanwas pensioenopbouw huidige periode + past service cost bij regelingwijzigingen (P&L), (2) **net interest** = disconteringsvoet × netto pensioenpositie aan begin periode (P&L, financiële last/bate), (3) **remeasurements** = actuariële winsten/verliezen op DBO en plan-asset-rendementen boven/onder interest (OCI, nooit gerecycled naar P&L). Voor entiteiten die nog onder pre-2013 IAS 19 zaten was de keuze tussen corridor-methode en direct-naar-P&L een hoofdpijndossier; de huidige IAS 19R verplicht alles via OCI voor remeasurements, P&L voor service + net interest.

Deze spec introduceert binnen Shillinq een complete IAS 19 motor met: een **plan-register** voor regelingsbeschrijvingen, een **actuariële waarderingsregister** voor de jaarlijkse DBO en plan-asset-cijfers (gevoed door externe actuaris of door declaratieve calculatie), een **roll-forward** per pensioenplan per periode met alle componenten, een **gevoeligheidsanalyse** (sensitivity) op de hoofdaannames, een complete **disclosure-tabel** voor de jaarrekening, en koppelingen naar HRMQ voor de pensioenadministratie van actieve medewerkers. Voor MKB+-tenants met een DC-regeling is een lichtere disclosure-flow voldoende; voor entiteiten met substantiële DB-overhang is de complete machine nodig.

## Data Model

Zes nieuwe schemas in een uitbreiding op de bestaande `bookkeeping` register.

**`pension-plan`** beschrijft de regeling. Attributes: `id`, `planName`, `planType` (DB / DC / CDC / hybrid), `country` (NL / DE / etc.), `regulatoryFramework` (Pensioenwet / BPW / vrijgesteld / IORP-II buitenland), `funded` (boolean), `provider` (text — pensioenfonds, verzekeraar, eigen beheer), `providerLEI` (text, optional), `inceptionDate` (date), `terminationDate` (date, optional), `eligibilityRules` (text), `accrualRate` (decimal, only DB — bijv. 1,875% van pensioengrondslag), `pensionableSalaryDefinition` (text), `retirementAge` (int), `participantCountActive` (int), `participantCountDeferred` (int), `participantCountRetirees` (int), `linkedHrmqGroup` (FK hrmq-group, optional), `governanceDocument` (file — pensioenreglement).

**`actuarial-valuation`** is de jaarlijkse waardering door een actuaris (extern of declaratief intern). Attributes: `plan` (FK), `valuationDate` (date), `actuary` (text), `actuaryCertificationNumber` (text), `dboGross` (decimal — bruto DBO), `dboPastService` (decimal — opgebouwd verleden), `dboFutureService` (decimal — verwachte aanwas resterende dienstjaren), `methodology` (PUC verplicht voor DB), `discountRate` (decimal — hoogwaardige bedrijfsobligaties NL/EU AA-rating, conform IAS 19 §83), `salaryGrowthAssumption` (decimal), `pensionGrowthAssumption` (decimal), `inflationAssumption` (decimal), `mortalityTable` (text — bijv. "AG-prognosetafel 2026"), `mortalityCorrection` (text — fonds-specifieke correctie), `disabilityRate` (decimal), `withdrawalRate` (decimal), `retirementAgeAssumption` (int), `planAssetsFairValue` (decimal), `assetCeilingApplied` (decimal — IFRIC 14), `netPensionLiability` (computed: dboGross − planAssetsFairValue + assetCeilingAdjustment), `valuationReport` (file).

**`pension-movement`** is de jaarlijkse roll-forward. Attributes: `plan`, `period`, `dboOpening`, `serviceCostCurrent` (decimal — P&L), `pastServiceCost` (decimal — P&L, regelingwijziging), `gainOnSettlement` (decimal — P&L), `netInterestCost` (decimal — P&L), `actuarialLossGainDBO` (decimal — OCI, uitgesplitst naar `dueToDemographicChanges` / `dueToFinancialChanges` / `dueToExperienceAdjustments`), `benefitsPaid` (decimal — onttrekking), `dboClosing` (computed), `planAssetsOpening`, `expectedReturnOnAssets` (decimal — geïmpliceerd door net interest), `actualReturnOnAssets` (decimal — werkelijk rendement), `actuarialGainLossAssets` (decimal — OCI, verschil werkelijk vs verwacht), `employerContributions` (decimal), `employeeContributions` (decimal), `benefitsPaidFromAssets` (decimal), `planAssetsClosing` (computed), `netPensionMovementPL` (computed: serviceCost + pastService + netInterest − settlements), `netPensionMovementOCI` (computed: actuarialLossGainDBO − actuarialGainLossAssets), `linkedJournalEntries` (array FK).

**`pension-assumption-sensitivity`** levert per balansdatum de gevoeligheid van DBO op aanname-wijzigingen. Attributes: `valuation` (FK), `assumption` (discount-rate / salary-growth / mortality / inflation), `direction` (+0.5pp / -0.5pp / +1pp / -1pp), `effectOnDBO` (decimal), `effectOnServiceCost` (decimal).

**`pension-asset-detail`** voor regelingen met materiële plan assets (typisch ondernemingspensioenfondsen). Attributes: `valuation`, `assetCategory` (cash / equities-quoted / bonds-government / bonds-corporate / real-estate / alternative / derivatives), `fairValue` (decimal), `level` (1 / 2 / 3 — IFRS 13 fair-value hiërarchie).

**`pension-disclosure-tabel`** is de samenvattende disclosure-tabel die in de jaarrekening verschijnt, gegenereerd uit de bovenstaande records.

## Requirements

### Requirement: REQ-PEN-001 PUC-methode verplicht voor DB-regelingen

Het systeem MOET voor elke `pension-plan` met `planType=DB` afdwingen dat de `actuarial-valuation` de Projected Unit Credit methode toepast, conform IAS 19 §67.

#### Scenario: DB-regeling actuariële waardering 2026

- **GIVEN** een eigen-beheer DB-toezegging aan een DGA met 1,875% accrual, pensioengrondslag EUR 80K, 20 dienstjaren tot pensioenleeftijd
- **WHEN** de actuariële waardering wordt opgenomen
- **THEN** vereist het systeem `methodology=PUC`; andere waarden worden geweigerd
- **AND** wordt `dboGross` per medewerker opgebouwd: voor elk verleden-dienstjaar één eenheid pensioen × salarisgroei-aanname × disconteringsvoet
- **AND** wordt het totaal getoond met onderverdeling actieven / slapers / gepensioneerden

### Requirement: REQ-PEN-002 Disconteringsvoet uit hoogwaardige bedrijfsobligaties

De `discountRate` in elke `actuarial-valuation` MOET aansluiten op de yield van hoogwaardige (AA-rating) bedrijfsobligaties in de relevante valuta met looptijden die overeenkomen met de duration van de DBO, conform IAS 19 §83.

#### Scenario: Disconteringsvoet voor NL DBO met 18-jaars duration

- **GIVEN** een DBO met gemiddelde duration 18 jaar in EUR
- **WHEN** de actuaris de disconteringsvoet vaststelt voor 31-12-2026
- **THEN** wordt een rate gebruikt die past bij iBoxx € Corporates AA index voor 15-20 jaars looptijden
- **AND** wordt de bron expliciet vermeld als veld in de `actuarial-valuation` (audit-trail vereist)
- **AND** valt het bij toepassing van een government-bond rate (lager) onder een spec-violation met waarschuwing in de pre-merge check

### Requirement: REQ-PEN-003 Drie-buckets bewegingsuitsplitsing P&L / OCI

Het systeem MOET de jaarbeweging in de netto pensioenpositie volledig uitsplitsen naar (1) service cost + past service + settlement (P&L: personeelslasten), (2) net interest (P&L: financiële lasten), (3) remeasurements (OCI), conform IAS 19R.

#### Scenario: Jaarbeweging 2026 met EUR 8M DBO en EUR 6,5M assets

- **GIVEN** een fonds met opening DBO EUR 8M, plan assets EUR 6,5M, netto verplichting EUR 1,5M
- **AND** een disconteringsvoet 2,0% en service cost EUR 320K
- **WHEN** de jaarbeweging wordt opgesteld
- **THEN** wordt service cost EUR 320K P&L (personeelslasten)
- **AND** wordt net interest EUR 30K (EUR 1,5M × 2%) P&L (financiële lasten)
- **AND** worden actuariële verschillen op DBO (bijv. EUR 180K verlies door rentedaling) en op assets (bijv. EUR 90K winst boven expected return) als netto EUR 90K OCI-verlies geboekt
- **AND** worden deze drie buckets nooit door elkaar gehaald

### Requirement: REQ-PEN-004 OCI is non-recycling

Remeasurements (actuariële verschillen) worden via OCI gepresenteerd en MOGEN NOOIT in latere periodes naar P&L worden gerecycleerd, conform IAS 19 §122.

#### Scenario: Actuarieel verlies van EUR 250K in 2026 blijft in OCI

- **GIVEN** een actuarieel verlies van EUR 250K in 2026 via OCI
- **WHEN** in 2027 of later het verlies omkeert door rentestijging
- **THEN** wordt de winst eveneens via OCI verwerkt, niet via P&L
- **AND** kan de gebruiker geen transactie aanmaken die OCI-pensioenposten via P&L vrijgeeft

### Requirement: REQ-PEN-005 Asset ceiling toepassen bij netto-vordering

Als de actuariële waardering een netto pensioenvordering oplevert (plan assets > DBO) MOET het systeem de asset ceiling van IFRIC 14 toepassen: de vordering is beperkt tot het maximum dat de entiteit kan terugkrijgen via terugbetalingen of vermindering van toekomstige bijdragen.

#### Scenario: Plan assets EUR 9M, DBO EUR 7,5M

- **GIVEN** plan assets EUR 9M, DBO EUR 7,5M (overschot EUR 1,5M)
- **AND** een pensioenreglement dat slechts EUR 800K aan toekomstige bijdragereductie toestaat
- **WHEN** de actuariële waardering wordt opgenomen
- **THEN** wordt `assetCeilingApplied=-700000` (EUR 1,5M overschot − EUR 800K maximum), netto vordering = EUR 800K
- **AND** verschijnt de beperking als aparte toelichtingsregel

### Requirement: REQ-PEN-006 Sensitivity disclosure op hoofdaannames

Voor elke DB-regeling MOET het systeem per balansdatum een sensitivity-analyse produceren op minimaal vier aannames (disconteringsvoet, salarisgroei, sterfte, inflatie) met +/- relevante bandbreedte.

#### Scenario: Sensitivity disconteringsvoet ±0,5pp

- **GIVEN** een DBO van EUR 8M bij disconteringsvoet 2,0%
- **WHEN** de sensitivity wordt berekend
- **THEN** verschijnt: discount rate +0,5pp → DBO EUR 7,3M (effect −EUR 700K); discount rate −0,5pp → DBO EUR 8,8M (effect +EUR 800K)
- **AND** worden vergelijkbare regels voor salary growth (+/-0,5pp), mortality (+/-1 jaar levensverwachting), inflation (+/-0,5pp) geproduceerd
- **AND** verschijnt deze tabel in de jaarrekening-toelichting onder "Sensitivity-analyse pensioenverplichtingen"

### Requirement: REQ-PEN-007 Disclosure-tabel jaarrekening

Het systeem MOET een complete disclosure-tabel produceren conform IAS 19 §135-149: regelingsbeschrijving, aannames, mutatie DBO, mutatie plan assets, geboekte bedragen in P&L en OCI, asset-categorieën, looptijden, verwachte toekomstige bijdragen.

#### Scenario: Disclosure-tabel jaarrekening 2026

- **GIVEN** een actieve DB-regeling met complete `actuarial-valuation`, `pension-movement` en `pension-asset-detail` voor 2026
- **WHEN** de jaarrekeningtoelichting wordt gegenereerd
- **THEN** verschijnt een gestandaardiseerde tabel: hoofdaannames + DBO-mutatie + Asset-mutatie + Geboekt in P&L (uitgesplitst service / interest / settlement) + Geboekt in OCI (uitgesplitst demographic / financial / experience / asset return) + Asset breakdown per categorie + Duration DBO + Verwachte werkgeversbijdrage volgend jaar
- **AND** sluit het overzicht volledig aan op de `pension-movement` records

### Requirement: REQ-PEN-008 DC-regeling lichte disclosure

Voor `planType=DC` (defined contribution) MOET het systeem alleen lichte disclosure produceren: bijdrage in periode + overzicht regelingen, conform IAS 19 §53.

#### Scenario: DC-regeling met EUR 480K werkgeversbijdrage 2026

- **GIVEN** een DC-regeling met EUR 480K aan werkgeversbijdragen over 2026
- **WHEN** de jaarrekeningtoelichting wordt gegenereerd
- **THEN** verschijnt enkel "Pensioenlasten DC-regelingen: EUR 480K" plus een korte regelingsbeschrijving
- **AND** wordt geen actuariële waardering of complexe disclosure geproduceerd

### Requirement: REQ-PEN-009 Past service cost direct in P&L bij regelingwijziging

Bij een regelingwijziging (plan amendment) MOET de past service cost direct in P&L worden geboekt op datum van wijziging, conform IAS 19 §103 (geen meer-jarige spreiding).

#### Scenario: Pensioenleeftijd verhoogd van 67 naar 68 in 2026

- **GIVEN** een DB-regeling waarvan de pensioenleeftijd op 1-7-2026 wordt verhoogd van 67 naar 68 met een tegenboekingseffect EUR 240K (negatieve past service cost = afname DBO)
- **WHEN** de wijziging wordt verwerkt
- **THEN** wordt `pastServiceCost=-240000` direct in P&L geboekt op 1-7-2026 (personeelslasten verminderen)
- **AND** wordt deze gebeurtenis apart vermeld in de toelichting met datum, aard en effect

### Requirement: REQ-PEN-010 HRMQ-koppeling voor actieve deelnemers

Het systeem MOET per `pension-plan` met `linkedHrmqGroup` periodiek (jaarlijks of bij significante mutaties) het deelnemersbestand uit HRMQ verifiëren, zodat in/uitdiensttredingen, leeftijden en salarissen in de actuariële waardering correct zijn.

#### Scenario: Synchronisatie deelnemersbestand jaar 2026

- **GIVEN** een DB-plan gekoppeld aan hrmq-group "vaste-staf-NL"
- **WHEN** de jaarlijkse pensioencyclus opstart per 1-12-2026
- **THEN** trekt het systeem alle actieve medewerkers met geboortedatum, salaris, dienstjaren uit HRMQ
- **AND** vergelijkt dit met `participantCountActive` van vorige waardering
- **AND** rapporteert verschillen (nieuwe deelnemers, vertrokken, salariswijzigingen) ter validatie door HR-controller voordat de actuariële waardering wordt vastgezet

## Standards & Sources

Primaire standaarden: **IAS 19 Employee Benefits** (IASB, revised 2011 versie), **RJ 271 Personeelsbeloningen** (Raad voor de Jaarverslaggeving), **IFRS for SMEs Section 28 Employee Benefits**. Implementatie-guidance: **IFRIC 14 IAS 19 — The Limit on a Defined Benefit Asset, Minimum Funding Requirements and their Interaction**. Nederlandse pensioenwet- en regelgeving: **Pensioenwet** (Pw, 2007), **Wet verplichte beroepspensioenregeling (Wvb)**, **Besluit financieel toetsingskader pensioenfondsen**, **Wet toekomst pensioenen (Wtp, 2023)** voor de transitie naar premiepensioen, **fiscale uitfasering eigen-beheer pensioen** (Wet Uitfasering PEB, 2017). Voor disconteringsvoet: **iBoxx € Corporates AA** indices als praktijk-referentie, **IAS 19 BC §141** voor de keuze tegen government bonds. Actuariële standaarden: **AG-tafels** (Actuarieel Genootschap), specifiek **AG-Prognosetafel 2026** voor sterftekansen (jaarlijkse update), **richtlijn ARG-1** voor actuariële gedragsregels. Praktijksources: Big-4 *IAS 19 handbooks* (KPMG, PwC, Deloitte, EY), NBA-handreiking *Pensioen in de jaarrekening*, Mercer/WTW/Aon Hewitt practitioner papers, DNB-uiting over pensioenfondsadministratie, AFM-toezicht op IFRS-jaarrekening van beursgenoteerde entiteiten.

## Cross-app integration

- **bookkeeping-voorzieningen-claims** — pensioenvoorziening is conceptueel een specialisatie van `provision` met type=pensioen; deze spec levert de actuariële detail-waardering, voorzieningen-spec de algemene structuur en toelichtingaansluiting.
- **bookkeeping-general-ledger** — service cost, past service cost en net interest worden via journaalposten in de juiste P&L-categorieën geboekt (personeelslasten resp. financiële lasten); OCI-bewegingen via aparte OCI-rekeningen die in het eigen vermogen verschijnen.
- **bookkeeping-financial-statements** — de IAS 19 disclosure-tabel (REQ-PEN-007) is een rapportagecomponent die het volledige overzicht in de toelichting verzorgt.
- **bookkeeping-deferred-tax** — pensioenverplichting genereert tijdelijke verschillen (commerciële opname versus fiscale opname pas bij betaling); de deferred-tax-detector leest `pension-movement` voor de jaarlijkse DTA-mutatie.
- **hrmq** — bron voor deelnemersbestand: medewerkers, geboortedata, salarissen, dienstjaren, in/uitdiensttredingen. De `pension-administration`-module in HRMQ (separate spec) houdt de actieve administratie bij; deze spec consumeert dat via `linkedHrmqGroup`.
- **openconnector → externe actuaris** — voor entiteiten die met externe actuariële bureaus werken (Mercer, WTW, AON, Sprenkels) kan de actuariële waardering via een connector worden opgehaald in plaats van handmatig ingevoerd.
- **docudesk** — bewaart pensioenreglement, actuariële rapporten, asset-management-overzichten, pensioenfonds-jaarverslagen onder restricted access.
- **decidesk** — regelingwijzigingen met materieel effect (>EUR 100K past service cost) routeren via management/audit committee goedkeuring.

## Target users

Primair de **CFO** of **group reporting manager** van een entiteit met DB-pensioenverplichtingen die de jaarrekening onder RJ of IFRS opstelt. In MKB+ vaak gecombineerd met **HR-directeur** of **pensioencoördinator** die de regeling beheert. Voor entiteiten met externe actuaris fungeert deze als **technisch gebruiker** die de waardering aanlevert; voor entiteiten die de berekening intern doen is een **actuarieel medewerker** of ingehuurde actuaris vereist (vrijwel altijd extern in MKB+, soms wel een gepensioneerd actuaris in dienst bij grote werkgevers). Secundair: de **externe accountant** voor controle van pensioenverplichtingen (vrijwel altijd een hoog-risico-gebied vanwege subjectieve aannames en grote bedragen), het **audit committee** of de **commissaris** voor het goedkeuren van wijzigingen, en **DNB** of **AFM** voor toezicht op pensioenuitvoerders. Tertiair: medewerkers zelf (via UPO — Uniform Pensioen Overzicht) en gepensioneerden (via pensioenuitvoerder). Strategische waarde voor Shillinq: een complete IAS 19 implementatie is voor de meeste MKB+-controllers vandaag een Excel- of derden-systeem; door dit declaratief en geïntegreerd met GL en HRMQ te leveren wordt een specialistisch gebied (waar accountants 30-50K aan honoraria voor in rekening brengen) toegankelijk binnen het standaard administratieplatform, en blijft de auditeerbaarheid hoger doordat alle aannames en mutaties traceerbaar in één register staan.

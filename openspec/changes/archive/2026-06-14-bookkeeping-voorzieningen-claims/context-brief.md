---
status: draft
---

# bookkeeping-voorzieningen-claims

## Purpose

Lever het volledige systeem voor het herkennen, waarderen, muteren en toelichten van **voorzieningen** in de jaarrekening conform **IAS 37 Provisions, Contingent Liabilities and Contingent Assets** en de Nederlandse equivalent **RJ 252 Voorzieningen, niet uit de balans blijkende verplichtingen en niet uit de balans blijkende activa**. Een voorziening is een verplichting waarvan het bestaan, het bedrag of het tijdstip onzeker is — fundamenteel anders dan een gewone crediteurenpost (waar alle drie vast staan) en anders dan een reserve (die geen verplichting is maar een afgezonderd deel van het eigen vermogen). De technische definitie vereist drie elementen: een **in-uitvoering-of-rechtens-afdwingbare verplichting** als gevolg van een gebeurtenis in het verleden, een **waarschijnlijke (> 50%) uitstroom van middelen**, en een **betrouwbare schatting** van het bedrag. Falen op één van de drie betekent geen voorziening op de balans, hooguit een toelichting als niet-uit-de-balans-blijkende verplichting.

Voor het Nederlandse MKB+ is dit conceptueel een van de moeilijkste posten omdat de praktische voorzieningen erg uiteenlopen: **pensioenvoorziening** (eigen-beheer pensioen, RJ 271 / IAS 19 — zie aparte spec), **jubileumvoorziening** voor de 25- en 40-jaars uitkeringen volgens CAO, **herstructureringsvoorziening** (alleen wanneer een gedetailleerd reorganisatieplan op balansdatum is gecommuniceerd of geïmplementeerd — strenge eis), **garantievoorziening** voor verkochte producten of opgeleverde projecten, **milieuvoorziening** voor verplichte bodemsanering / asbestverwijdering / ontmanteling, **voorziening claims en geschillen** voor lopende rechtszaken, **voorziening latente belastingverplichtingen** (zie deferred-tax spec), **voorziening groot onderhoud** voor cyclisch groot onderhoud van vastgoed, en de specifieke **dubieuze-debiteuren-voorziening** (eigenlijk een waarderingsafslag op debiteuren, in NL-praktijk vaak als voorziening geboekt).

Elke voorziening kent een levenscyclus: **vorming** (initial recognition) → **mutatie** (toevoeging via dotatie, vrijval ten gunste van resultaat, onttrekking via betaling) → **opheffing**. Bovendien vereist IAS 37 een **best-estimate** waardering, een **sensitivity analyse** (bandbreedte van mogelijke uitkomsten), en zodra de tijdshorizon materiaal is een **disconteringsvoet**-toepassing (DCF van verwachte uitstromen). Deze spec introduceert binnen Shillinq een uniforme voorzieningenmotor: per voorziening-type een eigen schema-uitbreiding op een gedeelde `Provision`-basis, een bewegingsoverzicht per voorziening per jaar, een jaarlijkse herwaarderingscyclus, een verplichte aansluiting met de toelichting in `bookkeeping-financial-statements`, en een audit-pakket per voorziening (rationale, schattingsbron, betrokken specialist, peer-review).

## Data Model

Drie centrale schemas in de bestaande `bookkeeping` register, plus per-type uitbreidingsregisters.

**`provision`** is de polymorfe hoofdregel. Attributes: `id`, `provisionType` (pensioen / jubileum / herstructurering / garantie / milieu / claims-en-geschillen / groot-onderhoud / dubieuze-debiteuren / overig), `description` (text), `recognitionDate` (date — datum eerste opname), `recognitionRationale` (text — onderbouwing waarom IAS 37 / RJ 252 criteria zijn voldaan), `legalOrConstructiveObligation` (legal / constructive), `obligatingEvent` (text — beschrijving gebeurtenis die verplichting deed ontstaan), `probabilityOfOutflow` (decimal 0-1, moet > 0.5), `bestEstimate` (decimal — beste schatting middelenuitstroom), `bestEstimateRationale` (text), `rangeLow` / `rangeHigh` (decimal — sensitivity bandbreedte), `expectedTiming` (object: `{shortTerm: decimal, mediumTerm: decimal, longTerm: decimal}` — verwachte uitstroom binnen 1 / 1-5 / >5 jaar), `discountRateApplied` (decimal, optional), `discountedValue` (computed wanneer disconteringsvoet > 0), `presentationOnBalanceSheet` (current / non-current / split), `linkedAccount` (FK Account — balansrekening waarop voorziening verschijnt), `status` (active / settled / released), `expert` (text — naam externe expert die schatting onderbouwt, indien van toepassing), `peerReviewer` (FK user), `peerReviewDate` (date).

**`provision-movement`** is de jaarmutatie per voorziening. Attributes: `provision` (FK), `period`, `openingBalance` (decimal), `additions` (decimal — dotatie, ten laste van resultaat), `additionsAcquired` (decimal — overgenomen via bedrijfscombinatie), `usedDuringPeriod` (decimal — onttrekking via betaling), `releasedUnused` (decimal — vrijval omdat verplichting kleiner blijkt), `unwindingOfDiscount` (decimal — rente-effect tijdsverloop bij disconteerde voorzieningen), `effectOfChangeInDiscountRate` (decimal), `effectOfChangeInEstimate` (decimal — bijstelling schatting), `translationDifferences` (decimal), `closingBalance` (computed: opening + additions + acquired + unwinding + rate-change + estimate-change − used − released + translation), `linkedJournalEntries` (array FK).

**`contingent-liability`** is de toelichtingsregel voor niet-opgenomen verplichtingen (geen voorziening, wel disclosure). Attributes: `description`, `obligationType` (legal / constructive), `nature` (lopende rechtszaak / borgstelling / huurgarantie / earn-out-clausule / fiscaal geschil / overig), `estimatedAmount` (decimal, optional — kan "onbepaalbaar" zijn), `probabilityCategory` (remote / possible / probable-but-no-reliable-estimate), `expectedTiming` (text), `disclosureNarrative` (text), `relatedParty` (FK org, optional).

**Type-specifieke uitbreidingen:**

`pensioenvoorziening-detail`: `pensionScheme` (enum), `actuarialMethod` (PUC — projected unit credit), `discountRate`, `salaryGrowthAssumption`, `mortalityTable` (AG-tabel jaartal), `participantCount`, `linkedActuaryReport` (file). Zie aparte `bookkeeping-pension-ias19` spec voor verdere details.

`jubileumvoorziening-detail`: `caoReference` (text — CAO en artikel), `eligibleEmployees` (int), `averageServiceYears` (decimal), `probabilityOfReachingMilestone` (decimal — kans dat medewerker daadwerkelijk jubileum haalt, op basis van turnover-statistiek), `actuarialModel` (text).

`herstructureringsvoorziening-detail`: `detailedPlanDate` (date — vereist door IAS 37 §72), `planCommunicatedTo` (array — affected parties), `affectedEmployees` (int), `expectedRedundancyPayments` (decimal), `expectedLeaseExitCosts` (decimal), `expectedAssetWriteDowns` (decimal — let op: writedowns vallen onder IAS 36, niet in voorziening), `expectedOnerousContractCosts` (decimal).

`garantievoorziening-detail`: `productCategories` (array), `historicalClaimRate` (decimal — % van omzet dat historisch claim wordt), `averageClaimAmount` (decimal), `warrantyPeriodMonths` (int), `revenueBaseInPeriod` (decimal — omzet die garantie genereert).

`milieuvoorziening-detail`: `contaminationLocation` (text), `regulatoryFramework` (Wbb / Wm / EU IED), `cleanupEstimate` (decimal), `expertConsultant` (text), `legallyRequiredCompletionDate` (date), `phasedExecutionPlan` (text), `ontmantelingsVerplichting` (boolean — voor IFRS 16 / IAS 16 §16(c) ontmantelingsverplichtingen die als component van MVA worden geactiveerd).

`claims-voorziening-detail`: `caseReference` (text — rolnummer), `court` (text), `legalCounsel` (text), `claimType` (contractbreuk / productaansprakelijkheid / arbeidsrecht / IE-inbreuk / belasting / overig), `plaintiffOrClaimant` (text), `amountClaimed` (decimal), `bestEstimateSettlement` (decimal), `legalAdviceMemo` (file — vertrouwelijke memo waarop schatting is gebaseerd).

`onderhoudsvoorziening-detail`: `assetReference` (FK fixed-asset of array), `maintenanceCycle` (years), `lastMajorMaintenanceDate` (date), `nextScheduledMaintenance` (date), `estimatedCost` (decimal), `inflationAssumption` (decimal). Let op: IFRS staat onderhoudsvoorziening NIET toe (moet als component van MVA worden geactiveerd onder IAS 16); deze post is alleen onder RJ 252 toegestaan voor bepaalde gevallen.

## Requirements

### Requirement: REQ-PROV-001 Drie-criteria-toets bij opname

Het systeem MOET bij elke nieuwe `provision` afdwingen dat alle drie de IAS 37 / RJ 252 criteria expliciet worden onderbouwd: bestaande verplichting uit verleden gebeurtenis, waarschijnlijke uitstroom (> 50%), en betrouwbare schatting.

#### Scenario: Voorziening kan niet worden opgenomen zonder onderbouwing

- **GIVEN** een poging tot opname van een herstructureringsvoorziening van EUR 1,2M
- **WHEN** `recognitionRationale`, `obligatingEvent` of `probabilityOfOutflow` ontbreekt of `probability ≤ 0.5`
- **THEN** weigert het systeem de opname met foutmelding "IAS 37 / RJ 252 criteria niet voldaan: [specifiek criterium]"
- **AND** wordt een suggestie gedaan om de verplichting als `contingent-liability` op te nemen indien probability tussen 0.05 en 0.5 ligt

### Requirement: REQ-PROV-002 Best-estimate met sensitivity bandbreedte

Elke `provision` MOET een best-estimate hebben PLUS een lage en hoge schattingsgrens; voor materiële voorzieningen MOET de sensitivity in de toelichting verschijnen.

#### Scenario: Milieuvoorziening met EUR 800K best-estimate

- **GIVEN** een bodemsaneringsverplichting met expert-rapport: lage schatting EUR 600K, beste EUR 800K, hoge EUR 1,4M
- **WHEN** de voorziening wordt opgenomen
- **THEN** wordt `bestEstimate=800000`, `rangeLow=600000`, `rangeHigh=1400000` opgeslagen
- **AND** verschijnt in de jaarrekeningtoelichting een zin "Het geschatte bedrag ligt tussen EUR 0,6M en EUR 1,4M; beste schatting EUR 0,8M, gebaseerd op rapport van [expert] d.d. [datum]"

### Requirement: REQ-PROV-003 Disconteringsvoet bij materiële tijdshorizon

Voor voorzieningen waarvan een materieel deel van de uitstroom > 1 jaar in de toekomst ligt MOET het systeem een disconteringsvoet toepassen die het tijdseffect en risico's specifiek voor de verplichting weerspiegelt.

#### Scenario: Ontmantelingsvoorziening met 10-jaars horizon

- **GIVEN** een ontmantelingsverplichting van EUR 2M die over 10 jaar zal worden uitgevoerd
- **AND** een risk-free rate van 2,5% (10-jaars Nederlandse staatsobligatie) plus 0,5% risico-opslag
- **WHEN** de voorziening wordt opgenomen
- **THEN** wordt `discountRateApplied=3.0%`, `discountedValue=1488000` (EUR 2M / 1.03^10)
- **AND** wordt jaarlijks via `unwindingOfDiscount` de rente bijgeboekt (EUR 44.640 in jaar 1: 1.488K × 3%) ten laste van financiële lasten

### Requirement: REQ-PROV-004 Mutatieoverzicht per voorziening per jaar

Het systeem MOET per voorziening per periode een complete mutatie tonen volgens de structuur opening → dotatie → onttrekking → vrijval → discontering-unwinding → schattingswijziging → koersverschillen → sluiting, conform IAS 37 §84.

#### Scenario: Garantievoorziening jaarmutatie 2026

- **GIVEN** een openingsbalans garantievoorziening EUR 320K per 1-1-2026
- **WHEN** de jaarmutatie wordt opgesteld voor periode 2026
- **THEN** toont `provision-movement`: opening EUR 320K + additions EUR 180K (dotatie 1,5% over omzet EUR 12M) − used EUR 95K (uitgevoerde garantie-reparaties) − released EUR 25K (vrijval, oude garantieperiode verstreken) = sluiting EUR 380K
- **AND** zijn de bewegingen elk gekoppeld aan onderliggende `linkedJournalEntries`

### Requirement: REQ-PROV-005 Herstructureringsvoorziening met gedetailleerd plan

Een herstructureringsvoorziening MAG alleen worden opgenomen als op balansdatum een gedetailleerd reorganisatieplan bestaat dat geldige expectations bij betrokken partijen heeft gewekt; het systeem MOET de planonderdelen registreren en blokkeren bij ontbreken.

#### Scenario: Voorziening reorganisatie 2026 geweigerd zonder plan

- **GIVEN** een poging tot opname van een herstructureringsvoorziening EUR 850K voor sluiting vestiging Eindhoven
- **WHEN** de gebruiker geen `detailedPlanDate` of geen `planCommunicatedTo` invult
- **THEN** weigert het systeem de opname en toont "Herstructureringsvoorziening vereist gedetailleerd plan dat op balansdatum is gecommuniceerd aan getroffen partijen (IAS 37 §72-83 / RJ 252.327-336)"
- **AND** wordt suggestie gedaan om de verwachte sluiting als `contingent-liability` met `probabilityCategory=possible` op te nemen

### Requirement: REQ-PROV-006 Claims-voorziening met legal-opinion-onderbouwing

Voor `claims-en-geschillen`-voorzieningen MOET het systeem een vertrouwelijke `legalAdviceMemo` als file-attachment vereisen met de juridische inschatting van waarschijnlijkheid en bedrag.

#### Scenario: Productaansprakelijkheidsclaim van EUR 1,5M

- **GIVEN** een lopende rechtszaak waarin EUR 1,5M wordt geclaimd en advocaat schat 60% kans op uitkering met verwachte EUR 700K
- **WHEN** de claims-voorziening wordt opgenomen
- **THEN** wordt `bestEstimate=700000`, `amountClaimed=1500000`, `probabilityOfOutflow=0.6` opgeslagen
- **AND** is `legalAdviceMemo` verplicht; zonder file weigert het systeem opname
- **AND** wordt het memo onder restricted access opgeslagen (alleen CFO, audit committee, accountant) ter bescherming van legal privilege

### Requirement: REQ-PROV-007 Onderscheid voorziening versus contingent liability

Het systeem MOET op basis van `probabilityOfOutflow` automatisch een voorgesteld pad uitstippelen: > 0.5 = voorziening op balans; 0.05-0.5 = contingent liability in toelichting; < 0.05 = remote, geen disclosure.

#### Scenario: Belastinggeschil met 30% kans op aanslag

- **GIVEN** een fiscaal geschil met aanslag EUR 400K en 30% kans op handhaving in beroep
- **WHEN** de financieel manager de verplichting wil registreren
- **THEN** voorstelt het systeem `contingent-liability` met `probabilityCategory=possible`, niet een voorziening
- **AND** verschijnt het bedrag in de toelichting "niet uit de balans blijkende verplichtingen" met de beschrijving en geschatte uitkomst

### Requirement: REQ-PROV-008 Aansluiting met jaarrekening-toelichting

Het systeem MOET per voorziening-type een geaggregeerd overzicht produceren dat één-op-één aansluit met de toelichting "Voorzieningen" in de jaarrekening (IAS 37 §85 / RJ 252.408 disclosure-eisen).

#### Scenario: Voorzieningentoelichting voor jaarrekening 2026

- **GIVEN** zes actieve voorzieningen verdeeld over 4 types: pensioen EUR 1,2M, jubileum EUR 220K, garantie EUR 380K, milieu EUR 800K
- **WHEN** de jaarrekening-toelichting wordt samengesteld
- **THEN** verschijnt een tabel per type met opening, dotatie, onttrekking, vrijval, discontering-unwinding, sluiting
- **AND** is voor elke materiële voorziening een narratieve toelichting opgenomen met aard, timing, onzekerheid en sensitivity
- **AND** is de som van alle voorzieningen aansluitend met `linkedAccount` saldi op de balans

### Requirement: REQ-PROV-009 Jaarlijkse herwaardering met schattingswijzigingen

Het systeem MOET op elke balansdatum elke actieve voorziening laten herwaarderen op basis van actuele informatie; schattingswijzigingen worden conform IAS 8 prospectief verwerkt via `effectOfChangeInEstimate`.

#### Scenario: Garantievoorziening verhoogd door slechte productserie

- **GIVEN** een garantievoorziening van EUR 380K per 1-1-2026 op basis van 1,5% historische claim-rate
- **AND** een eind-2026 herziening waaruit blijkt dat een productserie 2025 een 4% claim-rate vertoont, leidend tot verhoogde verwachte uitstroom EUR 540K
- **WHEN** de jaarlijkse herwaardering draait
- **THEN** wordt `effectOfChangeInEstimate=+160000` opgenomen in de mutatie 2026
- **AND** wordt deze schattingswijziging in de toelichting expliciet vermeld

### Requirement: REQ-PROV-010 Audit-trail en peer-review

Het systeem MOET voor elke voorziening minimaal één peer-reviewer (andere persoon dan de opnemer) registreren en de review-datum vastleggen; voor materiële voorzieningen (>EUR 100K of >1% balanstotaal) is goedkeuring door CFO of audit committee vereist.

#### Scenario: Milieuvoorziening EUR 800K vereist CFO-akkoord

- **GIVEN** een nieuwe milieuvoorziening van EUR 800K (materieel)
- **WHEN** de controller de voorziening wil opnemen
- **THEN** vraagt het systeem een peer-reviewer en een aanvullende CFO-goedkeuring
- **AND** wordt pas na beide goedkeuringen de status `active`
- **AND** blijft een audit-trail met wie wanneer welke schatting heeft gewijzigd

## Standards & Sources

Primair: **IAS 37 Provisions, Contingent Liabilities and Contingent Assets** (IASB), **RJ 252** (Raad voor de Jaarverslaggeving), **IFRS for SMEs Section 21** voor entiteiten onder IFRS for SMEs. Specifieke verwante standaarden: **IAS 19 Employee Benefits** (pensioenvoorziening — zie aparte spec), **IAS 16 §16(c)** (ontmantelingsverplichting als component MVA), **IAS 36 Impairment** (voor herstructurering-gerelateerde write-downs), **IAS 12** (latente belastingen, aparte spec), **IFRS 16 Leases** (onerous lease contracts), **IFRS 3 Business Combinations** (voorzieningen verworven in overname). Nederlandse wet en regelgeving: **Titel 9 Boek 2 BW** art. 374 (voorzieningen), 376 (pensioenvoorziening eigen beheer), 384 (waardering en voorzichtigheidsbeginsel). **Wet bodembescherming (Wbb)** voor milieuvoorzieningen. CAO's voor jubileumuitkeringen (varieert per branche). **Wet melding collectief ontslag (WMCO)** voor herstructureringsverplichtingen die aan vakbond gecommuniceerd moeten worden. **AG-tabellen** (Actuarieel Genootschap) voor sterftekansen in pensioen- en jubileumvoorziening. Praktijk: NBA Praktijkhandreiking 1141, RJ jaarlijkse uiting, NBA-uiting *Hot topics in voorzieningen*. Big-4 handboeken (KPMG / PwC / Deloitte / EY) hebben elk een uitgebreid hoofdstuk over IAS 37; SRA *Vaktechnisch handboek MKB*.

## Cross-app integration

- **bookkeeping-general-ledger** — voorzieningen verschijnen op balansrekeningen onder eigen rekeningnummerreeks; dotaties en vrijvallen muteren via journaalposten in GL.
- **bookkeeping-financial-statements** — de voorzieningentoelichting (REQ-PROV-008) is een rapportage-component die uit de `provision` + `provision-movement` records wordt samengesteld; niet-uit-de-balans-blijkende verplichtingen verschijnen als aparte toelichtingssectie uit `contingent-liability`.
- **bookkeeping-pension-ias19** — pensioenvoorziening eigen beheer is een specialisatie van `provision` met type=pensioen; de actuariële berekening wordt daar gedetailleerd.
- **bookkeeping-deferred-tax** — voorzieningen leiden meestal tot tijdelijke verschillen (commercieel last bij dotatie, fiscaal aftrekbaar bij betaling); de detector in deferred-tax leest `provision`-mutaties.
- **bookkeeping-fixed-assets-depreciation** — ontmantelingsverplichtingen worden bij activering van het asset toegevoegd als component aan de boekwaarde, met spiegelpost in `provision` (type=milieu / ontmanteling).
- **bookkeeping-financial-statements → toelichting** — de risk-disclosure-secties (sensitivity, bandbreedte) consumeren `rangeLow` / `rangeHigh` per voorziening.
- **hrmq** — bron voor jubileumvoorziening (medewerkers in dienst, jaartelling) en herstructureringsvoorziening (aantal getroffen medewerkers, verwachte vertrekvergoedingen).
- **docudesk** — bewaart expert-rapporten (milieu, actuaris), legal-opinion-memo's (claims), reorganisatieplannen en peer-review-vastleggingen onder restricted access.
- **decidesk** — herstructureringsvoorziening EUR > 100K of overige materiële voorzieningen kunnen worden gerouteerd door audit-committee voor goedkeuring.

## Target users

Primair de **financieel controller** of **CFO** van een MKB+-onderneming die de voorzieningen-positie maandelijks bewaakt en bij jaarafsluiting de toelichting opstelt. Bij grotere ondernemingen splitst de rol naar een **technisch boekhouder / RA in opleiding** die de mutaties bijhoudt, een **risk officer** die de claims- en geschillenvoorzieningen samen met juridisch onderhoudt, en een **environmental compliance officer** voor milieuvoorzieningen. De **HR-controller** voert jubileum- en herstructureringsvoorzieningen samen met de boekhouding. Secundair: de **externe accountant** die voorzieningen als een van de hoogste risicogebieden behandelt (subjectieve schattingen, management bias), de **belastinginspecteur** die bij Vpb-controle de timing van aftrekbaarheid van voorzieningen toetst (let op: niet alle commercieel opgenomen voorzieningen zijn fiscaal aftrekbaar — jubileumvoorziening pas bij uitkering, milieuvoorziening alleen onder strikte voorwaarden), en de **bedrijfsjurist** voor claims-onderbouwing. Strategische waarde voor Shillinq: door voorzieningen als uniforme datalaag te bieden — met automatische mutatiehistorie, sensitivity, en disclosure-aansluiting — wordt een arbeidsintensieve handmatige Excel-administratie die in bijna elke MKB+-boekhouding nog bestaat vervangen door een traceerbaar en auditeerbaar systeem dat het accountantscontrole-werk meetbaar verkort.

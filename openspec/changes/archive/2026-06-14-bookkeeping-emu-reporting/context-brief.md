---
status: draft
---

# EMU-saldo / EMU-schuld Reporting

## Purpose

Nederlandse decentrale overheden (provincies, gemeenten, waterschappen, gemeenschappelijke regelingen) zijn onder de **Wet Houdbare Overheidsfinanciën (Wet Hof)** verplicht om hun **EMU-saldo** (kassaldo: ontvangsten minus uitgaven) en **EMU-schuld** (bruto schuldpositie) periodiek aan het CBS te rapporteren. Het CBS aggregeert deze rapportages voor de Notificatie EDP (Excessive Deficit Procedure) richting Eurostat, die toetst of Nederland binnen de Europese Stabiliteits- en Groeipact-normen blijft (3% BBP tekort, 60% BBP schuld). EMU-rapportage verschilt fundamenteel van de accrual-basis **Besluit Begroting en Verantwoording (BBV)** jaarrekening: het EMU-saldo is **kasbasis**, dus afschrijvingen, voorzieningendotaties en boekwinsten/verliezen worden geëlimineerd terwijl bruto-investeringen en aflossingen juist meetellen. Deze spec biedt een geautomatiseerde pipeline van BBV-grootboek naar EMU-aangifte (kwartaalenquête EMU-saldo en jaarlijkse opgave EMU-schuld), inclusief afstemming, afwijkingsdetectie t.o.v. **vastgestelde meerjarenraming**, en SBR-Wonen / CBS XBRL-indiening.

## Data Model

De pipeline kent vier kernentiteiten: `EMUReport` (een ingediende of in-progress aangifte voor een periode), `EMUAdjustment` (de individuele accrual→kas correcties, gekoppeld aan een grootboekmutatie of een macroregel), `CashFlowItem` (kasstroomregel geclassificeerd naar IV3-hoofdstuk/functie), en `DebtPosition` (de uitstaande schuld per instrument per peildatum).

### EMUReport

```json
{
  "id": "emu-2026-q2-gem-1742",
  "rapporterendeOrganisatie": {
    "rsin": "001234567",
    "gemeentecode": "1742",
    "naam": "Gemeente Voorbeeldam",
    "soort": "gemeente"
  },
  "periode": {
    "jaar": 2026,
    "kwartaal": 2,
    "type": "kwartaal-emu-saldo"
  },
  "status": "ingediend",
  "indieningsdatum": "2026-07-15T09:23:00+02:00",
  "cbsBevestigingsnummer": "CBS-EMU-2026Q2-001234567",
  "emuSaldo": {
    "berekend": -2300000,
    "begroot": -1800000,
    "afwijking": -500000,
    "afwijkingPercentage": -27.8,
    "valuta": "EUR"
  },
  "emuSchuldUltimo": {
    "bruto": 142500000,
    "wettelijkeNorm": 156000000,
    "ruimte": 13500000
  },
  "bbvAansluiting": {
    "saldoBatenLasten": 4200000,
    "totaleAdjustments": -6500000,
    "aansluitingscontrole": "geslaagd"
  },
  "toelichting": "Afwijking veroorzaakt door versnelde dotatie aan voorziening pensioen wethouders (+450K) en hogere investering MFA Centrum (+820K kas)."
}
```

### EMUAdjustment

Elke adjustment beschrijft hoe een BBV-post wordt omgerekend naar kasbasis. Adjustments worden of automatisch afgeleid uit grootboekmutaties, of als macroregel toegepast (bijv. "alle afschrijvingen elimineren").

```json
{
  "id": "adj-2026-q2-0142",
  "reportId": "emu-2026-q2-gem-1742",
  "type": "eliminatie-afschrijving",
  "richting": "saldo-verhogend",
  "bedrag": 1240000,
  "bron": {
    "grootboekrekening": "4801000",
    "omschrijving": "Afschrijving gebouwen onderwijs",
    "taakveld": "4.2",
    "taakveldNaam": "Onderwijshuisvesting",
    "programma": "Onderwijs"
  },
  "regel": "Wet Hof art. 3 lid 2: afschrijvingen zijn geen kasuitgaven en worden geëlimineerd uit EMU-saldo",
  "toelichting": "Lineaire afschrijving brede school De Hoeksteen, boekwaarde EUR 24,8M, looptijd 40 jaar"
}
```

Voorbeelden van adjustment-typen:

| Type | Richting | BBV-effect | EMU-effect |
|------|----------|------------|------------|
| `eliminatie-afschrijving` | saldo-verhogend | last | geen kasuitgave |
| `eliminatie-voorzieningdotatie` | saldo-verhogend | last | geen kasuitgave (wel bij onttrekking met betaling) |
| `eliminatie-onttrekking-reserve` | saldo-neutraal | bate | geen kasontvangst |
| `toevoeging-bruto-investering` | saldo-verlagend | activering (geen last) | wel kasuitgave |
| `toevoeging-aflossing` | saldo-verlagend | balansmutatie | wel kasuitgave |
| `eliminatie-boekwinst-desinvestering` | saldo-verlagend | bate | alleen kasontvangst = boekwaarde + winst |
| `correctie-transactiemoment` | wisselend | factuurdatum | betaaldatum |
| `intercompany-eliminatie` | wisselend | dubbeltelling GR | enkelvoudig |

### CashFlowItem

Kasstromen geclassificeerd volgens **IV3 (Informatie voor Derden)**, conform de CBS-indeling per **hoofdstuk-functie** combinatie. Dit is dezelfde taxonomie die ook in de IV3-kwartaalrapportage wordt gebruikt, waardoor reconciliatie tussen IV3 en EMU eenvoudiger wordt.

```json
{
  "id": "cf-2026-q2-08745",
  "reportId": "emu-2026-q2-gem-1742",
  "datum": "2026-05-22",
  "bedrag": -820000,
  "iv3": {
    "hoofdstuk": "8",
    "hoofdstukNaam": "Volkshuisvesting, ruimtelijke ordening en stedelijke vernieuwing",
    "functie": "810",
    "functieNaam": "Ruimtelijke ordening",
    "categorie": "3.4.1",
    "categorieNaam": "Investeringen materiële vaste activa met economisch nut"
  },
  "taakveld": "8.1",
  "tegenrekening": {
    "soort": "leverancier",
    "naam": "BAM Infra Nederland B.V.",
    "factuurnummer": "F-2026-44218"
  },
  "kasOfTransactiebasis": "kas",
  "betaalmoment": "2026-05-22T14:11:00+02:00",
  "factuurmoment": "2026-04-30T00:00:00+02:00"
}
```

### DebtPosition

Schuldposities per instrument, gemeten op peildatum (kwartaaleinde voor tussentijdse rapportage, jaarultimo voor definitieve EMU-schuld).

```json
{
  "id": "debt-2026-q2-bng-0034",
  "reportId": "emu-2026-q2-gem-1742",
  "peildatum": "2026-06-30",
  "instrument": "vaste-geldlening",
  "tegenpartij": {
    "naam": "BNG Bank N.V.",
    "soort": "sector-S122-bank",
    "consolidatieEMU": "extern"
  },
  "hoofdsomOorspronkelijk": 25000000,
  "uitstaandeSchuld": 18750000,
  "rentevoet": 2.85,
  "rentevorm": "vast",
  "looptijdJaren": 20,
  "einddatum": "2034-12-31",
  "telt_mee_in_EMU_schuld": true,
  "categorie_eurostat": "AF.3-securities-other-than-shares-AF.4-loans"
}
```

DebtPosition kent ook `kasgeldlening`, `obligatie`, `schatkistbankieren-rekeningcourant` (negatief saldo telt als schuld), `derivaten-passief`, `crediteurensaldo > 1 jaar`, en `voorziening-met-juridische-afdwingbaarheid` (laatste alleen als Eurostat-classificatie dat vereist).

## Requirements

### Requirement: REQ-EMU-001 Kwartaal-EMU-saldo aangifte produceren

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

Het berekende EMU-saldo MOET worden gepresenteerd in het exacte format van de CBS-enquête EMU (kwartaalenquête overheidsfinanciën decentrale overheden), inclusief alle verplichte tussenregels.

#### Scenario: Indeling volgt CBS-template kwartaal-EMU 2026

- **GIVEN** een berekend EMU-saldo
- **WHEN** de gebruiker de aangifte exporteert
- **THEN** bevat de export de regels: 1) saldo baten en lasten BBV, 2) mutatie reserves, 3) bruto investeringen MVA, 4) bijdragen van derden in investeringen, 5) desinvesteringen, 6) afschrijvingen, 7) dotaties voorzieningen ten laste exploitatie, 8) onttrekkingen voorzieningen via exploitatie, 9) boekwinst/verlies desinvesteringen, 10) EMU-saldo
- **AND** is elke regel onderbouwd met de onderliggende `EMUAdjustment` records

### Requirement: REQ-EMU-004 EMU-schuld berekenen volgens Eurostat ESA2010

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

Bij gemeenschappelijke regelingen (GR) en verbonden partijen die binnen de overheidssector S.1313 (lokale overheid) vallen, MOET het systeem onderlinge transacties en schuldposities elimineren om dubbeltelling in geconsolideerde EMU-rapportage te voorkomen.

#### Scenario: Bijdrage aan Veiligheidsregio wordt geëlimineerd op koepelniveau

- **GIVEN** Gemeente Voorbeeldam betaalt EUR 3,4M bijdrage aan Veiligheidsregio Brabant-Zuid (een GR binnen sector S.1313)
- **WHEN** de geconsolideerde EMU-rapportage van de regio wordt opgesteld
- **THEN** wordt deze bijdrage geëlimineerd: bij de gemeente verschijnt het als `intercompany-eliminatie` saldo-verhogend, bij de VR als saldo-verlagend, netto effect S.1313 = nul
- **AND** wordt de eliminatie gemarkeerd met `tegenpartij.consolidatieEMU="intern-S1313"`

### Requirement: REQ-EMU-006 Automatische CBS XBRL-indiening

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

Het systeem MOET het berekende EMU-saldo per kwartaal automatisch vergelijken met de voor dat jaar/kwartaal vastgestelde meerjarenraming (begroting), en zowel absolute als procentuele afwijking weergeven.

#### Scenario: Q2 EMU-saldo wijkt 27,8% af van begroot

- **GIVEN** een begroot kwartaalsaldo Q2 2026 van EUR −1,8M en een gerealiseerd saldo van EUR −2,3M
- **WHEN** de vergelijking draait
- **THEN** toont het rapport `afwijking: -500000` en `afwijkingPercentage: -27.8`
- **AND** wordt de afwijking automatisch toegelicht met de top-3 bijdragende EMU-adjustments (bijv. "versnelde dotatie voorziening pensioen wethouders EUR 450K, hogere investering MFA Centrum EUR 820K kas, lagere OZB-ontvangsten EUR 230K")

### Requirement: REQ-EMU-008 Afwijkingsalert bij overschrijding individuele EMU-referentiewaarde

Het systeem MOET een alert genereren wanneer het EMU-saldo over een lopend jaar de individuele referentiewaarde (de "EMU-norm" per decentrale overheid, jaarlijks vastgesteld door het Rijk, gemeenten/provincies/waterschappen krijgen elk een eigen plafond gebaseerd op begrotingsomvang) dreigt te overschrijden, of wanneer de gezamenlijke ruimte voor de sector dreigt te worden uitgenut.

#### Scenario: Cumulatief EMU-tekort overschrijdt 80% van individuele norm

- **GIVEN** een gemeente met individuele EMU-referentiewaarde EUR 8,5M tekort
- **AND** een cumulatief tekort t/m Q3 van EUR 7,1M (= 83,5%)
- **WHEN** de Q3-rapportage wordt gegenereerd
- **THEN** verschijnt een alert "EMU-tekort 83,5% van referentiewaarde — risico op overschrijding bij ongewijzigd beleid Q4"
- **AND** wordt een prognose voor Q4 berekend op basis van geplande investeringen en aflossingen

#### Scenario: Macro-overschrijding sector tijdens bestuurlijk overleg

- **GIVEN** het ministerie van Financiën heeft via het BOFv (Bestuurlijk Overleg Financiële verhoudingen) aangekondigd dat de sector decentrale overheden 110% van de macroruimte gebruikt
- **WHEN** een gemeente investeringsplannen indient die het lokale EMU-saldo verder verslechteren
- **THEN** waarschuwt het systeem dat sanctierisico (artikel 7 Wet Hof: korting Gemeentefonds) toeneemt

### Requirement: REQ-EMU-009 Reconciliatie tussen EMU-rapportage en BBV-jaarrekening

Het systeem MOET een sluitende aansluiting tonen tussen de EMU-aangifte over een boekjaar en de definitieve BBV-jaarrekening, waarbij elk verschil herleidbaar is tot een individuele `EMUAdjustment` of een gedocumenteerde macroregel.

#### Scenario: Aansluitcontrole geslaagd voor jaarrekening 2025

- **GIVEN** een BBV-jaarrekening 2025 met saldo baten/lasten EUR 4,2M positief
- **AND** vier kwartaal-EMU-aangiften die optellen tot EMU-saldo EUR −2,3M
- **WHEN** de jaarreconciliatie draait
- **THEN** wordt het verschil van EUR 6,5M volledig verklaard door de som van alle adjustments
- **AND** toont het rapport "Aansluiting geslaagd: EUR 6,5M EUR adjustments, 0 ongereconcilieerd"
- **AND** is dit overzicht opvraagbaar door de accountant als onderbouwing bij de controleverklaring

#### Scenario: Aansluitverschil door late memoriaalboeking

- **GIVEN** een ongereconcilieerd verschil van EUR 18K na jaarafsluiting
- **WHEN** de reconciliatie draait
- **THEN** wordt het verschil getoond als "ongereconcilieerd" met een onderzoekstaak
- **AND** kan de gebruiker doorklikken naar de grootboekmutaties van december waar de oorzaak waarschijnlijk ligt

### Requirement: REQ-EMU-010 IV3-classificatie als gedeelde taxonomie

Het systeem MOET alle `CashFlowItem`-records classificeren volgens de IV3-taxonomie (hoofdstuk, functie, categorie), zodanig dat de IV3-kwartaalaangifte aan CBS en de EMU-aangifte vanuit hetzelfde geclassificeerde dataset worden gegenereerd.

#### Scenario: Eén grootboekmutatie voedt zowel IV3 als EMU

- **GIVEN** een factuurbetaling van EUR 820K aan BAM voor brede school MFA Centrum
- **WHEN** de boeking wordt vastgelegd
- **THEN** krijgt het bijbehorende `CashFlowItem` automatisch IV3-classificatie hoofdstuk 8, functie 810, categorie 3.4.1
- **AND** verschijnt het in de IV3-kwartaalaangifte onder die categorie
- **AND** verschijnt het in de EMU-aangifte als `toevoeging-bruto-investering`

### Requirement: REQ-EMU-011 Periodieke synchronisatie met Schatkistbankieren

Het systeem MOET dagelijks (of bij elke kwartaalafsluiting verplicht) de uitstaande positie op de schatkistbankieren-rekeningcourant en deposito's bij het Ministerie van Financiën inlezen, om de `DebtPosition`-records voor sector-Rijk transacties accuraat te houden.

#### Scenario: Dagelijkse import van schatkistbankieren-saldo

- **GIVEN** een actieve koppeling met de Agentschap-portaal API
- **WHEN** de dagelijkse synchronisatietaak draait om 02:00
- **THEN** wordt het saldo per ultimo vorige werkdag opgehaald
- **AND** wordt een `DebtPosition` met `instrument="schatkistbankieren-rekeningcourant"` bijgewerkt
- **AND** als het saldo negatief is, telt dit per direct mee in lopende EMU-schuldprognoses

### Requirement: REQ-EMU-012 Audit-trail en bewaarplicht

Alle EMU-rapportages, adjustments, CBS-bevestigingen en wijzigingen MOETEN voor de wettelijke bewaartermijn (10 jaar voor financiële administratie decentrale overheden conform Archiefwet 1995 en Wet Hof artikel 11) onveranderbaar worden bewaard, met volledige audit-trail wie wat wanneer wijzigde.

#### Scenario: Accountant raadpleegt aangifte uit 2020

- **GIVEN** een accountantscontrole in 2026 die de EMU-aangifte over 2020 wil verifiëren
- **WHEN** de accountant het rapport opent
- **THEN** wordt de exact ingediende versie getoond, met cbsBevestigingsnummer, alle adjustments, gebruikte BBV-grootboekmutaties, en de digitale handtekening van de toenmalige concerncontroller
- **AND** zijn eventuele latere correctieaangiften als aparte `EMUReport`-records zichtbaar in chronologische volgorde

## Standards & Sources

- **Wet Houdbare Overheidsfinanciën (Wet Hof)** — wet van 11 december 2013, artikelen 3 (EMU-saldo definitie), 5 (individuele referentiewaarde decentrale overheden), 7 (sanctiemechanisme korting Gemeentefonds bij macro-overschrijding), 11 (bewaarplicht en informatieverstrekking).
- **CBS-instructie EMU-enquête decentrale overheden** — jaarlijks geactualiseerd, definieert de exacte regels van de kwartaalenquête en de aansluitsystematiek BBV → EMU.
- **Stability and Growth Pact** — EU-verordening 1466/97 (preventieve arm) en 1467/97 (corrigerende arm, EDP — Excessive Deficit Procedure). 3% BBP tekortnorm, 60% BBP schuldnorm.
- **ESA2010 (European System of Accounts 2010)** — Eurostat-handboek voor sectorclassificatie en instrumentdefinities. Sector S.1313 = lokale overheid; instrumentcategorieën AF.2 (deposito's), AF.3 (effecten ex aandelen), AF.4 (leningen) tellen mee in bruto schuld.
- **Manual on Government Deficit and Debt (MGDD)** — Eurostat-handleiding met praktijkvoorbeelden voor classificatievraagstukken (PPS-constructies, garanties, derivatencorrecties).
- **IV3 (Informatie voor Derden)** — Regeling van de Minister van BZK met de verplichte taxonomie hoofdstuk-functie-categorie voor kwartaalrapportage van decentrale overheden aan CBS.
- **Besluit Begroting en Verantwoording provincies en gemeenten (BBV)** — geeft de accrual-basis grondslag waar EMU vanaf rekent. BBV-Commissie publiceert notities (notitie kapitaalgoederen, notitie reserves en voorzieningen) die relevante classificatieregels bevatten.
- **CBS XBRL-taxonomie EMU** — onderdeel van de Nederlandse Taxonomie (NT) onder beheer van het SBR-programma; bevat de exacte concepten, dimensies en presentaties voor de elektronische aangifte.
- **SBR / Digipoort** — Standard Business Reporting infrastructuur voor het ondertekend en versleuteld aanleveren van de XBRL aan CBS via PKIoverheid services-server certificaten.
- **Wet financiering decentrale overheden (Wet fido)** — definieert toegestane financiering en relateert aan schuldpositie.
- **Regeling schatkistbankieren decentrale overheden** — verplicht overschotten te parkeren bij het Rijk; saldi tellen voor EMU-schuld.
- **Bestuurlijk Overleg Financiële verhoudingen (BOFv)** — kanaal waarlangs Rijk en koepels (VNG, IPO, UvW) macroruimte EMU-saldo afstemmen.

## Cross-app integration

Deze EMU-spec leunt op en voedt meerdere andere capabilities binnen Shillinq en de Conduction-suite:

- **bookkeeping-bbv (foundation)** — De gehele EMU-pipeline neemt het BBV-grootboek als bronwaarheid: het saldo van baten en lasten, de mutaties in reserves en voorzieningen, en de balansmutaties (investeringen, aflossingen, mutatie kortlopende schulden) zijn de input voor de accrual-naar-kas conversie. De EMU-spec MAG geen eigen grootboek introduceren, maar definieert uitsluitend de classificatieregels en macroregels die op het bestaande BBV-grootboek worden toegepast.
- **bookkeeping-iv3-reporting** — IV3 en EMU delen de classificatie hoofdstuk-functie-categorie. De `CashFlowItem`-entiteit in deze spec is dezelfde die door IV3 wordt gebruikt; verschil is dat IV3 ook lasten/baten op accrualbasis indeelt, terwijl EMU alleen de kasstromen meeneemt. Eén keer classificeren, twee rapportages.
- **bookkeeping-schatkistbankieren** — Levert de dagelijkse rekening-courant en depositoposities die als `DebtPosition` records met `instrument="schatkistbankieren-rekeningcourant"` of `"schatkistbankieren-deposito"` worden gerepliceerd in de EMU-rapportage.
- **bookkeeping-begroting-meerjaren** — Levert de vastgestelde meerjarenraming en de daaruit afgeleide kwartaalbegroting voor de begroot-vs-realisatie vergelijking (REQ-EMU-007) en voor de individuele EMU-referentiewaarde-bewaking (REQ-EMU-008).
- **bookkeeping-verbonden-partijen** — Onderhoudt de registratie van gemeenschappelijke regelingen, verbonden partijen en hun sectorclassificatie (S.1313 lokale overheid vs S.11 niet-financiële vennootschappen vs S.122 banken), die nodig is voor REQ-EMU-005 intercompany-eliminatie.
- **bookkeeping-jaarrekening** — Levert de definitieve BBV-jaarrekening waarmee REQ-EMU-009 reconcilieert. Output van deze spec is een aansluitoverzicht dat als bijlage bij de jaarrekening en in het accountantsdossier wordt opgenomen.
- **openconnector → SBR / Digipoort** — Pluggable connector voor de XBRL-indiening bij CBS via SBR/Digipoort, inclusief PKIoverheid certificaatbeheer en SOAP-routing. De connector handelt herzendingen, bevestigingsverwerking en foutvertaling af.
- **docudesk** — Bewaart de getekende XBRL-aangiften, CBS-bevestigingen en accountantsbijlagen in een WORM-archief conform Archiefwet 1995 voor de 10-jaars bewaartermijn (REQ-EMU-012).
- **openregister → EMUReport schema** — Het canonical schema voor `EMUReport`, `EMUAdjustment`, `CashFlowItem` en `DebtPosition` wordt geregistreerd in OpenRegister zodat andere apps (zoals een gemeenteraad-dashboard of een provinciaal toezichtportaal) read-only kunnen koppelen.

## Target users

- **Concerncontroller gemeente of provincie** — primaire eindverantwoordelijke voor de juistheid en tijdigheid van de EMU-aangifte. Bekijkt afwijkingsalerts (REQ-EMU-008), accordeert de concept-aangifte, ondertekent digitaal voor indiening (REQ-EMU-006), en gebruikt de begroting-realisatie vergelijking (REQ-EMU-007) in P&C-cyclus rapportages aan college en raad.
- **Financieel adviseur waterschap** — voor waterschappen die naast de waterschapsbegroting ook EMU-rapporteren onder Wet Hof. Vergelijkbare workflow als gemeenten, met aanvullende aandacht voor specifieke investeringspatronen (dijkverzwaring, RWZI-projecten) die EMU-saldo sterk negatief maken in piekjaren.
- **CBS-rapporteur (financieel medewerker)** — operationele rol die de kwartaal-EMU-enquête voorbereidt, adjustments controleert, reconciliatieproblemen oplost en als eerste aanspreekpunt fungeert voor CBS-vragen over de aangifte.
- **Accountant (in opdracht)** — gebruikt het reconciliatieoverzicht (REQ-EMU-009) en de audit-trail (REQ-EMU-012) bij de jaarrekeningcontrole, met name de aansluiting tussen BBV jaarrekening en de som van vier kwartaal-EMU-aangiften.
- **Beleidsmedewerker financiën / planning & control** — gebruikt EMU-prognoses bij investeringsbesluitvorming. Een groot investeringspakket kan de individuele EMU-referentiewaarde overschrijden; de tool maakt zichtbaar welk deel van de ruimte al gebruikt is.
- **Toezichthouder bij de provincie (op gemeenten) of het Rijk (op provincies/waterschappen)** — kan via een read-only export inzicht krijgen in de EMU-positie van toezichtsobjecten, ten behoeve van het preventief en repressief financieel toezicht.
- **VNG / IPO / UvW koepelvertegenwoordiger** — niet als direct gebruiker, maar als consument van geaggregeerde sectordata die uit het systeem kan worden geëxporteerd voor BOFv-overleg met het Rijk over de macroruimte EMU-saldo decentrale overheden.

---
status: draft
---

# 13-Weeks Rolling Cashflow Forecast voor ZZP

## Purpose

Voor de ZZP'er en kleine MKB-ondernemer is cashflow het bestaansrisico nummer één: niet winst, maar liquiditeit bepaalt of de onderneming volgende maand nog kan voldoen aan zijn verplichtingen. De typische ZZP-cashflow is volatiel — facturen worden niet altijd op tijd betaald (gemiddelde betalingstermijn B2B in Nederland is 41 dagen volgens Atradius Payment Practices Barometer 2024, met staartwaarden tot 90 dagen bij overheid en grootzakelijk), kosten zijn vaak vast (huur, verzekering, leasing, abonnementen), en seizoenseffecten kunnen omzet in juli en december halveren. Een "13-weeks rolling cashflow forecast" is in de internationale ondernemersfinanciering (de TPR-praktijk uit het Verenigd Koninkrijk en de zogenaamde "13-week cash flow model" uit M&A turnaround-praktijk) de standaard kortetermijn-planningshorizon: lang genoeg om patronen te zien (kwartaalafsluiting BTW, vakantieperiode), kort genoeg om bestuurbaar te blijven.

Deze spec beschrijft hoe shillinq voor ZZP'ers en kleine MKB-ondernemers een rolling 13-weken-cashflow-forecast biedt: elke maandag schuift het horizonvenster één week op, alle openstaande AR-facturen worden geprojecteerd op hun verwachte ontvangstdatum (op basis van klant-specifieke betalingsgedrag-historie of contractuele termijn), alle AP-verplichtingen worden geboekt op hun vervaldatum, recurring-cost-stromen (huur, abonnementen, verzekeringen, leasing, salaris-eigen-loon DGA, FOR-dotatie) worden automatisch ingepland, en seizoenscorrecties (juli/augustus, kerstvakantie) worden toegepast op verwachte nieuwe omzet uit pipeline.

Het doel is dat de ondernemer (1) op elk moment de verwachte saldo-stand per week ziet voor de komende 13 weken, (2) bij dreigende negatieve saldo's een vroegtijdige waarschuwing krijgt met handelingsperspectieven (versneld factureren, betalingsregeling treffen met crediteur, rekening-courant aanboren, eigen DGA-loon uitstellen), (3) scenario-analyses kan draaien ("wat als klant X niet betaalt", "wat als ik nieuwe opdracht Y wel/niet aanneem", "wat als rente stijgt"), (4) een minimum buffer-policy kan definiëren (bijv. "altijd minimaal 1 maand vaste kosten op de zakelijke rekening") en alerts krijgt bij onderschrijding, en (5) bij maandafsluiting een realisatie-vs-forecast-vergelijking ziet zodat het model continu kan worden gekalibreerd.

De spec onderscheidt drie cashflow-categorieën conform IAS 7 (kasstroomoverzicht): operationele kasstromen (omzet, leveranciers, salaris, BTW-afdracht), investeringskasstromen (aanschaf bedrijfsmiddelen, verkoop), en financieringskasstromen (lening-aflossing, rekening-courant-mutatie, kapitaalstortingen/-uittredingen DGA of eenmanszaak). Voor de ZZP'er is de mix typisch 95 procent operationeel, 3 procent investering, 2 procent financiering — wat de meeste UI-aandacht op operationeel legt.

Specifieke aandacht gaat naar de Nederlandse cashflow-piek-momenten: kwartaal-BTW-afdracht (laatste werkdag van de maand volgend op kwartaal), jaarlijkse VPB/IB-aanslag (september-november), pensioen-/lijfrentepremie (december), vakantiegeld (mei, vooral voor DGA-loon). En aan de typische ZZP-buffer-strategieën: 1-2-3 spaardoelen (1: lopende BTW, 2: jaarlijkse IB-aanslag, 3: vakantie/ziekteverlof-reserve van 3-6 maanden vaste kosten).

De spec is uitdrukkelijk niet alleen een visualisatie maar een **planningsinstrument** met actief handelingsperspectief: bij elk dreigend probleem produceert de engine concrete actie-suggesties — variërend van "stuur deze week stage-2-herinnering aan Acme (huidige factuur €8.400 vervalt over 4 dagen)" tot "overweeg DGA-loon van mei naar juli te verplaatsen" tot "open een rekening-courant-aanvraag bij ABN-AMRO (geschat krediet €15.000 op basis van afgelopen 12 maanden omzet)". Het systeem is bewust onderscheidend van traditionele cashflow-rapportage (die alleen toont) door zijn proactieve karakter.

Een kritische ontwerpbeslissing is de scheiding tussen **deterministische** stromen (recurring betalingen, aanslagen, geplande betalingen — die vast staan) en **stochastische** stromen (AR-ontvangsten met kansverdeling, pipeline-conversie). De visualisatie gebruikt twee tinten per categorie: donker voor zeker, licht voor verwacht. De buffer-berekening houdt expliciet rekening met variantie: bij hoge variantie in pipeline moet de buffer hoger zijn dan bij stabiele AR-portefeuille.

Voor de overheidsdomein-toepassing (zzp-er in dienst van semi-publiek, gemeentes als opdrachtgever) wordt de cashflow extra gevoelig door de wettelijke 30-dagen-betaaltermijn maar de structureel langere praktijk (gemeentes lopen vaak 60-90 dagen achter wegens interne workflows). De engine moet deze realiteit modelleren in plaats van naïef de wettelijke termijn aan te houden.

## Data Model

### CashflowForecastHorizon

Hoofd-entiteit: één per ondernemer, lopend, met 13 weekslots vooruit. Wordt elke maandag automatisch gerold (week-1 valt af, nieuwe week-13 verschijnt).

```json
{
  "id": "cfh-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "horizonStart": "2026-05-25",
  "horizonEind": "2026-08-23",
  "rolledOp": "2026-05-25T02:00:00Z",
  "openingSaldo": {
    "zakelijkeRekening": 14820.00,
    "spaardoel_btw": 3200.00,
    "spaardoel_ib": 5800.00,
    "spaardoel_buffer": 8200.00,
    "totaal": 32020.00
  },
  "modelVersie": "v4.1-klantspecifiek-betaalgedrag",
  "kalibratieScore": 0.87
}
```

### CashflowWeek

Eén per week in het 13-weken-venster. Bevat geprojecteerde inflows/outflows per categorie + eindsaldo.

```json
{
  "id": "cfw-2026-w22-ond-001234",
  "horizonId": "cfh-ond-001234",
  "weeknummer": 22,
  "weekStart": "2026-05-25",
  "weekEind": "2026-05-31",
  "openingSaldo": 32020.00,
  "inflows": {
    "ar_geprognosticeerd": 8400.00,
    "ar_gerealiseerd": 0,
    "nieuwe_opdrachten_pipeline": 0,
    "rente": 4.50,
    "totaal": 8404.50
  },
  "outflows": {
    "ap_geprognosticeerd": 1820.00,
    "recurring_huur": 0,
    "recurring_verzekering": 320.00,
    "recurring_abonnementen": 184.00,
    "recurring_software": 78.00,
    "btw_afdracht": 0,
    "ib_aanslag": 0,
    "dga_loon": 0,
    "lijfrentepremie": 0,
    "investeringen": 0,
    "totaal": 2402.00
  },
  "nettoMutatie": 6002.50,
  "eindSaldo": 38022.50,
  "bufferStatus": "BOVEN_BUFFER",
  "alerts": []
}
```

### CashflowARProjection

Voor elke openstaande AR-factuur een projectie van verwachte ontvangstdatum + waarschijnlijkheid, gebaseerd op klant-specifieke betalingshistorie.

```json
{
  "id": "arproj-fact-2026-0247",
  "horizonId": "cfh-ond-001234",
  "factuurId": "fact-2026-0247",
  "klantId": "klant-acme-bv",
  "factuurDatum": "2026-04-15",
  "vervalDatum": "2026-05-15",
  "openstaandBedrag": 8400.00,
  "verwachtOntvangstDatum": "2026-05-28",
  "verwachtOntvangstWeek": "2026-w22",
  "betalingsHistorie": {
    "gemiddeldeAfwijking": "+13 dagen",
    "facturen12mnd": 9,
    "betaaldVoorVerval": 1,
    "betrouwbaarheidScore": 0.82
  },
  "scenarioBijNietBetalen": {
    "weekShift": "uitgesteld naar w26",
    "impactBuffer": -8400.00
  }
}
```

### CashflowAPSchedule

Geplande betalingen aan crediteuren binnen het horizon-venster, inclusief automatische incassi en betaalbatches.

```json
{
  "id": "apsched-fact-leverancier-2026-0089",
  "horizonId": "cfh-ond-001234",
  "leveranciersfactuurId": "lev-2026-0089",
  "leverancierNaam": "KPN Zakelijk",
  "vervalDatum": "2026-05-30",
  "geplandeBetaalDatum": "2026-05-29",
  "bedrag": 184.00,
  "categorie": "RECURRING_ABONNEMENTEN",
  "betalingsmethode": "AUTOMATISCHE_INCASSO_SEPA"
}
```

### CashflowRecurring

Definitie van recurring stromen: huur, verzekering, abonnementen, salaris, pensioen.

```json
{
  "id": "rec-huur-kantoor",
  "ondernemingId": "ond-nl-001234",
  "label": "Huur kantoorruimte De Werkfabriek",
  "categorie": "RECURRING_HUUR",
  "richting": "OUT",
  "frequentie": "MAANDELIJKS",
  "dagVanMaand": 1,
  "standaardBedrag": 850.00,
  "valutaCorrectie": null,
  "geldigVan": "2024-09-01",
  "geldigTot": null,
  "indexatieJaarlijks": "CPI_AFGELOPEN_JAAR"
}
```

### CashflowScenario

Wat-als-simulaties: vraag een scenario aan en het systeem herrekent het hele horizonvenster.

```json
{
  "id": "scen-2026-05-21-001",
  "horizonId": "cfh-ond-001234",
  "naam": "Wat als Acme factuur niet betaalt",
  "aanpassingen": [
    {"type": "AR_PROJECTION_OVERRIDE", "factuurId": "fact-2026-0247", "weekShift": 8, "kansvanBetaling": 0.40}
  ],
  "resultaat": {
    "minBufferWeek": "2026-w25",
    "minBufferBedrag": 2440.00,
    "onderschrijdingBuffer": true,
    "actiesuggestie": ["Pas DGA-loon-uitkering uit", "Versneld factureren nieuwe opdracht Y"]
  }
}
```

### CashflowBufferPolicy

Definitie van de minimum-buffer-policy van de ondernemer.

```json
{
  "id": "buffer-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "policy": "MIN_1_MAAND_VASTE_KOSTEN",
  "berekendeBuffer": 5200.00,
  "actueleSaldoTov Buffer": "+27000",
  "alertOndergrens": 5200.00,
  "alertVooralarm": 7800.00
}
```

## Requirements

### Requirement: REQ-CF-000 Horizon-initialisatie en openings-saldo-vaststelling

Bij eerste activatie moet het systeem op basis van de bankfeed (PSD2-koppeling) of handmatige saldo-invoer een betrouwbare openings-positie vaststellen, inclusief separate spaardoel-saldi indien de ondernemer die heeft.

#### Scenario: PSD2-bankfeed levert opening

- GIVEN nieuwe ondernemer koppelt zakelijke Bunq-rekening via PSD2
- WHEN initialisatie loopt
- THEN moet het saldo van vandaag worden opgehaald
- AND moet als openingSaldo.zakelijkeRekening worden ingesteld
- AND moet het systeem voorstellen om spaardoel-rekeningen toe te voegen

#### Scenario: Handmatige opening zonder PSD2

- GIVEN ondernemer wil geen PSD2-koppeling
- WHEN initialisatie loopt
- THEN moet handmatige saldo-invoer worden gevraagd voor zakelijke rekening + eventuele spaardoel-rekeningen

### Requirement: REQ-CF-001 Rolling 13-weken-horizon met wekelijkse roll

Het systeem moet elke maandag om 02:00 automatisch het horizonvenster verschuiven: week-1 valt af (mits gerealiseerd), week-13 wordt nieuw toegevoegd, alle projecties worden herberekend.

#### Scenario: Maandagse roll

- GIVEN huidig horizon loopt 18 mei — 16 augustus
- WHEN maandag 25 mei 02:00 cron draait
- THEN moet horizon worden 25 mei — 23 augustus
- AND moet rolledOp-timestamp worden geüpdatet

#### Scenario: Roll bij niet-gerealiseerde week

- GIVEN week-1 (vorige week) is niet volledig gerealiseerd (bankfeed ontbreekt)
- WHEN roll draait
- THEN moet het systeem een waarschuwing geven "Week-1 niet gereconcilieerd, gebruik kalibratie met voorzichtigheid"
- AND moet roll wel doorgaan

### Requirement: REQ-CF-002 Klant-specifieke betalingshistorie voor AR-projectie

Voor elke open AR-factuur moet de verwachte ontvangstdatum worden bepaald op basis van klant-specifieke betalingshistorie (gemiddelde afwijking t.o.v. vervaldatum) over de afgelopen 12 maanden, met een minimum van 3 facturen voor statistische significantie.

#### Scenario: Acme betaalt structureel 13 dagen te laat

- GIVEN klant Acme heeft 9 facturen in 12 maanden met gem. afwijking +13 dagen
- AND nieuwe factuur vervalt 15 mei
- WHEN projectie wordt berekend
- THEN moet verwacht ontvangstdatum 28 mei zijn
- AND moet betrouwbaarheidScore worden meegegeven

#### Scenario: Nieuwe klant zonder historie

- GIVEN nieuwe klant zonder historie
- WHEN projectie wordt berekend
- THEN moet het systeem terugvallen op de contractuele vervaldatum + 7 dagen buffer
- AND moet betrouwbaarheidScore worden gemarkeerd "LAAG"

### Requirement: REQ-CF-003 Recurring stromen automatisch ingepland

Vaste lasten (huur, verzekering, abonnementen, leasing, software, salaris DGA, lijfrentepremie) moeten uit een recurring-registry worden uitgerold over het horizonvenster.

#### Scenario: Maandelijkse huur op 1e van de maand

- GIVEN recurring "huur €850 op dag-1"
- WHEN horizon wordt herberekend
- THEN moet in week-w23 (1 juni valt erin) €850 als out worden opgenomen
- AND moet categorie RECURRING_HUUR zijn

#### Scenario: Jaarlijkse verzekering met indexering

- GIVEN recurring "BAV-verzekering €620 jaarlijks op 1 juli, indexatie CPI"
- AND CPI vorig jaar was 3.2 procent
- WHEN horizon wordt herberekend
- THEN moet 1 juli 2026 bedrag €639.84 zijn

### Requirement: REQ-CF-004 Kwartaal-BTW-afdracht automatisch geprojecteerd

Het systeem moet de eerstvolgende BTW-afdracht (laatste werkdag van de maand volgend op kwartaal) automatisch in het horizonvenster opnemen op basis van lopende BTW-positie.

#### Scenario: Q2-BTW-afdracht eind juli

- GIVEN BTW-positie per ultimo juni geprojecteerd op €4.820 te betalen
- WHEN horizon week-w31 (eind juli) wordt berekend
- THEN moet €4.820 als BTW_AFDRACHT-out worden opgenomen op 31 juli
- AND moet de bron-verwijzing naar de lopende BTW-aangifte zijn

### Requirement: REQ-CF-005 Buffer-policy met alerts

De ondernemer moet een minimum-buffer-policy kunnen definiëren; bij projectie onder buffer moet een waarschuwing met handelingsperspectief verschijnen.

#### Scenario: Buffer-onderschrijding voorspelt

- GIVEN buffer-policy "min 1 maand vaste kosten" = €5.200
- AND week-w25 projectie toont eindsaldo €4.800
- WHEN dashboard wordt geladen
- THEN moet alert "BUFFER_ONDERSCHRIJDING_VERWACHT_w25" verschijnen
- AND moet handelingsperspectief minimaal 3 acties bevatten

#### Scenario: Vooralarm

- GIVEN buffer-policy €5.200 met vooralarm €7.800
- AND week-w24 projectie toont eindsaldo €7.500
- WHEN dashboard laadt
- THEN moet vooralarm-waarschuwing verschijnen (geel, niet rood)

### Requirement: REQ-CF-006 Scenario-analyse "wat als X betaalt niet"

Het systeem moet ondersteunen dat de gebruiker een scenario opzet waarin één of meer open facturen "wegvallen" of "uitgesteld" worden, en moet de impact op het horizonvenster doorrekenen.

#### Scenario: Acme betaalt niet

- GIVEN gebruiker maakt scenario "Acme €8.400 niet betalen"
- WHEN scenario wordt uitgevoerd
- THEN moet het systeem een gekopieerde horizon met die aanpassing produceren
- AND moet diff-overzicht tonen: "min-saldo daalt van €15.200 naar €4.300 in w25"

#### Scenario: Wat als nieuwe opdracht

- GIVEN gebruiker maakt scenario "Opdracht Y aannemen: omzet €12.000 over 3 maanden, kosten +€800/mnd"
- WHEN scenario draait
- THEN moet eind-saldo over 13 weken stijgen met +€9.600

### Requirement: REQ-CF-007 IB/VPB-aanslag automatisch geprojecteerd

Indien een voorlopige of definitieve aanslag IB/VPB-vervaldatum binnen het horizon-venster valt, moet die automatisch worden opgenomen.

#### Scenario: VA IB september 2026

- GIVEN voorlopige aanslag IB €1.840 vervalt 28 september 2026
- WHEN horizon-week-w39 wordt berekend
- THEN moet €1.840 als out worden opgenomen in week-w39

### Requirement: REQ-CF-008 Realisatie-vs-forecast kalibratie

Na maandafsluiting moet het systeem realisatie vergelijken met de oorspronkelijke forecast en het kalibratie-model bijwerken (klant-specifieke betaalgedrag-statistieken).

#### Scenario: Maandafsluiting mei

- GIVEN mei is afgesloten met €8.420 daadwerkelijke ontvangsten vs €9.200 forecast
- WHEN kalibratie-batch draait op 1 juni
- THEN moet kalibratieScore worden bijgewerkt
- AND moet klant-betalingshistorie-statistieken per klant worden geüpdatet

### Requirement: REQ-CF-009 Drie spaardoel-rekeningen (1-2-3 strategie)

Het systeem moet ondersteuning bieden voor drie virtuele spaardoel-saldo's (BTW-reservering, IB-aanslag-reservering, ondernemers-buffer) bovenop de operationele rekening.

#### Scenario: Automatische BTW-reservering bij omzet

- GIVEN factuur €1.000 + €210 BTW wordt geboekt als ontvangst
- WHEN automatisch-overboek-naar-spaardoel staat aan
- THEN moet €210 worden verplaatst van zakelijke rekening naar spaardoel_btw
- AND moet die mutatie zichtbaar zijn in cashflow-projectie

### Requirement: REQ-CF-010 Dashboard met weekbar en alerts

Het systeem moet een visueel dashboard tonen met staafdiagram per week (inflows groen, outflows rood, eindsaldo lijn), buffer-zone gemarkeerd, en alerts boven kritieke weken.

#### Scenario: Visualisatie

- GIVEN gebruiker opent cashflow-dashboard
- WHEN dashboard rendert
- THEN moet een 13-weekse barchart verschijnen met inflows/outflows en eindsaldo-lijn
- AND moet de buffer-policy als horizontale streep zichtbaar zijn
- AND moeten weken met alert rood geaccentueerd zijn

### Requirement: REQ-CF-011 Export voor bank- of financieringsgesprek

Het systeem moet een PDF-export kunnen produceren van het 13-weken-overzicht plus aannames, geschikt voor accountmanagement bij de bank of een fiscalist.

#### Scenario: PDF-export voor bankgesprek

- GIVEN gebruiker wil rekening-courant verhoogd
- WHEN "Cashflow-overzicht exporteren" wordt geklikt
- THEN moet een PDF worden gegenereerd met horizon-tabel, weekbar-grafiek, aannames per categorie, scenario-analyse "stresstest"

### Requirement: REQ-CF-012 Pipeline-omzet uit pipelinq

Verwachte nieuwe omzet uit pipelinq (CRM/sales-pipeline) moet — op basis van waarschijnlijkheid × bedrag — als zachte-inflow in horizon-weken worden opgenomen, duidelijk onderscheiden van vaste AR.

#### Scenario: Pipeline-deal met 60 procent kans

- GIVEN pipelinq-deal "Prospect Z €18.000 sluiten in juli" met 60 procent kans
- WHEN horizon wordt herberekend
- THEN moet €10.800 (€18.000 × 0.60) als "pipeline-inflow" in week-w29 verschijnen
- AND moet visueel onderscheid maken tussen vaste AR (donkergroen) en pipeline (lichtgroen)

### Requirement: REQ-CF-013 Wat-als-scenario voor opdrachtaanvaarding

Het systeem moet ondersteunen dat een ondernemer een potentiële opdracht doorrekent op cashflow-impact voordat hij aanvaardt: bruto omzet, extra kosten, timing van eerste factuur, betalingsgedrag-aanname.

#### Scenario: Opdracht doorrekenen op cashflow

- GIVEN ondernemer overweegt een 6-maands opdracht €4.500/mnd
- WHEN scenario "Opdracht X aannemen" draait
- THEN moet eind-saldo-curve over 13 weken stijgen met geanticipeerde inflow (na typisch klantbetalingsgedrag)
- AND moet eventuele liquiditeitsdip in eerste 6-8 weken worden zichtbaar gemaakt

### Requirement: REQ-CF-014 Bankfeed-reconciliatie en saldo-actualisatie

Het systeem moet via PSD2-bankfeed (Bunq, Knab, ING zakelijk, Rabo zakelijk) dagelijks de zakelijke rekening-saldi en transacties uitlezen en automatisch reconcilieren tegen verwachte AR/AP.

#### Scenario: Klant Acme betaalt

- GIVEN €8.400 inkomende transactie van "ACME BV" met referentie "fact 2026-0247"
- WHEN reconciliatie draait
- THEN moet factuur 2026-0247 worden afgeboekt
- AND moet de week-22 inflow-realisatie worden bijgewerkt

#### Scenario: Onverwachte inkomende transactie

- GIVEN €1.200 inkomend zonder herkenbare referentie
- WHEN reconciliatie loopt
- THEN moet de transactie als "ongematcht" worden geflagd
- AND moet de gebruiker worden gevraagd om koppeling

### Requirement: REQ-CF-015 Kalibratie-rapportage met forecast accuracy

Het systeem moet maandelijks een accuracy-rapport produceren met MAPE (Mean Absolute Percentage Error) per categorie, zodat de gebruiker ziet welke onderdelen van de forecast betrouwbaar zijn en welke verbetering behoeven.

#### Scenario: Maandrapport accuracy mei

- GIVEN mei is afgesloten
- WHEN accuracy-batch draait op 1 juni
- THEN moet een rapport tonen "AR-projectie MAPE 8.2%, recurring 0%, pipeline 24.5%"
- AND moet de gebruiker zien dat pipeline het minst betrouwbaar is en kalibratie-richting bevatten

### Requirement: REQ-CF-016 Crisis-modus bij kritieke buffer-onderschrijding

Bij voorspelde negatieve eindsaldo's binnen 4 weken moet het systeem een crisis-modus activeren met dagelijks dashboard + concrete kortetermijn-actielijst en optionele integratie met financierings-marketplaces (NL Krediet Plein, ABN-AMRO Funding, KMO Finance).

#### Scenario: Crisis-modus geactiveerd

- GIVEN week-w23 projectie toont eindsaldo -EUR 2.400
- WHEN dashboard wordt geladen
- THEN moet een rood "Crisis-modus actief" banner verschijnen
- AND moeten dagelijkse acties worden voorgesteld: versnelde factuur klant X (€8.400 open), uitstellen DGA-loon-uitkering (€3.200), aanvragen rekening-courant-verhoging
- AND moet de gebruiker direct een aanvraag bij een financierings-marketplace kunnen indienen via API

#### Scenario: Crisis-modus deactiveert

- GIVEN AR van Acme is binnen, eindsaldo nu +EUR 5.800
- WHEN volgende dagbatch draait
- THEN moet crisis-modus automatisch worden gedeactiveerd
- AND moet de gebruiker een bevestigingsnotificatie ontvangen

### Requirement: REQ-CF-017 Verleden-vergelijking 12-maands cycli

Het systeem moet een vergelijkingsoverzicht tonen tussen huidige cashflow en dezelfde 13-weken-periode 12 maanden eerder, zodat seizoenseffecten en jaar-op-jaar groei zichtbaar zijn.

#### Scenario: YoY-vergelijking mei 2026 vs mei 2025

- GIVEN ondernemer is sinds 2024 actief
- WHEN "Vergelijking met vorig jaar" wordt geselecteerd
- THEN moet een overlay-grafiek verschijnen met 2025-cashflow vs 2026-cashflow
- AND moeten YoY-groei en seasonal patronen worden gemarkeerd

## Standards & Sources

- IAS 7 (Statement of Cash Flows) — operationeel/investering/financiering
- RJ 360 (Raad voor de Jaarverslaggeving — kasstroomoverzicht NL GAAP)
- Atradius Payment Practices Barometer 2024 (NL B2B-betalingstermijn gemiddelden: 41 dagen DSO; 39% te laat; 7% non-payment)
- Wet betalingstermijnen overheid (Stb. 2017, 226) — max 30 dagen
- Wet bestrijding betalingsachterstand B2B (Stb. 2012, 647) — max 60 dagen tenzij expliciet overeengekomen
- BR-13-week cashflow modeling standard (Restructuring Working Group, UK best practice geadopteerd in NL turnaround-praktijk)
- Belastingdienst BTW-afdracht-kalender 2026 (Q1: 30 april, Q2: 31 juli, Q3: 31 oktober, Q4: 31 januari)
- Belastingdienst kalender voorlopige aanslagen IB/VPB (peilmaanden mei + september + november)
- CPI-indexering CBS (voor recurring-cost-indexatie)
- SEPA Direct Debit-schema (Rulebook 13.0 effective November 2024) voor automatische incassi
- PSD2 (Verordening (EU) 2015/2366) en RTS Open Banking voor bank-feed integratie
- Wet aansprakelijkheid voor incasso 14-dagen-brief (interactie met dunning, art. 6:96 lid 6 BW)
- AFM richtsnoeren liquiditeitsmanagement MKB (Wft art. 4:14 e.v.)
- DNB liquiditeitsmonitor-publicaties (voor sector-benchmark)
- Berlin Group XS2A standaard voor PSD2-bankfeed
- ISO 20022 voor SEPA-payment-instructies en bankrapportages

## Cross-app integration

- `bookkeeping-ap-ar` — primaire bron AR-facturen + AP-schedule; klantgegevens met betalingstermijn-attributen
- `bookkeeping-credit-control-dunning` — disputed/dunning-status beïnvloedt AR-projectie-waarschijnlijkheid; pause-events synchroon
- `bookkeeping-btw-aangifte` — input voor BTW-afdracht-projectie per kwartaal
- `bookkeeping-ib-aangifte-zzp` — input voor VA/aanslag-projectie; suggesties tot wijziging VA bij grote winst-afwijking
- `bookkeeping-payroll-engine-nl` — input voor DGA-loon-uitkering (waar relevant); reservering vakantiegeld
- `bookkeeping-fixed-assets` — geplande investeringen (kapitaaluitgaven) in horizon
- `bookkeeping-kor-kleine-ondernemersregeling` — KOR-status beïnvloedt BTW-afdracht-projectie (geen, bij KOR)
- `dba-compliance-marker` — concentratie- en exclusiviteit-aggregaten gedeeld voor klantrisico
- `pipelinq` — bron voor pipeline-omzet projectie (gewogen kansverwacht), prospects-deals met sluit-waarschijnlijkheid
- `openconnector` — bank-feed (PSD2) voor realisatie-input + saldo-actualisatie (Bunq, Knab, ING, Rabo)
- `openregister` — file-export voor PDF-rapportage; scenario-snapshots
- `launchpad` — KPI's voor cashflow-gezondheid over alle cliënten van een advieskantoor
- `nldesign` — government-thema voor publieke MKB-tools

## Target users

- **Primair: ZZP'er met volatiele cashflow** (project-omzet, lange B2B-betalingstermijnen). Deze groep heeft de meest acute behoefte aan voorspelbaarheid omdat zij geen HR/finance-afdeling hebben die proactief op cashflow stuurt.
- **Secondair: Eenmanszaak/VOF in opbouwfase** (negatieve werkkapitaal-positie veelvuldig) — hier helpt vroege detectie van liquiditeitstekorten om tijdig krediet te regelen of opdrachtaanvaarding te heroverwegen.
- **Tertiair: MKB-DGA** met DGA-loon-uitkering-planning, vakantiegeld-reservering en jaarlijkse VPB-aanslag.
- **Tertiair: Boekhouder / accountant** die cliënt-portefeuille bewaakt op cashflow-gezondheid.
- **Bijzonder: ZZP'er in turnaround** (na slecht jaar, met rekening-courant-strakheid) — gebruikt scenario-engine actief voor herstructurering.
- **Bijzonder: ZZP'er met overheidsklanten** — extended-payment-terms-realiteit (60-90 dagen) maakt cashflow extra grillig.
- **Bijzonder: ZZP'er met buitenlandse klanten** — FX-risico en internationale betaaltermijnen.
- **Bijzonder: Seizoensondernemer** (horeca, recreatie, agrarisch) — sterk seizoens-pattern in cashflow vereist verfijnde correctie.
- **Niet binnen scope**: grootzakelijk (Treasury Management Systems zoals Kyriba, FIS Quantum, Bellin doen dit al en bieden meer geavanceerde functionaliteit), particulieren zonder onderneming (huishoudboekje is een ander product), banken (eigen risicomanagement-tools).

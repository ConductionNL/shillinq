---
status: draft
---

# Full NL Loonadministratie Engine

## Purpose

Een betrouwbare Nederlandse loonadministratie is voor MKB-werkgevers, DGA's met BV en gemeentes / publieke organisaties geen optionele functie maar een wettelijke verplichting met directe gevolgen voor de aangifte loonheffingen aan de Belastingdienst, de premieafdracht aan het UWV, de pensioenuitvoerder en de zorgverzekeraar (ZVW). Iedere fout in de loonberekening propageert naar het loonstrookje (Wet op de Loonadministratie verplicht een specificatie aan de werknemer), naar de jaaropgave, naar de Werkkostenregeling-budgetbewaking, naar het UWV-personeelsregister, naar de Aangifte Loonheffingen (LH-aangifte SBR-monthly via Digipoort) en — bij DGA's — naar de Vpb-aangifte van de BV.

Deze spec beschrijft de **foundation-engine** waarop alle andere loongerelateerde shillinq-specs (`bookkeeping-liv-lkv` lage-inkomensvoordeel + loonkostenvoordeel, `bookkeeping-wkr` werkkostenregeling, `bookkeeping-upa-pensioen` Uniform Pensioen Aanlevering, `bookkeeping-loonaangifte-sbr` LH-aangifte, `bookkeeping-pension-ias19` IAS 19 pensioenverplichting voor jaarrekening) hangen. De engine vervangt de bestaande tussenoplossing `bookkeeping-detachering-payroll-administratie` (die slechts een doorgeefluik was naar externe payroll-providers) door een volwaardige loonberekening: voor elke werknemer, voor elke loonperiode (week / 4-weken / maand), met alle in 2026 geldende loonheffingstabellen, premies werknemersverzekeringen, ZVW-bijdrage, sectorale opslagen, pensioenpremies, vakantietoeslag, eindejaarsuitkering, 13e maand, ploegentoeslag, kilometervergoedingen (belastingvrij tot €0,23/km in 2026), thuiswerkvergoeding (€2,40/dag in 2026), fooienregeling (horeca), en de doorlopende reservering vakantiedagen.

Het doel is dat een MKB-werkgever in shillinq (1) een werknemersmaster onderhoudt met alle fiscale en sociale-zekerheids-attributen (BSN, loonheffingstabel, jaarloon, sector, pensioenregeling, fiscaal partner-gegevens voor heffingskortingen, evt. AOV/zorgverzekering-via-werkgever), (2) per loonperiode een volledige bruto→netto-berekening krijgt die machine-verifieerbaar reproduceerbaar is, (3) een correcte LH-aangifte voorbereidt voor de eerstvolgende afdrachtmoment, (4) per werknemer een loonstrook genereert volgens art. 626 Boek 7 BW (met alle verplichte vermeldingen), (5) jaaroverzichten en jaaropgaven (Belastingdienst en werknemer) produceert, en (6) de boekingen automatisch in het grootboek doorzet (4xxx loonkosten, 16xx te betalen LH/SV, 17xx vakantiegeld-reservering).

De engine moet meegaan met jaarlijkse wijzigingen: tarieven schijven box 1 (in 2026: schijf 1 36,93% tot €38.441; schijf 2 49,5% boven €76.817 voor jonger dan AOW), franchise/maxima sociale verzekeringen (in 2026: max premieloon WW/WIA €74.480; ZVW-laag tarief 5,32% over maximaal premieloon ZVW €71.628), heffingskortingen (algemene heffingskorting, arbeidskorting met afbouw, jonggehandicaptenkorting, ouderenkortingen), en sectorpremies (telkens 1 januari nieuwe tarieven Werkhervattingskas).

Bijzondere aandacht voor: **DGA-loon** (gebruikelijk-loonregeling art. 12a Wet LB, in 2026 norm €56.000 of hoger op grond van vergelijkbare functies), **stagiairs en BBL'ers** (verlaagde premie WW/WIA), **AOW-gerechtigden in dienst** (geen premie AOW, wel deels Wajong), **uitzendkrachten via uitzendbureau** (out-of-scope — daarvoor heeft de uitlener de plicht), **gedetacheerden / interim** (afwijkende sectorindeling), en **horecafooien** (fooienregeling — werkgever neemt fooi op in loon onder voorwaarden).

Verder kritisch is de samenhang met de aangrenzende systemen: (1) **Werkkostenregeling (WKR)** waarin de vrije ruimte (in 2026 2,03 procent over de eerste €400.000 loonsom + 1,18 procent boven die grens) doorlopend wordt bewaakt en bij overschrijding 80 procent eindheffing wordt afgedragen; (2) **LIV en LKV** (Lage-inkomensvoordeel respectievelijk Loonkostenvoordelen) waarbij over werknemers in bepaalde inkomensbrackets of doelgroepen (ouderen, arbeidsgehandicapten, herintreders) tegemoetkomingen worden verkregen — de engine moet de berekening voor jaaropgaaf voorbereiden; (3) **UPA (Uniforme Pensioen Aanlevering)** waarmee pensioengegevens maandelijks naar de pensioenuitvoerder gaan; (4) **IAS 19 pensioenverplichting** voor jaarrekening van grotere werkgevers; (5) **Loonaangifte SBR** als finale uitvoer richting Digipoort.

Een ontwerp-imperatief is **reproduceerbaarheid** van elke berekening: bij een loonstroom van vandaag moet het systeem ook over 5 jaar exact dezelfde uitkomst kunnen reproduceren, gegeven dezelfde inputs én de toen geldende tabellen. Dit vereist dat alle tabellen, premies, en algoritmes versiegebonden en immutable worden opgeslagen, met audit-trail per loonperiode-berekening. Bij een naheffing of correctie door de Belastingdienst (vaak 1-3 jaar achteraf) moet shillinq de oorspronkelijke berekening kunnen reproduceren én de correctie kunnen doorrekenen.

Ten slotte ontkent de spec uitdrukkelijk niét de complexiteit van de NL-loonadministratie: er zijn meer dan 600 sector-CAO's met afwijkende elementen, talloze toeslagen (onregelmatigheidstoeslag, BHV-vergoeding, EHBO-toelage), en specifieke sectorregels (zeevarenden, podiumartiesten, sportlieden, gemeenten). Voor sector-specifieke uitbreidingen wordt expliciet ruimte gehouden in het data-model (`Werknemer.sectorSpecifiekeAttributen` als open JSON-object) zonder de engine te overladen.

## Data Model

### Werknemer

Hoofd-entiteit per persoon-in-loondienst, met alle fiscale en SV-attributen die in 2026 vereist zijn voor LH-aangifte.

```json
{
  "id": "wn-2024-0042",
  "werkgeverId": "wg-conduction-bv",
  "bsn": "123456789",
  "voorletters": "J.M.",
  "achternaam": "Jansen",
  "geboortedatum": "1985-03-12",
  "geslacht": "M",
  "inDienstSinds": "2024-04-01",
  "uitDienstPer": null,
  "burgerlijkeStaat": "GEHUWD",
  "fiscaalPartnerBsn": "987654321",
  "loonheffingstabel": "WIT_REGULIER",
  "loonheffingstabelKorting": true,
  "loonheffingstabelKortingIngangsdatum": "2024-04-01",
  "sectorcode": 32,
  "sectorOmschrijving": "Overige zakelijke dienstverlening II",
  "premieGroupWW": "SECTORFONDS",
  "premieGroupWGF": "AWF_LAAG",
  "contractType": "ONBEPAALDE_TIJD_SCHRIFTELIJK_GEEN_OPROEP",
  "uurloon": 28.50,
  "contracturenPerWeek": 40,
  "jaarloonSV": 59280.00,
  "vakantiegeldPct": 0.08,
  "eindejaarsuitkeringPct": 0,
  "dertiendeMaand": false,
  "pensioenRegeling": "PME_DC",
  "pensioenPremiePctWerkgever": 0.182,
  "pensioenPremiePctWerknemer": 0.072,
  "auto": null,
  "thuiswerkdagenPerWeek": 2
}
```

### LoonPeriode

Definitie van een loonperiode (week, 4-weken, maand) voor één werkgever; alle werknemers verwerken in dezelfde periode-tak.

```json
{
  "id": "lp-2026-05-wg-conduction-bv",
  "werkgeverId": "wg-conduction-bv",
  "periodeType": "MAAND",
  "jaar": 2026,
  "periodeNr": 5,
  "periodeStart": "2026-05-01",
  "periodeEind": "2026-05-31",
  "betaaldatum": "2026-05-27",
  "status": "GESLOTEN",
  "totaalBrutoloon": 87420.00,
  "totaalNettoBetaald": 61240.50,
  "totaalLHAfdracht": 18620.10,
  "totaalPremiesSVAfdracht": 7559.40,
  "totaalZVWAfdracht": 3654.00
}
```

### LoonStrook

Per werknemer per periode een complete loonstrook conform art. 626 BW: bruto, alle componenten, inhoudingen, netto, cumulatieven, vakantiegeld-reservering.

```json
{
  "id": "ls-wn-2024-0042-2026-05",
  "werknemerId": "wn-2024-0042",
  "periodeId": "lp-2026-05-wg-conduction-bv",
  "brutoComponenten": {
    "basissalaris": 4940.00,
    "vakantietoeslag_uitbetaling": 0,
    "ploegentoeslag": 0,
    "overuren_125pct": 0,
    "thuiswerkvergoeding": 19.20,
    "kilometervergoeding_belastingvrij": 0,
    "fooi": 0,
    "totaal_bruto": 4959.20
  },
  "fiscaalLoon": 4959.20,
  "premieloon_SV": 4940.00,
  "loonheffing": 1083.40,
  "inhoudingenSV": {
    "ww_wn_aandeel": 0,
    "wia_wn_aandeel": 0,
    "totaal_sv_wn": 0
  },
  "premiesSVWerkgever": {
    "awf": 130.85,
    "aof_basis": 360.62,
    "uniforme_opslag_kinderopvang": 2.47,
    "wko": 0,
    "whk": 6.92,
    "totaal_werkgever": 500.86
  },
  "zvw": {
    "ingehouden_wn": 0,
    "afgedragen_wg_5_32pct": 262.80
  },
  "pensioen": {
    "premie_wn_aandeel": 355.68,
    "premie_wg_aandeel": 898.88
  },
  "nettoBetaald": 3520.12,
  "cumulatieven": {
    "fiscaalloon_ytd": 24796.00,
    "vakantiegeld_reservering_ytd": 1983.68
  },
  "vakantieDagenReservering": {
    "opgebouwdPeriode": 2.0,
    "saldoEindPeriode": 12.5
  }
}
```

### LoonheffingTabel2026

Verzameltabel van LH-tarieven per soort (wit, groen, dagtabel, jaartabel) zoals jaarlijks door de Belastingdienst gepubliceerd in oranje boekje.

```json
{
  "id": "lht-2026-wit-maand-met-korting",
  "jaar": 2026,
  "kleur": "WIT",
  "periode": "MAAND",
  "metKorting": true,
  "tabelRegels": [
    {"vanaf": 0, "tot": 269, "lh": 0, "korting": 30.00},
    {"vanaf": 270, "tot": 538, "lh": 0, "korting": 75.20},
    {"vanaf": 4901, "tot": 5083, "lh": 1058.00, "korting": 240.83}
  ],
  "bron": "Belastingdienst LH-tabel 2026 januari, versienr 2025-W47"
}
```

### LHAfdracht

Per maand een aggregaat dat klaargezet wordt voor LH-aangifte naar Digipoort.

```json
{
  "id": "lhafdr-2026-05-wg-conduction-bv",
  "werkgeverId": "wg-conduction-bv",
  "periodeId": "lp-2026-05-wg-conduction-bv",
  "totaalLoonheffing": 18620.10,
  "totaalEindheffingenWKR": 220.00,
  "totaalPremiesSV": 7559.40,
  "totaalZVW": 3654.00,
  "totaalAfdracht": 30053.50,
  "vervaldagAfdracht": "2026-06-30",
  "status": "VOORBEREID",
  "sbrInstanceRef": null
}
```

### Loonjournaalpost

Automatische grootboekboeking per loonperiode.

```json
{
  "id": "jp-2026-05-wg-loon",
  "periodeId": "lp-2026-05-wg-conduction-bv",
  "datum": "2026-05-31",
  "regels": [
    {"rekening": "4001", "naam": "Brutolonen", "debet": 87420.00, "credit": 0},
    {"rekening": "4010", "naam": "Sociale lasten WG", "debet": 11213.40, "credit": 0},
    {"rekening": "4020", "naam": "Pensioenpremie WG", "debet": 15880.16, "credit": 0},
    {"rekening": "1610", "naam": "Te betalen netto loon", "debet": 0, "credit": 61240.50},
    {"rekening": "1620", "naam": "Af te dragen LH", "debet": 0, "credit": 18620.10},
    {"rekening": "1630", "naam": "Af te dragen premies SV+ZVW", "debet": 0, "credit": 11213.40},
    {"rekening": "1640", "naam": "Af te dragen pensioenpremie", "debet": 0, "credit": 23439.56}
  ],
  "balanced": true
}
```

### Werkgever

Configuratie van de werkgever zelf: loonheffingsnummer, sectorindeling, eindheffingsverklaringen WKR, AWF-laag-of-hoog-tarief.

```json
{
  "id": "wg-conduction-bv",
  "kvk": "12345678",
  "naam": "Conduction B.V.",
  "loonheffingsnummer": "851234567L01",
  "sectorcode": 32,
  "awfTarief": "LAAG",
  "wkrBudget2026": 8742.00,
  "wkrBudgetVerbruikt2026": 220.00,
  "loonsom2026_tot_400k_2_47pct": 8742.00,
  "loonsomBoven400k_1_15pct": 0,
  "ploegendienst": false,
  "horeca": false
}
```

## Requirements

### Requirement: REQ-PAY-000 Werkgever en werknemers-setup

Bij eerste activatie moet het systeem een werkgever-master inrichten (loonheffingsnummer, sectorindeling, AWF-laag-of-hoog, WKR-vrije-ruimte-budget) en per werknemer een complete master initialiseren via een wizard.

#### Scenario: Werkgever-onboarding

- GIVEN nieuwe werkgever activeert payroll
- WHEN setup-wizard start
- THEN moeten KvK, loonheffingsnummer, sectorindeling worden gevraagd
- AND moet UWV-sectorindeling-validatie worden uitgevoerd
- AND moet AWF-tarief-keuze (laag bij overwegend onbepaalde-tijd contracts, hoog anders) worden onderbouwd

### Requirement: REQ-PAY-001 Bruto→Netto loonberekening per werknemer per periode

Het systeem moet voor elke werknemer per loonperiode een volledige bruto→netto-berekening uitvoeren conform de loonheffingstabel die hoort bij zijn fiscale situatie en de in dat jaar geldende SV-premies.

#### Scenario: Reguliere maandloonberekening

- GIVEN werknemer met basissalaris €4.940, witte tabel met loonheffingskorting, mei 2026
- WHEN periode wordt verwerkt
- THEN moet LH €1.083,40 zijn conform LH-tabel 2026
- AND moet nettoloon €3.520,12 (na pensioenpremie en thuiswerkvergoeding) zijn

#### Scenario: Werknemer zonder loonheffingskorting

- GIVEN werknemer heeft heffingskorting niet geactiveerd
- WHEN LH berekend
- THEN moet de tabel-zonder-korting worden toegepast
- AND moet maandbedrag substantieel hoger zijn

### Requirement: REQ-PAY-002 Loonheffingstabellen 2026 ingeladen en versie-gemarkeerd

Het systeem moet de officiële LH-tabellen 2026 (wit/groen, regulier/bijzonder, week/4-weken/maand/jaar, met/zonder korting) ingeladen hebben met versienummer en bron-referentie.

#### Scenario: Tabel-validatie tegen Belastingdienst-PDF

- GIVEN LH-tabel 2026 wit, maand, met korting
- WHEN regel "loon 4901-5083 → LH 1058,00 / korting 240,83" wordt opgezocht
- THEN moet exact die regel beschikbaar zijn
- AND moet bron-attribuut "Belastingdienst LH-tabel 2026 januari, versienr 2025-W47" zijn

#### Scenario: Mid-jaarse tabelwijziging

- GIVEN Belastingdienst publiceert correctietabel per 1 juli 2026
- WHEN tabel-update wordt ingelezen
- THEN moet vanaf juli die nieuwe tabel gelden, voor juni de oude

### Requirement: REQ-PAY-003 Premies SV correct toegepast

Het systeem moet premies werknemersverzekeringen (AWF, AOF, Aof-uniforme opslag kinderopvang, WHK met sector-specifieke opslag, en eventuele sectorfonds) correct berekenen tot maximum premieloon 2026 €74.480.

#### Scenario: AWF-laag-tarief

- GIVEN werknemer met onbepaalde tijd schriftelijk contract, werkgever AWF-laag
- WHEN AWF berekend op SV-loon €4.940
- THEN moet AWF 2,64% × €4.940 = €130,42 zijn (let op: actuele 2026-tarieven; deze regel valideert tegen actueel-tabel)

#### Scenario: AOF basis-premie

- GIVEN werkgever met loonsom <€905k = "klein werkgever"
- WHEN AOF berekend
- THEN moet AOF-klein-tarief (in 2026: 5,38%) worden toegepast

### Requirement: REQ-PAY-004 ZVW-bijdrage werkgever

Het systeem moet ZVW-bijdrage werkgever (5,32% in 2026 voor laag-tarief, 6,57% bij hoog-tarief) toepassen tot maximaal ZVW-premieloon €71.628.

#### Scenario: ZVW werkgever laag-tarief

- GIVEN reguliere werknemer in loondienst
- WHEN ZVW berekend op €4.940
- THEN moet 5,32% × €4.940 = €262,81 zijn

### Requirement: REQ-PAY-005 Vakantietoeslag-reservering opbouw

Het systeem moet maandelijks 8 procent van het brutoloon reserveren als vakantietoeslag, met uitbetaling standaard in mei (instelbaar per werkgever).

#### Scenario: Maandelijkse reservering

- GIVEN brutoloon mei €4.940
- WHEN periode wordt verwerkt
- THEN moet €395,20 worden gereserveerd onder cumulatieven.vakantiegeld_reservering_ytd
- AND moet grootboek 17xx "Te betalen vakantiegeld" worden gecrediteerd

#### Scenario: Uitbetaling mei

- GIVEN ondertijd gereserveerd cumulatief €4.180 per mei
- WHEN mei-batch met "vakantietoeslag uitkeren" draait
- THEN moet €4.180 als vakantietoeslag op de loonstrook bij brutoComponenten verschijnen
- AND moet LH op het bijzondere-tarief-tabel worden berekend (groene tabel of bijzonder tarief)

### Requirement: REQ-PAY-006 13e maand en eindejaarsuitkering

Het systeem moet optioneel een 13e maand (gelijk maandloon) of eindejaarsuitkering (procentueel) ondersteunen, doorgaans uit te keren in november of december.

#### Scenario: 13e maand bij december-batch

- GIVEN werknemer met dertiendeMaand=true
- WHEN december-batch draait
- THEN moet bij brutoComponenten een extra regel "13e maand" verschijnen
- AND moet LH op bijzonder tarief

### Requirement: REQ-PAY-007 Belastingvrije kilometervergoeding €0,23/km

Het systeem moet kilometervergoeding voor zakelijke kilometers belastingvrij verwerken tot €0,23/km (2026).

#### Scenario: 120 zakelijke kilometers

- GIVEN werknemer dient 120 zakelijke km in
- WHEN periode wordt verwerkt
- THEN moet €27,60 belastingvrij worden uitgekeerd
- AND mag dit bedrag NIET in fiscaal loon worden opgenomen

#### Scenario: €0,30 overschrijdt belastingvrij maximum

- GIVEN werkgever betaalt €0,30/km
- WHEN periode wordt verwerkt
- THEN moet €0,07/km × 120 = €8,40 als belast loon worden opgenomen
- AND moet het deel onder €0,23 belastingvrij blijven

### Requirement: REQ-PAY-008 Thuiswerkvergoeding €2,40/dag

Het systeem moet de thuiswerkvergoeding (€2,40 per dag in 2026) toepassen op aangegeven thuiswerkdagen, belastingvrij.

#### Scenario: 8 thuiswerkdagen in mei

- GIVEN werknemer registreert 8 thuiswerkdagen in mei
- WHEN periode wordt verwerkt
- THEN moet €19,20 thuiswerkvergoeding belastingvrij worden uitgekeerd

#### Scenario: Combinatie thuiswerk + reisvergoeding zelfde dag

- GIVEN werknemer reist op een thuiswerkdag toch naar kantoor
- WHEN periode wordt verwerkt
- THEN mag op die dag óf thuiswerkvergoeding óf reisvergoeding worden uitgekeerd, niet beide

### Requirement: REQ-PAY-009 DGA-gebruikelijk-loon controle

Voor DGA's (statutair bestuurder met >5 procent aandelenbelang) moet het systeem toetsen of het loon voldoet aan de gebruikelijk-loonregeling (in 2026: minimaal €56.000 of hoger gangbaar inkomen vergelijkbare dienstbetrekking).

#### Scenario: DGA met te laag loon

- GIVEN DGA Jan met bruto jaarloon €48.000 (geen specifieke uitzondering)
- WHEN gebruikelijk-loon-toets draait
- THEN moet waarschuwing verschijnen "DGA-loon onder norm 2026 €56.000"
- AND moet advies "Verhoog loon of motiveer uitzondering"

#### Scenario: DGA met startup-uitzondering

- GIVEN DGA in eerste 3 jaar startup met aangetoond beperkte winstgevendheid
- WHEN toets draait
- THEN moet de uitzonderingsroute beschikbaar zijn met evidence-upload

### Requirement: REQ-PAY-010 Loonstrook conform art. 626 BW

Per werknemer per periode moet een loonstrook PDF worden gegenereerd met alle wettelijk verplichte vermeldingen: brutoloon, alle componenten, inhoudingen LH/SV, fiscaal loon cumulatief, sociaal verzekeringsloon, pensioen, nettoloon.

#### Scenario: PDF-loonstrook generatie

- GIVEN loonperiode 2026-05 is afgesloten
- WHEN loonstroken worden geproduceerd
- THEN moet per werknemer een PDF beschikbaar zijn
- AND moeten alle in art. 626 BW genoemde elementen aanwezig zijn

### Requirement: REQ-PAY-011 LH-aangifte voorbereid voor Digipoort

Het systeem moet per maand een SBR/XBRL LH-aangifte voorbereiden, geldig voor verzending via Digipoort naar de Belastingdienst, vóór de uiterste afdrachtdatum (laatste dag volgende maand).

#### Scenario: Mei-aangifte voorbereid uiterlijk 30 juni

- GIVEN periode 2026-05 is afgesloten op 27 mei
- WHEN LH-batch draait
- THEN moet sbrInstanceRef worden aangemaakt voor LH-2026-05
- AND moet afdrachtdatum 30 juni 2026 zijn
- AND moet status worden "VOORBEREID"

### Requirement: REQ-PAY-012 Grootboek automatisch geboekt

Per loonperiode moet automatisch een balanced loonjournaalpost worden geboekt: debet loonkosten, sociale lasten WG, pensioen WG; credit te betalen netto/LH/SV/pensioen.

#### Scenario: Balanced journaalpost

- GIVEN loonperiode 2026-05 afgesloten
- WHEN journaalpost wordt aangemaakt
- THEN moet debet-totaal credit-totaal evenaren (zero-imbalance)
- AND moet de boeking direct in GL zichtbaar zijn

### Requirement: REQ-PAY-013 Jaaropgave werknemer en Belastingdienst

Per jaar moet voor elke werknemer een jaaropgave worden gegenereerd (digitaal + PDF) met fiscaal loon, LH, ingehouden ZVW, pensioenpremie, en uitgekeerde vakantietoeslag.

#### Scenario: Jaaropgave 2025 voor werknemer

- GIVEN loonperioden 2025-01 t/m 2025-12 zijn allen afgesloten
- WHEN "Jaaropgaven genereren" wordt gedraaid in januari 2026
- THEN moet per werknemer een jaaropgave-PDF beschikbaar zijn
- AND moeten cumulatieven 100 procent matchen met som van loonperioden

### Requirement: REQ-PAY-014 Mutaties tussen-periode-verwerking

Het systeem moet kunnen omgaan met mid-periode mutaties (indienst-datum 15e van de maand, uitdienst-datum 22e, contractwijziging) en de pro-rato berekening toepassen.

#### Scenario: Indienst halverwege maand

- GIVEN werknemer komt in dienst per 15 mei 2026
- WHEN mei-batch draait
- THEN moet brutoloon pro-rato (17 werkdagen / 22 werkdagen) worden berekend
- AND moeten alle premies dienovereenkomstig schalen

#### Scenario: Uitdienst met openstaande vakantie-uren

- GIVEN werknemer gaat uit dienst per 30 juni met saldo 18 vakantiedagen
- WHEN eindafrekening wordt opgesteld
- THEN moet die 18 dagen × (jaarloon / 261) als brutoloon worden uitgekeerd
- AND moet LH op bijzonder tarief worden toegepast

### Requirement: REQ-PAY-015 30%-regeling expat-werknemer

Voor expat-werknemers met goedgekeurde 30%-regeling (per 2024 afgebouwd, in 2026 in transitie) moet het systeem de 30 procent vergoeding-correctie correct toepassen.

#### Scenario: 30%-regeling toegepast 2026

- GIVEN werknemer heeft beschikking 30%-regeling tot 2027
- AND brutoloon €8.500 per maand
- WHEN periode wordt verwerkt
- THEN moet 30 procent (€2.550) als belastingvrije vergoeding verschijnen
- AND moet fiscaal loon €5.950 zijn

## Standards & Sources

- Wet op de loonbelasting 1964 (Wet LB), in het bijzonder art. 12a (gebruikelijk loon DGA), art. 13 (waardering loon in natura), art. 31 (eindheffingsbestanddelen)
- Uitvoeringsregeling loonbelasting 2011 (Url LB 2011)
- Wet op de loonadministratie 1964 (Wet LA)
- Wet financiering sociale verzekeringen (Wfsv) — premieheffing en franchises 2026
- Zorgverzekeringswet (Zvw), art. 41 (werkgeversbijdrage)
- Pensioenwet 2007 — kaderwet pensioenen; Wet toekomst pensioenen (Wtp 2023) — transitie naar nieuwe stelsels
- Burgerlijk Wetboek art. 7:610 e.v. (arbeidsovereenkomst), art. 7:626 (verplichting tot specificatie loon — loonstrook), art. 7:634 (vakantiedagen-opbouw 4 maal contracturen per week), art. 7:625 (verhoging bij te late betaling)
- Wet minimumloon en minimumvakantiebijslag (WML) — 2026: min vakantiebijslag 8%; min uurloon per januari 2026
- Belastingdienst Handboek loonheffingen 2026 (jaarlijkse uitgave, www.belastingdienst.nl)
- LH-tabellen 2026 (Belastingdienst, gepubliceerd december 2025) — wit/groen, regulier/bijzonder, week/4-weken/maand/jaar
- SBR Nederland: Loonaangifte-taxonomie LA-XX-2026 (voor SBR/XBRL aanlevering Digipoort)
- UWV-handleiding sectorindeling Werkhervattingskas 2026
- Werkhervattingskas-premies 2026 per sector (UWV publicatie september 2025)
- AOW-leeftijd per geboortejaar (SVB publicatie)
- Belastingplan 2025 (afbouw ouderenkortingen, AHK-afbouw aanpassingen)
- Belastingplan 2026 (verdere aanpassingen, met name 30%-regeling transitie)
- UPA-standaard: Uniforme Pensioen Aanlevering specificatie 2026 (Pensioenfederatie)
- IAS 19 (Employee Benefits) en RJ 271 (Personeelsbeloningen)
- Eindheffingsbestanddelen WKR (Url LB art. 31a-c)
- Wet aanpak schijnconstructies (WAS) — keten-aansprakelijkheid loon
- Wet werk en zekerheid / Wet arbeidsmarkt in balans (WAB) — ketenregeling
- ISO 20022 voor SEPA-loonbetalingen
- AVG (Verordening (EU) 2016/679) — verwerking BSN en gevoelige werknemersgegevens
- ETSI EN 319 132 voor digitale ondertekening loonstrook-PDF

## Cross-app integration

- `bookkeeping-liv-lkv` — afnemer voor LIV/LKV-berekening (afhankelijk van loon-niveau en doelgroepen)
- `bookkeeping-wkr` — afnemer voor werkkostenregeling-vrije ruimte op loonsom; eindheffing 80% bij overschrijding
- `bookkeeping-upa-pensioen` — UPA-aanlevering pensioenuitvoerder (PME, ABP, BPL, PFZW, Zorg en Welzijn, etc.)
- `bookkeeping-loonaangifte-sbr` — afnemer voor SBR/XBRL-genereren en Digipoort-verzending
- `bookkeeping-pension-ias19` — input voor IAS 19 pensioenverplichting in jaarrekening (grotere werkgevers, RJ-compliant)
- `bookkeeping-ib-aangifte-zzp` — niet relevant (loon != winst), maar bij DGA wel loon-info in IB-aangifte DGA-natuurlijk-persoon
- `bookkeeping-ap-ar` — afdrachten LH/SV als crediteur Belastingdienst, pensioenuitvoerder als crediteur, netto-loon als crediteur werknemer
- `zzp-cashflow-13wk` — loon-batches als grote outflow in horizon-venster
- `bookkeeping-credit-control-dunning` — geen integratie (loon is geen factuurtraject), maar wel voor incassi op loonbeslag-verzoeken
- `dba-compliance-marker` — bij omzetting van DBA-opdrachtnemer naar werknemer: payroll-engine moet de nieuwe werknemersrelatie kunnen creëren
- `hrmq` — werknemersmaster bron (BSN, contract, sectoren, pensioenregeling, verlofadministratie, beoordelingsgesprekken)
- `openconnector` — Digipoort SOAP voor LH-aangifte, UPA-API voor pensioen, SEPA-betaalbatches naar bank
- `openregister` — file-storage loonstroken (bewaarplicht 7 jaar, sommige delen 5 jaar), jaaropgaven, contracten
- `docudesk` — ondertekening loonstroken (optioneel digital signature), arbeidsovereenkomsten
- `launchpad` — HR-KPI's voor management: loonkosten-trends, turnover, leeftijdsopbouw, vakantieverlofbalans
- `nldesign` — voor publieke werkgevers thema

## Target users

- **Primair: MKB-werkgever** met 1-50 werknemers (te klein voor SAP/AFAS/ADP, te groot voor handmatige Excel). Heeft directe behoefte aan een betrouwbare bruto→netto-berekening en LH-aangifte zonder maandelijks payroll-bureau-kosten van EUR 8-15 per werknemer.
- **Secondair: DGA-BV met alleen DGA-loon** (gebruikelijk-loon-toets, eenvoudig maar wettelijk strikt). Vaak een onevenredig complexe situatie omdat de DGA tegelijkertijd werkgever, werknemer en aandeelhouder is.
- **Tertiair: Boekhouder / administratiekantoor** met meerdere werkgever-cliënten. Multi-tenant view met centrale tabel-updates en bulk LH-aangifte-voorbereiding.
- **Tertiair: HR-medewerker** in MKB+ (50-250 werknemers) die salarisrun coördineert.
- **Bijzonder: Horeca-werkgever** met fooienregeling — specifieke registratie en verdeling per maand/per shift.
- **Bijzonder: Werkgever met DGA + werknemers** (gecombineerd) — twee verschillende loon-streams in één engine.
- **Bijzonder: Stichting / vereniging** met (deel)betaalde bestuurders en vrijwilligersvergoeding-grens (EUR 5,50/uur, max EUR 210/mnd, max EUR 2.100/jaar in 2026).
- **Bijzonder: Zorginstelling** met sector-specifieke CAO (VVT, GGZ, gehandicaptenzorg) en onregelmatigheidstoeslagen.
- **Bijzonder: Werkgever met expats** (30%-regeling, afbouwregime).
- **Niet binnen scope**: payroll-bureaus zelf (zij hebben eigen tooling zoals Visma, Nmbrs), uitzendbureaus (eigen sector-specifieke administratie ABU/NBBU), grootzakelijk (eigen ERP/HR-systeem zoals SuccessFactors, Workday), buitenlandse werkgevers zonder NL-vestiging (E101/A1-certificaat-route).

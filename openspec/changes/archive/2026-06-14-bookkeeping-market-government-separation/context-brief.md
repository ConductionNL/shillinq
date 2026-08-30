---
status: draft
---

# Wet Markt en Overheid Separation

## Purpose

The **Wet Markt en Overheid** (WMO, "Market and Government Act") is the Dutch implementation of EU state-aid principles into the Mededingingswet (Competition Act, hoofdstuk 4b, in force since 1 juli 2012, gewijzigd 1 juli 2014). The law obliges every **bestuursorgaan** — including gemeenten, provincies, waterschappen, gemeenschappelijke regelingen, omgevingsdiensten, GGD'en, RUD's and ZBO's — to follow four **gedragsregels** whenever it conducts an **economische activiteit** (the offering of goods or services on a market in concurrentie met private aanbieders).

The four rules are:

1. **Integrale kostprijs (Art. 25i Mw)** — the price charged must cover at least all direct and indirect costs, including overhead, vermogenskosten and a passende winstopslag. No structural verliesfinanciering from belastinggeld.
2. **Bevoordelingsverbod (Art. 25j Mw)** — geen voorkeursbehandeling van een eigen overheidsbedrijf (eigen vastgoed gratis, leningen onder marktrente, gratis personeel, etc.).
3. **Gegevensgebruik (Art. 25k Mw)** — gegevens verkregen uit publieke taken mogen niet hergebruikt worden voor economische activiteiten, behalve als ze ook beschikbaar zijn voor derden.
4. **Functiescheiding (Art. 25l Mw)** — dezelfde persoon mag niet tegelijk besluiten over de publieke regulering én de uitvoering van een concurrerende activiteit.

The WMO is enforced by the **Autoriteit Consument en Markt (ACM)**, which can open onderzoeken op klacht (mostly from MKB-concurrenten, branche-organisaties zoals VNO-NCW, MKB-Nederland, Koninklijke Horeca Nederland) of ambtshalve, and may impose a **last onder dwangsom** or **bestuurlijke boete tot € 900 000 of 10 % van de jaaromzet**. Known precedent-zaken include: **ACM/Gemeente Veendam (parkeergarages, 2014)**, **ACM/Gemeente Hilversum (haven Crailo, 2015)**, **ACM/Gemeente Eindhoven (parkeerexploitatie, 2017)** en **ACM/Reigersbos Sportcentrum (Amsterdam Zuidoost, 2020)** — alle wegens ontoereikende kostprijsdoorberekening of grondgebruik onder marktwaarde.

A bestuursorgaan can exempt a specific activity from the gedragsregels by passing an **algemeen-belang-besluit (ABB, Art. 25h lid 5–6 Mw)** in the raad/Provinciale Staten/AB, motiveren waarom de activiteit een publiek belang dient, kennisgeven aan ACM en publiceren in het gemeenteblad. The ABB-route is heavily contested: ACM and the **Adviescollege Toetsing Regeldruk** publish jaarlijkse signalen dat motiveringen te dun zijn (zie ACM-rapport "Evaluatie Wet M&O", april 2022, en het VNG-rapport "Algemeen belang in beeld", 2023).

This spec exists because shillinq is the kleine-gemeente / waterschap / GR-boekhouding van de Conduction-stack, and WMO-compliance is a **dagelijkse administratieve werkelijkheid** voor élke decentrale overheid die meer doet dan alleen wettelijke kerntaken. Without first-class support, controllers vallen terug op losse Excel-bestanden, ad-hoc grootboekrekeningen ("8xxx markt") en hand-berekende overhead-sleutels — exact het type schaduwadministratie dat ACM-onderzoeken triggers. The spec adds the WMO-laag bovenop `bookkeeping-bbv-compliance` (BBV-overheadtoerekening) en `bookkeeping-cost-centers-dimensions` (kostenplaats/kostendrager dimensies), zodat élke transactie die een commerciële activiteit raakt automatisch in een aparte sub-administratie wordt geboekt, élke maand een integrale kostprijs wordt herberekend, en élk kwartaal een ACM-rapportage en cross-subsidy-alert wordt gegenereerd.

The economic context matters: in 2026 zijn er **342 gemeenten, 12 provincies, 21 waterschappen en circa 600 gemeenschappelijke regelingen** in Nederland. Volgens VNG-onderzoek (2023) verricht **84 % van de gemeenten ten minste één economische activiteit**, met gemiddeld 4,2 activiteiten per gemeente. De top-categorieën zijn: verhuur sportaccommodaties (78 %), verhuur cultuurgebouwen (61 %), parkeerexploitatie commercieel (44 %), kringloop/retail (29 %), havenexploitatie commercieel (waterschappen + kustgemeenten, 18 %), bedrijventerrein-uitgifte boven marktwaarde (39 %), schoollunches-cateringdienst (22 %), reclame-exploitatie op gemeentelijk vastgoed (31 %), en — sinds 2024 een snel groeiende categorie — **datacenter-koudewarmte-as-a-service** (7 %). De WMO raakt dus geen randverschijnsel maar de kerncyclus van decentrale begroting en jaarrekening; de boekhoudkundige inrichting moet hierop berekend zijn, niet als optionele module.

## Data Model

### CommercialActivity (commerciele-activiteit)

The central register entry. One row per "economische activiteit op een markt". Examples in production data:

```json
{
  "id": "ca-2026-gemeente-tilburg-zaalverhuur-natuurmuseum",
  "code": "MO-NM-001",
  "naam": "Zaalverhuur Natuurmuseum Brabant",
  "bestuursorgaan": "Gemeente Tilburg",
  "organisatieonderdeel": "Afdeling Cultuur & Erfgoed",
  "startDatum": "2018-09-01",
  "eindDatum": null,
  "beschrijving": "Verhuur van zaalruimte, theater en grand café aan derden voor bedrijfsbijeenkomsten, recepties, productpresentaties.",
  "marktsegment": "zakelijke evenementenlocaties Midden-Brabant",
  "concurrenten": ["LocHal Tilburg BV", "Faxx Theater", "Hotel Mercure Tilburg Centrum"],
  "afnemers": ["ASML", "Rabobank Hart van Brabant", "Universiteit van Tilburg"],
  "jaaromzet": 412000,
  "isExempted": false,
  "exemptionBesluitId": null,
  "kostprijsMethode": "integrale-kostprijs-art-25i",
  "kostenplaatsCode": "K-9210-NMB",
  "kostendragerCode": "D-MO-NM-001",
  "grootboekDimensie": "MO",
  "acmMelding": {
    "ingediend": true,
    "datum": "2018-08-15",
    "kenmerk": "ACM/UIT/498321"
  },
  "auditTrail": {
    "createdBy": "concerncontroller@tilburg.nl",
    "createdAt": "2018-08-01T09:14:00Z",
    "lastReviewedAt": "2026-01-12T11:30:00Z"
  }
}
```

A second example with an active ABB:

```json
{
  "id": "ca-2026-gemeente-utrecht-kringloop-emmaus",
  "code": "MO-KW-002",
  "naam": "Kringloopwinkel Emmaüs Utrecht-Oost (subsidie + retail)",
  "bestuursorgaan": "Gemeente Utrecht",
  "marktsegment": "tweedehandsgoederen-retail",
  "isExempted": true,
  "exemptionBesluitId": "abb-2021-utrecht-006",
  "kostprijsMethode": "kostprijs-monitor-zonder-winstopslag",
  "publiekBelang": "Arbeidsparticipatie kwetsbare doelgroepen + circulaire economie (Coalitieakkoord 2022-2026, par. 4.3)"
}
```

### IntegralCostPrice (integrale-kostprijs)

Calculated on a configurable cadence (per default `monthly`, with year-end recalculation as `definitief`). Stored as a **time-versioned** record so historic prices remain auditable:

```json
{
  "id": "ikp-2026-q1-ca-2026-gemeente-tilburg-zaalverhuur",
  "commercialActivityId": "ca-2026-gemeente-tilburg-zaalverhuur-natuurmuseum",
  "periode": "2026-Q1",
  "berekendOp": "2026-04-05T02:00:12Z",
  "status": "definitief",
  "componenten": {
    "directeLoonkosten": 41250.00,
    "directeMaterialen": 8730.00,
    "directeAfschrijvingen": 6900.00,
    "indirecteOverhead": {
      "huisvesting": 14200.00,
      "ict": 3850.00,
      "directieEnStaf": 5620.00,
      "facilitair": 2470.00
    },
    "vermogenskosten": 1820.00,
    "winstopslag": 3960.00
  },
  "totaleKosten": 88800.00,
  "verkochteEenheden": 312,
  "eenheidLabel": "dagdeel-zaalhuur",
  "kostprijsPerEenheid": 284.62,
  "gehanteerdTarief": 295.00,
  "marge": 10.38,
  "margePercentage": 3.51,
  "compliant": true,
  "toelichting": "Overhead toegerekend volgens BBV-sleutel personeel-fte (zie OverheadDistributionRule odr-2026-tilburg-cultuur)."
}
```

### OverheadDistributionRule (overhead-verdeelsleutel)

```json
{
  "id": "odr-2026-tilburg-cultuur",
  "naam": "Overhead-verdeling Cultuur & Erfgoed 2026",
  "geldigVan": "2026-01-01",
  "geldigTot": "2026-12-31",
  "basis": "personele-fte",
  "alternatieveBases": ["m2-vloeroppervlak", "directe-loonsom", "draaiuren"],
  "bronTaakvelden": ["0.4 Overhead", "0.5 Treasury"],
  "doelKostendragers": [
    {"id": "D-MO-NM-001", "ratio": 0.072},
    {"id": "D-MO-EV-003", "ratio": 0.018},
    {"id": "D-PUBL-NM-100", "ratio": 0.910}
  ],
  "bbvConsistent": true,
  "bbvReferentie": "Notitie Overhead BBV (Commissie BBV, juli 2017)",
  "vaststellingsbesluit": "Raadsbesluit 2025-184 (Begroting 2026)"
}
```

### AlgemeenBelangBesluit (ABB)

```json
{
  "id": "abb-2021-utrecht-006",
  "kenmerk": "Raadsbesluit 2021-122",
  "bestuursorgaan": "Gemeenteraad Utrecht",
  "vaststellingsdatum": "2021-11-04",
  "publicatieGemeenteblad": "gmb-2021-401892",
  "publicatieDatum": "2021-11-12",
  "kennisgevingAcm": {
    "ingediend": true,
    "datum": "2021-11-08",
    "kenmerk": "ACM/IN/621004"
  },
  "betreftActiviteiten": ["ca-2026-gemeente-utrecht-kringloop-emmaus"],
  "publiekBelangCategorieen": ["arbeidsparticipatie", "duurzaamheid", "armoedebestrijding"],
  "motivering": "De activiteit draagt aantoonbaar bij aan re-integratie van WWB-doelgroep (38 trajecten/jaar) en aan circulaire-economie-doelstellingen. Markt is bediend door enkele particuliere kringlopen maar capaciteit ontoereikend voor doelgroep WMO/Participatiewet.",
  "evaluatieRitme": "tweejaarlijks",
  "volgendeEvaluatie": "2026-11-01",
  "status": "geldig",
  "bezwaarTermijnVerstreken": true,
  "bestuursrechtelijkeProcedures": []
}
```

### ActivityCostAllocation (boekingsplit)

Created on every journal entry that touches a commercial activity, so dat één inkomende factuur (bijv. energie van Eneco) automatisch wordt gesplitst over publieke en commerciële sub-administraties:

```json
{
  "id": "aca-2026-03-14-fact-eneco-3389",
  "journalEntryId": "je-2026-03-14-00417",
  "originalAmount": 12480.00,
  "splits": [
    {
      "kostendrager": "D-PUBL-NM-100",
      "ratio": 0.910,
      "amount": 11356.80,
      "grootboek": "443100 Energie publiek",
      "dimensie": "PUBL"
    },
    {
      "kostendrager": "D-MO-NM-001",
      "ratio": 0.072,
      "amount": 898.56,
      "grootboek": "443900 Energie marktactiviteit",
      "dimensie": "MO"
    },
    {
      "kostendrager": "D-MO-EV-003",
      "ratio": 0.018,
      "amount": 224.64,
      "grootboek": "443900 Energie marktactiviteit",
      "dimensie": "MO"
    }
  ],
  "verdeelsleutel": "odr-2026-tilburg-cultuur",
  "automatischToegepast": true,
  "handmatigeOverride": null
}
```

### ACMReport

```json
{
  "id": "acm-rap-2026-q1-tilburg",
  "bestuursorgaan": "Gemeente Tilburg",
  "rapportagePeriode": "2026-Q1",
  "generatedAt": "2026-04-10T08:21:00Z",
  "format": "ACM-standaardformulier-mo-2024",
  "activiteiten": [
    {
      "commercialActivityId": "ca-2026-gemeente-tilburg-zaalverhuur-natuurmuseum",
      "omzet": 92040.00,
      "integraleKostprijs": 88800.00,
      "geheveTarief": 92040.00,
      "kostendekking": 1.036,
      "wmoCompliant": true,
      "afwijkingenToelichting": null
    }
  ],
  "samenvatting": "Alle 7 commerciële activiteiten dragen integrale kostprijs. 0 ABB-besluiten in herziening.",
  "ondertekenaar": "concerncontroller@tilburg.nl",
  "ondertekendOp": null,
  "verzondenAanAcm": false,
  "publicatieGemeenteblad": null
}
```

## Requirements

### 1. SHALL maintain a Commercial Activity Register (Markt-activiteiten-register)

The system SHALL provide a register of all `CommercialActivity` rows per bestuursorgaan with verplichte velden: code, naam, organisatieonderdeel, beschrijving, marktsegment, concurrenten, afnemers, startDatum, kostprijsmethode, kostenplaats- en kostendragerkoppeling, ACM-meldingstatus, en (indien `isExempted=true`) een `exemptionBesluitId` dat verwijst naar een `AlgemeenBelangBesluit`.

The register SHALL block aanmaken zonder verplichte velden, SHALL auditeerbaar zijn (created/updated by + at), en SHALL een jaarlijkse review-workflow afdwingen (`lastReviewedAt` ouder dan 365 dagen triggert een taak voor de concerncontroller).

Example flow: **Gemeente Apeldoorn** opent een **dansschool in sporthal de Maten** (commercieel, want particuliere dansscholen in de regio). Controller registreert `MO-SP-014`, koppelt aan kostendrager `D-MO-SP-014`, ACM-melding wordt automatisch op de wachtrij gezet.

### 2. SHALL calculate Integral Cost Price per commercial activity per period

The system SHALL berekenen, op een configureerbare cadence (default maandelijks, met definitief jaarcijfer per 31 maart van het volgende jaar), de **integrale kostprijs** als:

```
IKP = directe loonkosten
    + directe materialen + diensten van derden
    + directe afschrijvingen
    + Σ (overhead-component_i × allocation-ratio_i)
    + vermogenskosten (gewogen kostenvoet × geïnvesteerd vermogen)
    + winstopslag (passend, default 2-5 %, instelbaar per activiteit)
```

The calculation SHALL use the BBV-overheadsleutel uit `bookkeeping-bbv-compliance` (taakveld 0.4) om dubbel werk te voorkomen, SHALL versioned worden opgeslagen, en SHALL een `compliant`-flag krijgen (`true` als geheven tarief ≥ IKP/eenheid, anders `false` met `afwijkingsToelichting` als verplicht veld).

Realistisch voorbeeld — **Gemeente Tilburg Natuurmuseum**: directe loonkosten Q1 = € 41 250 (1,2 fte zaalbeheer), materialen € 8 730 (drank/catering inkoop), afschrijvingen € 6 900 (audio-visuele apparatuur), overhead-allocatie volgens fte-sleutel 7,2 % × € 365 833 corporate overhead = € 26 140, vermogenskosten € 1 820, winstopslag 3 % × kostentotaal = € 2 545. IKP = € 87 385 over 312 dagdelen = € 280,08/dagdeel. Geheven tarief € 295 → compliant.

### 3. SHALL automatically split transactions touching commercial activities

Every `JournalEntry` waarvan de kostenplaats of kostendrager een commerciële activiteit raakt (direct of via een overhead-verdeelsleutel) SHALL automatisch een `ActivityCostAllocation` genereren die het bedrag splitst over publieke en commerciële sub-administraties volgens de geldende `OverheadDistributionRule`.

Splits SHALL volgens een geldige `OverheadDistributionRule` (geldigVan/geldigTot omsluiten transactiedatum). Handmatige override SHALL toegestaan zijn met verplichte motivering en 4-ogen-akkoord (twee user-ids).

Voorbeeld: **Waterschap Vechtstromen** heeft een commerciële **slibverwerking-as-a-service** voor naburige industrie. Inkoopfactuur ophaaldienst € 18 400 wordt automatisch 64 %/36 % gesplitst tussen publieke afvalwaterzuivering (D-PUBL-AWZI-01) en commerciële verwerking (D-MO-SVS-04). Splits volgen sleutel `odr-2026-vechtstromen-slib`.

### 4. SHALL produce a separate jaarrekening-sectie per commercial activity

The system SHALL exporteren, als onderdeel van de jaarrekening-bijlagen, per commerciële activiteit een **kostendekkingsoverzicht** met: omzet, integrale kostprijs (opgesplitst in direct/indirect/overhead/vermogenskosten/winstopslag), kostendekkingsratio, vergelijking met voorgaand jaar, en — voor `isExempted=true` activiteiten — een verwijzing naar het geldende ABB.

The export SHALL voldoen aan het **VNG-formaat WMO-bijlage jaarrekening (versie 2024)** en SHALL machine-leesbaar (SBR/XBRL) zijn voor toekomstige verplichte rapportages.

### 5. SHALL manage the Algemeen-Belang-Besluit lifecycle

The system SHALL `AlgemeenBelangBesluit` records ondersteunen met workflow: **concept → raadsvoorstel → raadsbesluit → publicatie gemeenteblad → kennisgeving ACM → bezwaartermijn (6 weken) → geldig → evaluatie → herziening of intrekking**.

The system SHALL automatisch een evaluatie-taak triggeren op `volgendeEvaluatie` (default tweejaarlijks per VNG-handreiking 2023), SHALL koppelen aan een gemeenteraad-besluit (via bookkeeping-governance of een externe raadsinformatiesysteem-link), en SHALL publicatie verifiëren door automatisch de DROP-API (Decentrale Regelgeving en Officiële Publicaties) te checken op het `gmb-` kenmerk.

Voorbeeld: **Provincie Gelderland** wil een ABB voor de **exploitatie van het Airborne Museum**. Workflow doorloopt provinciale staten besluit PS2026-44, publicatie in provinciaal blad pb-2026-78, kennisgeving ACM kenmerk ACM/IN/710022, en wordt over twee jaar automatisch geherwaardeerd.

### 6. SHALL generate ACM-rapportages

The system SHALL kwartaal- en jaarrapportages genereren in het ACM-standaardformulier (Markt en Overheid 2024) met: alle commerciële activiteiten, hun omzet, IKP, kostendekkingsratio, lijst van ABB'en met motivering, en alle handmatige split-overrides van het kwartaal.

Rapportages SHALL gepreviewed worden, vereisen formele ondertekening door de concerncontroller (digitale handtekening + tijdsstempel), en kunnen gepubliceerd worden in het gemeenteblad. Verzonden rapportages SHALL onveranderbaar worden gemaakt (write-once) en gearchiveerd met retentie 7 jaar (conform Mededingingswet bewaartermijn).

### 7. SHALL detect and alert on cross-subsidy risks

The system SHALL maandelijks een **cross-subsidy-detector** draaien die alerten op:

- IKP/eenheid > geheven tarief gedurende 2 opeenvolgende perioden (mogelijk verliesfinanciering),
- omzetgroei > 25 % yoy zonder bijbehorende IKP-update,
- overhead-allocatie ratio < 1 % terwijl directe kosten > 10 % van totaal (mogelijke under-allocation),
- ABB-besluit > 2 jaar geleden geëvalueerd,
- handmatige splits-overrides > 5 % van transacties in een kwartaal,
- nieuwe leveranciersfactuur > € 50 000 op publieke kostenplaats waarvan > 30 % in vorige boekjaar naar commerciële dragers werd doorbelast (signaal van potentiële overhead-onderschatting).

Alerts SHALL routeren naar de concerncontroller, met escalatie naar gemeentesecretaris als 4 weken niet geadresseerd. Alle alerts en hun afhandeling SHALL geaudit worden in het kader van een ACM-onderzoek.

Realistisch voorbeeld — ACM-zaak **Hilversum/Crailo**: een vroegtijdige cross-subsidy-alert had de gemeente kunnen wijzen op een te lage huurprijs voor de jachthaven (€ 35/m vs marktconform € 78/m).

### 8. SHALL handle activity transitions (public ↔ commercial)

The system SHALL een transitie-workflow ondersteunen voor activiteiten die van **publiek → commercieel** of **commercieel → publiek** overgaan, óf binnen commercieel van **regulier → ABB-exempt**.

De workflow SHALL: een effectieve datum vastleggen, alle openstaande verplichtingen op de oude dimensie afsluiten, een **openingsbalans van de commerciële sub-administratie** genereren met activa-overdracht tegen marktwaarde (niet boekwaarde — anders verboden bevoordeling per Art. 25j Mw), de eerste IKP-cyclus markeren als `voorlopig-transitie`, en de ACM-melding triggeren binnen 4 weken na effectieve datum.

Voorbeeld: **Gemeente Almere** besluit haar **sportkantine-exploitatie** vanaf 1 januari 2026 commercieel te voeren. Inventaris (€ 87 000 boekwaarde, € 142 000 marktwaarde getaxeerd) wordt voor € 142 000 overgedragen aan kostendrager D-MO-SP-020 en geboekt als interne verkoop ten gunste van publieke dimensie; eerste IKP per 31 maart 2026 voorlopig.

### 9. SHALL integrate with the gemeenteraad-besluit workflow (governance)

The system SHALL de ABB-workflow én de jaarlijkse vaststelling van overhead-verdeelsleutels koppelen aan de **bestuurlijke besluitvormingsketen**: raadsvoorstel-id, agendapunt-id, stemuitslag, ondertekening griffier/burgemeester.

Koppeling SHALL via een open interface (default: `bookkeeping-governance` spec, secundair: iBabs/NotuBiz/GO. raadsinformatiesystemen API). Een IKP-tarief of ABB SHALL niet `geldig` mogen worden zonder gekoppeld vastgesteld bestuursbesluit.

### 10. SHALL provide an audit trail meeting ACM-onderzoek standards

Every wijziging op `CommercialActivity`, `IntegralCostPrice`, `OverheadDistributionRule`, `AlgemeenBelangBesluit`, `ActivityCostAllocation` (override), en `ACMReport` SHALL een onveranderbare audit-log entry genereren met user-id, tijdstempel (UTC, ms-resolutie), voor/na-waarden, en motivering (verplicht voor overrides en herzieningen).

Logs SHALL exporteerbaar zijn in CSV én een ACM-handhavings-pakket (zip met geïndexeerde PDF's + JSON-manifest) dat in één klik kan worden geleverd na een vordering ex Art. 5:17 Awb of Art. 5:20 Awb.

Concreet voorbeeld van een handhavings-pakket: in **ACM-zaak Eindhoven Parkeerexploitatie (2017)** vorderde ACM zes jaar aan tarief-onderbouwingen, overhead-allocatiesleutels, bestuursbesluiten en interne mailwisselingen rond tariefvaststelling. De gemeente leverde een pakket van 8 600 documenten in losse PDF's zonder index, hetgeen de zaakduur verlengde van een geschatte 6 maanden naar 22 maanden. Met deze spec wordt zo'n pakket in seconden, met machine-leesbare manifest, gegenereerd: één klik op "Genereer ACM-handhavings-pakket" → zip-archief met `manifest.json`, `commercial-activities/<id>.json`, `cost-prices/<period>/<id>.json`, `allocations/<period>/<journal-id>.json`, `besluiten/<id>.pdf`, `audit-log/<period>.csv`.

### 11. SHALL support multi-bestuursorgaan (gemeenschappelijke regelingen, shared services)

The system SHALL ondersteunen dat één commerciële activiteit door **meerdere bestuursorganen gezamenlijk** wordt geëxploiteerd — typisch in **gemeenschappelijke regelingen** (GR), **omgevingsdiensten** (RUD), **GGD'en** of shared-service-centra. In dat geval SHALL het CommercialActivity-record meerdere `bestuursorgaan`-eigenaren bevatten met eigen aandeel-percentages, eigen kostendrager-koppelingen en eigen IKP-allocaties.

Voorbeeld: **Omgevingsdienst Regio Arnhem (ODRA)** verricht voor 11 deelnemende gemeenten een **commerciële bodemadvies-dienst** aan particuliere ontwikkelaars (concurrent met Antea Group, RoyalHaskoningDHV). Omzet en IKP worden in ODRA-administratie gevoerd; jaarlijkse resultaat wordt verdeeld over deelnemers volgens GR-verdeelsleutel (inwoneraantal-weighted). Elke deelnemer ontvangt een eigen jaarrekening-bijlage WMO met haar aandeel.

The system SHALL bij multi-eigenaar-activiteiten één ABB-besluitstapel ondersteunen waarbij **élk** deelnemend bestuursorgaan zelfstandig een ABB moet vaststellen — een ontbrekend ABB bij één deelnemer maakt de exemptie ongeldig voor die deelnemer's aandeel. Dit is een veelgemiste compliance-val: VNG-rapport 2023 vond dat 41 % van de GR-ABB's dit verzuim heeft.

### 12. SHALL maintain a market-benchmark register for tarief-validatie

The system SHALL een `MarketBenchmark`-register bijhouden waarmee voor elke commerciële activiteit het geheven tarief vergeleken kan worden met marktconforme prijzen van benoemde concurrenten. Velden: peildatum, bron (offerte, prijslijst, branche-rapport, BDO Benchmark, COELO), bedrag, eenheid, motivering bij outliers.

Bij IKP-berekening met `geheven tarief < gemiddelde marktprijs - 15 %` SHALL het systeem een **bevoordeling-risico-flag** zetten (potentiële schending Art. 25j Mw bevoordelingsverbod), zelfs als IKP-kostendekkend is — want te lage tarieven kunnen alsnog als bevoordeling van eigen overheidsbedrijf gelden.

Voorbeeld: **Gemeente Den Haag** verhuurt vergaderzalen Atrium aan € 180/dagdeel. IKP = € 165 (kostendekkend), maar markttarief vergelijkbare locatie € 245. De spread is 27 % onder markt → flag → onderbouwing vereist (bv. lagere service-kwaliteit, geen catering) of tariefverhoging.

## Implementation phasing & technical notes

The 12 requirements decomponeren goed in **3 implementatie-fasen** voor shillinq:

**Fase 1 (MVP, Q3 2026)**: Req 1, 2, 3, 4 — register + IKP-berekening + automatische split + jaarrekening-bijlage. Levert direct waarde voor élke gemeente die nu Excel-WMO doet, en is afhankelijkheid voor alles wat volgt.

**Fase 2 (Compliance, Q4 2026)**: Req 5, 6, 7, 10 — ABB-lifecycle + ACM-rapportage + cross-subsidy-detector + audit-trail. Maakt het verschil tussen "administratief in orde" en "ACM-bestendig".

**Fase 3 (Governance & ecosystem, Q1-Q2 2027)**: Req 8, 9, 11, 12 — transitie-workflow, governance-koppeling, multi-bestuursorgaan, marktbenchmark. Vereist integratie met bookkeeping-governance, raadsinformatiesystemen, en optioneel een gedeeld benchmark-platform (kandidaat: VNG Realisatie als data-trustee).

**Performance & schaal**: een grote gemeente (Amsterdam: ca. 90 commerciële activiteiten, > 2 miljoen journaalposten per jaar waarvan ca. 18 % WMO-relevant) genereert ca. 360 000 ActivityCostAllocation-rows/jaar. De automatische split MOET asynchroon kunnen (queue-based, in lijn met OpenRegister event-bus per ADR-008), met SLA van 5 minuten van JournalEntry-commit tot allocatie-zichtbaarheid in dashboards.

**Internationalisering**: alle UI-strings nl/en (per fleet-i18n-vereiste), maar **datamodel-velden blijven Nederlands** (juridisch verankerd: "algemeen-belang-besluit" is een wettelijke term, niet vertaalbaar zonder betekenis-verlies; vergelijk fiscale spec waar BTW-termen origineel blijven).

**Privacy/AVG**: het register bevat **geen** persoonsgegevens van afnemers (alleen organisatie-namen op B2B-basis); audit-trail-user-ids zijn pseudoniem en mappen naar Nextcloud-accounts. Bewaartermijn 7 jaar (Mededingingswet) prevaleert boven AVG-data-minimalisatie. Geen DPIA nodig op deze module (wel op de bredere shillinq-suite).

## Standards & Sources

- **Mededingingswet, hoofdstuk 4b (Art. 25g–25m)** — overheidsorganisaties die economische activiteiten verrichten; in werking 1 juli 2012, gewijzigd 1 juli 2014 (vervallen wettelijke evaluatiebepaling, ACM-bevoegdheden uitgebreid).
- **Besluit Markt en Overheid** (Stb. 2012, 255) — uitwerking integrale-kostprijsregels, exemptiecategorieën, overgangsrecht.
- **Beleidsregel integrale kostprijsberekening (Min. EZ, 2012)** — toelichting op vermogenskosten, overhead-toerekening, winstopslag.
- **ACM-leidraad "Wet Markt en Overheid voor decentrale overheden" (2019, geactualiseerd 2022)** — ACM-interpretaties, voorbeelden, handhavingsbeleid.
- **ACM-evaluatierapport "Werking Wet Markt en Overheid"** (april 2022, kenmerk ACM/UIT/575213) — knelpunten, aanbevelingen, casestatistieken (78 onderzoeken sinds 2014, 14 boetes).
- **Handreiking VNG "Wet Markt en Overheid in de praktijk" (2018, herzien 2023)** — implementatiestappen, voorbeeldbesluiten, ABB-template.
- **VNG-rapport "Algemeen belang in beeld" (2023)** — analyse van 327 ABB-besluiten, kwaliteit van motiveringen, jurisprudentie.
- **Notitie Overhead BBV (Commissie BBV, juli 2017)** — geconsolideerde regels overhead-allocatie, basis voor WMO-conformiteit.
- **Adviescollege Toetsing Regeldruk advies "WMO en MKB-impact" (2021)** — regeldruk en concurrentie-effecten.
- **Jurisprudentie**: CBb 11 juli 2017 (ECLI:NL:CBB:2017:226, Veendam parkeren), CBb 28 januari 2020 (ECLI:NL:CBB:2020:48, Eindhoven), Rb. Rotterdam 13 september 2018 (ECLI:NL:RBROT:2018:7575, Hilversum Crailo), CBb 2 maart 2021 (ECLI:NL:CBB:2021:225, Reigersbos), ABRvS 24 juni 2020 (ECLI:NL:RVS:2020:1495, ABB-procedure Groningen).
- **DROP-API** (Decentrale Regelgeving en Officiële Publicaties, KOOP) — automatische verificatie publicatie gemeenteblad/provinciaal blad/waterschapsblad.
- **SBR/XBRL-taxonomieën** — toekomstige machine-leesbare ACM-rapportage (in lijn met NTA Gemeente/Provincie).
- **Algemeen-belang-besluit template (VNG modelverordeningen)** — standaardtekst raadsbesluit + motivering.

## Cross-app integration

- **bookkeeping-bbv-compliance** — fundament voor overhead-allocatie. WMO IKP-berekening hergebruikt de BBV-overheadsleutel (taakveld 0.4) zodat één bron de IKP én de jaarrekening-overhead-presentatie voedt. Inconsistentie tussen WMO-sleutel en BBV-sleutel zou bij een ACM-onderzoek én bij accountantscontrole een issue zijn.
- **bookkeeping-cost-centers-dimensions** — levert de kostenplaats/kostendrager-primitieven én de meer-dimensionale grootboekstructuur. Deze spec voegt de WMO-dimensie (`MO` vs `PUBL`) toe als verplicht splitsings-attribuut en breidt de dimensie-validatie uit.
- **bookkeeping-general-ledger** — koppelvlak voor het automatisch splitsen van journaalposten. Elke JournalEntry-write die een commerciële kostenplaats/drager raakt, triggert een `ActivityCostAllocation` voor de splits worden geschreven.
- **bookkeeping-governance** (raadsvoorstellen/besluiten) — leverancier van bestuurlijke besluitvorming voor ABB en jaarlijkse overhead-sleutels.
- **bookkeeping-financial-reporting** — consumer van de IKP- en kostendekkingsdata voor de jaarrekening-bijlage WMO en voor de raadsmonitor commerciële activiteiten.
- **bookkeeping-audit-trail** (cross-cutting) — alle WMO-mutaties stromen in dezelfde immutable log-store die de accountant en ACM kunnen bevragen.
- **openconnector** — DROP-API en (toekomstig) ACM-portaal-koppeling lopen via OC-sources zodat externe authenticatie/throttling centraal blijft.

## Target users

- **Concerncontroller gemeente / provincie / waterschap** — eindverantwoordelijk voor WMO-naleving, beheert het commerciële-activiteiten-register, beoordeelt cross-subsidy-alerts, tekent de ACM-rapportage en stuurt aan op herziening van IKP-tarieven.
- **BBV-specialist / financieel beleidsadviseur** — beheert de overhead-verdeelsleutels in samenhang met de BBV-overhead-notitie, adviseert raad/college over IKP-tarieven en jaarrekening-bijlagen.
- **Beleidsmedewerker juridisch (algemeen belang)** — stelt ABB-voorstellen op, motiveert publiek belang, beheert evaluatiecyclus, verwerkt eventuele bezwaarschriften en bestuursrechtelijke procedures.
- **Accountant Public Sector (Big 4 of gemeentelijke accountantsdienst)** — controleert kostendekkingsoverzichten in de jaarrekeningcontrole, valideert overhead-sleutels en cross-subsidy-rapportage, gebruikt de audit-trail voor steekproeven.
- **Griffier / raadsadviseur** — beheert de bestuurlijke besluitvorming-koppeling (ABB-besluiten, vaststelling overhead-sleutels) en zorgt voor publicatie.
- **ACM-onderzoeker (extern, lezer)** — ontvangt op vordering een geïndexeerd handhavings-pakket; de spec ontwerpt voor dít publiek de export-format en audit-trail-volledigheid.
- **Gemeentesecretaris / directeur bedrijfsvoering** — eindescalatiepunt voor onopgeloste cross-subsidy-alerts en transitie-besluiten van activiteiten naar/uit het commerciële regime.

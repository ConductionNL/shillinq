---
status: draft
---

# KOR (Kleine Ondernemersregeling)

## Purpose

De Kleine Ondernemersregeling (KOR) is een Nederlandse btw-vrijstellingsregeling voor kleine ondernemers met een jaaromzet onder EUR 20.000. Sinds de modernisering van 1 januari 2020 (omzettingsregeling) en de uitbreiding KOR-EU per 1 januari 2025 (Wet implementatie Richtlijn (EU) 2020/285) vormt de KOR voor honderdduizenden ZZP'ers en kleine MKB-ondernemers het fiscale ankerpunt waarin zij hun btw-administratie radicaal vereenvoudigen. Volgens cijfers van de Belastingdienst maakten in 2024 ruim 320.000 ondernemers gebruik van de KOR — overwegend ZZP'ers in dienstverlening, kleine webshops, hobby-ondernemers en starters.

Deze spec beschrijft hoe shillinq als boekhoud-app de volledige KOR-levenscyclus ondersteunt: aanmelding bij de Belastingdienst, drempelbewaking met preventieve waarschuwingen op 80/90/100 procent, automatische middenjaar-revocatie bij overschrijding, factuurgeneratie zonder btw met de correcte verplichte vermelding, blokkering van voorbelasting-aftrek, drie-jaars-lock-in handhaving, opt-out na de verplichte periode, en — nieuw per 2025 — grensoverschrijdende KOR-EU registraties met de EU-brede EUR 100.000-drempel en de jaarlijkse Q-aangifte (kwartaalopgaaf cross-border omzet).

Het doel is dat een ondernemer die KOR kiest op één plek (1) een correcte aanvraagstroom doorloopt richting mijnbelastingdienst.nl, (2) zijn drempel realtime ziet meebewegen met geboekte omzet, (3) facturen ontvangt die voldoen aan artikel 25 Wet OB 1968, (4) niet per ongeluk voorbelasting claimt, (5) tijdig gewaarschuwd wordt bij dreigende overschrijding, en (6) bij feitelijke overschrijding direct overschakelt op het reguliere btw-regime met de juiste herrekening en suppletie. De spec dekt zowel binnenlandse KOR (artikel 25 OB) als KOR-EU (artikelen 25a t/m 25d OB).

KOR is fiscaal onomkeerbaar gedurende drie boekjaren — een ZZP'er die in maart 2026 toetreedt zit vast tot en met 31 december 2028. Verkeerde aanmelding kost dus letterlijk drie jaar suboptimale fiscaliteit; dat maakt validatie, scenario-analyse en duidelijke informatievoorziening in de app cruciaal. De app moet de ondernemer behoeden voor onbedoelde aanmelding (door te dwingen tot een expliciete bevestiging) én voor ongewenste overschrijding (door tijdige waarschuwingen). Beide kanten van die fiscale val zijn voor de kleine ondernemer reëel: jaarlijks krijgt de Belastingdienst duizenden bezwaarschriften van ondernemers die zich onvoldoende geïnformeerd voelden — die signalen vormen mede de aanleiding voor deze spec.

De spec bouwt voort op de Belastingdienst-process-flow zoals beschreven in het Handboek Ondernemen (editie 2026) en op de praktijkadviezen van de Register Belastingadviseurs (RB) en het Nederlands Orde van Belastingadviseurs (NOB). Waar mogelijk wordt aangesloten bij de XBRL-taxonomie van de Belastingdienst voor toekomstige machine-to-machine aanmelding (SBR-route), hoewel KOR-aanmelding op moment van schrijven (mei 2026) nog steeds web-formulier-only is via mijnbelastingdienst.nl/zakelijk.

## Data Model

### KORRegistration

De kern-entiteit: één registratie per onderneming per regime (NL-KOR of KOR-EU). Bevat aanmeldgegevens, ingangsdatum, drie-jaars-lock-in einddatum, status, en — voor KOR-EU — de lidstaten van vestiging en de EX-nummer toekenning.

```json
{
  "id": "kor-reg-2026-0042",
  "ondernemingId": "ond-nl-001234",
  "regime": "KOR_NL",
  "status": "ACTIEF",
  "aanmeldDatum": "2025-11-15",
  "ingangsDatum": "2026-01-01",
  "lockInEindDatum": "2028-12-31",
  "vroegsteOpzegDatum": "2028-10-01",
  "belastingdienstReferentie": "KOR-2025-NL-89231",
  "aanmeldKanaal": "MIJN_BELASTINGDIENST_ZAKELIJK",
  "drempelJaar": 20000,
  "voorgaandeOmzet": {
    "2025": 17820.50,
    "2024": 15200.00
  },
  "omzettingsRegeling": false,
  "fiscalEenheidId": null
}
```

### KORAnnualTurnover

Lopende registratie van KOR-relevante omzet per kalenderjaar. Niet álle omzet telt mee — bijvoorbeeld vrijgestelde prestaties (onderwijs, medisch) en omzet uit onroerend goed kunnen anders behandeld worden. Deze entiteit verzamelt per maand zodat drempelvoorspellingen mogelijk zijn.

```json
{
  "id": "kor-turnover-2026-ond-001234",
  "registrationId": "kor-reg-2026-0042",
  "jaar": 2026,
  "lopendeOmzet": 16420.00,
  "drempel": 20000,
  "drempelBenutting": 0.821,
  "perMaand": {
    "2026-01": 1380.00,
    "2026-02": 1850.00,
    "2026-03": 2110.00,
    "2026-04": 1990.00,
    "2026-05": 2200.00,
    "2026-06": 1840.00,
    "2026-07": 2510.00,
    "2026-08": 2540.00
  },
  "uitgeslotenPosten": [
    {"type": "VRIJGESTELDE_PRESTATIE", "bedrag": 4200.00, "grondslag": "art. 11 OB"}
  ],
  "prognoseEindeJaar": 24630.00,
  "prognoseStatus": "OVERSCHRIJDING_VERWACHT"
}
```

### KORThresholdAlert

Waarschuwingsevent — gegenereerd zodra benutting een drempelschijf passeert. Drie schijven: 80% (vroege waarschuwing), 90% (kritieke waarschuwing met advies), 100% (overschrijdingsmelding met automatische revocatie-trigger).

```json
{
  "id": "alert-2026-08-ond-001234",
  "registrationId": "kor-reg-2026-0042",
  "trigger": "DREMPEL_90PCT",
  "uitgeloostOp": "2026-08-12T14:23:00Z",
  "omzetOpMoment": 18120.00,
  "drempelBenutting": 0.906,
  "prognoseEindeJaar": 24630.00,
  "ernst": "KRITIEK",
  "aanbeveling": "OPT_OUT_OVERWEGEN",
  "kanaal": ["EMAIL", "IN_APP", "DASHBOARD"],
  "bevestigdDoor": null,
  "actieOndernomen": null
}
```

### KORRevocation

Beëindigingsentiteit. Bevat zowel vrijwillige opzegging na drie jaar als gedwongen revocatie wegens overschrijding. Bij overschrijding wordt de revocatieDatum altijd teruggezet naar de leveringsdatum van de transactie die de drempel deed kantelen — niet einde-jaar, niet einde-kwartaal.

```json
{
  "id": "kor-rev-2026-09-ond-001234",
  "registrationId": "kor-reg-2026-0042",
  "type": "OVERSCHRIJDING",
  "revocatieDatum": "2026-09-04",
  "triggerFactuurId": "fact-2026-0287",
  "omzetOpMoment": 20240.00,
  "btwSuppletieVerschuldigd": true,
  "btwSuppletieBedrag": 4250.40,
  "herrekeningRange": {"van": "2026-09-04", "tot": "2026-12-31"},
  "nieuwRegime": "REGULIER_BTW",
  "blokkadeHeraanmelding": "2029-01-01",
  "belastingdienstNotificatie": {
    "verzonden": true,
    "verzondenOp": "2026-09-05T08:15:00Z",
    "bevestigingsnummer": "BD-MUT-2026-44782"
  }
}
```

### KORInvoice (no-BTW variant)

Factuurvariant zonder btw-tarief, zonder verleggingsregeling, met verplichte vermelding op factuur. Geen "0% btw" want dat is fiscaal iets anders (nultarief bij export); KOR is een vrijstelling.

```json
{
  "id": "fact-2026-0214",
  "factuurnummer": "2026-0214",
  "datum": "2026-08-12",
  "ondernemingId": "ond-nl-001234",
  "klantId": "klant-9912",
  "regels": [
    {
      "omschrijving": "Knipbeurt + wassen",
      "aantal": 1,
      "prijsPerStuk": 32.50,
      "totaal": 32.50,
      "btwTarief": null,
      "btwBedrag": 0,
      "vrijstellingsGrondslag": "KOR_ART25_OB"
    }
  ],
  "subtotaal": 32.50,
  "btwTotaal": 0,
  "totaal": 32.50,
  "vermeldingOpFactuur": "Vrijgesteld van btw op grond van artikel 25 Wet op de omzetbelasting 1968 (Kleine Ondernemersregeling).",
  "korRegistrationId": "kor-reg-2026-0042",
  "voorbelastingAftrekbaar": false
}
```

### KOREUTurnover (alleen bij regime KOR_EU)

Per-lidstaat omzetregistratie voor cross-border KOR. EU-brede drempel: EUR 100.000 (totaal), met daarnaast per lidstaat hun nationale KOR-drempel.

```json
{
  "id": "kor-eu-turnover-2026-ond-001234",
  "registrationId": "kor-reg-eu-2026-0019",
  "exNummer": "EX-NL-2026-019234",
  "jaar": 2026,
  "totaalEUOmzet": 47800.00,
  "drempelEUBrut": 100000,
  "perLidstaat": {
    "BE": {"omzet": 12400.00, "drempelBE": 25000, "benutting": 0.496},
    "DE": {"omzet": 8200.00, "drempelDE": 22000, "benutting": 0.372},
    "FR": {"omzet": 9100.00, "drempelFR": 85000, "benutting": 0.107},
    "NL": {"omzet": 18100.00, "drempelNL": 20000, "benutting": 0.905}
  },
  "kwartaalopgaafStatus": {
    "Q1": "INGEDIEND",
    "Q2": "INGEDIEND",
    "Q3": "OPEN"
  }
}
```

## Requirements

### REQ-001: KOR opt-in workflow met scenario-analyse

Het systeem MOET een aanmeldstroom bieden die (a) de ondernemer historische omzet uit de laatste twee boekjaren toont, (b) een prognose voor het lopende jaar berekent, (c) duidelijk de drie-jaars-lock-in communiceert met einddatum, (d) berekent hoeveel voorbelasting de ondernemer verliest op basis van laatste-jaar aftrek, (e) een fiscaal advies-vergelijking presenteert (KOR vs. regulier), en (f) na bevestiging een vooringevulde aanvraag genereert voor mijnbelastingdienst.nl/zakelijk.

#### Scenario: ZZP-fotograaf overweegt KOR met grensgeval

**GEGEVEN** een ZZP-fotograaf met omzet 2024: EUR 19.200 en 2025: EUR 16.800
**EN** lopende prognose 2026: EUR 18.500 (gebaseerd op H1)
**EN** voorbelasting laatste jaar: EUR 980 (apparatuur, software, autokosten)
**WANNEER** zij de KOR-aanvraag start
**DAN** toont het systeem een vergelijking: regulier regime EUR 980 aftrek + EUR ~3.885 btw afdracht netto-effect EUR -2.905, versus KOR netto-effect EUR 0 op btw maar EUR 0 voorbelasting
**EN** waarschuwt het systeem dat 2024 dichtbij de drempel zat ("histories patroon — overweeg buffermarge")
**EN** kan zij niet aanmelden zonder de drie-jaars-lock-in checkbox ("Ik begrijp dat ik tot 31-12-2028 geen wijziging kan aanvragen") expliciet aan te vinken

### REQ-002: Realtime drempelbewaking met maandelijkse prognose

Het systeem MOET na elke geboekte verkoopfactuur de lopende KOR-omzet herrekenen, de benutting (lopende omzet / EUR 20.000) bijwerken, en een lineaire prognose voor eindejaars-omzet tonen op basis van year-to-date trend. Vrijgestelde prestaties (art. 11 OB), intracommunautaire leveringen onder reguliere btw, en uitgeslotenposten worden NIET meegerekend in de KOR-drempel.

### REQ-003: Drempelschijven 80% / 90% / 100% met escalerende alerts

Het systeem MOET drie alert-schijven bewaken: (a) bij overgang van <80% naar >=80% een vroege informatieve waarschuwing per email + in-app, (b) bij >=90% een kritieke waarschuwing met expliciet advies over opt-out-mogelijkheden en factureer-strategie (vooruitfactureren naar januari), (c) bij 100% (overschrijding) een directe revocatie-flow met begeleidende uitleg.

#### Scenario: Kapper nadert grens halverwege jaar

**GEGEVEN** een ZZP-kapper Maria met lopende omzet EUR 16.420 in augustus
**EN** maandgemiddelde van EUR 2.050
**WANNEER** zij een factuur boekt van EUR 200 die de teller op EUR 18.120 brengt (90,6% benutting)
**DAN** genereert het systeem direct een KORThresholdAlert met ernst KRITIEK
**EN** stuurt een email "Je hebt 90% van je KOR-drempel bereikt. Met je huidige tempo overschrijd je de EUR 20.000 in november."
**EN** toont in het dashboard een rode banner met drie opties: (1) doorgaan en accepteren dat KOR per overschrijdingsdatum eindigt, (2) klantopdrachten doorschuiven naar januari, (3) deelvolume factureren onder reguliere btw na bewuste revocatie
**EN** linkt naar belastingdienst.nl artikel over KOR-overschrijding

### REQ-004: Automatische middenjaar-revocatie bij overschrijding

Het systeem MOET bij feitelijke overschrijding (>EUR 20.000 lopend) automatisch (a) de KORRegistration status zetten naar GEEINDIGD_OVERSCHRIJDING, (b) de revocatieDatum gelijkstellen aan de leveringsdatum van de triggerfactuur, (c) álle facturen vanaf die datum hermarkeren met reguliere btw, (d) een suppletieaangifte berekenen voor reeds verzonden KOR-facturen na revocatiedatum, en (e) een blokkade op heraanmelding registreren van drie jaar.

#### Scenario: Webshop-houder overschrijdt met grote bestelling

**GEGEVEN** een webshop met lopende KOR-omzet EUR 19.840
**WANNEER** een klant bestelt voor EUR 412 (leveringsdatum 4 september)
**DAN** kantelt de teller naar EUR 20.252 — overschrijding
**EN** roept het systeem het revocatieproces aan met datum 4-9-2026
**EN** wordt de factuur van die bestelling alsnog uitgegeven met 21% btw (EUR 71,53)
**EN** krijgt de ondernemer een duidelijke melding "Je KOR is per 4 september beëindigd. Vanaf nu factureer je weer met btw. Je mag nu wel voorbelasting aftrekken vanaf deze datum."
**EN** wordt een kalenderitem ingepland voor de eerstvolgende kwartaalaangifte

### REQ-005: Factuur zonder btw met verplichte vermelding artikel 25 OB

Het systeem MOET KOR-facturen genereren zonder btw-tarief en zonder btw-bedrag, met een tekstuele vermelding die exact voldoet aan de eis van artikel 35a lid 1 onder n Wet OB. De standaard tekst is: "Vrijgesteld van btw op grond van artikel 25 Wet op de omzetbelasting 1968 (Kleine Ondernemersregeling)." Deze vermelding mag NIET vervangen worden door "0% btw" of "geen btw verschuldigd".

### REQ-006: Blokkade voorbelasting-aftrek tijdens KOR

Het systeem MOET bij geboekte inkoopfacturen de voorbelasting-aftrek automatisch op nul zetten zolang de KORRegistration ACTIEF is. De brutobedragen worden geboekt als kosten (inclusief btw). Bij correctie/credit van een inkoopfactuur na revocatiedatum moet het systeem voorbelasting correctief alsnog terug-claimen voor de periode na revocatie.

### REQ-007: Drie-jaars-lock-in handhaving + opt-out workflow na afloop

Het systeem MOET opzegging vóór de lockInEindDatum blokkeren behalve in expliciete uitzonderingsgevallen (overlijden, staking, faillissement, fiscale herstructurering). Vanaf vroegsteOpzegDatum (drie maanden voor lock-in einde) mag de ondernemer een opt-out aanvraag indienen die per de eerstvolgende kalenderjaar-grens effectief wordt.

#### Scenario: Ondernemer wil eerder uit dan toegestaan

**GEGEVEN** een ondernemer met KOR sinds 2026, lockInEindDatum 31-12-2028
**WANNEER** hij in juni 2027 opt-out probeert vanwege groeiplannen
**DAN** weigert het systeem de opzegging met uitleg "Je KOR loopt nog tot 31-12-2028. Eerste opzegmogelijkheid: 1 oktober 2028 voor ingang per 1-1-2029."
**EN** biedt het systeem alternatieven: vooruitfactureren naar januari, vrijwillige overschrijding via een geplande grote verkoop (waarbij de ondernemer expliciet bevestigt dat hij de overschrijding wil — niet aanbevolen)

### REQ-008: KOR-EU registratie en EX-nummer beheer

Het systeem MOET een aparte KOR-EU aanmeldroute bieden vanaf 1-1-2025, die (a) de ondernemer een EX-nummer (NL-prefix) laat aanvragen via de KOR-EU portal van de Belastingdienst, (b) per beoogde lidstaat de nationale KOR-drempel toont, (c) de EU-brede EUR 100.000-drempel bewaakt, (d) de verplichte kwartaalopgaaf (Q1/Q2/Q3/Q4) per kwartaal genereert met omzet per lidstaat, en (e) cross-border KOR-facturen voorziet van de vermelding "Exempt from VAT pursuant to special scheme for small enterprises (Article 284 VAT Directive 2006/112/EC)".

### REQ-009: Jaarlijkse omzetopgaaf en eindafrekening

Het systeem MOET na 31 december van elk KOR-jaar (a) een definitieve jaaromzet vaststellen, (b) een rapportage genereren voor de ondernemer ter verantwoording, (c) bij KOR-EU de jaarlijkse eindopgaaf voorbereiden voor indiening, en (d) de drempelbenutting van het afgelopen jaar tonen ten opzichte van de drie-jaars-trend ("Je hebt drie jaar onder de drempel gezeten — overweeg of KOR nog steeds optimaal is").

### REQ-010: Drempelbeoordeling vooraf en branche-specifieke uitsluitingen

Het systeem MOET een drempelbeoordeling (vergelijkbaar met de Belastingdienst-tool "Drempelbeoordeling KOR") integreren die de ondernemer vóór aanmelding doorrekent op basis van (a) verwachte omzetcategorieën, (b) reeds bestaande btw-vrijstellingen, (c) eventuele intracommunautaire prestaties die buiten de KOR-drempel vallen maar wel onder reguliere btw worden behandeld, en (d) branchespecifieke aandachtspunten. Voor bepaalde branches (bijvoorbeeld onroerend-goed verhuur, financiële diensten, gezondheidszorg) bestaan combinaties met bestaande vrijstellingen die de KOR-keuze irrelevant of contraproductief maken — het systeem signaleert dit.

#### Scenario: Onroerend-goed verhuurder probeert KOR

**GEGEVEN** een ondernemer die uitsluitend btw-vrijgestelde verhuur van woonruimte doet
**WANNEER** hij KOR overweegt
**DAN** legt het systeem uit dat zijn prestaties al volledig vrijgesteld zijn o.g.v. art. 11-1-b OB
**EN** dat KOR geen toegevoegde waarde heeft maar wel onnodig de voorbelasting-aftrek voor onderhoudskosten blokkeert
**EN** raadt het systeem aan om af te zien van KOR-aanmelding

### REQ-011: Transitie regulier → KOR en KOR → regulier

Het systeem MOET bij transitie van regulier btw-regime naar KOR (a) de voorraad-correctie berekenen volgens herzieningsregels (art. 13 Uitvoeringsbeschikking OB) voor investeringsgoederen jonger dan 5 jaar (10 jaar voor onroerend goed), (b) een suppletieaangifte voor het overgangsmoment voorbereiden, en bij transitie KOR → regulier (c) het herrekenrecht voor voorbelasting op nog niet gebruikte goederen activeren, (d) de eerstvolgende kwartaalaangifte voorbereiden, en (e) klanten waar nodig informeren over gewijzigde factuurpresentatie.

#### Scenario: Mixed-use ondernemer met gedeeltelijk vrijgestelde prestaties

**GEGEVEN** een yoga-docent die zowel yogalessen (vrijgesteld o.g.v. art. 11-1-p OB) als merchandise (belast) verkoopt
**EN** merchandise-omzet 2025: EUR 14.500
**WANNEER** zij KOR overweegt
**DAN** legt het systeem uit dat alleen de belaste prestaties meetellen voor de KOR-drempel
**EN** waarschuwt dat KOR de aftrek-mogelijkheid voor merchandise-inkopen blokkeert, maar geen invloed heeft op de bestaande vrijstelling voor lessen
**EN** berekent het netto-effect alleen over het merchandise-deel

## Standards & Sources

- **Wet op de omzetbelasting 1968, artikel 25** — kerngrondslag KOR sinds 1-1-2020 (omzettingsregeling die de oude degressieve KOR verving)
- **Wet op de omzetbelasting 1968, artikelen 25a t/m 25d** — KOR-EU bepalingen geldig vanaf 1-1-2025 (Wet implementatie Richtlijn (EU) 2020/285)
- **Richtlijn (EU) 2020/285** — Europese richtlijn die de bijzondere regeling voor kleine ondernemingen moderniseert; geïmplementeerd in NL per 1-1-2025
- **Uitvoeringsbeschikking omzetbelasting 1968, artikel 31a** — uitvoeringsregels KOR incl. drempelberekening en uitgesloten prestaties
- **Uitvoeringsbeschikking omzetbelasting 1968, artikel 13** — herzieningsregels voor voorbelasting bij regimewijziging
- **Wet op de omzetbelasting 1968, artikel 35a lid 1 onder n** — verplichte vermelding op factuur bij vrijstelling
- **Belastingdienst KOR-portal** — mijnbelastingdienst.nl/zakelijk, sectie "Kleine Ondernemersregeling"
- **Belastingdienst KOR-EU portal** — separate aanmeldroute met EX-nummer toekenning sinds 1-1-2025
- **Besluit van de Staatssecretaris van Financiën 17 december 2019, nr. 2019-21260** — toelichting nieuwe KOR per 2020
- **Besluit van de Staatssecretaris van Financiën 22 november 2024** — toelichting KOR-EU implementatie
- **Handboek Ondernemen Belastingdienst, hoofdstuk btw, sectie KOR** — operationele leidraad voor ondernemers

## Cross-app integration

**bookkeeping-vat-btw-filing** — De reguliere btw-aangifte (kwartaal of maand) wordt opgeschort zodra een ACTIEF KORRegistration bestaat voor de periode. De filing-app moet luisteren naar `kor.registration.activated` events en de kwartaalaangiftes voor de KOR-periode markeren als "niet van toepassing". Bij revocatie via `kor.registration.revoked` herstart het reguliere ritme vanaf de eerstvolgende kwartaalgrens — met een tussentijdse suppletie voor de gedeeltelijke periode tussen revocatiedatum en kwartaaleinde.

**bookkeeping-accounts-receivable-core** — De factuur-renderer moet de KOR-variant ondersteunen: geen btw-kolom, geen btw-totaal, met de verplichte vermeldingsregel onderaan de factuur. PDF-templates moeten conditioneel het btw-blok wegvallen. Credit nota's onder KOR werken hetzelfde — geen btw-correctie, alleen omzetcorrectie. De receivables-app moet ook KOR-omzet correct doorgeven aan de KORAnnualTurnover entiteit voor drempelmonitoring.

**bookkeeping-zzp-tax-regime** — KOR werkt samen met de zelfstandigenaftrek en startersaftrek voor inkomstenbelasting. De ZZP-regime app moet KOR-status meenemen in de fiscale jaaroverzichten en in de pre-vulling van de IB-aangifte. Let op: KOR-vrijgestelde omzet is wél omzet voor IB-doeleinden — alleen de btw-component vervalt, niet de winst-belasting.

**bookkeeping-accounts-payable-core** — De inkoop-app moet bij ACTIEF KOR-regime de voorbelasting-velden uitschakelen of op nul forceren, en de bruto-bedragen als kosten boeken. Bij overgang naar regulier regime moet de payables-app retroactief herzieningsboekingen kunnen maken voor investeringsgoederen.

**bookkeeping-fiscal-eenheid** — Als de ondernemer onderdeel is van een fiscale eenheid btw, kan hij persoonlijk geen KOR aanvragen — de fiscale eenheid wordt als één belastingplichtige gezien. De fiscaal-eenheid app moet KOR-aanvragen blokkeren met passende uitleg.

**notifications** — De alert-app levert het kanaal voor 80/90/100% waarschuwingen (email + in-app + dashboard). KORThresholdAlert events publiceren naar `notifications.dispatch`.

## Target users

**ZZP'er met persoonlijke dienstverlening** — kapper, masseur, coach, fotograaf, schoonheidsspecialiste. Typisch jaaromzet EUR 12.000 - 18.000, weinig voorbelasting (alleen telefoon, software, soms apparatuur). KOR bespaart 4x per jaar de aangifte-stress en maakt facturen eenvoudiger voor particuliere klanten (geen btw-vraagstuk).

**Freelancer in creatieve sector** — illustrator, tekstschrijver, junior developer met paar opdrachten naast hoofdbaan. Jaaromzet vaak EUR 5.000 - 15.000. KOR ideaal want klanten zijn vaak particulier of buitenland; weinig business-inkopen.

**Dichtbij-thuis horeca** — kleine cateraar, ijs-aan-huis, hobby-bakker met online verkoop. Jaaromzet rond EUR 15.000 - 19.000. Aandachtspunt: snel groeien risico op overschrijding; drempelbewaking essentieel.

**Kleine webshop / Etsy-verkoper** — handgemaakte sieraden, vintage kleding, tweedehands boeken. Omzet zeer variabel; KOR-EU per 2025 belangrijk omdat veel kopers in Duitsland/België zitten. EX-nummer maakt cross-border verkoop administratief eenvoudiger dan OSS-regeling.

**Hobby-ondernemer met bijverdienste** — gepensioneerde die bijklust als klusjesman, leraar met avond-bijles, ouder met thuiscatering tussen schooluren. Omzet meestal ver onder drempel; KOR voorkomt dat fiscaliteit een belemmering wordt voor de bijverdienste.

**Branche-overstijgende ondernemer** — multidisciplinair zoals een yogadocent die ook merchandise verkoopt, of een coach die ook boeken uitgeeft. Edge case: alleen de btw-belaste tak telt voor de drempel; vrijgestelde prestaties (art. 11 OB) blijven los van KOR vrijgesteld. Het systeem moet dit correct uitsplitsen — een coach met EUR 30.000 lessen-omzet (vrijgesteld als onderwijs) en EUR 12.000 boekenverkoop (belast) kan WEL KOR krijgen voor de boeken.

**Edge case: fiscale eenheid btw** — Wanneer twee BV's of een eenmanszaak met BV in een fiscale eenheid zitten, geldt de fiscale eenheid als één belastingplichtige. De individuele rechtspersoon kán geen KOR krijgen — alleen de fiscale eenheid als geheel, mits deze onder EUR 20.000 blijft (zeldzaam). Het systeem moet dit detecteren en de aanvraagstroom blokkeren met passende uitleg en doorverwijzing naar de fiscaal adviseur.

**Edge case: mixed-use met vrijgestelde + KOR + reguliere prestaties** — Bijvoorbeeld een huisarts (vrijgesteld o.g.v. art. 11-1-g) die ook een kleine medische webshop heeft. De webshop kan KOR krijgen als zijn omzet onder EUR 20.000 blijft, terwijl de praktijk los van btw-systematiek opereert. Pro-rata voorbelasting blijft complex en vereist zorgvuldige boekhoudkundige scheiding tussen de drie kanalen (vrijgesteld, KOR, regulier).

**Edge case: branche-overstijgende administratie binnen één onderneming** — Een eenmanszaak kan meerdere activiteiten combineren onder één KvK-nummer. Voor KOR-doeleinden geldt de totale omzet van alle activiteiten samen — het is niet mogelijk om alleen voor één tak KOR aan te vragen. Het systeem moet voor multi-activiteit ondernemingen een geaggregeerde drempelweergave bieden met onderverdeling per activiteit, zodat de ondernemer ziet welke tak welk aandeel in de drempelbenutting heeft. Dit voorkomt verrassingen waar bijvoorbeeld een goed lopende secundaire activiteit de KOR-status voor de hoofdactiviteit in gevaar brengt.

**Edge case: start halverwege jaar (pro-rata drempel)** — De KOR-drempel van EUR 20.000 is een jaardrempel — die wordt NIET pro-rata herrekend voor ondernemers die halverwege het jaar starten of KOR aanmelden. Een ondernemer die per 1 juli 2026 start onder KOR mag tussen 1-7 en 31-12 maximaal EUR 20.000 maken — niet EUR 10.000. Dit is een veelvoorkomende misvatting; het systeem moet bij aanmeldingen die niet per 1-1 ingaan expliciet uitleggen dat de volle EUR 20.000 geldt voor de resterende periode.

**Edge case: faillissement of staking tijdens lock-in** — Bij beëindiging van de onderneming (uitschrijving KvK, faillissement, overlijden) wordt de KOR automatisch beëindigd zonder de drie-jaars-lock-in te schenden. Het systeem moet via integratie met `bookkeeping-onderneming-lifecycle` events afvangen en de KORRegistration sluiten zonder blokkade op heraanmelding (omdat heraanmelding van een nieuwe onderneming juridisch een nieuwe entiteit betreft).

**Edge case: KOR-EU en btw-OSS overlap** — Een ondernemer kan niet tegelijk KOR-EU (vrijstelling) én Union OSS (afdracht via NL) voor dezelfde EU-lidstaat gebruiken. Het systeem moet bij KOR-EU aanmelding checken of er actieve OSS-registraties zijn en deze conflicteren; per lidstaat kiest de ondernemer óf KOR-EU vrijstelling óf OSS-afdracht. Voor lidstaten waar KOR-EU niet wordt aangevraagd, blijft de reguliere btw-systematiek (eventueel via OSS) gelden.

**Edge case: ondernemers die net buiten de KOR vallen** — Een ondernemer met EUR 21.000 - 25.000 omzet zit in een ongunstige zone: hij is te groot voor KOR maar te klein om alle administratieve last efficiënt te dragen. Het systeem moet in de adviesmodule deze drempelzone signaleren en alternatieven schetsen (bewust factureren onder grens, samenwerking met collega in plaats van uitbreiding, etc.) — zonder fiscaal advies te geven, maar wel om de informatieasymmetrie te verkleinen.

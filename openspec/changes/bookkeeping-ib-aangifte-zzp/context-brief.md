---
status: draft
---

# IB Aangifte Assembly for ZZP

## Purpose

De jaarlijkse aangifte inkomstenbelasting (IB) is voor IB-ondernemers — vrijwel uitsluitend ZZP'ers, eenmanszaken, maten in een maatschap en vennoten in een VOF — het fiscale eindstation van het boekjaar. Anders dan vennootschapsbelasting bij rechtspersonen, wordt de winst uit onderneming bij IB-ondernemers via box 1 belast en moet alles uit het boekhouddossier (winst, balans, urencriterium, investeringen, woonwerk-kilometers, premies AOV/lijfrente, heffingskortingen) zorgvuldig samengebracht worden in één integraal P-formulier dat via SBR/XBRL bij de Belastingdienst wordt aangeleverd. Deze spec beschrijft hoe shillinq de volledige assemblage en indiening van de IB-aangifte voor ZZP'ers automatiseert: van data-aggregatie en validatie tot SBR-genereren, voorbereiding van de aangifte door een fiscalist (Becon-route) of directe self-service-indiening via de DigiD-route.

Het doel is dat een ZZP'er die zijn boekhouding in shillinq voert, in maart of april (na afsluiting van het boekjaar) op één plek (1) een complete pre-fill van het P-formulier krijgt, (2) een set fiscale optimalisatie-suggesties ziet (zelfstandigenaftrek, MKB-winstvrijstelling, startersaftrek, FOR-dotatie/oudedagsreserve afbouw, investeringsaftrek-keuzes, lijfrente-jaarruimte/reserveringsruimte), (3) een geldige XBRL-instance kan downloaden of via SBR direct kan inschieten richting de Digipoort van de Belastingdienst, (4) een audit-trail bewaart waarin elke regel van de aangifte herleidbaar is naar onderliggende boekingen, en (5) een conceptaangifte kan delen met zijn boekhouder of belastingadviseur via de Becon-rol.

De spec dekt de standaard IB-stromen voor ondernemers (Winst uit onderneming, box 1 werk en woning, box 3 sparen en beleggen voor zover aan de onderneming gerelateerd) en de essentiële aftrekposten en faciliteiten waar ZZP'ers recht op hebben mits ze het urencriterium halen: zelfstandigenaftrek (in 2026 EUR 2.470, conform afbouwpad uit het Belastingplan 2023), startersaftrek (driemaal EUR 2.123 in de eerste vijf jaar), MKB-winstvrijstelling (13,31 procent in 2026), kleinschaligheidsinvesteringsaftrek (KIA, energie-investeringsaftrek (EIA), milieu-investeringsaftrek (MIA), oudedagsreserve (FOR — afbouw, dotatie sinds 2023 niet meer toegestaan, alleen afbouw resterend saldo), AOV-premies, lijfrentepremies binnen jaarruimte/reserveringsruimte, en alle reguliere heffingskortingen (algemene heffingskorting, arbeidskorting, inkomensafhankelijke combinatiekorting).

Deze IB-assemblage hangt direct af van drie onderliggende specs: `zzp-urencriterium-tracker` (voor 1225-uren-validatie, zonder welk geen zelfstandigenaftrek), `bookkeeping-investeringsaftrek` (voor KIA/EIA/MIA-berekening) en het algemene GL-resultaat (winst-en-verliesrekening). Het output-formaat is de XBRL-instance volgens de Nederlandse Taxonomie (NT versie 17 voor 2026-aangiften), conform de SBR-standaard zoals beheerd door SBR Nederland (logius/MinFin).

Daarnaast ondersteunt de spec de bijzondere aangifte-routes: M-formulier (migratie in/uit Nederland gedurende belastingjaar), W-formulier (winst-uit-onderneming in combinatie met loondienst, voor de gevallen waar de Belastingdienst expliciet om W vraagt), en de overgang naar het C-formulier wanneer een ZZP'er emigreert naar het buitenland maar nog Nederlandse winst-bron heeft. Het systeem moet kunnen herkennen op basis van profiel-attributen welk formulier-type van toepassing is en de juiste taxonomie-rubriek-set activeren. De keuze-flow tussen P en W is in de praktijk vaak onduidelijk voor ZZP'ers die part-time loondienst combineren met ondernemen; shillinq biedt hier een beslisboom op basis van inkomstenverdeling en bron-balansen.

Een ander belangrijk onderdeel is de samenhang met de **eHerkenning**-route voor zakelijke aangifte: sinds 2021 vereist de Belastingdienst voor sommige aangifte-routes eHerkenning-niveau-3 of hoger. Voor de IB-aangifte door een IB-ondernemer zelf is DigiD nog steeds primair, maar bij Becon-route via fiscalist is eHerkenning vereist. Shillinq moet beide flows ondersteunen en bij ontbrekend eHerkenning-certificaat tijdig waarschuwen (eHerkenning-aanvraag duurt 2-3 weken bij geaccrediteerde leveranciers zoals Reconi, Digidentity, KPN, Z-login of We-ID).

De spec moet tevens de optimalisatie-suggestie-engine bevatten: een rule-based + LLM-augmented adviseur die op basis van geaggregeerde balans- en winstcijfers proactief suggesties doet — "ondernemer heeft EUR 8.200 lijfrente-jaarruimte 2025, een storting voor 31 december levert geschat EUR 3.034 belastingbesparing in 2025 en wordt fiscaal aftrekbaar"; of "FOR-saldo van EUR 14.300 kan vrijval in 2026 of 2027 met optimaler tarief". Deze suggestion-engine fungeert als digitale belastingadviseur voor ZZP'ers die geen fiscalist hebben.

## Data Model

### IBAangifte

Hoofd-entiteit: één aangifte per IB-ondernemer per belastingjaar. Bevat status (concept/gevalideerd/ingediend/verwerkt), referenties naar onderliggende bron-entiteiten, en de berekende eindcijfers per rubriek van het P-formulier.

```json
{
  "id": "ib-aangifte-2025-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "bsn": "123456789",
  "belastingjaar": 2025,
  "status": "GEVALIDEERD",
  "indieningKanaal": "SBR_DIGIPOORT_BECON",
  "fiscalistBeconNummer": "B12345",
  "aangifteType": "P_FORMULIER",
  "fiscalePartner": {
    "bsn": "987654321",
    "verdeelsleutel": "OPTIMAAL_BEREKEND"
  },
  "winstUitOnderneming": 47820.00,
  "ondernemersaftrek": 5616.00,
  "mkbWinstvrijstelling": 5618.05,
  "belastbareWinst": 36585.95,
  "totaalBox1Inkomen": 36585.95,
  "totaalBox3Inkomen": 1820.00,
  "verschuldigdeIB": 9420.18,
  "heffingskortingen": 3950.40,
  "teBetalenOfTeOntvangen": 5469.78,
  "ingediendOp": null,
  "xbrlInstanceId": "xbrl-2025-ond-001234-v3",
  "auditTrailId": "audit-ib-2025-ond-001234"
}
```

### IBWinstOpgave

Subset met alle winst-uit-onderneming-componenten. Dit is de fiscale W&V die afwijkt van de commerciële W&V — denk aan afschrijvingsbeperking goodwill, niet-aftrekbare boetes/representatiekosten boven drempel, bijtelling auto, autokostenforfait, fooien.

```json
{
  "id": "ib-winst-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "omzetExclusiefBtw": 82400.00,
  "kostprijsOmzet": 12300.00,
  "brutoWinst": 70100.00,
  "afschrijvingen": 4800.00,
  "personeelskosten": 0,
  "huisvestingskosten": 6200.00,
  "autokosten": {
    "totaal": 8400.00,
    "bijtellingPrive": 3960.00,
    "aftrekbaar": 4440.00
  },
  "verkoopkosten": 1820.00,
  "kantoorkosten": 1100.00,
  "algemeneKosten": 2820.00,
  "nietAftrekbaarBoetes": 180.00,
  "representatieCorrectie": 320.00,
  "winstVoorOndernemersaftrek": 47820.00,
  "fiscaleAfwijkingenLog": [
    {"post": "REPRESENTATIE_DREMPEL", "bedrag": 320.00, "grondslag": "art. 3.15 Wet IB 2001"}
  ]
}
```

### IBOndernemersaftrek

Verzamelt alle ondernemersfaciliteiten: zelfstandigenaftrek (afhankelijk van urencriterium), startersaftrek (driemaal in eerste vijf jaar), aftrek voor S&O-werk (WBSO-koppeling), meewerkaftrek (partner), stakingsaftrek (bij beëindiging).

```json
{
  "id": "ib-onda-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "urencriterium": {
    "behaald": true,
    "uren": 1462,
    "drempel": 1225,
    "evidenceRef": "uren-tracker-2025-ond-001234"
  },
  "zelfstandigenaftrek": {
    "toegestaan": true,
    "bedrag": 3750.00,
    "grondslag": "art. 3.76 Wet IB 2001, tarief 2025"
  },
  "startersaftrek": {
    "toegestaan": true,
    "jaarVanGebruik": 2,
    "maxKeer": 3,
    "bedrag": 2123.00
  },
  "soAftrek": {
    "toegestaan": false,
    "wbsoBeschikking": null
  },
  "meewerkaftrek": {
    "toegestaan": false
  },
  "totaalAftrek": 5873.00
}
```

### IBHeffingskortingenAlgemeen

Heffingskortingen worden tijdens de aanslag verrekend; relevant voor pre-fill en correcte teruggave-berekening zijn de algemene heffingskorting (afbouw vanaf inkomen EUR 28.406), arbeidskorting (afbouw bij hoog inkomen), inkomensafhankelijke combinatiekorting (IACK — uitsluitend voor ouders met kind <12 + werkende partner), jonggehandicaptenkorting.

```json
{
  "id": "ib-heff-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "algemeneHeffingskorting": 2890.00,
  "arbeidskorting": 1060.40,
  "iack": 0,
  "ouderenkorting": 0,
  "alleenstaandeOuderenkorting": 0,
  "jonggehandicaptenkorting": 0,
  "totaalHeffingskortingen": 3950.40,
  "berekeningsbasis": {
    "inkomenBox1": 36585.95,
    "arbeidsinkomen": 36585.95,
    "ahkAfbouwToegepast": 0
  }
}
```

### IBLijfrenteAOV

Aftrekbare premies pensioen-equivalent: lijfrenteverzekering binnen jaarruimte en reserveringsruimte (art. 3.127 IB), arbeidsongeschiktheidsverzekering-premies (volledig aftrekbaar als ondernemer).

```json
{
  "id": "ib-lijfrente-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "jaarruimte2025": {
    "berekend": 6280.00,
    "benut": 4800.00,
    "resterend": 1480.00
  },
  "reserveringsruimte2025": {
    "berekend": 9200.00,
    "benut": 0,
    "resterend": 9200.00
  },
  "aovPremies": {
    "bedrag": 2400.00,
    "polisnummer": "AOV-MOVIR-789123",
    "verzekeraar": "Movir N.V."
  },
  "totaalAftrekbaar": 7200.00
}
```

### IBBijtellingAuto

Per auto van de zaak een berekening van de bijtelling, gekoppeld aan de cataloguswaarde, brandstoftype, datum eerste registratie, en eventuele eigen bijdrage.

```json
{
  "id": "ib-bijtelling-auto-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "kenteken": "12-ABC-3",
  "cataloguswaardeNieuw": 38000.00,
  "datumEersteRegistratie": "2024-04-15",
  "bijtellingsCategorie": "REGULIER_22PCT",
  "bijtellingsPct": 0.22,
  "bijtellingBedrag": 8360.00,
  "eigenBijdrage": 0,
  "nettoBijtelling": 8360.00,
  "kilometerAdministratie": {
    "aanwezig": false,
    "privéKilometers": null,
    "regelingTotZakelijk": "FORFAIT_BIJTELLING"
  },
  "grondslag": "art. 3.20 Wet IB 2001"
}
```

### IBBox3Vermogen

Vermogen-positie per 1 januari belastingjaar voor box-3-berekening, opgesplitst in drie categorieën conform overbruggingswet.

```json
{
  "id": "ib-box3-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "peildatum": "2025-01-01",
  "bankEnSpaartegoeden": 28000.00,
  "overigeBezittingen": 41000.00,
  "schulden": 0,
  "totaalRendementsgrondslag": 69000.00,
  "heffingvrijVermogen2025": 57000.00,
  "belastbareGrondslag": 12000.00,
  "berekendRendement": {
    "methode": "FORFAIT_OVERBRUGGINGSWET",
    "rendement": 1820.00,
    "verschuldigdeIB_36pct": 655.20
  },
  "werkelijkRendementOpgevoerd": false
}
```

### IBAuditTrail

Per aangifte een complete herleidbaarheid: elke rubriek-waarde linkt naar onderliggende journaalpost-id's of brontabellen, met timestamp, gebruiker, versie. Conform bewaarplicht art. 52 AWR (7 jaar).

```json
{
  "id": "audit-ib-2025-ond-001234",
  "aangifteId": "ib-aangifte-2025-ond-001234",
  "regels": [
    {
      "rubriek": "omzet_excl_btw",
      "waarde": 82400.00,
      "bron": "GL_ACCOUNT_8000-8099",
      "journaalposten": ["jp-2025-01-001", "jp-2025-01-002", "..."],
      "berekendOp": "2026-03-15T10:24:18Z",
      "berekendDoor": "system"
    }
  ],
  "totalRegels": 247,
  "freezeMoment": "2026-03-15T10:24:18Z",
  "gefreezdDoor": "user-zzp-ond-001234"
}
```

## Requirements

### Requirement: REQ-IB-001 Aangifte-aggregatie uit GL

Het systeem moet voor elke IB-ondernemer een complete pre-fill van het P-formulier samenstellen door alle relevante grootboek-rekeningen automatisch te mappen op de XBRL-rubrieken van de Nederlandse Taxonomie (NT17, belastingjaar 2025+).

#### Scenario: Volledige pre-fill bij afsluiten boekjaar

- GIVEN een ZZP'er heeft het boekjaar 2025 afgesloten in shillinq
- WHEN de gebruiker "IB-aangifte starten 2025" kiest
- THEN moet het systeem binnen 10 seconden een concept-IBAangifte produceren met winst, balans, urencriterium-bewijs, investeringsaftrek-berekening, en heffingskortingen ingevuld
- AND moet elke ingevulde waarde een audittrail-regel hebben die verwijst naar de onderliggende journaalposten

#### Scenario: Afwijking commercieel-fiscaal automatisch geboekt

- GIVEN een ondernemer heeft EUR 4.200 representatiekosten geboekt
- WHEN de IB-pre-fill draait
- THEN moet het systeem de aftrekbeperking representatie (art. 3.15 Wet IB) toepassen en de correctie als "fiscaleAfwijking" loggen
- AND moet de IBWinstOpgave het netto-aftrekbare bedrag tonen

### Requirement: REQ-IB-002 Zelfstandigenaftrek validatie tegen urencriterium

Het systeem mag zelfstandigenaftrek uitsluitend toekennen indien het urencriterium (≥1225 uren in onderneming) bewezen is via de `zzp-urencriterium-tracker`. Bij ontbrekend bewijs moet de aftrek geblokkeerd worden met een blokkerende waarschuwing en directe link naar de urentracker.

#### Scenario: Aftrek toegekend bij behaald urencriterium

- GIVEN de urentracker rapporteert 1462 uren ondernemingsuren in 2025
- WHEN het systeem de ondernemersaftrek berekent
- THEN moet zelfstandigenaftrek 2025 (EUR 3.750) worden toegekend
- AND moet evidenceRef verwijzen naar het urentracker-rapport

#### Scenario: Aftrek geblokkeerd bij onvoldoende uren

- GIVEN de urentracker rapporteert 1180 uren in 2025
- WHEN het systeem ondernemersaftrek berekent
- THEN moet zelfstandigenaftrek geblokkeerd worden met "URENCRITERIUM_NIET_BEHAALD"
- AND moet de gebruiker een waarschuwing zien met "EUR 3.750 aftrek niet toegestaan — uren tekort 45"

### Requirement: REQ-IB-003 MKB-winstvrijstelling automatische toepassing

Na ondernemersaftrek moet het systeem de MKB-winstvrijstelling (2026: 12,7 procent; 2025: 12,7 procent na verlaging in Belastingplan 2024) automatisch berekenen over de winst na ondernemersaftrek, en de uitkomst tonen als aparte regel.

#### Scenario: MKB-vrijstelling correct toegepast

- GIVEN winst na ondernemersaftrek is EUR 41.947
- WHEN MKB-winstvrijstelling wordt berekend
- THEN moet de vrijstelling EUR 5.327,27 zijn (12,7 procent)
- AND moet belastbare winst EUR 36.619,73 zijn

#### Scenario: MKB-vrijstelling niet bij verlies

- GIVEN winst na ondernemersaftrek is negatief (EUR -3.200)
- WHEN MKB-winstvrijstelling wordt berekend
- THEN mag geen vrijstelling worden toegepast (vrijstelling is geen aftrekpost bij verlies — keuze ondernemer)
- AND moet het systeem optioneel kunnen aangeven "MKB-vrijstelling overgeslagen wegens verlies (carry-forward verlies blijft EUR 3.200)"

### Requirement: REQ-IB-004 Investeringsaftrek-koppeling

De spec moet automatisch KIA, EIA, MIA en VAMIL-bedragen uit `bookkeeping-investeringsaftrek` opnemen in de aangifte, met de juiste rubriek-mapping en evidence-links naar de onderliggende investeringsbeslissingen.

#### Scenario: KIA opgenomen uit investeringsaftrek-spec

- GIVEN het systeem heeft KIA-recht EUR 2.490 berekend voor 2025
- WHEN IB-pre-fill draait
- THEN moet KIA als ondernemersfaciliteit verschijnen in IBOndernemersaftrek
- AND moet de KIA-staffel-berekening per investering linkbaar zijn voor de fiscalist

### Requirement: REQ-IB-005 Lijfrente jaarruimte en reserveringsruimte

Het systeem moet automatisch jaarruimte (art. 3.127 lid 1 IB) en reserveringsruimte (lid 4) berekenen op basis van winst voorgaand jaar, AOW-leeftijd, pensioenaangroei (voor ZZP altijd 0), en de wettelijke maxima/franchises.

#### Scenario: Jaarruimte 2026 correct berekend

- GIVEN winst 2025 was EUR 41.947 (na MKB-vrijstelling EUR 36.619)
- AND ondernemer is 47 jaar
- WHEN jaarruimte 2026 wordt berekend
- THEN moet jaarruimte = 13,3 procent * (premiegrondslag − franchise 2026) zijn
- AND moet de premiegrondslag EUR 36.619 − EUR 17.546 (franchise 2026) gebruiken

#### Scenario: Reserveringsruimte uit voorgaande 10 jaar

- GIVEN de ondernemer heeft 2017-2024 niet alle jaarruimte benut
- WHEN reserveringsruimte 2026 wordt berekend
- THEN moet het systeem de cumulatieve niet-benutte ruimte tot max EUR 9.200 (2026) opvoeren

### Requirement: REQ-IB-006 Heffingskortingen pre-fill

Algemene heffingskorting (met afbouw), arbeidskorting (afbouw vanaf EUR 43.071 in 2026), en IACK (alleen indien kind <12 + werkende partner) moeten automatisch berekend worden op basis van box-1-inkomen en gezinssituatie uit het profiel.

#### Scenario: Algemene heffingskorting met afbouw

- GIVEN box-1-inkomen EUR 56.000 in 2026
- WHEN AHK wordt berekend
- THEN moet het systeem de afbouw toepassen: AHK = max EUR 3.362 − 6,337 procent * (inkomen − EUR 28.406)
- AND moet de uitkomst circa EUR 1.612 zijn

#### Scenario: IACK voor alleenstaande ouder

- GIVEN ondernemer is alleenstaande ouder met kind van 6 jaar
- WHEN IACK wordt berekend
- THEN moet IACK worden toegekend volgens 2026-tabel (max EUR 2.986)

### Requirement: REQ-IB-007 SBR/XBRL instance generatie

Het systeem moet een geldige XBRL-instance produceren volgens de Nederlandse Taxonomie versie 17 (NT17) voor belastingjaar 2025+, inclusief alle verplichte rubrieken (vrij vanaf NT17.1) en geldig voor Digipoort-aanlevering.

#### Scenario: XBRL valideert tegen NT17

- GIVEN een gevalideerde IBAangifte 2025
- WHEN "Genereer XBRL-instance" wordt geklikt
- THEN moet het systeem een XBRL-instance produceren die slaagt voor de NT17-validatie
- AND moet alle rubrieken die in NT17 verplicht zijn voor het P-formulier ingevuld zijn

#### Scenario: XBRL-validatie faalt bij ontbrekende rubriek

- GIVEN een aangifte mist het BSN-fiscaal-partner-veld terwijl er wel een fiscale partner is opgegeven
- WHEN XBRL-validatie draait
- THEN moet het systeem de validatiefout tonen met de exacte XBRL-rubriek-naam
- AND moet de "Indienen"-knop geblokkeerd zijn

### Requirement: REQ-IB-008 Becon-route indiening door fiscalist

Het systeem moet de Becon-route (Beconnummer = fiscaal intermediair-nummer) ondersteunen waarin een geregistreerde fiscalist namens de ondernemer indient via Digipoort met PKIoverheid-certificaat.

#### Scenario: Fiscalist ondertekent en dient in

- GIVEN een fiscalist met Beconnummer B12345 is gekoppeld aan ondernemer
- AND aangifte is gevalideerd
- WHEN fiscalist "Indienen via Digipoort" kiest
- THEN moet het systeem de XBRL signeren met het PKIoverheid-services-certificaat van de fiscalist
- AND moet status worden "INGEDIEND" met Digipoort-ontvangstbevestiging-ID

### Requirement: REQ-IB-009 Audit trail en herleidbaarheid

Elke rubriek van de aangifte moet herleidbaar zijn naar onderliggende boekingen of brontabellen, en de aangifte moet bij indiening worden "gefreezed" zodat post-hoc wijzigingen onmogelijk worden zonder een formele correctieaangifte.

#### Scenario: Drill-down van rubriek naar journaalposten

- GIVEN een ingediende aangifte
- WHEN de gebruiker klikt op rubriek "omzet excl btw EUR 82.400"
- THEN moet een lijst van alle onderliggende journaalposten verschijnen met datum, klant, bedrag

#### Scenario: Freeze na indiening

- GIVEN status is "INGEDIEND"
- WHEN gebruiker probeert een grootboekrekening die in de aangifte zit te wijzigen
- THEN moet het systeem waarschuwen "Wijziging vereist correctieaangifte (suppletie)" en de optie aanbieden om een nieuwe aangifte-versie te starten

### Requirement: REQ-IB-010 Fiscale partner verdeling

Voor gehuwde/geregistreerde IB-ondernemers moet het systeem de optimale verdeling van aftrekposten over de partners berekenen (hypotheekrente, persoonsgebonden aftrek, box-3).

#### Scenario: Optimale verdeling berekening

- GIVEN ondernemer en partner hebben samen EUR 8.200 hypotheekrente-aftrek
- AND beide hebben verschillend inkomen-tarief
- WHEN "Optimaal verdelen" wordt gekozen
- THEN moet het systeem voorstellen om 100 procent van de aftrek bij de hoogste-tariefpartner te plaatsen
- AND moet de gerealiseerde belastingbesparing tonen

### Requirement: REQ-IB-011 Voorlopige aanslag actualisatie

Het systeem moet de voorlopige aanslag (VA) bij grote afwijking van het actuele winstniveau automatisch signaleren en een VA-wijzigingsverzoek voorbereiden voor Digipoort.

#### Scenario: VA te laag bij winstgroei

- GIVEN voorlopige aanslag 2026 is gebaseerd op winst EUR 30.000
- AND het systeem voorspelt op 30 juni 2026 dat winst EUR 55.000 wordt
- WHEN VA-monitor draait
- THEN moet een suggestie "VA verhogen" verschijnen met geschatte bijbetaling om belastingrente (art. 30hb AWR) te voorkomen

### Requirement: REQ-IB-012 Correctieaangifte en suppletie

Indien na indiening een onjuistheid wordt ontdekt, moet het systeem een correctieaangifte (volledige nieuwe aangifte met markering "correctie") kunnen genereren met diff-overzicht tegenover de oorspronkelijke aangifte.

#### Scenario: Vergeten lijfrentepremie gecorrigeerd

- GIVEN aangifte 2025 is ingediend op 14 maart 2026
- AND op 22 maart blijkt dat een lijfrentepremie van EUR 2.400 vergeten is
- WHEN gebruiker "Correctieaangifte starten" kiest
- THEN moet het systeem een nieuwe aangifte produceren met dezelfde data en de correctie
- AND moet een diff-rapport tonen: "Aftrek + EUR 2.400, te ontvangen + EUR 882"

### Requirement: REQ-IB-013 Bijtelling auto van de zaak

Het systeem moet de fiscale bijtelling voor privégebruik auto van de zaak correct berekenen op basis van cataloguswaarde, bijtellingspercentage (22 procent regulier, 16 procent voor zero-emission tot tariefstaffel), en eventuele eigen bijdrage van de ondernemer.

#### Scenario: Reguliere auto met 22 procent bijtelling

- GIVEN ondernemer rijdt zakelijke auto met cataloguswaarde EUR 38.000
- AND geen sluitende kilometeradministratie voor <500 km privé
- WHEN bijtelling 2026 berekend wordt
- THEN moet bijtelling EUR 8.360 (22 procent × 38.000) zijn
- AND moet die bij winst worden opgeteld als onttrekking

#### Scenario: EV met staffel-bijtelling

- GIVEN zero-emission auto cataloguswaarde EUR 52.000 (in 2026: 17 procent over eerste EUR 30.000, 22 procent boven)
- WHEN bijtelling berekend
- THEN moet bijtelling (17 procent × 30.000) + (22 procent × 22.000) = EUR 9.940 zijn

### Requirement: REQ-IB-014 Werkruimte-aftrek thuiswerkende ZZP

Het systeem moet de strikte regels voor werkruimte-aftrek (art. 3.16 lid 2 IB) handhaven: kwalificatie als zelfstandige werkruimte (eigen ingang, eigen sanitair) + 65 procent inkomenscriterium.

#### Scenario: Werkruimte voldoet niet aan kwalificatie

- GIVEN ondernemer wil EUR 1.800 werkruimte-aftrek claimen voor kantoor in woonkamer
- WHEN kwalificatie-check loopt
- THEN moet aftrek worden geweigerd met grondslag "geen zelfstandige werkruimte conform art. 3.16 IB"
- AND moet de gebruiker worden gewezen op de alternatieve route (huurwoning-componenten via art. 3.17)

### Requirement: REQ-IB-015 Box 3 sparen en beleggen voor ondernemers

Het systeem moet box-3-vermogen automatisch overnemen uit de balans (privé-onttrokken sparen, beleggingen, tweede woning) en de fictief-rendement-berekening 2026 toepassen volgens overbruggingswet (drie categorieën: bank/spaartegoeden, overige bezittingen, schulden).

#### Scenario: Box 3 met spaargeld en beleggingen

- GIVEN ondernemer heeft per 1 januari 2026 EUR 28.000 spaargeld + EUR 41.000 beleggingen
- AND heffingvrij vermogen 2026 is EUR 57.684
- WHEN box-3 berekend wordt
- THEN moet het systeem de overbruggingswet-methodiek toepassen
- AND moet rendement worden berekend tegen werkelijk-rendement of forfait (de gunstigste)

## Standards & Sources

- Wet inkomstenbelasting 2001 (Wet IB), in het bijzonder hoofdstuk 3 (Winst uit onderneming): art. 3.6 (urencriterium), 3.15 (representatiebeperking), 3.16 (werkruimte-aftrek), 3.17 (woning-componenten), 3.20 (bijtelling auto), 3.76 (zelfstandigenaftrek), 3.78 (startersaftrek), 3.79a (MKB-winstvrijstelling), 3.127 (lijfrenteruimte)
- Wet IB 2001 hoofdstuk 5 (Box 3) en overbruggingswet box 3 (Wet rechtsherstel box 3 / Overbruggingswet)
- Algemene wet inzake rijksbelastingen (AWR), art. 52 (bewaarplicht 7 jaar), art. 30hb (belastingrente), art. 67c (vergrijpboete)
- Algemene wet bestuursrecht (Awb) — termijnen bezwaar/beroep aangifte
- Belastingplan 2024 en 2025 (afbouw zelfstandigenaftrek tot EUR 1.200 in 2027, MKB-winstvrijstelling-verlaging van 14% naar 12,03% per stappen)
- Belastingplan 2026 (verwachte verdere aanpassingen)
- Handboek Ondernemen 2026 (Belastingdienst, jaarlijks geactualiseerd, ISBN-equivalent in digitale uitgave)
- SBR Nederland: Nederlandse Taxonomie (NT) versie 17 voor belastingjaar 2025, NT18 in pre-release voor belastingjaar 2026
- Digipoort technische specificaties (Logius): koppelvlakspecificaties FRC/AGV en certificaateisen PKIoverheid Services-certificaat
- PKIoverheid Services-certificaat voor Becon-aanlevering (Logius CA-hierarchie)
- eHerkenning niveau 3+ voor zakelijke aangifte routes
- Register Belastingadviseurs (RB) en Nederlandse Orde van Belastingadviseurs (NOB) praktijkadviezen 2025/2026
- KOR-koppeling: Richtlijn (EU) 2020/285 (BTW-KOR-EU)
- Webmodule Beoordeling Arbeidsrelatie (WBA) voor DBA-koppeling (zie `dba-compliance-marker`)

## Cross-app integration

- `zzp-urencriterium-tracker` — verplichte input voor zelfstandigenaftrek-toets en startersaftrek
- `bookkeeping-investeringsaftrek` — input voor KIA/EIA/MIA-aftrek-bedragen
- `bookkeeping-kor-kleine-ondernemersregeling` — winst-mutaties bij KOR (geen voorbelasting-aftrek), KOR-status synchroon houden
- `bookkeeping-innovatiebox-administratie` — niet relevant voor IB-ondernemers (alleen Vpb), maar S&O-aftrek uit WBSO wel
- `bookkeeping-ap-ar` — bron voor omzet en kosten, debiteuren-restant voor balans
- `bookkeeping-fixed-assets` — bron voor afschrijvingen, boekwaarde en investeringen
- `bookkeeping-payroll-engine-nl` — bij DGA: salarisinkomsten uit eigen BV (loon-input voor IB box 1)
- `bookkeeping-credit-control-dunning` — afgeschreven oninbare vorderingen als bedrijfslast
- `zzp-cashflow-13wk` — voorlopige aanslag-projectie en optimalisatie timing
- `dba-compliance-marker` — risico-context voor opdracht-classificatie
- `openconnector` — outbound integratie met Digipoort SOAP webservice (LH-Becon)
- `hrmq` — partnergegevens en gezinssituatie voor heffingskortingen (waar overlap)
- `openregister` — file-storage voor XBRL-instance + audit-trail + freeze-snapshot, bewaarplicht 7 jaar
- `pipelinq` — communicatie met fiscalist (Becon-route)
- `hydra` — coördinatie met belastingadviseur via gemeenschappelijke workflows

## Target users

- **Primair: ZZP'ers en eenmanszaken** die hun aangifte zelf doen via DigiD (self-service). Deze groep heeft het meest belang bij de pre-fill, de optimalisatie-suggesties en de validatie tegen XBRL-vereisten, omdat zij vaak geen fiscalist consulteren en risico lopen op gemiste aftrek of fouten met naheffing.
- **Secondair: Fiscalist / boekhouder via Becon-rol** (intermediair-route met PKIoverheid-certificaat). Voor deze gebruikersgroep is de portfolio-aanpak van belang: een fiscalist beheert tientallen tot honderden cliënten en wil bulk-validatie en parallel werken op meerdere dossiers.
- **Tertiair: Maten in maatschap of vennoten in VOF** met aandeel in winst — vereist correcte winstverdeling per maat en gespiegelde aangifte tussen partners.
- **Bijzondere groep: ZZP'ers met combinatie loondienst + ondernemen** — vereisten extra zorg voor de keuze tussen P- en W-formulier en het grotendeels-criterium.
- **Bijzondere groep: Recent gestarte ZZP'ers** (eerste vijf jaar — startersaftrek-relevant)
- **Bijzondere groep: ZZP'ers met fiscaal partner** (verdeling aftrekposten over partners)
- **Niet binnen scope**: BV/NV (Vpb — eigen aangifte-spec), buitenlandse belastingplichtigen (C-formulier — alleen overgang ondersteund), particulieren zonder onderneming (puur box-1-loondienst — geen winstkomponent).

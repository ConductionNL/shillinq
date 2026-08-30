---
status: draft
---

# 1225-Uren Urencriterium Tracker

## Purpose

Het urencriterium is de fiscale poortwachter voor vrijwel elke ondernemersfaciliteit in box 1 voor IB-ondernemers: zonder bewijs dat de ondernemer minimaal 1.225 uren per kalenderjaar aan zijn onderneming heeft besteed (art. 3.6 Wet IB 2001), vervalt het recht op zelfstandigenaftrek (in 2026 EUR 2.470), startersaftrek (driemaal EUR 2.123), meewerkaftrek, en — niet onbelangrijk — de mogelijkheid om de oudedagsreserve af te bouwen tegen het hoogste tarief. Voor de gemiddelde ZZP'er met een winst rond EUR 45.000 betekent het missen van het urencriterium een directe belastingschade van EUR 1.500 tot EUR 2.500 per jaar, oplopend tot EUR 4.000+ in de eerste vijf jaar als ook de startersaftrek wegvalt.

In de praktijk leeft de 1.225-urennorm voor veel ZZP'ers buiten zicht totdat de Belastingdienst in een controle om bewijs vraagt — en dat bewijs is onder de huidige rechtspraak (Hoge Raad 2007 e.v., recente uitspraken Rechtbank Gelderland 2024) steeds strikter geworden. Een achteraf opgestelde Excel-staat is volgens de Belastingdienst geen acceptabel bewijs meer; de norm is een tijdgebonden en sluitende urenregistratie. Deze spec beschrijft hoe shillinq voor ZZP'ers het urencriterium operationaliseert tot een dagelijks rolling dashboard: automatische tally van billable uren uit `bookkeeping-time-tracking`, non-billable activiteiten (acquisitie, administratie, scholing, ICT-onderhoud, vakliteratuur), reistijd, mits aantoonbaar zakelijk, en aangrenzende activiteiten zoals netwerkbijeenkomsten en het bijhouden van vakkennis.

Het doel is dat een ZZP'er op elke werkdag (1) zijn cumulatieve uren ziet ten opzichte van de 1.225-drempel, (2) een prognose krijgt naar einde jaar gebaseerd op het werkpatroon van de afgelopen 12 weken, (3) bij dreigende onderschrijding een vroegtijdige alert ontvangt met concrete handelingsperspectieven (extra acquisitietijd, scholing inplannen, vakantieperiode heroverwegen), (4) zijn registratie kan koppelen aan WBSO-administratie voor S&O-uren die zowel meetellen voor urencriterium als voor de S&O-aftrek, en (5) bij IB-aangifte (`bookkeeping-ib-aangifte-zzp`) een audit-vast bewijsstuk produceert dat een Belastingdienstcontrole doorstaat.

De spec dekt expliciet de bijzondere situaties: het verlaagde urencriterium van 800 uren voor arbeidsongeschikten (art. 3.6 lid 5 IB), het deeltijdcriterium voor zwangerschap (er wordt geacht 16 weken doorgewerkt te zijn), de tussenstreepperiode bij starters in jaar 1 (urencriterium geldt per kalenderjaar, niet pro rata), en het deeltijdcriterium voor meewerkende partners (525 uren voor meewerkaftrek). Ook dekt de spec het — vaak onderbelichte — "grotendeels-criterium" (≥50 procent van totaal arbeidstijd aan onderneming) dat voor ondernemers die naast hun zaak in loondienst werken, een tweede horde vormt.

Vanuit de software-architectuur bouwt deze spec voort op `bookkeeping-time-tracking` (die individuele projecturen tracked), maar voegt daar de fiscale wegingsregels, categorisatie naar IB-categorieën, prognose-engine en de audit-trail aan toe.

De spec onderscheidt zich expliciet van time-tracking by-design: time-tracking richt zich op project-/klant-billable-uren voor facturatie, terwijl het urencriterium een fiscaal-juridisch geconstrueerde verzamelnoemer is die óók non-billable activiteiten omvat die voor een werknemer-equivalent gewoon onder "werkuren" zouden vallen (mailen aan een leverancier, factuur opstellen, jaarrekening voorbereiden, declaratie verwerken, ICT-onderhoud bijwerken — al deze uren tellen mee voor de 1.225-norm maar zijn niet billable). Het tracker-systeem moet daarom een breder universum aan activiteiten kunnen registreren dan time-tracking, met expliciete fiscale categorisatie die per categorie kan worden gemotiveerd richting een Belastingdienstcontroleur.

Ook nieuw in 2026 is de samenhang met de geactiveerde Wet DBA-handhaving (zie `dba-compliance-marker`): een ondernemer die in een DBA-controle terechtkomt wordt vaak gevraagd om de urenadministratie als evidence voor "eigen onderneming runnen"; de fiscale-uren-administratie wordt zo tweezijdig relevant: voor de zelfstandigenaftrek (Belastingdienst-IB-controle) én voor de DBA-toets (Belastingdienst-loonheffing-controle). Een sluitende registratie dekt beide hoeken.

## Data Model

### UrencriteriumYear

Hoofd-entiteit: één per ondernemer per kalenderjaar. Bevat doelnorm (1225 of 800), lopende stand, prognose, drempel-status en de bewijslast-classificatie.

```json
{
  "id": "urc-2026-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "kalenderjaar": 2026,
  "doelNorm": 1225,
  "normGrondslag": "art. 3.6 lid 1 Wet IB 2001 — standaard",
  "lopendeUren": 842.5,
  "prognoseEindeJaar": 1408.0,
  "prognoseConfidence": 0.84,
  "drempelStatus": "OP_KOERS",
  "grotendeelsCriterium": {
    "vanToepassing": false,
    "loondienstUren": 0,
    "ondernemerUren": 842.5,
    "percentage": 1.0
  },
  "berekendOp": "2026-05-21T08:00:00Z"
}
```

### UrenRegistratie

Per dag een set registratie-records, gecategoriseerd. Bron kan zijn: automatisch uit time-tracking (billable), handmatig (non-billable), agenda-import (reistijd/meetings).

```json
{
  "id": "urreg-2026-05-21-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "datum": "2026-05-21",
  "totaalUren": 8.5,
  "categorieen": {
    "BILLABLE_KLANTWERK": 5.5,
    "ACQUISITIE": 1.0,
    "ADMINISTRATIE": 0.5,
    "REISTIJD_ZAKELIJK": 1.0,
    "SCHOLING": 0.5
  },
  "bronnen": [
    {"type": "TIME_TRACKING", "id": "tt-2026-05-21-proj-x", "uren": 5.5},
    {"type": "AGENDA_IMPORT", "id": "cal-meeting-456", "uren": 1.0},
    {"type": "HANDMATIG", "id": "manual-21052026-1", "uren": 2.0}
  ],
  "registratieMoment": "2026-05-21T17:42:00Z",
  "verschilTussenWerkEnRegistratie": "SAMENDAG"
}
```

### UrenCategorie

Definitie-tabel van welke activiteiten meetellen, met fiscale grondslag-referentie en evt. cap.

```json
{
  "code": "REISTIJD_ZAKELIJK",
  "label": "Reistijd zakelijk (klantbezoek, ophalen materiaal)",
  "telTMee": true,
  "fiscaleBron": "HR 14 maart 2003, BNB 2003/258",
  "voorwaarden": ["aantoonbaar zakelijk doel", "bestemming geen vaste werkplek"],
  "maxPerDag": 4.0,
  "voorbeelden": ["rijden naar klant in Eindhoven", "ophalen materiaal bij leverancier"]
}
```

### UrenPrognose

Voorspelling per maand voor resterend jaar, op basis van rolling 12-weeks gemiddelde + seizoenscorrectie + ingeplande vakantie + bekende klantopdrachten.

```json
{
  "id": "prog-2026-05-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "berekendOp": "2026-05-21T08:00:00Z",
  "modelVersie": "v3.2-12wk-seasonal",
  "perMaandPrognose": {
    "2026-06": 145.0,
    "2026-07": 95.0,
    "2026-08": 75.0,
    "2026-09": 168.0,
    "2026-10": 168.0,
    "2026-11": 158.0,
    "2026-12": 105.0
  },
  "vakanties": [
    {"start": "2026-07-15", "eind": "2026-08-09", "uren": 0}
  ],
  "totaalPrognose": 1408.0,
  "kansBehaaldNorm": 0.91
}
```

### UrenAlert

Waarschuwingsevents — gegenereerd op vaste momenten (kwartaal-eind) en bij prognose-omslagen (van OP_KOERS naar RISICO).

```json
{
  "id": "alert-2026-09-30-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "type": "PROGNOSE_RISICO_DROP",
  "aanleidingDatum": "2026-09-30",
  "lopendeUren": 916.0,
  "norm": 1225,
  "prognoseEindeJaar": 1180.0,
  "tekort": 45.0,
  "urgentie": "HOOG",
  "handelingsperspectief": [
    "Plan 45 uur extra acquisitietijd in Q4",
    "Verminder geplande vakantieperiode rond Kerstmis (huidige planning: 12 dagen)",
    "Overweeg fiscaal verlies van zelfstandigenaftrek 2026: EUR 2.470"
  ]
}
```

### UrenEvidence

Audit-vast bewijsdossier per kwartaal: PDF-export met geaggregeerde uren per dag/week/categorie + onderliggende time-tracking-referenties + agenda-snippets, ondertekend met digital signature/hash.

```json
{
  "id": "evid-2026-q1-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "periode": "2026-Q1",
  "totaalUren": 312.5,
  "perCategorie": {"BILLABLE_KLANTWERK": 210.0, "...": "..."},
  "exportFormaat": "PDF_A3",
  "fileRef": "files/urc/2026-q1-ond-001234.pdf",
  "sha256": "a7f3b2c1...",
  "gegenereerdOp": "2026-04-02T09:00:00Z",
  "bewaarTermijn": "7 jaar (art. 52 AWR)"
}
```

## Requirements

### Requirement: REQ-URC-000 Initiatie tracker en doel-norm bepaling

Bij eerste activatie van de tracker moet het systeem op basis van het ondernemingsprofiel (rechtsvorm, arbeidsongeschiktheid-status, AOW-leeftijd, eventueel parallel loondienst) automatisch de juiste doel-norm (1.225 of 800 uren), het grotendeels-criterium en de bewijslast-vereisten bepalen.

#### Scenario: Initialisatie reguliere IB-ondernemer

- GIVEN nieuwe ondernemer met eenmanszaak, geen arbeidsongeschiktheid, geen parallel loondienst
- WHEN tracker wordt geïnitialiseerd voor 2026
- THEN moet doel-norm 1.225 worden gezet
- AND mag het grotendeels-criterium niet als toepasselijk worden gemarkeerd
- AND moet de standaard categorieën-set worden geactiveerd

#### Scenario: Initialisatie met AO

- GIVEN nieuwe ondernemer met UWV-vastgestelde AO 80-100%
- WHEN initialisatie loopt
- THEN moet doel-norm 800 worden gezet
- AND moet bewijsstuk UWV-beschikking worden gevraagd

### Requirement: REQ-URC-001 Dagelijkse rolling tally

Het systeem moet elke dag de cumulatieve urenstand voor het lopende kalenderjaar automatisch updaten op basis van time-tracking, agenda, en handmatige registraties.

#### Scenario: Tally na dagregistratie

- GIVEN ondernemer registreert 8.5 uur op 21 mei 2026
- WHEN end-of-day-batch draait om 23:00
- THEN moet UrencriteriumYear.lopendeUren met 8.5 worden opgehoogd
- AND moet prognose worden herberekend

#### Scenario: Reistijd-cap toegepast

- GIVEN ondernemer registreert 6 uur reistijd op één dag
- WHEN tally draait
- THEN moet maximaal 4 uur reistijd geteld worden (cap per dag)
- AND moet een notitie "Reistijd-cap toegepast: 2 uur niet meegeteld" worden gelogd

### Requirement: REQ-URC-002 Prognose engine met seizoenscorrectie

Het systeem moet een prognose tot einde kalenderjaar produceren op basis van een rolling 12-weeks gemiddelde, gecorrigeerd voor seizoenspatronen (zomerdip, december-dip), bekende vakantieperioden, en ingeplande opdrachten.

#### Scenario: Prognose includeert geplande vakantie

- GIVEN ondernemer heeft vakantie ingepland 15 juli — 9 augustus
- WHEN prognose wordt berekend
- THEN moet die periode op 0 uren staan
- AND moet kansBehaaldNorm dienovereenkomstig dalen

#### Scenario: Prognose past seizoenscorrectie toe

- GIVEN historische data toont 25 procent lagere uren in augustus
- WHEN augustus-prognose wordt berekend
- THEN moet augustus 25 procent onder rolling-gemiddelde liggen
- AND moet het model versienummer "v3.2-12wk-seasonal" tonen

### Requirement: REQ-URC-003 Drempel-alerts op kritieke momenten

Het systeem moet alerts genereren op vaste momenten (eind Q1, Q2, Q3) en bij prognose-omslagen (OP_KOERS → RISICO of RISICO → KRITIEK), met concrete handelingsperspectieven.

#### Scenario: Q3-alert bij prognose-tekort

- GIVEN per 30 september lopende uren 916, prognose 1180
- WHEN Q3-end-batch draait
- THEN moet een alert "PROGNOSE_RISICO_DROP" worden gegenereerd
- AND moet handelingsperspectief minimaal 3 concrete acties bevatten

#### Scenario: Omslagalert bij ziekte

- GIVEN ondernemer registreert 2 weken ziekte (eerder bij OP_KOERS)
- WHEN prognose wordt herberekend
- THEN moet bij omslag naar RISICO direct een alert worden gegenereerd
- AND moet de alert verwijzen naar de ziekteperiode als oorzaak

### Requirement: REQ-URC-004 Categorisatie en wegingsregels

Het systeem moet ondersteuning bieden voor alle door de Belastingdienst erkende uren-categorieën, met de juiste cap-regels en bewijsvereisten per categorie.

#### Scenario: Acquisitietijd zonder cap

- GIVEN ondernemer registreert 3 uur acquisitie (telefoneren, offertes)
- WHEN categorisatie wordt toegepast
- THEN moet alles meegeteld worden (geen cap)
- AND moet de fiscale grondslag-referentie "HR 22 mei 1996, BNB 1996/302" beschikbaar zijn

#### Scenario: Scholing met bewijsvereiste

- GIVEN ondernemer registreert 8 uur scholing op één dag
- WHEN tally draait
- THEN moet het systeem vragen om bewijs (factuur cursus, diploma)
- AND mag de tally pas definitief gemaakt worden na evidence-upload

### Requirement: REQ-URC-005 WBSO-uren dubbeltelling

S&O-uren die geregistreerd zijn voor de WBSO-aftrek moeten automatisch meetellen in het urencriterium, zonder dat ze tweemaal handmatig moeten worden geregistreerd.

#### Scenario: WBSO-S&O-uren tellen mee

- GIVEN ondernemer heeft 320 S&O-uren voor 2026 geregistreerd in WBSO-administratie
- WHEN urencriterium-tally draait
- THEN moeten die 320 uur automatisch meetellen in de BILLABLE_KLANTWERK + R_AND_D categorie
- AND mag geen dubbele telling plaatsvinden met time-tracking

### Requirement: REQ-URC-006 Verlaagd urencriterium bij arbeidsongeschiktheid

Voor ondernemers met UWV-vastgestelde arbeidsongeschiktheid moet het systeem het verlaagde criterium van 800 uren ondersteunen (art. 3.6 lid 5 IB).

#### Scenario: 800-uren-norm bij WIA

- GIVEN ondernemer heeft WIA-uitkering en heeft "AO" geconfigureerd
- WHEN UrencriteriumYear wordt aangemaakt voor 2026
- THEN moet doelNorm 800 zijn
- AND moet normGrondslag "art. 3.6 lid 5 IB" tonen

### Requirement: REQ-URC-007 Grotendeels-criterium voor combinatie loondienst+ZZP

Voor ondernemers die naast hun zaak in loondienst werken, moet het systeem automatisch toetsen of meer dan 50 procent van de totale arbeidstijd aan de onderneming wordt besteed (art. 3.6 lid 2 IB).

#### Scenario: Loondienst > onderneming

- GIVEN ondernemer werkt 32 uur per week in loondienst (1670 uur/jaar)
- AND registreert 1240 ondernemingsuren
- WHEN grotendeelscriterium wordt getoetst
- THEN moet "NIET_GROTENDEELS_ONDERNEMING" worden gemarkeerd
- AND moet zelfstandigenaftrek geblokkeerd worden ondanks 1225+ ondernemingsuren

### Requirement: REQ-URC-008 Zwangerschap fictie 16 weken

Het systeem moet de wettelijke fictie ondersteunen dat een ondernemende moeder geacht wordt te hebben doorgewerkt tijdens 16 weken zwangerschapsverlof.

#### Scenario: Zwangerschapsverlof urentoekenning

- GIVEN ondernemer heeft zwangerschapsverlof geregistreerd 1 mei — 21 augustus 2026
- AND ondernemer kreeg ZEZ-uitkering (Zelfstandig en Zwanger)
- WHEN urencriterium-tally draait
- THEN moeten 16 weken * gemiddeld weekuren (op basis voorgaand jaar) worden toegevoegd als "FICTIE_ZEZ"

### Requirement: REQ-URC-009 Agenda-import met categorie-AI

Het systeem moet een ICS- of Outlook-import bieden waarin agendablokken automatisch worden gecategoriseerd via een lokale classifier (LLM optioneel).

#### Scenario: ICS-import categoriseert meetings

- GIVEN ondernemer importeert zijn Google Calendar voor mei 2026
- WHEN categorie-classifier draait
- THEN moeten "Klantmeeting Acme" → KLANTWERK, "Acquisitiegesprek prospect" → ACQUISITIE, "Cursus PHP" → SCHOLING worden gemarkeerd
- AND moet de gebruiker per item kunnen heroverwegen voordat het definitief in tally landt

### Requirement: REQ-URC-010 Audit-vast bewijsdossier

Per kwartaal moet het systeem een PDF-A3-bewijsdossier kunnen genereren dat een Belastingdienstcontrole doorstaat, met SHA-256-hash en bewaartermijn 7 jaar.

#### Scenario: Q1-bewijsdossier export

- GIVEN Q1 2026 is afgerond
- WHEN "Bewijsdossier exporteren" wordt geklikt
- THEN moet een PDF worden gegenereerd met dagregistraties, categorisering, bron-referenties
- AND moet de SHA-256-hash worden opgeslagen
- AND moet het document terugvindbaar zijn via bewaartermijn-index

#### Scenario: Bewijsdossier bij controle Belastingdienst

- GIVEN de Belastingdienst vraagt bewijs urencriterium 2024
- WHEN gebruiker zoekt op "evidence 2024"
- THEN moet het systeem het bewijsdossier vinden en de SHA-256-hash valideren tegen de oorspronkelijke export

### Requirement: REQ-URC-011 Integratie met IB-aangifte

Bij start van IB-aangifte moet het urencriterium-resultaat met evidenceRef worden doorgestuurd naar `bookkeeping-ib-aangifte-zzp`.

#### Scenario: Urencriterium-bewijs naar IB-aangifte

- GIVEN urencriterium 2025 staat op 1462 (BEHAALD)
- WHEN IB-aangifte 2025 wordt gestart
- THEN moet IB-aangifte het criterium-bewijs ophalen via API
- AND moet zelfstandigenaftrek automatisch worden toegekend

### Requirement: REQ-URC-012 Meerderejaarsoverzicht voor ZZP-strategie

Het systeem moet een meerderejaars-overzicht (5 jaar terug) bieden zodat ondernemers patronen herkennen en hun ZZP-strategie kunnen bijsturen.

#### Scenario: 5-jaars trend dashboard

- GIVEN ondernemer is sinds 2021 actief
- WHEN "Trend uren" wordt geopend
- THEN moet een grafiek 2021-2026 verschijnen met gerealiseerde uren per jaar
- AND moeten jaren waarin urencriterium niet werd behaald, gemarkeerd worden met rode flag

### Requirement: REQ-URC-013 Pre-fill bron-suggesties met automatische detectie

Het systeem moet open kanalen (e-mail signatuur "Met vriendelijke groet, vanuit de trein"-vermeldingen, agenda-events, factuur-uren) actief scannen op niet-geregistreerde uren-evidence en pre-fill suggesties bieden zodat ondernemers niet handmatig backfillen.

#### Scenario: Email-evidence suggereert acquisitietijd

- GIVEN ondernemer heeft op 18 mei 14 outbound e-mails naar 6 verschillende prospects gestuurd
- AND op 18 mei is geen ACQUISITIE-uur geregistreerd
- WHEN dagelijkse suggestie-batch draait
- THEN moet een suggestie verschijnen "Lijkt op 1-2 uur acquisitietijd 18 mei (14 mails, 6 prospects)"
- AND moet de gebruiker met één klik kunnen accepteren of weigeren

#### Scenario: Factuur impliceert klantwerk-uren

- GIVEN factuur week 22 toont 12 uur klantwerk voor Acme
- AND time-tracking toont slechts 8 uur op die opdracht
- WHEN reconciliatie-check draait
- THEN moet flag verschijnen "Factuur impliceert 4 uur meer dan geregistreerd — controleren"

### Requirement: REQ-URC-014 Multi-onderneming consolidatie

Voor ondernemers met meerdere ondernemingsvormen (eenmanszaak + maatschap of VOF) moet het systeem het urencriterium per onderneming én geconsolideerd kunnen tonen, conform fiscale regel dat uren-criterium per onderneming geldt.

#### Scenario: Twee ondernemingen, één meegeteld

- GIVEN ondernemer heeft eenmanszaak (920 uur) en VOF-aandeel (450 uur)
- WHEN urencriterium-toets draait
- THEN moet per onderneming worden getoetst — beide individueel onder 1225
- AND moet het systeem waarschuwen "Geen onderneming voldoet aan 1225-norm; zelfstandigenaftrek niet toegestaan"

### Requirement: REQ-URC-015 Real-time tracking via Pomodoro/Toggl-integratie

Het systeem moet via OAuth integratie ondersteunen met populaire tijdregistratie-tools (Toggl, Harvest, Clockify, Pomofocus) zodat real-time time-entries direct in shillinq landen.

#### Scenario: Toggl-integratie sync

- GIVEN ondernemer heeft Toggl gekoppeld via OAuth
- WHEN nieuwe time-entry "Klant Acme — backend dev — 1h 23m" in Toggl wordt geboekt
- THEN moet die binnen 60 seconden via webhook in shillinq UrenRegistratie verschijnen
- AND moet automatisch worden gecategoriseerd als BILLABLE_KLANTWERK

### Requirement: REQ-URC-016 Audit-modus voor Belastingdienst-controleur

Het systeem moet een tijdelijke read-only "controleur-toegang" kunnen verlenen aan een Belastingdienst-inspecteur met scoped access tot urenregistratie + bewijsstukken voor een opgegeven periode, met volledige access-log.

#### Scenario: Read-only inspecteur-token

- GIVEN inspecteur vraagt inzage 2024 urenregistratie
- WHEN ondernemer een tijdelijk inspecteur-token uitgeeft (geldig 14 dagen, alleen 2024)
- THEN moet de inspecteur via een unieke URL alle 2024 registraties + categorisatie + bron-referenties + bewijsdossiers zien
- AND moet elke pagina-view in access-log worden vastgelegd

#### Scenario: Token-revocatie

- GIVEN inspecteur-token is verleend, ondernemer wil herroepen
- WHEN "Token revoceren" wordt geklikt
- THEN moet de URL onmiddellijk ongeldig worden
- AND moet de inspecteur via mail een revocatie-bevestiging ontvangen

### Requirement: REQ-URC-017 Backfill met bewijsplafond

Indien een ondernemer achteraf uren wil toevoegen (backfill), moet het systeem dit alleen toestaan voor de afgelopen 7 dagen zonder beperking; daarbuiten alleen met expliciete reden + bewijs upload, omdat de Belastingdienst achteraf opgestelde registraties wantrouwt.

#### Scenario: Backfill 5 dagen geleden

- GIVEN ondernemer wil 16 mei (vandaag is 21 mei) een vergeten uur toevoegen
- WHEN registratie wordt opgevoerd
- THEN moet het systeem dit zonder extra eisen accepteren
- AND moet het label "Backfill T+5 dagen" stilzwijgend worden bijgevoegd

#### Scenario: Backfill 6 weken geleden

- GIVEN ondernemer wil voor 5 april (T+6 weken) uren toevoegen
- WHEN registratie wordt opgevoerd
- THEN moet expliciete reden worden gevraagd
- AND moet bewijs (e-mail, factuur, agenda-event) verplicht zijn
- AND moet de backfill apart worden gelabeld in evidence-dossier zodat een controleur de context ziet

## Standards & Sources

- Wet inkomstenbelasting 2001, art. 3.6 (urencriterium), lid 1 (1225), lid 2 (grotendeels), lid 5 (verlaagd 800 bij AO), art. 3.78 (startersaftrek), art. 3.76 (zelfstandigenaftrek), art. 3.78a (meewerkaftrek)
- Algemene wet inzake rijksbelastingen, art. 52 (administratieve verplichtingen, bewaartermijn 7 jaar), art. 47 (informatieplicht aan inspecteur)
- Hoge Raad 14 maart 2003, BNB 2003/258 (reistijd-criterium)
- Hoge Raad 22 mei 1996, BNB 1996/302 (acquisitietijd telt mee)
- Hoge Raad 2 oktober 1996, BNB 1996/388 (administratie- en algemene werkzaamheden)
- Hoge Raad 18 mei 2007 (bewijslast bij urencriterium, sluitende registratie vereist)
- Rechtbank Gelderland 2024 uitspraken over bewijslast urenregistratie (achteraf opgestelde Excel onvoldoende)
- Rechtbank Den Haag 2023 over wel/niet meetellen reistijd zonder vaste werkplek
- Belastingdienst Handboek Ondernemen 2026, hoofdstuk 5 (Urencriterium)
- Wet arbeid en zorg (Wazo) en Wet ZEZ (Zelfstandig en Zwanger) — fictie 16 weken
- Wet werk en inkomen naar arbeidsvermogen (WIA) — voor verlaagde norm bij arbeidsongeschiktheid
- WBSO-handleiding (RVO) voor S&O-uren-administratie — koppeling
- AVG (Verordening (EU) 2016/679), art. 5 lid 1c (minimale gegevensverwerking) voor agenda-import
- ISO/IEC 27001:2022 — voor bewijsdossier-integriteit
- RFC 5545 (iCalendar) — voor ICS-import

## Cross-app integration

- `bookkeeping-time-tracking` — primaire bron voor billable uren per project/klant; bidirectionele synchronisatie
- `bookkeeping-ib-aangifte-zzp` — afnemer van urencriterium-resultaat voor zelfstandigenaftrek-toets en startersaftrek
- `bookkeeping-wbso-administratie` (bestaand) — S&O-uren bidirectioneel, dubbeltelling voorkomen
- `bookkeeping-retainer-billing` — retainer-uren-allocatie voor categorisatie
- `bookkeeping-expense-capture` — context-uren bij administratieve verwerking
- `dba-compliance-marker` — urenregistratie als evidence voor "eigen onderneming runnen" bij DBA-toets
- `zzp-cashflow-13wk` — pipeline-omzet die uit verwachte uren komt
- `hrmq` — meewerkende partner-uren voor meewerkaftrek (525-uren-criterium), arbeidsongeschiktheidsdata van WIA
- `pipelinq` — agenda-bron (klantmeetings, acquisitiegesprekken, prospects)
- `openconnector` — ICS/CalDAV/Outlook/Google Calendar-import endpoints; Toggl/Harvest/Clockify/Pomofocus-OAuth
- `openregister` — bewijsdossier file-storage met SHA-256 + bewaartermijn (art. 52 AWR, 7 jaar)
- `nldesign` — government-thema voor publieke ZZP-portaal-deelnemers
- `launchpad` — BI-overzicht voor langjarige trends over meerdere ondernemingen

## Target users

- **Primair: ZZP'er die zelfstandigenaftrek wil claimen** (1225-uren-norm). Heeft directe fiscale baat bij sluitende registratie van EUR 1.500-2.500 belastingvoordeel per jaar.
- **Secondair: Starter** (driemaal startersaftrek mogelijk in eerste 5 jaar — dus urencriterium hard nodig). Extra-aftrek EUR 2.123 per jaar bij startersjaren.
- **Tertiair: Meewerkende partner** (525-uren-norm voor meewerkaftrek)
- **Bijzonder: Arbeidsongeschikte ondernemer** (verlaagd 800-uren-criterium per art. 3.6 lid 5 IB)
- **Bijzonder: Zwangere ondernemer** (Wet ZEZ — 16 weken fictie doorwerken)
- **Bijzonder: ZZP'er met combinatie loondienst** (grotendeels-criterium >50 procent ondernemingstijd)
- **Bijzonder: Ondernemer met meerdere ondernemingen** (eenmanszaak + maatschap — per onderneming toetsen)
- **Tussenliggend: Boekhouder / belastingadviseur** met cliëntportfolio (multi-tenant view, vroege waarschuwing per cliënt)
- **Niet binnen scope**: rechtspersonen (DGA-loon werkt anders, vereist gebruikelijk-loon-regeling), BV-DGAs (geen IB-ondernemer)

# Spec: zzp-urencriterium-tracker

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T2 (fiscal compliance + operations)  
**Depends on:**  
- `bookkeeping-time-tracking`  
- `bookkeeping-ib-aangifte-zzp`  
**Cross-project:**  
- `openconnector` (ICS/CalDAV import)  
- `openregister` (file-storage + SHA-256)  
- `hrmq` (AO-status, meewerkende-partner)  
- `bookkeeping-wbso-administratie` (S&O-uren sync)  

---

## ADDED Requirements

### Requirement: REQ-URC-000 Initiatie tracker en doel-norm bepaling

The system MUST automatically determine the correct doel-norm (1.225 or 800 hours), the grotendeels-criterium applicability and the evidence requirements from the entrepreneur profile on first activation (art. 3.6 Wet IB 2001).

Bij eerste activatie van de tracker moet het systeem op basis van het ondernemingsprofiel
(rechtsvorm, arbeidsongeschiktheid-status, AOW-leeftijd, eventueel parallel loondienst)
automatisch de juiste doel-norm (1.225 of 800 uren), het grotendeels-criterium en de
bewijslast-vereisten bepalen (art. 3.6 Wet IB 2001).

#### Scenario: Initialisatie reguliere IB-ondernemer

- **GIVEN** nieuwe ondernemer met eenmanszaak, geen arbeidsongeschiktheid, geen parallel loondienst
- **WHEN** tracker wordt geïnitialiseerd voor 2026
- **THEN** moet doel-norm 1.225 worden gezet
- **AND** mag het grotendeels-criterium niet als toepasselijk worden gemarkeerd
- **AND** moet de standaard categorieën-set worden geactiveerd

#### Scenario: Initialisatie met AO

- **GIVEN** nieuwe ondernemer met UWV-vastgestelde AO 80-100%
- **WHEN** initialisatie loopt
- **THEN** moet doel-norm 800 worden gezet
- **AND** moet normGrondslag "art. 3.6 lid 5 Wet IB 2001" tonen

### Requirement: REQ-URC-001 Dagelijkse rolling tally

The system MUST update the cumulative running hours for the current calendar year daily from time-tracking, agenda and manual registrations.

Het systeem moet elke dag de cumulatieve urenstand voor het lopende kalenderjaar
automatisch updaten op basis van time-tracking, agenda, en handmatige registraties.

#### Scenario: Tally na dagregistratie

- **GIVEN** ondernemer registreert 8.5 uur op 21 mei 2026
- **WHEN** end-of-day-batch draait om 23:00
- **THEN** moet UrencriteriumYear.lopendeUren met 8.5 worden opgehoogd
- **AND** moet prognose worden herberekend

#### Scenario: Reistijd-cap toegepast

- **GIVEN** ondernemer registreert 6 uur reistijd op één dag
- **WHEN** tally draait
- **THEN** moet maximaal 4 uur reistijd geteld worden (cap per dag)
- **AND** moet een notitie "Reistijd-cap toegepast: 2 uur niet meegeteld" worden gelogd

### Requirement: REQ-URC-002 Prognose engine met seizoenscorrectie

The system MUST produce a year-end forecast based on a rolling 12-week average, corrected for seasonality, known holidays and scheduled assignments.

Het systeem moet een prognose tot einde kalenderjaar produceren op basis van een
rolling 12-weeks gemiddelde, gecorrigeerd voor seizoenspatronen (zomerdip,
december-dip), bekende vakantieperioden, en ingeplande opdrachten.

#### Scenario: Prognose includeert geplande vakantie

- **GIVEN** ondernemer heeft vakantie ingepland 15 juli — 9 augustus
- **WHEN** prognose wordt berekend
- **THEN** moet die periode op 0 uren staan
- **AND** moet kansBehaaldNorm dienovereenkomstig dalen

#### Scenario: Prognose past seizoenscorrectie toe

- **GIVEN** historische data toont 25 procent lagere uren in augustus
- **WHEN** augustus-prognose wordt berekend
- **THEN** moet augustus 25 procent onder rolling-gemiddelde liggen
- **AND** moet het model versienummer "v3.2-12wk-seasonal" tonen

### Requirement: REQ-URC-003 Drempel-alerts op kritieke momenten

The system MUST generate alerts at fixed moments (quarter end) and on prognose omslagen (OP_KOERS to RISICO or RISICO to KRITIEK), with concrete handelingsperspectieven.

Het systeem moet alerts genereren op vaste momenten (kwartaal-eind) en bij
prognose-omslagen (OP_KOERS → RISICO of RISICO → KRITIEK), met concrete
handelingsperspectieven.

#### Scenario: Q3-alert bij prognose-tekort

- **GIVEN** per 30 september lopende uren 916, prognose 1180
- **WHEN** Q3-end-batch draait
- **THEN** moet een alert "PROGNOSE_RISICO_DROP" worden gegenereerd
- **AND** moet handelingsperspectief minimaal 3 concrete acties bevatten

#### Scenario: Omslagalert bij ziekte

- **GIVEN** ondernemer registreert 2 weken ziekte (eerder bij OP_KOERS)
- **WHEN** prognose wordt herberekend
- **THEN** moet bij omslag naar RISICO direct een alert worden gegenereerd
- **AND** moet de alert verwijzen naar de ziekteperiode als oorzaak

### Requirement: REQ-URC-004 Categorisatie en wegingsregels

The system MUST support every Belastingdienst-recognised hour category with the correct cap rules and evidence requirements per category.

Het systeem moet ondersteuning bieden voor alle door de Belastingdienst erkende
uren-categorieën, met de juiste cap-regels en bewijsvereisten per categorie.

#### Scenario: Acquisitietijd zonder cap

- **GIVEN** ondernemer registreert 3 uur acquisitie (telefoneren, offertes)
- **WHEN** categorisatie wordt toegepast
- **THEN** moet alles meegeteld worden (geen cap)
- **AND** moet de fiscale grondslag-referentie "HR 22 mei 1996, BNB 1996/302" beschikbaar zijn

#### Scenario: Scholing met bewijsvereiste

- **GIVEN** ondernemer registreert 8 uur scholing op één dag
- **WHEN** tally draait
- **THEN** moet het systeem vragen om bewijs (factuur cursus, diploma)
- **AND** mag de tally pas definitief gemaakt worden na evidence-upload

### Requirement: REQ-URC-005 WBSO-uren dubbeltelling

S&O hours registered for the WBSO deduction MUST count automatically toward the urencriterium without double manual registration.

S&O-uren die geregistreerd zijn voor de WBSO-aftrek moeten automatisch meetellen
in het urencriterium, zonder dat ze tweemaal handmatig moeten worden geregistreerd.

#### Scenario: WBSO-S&O-uren tellen mee

- **GIVEN** ondernemer heeft 320 S&O-uren voor 2026 geregistreerd in WBSO-administratie
- **WHEN** urencriterium-tally draait
- **THEN** moeten die 320 uur automatisch meetellen in de BILLABLE_KLANTWERK + R_AND_D categorie
- **AND** mag geen dubbele telling plaatsvinden met time-tracking

### Requirement: REQ-URC-006 Verlaagd urencriterium bij arbeidsongeschiktheid

For entrepreneurs with UWV-assessed incapacity for work the system MUST support the reduced 800-hour criterion (art. 3.6 lid 5 Wet IB).

Voor ondernemers met UWV-vastgestelde arbeidsongeschiktheid moet het systeem het
verlaagde criterium van 800 uren ondersteunen (art. 3.6 lid 5 Wet IB).

#### Scenario: 800-uren-norm bij WIA

- **GIVEN** ondernemer heeft WIA-uitkering en heeft "AO" geconfigureerd
- **WHEN** UrencriteriumYear wordt aangemaakt voor 2026
- **THEN** moet doelNorm 800 zijn
- **AND** moet normGrondslag "art. 3.6 lid 5 Wet IB 2001" tonen

### Requirement: REQ-URC-007 Grotendeels-criterium voor combinatie loondienst+ZZP

For entrepreneurs who also work in paid employment the system MUST automatically test whether more than 50 percent of total working time is spent on the onderneming (art. 3.6 lid 2 Wet IB).

Voor ondernemers die naast hun zaak in loondienst werken, moet het systeem automatisch
toetsen of meer dan 50 procent van de totale arbeidstijd aan de onderneming wordt
besteed (art. 3.6 lid 2 Wet IB).

#### Scenario: Loondienst > onderneming

- **GIVEN** ondernemer werkt 32 uur per week in loondienst (1670 uur/jaar)
- **AND** registreert 1240 ondernemingsuren
- **WHEN** grotendeelscriterium wordt getoetst
- **THEN** moet "NIET_GROTENDEELS_ONDERNEMING" worden gemarkeerd
- **AND** moet zelfstandigenaftrek geblokkeerd worden ondanks 1225+ ondernemingsuren

### Requirement: REQ-URC-008 Zwangerschap fictie 16 weken

The system MUST support the statutory fiction that an entrepreneuring mother is deemed to have continued working during 16 weeks of pregnancy leave (Wet ZEZ).

Het systeem moet de wettelijke fictie ondersteunen dat een ondernemende moeder
geacht wordt te hebben doorgewerkt tijdens 16 weken zwangerschapsverlof (Wet ZEZ).

#### Scenario: Zwangerschapsverlof urentoekenning

- **GIVEN** ondernemer heeft zwangerschapsverlof geregistreerd 1 mei — 21 augustus 2026
- **AND** ondernemer kreeg ZEZ-uitkering (Zelfstandig en Zwanger)
- **WHEN** urencriterium-tally draait
- **THEN** moeten 16 weken * gemiddeld weekuren (op basis voorgaand jaar) worden toegevoegd als "FICTIE_ZEZ"

### Requirement: REQ-URC-009 Agenda-import met categorie-AI

The system MUST offer an ICS or Outlook import in which agenda blocks are automatically categorised via a local classifier (LLM optional, MVP manual confirm).

Het systeem moet een ICS- of Outlook-import bieden waarin agendablokken automatisch
worden gecategoriseerd via een lokale classifier (LLM optioneel, MVP: manual confirm).

#### Scenario: ICS-import categoriseert meetings

- **GIVEN** ondernemer importeert zijn Google Calendar voor mei 2026
- **WHEN** categorie-classifier draait
- **THEN** moeten "Klantmeeting Acme" → KLANTWERK, "Acquisitiegesprek prospect" → ACQUISITIE, "Cursus PHP" → SCHOLING worden gemarkeerd
- **AND** moet de gebruiker per item kunnen heroverwegen voordat het definitief in tally landt

### Requirement: REQ-URC-010 Audit-vast bewijsdossier

The system MUST be able to generate a per-quarter PDF-A3 evidence dossier that survives a Belastingdienst audit, with a SHA-256 hash and a 7-year retention term (art. 52 AWR).

Per kwartaal moet het systeem een PDF-A3-bewijsdossier kunnen genereren dat een
Belastingdienstcontrole doorstaat, met SHA-256-hash en bewaartermijn 7 jaar
(art. 52 AWR).

#### Scenario: Q1-bewijsdossier export

- **GIVEN** Q1 2026 is afgerond
- **WHEN** "Bewijsdossier exporteren" wordt geklikt
- **THEN** moet een PDF worden gegenereerd met dagregistraties, categorisering, bron-referenties
- **AND** moet de SHA-256-hash worden opgeslagen
- **AND** moet het document terugvindbaar zijn via bewaartermijn-index

#### Scenario: Bewijsdossier bij controle Belastingdienst

- **GIVEN** de Belastingdienst vraagt bewijs urencriterium 2024
- **WHEN** gebruiker zoekt op "evidence 2024"
- **THEN** moet het systeem het bewijsdossier vinden en de SHA-256-hash valideren tegen de oorspronkelijke export

### Requirement: REQ-URC-011 Integratie met IB-aangifte

On starting the IB-aangifte the urencriterium result with evidenceRef MUST be forwarded to bookkeeping-ib-aangifte-zzp.

Bij start van IB-aangifte moet het urencriterium-resultaat met evidenceRef
worden doorgestuurd naar `bookkeeping-ib-aangifte-zzp`.

#### Scenario: Urencriterium-bewijs naar IB-aangifte

- **GIVEN** urencriterium 2025 staat op 1462 (BEHAALD)
- **WHEN** IB-aangifte 2025 wordt gestart
- **THEN** moet IB-aangifte het criterium-bewijs ophalen via API
- **AND** moet zelfstandigenaftrek automatisch worden toegekend

### Requirement: REQ-URC-012 Meerderejaarsoverzicht voor ZZP-strategie

The system MUST provide a multi-year overview (5 years back) so entrepreneurs can recognise patterns and adjust their ZZP strategy.

Het systeem moet een meerderejaars-overzicht (5 jaar terug) bieden zodat
ondernemers patronen herkennen en hun ZZP-strategie kunnen bijsturen.

#### Scenario: 5-jaars trend dashboard

- **GIVEN** ondernemer is sinds 2021 actief
- **WHEN** "Trend uren" wordt geopend
- **THEN** moet een grafiek 2021-2026 verschijnen met gerealiseerde uren per jaar
- **AND** moeten jaren waarin urencriterium niet werd behaald, gemarkeerd worden met rode flag

### Requirement: REQ-URC-013 Pre-fill bron-suggesties met automatische detectie

The system MUST actively scan open channels (email signature, agenda events, invoice hours) for unregistered hour evidence and offer pre-fill suggestions.

Het systeem moet open kanalen (e-mail signatuur, agenda-events, factuur-uren) actief
scannen op niet-geregistreerde uren-evidence en pre-fill suggesties bieden zodat
ondernemers niet handmatig backfillen.

#### Scenario: Email-evidence suggereert acquisitietijd

- **GIVEN** ondernemer heeft op 18 mei 14 outbound e-mails naar 6 verschillende prospects gestuurd
- **AND** op 18 mei is geen ACQUISITIE-uur geregistreerd
- **WHEN** dagelijkse suggestie-batch draait
- **THEN** moet een suggestie verschijnen "Lijkt op 1-2 uur acquisitietijd 18 mei (14 mails, 6 prospects)"
- **AND** moet de gebruiker met één klik kunnen accepteren of weigeren

#### Scenario: Factuur impliceert klantwerk-uren

- **GIVEN** factuur week 22 toont 12 uur klantwerk voor Acme
- **AND** time-tracking toont slechts 8 uur op die opdracht
- **WHEN** reconciliatie-check draait
- **THEN** moet flag verschijnen "Factuur impliceert 4 uur meer dan geregistreerd — controleren"

### Requirement: REQ-URC-014 Multi-onderneming consolidatie

For entrepreneurs with multiple ondernemingsvormen the system MUST be able to show the urencriterium per onderneming and consolidated, per the fiscal rule that the criterion applies per onderneming.

Voor ondernemers met meerdere ondernemingsvormen (eenmanszaak + maatschap of VOF)
moet het systeem het urencriterium per onderneming én geconsolideerd kunnen tonen,
conform fiscale regel dat uren-criterium per onderneming geldt.

#### Scenario: Twee ondernemingen, één meegeteld

- **GIVEN** ondernemer heeft eenmanszaak (920 uur) en VOF-aandeel (450 uur)
- **WHEN** urencriterium-toets draait
- **THEN** moet per onderneming worden getoetst — beide individueel onder 1225
- **AND** moet het systeem waarschuwen "Geen onderneming voldoet aan 1225-norm; zelfstandigenaftrek niet toegestaan"

### Requirement: REQ-URC-015 Real-time tracking via Toggl/Harvest-integratie (Future T3)

The system MUST support OAuth integration with popular time-tracking tools (Toggl, Harvest, Clockify, Pomofocus) so real-time time-entries land directly in shillinq.

Het systeem moet via OAuth integratie ondersteunen met populaire tijdregistratie-tools
(Toggl, Harvest, Clockify, Pomofocus) zodat real-time time-entries direct in shillinq landen.

#### Scenario: Toggl-integratie sync

- **GIVEN** ondernemer heeft Toggl gekoppeld via OAuth
- **WHEN** nieuwe time-entry "Klant Acme — backend dev — 1h 23m" in Toggl wordt geboekt
- **THEN** moet die binnen 60 seconden via webhook in shillinq UrenRegistratie verschijnen
- **AND** moet automatisch worden gecategoriseerd als BILLABLE_KLANTWERK

### Requirement: REQ-URC-016 Audit-modus voor Belastingdienst-controleur

The system MUST be able to grant a temporary read-only controleur-toegang to a Belastingdienst inspector with scoped access to the urenregistratie and evidence for a given period, with a full access log.

Het systeem moet een tijdelijke read-only "controleur-toegang" kunnen verlenen aan
een Belastingdienst-inspecteur met scoped access tot urenregistratie + bewijsstukken
voor een opgegeven periode, met volledige access-log.

#### Scenario: Read-only inspecteur-token

- **GIVEN** inspecteur vraagt inzage 2024 urenregistratie
- **WHEN** ondernemer een tijdelijk inspecteur-token uitgeeft (geldig 14 dagen, alleen 2024)
- **THEN** moet de inspecteur via een unieke URL alle 2024 registraties + categorisatie + bron-referenties + bewijsdossiers zien
- **AND** moet elke pagina-view in access-log worden vastgelegd

#### Scenario: Token-revocatie

- **GIVEN** inspecteur-token is verleend, ondernemer wil herroepen
- **WHEN** "Token revoceren" wordt geklikt
- **THEN** moet de URL onmiddellijk ongeldig worden
- **AND** moet de inspecteur via mail een revocatie-bevestiging ontvangen

### Requirement: REQ-URC-017 Backfill met bewijsplafond

If an entrepreneur wants to add hours retroactively (backfill) the system MUST allow this only for the past 7 days without restriction; beyond that only with an explicit reason and evidence upload.

Indien een ondernemer achteraf uren wil toevoegen (backfill), moet het systeem dit
alleen toestaan voor de afgelopen 7 dagen zonder beperking; daarbuiten alleen met
expliciete reden + bewijs upload.

#### Scenario: Backfill 5 dagen geleden

- **GIVEN** ondernemer wil 16 mei (vandaag is 21 mei) een vergeten uur toevoegen
- **WHEN** registratie wordt opgevoerd
- **THEN** moet het systeem dit zonder extra eisen accepteren
- **AND** moet het label "Backfill T+5 dagen" stilzwijgend worden bijgevoegd

#### Scenario: Backfill 6 weken geleden

- **GIVEN** ondernemer wil voor 5 april (T+6 weken) uren toevoegen
- **WHEN** registratie wordt opgevoerd
- **THEN** moet expliciete reden worden gevraagd
- **AND** moet bewijs (e-mail, factuur, agenda-event) verplicht zijn
- **AND** moet de backfill apart worden gelabeld in evidence-dossier zodat een controleur de context ziet

---

## Data Model

See `openspec/architecture/adr-000-data-model.md` for `UrencriteriumYear`,
`UrenRegistratie`, `UrenCategorie`, `UrenPrognose`, `UrenAlert`, `UrenEvidence`
register definitions.

## Fiscal References

- **Wet inkomstenbelasting 2001**, art. 3.6 (urencriterium), art. 3.78 (startersaftrek)
- **Hoge Raad** — 2007 (bewijslast), 2003 (reistijd), 1996 (acquisitie, administratie)
- **Rechtbank Gelderland** — 2024 (Excel-backfill onvoldoende)
- **Belastingdienst Handboek Ondernemen 2026** — ch. 5 (Urencriterium)
- **Wet ZEZ (Zelfstandig en Zwanger)** — fictie 16 weken
- **Algemene wet inzake rijksbelastingen**, art. 52 (bewaartermijn 7 jaar)

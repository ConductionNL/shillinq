---
status: draft
---

# Credit Control & Dunning Ladder

## Purpose

Voor de Nederlandse ZZP'er en MKB-ondernemer is achterstallige debiteurenbetaling een chronisch probleem met directe cashflow-impact: volgens Atradius (Payment Practices Barometer 2024) wordt gemiddeld 39 procent van alle B2B-facturen in Nederland te laat betaald, en is bij ongeveer 7 procent sprake van non-payment (afschrijving). Voor de ondernemer betekent dat (a) reëel cashflow-risico (zie ook `zzp-cashflow-13wk`), (b) administratieve last bij elke individuele aanmaning, (c) emotionele/relationele complicaties bij goede klanten die incidenteel te laat zijn, en (d) onbenutte wettelijke instrumenten zoals de 14-dagen-brief uit de Wet Incasso (art. 6:96 lid 6 BW) waarmee bij niet-tijdige betaling buitengerechtelijke incassokosten op de debiteur verhaald kunnen worden.

Deze spec beschrijft hoe shillinq een geautomatiseerde dunning-ladder biedt: een gefaseerde, configureerbare workflow die voor elke openstaande factuur de status bewaakt en op vastgestelde momenten escalerend ingrijpt — van een vriendelijke reminder op vervaldag (stage 1), via een herinnering na 14 dagen (stage 2), een formele aanmaning na 30 dagen met inbouw van de wettelijke 14-dagen-brief (stage 3), een ingebrekestelling met aanzegging van incassobureau na 60 dagen (stage 4), tot daadwerkelijke overdracht aan een incassobureau of deurwaarder na 90 dagen (stage 5). De ladder is per klant aanpasbaar (overheid: 60 dagen extended payment terms; vaste vertrouwde klanten: handmatig overrulebaar), respecteert pauzes bij disputed invoices, en kan opt-in worden gekoppeld aan externe credit-scoring (Graydon, Creditsafe, Atradius Insights) voor risico-aware factureren vooraf.

Het doel is dat de ZZP'er (1) geen handmatige debiteurenadministratie meer hoeft te voeren — het systeem stuurt automatisch de juiste e-mail/brief op het juiste moment in de juiste toon, (2) de wettelijke incassokostenregeling (Wet IK, art. 6:96 BW + Besluit vergoeding voor buitengerechtelijke incassokosten) correct toepast met de juiste 14-dagen-brief en kostenberekening volgens de staffel, (3) onderscheid maakt tussen B2B (waar incassokosten direct verschuldigd zijn na verzuim) en B2C (waar de 14-dagen-aanmaning verplicht is voorafgaand aan incassokosten), (4) escalaties kan pauzeren bij disputes met audit-trail, en (5) bij overdracht aan incassobureau een compleet dossier kan delen met alle correspondentie, facturen, en bewijs van pogingen tot minnelijke schikking.

De spec dekt expliciet de Nederlandse wettelijke incassokostenstaffel (Besluit BIK): tot €2.500 — 15 procent met minimum €40; €2.500-€5.000 — 10 procent over meerdere; €5.000-€10.000 — 5 procent over meerdere; €10.000-€200.000 — 1 procent over meerdere; daarboven — 0,5 procent. En de wettelijke rente: handelsrente B2B per Wet bestrijding betalingsachterstand (art. 6:119a BW) van actuele ECB-herfinancieringsrente +8 procentpunt (per 1 januari 2026: 11,5 procent), en B2C de gewone wettelijke rente per art. 6:119 BW (per 1 januari 2026: 7 procent).

Aanvullend dekt de spec de samenhang met disputed invoices (workflow waarin dunning pauzeert, dispute-resolutie wordt vastgelegd, partial-payments correct worden geboekt), de fiscale behandeling van afgeschreven oninbare vorderingen (BTW-teruggaaf via art. 29 OB bij definitieve oninbaarheid), en — voor het overheidsdomein — de specifieke betalingsvoorwaarden uit de Aanbestedingswet en de standaard inkoopvoorwaarden (ARIV, ARVODI) van het Rijk.

Een centraal ontwerpprincipe is **relatie-bewust escaleren**: de dunning-ladder mag geen klantrelaties beschadigen waar dat niet hoeft. De toon van stage 1 ("vriendelijke reminder") is fundamenteel anders dan stage 4 ("ingebrekestelling met aanzegging tot incassoprocedure"); de spec definieert een gradient van vriendelijk-zakelijk-formeel-juridisch en biedt per stage een template-bibliotheek waar de ondernemer eigen tone-of-voice kan inbrengen. Voor goede vaste klanten kan de ladder per-klant worden afgevlakt (bv. "geen stage 4/5 ooit automatisch — escaleer naar account manager"), terwijl voor nieuwe of risicovolle klanten een agressievere ladder kan worden ingericht (bv. stages op 0/7/21/45/75 dagen).

De engine moet ook de **integriteit van de boekhouding** waarborgen: elke verstuurde dunning-actie creëert een onveranderbare evidence-trail (e-mail-headers, PDF-renderingen met hashes, eventueel ondertekend met digital signature), zodat in een gerechtelijke procedure of incassodossier het bewijs van pogingen tot minnelijke schikking sluitend kan worden geleverd. Dit is met name relevant voor de incassokosten-claim: art. 6:96 BW vereist dat de schuldeiser aantoonbaar moet hebben aangemaand, en dat bewijs is in de praktijk vaak het verschil tussen wel/niet kunnen verhalen.

Tot slot biedt de spec een **anti-pattern-detector** die onbedoelde escalatie voorkomt: indien een debiteur in de afgelopen 90 dagen meerdere facturen heeft betaald én een dunning-trigger ontstaat alleen door een administratieve fout (bv. verkeerde IBAN, vergeten betalingskenmerk), moet het systeem dat detecteren en eerst proactief contact opnemen voordat juridische escalatie volgt.

## Data Model

### DunningLadder

Configuratie-entiteit: één per ondernemer (of per klantgroep). Definieert de stages, tijdvakken, toon, escalatie-acties.

```json
{
  "id": "ladder-standaard-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "naam": "Standaard dunning-ladder ZZP",
  "klantGroep": "DEFAULT",
  "stages": [
    {"nr": 1, "dagenNaVervalDatum": 0, "naam": "Vriendelijke reminder", "kanaal": "EMAIL", "templateId": "tpl-stage1-vriendelijk"},
    {"nr": 2, "dagenNaVervalDatum": 14, "naam": "Herinnering", "kanaal": "EMAIL", "templateId": "tpl-stage2-herinnering"},
    {"nr": 3, "dagenNaVervalDatum": 30, "naam": "Aanmaning + 14-dagen-brief", "kanaal": "EMAIL+POSTREGISTRATIE", "templateId": "tpl-stage3-aanmaning-14d", "wettelijkEffect": "14_DAGEN_BRIEF_BIK"},
    {"nr": 4, "dagenNaVervalDatum": 60, "naam": "Ingebrekestelling", "kanaal": "AANGETEKENDE_POST", "templateId": "tpl-stage4-ingebrekestelling", "wettelijkEffect": "VERZUIM_INTREDEN"},
    {"nr": 5, "dagenNaVervalDatum": 90, "naam": "Overdracht incasso", "kanaal": "INCASSOBUREAU_API", "actie": "TRANSFER_INCASSO"}
  ],
  "actief": true
}
```

### KlantLadderOverride

Per-klant override op de standaardladder (overheid extended terms, vertrouwde klant handmatig).

```json
{
  "id": "override-klant-gemeente-amsterdam",
  "klantId": "klant-gemeente-amsterdam",
  "baseLadderId": "ladder-standaard-ond-001234",
  "overrides": {
    "stages": [
      {"nr": 1, "dagenNaVervalDatum": 0, "naam": "Reminder", "kanaal": "EMAIL"},
      {"nr": 2, "dagenNaVervalDatum": 30, "naam": "Herinnering (overheid extended)", "kanaal": "EMAIL"},
      {"nr": 3, "dagenNaVervalDatum": 60, "naam": "Aanmaning", "kanaal": "EMAIL"},
      {"nr": 4, "dagenNaVervalDatum": 90, "naam": "Escalatie naar account manager"}
    ]
  },
  "reden": "Aanbestedingswet — overheid heeft 30 dagen wettelijke termijn + interne verwerking, ladder pas vanaf dag-30"
}
```

### DunningRun

Per factuur per stage een uitvoeringsregistratie. Bevat verstuurde inhoud, kanaal, tijdstip, response.

```json
{
  "id": "drun-fact-2026-0247-stage3",
  "factuurId": "fact-2026-0247",
  "ladderId": "ladder-standaard-ond-001234",
  "stageNr": 3,
  "uitgevoerdOp": "2026-06-14T09:00:00Z",
  "kanaal": "EMAIL+POSTREGISTRATIE",
  "ontvangerEmail": "finance@acme.nl",
  "ontvangerNaam": "Crediteurenadministratie Acme BV",
  "templateId": "tpl-stage3-aanmaning-14d",
  "renderedSubject": "Aanmaning factuur 2026-0247 — €8.400",
  "wettelijkEffect": {
    "type": "14_DAGEN_BRIEF_BIK",
    "termijn14DagenEindigt": "2026-06-28",
    "incassokostenBijVerzuim": 1100.00,
    "rentebijVerzuimVa": "2026-06-29"
  },
  "deliveryStatus": "DELIVERED",
  "openTracking": {"opened": true, "openedAt": "2026-06-14T09:42:00Z"}
}
```

### IncassoKostenBerekening

Per factuur berekening van de incassokosten volgens BIK-staffel, opgebouwd na 14-dagen-brief verzuim.

```json
{
  "id": "ik-fact-2026-0247",
  "factuurId": "fact-2026-0247",
  "hoofdsom": 8400.00,
  "berekening": {
    "schaal1_to2500_15pct": 375.00,
    "schaal2_2500_5000_10pct": 250.00,
    "schaal3_5000_10000_5pct": 170.00,
    "totaal": 795.00,
    "minimum": 40.00,
    "toegepast": 795.00
  },
  "wettelijkeRente": {
    "tarief": 0.115,
    "type": "HANDELSRENTE_B2B_6_119A_BW",
    "ingangsdatum": "2026-06-29",
    "berekendOp": "2026-07-21",
    "dagen": 22,
    "bedrag": 58.13
  },
  "totaalVerschuldigd": 9253.13
}
```

### DunningPauseDispute

Wanneer een factuur in dispuut staat, moet dunning pauzeren met audit-trail.

```json
{
  "id": "pause-fact-2026-0247-dispute-001",
  "factuurId": "fact-2026-0247",
  "pauzeStart": "2026-06-02T14:20:00Z",
  "pauzeEind": null,
  "reden": "DISPUTED",
  "details": "Klant betwist 4 uur uit week-22; gesprek gepland 10 juni",
  "gepauzeerdDoor": "user-zzp-ond-001234",
  "evidenceRefs": ["email-2026-06-02-disputereactie.eml"]
}
```

### CreditScore

Optionele integratie met externe credit-scoring providers; per klant een score-snapshot.

```json
{
  "id": "cs-klant-acme-bv-2026-05",
  "klantId": "klant-acme-bv",
  "provider": "GRAYDON",
  "scoreDatum": "2026-05-01",
  "score": 6.4,
  "scoreSchaal": "1-10",
  "betalingsRisicoIndicatie": "MIDDEN",
  "creditLimietAdvies": 25000.00,
  "kostenLookup": 1.85
}
```

### OninbaarAfschrijving

Bij definitieve oninbaarheid: afschrijving + BTW-teruggaaf via art. 29 OB.

```json
{
  "id": "oninb-fact-2025-0089",
  "factuurId": "fact-2025-0089",
  "hoofdsomAfgeschreven": 4200.00,
  "btwTeruggaaf": 882.00,
  "art29OBVerklaring": "Schuldenaar in staat van faillissement op 2026-04-12",
  "evidenceRef": "files/oninb/faillissementsvonnis-2026-04-12.pdf",
  "boekingId": "jp-2026-04-15-oninb-0089",
  "btwAangiftePeriode": "2026-Q2"
}
```

## Requirements

### Requirement: REQ-CCD-000 Ladder-activering en eerste setup

Bij eerste activatie van dunning moet het systeem de ondernemer door een setup-wizard leiden waarin standaard-ladder wordt gekozen, templates per stage worden gepersonaliseerd, en e-mail-afzender + footer-data worden ingesteld.

#### Scenario: Setup-wizard 5 stappen

- GIVEN nieuwe ondernemer activeert dunning
- WHEN setup-wizard start
- THEN moet stap 1 (ladder kiezen), stap 2 (toon kiezen: vriendelijk/zakelijk), stap 3 (afzender-data), stap 4 (templates personaliseren), stap 5 (test-versturen aan eigen adres) worden doorlopen
- AND mag dunning pas worden geactiveerd na voltooide wizard

### Requirement: REQ-CCD-001 Configureerbare ladder per ondernemer

Het systeem moet één of meerdere dunning-ladders per ondernemer kunnen definiëren met variabele stages, tijdvakken, kanalen en templates.

#### Scenario: Standaard 5-stage ladder

- GIVEN nieuwe ondernemer gebruikt shillinq voor het eerst
- WHEN dunning-config wordt geïnitialiseerd
- THEN moet de standaardladder (5 stages: 0/14/30/60/90 dagen) worden voorgesteld
- AND moet de ondernemer kunnen wijzigen voordat go-live

#### Scenario: Tweede ladder voor speciale klanten

- GIVEN ondernemer wil een aangepaste ladder voor overheidsklanten
- WHEN nieuwe ladder "Overheid" wordt aangemaakt
- THEN moet die als alternatief beschikbaar zijn
- AND moeten klanten van categorie "OVERHEID" automatisch deze ladder krijgen

### Requirement: REQ-CCD-002 Per-klant override met audit

Voor individuele klanten moet een override op de standaardladder ingesteld kunnen worden met motivatie en bewaaraudit.

#### Scenario: Override voor goede vaste klant

- GIVEN klant Mavila BV is 15 jaar trouwe klant, betaalt soms te laat door admin
- WHEN ondernemer override "geen automatische stage 4 en 5" instelt
- THEN moet de override worden bewaard met reden + datum + gebruiker
- AND moet ladder-execution stage 4/5 overslaan voor deze klant

### Requirement: REQ-CCD-003 Wettelijke 14-dagen-brief correct toepassen

Stage 3 (aanmaning) moet voor B2C-debiteuren een wettelijke 14-dagen-brief bevatten conform art. 6:96 lid 6 BW; incassokosten mogen pas worden gevorderd na verloop van die termijn.

#### Scenario: B2C 14-dagen-brief

- GIVEN factuur op B2C-klant 30 dagen vervallen
- WHEN stage 3 wordt geactiveerd
- THEN moet het systeem de 14-dagen-brief versturen met expliciete vermelding "u krijgt 14 dagen om de factuur alsnog te voldoen voordat incassokosten verschuldigd worden"
- AND mag incassokostenberekening pas plaatsvinden op dag-44 (30 + 14)

#### Scenario: B2B direct verzuim

- GIVEN factuur op B2B-klant
- AND geen aparte ingebrekestellingsclausule in voorwaarden
- WHEN factuur vervalt (geen prestatie van debiteur nodig om in verzuim te raken — art. 6:83 sub a BW)
- THEN mogen incassokosten en handelsrente direct vanaf vervaldatum worden berekend

### Requirement: REQ-CCD-004 Incassokostenstaffel BIK correct toegepast

Het systeem moet de Besluit-vergoeding-buitengerechtelijke-incassokosten-staffel hanteren: 15 procent / 10 procent / 5 procent / 1 procent / 0,5 procent over de oplopende schalen, met minimum €40.

#### Scenario: Berekening op €8.400 hoofdsom

- GIVEN hoofdsom €8.400, verzuim ingetreden
- WHEN incassokosten berekend
- THEN moet de staffel oplopen: 15% × €2.500 = €375; 10% × €2.500 = €250; 5% × €3.400 = €170
- AND moet totaal €795 zijn

#### Scenario: Minimum €40 bij kleine factuur

- GIVEN hoofdsom €100, verzuim
- WHEN berekend
- THEN moet incassokosten €40 (minimum) zijn, niet €15

### Requirement: REQ-CCD-005 Handelsrente B2B + wettelijke rente B2C

Het systeem moet bij berekening van rentekosten onderscheid maken tussen B2B (art. 6:119a BW, ECB-rente + 8 procentpunt — per 1 januari 2026: 11,5 procent) en B2C (art. 6:119 BW — per 1 januari 2026: 7 procent).

#### Scenario: B2B 11,5 procent

- GIVEN B2B-debiteur, hoofdsom €8.400, 30 dagen verzuim na 14-dagen-termijn
- WHEN rente berekend
- THEN moet (€8.400 × 11,5% × 30/365) = €79,40 zijn

#### Scenario: B2C 7 procent

- GIVEN B2C-debiteur, hoofdsom €820, 60 dagen verzuim
- WHEN rente berekend
- THEN moet (€820 × 7% × 60/365) = €9,43 zijn

### Requirement: REQ-CCD-006 Dispute-pauze met audit

Indien een factuur gemarkeerd wordt als disputed, moet dunning automatisch pauzeren tot het dispuut is opgelost (of een hard-deadline van bv. 60 dagen verstreken is).

#### Scenario: Dispute pauzeert ladder

- GIVEN dunning loopt op factuur 2026-0247
- WHEN gebruiker markeert "Disputed: 4 uur betwist door klant"
- THEN moet ladder pauzeren, geen stage-acties uitvoeren
- AND moet pauzeer-event worden gelogd

#### Scenario: Dispute resolutie

- GIVEN dispute is opgelost met partial settlement: hoofdsom verlaagd van €8.400 naar €7.800
- WHEN gebruiker "Dispute opgelost" markeert met aanpassing factuur
- THEN moet ladder hervatten op resterende hoofdsom
- AND mag stage waar de pauze begon, niet opnieuw worden uitgevoerd

### Requirement: REQ-CCD-007 Partial-payment-verwerking

Wanneer een debiteur gedeeltelijk betaalt, moet de ladder doorlopen op het resterende saldo, en moet de incassokosten/rente-berekening worden aangepast.

#### Scenario: Partial payment €3.000 van €8.400

- GIVEN factuur 2026-0247 met hoofdsom €8.400, deelbetaling €3.000 op dag-20
- WHEN partial-payment wordt geboekt
- THEN moet resterend saldo €5.400 worden
- AND moet incassokostenberekening op dag-30 over €5.400 gaan
- AND moet de ladder voor het resterende deel doorlopen

### Requirement: REQ-CCD-008 Overdracht aan incassobureau via API

Bij stage 5 moet het systeem het dossier (factuur, alle ladder-runs, evidence, klantgegevens) kunnen overdragen aan een gekoppeld incassobureau via API (bijv. Bos Incasso, Atradius Collections, Intrum).

#### Scenario: API-overdracht

- GIVEN stage 5 wordt geactiveerd op factuur 2026-0247
- AND incassobureau "Bos Incasso" is gekoppeld via API
- WHEN overdracht-actie draait
- THEN moet het systeem dossier-bundel POST'en naar de Bos-API
- AND moet ladder-status worden "OVERGEDRAGEN_INCASSO"
- AND moet de factuur in shillinq als "in handen incasso" worden gemarkeerd

### Requirement: REQ-CCD-009 Credit-score-integratie optioneel

Indien gekoppeld aan Graydon/Creditsafe moet bij elke nieuwe factuur de creditscore van de klant geraadpleegd worden en bij hoog risico een waarschuwing geven of vooruitbetaling adviseren.

#### Scenario: Lage credit score waarschuwt

- GIVEN klant heeft Graydon-score 2.4 (laag)
- WHEN nieuwe factuur €15.000 wordt opgesteld
- THEN moet waarschuwing verschijnen "Klant heeft lage creditscore; overweeg vooruitbetaling of factor"
- AND mag het systeem alternatief "deelfacturatie" voorstellen

### Requirement: REQ-CCD-010 Oninbare afschrijving met BTW-teruggaaf

Bij definitieve oninbaarheid (faillissement, schuldsanering, oncontroleerbare debiteur na 1 jaar verzuim) moet het systeem een afschrijvingsboeking maken én de BTW-teruggaaf op grond van art. 29 lid 1 OB voorbereiden voor de eerstvolgende BTW-aangifte.

#### Scenario: Afschrijving bij faillissement

- GIVEN klant in staat van faillissement verklaard
- AND factuur €4.200 (incl. €882 BTW) staat 9 maanden open
- WHEN ondernemer "Afschrijven oninbaar" kiest
- THEN moet hoofdsom als bedrijfslast worden geboekt
- AND moet €882 BTW worden voorbereid als teruggaaf in eerstvolgende BTW-aangifte
- AND moet faillissementsvonnis als evidence worden gevraagd

### Requirement: REQ-CCD-011 Templates per stage met merge-velden

Elke stage moet een template hebben met merge-velden (klantnaam, factuurnummer, vervaldatum, openstaand bedrag, IBAN, betaaltermijn, evt. incassokosten/rente).

#### Scenario: Template stage 2 rendert correct

- GIVEN template "Beste {{klantNaam}}, factuur {{factuurNummer}} van {{factuurDatum}} ter waarde van €{{openstaandBedrag}} staat sinds {{vervalDatum}} open..."
- WHEN dunning-run uitvoert
- THEN moet rendered subject/body alle placeholders ingevuld hebben
- AND moet preview vóór verzending mogelijk zijn

### Requirement: REQ-CCD-012 Overheid-specifieke termijnen

Voor klanten met categorie OVERHEID moet het systeem rekening houden met de wettelijke 30-dagen-betaaltermijn (Wet betalingstermijnen overheid) en aanbestedings-specifieke voorwaarden, en de standaard-ladder dienovereenkomstig schalen.

#### Scenario: Overheidsklant met 30-dagen-termijn

- GIVEN klant Gemeente Amsterdam, categorie OVERHEID
- WHEN factuur wordt aangemaakt
- THEN moet vervaltermijn automatisch 30 dagen zijn
- AND moet de overheid-override-ladder worden toegepast (stages op 0/30/60/90)

### Requirement: REQ-CCD-013 Betalingsregeling-onderhandeling

Bij stage 4 (ingebrekestelling) moet het systeem de mogelijkheid bieden om een betalingsregeling te treffen met de debiteur: termijnafspraak, gespreide bedragen, automatische administratie van deelbetalingen.

#### Scenario: 3-termijnen-regeling

- GIVEN debiteur biedt aan €8.400 in 3 maandelijkse termijnen van €2.800 te betalen
- WHEN gebruiker "Betalingsregeling vastleggen" kiest
- THEN moet de ladder pauzeren onder voorwaarde van tijdige termijnbetaling
- AND moet bij wanprestatie van enige termijn automatisch escalatie naar stage 5 plaatsvinden

### Requirement: REQ-CCD-014 Toonregistratie en escalerende formulering

Templates van stage 1 tot 5 moeten een glijdende toonschaal volgen (vriendelijk → zakelijk → formeel → juridisch) en het systeem moet de gebruikte toon expliciet labelen voor de gebruiker.

#### Scenario: Stage 1 vriendelijke toon

- GIVEN stage 1 template wordt gerenderd
- WHEN preview wordt getoond
- THEN moet de tekst beginnen met "Wellicht heeft u onze factuur over het hoofd gezien..."
- AND moet UI-label "Toon: vriendelijk" tonen

#### Scenario: Stage 4 formele toon

- GIVEN stage 4 template wordt gerenderd
- WHEN preview wordt getoond
- THEN moet de tekst formeel zijn: "Hierbij stellen wij u in gebreke..."
- AND moet UI-label "Toon: juridisch" tonen

### Requirement: REQ-CCD-015 Aangetekend versturen via PostNL API

Stage 4 (ingebrekestelling) vereist veelal bewijs van ontvangst; het systeem moet aangetekende post via PostNL Track & Trace-API kunnen versturen en de ontvangstbevestiging als evidence vastleggen.

#### Scenario: Aangetekend versturen ingebrekestelling

- GIVEN stage 4 wordt geactiveerd
- AND PostNL-API gekoppeld
- WHEN aangetekend versturen wordt gekozen
- THEN moet de brief via PostNL-API worden ingeschoten met aangetekend label
- AND moet Track & Trace-code worden vastgelegd in evidence
- AND moet ontvangstbevestiging na bezorging worden gearchiveerd

## Standards & Sources

- Burgerlijk Wetboek art. 6:96 lid 6 (14-dagen-brief), art. 6:119 (wettelijke rente), art. 6:119a (handelsrente B2B), art. 6:83 (verzuim van rechtswege), art. 6:127-141 (verrekening)
- Wet Incassokosten (WIK, "WIK14d") van 1 juli 2012
- Besluit vergoeding voor buitengerechtelijke incassokosten (Stb. 2012, 141) — BIK-staffel
- Wet bestrijding betalingsachterstand (Stb. 2012, 647) — handelsrente
- Wet kwaliteit incassodienstverlening (Wki, 2024) — kwaliteitsvereisten incassobureaus + AFM-toezicht
- Wet betalingstermijnen overheid (Stb. 2017, 226) — 30 dagen termijn
- Wet op de omzetbelasting 1968, art. 29 lid 1 (BTW-teruggaaf oninbaar), art. 29 lid 7 (correctie reeds afgetrokken)
- Aanbestedingswet 2012, ARIV 2018, ARVODI 2018 (Rijksoverheid inkoopvoorwaarden)
- ECB Main Refinancing Rate — basis voor handelsrente (per 1-1-2026: 3,5%, dus handelsrente 11,5%)
- DNB-publicatie wettelijke rente B2C per halfjaar
- Atradius Payment Practices Barometer 2024 (statistische context)
- Wet financieel toezicht (Wft) art. 4:64 e.v. — incassobureau-registratie
- AFM-richtsnoeren incassopraktijk
- AVG (Verordening (EU) 2016/679) art. 6 lid 1f (verwerkingsgrondslag — incasso = gerechtvaardigd belang), art. 28 (verwerkersovereenkomst incassobureau), art. 32 (beveiliging persoonsgegevens)
- ISO 20022 voor SEPA-betalingsinstructies (correcte structuur betalingsverzoek)
- ZZP Pluis-keurmerk en MKB-Servicedesk gedragsregels incasso
- Faillissementswet (Fw) art. 26 e.v. — vorderingen op gefailleerde + WSNP
- Wet schuldsanering natuurlijke personen (Wsnp)

## Cross-app integration

- `bookkeeping-ap-ar` — primaire bron voor openstaande facturen + klantmaster met categorisatie (B2B/B2C/overheid)
- `zzp-cashflow-13wk` — disputed/dunning-status beïnvloedt cashflow-projectie; partial-payments synchroon
- `bookkeeping-btw-aangifte` — BTW-teruggaaf art. 29 OB bij oninbaarheid
- `bookkeeping-ib-aangifte-zzp` — afgeschreven oninbare vorderingen als bedrijfslast in winst-uit-onderneming
- `dba-compliance-marker` — uitsluiting dunning bij DBA-disputes met opdrachtgever; disputes per modelovereenkomst-toets
- `bookkeeping-sepa-direct-debit` — alternatieve betaalmethode i.p.v. dunning (preventief)
- `pipelinq` — klantcommunicatie via primaire CRM-kanaal i.p.v. losse e-mailclient; contact-historie per debiteur
- `openconnector` — outbound integratie met incassobureau-API's (Bos, Atradius Collections, Intrum, GGN), credit-score-API's (Graydon, Creditsafe, Atradius Insights), PostNL-aangetekende-post-API
- `openregister` — file-storage voor evidence (eerste e-mail, ontvangstbevestiging, aanmaning-PDF) met SHA-256-hashes en 7-jaar-retentie
- `docudesk` — PDF-templates en versiebeheer van aanmaningsbrieven en ingebrekestellingen
- `hrmq` — voor MKB-werkgevers die incassoteam coordineren — workflow-management
- `launchpad` — KPI's DSO (Days Sales Outstanding), aging-buckets, success rate per stage

## Target users

- **Primair: ZZP'er met B2B-portfolio** (gemiddelde betalingsachterstand stress). Lijdt het meest onder achterstallige betalingen vanwege beperkte cashbuffer; auto-dunning bespaart wekelijks uren administratieve tijd.
- **Secondair: MKB-ondernemer** (groter volume, behoefte aan automatisering en gedifferentieerde ladder per klantcategorie).
- **Tertiair: Boekhouder / administratiekantoor** met meerdere cliënten in centraal cockpit; meer dan 50 cliënten met dunning maakt handmatig onmogelijk.
- **Tertiair: Credit controller in MKB+** (dedicated FTE die ladders configureert per klant en escalaties bewaakt).
- **Bijzonder: ZZP'er met overheidsklanten** (extended terms maar wettelijk vast; ladder pas vanaf dag-30 in werking).
- **Bijzonder: B2C-ondernemer** (webshop, kleine winkel) — strikt wetshandhaving van 14-dagen-brief bij consumenten, anders geen incassokosten verschuldigd.
- **Bijzonder: Detacheringsbureau** met uurfacturatie waarbij debiteurenrisico hoog is door doorgeefluik-rol.
- **Bijzonder: Coach / trainer** met retainer-modellen die specifiek dunning-templates vereisen voor "vooruitbetaalde diensten".
- **Niet binnen scope**: zuivere consumenten-debiteuren met grote portefeuilles (vereist consumer-credit-licensing dat buiten scope shillinq valt), incassobureaus zelf (zij hebben eigen WSCD-tooling), grootzakelijk met eigen SAP/Oracle-collections (eigen module beschikbaar).

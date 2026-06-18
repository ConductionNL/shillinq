---
status: done
---

# Spec: bookkeeping-bbv-compliance

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1)

## Purpose

This specification defines the requirements for bookkeeping bbv compliance in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: BBV reporting — not browser-testable; referred UI unbuilt


### REQ-BBV-001: RGS-decentraal verplicht voor BBV-tenants

The system SHALL satisfy this requirement: RGS-decentraal verplicht voor BBV-tenants.

Tenants met `bbv_compliance=true` mogen geen grootboekrekening aanmaken of muteren zonder geldige `rgs_decentraal_code`. Bij migratie van een bestaande administratie naar BBV-modus draait een verplichte mappingstap die elke rekening koppelt aan een D-code uit de actuele RGS-decentraal-publicatie (versie wordt vastgelegd in tenant-config). De RGS-decentraal-set wordt jaarlijks geïmporteerd uit de officiële publicatie op referentiegrootboekschema.nl en gevalideerd tegen het hoofdkenmerk (`dc`, `niveau`, `referentienummer`).

#### Scenario: Nieuwe grootboekrekening zonder RGS-koppeling wordt geweigerd

- **GIVEN** tenant `gemeente-lemelerveld` heeft `bbv_compliance=true`
- **WHEN** een gebruiker probeert grootboekrekening `4100 Salarissen` aan te maken zonder `rgs_decentraal_rekening` te zetten
- **THEN** faalt de write met `ValidationError("REQ-BBV-001: rgs_decentraal_rekening verplicht bij bbv_compliance=true")`

#### Scenario: Onbekende RGS-code wordt geweigerd

- **GIVEN** actieve RGS-decentraal versie `2025-v1.0` is geladen
- **WHEN** een import-job poogt rekening `1500` te koppelen aan rgs-code `D.OnbekendeCode`
- **THEN** faalt de import met `ValidationError("REQ-BBV-001: rgs_decentraal_code 'D.OnbekendeCode' bestaat niet in versie 2025-v1.0")` en wordt de rij naar de error-bin geschreven

#### Scenario: Massa-mapping via referentienummer

- **GIVEN** een tenant migreert een legacy decade-grootboekschema
- **WHEN** de admin de auto-map functie start met optie `match-on=referentienummer`
- **THEN** matcht het systeem elke legacy rekening op het 5-niveau referentienummer en presenteert een review-scherm met confidence-score per voorgestelde koppeling

### REQ-BBV-002: Taakveld-classificatie op iedere exploitatie-boeking

The system SHALL satisfy this requirement: Taakveld-classificatie op iedere exploitatie-boeking.

Iedere journaalpostregel met een exploitatie-grootboekrekening moet een `taakveld` en een `economische_categorie` dragen. Het systeem mag default-waarden afleiden van de gekoppelde RGS-decentraal-rekening (zie `taakveld_default`, `economische_categorie_default`), maar de gebruiker kan deze overschrijven binnen de toegestane set voor die rekening. Balansboekingen en boekingen op reserves/voorzieningen zijn vrijgesteld van taakveld-plicht (taakveld 0.10 = "Mutaties reserves" is uitzondering).

#### Scenario: Auto-defaulting taakveld bij boeking

- **GIVEN** grootboekrekening `4310 Onderhoud begraafplaatsen` heeft `taakveld_default=7.5` en `economische_categorie_default=3.4.3`
- **WHEN** een inkoopfactuur wordt geboekt op deze rekening zonder expliciet taakveld
- **THEN** krijgt de boekingsregel automatisch `taakveld=7.5` en `economische_categorie=3.4.3`

#### Scenario: Override binnen toegestane set

- **GIVEN** grootboekrekening `4200 Personeel inhuur` is gekoppeld aan economische categorie `1.2.1`
- **WHEN** een gebruiker probeert deze regel te boeken op taakveld `6.1 Samenkracht en burgerparticipatie`
- **THEN** slaagt de boeking en wordt `taakveld=6.1` vastgelegd, want elk exploitatie-taakveld is toegestaan voor personeelslasten

#### Scenario: Exploitatie-boeking zonder taakveld faalt

- **GIVEN** tenant heeft `bbv_compliance=true`
- **WHEN** journaalpost `JP-2026-0451` wordt aangeboden met een exploitatie-regel zonder `taakveld`
- **THEN** faalt de boeking met `ValidationError("REQ-BBV-002: taakveld verplicht voor bbv_classificatie=exploitatie op regel 3")`

### REQ-BBV-003: Meerjarenraming T+0 t/m T+3 sluitend

The system SHALL satisfy this requirement: Meerjarenraming T+0 t/m T+3 sluitend.

De primitieve begroting voor jaar T moet vergezeld gaan van een meerjarenraming voor T+1, T+2, T+3. Voor elk van de vier jaren geldt de regel **structureel en reëel sluitend**: totaal baten minus totaal lasten plus saldo mutaties reserves >= 0, waarbij incidentele baten en lasten apart worden getoond. Het systeem berekent het saldo per jaar live en blokkeert publicatie van een niet-sluitende begroting (override mogelijk met motivatie + raadsbesluit-referentie).

#### Scenario: Sluitende meerjarenraming wordt vastgesteld

- **GIVEN** gemeente Lemelerveld heeft baten 2026 EUR 92.4M, lasten EUR 91.8M, mutatie reserves +EUR 0.2M
- **WHEN** de begroting voor publicatie wordt aangeboden
- **THEN** toont het systeem `saldo_2026 = +0.8M (sluitend)` en staat publicatie toe

#### Scenario: Tekort T+2 blokkeert publicatie

- **GIVEN** meerjarenraming toont saldo T+2 = -EUR 1.2M
- **WHEN** gebruiker klikt "Publiceer begroting"
- **THEN** blokkeert het systeem met `BBVConstraintError("REQ-BBV-003: jaar 2028 niet sluitend, saldo -1200000 EUR. Voeg dekking toe of motiveer afwijking onder art. 189 Gemeentewet")`

#### Scenario: Incidentele baten apart getoond

- **GIVEN** begroting bevat EUR 2.0M structurele baten en EUR 0.5M incidentele bate verkoop grondpositie
- **WHEN** het systeem het "Overzicht structureel begrotingssaldo" genereert
- **THEN** toont de output baten EUR 1.5M structureel + EUR 0.5M incidenteel, met expliciete sub-totalen conform BBV-art. 19

### REQ-BBV-004: Reserves en voorzieningen — correcte mutatieroute

The system SHALL satisfy this requirement: Reserves en voorzieningen — correcte mutatieroute.

Mutaties op reserves lopen uitsluitend via resultaatbestemming (taakveld 0.10), nooit via een exploitatie-taakveld. Mutaties op voorzieningen lopen wel via exploitatie (last bij toevoeging, vrijval als negatieve last). Het systeem dwingt deze routing bij iedere journaalpost af en blokkeert verkeerde combinaties.

#### Scenario: Storting bestemmingsreserve via 0.10

- **GIVEN** bestemmingsreserve `Onderwijshuisvesting` (balansrekening 2310)
- **WHEN** journaalpost wordt aangeboden: D 2310 EUR 500.000 / C resultaat met `taakveld=4.2`
- **THEN** faalt boeking met `BBVConstraintError("REQ-BBV-004: mutatie reserve 2310 vereist taakveld 0.10, gevonden 4.2")`

#### Scenario: Storting voorziening via exploitatie

- **GIVEN** voorziening `Groot Onderhoud Wegen` (balansrekening 2420) is gekoppeld aan taakveld 2.1
- **WHEN** journaalpost: D 4250 Dotatie voorziening onderhoud / C 2420 met `taakveld=2.1`
- **THEN** slaagt de boeking, want voorziening-dotatie hoort op het gekoppelde exploitatie-taakveld

#### Scenario: Vrijval voorziening als negatieve last

- **GIVEN** voorziening blijkt na actualisatie EUR 80.000 te hoog
- **WHEN** vrijval-boeking: D 2420 EUR 80.000 / C 4250 met `taakveld=2.1`
- **THEN** registreert het systeem dit als negatieve last op taakveld 2.1 in periode-rapportage (niet als bate categorie 8)

### REQ-BBV-005: Materiële vaste activa — afschrijvingsregime per categorie

The system SHALL satisfy this requirement: Materiële vaste activa — afschrijvingsregime per categorie.

MVA worden geadministreerd conform Notitie MVA (commissie BBV, juli 2023). Activa met economisch nut worden geactiveerd tegen aanschafwaarde minus eventuele bijdragen van derden die in directe relatie staan tot het actief; subsidies/bijdragen worden in mindering gebracht. Activa met maatschappelijk nut worden sinds 2017 verplicht geactiveerd (geen netto-methode meer toegestaan). Afschrijving start in de maand volgend op ingebruikname. Componentenmethode is toegestaan voor samengestelde activa (bv schoolgebouw: dak 40jr, installaties 20jr, casco 60jr).

#### Scenario: Subsidie van derden in mindering op aanschaf

- **GIVEN** nieuwe sporthal aanschafwaarde EUR 2.4M met provinciale bijdrage EUR 350k geoormerkt voor dit specifieke object
- **WHEN** het MVA-record wordt opgevoerd
- **THEN** bedraagt de te activeren waarde EUR 2.050.000 en wordt de subsidie als directe vermindering vastgelegd (niet als bate in exploitatie)

#### Scenario: Maatschappelijk nut moet geactiveerd worden

- **GIVEN** nieuwe rondweg EUR 8.4M, categorie maatschappelijk-nut
- **WHEN** gebruiker probeert investering in één keer ten laste van exploitatie te boeken
- **THEN** blokkeert het systeem met `BBVConstraintError("REQ-BBV-005: investering > activeringsgrens, maatschappelijk-nut moet geactiveerd worden conform BBV art. 59 lid 4")`

#### Scenario: Afschrijving start maand na ingebruikname

- **GIVEN** sporthal in gebruik genomen 2026-09-15, afschrijvingstermijn 40 jaar lineair
- **WHEN** maandafsluiting september 2026 draait
- **THEN** geen afschrijvingsboeking; bij oktober-afsluiting wordt eerste afschrijving EUR 4.270,83 (= 2.050.000 / 40 / 12) geboekt op taakveld 5.2

### REQ-BBV-006: Iv3-aanlevering aan CBS — kwartaal en jaar

The system SHALL satisfy this requirement: Iv3-aanlevering aan CBS — kwartaal en jaar.

BBV-pijler is de Iv3-aanlevering aan CBS via Kredo (KRedo voor Decentrale Overheden). Kwartaal-Iv3 binnen 1 maand na kwartaaleinde, jaar-Iv3 voor 15 juli. Bestandsformaat: XBRL conform de jaarlijks gepubliceerde Iv3-taxonomy. Aggregatie: alle boekingen op `boekjaar`, `kwartaal`, `taakveld`, `economische_categorie`. Het systeem genereert het XBRL-instance document, valideert tegen de taxonomy, en biedt het aan via de Kredo SOAP/REST-koppeling.

#### Scenario: Q1-aanlevering genereren

- **GIVEN** gemeente sluit Q1 2026 met EUR 23.1M lasten en EUR 24.5M baten
- **WHEN** admin start "Iv3 Q1 2026 genereren"
- **THEN** produceert het systeem een XBRL-instance met alle taakveld×economische-categorie combinaties, valideert tegen taxonomy `iv3-gem-2026-v1.1`, en toont eventuele schendingen (bv. lege verplichte cellen) in een review-scherm

#### Scenario: Iv3-validatie weigert taakveld 6.72 onder hoofdfunctie 7

- **GIVEN** gebruiker heeft per ongeluk taakveld 6.72 onder hoofdfunctie 7 geplaatst (incorrect, hoort onder 6)
- **WHEN** Iv3-export draait
- **THEN** faalt validatie met `Iv3ValidationError("taakveld 6.72 hoort bij hoofdfunctie 6, niet 7. Corrigeer in Taakveld-stam")`

#### Scenario: Vergelijkende cijfers vorig jaar verplicht in jaar-Iv3

- **GIVEN** jaar-Iv3 2025 wordt gegenereerd in juni 2026
- **WHEN** het XBRL-document wordt opgebouwd
- **THEN** bevat het zowel realisatie 2025 (primair) als realisatie 2024 (vergelijkend) per taakveld×categorie, conform Iv3-informatievoorschrift 2025 §4.2

### REQ-BBV-007: Verplichte paragrafen in begroting en jaarrekening

The system SHALL satisfy this requirement: Verplichte paragrafen in begroting en jaarrekening.

BBV art. 9 schrijft 7 verplichte paragrafen voor: (1) Lokale heffingen, (2) Weerstandsvermogen en risicobeheersing, (3) Onderhoud kapitaalgoederen, (4) Financiering, (5) Bedrijfsvoering, (6) Verbonden partijen, (7) Grondbeleid. Voor provincies geldt een aangepaste set (Wet Fido + provinciale BBV). Voor waterschappen Waterschapsbesluit. Iedere paragraaf is een gestructureerd document met verplichte onderdelen (bv weerstandsvermogen vereist incidenteel + structureel weerstandscapaciteit, risicobedrag, ratio). Het systeem biedt per paragraaf een template-driven editor met velden die automatisch gevuld worden vanuit de administratie (bv weerstandsratio = beschikbaar / benodigd weerstandsvermogen).

#### Scenario: Paragraaf Weerstandsvermogen auto-berekent ratio

- **GIVEN** algemene reserve EUR 12.5M + onbenutte belastingcapaciteit EUR 1.8M = weerstandscapaciteit EUR 14.3M; risicobedrag uit risicoregister EUR 9.6M
- **WHEN** paragraaf wordt opgebouwd
- **THEN** toont het systeem ratio 1.49, klasse "B - ruim voldoende" conform NAR-tabel (Nederlandse Adviesbureau Risicomanagement)

#### Scenario: Paragraaf Verbonden Partijen lijst is compleet

- **GIVEN** gemeente heeft 14 verbonden partijen geregistreerd (4 GR, 3 NV, 7 stichtingen)
- **WHEN** paragraaf gegenereerd wordt
- **THEN** bevat de output per partij: vestigingsplaats, openbaar belang, bestuurlijk + financieel belang, eigen vermogen begin/einde, vreemd vermogen begin/einde, resultaat, risico's, ontwikkelingen — alle verplichte velden uit BBV art. 15

#### Scenario: Ontbrekende paragraaf blokkeert jaarrekening-vaststelling

- **GIVEN** jaarstukken 2025 missen paragraaf Grondbeleid
- **WHEN** ambtenaar klikt "Vaststellen jaarrekening"
- **THEN** blokkeert het systeem met lijst ontbrekende verplichte paragrafen en verwijzing naar BBV-art. 9

### REQ-BBV-008: Vergelijkende periode verplicht

The system SHALL satisfy this requirement: Vergelijkende periode verplicht.

Iedere BBV-conforme rapportage toont minstens vergelijkende cijfers vorig jaar. Jaarrekening jaar T toont kolommen: realisatie T-1, primitieve begroting T, begroting na wijziging T, realisatie T, verschil. Begroting jaar T toont realisatie T-2 (vastgesteld), begroting T-1 (na wijziging tot publicatiedatum), primitieve begroting T, plus meerjarenraming T+1/T+2/T+3.

#### Scenario: Jaarrekening 2025 toont vijf kolommen

- **GIVEN** tenant draait "Jaarrekening 2025 genereren"
- **WHEN** het programmaplan wordt opgebouwd
- **THEN** toont elke programma-tabel kolommen: realisatie 2024, primitieve begroting 2025, begroting na wijziging 2025, realisatie 2025, verschil (analyse > EUR 50k of > 10% wordt toegelicht)

#### Scenario: Stelselwijziging herstelt vergelijkende cijfers

- **GIVEN** een nieuwe taakveld-splitsing is doorgevoerd per 2026 (bv 6.72 gesplitst in 6.72a en 6.72b)
- **WHEN** begroting 2026 wordt opgesteld
- **THEN** herrekent het systeem realisatie 2024 + begroting 2025 naar de nieuwe indeling en markeert deze cijfers met `stelselwijziging=true` voor toelichting

### REQ-BBV-009: Rechtmatigheidsverantwoording per 2023

The system SHALL satisfy this requirement: Rechtmatigheidsverantwoording per 2023.

Sinds boekjaar 2023 geeft het college/GS/DB zelf een rechtmatigheidsverantwoording af bij de jaarrekening (was voorheen taak accountant). Het systeem onderbouwt deze verantwoording door per boeking de rechtmatigheids-aspecten vast te leggen: begrotingsrechtmatigheid (binnen vastgesteld budget?), voorwaarden-rechtmatigheid (conform regelgeving?), M&O-rechtmatigheid (misbruik & oneigenlijk gebruik beheerst?). Bij overschrijdingen wordt automatisch een fout/onzekerheid geregistreerd en getoetst aan de door de raad vastgestelde rapportagegrens en goedkeuringstolerantie (default 1% / 3% van totale lasten).

#### Scenario: Begrotingsoverschrijding genereert rechtmatigheidsfout

- **GIVEN** taakveld 7.2 Riolering heeft begroting EUR 4.2M na wijziging; realisatie EUR 4.48M (overschrijding EUR 280k = 6.7%)
- **WHEN** jaarafsluiting draait
- **THEN** registreert het systeem een rechtmatigheidsfout EUR 280k, toetst aan goedkeuringstolerantie raad (3% van EUR 92M = EUR 2.76M), en toont in rechtmatigheidsoverzicht "binnen tolerantie, geen impact verklaring"

#### Scenario: Onrechtmatige inkoop boven Europese drempel

- **GIVEN** inkoop EUR 285k zonder Europese aanbesteding (drempel diensten 2026: EUR 221k)
- **WHEN** inkoopdossier wordt afgesloten zonder bewijs van aanbestedingsprocedure
- **THEN** registreert het systeem onrechtmatige uitgave EUR 285k in M&O-register en toont waarschuwing in rechtmatigheidsverantwoording-concept

### REQ-BBV-010: SiSa-bijlage genereren

The system SHALL satisfy this requirement: SiSa-bijlage genereren.

Single information Single audit: gemeenten verantwoorden specifieke uitkeringen van het Rijk via de SiSa-bijlage bij de jaarrekening (jaarlijks geactualiseerde tabel van BZK, ca. 50 regelingen). Per regeling vaste indicatorenset (bedragen, aantallen, prestaties). Het systeem genereert SiSa-bijlage uit de subsidie-administratie en toetst volledigheid tegen de actuele SiSa-bijlagenlijst.

#### Scenario: SiSa H8 Combinatiefuncties

- **GIVEN** regeling H8 verstrekt EUR 178k aan combinatiefunctionarissen, ingezet 4.2 FTE in onderwijs en 1.8 FTE in sport
- **WHEN** SiSa-bijlage 2025 wordt gegenereerd
- **THEN** bevat de output regel H8 met indicatoren: besteed bedrag EUR 178.000, aantal FTE onderwijs 4.2, aantal FTE sport 1.8, conform SiSa-bijlage 2025 versie BZK

#### Scenario: Ontvangen rijksbijdrage zonder SiSa-koppeling

- **GIVEN** inkomende beschikking EUR 250k voor regeling "Onderwijsachterstanden" zonder `sisa_indicator` ingevuld
- **WHEN** ambtenaar probeert beschikking te boeken
- **THEN** waarschuwt het systeem: "Mogelijk SiSa-plichtig: regeling Onderwijsachterstanden valt onder SiSa-bijlage code D8. Bevestig of overslaan?"

### REQ-BBV-011: Jaarrekening-export PDF/A en XBRL

The system SHALL satisfy this requirement: Jaarrekening-export PDF/A en XBRL.

De vastgestelde jaarrekening wordt aangeleverd in twee formaten: (a) een leesbaar PDF/A-3 document met alle programma's, paragrafen, balans, overzicht baten/lasten, kasstroomoverzicht en toelichtingen (voor raad/PS/AB en publicatie op overheid.nl), en (b) een XBRL-instance conform de Iv3-taxonomie voor aanlevering aan CBS. PDF wordt opgebouwd uit register-templates met dynamische tabellen; XBRL via dezelfde aggregaties als REQ-BBV-006.

#### Scenario: Vastgestelde jaarrekening publiceren

- **GIVEN** raad heeft jaarrekening 2025 vastgesteld 2026-06-26
- **WHEN** admin klikt "Definitief publiceren"
- **THEN** genereert systeem PDF/A-3 met audit-trail (versienr, hash, vaststellingsdatum, raadsbesluit-ref), XBRL-instance, en plaatst beide in document-archief met retentie 7 jaar

## Seed Data

The following seed data MUST be loaded for all BBV-tenants (gemeente, provincie, waterschap):

1. **bbv-taakvelden-overheidslaag-YYYY.json** — 53 taakvelden for gemeente / 14 for waterschap / andere set for provincie, per Iv3 informatievoorschrift YYYY.
2. **rgs-decentraal-YYYY.json** — RGS D-codes with default taakveld+economische-categorie assignments, per SBR/Logius official publication YYYY.
3. **economische-categorien-YYYY.json** — Iv3 cost-type categories (1-8), per informatievoorschrift YYYY.
4. **beleidsindicatoren-bbv-YYYY.json** — 39 fixed indicators per Regeling Beleidsindicatoren YYYY.

All seed files carry SPDX header and `_meta.iv3Version` tag.

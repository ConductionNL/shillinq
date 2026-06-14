---
status: draft
---

# BBV Compliance for shillinq

## Purpose

This spec adds BBV (Besluit Begroting en Verantwoording provincies en gemeenten) compliance to shillinq, transforming the platform from a generic Dutch bookkeeping ERP into a fully compliant administrative system for **decentrale overheden**: provincies, gemeenten, waterschappen, and gemeenschappelijke regelingen. BBV is a statutory regulation rooted in article 186 of the Gemeentewet, article 190 of the Provinciewet, and article 99 of the Waterschapswet — it dictates *how* every Dutch sub-national government must structure its programmabegroting, jaarrekening, meerjarenraming, and supporting administration. Without BBV compliance a gemeente cannot submit a legally valid jaarrekening to its raad, cannot receive an unqualified accountantsverklaring (rechtmatigheidsverklaring under the 2023 rechtmatigheidsverantwoording rules), and cannot be aggregated by CBS into the Iv3 macro-statistics that feed the gemeentefonds-verdeelsleutel. This spec implements the full BBV apparatus: the mandatory taakveldenstructuur, the RGS-decentraal koppeling, multi-year (T+0 through T+3) budgeting with comparative periods, BBV-conforme classification of investeringen (MVA), reserves and voorzieningen, the verplichte paragrafen, and the document generation pipeline for begroting, tussenrapportage (burap/marap), and jaarstukken.

## Data Model

The spec adds nine new schemas to the shillinq OpenRegister and extends three existing ones. All schemas live in register `bookkeeping-bbv` except where noted.

### Schema: Programma (`programma`)

A Programma is a politically-defined cluster of activities the raad/PS/AB authorizes the college/GS/DB to deliver. Each programma rolls up one or more taakvelden and carries baten, lasten, mutaties reserves, and policy-level indicators.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `nummer` | integer (0-99) | yes | `7` |
| `naam` | string | yes | `"Volksgezondheid en Milieu"` |
| `omschrijving` | text | yes | `"Bevorderen van een gezonde leefomgeving..."` |
| `portefeuillehouder` | string | yes | `"wethouder J. de Vries"` |
| `taakvelden` | ref[Taakveld] | yes | `[7.1, 7.2, 7.3, 7.4, 7.5]` |
| `doelstellingen` | array[object] | yes | `[{"wat":"...","wanneer":"2027","kpi":"..."}]` |
| `beleidsindicatoren` | array[BeleidsIndicator] | yes | BBV-verplichte set per programma |
| `boekjaar` | integer | yes | `2026` |
| `versie` | string | yes | `"begroting"` \| `"jaarrekening"` \| `"burap-1"` |

```json
{
  "nummer": 7,
  "naam": "Volksgezondheid en Milieu",
  "portefeuillehouder": "wethouder J. de Vries",
  "taakvelden": ["7.1", "7.2", "7.3", "7.4", "7.5"],
  "doelstellingen": [
    {"wat": "Reductie restafval naar 100kg/inwoner", "wanneer": "2028", "kpi": "kg_restafval_per_inwoner"}
  ],
  "beleidsindicatoren": [
    {"code": "fijn-huishoudelijk-restafval", "waarde": 142, "eenheid": "kg/inwoner", "bron": "CBS"},
    {"code": "hernieuwbare-elektriciteit", "waarde": 38.2, "eenheid": "%", "bron": "Klimaatmonitor"}
  ]
}
```

### Schema: Taakveld (`taakveld`)

Taakveld is the BBV-verplichte uniforme indeling — 53 taakvelden voor gemeenten (Iv3 2025/2026), 14 voor waterschappen, andere set voor provincies. Codering is **hoofdfunctie.subfunctie** (bv `0.10`, `6.72`, `7.5`). De volledige set is wettelijk vastgelegd in de Regeling vaststelling taakvelden en beleidsindicatoren.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `code` | string (regex `^\d{1,2}\.\d{1,3}$`) | yes | `"7.5"` |
| `naam` | string | yes | `"Begraafplaatsen en crematoria"` |
| `hoofdfunctie` | integer (0-8) | yes | `7` |
| `hoofdfunctie_naam` | string | yes | `"Volksgezondheid en Milieu"` |
| `omschrijving_iv3` | text | yes | letterlijke Iv3-definitie |
| `overheidslaag` | enum | yes | `"gemeente"` \| `"provincie"` \| `"waterschap"` |
| `verplichte_economische_categorieen` | array[string] | no | `["4.1.1","4.3.3"]` |
| `geldig_vanaf` | date | yes | `"2023-01-01"` |
| `geldig_tot` | date | no | `null` |

```json
{
  "code": "6.72",
  "naam": "Maatwerkdienstverlening 18-",
  "hoofdfunctie": 6,
  "hoofdfunctie_naam": "Sociaal Domein",
  "omschrijving_iv3": "Alle individueel toewijsbare jeugdhulp (zorg in natura en PGB) op grond van de Jeugdwet, inclusief jeugd-GGZ, jeugd-LVB, dyslexiezorg en pleegzorg...",
  "overheidslaag": "gemeente",
  "geldig_vanaf": "2025-01-01"
}
```

### Schema: EconomischeCategorie (`economische-categorie`)

Iv3-economische categorieën (kostensoorten) — verplichte tweede dimensie bovenop taakveld. Hoofdcategorieën 1-8 (1=Personeel, 3=Goederen/diensten, 4=Inkomensoverdrachten, 6=Investeringen, etc.) met sub-codes tot 3 niveaus diep.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `code` | string | yes | `"4.3.3"` |
| `naam` | string | yes | `"Inkomensoverdrachten - rijk"` |
| `niveau` | integer | yes | `3` |
| `parent_code` | string | no | `"4.3"` |
| `baten_of_lasten` | enum | yes | `"baten"` \| `"lasten"` \| `"balans"` |
| `iv3_verplicht` | boolean | yes | `true` |

### Schema: RgsDecentraalRekening (`rgs-decentraal-rekening`)

RGS-decentraal is de overheidsspecifieke uitbreiding op RGS 3.7 (regulier MKB-RGS) met de **D-takken** (D-codes) voor decentrale overheden. Iedere grootboekrekening krijgt een RGS-decentraal code die de wettelijke positie van de rekening vastlegt en koppeling enables tussen administratie en Iv3-aanlevering aan CBS.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `rgs_code` | string | yes | `"BBedKstPriOlnEow"` |
| `rgs_decentraal_code` | string | yes | `"D.WLstPerOvhSal"` |
| `omschrijving_kort` | string | yes | `"Salarissen ambtenaren"` |
| `omschrijving_lang` | text | yes | volledige RGS-definitie |
| `referentienummer` | string | yes | `"1.2.01.01.001"` |
| `dc` | enum | yes | `"D"` (debet) \| `"C"` (credit) |
| `rgs_niveau` | integer (1-5) | yes | `4` |
| `taakveld_default` | ref[Taakveld] | no | `"0.4"` |
| `economische_categorie_default` | ref[EconomischeCategorie] | yes | `"1.1.1"` |
| `omslag` | enum | no | `"verplicht"` \| `"toegestaan"` \| `"niet-toegestaan"` |

### Schema: Grootboekrekening (extension)

Bestaande shillinq grootboekrekening wordt uitgebreid:

| Added attribute | Type | Required | Example |
|---|---|---|---|
| `rgs_decentraal_rekening` | ref[RgsDecentraalRekening] | yes (for BBV-tenants) | `"D.WLstPerOvhSal"` |
| `taakveld` | ref[Taakveld] | yes (for exploitatie-rekeningen) | `"6.1"` |
| `economische_categorie` | ref[EconomischeCategorie] | yes | `"1.1.1"` |
| `bbv_classificatie` | enum | yes | `"exploitatie"` \| `"investering"` \| `"reserve"` \| `"voorziening"` \| `"balans-overig"` |

### Schema: MeerjarenBudget (`meerjaren-budget`)

BBV verplicht een meerjarenraming van begrotingsjaar T plus drie jaren (T+1 t/m T+3), alle vier in evenwicht reëel sluitend. Per programma × taakveld × economische categorie × jaar één bedrag voor baten en één voor lasten.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `programma` | ref[Programma] | yes | `"7"` |
| `taakveld` | ref[Taakveld] | yes | `"7.5"` |
| `economische_categorie` | ref[EconomischeCategorie] | yes | `"3.4.3"` |
| `boekjaar` | integer | yes | `2026` |
| `bedrag_baten` | decimal(15,2) | yes | `342500.00` |
| `bedrag_lasten` | decimal(15,2) | yes | `687300.00` |
| `versie` | enum | yes | `"primitief"` \| `"na-wijziging"` \| `"realisatie"` |
| `begrotingswijziging` | ref[Begrotingswijziging] | no | `null` |
| `meerjaren_horizon` | integer (0-3) | yes | `0` |

```json
{
  "programma": "7",
  "taakveld": "7.5",
  "economische_categorie": "3.4.3",
  "boekjaar": 2026,
  "bedrag_baten": 342500.00,
  "bedrag_lasten": 687300.00,
  "versie": "primitief",
  "meerjaren_horizon": 0,
  "toelichting": "Onderhoud begraafplaats Lemelerveld + nieuwe urnenmuur fase 2"
}
```

### Schema: Reserve (`reserve`)

BBV onderscheidt **algemene reserves** (vrij besteedbaar weerstandsvermogen) en **bestemmingsreserves** (door raad geoormerkt voor specifiek doel). Mutaties lopen via resultaatbestemming, niet via exploitatie. Notitie Reserves en Voorzieningen (commissie BBV, herziening 2023) is leidend.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `naam` | string | yes | `"Bestemmingsreserve Onderwijshuisvesting"` |
| `soort` | enum | yes | `"algemeen"` \| `"bestemming"` |
| `doel` | text | yes (bestemming) | `"Dekking IHP 2024-2034"` |
| `raadsbesluit_instelling` | string | yes | `"2024-R-087"` |
| `plafond` | decimal(15,2) | no | `8500000.00` |
| `bodem` | decimal(15,2) | no | `0.00` |
| `looptijd_einde` | date | no | `"2034-12-31"` |
| `programma` | ref[Programma] | no | `"4"` |
| `rentetoerekening` | boolean | yes | `false` |

### Schema: Voorziening (`voorziening`)

Een voorziening is een verplichte buffer voor concrete verplichtingen/risico's waarvan omvang en/of moment onzeker is. BBV art. 44 onderscheidt: (a) verplichtingen/verliezen, (b) gelijkmatige verdeling lasten (onderhoudsvoorzieningen), (c) bijdragen van derden geoormerkt voor specifiek doel (afvalstoffenheffing, rioolheffing), (d) middelen van derden met specifieke aanwendingsverplichting. Mutaties **lopen via exploitatie** (in tegenstelling tot reserves).

| Attribute | Type | Required | Example |
|---|---|---|---|
| `naam` | string | yes | `"Voorziening Groot Onderhoud Wegen"` |
| `bbv_artikel_44_categorie` | enum | yes | `"a"` \| `"b"` \| `"c"` \| `"d"` |
| `onderbouwing_document` | ref[Document] | yes | `"IBOR-beheerplan-2025-2034.pdf"` |
| `actualisatie_frequentie_jaar` | integer | yes | `4` |
| `volgende_actualisatie` | date | yes | `"2029-12-31"` |
| `taakveld` | ref[Taakveld] | yes | `"2.1"` |

### Schema: MaterieleVasteActiva (`materiele-vaste-activa`)

MVA-administratie conform Notitie Materiële Vaste Activa (commissie BBV, juli 2023). Onderscheid investeringen met economisch nut, met economisch nut waarvoor heffing geheven kan worden (riolering/afval), en met maatschappelijk nut (wegen, bruggen, openbaar groen).

| Attribute | Type | Required | Example |
|---|---|---|---|
| `omschrijving` | string | yes | `"Renovatie Sporthal De Spil"` |
| `mva_categorie` | enum | yes | `"economisch-nut"` \| `"economisch-nut-heffing"` \| `"maatschappelijk-nut"` |
| `aanschafwaarde` | decimal(15,2) | yes | `2400000.00` |
| `ingebruikname_datum` | date | yes | `"2026-09-01"` |
| `afschrijvingsmethode` | enum | yes | `"lineair"` \| `"annuitair"` |
| `afschrijvingstermijn_jaar` | integer | yes | `40` |
| `restwaarde` | decimal(15,2) | yes | `0.00` |
| `rente_omslag_percentage` | decimal(5,3) | yes | `1.250` |
| `taakveld` | ref[Taakveld] | yes | `"5.2"` |
| `kredietbesluit` | string | yes | `"2024-R-112"` |
| `componenten_methode` | boolean | yes | `true` |
| `subsidie_van_derden` | decimal(15,2) | no | `350000.00` |

### Schema: Subsidie (`subsidie`)

Subsidieadministratie (verstrekt en ontvangen) conform Algemene wet bestuursrecht titel 4.2 + SiSa-bijlage (Single information Single audit) bij jaarrekening.

| Attribute | Type | Required | Example |
|---|---|---|---|
| `subsidie_soort` | enum | yes | `"verstrekt-incidenteel"` \| `"verstrekt-structureel"` \| `"ontvangen-rijk"` \| `"ontvangen-provincie"` \| `"ontvangen-eu"` |
| `regeling_naam` | string | yes | `"Brede Regeling Combinatiefuncties"` |
| `sisa_indicator` | string | no | `"H8"` |
| `verstrekker_of_ontvanger` | string | yes | `"Stichting Welzijn Lemelerveld"` |
| `beschikking_nummer` | string | yes | `"2026-BWT-04321"` |
| `bedrag_verleend` | decimal(15,2) | yes | `78500.00` |
| `bedrag_vastgesteld` | decimal(15,2) | no | `74250.00` |
| `taakveld` | ref[Taakveld] | yes | `"5.3"` |
| `economische_categorie` | ref[EconomischeCategorie] | yes | `"4.3.5"` |

### Schema: Begrotingswijziging (`begrotingswijziging`)

Iedere wijziging op de vastgestelde primitieve begroting vereist raadsbesluit (gemeente) / PS-besluit (provincie) / AB-besluit (waterschap). Wettelijk vastgelegd in BBV art. 8 en gemeentelijke financiële verordening art. 212 Gemeentewet.

### Schema: BeleidsIndicator (`beleids-indicator`)

BBV art. 25 lid 2 verplicht een set indicatoren per programma. Sinds 2017 zijn er **39 verplichte beleidsindicatoren** (Regeling Beleidsindicatoren) met vaste meeteenheid + bron (CBS, Klimaatmonitor, Waarstaatjegemeente).

## Requirements

### Requirement: REQ-BBV-001 RGS-decentraal verplicht voor BBV-tenants

Tenants met `bbv_compliance=true` mogen geen grootboekrekening aanmaken of muteren zonder geldige `rgs_decentraal_code`. Bij migratie van een bestaande administratie naar BBV-modus draait een verplichte mappingstap die elke rekening koppelt aan een D-code uit de actuele RGS-decentraal-publicatie (versie wordt vastgelegd in tenant-config). De RGS-decentraal-set wordt jaarlijks geïmporteerd uit de officiële publicatie op referentiegrootboekschema.nl en gevalideerd tegen het hoofdkenmerk (`dc`, `niveau`, `referentienummer`).

#### Scenario: Nieuwe grootboekrekening zonder RGS-koppeling wordt geweigerd

- GIVEN tenant `gemeente-lemelerveld` heeft `bbv_compliance=true`
- WHEN een gebruiker probeert grootboekrekening `4100 Salarissen` aan te maken zonder `rgs_decentraal_rekening` te zetten
- THEN faalt de write met `ValidationError("REQ-BBV-001: rgs_decentraal_rekening verplicht bij bbv_compliance=true")`

#### Scenario: Onbekende RGS-code wordt geweigerd

- GIVEN actieve RGS-decentraal versie `2026-v1.0` is geladen
- WHEN een import-job poogt rekening `1500` te koppelen aan rgs-code `D.OnbekendeCode`
- THEN faalt de import met `ValidationError("REQ-BBV-001: rgs_decentraal_code 'D.OnbekendeCode' bestaat niet in versie 2026-v1.0")` en wordt de rij naar de error-bin geschreven

#### Scenario: Massa-mapping via referentienummer

- GIVEN een tenant migreert een legacy decade-grootboekschema
- WHEN de admin de auto-map functie start met optie `match-on=referentienummer`
- THEN matcht het systeem elke legacy rekening op het 5-niveau referentienummer en presenteert een review-scherm met confidence-score per voorgestelde koppeling

### Requirement: REQ-BBV-002 Taakveld-classificatie op iedere exploitatie-boeking

Iedere journaalpostregel met een exploitatie-grootboekrekening moet een `taakveld` en een `economische_categorie` dragen. Het systeem mag default-waarden afleiden van de gekoppelde RGS-decentraal-rekening (zie `taakveld_default`, `economische_categorie_default`), maar de gebruiker kan deze overschrijven binnen de toegestane set voor die rekening. Balansboekingen en boekingen op reserves/voorzieningen zijn vrijgesteld van taakveld-plicht (taakveld 0.10 = "Mutaties reserves" is uitzondering).

#### Scenario: Auto-defaulting taakveld bij boeking

- GIVEN grootboekrekening `4310 Onderhoud begraafplaatsen` heeft `taakveld_default=7.5` en `economische_categorie_default=3.4.3`
- WHEN een inkoopfactuur wordt geboekt op deze rekening zonder expliciet taakveld
- THEN krijgt de boekingsregel automatisch `taakveld=7.5` en `economische_categorie=3.4.3`

#### Scenario: Override binnen toegestane set

- GIVEN grootboekrekening `4200 Personeel inhuur` is gekoppeld aan economische categorie `1.2.1`
- WHEN een gebruiker probeert deze regel te boeken op taakveld `6.1 Samenkracht en burgerparticipatie`
- THEN slaagt de boeking en wordt `taakveld=6.1` vastgelegd, want elk exploitatie-taakveld is toegestaan voor personeelslasten

#### Scenario: Exploitatie-boeking zonder taakveld faalt

- GIVEN tenant heeft `bbv_compliance=true`
- WHEN journaalpost `JP-2026-0451` wordt aangeboden met een exploitatie-regel zonder `taakveld`
- THEN faalt de boeking met `ValidationError("REQ-BBV-002: taakveld verplicht voor bbv_classificatie=exploitatie op regel 3")`

### Requirement: REQ-BBV-003 Meerjarenraming T+0 t/m T+3 sluitend

De primitieve begroting voor jaar T moet vergezeld gaan van een meerjarenraming voor T+1, T+2, T+3. Voor elk van de vier jaren geldt de regel **structureel en reëel sluitend**: totaal baten minus totaal lasten plus saldo mutaties reserves >= 0, waarbij incidentele baten en lasten apart worden getoond. Het systeem berekent het saldo per jaar live en blokkeert publicatie van een niet-sluitende begroting (override mogelijk met motivatie + raadsbesluit-referentie).

#### Scenario: Sluitende meerjarenraming wordt vastgesteld

- GIVEN gemeente Lemelerveld heeft baten 2026 EUR 92.4M, lasten EUR 91.8M, mutatie reserves +EUR 0.2M
- WHEN de begroting voor publicatie wordt aangeboden
- THEN toont het systeem `saldo_2026 = +0.8M (sluitend)` en staat publicatie toe

#### Scenario: Tekort T+2 blokkeert publicatie

- GIVEN meerjarenraming toont saldo T+2 = -EUR 1.2M
- WHEN gebruiker klikt "Publiceer begroting"
- THEN blokkeert het systeem met `BBVConstraintError("REQ-BBV-003: jaar 2028 niet sluitend, saldo -1200000 EUR. Voeg dekking toe of motiveer afwijking onder art. 189 Gemeentewet")`

#### Scenario: Incidentele baten apart getoond

- GIVEN begroting bevat EUR 2.0M structurele baten en EUR 0.5M incidentele bate verkoop grondpositie
- WHEN het systeem het "Overzicht structureel begrotingssaldo" genereert
- THEN toont de output baten EUR 1.5M structureel + EUR 0.5M incidenteel, met expliciete sub-totalen conform BBV-art. 19

### Requirement: REQ-BBV-004 Reserves en voorzieningen — correcte mutatieroute

Mutaties op reserves lopen uitsluitend via resultaatbestemming (taakveld 0.10), nooit via een exploitatie-taakveld. Mutaties op voorzieningen lopen wel via exploitatie (last bij toevoeging, vrijval als negatieve last). Het systeem dwingt deze routing bij iedere journaalpost af en blokkeert verkeerde combinaties.

#### Scenario: Storting bestemmingsreserve via 0.10

- GIVEN bestemmingsreserve `Onderwijshuisvesting` (balansrekening 2310)
- WHEN journaalpost wordt aangeboden: D 2310 EUR 500.000 / C resultaat met `taakveld=4.2`
- THEN faalt boeking met `BBVConstraintError("REQ-BBV-004: mutatie reserve 2310 vereist taakveld 0.10, gevonden 4.2")`

#### Scenario: Storting voorziening via exploitatie

- GIVEN voorziening `Groot Onderhoud Wegen` (balansrekening 2420) is gekoppeld aan taakveld 2.1
- WHEN journaalpost: D 4250 Dotatie voorziening onderhoud / C 2420 met `taakveld=2.1`
- THEN slaagt de boeking, want voorziening-dotatie hoort op het gekoppelde exploitatie-taakveld

#### Scenario: Vrijval voorziening als negatieve last

- GIVEN voorziening blijkt na actualisatie EUR 80.000 te hoog
- WHEN vrijval-boeking: D 2420 EUR 80.000 / C 4250 met `taakveld=2.1`
- THEN registreert het systeem dit als negatieve last op taakveld 2.1 in periode-rapportage (niet als bate categorie 8)

### Requirement: REQ-BBV-005 Materiële vaste activa — afschrijvingsregime per categorie

MVA worden geadministreerd conform Notitie MVA (commissie BBV, juli 2023). Activa met economisch nut worden geactiveerd tegen aanschafwaarde minus eventuele bijdragen van derden die in directe relatie staan tot het actief; subsidies/bijdragen worden in mindering gebracht. Activa met maatschappelijk nut worden sinds 2017 verplicht geactiveerd (geen netto-methode meer toegestaan). Afschrijving start in de maand volgend op ingebruikname. Componentenmethode is toegestaan voor samengestelde activa (bv schoolgebouw: dak 40jr, installaties 20jr, casco 60jr).

#### Scenario: Subsidie van derden in mindering op aanschaf

- GIVEN nieuwe sporthal aanschafwaarde EUR 2.4M met provinciale bijdrage EUR 350k geoormerkt voor dit specifieke object
- WHEN het MVA-record wordt opgevoerd
- THEN bedraagt de te activeren waarde EUR 2.050.000 en wordt de subsidie als directe vermindering vastgelegd (niet als bate in exploitatie)

#### Scenario: Maatschappelijk nut moet geactiveerd worden

- GIVEN nieuwe rondweg EUR 8.4M, categorie maatschappelijk-nut
- WHEN gebruiker probeert investering in één keer ten laste van exploitatie te boeken
- THEN blokkeert het systeem met `BBVConstraintError("REQ-BBV-005: investering > activeringsgrens, maatschappelijk-nut moet geactiveerd worden conform BBV art. 59 lid 4")`

#### Scenario: Afschrijving start maand na ingebruikname

- GIVEN sporthal in gebruik genomen 2026-09-15, afschrijvingstermijn 40 jaar lineair
- WHEN maandafsluiting september 2026 draait
- THEN geen afschrijvingsboeking; bij oktober-afsluiting wordt eerste afschrijving EUR 4.270,83 (= 2.050.000 / 40 / 12) geboekt op taakveld 5.2

### Requirement: REQ-BBV-006 Iv3-aanlevering aan CBS — kwartaal en jaar

BBV-pijler is de Iv3-aanlevering aan CBS via Kredo (KRedo voor Decentrale Overheden). Kwartaal-Iv3 binnen 1 maand na kwartaaleinde, jaar-Iv3 voor 15 juli. Bestandsformaat: XBRL conform de jaarlijks gepubliceerde Iv3-taxonomy. Aggregatie: alle boekingen op `boekjaar`, `kwartaal`, `taakveld`, `economische_categorie`. Het systeem genereert het XBRL-instance document, valideert tegen de taxonomy, en biedt het aan via de Kredo SOAP/REST-koppeling.

#### Scenario: Q1-aanlevering genereren

- GIVEN gemeente sluit Q1 2026 met EUR 23.1M lasten en EUR 24.5M baten
- WHEN admin start "Iv3 Q1 2026 genereren"
- THEN produceert het systeem een XBRL-instance met alle taakveld×economische-categorie combinaties, valideert tegen taxonomy `iv3-gem-2026-v1.1`, en toont eventuele schendingen (bv. lege verplichte cellen) in een review-scherm

#### Scenario: Iv3-validatie weigert taakveld 6.72 onder hoofdfunctie 7

- GIVEN gebruiker heeft per ongeluk taakveld 6.72 onder hoofdfunctie 7 geplaatst (incorrect, hoort onder 6)
- WHEN Iv3-export draait
- THEN faalt validatie met `Iv3ValidationError("taakveld 6.72 hoort bij hoofdfunctie 6, niet 7. Corrigeer in Taakveld-stam")`

#### Scenario: Vergelijkende cijfers vorig jaar verplicht in jaar-Iv3

- GIVEN jaar-Iv3 2025 wordt gegenereerd in juni 2026
- WHEN het XBRL-document wordt opgebouwd
- THEN bevat het zowel realisatie 2025 (primair) als realisatie 2024 (vergelijkend) per taakveld×categorie, conform Iv3-informatievoorschrift 2025 §4.2

### Requirement: REQ-BBV-007 Verplichte paragrafen in begroting en jaarrekening

BBV art. 9 schrijft 7 verplichte paragrafen voor: (1) Lokale heffingen, (2) Weerstandsvermogen en risicobeheersing, (3) Onderhoud kapitaalgoederen, (4) Financiering, (5) Bedrijfsvoering, (6) Verbonden partijen, (7) Grondbeleid. Voor provincies geldt een aangepaste set (Wet Fido + provinciale BBV). Voor waterschappen Waterschapsbesluit. Iedere paragraaf is een gestructureerd document met verplichte onderdelen (bv weerstandsvermogen vereist incidenteel + structureel weerstandscapaciteit, risicobedrag, ratio). Het systeem biedt per paragraaf een template-driven editor met velden die automatisch gevuld worden vanuit de administratie (bv weerstandsratio = beschikbaar / benodigd weerstandsvermogen).

#### Scenario: Paragraaf Weerstandsvermogen auto-berekent ratio

- GIVEN algemene reserve EUR 12.5M + onbenutte belastingcapaciteit EUR 1.8M = weerstandscapaciteit EUR 14.3M; risicobedrag uit risicoregister EUR 9.6M
- WHEN paragraaf wordt opgebouwd
- THEN toont het systeem ratio 1.49, klasse "B - ruim voldoende" conform NAR-tabel (Nederlandse Adviesbureau Risicomanagement)

#### Scenario: Paragraaf Verbonden Partijen lijst is compleet

- GIVEN gemeente heeft 14 verbonden partijen geregistreerd (4 GR, 3 NV, 7 stichtingen)
- WHEN paragraaf gegenereerd wordt
- THEN bevat de output per partij: vestigingsplaats, openbaar belang, bestuurlijk + financieel belang, eigen vermogen begin/einde, vreemd vermogen begin/einde, resultaat, risico's, ontwikkelingen — alle verplichte velden uit BBV art. 15

#### Scenario: Ontbrekende paragraaf blokkeert jaarrekening-vaststelling

- GIVEN jaarstukken 2025 missen paragraaf Grondbeleid
- WHEN ambtenaar klikt "Vaststellen jaarrekening"
- THEN blokkeert het systeem met lijst ontbrekende verplichte paragrafen en verwijzing naar BBV-art. 9

### Requirement: REQ-BBV-008 Vergelijkende periode verplicht

Iedere BBV-conforme rapportage toont minstens vergelijkende cijfers vorig jaar. Jaarrekening jaar T toont kolommen: realisatie T-1, primitieve begroting T, begroting na wijziging T, realisatie T, verschil. Begroting jaar T toont realisatie T-2 (vastgesteld), begroting T-1 (na wijziging tot publicatiedatum), primitieve begroting T, plus meerjarenraming T+1/T+2/T+3.

#### Scenario: Jaarrekening 2025 toont vijf kolommen

- GIVEN tenant draait "Jaarrekening 2025 genereren"
- WHEN het programmaplan wordt opgebouwd
- THEN toont elke programma-tabel kolommen: realisatie 2024, primitieve begroting 2025, begroting na wijziging 2025, realisatie 2025, verschil (analyse > EUR 50k of > 10% wordt toegelicht)

#### Scenario: Stelselwijziging herstelt vergelijkende cijfers

- GIVEN een nieuwe taakveld-splitsing is doorgevoerd per 2026 (bv 6.72 gesplitst in 6.72a en 6.72b)
- WHEN begroting 2026 wordt opgesteld
- THEN herrekent het systeem realisatie 2024 + begroting 2025 naar de nieuwe indeling en markeert deze cijfers met `stelselwijziging=true` voor toelichting

### Requirement: REQ-BBV-009 Rechtmatigheidsverantwoording per 2023

Sinds boekjaar 2023 geeft het college/GS/DB zelf een rechtmatigheidsverantwoording af bij de jaarrekening (was voorheen taak accountant). Het systeem onderbouwt deze verantwoording door per boeking de rechtmatigheids-aspecten vast te leggen: begrotingsrechtmatigheid (binnen vastgesteld budget?), voorwaarden-rechtmatigheid (conform regelgeving?), M&O-rechtmatigheid (misbruik & oneigenlijk gebruik beheerst?). Bij overschrijdingen wordt automatisch een fout/onzekerheid geregistreerd en getoetst aan de door de raad vastgestelde rapportagegrens en goedkeuringstolerantie (default 1% / 3% van totale lasten).

#### Scenario: Begrotingsoverschrijding genereert rechtmatigheidsfout

- GIVEN taakveld 7.2 Riolering heeft begroting EUR 4.2M na wijziging; realisatie EUR 4.48M (overschrijding EUR 280k = 6.7%)
- WHEN jaarafsluiting draait
- THEN registreert het systeem een rechtmatigheidsfout EUR 280k, toetst aan goedkeuringstolerantie raad (3% van EUR 92M = EUR 2.76M), en toont in rechtmatigheidsoverzicht "binnen tolerantie, geen impact verklaring"

#### Scenario: Onrechtmatige inkoop boven Europese drempel

- GIVEN inkoop EUR 285k zonder Europese aanbesteding (drempel diensten 2026: EUR 221k)
- WHEN inkoopdossier wordt afgesloten zonder bewijs van aanbestedingsprocedure
- THEN registreert het systeem onrechtmatige uitgave EUR 285k in M&O-register en toont waarschuwing in rechtmatigheidsverantwoording-concept

### Requirement: REQ-BBV-010 SiSa-bijlage genereren

Single information Single audit: gemeenten verantwoorden specifieke uitkeringen van het Rijk via de SiSa-bijlage bij de jaarrekening (jaarlijks geactualiseerde tabel van BZK, ca. 50 regelingen). Per regeling vaste indicatorenset (bedragen, aantallen, prestaties). Het systeem genereert SiSa-bijlage uit de subsidie-administratie en toetst volledigheid tegen de actuele SiSa-bijlagenlijst.

#### Scenario: SiSa H8 Combinatiefuncties

- GIVEN regeling H8 verstrekt EUR 178k aan combinatiefunctionarissen, ingezet 4.2 FTE in onderwijs en 1.8 FTE in sport
- WHEN SiSa-bijlage 2025 wordt gegenereerd
- THEN bevat de output regel H8 met indicatoren: besteed bedrag EUR 178.000, aantal FTE onderwijs 4.2, aantal FTE sport 1.8, conform SiSa-bijlage 2025 versie BZK

#### Scenario: Ontvangen rijksbijdrage zonder SiSa-koppeling

- GIVEN inkomende beschikking EUR 250k voor regeling "Onderwijsachterstanden" zonder `sisa_indicator` ingevuld
- WHEN ambtenaar probeert beschikking te boeken
- THEN waarschuwt het systeem: "Mogelijk SiSa-plichtig: regeling Onderwijsachterstanden valt onder SiSa-bijlage code D8. Bevestig of overslaan?"

### Requirement: REQ-BBV-011 Jaarrekening-export PDF/A en XBRL

De vastgestelde jaarrekening wordt aangeleverd in twee formaten: (a) een leesbaar PDF/A-3 document met alle programma's, paragrafen, balans, overzicht baten/lasten, kasstroomoverzicht en toelichtingen (voor raad/PS/AB en publicatie op overheid.nl), en (b) een XBRL-instance conform de Iv3-taxonomie voor aanlevering aan CBS. PDF wordt opgebouwd uit register-templates met dynamische tabellen; XBRL via dezelfde aggregaties als REQ-BBV-006.

#### Scenario: Vastgestelde jaarrekening publiceren

- GIVEN raad heeft jaarrekening 2025 vastgesteld 2026-06-26
- WHEN admin klikt "Definitief publiceren"
- THEN genereert systeem PDF/A-3 met audit-trail (versienr, hash, vaststellingsdatum, raadsbesluit-ref), XBRL-instance, en plaatst beide in document-archief met retentie 7 jaar

## Standards & Sources

- **BBV** (Besluit Begroting en Verantwoording provincies en gemeenten) — Stb. 2003, 27, laatst gewijzigd 2024
- **Notitie Materiële Vaste Activa** (commissie BBV, juli 2023)
- **Notitie Reserves en Voorzieningen** (commissie BBV, herziening 2023)
- **Notitie Grondbeleid in begroting en jaarstukken** (commissie BBV, 2023)
- **Notitie Lokale heffingen** (commissie BBV)
- **Notitie Verbonden Partijen** (commissie BBV, 2024)
- **RGS-decentraal** (Referentie GrootboekSchema voor decentrale overheden, beheerd door SBR/Logius) — jaarlijkse release
- **Iv3-informatievoorschrift Gemeenten en GRen** (Ministerie BZK, jaarlijks) — actueel: versie 2026 1.0
- **Iv3-informatievoorschrift Provincies** (BZK)
- **Iv3-informatievoorschrift Waterschappen** (BZK / Unie van Waterschappen)
- **Regeling vaststelling taakvelden en beleidsindicatoren**
- **SiSa-bijlage** (BZK, jaarlijks geactualiseerd)
- **ENSIA** (Eenduidige Normatiek Single Information Audit) — niet financieel maar wel jaarlijkse betrouwbaarheidsverantwoording die parallel aan jaarrekening loopt
- **Gemeentewet** art. 186-213 (financieel beheer en verantwoording)
- **Provinciewet** art. 190-217
- **Waterschapswet** art. 99-109
- **Wet Fido** (Wet financiering decentrale overheden) — kasgeldlimiet, renterisiconorm
- **BADO** (Besluit Accountantscontrole Decentrale Overheden) — controleprotocol accountant
- **Rechtmatigheidsverantwoording** (sinds boekjaar 2023, Kadernota Rechtmatigheid 2024)
- **Kredo** (Kring Decentrale Overheden) — CBS-aanleverportaal

## Cross-app integration

- **bookkeeping-general-ledger** (shillinq core): de Grootboekrekening-schema extension is een additieve laag — niet-BBV-tenants raken niets. BBV-tenants krijgen taakveld + RGS-decentraal als verplichte velden. Boekingen blijven journaalposten met dezelfde double-entry validatie.
- **bookkeeping-cost-centers-dimensions**: taakveld en programma worden geregistreerd als extra dimensies bovenop de generieke kostenplaats/kostendrager-set. De dimensies zijn hiërarchisch (programma → taakveld → grootboekrekening) zodat aggregatie en drill-down werken zonder herberekening.
- **bookkeeping-iv3-reporting**: aparte spec voor de XBRL-generatie en Kredo-koppeling; deze BBV-spec levert de gevalideerde data, iv3-reporting levert de transportlaag (XBRL-taxonomie, signing, KvK-aanlevering, Kredo SOAP-call).
- **bookkeeping-procurement-rechtmatigheid**: aparte spec voor inkoop + aanbestedingsrechtmatigheid; deze BBV-spec consumeert de M&O-fouten en aanbestedingsfouten in de rechtmatigheidsverantwoording (REQ-BBV-009).
- **bookkeeping-subsidie-management**: aparte spec voor de volledige Awb 4.2 subsidie-cyclus (aanvraag → verlening → vaststelling → terugvordering); deze BBV-spec consumeert verleende/vastgestelde bedragen voor SiSa (REQ-BBV-010) en exploitatie-boekingen.
- **bookkeeping-grondexploitatie**: aparte spec voor BIE (Bouwgronden in Exploitatie) administratie conform Notitie Grondbeleid; deze BBV-spec consumeert de eindwaarde-berekeningen voor de paragraaf Grondbeleid.
- **decidesk** (raadsinformatie): jaarstukken en begroting worden via ADR-019 integration-registry beschikbaar gesteld als agendapunten in decidesk; raadsbesluiten over begrotingswijzigingen en jaarrekening-vaststelling worden terug-gesynchroniseerd naar Begrotingswijziging-records.
- **docudesk** (document management): PDF/A-3 jaarrekening en XBRL-instance worden gearchiveerd in docudesk met retentieklasse "financiele-verantwoording-7jr" conform Archiefwet.
- **openconnector**: Kredo-aanlevering, RGS-decentraal-import, SiSa-bijlage import van BZK, en CBS-aanlevering lopen alle via openconnector-sources zodat de protocol-laag (SOAP, REST, XBRL) buiten shillinq blijft.
- **openregister**: alle schemas worden geregistreerd in een dedicated `bookkeeping-bbv` register met multi-tenant scoping (tenant = gemeente/provincie/waterschap); RGS-decentraal stamtabel is shared register (`bookkeeping-bbv-reference`) want identiek voor alle tenants.

## Target users

- **Financieel medewerker gemeente** (taakniveau MBO-4/HBO, ca. 1-15 FTE per gemeente) — dagelijks boeken, factuurverwerking, controleren taakveld-toewijzing, eerste-aanleg meerjarenraming bewerken. Primaire UI-gebruiker, vereist Nederlandstalige interface met BBV-jargon en tooltips bij ieder verplicht veld.
- **Controller / concerncontroller** (HBO/WO, 1-5 FTE) — bewaakt begrotingsrechtmatigheid, weerstandsvermogen, paragrafen, kwartaalrapportages. Werkt in BI-views met drill-down van programma naar taakveld naar boeking. Verantwoordelijk voor sluitend krijgen meerjarenraming.
- **Financieel beleidsmedewerker / strateeg** — stelt programmabegroting op, formuleert doelen en indicatoren, coördineert paragrafen met vakafdelingen.
- **Hoofd Financiën / directeur Middelen** — eindverantwoordelijk voor jaarrekening en rechtmatigheidsverantwoording; tekent uit en levert aan college.
- **Accountant** (Big-4 of regionale registeraccountant met decentrale-overheden-expertise: Deloitte, BDO, Baker Tilly, Astrium) — leest mee tijdens interim- en eindcontrole, downloadt detail-grootboekslagen, controleert SiSa-bijlage, beoordeelt rechtmatigheidsverantwoording. Vereist read-only auditor-rol met export-rechten naar Excel + audit-trail per boeking.
- **Raadslid / Statenlid / AB-lid** (politiek, geen financiële achtergrond meestal) — leest programmabegroting en jaarstukken; kan in publieks-portal door programma's klikken en KPI's bekijken. Geen directe shillinq-toegang, consumeert via decidesk + publieks-portal (overheid.nl-style).
- **Burger** — leest gepubliceerde jaarstukken op overheid.nl + lokaal portaal; geen authenticatie nodig voor publiek-vastgestelde stukken.
- **CBS / BZK** — ontvangen Iv3-aanlevering machine-to-machine via Kredo; geen UI-toegang.
- **Provincie als toezichthouder** (financieel toezicht op gemeenten via art. 203 Gemeentewet) — leest jaarrekening + begroting, beoordeelt of repressief of preventief toezicht van toepassing is; consumeert via gepubliceerde jaarstukken.
- **Gemeenschappelijke regeling (penningmeester)** — kleinere variant van gemeente-flow met soms eigen taakveld-subset (bv. veiligheidsregio enkel hoofdfunctie 1, GGD enkel hoofdfunctie 7), maar verder identieke BBV-plicht.
- **Waterschap (afdeling Financiën, Hoogheemraadschap-controller)** — afwijkende set programma's (typisch: Waterveiligheid, Watersysteem, Waterketen, Bestuur) en eigen taakveldenset; deze spec ondersteunt overheidslaag-discriminator zodat één codebasis alle drie types serveert.

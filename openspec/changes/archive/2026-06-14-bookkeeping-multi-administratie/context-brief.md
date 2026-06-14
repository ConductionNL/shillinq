---
status: draft
---
# Multi-administratie (Holding + werkmij, multi-tenant)

## Purpose

Voor het overgrote deel van de Nederlandse MKB-markt — en voor vrijwel iedere accountants- of administratiekantoor-klant — is de single-administratie-aanname onhoudbaar. Een gemiddelde MKB-onderneming met groeiambities is georganiseerd in een holdingstructuur: een personal holding (Beheer B.V.) bezit de aandelen van een werkmaatschappij (Werk B.V.), die op haar beurt mogelijk weer dochters bezit. Iedere B.V. heeft een eigen KvK-nummer, eigen loonheffingsnummer (indien personeel), eigen BTW-nummer, eigen IB-aangifte (bij IB-ondernemer) of VPB-aangifte (bij rechtspersoon), eigen jaarrekening met KvK-deponering, eigen bankrekeningen en eigen administratie. Daarboven publiceert de holding optioneel een geconsolideerde jaarrekening waarin intercompany-mutaties zijn geëlimineerd.

Voor decentrale overheden geldt een vergelijkbare maar andere logica: een gemeente kan optreden als penvoerder voor een gemeenschappelijke regeling, kan deelnemen in een verbonden partij (NV/BV/stichting), exploiteren grondbedrijf als afzonderlijke administratie, en moeten verschillende geldstromen (algemene dienst, grondexploitatie, sociaal domein) administratief gescheiden bijgehouden worden binnen één institutionele wettelijke entiteit. Een goede multi-administratie-architectuur faciliteert beide werelden.

Op dit moment kent shillinq één administratie per installatie. Voor een accountantskantoor met 200 klanten betekent dat 200 OpenRegister-instanties, 200 backups, 200 keer beheer, en geen mogelijkheid om bijvoorbeeld een holding-werkmij geconsolideerd te zien. Dit is een fundamentele beperking. Deze brief beschrijft een refactoring van shillinq waarbij `administratie` een eerste-klas tenancy-construct wordt binnen één OpenRegister-installatie, met per-administratie chart-of-accounts, boekjaar, valuta, BTW-regime, gebruikers-rechten, en backup-/exportniveau. Tegelijk worden intercompany-journaalposten en consolidatie-eliminatie-hooks geïntroduceerd.

Het is expliciet géén doel om volledige geconsolideerde jaarrekening-rendering te leveren in deze brief — dat is een aparte spec (`bookkeeping-consolidatie`). Het doel hier is dat shillinq de fundamenten heeft waarop consolidatie veilig kan draaien: gescheiden boekingen, sluitende intercompany-stromen, en gestandaardiseerde mapping naar consolidatie-hoofdrekeningen. Daarnaast moet migratie tussen administraties mogelijk zijn (een activum verplaatsen van werkmaatschappij A naar werkmaatschappij B bij interne herstructurering) zonder de audit-trail te verbreken.

Voor multi-tenant accountantsgebruik wordt de administratie ook de hoofd-isolatie-grens voor autorisatie: een gebruiker krijgt expliciet toegang tot één of meerdere administraties met een rol per administratie. Een holding-controller kan beide administraties zien; een externe accountant slechts één klantadministratie; de partner van het kantoor alle. Daarnaast moet de gebruikersinterface een snelle switcher bieden, vergelijkbaar met de organisatie-switcher in moderne SaaS-tools, zodat een controller die voor drie B.V.'s werkt niet hoeft uit te loggen.

Deze brief beschrijft een nieuw register `administraties` met schema's `administratie`, `administratie_lidmaatschap`, `intercompany_journaalpost`, `consolidatie_mapping`, `administratie_migratie`, en uitbreidingen op alle bestaande financiële schema's met een verplicht `administratie`-veld.

## Data Model (entities + Dutch JSON)

### Schema `administratie`
Eén entiteit per juridisch zelfstandige boekhouding.

```json
{
  "id": "uuid",
  "administratiecode": "WERK-001",
  "naam": "Voorbeeld Werk B.V.",
  "rechtsvorm": "bv|nv|eenmanszaak|vof|stichting|vereniging|cooperatie|gemeente|provincie|gr|overig",
  "kvk_nummer": "12345678",
  "rsin": "001234567",
  "btw_nummer": "NL001234567B01",
  "loonheffingsnummer": "001234567L01",
  "fiscaal_nummer": "001234567",
  "fiscale_eenheid_vpb": "uuid|nullable|ref:administratie",
  "fiscale_eenheid_btw": "uuid|nullable|ref:administratie",
  "bezoekadres": {"straat": "Voorbeeldstraat 1", "postcode": "1000AA", "plaats": "Amsterdam"},
  "iban_primair": "NL91ABNA0417164300",
  "moederadministratie": "uuid|nullable|ref:administratie",
  "dochters": ["uuid"],
  "boekjaar_start_maand": 1,
  "boekjaar_start_dag": 1,
  "afwijkend_boekjaar": false,
  "presentatievaluta": "EUR",
  "functionele_valuta": "EUR",
  "btw_regime": "standaard|kleine_ondernemers_regeling|landbouw|reisbureau|vrijgesteld|overheid",
  "btw_aangifte_frequentie": "maand|kwartaal|jaar",
  "chart_of_accounts": "uuid|ref:rekeningschema",
  "consolidatie_mapping": "uuid|nullable|ref:consolidatie_mapping",
  "consolideren_in": "uuid|nullable|ref:administratie",
  "consolidatiemethode": "integraal|proportioneel|equity|niet_consolideren",
  "actief_vanaf": "2024-01-01",
  "actief_tot": null,
  "status": "actief|gearchiveerd|in_liquidatie|opgeheven",
  "backup_schema": "dagelijks|wekelijks|aanvragen",
  "data_retentie_jaren": 7,
  "default_taal": "nl",
  "logo_bestand": "file:uuid|nullable"
}
```

### Schema `administratie_lidmaatschap`
Koppelt een gebruiker aan een administratie met een rol.

```json
{
  "id": "uuid",
  "gebruiker": "uuid",
  "administratie": "uuid",
  "rol": "eigenaar|controller|boekhouder|inkijker|accountant_extern|salarisadministrateur|debiteurenadmin|crediteurenadmin",
  "toegangsbeperking_grootboek": ["4000-4999", "8000-8999"],
  "mag_journaalposten_boeken": true,
  "mag_jaarafsluiting_doen": false,
  "geldig_van": "2026-01-01",
  "geldig_tot": null,
  "verleend_door": "uuid",
  "verleend_op": "2025-12-15T10:00:00Z"
}
```

### Schema `intercompany_journaalpost`
Gekoppelde journaalpost tussen twee administraties.

```json
{
  "id": "uuid",
  "intercompany_nummer": "IC-2026-00042",
  "datum": "2026-06-30",
  "soort": "doorbelasting|dividend|lening|aandelenkapitaal|rente|huur|management_fee|overig",
  "bron_administratie": "uuid",
  "doel_administratie": "uuid",
  "bron_journaalpost": "uuid",
  "doel_journaalpost": "uuid",
  "bedrag": 25000.00,
  "valuta": "EUR",
  "wisselkoers": 1.0000,
  "omschrijving": "Management fee Q2 2026 van Werk B.V. aan Beheer B.V.",
  "btw_behandeling": "verlegd|standaard|fiscale_eenheid_geen_btw",
  "geconsolideerd_elimineren": true,
  "eliminatie_rekening": "9999",
  "status": "concept|gekoppeld|bevestigd_beide|eliminatie_geboekt",
  "afwijking_bedrag": 0.00
}
```

### Schema `consolidatie_mapping`
Vertaalt de chart-of-accounts van een dochter naar die van de moeder voor consolidatiedoeleinden.

```json
{
  "id": "uuid",
  "naam": "Mapping WERK-001 naar HOLDING-001",
  "bron_administratie": "uuid",
  "doel_administratie": "uuid",
  "regels": [
    {"bron_rekening": "4310", "doel_rekening": "4300", "omschrijving": "ICT-kosten consolidatie"},
    {"bron_rekening": "4311", "doel_rekening": "4300", "omschrijving": "ICT-licenties consolidatie"}
  ],
  "eliminatie_rekening_intercompany": "9999",
  "valutaomrekening_methode": "slotkoers|gemiddelde|historisch",
  "geldig_van": "2026-01-01"
}
```

### Schema `administratie_migratie`
Vastlegging van een asset/post-overdracht tussen administraties met audit-trail.

```json
{
  "id": "uuid",
  "migratienummer": "MIG-2026-007",
  "datum": "2026-09-01",
  "bron_administratie": "uuid",
  "doel_administratie": "uuid",
  "soort": "vaste_activa|debiteur|crediteur|werknemer|contract|overig",
  "objecten": ["uuid", "uuid"],
  "boekwaarde_overdracht": 87000.00,
  "marktwaarde_overdracht": 92000.00,
  "verschil_naar_resultaat": 5000.00,
  "fiscale_behandeling": "geruisloze_doorschuiving|met_realisatie|fiscale_eenheid",
  "juridische_grondslag": "Akte van inbreng dd 2026-08-15 notaris X",
  "documenten": ["file:uuid"],
  "bron_journaalpost": "uuid",
  "doel_journaalpost": "uuid",
  "status": "voorbereid|uitgevoerd|geboekt_beide|teruggedraaid"
}
```

### Uitbreiding bestaande schema's
Alle financiële schema's (`journaalpost`, `factuur`, `crediteur`, `debiteur`, `grootboekrekening`, `budget`, `verplichting`, `vast_actief`) krijgen verplicht veld:

```json
{
  "administratie": "uuid|ref:administratie"
}
```

## Requirements

### REQ-MA-001: Administratie-isolatie van alle financiële data
Geen enkele financiële entiteit mag zonder `administratie`-veld bestaan; queries respecteren altijd de actieve administratie-context.

- GIVEN een gebruiker met toegang tot administraties WERK-001 en BEHEER-001, WHEN deze in context WERK-001 een journaalpost-query doet, THEN ziet deze uitsluitend journaalposten van WERK-001 en geen enkele van BEHEER-001.
- GIVEN een gebruiker zonder toegang tot een specifieke administratie, WHEN deze een ID van een journaalpost van die administratie probeert te benaderen, THEN ontvangt deze een 404 (geen 403, om bestaan te maskeren).
- GIVEN een poging een journaalpost te boeken zonder geldig `administratie`-veld, WHEN opgeslagen, THEN faalt validatie met duidelijke foutmelding.

### REQ-MA-002: Per-administratie rekeningschema en boekjaar
Iedere administratie heeft een eigen chart-of-accounts en eigen boekjaar-cyclus, onafhankelijk van andere administraties.

- GIVEN administratie WERK-001 met boekjaar jan-dec en BEHEER-001 met afwijkend boekjaar jul-jun, WHEN beide hun jaarafsluiting plannen, THEN voert elk dit op het eigen tijdstip uit zonder elkaar te raken.
- GIVEN administratie A op chart-of-accounts "RGS 3.5" en administratie B op een custom schema, WHEN journaalposten worden geboekt, THEN valideert elk tegen de eigen rekeningstructuur.
- GIVEN een organisatie wil een nieuw rekeningschema toevoegen voor een nieuwe BV, WHEN de administratie wordt aangemaakt, THEN kan een template (RGS-standaard, MKB-schema, overheid-BBV) worden geselecteerd of een bestaande administratie als template worden gebruikt.

### REQ-MA-003: Multi-tenant gebruikersrechten met administratie-switcher
Een gebruiker kan tot meerdere administraties toegang hebben met verschillende rollen per administratie.

- GIVEN een controller met rol `controller` in WERK-001 en `inkijker` in BEHEER-001, WHEN deze inlogt, THEN ziet deze in de UI een switcher met beide administraties en kan binnen één sessie wisselen zonder opnieuw in te loggen.
- GIVEN dezelfde controller actief in WERK-001, WHEN deze een journaalpost wil boeken, THEN lukt dit; WHEN deze in BEHEER-001 een journaalpost wil boeken, THEN faalt dit met "Onvoldoende rechten in deze administratie".
- GIVEN een accountantskantoor met 50 klanten, WHEN een nieuwe medewerker toegang krijgt tot 12 specifieke klanten, THEN worden 12 `administratie_lidmaatschap`-records aangemaakt met de bijbehorende rol.

### REQ-MA-004: Intercompany-journaalpost met sluitende boeking aan beide kanten
Een intercompany-boeking moet automatisch in beide administraties dezelfde mutatie spiegelen.

- GIVEN administratie WERK boekt een management fee van 25.000 EUR aan BEHEER, WHEN de intercompany-journaalpost wordt aangemaakt, THEN ontstaan twee journaalposten — één in WERK (kosten + crediteur BEHEER) en één in BEHEER (omzet + debiteur WERK) — gekoppeld via `intercompany_nummer`.
- GIVEN de tegenkant nog niet bevestigd is, WHEN één van de twee partijen de boeking wijzigt, THEN gaat de status terug naar `concept` en moet de tegenkant opnieuw bevestigen.
- GIVEN een intercompany-stand waarbij de saldi tussen WERK en BEHEER per balansdatum 100 EUR afwijken, WHEN het reconciliatie-rapport wordt opgevraagd, THEN toont dit het verschil met onderliggende posten en biedt een correctievoorstel.

### REQ-MA-005: Consolidatie-mapping en eliminatie-hooks
Iedere dochteradministratie kan haar grootboekrekeningen mappen naar de moederrekeningen voor consolidatiedoeleinden, met eliminatie van intercompany-mutaties.

- GIVEN een mapping waarin rekeningen 4310 en 4311 van WERK beide naar 4300 in BEHEER worden geconsolideerd, WHEN een consolidatie-export draait, THEN worden alle journaalposten op 4310/4311 in WERK opgeteld op 4300 in de geconsolideerde rapportage.
- GIVEN een intercompany-stroom van 25.000 EUR met `geconsolideerd_elimineren=true`, WHEN consolidatie wordt gegenereerd, THEN worden zowel de omzet bij BEHEER als de kosten bij WERK uit de geconsolideerde resultatenrekening verwijderd.
- GIVEN een dochter in vreemde valuta (USD), WHEN consolidatie wordt gegenereerd, THEN worden balansposten tegen slotkoers omgerekend, P&L tegen gemiddelde koers, en wordt het wisselkoersverschil op een aparte reserve gepresenteerd.

### REQ-MA-006: Migratie tussen administraties (asset transfer)
Een vast actief, een contract of een werknemer moet van administratie A naar administratie B kunnen worden overgedragen met behoud van historie.

- GIVEN een vast actief in WERK-001 met boekwaarde 87.000 EUR, WHEN deze wordt overgedragen aan WERK-002 met overdrachtswaarde 92.000 EUR, THEN ontstaan: (1) een desinvesteringsboeking in WERK-001 met 5.000 EUR boekwinst, (2) een nieuwe activering in WERK-002 voor 92.000 EUR, (3) een `administratie_migratie`-record dat beide journaalposten koppelt en de juridische grondslag vastlegt.
- GIVEN een werknemer wordt per 1 september overgedragen van WERK-001 naar WERK-002, WHEN de migratie wordt verwerkt, THEN wordt het arbeidscontract afgesloten in WERK-001 (met vertrekboeking), aangemaakt in WERK-002 (met instapboeking voor opgebouwde reserveringen vakantiegeld/13e maand), en wordt de payroll-verplichting overgenomen.
- GIVEN een migratie wordt geannuleerd voordat beide kanten zijn geboekt, WHEN teruggedraaid, THEN wordt mutatie ongedaan gemaakt en blijven beide administraties in oorspronkelijke staat.

### REQ-MA-007: Per-administratie backup en data-export
Iedere administratie moet onafhankelijk geback-upt en geëxporteerd kunnen worden.

- GIVEN een accountant wil de jaarcijfers van klant WERK-001 over 2026 archiveren, WHEN deze een full-export aanvraagt, THEN ontvangt deze een ZIP met alle journaalposten, balansen, jaarrekening en attached documents van uitsluitend WERK-001 in een gestandaardiseerd Auditfile XAF-formaat.
- GIVEN een klant zegt het contract op, WHEN diens administratie wordt geëxporteerd en gearchiveerd, THEN gaat de administratie naar status `gearchiveerd` en zijn alle data nog 7 jaar (wettelijke bewaartermijn) raadpleegbaar voor inzage maar niet meer muteerbaar.
- GIVEN backup-schema `dagelijks` voor een administratie, WHEN het backup-tijdvenster wordt bereikt, THEN wordt slechts díe administratie geback-upt zonder andere administraties te raken (incremental).

### REQ-MA-008: Fiscale eenheid VPB en BTW
Administraties die fiscaal in één eenheid zitten moeten dit kunnen aanduiden voor correcte BTW- en VPB-rapportage.

- GIVEN administraties WERK en BEHEER zitten in fiscale eenheid VPB, WHEN de VPB-aangifte wordt gegenereerd, THEN wordt slechts één aangifte gemaakt op naam van BEHEER met geconsolideerd resultaat.
- GIVEN administraties WERK en BEHEER in fiscale eenheid BTW, WHEN intercompany-facturen worden gemaakt, THEN wordt automatisch BTW-behandeling `fiscale_eenheid_geen_btw` toegepast en wordt slechts één BTW-aangifte ingediend.
- GIVEN een administratie verlaat de fiscale eenheid per 1 juli, WHEN de wijziging wordt geregistreerd, THEN wordt vanaf die datum normale BTW toegepast op intercompany-stromen en wordt een correctie voor het lopende jaar voorgesteld.

### REQ-MA-009: Per-administratie audit-trail met cross-administratie viewer
Audit-logs zijn per administratie, maar gebruikers met multi-administratie-toegang kunnen cross-administratie rapportages opvragen.

- GIVEN een audit-vraag "alle journaalposten geboekt door gebruiker X over Q1 2026", WHEN deze in administratie WERK-001 wordt gesteld, THEN levert het systeem alleen posten uit WERK-001.
- GIVEN dezelfde vraag door een holding-controller met toegang tot WERK-001, WERK-002 en BEHEER-001, WHEN deze opvraagt vanuit "consolidatie-view", THEN levert het systeem gecombineerde resultaten met expliciete administratie-kolom.

### REQ-MA-010: Administratie-aanmaak via wizard met template-overname
Het aanmaken van een nieuwe administratie moet binnen 5 minuten kunnen met sensibele defaults via wizard.

- GIVEN een accountant maakt een nieuwe BV-administratie aan via wizard, WHEN deze KvK-nummer invult, THEN haalt het systeem via KvK-koppeling rechtsvorm, naam, adres en RSIN op en pre-vult deze velden.
- GIVEN de wizard biedt template-keuze, WHEN "BV met loonadministratie en BTW maandaangifte" wordt gekozen, THEN worden standaard chart-of-accounts (RGS 3.5), boekjaar (kalenderjaar), BTW-frequentie (maand) en loonheffingsregistratie aangezet, en is de administratie direct boekklaar.
- GIVEN een holding wordt aangemaakt met al een bestaande werkmaatschappij in het systeem, WHEN de holding wordt aangemaakt, THEN biedt de wizard direct de koppeling `werkmaatschappij.moederadministratie=holding` en stelt consolidatie-eliminatie-defaults in.

## Standards & Sources

- **Burgerlijk Wetboek Boek 2 Titel 9** (jaarrekening) — wettelijke basis voor groepsmaatschappijen, consolidatieplicht en intercompany-eliminatie (art. 2:406 BW en verder).
- **RJ — Richtlijnen voor de Jaarverslaggeving** (Raad voor de Jaarverslaggeving), in het bijzonder RJ 217 (Consolidatie) en RJ 122 (Prijsgrondslagen voor activa en passiva en resultaatbepaling, valuta).
- **IFRS 10** Consolidated Financial Statements + **IFRS 3** Business Combinations — internationale referentie voor consolidatie-architectuur.
- **Auditfile XAF 3.2** (Standard Audit File Tax — Netherlands) — verplicht export-formaat voor Belastingdienst-controles; per administratie te genereren.
- **RGS — Referentie Grootboekschema 3.5+** (Standard Business Reporting) — defaultkeuze voor chart-of-accounts; standaard SBR-mapping voor IB/VPB-aangifte.
- **SBR — Standard Business Reporting** + **Nederlandse Taxonomie** — XBRL-aangiftes per administratie naar Belastingdienst, KvK en CBS.
- **Algemene Wet inzake Rijksbelastingen art. 52** — wettelijke administratieplicht en 7-jaars-bewaartermijn (voor onroerende zaken 10 jaar).
- **Wet op de Vennootschapsbelasting art. 15** — fiscale eenheid VPB; vereist gescheiden administraties met geconsolideerde aangifte.
- **Wet op de Omzetbelasting art. 7 lid 4** — fiscale eenheid BTW; intercompany zonder BTW.
- **AVG / GDPR** — datalocatie-eisen per klant (een Duitse klant van een NL-accountant kan vereisen dat zijn administratie aantoonbaar EU-gescheiden wordt opgeslagen).
- **BBV** (voor overheidsvariant) — verbonden partijen, grondbedrijf als afzonderlijke administratie, gemeenschappelijke regelingen.

## Cross-app integration

- **OpenRegister**: tenancy-laag — `administratie` wordt een filterveld op nagenoeg elke shillinq-entiteit; OpenRegister's RBAC-laag moet administratie-aware queries ondersteunen.
- **alle bestaande shillinq-specs** (`bookkeeping-general-ledger`, `bookkeeping-budget-forecast`, `bookkeeping-purchase-invoice`, `bookkeeping-sales-invoice`, `bookkeeping-vat-return`, `bookkeeping-payroll`, `bookkeeping-fixed-assets`): moeten allen worden gemigreerd om `administratie` als verplicht veld op te nemen — dit is de "foundation refactor"-aard van deze brief.
- **bookkeeping-rechtmatigheidsverantwoording** en **bookkeeping-verplichtingenadministratie** (parallel briefs): krijgen administratie-veld vanaf eerste implementatie.
- **toekomstige bookkeeping-consolidatie** spec: bouwt op deze fundamenten; rendert geconsolideerde jaarrekening.
- **OpenConnector — KvK Handelsregister**: automatische pre-fill van administratie-gegevens bij aanmaak.
- **OpenConnector — Belastingdienst (Digipoort / SBR)**: per-administratie aangiftes BTW, VPB, IB, loonheffing.
- **OpenConnector — CBS IV3 / Verbonden Partijen**: overheidsvariant voor decentrale administraties.
- **OpenConnector — Bank** (PSD2): per-administratie eigen rekening-koppelingen, geen vermenging.
- **OpenConnector — Salarisbureau-koppeling**: per loonheffingsnummer aparte koppeling.
- **larpingapp / openklant**: gedeelde leveranciers/klant-database — maar wel met expliciet onderscheid welke administratie met welke partij zaken doet (intercompany).
- **decidesk**: bestuurlijke besluitvorming over oprichting, fusie, splitsing van administraties wordt gedocumenteerd in besluit-flow.
- **DocuDesk**: per-administratie eigen documentenmap (KvK-uittreksel, statuten, jaarrekeningen, notariële aktes).
- **procest**: workflows kunnen administratie-overstijgend zijn (centrale inkoop) of administratie-specifiek (lokale goedkeuring).
- **openconnector — Auditfile XAF generator**: per-administratie export naar Belastingdienst.

## Target users

- **Accountant / Boekhouder bij accountantskantoor** — beheert tientallen tot honderden klant-administraties in één installatie; primaire afnemer van de multi-administratie-functionaliteit; gebruikt switcher continu.
- **Partner / Eigenaar accountantskantoor** — heeft toegang tot alle administraties; rapporteert op kantoorniveau over administratieve workload.
- **DGA / Eigenaar holding-werkmij-structuur** — heeft toegang tot zowel holding- als werkmaatschappij-administratie; bekijkt geconsolideerd resultaat in eigen dashboard.
- **Controller bij MKB-onderneming met meerdere BV's** — wisselt tussen administraties, boekt intercompany-mutaties, bewaakt fiscale eenheid-saldi.
- **CFO / Concerncontroller bij grotere groep** — gebruikt consolidatie-mapping en eliminatie; rapporteert geconsolideerd naar aandeelhouders.
- **Externe accountant van een holding** — krijgt tijdelijke toegang tot alle administraties van de groep voor controlewerk; verleent verklaring op zowel enkelvoudige als geconsolideerde jaarrekening.
- **Salarisadministrateur** — werkt typisch per administratie (één loonheffingsnummer); rolbeperking tot HR-rekeningen.
- **Penvoerder van gemeenschappelijke regeling (overheidsvariant)** — beheert administratie van GR naast eigen gemeente-administratie.
- **Grondbedrijf-controller (overheidsvariant)** — werkt in administratief afgescheiden grondbedrijf-tak.
- **Systeembeheerder / OpenRegister-beheerder** — beheert administratie-aanmaak, lidmaatschap-toekenning, backup-schemes, archivering bij contracteinde.
- **Compliance officer** — bewaakt datalocatie- en bewaartermijnen per administratie; ondersteunt GDPR/AVG-naleving voor multi-klant-omgevingen.
- **Belastingadviseur** — krijgt toegang tot administratie(s) van klant rond aangifteperioden; gebruikt XAF-export voor eigen analyse.

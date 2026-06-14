---
status: draft
---
# Rechtmatigheidsverantwoording (mandatory since 2023)

## Purpose

Per artikel 17a van het Besluit Begroting en Verantwoording provincies en gemeenten (BBV), aangevuld door de Wet versterking decentrale rekenkamers en de bijbehorende wijzigingen aan de Gemeentewet/Provinciewet die per boekjaar 2023 in werking zijn getreden, is het college van Burgemeester en Wethouders (B&W) — en analoog Gedeputeerde Staten bij provincies en het Dagelijks Bestuur bij gemeenschappelijke regelingen — verplicht een rechtmatigheidsverantwoording op te nemen in de jaarrekening. Tot 2022 gaf de externe accountant deze verklaring af; sinds 2023 verklaart het college zélf dat alle financiële handelingen rechtmatig tot stand zijn gekomen. De accountant geeft daarna alleen nog een getrouwheidsoordeel over de jaarrekening als geheel (inclusief de rechtmatigheidsverantwoording als bestanddeel daarvan).

Voor decentrale overheden die shillinq als bron-administratie willen gebruiken is dit een gating-functionaliteit: zonder geautomatiseerde rechtmatigheidstoetsing per journaalpost, geaggregeerde rapportage tegen de wettelijke toleranties (3% fout / 1% onzekerheid van het totaal van de lasten inclusief mutaties reserves), en een gesloten audit-trail per geconstateerde afwijking, is de jaarrekening niet conform BBV en BADO (Besluit Accountantscontrole Decentrale Overheden) op te leveren. Deze brief beschrijft een nieuw register `verantwoording` met schema's `rechtmatigheidstoets`, `rechtmatigheidsbevinding`, `rechtmatigheidsparagraaf`, en `tolerantiegrens`, plus de uitbreiding van het bestaande `journaalpost`-schema met een verplicht `rechtmatigheid`-veld.

De negen wettelijke rechtmatigheidscriteria die getoetst moeten worden zijn: (1) begrotingscriterium — past de last binnen de geautoriseerde begroting per programma; (2) voorwaardencriterium — voldoet de handeling aan in- en externe voorwaarden zoals subsidievoorwaarden, ARBO, AVG; (3) misbruik- en oneigenlijk-gebruikcriterium (M&O); (4) calculatiecriterium — rekenkundige juistheid; (5) valuteringscriterium — juiste boekingsperiode; (6) adresseringscriterium — juiste tegenpartij; (7) volledigheidscriterium — volledig vastgelegd; (8) aanvaardbaarheidscriterium — passend bij doelstellingen; en in de praktijk twee dominante materiële criteria: (9a) Europees aanbestedingsrecht (drempel 221.000 EUR leveringen/diensten, 5.538.000 EUR werken voor 2024-2025) en (9b) staatssteunrecht (de-minimis 300.000 EUR over drie jaar). Het register moet per journaalpost één of meer toetsen kunnen registreren, waarbij elke toets uitvalt naar `voldoet`, `voldoet_niet` (fout), of `onzeker` (onzekerheid), met onderbouwing en eventueel bewijsstukken via OpenRegister files-attached-to-object.

## Data Model (entities + Dutch JSON)

### Schema `rechtmatigheidstoets`
Een toets is de geautomatiseerde of handmatige beoordeling van één criterium tegen één journaalpost (of een groep gerelateerde posten zoals een crediteurenfactuur met meerdere boekingsregels).

```json
{
  "id": "uuid",
  "journaalpost": "uuid|ref:journaalpost",
  "criterium": "begroting|voorwaarden|misbruik_oneigenlijk_gebruik|calculatie|valutering|adressering|volledigheid|aanvaardbaarheid|europees_aanbesteden|staatssteun",
  "uitkomst": "voldoet|voldoet_niet|onzeker|niet_van_toepassing",
  "toetsdatum": "2026-03-15",
  "toetser": "uuid|ref:gebruiker",
  "toetstype": "automatisch|handmatig|extern",
  "onderbouwing": "Factuur 2026-441 valt onder raamovereenkomst RO-2024-12 (Europees aanbesteed via TenderNed-publicatie 2024/S-117-356721); past binnen begroting programma 5.1 (resterend budget 145.000 EUR, factuur 12.450 EUR).",
  "bedrag_betrokken": 12450.00,
  "bewijsstukken": ["file:uuid", "file:uuid"],
  "regelverwijzing": "BBV art. 17a lid 2; gemeentelijk inkoopbeleid 2024 art. 8.3",
  "rechtmatigheidsbevinding": "uuid|ref:rechtmatigheidsbevinding|nullable"
}
```

### Schema `rechtmatigheidsbevinding`
Bij `voldoet_niet` of `onzeker` wordt een bevinding aangemaakt die de impact kwantificeert en aan een rapportageperiode koppelt.

```json
{
  "id": "uuid",
  "bevindingsnummer": "RV-2026-0142",
  "soort": "fout|onzekerheid",
  "criterium": "europees_aanbesteden",
  "bedrag_fout": 47800.00,
  "bedrag_onzekerheid": 0,
  "boekjaar": 2026,
  "programma": "5.1",
  "omschrijving": "Onderhandse gunning aan leverancier X voor 47.800 EUR; opdracht had Europees aanbesteed moeten worden gezien meerjarig volume > drempel.",
  "oorzaak": "Inkoper niet bekend met clustering meerjarige opdrachten.",
  "maatregel": "Inkoopproces herzien; clustering-check toegevoegd aan inkoopworkflow per Q3.",
  "status": "open|in_behandeling|opgenomen_in_paragraaf|opgelost",
  "gemeld_aan": ["college", "auditcommissie"],
  "meldingsdatum": "2026-04-02",
  "verantwoordelijke_portefeuillehouder": "uuid|ref:bestuurder"
}
```

### Schema `rechtmatigheidsparagraaf`
De geaggregeerde rapportage in de jaarrekening, één per boekjaar.

```json
{
  "id": "uuid",
  "boekjaar": 2026,
  "totaal_lasten_inclusief_mutaties_reserves": 142500000.00,
  "tolerantiegrens_fout_percentage": 3.0,
  "tolerantiegrens_fout_bedrag": 4275000.00,
  "tolerantiegrens_onzekerheid_percentage": 1.0,
  "tolerantiegrens_onzekerheid_bedrag": 1425000.00,
  "totaal_geconstateerde_fouten": 213400.00,
  "totaal_geconstateerde_onzekerheden": 89200.00,
  "binnen_tolerantie": true,
  "verklaring_college": "Het college verklaart dat de in de jaarrekening 2026 verantwoorde baten en lasten alsmede de balansmutaties rechtmatig tot stand zijn gekomen binnen de door de raad vastgestelde kaders, met inachtneming van de hieronder gespecificeerde bevindingen die binnen de door de raad vastgestelde toleranties blijven.",
  "bevindingen": ["uuid", "uuid"],
  "vastgesteld_door_college_op": "2027-05-12",
  "behandeld_in_raad_op": "2027-06-20",
  "status": "concept|vastgesteld_college|behandeld_raad|definitief"
}
```

### Schema `tolerantiegrens`
Per raadsbesluit vastgestelde toleranties (default 3%/1% maar raad mag scherper stellen).

```json
{
  "id": "uuid",
  "boekjaar": 2026,
  "fout_percentage": 3.0,
  "onzekerheid_percentage": 1.0,
  "vastgesteld_bij_raadsbesluit": "RB-2025-117",
  "vastgesteld_op": "2025-11-14",
  "geldig_vanaf": "2026-01-01",
  "geldig_tot": "2026-12-31",
  "berekeningsbasis": "totaal_lasten_inclusief_mutaties_reserves"
}
```

### Uitbreiding bestaand schema `journaalpost`
Toegevoegd veld `rechtmatigheid`:

```json
{
  "rechtmatigheid": {
    "status": "niet_getoetst|in_behandeling|getoetst|vrijgesteld",
    "toetsen": ["uuid", "uuid"],
    "samenvattend_oordeel": "voldoet|bevat_fout|bevat_onzekerheid|gemengd",
    "laatste_toetsdatum": "2026-03-15"
  }
}
```

## Requirements

### REQ-RV-001: Automatische rechtmatigheidstoetsing bij journaalpost-aanmaak
Iedere journaalpost moet bij creatie automatisch worden onderworpen aan een minimumset van geautomatiseerde toetsen (begroting, calculatie, valutering, adressering, volledigheid) zodat handmatige werklast beperkt blijft tot de materiële criteria.

- GIVEN een nieuwe crediteurenfactuur van 25.000 EUR op grootboekrekening 4310 programma 5.1, WHEN de journaalpost wordt geboekt, THEN worden vijf `rechtmatigheidstoets`-records aangemaakt (begroting/calculatie/valutering/adressering/volledigheid) en wordt `journaalpost.rechtmatigheid.status` op `getoetst` gezet mits alle vijf `voldoet` retourneren.
- GIVEN de begrotingsruimte op programma 5.1 nog 12.000 EUR vrij is, WHEN een factuur van 25.000 EUR wordt geboekt, THEN retourneert de begrotingstoets `voldoet_niet`, wordt automatisch een `rechtmatigheidsbevinding` met `soort=fout` en `bedrag_fout=13000` aangemaakt, en wordt de boeking wel doorgevoerd maar de portefeuillehouder genotificeerd.
- GIVEN een journaalpost zonder tegenrekening op de creditzijde, WHEN de adresseringstoets draait, THEN retourneert deze `voldoet_niet` en blokkeert de boeking tot een geldige tegenpartij is geregistreerd.

### REQ-RV-002: Handmatige toetsing voor materiële criteria
De criteria Europees aanbesteden, staatssteun, voorwaarden en M&O moeten handmatig of via gerichte workflow-koppeling getoetst kunnen worden, met onderbouwing en bewijsstukken.

- GIVEN een inkoopfactuur boven de signaaldrempel van 50.000 EUR, WHEN de factuur wordt aangeboden voor betaling, THEN wordt een handmatige toets `europees_aanbesteden` aangemaakt in status `in_behandeling` en moet een inkoopadviseur deze afhandelen voordat de betaling kan worden vrijgegeven.
- GIVEN een subsidieverstrekking aan een onderneming voor 150.000 EUR, WHEN de boeking plaatsvindt, THEN wordt een staatssteuntoets gevraagd inclusief de-minimis-verklaring als bewijsstuk, en kan de toets pas op `voldoet` worden gezet als het bewijsstuk is aangehecht.
- GIVEN een afgewezen toets met `uitkomst=voldoet_niet`, WHEN de toets wordt opgeslagen, THEN moet het veld `onderbouwing` minimaal 50 karakters bevatten en moet er een `rechtmatigheidsbevinding` aan gekoppeld worden.

### REQ-RV-003: Tolerantiegrens-beheer per boekjaar
Toleranties moeten per boekjaar vastgelegd worden bij raadsbesluit; default is wettelijk 3% fout / 1% onzekerheid maar de raad mag scherper stellen.

- GIVEN geen `tolerantiegrens`-record voor boekjaar 2027, WHEN het boekjaar wordt geopend, THEN worden automatisch defaults aangemaakt met `fout_percentage=3.0` en `onzekerheid_percentage=1.0` met status `concept` totdat een raadsbesluit-referentie is ingevuld.
- GIVEN een raadsbesluit dat scherpere toleranties van 2% / 0.5% vastlegt, WHEN dit wordt vastgelegd in shillinq, THEN worden alle lopende toetsen voor dat boekjaar opnieuw geaggregeerd tegen de nieuwe grenzen.

### REQ-RV-004: Audit-trail per toets en bevinding
Elke toets, statusovergang en wijziging aan een bevinding moet onveranderlijk worden vastgelegd via OpenRegister audit log, conform BADO-eisen voor toetsbare verantwoording.

- GIVEN een gewijzigde `onderbouwing` op een bestaande toets, WHEN de wijziging wordt opgeslagen, THEN wordt een audit-log-entry gemaakt met oude waarde, nieuwe waarde, gebruiker en tijdstempel, en blijft de oude waarde raadpleegbaar.
- GIVEN een verzoek van de accountant tot inzage in alle toetsen die voldoen aan `criterium=europees_aanbesteden` over boekjaar 2026, WHEN de accountant deze query uitvoert via de audit-export-endpoint, THEN ontvangt deze een volledig getekend (PAdES of XAdES) bestand met alle toetsen, bevindingen en gekoppelde bewijsstukken.

### REQ-RV-005: Aggregatie naar rechtmatigheidsparagraaf
Bij afsluiting van het boekjaar moet shillinq alle openstaande bevindingen aggregeren tot één `rechtmatigheidsparagraaf` met de wettelijke verklaring.

- GIVEN het boekjaar 2026 wordt afgesloten met 213.400 EUR fout en 89.200 EUR onzekerheid, WHEN de paragraaf wordt gegenereerd, THEN worden beide bedragen vergeleken tegen de tolerantiegrenzen (4.275.000 / 1.425.000), wordt `binnen_tolerantie=true` gezet, en wordt de standaard collegeverklaring opgenomen.
- GIVEN totaal fout 5.100.000 EUR bij tolerantiegrens 4.275.000 EUR, WHEN de paragraaf wordt gegenereerd, THEN wordt `binnen_tolerantie=false` gezet, wordt een afwijkende verklaringstekst gegenereerd waarin de fouten expliciet worden benoemd, en wordt de portefeuillehouder Financiën verplicht om een toelichting te schrijven voordat de paragraaf naar `vastgesteld_college` kan.

### REQ-RV-006: Koppeling aan jaarrekening (BBV-export)
De rechtmatigheidsparagraaf moet als bestanddeel van de jaarrekening worden geëxporteerd in BBV-conform formaat (SISA, IV3, en het concept-BBV-XBRL waar van toepassing).

- GIVEN een definitieve `rechtmatigheidsparagraaf` voor boekjaar 2026, WHEN de jaarrekening-export draait, THEN wordt de paragraaf opgenomen als XBRL-element in de IV3-rapportage richting CBS en als PDF-bijlage in de jaarrekening-bundel.
- GIVEN een nog niet vastgestelde paragraaf, WHEN de export wordt geprobeerd, THEN faalt deze met een duidelijke melding dat de paragraaf eerst door het college moet worden vastgesteld.

### REQ-RV-007: Drempelbedragen en signalering Europees aanbesteden
Het systeem moet de actuele Europese drempelbedragen kennen (2024-2025: 221.000 EUR diensten/leveringen decentraal, 5.538.000 EUR werken, 750.000 EUR sociale/specifieke diensten) en factuurclustering per leverancier signaleren.

- GIVEN drie facturen aan leverancier X in boekjaar 2026 met totaal 235.000 EUR, WHEN de derde factuur wordt geboekt, THEN signaleert het systeem dat de Europese drempel voor leveringen wordt overschreden en eist een handmatige `europees_aanbesteden`-toets met verwijzing naar TenderNed-publicatie of onderbouwde uitzondering.
- GIVEN een raamovereenkomst RO-2024-12 met TenderNed-referentie, WHEN facturen onder deze raamovereenkomst worden geboekt, THEN refereren de automatische toetsen naar de raamovereenkomst en wordt geen aparte aanbestedingstoets gevraagd.

### REQ-RV-008: Workflow-integratie met inkoopproces en verplichtingenadministratie
Rechtmatigheidstoetsing moet zo vroeg mogelijk in het inkoopproces plaatsvinden, idealiter bij de verplichting (PO) zodat de factuur slechts een afronding is.

- GIVEN een inkooporder wordt aangemaakt voor 75.000 EUR, WHEN de PO wordt vastgelegd in `verplichtingenadministratie`, THEN worden begroting- en aanbestedingstoetsen direct uitgevoerd op de PO en bij latere facturering overgenomen tenzij de factuur materieel afwijkt.
- GIVEN een factuur die afwijkt van de PO met meer dan 10%, WHEN de factuur wordt geboekt, THEN worden de toetsen op de factuur opnieuw uitgevoerd en wordt de afwijking als onderdeel van de onderbouwing vastgelegd.

### REQ-RV-009: Rapportage en dashboards per programma en portefeuille
Het college, de raad en de auditcommissie moeten op elk moment inzicht hebben in de actuele rechtmatigheidspositie zonder te wachten op de jaarrekening.

- GIVEN een gebruiker met rol `portefeuillehouder`, WHEN deze het rechtmatigheidsdashboard opent, THEN ziet deze de openstaande bevindingen op de eigen programma's, het lopende fouten/onzekerheden-totaal vs tolerantie, en de top-5 risicovolle inkoopstromen.
- GIVEN de auditcommissie wil kwartaalrapportage, WHEN de export `rechtmatigheid_kwartaal` wordt aangevraagd, THEN ontvangt deze een PDF met geaggregeerde cijfers per programma, alle bevindingen boven 25.000 EUR en de trendgrafiek over de afgelopen 4 kwartalen.

### REQ-RV-010: Hertoetsbaarheid en correctieboekingen
Wanneer een fout wordt opgelost via een correctieboeking moet de oorspronkelijke toets de status `opgelost` krijgen zonder de audit-trail te verstoren.

- GIVEN een bevinding met `bedrag_fout=13000` waarvoor een correctieboeking wordt gemaakt, WHEN de correctieboeking wordt gekoppeld aan de bevinding via veld `correctieboeking_id`, THEN gaat de bevinding naar `status=opgelost`, blijft het oorspronkelijke `bedrag_fout` ongewijzigd in de paragraaf-aggregatie (telt nog mee voor het jaar van constatering), maar wordt in het dashboard de "opgeloste" markering getoond.

## Standards & Sources

- **BBV — Besluit Begroting en Verantwoording provincies en gemeenten** (Stb. 2003, 27; meest recent gewijzigd Stb. 2022, 463). Artikel 17a is de wettelijke grondslag voor de rechtmatigheidsverantwoording vanaf boekjaar 2023.
- **Kadernota Rechtmatigheid 2024** van de Commissie BBV — geeft uitleg over de negen criteria, de begrotingsrechtmatigheid, en de aggregatieregels.
- **BADO — Besluit Accountantscontrole Decentrale Overheden** (Stb. 2002, 68; laatst gewijzigd 2023) — definieert toleranties (3% fouten, 1% onzekerheden van het totaal van de lasten inclusief mutaties reserves) en de eisen aan de accountantscontrole.
- **Aanbestedingswet 2012** (laatste wijziging 2022) — definieert het Europese aanbestedingsregime; drempelbedragen worden tweejaarlijks bij verordening (EU) 2017/2364 aangepast en gepubliceerd door PIANOo.
- **Algemene Groepsvrijstellingsverordening (AGVV)** (EU) nr. 651/2014 en **De-minimisverordening** (EU) 2023/2831 — staatssteunkader.
- **VNG-Model Financiële Verordening** art. 212 Gemeentewet — definieert hoe de raad toleranties en uitgangspunten vaststelt.
- **IV3-rapportage** — CBS-verplichte informatie voor derden; de rechtmatigheidsparagraaf is daarvan een onderdeel.
- **Notitie Rechtmatigheidsverantwoording 2022** van de Vereniging van Nederlandse Gemeenten en de NBA — praktische voorbeelden van paragraaftekst.
- **EML-NL Financiën** (in ontwikkeling) — toekomstig standaard XML-uitwisselingsformaat voor financiële verantwoording; nog niet productie maar wel relevant voor toekomstvastheid van het export-schema.

## Cross-app integration

- **bookkeeping-bbv-compliance** (bestaand): leverancier van programma-indeling, BBV-rekeningschema en IV3-mapping. De rechtmatigheidstoets `begrotingscriterium` consulteert dit register voor budget per programma.
- **bookkeeping-financial-statements** (bestaand): de rechtmatigheidsparagraaf wordt als bijlage opgenomen in de jaarrekening-render-pipeline.
- **bookkeeping-verplichtingenadministratie** (parallel brief): inkoop-PO's worden hier eerst getoetst zodat de factuurfase een lichtere check krijgt.
- **bookkeeping-general-ledger**: bron van de `journaalpost`-entiteit die wordt uitgebreid met het `rechtmatigheid`-veld.
- **OpenConnector — TenderNed**: ophalen van aanbestedingspublicaties voor verificatie van Europees aanbestedingsregime; mapping van CPV-codes naar inkoopstromen.
- **OpenConnector — KvK**: ophalen van rechtsvorm/SBI voor staatssteuntoets (is de tegenpartij een onderneming in de zin van EU-staatssteunrecht?).
- **OpenConnector — CBS IV3**: oplevering van de jaarrekening inclusief rechtmatigheidsparagraaf.
- **DocuDesk**: rendering van de paragraaf en de bevindingenlijst als PDF/A-3 met digitale handtekening van het college.
- **decidesk**: koppeling met collegebesluiten en raadsbesluiten over toleranties en vaststelling van de paragraaf.
- **procest**: workflow voor de handmatige toetsen (toewijzing aan inkoopadviseur, juridisch medewerker, controller) inclusief escalatie bij overschrijding doorlooptijd.
- **OpenRegister**: drager-platform voor het `verantwoording`-register, audit-log, en files-attached-to-object voor bewijsstukken.

## Target users

- **Concerncontroller / Hoofd Financiën** — eindverantwoordelijk voor de paragraaf; bewaakt totalen vs tolerantie en stuurt op risico-stromen via dashboard.
- **College van B&W (portefeuillehouder Financiën)** — stelt de paragraaf vast; krijgt push-notificaties bij bevindingen op de eigen portefeuille.
- **Gemeentesecretaris** — coördineert het collegebesluit; bewaakt termijnen rond accountantscontrole.
- **Verbijzonderde Interne Controle (VIC) / interne auditor** — voert steekproeven uit op de geautomatiseerde toetsen; ontvangt drill-down rapportages.
- **Externe accountant** — gebruikt de audit-export voor het getrouwheidsoordeel; beoordeelt of de toetsen voldoen aan BADO.
- **Inkoopadviseur** — krijgt handmatige aanbestedings- en staatssteuntoetsen toegewezen via procest-workflow.
- **Budgethouder per programma** — krijgt waarschuwing bij dreigende overschrijding begrotingscriterium; kan zelf bevindingen toelichten.
- **Auditcommissie / Rekenkamer** — leest kwartaalrapportages; gebruikt het systeem voor eigen onderzoek naar rechtmatigheidsrisico's.
- **Raad** — stelt jaarlijks bij verordening de toleranties vast en behandelt de paragraaf bij vaststelling jaarrekening.

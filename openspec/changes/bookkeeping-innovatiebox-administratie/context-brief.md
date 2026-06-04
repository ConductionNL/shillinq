---
status: draft
---

# Innovatiebox Administratie

## Purpose

De Innovatiebox is een fiscale faciliteit in de vennootschapsbelasting (Vpb) die Nederlandse innovatieve ondernemingen in staat stelt om winst behaald met kwalificerende immateriele activa effectief tegen **9%** te belasten in plaats van het reguliere toptarief van **25,8%** (2024). Het tariefverschil van ruim 16 procentpunt vertegenwoordigt voor R&D-intensieve scale-ups en MKB-plus BV's een substantiele kasstroom: een BV met EUR 1 miljoen kwalificerende innovatiewinst bespaart op jaarbasis circa EUR 168.000 aan Vpb ten opzichte van regulier belaste winst.

De wettelijke basis ligt in **Wet Vpb 1969 artikelen 12b tot en met 12bg** (afdeling 2.3 Innovatiebox), nader uitgewerkt in het **Besluit Innovatiebox 2023** (Stcrt. 2023, 21084) van de Staatssecretaris van Financien. De faciliteit is sinds 2017 geharmoniseerd met **OECD BEPS Action 5** (modified nexus approach), wat betekent dat alleen winst toerekenbaar aan eigen R&D-inspanning fiscaal wordt begunstigd. Uitbestede R&D aan groepsmaatschappijen (verbonden lichamen) leidt tot een evenredige verlaging van de begunstigde winst via de nexusbreuk.

De `bookkeeping-innovatiebox-administratie` capability binnen shillinq voorziet in de gehele administratieve keten die de Belastingdienst tijdens een innovatiebox-controle eist: van toegangsticket (octrooi, S&O-verklaring, kwekersrecht), via kostentoerekening per kwalificerend activum, profit splitting tussen productiewinst en eigendomswinst, nexusberekening, voortwentelingsadministratie van innovatieverliezen, tot de uiteindelijke regel 23 van de aangifte vennootschapsbelasting (Vpb-aangifte). De administratie moet voldoen aan het **doorsnijdingsverbod** (Vpb 12bd lid 2): kosten die aan kwalificerende activa worden toegerekend mogen niet ook elders in de winstbepaling worden afgetrokken, en omgekeerd.

Doelgroep voor deze capability zijn fiscalisten bij MKB-plus BV's met WBSO-toegang, controllers bij R&D-intensieve scale-ups, en externe belastingadviseurs die meerdere clienten met innovatieboxposities bedienen. De capability is uitdrukkelijk geen vervanging voor de fiscale beoordeling door een belastingadviseur, maar levert wel de bronadministratie die nodig is om een verdedigbare positie in te nemen en een innovatiebox-vaststellingsovereenkomst (VSO) met de Belastingdienst af te sluiten.

Twee historische ontwikkelingen vormen de context van deze capability. Ten eerste de **BEPS-implementatie per 1 januari 2017** (Wet aanpassing fiscale eenheid en wijziging innovatiebox, Stb. 2016, 545), die de oorspronkelijke octrooibox uit 2007 en de bredere innovatiebox uit 2010 aanscherpte tot een nexus-gebaseerd regime conform OECD-standaarden. Vanaf dat moment volstaat het bezit van een octrooi of S&O-verklaring niet meer; alleen winst toerekenbaar aan eigen ontwikkelactiviteiten kwalificeert. Ten tweede de **tariefverhoging van 7% naar 9% per 1 januari 2021** (Belastingplan 2021), die het effectieve voordeel beperkte maar de faciliteit politiek houdbaar maakte tegen de achtergrond van internationale druk op nationale IP-regimes. Een verdere tariefverhoging is in elk Belastingplan een terugkerend gespreksonderwerp; de capability moet daarom tariefswijzigingen per boekjaar parametriseerbaar afhandelen zonder herontwerp.

## Data Model

De capability introduceert vijf samenhangende entiteiten die de innovatiebox-keten dekken. Alle entiteiten erven van de OpenRegister basis-objectstructuur en worden opgeslagen in een innovatiebox-register dat per fiscale eenheid wordt aangelegd.

### QualifyingAsset (Kwalificerend Immaterieel Activum)

Centrale entiteit die elk afzonderlijk innovatie-activum vastlegt. De Wet Vpb onderscheidt drie hoofdcategorieen toegangstickets:

1. **Octrooi-route** (art. 12ba lid 1 sub a): verleend octrooi, gebruiksmodel, kwekersrecht, weesgeneesmiddel, of aanvullend beschermingscertificaat.
2. **S&O-route** (art. 12ba lid 1 sub b): activum voortgekomen uit speur- en ontwikkelingswerk waarvoor een **S&O-verklaring** is afgegeven door RVO (WBSO).
3. **Combinatie-route**: voor BV's met groepsomzet > EUR 50 miljoen of mondiale groepsomzet > EUR 250 miljoen geldt dat zowel S&O-verklaring als octrooi (of vergelijkbaar) vereist is.

```json
{
  "id": "qa-2024-001",
  "naam": "Slimme routeringsalgoritme v2",
  "type": "software",
  "toegangsticket": {
    "soort": "so_verklaring",
    "so_verklaring_nummer": "S2024/001234",
    "so_verklaring_periode": {"van": "2024-01-01", "tot": "2024-12-31"},
    "octrooi_nummer": null,
    "octrooi_land": null,
    "octrooi_aanvraagdatum": null,
    "kwekersrecht_nummer": null
  },
  "type_immaterieel_activum": "software",
  "ontwikkelingsperiode": {"start": "2023-03-01", "einde_verwacht": "2024-12-31"},
  "in_gebruik_genomen": "2024-09-15",
  "voortbrenger": "interne_ontwikkeling",
  "kostendrager_id": "kd-rd-platform-team",
  "verbonden_lichaam_betrokken": false,
  "drempelbedrag_van_toepassing": true,
  "status": "actief"
}
```

Type-veld kent de waarden `octrooi`, `software`, `kweekrecht`, `weesgeneesmiddel`, `gebruiksmodel`, `abc` (aanvullend beschermingscertificaat), of `combinatie`. Software-activa zijn de meest voorkomende toepassing in de SaaS-economie en kwalificeren uitsluitend via de S&O-route (octrooi op software is in NL beperkt toegankelijk).

### NexusCalculation (Nexusberekening)

Per kwalificerend activum wordt jaarlijks de **nexusbreuk** berekend conform art. 12bc Vpb en de modified nexus approach van OECD BEPS Action 5. De breuk is:

```
nexusbreuk = min(1, 1,3 * (eigen R&D kosten) / (totale R&D kosten))
```

De factor 1,3 is de **uplift** die maximaal 30% extra ruimte geeft voor uitbestede R&D, maar nooit boven 100% (cap). Uitbestede R&D aan **niet-verbonden** derden telt mee als eigen R&D; uitbesteding aan verbonden lichamen telt alleen mee in de noemer.

```json
{
  "id": "nexus-2024-qa001",
  "qualifying_asset_id": "qa-2024-001",
  "boekjaar": 2024,
  "eigen_rd_kosten": 480000.00,
  "rd_kosten_uitbesteed_derden": 120000.00,
  "rd_kosten_uitbesteed_verbonden": 80000.00,
  "totale_rd_kosten": 680000.00,
  "uplift_factor": 1.30,
  "nexus_teller_voor_uplift": 600000.00,
  "nexus_teller_na_uplift": 780000.00,
  "nexus_noemer": 680000.00,
  "nexusbreuk_ongecapt": 1.1471,
  "nexusbreuk_toegepast": 1.0000,
  "berekend_op": "2025-02-15",
  "berekend_door": "controller@bv-x.nl"
}
```

Voorbeeld: BV X spendeert EUR 480k aan eigen R&D-loonkosten, EUR 120k aan externe ontwikkelaars (niet-verbonden), en EUR 80k aan een Indiase groepsdochter. Teller voor uplift = 480 + 120 = 600. Na uplift = 780. Noemer = 680. Ratio = 1,147, gecapt op 1,00. De nexusbreuk is dus 100%: alle kwalificerende winst telt mee.

### IBProfitAttribution (Innovatiebox Winsttoerekening)

Niet de hele opbrengst van een product is per definitie innovatiewinst. De wet onderscheidt **eigendomswinst** (toerekenbaar aan het IE-recht) van **productiewinst** (routinematige fabricage, distributie, marketing). Alleen eigendomswinst kwalificeert. De winsttoerekening kan gebeuren via drie methoden, beschreven in het Besluit Innovatiebox 2023 paragraaf 6:

1. **Per asset methode (afpelmethode)**: opbrengst - routinewinst overige functies = innovatiewinst. Standaard voor grote ondernemingen.
2. **Forfaitaire methode (art. 12bd lid 3)**: 25% van de winst tot maximaal EUR 25.000 per jaar. Vereenvoudiging voor kleine voordelen, drie jaar geldig.
3. **Cost-plus / kostprijs-plus methode**: opbrengst gerelateerd aan kosten + opslag, vooral bij intra-groep transacties.

```json
{
  "id": "ipa-2024-qa001",
  "qualifying_asset_id": "qa-2024-001",
  "boekjaar": 2024,
  "methode": "per_asset_afpelmethode",
  "bruto_opbrengst_activum": 2400000.00,
  "directe_kosten_activum": 850000.00,
  "routine_marketing_winst": 180000.00,
  "routine_distributie_winst": 90000.00,
  "routine_productie_winst": 480000.00,
  "kwalificerende_winst_voor_nexus": 800000.00,
  "nexus_calculation_id": "nexus-2024-qa001",
  "nexusbreuk_toegepast": 1.0000,
  "kwalificerende_winst_na_nexus": 800000.00,
  "effectief_tarief": 0.09,
  "vpb_op_innovatiedeel": 72000.00,
  "vpb_zonder_innovatiebox": 206400.00,
  "voordeel_innovatiebox": 134400.00,
  "drempel_2024": 0.00,
  "drempel_resterend": 0.00
}
```

Voorbeeld: BV X heeft op activum qa-2024-001 een bruto opbrengst van EUR 2,4 miljoen. Na aftrek van directe kosten en routinewinsten resteert EUR 800k kwalificerende winst. Met nexus 100% blijft dit EUR 800k. Vpb op innovatiedeel: 9% × 800k = EUR 72.000. Zonder innovatiebox zou dit 25,8% × 800k = EUR 206.400 zijn. Voordeel: EUR 134.400. Bij gedeeltelijke nexus (bijv. 50%) zou de begunstigde winst EUR 400k zijn en het voordeel zou halveren.

### IBExpenseAllocation (Kostentoerekening per Activum)

De **doorsnijdingsverbod** (art. 12bd lid 2) eist dat kosten die zijn toegerekend aan kwalificerende activa, niet ook elders in de aangifte worden afgetrokken. Dit vereist een sluitende kostenadministratie per activum, gekoppeld aan de loonadministratie (S&O-loonuren), kostenplaatsen, en grootboekrekeningen.

```json
{
  "id": "iea-2024-qa001-q3",
  "qualifying_asset_id": "qa-2024-001",
  "boekjaar": 2024,
  "periode": "2024-Q3",
  "kostensoort": "rd_loonkosten",
  "bron": "loonadministratie",
  "bron_referentie": {
    "so_verklaring": "S2024/001234",
    "medewerker_ids": ["emp-101", "emp-102", "emp-118"],
    "totale_so_uren": 1840,
    "uurtarief_intern": 65.00
  },
  "bedrag": 119600.00,
  "grootboekrekening": "4010_RD_loonkosten_geactiveerd",
  "kostenplaats": "kp-platform-team",
  "kostendrager_id": "kd-rd-platform-team",
  "boekstuk_referentie": "memboek-2024-09-031",
  "exclusief_in_winstbepaling": true
}
```

Kostensoort kent waarden `rd_loonkosten`, `materiaal`, `afschrijving`, `licentie`, `uitbesteding_derden`, `uitbesteding_verbonden`, `overhead_opslag`. Het veld `exclusief_in_winstbepaling: true` markeert dat deze kostenpost niet nogmaals in de reguliere winstaftrek mag verschijnen.

### CarryForwardLoss (Voortwenteling Innovatieverliezen)

Innovatieverliezen (negatieve kwalificerende winst per activum) worden afzonderlijk geadministreerd en kunnen alleen worden verrekend met toekomstige positieve innovatiewinst op hetzelfde activum (of conform vaststellingsovereenkomst breder). Dit vloeit voort uit art. 12be Vpb. Verliezen verlaten dus niet de innovatiebox naar de reguliere winstbepaling, tenzij het activum definitief wordt afgevoerd.

```json
{
  "id": "cfl-2023-qa001",
  "qualifying_asset_id": "qa-2024-001",
  "ontstaansboekjaar": 2023,
  "negatief_kwalificerend_resultaat": 215000.00,
  "verrekend_boekjaar": [
    {"jaar": 2024, "bedrag": 215000.00, "saldo_na": 0.00}
  ],
  "saldo_open": 0.00,
  "status": "volledig_verrekend",
  "vervaldatum": null
}
```

Voorbeeld: in 2023 ontstaat een innovatieverlies van EUR 215k (kosten boven opbrengst tijdens ontwikkelfase). In 2024 is er voor het eerst positieve winst van EUR 800k op hetzelfde activum. De eerste EUR 215k wordt verrekend tegen het reguliere tarief (drempel), pas daarna geldt 9% over EUR 585k. De `IBProfitAttribution` referent aan deze `CarryForwardLoss` via het veld `drempel_2024`.

## Requirements

### 1. Registratie van kwalificerende immateriele activa met toegangsticket-verificatie

De capability MOET het mogelijk maken om per kwalificerend activum het toegangsticket vast te leggen (octrooi, S&O-verklaring, kwekersrecht, weesgeneesmiddel, aanvullend beschermingscertificaat, gebruiksmodel) inclusief uitgevende instantie, registratienummer, geldigheidsperiode en datum van aanvraag. Voor S&O-verklaringen MOET het S&O-verklaringsnummer in het formaat S{jaar}/{6-cijferig} worden gevalideerd en gekoppeld aan de RVO-administratie. Voor combi-route-bedrijven (groepsomzet boven de drempel van art. 12ba lid 3) MOET het systeem afdwingen dat BEIDE typen toegangstickets aanwezig zijn voordat het activum als kwalificerend kan worden gemarkeerd. Activa zonder geldig of verlopen toegangsticket MOETEN automatisch een waarschuwingsstatus krijgen en uitgesloten worden van de jaarlijkse nexus- en winsttoerekeningsberekening.

### 2. WBSO-koppeling en S&O-loonurenadministratie

De capability MOET een directe koppeling onderhouden met `bookkeeping-wbso-sno-administratie` zodat S&O-uren per medewerker per S&O-verklaring automatisch beschikbaar zijn voor toerekening aan een kwalificerend activum. Per kwartaal MOET het systeem de gerealiseerde S&O-uren ophalen, vermenigvuldigen met het toegepaste S&O-uurloon (jaarlijks vastgesteld door RVO), en als kostenpost van type `rd_loonkosten` toerekenen aan het activum. Bij correcties op de mededeling werkelijke S&O-uren (te dienen binnen drie maanden na afloop kalenderjaar) MOET de capability de bijbehorende `IBExpenseAllocation` records herberekenen en een audit-trail bijhouden van oude en nieuwe bedragen.

### 3. Nexusberekening conform OECD BEPS Action 5

Voor elk kwalificerend activum MOET het systeem jaarlijks de nexusbreuk berekenen volgens de formule: min(1; 1,3 * (eigen R&D + R&D uitbesteed aan derden) / (totale R&D-kosten inclusief uitbesteed aan verbonden lichamen)). De capability MOET onderscheid afdwingen tussen R&D uitbesteed aan **verbonden lichamen** (art. 10a lid 4 Vpb: 1/3 belang of bestuurlijke controle) en R&D uitbesteed aan **niet-verbonden derden**. Verbondenheid MOET worden afgeleid uit de groepsadministratie of expliciet per leverancier worden gemarkeerd. De berekening MOET de uplift-factor van 1,3 toepassen op de teller voor cap, en het resultaat cappen op 1,0. Historische nexusbreuken MOETEN per boekjaar onveranderbaar worden opgeslagen ten behoeve van controlespoor.

### 4. Profit-splitting tussen eigendomswinst en routinewinst

De capability MOET drie methoden van winsttoerekening ondersteunen: (a) per asset afpelmethode (default voor activa met opbrengst > EUR 25k/jaar), (b) forfaitaire methode (25% van de winst tot maximaal EUR 25.000 per jaar, geldigheid drie jaar, art. 12bd lid 3), (c) cost-plus methode voor intra-groep transacties. Per gekozen methode MOET het systeem afdwingen dat routine-functies (marketing, distributie, productie) eerst hun arm's-length-routinewinst krijgen toebedeeld op basis van transfer pricing-benchmarks, voordat de residuele winst als kwalificerende eigendomswinst aan het activum wordt toegerekend. De methodekeuze MOET per activum worden vastgelegd inclusief motivering en MOET niet zonder waarschuwing tussen boekjaren wijzigen (consistency principle van het Besluit Innovatiebox 2023 paragraaf 6.4).

### 5. Kostentoerekening met doorsnijdingsverbod-handhaving

De capability MOET per activum en per kostensoort (R&D-loonkosten, materiaal, afschrijving immaterieel activum, licentie, uitbesteding) de toegerekende kosten administreren met referentie naar boekstuk, grootboekrekening, kostenplaats en kostendrager uit `bookkeeping-cost-centers-dimensions`. Kostenposten met `exclusief_in_winstbepaling: true` MOETEN bij aanlevering aan de Vpb-aangifte als afzonderlijke aftrekposten in de innovatieboxberekening verschijnen en NIET in de reguliere winstberekening van regel 1-22 van de Vpb-aangifte. Het systeem MOET een doorsnijdingscontrole uitvoeren: indien een grootboekrekening x kostenplaats-combinatie zowel in een innovatieboxtoerekening als in de reguliere winstbepaling is gebruikt, MOET een blokkerende waarschuwing worden gegeven met opgaaf van het dubbele bedrag.

### 6. Voortwentelingsadministratie van innovatieverliezen

De capability MOET per kwalificerend activum een afzonderlijke verliescompensatie-administratie voeren. Negatieve kwalificerende winst (innovatieverlies) MOET per boekjaar worden vastgelegd als `CarryForwardLoss` met ontstaansjaar, bedrag en open saldo. In volgende boekjaren MOET positieve kwalificerende winst eerst worden verrekend met openstaande voortwentelingsverliezen voordat het 9%-tarief van toepassing wordt; deze drempel (`drempel_resterend`) MOET expliciet zichtbaar zijn in de `IBProfitAttribution`. Bij definitieve afstoting van het activum (verkoop, sluiting, octrooi-vervalling) MOET het resterende open verlies kunnen worden overgeheveld naar de reguliere winstbepaling onder vermelding van wettelijke grondslag en goedkeuring fiscalist.

### 7. Jaaraangifte Vpb-aanlevering

De capability MOET jaarlijks na boekjaarsluiting een innovatiebox-bijlage genereren in een formaat dat directe overname naar regel 23 van het aangiftebiljet Vennootschapsbelasting (Vpb-aangifte) mogelijk maakt. De bijlage MOET bevatten: per activum de kwalificerende winst voor en na nexus, de toegepaste nexusbreuk met onderbouwing, de toegerekende kosten per kostensoort, eventuele drempelverrekening van voortwentelingsverliezen, en de berekende Vpb op innovatiedeel tegen 9%. Het systeem MOET tevens een SBR/XBRL-export ondersteunen die aansluit op de Nederlandse Taxonomie (NT) voor Vpb-aangifte en MOET een PDF-bijlage met onderbouwing genereren voor de innovatiebox-vaststellingsovereenkomst (VSO) met de Belastingdienst.

### 8. Drempelbedrag- en kleine-voordelenregeling

De capability MOET de forfaitaire regeling van art. 12bd lid 3 ondersteunen waarbij de belastingplichtige kan kiezen voor 25% van de winst (gemaximeerd op EUR 25.000 per jaar) als kwalificerende winst, zonder gedetailleerde afpelberekening. Bij keuze voor het forfait MOET het systeem deze keuze drie aaneengesloten boekjaren vasthouden (art. 12bd lid 4) en in jaar vier opnieuw de standaardberekening hanteren tenzij opnieuw gekozen. De forfaitaire keuze MOET per kwalificerend activum afzonderlijk gemaakt kunnen worden, en het systeem MOET waarschuwen wanneer het cumulatieve forfait over alle activa EUR 25.000 per jaar overschrijdt (het maximum geldt per belastingplichtige, niet per activum).

### 9. Verbondenheidsadministratie en transfer pricing

De capability MOET een register bijhouden van verbonden lichamen in de zin van art. 10a lid 4 Vpb (belang van ten minste 1/3 of bestuurlijke controle), inclusief land van vestiging en transfer-pricing-methode. Bij uitbesteding van R&D aan een verbonden lichaam MOET het systeem (a) de kostenpost markeren als nexus-noemer-only, (b) een transfer pricing-onderbouwing (functie/risico/bezit-analyse) eisen als bijlage, en (c) waarschuwen indien de royalty-flow andersom (van verbonden lichaam naar belastingplichtige voor gebruik IE) leidt tot dubbele begunstiging. Voor activa in een fiscale eenheid (art. 15 Vpb) MOET de capability consolidatie ondersteunen waarbij intra-fiscale-eenheid R&D-uitbesteding als eigen R&D telt.

### 10. Vaststellingsovereenkomst (VSO) en audit-readiness

De capability MOET het mogelijk maken om een innovatiebox-vaststellingsovereenkomst met de Belastingdienst vast te leggen met looptijd (doorgaans vier jaar), overeengekomen toerekeningsmethode, vaste percentages voor routinewinsten, en eventuele specifieke afspraken per activum. Tijdens de VSO-looptijd MOET het systeem afwijkingen van de overeengekomen methode signaleren. Voor audit-readiness MOET de capability een tijdsgestempelde audit-trail bijhouden van alle wijzigingen aan kostentoerekeningen, nexusberekeningen en winsttoerekeningen, met behoud van de oorspronkelijke versie. Alle berekeningen MOETEN reproduceerbaar zijn vanuit de bronadministratie (loon, grootboek, projectadministratie) zonder handmatige tussenstappen.

## Standards & Sources

- **Wet op de vennootschapsbelasting 1969, art. 12b tot en met 12bg** (Afdeling 2.3 Innovatiebox): wettelijke basis voor toegangstickets (12ba), drempelbedrag (12bb), kwalificerende voordelen (12bc), nexusbenadering en winsttoerekening (12bd), voortwentelingsverliezen (12be), keuze en herziening (12bf), en samenloop met andere regelingen (12bg).
- **Besluit Innovatiebox 2023** (Stcrt. 2023, 21084): besluit van de Staatssecretaris van Financien met toelichting op uitvoeringspraktijk, methoden van winsttoerekening (par. 6), forfaitaire regeling (par. 7), nexusbreuk-berekening (par. 8), en behandeling van vaststellingsovereenkomsten (par. 11). Vervangt het eerdere Besluit Innovatiebox van 2014.
- **OECD/G20 BEPS Action 5: Countering Harmful Tax Practices More Effectively, Taking into Account Transparency and Substance** (Final Report 2015): introduceert de **modified nexus approach** die alle EU-IP-regimes vanaf 2016 verplicht moeten volgen. Definitie van qualifying expenditure (eigen R&D + uitbesteed aan niet-verbonden) en uplift-factor van maximaal 30%.
- **EU Code of Conduct Group (Business Taxation)**: review van nationale IP-regimes op compliance met BEPS Action 5, jaarlijkse rapportages aan Ecofin.
- **WBSO-Wet** (Wet vermindering afdracht loonbelasting en premie voor de volksverzekeringen, hoofdstuk VIII): basis voor afgifte S&O-verklaringen door Rijksdienst voor Ondernemend Nederland (RVO). S&O-verklaring is een van de twee toegangsroutes tot de Innovatiebox.
- **Beleidsregels S&O-Verklaring RVO** (jaarlijks geactualiseerd): definitie van speur- en ontwikkelingswerk, criteria voor technische nieuwheid, S&O-uurloon vaststelling, en aanvraag- en mededelingstermijnen.
- **Nederlandse Taxonomie (NT)** beheerd door SBR-Wonen / Logius: XBRL-rapportagestandaard voor Vpb-aangifte. De innovatiebox-elementen zijn opgenomen in de NT-jaarrelease.
- **OESO Transfer Pricing Guidelines for Multinational Enterprises and Tax Administrations** (2022 editie): basis voor routinewinst-bepaling per functie (productie, distributie, marketing) en arm's-length-onderbouwing van intra-groep R&D-uitbesteding.
- **HR 4 oktober 2019, ECLI:NL:HR:2019:1525** (uitspraak Hoge Raad over winsttoerekening innovatiebox): bevestigt dat afpelmethode default is en dat forfait alleen geldt bij expliciete keuze.
- **Kamerstukken II 2016/17, 34552, nr. 3** (Memorie van Toelichting Wet aanpassing fiscale eenheid en wijziging innovatiebox): wetshistorie voor BEPS-implementatie per 1 januari 2017.

## Cross-app integration

De capability `bookkeeping-innovatiebox-administratie` werkt nauw samen met drie andere bookkeeping-capabilities binnen shillinq en met externe registers:

**bookkeeping-vpb-corporate-tax**: levert de aggregatie van alle Vpb-aangiftebestanddelen waarvan de innovatieboxbijlage onderdeel uitmaakt. De `IBProfitAttribution` records voeden direct regel 23 van de Vpb-aangifte (verlaging belastbaar bedrag wegens innovatiebox). Reguliere winstbestanddelen (regels 1-22) MOETEN gecorrigeerd worden voor de aan innovatiebox toegerekende kosten en opbrengsten; deze contra-boekingen lopen via `bookkeeping-vpb-corporate-tax`. Bij fiscale eenheid (art. 15 Vpb) consolideert `bookkeeping-vpb-corporate-tax` de innovatieboxposities van alle gevoegde dochters.

**bookkeeping-wbso-sno-administratie**: levert de bronadministratie van S&O-verklaringen, gerealiseerde S&O-uren per medewerker per project, en de jaarlijkse mededeling werkelijke S&O-uren aan RVO. Deze capability is de single source of truth voor S&O-toegangstickets en S&O-loonurenkosten. De innovatieboxcapability consumeert deze data via een interne API die zowel periodieke kostentoerekening (per kwartaal) als correcties op de mededeling (binnen drie maanden na boekjaarsluiting) ondersteunt.

**bookkeeping-cost-centers-dimensions**: levert het dimensionele kader (kostenplaatsen, kostendragers, projecten) waarop kostentoerekeningen worden vastgelegd. Een kwalificerend activum krijgt typisch een eigen kostendrager (bijv. `kd-rd-platform-team`) waarop alle directe kosten geboekt worden. De doorsnijdingscontrole uit Requirement 5 leunt op consistente dimensietoekenning in deze capability. R&D-overhead-allocatie (huur, IT, ondersteuning) verloopt via verdelingssleutels die in `bookkeeping-cost-centers-dimensions` zijn gedefinieerd.

Daarnaast bestaan koppelingen met `bookkeeping-payroll` (voor R&D-loonkostenbron buiten WBSO-uren, met name management-loonkosten in mate van betrokkenheid), `bookkeeping-fixed-assets` (voor geactiveerde immateriele activa met afschrijving), en `bookkeeping-intercompany` (voor intra-groep royalty- en kostenstromen rond verbonden lichamen).

## Target users

**Fiscalist mid-market BV (50-500 fte)**: typisch een in-house fiscalist of head of tax bij een Nederlandse BV met WBSO-toegang en jaarlijkse innovatiewinst tussen EUR 100k en EUR 5 miljoen. Werkt met een externe belastingadviseur voor de jaarlijkse Vpb-aangifte en wil tussen aangiftes door tussentijdse innovatieboxposities kunnen monitoren. Wil scenario's kunnen doorrekenen (bijv. effect van extra R&D-uitbesteding aan een Indiase dochter op de nexusbreuk) zonder daarvoor de adviseur te hoeven inschakelen. Heeft behoefte aan een dashboard met per activum de kwalificerende winst-prognose en het verwachte Vpb-voordeel.

**Controller R&D-intensieve scale-up (20-200 fte)**: typisch CFO of financial controller bij een SaaS- of biotech-scale-up met meerdere S&O-projecten, vaak Series A/B/C gefinancierd. Heeft geen eigen fiscalist en is afhankelijk van een Big Four of mid-tier accountantskantoor voor innovatiebox-onderbouwing. Wil de administratieve last per S&O-project minimaliseren door directe koppeling tussen projectadministratie (Jira/Linear), tijdregistratie, loonadministratie en innovatiebox-toerekening. Heeft als belangrijkste KPI het percentage R&D-kosten dat als kwalificerende eigen R&D telt in de nexusbreuk, omdat dit direct het Vpb-voordeel beinvloedt.

**Externe belastingadviseur (Big Four / mid-tier / boutique)**: typisch een fiscalist binnen de Vpb-praktijk die meerdere clienten met innovatieboxposities bedient en jaarlijks de innovatieboxbijlage bij de Vpb-aangifte opstelt. Heeft behoefte aan een multi-tenant view waarbij per client de innovatieboxadministratie wordt bijgehouden en jaarlijks de berekening reproduceerbaar is voor controle door de Belastingdienst. Wil VSO-onderhandelingen met de Belastingdienst kunnen onderbouwen met meerjarige historische data en scenario-analyses. Hecht groot belang aan audit-trail en versiebeheer omdat innovatiebox-aanslagen tot vijf jaar na aangifte kunnen worden gecorrigeerd.

Een vierde, latente doelgroep is de **Belastingdienst-controleambtenaar** zelf: bij een innovatiebox-controle moet de belastingplichtige binnen redelijke termijn een complete onderbouwing kunnen leveren. De capability is zo ontworpen dat een export voor de Belastingdienst (in PDF + onderliggende CSV/XBRL) binnen enkele minuten te produceren is, wat de controleduur en de kans op informatiebeschikkingen materieel verlaagt.

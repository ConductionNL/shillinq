---
status: draft
---
# Verplichtingenadministratie (committed vs paid)

## Purpose

Een verplichtingenadministratie ("commitment accounting" in internationale terminologie) is het sluitstuk van een volwassen overheidsboekhouding. Waar de cashflow-administratie alleen betalingen ziet en de baten-lastenadministratie facturen op factuurdatum boekt, registreert de verplichtingenadministratie het moment waarop een organisatie zich juridisch of bestuurlijk bindt aan een toekomstige uitgave — typisch bij ondertekening van een inkooporder, gunningsbesluit, subsidiebeschikking, arbeidscontract of raamovereenkomst. Voor decentrale overheden in Nederland is dit niet enkel best practice maar wordt het in toenemende mate verwacht door interne toezichthouders, externe accountants en — onder de Wet versterking decentrale rekenkamers en de hernieuwde aandacht voor rechtmatigheid sinds 2023 — door de raad. Voor commerciële MKB-klanten van shillinq is verplichtingenadministratie de basis voor cashflow-prognoses, kredietruimte-bewaking en investeringssturing.

De doelstelling van deze module is om in shillinq een eerste-klas register `verplichtingen` te introduceren dat de hele levenscyclus van een verplichting volgt — van potentieel (offerte-aanvraag) via formeel aangegaan (PO/contract) en deelprestatie-ontvangst en facturering tot betaling en afsluiting — en om de berekening van vrije budgetruimte te wijzigen van "budget minus gerealiseerd" naar "budget minus gerealiseerd minus openstaande verplichtingen". Dat laatste is een fundamentele wijziging: het beschikbare budget zoals een budgethouder dat ziet, daalt op het moment van PO-ondertekening, niet pas op factuurdatum.

Daarnaast moet de module multi-jaarse verplichtingen aankunnen (een raamovereenkomst voor onderhoud over vier jaar moet jaarlijks haar deel van het budget consumeren), de mandaatregeling van de organisatie afdwingen (wie mag tot welk bedrag aangaan, wie tekent boven 100.000 EUR, etc.), en de verplichting kunnen koppelen aan een BBV-programma, een kostenplaats én een grootboekrekening tegelijk. Bij realisatie (factuur) wordt de verplichting verminderd; bij definitieve afsluiting wordt eventueel restant vrijgemaakt naar het budget. De koppeling met de rechtmatigheidsverantwoording (parallelle brief) is intensief: toetsing bij de verplichting in plaats van bij de factuur scheelt enorm veel werk omdat één PO honderden deelfacturen kan dekken.

Deze brief beschrijft het register `inkoop` met schema's `verplichting`, `verplichtingsregel`, `verplichtingsmutatie`, `mandaat`, `goedkeuringsstap`, en uitbreidingen aan bestaande schema's `journaalpost` en `budget` om de drie-staps boekhouding (aangegaan → ontvangen → gefactureerd) te ondersteunen.

## Data Model (entities + Dutch JSON)

### Schema `verplichting`
De hoofdentiteit; één per juridisch bindend moment (PO, contract, beschikking).

```json
{
  "id": "uuid",
  "verplichtingsnummer": "VPL-2026-00874",
  "soort": "inkooporder|raamovereenkomst|arbeidscontract|subsidiebeschikking|huurovereenkomst|leasing|overig",
  "aangaandatum": "2026-04-15",
  "looptijd_van": "2026-05-01",
  "looptijd_tot": "2030-04-30",
  "tegenpartij": {
    "soort": "leverancier|werknemer|subsidieontvanger|verhuurder",
    "kvk": "12345678",
    "naam": "Voorbeeld B.V.",
    "iban": "NL91ABNA0417164300",
    "btw_nummer": "NL001234567B01"
  },
  "totaalbedrag_excl_btw": 248000.00,
  "totaalbedrag_incl_btw": 300080.00,
  "valuta": "EUR",
  "btw_regime": "verlegd|standaard|vrijgesteld",
  "status": "concept|in_goedkeuring|aangegaan|deels_geleverd|deels_gefactureerd|deels_betaald|afgesloten|geannuleerd",
  "gerelateerde_aanbesteding": "TenderNed:2024/S-117-356721|nullable",
  "raamovereenkomst": "uuid|nullable",
  "regels": ["uuid", "uuid"],
  "mutaties": ["uuid", "uuid"],
  "goedkeuringen": ["uuid"],
  "mandaat_toegepast": "uuid|ref:mandaat",
  "rechtmatigheidstoetsen": ["uuid"],
  "interne_kenmerk": "ICT-modernisering-2026",
  "documenten": ["file:uuid", "file:uuid"]
}
```

### Schema `verplichtingsregel`
Eén regel per budget-coderingscombinatie; één PO kan over meerdere programma's en jaren gespreid zijn.

```json
{
  "id": "uuid",
  "verplichting": "uuid|ref:verplichting",
  "regelnummer": 1,
  "omschrijving": "Licentie ERP-platform 2026",
  "boekjaar": 2026,
  "bedrag_excl_btw": 62000.00,
  "bedrag_incl_btw": 75020.00,
  "grootboekrekening": "4310",
  "kostenplaats": "KP-1042",
  "programma": "5.1",
  "btw_code": "21H",
  "verwacht_geleverd_op": "2026-12-31",
  "geleverd_bedrag": 0.00,
  "gefactureerd_bedrag": 0.00,
  "betaald_bedrag": 0.00,
  "restant_verplicht": 62000.00,
  "afgesloten": false
}
```

### Schema `verplichtingsmutatie`
Onveranderlijke registratie van elke wijziging — verhoging, verlaging, prestatie-ontvangst, factuur, betaling, afsluiting.

```json
{
  "id": "uuid",
  "verplichting": "uuid",
  "verplichtingsregel": "uuid|nullable",
  "datum": "2026-08-22",
  "soort": "aangegaan|verhoogd|verlaagd|prestatie_ontvangen|gefactureerd|betaald|afgesloten|geannuleerd",
  "bedrag": 15500.00,
  "valuta": "EUR",
  "toelichting": "Factuur 2026-441 verwerkt voor Q3-licenties.",
  "gerelateerde_factuur": "uuid|nullable",
  "gerelateerde_betaling": "uuid|nullable",
  "journaalpost": "uuid|nullable",
  "gebruiker": "uuid"
}
```

### Schema `mandaat`
De organisatorische bevoegdheid tot aangaan van verplichtingen.

```json
{
  "id": "uuid",
  "mandaatcode": "M-INKOOP-50K",
  "naam": "Inkoopmandaat tot 50.000 EUR",
  "houder": "uuid|ref:gebruiker|ref:functie",
  "maximumbedrag": 50000.00,
  "soort_verplichting": ["inkooporder", "raamovereenkomst"],
  "uitsluitingen": ["ICT-aankopen > 25.000"],
  "geldig_van": "2025-01-01",
  "geldig_tot": "2027-12-31",
  "vastgesteld_bij": "Mandaatbesluit 2024-117",
  "vereist_tweede_handtekening_boven": 25000.00
}
```

### Schema `goedkeuringsstap`
Workflow-stap bij aangaan van een verplichting die mandaat-niveau overschrijdt.

```json
{
  "id": "uuid",
  "verplichting": "uuid",
  "stapnummer": 1,
  "rol_vereist": "budgethouder|teamleider|directeur|college",
  "toegewezen_aan": "uuid",
  "status": "wachtend|in_behandeling|goedgekeurd|afgewezen|teruggezonden",
  "behandeld_op": "2026-04-12T14:30:00Z",
  "opmerking": "Akkoord, past binnen meerjareninvestering ICT.",
  "vereist_handtekening": true,
  "handtekening_bestand": "file:uuid|nullable"
}
```

### Uitbreiding `budget`
Toegevoegd: `vrije_ruimte` = `geautoriseerd_bedrag - gerealiseerd_bedrag - openstaande_verplichtingen`.

```json
{
  "geautoriseerd_bedrag": 750000.00,
  "gerealiseerd_bedrag": 412000.00,
  "openstaande_verplichtingen": 187000.00,
  "vrije_ruimte": 151000.00,
  "verplichtingen": ["uuid", "uuid", "uuid"]
}
```

## Requirements

### REQ-VPL-001: Verplichting aangaan met budget-blokkering
Bij aangaan van een verplichting moet de vrije budgetruimte direct dalen met het verplichte bedrag, ook al is er nog geen factuur of betaling.

- GIVEN een budget op programma 5.1 met `vrije_ruimte=200.000`, WHEN een verplichting van 75.000 EUR wordt aangegaan, THEN daalt `vrije_ruimte` naar 125.000 EUR en stijgt `openstaande_verplichtingen` naar 75.000 EUR zonder dat `gerealiseerd_bedrag` wijzigt.
- GIVEN een verplichting verspreid over twee boekjaren (40.000 in 2026, 40.000 in 2027), WHEN deze wordt aangegaan, THEN wordt elk jaardeel afzonderlijk op het budget van dat boekjaar geblokkeerd.
- GIVEN een poging om een verplichting van 250.000 EUR aan te gaan terwijl de vrije ruimte 200.000 EUR is, WHEN de gebruiker geen mandaat heeft voor overschrijding, THEN wordt de verplichting niet aangegaan en moet eerst een budgetoverheveling of -ophoging worden geregeld.

### REQ-VPL-002: Mandaattoetsing bij aangaan
Geen verplichting kan op `aangegaan` worden gezet zonder geldige mandaatreferentie en eventueel goedkeuringsworkflow.

- GIVEN een gebruiker met mandaat tot 50.000 EUR, WHEN deze een verplichting van 30.000 EUR aangaat, THEN wordt het mandaat geregistreerd en gaat de status direct naar `aangegaan`.
- GIVEN een gebruiker met mandaat tot 50.000 EUR die een PO van 75.000 EUR opvoert, WHEN de verplichting wordt ingediend, THEN gaat de status naar `in_goedkeuring` en wordt automatisch een goedkeuringsstap aangemaakt voor de naasthogere mandaathouder.
- GIVEN een verplichting van 30.000 EUR met vereiste `tweede_handtekening_boven=25000`, WHEN ingediend, THEN moet zowel de aanvrager als de tweede tekenaar de verplichting bevestigen voordat status `aangegaan` wordt.

### REQ-VPL-003: Drie-staps registratie aangegaan-ontvangen-gefactureerd
De levenscyclus moet drie afzonderlijke momenten kennen met aparte mutaties.

- GIVEN een verplichting voor levering van 100 stoelen à 250 EUR, WHEN 60 stoelen worden geleverd, THEN wordt een `verplichtingsmutatie` met `soort=prestatie_ontvangen` en `bedrag=15000` aangemaakt en stijgt `geleverd_bedrag` op de regel; `restant_verplicht` blijft ongewijzigd.
- GIVEN ontvangst van 60 stoelen is geregistreerd, WHEN een factuur voor die 60 stoelen wordt geboekt, THEN wordt mutatie `gefactureerd` aangemaakt, daalt `restant_verplicht` met 15.000, stijgt `gefactureerd_bedrag` op de regel, en wordt een journaalpost geboekt op de crediteuren-rekening.
- GIVEN factuur is geboekt maar nog niet betaald, WHEN de cashpositie wordt opgevraagd, THEN telt het bedrag mee als kortlopende schuld; de verplichting telt niet meer mee als openstaande verplichting voor budgetdoeleinden (wel voor cashflow-prognose).

### REQ-VPL-004: Multi-jaarse verplichtingen en raamovereenkomsten
Het systeem moet verplichtingen aankunnen met looptijd over meerdere boekjaren, met jaarlijkse budgetreservering en periodieke afroep.

- GIVEN een raamovereenkomst van 4 jaar à 100.000 EUR per jaar, WHEN deze wordt vastgelegd, THEN worden vier verplichtingsregels aangemaakt (één per boekjaar) en wordt op elk jaarbudget 100.000 EUR geblokkeerd.
- GIVEN dezelfde raamovereenkomst, WHEN een afroep van 25.000 EUR voor 2027 wordt geboekt, THEN consumeert die de regel van 2027 zonder de regels van 2026, 2028, 2029 te raken.
- GIVEN een raamovereenkomst die jaarlijks 100.000 EUR mag bedragen maar nooit hoger dan 1.000.000 EUR over de looptijd, WHEN een afroep ertoe zou leiden dat de jaarcap wordt overschreden, THEN wordt de afroep geweigerd en geadviseerd de verplichting te verhogen via wijzigingsworkflow.

### REQ-VPL-005: Koppeling met inkooporder en factuur (drie-weg-match)
Bij factuurverwerking moet shillinq controleren of er een geldige verplichting bestaat en of de drie-weg-match (PO ↔ ontvangstbevestiging ↔ factuur) klopt.

- GIVEN een factuur die verwijst naar PO VPL-2026-00874 voor een prestatie die al volledig is ontvangen, WHEN de factuur wordt geboekt, THEN wordt deze automatisch aan de juiste verplichtingsregel gekoppeld en doorgezet naar betaling.
- GIVEN een factuur 10% boven het PO-bedrag, WHEN deze wordt geboekt, THEN gaat de factuur naar status `in_behandeling_afwijking` en moet de budgethouder de afwijking goedkeuren met onderbouwing (raakt rechtmatigheidstoets `calculatie` en mogelijk `voorwaarden`).
- GIVEN een factuur zonder PO-referentie boven 5.000 EUR, WHEN deze wordt aangeboden, THEN wordt de boeking geweigerd met de melding "Verplichting ontbreekt; eerst PO opvoeren" tenzij de factuur valt in een toegestane uitzonderingscategorie (energierekening, belastingaanslag, etc.).

### REQ-VPL-006: Wijzigingen, verhogingen en annulering
Verplichtingen moeten gewijzigd kunnen worden binnen dezelfde audit-trail; wijzigingen zijn altijd toevoegende mutaties.

- GIVEN een verplichting van 50.000 EUR die met 10.000 wordt verhoogd, WHEN de wijziging wordt geregistreerd, THEN ontstaat een mutatie `verhoogd` met `bedrag=10000`, totaalbedrag wordt 60.000, en de verhoging vereist opnieuw mandaattoetsing als de nieuwe totaal het mandaatniveau overschrijdt.
- GIVEN een verplichting van 100.000 EUR die volledig wordt geannuleerd vóór levering, WHEN geannuleerd, THEN wordt mutatie `geannuleerd` aangemaakt, status gaat naar `geannuleerd`, en 100.000 EUR vrije budgetruimte komt terug.
- GIVEN een verplichting waarvan slechts 80% wordt geleverd en de rest komt te vervallen, WHEN afgesloten met mutatie `afgesloten`, THEN komt het ongebruikte restant (20%) terug in de vrije budgetruimte en wordt de regel op `afgesloten=true` gezet.

### REQ-VPL-007: Salaris- en personeelsverplichtingen
Arbeidscontracten moeten als verplichting worden vastgelegd met automatische maandelijkse realisatie.

- GIVEN een arbeidscontract van een nieuwe medewerker met bruto 4.500 EUR/maand voor 12 maanden, WHEN het contract wordt vastgelegd, THEN wordt een verplichting van 54.000 EUR (excl. werkgeverslasten) aangemaakt met soort `arbeidscontract`.
- GIVEN maandelijkse loonbetaling, WHEN de salarisrun wordt verwerkt, THEN wordt elk medewerkerdeel automatisch afgeboekt van de bijbehorende verplichting en wordt het restant zichtbaar in cashflow-prognose.
- GIVEN een contract voor onbepaalde tijd, WHEN vastgelegd, THEN wordt standaard 24 maanden vooruit als verplichting geboekt met jaarlijkse hernieuwing tijdens budget-cyclus.

### REQ-VPL-008: Subsidieverplichtingen
Verleende subsidies moeten als verplichting worden vastgelegd op moment van beschikking, niet op moment van uitbetaling.

- GIVEN een subsidiebeschikking van 50.000 EUR voor periode 1 juli 2026 t/m 30 juni 2027, WHEN de beschikking wordt vastgelegd, THEN ontstaat een verplichting met twee regels (25.000 op 2026, 25.000 op 2027) en blokkering op het juiste budget.
- GIVEN een subsidie met voorschotregeling 80% / eindafrekening 20%, WHEN deelbetalingen worden gedaan, THEN worden mutaties `gefactureerd` en `betaald` op de juiste deelbedragen geboekt en wordt het restant gevolgd tot eindverantwoording.
- GIVEN een subsidie die bij eindverantwoording lager wordt vastgesteld (40.000 in plaats van 50.000), WHEN de vaststellingsbeschikking wordt verwerkt, THEN wordt een `verlaagd`-mutatie van 10.000 EUR geboekt en komt 10.000 EUR vrij in het oorspronkelijke budgetjaar.

### REQ-VPL-009: BBV-programma-rapportage met verplichtingen
De BBV-rapportage moet per programma ook openstaande verplichtingen tonen.

- GIVEN het BBV-rapport per programma over Q2 2026, WHEN gerenderd, THEN bevat het per programma kolommen `geautoriseerd`, `gerealiseerd`, `openstaande_verplichtingen`, `vrije_ruimte` en `prognose_einde_jaar`.
- GIVEN een programma waarbij verplichtingen > geautoriseerd budget, WHEN gerenderd, THEN wordt het programma rood gemarkeerd en wordt automatisch een melding richting concerncontroller gestuurd.

### REQ-VPL-010: Audit-trail en rechtmatigheidskoppeling
Elke mutatie is onveranderlijk en de verplichting kan rechtmatigheidstoetsen dragen (verlaagt werklast op factuurniveau).

- GIVEN een verplichting van 75.000 EUR voor ICT-diensten, WHEN aangegaan, THEN worden automatisch toetsen `begroting`, `mandaat` en `europees_aanbesteden` uitgevoerd op het verplichtingsbedrag en gekoppeld via `rechtmatigheidstoetsen`.
- GIVEN dezelfde verplichting waarvoor reeds toets `europees_aanbesteden=voldoet` is geregistreerd, WHEN later facturen onder de verplichting binnenkomen, THEN refereren de factuurtoetsen aan de PO-toets en wordt slechts gecontroleerd of de factuur binnen scope blijft.

## Standards & Sources

- **BBV — Besluit Begroting en Verantwoording provincies en gemeenten** art. 21 en 28 (programmaplan en programmaverantwoording); de Notitie Verbonden Partijen, en de Notitie Investeringen 2017 (laatst herzien 2022).
- **Commissie BBV — Notitie Materiële Vaste Activa, Notitie Subsidies, Notitie Aanbesteden** (verschillende jaartallen 2018-2023) — geven specifieke regels voor verplichtingen rond investeringen, subsidies, en aanbestedingen.
- **Aanbestedingswet 2012** + **PIANOo-richtlijnen** — bepaalt drempels die in mandaten en goedkeuringsworkflows moeten worden afgedwongen.
- **Mandaatregeling Gemeentewet** art. 168 e.v. — wettelijke basis voor mandaatschema's binnen overheden.
- **IPSAS 19 — Provisions, Contingent Liabilities and Contingent Assets** — internationale standaard voor overheidsboekhouding; geeft inspiratie voor onderscheid juridisch versus feitelijk aangaan.
- **NEN 7522** — Procesgang inkoop tot betaling (PtoP) voor de Nederlandse overheid; standaardiseert drie-weg-match en factuurworkflow.
- **UBL 2.1 + SimplerInvoicing 2.0** — invoicing-standaard; PO-referentie als verplicht veld voor matching.
- **PEPPOL BIS Billing 3.0** — Europese e-invoicing met PO-referentie als matchingsleutel.
- **VNG Model Inkoop- en Aanbestedingsbeleid 2024** — beleidskader dat mandaten en drempels per gemeente vastlegt.
- **MKB-praktijk:** ISO 9001 procurement-controls als referentie voor commerciële variant van mandaten en goedkeuringen.

## Cross-app integration

- **bookkeeping-general-ledger**: ontvanger van journaalposten bij realisatie- en betaalmomenten.
- **bookkeeping-budget-forecast**: budgetbewaking gebruikt voortaan `vrije_ruimte` inclusief verplichtingen; prognose-module gebruikt verwachte realisatiedata uit verplichtingsregels.
- **bookkeeping-rechtmatigheidsverantwoording** (parallel brief): kernintegratie — toetsen worden bij voorkeur op verplichtingsniveau uitgevoerd.
- **bookkeeping-bbv-compliance**: programma-indeling als verplicht veld op verplichtingsregels.
- **bookkeeping-purchase-invoice** (bestaand): factuurverwerking koppelt aan PO; drie-weg-match-logica.
- **bookkeeping-payroll** of via ExApp: arbeidscontracten worden aangeboden als verplichting; maandsalarissen consumeren verplichting.
- **OpenConnector — TenderNed**: import van aanbestede contracten en raamovereenkomsten als startpunt voor verplichting.
- **OpenConnector — DigiInkoop / Leverancier-portaal**: PO-uitwisseling en orderbevestiging in PEPPOL/UBL.
- **OpenConnector — Bank**: betaalrun consumeert betaalmutaties op verplichtingen.
- **procest**: workflow-engine voor goedkeuringen, escalaties bij doorlooptijdoverschrijding, mandaat-bypass-aanvragen.
- **decidesk**: bestuurlijke besluitvorming over grote contracten/raamovereenkomsten (collegebesluit als bron-document gekoppeld aan verplichting).
- **DocuDesk**: archivering van PO-PDF, ondertekend contract, beschikkingsbrief subsidie.
- **OpenRegister**: drager van het `inkoop`-register en audit-log.
- **larpingapp / openklant**: bron van tegenpartijgegevens (KvK-validatie, IBAN-check).

## Target users

- **Inkoopadviseur** — opvoert verplichtingen, beheert raamovereenkomsten, monitort PO-portefeuille.
- **Budgethouder** — bewaakt vrije ruimte en goedkeurt afwijkingen; krijgt waarschuwing bij dreigende overschrijding.
- **Crediteurenadministrateur** — verwerkt facturen tegen PO's (drie-weg-match); ziet welke facturen ontbrekende PO-referentie hebben.
- **Concerncontroller** — rapporteert verplichtingen-positie aan management; gebruikt voor cashflow-prognose.
- **Teamleider / Directeur** — goedkeurder in workflow boven mandaatniveau.
- **College van B&W** — goedkeuringsstap voor verplichtingen boven collegegrenzen; eindverantwoordelijk voor rechtmatigheid.
- **HR-medewerker** — voert arbeidscontracten op als verplichting; bewaakt looptijden en hernieuwingen.
- **Subsidiebeheerder** — voert beschikkingen op; volgt voorschotten en eindverantwoording.
- **VIC / interne auditor** — controleert mandaat-naleving en drie-weg-match-kwaliteit via dashboard.
- **Externe accountant** — toetst de getrouwheid van openstaande-verplichtingenpositie per balansdatum; vereist bewijsstukken via attached files.
- **MKB-ondernemer (commerciële variant)** — gebruikt vereenvoudigde versie voor cashflow-prognose op basis van openstaande verplichtingen aan leveranciers en personeelskosten.

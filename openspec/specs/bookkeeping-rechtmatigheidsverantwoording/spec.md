---
status: done
---

# Specification: Rechtmatigheidsverantwoording

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T2 (compliance + operations)  
**Depends on:** bookkeeping-general-ledger, bookkeeping-bbv-compliance, bookkeeping-verplichtingenadministratie, OpenRegister (audit-log + files), procest, TenderNed (optional)

---

## Purpose

Rechtmatigheidsverantwoording (BBV artikel 17a, mandatory since 2023) declares that all financial transactions of a decentrale overheid have been processed legitimately. This spec defines:

- **Rechtmatigheidstoets:** automatic or manual assessment of one journaalpost against one of nine wettelijke criteria.
- **Rechtmatigheidsbevinding:** quantification of errors (fouten) or uncertainties (onzekerheden) when a toets fails.
- **Rechtmatigheidsparagraaf:** aggregated jaarrekening statement per boekjaar.
- **Tolerantiegrens:** raadsbesluit-defined error/uncertainty thresholds (default 3% / 1% per BADO).
- **Journaalpost extension:** mandatory `rechtmatigheid` sub-object tracking toets status and linked toetsen.

---

@e2e exclude unbuilt UI: rechtmatigheidsverantwoording pages not yet implemented

## Requirements

### Requirement: REQ-RV-001 Automatic Lightweight Toetsing on Journaalpost Creation

The system MUST subject every journaalpost to an automatic minimum set of legitimacy checks on creation.

Iedere journaalpost moet bij creatie automatisch worden onderworpen aan een minimumset van geautomatiseerde toetsen (begroting, calculatie, valutering, adressering, volledigheid) zodat handmatige werklast beperkt blijft tot de materiële criteria.

#### Scenario: Five automatic checks pass

**GIVEN** een nieuwe crediteurenfactuur van 25.000 EUR op grootboekrekening 4310 programma 5.1 met tegenrekening 1200 (creditzijde) op 2026-03-15 (binnen boekjaar 2026)  
**WHEN** de journaalpost wordt geboekt (status = geplaatst)  
**THEN** worden vijf `rechtmatigheidstoets`-records aangemaakt (criterium: begroting, calculatie, valutering, adressering, volledigheid) met `toetstype = automatisch` en alle vijf retourneren `uitkomst = voldoet`; `journaalpost.rechtmatigheid.status` wordt op `getoetst` gezet met `samenvattend_oordeel = voldoet`.

#### Scenario: Budget limit exceeded, error recorded

**GIVEN** de begrotingsruimte op programma 5.1 boekjaar 2026 nog slechts 12.000 EUR vrij is  
**WHEN** een factuur van 25.000 EUR wordt geboekt op dezelfde programma  
**THEN** retourneert de begrotingstoets `uitkomst = voldoet_niet` met `bedrag_betrokken = 25000`; automatisch wordt een `rechtmatigheidsbevinding` aangemaakt met `soort = fout`, `bedrag_fout = 13000`, `criterium = begroting`, `boekjaar = 2026`, `programma = 5.1`, `status = open`; de boeking wordt WEL doorgevoerd (journaalpost status = geplaatst), maar portefeuillehouder programma 5.1 ontvangt een notificatie.

#### Scenario: Missing debit/credit or incomplete data blocks booking

**GIVEN** een journaalpost zonder tegenrekening op de creditzijde (debitzijde bedrag, creditzijde leeg)  
**WHEN** de adresseringstoets draait (valideert: both debit en credit gekoppeld)  
**THEN** retourneert de adresseringstoets `uitkomst = voldoet_niet`; `journaalpost.rechtmatigheid.status` = `in_behandeling`; de boeking wordt GEBLOKT en mag niet naar `geplaatst` totdat een geldige tegenrekening is geregistreerd.

**Implementation note:** Automatic toetsen trigger synchronously on journaalpost.create via `x-openregister-actions`. Queries:
- **begroting:** SUM(journaalposten.bedrag where programma = X AND boekjaar = 2026) vs. budget from `bookkeeping-bbv-compliance`.
- **calculatie:** debit_total = credit_total (unbalanced journal posts fail).
- **valutering:** toetsdatum must be within boekjaar start/end dates.
- **adressering:** both debit-account and credit-account have valid FK + are not archived.
- **volledigheid:** required fields (bedrag, journaalpost_datum, debit-account, credit-account, tegenpartij if AP/AR) are present.

---

### Requirement: REQ-RV-002 Manual Material Criteria with Evidence Attachment

The system MUST allow the material criteria to be assessed manually with supporting evidence attachments.

De criteria Europees aanbesteden, staatssteun, voorwaarden en M&O moeten handmatig of via gerichte workflow-koppeling getoetst kunnen worden, met onderbouwing en bewijsstukken.

#### Scenario: High-value procurement triggers manual aanbestedings-toets

**GIVEN** een inkoopfactuur boven de signaaldrempel van 50.000 EUR (leverancier bedrag) voor leveringen  
**WHEN** de factuur wordt aangeboden voor betaling (in `verplichtingenadministratie` of direct in AP)  
**THEN** wordt automatisch een handmatige toets `criterium = europees_aanbesteden` aangemaakt in `toetstype = handmatig`, `status = in_behandeling`; een procest-task wordt aangemaakt en toegewezen aan een Inkoopadviseur met due-date = heden + 10 werkdagen. De factuur mag niet naar betaling totdat de toets `uitkomst` is vastgesteld.

#### Scenario: Subsidy requires state aid evidence

**GIVEN** een subsidieverstrekking aan een onderneming voor 150.000 EUR  
**WHEN** de boeking plaatsvindt  
**THEN** wordt automatisch een handmatige toets `criterium = staatssteun` aangemaakt. Een procest-task vraagt om de de-minimis-verklaring (of AGVV-artikel-referentie); het bewijsstuk wordt via `rechtmatigheidstoets.bewijsstukken[]` (file:uuid FK) geattacheerd. De toets kan pas op `uitkomst = voldoet` worden gezet als het bewijsstuk aangehecht is en door de Juridisch Medewerker is goedgekeurd.

#### Scenario: Rejected toets requires 50-char minimum onderbouwing

**GIVEN** een afgewezen toets met `uitkomst = voldoet_niet`  
**WHEN** de toets wordt opgeslagen  
**THEN** valideert het systeem dat `onderbouwing` minimaal 50 karakters bevat (technische check); vereist dat er een `rechtmatigheidsbevinding` aan gekoppeld is (FK `rechtmatigheidsbevinding`); indien beide checks slagen, wordt de toets `status = getoetst` gezet en de bevinding `status = open` (wachttijd op maatregel).

**Implementation note:** Manual toetsen are created by procest task-completion or direct UI form. Procest integration:
- On task creation, `status = in_behandeling`.
- On task completion, status updates to `getoetst`.
- If task escalates (10 werkdagen overdue), notificatie to portefeuillehouder + auditcommissie.

---

### Requirement: REQ-RV-003 Tolerantiegrens Configuration per Fiscal Year

The system MUST allow tolerance thresholds to be configured per fiscal year by council decision.

Toleranties moeten per boekjaar vastgelegd worden bij raadsbesluit; default is wettelijk 3% fout / 1% onzekerheid maar de raad mag scherper stellen.

#### Scenario: Automatic default toleranties on fiscal year open

**GIVEN** geen `tolerantiegrens`-record voor boekjaar 2027  
**WHEN** het boekjaar 2027 wordt geopend (date-based OR manual trigger in app)  
**THEN** worden automatisch defaults aangemaakt met `fout_percentage = 3.0`, `onzekerheid_percentage = 1.0`, `status = concept` (niet geldig totdat raadsbesluit-referentie is ingevuld).

#### Scenario: Council tightens tolerances, aggregations recompute

**GIVEN** een raadsbesluit RB-2026-098 dat scherpere toleranties van 2% fout / 0.5% onzekerheid vastlegt voor boekjaar 2026  
**WHEN** dit wordt ingevoerd in shillinq (via Admin UI: Tolerantiegrens bewerken) met raadsbesluit-referentie  
**THEN** wordt de `tolerantiegrens`-record bijgewerkt met `fout_percentage = 2.0`, `onzekerheid_percentage = 0.5`, `vastgesteld_bij_raadsbesluit = RB-2026-098`, `vastgesteld_op = [date]`; alle lopende aggregaties voor boekjaar 2026 worden opnieuw gecompute en vergeleken tegen de nieuwe grenzen; indien nu fouten/onzekerheden buiten tolerantie vallen, wordt een auditcommissie-alert gegenereerd.

**Implementation note:** Tolerantiegrens seeds are created per administration on first use; raadsbesluit-update triggers `x-openregister-aggregations` re-evaluation via scheduled task.

---

### Requirement: REQ-RV-004 Audit Trail per Toets en Bevinding

The system MUST record every check, status transition and finding change immutably in the audit trail.

Elke toets, statusovergang en wijziging aan een bevinding moet onveranderlijk worden vastgelegd via OpenRegister audit log, conform BADO-eisen voor toetsbare verantwoording.

#### Scenario: Evidence update is audit-logged

**GIVEN** een bestaande `rechtmatigheidstoets` met `onderbouwing = "Factuur 2026-441 past binnen budget"`  
**WHEN** de onderbouwing wordt bijgewerkt naar `"Factuur 2026-441 valt onder raamovereenkomst RO-2024-12 (Europees aanbesteed)"`  
**THEN** wordt in OpenRegister audit-log een entry gemaakt: `{ entity_id, field: onderbouwing, old_value, new_value, user_id, timestamp, reason }`. De oude waarde blijft raadpleegbaar via audit-drill-down; de toets zelf is niet blokkered (update ≠ status change).

#### Scenario: Accountant requests audit export

**GIVEN** een verzoek van de externe accountant tot inzage in alle toetsen die voldoen aan `criterium = europees_aanbesteden` over boekjaar 2026  
**WHEN** de accountant deze query uitvoert via de Audit Export endpoint (UI: "Download audit trail for compliance inspection")  
**THEN** ontvangt deze een CSV (of optioneel ondertekend XBRL) bestand met:
- Alle `rechtmatigheidstoets`-records matching the query.
- Alle gekoppelde `rechtmatigheidsbevinding`-records.
- Complete audit-trail mutations (old/new values per field).
- File references (bewijsstukken).
- Hash/digital signature (optional, via DocuDesk) voor onveranderlijkheid.

**Implementation note:** Audit-trail is automatic per OpenRegister on every mutation. Export is read-only query; audit-log itself is immutable per OR design.

---

### Requirement: REQ-RV-005 Aggregation Against Tolerances and Rollup to Rechtmatigheidsparagraaf

The system MUST aggregate all findings against tolerances into a single rechtmatigheidsparagraaf at year-end close.

Bij afsluiting van het boekjaar moet shillinq alle openstaande bevindingen aggregeren tot één `rechtmatigheidsparagraaf` met de wettelijke verklaring.

#### Scenario: Fouten and onzekerheden within tolerance

**GIVEN** boekjaar 2026 afgesloten met totaal_lasten_inclusief_mutaties_reserves = 142.500.000 EUR, tolerance = 3% fout / 1% onzekerheid  
**AND** 213.400 EUR fout + 89.200 EUR onzekerheid detected across all toetsen  
**WHEN** de paragraaf wordt gegenereerd (manual UI click of scheduled task at periode-end)  
**THEN** wordt de `rechtmatigheidsparagraaf` aangemaakt:
- `tolerantiegrens_fout_bedrag = 142.500.000 * 0.03 = 4.275.000 EUR`
- `tolerantiegrens_onzekerheid_bedrag = 142.500.000 * 0.01 = 1.425.000 EUR`
- `totaal_geconstateerde_fouten = 213.400`
- `totaal_geconstateerde_onzekerheden = 89.200`
- `binnen_tolerantie = true` (both < thresholds)
- `verklaring_college = [standard text citing BBV artikel 17a]`
- `status = concept` (awaiting college besluit)
- All open/in_behandeling bevindingen are listed in `bevindingen[]`.

#### Scenario: Fouten exceed tolerance

**GIVEN** totaal fout 5.100.000 EUR bij tolerantiegrens 4.275.000 EUR  
**WHEN** de paragraaf wordt gegenereerd  
**THEN** wordt `binnen_tolerantie = false` gezet; `verklaring_college` bevat gewijzigde tekst:
> "Het college verklaart dat de in de jaarrekening 2026 verantwoorde baten en lasten alsmede de balansmutaties grotendeels rechtmatig tot stand zijn gekomen, echter met geconstateerde fouten van EUR 5.100.000 die de tolerantielimiet van EUR 4.275.000 (3% van totale lasten) overschrijden. Het college zal deze afwijkingen nader toelichten en zal [...maatregelen...] implementeren."

The portefeuillehouder Financiën is verplicht om een toelichting in te schrijven voordat de paragraaf naar `vastgesteld_college` kan.

**Implementation note:** Aggregation query:
```
SELECT
  SUM(bedrag_fout) totaal_geconstateerde_fouten,
  SUM(bedrag_onzekerheid) totaal_geconstateerde_onzekerheden
FROM rechtmatigheidsbevinding
WHERE boekjaar = 2026 AND status != 'opgelost'
HAVING totaal_geconstateerde_fouten <= tolerantiegrens_fout_bedrag
  AND totaal_geconstateerde_onzekerheden <= tolerantiegrens_onzekerheid_bedrag
```

---

### Requirement: REQ-RV-006 Jaarrekening Export (BBV-conform)

The system MUST export the rechtmatigheidsparagraaf as part of the annual accounts in BBV-conform format.

De rechtmatigheidsparagraaf moet als bestanddeel van de jaarrekening worden geëxporteerd in BBV-conform formaat (XBRL IV3 + PDF bijlage).

#### Scenario: Export finalized paragraph to jaarrekening bundle

**GIVEN** een definitieve `rechtmatigheidsparagraaf` voor boekjaar 2026 (status = definitief)  
**WHEN** de jaarrekening-export draait (triggered via `bookkeeping-financial-statements` module)  
**THEN** wordt de paragraaf opgenomen als:
- XBRL element in de IV3-rapportage richting CBS (per CBS-richtlijnen voor rechtmatigheid).
- PDF bijlage in de jaarrekening-bundel (gerenderd via docudesk; optioneel digitaal ondertekend via college-handtekening).
- Bevindingenlijst als appendix (alle bevindingen met oorzaak + maatregel).

#### Scenario: Unfinalized paragraph blocks export

**GIVEN** een paragraaf voor boekjaar 2026 in `status = concept` (nog niet door college vastgesteld)  
**WHEN** de jaarrekening-export wordt geprobeerd  
**THEN** faalt deze met melding: "Rechtmatigheidsparagraaf nog niet vastgesteld. Ga naar [link] en voltooi college-vaststellingsbesluit."

**Implementation note:** Export consumes the finalized paragraph via `bookkeeping-financial-statements` integration; IV3 template includes the XBRL mapping per CBS DDM definitions.

---

### Requirement: REQ-RV-007 Drempelbedragen en Clustering-detectie voor Europees Aanbesteden

The system MUST know the current EU procurement thresholds and MUST detect supplier invoice clustering.

Het systeem moet de actuele Europese drempelbedragen kennen en factuurclustering per leverancier signaleren.

#### Scenario: Clustering crosses threshold, triggers toets

**GIVEN** drie facturen aan leverancier X (BVD-nummer 12345678) in boekjaar 2026:
- 2026-01-15: EUR 85.000
- 2026-02-20: EUR 90.000
- 2026-03-30: EUR 60.000
- (totaal: EUR 235.000)

**AND** de Europese drempel voor leveringen decentraal = EUR 221.000 (2024-2025)  
**WHEN** de derde factuur (EUR 60.000) wordt geboekt  
**THEN** signaleert het systeem dat de cumulatieve drempel wordt overschreden; automatisch een handmatige toets `criterium = europees_aanbesteden` wordt aangemaakt (in_behandeling); procest-task vraagt om:
- TenderNed-publicatie-referentie (als Europees aanbesteed), OF
- Onderbouwde uitzondering (bv. raamovereenkomst RO-2024-12 die eerder is aanbesteed).

Geen verdere factuurverwerking zonder toets-afronding.

#### Scenario: Framework agreement exempts further posten

**GIVEN** een raamovereenkomst RO-2024-12 met TenderNed-referentie 2024/S-117-356721 (Europees aanbesteed)  
**WHEN** facturen onder deze raamovereenkomst worden geboekt  
**THEN** refereren de automatische toetsen naar de raamovereenkomst; `onderbouwing = "Factuur onder raamovereenkomst RO-2024-12 (TenderNed 2024/S-117-356721, eerder Europees aanbesteed)"`; geen aparte aanbestedingstoets gevraagd (status = voldoet automatisch).

**Implementation note:** Clustering detection runs on journaalpost.create; query leverancier_id (BVD-nummer) + boekjaar; SUM bedragen; compare vs. `lib/Settings/drempelbedragen.json` (2024-2025 values: 221k leveringen, 5.538M werken, 750k sociale diensten).

---

### Requirement: REQ-RV-008 Workflow Integration with Procurement and Obligations

The system MUST integrate legitimacy checks into the procurement and obligation workflow as early as possible.

Rechtmatigheidstoetsing moet zo vroeg mogelijk in het inkoopproces plaatsvinden, idealiter bij de verplichting (PO) zodat de factuur slechts een afronding is.

#### Scenario: PO-level toetsing, factuur inheritance

**GIVEN** een inkooporder (PO) voor EUR 75.000 wordt aangemaakt in `verplichtingenadministratie`  
**WHEN** de PO wordt vastgelegd  
**THEN** worden begroting- en europees_aanbesteden toetsen direct uitgevoerd op de PO. Results (bv. `europees_aanbesteden = voldoet_niet`) worden gemarkeerd. Wanneer de factuur later wordt geboekt en ± 10% van de PO bedrag (EUR 75.000 ± 7.500 = EUR 67.500–82.500) aansluit, worden de PO-toetsen OVERGENOMEN voor de factuur (status = inherited).

#### Scenario: Factuur deviates > 10% from PO, re-toetsing required

**GIVEN** PO voor EUR 75.000 aangemaakt, begroting-toets passed, europees_aanbesteden = voldoet (raamovereenkomst)  
**WHEN** factuur van EUR 82.500 wordt geboekt (> 10% delta: EUR 7.500 / 75.000 = 10%)  
**THEN** de PO-toetsen worden NIET overgenomen; nieuwe rechtmatigheidstoetsen worden aangemaakt voor de factuur; afwijking wordt gerapporteerd in `onderbouwing` ("Factuur EUR 82.500 wijkt af van PO EUR 75.000; waarschijnlijk aanpassingsfactuur; dubbel-checken inhoud tegen PO-originally").

---

### Requirement: REQ-RV-009 Rapportage en Dashboards per Programma en Portefeuille

The system MUST provide reporting and dashboards of the current legitimacy position per programme and portfolio.

Het college, de raad en de auditcommissie moeten op elk moment inzicht hebben in de actuele rechtmatigheidspositie.

#### Scenario: Portfolio holder dashboard

**GIVEN** een gebruiker met rol `portefeuillehouder_programma_5.1`  
**WHEN** deze het rechtmatigheidsdashboard opent (UI: Rechtmatigheid > Mijn Programma's)  
**THEN** ziet deze:
- Openstaande bevindingen op programma 5.1 (list: bevindingsnummer, bedrag, oorzaak, status).
- YTD fouten/onzekerheden totaal vs. tolerantiegrens (gauge/progress-bar).
- Top 5 risicovolle leveranciers / inkoop-stromen (leverancier, YTD bedrag, clustering-status, europees_aanbesteden pending-toetsen).
- Notificatie: "2 europees_aanbesteden toetsen vereisten in-behandeling."

#### Scenario: Quarterly export for audit committee

**GIVEN** de auditcommissie vraagt kwartaalrapportage  
**WHEN** export `rechtmatigheid_kwartaal_Q1_2026` wordt gedownload (UI: Export > Kwartaalrapport)  
**THEN** ontvangt deze een PDF met:
- Geaggregeerde cijfers per programma (fouten/onzekerheden YTD, tolerance status).
- Alle bevindingen > EUR 25.000 (list with oorzaak + maatregel).
- Trendgrafiek: fouten/onzekerheden per kwartaal (Q1, Q2, Q3, Q4 vorig jaar; Q1 dit jaar).
- Toprisico's: leveranciers/procurementstromen met meeste bevindingen.
- Audit trail summary: aantal gewijzigde toetsen, gemiddelde resolution-time.

---

### Requirement: REQ-RV-010 Correctieboeking Linking and Bevinding Resolution

The system MUST keep the original check and finding auditable when a correction entry resolves an error.

Wanneer een fout wordt opgelost via een correctieboeking moet de oorspronkelijke toets en bevinding toetsbaar blijven.

#### Scenario: Correctieboeking links to bevinding, marks resolved

**GIVEN** een bevinding RV-2026-0142 met `bedrag_fout = 13.000` EUR (begroting overschrijding programma 5.1)  
**WHEN** de operator een correctieboeking aanmaakt (debit programma 5.1 budget-reserve, credit programma 5.1 lasten; bedrag EUR 13.000) en deze koppelt aan de bevinding via UI-field "Gekoppelde correctieboeking"  
**THEN** gaat de bevinding naar `status = opgelost`; het origineel `bedrag_fout = 13.000` blijft ongewijzigd in de jaarrekening-paragraaf van het jaar van constatering (compliance geschiedenis); in het dashboard wordt een "resolved" markering getoond; audit-trail wist de koppeling op (`bevinding.correctieboeking_id = [uuid]`, timestamp, user).

#### Scenario: Multiple toetsen linked to one bevinding

**GIVEN** een bevinding RV-2026-0143 voor staatssteun-overschrijding EUR 47.800; drie toetsen linken ernaar (drie staatssteun-facturen die gezamenlijk de de-minimis drempel overschreden)  
**WHEN** operator alle drie toetsen op `uitkomst = voldoet_niet` zet en ze naar dezelfde bevinding linkt  
**THEN** convergeert de bevinding automatie op soort/criterium (de-minimis staatssteun); bedragen worden gesommeerd; paragraaf-aggregatie telt EUR 47.800 eenmaal (niet 3×).

---

## Acceptance Criteria

- [ ] All ten REQ-RV requirements are implemented and tested per requirement scenarios.
- [ ] Automatic toetsen (begroting, calculatie, valutering, adressering, volledigheid) run synchronously on journaalpost.create and complete within 2 seconds per post.
- [ ] Manual toetsen can be assigned via procest with default 10-day due date; escalation notifies portefeuillehouder + auditcommissie on overdue.
- [ ] Audit-trail per toets and bevinding is immutable and exportable in CSV and signed XBRL formats.
- [ ] Bewijsstukken (files) can be attached to toetsen; file deletion is audit-logged.
- [ ] Rechtmatigheidsparagraaf aggregation completes within 5 seconds for 100k+ toetsen.
- [ ] Jaarrekening export includes finalized paragraaf as XBRL + PDF bijlage.
- [ ] Dashboard and quarterly reports render within 3 seconds.
- [ ] All user-facing strings are translatable (i18n: nl_NL, en_US minimum).
- [ ] Compliance test passes: `composer test` and Playwright MCP browser tests for all 5 manifest navigation entries.

# Spec: bookkeeping-rechtmatigheidsverantwoording

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** bookkeeping-general-ledger, bookkeeping-bbv-compliance, bookkeeping-verplichtingenadministratie, OpenRegister (audit-log + files), procest, TenderNed (optional)

Rechtmatigheidsverantwoording (BBV artikel 17a, mandatory since 2023) declares that all financial transactions of a decentrale overheid have been processed legitimately. This capability defines the `Rechtmatigheidstoets`, `Rechtmatigheidsbevinding`, `Rechtmatigheidsparagraaf`, and `Tolerantiegrens` registers and the mandatory `rechtmatigheid` sub-object on the journaalpost (JournalEntry). It is declared as ADR-037 register-fragment metadata (`lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json`) plus one ADR-031 exception-path guard; no bespoke service class is added.

## ADDED Requirements

### Requirement: REQ-RV-001: The system SHALL run automatic lightweight toetsing on journaalpost creation

Iedere journaalpost MUST bij creatie automatisch worden onderworpen aan een minimumset van geautomatiseerde toetsen (begroting, calculatie, valutering, adressering, volledigheid) zodat handmatige werklast beperkt blijft tot de materiële criteria. Automatic toetsen trigger synchronously via the JournalEntry lifecycle. Queries: begroting (SUM journaalposten.bedrag per programma per boekjaar vs. budget from `bookkeeping-bbv-compliance`); calculatie (debit_total = credit_total); valutering (toetsdatum within boekjaar); adressering (both debit/credit accounts valid + not archived); volledigheid (required fields present).

#### Scenario: Five automatic checks pass

- **GIVEN** een nieuwe crediteurenfactuur van 25.000 EUR op grootboekrekening 4310 programma 5.1 met tegenrekening 1200 (creditzijde) op 2026-03-15 (binnen boekjaar 2026)
- **WHEN** de journaalpost wordt geboekt (status = geplaatst)
- **THEN** worden vijf `Rechtmatigheidstoets`-records aangemaakt (criterium: begroting, calculatie, valutering, adressering, volledigheid) met `toetstype = automatisch` en alle vijf retourneren `uitkomst = voldoet`; `journaalpost.rechtmatigheid.status` wordt op `getoetst` gezet met `samenvattend_oordeel = voldoet`.

#### Scenario: Budget limit exceeded, error recorded

- **GIVEN** de begrotingsruimte op programma 5.1 boekjaar 2026 nog slechts 12.000 EUR vrij is
- **WHEN** een factuur van 25.000 EUR wordt geboekt op dezelfde programma
- **THEN** retourneert de begrotingstoets `uitkomst = voldoet_niet` met `bedrag_betrokken = 25000`; automatisch wordt een `Rechtmatigheidsbevinding` aangemaakt met `soort = fout`, `bedrag_fout = 13000`, `criterium = begroting`, `boekjaar = 2026`, `programma = 5.1`, `status = open`; de boeking wordt WEL doorgevoerd, maar portefeuillehouder programma 5.1 ontvangt een notificatie.

#### Scenario: Missing debit/credit or incomplete data blocks booking

- **GIVEN** een journaalpost zonder tegenrekening op de creditzijde
- **WHEN** de adresseringstoets draait (valideert: both debit en credit gekoppeld)
- **THEN** retourneert de adresseringstoets `uitkomst = voldoet_niet`; `journaalpost.rechtmatigheid.status` = `in_behandeling`; de boeking wordt GEBLOKT en mag niet naar `geplaatst` totdat een geldige tegenrekening is geregistreerd.

### Requirement: REQ-RV-002: The system SHALL support manual material criteria with evidence attachment

De criteria Europees aanbesteden, staatssteun, voorwaarden en M&O MUST handmatig of via gerichte workflow-koppeling getoetst kunnen worden, met onderbouwing en bewijsstukken. Manual toetsen are created by procest task-completion or a direct UI form; `status = in_behandeling` on creation, `getoetst` on completion. A `voldoet_niet`/`onzeker` uitkomst requires an onderbouwing of at least 50 characters and a linked `Rechtmatigheidsbevinding` (enforced by `RechtmatigheidGuard::canFinaliseToets`).

#### Scenario: High-value procurement triggers manual aanbestedings-toets

- **GIVEN** een inkoopfactuur boven de signaaldrempel van 50.000 EUR voor leveringen
- **WHEN** de factuur wordt aangeboden voor betaling
- **THEN** wordt automatisch een handmatige toets `criterium = europees_aanbesteden` aangemaakt (`toetstype = handmatig`, `status = in_behandeling`); een procest-task wordt toegewezen aan een Inkoopadviseur met due-date = heden + 10 werkdagen. De factuur mag niet naar betaling totdat de toets `uitkomst` is vastgesteld.

#### Scenario: Subsidy requires state aid evidence

- **GIVEN** een subsidieverstrekking aan een onderneming voor 150.000 EUR
- **WHEN** de boeking plaatsvindt
- **THEN** wordt automatisch een handmatige toets `criterium = staatssteun` aangemaakt; een procest-task vraagt om de de-minimis-verklaring; het bewijsstuk wordt via `Rechtmatigheidstoets.bewijsstukken[]` (file:uuid FK) geattacheerd; de toets kan pas op `uitkomst = voldoet` als het bewijsstuk aangehecht en goedgekeurd is.

#### Scenario: Rejected toets requires 50-char minimum onderbouwing

- **GIVEN** een afgewezen toets met `uitkomst = voldoet_niet`
- **WHEN** de toets wordt afgerond
- **THEN** valideert het systeem dat `onderbouwing` minimaal 50 karakters bevat en dat er een `Rechtmatigheidsbevinding` aan gekoppeld is; indien beide checks slagen wordt de toets `status = getoetst` en de bevinding `status = open`.

### Requirement: REQ-RV-003: The system SHALL configure tolerantiegrens per fiscal year

Toleranties MUST per boekjaar vastgelegd worden bij raadsbesluit; default is wettelijk 3% fout / 1% onzekerheid per BADO, maar de raad MAY scherper stellen. A raadsbesluit-update re-aggregates all paragraaf computations for the boekjaar.

#### Scenario: Automatic default toleranties on fiscal year open

- **GIVEN** geen `Tolerantiegrens`-record voor boekjaar 2027
- **WHEN** het boekjaar 2027 wordt geopend
- **THEN** worden automatisch defaults aangemaakt met `fout_percentage = 3.0`, `onzekerheid_percentage = 1.0`, `status = concept`.

#### Scenario: Council tightens tolerances, aggregations recompute

- **GIVEN** een raadsbesluit RB-2026-098 dat scherpere toleranties van 2% fout / 0.5% onzekerheid vastlegt voor boekjaar 2026
- **WHEN** dit wordt ingevoerd in shillinq met raadsbesluit-referentie
- **THEN** wordt de `Tolerantiegrens`-record bijgewerkt; alle lopende aggregaties voor boekjaar 2026 worden opnieuw gecompute en vergeleken tegen de nieuwe grenzen; indien fouten/onzekerheden nu buiten tolerantie vallen wordt een auditcommissie-alert gegenereerd.

### Requirement: REQ-RV-004: The system SHALL maintain an immutable audit trail per toets and bevinding

Elke toets, statusovergang en wijziging aan een bevinding MUST onveranderlijk worden vastgelegd via OpenRegister audit-log, conform BADO-eisen voor toetsbare verantwoording. Audit-log is automatic per OpenRegister on every mutation and is read-only/exportable.

#### Scenario: Evidence update is audit-logged

- **GIVEN** een bestaande `Rechtmatigheidstoets` met een bestaande onderbouwing
- **WHEN** de onderbouwing wordt bijgewerkt
- **THEN** wordt in OpenRegister audit-log een entry gemaakt met entity_id, field, old_value, new_value, user_id, timestamp en reason; de oude waarde blijft raadpleegbaar via audit-drill-down.

#### Scenario: Accountant requests audit export

- **GIVEN** een verzoek van de externe accountant tot inzage in alle toetsen met `criterium = europees_aanbesteden` over boekjaar 2026
- **WHEN** de accountant de Audit Export uitvoert
- **THEN** ontvangt deze een CSV (of optioneel ondertekend XBRL) met alle matching toetsen, gekoppelde bevindingen, complete audit-trail mutaties, file references en optioneel een hash/handtekening.

### Requirement: REQ-RV-005: The system SHALL aggregate findings against tolerances into a rechtmatigheidsparagraaf

Bij afsluiting van het boekjaar MUST shillinq alle openstaande bevindingen aggregeren tot één `Rechtmatigheidsparagraaf` met de wettelijke verklaring. The aggregation SUMs `bedrag_fout` / `bedrag_onzekerheid` of bevindingen with `status != opgelost` and compares against the tolerantiegrens-derived thresholds.

#### Scenario: Fouten and onzekerheden within tolerance

- **GIVEN** boekjaar 2026 met totaal_lasten = 142.500.000 EUR, tolerance 3% / 1%, en 213.400 EUR fout + 89.200 EUR onzekerheid
- **WHEN** de paragraaf wordt gegenereerd
- **THEN** wordt `tolerantiegrens_fout_bedrag = 4.275.000`, `tolerantiegrens_onzekerheid_bedrag = 1.425.000`, `binnen_tolerantie = true`, `verklaring_college` = standaardtekst, `status = concept`, en alle openstaande bevindingen staan in `bevindingen[]`.

#### Scenario: Fouten exceed tolerance

- **GIVEN** totaal fout 5.100.000 EUR bij tolerantiegrens 4.275.000 EUR
- **WHEN** de paragraaf wordt gegenereerd
- **THEN** wordt `binnen_tolerantie = false`; `verklaring_college` bevat gewijzigde tekst die de overschrijding citeert; de portefeuillehouder Financiën MUST een toelichting inschrijven voordat de paragraaf naar `vastgesteld_college` kan (enforced by `RechtmatigheidGuard::canVaststellenParagraaf`).

### Requirement: REQ-RV-006: The system SHALL export the finalized paragraaf to the jaarrekening (BBV-conform)

De rechtmatigheidsparagraaf MUST als bestanddeel van de jaarrekening worden geëxporteerd in BBV-conform formaat (XBRL IV3 + PDF bijlage), maar alleen wanneer `status = definitief` (enforced by `RechtmatigheidGuard::canExportParagraaf`).

#### Scenario: Export finalized paragraph to jaarrekening bundle

- **GIVEN** een definitieve `Rechtmatigheidsparagraaf` voor boekjaar 2026 (status = definitief)
- **WHEN** de jaarrekening-export draait via `bookkeeping-financial-statements`
- **THEN** wordt de paragraaf opgenomen als XBRL IV3-element richting CBS, als PDF bijlage (gerenderd via docudesk, optioneel ondertekend), en de bevindingenlijst als appendix.

#### Scenario: Unfinalized paragraph blocks export

- **GIVEN** een paragraaf voor boekjaar 2026 in `status = concept`
- **WHEN** de jaarrekening-export wordt geprobeerd
- **THEN** faalt deze met de melding "Rechtmatigheidsparagraaf nog niet vastgesteld."

### Requirement: REQ-RV-007: The system SHALL apply drempelbedragen and clustering detection for europees aanbesteden

Het systeem MUST de actuele Europese drempelbedragen kennen en factuurclustering per leverancier per boekjaar signaleren (2024-2025: 221k leveringen/diensten, 5.538M werken, 750k sociale diensten).

#### Scenario: Clustering crosses threshold, triggers toets

- **GIVEN** drie facturen aan leverancier X (BVD 12345678) in boekjaar 2026 van 85.000 + 90.000 + 60.000 EUR (totaal 235.000) en de Europese drempel voor leveringen = 221.000 EUR
- **WHEN** de derde factuur wordt geboekt
- **THEN** signaleert het systeem de drempel-overschrijding; automatisch wordt een handmatige toets `criterium = europees_aanbesteden` aangemaakt; een procest-task vraagt om een TenderNed-publicatie-referentie of een onderbouwde uitzondering.

#### Scenario: Framework agreement exempts further posten

- **GIVEN** een raamovereenkomst RO-2024-12 met TenderNed-referentie 2024/S-117-356721
- **WHEN** facturen onder deze raamovereenkomst worden geboekt
- **THEN** refereren de automatische toetsen naar de raamovereenkomst (`raamovereenkomst = RO-2024-12`) en geldt de aanbestedingstoets als `voldoet`; geen aparte toets gevraagd.

### Requirement: REQ-RV-008: The system SHALL integrate toetsing with procurement and obligations

Rechtmatigheidstoetsing MUST zo vroeg mogelijk in het inkoopproces plaatsvinden, idealiter bij de verplichting (PO), zodat de factuur slechts een afronding is.

#### Scenario: PO-level toetsing, factuur inheritance

- **GIVEN** een inkooporder (PO) voor 75.000 EUR in `verplichtingenadministratie`
- **WHEN** de PO wordt vastgelegd
- **THEN** worden begroting- en europees_aanbesteden toetsen direct op de PO uitgevoerd; wanneer de factuur later binnen ± 10% (67.500–82.500 EUR) aansluit, worden de PO-toetsen OVERGENOMEN (status = inherited).

#### Scenario: Factuur deviates > 10% from PO, re-toetsing required

- **GIVEN** PO voor 75.000 EUR met passed toetsen
- **WHEN** een factuur van 82.500 EUR wordt geboekt (> 10% delta)
- **THEN** worden de PO-toetsen NIET overgenomen; nieuwe rechtmatigheidstoetsen worden aangemaakt met onderbouwing over de afwijking.

### Requirement: REQ-RV-009: The system SHALL provide reporting and dashboards per programma and portefeuille

Het college, de raad en de auditcommissie MUST op elk moment inzicht hebben in de actuele rechtmatigheidspositie, via dashboards en kwartaalrapportage.

#### Scenario: Portfolio holder dashboard

- **GIVEN** een gebruiker met rol `portefeuillehouder` voor programma 5.1
- **WHEN** deze het rechtmatigheidsdashboard opent
- **THEN** ziet deze de openstaande bevindingen op programma 5.1, YTD fouten/onzekerheden vs. tolerantiegrens, top-5 risicovolle leveranciers en een notificatie over openstaande europees_aanbesteden toetsen.

#### Scenario: Quarterly export for audit committee

- **GIVEN** de auditcommissie vraagt kwartaalrapportage
- **WHEN** de kwartaalrapportage Q1 2026 wordt gedownload
- **THEN** ontvangt deze een PDF met geaggregeerde cijfers per programma, alle bevindingen > 25.000 EUR, een trendgrafiek per kwartaal, toprisico's en een audit-trail samenvatting.

### Requirement: REQ-RV-010: The system SHALL link correctieboekingen and resolve bevindingen

Wanneer een fout wordt opgelost via een correctieboeking MUST de oorspronkelijke toets en bevinding toetsbaar blijven. A bevinding may only move to `opgelost` when a `correctieboeking_id` FK is set (enforced by `RechtmatigheidGuard::canResolveBevinding`); the original `bedrag_fout` remains in the year-of-discovery paragraaf.

#### Scenario: Correctieboeking links to bevinding, marks resolved

- **GIVEN** een bevinding RV-2026-0142 met `bedrag_fout = 13.000` EUR
- **WHEN** de operator een correctieboeking aanmaakt en koppelt via `correctieboeking_id`
- **THEN** gaat de bevinding naar `status = opgelost`; het originele `bedrag_fout` blijft ongewijzigd in de paragraaf van het jaar van constatering; het dashboard toont een "resolved" markering; de koppeling wordt audit-logged.

#### Scenario: Multiple toetsen linked to one bevinding

- **GIVEN** een bevinding RV-2026-0143 voor staatssteun-overschrijding EUR 47.800 met drie gekoppelde toetsen
- **WHEN** alle drie toetsen op `uitkomst = voldoet_niet` staan en naar dezelfde bevinding linken
- **THEN** telt de paragraaf-aggregatie EUR 47.800 eenmaal (niet 3×).

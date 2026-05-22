---
status: draft
---
# Bookkeeping Intercompany Elimination Engine

## Purpose

Wanneer een Nederlandse onderneming uit meerdere juridische entiteiten bestaat — een holding-BV met één of meer werkmaatschappijen, een vastgoed-BV, een internationale dochter — ontstaan onvermijdelijk **intercompany-transacties**: de werkmaatschappij verkoopt diensten aan de holding, de holding leent geld aan de dochter, een vastgoed-BV verhuurt panden aan de operationele BV. Deze transacties zijn in elk van de individuele administraties volstrekt legitiem en moeten daar correct geboekt worden — maar bij **consolidatie** naar concern-niveau moeten ze er weer uit, want vanuit het perspectief van de gezamenlijke aandeelhouder gaat de waarde gewoon van linker- naar rechterbroekzak. Een geconsolideerde jaarrekening die intercompany-omzet meetelt is misleidend: de groep lijkt groter dan ze is, marges worden verstoord, en posities op de balans (debiteuren, crediteuren, leningen) tellen dubbel.

Eliminatie is conceptueel simpel — boek de intercompany-positie aan beide kanten weg — maar in de praktijk het meest foutgevoelige onderdeel van consolidatie. De drie hoofd-problemen zijn: (1) **detectie**: hoe weet je welke transacties intercompany zijn? Niet alle administraties gebruiken aparte intercompany-grootboekrekeningen; soms zit een intercompany-verkoop op een gewone debiteur-rekening met een tekstuele aanduiding. (2) **matching**: welke debet-zijde in entiteit A hoort bij welke credit-zijde in entiteit B? Een verkoop van €100k aan moederkant moet matchen met een inkoop van €100k aan dochterkant, op dezelfde periode, met dezelfde context. (3) **mismatch-handling**: wat doe je als de twee zijden niet exact gelijk zijn? Timing-verschillen (factuur in december, ontvangst in januari), FX-translatie-verschillen, transfer-pricing-correcties die nog niet in beide entiteiten zijn doorgevoerd — al deze veroorzaken kleine of grote afwijkingen die ergens moeten landen.

Vandaag wordt dit overal in Excel of in semi-handmatige consolidatie-tools opgelost. De controller of accountant maakt per periode een Excel-werkblad met aan de linkerkant alle intercompany-saldi van entiteit A, aan de rechterkant alle intercompany-saldi van entiteit B, en in het midden formules die uitrekenen of het matcht. Mismatches worden via e-mail uitgevochten tussen entiteit-administrateurs ("klopt jouw intercompany-saldo wel? Wij zien €25.000 vordering op jullie maar jullie boeken €24.500 schuld..."). Bij een groep met tien entiteiten en honderden intercompany-relaties wordt dit een fulltime baan.

Shillinq's intercompany-eliminatie-module bouwt een dedicated matching-engine die periode-na-periode automatisch alle intercompany-transacties tussen entiteiten matcht, mismatches detecteert en classificeert op oorzaak (timing, FX, transfer-pricing, fout), eliminatie-journaalposten genereert voor de match-bare delta's, en een exception-queue produceert voor de gevallen die handmatige tussenkomst vereisen. De engine onderscheidt zich van Excel-aanpakken door drie eigenschappen: **persisterende intercompany-relatie-registratie** (de wegwijzer hoeft niet elke periode opnieuw bedacht te worden), **tolerantie-gebaseerde auto-resolve** (kleine afwijkingen worden weggeschreven naar een afgesproken bucket zonder menselijke tussenkomst), en **counterparty-saldo-overzicht** (één view die per intercompany-paartje laat zien wat er over en weer staat met audit-trail naar de bron-transacties).

De scope van deze brief is **detectie, matching, en eliminatie-generatie**. Currency translation, goodwill, minority interest, en de uiteindelijke consolidatie-output staan in `bookkeeping-consolidation-commercial`. Het opstellen van de geconsolideerde jaarrekening conform Titel 9 staat in `bookkeeping-titel-9-jaarrekening`.

## Data Model

**IntercompanyRelation**: persistente registratie van een handelsrelatie tussen twee groepsentiteiten. Velden: groep-id (FK naar `ConsolidationGroup`), entiteit-a-id, entiteit-b-id, relatie-type (sales-of-goods, sales-of-services, royalty, licensing, management-fee, interest-on-loan, dividend, capital-contribution, expense-recharge), default-grootboekrekening-a (de rekening die entiteit A gebruikt voor deze relatie, bijv. "1310 - Vordering op Werk BV"), default-grootboekrekening-b (de tegen-rekening in entiteit B, bijv. "1610 - Schuld aan Holding BV"), tolerantie-absoluut (default €10), tolerantie-relatief (default 0.5%), tolerantie-fallback-rekening (waar het mismatch-saldo naartoe geboekt wordt indien binnen tolerantie, bijv. "9999 - Eliminatie-restpost"), actief-vanaf, actief-tot.

**IntercompanyTransaction**: een individuele intercompany-boeking gedetecteerd in een entiteit-administratie. Velden: bron-administratie-id, bron-journaalpost-id, bron-regel-nummer, boekingsdatum, grootboekrekening, debet-bedrag, credit-bedrag, valuta, omschrijving, counterparty-entiteit-id (gedetecteerd of expliciet aangegeven), counterparty-relatie-id (welke `IntercompanyRelation` hoort hierbij), detectie-methode (rekening-based, label-based, expliciet aangegeven), detectie-confidence (high/medium/low), gematched (boolean), match-id (FK naar `IntercompanyMatch`).

**IntercompanyMatch**: een geslaagde matching tussen twee (of meer) `IntercompanyTransaction`-records uit twee verschillende entiteiten. Velden: periode, relatie-id, entiteit-a-transacties (lijst van transactie-ids aan A-kant), entiteit-b-transacties (lijst van transactie-ids aan B-kant), totaal-bedrag-a, totaal-bedrag-b, mismatch-bedrag (= bedrag-a − bedrag-b), mismatch-percentage (relatief), match-status (perfect-match, binnen-tolerantie, buiten-tolerantie, eenzijdig-a, eenzijdig-b), gegenereerde-eliminatie-id (FK naar `EliminationEntry` als eliminatie is gegenereerd).

**IntercompanyMismatch**: een gedetecteerde discrepantie die niet automatisch resolved kan worden. Velden: periode, relatie-id, match-id (indien onderdeel van een match), oorzaak-classificatie (timing-difference, fx-translation, transfer-pricing-adjustment, missing-booking, classification-error, unknown), bedrag, valuta, beschrijving, status (open, in-onderzoek, opgelost, geaccepteerd-als-restpost), assignee (user-id), resolutie-opmerkingen, resolutie-actie (handmatige correctie-boeking in bron, herclassificatie, manual-elimination-adjustment, accept-as-difference).

**ToleranceRule**: configureerbare regel voor wanneer een mismatch als acceptabel wordt gezien. Velden: groep-id, relatie-type (kan generic of specifiek), tolerantie-absoluut, tolerantie-relatief, tolerantie-methode (max-of-absolute-relative, min-of-absolute-relative, absolute-only), restpost-grootboekrekening, auto-resolve (boolean — als true wordt binnen-tolerantie automatisch geaccepteerd zonder review).

**CounterpartyBalance**: aggregatie-view per intercompany-paartje per periode. Velden: groep-id, entiteit-a-id, entiteit-b-id, periode, totaal-vorderingen-a-op-b, totaal-schulden-a-aan-b, netto-positie-a-tov-b, totaal-omzet-van-a-aan-b, totaal-inkopen-van-a-bij-b, aantal-transacties, aantal-mismatches, laatste-update-timestamp.

**EliminationJournal**: gegenereerde eliminatie-journaalpost, opgenomen in de consolidatie-laag (niet in de bron-administraties). Velden: consolidatie-periode-id, match-id (de match die deze eliminatie heeft veroorzaakt), boekingsdatum, omschrijving, regels (lijst van debet/credit per grootboekrekening, conceptueel een journaalpost), totaal-debet, totaal-credit (moeten balanceren), gegenereerd-door (systeem of user), goedgekeurd-door (user-id), goedgekeurd-op.

## Requirements

### REQ-ICE-001: Intercompany-relatie definitie en onderhoud

Het systeem MOET een gebruiker (controller of accountant) in staat stellen intercompany-relaties expliciet te definiëren tussen entiteiten in een consolidatiegroep, inclusief de relevante grootboekrekeningen aan beide kanten en tolerantie-instellingen, zodat de matching-engine weet welke transacties bij elkaar horen.

- GIVEN consolidatiegroep "Acme Group" met entiteiten Holding BV, Werk BV, Vastgoed BV
  WHEN ik IntercompanyRelation aanmaak "Werk BV verkoopt diensten aan Holding BV" met type "sales-of-services", grootboekrekening Werk BV = 8200 (Intercompany omzet), grootboekrekening Holding BV = 4400 (Inkopen diensten van groepsmaatschappij), tolerantie €10 absoluut / 0.5% relatief
  THEN de relatie wordt opgeslagen en gekoppeld aan beide entiteit-rekeningschema's; toekomstige consolidatie-runs gebruiken deze als matching-instructie.

- GIVEN er bestaat een impliciete relatie via gemeenschappelijke grootboekrekening-naam ("Intercompany" in beide entiteiten)
  WHEN gebruiker de IC-relatie-setup-wizard opent
  THEN het systeem detecteert kandidaat-relaties op basis van rekening-naam-similariteit en stelt voor deze als formele `IntercompanyRelation` te registreren, ter goedkeuring.

- GIVEN er is per ongeluk een dubbele relatie gedefinieerd voor hetzelfde paar entiteiten + hetzelfde type
  WHEN gebruiker opslaat
  THEN het systeem detecteert het dubbel, weigert de tweede aan te maken, en verwijst naar de bestaande relatie ter wijziging.

### REQ-ICE-002: Auto-detectie intercompany-transacties

Het systeem MOET in alle entiteit-administraties van een groep periodiek scannen op transacties die intercompany zijn — op basis van geregistreerde IC-grootboekrekeningen, op basis van counterparty-naam-matching (de naam van een groep-entiteit als debiteur/crediteur), of op basis van transactie-label/tag — en deze als `IntercompanyTransaction` registreren met counterparty-aanduiding.

- GIVEN Werk BV boekt een verkoopfactuur €100.000 op rekening 8200 (Intercompany omzet) met debiteur "Holding BV"
  WHEN de scan loopt
  THEN het systeem detecteert deze als intercompany-transactie, identificeert Holding BV als counterparty (op basis van debiteur-naam-match met groep-entiteit), koppelt aan de relevante `IntercompanyRelation`, en registreert een `IntercompanyTransaction` met confidence high.

- GIVEN een transactie zit op een gewone debiteur-rekening (1300) maar de debiteur-naam is "Holding BV"
  WHEN de scan loopt
  THEN het systeem detecteert dit op basis van naam-match (medium confidence), markeert de transactie als kandidaat-IC, en plaatst deze in een review-queue voor expliciete bevestiging door de gebruiker (zonder bevestiging wordt deze niet gebruikt voor matching).

- GIVEN gebruiker heeft een transactie expliciet getagd als "intercompany" maar er is geen counterparty geregistreerd
  WHEN scan loopt
  THEN het systeem markeert de transactie als ambigu-intercompany en vraagt de gebruiker de counterparty aan te wijzen voordat matching kan plaatsvinden.

### REQ-ICE-003: Periodieke matching auto-run

Het systeem MOET periodiek (per maand, kwartaal of jaareinde, configureerbaar) automatisch alle intercompany-transacties van een periode matchen door per `IntercompanyRelation` de A-zijde te aggregeren, de B-zijde te aggregeren, en het netto-saldo te bepalen.

- GIVEN periode januari-2025, IC-relatie "Werk BV → Holding BV sales-of-services" met aan Werk BV-zijde 3 verkoopfacturen totaal €100.000 en aan Holding BV-zijde 3 inkoopfacturen totaal €100.000
  WHEN matching loopt
  THEN het systeem aggregeert beide kanten, detecteert perfecte match (delta = €0), maakt een `IntercompanyMatch` met status perfect-match aan, en markeert alle 6 transacties als gematched.

- GIVEN dezelfde periode maar Holding BV heeft slechts 2 inkoopfacturen geboekt totaal €75.000 (één van €25.000 staat nog niet in de administratie wegens vakantie-achterstand)
  WHEN matching loopt
  THEN het systeem detecteert mismatch €25.000, classificeert dit als kandidaat "timing-difference" of "missing-booking", maakt een `IntercompanyMismatch` aan met status open, en plaatst deze in de exception-queue voor handmatig onderzoek.

- GIVEN gebruiker draait de matching opnieuw na een correctie in een bron-administratie
  WHEN re-run loopt
  THEN het systeem ongedaan maakt eerder gegenereerde matches voor de periode (mits niet definitief goedgekeurd), her-matched op basis van actuele bron-data, en update alle exception-queue items.

### REQ-ICE-004: Tolerantie-gebaseerde auto-resolve

Het systeem MOET configureerbare tolerantie-regels toepassen op gedetecteerde mismatches; mismatches binnen tolerantie worden automatisch geaccepteerd als acceptabel verschil en geboekt naar een afgesproken restpost-rekening, mismatches buiten tolerantie blijven in de exception-queue voor handmatige resolutie.

- GIVEN IC-relatie met tolerantie €10 absoluut / 0.5% relatief, en een match met delta €7 op een totaal van €100.000 (0.007%)
  WHEN matching loopt
  THEN beide tolerantie-checks passen (€7 < €10 absoluut, 0.007% < 0.5% relatief), match wordt gestempeld binnen-tolerantie, eliminatie wordt gegenereerd voor het gemeenschappelijke deel (€99.993) en de €7 mismatch wordt geboekt naar de restpost-rekening (default "9999 - Eliminatie-rounding-restpost").

- GIVEN dezelfde relatie maar delta is €15 (>€10 absoluut, ondanks 0.015% <0.5% relatief)
  WHEN matching loopt
  THEN match wordt gestempeld buiten-tolerantie (één van de twee tolerantie-checks faalt), eliminatie wordt NIET automatisch gegenereerd, en de mismatch komt in de exception-queue.

- GIVEN gebruiker wil een tolerantie-regel tijdelijk verlagen voor een bepaalde periode (jaareinde, strenger)
  WHEN ze de tolerantie aanpast naar €1 / 0.1% voor december-periode
  THEN periode-specifieke tolerantie overschrijft de default; eerder geresolved-binnen-tolerantie items in dezelfde periode worden geherclassificeerd indien de strengere tolerantie ze nu uitsluit.

### REQ-ICE-005: Mismatch-classificatie en resolutie

Het systeem MOET een gebruiker in staat stellen mismatches te classificeren op oorzaak (timing, FX, transfer-pricing, fout, anders), per classificatie een resolutie-pad aan te bieden, en de gekozen resolutie te traceren tot doorvoer in bron-administraties of consolidatie-aanpassing.

- GIVEN mismatch €25.000 in IC-relatie Werk BV → Holding BV, oorzaak "timing-difference" (factuur was per 31 dec maar Holding BV heeft pas op 5 jan ontvangen)
  WHEN gebruiker classificeert als timing-difference en kiest resolutie "interim-elimination-with-reversal-next-period"
  THEN het systeem genereert een eliminatie-journaalpost voor de €25.000 in december (debet IC-omzet €25k, credit transitorische post), en plant een tegenboeking voor januari (debet transitorische post, credit IC-inkopen €25k) zodat het over twee periodes consistent verloopt.

- GIVEN mismatch €1.200 in IC-relatie tussen NL- en US-dochter, oorzaak "fx-translation"
  WHEN gebruiker classificeert als fx-translation
  THEN het systeem boekt het verschil naar de FX-translation-restpost (CTA-component in eigen vermogen) in plaats van naar resultaat, en documenteert in de match-trail de toegepaste koersen.

- GIVEN mismatch wegens transfer-pricing-correctie die nog niet doorgevoerd is in een van de entiteiten
  WHEN gebruiker classificeert als transfer-pricing-adjustment en kiest "create-source-correction-booking"
  THEN het systeem opent een wizard om de ontbrekende correctie-boeking in de juiste bron-administratie te genereren, met voorgesteld journaal; na bevestiging wordt de boeking in de bron geplaatst en wordt de matching opnieuw uitgevoerd.

### REQ-ICE-006: Eliminatie-journaalpost generatie

Het systeem MOET voor elke succesvolle (perfecte of binnen-tolerantie) match automatisch een eliminatie-journaalpost genereren in de consolidatie-laag — niet in de bron-administraties — met de juiste debet/credit-regels per grootboekrekening en een verwijzing naar de match en bron-transacties.

- GIVEN match Werk BV-omzet €100.000 ↔ Holding BV-inkopen €100.000
  WHEN eliminatie wordt gegenereerd
  THEN het systeem maakt journaalpost: debet 8200 (IC-omzet Werk BV) €100k, credit 4400 (IC-inkopen Holding BV) €100k. Dit wordt in de `EliminationJournal` opgeslagen, gelinkt aan de match, en zichtbaar in het consolidatie-werkpapier.

- GIVEN match van intercompany-vorderingen en -schulden €25.000
  WHEN eliminatie wordt gegenereerd
  THEN journaalpost: debet 1610 (Schuld aan Werk BV in Holding BV) €25k, credit 1310 (Vordering op Holding BV in Werk BV) €25k. Geconsolideerde balans toont na eliminatie geen intercompany-saldo meer.

- GIVEN match van intercompany-lening met rente (hoofdsom €500k, rente €25k)
  WHEN eliminatie wordt gegenereerd
  THEN twee separate journaalposten: één voor de hoofdsom (vordering vs schuld), één voor de rente (rentebaten vs rentelasten). Beide gelinkt aan de match.

### REQ-ICE-007: Counterparty-saldo overzicht

Het systeem MOET per intercompany-paartje (twee groepsentiteiten) een consolideerd overzicht bieden van alle openstaande saldi over en weer, alle stromen in de periode, en alle mismatches, zodat controllers één-blik-status hebben en discussies tussen entiteit-administrateurs versneld worden.

- GIVEN consolidatiegroep met intercompany-relaties tussen Holding BV, Werk BV, Vastgoed BV
  WHEN ik de "Counterparty View" voor Werk BV ↔ Holding BV open
  THEN het systeem toont: huidige openstaande vorderingen €X, huidige openstaande schulden €Y, netto-positie €Z, totale omzet-stroom in laatste periode €A, totale inkoop-stroom €B, aantal mismatches in periode N, link naar mismatch-detail. Per kant een drilldown naar de individuele bron-transacties.

- GIVEN beide controllers (van Werk BV en Holding BV) bekijken dezelfde counterparty-view
  WHEN er zich een mismatch voordoet
  THEN beide zien hetzelfde scherm met dezelfde data, kunnen comments achterlaten direct in-context (per mismatch een discussiethread), en zien wie wat heeft ge-edit en wanneer.

- GIVEN ik wil per kwartaal een PDF-export van counterparty-statements (klassiek "IC-confirmation letter")
  WHEN ik export aanvraag
  THEN het systeem genereert per IC-relatie een PDF met alle transacties van de periode, de eindbalans, en handtekening-velden voor beide controllers.

### REQ-ICE-008: Cross-period roll-forward en historische audit

Het systeem MOET intercompany-saldi van periode tot periode roll-forward consistentie bewaken (eindstand vorige periode = beginstand huidige periode), en bij re-matching of correctie van vorige periodes de impact op huidige periodes herberekenen en signaleren.

- GIVEN matching voor Q1-2025 is definitief, eindstand intercompany-vordering Werk BV op Holding BV = €15.000
  WHEN Q2-2025 matching start
  THEN beginstand Q2 = €15.000 wordt geverifieerd tegen de openingsbalans van Werk BV in Q2; bij afwijking wordt een alert gegenereerd.

- GIVEN er wordt een correctie geboekt op een Q1-transactie nadat Q2 al gematched is
  WHEN het systeem deze backdated wijziging detecteert
  THEN het systeem alarmeert dat Q1 en Q2 herberekend moeten worden, blokkeert verdere matching tot dit is afgehandeld, en biedt een wizard om de cascading impact te beheren.

- GIVEN gebruiker wil een audit-rapport voor accountant tonen
  WHEN ik "IC audit report" voor jaar 2025 vraag
  THEN het systeem genereert een rapport met per IC-relatie alle matches in het jaar, alle mismatches met resolutie, alle eliminatie-journalen, en een verklaring van eind-saldo (rolforward van begin naar eind).

### REQ-ICE-009: Multi-currency intercompany matching

Het systeem MOET intercompany-transacties tussen entiteiten met verschillende functionele valuta correct matchen door beide kanten naar een gemeenschappelijke vergelijkings-valuta (default = groep-rapportage-valuta) te converteren tegen de juiste koers, en het translatie-verschil dat ontstaat als CTA te boeken in plaats van als P&L-verschil.

- GIVEN Werk BV (EUR) heeft €100.000 verkocht aan US-Dochter (USD); US-Dochter heeft USD 108.500 ingeboekt
  WHEN matching loopt met EUR als rapportage-valuta en transactie-datum-koers USD/EUR = 0,921 (€100k = USD 108.578)
  THEN het systeem vertaalt USD 108.500 naar EUR (€99.928), vergelijkt met €100.000, mismatch €72 wordt geclassificeerd als FX-translation (te klein voor transfer-pricing) en geboekt naar CTA-restpost.

- GIVEN intercompany-saldo op balansdatum: Werk BV heeft EUR-vordering €25.000 op US-Dochter, US-Dochter heeft USD-schuld geboekt USD 27.100 (oude koers)
  WHEN matching op balansdatum loopt met slotkoers USD/EUR = 0,925
  THEN US-zijde wordt vertaald naar EUR (USD 27.100 × 0,925 = €25.067), mismatch €67 wordt als FX-translation gevangen en naar CTA-EV-restpost geboekt.

- GIVEN gebruiker wil per IC-relatie de gehanteerde koersen zien
  WHEN ik match-detail open
  THEN het systeem toont alle gebruikte koersen (transactie-datum, gemiddelde, slot), bron van de koers (ECB, manual override), en de berekeningsstap-voor-stap van bron-bedrag naar vergelijkings-bedrag.

### REQ-ICE-010: Performance en schaalbaarheid

Het systeem MOET matching kunnen uitvoeren voor groepen tot 50 entiteiten en 100.000 intercompany-transacties per periode binnen acceptabele tijd (target: <5 minuten voor een typische maand-matching van een 10-entiteiten-groep met 5.000 IC-transacties), en moet incremental matching ondersteunen zodat alleen wijzigingen sinds laatste run worden geherprocesseerd.

- GIVEN consolidatiegroep met 12 entiteiten en gemiddeld 4.000 IC-transacties per maand
  WHEN ik full matching voor januari draai
  THEN het systeem voltooit in minder dan 5 minuten op standaard productie-hardware (4 vCPU, 8GB RAM).

- GIVEN ik heb in januari al matching gedraaid, en nu wil ik na een correctie in één bron-administratie de matching opnieuw doen
  WHEN ik incremental re-match aanvraag
  THEN het systeem detecteert welke transacties gewijzigd zijn sinds laatste run, herberekent alleen de relevante matches en eliminaties, en voltooit in <30 seconden voor een typische delta van 50-100 transacties.

- GIVEN ik wil matching draaien voor een grote groep (40 entiteiten, 60.000 transacties per maand)
  WHEN ik full matching aanvraag
  THEN het systeem partitioneert het werk per IC-relatie en draait parallel waar mogelijk; voltooi binnen 30 minuten op productie-hardware en toon real-time voortgang met per-relatie status.

## Standards & Sources

- **Burgerlijk Wetboek Boek 2 art. 2:406** — verplichting tot consolidatie en eliminatie van interne transacties
- **RJ 217.301-307** — specifieke regels voor eliminatie van intercompany sales, vorderingen/schulden, dividenden en ongerealiseerde tussenwinsten in voorraad of vaste activa
- **IAS 27.18** + **IFRS 10.B86** — IFRS-eisen voor eliminatie van intercompany-balansen, -transacties, -baten en -lasten
- **OESO Transfer Pricing Guidelines** — context voor IC-pricing dat moet matchen tussen entiteiten
- **NBA-handreiking 1118** — accountantsvereisten voor controle van intercompany-eliminaties
- **NL Belastingdienst — Verrekenprijzendocumentatie** — fiscale transfer-pricing-documentatie-eisen die parallel lopen aan commerciële intercompany-administratie

## Cross-app integration

- **bookkeeping-consolidation-commercial** (NEW spec, REQUIRED) — deze eliminatie-engine is een onderdeel van het bredere consolidatie-proces; consolidatie-spec definieert de context, deze spec definieert de matching-mechanica in detail
- **bookkeeping-multi-administratie** (NEW spec, REQUIRED) — bron-administraties waar IC-transacties uit gehaald worden moeten in één Shillinq-instance staan
- **bookkeeping-grootboek** — IC-transacties zijn ook gewone journaalposten in de bron-administraties; rekeningschema-mapping is essentieel
- **bookkeeping-transfer-pricing** (future spec) — IC-prijsstelling tussen entiteiten moet documentatie-conform zijn; deze engine signaleert prijsverschillen die op TP-correctie kunnen wijzen
- **openregister** — `IntercompanyRelation`, `IntercompanyTransaction`, `IntercompanyMatch`, `IntercompanyMismatch` zijn OR-schemas met relaties naar de bron-administratie schemas
- **openconnector** — voor entiteiten die niet in Shillinq zelf staan kan IC-data via OC-source uit Exact Online, Twinfield of vergelijkbare systemen gehaald worden

## Target users

**Concern-controller**: in-house controller bij een MKB-holding die periodiek (maand of kwartaal) de intercompany-eliminaties moet uitvoeren voor management-rapportage of jaarrekening-voorbereiding. Wil één-knop matching, exception-queue met duidelijke prioriteit, en counterparty-statements ter handhaving van saldo-discipline.

**Entiteit-administrateur**: medewerker bij een dochter die de eigen administratie voert en periodiek door de concern-controller benaderd wordt over mismatches met andere entiteiten. Wil counterparty-view per entiteit zien, kunnen reageren op mismatches met context, en in-app discussies voeren in plaats van email-pingpong.

**Accountant in controle-opdracht**: externe accountant die de geconsolideerde jaarrekening controleert en moet kunnen narekenen of alle intercompany-stromen correct geëlimineerd zijn. Wil audit-trail per match, lijst van mismatch-resoluties met onderbouwing, en counterparty-confirmation-templates.

**DGA in eenvoudige BV-BV-structuur**: ondernemer met persoonlijke holding + werk-BV die zelf zonder accountant-tussenkomst de eliminaties wil uitvoeren. Wil minimum setup (één IC-relatie volstaat voor de meeste interne management-fee), auto-resolve voor alle binnen-tolerantie verschillen, en visuele feedback dat consolidatie "klopt".

---
status: draft
---
# Bookkeeping Consolidation (Commercial / RJ 217 / IAS 27)

## Purpose

Dutch groep-structuren (holding + werkmaatschappijen) zijn de norm in het Nederlandse MKB. Vrijwel elke commerciële onderneming met meer dan één entiteit kent een moeder-dochter relatie: een persoonlijke holding bovenop een werk-BV (de klassieke "BV-BV-structuur"), een tussenholding voor pensioen-in-eigen-beheer, een vastgoed-BV naast de operationele BV, of een joint venture met een minderheidsbelang. Voor al deze structuren eist de Nederlandse wet — Titel 9 Boek 2 BW, art. 2:406 — een **geconsolideerde jaarrekening** zodra de moeder "overheersende zeggenschap" uitoefent over de dochter, tenzij een vrijstelling geldt (art. 2:407: kleine groep; art. 2:408: tussenholding-vrijstelling met IFRS-moeder; art. 2:403: 403-verklaring voor dochters die opgaan in moeder).

Consolidatie is conceptueel eenvoudig — "tel alle administraties bij elkaar op alsof het één onderneming is" — maar in de praktijk minutieus en foutgevoelig. Het is niet een simpele optelsom van balansen en winst-en-verliesrekeningen. Het is een vier-traps proces: (1) **harmonisatie** van waarderingsgrondslagen en boekjaren tussen entiteiten, (2) **aggregatie** van per-administratie cijfers naar concern-niveau, (3) **eliminatie** van interne transacties zodat alleen externe omzet, externe kosten, externe vorderingen en externe schulden overblijven, en (4) **acquisitie-accounting** voor verworven dochters waarbij goodwill of badwill ontstaat en non-controlling interest (minderheidsbelang) wordt afgesplitst.

Voor accountantskantoren is consolidatie historisch een **specialist-product**. De gemiddelde MKB-accountant heeft één of twee groep-klanten en knutselt de consolidatie in Excel: per kolom een entiteit, eliminatie-kolom met handgemaakte journalen, totaal-kolom met formules. Dit werkt — tot het niet meer werkt: bij meer dan vier entiteiten, bij vreemde valuta, bij intercompany-mismatches, bij wijzigingen in groepssamenstelling, of bij accountantscontroles die de eliminatie-logica willen narekenen. Software-pakketten zoals Exact Online Plus, Unit4 en Visma bieden consolidatie alleen in hun duurste tiers (typisch €500-2000/maand) en zelfs daar is de gebruikservaring matig (rigide rapportstructuur, beperkte eliminatie-flexibiliteit, geen audit-trail).

Shillinq's consolidatie-module mikt op dit gat: **professionele consolidatie voor het Nederlandse MKB en zijn accountant**, geprijsd voor een holding-werkmaatschappij-structuur in plaats van een beursfonds. De module bouwt voort op `bookkeeping-multi-administratie` (per-entiteit grootboeken in één Shillinq-instance) en `bookkeeping-financial-statements` (per-entiteit jaarrekening-output), voegt daar consolidatie-logica overheen, en levert geconsolideerde balans, V&W, kasstroomoverzicht en consolidatie-toelichting volgens RJ 217 (Richtlijn voor de Jaarverslaggeving — Nederlands commercieel) of IAS 27 / IFRS 10 (voor groepen die kiezen voor IFRS-rapportage, typisch om internationale financiering of beursnotering).

De scope van deze brief is **commerciële consolidatie**: jaarrekening voor de aandeelhouder, conform RJ of IFRS, voor publicatie bij de Kamer van Koophandel of voor interne management-rapportage. Fiscale consolidatie (fiscale eenheid voor de vennootschapsbelasting) is een aparte regeling met andere regels en hoort in een eigen module (`bookkeeping-fiscal-unit`). Cash-flow consolidatie en management-reporting consolidaties (segment-rapportage, geografische uitsplitsing) komen later.

## Data Model

**ConsolidationGroup**: definitie van een consolidatiekring. Velden: naam ("Acme Group"), moeder-administratie-id, rapportage-valuta (default EUR), boekjaar-einde (moet uniform zijn voor alle entiteiten in de groep — of expliciete aanpassing voor entiteiten met afwijkend boekjaar), waarderingsgrondslag (RJ-commercieel, IFRS, of US-GAAP), consolidatie-methode-default (integraal / proportioneel / equity), eerste-consolidatie-datum, opmerkingen.

**GroupEntity**: deelneming binnen een consolidatiegroep. Velden: groep-id, administratie-id (verwijzing naar `Administratie` uit multi-administratie module), entiteit-type (moeder, dochter, joint venture, geassocieerde deelneming), eigendomspercentage (0-100, default 100 voor 100%-dochters), stem-percentage (kan afwijken bij prioriteitsaandelen), consolidatie-methode (integraal voor controlerend belang, proportioneel voor joint ventures pre-2014, equity voor geassocieerde deelnemingen), eerste-consolidatie-datum, laatste-consolidatie-datum (bij desinvestering), functionele valuta (EUR, USD, GBP, etc.).

**IntercompanyRelation**: registratie van interne handelsrelaties tussen groepsentiteiten. Velden: groep-id, debiteur-entiteit-id, crediteur-entiteit-id, type (sales, services, licensing, royalties, interest, dividend, loan), default-eliminatie-grootboekrekening, default-counterparty-grootboekrekening, opmerkingen. Dit is de "wegwijzer" die de eliminatie-engine vertelt welke debiteur-rekening in entiteit A correspondeert met welke crediteur-rekening in entiteit B.

**ConsolidationPeriod**: een consolidatie-runs voor een specifieke periode. Velden: groep-id, periode-start, periode-eind, status (open, eliminatie-fase, review, gesloten, gearchiveerd), uitvoerder (user-id), uitvoer-timestamp, totaal-aantal-eliminaties, totaal-eliminatie-bedrag (debet/credit, moeten balanceren), foutmeldingen-jsonb (lijst van mismatches die handmatig opgelost zijn).

**EliminationEntry**: een individuele eliminatie-boeking binnen een consolidatie-periode. Velden: periode-id, type (intercompany-sales, intercompany-AR-AP, intercompany-loan, intercompany-dividend, intercompany-margin-in-inventory, goodwill-write-up, minority-interest-split), boekingsdatum, omschrijving, regels-jsonb (debet/credit per grootboekrekening), bron-entiteiten (welke administraties zijn betrokken), bron-transacties (verwijzingen naar originele journaalposten in de bron-administraties), auto-gegenereerd (boolean), accountant-review-status (pending, approved, rejected).

**TranslationAdjustment**: koerseverschillen bij translatie van vreemde-valuta-administraties naar rapportage-valuta. Velden: periode-id, entiteit-id, valuta-koppel (USD-EUR), translatie-methode (current-rate voor balansposten, gemiddelde-rate voor V&W-posten, historische rate voor eigen vermogen), bedrag-in-functionele-valuta, bedrag-in-rapportage-valuta, CTA-component (Cumulative Translation Adjustment, geboekt in eigen vermogen onder "koersverschillen reserve").

**MinorityInterest**: registratie van minderheidsbelangen (third-party aandeelhouders in dochters waar de groep <100% bezit). Velden: groep-id, entiteit-id, percentage-derden, openingssaldo-minderheidsbelang, aandeel-in-resultaat-periode, dividend-aan-minderheid, eindsaldo-minderheidsbelang. Wordt apart gepresenteerd in geconsolideerd eigen vermogen ("Aandeel derden").

**Goodwill**: ontstaat bij acquisitie wanneer de koopprijs hoger is dan de fair-value van de verworven netto-activa. Velden: groep-id, dochter-entiteit-id, acquisitie-datum, koopprijs, fair-value-netto-activa-verworven, goodwill-bedrag (positief = goodwill, negatief = badwill / negatieve goodwill), afschrijvingsmethode (RJ: max 20 jaar lineair; IFRS: niet afschrijven maar jaarlijks impairment-test), restwaarde, opgebouwde-afschrijvingen, impairment-correcties.

**ConsolidatedBalance / ConsolidatedIncomeStatement**: output-objecten met geaggregeerde + geëlimineerde cijfers per rapportageregel. Bevatten ook comparatieve cijfers (vorig boekjaar) en footnote-references naar de toelichting.

## Requirements

### REQ-CONS-001: Consolidatiegroep-definitie

Het systeem MOET een gebruiker (typisch accountant of controller) in staat stellen een consolidatiegroep aan te maken door de moeder-administratie te selecteren, een of meer dochter-administraties toe te voegen met eigendomspercentage en consolidatiemethode, en rapportage-parameters vast te leggen (valuta, grondslag, boekjaar-einde).

- GIVEN ik heb in Shillinq drie administraties (Holding BV, Werk BV, Vastgoed BV)
  WHEN ik consolidatiegroep "Acme Group" aanmaak met Holding BV als moeder, Werk BV (100%) en Vastgoed BV (100%) als dochters, EUR als rapportage-valuta, RJ-commercieel als grondslag
  THEN de groep wordt aangemaakt, alle drie de entiteiten worden gekoppeld met consolidatie-methode "integraal", en de UI toont een groepsoverzicht met drie blokjes onder de moeder.

- GIVEN ik definieer een consolidatiegroep met een joint-venture-dochter (50%-belang)
  WHEN ik het eigendomspercentage op 50% zet en consolidatie-methode op "equity"
  THEN het systeem registreert deze als geassocieerde deelneming en zal in consolidatie alleen het 50%-resultaat en de 50%-boekwaarde van het eigen vermogen meenemen, niet de individuele balansposten.

- GIVEN ik probeer een dochter toe te voegen waarvan het boekjaar afwijkt van de moeder (gebroken vs kalender)
  WHEN ik de groep opslaag
  THEN het systeem waarschuwt dat boekjaren moeten worden geharmoniseerd (interim-cijfers per moeder-eindedatum nodig) en vraagt of dit handmatig of via een interim-rapportage moet gebeuren.

### REQ-CONS-002: Aggregatie per-administratie balans + V&W

Het systeem MOET voor een gekozen consolidatie-periode de individuele balansen en winst-en-verliesrekeningen van alle groepsentiteiten ophalen, harmoniseren naar de rapportage-valuta en de groep-grootboekstructuur, en aggregeren tot een pre-eliminatie totaalbeeld (klassieke "consolidatie-werkpapier" met kolom per entiteit + totaal).

- GIVEN consolidatiegroep "Acme Group" met drie entiteiten, allemaal in EUR, en ik vraag consolidatie aan voor 2025
  WHEN het systeem aggregeert
  THEN het toont een werkpapier met vier kolommen: Holding BV, Werk BV, Vastgoed BV, Subtotaal-pre-eliminatie. Elke rij is een rapportageregel uit het groep-rekeningschema. Verticaal optellen klopt: Subtotaal = som van de drie entiteit-kolommen.

- GIVEN een dochter heeft een afwijkend rekeningschema (lokaal account voor "telefoonkosten" 4310 vs groep-rekening 6210)
  WHEN aggregatie loopt
  THEN het systeem mapt 4310 → 6210 op basis van een vooraf gedefinieerde mapping-tabel per entiteit en logt onbekende rekeningen in een exception-queue.

- GIVEN een dochter rapporteert in USD en de groep in EUR
  WHEN aggregatie loopt
  THEN balansposten worden vertaald tegen de slotkoers (current-rate methode), V&W-posten tegen de gemiddelde koers van de periode, en het ontstane koersverschil wordt geboekt als CTA in een aparte EV-reserve.

### REQ-CONS-003: Eliminatie intercompany sales en kostprijs

Het systeem MOET intercompany handelstransacties (Werk BV verkoopt aan Holding BV) detecteren en elimineren door zowel de intercompany-omzet aan moederkant als de intercompany-inkoop aan dochterkant tegen elkaar weg te boeken, zodat de geconsolideerde V&W alleen externe transacties toont.

- GIVEN Werk BV heeft €100.000 omzet aan Holding BV geboekt op rekening 8200 (Intercompany sales), Holding BV heeft €100.000 inkoop van Werk BV geboekt op rekening 7200 (Intercompany purchases)
  WHEN consolidatie loopt
  THEN het systeem genereert automatisch een eliminatie-journaal: debet 8200 €100.000, credit 7200 €100.000. Geconsolideerde omzet en geconsolideerde inkopen dalen beide met €100.000.

- GIVEN dezelfde transactie maar Werk BV heeft €100.000 geboekt en Holding BV slechts €99.500 (rounding of timing-verschil)
  WHEN consolidatie loopt
  THEN het systeem detecteert de mismatch van €500, vergelijkt met de tolerantie (default €10 absoluut of 0.5% relatief), classificeert als binnen-tolerantie of buiten-tolerantie, en plaatst buiten-tolerantie items in een exception-queue voor handmatige resolutie.

- GIVEN intercompany verkoop met margin-in-inventory: Werk BV heeft €100k verkocht met €20k marge aan Holding BV, en Holding BV heeft daarvan voor €60k nog op voorraad
  WHEN consolidatie loopt
  THEN het systeem detecteert ongerealiseerde tussenwinst (60% van €20k = €12k) en boekt een aanvullende eliminatie: debet kostprijs €12k, credit voorraad €12k — omdat deze winst pas mag worden erkend wanneer Holding BV de goederen extern verkoopt.

### REQ-CONS-004: Eliminatie intercompany vorderingen en schulden

Het systeem MOET intercompany balansposten (vorderingen op groepsmaatschappijen vs schulden aan groepsmaatschappijen) tegen elkaar wegboeken, zodat geconsolideerde debiteuren en crediteuren alleen externe partijen bevatten.

- GIVEN Werk BV heeft openstaande vordering €25.000 op Holding BV (rekening 1310), Holding BV heeft openstaande schuld €25.000 aan Werk BV (rekening 1610)
  WHEN consolidatie loopt
  THEN het systeem genereert eliminatie: debet 1610 €25.000, credit 1310 €25.000. Geconsolideerde balans toont geen intercompany vorderingen/schulden meer.

- GIVEN een intercompany lening: Holding BV heeft €500.000 verstrekt aan Werk BV met 5% rente, en Werk BV heeft €25.000 rentekosten geboekt voor 2025
  WHEN consolidatie loopt
  THEN het systeem elimineert zowel de hoofdsom (debet schuld €500k, credit vordering €500k) als de rente (debet rentebaten €25k, credit rentekosten €25k). Geconsolideerd resultaat verandert per saldo niet (de groep heeft geen externe rentestroom uit deze interne lening).

- GIVEN intercompany dividend: Werk BV heeft €150.000 dividend uitgekeerd aan Holding BV
  WHEN consolidatie loopt
  THEN het systeem elimineert het dividend aan moederkant (debet ontvangen dividend, credit eigen vermogen aanpassing) zodat het niet dubbel telt — Werk BV's nettowinst is al integraal opgenomen in de geconsolideerde V&W.

### REQ-CONS-005: Currency translation en CTA

Het systeem MOET vreemde-valuta-administraties vertalen naar de rapportage-valuta volgens de current-rate methode (RJ 122 / IAS 21), waarbij balansposten op de slotkoers, V&W-posten op gemiddelde koers, en eigen-vermogen-mutaties op historische koers worden omgerekend, met het saldoverschil als Cumulative Translation Adjustment in eigen vermogen.

- GIVEN een Amerikaanse dochter met USD-administratie, slotkoers USD/EUR = 0,92, gemiddelde koers 2025 = 0,93, openingskoers 2025 = 0,94
  WHEN consolidatie loopt
  THEN balansposten worden vertaald tegen 0,92, V&W tegen 0,93, en het verschil dat ontstaat tussen het vertaalde EV-saldo en het via-V&W-doorgerolde EV-saldo wordt als CTA geboekt onder eigen vermogen.

- GIVEN dezelfde dochter, en in 2026 wordt de USD-EUR koers volatiel
  WHEN consolidatie 2026 loopt
  THEN de CTA wordt cumulatief bijgewerkt (delta-CTA over de periode wordt aan vorige cumulatieve saldo toegevoegd), en bij desinvestering van de dochter wordt de gehele cumulatieve CTA "gerecycled" via de V&W (overgenomen uit OCI).

- GIVEN gebruiker wil intercompany-positie in USD elimineren tegen rapportage-valuta EUR
  WHEN consolidatie loopt
  THEN de vordering en schuld worden eerst vertaald naar EUR tegen de slotkoers en daarna geëlimineerd. Eventueel translatie-verschil tussen de twee zijden valt in CTA, niet in resultaat.

### REQ-CONS-006: Non-controlling interest (minderheidsbelang)

Het systeem MOET voor dochters waar de groep <100% bezit het aandeel van derden ("minority interest" / "non-controlling interest" / "aandeel derden") apart berekenen en presenteren in zowel de geconsolideerde balans (als component van eigen vermogen) als in de V&W (als laatste regel onder nettowinst).

- GIVEN consolidatiegroep met een dochter waarin de groep 70% bezit (30% derden), dochter heeft 2025 nettowinst €100.000
  WHEN consolidatie loopt
  THEN geconsolideerd resultaat toont €100k volledige nettowinst, daaronder twee regels: "Toe te rekenen aan aandeelhouders moeder: €70k" en "Toe te rekenen aan minderheidsbelang: €30k". Op de balans wordt €30k aandeel-derden als aparte EV-component getoond.

- GIVEN dezelfde dochter, en gedurende 2026 wordt het belang verhoogd van 70% naar 85%
  WHEN consolidatie 2026 loopt
  THEN de groep registreert de verhoging als equity-transactie (geen goodwill bij belangenwijziging zonder controle-overgang), en de minority-interest-saldo daalt evenredig met de aandelenoverdracht.

- GIVEN een 100%-dochter waarin minderheid via prioriteitsaandelen wel stemrecht heeft
  WHEN consolidatie loopt
  THEN consolidatie blijft integraal (100% economisch belang), maar de gebruiker kan kiezen om in toelichting de stemverhouding apart te documenteren.

### REQ-CONS-007: Acquisitie-accounting (goodwill, badwill)

Het systeem MOET bij eerste-consolidatie van een nieuw verworven dochter de koopprijs vergelijken met de fair value van de verworven netto-activa en het verschil als goodwill (positief) of badwill (negatief) verantwoorden, volgens RJ 216 (Nederlandse commerciële norm) of IFRS 3 (Business Combinations).

- GIVEN Holding BV koopt op 1 juli 2025 100% van Target BV voor €1.500.000; fair value van Target's identificeerbare netto-activa op acquisitie-datum is €1.000.000
  WHEN eerste consolidatie van Target BV loopt
  THEN het systeem berekent goodwill €500.000, activeert dit op de geconsolideerde balans onder immateriële vaste activa, en start een 10-jaars-lineaire afschrijving (RJ-default; gebruiker kan tot max 20 jaar verlengen met onderbouwing).

- GIVEN dezelfde acquisitie maar koopprijs is €800k en fair value netto-activa is €1.000k
  WHEN eerste consolidatie loopt
  THEN het systeem registreert badwill €200k, en boekt dit volgens RJ 216 direct als bate in de V&W van het verwervingsjaar (na hertoetsing van de fair value assessment).

- GIVEN gebruiker rapporteert onder IFRS in plaats van RJ
  WHEN goodwill wordt geactiveerd
  THEN het systeem schakelt naar IFRS-regime: geen jaarlijkse afschrijving maar jaarlijkse impairment-test conform IAS 36 (CGU-bepaling, recoverable amount, write-down naar VIU of FVLCS).

### REQ-CONS-008: Eliminatie-audit-trail en accountant-review

Het systeem MOET voor elke gegenereerde of handmatige eliminatie een volledige audit-trail bijhouden — wie/wanneer/waarom — en accountants in staat stellen elke eliminatie te reviewen, goed te keuren of te weigeren, met opmerkingen die in het permanente dossier worden vastgelegd.

- GIVEN consolidatie heeft 47 eliminatie-boekingen gegenereerd voor periode 2025
  WHEN accountant het consolidatie-werkpapier opent
  THEN elke eliminatie toont: type, bron-transacties (klikbaar naar de originele journaalposten in de bron-administraties), debet/credit-regels, gegenereerd door (systeem of user-naam), gegenereerd op (timestamp), review-status, review-opmerkingen.

- GIVEN accountant vindt een eliminatie verdacht en wil deze afkeuren
  WHEN ze klikt "afkeuren" met motivatie "intercompany classificatie onjuist, dit was externe sale"
  THEN het systeem markeert de eliminatie als rejected, draait deze terug uit de consolidatie, en logt de wijziging in de audit-trail. Een herziene consolidatie kan worden gegenereerd.

- GIVEN accountant heeft consolidatie definitief goedgekeurd en gesigned
  WHEN ze status op "gesloten" zet
  THEN het systeem maakt een snapshot van de consolidatie (alle eliminaties, bron-transacties, eindcijfers) en archiveert dit immutabel voor 7+ jaar onder de fiscale bewaarplicht en de bewaarplicht voor jaarrekening-werkpapieren.

### REQ-CONS-009: Comparatieve periodes en herclassificatie

Het systeem MOET geconsolideerde cijfers altijd comparatief presenteren (huidig jaar + vorig jaar) en bij herclassificaties of stelselwijzigingen de vergelijkende cijfers automatisch aanpassen met expliciete melding aan de gebruiker.

- GIVEN consolidatie voor 2025 wordt gegenereerd
  WHEN gebruiker de geconsolideerde balans bekijkt
  THEN het systeem toont per rapportageregel zowel 2025 als 2024 in twee kolommen. Verschilkolom (€ en %) is optioneel zichtbaar.

- GIVEN gebruiker verandert de groep-rekeningschema-mapping van een dochter voor 2025 (verschuift telefoonkosten van 4310 naar 6220)
  WHEN consolidatie 2025 wordt gegenereerd
  THEN het systeem detecteert dat dit een herclassificatie is en biedt aan om 2024 ook te herclassificeren voor vergelijkbaarheid. Indien geaccepteerd, worden 2024-cijfers in de comparatieve kolom overeenkomstig aangepast en wordt dit als toelichting bij de jaarrekening opgenomen.

- GIVEN gebruiker voegt halverwege 2025 een nieuwe dochter toe aan de consolidatiekring
  WHEN consolidatie loopt
  THEN het systeem consolideert de nieuwe dochter pro-rata vanaf acquisitiedatum, en in de toelichting wordt expliciet de "wijziging in groepssamenstelling" gemeld.

### REQ-CONS-010: Geconsolideerde toelichting en uitsplitsing

Het systeem MOET een geconsolideerde toelichting (financial statement notes) genereren met de wettelijk verplichte uitsplitsingen, waaronder consolidatie-grondslagen, lijst van groepsmaatschappijen, verloop van eigen vermogen (incl. minority interest en CTA), goodwill-verloop, en intercompany-eliminaties op hoofdcategorieën.

- GIVEN geconsolideerde jaarrekening 2025 wordt gegenereerd
  WHEN de toelichting wordt opgesteld
  THEN het systeem genereert standaard-paragrafen voor: (1) consolidatiegrondslag (RJ 217 of IFRS 10), (2) lijst van geconsolideerde maatschappijen met naam/zetel/belang/methode, (3) verloop eigen vermogen in matrixvorm (geplaatst kapitaal, agio, overige reserves, herwaardering, CTA, onverdeeld resultaat, aandeel derden), (4) verloop goodwill (beginstand, acquisities, afschrijvingen, impairments, eindstand), (5) overzicht intercompany-eliminaties per categorie (sales, AR/AP, leningen, dividenden).

- GIVEN groep heeft significante minderheidsbelangen
  WHEN toelichting wordt gegenereerd
  THEN er wordt een aparte paragraaf "Aandeel derden" opgenomen met per relevante dochter het percentage, het aandeel in resultaat en de eindstand minderheidsbelang.

- GIVEN groep heeft buitenlandse dochters
  WHEN toelichting wordt gegenereerd
  THEN er komt een paragraaf "Vreemde valuta" met de toegepaste koersen, totale CTA-mutatie van het jaar, en cumulatief CTA-saldo per balansdatum.

## Standards & Sources

- **Burgerlijk Wetboek Boek 2 Titel 9** — wettelijke basis voor consolidatie-verplichting (art. 2:406), vrijstellingen (2:407, 2:408, 2:403), en presentatie-eisen voor geconsolideerde jaarrekening
- **Raad voor de Jaarverslaggeving — RJ 217 Geconsolideerde Jaarrekening** — Nederlandse commerciële norm, integraal/proportioneel/equity, eliminatie-regels, goodwill-behandeling
- **RJ 216 Fusies en Overnames** — purchase-accounting, fair value bepaling, goodwill/badwill
- **RJ 122 Prijsgrondslagen voor vreemde valuta** — current-rate methode, CTA
- **IAS 27 Separate Financial Statements** + **IFRS 10 Consolidated Financial Statements** — IFRS-tegenhanger voor groepen die IFRS rapporteren
- **IFRS 3 Business Combinations** — IFRS purchase-accounting, niet-afschrijven goodwill + impairment
- **IAS 21 The Effects of Changes in Foreign Exchange Rates** — IFRS currency translation
- **NBA-handreiking 1118** — accountantsvereisten bij consolidatie-controle (audit van eliminaties, going-concern in groepscontext)
- **SBR/XBRL-taxonomie KVK** — geconsolideerde jaarrekening velden voor publicatie (depositioning)

## Cross-app integration

- **bookkeeping-multi-administratie** (NEW spec, REQUIRED) — levert de per-entiteit grootboeken die geconsolideerd worden; consolidatie kan alleen werken als Shillinq meerdere administraties in één instance ondersteunt
- **bookkeeping-financial-statements** — per-entiteit balans + V&W zijn de input voor de consolidatie-aggregatie; rapportageregel-mapping moet consistent zijn
- **bookkeeping-intercompany-elimination** (NEW spec) — levert de matching-engine die intercompany-transacties detecteert; deze brief beschrijft de consolidatie-context, de eliminatie-spec beschrijft het matching-algoritme in detail
- **bookkeeping-titel-9-jaarrekening** (NEW spec) — de geconsolideerde jaarrekening is een variant van de Titel 9 jaarrekening met aanvullende paragrafen
- **bookkeeping-sbr-xbrl-reporting** — geconsolideerde cijfers worden via SBR-XBRL gedeponeerd bij de KVK, met aparte taxonomie-entry-points voor geconsolideerd
- **openregister** — alle entiteiten in `ConsolidationGroup`, `GroupEntity`, `EliminationEntry`, `Goodwill` etc. zijn OpenRegister-schemas; relaties via OR-relationships
- **openconnector** — koppeling met bron-systemen voor entiteiten die niet in Shillinq zelf staan (een dochter die nog Exact Online gebruikt levert via OC een trial-balance via API)

## Target users

**Accountantskantoor (specialist consolidatie)**: een controle- of samenstel-accountant die voor MKB-klanten met groepstructuren de geconsolideerde jaarrekening opstelt of controleert. Werkt vandaag met Excel-werkpapieren of dure dedicated tools (Caseware, Tax Solutions). Wil per consolidatie-cyclus 60-80% tijdsbesparing en een audit-proof trail.

**Concern-controller / Group financial controller**: in-house controller bij een MKB-holding die maandelijks of kwartaalmatig geconsolideerde cijfers nodig heeft voor management-rapportage en jaarlijks voor de officiële jaarrekening. Wil dashboard-view van eliminatie-volume, mismatch-queue, en consolidatie-status per entiteit.

**Directeur-grootaandeelhouder van een DGA-structuur**: eigenaar van een persoonlijke holding + werk-BV(s). Wil bij voorkeur zonder accountant-tussenkomst zelf de geconsolideerde jaarrekening kunnen genereren voor zijn eigen inzicht en voor de bank (financiering), en pas bij de officiële deposito-versie zijn accountant inschakelen voor review.

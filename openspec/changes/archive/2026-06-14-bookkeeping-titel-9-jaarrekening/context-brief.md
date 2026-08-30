---
status: draft
---
# Bookkeeping Titel 9 Boek 2 BW Jaarrekening Generatie

## Purpose

Elke Nederlandse rechtspersoon — BV, NV, coöperatie, stichting met onderneming, vereniging boven bepaalde grens — moet jaarlijks een **jaarrekening** opstellen die voldoet aan Titel 9 Boek 2 Burgerlijk Wetboek. Deze jaarrekening bestaat uit een balans, een winst-en-verliesrekening, een toelichting daarop, en voor middelgrote en grote rechtspersonen ook een kasstroomoverzicht en een bestuursverslag. De jaarrekening moet binnen vijf maanden na boekjaareinde door het bestuur opgemaakt, binnen zeven maanden door de algemene vergadering vastgesteld (of na maximum drie maanden uitstel binnen tien maanden), en daarna binnen acht dagen bij de Kamer van Koophandel gedeponeerd. Deponering gebeurt sinds 2017 verplicht digitaal in SBR-XBRL-formaat (Standard Business Reporting met XBRL-taxonomie).

De wet onderscheidt vier groottecategorieën — micro, klein, middelgroot, groot — met steeds zwaardere eisen aan omvang en openbaarheid van de jaarrekening. Een micro-rechtspersoon (balans <€450k, omzet <€900k, gemiddeld <10 werknemers) deponeert alleen een verkorte balans zonder winst-en-verliesrekening en zonder toelichting; een grote rechtspersoon (>€25m balans, >€50m omzet, >250 werknemers) moet een volledige geauditeerde jaarrekening met uitgebreide toelichting en bestuursverslag publiceren. Voor middelgrote rechtspersonen is een accountantsverklaring verplicht; voor kleine rechtspersonen niet (al kiezen veel kleine BV's er vrijwillig voor, vaak op verzoek van financiers).

Het opstellen van een Titel 9 jaarrekening is **routinematig maar onverbiddelijk specifiek**. De wet schrijft exact voor welke rubrieken in welke volgorde op de balans verschijnen (art. 2:373 en RJ 240), welke posten in de winst-en-verliesrekening (model A "categorisch" of model E "functioneel", naar keuze maar consequent), welke toelichtingen verplicht zijn (waarderingsgrondslagen, mutaties materieel vaste activa, opbouw eigen vermogen, omvang en condities van schulden, niet-uit-balans-blijkende verplichtingen, segmenten omzet voor middelgroot+, bezoldigingen voor groot, etc.), en welke comparatieve cijfers (minimaal vorig boekjaar, soms meer bij stelselwijzigingen). De Raad voor de Jaarverslaggeving (RJ) heeft 25+ richtlijnen die de wettelijke kaders concretiseren — RJ 210 (immateriële activa), 212 (materiële activa), 220 (financiële activa), 240 (eigen vermogen), 252 (voorzieningen), 290 (financiële instrumenten), 271 (personeelsbeloningen), 272 (belastingen), 350 (kasstroomoverzicht), etc.

Vandaag wordt jaarrekening-opstelling overal door samenstel-accountants in dedicated software gedaan: Caseware, Visionplanner Samenstellen, Auditor, Unit4 Multivers Reporting. De software is goed maar duur (€1.500-5.000 per kantoor per jaar) en gericht op accountants — niet op de ondernemer zelf. Voor de DGA betekent dit elke jaarrekening €1.500-4.000 aan samenstel-fees, ook al heeft hij de administratie zelf perfect in orde. Voor de accountant zit het werk vooral in het in-output-mappen (bron-administratie → samenstel-software → XBRL → KVK), niet in inhoudelijk denkwerk.

Shillinq's Titel 9 jaarrekening-generatie sluit dit gat door **direct vanuit de Shillinq grootboek-administratie** een wettelijk-conforme jaarrekening te genereren — concept, review, sign-off, en deponering bij KVK via SBR-XBRL — zonder dat de bron-data eerst geëxporteerd hoeft te worden naar externe samenstel-software. De ondernemer met een eenvoudige kleine BV kan dit volledig zelf doen (vrijstelling van accountantsverklaring); de middelgrote rechtspersoon of complexe DGA-structuur betrekt zijn accountant via in-app review en sign-off flows. De jaarrekening output wordt automatisch onderhouden synchroon met de bron-administratie: een correctie op een grootboekpost laat de jaarrekening-cijfers meebewegen tot het moment van definitieve sign-off, waarna de jaarrekening immutabel wordt en eventuele post-vaststelling correcties als foutherstel worden behandeld.

De scope van deze brief is **enkelvoudige (niet-geconsolideerde) Titel 9 jaarrekening voor de commerciële rechtspersoon**. Geconsolideerde jaarrekening voor groepen staat in `bookkeeping-consolidation-commercial`. Fiscale jaarrekening (vennootschapsbelasting-aangifte) en IB-aangifte voor IB-ondernemers vallen onder aparte modules. SBR-XBRL-deponering wordt hier als onmisbare integratie genoemd maar de XBRL-mapping-mechanica zelf staat in `bookkeeping-sbr-xbrl-reporting`.

## Data Model

**AnnualReport**: hoofdrecord van een jaarrekening voor een specifieke entiteit en boekjaar. Velden: administratie-id, boekjaar-start, boekjaar-eind, groottecategorie (micro, klein, middelgroot, groot), groottecategorie-onderbouwing (twee opeenvolgende boekjaren voldoen aan twee van drie criteria — balansomvang, netto-omzet, gemiddeld aantal werknemers), rapportage-grondslag (RJ-commercieel, fiscaal, IFRS-light, IFRS-volledig), valuta (default EUR), status (concept, in-review, vastgesteld, gedeponeerd, gearchiveerd), opmaak-datum (bestuur), vaststelling-datum (algemene vergadering), deponering-datum (KVK), accountantsverklaring-vereist (boolean), accountantsverklaring-status (none, voorbereid, ondertekend, afgegeven), bestuur-leden (lijst), aandeelhouders-besluit-jsonb.

**BalanceSheet** en **IncomeStatement**: structured-output van de balans en V&W, georganiseerd volgens de wettelijke modellen (Titel 9 art. 2:373 voor balans-model, 2:377 voor V&W-model A "categorisch" of E "functioneel"). Elke regel heeft: rubriek-code (wettelijke nummering bijv. "B.I.1 Materiële vaste activa"), label, huidig-jaar-bedrag, vorig-jaar-bedrag, footnote-references (lijst van toelichting-paragrafen die op deze regel betrekking hebben), source-mapping (welke grootboekrekeningen uit de bron-administratie samen deze regel vormen).

**CashFlowStatement**: kasstroomoverzicht volgens RJ 350, alleen verplicht voor middelgroot+. Velden: methode (direct of indirect — indirect is veruit gebruikelijker), drie hoofd-categorieën (operationeel, investerings, financierings), regels per categorie met huidig + vorig jaar, mutatie liquide middelen verklaring.

**Note**: een individuele toelichting-paragraaf. Velden: report-id, volgorde, code (bijv. "1.1 Algemene grondslagen"), titel, content-jsonb (rich-text met embedded tabellen, MVA-verloopstaat, EV-verloop, schuldensplitsing, etc.), wettelijke-basis (art. 2:381, 2:382, RJ 240, etc.), genereerd-door (template-naam) of handmatig-bewerkt (boolean).

**Director Report (Bestuursverslag)**: voor middelgroot+ verplicht apart document. Velden: report-id, secties (algemeen, financiële gang van zaken, risico's en onzekerheden, toekomstparagraaf, personeel, milieu, R&D, ESG, gebeurtenissen na balansdatum), per-sectie content (markdown of rich text), opmaakstatus, bestuur-handtekeningen.

**AuditOpinion**: accountantsverklaring (controle, beoordeling, of samenstellingsverklaring). Velden: report-id, type (controle-verklaring, beoordelingsopdracht, samenstellingsverklaring), strekking (goedkeurend, met beperking, oordeelonthouding, afkeurend), accountantskantoor, controlerend-accountant (RA-nummer), key-audit-matters (alleen controle, lijst), getekend-op, signed-pdf-storage-id.

**ReportTemplate** en **ReportSection**: configureerbare templates voor de standaard-secties van de jaarrekening, per groottecategorie en grondslag. Bijvoorbeeld: template "klein-RJ-commercieel" bevat verplichte secties [grondslagen, balans, V&W, toelichting balans, toelichting V&W, ondertekening] en optionele [bestuursverslag, kasstroom]. Template "middelgroot-RJ" voegt verplicht toe [kasstroomoverzicht, bestuursverslag, accountantsverklaring]. Elke sectie heeft een generator (welke berekening produceert de content) en een rendering-template (Twig/Handlebars).

**ReviewWorkflow**: workflow-record voor het concept → review → vaststelling → deponering proces. Velden: report-id, huidige stap, lijst van stappen met assignee, completion-status, comments per stap, overdracht-momenten (bijv. concept → accountant voor review → terug naar bestuur voor vaststelling).

## Requirements

### REQ-T9-001: Groottecategorie-bepaling en classificatie

Het systeem MOET op basis van de bron-administratie-cijfers automatisch de groottecategorie van de rechtspersoon bepalen (micro, klein, middelgroot, groot) volgens art. 2:395a-398 BW, waarbij ten minste twee van de drie criteria (balanstotaal, netto-omzet, gemiddeld aantal werknemers) over twee opeenvolgende boekjaren voldaan moeten zijn, en de wettelijke gevolgen voor opmaak en deponering tonen.

- GIVEN een BV met 2024 en 2025 cijfers: balanstotaal 2024 €380k en 2025 €420k, netto-omzet 2024 €750k en 2025 €820k, gemiddeld 6 werknemers in beide jaren
  WHEN ik jaarrekening 2025 aanmaak
  THEN het systeem classificeert deze als "micro" (alle drie criteria onder micro-grens in beide jaren), toont de classificatie met onderbouwing, en activeert het micro-template (alleen verkorte balans deponering).

- GIVEN een BV met balanstotaal €15 miljoen, omzet €30 miljoen, 80 werknemers (overschrijdt klein-grenzen maar onder middelgroot-grens van €25m balans / €50m omzet / 250 werknemers)
  WHEN ik jaarrekening aanmaak
  THEN het systeem classificeert als "middelgroot" (twee van drie criteria — omzet €30m > klein €12m, werknemers 80 > klein 50), markeert accountantsverklaring als verplicht, en activeert het middelgroot-template (incl. kasstroomoverzicht + bestuursverslag).

- GIVEN een BV met grenswaarde-cijfers die op het kantelpunt liggen tussen klein en middelgroot
  WHEN classificatie loopt
  THEN het systeem toont een waarschuwing met de "twee-opeenvolgende-jaren"-regel: groottecategorie verandert pas als twee opeenvolgende jaren in de nieuwe categorie vallen. Voor het overgangsjaar geldt de oude categorie nog.

### REQ-T9-002: Balans-genering conform art. 2:373

Het systeem MOET de balans genereren volgens het wettelijke model (art. 2:373 BW + RJ 240), met activa-zijde opgesplitst in vaste activa (immateriële, materiële, financiële) en vlottende activa (voorraden, vorderingen, effecten, liquide middelen), en passiva-zijde in eigen vermogen, voorzieningen, langlopende schulden en kortlopende schulden, met correcte rubrieknummering en comparatieve cijfers.

- GIVEN administratie met materiële vaste activa €450k, immateriële €120k, vorderingen €180k, liquide middelen €95k, eigen vermogen €380k, voorzieningen €45k, langlopende schulden €280k, kortlopende schulden €140k
  WHEN balans 2025 wordt gegenereerd
  THEN het systeem produceert een gestructureerde balans met rubrieken B.I (immateriële) €120k, B.II (materiële) €450k, B.III (financiële) €0, totaal vaste activa €570k. C.I (voorraden) €0, C.II (vorderingen) €180k, C.III (effecten) €0, C.IV (liquide middelen) €95k, totaal vlottende activa €275k. Totaal activa = €845k. Aan passiva-zijde A (eigen vermogen) €380k, B (voorzieningen) €45k, C (langlopende schulden) €280k, D (kortlopende schulden) €140k. Totaal passiva = €845k. Balans sluit.

- GIVEN een balans rubriek "Vorderingen" omvat verschillende subcategorieën (handelsdebiteuren, intercompany, overige)
  WHEN balans wordt gegenereerd
  THEN het systeem toont de hoofdregel C.II in de balans zelf, en koppelt automatisch een toelichting-paragraaf "Vorderingen" met uitsplitsing in subcategorieën conform art. 2:381.

- GIVEN gebruiker wil de balans als comparatief overzicht 2025 vs 2024 zien
  WHEN balans wordt gerenderd
  THEN elke regel toont huidig jaar (2025) en vorig jaar (2024), met optionele kolom voor verschil. Comparatieve cijfers komen automatisch uit de jaarrekening van vorig jaar (indien beschikbaar) of uit de openingsbalans van het huidige boekjaar.

### REQ-T9-003: Winst-en-verliesrekening (model A of E)

Het systeem MOET de winst-en-verliesrekening genereren volgens het categorische model A (art. 2:377 lid 2) of het functionele model E (lid 3), naar keuze maar in jaarverslagen consequent, met de rubrieken in wettelijke volgorde en de juiste tussentotalen.

- GIVEN administratie kiest model A "categorisch", omzet €1.500k, mutatie voorraden €+50k, geactiveerde productie €0, overige bedrijfsopbrengsten €25k; kosten van grond- en hulpstoffen €420k, lonen + salarissen €380k, sociale lasten + pensioenen €95k, afschrijvingen €80k, overige bedrijfskosten €250k; rentebaten €4k, rentelasten €18k; belastingen €70k
  WHEN V&W wordt gegenereerd
  THEN het systeem produceert: 1. Netto-omzet €1.500k, 2. Wijziging voorraden €50k, 3. Geactiveerde productie €0, 4. Overige bedrijfsopbrengsten €25k. Subtotaal bedrijfsopbrengsten €1.575k. 5. Kosten grond- en hulpstoffen €420k, 6. Lonen €380k, 7. Sociale lasten €95k, 8. Afschrijvingen €80k, 9. Overige bedrijfskosten €250k. Subtotaal bedrijfslasten €1.225k. Bedrijfsresultaat €350k. 10. Rentebaten €4k, 11. Rentelasten €(18k). Resultaat voor belasting €336k. 12. Belastingen €(70k). Nettoresultaat €266k.

- GIVEN administratie kiest model E "functioneel"
  WHEN V&W wordt gegenereerd
  THEN het systeem groepeert kosten naar functie (kostprijs verkopen, verkoop- en distributiekosten, algemene beheerskosten) in plaats van naar categorie, met bruto-marge-tussen-totaal na kostprijs.

- GIVEN gebruiker wil halverwege het boekjaar wisselen van model A naar model E
  WHEN ze het modelaanpassen
  THEN het systeem waarschuwt dat consistente toepassing wettelijk verplicht is (stelselwijziging), vraagt om motivatie, en eist dat comparatieve cijfers vorig jaar ook in het nieuwe model worden gepresenteerd.

### REQ-T9-004: Toelichting-generatie (wettelijk verplichte paragrafen)

Het systeem MOET alle wettelijk verplichte toelichting-paragrafen automatisch genereren op basis van de groottecategorie en de inhoud van de bron-administratie, waaronder: algemene grondslagen, mutatieoverzicht materiële vaste activa, opbouw eigen vermogen met verloop, uitsplitsing en condities van schulden, niet-uit-balans-blijkende verplichtingen, gebeurtenissen na balansdatum, en (voor middelgroot+) segmentinformatie en bezoldigingen.

- GIVEN middelgrote BV met materiële vaste activa (gebouwen, machines, inventaris) en toegepaste afschrijvingsmethoden
  WHEN toelichting wordt gegenereerd
  THEN het systeem produceert een mutatieoverzicht MVA conform RJ 212: per categorie aanschafwaarde-beginstand, investeringen, desinvesteringen, afschrijvingen, impairments, aanschafwaarde-eindstand; cumulatieve afschrijving begin- en eindstand; boekwaarde begin- en eindstand. Met afschrijvingsmethode en -percentages per categorie.

- GIVEN BV met onverdeelde winst, statutaire reserves, agioreserve, herwaarderingsreserve
  WHEN toelichting eigen vermogen wordt gegenereerd
  THEN het systeem produceert een verloopoverzicht in matrix-vorm: kolommen per EV-component (geplaatst kapitaal, agio, herwaardering, wettelijke reserve, overige reserves, onverdeeld resultaat), rijen voor beginstand, mutaties (resultaatbestemming vorig jaar, dividend, nettoresultaat huidig jaar, herwaardering), eindstand.

- GIVEN BV heeft langlopende lening van bank met aflossingsschema en zekerheden
  WHEN toelichting schulden wordt gegenereerd
  THEN het systeem produceert paragraaf met per significante schuld: bedrag, rentevoet, einddatum, aflossingsschema (binnen 1 jaar / 1-5 jaar / >5 jaar uitsplitsing), zekerheden (hypotheek, pand, borgstelling).

### REQ-T9-005: Kasstroomoverzicht (verplicht middelgroot+)

Het systeem MOET een kasstroomoverzicht volgens RJ 350 genereren — verplicht voor middelgrote en grote rechtspersonen, vrijwillig voor klein — met de drie hoofdcategorieën operationele, investerings- en financieringsactiviteiten, methode indirect (standaard) of direct (op verzoek), en sluitende mutatie van geldmiddelen.

- GIVEN middelgrote BV met nettoresultaat €266k, afschrijvingen €80k, mutaties werkkapitaal (vorderingen +€30k, voorraden +€15k, crediteuren −€20k), investeringen €120k, langlopende-lening-aflossing €50k, dividend-uitkering €100k
  WHEN kasstroomoverzicht (indirect) wordt gegenereerd
  THEN het systeem produceert: Operationele kasstroom = nettoresultaat €266k + afschrijvingen €80k − vorderingen-toename €30k − voorraad-toename €15k − crediteuren-afname €20k = €281k. Investerings-kasstroom = − investeringen €120k = €(120k). Financierings-kasstroom = − aflossing €50k − dividend €100k = €(150k). Netto-mutatie geldmiddelen = €281k − €120k − €150k = €11k. Sluit aan op de werkelijke mutatie geldmiddelen volgens balans.

- GIVEN administratie heeft eenmalige post (verkoop deelneming €200k boekwinst)
  WHEN kasstroomoverzicht wordt gegenereerd
  THEN de boekwinst wordt uit het resultaat geëlimineerd in de operationele kasstroom en de kasontvangst uit de verkoop wordt apart als investerings-kasstroom getoond, om dubbeltelling te voorkomen.

- GIVEN gebruiker wil de directe methode in plaats van indirect
  WHEN ze de methode omschakelt
  THEN het systeem toont een waarschuwing dat de directe methode hogere data-eisen heeft (per soort ontvangst en uitgave) en biedt aan om de relevante mapping uit het kasstroom-rekeningschema op te zetten of, bij ontbreken daarvan, terug te vallen op indirect.

### REQ-T9-006: Bestuursverslag (middelgroot+)

Het systeem MOET een bestuursverslag-template genereren voor middelgrote en grote rechtspersonen met de wettelijk vereiste secties (art. 2:391: getrouw overzicht van toestand op balansdatum, ontwikkeling tijdens boekjaar, verwachte gang van zaken, risico's en onzekerheden, personeel, milieu, R&D, ESG voor grote), met sjabloon-tekst die door bestuur kan worden aangevuld.

- GIVEN middelgrote BV genereert jaarrekening 2025
  WHEN het bestuursverslag-template wordt aangemaakt
  THEN het systeem produceert een document met secties: 1. Algemeen (rechtsvorm, vestiging, activiteiten — auto-ingevuld), 2. Financiële gang van zaken (auto-tekst met omzet-, marge-, resultaat-vergelijking jaar-op-jaar + grafieken), 3. Risico's en onzekerheden (template-prompts met voorbeeld-categorieën), 4. Verwachte gang van zaken (leeg, door bestuur in te vullen), 5. Personeel (gemiddeld aantal werknemers, ziekteverzuim auto-uit-HR-bron indien beschikbaar), 6. Ondertekening (datum + bestuur-namen auto-ingevuld).

- GIVEN BV heeft R&D-activiteiten met activering van ontwikkelingskosten
  WHEN bestuursverslag wordt gegenereerd
  THEN automatisch wordt een R&D-paragraaf toegevoegd met overzicht van geactiveerde ontwikkelingskosten, lopende projecten, en toelichting op behoud van duurzame meerwaarde.

- GIVEN grote rechtspersoon valt onder CSRD/EU-duurzaamheidsrapportage-eisen
  WHEN bestuursverslag wordt gegenereerd
  THEN het systeem voegt een ESG-sectie toe met placeholder-velden voor de ESRS-thema's (E1-5 milieu, S1-4 sociaal, G1 governance) en linkt naar de aparte CSRD-module.

### REQ-T9-007: Accountantsverklaring-flow (middelgroot+ verplicht, klein vrijwillig)

Het systeem MOET de workflow ondersteunen voor het inschakelen van een accountant — voor middelgrote en grote rechtspersonen verplicht (controleverklaring), voor kleine optioneel (samenstellingsverklaring of beoordelingsopdracht) — met deel-werkpapieren, review-iteraties tussen bestuur en accountant, en uiteindelijke ondertekening en opname van de accountantsverklaring in de jaarrekening.

- GIVEN concept-jaarrekening klaar voor middelgrote BV
  WHEN bestuur de jaarrekening "indient bij accountant voor controle"
  THEN het systeem creëert een review-task voor de accountant, geeft toegang tot alle bron-administratie en concept-output, en biedt de accountant een interface om opmerkingen te plaatsen per balans/V&W-regel of toelichting-paragraaf.

- GIVEN accountant heeft 12 opmerkingen geplaatst en stelt aanpassingen voor
  WHEN bestuur de opmerkingen verwerkt en wijzigingen doorvoert
  THEN het systeem traceert per opmerking of deze "verwerkt", "afgewezen met motivatie" of "ter discussie" is; revisie-iteratie wordt gelogd.

- GIVEN accountant is tevreden en wil een goedkeurende controleverklaring afgeven
  WHEN accountant de verklaring opmaakt (controle-strekking goedkeurend, met of zonder key audit matters)
  THEN het systeem rendert de verklaring met standaard-tekst NV-COS 700, ondertekening met accountantsnaam en RA-nummer, en hecht deze definitief aan de jaarrekening.

### REQ-T9-008: SBR-XBRL-deponering bij KVK

Het systeem MOET de definitieve jaarrekening converteren naar SBR-XBRL-formaat volgens de actuele KVK-taxonomie en deze elektronisch indienen bij de KVK via de Digipoort, met automatische bevestigingsverwerking en archivering.

- GIVEN jaarrekening definitief vastgesteld door algemene vergadering, status "vastgesteld", klein-categorie
  WHEN bestuur "Deponeer bij KVK" kiest
  THEN het systeem genereert XBRL-instance-document conform NT (Nederlandse Taxonomie) entry-point "Klein-KVK", valideert tegen XBRL-schema (alle verplichte velden ingevuld, tussentotalen kloppen), en biedt een preview voor approval.

- GIVEN preview wordt goedgekeurd en deponering wordt verstuurd
  WHEN het systeem via Digipoort de aanlevering doet
  THEN bestuur ontvangt status-updates (verzonden, ontvangen, formeel verwerkt, openbaar), de KVK-ontvangstbevestiging wordt opgeslagen in het permanente dossier, en de deponering-datum wordt geregistreerd.

- GIVEN deponering wordt door KVK afgewezen wegens validatie-error (bijv. ontbrekend SBI-code of inconsistent rekenkundig totaal)
  WHEN error-response binnenkomt
  THEN het systeem parseert de error-details, toont gebruiker welk veld het probleem is, biedt directe correctie-mogelijkheid en re-submit zonder dat de hele jaarrekening opnieuw opgemaakt hoeft te worden.

### REQ-T9-009: Vrijstellingen klein (geen toelichting + geen accountantsverklaring)

Het systeem MOET de verlichte regels voor kleine rechtspersonen toepassen: verkorte balans, beperkte toelichting (geen V&W-toelichting verplicht, beperkte rubrieken), geen kasstroomoverzicht verplicht, geen bestuursverslag verplicht, en geen accountantsverklaring verplicht (art. 2:396 lid 7-9 BW).

- GIVEN BV classificeert als klein
  WHEN jaarrekening wordt gegenereerd
  THEN het systeem activeert klein-template: verkorte balans (hoofdrubrieken, geen sub-uitsplitsing), beperkte toelichting (alleen grondslagen + verplichte EV-toelichting + niet-uit-balans-blijkende verplichtingen), geen verplichting V&W-publicatie (de gedeponeerde versie bevat alleen verkorte balans + beperkte toelichting), geen bestuursverslag, geen kasstroomoverzicht, geen accountantsverklaring.

- GIVEN kleine BV wil vrijwillig een uitgebreidere jaarrekening publiceren (op verzoek van financier)
  WHEN bestuur het uitgebreide template kiest
  THEN het systeem genereert de uitgebreide versie (zoals voor middelgroot maar zonder accountantsverklaring) en biedt aan om deze separat te delen (PDF of digitaal portaal) terwijl bij KVK alleen de wettelijk-minimale versie wordt gedeponeerd.

- GIVEN micro-BV valt onder nog beperktere regels (alleen verkorte balans zonder toelichting)
  WHEN deponering wordt voorbereid
  THEN het systeem produceert het micro-template (alleen balans, geen V&W, geen toelichting) en gebruikt het micro-XBRL-entry-point voor KVK-aanlevering.

### REQ-T9-010: Concept → review → vaststelling → deponering workflow

Het systeem MOET de complete jaarrekening-cyclus orchestreren — van eerste concept op basis van bron-cijfers, via interne review en accountant-review, naar opmaak door bestuur, vaststelling door algemene vergadering, en deponering bij KVK — met audit-trail per fase, snapshot-versies, en wettelijke termijn-bewaking.

- GIVEN boekjaareinde 31 december 2025
  WHEN jaarrekening-workflow voor BV start in februari 2026
  THEN het systeem toont de wettelijke termijnen: opmaak door bestuur uiterlijk 5 maanden na BJ (31 mei 2026, met maximaal 5 maanden uitstel tot 31 oktober), vaststelling AV uiterlijk 2 maanden na opmaak, deponering KVK uiterlijk 8 dagen na vaststelling, absolute deadline 12 maanden na BJ (31 dec 2026). Statusbalk toont voortgang.

- GIVEN concept wordt geüpdatet naarmate bron-administratie nog wijzigt (afsluitings-correcties)
  WHEN ik concept-versie bekijk
  THEN het systeem toont real-time cijfers maar markeert deze als "concept — niet vastgesteld"; elke wijziging in de bron leidt tot automatische heberekening totdat status op "opgemaakt door bestuur" gezet wordt (vanaf dan wordt een snapshot vastgehouden).

- GIVEN bestuur heeft jaarrekening opgemaakt en notarieel besluit van de algemene vergadering tot vaststelling is genomen
  WHEN ze "Vastgesteld door AV" markeren met upload van AV-besluit-PDF
  THEN het systeem registreert vaststellingsdatum, maakt een definitieve immutabele snapshot van alle cijfers en documenten, en activeert deponering-flow naar KVK.

## Standards & Sources

- **Burgerlijk Wetboek Boek 2 Titel 9** — wettelijke basis voor jaarrekening (art. 2:361 e.v.); balans-model art. 2:373; V&W-model art. 2:377; toelichting art. 2:381-388; bestuursverslag art. 2:391; openbaarmaking art. 2:394
- **Art. 2:395a-398 BW** — groottecriteria voor micro, klein, middelgroot, groot
- **Raad voor de Jaarverslaggeving (RJ)** — RJk-bundel voor klein (verlichte regels), RJ-bundel voor middelgroot+; specifieke richtlijnen voor MVA (RJ 212), IVA (RJ 210), EV (RJ 240), voorzieningen (RJ 252), pensioenen (RJ 271), belastingen (RJ 272), kasstroomoverzicht (RJ 350), gebeurtenissen na balansdatum (RJ 160)
- **NV-COS** (Nadere Voorschriften Controle- en Overige Standaarden) — accountantsstandaarden, in het bijzonder NV-COS 700 (controle-verklaring), NV-COS 4410 (samenstelling), NV-COS 2400 (beoordelingsopdracht)
- **KVK SBR-Taxonomie (Nederlandse Taxonomie, NT)** — XBRL-schema voor jaarrekening-deponering; per groottecategorie en jaargang een entry-point (bv. NT16-Klein-KVK)
- **EU Accounting Directive 2013/34/EU** — Europese harmonisatie van jaarrekening-regels die in Titel 9 BW is geïmplementeerd
- **CSRD (Corporate Sustainability Reporting Directive)** — voor grote rechtspersonen vanaf BJ 2024/2025 ESG-rapportage-eisen via ESRS-standaarden

## Cross-app integration

- **bookkeeping-financial-statements** (REQUIRED) — levert de geaggregeerde balans- en V&W-cijfers per rubriek; deze module bouwt daar de wettelijke presentatie en toelichting overheen
- **bookkeeping-sbr-xbrl-reporting** (REQUIRED) — verzorgt de XBRL-conversie en Digipoort-aanlevering bij KVK; deze module triggert die flow vanuit de vastgestelde jaarrekening
- **bookkeeping-grootboek** — bron van alle cijfers; rekeningschema-mapping naar Titel 9 rubrieken is een one-time-config per administratie
- **bookkeeping-vpb-aangifte** (future) — fiscale jaarrekening voor vennootschapsbelasting heeft eigen format; commerciële jaarrekening (deze module) is uitgangspunt voor fiscale herleidingen
- **bookkeeping-consolidation-commercial** (NEW spec) — voor groepen wordt naast enkelvoudige ook geconsolideerde jaarrekening opgesteld; consolidatie-module produceert geconsolideerde balans/V&W, deze module produceert de wettelijke jaarrekening-vorm
- **bookkeeping-csrd-esg-reporting** (future) — voor grote rechtspersonen vanaf 2025 verplichte ESG-rapportage als onderdeel van bestuursverslag
- **openregister** — `AnnualReport`, `Note`, `DirectorReport`, `AuditOpinion`, `ReviewWorkflow` zijn allemaal OR-schemas; immutable snapshots na vaststelling
- **openconnector** — Digipoort-koppeling voor KVK-deponering, optioneel ook koppeling met accountantskantoor-systemen (Caseware, Visionplanner) voor data-uitwisseling

## Target users

**DGA met kleine BV**: ondernemer die zelf zijn administratie voert in Shillinq en de jaarrekening zelf wil opstellen en deponeren bij KVK zonder accountant-tussenkomst. Wettelijk mag dit voor kleine rechtspersonen. Wil minimal-friction flow: bron-cijfers worden automatisch in jaarrekening-format gezet, hij review't, hij signeert, hij deponeert. €0 aan accountantskosten in plaats van €1.500.

**Samenstel-accountant (klein)**: accountant die voor kleine BV's de samenstelling van jaarrekening verzorgt (niet verplicht maar gangbaar). Wil tooling die efficiency oplevert: één-knop concept op basis van Shillinq-bron, makkelijke aanpassingen in toelichting, snelle sign-off en KVK-deponering. Tijdsbesparing van 4-8 uur per jaarrekening = €600-1.200 per klant per jaar.

**Controlerend accountant (middelgroot+)**: externe accountant die middelgrote/grote rechtspersonen controleert. Wil read-only toegang tot de bron-administratie en de jaarrekening-output in Shillinq, kunnen reviewen en opmerkingen plaatsen, controleverklaring afgeven die definitief aan jaarrekening hangt. Audit-trail van alle aanpassingen tijdens de controle is non-negotiable.

**Controller / financieel manager (middelgroot+)**: in-house verantwoordelijke voor jaarrekening-cyclus. Wil termijn-tracking, workflow-orchestratie tussen bestuur en accountant, en consistentie tussen maandafsluitingen, kwartaalcijfers en jaarrekening. Wil dat de jaarrekening de "single source of truth" is en exporteerbaar is naar bank-rapportages, intern reporting, etc.

**Bestuur (directie / RvB)**: ondertekenaars van de jaarrekening en bestuursverslag. Hebben niet de tijd om elk cijfer te verifiëren maar willen wel de mogelijkheid om kritische posten (resultaat, EV, dividend-voorstel) te begrijpen en specifieke paragrafen van het bestuursverslag (toekomstparagraaf, risico-analyse) zelf op te stellen.

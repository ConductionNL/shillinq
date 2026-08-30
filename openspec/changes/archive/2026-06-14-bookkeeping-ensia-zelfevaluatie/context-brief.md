status: draft

# Bookkeeping ENSIA Zelfevaluatie

## Purpose

ENSIA (Eenduidige Normatiek Single Information Audit) is de jaarlijkse verplichte zelfevaluatie informatieveiligheid voor gemeenten, provincies en waterschappen in Nederland. Onder regie van VNG/IPO/UvW moet elke decentrale overheid voor 1 mei rapporteren op de BIO-onderwerpen (Baseline Informatiebeveiliging Overheid) en aparte verantwoordingsdomeinen als DigiD, SUWI, BAG, BGT, BRP en WOZ. Het proces eindigt in een formele Colleges-verklaring die door het college van B&W moet worden ondertekend en doorgestuurd naar de minister van BZK.

De huidige praktijk is fragmentarisch: gemeenten gebruiken een mix van Excel-templates van VNG, eigen GRC-tools (vaak duur), en losse Word-documenten. Bewijsstukken (screenshots, beleidsdocumenten, audit-rapporten van leveranciers) zwerven over fileshares. De link tussen gestelde controle-vragen en daadwerkelijk bewijs is fragiel - bij externe audit (ENSIA-IT-audit door PwC/KPMG/EY) moeten teams dagen besteden aan reconstrueren wie waarom welke score heeft gegeven.

Deze spec levert een gestructureerde ENSIA-workflow binnen Shillinq: per BIO-onderwerp en per verantwoordingsdomein een vragenboom met scoring, bewijsstuk-koppeling, peer-review, college-akkoord-flow, en geautomatiseerd uitkomstrapport in het format dat ENSIA-tool verwacht (XML-export naar de landelijke ENSIA-portal). Audit-trail per antwoord-wijziging zodat externe auditor wijzigingsgeschiedenis kan traceren.

## Data Model

**ENSIAJaarcyclus** (nieuw schema, register `shillinq-ensia`):
- `jaar` (integer)
- `organisatie` (string, KvK + naam)
- `status` (enum: in-voorbereiding / in-uitvoering / peer-review / college-akkoord / ingediend / afgerond)
- `startDatum`, `deadlineColleges`, `deadlineMinister` (date)
- `verantwoordingsdomeinen` (array, enum: BIO / DigiD / SUWI / BAG / BGT / BRP / WOZ)
- `procesEigenaar` (user-reference, CISO)
- `verklaringFile` (file-reference, ondertekende collegebrief)

**Evaluatievraag** (nieuw schema):
- `cyclusId` (relatie)
- `domein` (enum)
- `onderwerp` (string, bv. "Toegangsbeveiliging", "Backup & Recovery")
- `vraagCode` (string, bv. "BIO-9.1.1")
- `vraagtekst` (string, lang)
- `antwoordType` (enum: ja-nee-nvt / volwassenheidsniveau-1-5 / vrije-tekst)
- `antwoord` (string)
- `volwassenheidsScore` (integer 1-5, optioneel)
- `toelichting` (string)
- `beantwoorder` (user)
- `peerReviewer` (user, nullable)
- `peerReviewStatus` (enum: nog-niet-beoordeeld / akkoord / wijziging-gevraagd)
- `bewijsstukken` (array van file-references + omschrijving)

**Bevinding** (nieuw schema, voor risico's en verbeterpunten):
- `cyclusId` (relatie)
- `vraagId` (relatie, nullable)
- `type` (enum: tekortkoming / verbeterpunt / risico-acceptatie)
- `beschrijving`, `impact`, `kans` (string/int)
- `mitigatieActie` (string)
- `verantwoordelijke` (user)
- `streefDatum` (date)
- `status` (enum: open / in-behandeling / gerealiseerd / geaccepteerd)

## Requirements

### REQ-001: Jaarcyclus initialiseren met VNG-vragenset

GIVEN een CISO start de ENSIA-cyclus voor jaar X
WHEN deze "Nieuwe ENSIA-cyclus" kiest en de relevante verantwoordingsdomeinen selecteert
THEN haalt het systeem via de VNG-bron de actuele vragenset voor jaar X op (BIO-versie + domein-specifieke vragen) en genereert per domein de Evaluatievragen met initial-status `nog-niet-beantwoord`.

### REQ-002: Vraag-toewijzing per onderwerp-eigenaar

GIVEN een geinitialiseerde cyclus met onbeantwoorde vragen
WHEN de proceseigenaar per BIO-onderwerp een beantwoorder toewijst (bijv. teamleider infra voor toegangsbeveiliging)
THEN ontvangen die gebruikers een notificatie met deeplink, en zien zij in hun persoonlijke werklijst alleen de vragen die aan hen toegewezen zijn, gegroepeerd per onderwerp.

### REQ-003: Volwassenheidsscore met onderbouwing-eis

GIVEN een vraag van type `volwassenheidsniveau-1-5`
WHEN de beantwoorder een score van 3 of hoger geeft
THEN eist het systeem minimaal een bewijsstuk (beleid, procedure-document, screenshot van werkende control, of audit-rapport) en een toelichting van minimaal 50 tekens voordat de vraag als beantwoord kan worden opgeslagen.

### REQ-004: Peer-review-flow

GIVEN een cyclus met status `in-uitvoering` waar alle vragen van een domein beantwoord zijn
WHEN het domein automatisch overgaat naar `peer-review` en peer-reviewers per onderwerp zijn toegewezen
THEN kan een peer-reviewer per vraag akkoord geven, of wijziging vragen met commentaar dat naar de oorspronkelijke beantwoorder gaat - waarbij de cyclus pas naar `college-akkoord` kan zonder open wijzigingsverzoeken.

### REQ-005: Bevindingen automatisch genereren

GIVEN een afgeronde peer-review met vragen-scores
WHEN volwassenheidsscore lager is dan het normniveau dat VNG voor die vraag definieert (vaak niveau 3)
THEN genereert het systeem automatisch een concept-Bevinding van type `tekortkoming` met de vraag als context, en plaatst deze in de bevindingen-lijst voor risico-acceptatie of mitigatie-planning.

### REQ-006: College-verklaring genereren

GIVEN een cyclus waarvan alle bevindingen een mitigatie-plan of risico-acceptatie hebben
WHEN de proceseigenaar "Genereer collegeverklaring" kiest
THEN produceert het systeem een Word-document op basis van het ENSIA-template (VNG) met automatisch ingevulde organisatiegegevens, samenvatting per domein, top-bevindingen, en handtekeningvelden - klaar voor college-vergadering.

### REQ-007: XML-export naar landelijke ENSIA-portal

GIVEN een cyclus met status `college-akkoord` en geuploade verklaringFile
WHEN de proceseigenaar "Indienen bij ENSIA-portal" kiest
THEN exporteert het systeem een XML-bestand conform de ENSIA-XSD met alle antwoorden, scores, bewijsstuk-hashes, en collegebrief-referentie, en biedt deze als download aan voor upload naar het VNG-portaal (geen directe API-koppeling beschikbaar in 2026).

### REQ-008: Wijzigings-audit-log met diff

GIVEN een willekeurige Evaluatievraag waarvan een antwoord wordt gewijzigd
WHEN de wijziging wordt opgeslagen
THEN registreert het systeem: tijdstip, gebruiker, oude antwoord, nieuwe antwoord, reden-veld (verplicht bij wijziging na peer-review), zodat een externe auditor (IT-audit-fase) per vraag de wijzigingsgeschiedenis kan inzien met diff-weergave.

## Standards

- **BIO** (Baseline Informatiebeveiliging Overheid, momenteel BIO-1.04 + BIO-2 in transitie)
- **ENSIA** (Eenduidige Normatiek Single Information Audit, VNG/IPO/UvW)
- **ISO 27001:2022** (alignment met BIO-controles)
- **NEN 7510** (informatiebeveiliging zorgsector, voor GGD-domein)
- **NORA** (Nederlandse Overheid Referentie Architectuur)
- **AVG / UAVG** (privacy-overlap met BIO-bijlage)
- **Logius DigiD-normen**, **SUWInet-normenkader**, **Wet BAG**, **Wet BRP**

## Cross-app

- **openregister bio-nen7510**: bron-vragenset voor BIO-controles (gedeeld register)
- **docudesk**: opslag bewijsstukken + beleidsdocumenten waarnaar bevindingen verwijzen
- **opencatalogi**: VNG-vragensets als externe katalogus
- **planix**: mitigatie-acties als planix-tasks (cross-link naar verbeter-projecten)
- **launchpad**: voortgangs-dashboard ENSIA-cyclus, top-5-tekortkomingen-widget
- **decidesk**: collegebesluit-workflow voor de uiteindelijke verklaring

## Target users

- **CISO / Functionaris Informatiebeveiliging** (proceseigenaar): cyclus-orkestratie, toewijzingen
- **Teamleider infrastructuur / applicatiebeheerder**: beantwoorder van technische vragen
- **Privacy Officer / FG**: beantwoorder AVG-overlap-vragen
- **Concerncontroller**: peer-reviewer financiele controles
- **Gemeentesecretaris**: indiener bij college
- **College van B&W**: ondertekenaar collegeverklaring
- **Externe IT-auditor** (PwC/KPMG/EY/BDO): read-only toegang voor audit-fase

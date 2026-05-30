status: draft

# Bookkeeping TenderNed Integratie

## Purpose

Automatische integratie tussen TenderNed (het centrale Nederlandse aanbestedingsplatform) en de Shillinq verplichtingenadministratie. Wanneer een organisatie een aanbesteding wint op TenderNed, moet de financiele administratie deze opdracht direct kunnen verwerken als verplichting (commitment), zodat budget-uitputting realtime zichtbaar is en cashflow-prognoses kloppen. De huidige workflow is volledig handmatig: een inkoper of contractmanager exporteert PDF's vanuit TenderNed, mailt deze naar finance, en finance voert de gegevens opnieuw in - met alle bijbehorende risico's op typefouten, dubbele invoer en vertraging tussen gunning en boekhoudkundige verwerking.

Deze spec levert een tweerichtingsintegratie: import van gewonnen aanbestedingen (gunning, contractwaarde, looptijd, leverancier) als shillinq-verplichtingen, plus continue statussynchronisatie tijdens de opdrachtuitvoering (oplevering-milestones, deelfacturen, eind-oplevering). De integratie ondersteunt zowel de aanbestedende dienst-rol (overheid die aanbesteedt) als de inschrijvende leverancier-rol (organisatie die wint), met aparte workflows voor beide perspectieven. ENSIA-, BIO- en SUWI-compliance-eisen rond audit-trail en herleidbaarheid van uitgaven worden geborgd door volledige link-terug naar het oorspronkelijke TenderNed-dossier.

Doelpubliek: gemeentelijke financien-afdelingen, provincies, waterschappen, ZBO's, en MKB-leveranciers aan de Nederlandse overheid.

## Data Model

**TenderNedAanbesteding** (nieuw schema, register `shillinq`):
- `aanbestedingId` (string, TenderNed-identifier, uniek)
- `tenderNedUrl` (string, deeplink naar dossier)
- `titel`, `beschrijving`, `cpvCodes` (array van CPV-classificaties)
- `aanbestedendeDienst` (string, KvK-koppeling)
- `gunningsDatum` (date)
- `contractWaarde` (decimal, exclusief BTW)
- `looptijdStart`, `looptijdEind` (date)
- `gegundeLeverancier` (string, KvK + naam)
- `status` (enum: aangekondigd / open / gesloten / gegund / in-uitvoering / afgerond / beeindigd)
- `verplichtingId` (relatie naar shillinq Verplichting)

**Verplichting** (uitbreiding bestaand schema):
- `bron` (enum, toegevoegd: `tenderned`)
- `bronReferentie` (string, TenderNed-aanbestedingId)
- `mijlpalen` (array van milestone-objecten: datum, omschrijving, percentage, status, factuurnummer)

**OpdrachtUitvoering** (nieuw schema):
- `verplichtingId` (relatie)
- `mijlpaalId` (string)
- `opleveringsDatum` (date)
- `opleveringsType` (enum: deeloplevering / eindoplevering / tussentijdse-rapportage)
- `goedgekeurd` (boolean)
- `goedkeurder` (string, user)
- `bewijsstukken` (array van file-references)

## Requirements

### REQ-001: TenderNed dossier importeren

GIVEN een gebruiker met rol contractmanager beschikt over een TenderNed-aanbestedingId
WHEN de gebruiker via "Importeer TenderNed-dossier" het id invoert en bevestigt
THEN haalt de connector via de openconnector TenderNed-source de dossiergegevens op, maakt een TenderNedAanbesteding-object aan, en linkt deze als concept-verplichting met status `concept` totdat de gebruiker de financiele kenmerken (kostenplaats, grootboekrekening) heeft aangevuld.

### REQ-002: Automatische gunning-trigger

GIVEN een TenderNedAanbesteding-object met status `open` waar de organisatie inschrijver is
WHEN de TenderNed-polling-job detecteert dat de status naar `gegund` is gewijzigd en de winnende leverancier-KvK matcht met de organisatie
THEN converteert het systeem automatisch het concept naar een actieve verplichting, stuurt een notification naar de contractmanager, en zet de status van de aanbesteding op `in-uitvoering`.

### REQ-003: Mijlpaal-planning genereren

GIVEN een TenderNedAanbesteding met `looptijdStart` en `looptijdEind`
WHEN de verplichting wordt geactiveerd en het opdrachttype `levering-in-fases` of `dienstverlening-doorlopend` is
THEN genereert het systeem een initiele mijlpalen-planning gebaseerd op het opdrachttype (bijv. kwartaal-deelfacturen voor doorlopende dienstverlening), die de contractmanager kan accepteren of aanpassen.

### REQ-004: Bewijsstuk-koppeling per mijlpaal

GIVEN een actieve opdracht met mijlpalen
WHEN een gebruiker een mijlpaal als opgeleverd markeert
THEN verplicht het systeem het uploaden van minimaal een bewijsstuk (oplevering-document, acceptatie-protocol, of e-mail goedkeuring), koppelt dit als OR file-attachment aan het OpdrachtUitvoering-object, en blokkeert facturatie tot de mijlpaal-goedkeuring is voltooid.

### REQ-005: ENSIA-audit-trail

GIVEN een mutatie op een TenderNed-verplichting of OpdrachtUitvoering
WHEN de mutatie wordt opgeslagen
THEN registreert het systeem in de auditlog: tijdstip, gebruiker, oude waarde, nieuwe waarde, en de TenderNed-dossierreferentie - zodat een ENSIA-evaluator de volledige keten van aanbesteding tot eindfactuur kan reconstrueren.

### REQ-006: Status-sync terug naar TenderNed

GIVEN een aanbestedende dienst die zelf de aanbesteding heeft uitgeschreven
WHEN een mijlpaal de status `afgerond` krijgt en `opleveringsType` is `eindoplevering`
THEN stuurt het systeem via de TenderNed-API een status-update zodat het publieke aanbestedingsdossier de afronding registreert (verplicht voor transparantie-eisen artikel 2.135 Aanbestedingswet).

### REQ-007: Budget-impact realtime

GIVEN een gebruiker bekijkt een kostenplaats- of programma-dashboard
WHEN een TenderNed-aanbesteding gegund wordt en als verplichting wordt vastgelegd
THEN updates het budget-uitputting-widget binnen 60 seconden, en toont een drill-through-link naar het TenderNed-dossier voor verantwoordingsdoeleinden.

### REQ-008: Leverancier-perspectief omzet-prognose

GIVEN een MKB-leverancier-tenant met rol salesmanager
WHEN gewonnen TenderNed-opdrachten worden geimporteerd
THEN populeert het systeem de omzet-prognose-pijplijn met de contractwaarde verdeeld over de mijlpalen, zodat de leverancier cashflow-planning kan maken gebaseerd op verwachte facturatie-momenten.

## Standards

- **Aanbestedingswet 2012** (artikel 2.135 - publicatieplicht gunningsbeslissing)
- **TenderNed-API specificatie** (Logius, REST/JSON)
- **CPV 2008** (Common Procurement Vocabulary)
- **eForms-NL** (EU-standaard voor aanbestedingsnotices, verplicht vanaf 25 okt 2023)
- **NEN 2748** (termen voor facilities-aanbestedingen)
- **ENSIA / BIO** (audit-trail eisen voor financien-processen overheid)
- **NLCS / NLCIUS** (eFacturatie-standaard, links naar mijlpaal-facturen)

## Cross-app

- **openconnector**: TenderNed-source-definitie + polling-job (5 min interval voor lopende dossiers)
- **openregister**: verplichting/mijlpalen-schema, audit-log, file-attachments
- **opencatalogi**: TenderNed als externe katalogus opvoeren zodat aanbestedingen browsable zijn naast eigen registers
- **docudesk**: contract-templates voor nadere-overeenkomsten onder raamcontracten
- **launchpad**: budget-uitputting-widget, top-leveranciers-rapport

## Target users

- **Contractmanager** (gemeente/provincie): import-init, mijlpaal-planning, oplevering-goedkeuring
- **Finance-medewerker** (overheid): verplichting-verrijking, budgetcontrole, factuurmatching
- **Inkoper** (aanbestedende dienst): TenderNed-publicatie + status-sync
- **Salesmanager** (MKB-leverancier): omzet-prognose, gewonnen-aanbestedingen-overzicht
- **Concerncontroller**: cross-domein rapportages, ENSIA-evidence
- **Auditor** (extern): audit-trail-toegang via read-only rol

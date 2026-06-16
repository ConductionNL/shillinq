---
status: draft
---
# bookkeeping-programmabegroting — Programmabegroting & Meerjarenraming

## Purpose

Implement the BBV-verplichte programmabegroting and the four-year meerjarenraming as a first-class, machine-readable artefact inside shillinq. The programmabegroting is the central beleidsdocument and financial authorisation that the gemeenteraad / provinciale staten / algemeen bestuur adopts for the upcoming begrotingsjaar; it is simultaneously a strategic plan, a legal authorisation to spend, and a public accountability instrument. Under BBV (Besluit begroting en verantwoording provincies en gemeenten) the begroting must be structured around programma's chosen by the local political body, supplemented by the BBV-verplichte taakveldenindeling for vergelijkbaarheid, and accompanied by seven verplichte paragrafen, a meerjarenraming, and a financiële beschouwing that demonstrates a sluitend evenwicht.

This capability operationalises the entire begrotingsproces — opstellen, behandelen, vaststellen, wijzigen — and produces both the political document (programmabegroting voor de raad) and the technical artefact (iv3-aanlevering aan CBS, EMU-saldo voor Wet Hof). It enforces the structural rules that the Commissie BBV publishes (notities), implements the begrotingswijziging-workflow with verplichte raadsbesluit, and integrates upstream with bookkeeping-bbv-compliance (taakvelden, classificaties) and bookkeeping-budget-forecast (cijfers, projecties).

The capability also enforces the sluitend criterium: the meerjarenraming must demonstrate structureel and reëel evenwicht over the four-year horizon, where structureel means recurring lasten covered by recurring baten, and reëel means cijfers corrected for nominale ontwikkeling. The provinciale financieel toezichthouder uses precisely this criterium to decide between repressief and preventief toezicht; preventief toezicht means every begrotingsbesluit needs prior approval and is the strongest signal of financial distress short of artikel 12.

## Data Model

Primary register: `bookkeeping-programmabegroting`. Schemas:

- **Programmabegroting** — `version`, `organisationId`, `organisationType`, `begrotingsjaar`, `meerjarenHorizon` (default 4), `status` (draft/in-behandeling/vastgesteld/superseded), `vaststellingsBesluit` (FK to raadsbesluit), `vaststellingsDatum`, `sluitendStructureel` (boolean), `sluitendReëel` (boolean), `toezichtRegime` (repressief/preventief/artikel-12).
- **Programma** — `begroting` (FK), `nummer`, `naam`, `portefeuillehouder`, `doelstellingen` (rich-text narrative), `indicatoren` (children: KPI per programma per jaar), `batenTotaal`, `lastenTotaal`, `saldoVoorMutaties`, `mutatiesReserves`, `saldoNaMutaties`. Locally chosen; not BBV-vastgesteld.
- **Indicator** — `programma` (FK), `code`, `omschrijving`, `eenheid`, `nulwaarde`, `streefwaarde`, `realisatie`, `bron` (link to register/dataset). Includes the verplichte beleidsindicatoren uit BBV Article 25.
- **Taakveld** — `programma` (FK), `taakveldCode` (BBV-lijst, e.g. 0.1, 6.71), `taakveldNaam`, `baten`, `lasten`. The BBV-verplichte indeling that overlay the locally-chosen programma's; one programma may contain multiple taakvelden and a taakveld may not span programma's.
- **Investering** — `programma` (FK), `omschrijving`, `bruto`, `dekking` (eigen-middelen/lening/bijdragen-derden/subsidie), `afschrijvingstermijn`, `eersteAfschrijvingsjaar`, `kapitaallastenSchedule` (per-year).
- **Reserve** — `begroting` (FK), `type` (algemene-reserve/bestemmingsreserve), `naam`, `beginsaldo`, `toevoegingen`, `onttrekkingen`, `eindsaldo`, `bestemmingsdoel`.
- **Voorziening** — `begroting` (FK), `naam`, `grondslag` (BBV Article 44 a/b/c/d), `beginsaldo`, `dotaties`, `vrijval`, `aanwendingen`, `eindsaldo`.
- **Paragraaf** — `begroting` (FK), `type` (lokaleHeffingen/weerstandsvermogenRisicobeheersing/onderhoudKapitaalgoederen/financiering/bedrijfsvoering/verbondenPartijen/grondbeleid), `narrative`, `kerncijfers` (children). One per type per begroting (7 verplicht).
- **Meerjarenraming** — `begroting` (FK), `jaar` (T+1..T+4), `batenStructureel`, `batenIncidenteel`, `lastenStructureel`, `lastenIncidenteel`, `saldoStructureel`, `saldoReëel`, `sluitend`.
- **Begrotingswijziging** — `begroting` (FK), `wijzigingsnummer`, `omschrijving`, `mutaties` (per-programma per-taakveld delta), `raadsbesluit` (FK), `vaststellingsDatum`, `effectiefVanaf`, `status`.

Cross-register joins: `BBVTaakveldCatalogus` (bookkeeping-bbv-compliance), `Forecast` (bookkeeping-budget-forecast), `KasgeldLimiet`/`RenteRisicoNorm` (bookkeeping-wet-fido-treasury), `Grootboekpost` (general-ledger).

## Requirements

- **REQ-001** The system SHALL allow a controller to draft a Programmabegroting for a given `(organisationId, begrotingsjaar)` and SHALL refuse a second draft when a vastgestelde begroting already exists, unless the new draft is explicitly marked as a begrotingswijziging.
- **REQ-002** The system SHALL enforce that every Programma sums its Taakveld children's baten and lasten consistently, that taakvelden conform to the BBV-taakveldcatalogus current for the begrotingsjaar, and that no taakveld is split across multiple programma's within one begroting.
- **REQ-003** The system SHALL compute the sluitend-criterium on the Meerjarenraming as: `sluitendStructureel = (lastenStructureel ≤ batenStructureel)` for every jaar in T+1..T+4, and `sluitendReëel = (saldoReëel ≥ 0)` after applying nominale-ontwikkeling correctie, and SHALL set the begroting's two sluitend-flags accordingly.
- **REQ-004** The system SHALL maintain the seven BBV-verplichte paragrafen — lokale heffingen, weerstandsvermogen & risicobeheersing, onderhoud kapitaalgoederen, financiering, bedrijfsvoering, verbonden partijen, grondbeleid — and SHALL refuse to transition a begroting to `vastgesteld` if any paragraaf is missing or has empty narrative.
- **REQ-005** The system SHALL require a vaststellingsBesluit (raadsbesluit / statenbesluit / AB-besluit) link before transitioning a begroting from `in-behandeling` to `vastgesteld`, capturing besluitnummer, datum, and stemming-uitslag for audit traceability.
- **REQ-006** The system SHALL implement the Begrotingswijziging-workflow such that no mutaties on a vastgestelde begroting may take effect without their own raadsbesluit; the system SHALL refuse grootboekposten that exceed the authorised lasten per programma until the relevant wijziging is vastgesteld.
- **REQ-007** The system SHALL generate the iv3-aanlevering (information voor derden) as the verplichte CBS-export, aggregating baten and lasten per taakveld and per economische categorie, and SHALL submit it via the CBS-portaal at the statutory cadence (kwartaal voor realisatie, jaar voor begroting).
- **REQ-008** The system SHALL compute the EMU-saldo from the begroting using the Wet Hof definitions and SHALL produce the verplichte EMU-rapportage, distinguishing exploitatie- en investerings-componenten and applying the macro-economische normen (referentiewaarde per organisatie).
- **REQ-009** The system SHALL determine and persist the toezichtRegime by combining the sluitend-flags (structureel and reëel) with the verleden 4 jaar resultaat, mirroring the provinciale toezichthouder's beoordelingskader, and SHALL emit an event when the regime would shift from repressief to preventief.
- **REQ-010** The system SHALL publish the Programmabegroting in both raadsleesbare PDF/A vorm (rich layout, programma's first) and machine-leesbare JSON vorm (taakvelden first, OpenRegister-export), and SHALL expose the JSON on OpenCatalogi for hergebruik door derden.

### Behaviour examples

**GIVEN** a gemeente drafts Programmabegroting 2027 with €5M tekort op structurele lasten in T+1, dalend naar €0 in T+4 **WHEN** the controller requests the sluitend-beoordeling **THEN** the system marks sluitendStructureel=false for T+1, true for T+4, sets the overall begroting to sluitendStructureel=false, and projects a likely preventief toezichtregime that triggers a warning before vaststelling.

**GIVEN** a vastgestelde begroting 2027 with €12M autorisatie op programma Sociaal Domein **WHEN** a grootboekpost van €13M wordt geboekt op een taakveld binnen Sociaal Domein **THEN** the system refuses the boeking with a validation error citing budgetoverschrijding zonder begrotingswijziging, and lists the openstaande wijziging-drafts indien aanwezig.

**GIVEN** een waterschap stelt de programmabegroting vast met paragraaf grondbeleid leeg **WHEN** de DB de status op vastgesteld zet **THEN** the system refuses with a validation error pointing to BBV Article 26 lid f en biedt een leeg-template voor de paragraaf grondbeleid.

## Standards & Sources

- **Besluit begroting en verantwoording provincies en gemeenten** (BBV), Stb. 2003/27 with amendments through Stb. 2024/198
- **Regeling vaststelling iv3-informatievoorschrift** (Min BZK)
- **Wet houdbare overheidsfinanciën (Wet Hof)**, Stb. 2013/530
- **Notitie Programma's en programmabegroting** (Commissie BBV)
- **Notitie Taakvelden** (Commissie BBV, latest 2024 update)
- **Notitie Materiële vaste activa** (Commissie BBV)
- **Notitie Reserves en voorzieningen** (Commissie BBV)
- **Notitie Weerstandsvermogen en risicobeheersing** (Commissie BBV)
- **Notitie Verbonden partijen** (Commissie BBV)
- **Notitie Grondbeleid** (Commissie BBV)
- **Gemeentewet Article 189–195**, **Provinciewet Article 195–201**, **Waterschapswet Article 99–106**
- **Beoordelingskader financieel toezicht** (IPO, voor gemeenten; BZK voor provincies/waterschappen)
- **Iv3-handleiding CBS**, latest editie

## Cross-app integration

- **Depends on** `bookkeeping-bbv-compliance` — supplies the BBV-taakveldcatalogus en taakveld-classificatieregels.
- **Depends on** `bookkeeping-budget-forecast` — supplies forecast-projecties die de meerjarenraming voeden.
- **Feeds** `bookkeeping-wet-fido-treasury` — vastgestelde begroting is de basis voor kasgeldlimiet en rente-risiconorm.
- **Feeds** `bookkeeping-bado-controleprotocol` — materialiteit-grondslag voor de auditor.
- **Feeds** `bookkeeping-sisa-reporting` — programma-structuur cross-walked naar SiSa-regelingen.
- **Feeds** `bookkeeping-jaarrekening-publication` — realisatiecijfers worden tegen deze begroting afgezet.
- **OpenConnector events** — `begroting.draft.created`, `begroting.vastgesteld`, `begroting.wijziging.vastgesteld`, `begroting.toezicht.regime-shift`, `begroting.iv3.submitted`.
- **OpenCatalogi** — machine-leesbare programmabegroting JSON gepubliceerd voor hergebruik (open data).

## Target users

- **Concerncontroller / financieel directeur** — primary author, drives concept-begroting en de meerjarenraming.
- **Programmamanager / portefeuillehouder** — owns doelstellingen, indicatoren, en de narratieve programma-tekst.
- **College van B&W / GS / DB** — biedt de begroting aan aan raad/staten/AB.
- **Raad/Staten/AB** — stelt de begroting en wijzigingen vast.
- **Griffier / statengriffier / secretaris** — registreert de besluiten en koppelt ze aan de begrotingsversie.
- **Financieel toezichthouder (provincie voor gemeente; BZK voor provincie/waterschap)** — beoordeelt sluitendheid en bepaalt repressief vs preventief regime.
- **CBS** — ontvangt iv3-aanlevering.
- **Min Fin / DNB** — gebruikt EMU-saldo-aggregaat voor macro-economisch toezicht.
- **Burger / onderzoeksjournalist** — leest de gepubliceerde programmabegroting via OpenCatalogi en gebruikt de iv3-aanlevering voor vergelijkingen tussen organisaties.
- **Onderzoekers / waarstaatjegemeente / openspending** — gebruikt de machine-leesbare JSON-export voor benchmarking en wetenschappelijk onderzoek.

## Implementation notes

The programmabegroting capability deliberately decouples the two views op de begroting — programma-georienteerd (politiek, lokaal) versus taakveld-georienteerd (BBV-verplicht, vergelijkbaar) — by modelling beide als parallelle views over dezelfde grondgegevens. De Taakveld-children van een Programma zijn de canonical brondata; de programma-aggregatie wordt automatisch berekend uit de taakveld-sums. Dit voorkomt afrondingsverschillen tussen de politieke en de technische views en garandeert dat de iv3-aanlevering exact aansluit met wat de raad heeft vastgesteld.

De begrotingswijziging-workflow gebruikt event-sourcing: elke wijziging is een onafhankelijk delta-document met eigen raadsbesluit, en de huidige stand van de begroting is altijd `vastgestelde basis + Σ(vastgestelde wijzigingen)`. Hierdoor blijft de audit-trail intact zelfs wanneer wijzigingen worden teruggedraaid (terugdraaiing is zelf weer een wijziging met negatief delta). Voor de iv3-aanlevering wordt de stand op een specifieke peildatum opgevraagd, niet de actuele stand, zodat de aanlevering reproduceerbaar blijft.

De sluitend-beoordeling onderscheidt structureel en reëel evenwicht omdat de provinciale toezichthouder beide afzonderlijk weegt. Structureel evenwicht test of terugkerende lasten worden gedekt door terugkerende baten; reëel evenwicht corrigeert voor nominale ontwikkeling (loon- en prijsindexatie) zodat een nominaal sluitende begroting met onrealistische rente-aannames niet vals positief is. De beide flags worden onafhankelijk gepersisteerd zodat de raad ziet welke specifieke voorwaarde knelt.

De zeven verplichte paragrafen worden niet als losse documenten beheerd maar als structured records met verplichte velden (kerncijfers) en optionele narrative. Voor weerstandsvermogen worden ratio's automatisch berekend uit het risicoregister; voor financiering wordt de paragraaf gevoed door de bookkeeping-wet-fido-treasury data; voor verbonden partijen wordt de lijst onderhouden in een aparte registratie en in de paragraaf alleen referenties opgenomen. Dit voorkomt drift tussen paragraaf-tekst en operationele werkelijkheid.

De BBV-verplichte beleidsindicatoren ex Article 25 BBV worden geïmporteerd uit de centrale catalogus van waarstaatjegemeente; per indicator wordt het bron-dataset gekoppeld zodat de realisatiecijfers actueel blijven. De lokaal-gekozen indicatoren worden door de programmamanager onderhouden met expliciete bron-aanwijzing — geen lokale indicator zonder bron-koppeling, om mockwaarden te voorkomen.

De iv3-aanlevering aan CBS gebeurt via een dedicated OpenConnector-source met het iv3-koppelvlak. Voor de begroting-aanlevering wordt de vastgestelde begroting eenmaal per jaar verzonden; voor de realisatie wordt elk kwartaal de stand op de peildatum verzonden. De aanlevering bevat alleen baten/lasten per taakveld per economische categorie; de programma-structuur is intern aan de gemeente en wordt niet vergelijkbaar gemaakt. De system valideert het iv3-bericht tegen de XSD-schema's van CBS voordat verzenden zodat afwijzingen door CBS minimaal worden.

De EMU-saldo-berekening volgt de definities van Wet Hof / SNA-2010: het verschil tussen baten en lasten in een gegeven jaar, met specifieke correcties voor investeringen (geactiveerd in plaats van direct ten laste van het saldo) en voor mutaties in voorzieningen en reserves. De referentiewaarde per organisatie wordt jaarlijks door BZK bekend gemaakt en geïmporteerd; overschrijdingen leiden tot een macro-economisch signaal maar niet tot directe sancties (de Wet Hof werkt collectief via de medeoverhedennorm).

De toezicht-regime-bepaling combineert vier signalen: structureel sluitend in de meerjarenraming, reëel sluitend, resultaat van de afgelopen 4 jaar (geen herhaalde tekorten zonder dekkingsplan), en de risico-positie van de algemene reserve (weerstandsratio ≥ 1.0). Slechts bij volledige conformiteit op alle vier vlakken kan repressief toezicht worden behouden; bij twijfel emit het systeem een vooraankondigings-event voor de toezichthouder zodat deze proactief overleg kan voeren met de gemeente vóór het formele besluit valt.

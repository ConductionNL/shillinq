---
status: draft
---
# bookkeeping-vpb-mkb — Vpb-aangifte BV/NV (Regulier)

## Purpose

Implement the reguliere Vennootschapsbelasting (Vpb) aangifte workflow for private-sector BV's en NV's that are subject to standard corporate income tax — distinct from the Vpb-plicht voor overheidsondernemingen (covered by a separate capability). This capability targets MKB-companies and their accountants/fiscalisten: it walks the user through the annual cycle of voorlopige aanslag, definitieve aangifte, definitieve aanslag, en de bezwaar/beroep-workflow when the inspecteur and the taxpayer disagree.

The Vpb-aangifte must be submitted via SBR (Standard Business Reporting) using the XBRL-taxonomie van de Nederlandse Taxonomie Project (NTP), filed through Digipoort. shillinq generates the SBR-XBRL-instance from the verzamelde grootboek- en jaarrekening-data, applies the fiscale correcties (commerciële winst → fiscale winst), claims de toepasselijke faciliteiten (innovatiebox, deelnemingsvrijstelling, KIA/EIA/MIA, fiscale eenheid, voorvoegingsverliezen), berekent de verschuldigde Vpb tegen de schijftarieven, en stuurt de aangifte in. The aangifte is wettelijk binnen 5 maanden na boekjaar in (verlenging mogelijk tot 11 maanden); de inspecteur reageert met een aanslag waarop bezwaar mogelijk is.

The capability is explicitly NOT a tax-advisory tool: het rekent de aangifte uit op basis van expliciete keuzes van de gebruiker (of diens fiscalist) en documenteert die keuzes voor latere controle. De integratie met bookkeeping-financial-statements zorgt dat de commerciële winst die op de jaarrekening staat exact aansluit met de aangifte; daarna voert deze module de fiscale correcties uit en produceert het XBRL-bericht.

## Data Model

Primary register: `bookkeeping-vpb`. Schemas:

- **Belastingplichtige** — `rsin`, `kvkNummer`, `rechtsvorm` (BV/NV/coop/onderlinge), `boekjaarStart`, `boekjaarEind`, `fiscaalAdviseur` (FK), `digipoortCertificaat` (FK to credential vault), `eHerkenningsNiveau` (verplicht EH3 voor Belastingdienst-aangifte).
- **VpbAangifte** — `belastingplichtige` (FK), `belastingjaar`, `boekjaarVan`, `boekjaarTot`, `status` (concept/ingediend/aanslag-ontvangen/bezwaar/beroep/onherroepelijk), `commerciëleWinst` (FK to FinancialStatements), `fiscaleCorrecties` (children), `fiscaleWinstVoorVerliezen`, `voorvoegingsverliezen` (toegepast), `fiscaleWinstBelastbaar`, `verschuldigdeVpb`, `voorheffingen` (dividend-bel, buitenlandse bronbel), `teBetalen`, `ingediendOp`, `digipoortReceiptId`.
- **FiscaleCorrectie** — `aangifte` (FK), `code` (NTP-classificatie, e.g. afschrijvingsbeperking-art-3-30a, niet-aftrekbare-kosten-art-3-14, deelnemingsvrijstelling-art-13), `omschrijving`, `commercieelBedrag`, `fiscaalBedrag`, `correctieBedrag` (delta), `toelichting`.
- **Innovatiebox** — `aangifte` (FK), `kwalificerendeActiva` (children), `forfaitaireBenadering` (€25k drempel of werkelijke-winst-methode), `nexusFactor` (R&D-eigen / R&D-totaal), `innovatieboxWinst`, `effectiefTarief` (9% i.p.v. 25.8%).
- **Deelneming** — `belastingplichtige` (FK), `naam`, `rsin`, `aandeelhouderschap%`, `kwalificerendeDeelneming` (>=5%), `deelnemingsvrijstellingVanToepassing` (boolean met motivatie), `dividenden`, `vervreemdingsResultaat`.
- **FiscaleEenheid** — `moedermaatschappij` (FK Belastingplichtige), `dochters` (children), `voegingsdatum`, `ontvoegingsdatum`, `voorvoegingsverliezenPerDochter` (separately tracked).
- **Voorvoegingsverlies** — `belastingplichtige` (FK), `verliesjaar`, `oorspronkelijkBedrag`, `reedsVerrekend`, `restant`, `verjaartIn` (9 jaar voorwaarts vanaf 2019-verliezen, onbeperkt vanaf 2022-verliezen onder 50%-regel), `beschikking` (FK to inspecteur-beschikking).
- **InvesteringsAftrek** — `aangifte` (FK), `type` (KIA/EIA/MIA/Vamil), `investering` (FK), `investeringsbedrag`, `aftrekPercentage`, `aftrekBedrag`, `cumulatieGecheckt`.
- **VoorlopigeAanslag** — `belastingplichtige` (FK), `belastingjaar`, `aanslagnummer`, `dagtekening`, `geschatBelastbaarBedrag`, `voorlopigVerschuldigd`, `betalingsregeling` (maandelijks/eenmalig).
- **DefinitieveAanslag** — `aangifte` (FK), `aanslagnummer`, `dagtekening`, `vastgesteldBelastbaarBedrag`, `vastgesteldVerschuldigd`, `verrekendeVoorheffingen`, `verrekendeVoorlopigeAanslagen`, `tePunten`, `bezwaartermijn` (6 weken).
- **BezwaarBeroep** — `aanslag` (FK), `type` (bezwaar/beroep/hoger-beroep/cassatie), `ingediendOp`, `motivering`, `ontvangstbevestiging`, `uitspraak`, `uitspraakDatum`, `vervolgInstantie`, `processtukken` (children).

Cross-register joins: `JaarrekeningRecord` (bookkeeping-financial-statements), `Grootboekpost` (general-ledger), `SBRBericht` (bookkeeping-sbr-xbrl-reporting).

## Requirements

- **REQ-001** The system SHALL allow exactly one VpbAangifte per `(belastingplichtige, belastingjaar)` and SHALL refuse creation of a new aangifte until an existing one is `onherroepelijk` or expressly heropend, in line with the wettelijke 5-jaars heropeningstermijn.
- **REQ-002** The system SHALL bind the commerciëleWinst of an aangifte to a specific, vastgestelde jaarrekening-version, and SHALL refuse to transition the aangifte to `ingediend` if the linked jaarrekening is not vastgesteld door AvA.
- **REQ-003** The system SHALL apply the schijftarieven van het belastingjaar — for 2026: 19% over de eerste €245.000 belastbaar bedrag, 25.8% over het meerdere — and SHALL parameterise these tarieven by year so wetswijzigingen retroactief reproduceerbaar blijven.
- **REQ-004** The system SHALL implement de innovatiebox-berekening volgens Wet Vpb Article 12b/12bd: zowel de forfaitaire benadering (toepasbaar tot €25.000 voordeel, beperkt tot eerste 3 jaar) als de werkelijke-winst-methode met nexus-factor, en SHALL bind elke claim aan een S&O-verklaring van RVO.
- **REQ-005** The system SHALL implement de deelnemingsvrijstelling van Article 13 Wet Vpb: voordelen uit kwalificerende deelnemingen (≥5% nominaal gestort kapitaal in beginsel) worden uit het belastbaar bedrag gehaald, mits geen low-taxed-portfolio-investment, en SHALL motivate elke claim met de drie cumulatieve toetsen (oogmerktoets, onderworpenheidstoets, bezittingentoets).
- **REQ-006** The system SHALL implement voorvoegingsverliezenverrekening volgens het regime van het verliesjaar: voor verliezen tot en met 2018 — voorwaarts 9 jaar; voor verliezen vanaf 2019 — voorwaarts 6 jaar; voor verliezen vanaf 2022 — onbeperkt voorwaarts met de 50%-beperking boven €1M belastbare winst.
- **REQ-007** The system SHALL handle fiscale eenheid mutaties (voegingen, ontvoegingen, sanctiebepalingen Article 15ai) by maintaining per-dochter voorvoegingsverliezen-administratie, en SHALL refuse a voeging die de voorwaarden van Article 15 niet vervult (≥95% bezit, gelijke boekjaren, vestigingsplaats NL).
- **REQ-008** The system SHALL cumulate KIA, EIA en MIA correct: een investering kan KIA en EIA combineren, KIA en MIA combineren, maar EIA en MIA zijn niet stapelbaar op dezelfde investering; the system SHALL enforce deze regels en de geldende minima/maxima per belastingjaar.
- **REQ-009** The system SHALL generate the SBR-XBRL-instance volgens de Nederlandse Taxonomie van het toepasselijk jaar, sign it with the Belastingplichtige's eHerkenningsNiveau-EH3 plus PKIO-Digipoort-certificate, submit via Digipoort, en persist de Digipoort receipt; resubmission SHALL be supported voor bezwaar-aanvullingen.
- **REQ-010** The system SHALL implement the bezwaar/beroep-workflow met statutaire termijnen: bezwaar binnen 6 weken na aanslag; uitspraak inspecteur binnen 6 weken (te verlengen tot 12); beroep bij Rechtbank binnen 6 weken na uitspraak; hoger beroep bij Hof; cassatie bij Hoge Raad; en SHALL bewaken de termijnen met escalatie-alerts.

### Behaviour examples

**GIVEN** een BV met commerciële winst 2026 van €420.000 en €30.000 aan niet-aftrekbare kosten (Article 3.14) **WHEN** the controller draft de aangifte **THEN** the system zet de fiscale correctie van +€30.000, berekent fiscale winst van €450.000, past voorvoegingsverliezen toe indien beschikbaar, en berekent Vpb als 19% × €245.000 + 25.8% × €205.000 = €46.550 + €52.890 = €99.440.

**GIVEN** een BV claimt innovatiebox via de werkelijke-winst-methode op €800.000 innovatieboxwinst met nexus-factor 0.85 **WHEN** the controller indient de aangifte **THEN** the system past de innovatiebox toe op €800.000 × 0.85 = €680.000 tegen het effectieve 9%-tarief, en op de resterende €120.000 het reguliere staffeltarief, en koppelt een verplichte S&O-verklaring-referentie.

**GIVEN** een definitieve aanslag is ontvangen met een hogere belastbare winst dan aangegeven door niet-erkende afschrijving op een bedrijfspand **WHEN** the fiscalist registreert een bezwaar binnen 6 weken **THEN** the system creëert een BezwaarBeroep-record, start de 6-weken-uitspraaktermijn-counter voor de inspecteur, en blokkeert de invorderbaarheid van het betwiste deel conform Article 25 IW indien uitstel-betaling is aangevraagd.

## Standards & Sources

- **Wet op de vennootschapsbelasting 1969** (Wet Vpb), Stb. 1969/445 met laatste wijzigingen Stb. 2025/512 (Belastingplan 2026)
- **Uitvoeringsregeling vennootschapsbelasting 1971**
- **Algemene wet inzake rijksbelastingen (AWR)**, Stb. 1959/301
- **Invorderingswet 1990 (IW)**
- **Algemene wet bestuursrecht (Awb)** — bezwaar/beroep-procedure
- **Nederlandse Taxonomie (NT)**, jaarlijkse uitgave door SBR Programma — XBRL-taxonomie voor Vpb-aangifte
- **Digipoort-koppelvlakspecificatie** (Logius)
- **Besluiten van de Staatssecretaris van Financiën** over innovatiebox, deelnemingsvrijstelling, fiscale eenheid (talrijke beleidsbesluiten, geconsolideerd op rijksoverheid.nl)
- **Wet bevordering speur- en ontwikkelingswerk (WBSO)** — voor S&O-verklaringen die innovatiebox onderbouwen
- **Wet op de inkomstenbelasting 2001 (IB)** — Article 3.40+ over investeringsaftrek (KIA/EIA/MIA), van overeenkomstige toepassing op Vpb via Article 8 Wet Vpb
- **eHerkenning-niveau EH3** (Logius) — verplicht authenticatieniveau voor Vpb-aangifte
- **Reglement bezwaarschriftenprocedure Belastingdienst**

## Cross-app integration

- **Depends on** `bookkeeping-sbr-xbrl-reporting` — supplies SBR-instance-generation, Digipoort-koppelvlak, NT-taxonomie.
- **Depends on** `bookkeeping-financial-statements` — supplies vastgestelde jaarrekening-cijfers die de commerciële winst leveren.
- **Depends on** `bookkeeping-general-ledger` — supplies grootboekposten waarop fiscale correcties zich afspelen.
- **Feeds** `bookkeeping-tax-calendar` — submission-deadlines voor aangifte, voorlopige-aanslag-termijnen, bezwaar-termijnen.
- **Feeds** `bookkeeping-cashflow-forecast` — projecteert te-betalen-Vpb in liquiditeitsplanning.
- **OpenConnector events** — `vpb.aangifte.concept`, `vpb.aangifte.ingediend`, `vpb.aanslag.ontvangen`, `vpb.bezwaar.ingediend`, `vpb.beroep.uitspraak`, `vpb.termijn.verstrijkt-binnenkort`.
- **Belastingdienst Digipoort** — uitwisseling van XBRL-aangifte en aanslagberichten.
- **RVO** — koppelvlak voor S&O-verklaringen die innovatiebox-claims onderbouwen.

## Target users

- **MKB-ondernemer / DGA** — bekijkt de aangifte, ondertekent met eHerkenning, ziet de te-betalen-Vpb in het cashflow-overzicht.
- **Fiscalist / belastingadviseur** — primary author; configureert fiscale correcties, claimt faciliteiten, voert bezwaar/beroep.
- **Boekhouder / administrateur** — voert commerciële posten op die later fiscaal gecorrigeerd worden; sluit jaarrekening aan op aangifte.
- **Externe accountant** — toetst de aansluiting tussen jaarrekening en Vpb-aangifte; controleert claim-onderbouwing.
- **Belastingdienst (inspecteur)** — counterparty; ontvangt aangifte, stuurt aanslag, behandelt bezwaar.
- **Rechtbank / Gerechtshof / Hoge Raad** — uitspraakinstanties in de beroepsketen.
- **RVO** — verstrekt S&O-verklaringen die als bijlage bij innovatiebox-claims dienen, en beoordeelt EIA/MIA/Vamil-meldingen die in de aangifte landen.
- **Kamer van Koophandel** — leverancier van de RSIN/KvK-koppeling en publiceert de jaarrekening die de commerciële winst onderbouwt.
- **Notaris / juridisch adviseur** — betrokken bij fiscale-eenheid-voegingen en ontvoegingen die invloed hebben op de aangifte.

## Implementation notes

The Vpb-aangifte-capability bewust het scheidingsprincipe tussen commerciële boekhouding en fiscale aangifte handhaaft. De grootboek-administratie blijft commercieel-georienteerd (BW2 Titel 9), en alle fiscale correcties leven exclusief in de FiscaleCorrectie-records onder de VpbAangifte. Dit voorkomt dat fiscale keuzes de commerciële cijfers vervuilen, en het maakt de auditor-aansluiting tussen jaarrekening en aangifte expliciet: elke regel in de aansluiting is een gepersisteerde FiscaleCorrectie met code, motivering, en bron-grootboekpost.

De schijftarieven, drempelbedragen, en faciliteits-percentages worden geparameteriseerd per belastingjaar in een aparte VpbTariefcatalogus-tabel die jaarlijks na het Belastingplan wordt bijgewerkt. Hierdoor blijven oude aangiftes correct herrekenbaar (bijvoorbeeld bij navordering jaren later), en nieuwe wetswijzigingen kunnen worden ingevoerd zonder code-deploy.

De innovatiebox-implementatie ondersteunt zowel de forfaitaire (kleine R&D-positie tot €25k voordeel, max 3 jaar na S&O-verklaring) als de werkelijke-winst-methode met nexus-factor (R&D-uitgaven-eigen / R&D-uitgaven-totaal, gecapped op 100%). De nexus-factor wordt automatisch berekend uit de R&D-uitgaven-administratie indien aanwezig, of handmatig ingevoerd door de fiscalist met onderbouwing. Elke claim is verplicht gekoppeld aan een S&O-verklaring-referentie van RVO; zonder die referentie wordt de claim niet toegestaan in de aangifte.

De deelnemingsvrijstelling-toets vereist motivering op alle drie de cumulatieve toetsen (oogmerk, onderworpenheid, bezittingen) en bewaart die motivering als onderdeel van het aangifte-dossier. De automatische detectie van potentieel laag-belaste portfolio-investeringen flagt de toets-uitkomst maar laat de fiscalist de eindbeoordeling maken; dit is bewust geen automatische uitsluiting omdat de jurisprudentie te casuistisch is voor regelmatige automatisering.

De bezwaar/beroep-workflow gebruikt een statemachine met statutaire termijnen als harde deadlines. De system genereert kalender-events voor elke termijn en escaleert op T-7-dagen, T-3-dagen, en op-de-dag-zelf. Verstreken termijnen leiden tot een rode markering in het dossier omdat een gemiste bezwaartermijn de aanslag onherroepelijk maakt en directe financiële schade kan opleveren.

De fiscale-eenheid-administratie is bewust complex modelmatig omdat de Vpb-gevolgen van een voeging of ontvoeging meerjarig doorwerken. Voorvoegingsverliezen blijven gebonden aan de dochter waar ze zijn ontstaan en mogen alleen worden verrekend met winsten van diezelfde dochter (na voeging beoordeeld als deel van de eenheid maar boekhoudkundig gesegmenteerd). De system houdt per dochter een afzonderlijke verliesadministratie bij en past de verrekening dochter-specifiek toe. Bij ontvoeging worden de resterende verliezen "meegenomen" naar de zelfstandige dochter, mits niet verjaard.

De SBR-XBRL-instance wordt gegenereerd door een aparte SBR-engine-module die de Nederlandse Taxonomie van het toepasselijke jaar consulteert. Elk veld in de aangifte wordt gemarkeerd met het juiste NTP-element, en de instance wordt gevalideerd tegen de taxonomie-XSD vóór verzenden. De Digipoort-verzending gebruikt PKIO-Digipoort-certificaat van de Belastingplichtige (niet die van de fiscalist), wat betekent dat de Belastingplichtige zelf in het certificaatregister moet staan; voor MKB-clients zonder eigen certificaat ondersteunt het systeem de SBR-koppeling via een fiscaal-intermediair onder de Servicegerichte Architectuur van SBR.

De voorlopige-aanslag-workflow is gescheiden van de definitieve aangifte: een voorlopige aanslag wordt door de inspecteur opgelegd op basis van het verwachte belastbaar bedrag en kan op verzoek van de belastingplichtige worden bijgesteld. De system ondersteunt het herzieningsverzoek (Belastingdienst-formulier "Verzoek wijziging voorlopige aanslag Vpb") via een aparte workflow, en houdt rekening met de invloed van een herziene voorlopige aanslag op de cashflow-projectie van de belastingplichtige.

De koppeling tussen jaarrekening en aangifte gebeurt via een dedicated AansluitTabel die elke regel van de aangifte herleidt tot een grootboekrekening of jaarrekeningpost plus de toegepaste FiscaleCorrectie. Dit is de tabel die de externe accountant gebruikt voor de aansluiting-controle die onderdeel is van de Vpb-zekerheid (indien overeengekomen). Zonder volledig sluitende AansluitTabel kan de aangifte niet worden ingediend; dit voorkomt dat verschillen tussen jaarrekening en aangifte onverklaard blijven en bij latere navordering tot problemen leiden.

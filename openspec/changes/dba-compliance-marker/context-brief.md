---
status: draft
---

# DBA Compliance Marker per Opdracht

## Purpose

De Wet deregulering beoordeling arbeidsrelaties (Wet DBA) van mei 2016 is voor de Nederlandse ZZP-markt het centrale risico-instrument waarmee de Belastingdienst kan beoordelen of een opdrachtnemer feitelijk zelfstandig ondernemer is of materieel als werknemer moet worden aangemerkt — met alle fiscale en sociale-zekerheidsgevolgen van dien (alsnog loonheffing, premies werknemersverzekeringen, naheffingen, vergrijpboetes). Sinds 2025 wordt de Wet DBA actief gehandhaafd na een jarenlang handhavingsmoratorium; per 1 januari 2025 voert de Belastingdienst weer reguliere correctieverplichtingen op met naheffingen die kunnen oplopen tot tienduizenden euro's per opdracht. Voor de opdrachtgever én de ZZP'er is een gestructureerde, evidence-driven DBA-compliance-administratie daarmee in 2026 essentieel.

Deze spec beschrijft hoe shillinq DBA-compliance per opdracht (per klant, per project) operationaliseert tot een risico-score, een evidence-dossier, en een waarschuwingssysteem dat actief flags genereert op de drie kernelementen uit de "wezenskenmerken van een arbeidsovereenkomst" (Wet DBA + art. 7:610 BW): (a) gezagsverhouding (instructies, controle, integratie in organisatie), (b) verplichting tot persoonlijke arbeid (vervangbaarheid), (c) verplichting tot loonbetaling (financieel risico bij ondernemer). Aanvullend toetst het systeem op de jurisprudentie-criteria van het Deliveroo-arrest (HR 24 maart 2023, NJ 2023/188): aard van de werkzaamheden, duur van de relatie, exclusiviteit, ondernemerschap-kenmerken (eigen klanten, eigen reclame, eigen risico), aanwezigheid van een modelovereenkomst, en de feitelijke uitvoering versus contractueel intent.

Het doel is dat bij elke nieuwe opdracht in shillinq (1) een DBA-intake-vragenlijst doorlopen wordt met geautomatiseerde scoring per risico-aspect, (2) de gekozen modelovereenkomst (uit het Belastingdienst-register of een eigen variant) gekoppeld wordt aan de opdracht, (3) gedurende de looptijd geautomatiseerd flags worden gegenereerd op risicovolle signalen (factuurfrequentie identiek aan loonperiode, exclusieve relatie, multiple-engagement-met-zelfde-opdrachtgever-over-meerdere-jaren), (4) een evidence-dossier wordt opgebouwd met facturen, urenstaten, communicatie, modelovereenkomst, en (5) bij een Belastingdienstcontrole een audit-vast rapport per opdracht kan worden geproduceerd.

De spec dekt zowel het ZZP-perspectief (opdrachtnemer-zijde, focus op eigen risico-management en evidence) als het opdrachtgever-perspectief (waarschuwing bij risicovolle inhuur, register van modelovereenkomsten, bewaarplicht). Deze tweeledigheid is bewust: shillinq wordt door zowel ZZP'ers als kleine MKB-opdrachtgevers gebruikt, en de dwarsdoorsnede van de Wet DBA is dat beide partijen even hard kunnen worden geraakt door een negatieve beoordeling.

Een belangrijk onderdeel is de signalering rond de "VAR-achtige" patronen die in 2025+ als rode vlag gelden: één hoofdopdrachtgever (>70 procent omzet), vaste werktijden, structureel werken op locatie opdrachtgever, gebruik van apparatuur/email-adres opdrachtgever, en — meest belastend — de combinatie van langdurigheid (>2 jaar) met exclusiviteit en uniforme tarifering. De engine produceert hierop een rolling risico-score per opdracht én een geaggregeerd portfolio-risico voor de ZZP'er.

Bijzondere aandacht binnen de spec gaat naar de nieuwe Wet Verduidelijking Beoordeling Arbeidsrelaties en Rechtsvermoeden (VBAR), die in de Tweede Kamer ligt en — volgens de huidige kabinetsplanning — beoogd is voor inwerkingtreding 1 januari 2026. De VBAR introduceert een wettelijk rechtsvermoeden dat een opdrachtnemer met een uurtarief beneden EUR 33 (peil 2024, geïndexeerd) automatisch als werknemer wordt aangemerkt tenzij het tegendeel aannemelijk wordt gemaakt — een paradigmaverschuiving die het bewijslast omdraait. Shillinq moet bij elke factuurregel het effectieve uurtarief berekenen en bij onderschrijden van de VBAR-grens een directe waarschuwing geven, ongeacht of de wet op het moment van factureren al in werking is (een soft-launch flag schakelt om naar harde blokkering zodra de wet werkelijk geldt).

Naast het ZZP-/opdrachtgever-perspectief dekt de spec ook de **opdrachten-via-tussenkomst** (broker, detacheringsbureau, Hays/YER/Brunel/Yacht): in zo'n constructie zijn er drie partijen — eindklant, intermediair, ZZP-leverancier — en geldt aparte regelgeving (Wet allocatie arbeidskrachten door intermediairs Waadi, Wet ketenaansprakelijkheid). Het systeem moet die intermediair-structuur kunnen modelleren met aparte DBA-toets per relatie.

## Data Model

### DBAOpdracht

Hoofd-entiteit: één per opdracht (per klant per project of per doorlopende relatie). Bevat intake-antwoorden, modelovereenkomst-referentie, lopende risico-score, en links naar evidence-stukken.

```json
{
  "id": "dba-opdr-2026-0042",
  "ondernemingId": "ond-nl-001234",
  "klantId": "klant-acme-bv",
  "opdrachtNaam": "Backend ontwikkeling betaalmodule",
  "startDatum": "2026-03-01",
  "verwachteEindDatum": "2026-09-30",
  "feitelijkeEindDatum": null,
  "verwachteOmzet": 48000.00,
  "gerealiseerdeOmzet": 18200.00,
  "modelOvereenkomstId": "modov-bd-2024-tussenkomstvrij-v3",
  "intakeStatus": "VOLTOOID",
  "intakeDatum": "2026-02-22",
  "actueleRisicoscore": 34,
  "risicoNiveau": "LAAG_MIDDEN",
  "openFlags": 2,
  "evidenceDossierId": "evid-dba-opdr-2026-0042"
}
```

### DBAIntake

Vragenlijst-antwoorden per opdracht, gestructureerd in drie hoofdblokken (gezag, persoonlijke arbeid, financieel risico) plus aanvullende Deliveroo-criteria.

```json
{
  "id": "intake-dba-opdr-2026-0042",
  "opdrachtId": "dba-opdr-2026-0042",
  "ingevuldOp": "2026-02-22",
  "ingevuldDoor": "user-zzp-ond-001234",
  "gezagsverhouding": {
    "kwaInstructiesScore": 2,
    "kwaResultaatVrijScore": 4,
    "deelneemtAanWerkoverlegScore": 3,
    "subtotaal": 9
  },
  "persoonlijkeArbeid": {
    "vervangbaarScore": 4,
    "vervangingFeitelijkScore": 0,
    "subtotaal": 4
  },
  "financieelRisico": {
    "factuurFrequentieScore": 2,
    "betalingsRisicoScore": 4,
    "investeringEigenMiddelenScore": 3,
    "subtotaal": 9
  },
  "deliverooCriteria": {
    "aardWerkzaamhedenSpecialistisch": true,
    "duurRelatie": "6_TOT_12_MAANDEN",
    "exclusief": false,
    "eigenKlanten": true,
    "eigenReclame": true,
    "subtotaal": 12
  },
  "totaalScore": 34,
  "maxScore": 100,
  "interpretatie": "LAAG_MIDDEN_RISICO"
}
```

### DBAModelovereenkomst

Register van modelovereenkomsten: Belastingdienst-goedgekeurde varianten + eigen overeenkomsten. Bevat metadata, geldigheid, en de "essentiele bepalingen" die in het feitelijke contract terug moeten komen.

```json
{
  "id": "modov-bd-2024-tussenkomstvrij-v3",
  "naam": "Tussenkomst-vrij — algemeen (Belastingdienst v3 — 2024)",
  "bron": "BELASTINGDIENST_GOEDGEKEURD",
  "publicatieURL": "https://www.belastingdienst.nl/.../modelovereenkomsten/tussenkomstvrij",
  "goedkeuringDatum": "2024-04-12",
  "geldigTot": "2029-04-12",
  "essentieleBepalingen": [
    {"id": "EB-01", "tekst": "Opdrachtnemer is vrij in tijd, plaats en wijze van uitvoering", "verplicht": true},
    {"id": "EB-02", "tekst": "Opdrachtnemer mag zich laten vervangen zonder toestemming opdrachtgever", "verplicht": true},
    {"id": "EB-03", "tekst": "Opdrachtnemer factureert per opdracht, geen vaste maandelijkse vergoeding", "verplicht": true}
  ],
  "actueleVersie": true
}
```

### DBARisicoflag

Per opdracht een lopende lijst flags die door de monitoring-engine worden gegenereerd. Elke flag heeft ernst, grondslag, en actiesuggestie.

```json
{
  "id": "flag-dba-opdr-2026-0042-005",
  "opdrachtId": "dba-opdr-2026-0042",
  "type": "FACTUURFREQUENTIE_LIJKT_OP_LOON",
  "detectieMoment": "2026-05-15T09:00:00Z",
  "ernst": "MIDDEN",
  "details": {
    "factuurInterval": "31 dagen ± 2",
    "factuurBedragVariatie": "0.04 (zeer laag)",
    "interpretatie": "Maandfacturatie met vast bedrag oogt als loon"
  },
  "fiscaleBron": "Deliveroo-arrest HR 24-3-2023, criterium 'financieel risico'",
  "actieSuggestie": "Varieer factuurbedrag naar werkelijk gewerkte uren; factureer per deliverable",
  "status": "OPEN",
  "weergegevenAanGebruiker": true
}
```

### DBAPortfolioRisico

Aggregaat over alle actieve opdrachten van een ondernemer: omzetconcentratie, langjarige relaties, exclusiviteit-patronen.

```json
{
  "id": "portf-2026-05-ond-001234",
  "ondernemingId": "ond-nl-001234",
  "peilDatum": "2026-05-21",
  "actieveOpdrachten": 7,
  "concentratie": {
    "grootsteKlant": "klant-acme-bv",
    "aandeelOmzet12mnd": 0.62,
    "drempelHoog": 0.70,
    "status": "WAARSCHUWING"
  },
  "langjarigeRelaties": [
    {"klantId": "klant-acme-bv", "startDatum": "2024-01-15", "duurJaren": 2.4}
  ],
  "exclusieveRelaties": 0,
  "overallRisico": "MIDDEN"
}
```

### DBAEvidenceDossier

Per opdracht een dossier-collectie: getekende modelovereenkomst, eerste/laatste factuur, urenstaat-samenvatting, e-mailcommunicatie-archief.

```json
{
  "id": "evid-dba-opdr-2026-0042",
  "opdrachtId": "dba-opdr-2026-0042",
  "stukken": [
    {"type": "GETEKENDE_OVEREENKOMST", "fileRef": "files/dba/opdr-0042-overeenkomst.pdf", "datum": "2026-02-28"},
    {"type": "FACTUUR_EERSTE", "fileRef": "files/dba/opdr-0042-fact-001.pdf"},
    {"type": "URENSTAAT_KWARTAAL", "fileRef": "files/dba/opdr-0042-urs-q1.pdf"}
  ],
  "compleetheidScore": 0.85,
  "bewaarTermijn": "7 jaar (art. 52 AWR)"
}
```

## Requirements

### Requirement: REQ-DBA-000 Compliance-modus instelling per ondernemer

Bij eerste configuratie moet de ondernemer kiezen tussen "soft mode" (waarschuwingen, geen blokkades), "hard mode" (blokkades bij HOOG-risico), en "intermediair-mode" (extra strenge toets voor tussenkomst-constructies).

#### Scenario: Soft mode

- GIVEN nieuwe ondernemer kiest "soft mode"
- WHEN HOOG-risico-opdracht wordt aangemaakt
- THEN moet waarschuwing verschijnen maar mag de factuur worden verstuurd
- AND moet de waarschuwing in audit-log worden vastgelegd

#### Scenario: Hard mode

- GIVEN ondernemer kiest "hard mode" (preventief)
- WHEN HOOG-risico-opdracht wordt aangemaakt
- THEN moet de eerste factuur worden geblokkeerd
- AND mag pas worden vrijgegeven na management/fiscalist-override met onderbouwing

### Requirement: REQ-DBA-001 Intake bij elke nieuwe opdracht

Bij het aanmaken van een nieuwe opdracht in shillinq moet het systeem een verplichte DBA-intake voorleggen voordat de eerste factuur kan worden uitgestuurd.

#### Scenario: Intake voor eerste factuur

- GIVEN ondernemer maakt een nieuwe opdracht "Backend ontwikkeling Acme"
- WHEN de gebruiker de eerste factuur probeert uit te sturen
- THEN moet het systeem de DBA-intake afdwingen
- AND mag de factuur pas verstuurd worden na voltooide intake

#### Scenario: Skip intake bij €<5000 eenmalige opdracht

- GIVEN ondernemer maakt opdracht met "Eenmalig, totaalbedrag €4.200"
- WHEN intake start
- THEN moet het systeem de verkorte intake (3 vragen) aanbieden in plaats van de volledige
- AND moet de risicoscore worden gemarkeerd als "VERKORT_LAGE_DREMPEL"

### Requirement: REQ-DBA-002 Modelovereenkomst-register

Het systeem moet een register bijhouden van Belastingdienst-goedgekeurde modelovereenkomsten met versiehistorie en geldigheid.

#### Scenario: Modelovereenkomst koppelen aan opdracht

- GIVEN intake is doorlopen
- WHEN gebruiker een modelovereenkomst kiest
- THEN moet het systeem de essentiële bepalingen tonen
- AND moet vragen of de feitelijke overeenkomst ALLE essentiële bepalingen overneemt

#### Scenario: Verlopen modelovereenkomst signaleren

- GIVEN een opdracht gebruikt model "modov-bd-2021-x" dat in 2024 verviel
- WHEN periodieke compliance-scan draait
- THEN moet een flag "MODELOVEREENKOMST_VERLOPEN" worden gegenereerd
- AND moet de gebruiker worden geadviseerd een nieuwe modelovereenkomst te kiezen

### Requirement: REQ-DBA-003 Risico-score op drie pijlers + Deliveroo

Het systeem moet een totaalscore (0-100) berekenen op basis van de drie wezenskenmerken (gezag, persoonlijke arbeid, financieel risico) + Deliveroo-criteria, met heldere grenzen LAAG (<25), LAAG_MIDDEN (25-49), MIDDEN_HOOG (50-74), HOOG (75+).

#### Scenario: Hoge gezagsverhouding-score

- GIVEN intake toont dagelijkse instructies + verplichte aanwezigheid kantoor
- WHEN risico-score wordt berekend
- THEN moet gezagsverhouding-subtotaal hoog (>15 van 20) zijn
- AND moet totaalscore in HOOG-bracket vallen

#### Scenario: Lage score bij specialistisch + eigen klanten

- GIVEN intake toont specialistisch werk + meerdere klanten + eigen reclame
- WHEN score wordt berekend
- THEN moet Deliveroo-criteria subtotaal laag zijn (<5 van 20)
- AND moet de score in LAAG-bracket vallen

### Requirement: REQ-DBA-004 Lopende monitoring op factuurpatronen

Het systeem moet continu monitoren op factuur-frequentie en bedrag-variatie per opdracht; uniforme maandfacturatie met vast bedrag triggert een flag.

#### Scenario: Vaste maandfactuur gedetecteerd

- GIVEN 6 maanden lang factuur op de 1e van de maand met bedrag €4.000 ± 2 procent
- WHEN monitoring-batch draait
- THEN moet flag "FACTUURFREQUENTIE_LIJKT_OP_LOON" worden gegenereerd
- AND moet actiesuggestie aangeven: "Vary bedrag op basis van werkelijke uren"

#### Scenario: Geen flag bij variatie

- GIVEN facturen variëren tussen €2.100 en €6.800 per maand
- WHEN monitoring draait
- THEN mag geen vaste-maandfactuur-flag worden gegenereerd

### Requirement: REQ-DBA-005 Concentratie- en exclusiviteit-monitoring

Het portfolio-aggregaat moet maandelijks worden herberekend en waarschuwen bij omzetconcentratie >70 procent bij één klant of bij langjarige (>2 jaar) relaties met >50 procent omzet.

#### Scenario: Concentratie-waarschuwing

- GIVEN één klant levert 73 procent van de 12-maands omzet
- WHEN portfolio-aggregaat wordt berekend
- THEN moet concentratie.status "WAARSCHUWING" zijn
- AND moet een dashboard-banner verschijnen

#### Scenario: Langjarige hoofdrelatie

- GIVEN klant X bestaat 2.5 jaar en levert 55 procent omzet
- WHEN aggregaat draait
- THEN moet flag "LANGJARIGE_HOOFDRELATIE" worden gegenereerd

### Requirement: REQ-DBA-006 Multiple engagement signaal

Indien een ondernemer meerdere opdrachten aanmaakt voor dezelfde opdrachtgever op verschillende rechtspersonen (concernverhouding), moet het systeem dit als hoog risico flaggen.

#### Scenario: Multiple-entity-zelfde-concern

- GIVEN klant "Acme NL BV" en "Acme België BVBA" hebben dezelfde KvK-uiteindelijk-belanghebbende
- AND er lopen drie opdrachten over deze entiteiten
- WHEN concern-check draait
- THEN moet flag "MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN" worden gegenereerd

### Requirement: REQ-DBA-007 Evidence-dossier per opdracht

Het systeem moet automatisch een evidence-dossier bouwen per opdracht: getekende overeenkomst, eerste/laatste factuur, urenstaten per kwartaal, en optioneel e-mailcommunicatie-archief.

#### Scenario: Compleetheidsscore

- GIVEN opdracht heeft overeenkomst + facturen, geen urenstaten
- WHEN compleetheid wordt berekend
- THEN moet compleetheidScore ~0.6 zijn
- AND moet de gebruiker zien welke stukken ontbreken

### Requirement: REQ-DBA-008 Audit-rapport per opdracht voor Belastingdienst

Op verzoek moet het systeem een PDF-audit-rapport per opdracht produceren met intake-antwoorden, gekozen modelovereenkomst, risico-score-verloop, gegenereerde flags, en de bijbehorende evidence-stukken.

#### Scenario: Belastingdienstcontrole rapport export

- GIVEN Belastingdienst vraagt om dossier opdracht 0042
- WHEN gebruiker "Audit-rapport exporteren" kiest
- THEN moet PDF-A3 worden gegenereerd met intake + score + flags + evidence
- AND moet de SHA-256-hash worden vastgelegd

### Requirement: REQ-DBA-009 Periodieke herbeoordeling

Voor opdrachten langer dan 12 maanden moet het systeem jaarlijks een herbeoordeling vragen om wijzigingen in de werkrelatie vast te leggen.

#### Scenario: Jaarlijkse herbeoordeling

- GIVEN opdracht loopt 13 maanden
- WHEN periodieke trigger draait op verjaardag intake
- THEN moet het systeem een "Bevestig of update intake" notificatie sturen
- AND mag bij geen reactie binnen 30 dagen flag "HERBEOORDELING_OVERDUE" worden gegenereerd

### Requirement: REQ-DBA-010 Opdrachtgever-perspectief (inhuur-flow)

Wanneer shillinq door een opdrachtgever wordt gebruikt om een ZZP'er in te huren, moet de DBA-intake aan de opdrachtgever-kant ook worden afgedwongen voordat een PO/inkoopfactuur wordt aangemaakt.

#### Scenario: Inhuur-intake bij PO

- GIVEN MKB-opdrachtgever maakt PO voor ZZP-leverancier
- WHEN PO wordt opgesteld
- THEN moet de DBA-inhuur-intake verschijnen (mirror van leverancier-zijde)
- AND moet bij HOOG-risico de PO worden geblokkeerd voor goedkeuring zonder management-override

### Requirement: REQ-DBA-011 Belastingdienst-correctieverplichting workflow

Bij ontvangst van een correctieverplichting (digitale brief Belastingdienst) moet het systeem een correctie-dossier kunnen aanmaken met alle relevante opdracht-evidence + boekingen voor herclassificatie.

#### Scenario: Correctie-dossier opbouwen

- GIVEN Belastingdienst stuurt correctie-brief over opdracht 0042
- WHEN gebruiker "Correctie-dossier starten" kiest
- THEN moet het systeem een werkmap aanmaken met alle opdracht-gerelateerde boekingen
- AND moet een herclassificatie-scenario (loon i.p.v. winst) kunnen worden doorgerekend

### Requirement: REQ-DBA-012 Privacy en AVG

E-mail- en communicatie-archieven die als evidence worden opgenomen moeten AVG-compliant worden behandeld: opt-in voor archivering, bewaartermijn 7 jaar, recht op inzage door betrokkenen.

#### Scenario: AVG-opt-in voor e-mail archivering

- GIVEN gebruiker wil e-mailcommunicatie als evidence opnemen
- WHEN archiveringsfunctie wordt ingeschakeld
- THEN moet expliciete opt-in worden gevraagd voor verwerking persoonsgegevens van de wederpartij
- AND moet bewaartermijn duidelijk worden getoond

### Requirement: REQ-DBA-013 Webmodule-Beoordeling-Arbeidsrelatie integratie

Indien de Belastingdienst-webmodule (WBA) voor zelfstandigen actief is, moet shillinq de WBA-uitkomst kunnen opslaan per opdracht als formeel beoordelingsresultaat naast de eigen risico-score.

#### Scenario: WBA "indicatie buiten dienstbetrekking"

- GIVEN ondernemer heeft WBA doorlopen voor opdracht
- WHEN uitkomst "indicatie buiten dienstbetrekking" wordt geüpload
- THEN moet die als formeel beoordelingsresultaat aan de opdracht worden gehangen
- AND moet de gebruiker de geldigheidstermijn (1 jaar volgens WBA-policy) krijgen aangereikt

### Requirement: REQ-DBA-014 VBAR (Vervangbaarheid) bewijslast

De wettelijke vervangbaarheidstoets is een van de zwaarste criteria; het systeem moet expliciet vragen naar bewijzen van werkelijk doorgevoerde vervangingen (niet alleen contractuele clausule) en die als evidence opslaan.

#### Scenario: Vervangbaarheid contractueel maar nooit getoetst

- GIVEN intake meldt "vervangbaar volgens contract" maar geen feitelijke vervanging in 18 maanden
- WHEN risico-score wordt berekend
- THEN moet vervangingFeitelijkScore op 0 staan
- AND moet flag "VERVANGBAARHEID_THEORETISCH" worden gegenereerd

### Requirement: REQ-DBA-015 Branchekader interpretatie

Het systeem moet branche-specifieke kaders (ICT-kader, Zorg-kader, Bouw-kader, Onderwijs-kader) onderkennen waarin extra DBA-criteria gelden, en daarop branchespecifieke flags genereren.

#### Scenario: ICT-kader: integratie in productieteam

- GIVEN ondernemer is ICT-er met opdracht binnen kantoor klant
- AND zit dagelijks in scrum/stand-up met vaste personeelsleden
- WHEN ICT-kader-check loopt
- THEN moet flag "ICT_INTEGRATIE_IN_TEAM" met details over scrum-deelname worden gegenereerd

### Requirement: REQ-DBA-016 VBAR uurtarief-grens monitoring

Bij elke uitgaande factuur moet het systeem het effectieve uurtarief uitrekenen en bij onderschrijden van de VBAR-rechtsvermoeden-grens (EUR 33 peil 2024, geïndexeerd) waarschuwen.

#### Scenario: Onder VBAR-grens

- GIVEN factuur 40 uur tegen EUR 28/uur
- WHEN factuur wordt opgesteld
- THEN moet waarschuwing verschijnen "Effectief uurtarief EUR 28 onder VBAR-rechtsvermoeden-grens EUR 33"
- AND moet de gebruiker worden geadviseerd om tarief te verhogen of motivatie vast te leggen

#### Scenario: Boven grens met all-in vergoeding

- GIVEN factuur fixed-price EUR 12.000 voor 280 uur (effectief EUR 42,86/uur)
- WHEN factuur wordt opgesteld
- THEN moet geen VBAR-flag worden gegeneerd
- AND moet uurtarief in evidence worden opgeslagen voor latere portfolio-aggregatie

### Requirement: REQ-DBA-017 Tussenkomst-driehoek modelleren

Voor opdrachten via een intermediair (broker, detacheringsbureau) moet het systeem de tussenkomst-driehoek modelleren met aparte DBA-toetsing per relatie (ZZP-intermediair én intermediair-eindklant).

#### Scenario: Detachering via Yacht naar Belastingdienst

- GIVEN ondernemer levert via Yacht aan Belastingdienst-eindklant
- WHEN intake wordt gestart
- THEN moet het systeem vragen om beide relaties te documenteren
- AND moet apart DBA-risico worden berekend voor (a) ZZP-Yacht-relatie en (b) Yacht-Belastingdienst-relatie
- AND moet aanvullend de Waadi en Wka worden geflagged als toepasselijke kaders

### Requirement: REQ-DBA-018 Beëindiging-procedure met evidence-cap

Bij beëindiging van een opdracht moet het systeem de evidence-trail afsluiten, een eindrapport produceren, en de bewaarperiode-klok starten (7 jaar conform art. 52 AWR).

#### Scenario: Opdracht beëindigd

- GIVEN opdracht 0042 wordt gemarkeerd als beëindigd per 30 september 2026
- WHEN beëindigingsprocedure draait
- THEN moet eindrapport worden gegenereerd met totaaloverzicht (intake, scores per kwartaal, gegenereerde flags, getroffen acties)
- AND moet de bewaartermijn-klok worden gezet tot 30 september 2033

## Standards & Sources

- Wet deregulering beoordeling arbeidsrelaties (Wet DBA, mei 2016)
- Wetsvoorstel Verduidelijking Beoordeling Arbeidsrelaties en Rechtsvermoeden (VBAR), 2025/2026 — uurtariefgrens EUR 33 (peil 2024, geïndexeerd)
- Burgerlijk Wetboek art. 7:610 (arbeidsovereenkomst-criteria), art. 7:610a (rechtsvermoeden), art. 7:610b (omvang arbeid)
- Hoge Raad 24 maart 2023, ECLI:NL:HR:2023:443, NJ 2023/188 (Deliveroo-arrest, 10 criteria)
- Hoge Raad 13 november 2020, ECLI:NL:HR:2020:1746 (PostNL-arrest)
- Hoge Raad 6 november 2020, ECLI:NL:HR:2020:1747 (Gemeente Amsterdam-arrest)
- Belastingdienst Handhavingsmoratorium opheffing per 1 januari 2025 (Kamerbrief minister SZW)
- Wet op de loonbelasting 1964, art. 6 (inhoudingsplicht werkgever)
- Wet financiering sociale verzekeringen (Wfsv) — premieplicht
- Belastingdienst register modelovereenkomsten (publiek register, https://www.belastingdienst.nl/modelovereenkomsten)
- Webmodule Beoordeling Arbeidsrelatie (WBA) — Belastingdienst-online-tool
- Wet allocatie arbeidskrachten door intermediairs (Waadi) — voor tussenkomst-constructies
- Wet ketenaansprakelijkheid (Wka) — keten-aansprakelijkheid eindopdrachtgever
- AWR art. 52 (bewaarplicht 7 jaar), art. 67e (vergrijpboete bij opzet/grove schuld)
- AVG (Verordening (EU) 2016/679) — verwerking persoonsgegevens wederpartij
- Sectorpremies WW 2026 + Werkhervattingskas-tarieven 2026 (relevant bij naheffing-scenario)

## Cross-app integration

- `bookkeeping-ap-ar` — facturen als evidence + factuurfrequentie-monitoring + uurtarief-detectie
- `bookkeeping-payroll-engine-nl` — bij omzetting naar loondienst (correctie-scenario), berekening van na te heffen LH/SV
- `bookkeeping-ib-aangifte-zzp` — risico-overzicht in jaarrapportage IB; DBA-risico beïnvloedt waarschijnlijkheid van Belastingdienstcontrole
- `bookkeeping-credit-control-dunning` — uitsluiting van dunning bij disputed-DBA-overleg
- `zzp-urencriterium-tracker` — urenregistratie als evidence voor "eigen onderneming runnen"-toets
- `zzp-cashflow-13wk` — concentratie- en exclusiviteit-aggregaten gedeeld
- `hrmq` — inhuur-flow voor MKB-opdrachtgever, modelovereenkomst-register, opdrachtnemer-profielen
- `pipelinq` — opdracht-CRM, communicatie met klanten, contractversiebeheer
- `openconnector` — Belastingdienst-modelovereenkomst-register als externe bron, WBA-API
- `openregister` — file-storage voor evidence-stukken met retentie-policy en SHA-256-hashes
- `docudesk` — versionering en e-signing van modelovereenkomsten met opdrachtgevers
- `hydra` — coördinatie met externe juristen / arbeidsrechtspecialisten

## Target users

- **Primair: ZZP'er met meerdere of langjarige opdrachten** (eigen risicobeheer Wet DBA) — vooral ICT-, consultancy-, communicatie- en zorg-ZZP'ers, omdat juist daar de grenzen tussen "echte" zelfstandigheid en "verkapte" werkrelatie vaag zijn.
- **Secondair: MKB-opdrachtgever die ZZP'ers inhuurt** (inhuur-flow, blokkering risicovolle PO). De inhuur-flow is even kritisch als de opdrachtnemer-flow: een opdrachtgever met VBAR-niet-compliant inhuur loopt na 1 januari 2026 directe naheffing-risico.
- **Tertiair: Belastingadviseur / fiscalist** die voor cliënten DBA-portfolio bewaakt. Voor advieskantoren is bulk-monitoring over cliënten cruciaal.
- **Tertiair: Arbeidsjurist** die DBA-conflicten begeleidt of preventief advies geeft, met behoefte aan compleet dossier.
- **Specifiek belangrijk: ZZP'ers met één hoofdopdrachtgever** (concentratie-monitoring) — dit profiel heeft het hoogste fiscale risico en de hoogste behoefte aan een goed dossier.
- **Specifiek belangrijk: ZZP'ers in zorg en onderwijs** — sectoren waar DBA-controle in 2025/2026 intensief gevoerd wordt.
- **Specifiek belangrijk: Detacheringsbureaus / intermediairs** die ZZP'ers doorzetten naar eindklanten — Waadi- en Wka-risico's bovenop DBA.
- **Niet binnen scope**: payrolling (separate constructie, eigen wetgeving), uitzendconstructies via uitzendbureau (Wet allocatie arbeidskrachten — andere kaders), buitenlandse opdrachtnemers (E101/A1-verklaring uit eigen lidstaat — andere route).

---
status: proposed
app: shillinq
spec: bookkeeping-single-audit-eu-fondsen
depends-on:
  - bookkeeping-sisa-reporting
  - bookkeeping-subsidie-verantwoording
target-users:
  - projectleider-EU-subsidie
  - controller-publieke-sector
  - auditdienst-rijk
  - managementautoriteit
  - eu-auditor-DG-REGIO
  - certificeringsautoriteit
standards:
  - EU-VO-2021/1060
  - EU-VO-2021/1058
  - EU-VO-2021/241
  - EU-VO-2021/1057
  - EU-VO-2021/1056
  - ISA-805
  - INTOSAI
  - ARC-Single-Audit-Strategie
  - CGR-Comptabiliteitswet
---

# Single Audit voor EU-fondsen (ERDF, ESF+, JTF, RRF)

## Purpose

Nederlandse gemeenten, provincies, RUD's, onderwijsinstellingen, kennisinstellingen en SHV-partijen ontvangen substantiële financiering uit EU-fondsen: het Europees Fonds voor Regionale Ontwikkeling (ERDF/EFRO), het Europees Sociaal Fonds Plus (ESF+), het Just Transition Fund (JTF) en de Recovery and Resilience Facility (RRF, in NL het Nationaal Herstelplan). Voor de programmaperiode 2021-2027 bedraagt de Nederlandse allocatie ruim €4.9 miljard, exclusief het RRF (€5.4 miljard) en Interreg-grensoverschrijdende fondsen.

Het verantwoordingsregime is bijzonder zwaar: het single-audit-principe (ARC-Single-Audit-Strategie) eist dat één controleketen volstaat voor alle bestuurslagen, maar in de praktijk leidt dit tot een gelaagde controle door begunstigde-accountant, managementautoriteit, audit-autoriteit (Auditdienst Rijk), Europese Commissie (DG REGIO/EMPL), Europese Rekenkamer en OLAF. Per project gelden strenge voorwaarden: scheiding van EU-fondsen-administratie van reguliere boekhouding, originele bewijsstukken voor elke euro (bonnen, declaraties, contracten, aanbestedingsdossiers, urenstaten, foto's van investeringen), zichtbaarheids- en communicatie-eisen, gendermainstreaming-rapportage, en bewaarplicht van 5 jaar na sluiting van het programma (effectief vaak 12-15 jaar).

Onregelmatigheden boven €10.000 (OLAF-drempel) moeten worden gemeld via het IMS (Irregularity Management System). Financiële correcties bij geconstateerde tekortkomingen kunnen oplopen tot 100% terugvordering met rente. Mid-term audits (jaarlijks accounts-package) en final audits (sluiting van het programma) vereisen een complete reconstructie van de geldstroom van EU-bijdrage tot eindbegunstigde-uitgave.

Shillinq biedt een gespecialiseerde EU-fondsen-administratie-module die voldoet aan de eisen van Verordening (EU) 2021/1060 (Common Provisions Regulation, CPR), de Nederlandse uitvoeringsregelingen (Regeling Europese EZK-en LNV-subsidies, ESF+-regeling SZW) en de single-audit-principes.

## Data Model

**EuProject** (entity): project_code (CCI-nummer + intern), fund (ERDF, ESF+, JTF, RRF, AMIF, ISF, BMVI, EMFAF), programme (Kansen voor West, ESF+ Arbeidsmarkt, JTF Groningen, NL-NHP), priority_axis, specific_objective, intervention_field_code, start_date, end_date, total_eligible_budget, eu_co_funding_rate, national_co_funding (rijk, provincie, gemeente, privaat), beneficiary_organization, partners[], managing_authority, intermediate_body, project_status (in_voorbereiding, uitvoering, afgerond_in_audit, gesloten, ingetrokken).

**EligibilityRule** (entity): fund, regulation_article, rule_description, applicable_cost_categories[], geographical_scope (NUTS-regio), temporal_scope (subsidiabel vanaf indieningsdatum, retroactiviteits-uitzondering), simplified_cost_option (SCO: flat_rate, lump_sum, unit_cost, financing_not_linked_to_costs), evidence_required[].

**SegregatedLedger** (entity): per EU-project een aparte sub-administratie gekoppeld aan het algemene grootboek via dedicated rekeningnummers, met afzonderlijke kostenplaats- en kostendragerstructuur, met een "EU-fonds-vlag" op elke transactie zodat reguliere boekhouding en EU-administratie elk volledig sluitend zijn maar wel reconcilieerbaar.

**EuExpenditure** (entity): project_id, cost_category (personeel, kapitaal, externe_dienstverlening, reis_verblijf, indirecte_kosten), gl_journal_entry_id, gross_amount, vat_treatment (terugvorderbaar=niet_subsidiabel, niet-terugvorderbaar=subsidiabel), declared_amount, eu_co_funding_amount, declaration_period, status (geboekt, gedeclareerd, ingediend_bij_MA, gecertificeerd, betaald_door_EC, in_audit, gecorrigeerd).

**SupportingDocument** (entity): expenditure_id, document_type (factuur, betaalbewijs, contract, aanbestedingsdossier, urenstaat, salaris-specificatie, foto, presentielijst, milestone-rapport), source_uri (in docudesk), digital_signature, retention_until_date, accessibility_level, certified_true_copy_status.

**IrregularityReport** (entity): project_id, detection_date, detection_source (interne_audit, externe_audit, klacht, OLAF, ARC, DG_REGIO), nature (fraude_verdenking, dubbelfinanciering, ondeugdelijke_aanbesteding, niet_subsidiabele_kosten, beneficiary_failure), amount_concerned, recovery_amount, ims_reference, ims_submitted_at, status (initieel, vervolgcontrole, definitief, ingetrokken).

**AuditTrail** (entity): event_type (booking, declaration, certification, payment, correction), actor (natuurlijk_persoon + organisatie + rol), timestamp, before_state, after_state, justification, audit_evidence_uri.

## Requirements

### REQ-001: EU-project opzetten met fonds-specifieke configuratie

**GIVEN** een projectleider start een nieuw ERDF-project "Kansen voor West III - Smart Industry Hub" met CCI-nummer 2021NL16RFPR007
**WHEN** zij het project aanmaakt en het fonds + interventieveld kiest
**THEN** laadt het systeem de toepasselijke eligibility-rules conform Verordening 2021/1058 art 7, configureert automatisch de vereiste sub-grootboek-structuur met EU-vlag, koppelt de juiste managing_authority (in dit geval Stadsregio Rotterdam-Den Haag), past de juiste co-funding-rate toe (max 40% EU voor meer-ontwikkelde regio's), en blokkeert boekingen op project_dates die buiten de subsidiabele periode vallen.

### REQ-002: Gescheiden EU-fondsen-administratie

**GIVEN** een controller boekt een factuur voor consultancy ten behoeve van een ERDF-project (€12.500 excl BTW)
**WHEN** zij de boeking koppelt aan project_code "ERDF-2026-007"
**THEN** boekt het systeem de transactie zowel in de reguliere boekhouding (Consultancy-kosten / Crediteur) als in de gesegregeerde EU-administratie onder cost_category "externe_dienstverlening", markeert de transactie als subsidiabel of niet-subsidiabel op basis van de eligibility-rules, vlagt automatisch wanneer BTW terugvorderbaar is (in welk geval BTW niet subsidiabel is), en houdt beide administraties continu reconcilieerbaar via project-specifieke tussenrekeningen.

### REQ-003: Realisatie-rapportage per kwartaal aan managementautoriteit

**GIVEN** een ERDF-project dient kwartaal-realisatie in conform art 73 CPR
**WHEN** de projectleider de kwartaalrapportage genereert
**THEN** verzamelt het systeem alle subsidiabele uitgaven met status "gedeclareerd" of "ingediend_bij_MA" over de periode, splitst per cost_category, voegt SCO-berekeningen toe (flat-rate 15% voor indirecte kosten, lump-sum voor milestones), levert het outputs-indicators-rapport (RCO-indicatoren ERDF), levert het results-indicators-rapport (RCR-indicatoren), en exporteert in het door de MA vereiste XBRL/Excel-formaat met digitale handtekening.

### REQ-004: Bewijsstukken-management met integriteit-garanties

**GIVEN** een EU-uitgave wordt geboekt en vereist bewijsstukken
**WHEN** de gebruiker bewijsstukken uploadt
**THEN** valideert het systeem dat alle verplichte document-types per cost-category aanwezig zijn (bij personeel: contract, salaris-specificatie, urenstaat met handtekening; bij investering: factuur, betaalbewijs, aanbestedingsdossier indien >€143.000 voor leveringen of >€5.538.000 voor werken), berekent een SHA-256 hash bij upload, bewaart het origineel in docudesk met retentie tot 31-12 van het 5e jaar na programma-sluiting, en weigert de declaratie totdat alle verplichte stukken aanwezig zijn.

### REQ-005: Aanbestedings-compliance toetsing

**GIVEN** een uitgave van €185.000 voor leveringen wordt opgevoerd binnen een ERDF-project
**WHEN** de boeking wordt gevalideerd
**THEN** detecteert het systeem dat de drempel voor EU-aanbestedingsplicht (€143.000 voor centrale overheid, €221.000 voor decentrale overheid in 2026) wordt overschreden, vraagt om upload van het complete aanbestedingsdossier (vooraankondiging, bestek, gunningscriteria, ontvangen inschrijvingen, gunningsbesluit, overeenkomst, publicatie in TenderNed/TED), valideert aanwezigheid van een Single Programming Document-referentie, en blokkeert de declaratie bij ontbrekende of inconsistente aanbestedingsdocumentatie.

### REQ-006: Mid-term audit en jaarlijks accounts-package

**GIVEN** een audit-autoriteit (Auditdienst Rijk) start de jaarlijkse audit conform art 78 CPR
**WHEN** zij toegang vraagt tot het accounts-package
**THEN** genereert het systeem een complete export per programma-jaar (1 juli - 30 juni) met de gecertificeerde uitgaven-tabel, de management-declaration, de jaarlijkse samenvatting van controles en audits, een uitsplitsing naar prioriteit/specifiek doel, en biedt een audit-portaal waarin de ADR doorklikbaar elk uitgave-item kan reviewen met alle onderliggende bewijsstukken en de complete audit-trail van boeking tot certificering.

### REQ-007: Onregelmatigheden-melding via IMS

**GIVEN** een interne controle detecteert een dubbelfinanciering van €15.400 (dezelfde factuur gedeclareerd onder ERDF én ESF+)
**WHEN** de controller een irregularity-report aanmaakt
**THEN** valideert het systeem dat het bedrag de OLAF-drempel van €10.000 overschrijdt, vereist classificatie van de nature (in dit geval "dubbelfinanciering"), genereert het IMS-bericht conform Anti-Fraud Information System schema, vereist een terugvorderings-plan met betalingsregeling van de begunstigde, registreert de melddatum aan de Europese Commissie, en blokkeert verdere declaraties op het betrokken project totdat de correctie is verwerkt.

### REQ-008: Financiële correctie en terugvordering

**GIVEN** DG REGIO legt een 5% flat-rate financiële correctie op bij een geconstateerde aanbestedings-tekortkoming op een €840.000 contract
**WHEN** de certificeringsautoriteit de correctie van €42.000 doorvoert
**THEN** boekt het systeem de correctie in de EU-administratie als negatieve uitgave, initieert een terugvorderings-administratie tegen de begunstigde, koppelt aan het oorspronkelijke audit-bevinding-document, vermindert het beschikbare budget voor toekomstige declaraties met het gecorrigeerde bedrag, en verwerkt de correctie in het volgende accounts-package naar de Europese Commissie.

### REQ-009: Mid-term en final-audit bewijsstukken-reconstructie

**GIVEN** een EU-auditor van DG REGIO vraagt 5 jaar na project-sluiting om een sample-controle van 12 uitgaven
**WHEN** zij de bewijsstukken opvraagt
**THEN** kan het systeem alle gevraagde transacties reconstrueren ondanks dat het project gesloten is, levert per uitgave de complete audit-trail (boeking, declaratie, certificering, betaling, audit-bevindingen), levert alle bewijsstukken met onveranderde hashes, levert de originele aanbestedingsdossiers, en faciliteert een read-only auditor-account met session-logging voor de duur van de audit.

### REQ-010: Zichtbaarheids- en communicatie-naleving (Annex IX CPR)

**GIVEN** een ERDF-project ontvangt EU-financiering boven €500.000
**WHEN** de projectleider rapporteert over zichtbaarheidsverplichtingen
**THEN** registreert het systeem geplaatste billboards/posters/borden met datum, locatie, foto en GPS-coördinaten, controleert aanwezigheid van het EU-embleem op alle communicatie-uitingen (website, persberichten, publicaties), bewaakt aanwezigheid van het verplichte placebijdrage-disclaimer ("Mede gefinancierd door de Europese Unie"), en levert bij audits een complete dossier van de zichtbaarheids-naleving inclusief screenshots en mediabewijs.

## Standards & References

- **Verordening (EU) 2021/1060** (Common Provisions Regulation, CPR): horizontale bepalingen voor ERDF, ESF+, JTF, AMIF, ISF, BMVI, EMFAF
- **Verordening (EU) 2021/1058**: ERDF en Cohesiefonds specifieke bepalingen
- **Verordening (EU) 2021/1057**: ESF+ specifieke bepalingen
- **Verordening (EU) 2021/241**: Recovery and Resilience Facility
- **Verordening (EU) 2021/1056**: Just Transition Fund
- **Anti-Fraude Strategie van de Commissie** + IMS (Irregularity Management System)
- **OLAF-procedures**: melddrempel €10.000, samenwerking met nationale autoriteiten
- **ARC Single-Audit-Strategie**: Nederlandse uitwerking van het single-audit-principe
- **CGR Comptabiliteitswet**: Nederlandse comptabiliteitswet voor overheidsfinanciën
- **Aanbestedingswet 2012** + drempelbedragen (Europese Commissie publiceert tweejaarlijks)
- **INTOSAI** auditstandaarden
- **ISA 805**: Special Considerations - Audits of Single Financial Statements

## Cross-app dependencies

- **shillinq:bookkeeping-sisa-reporting**: Single Information Single Audit-rapportage aan rijksoverheid voor specifieke uitkeringen die naast EU-fondsen lopen
- **shillinq:bookkeeping-subsidie-verantwoording**: bredere subsidie-administratie-laag waarvan EU-fondsen een gespecialiseerde subset zijn
- **shillinq:bookkeeping-cost-accounting**: kostendrager- en kostenplaats-allocatie waarop project-administratie steunt
- **purchaseq**: aanbestedings-workflow met automatische drempel-toetsing voor EU-aanbestedingsplicht
- **docudesk**: lange-termijn bewaring van bewijsstukken met immutability en hash-validatie tot 15 jaar na boeking
- **openconnector**: koppeling met IMS, TenderNed, TED-eSender, en de SFC2021 (Shared Fund Management Common system) van de Europese Commissie
- **openregister**: registratie van EU-projecten als publiek toegankelijke open-data conform transparantie-eisen art 49 CPR

## Target users

- **Projectleider EU-subsidie**: dagelijkse projectadministratie, declaraties, milestone-rapportage
- **Controller publieke sector**: bewaakt scheiding van administraties, intern audit, onregelmatigheden-detectie
- **Auditdienst Rijk (ADR)**: jaarlijkse audit van accounts-package, sample-controles
- **Managementautoriteit** (bijv. Stadsregio Rotterdam-Den Haag, Ministerie SZW voor ESF+): ontvangt declaraties, voert eerste-lijns-controle uit
- **Certificeringsautoriteit**: certificeert uitgaven naar EC, beheert correcties
- **EU-auditor DG REGIO / DG EMPL**: tweede-lijns audits, on-the-spot checks
- **Europese Rekenkamer (ECA)**: hoogste-lijns audit met onaangekondigde sample-controles
- **OLAF-onderzoeker**: fraude-onderzoek bij gemelde of vermoede onregelmatigheden

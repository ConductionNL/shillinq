# Spec: bookkeeping-single-audit-eu-fondsen

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (regulatory + compliance)
**Depends on:** bookkeeping-subsidie-verantwoording, bookkeeping-cost-accounting, docudesk, openconnector, purchaseq
**Compliance basis:** Verordening (EU) 2021/1060 (CPR), 2021/1058 (ERDF), 2021/1057 (ESF+), 2021/1056 (JTF), 2021/241 (RRF); INTOSAI audit standards; Nederlandse single-audit-strategie (ARC)

## ADDED Requirements

### Requirement: REQ-EUF-001 The system SHALL let a projectleider set up an EU-project with fund-specific configuration

The system MUST declare an `EuProject` register (CCI-nummer, fonds enum
ERDF/ESF+/JTF/RRF/AMIF/ISF/BMVI/EMFAF, programma, prioriteit-as, specifiek doel,
budget, co-funding-rate, managing authority, beneficiary, project-status
lifecycle) and, on project creation, load the applicable `EligibilityRule`
records for the chosen fonds, derive the EU-bijdrage, and constrain bookings to
the subsidiabele periode `[startDate, endDate]` (Verordening 2021/1058 art 7,
2021/1060 art 22).

#### Scenario: Onboard an ERDF project and auto-load eligibility rules

- **GIVEN** een projectleider start een ERDF-project "Kansen voor West III — Smart Industry Hub" (CCI 2021NL16RFPR007, prioriteit-as 1, specifiek doel "ondernemerschap KMO")
- **WHEN** zij het project aanmaakt en fonds ERDF + interventieveld 02.5 kiest
- **THEN** laadt het systeem de toepasselijke ERDF eligibility-rules (cost-categories + VAT-treatment), koppelt de managing_authority, past de co-funding-rate toe, blokkeert boekingen buiten de subsidiabele periode, en initialiseert budget_tracking met total_eligible_budget + eu_co_funding_amount.

### Requirement: REQ-EUF-002 The system SHALL maintain a segregated EU-fondsen administration with reconciliation

Every `EuExpenditure` MUST post both in the reguliere GL and in a per-project
`SegregatedLedger` (dedicated rekeningnummers + EU-vlag), kept reconcilieerbaar
via een project-specifieke tussenrekening that nets to zero. VAT that is
terugvorderbaar MUST be flagged niet-subsidiabel; declared_amount is adjusted per
vat_treatment; eu_co_funding_amount is bounded by the project's co-funding-rate
(art 61 + 67 Verordening 2021/1060). A ledger MAY only close when
reconciliationVariance is zero.

#### Scenario: Book a consultancy invoice with VAT recovery into an ERDF project

- **GIVEN** een controller boekt €12.500 consultancy (excl. BTW, terugvorderbaar) op ERDF-project "ERDF-2026-007", cost_category externe_dienstverlening
- **WHEN** de boeking wordt vastgelegd
- **THEN** boekt het systeem zowel in de reguliere GL als in de gesegregeerde EU-administratie, vlagt VAT terugvorderbaar, zet gross_amount €12.500 / eu_co_funding_amount €5.000 (40%) / declared_amount €12.500, en boekt de tussenrekening-mutatie zodat beide administraties sluitend en reconcilieerbaar blijven.

### Requirement: REQ-EUF-003 The system SHALL produce per-kwartaal realisatie-rapportage to the managementautoriteit

The system MUST aggregate `EuExpenditure` by `declarationPeriod` (ISO kwartaal,
art 73 CPR) split by cost_category — exposed declaratively as the EuExpenditure
`x-openregister-aggregations.declarationTotals` block — with RCO/RCR indicator
fields, exportable to XBRL/Excel per MA-format with a digitale handtekening.

#### Scenario: Generate a quarterly declaration

- **GIVEN** een ERDF-project dient kwartaal-realisatie in over 1 okt – 31 dec 2026
- **WHEN** de projectleider de kwartaalrapportage genereert
- **THEN** verzamelt het systeem alle EuExpenditure met status gedeclareerd/ingediend_bij_MA over de periode, splitst per cost_category, voegt SCO-berekeningen + RCO/RCR-indicatoren toe, en levert een tabel met cost_category, gross_amount, vat_treatment, declared_amount en eu_co_funding_amount (export + handtekening deferred T4).

### Requirement: REQ-EUF-004 The system SHALL enforce bewijsstukken-completeness with integrity guarantees

The system MUST declare a `SupportingDocument` register (documentType,
docudesk sourceUri, sha256Hash, retentionUntilDate, certifiedTrueCopyStatus) and
MUST block submission of a declaration (`EuExpenditure` submit transition) until
every verplicht document-type for the cost_category is present. Originals live in
docudesk (ADR-022); only URI + hash are stored. Certification requires a
well-formed SHA-256 hash.

#### Scenario: Personeelskosten declaration blocked until evidence is complete

- **GIVEN** een EU-uitgave voor personeel (€18.000) wordt geboekt
- **WHEN** de controller bewijsstukken uploadt en de declaratie wil indienen
- **THEN** valideert het systeem dat alle verplichte document-types voor personeel (contract + salaris_specificatie + urenstaat) aanwezig zijn, bewaart de SHA-256 hash + URI, en weigert indiening (submit) totdat alle verplichte stukken aanwezig zijn.

### Requirement: REQ-EUF-005 The system SHALL apply aanbestedings-compliance threshold detection

The system MUST flag an `EuExpenditure` as aanbestedingsplichtig when grossAmount
meets the procurement threshold for the beneficiary-type (€143k centrale / €221k
decentrale overheid, 2026, stored per fonds + year on `EligibilityRule`) and MUST
block submission without a linked aanbestedingsdossier SupportingDocument
(art 65 Verordening 2021/1060, Aanbestedingswet 2012).

#### Scenario: A €285k decentrale-overheid IT purchase requires a procurement dossier

- **GIVEN** een uitgave van €285.000 voor een IT-systeem voor gemeente Amsterdam (decentrale overheid)
- **WHEN** de boeking wordt gevalideerd en de declaratie wil indienen
- **THEN** detecteert het systeem overschrijding van de €221.000 drempel, markeert de uitgave aanbestedingsplichtig, en blokkeert indiening (submit) totdat een compleet aanbestedingsdossier-bewijsstuk gekoppeld is.

### Requirement: REQ-EUF-006 The system SHALL generate an accounts-package and a read-only audit-portaal

The system MUST aggregate `EuExpenditure` with status betaald_door_EC per
programma-jaar (1 juli – 30 juni) for the gecertificeerde-uitgaven-tabel +
management-declaration + uitsplitsing per priority/specifiek doel, and MUST offer
a read-only audit-portaal (manifest `EuAuditPortaal`) where an auditor drills
from AuditTrail to EuExpenditure + SupportingDocument with SHA-256 hashes
(art 74 + 78 CPR). The `auditor` role is read-only on every schema's RBAC.

#### Scenario: Auditor reviews a programme-year via the audit-portaal

- **GIVEN** de Auditdienst Rijk start de jaarlijkse audit over 1 juli 2025 – 30 juni 2026
- **WHEN** de auditor toegang vraagt tot het accounts-package
- **THEN** biedt het systeem een read-only audit-portaal met doorklikbare uitgave-items, complete audit-trail per uitgave (boeking → declaratie → certificering → betaling) en downloadbare bewijsstukken met SHA-256 hash; de auditor-rol heeft uitsluitend leesrechten.

### Requirement: REQ-EUF-007 The system SHALL report irregularities via IMS above the OLAF threshold

The system MUST declare an `IrregularityReport` register (detection_source,
nature, amount_concerned, recovery_amount, ims_reference, status lifecycle) and,
when amountConcerned reaches €10.000, MUST require an IMS-reference before the
report escalates and MUST block further declarations on the betrokken project
until correctie verwerkt (art 86 Verordening 2021/1060).

#### Scenario: Double-funding of €15.4k triggers IMS-meldplicht

- **GIVEN** interne controle detecteert dubbelfinanciering: dezelfde factuur €15.400 is onder ERDF én ESF+ gedeclareerd
- **WHEN** de controller een irregularity-report aanmaakt met nature dubbelfinanciering
- **THEN** valideert het systeem dat €15.400 de OLAF-drempel €10.000 overschrijdt, eist een IMS-referentie + terugvorderings-plan voordat de melding naar vervolgcontrole escaleert, en blokkeert verdere declaraties op de betrokken projecten totdat correctie verwerkt is.

### Requirement: REQ-EUF-008 The system SHALL book financial corrections as negative expenditure with recovery

The system MUST book a financiële correctie as a negative `EuExpenditure`
(correctionOfExpenditureId linking the original), reduce the available budget,
and carry the correctie into the volgend accounts-package (art 85 CPR).

#### Scenario: DG REGIO imposes a 5% flat-rate correction of €42k

- **GIVEN** DG REGIO legt een 5% flat-rate correctie op vanwege een aanbestedings-tekortkoming op een €840.000 contract
- **WHEN** de certificeringsautoriteit de correctie van €42.000 doorvoert
- **THEN** boekt het systeem een negatieve EuExpenditure (−€42.000), linkt aan het audit-bevinding-document, vermindert het beschikbare budget en verwerkt de correctie in het volgende accounts-package.

### Requirement: REQ-EUF-009 The system SHALL keep an immutable audit-trail enabling 5+ year reconstructie

The system MUST declare an append-only `AuditTrail` register (event_type, actor
role, timestamp, before/after-state JSON, justification, evidence-URI). AuditTrail
records MUST NOT be modified or deleted, only inserted, so that transactions are
reconstructible 5+ years post-closure with unmodified hashes. BSN / bijzondere
persoonsgegevens MUST NOT be recorded (ADR-005). (art 46 + 69 + 82 CPR).

#### Scenario: A DG REGIO auditor reconstructs a sample five years after closure

- **GIVEN** een DG REGIO-auditor vraagt in juli 2031 een sample-controle van 12 uitgaven uit juli 2026 – juni 2027, na project-sluiting
- **WHEN** de auditor bewijsstukken + audit-trail opvraagt
- **THEN** reconstrueert het systeem elke transactie met complete audit-trail en onveranderde SHA-256 hashes; AuditTrail-records kunnen niet zijn gewijzigd of verwijderd (append-only), en read-only toegang met sessie-logging wordt afgedwongen.

### Requirement: REQ-EUF-010 The system SHALL track zichtbaarheids- en communicatie-naleving

The system MUST allow `SupportingDocument` subtypes billboard_photo,
website_screenshot and media_evidence (with capturedAt, gpsCoordinates,
sourceUrl), scoped to a project, to compile a visibility-dossier per Annex IX CPR
for projects above the €500k EU-bijdrage threshold.

#### Scenario: Compile a visibility-dossier for a >€500k project

- **GIVEN** ERDF-project "Smart Industry Hub" ontvangt €650.000 EU-bijdrage
- **WHEN** de projectleider zichtbaarheidsverplichtingen rapporteert
- **THEN** registreert het systeem billboards/posters (foto + GPS + datum), website-screenshots (URL + datum) en mediabewijs als SupportingDocument-subtypes en kan het bij audit een compleet visibility-dossier per Annex IX CPR samenstellen.

### Requirement: REQ-EUF-011 The system SHALL validate cost-eligibility per fonds and block excluded activities

On `EuExpenditure` declare, the system MUST validate the cost_category against an
active `EligibilityRule` for the project's fonds and MUST block costs for
excluded activities (e.g. politieke campagne onder ESF+ art 5(2)), allowing a
controller override only with an audit-trail note (Verordeningen 2021/1057 art 5,
2021/1058 art 7, 2021/1056 art 12, 2021/241 art 4).

#### Scenario: A political-campaign cost under ESF+ is blocked

- **GIVEN** een projectleider wil €15.000 voor een politieke campagne boeken onder een ESF+ project
- **WHEN** het systeem de cost valideert tegen de ESF+ eligibility-rule
- **THEN** is politieke campagne niet-eligible per art 5(2) en blokkeert het systeem de boeking/declaratie met een foutmelding; override kan alleen met goedkeuring + audit-trail notitie.

#### Scenario: An eligible gender-mainstreaming consultancy cost is confirmed

- **GIVEN** een projectleider boekt €8.000 gender-mainstreaming consultancy (externe_dienstverlening) onder een ESF+ project
- **WHEN** het systeem de cost valideert
- **THEN** leest het systeem de ESF+ eligibility-rule, bevestigt dat externe_dienstverlening eligible is, vlagt de VAT-treatment, en boekt succesvol met eligibility_confirmed.

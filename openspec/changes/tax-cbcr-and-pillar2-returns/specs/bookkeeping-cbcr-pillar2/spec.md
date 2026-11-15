# bookkeeping-cbcr-pillar2 Specification (delta)

## Purpose

The user-facing half of country-by-country reporting and Pillar Two: the
scope test, figures from the books, the CbC file validated against the
OECD CbC XML Schema v2.0, the GloBE information return and the Dutch QDMTT
amount as far as the records carry them, and the pages to review and file.
From shillinq matrix row `tax-cbcr`.

## MODIFIED Requirements

### Requirement: REQ-CBC-001 Drempelwaarde-detectie EUR 750M

The system SHALL keep one stored scope assessment per group and fiscal
year. The group SHALL be in scope for CbC reporting for a fiscal year when
its consolidated revenue in the fiscal year before was EUR 750,000,000 or
more, and in scope for Pillar Two when its consolidated revenue was EUR
750,000,000 or more in at least two of the four fiscal years immediately
before. Revenue SHALL be read from the group's consolidated income
statement where one exists and otherwise entered, and each year's source
MUST be shown. The assessment SHALL set the CbC filing deadline at 12
months after year end, the GloBE information return deadline at 15 months
(18 for the first Pillar Two year) and the bijheffing return deadline at 17
months (20 for the first year). The assessment SHALL run from the "Assess
scope" action on the ultimate parent's page and from a weekly job for a
fiscal year not yet assessed, and the group's responsible owner MUST be
notified when an outcome differs from the year before.

#### Scenario: Groep overschrijdt EUR 750M-drempel in FY2025

- GIVEN a group with consolidated revenue of EUR 720M in 2024 and EUR 805M in 2025
- WHEN the tax director presses Assess scope for 2026 on the ultimate parent's page
- THEN the assessment shows CbC in scope for 2026 with a filing deadline of 31 December 2027
- AND Pillar Two not in scope for 2026, because only one of the four preceding years reached EUR 750M

#### Scenario: Pillar Two follows two years in four

- GIVEN the same group with EUR 790M in 2026
- WHEN the weekly job assesses 2027
- THEN the assessment shows Pillar Two in scope for 2027 as its first year, with the GloBE information return due on 30 June 2029
- AND the group's responsible owner receives a notification that Pillar Two now applies

### Requirement: REQ-CBC-002 CbCR-aggregatie per jurisdictie

The system SHALL store, per group entity and fiscal year, the CbC figures
read from the posted ledger lines of the entity's administration: revenue
from unrelated parties, revenue from related parties, profit before tax,
income tax paid, income tax accrued, stated capital, accumulated earnings,
number of employees and tangible assets other than cash. Each ledger
account MUST carry a CbC category set by the user, and a line on revenue
SHALL count as related when its counterparty stands for another group
entity or its transaction is an intercompany entry. Dividends from other
group entities MUST NOT count as revenue. An entity whose books are not in
Shillinq SHALL take entered or imported figures, marked as such. Amounts
SHALL be converted into the group's presentation currency, result figures
at the year's average rate and balance figures at the closing rate. The
system SHALL then write one jurisdiction summary per tax residency as the
sum of its entities' figures, and MUST NOT overwrite a summary that is
reconciled or locked.

#### Scenario: CbCR-aggregatie voor jurisdictie DE, FY2026

- GIVEN three German group entities with unrelated revenue of EUR 120M, EUR 45M and EUR 8M and related revenue of EUR 30M, EUR 12M and EUR 0
- WHEN the tax director presses Prepare from the books on the 2026 CbC report
- THEN one DE summary for 2026 shows unrelated revenue EUR 173M, related revenue EUR 42M and total revenue EUR 215M
- AND profit before tax, tax paid, tax accrued, stated capital, accumulated earnings, employees and tangible assets are the sums of the three entities' figures
- AND its business activities are the union of the three entities' activities

#### Scenario: Related revenue is split out from the books

- GIVEN Voorbeeld Productie B.V. posted EUR 300M of sales to third parties and EUR 40M to a customer that is listed as standing for Voorbeeld GmbH
- WHEN the tax director presses Read figures from the books for 2026 on the entity's page
- THEN its 2026 figures show unrelated revenue EUR 300M and related revenue EUR 40M, with source books

#### Scenario: An unmapped account is named

- GIVEN an expense account with a 2026 movement and no CbC category
- WHEN the figures are read from the books
- THEN the figures list that account under unmapped accounts
- AND the CbC report's checks tab shows the check "all accounts mapped" as failed

#### Scenario: A reconciled summary is left alone

- GIVEN the DE summary for 2026 is reconciled
- WHEN the tax director presses Prepare from the books on the 2026 CbC report
- THEN the NL summary is rewritten and the DE summary is not
- AND the action reports that it skipped DE because it is reconciled

### Requirement: REQ-CBC-006 QDMTT-prioriteit boven IIR

For jurisdiction NL, when the jurisdiction computation's ETR is below 15%,
the system SHALL mark the QDMTT as applicable, compute the QDMTT amount from
the existing calculation, and create or update the QDMTT return of the
Dutch filing entity with the amount payable and the bijheffing return
deadline from the scope assessment. The QDMTT amount SHALL be reported in
the GloBE information return's jurisdiction section for NL and credited
against any top-up tax attributed to NL under the IIR. A jurisdiction under
a safe harbour MUST NOT get a QDMTT amount. The system MUST NOT produce a
file for the bijheffing tax return until the Belastingdienst's format for
it is known.

#### Scenario: NL-dochter met ETR 12% en buitenlandse moeder

- GIVEN a 2027 NL computation with GloBE income EUR 6M, ETR 12% and SBIE EUR 0.5M
- WHEN the computation is approved
- THEN the QDMTT return for 2027 shows EUR 165,000 payable and the bijheffing return deadline of 31 August 2029
- AND the minimum tax return's QDMTT tab shows the same amount
- AND the top-up tax the German parent would collect for NL under the IIR is reduced by that amount

### Requirement: REQ-CBC-008 XML/XBRL-export CbCR conform OESO-schema

The system SHALL generate the CbC report as an XML file per the OECD CbC
XML Schema version 2.0 of June 2019 (namespace `urn:oecd:ties:cbc:v2`),
with a message header, the reporting entity and its reporting role, one
report per jurisdiction with the summary figures and the constituent
entities with their business activities, and a document reference per
record. The system MUST validate the file against the bundled OECD XSD
before storing it, and MUST NOT store a file that fails validation; the
errors SHALL be shown on the report's checks tab with element and message.
A stored file SHALL record the schema version and the XSD set's checksum.

#### Scenario: CbCR-indiening 2026 naar Belastingdienst

- GIVEN a reconciled 2026 CbC report with 23 jurisdiction summaries and 47 constituent entities
- WHEN the tax director presses Generate XML
- THEN Shillinq itself renders a file with MessageSpec, a DocSpec per record and 23 CbcReports elements, valid against CbcXML_v2.0.xsd
- AND after filing, Mark submitted stores the Belastingdienst reference on the report

#### Scenario: A valid file is stored

- GIVEN a 2026 CbC report with two jurisdictions, all checks passed
- WHEN the tax director presses Generate XML
- THEN a file appears on the Files tab that validates against CbcXML_v2.0.xsd
- AND it contains a CbcReports element for NL and one for DE with Revenues, ProfitOrLoss, TaxPaid, TaxAccrued, Capital, Earnings, NbEmployees and Assets

#### Scenario: An invalid file is not stored

- GIVEN a constituent entity with an address that has no country
- WHEN the tax director presses Generate XML
- THEN no file is stored
- AND the checks tab shows the XSD error naming the Address element

### Requirement: REQ-CBC-009 GIR (GloBE Information Return) genereren

The system SHALL test each jurisdiction of a Pillar Two year against the
transitional CbCR safe harbours (de minimis, simplified ETR, routine
profits) from the jurisdiction's CbC figures, for fiscal years beginning on
or before 31 December 2026 and ending before 1 July 2028, and SHALL prepare
a draft jurisdiction computation for each jurisdiction that passes none.
The system SHALL generate the GloBE information return as XML per the OECD
GIR XML Schema published with the January 2025 user guide, with the filing
information, the corporate structure, a summary per jurisdiction and a
jurisdiction section per jurisdiction. Before rendering, the system MUST
list every element the XSD requires that the stored records cannot fill,
and MUST NOT render a file while that list is not empty. A Dutch TIN SHALL
be the RSIN padded to nine digits with issuer NL and TIN type GIR3001. The
file MUST be validated against the bundled XSD before it is stored.

#### Scenario: GIR FY2026 voor groep van 23 jurisdicties

- GIVEN a group in scope for Pillar Two in 2026 with 23 jurisdictions, of which 5 pass a transitional CbCR safe harbour test
- WHEN the tax director presses Run safe harbour tests and then Generate XML
- THEN the Safe harbour tab shows the passing test for each of the 5 and a draft computation exists for each of the other 18
- AND no file is stored, and the checks tab lists for each of the 18 the per-entity elements the GIR XSD requires that the records do not hold

#### Scenario: Every jurisdiction under safe harbour

- GIVEN a group in scope for Pillar Two in 2026 whose NL and DE jurisdictions both pass the simplified ETR test at 17%
- WHEN the tax director presses Generate XML on the 2026 minimum tax return
- THEN a GIR file is stored that validates against the bundled GIR XSD
- AND its summary claims the transitional CbCR safe harbour for NL and DE

#### Scenario: A computed jurisdiction lacks entity figures

- GIVEN a group in scope for Pillar Two in 2026 whose IE jurisdiction passes no safe harbour test
- WHEN the tax director presses Generate XML
- THEN no file is stored
- AND the checks tab lists, for IE, the per-entity elements the XSD requires that the records do not hold

## ADDED Requirements

### Requirement: REQ-CBC-011 Pages to review and file

The Taxes menu SHALL have three entries: Group entities, Country-by-country
reports and Minimum tax returns, each an index page with a detail page. The
group entity page SHALL show the entity's figures per year with their
source, the scope assessments for the ultimate parent, and an account
mapping tab for the group's accounts. The CbC report page SHALL show the
jurisdictions with the nine figures, the entities, the reconciliation, the
checks and the files, with the actions Prepare from the books, Generate XML,
Mark reconciled, Mark submitted and Create correction. The minimum tax
return page SHALL show the safe harbour results per jurisdiction, the
computations, the QDMTT return, the checks and the files, with the actions
Run safe harbour tests, Generate XML, Approve and Mark submitted. Marking a
return submitted MUST ask for the Belastingdienst reference.

#### Scenario: A tax director files the CbC report

- GIVEN a reconciled 2026 CbC report with a stored file
- WHEN the tax director downloads the file from the Files tab, files it, and presses Mark submitted with reference CBC-2027-0001
- THEN the report is in state submitted and shows the reference

### Requirement: REQ-CBC-012 Checks block the file

Each CbC report and minimum tax return SHALL have a checks tab that runs
and shows as passed or failed: figures present for every entity in scope,
no unmapped accounts, a TIN or a stated reason for every entity, at least
one business activity per jurisdiction, total revenue equal to unrelated
plus related revenue, an explained reconciliation residual, and for the
GloBE return a safe harbour or an approved computation for every
jurisdiction. Generate XML MUST be refused while a blocking check fails and
the refusal SHALL name the check. Submitting a CbC report and approving a
GloBE return MUST be refused while no valid file exists for the records as
they stand.

#### Scenario: A missing TIN blocks the file

- GIVEN a constituent entity without a TIN and without a reason
- WHEN the tax director presses Generate XML on the CbC report
- THEN the action is refused naming the check "TIN or reason for every entity"

### Requirement: REQ-CBC-013 Correcting a submitted CbC report

A submitted CbC report SHALL be correctable through a new report that
points at it. The correction's file SHALL carry the message type indicator
for corrections, and each changed record SHALL carry a corrected document
type with a reference to the last document reference sent for that record.
Records that did not change MUST NOT be repeated in the correction.

#### Scenario: One jurisdiction is corrected

- GIVEN a submitted 2026 CbC report and a later change to DE's tax paid
- WHEN the tax director presses Create correction and then Generate XML
- THEN the file has MessageTypeIndic CBC402 and one CbcReports element for DE with DocTypeIndic OECD2 and the CorrDocRefId of the DE record in the first file

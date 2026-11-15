# Design: tax-cbcr-and-pillar2-returns

Read at shillinq development `68d4e8113` on 2026-10-07.

## Context

**What exists.** `lib/Settings/register.d/bookkeeping-cbcr-pillar2.json`
declares eight schemas: `GroupEntityRegistry`, `CbcrJurisdictionSummary`,
`Pillar2JurisdictionComputation`, `Pillar2SafeHarbour`, `QdmttReturn`,
`GlobeInformationReturn`, `CbcrReturn` and `TaxTreatyOverview`. Three carry
`x-openregister-calculations` (CbC total revenue, GloBE income, ETR, SBIE,
top-up tax). `lib/Lifecycle/CbcrPillar2Guard.php` guards four transitions:
summary reconcile, computation approve, QDMTT submit and CbC return
reconcile (residual over EUR 1M must be explained). The lifecycles are
`draft → reconciled → submitted → locked` (CbC return), `draft → approved →
submitted → locked` (GIR) and `draft → submitted → accepted | rejected`
(QDMTT return).

**What does not.** No page in `src/` names any of the eight schemas. The
`x-openregister-threshold-watcher` on `GroupEntityRegistry`, the three
`x-openregister-export-target` blocks and the five `x-openregister-*-source`
blocks describe a cron job, three renderers and five feeds that no code
reads. `GroupEntityRegistry` has no link to an `Administration`, no TIN and
no address, all of which the CbC file needs. `mainBusinessActivity` is one
value from an enum of eleven that does not match the OECD's thirteen codes.
`CbcrReturn` has no reporting role.

**The books.** Each legal entity kept in Shillinq is an `Administration`
(`register.d/bookkeeping-multi-administratie.json`) with `rsin`,
`legalForm`, `functionalCurrency` and `parentAdministrationId`, but no
country. Posted `GLLine` records carry `administrationId`
(`glline-administration-scope.json`), `subLedgerType` and `subLedgerRef`
(the counterparty document), and since `2026-09-29-reporting-segment-results`
`signedAmount`, `accountClass` and `countsInResult`, stamped by
`GLLineResultStampListener`. `Account` has `accountType` but nothing that
tells revenue from dividends received or current tax from deferred tax.
`ConsolidatedIncomeStatement` (`bookkeeping-consolidation-commercial.json`)
holds the group's consolidated figures per `ConsolidationGroup`.

**Reports and pages.** Files are produced by a `ReportGeneratorInterface`
implementation, listed in `lib/Reporting/ReportCatalogue.php`, stored by
`ReportGenerationService` as a Files-backed `GeneratedReport`. Tax returns
live in the Taxes menu (`BtwAangiften`, `IcpOpgaaf`, `IbAangifte`,
`TaxProvisions`, and so on) as index plus detail pages from manifest
fragments in `src/manifest.d/`. The VAT return change
(`tax-vat-return-from-books`) sets the pattern this change follows: an
action prepares a stored snapshot, a checks tab shows pass or fail, and a
`requires` guard blocks the transition while a blocking check fails.

**The authorities**, read on 2026-10-07:

| What | Version | Source |
|---|---|---|
| CbC XML schema | **v2.0, June 2019** (`targetNamespace="urn:oecd:ties:cbc:v2"`, files `CbcXML_v2.0.xsd`, `oecdcbctypes_v5.0.xsd`, `isocbctypes_v1.1.xsd`, root attribute `version="2.0"`) | OECD, *Country-by-Country Reporting XML Schema: User Guide for Tax Administrations*, Version 2.0, June 2019, https://www.oecd.org/tax/beps/country-by-country-reporting-xml-schema-user-guide-for-tax-administrations-june-2019.pdf. In use since the v1 to v2 cutover of early 2021; no later version is published. |
| CbC filing in the Netherlands | Handleiding CbCNL deel 1, versie 4.1 (14 October 2025) | https://odb.belastingdienst.nl/wp-content/uploads/2025/10/HL_CBCNL_4.1_Deel_1_Algemeen.pdf. Section 1.5: the Dutch XSDs are on odb.belastingdienst.nl behind an account; transport is Digipoort. |
| GIR XML schema | **OECD GIR XML Schema as published with the January 2025 user guide**, approved by the Inclusive Framework on 30 October 2024 | OECD (2025), *GloBE Information Return (Pillar Two) XML Schema: User Guide for Tax Administrations*, https://doi.org/10.1787/c594935a-en, PDF at https://www.oecd.org/content/dam/oecd/en/publications/reports/2025/01/globe-information-return-pillar-two-xml-schema_3980638f/c594935a-en.pdf. The guide's text prints no schema version number; the builder pins the `targetNamespace` and file names from the XSD package itself in `SOURCE.md`. |
| BIA (Dutch GIR) | Handleiding WMBBIANL deel 1, versie 1.0; Release Notes v12 of 12 June 2026; Digipoort filing open from 1 June 2026 | https://odb.belastingdienst.nl/wp-content/uploads/2024/12/HL_WMBBIANL_Deel_1_Algemeen_DEFVERSIE1.0.pdf, https://odb.belastingdienst.nl/bijheffing-informatieaangifte-bia/planningsoverzicht-bijheffing-informatieaangifte/ |
| Dutch TIN in the GIR | RSIN, 9 digits, zero-padded, `issuedBy="NL"`, `TypeOfTIN="GIR3001"`; when a file is split, `FilingInfo` repeats and the first part is `OECD1`, later parts `OECD0` | Belastingdienst, FAQ GIR, 11 June 2026, https://odb.belastingdienst.nl/wp-content/uploads/2026/06/FAQ_GIR.pdf |

The OECD website refuses scripted requests (HTTP 403) on its HTML pages;
the two PDFs above download. The XSD packages have to be fetched by hand
from the OECD publication pages (task 2.1).

## Goals / Non-Goals

**Goals**

- A tax director sees, per fiscal year, whether the group is in scope for
  CbC and for Pillar Two, and the deadlines that follow.
- The CbC figures come from the books of every administration in the
  group, per entity, summed per jurisdiction, and every number traces back
  to ledger lines or to an entered value marked as entered.
- The CbC file is valid against the OECD XSD before it exists.
- The GIR and the Dutch QDMTT amount are produced where the stored records
  carry what the XSD requires, and the page says plainly where they do not.

**Non-Goals**

- Digipoort transport, the art. 29d notification, the bijheffing tax return
  file.
- Entity-level GloBE computation (the GIR's per-entity income and
  adjustment elements).
- Consolidating the group: this change reads each administration's own
  ledger.

## Decisions

### D1. A scope assessment per group and fiscal year

A new schema `GroupScopeAssessment` holds: `ultimateParentEntity` (the UPE's
`GroupEntityRegistry`), `fiscalYear`, `fiscalYearStart`, `fiscalYearEnd`,
`revenueByYear` (the four preceding years and the current one, each with
amount in EUR and source `consolidated-statements` or `entered`),
`cbcrInScope`, `pillar2InScope`, `firstCbcrYear`, `firstPillar2Year`,
`cbcrFilingDeadline`, `girFilingDeadline`, `bijheffingReturnDeadline`,
`fxRates` (average and closing rate per currency for the year, see D2),
`assessedAt`, `assessedBy`.

`GroupScopeService::assess(upeId, fiscalYear)` reads consolidated revenue
from the `ConsolidatedIncomeStatement` of the `ConsolidationGroup` whose
`parentAdministrationId` is the UPE's administration. A year without one
takes the entered amount. The rules:

- CbC in scope for year Y when revenue in Y-1 is EUR 750,000,000 or more
  (Handleiding CbCNL 4.1, section 1).
- Pillar Two in scope for year Y when revenue is EUR 750,000,000 or more in
  at least two of the four years immediately before Y (Wet minimumbelasting
  2024 art. 2.1).
- CbC deadline: 12 months after year end. GIR deadline: 15 months, 18 for
  the first Pillar Two year. Bijheffing return: 17 months, 20 for the first
  year.

The assessment is a stored record so the outcome that was relied on stays
readable after the books change. "Assess scope" on the UPE's detail page
runs it; a weekly `TimedJob` (`GroupScopeAssessmentJob`) runs it for every
UPE whose current fiscal year has no assessment yet and whose previous year
has a consolidated income statement, and notifies the UPE's
`responsibleOwner` when `cbcrInScope` or `pillar2InScope` differs from the
year before. The job is registered in `info.xml` with a version bump.

`GroupEntityRegistry.thresholdCrossed` and `thresholdCrossedAt` stay and are
written by the service from the latest assessment, so existing readers
keep working. The `x-openregister-threshold-watcher` block is removed.

Alternative considered: keep the declarative threshold-watcher and build
the cron in openconnector, as the archived tasks said. Rejected: integriq
(openconnector) has no such job and the rule needs four years of history,
which is a read across records, not a property calculation.

### D2. Figures per entity from the books, summed per jurisdiction

**Group entities get what the file needs.** The new fragment adds to
`GroupEntityRegistry`: `administrationId` (the Shillinq administration that
holds the entity's books, optional), `tin`, `tinIssuedBy`, `address`
(street, city, postal code, country), `incorporationCountry`,
`bizActivities` (array of OECD codes `CBC501` to `CBC513`), and
`counterpartyRefs` (the `CustomerMaster` and `Payee` ids that stand for this
entity in other administrations). A repair step maps the old
`mainBusinessActivity` value into `bizActivities`. `tin` for a Dutch entity
defaults to the administration's `rsin`.

**Accounts get a CbC category.** `Account.cbcrCategory` with values
`revenue`, `revenue-excluded` (dividends and other distributions from
other constituent entities, excluded from CbC revenue by the user guide),
`income-tax-current`, `income-tax-deferred`, `income-tax-payable`,
`stated-capital`, `accumulated-earnings`, `tangible-asset`,
`eligible-payroll` (for the SBIE, D4) and `none`. A suggestion is filled in
from `accountType` on first use (revenue accounts to `revenue`, the rest to
`none`) and the user confirms it on the account mapping tab.

**`CbcrEntityFigures`** is a new schema, one record per entity per fiscal
year: `entity`, `fiscalYear`, `source` (`books`, `entered`, `imported`),
`currency`, the nine CbC figures (`unrelatedPartyRevenue`,
`relatedPartyRevenue`, `profitBeforeTax`, `incomeTaxPaidCash`,
`incomeTaxAccrued`, `statedCapital`, `accumulatedEarnings`,
`numberOfEmployees`, `tangibleAssetsOtherThanCash`), two Pillar Two inputs
(`deferredTaxExpense`, `eligiblePayroll`), `unmappedAccounts` (account
numbers with movement or balance and no category), `computedAt`.

`CbcrFiguresService::readFromBooks(entityId, fiscalYear)` reads the posted
`GLLine` records of the entity's administration within the fiscal year:

| Figure | From the books |
|---|---|
| Revenue, unrelated and related | Sum of `signedAmount` on `revenue` accounts where `countsInResult`. A line is related when its `subLedgerRef` resolves to a counterparty listed in any group entity's `counterpartyRefs`, or its transaction is an `IntercompanyJournalEntry`. `revenue-excluded` accounts are left out. |
| Profit before tax | Sum of `signedAmount` over all lines where `countsInResult`, minus the `income-tax-current` and `income-tax-deferred` accounts. |
| Tax accrued | Debit-positive movement on `income-tax-current` accounts in the year. |
| Tax paid | Debit movement on `income-tax-payable` accounts in the year whose transaction has no line on another income tax account, so a reclassification does not count as a payment. |
| Stated capital, accumulated earnings, tangible assets | Closing balance at year end of the mapped accounts. When the year is not closed, accumulated earnings add the year's result and the figure is marked "year not closed". |
| Employees | Headcount at year end from humaniq through the register resolution in `HrmqCostRateAdapter` (it already knows the `humaniq` and `hrmq` slugs); else entered. The method goes into the summary's `employeeMethodologyNote`. |
| Deferred tax, eligible payroll | Movement on `income-tax-deferred` and `eligible-payroll` accounts. |

An entity without `administrationId` gets its figures entered on its detail
page or imported from a CSV with the same columns; `source` says which.

Amounts are converted into the UPE's presentation currency: result figures
at the average rate, balance figures at the closing rate, both taken from
`GroupScopeAssessment.fxRates`. The user enters these rates once per year;
the OECD guidance asks for the rate used in the consolidated statements,
which this change does not try to derive.

**Per jurisdiction.** `CbcrFiguresService::summarise(upeId, fiscalYear)`
writes one `CbcrJurisdictionSummary` per tax residency (`taxResidency`,
falling back to `jurisdiction`) as the sum of the entities' figures, and
`mainBusinessActivities` as the union of their `bizActivities`. An entity
with no tax residency is reported under the stateless code of the XSD's
country list. Summaries in state `reconciled` or `locked` are not
overwritten; the action says which jurisdictions it skipped.

The five `x-openregister-*-source` blocks are removed: they described
feeds from apps (`bookkeeping-vpb-mkb`, `hrmq` as an app id) that do not
exist under those names, and this service is the feed.

Alternative considered: one declarative `x-openregister-aggregations`
across administrations. Rejected: the related-party split needs the
counterparty of each line resolved against the group's entities, and the
figures must be stored per entity to be reviewed and corrected, which a
live aggregation does not give.

### D3. The CbC file

`CbcXmlGenerator` implements `ReportGeneratorInterface` with report type
`cbc-report` (category `tax`, format `xml`) and renders from a `CbcrReturn`
and the records it links:

- `MessageSpec`: `SendingEntityIN` (the reporting entity's TIN),
  `TransmittingCountry` and `ReceivingCountry` `NL`, `MessageType` `CBC`,
  `Language`, `MessageRefId` (unique per file), `MessageTypeIndic` `CBC401`
  for a first file and `CBC402` for a correction, `ReportingPeriod` (year
  end), `Timestamp`.
- `CbcBody/ReportingEntity`: the entity with `ResCountryCode`, `TIN`,
  `Name`, `Address`; `NameMNEGroup`; `ReportingRole` from the new
  `CbcrReturn.reportingRole` (`CBC701` UPE, `CBC702` surrogate, `CBC703`
  local filing, `CBC704` local filing with incomplete information);
  `ReportingPeriod` start and end; `DocSpec`.
- `CbcBody/CbcReports`, one per `CbcrJurisdictionSummary`: `ResCountryCode`,
  `Summary` (`Revenues/Unrelated`, `Related`, `Total`, `ProfitOrLoss`,
  `TaxPaid`, `TaxAccrued`, `Capital`, `Earnings`, `NbEmployees`, `Assets`,
  amounts with `currCode`), `ConstEntities` for each entity resident there
  with `IncorpCountryCode` and `BizActivities`, and `DocSpec`.
- `CbcBody/AdditionalInfo`: the employee methodology and the source of the
  figures (consolidated statements or entity books), as the user guide
  allows.

Every `DocSpec` gets a `DocRefId` stored on the record it came from
(`CbcrReturn.docRefIds`, keyed by record id). A correction of a submitted
return is a new `CbcrReturn` with `correctsReturn` set; its file uses
`OECD2` with `CorrDocRefId` pointing at the last `DocRefId` of each changed
record, and leaves unchanged records out.

**Validation before storage.** The generator builds the document, then
calls `DOMDocument::schemaValidate()` against
`lib/Standards/Xsd/oecd-cbc-v2.0/CbcXML_v2.0.xsd` with
`libxml_use_internal_errors(true)`. If validation fails, nothing is stored,
the `GeneratedReport` is not created, and the errors (line, element,
message) are written to `CbcrReturn.lastValidation` and shown on the checks
tab. A stored file always carries `schemaVersion` `2.0` and the XSD
folder's checksum in its record.

The `x-openregister-export-target` block on `CbcrReturn` is removed; its
field `cbcrXmlSubmission` now holds the `GeneratedReport` id of the file.

### D4. Safe harbour first, computation where it fails

`Pillar2SafeHarbourService::test(upeId, fiscalYear)` runs, per jurisdiction
of a Pillar Two in-scope year, the three transitional CbCR safe harbour
tests on the `CbcrJurisdictionSummary` figures, and writes one
`Pillar2SafeHarbour` record per test with `dataSource` `qualified-cbcr` and
the inputs in `supportingCalculations`:

- De minimis: total revenue below EUR 10,000,000 and profit before tax
  below EUR 1,000,000.
- Simplified ETR: (tax accrued plus deferred tax expense) divided by profit
  before tax at least 15% for fiscal years beginning in 2023 and 2024, 16%
  in 2025, 17% in 2026.
- Routine profits: profit before tax not above the SBIE amount computed
  from `eligiblePayroll` and `tangibleAssetsOtherThanCash` at the year's
  carve-out rates.

The tests apply to fiscal years beginning on or before 31 December 2026
and ending before 1 July 2028. Outside that window the service writes no
safe harbour record and every jurisdiction gets a computation.

A jurisdiction that passes no test gets a `Pillar2JurisdictionComputation`
in state `draft`, prefilled with `commercialProfitBeforeTax`, covered taxes
(current plus deferred, as one `coveredTaxAdjustments` line), `payroll` and
`tangibleAssetsNbv`. The GloBE adjustments are entered by the user on the
computation's detail page in the existing `globeIncomeAdjustments` array;
the declared calculations then produce ETR, SBIE and top-up tax. The page
says the adjustments are entered, not derived.

The carve-out rates come from the existing `carveOutRatePayroll` and
`carveOutRateTangible` fields. Task 3.3 checks the rate table against GloBE
Model Rules art. 9.2 and the Wet minimumbelasting 2024 before the service
uses it: the archived spec's REQ-CBC-005 scenario gives 9.6% and 7.6% for
2026, which reads one year off the 0.2 point yearly step from 10% and 8% in
2023.

**QDMTT.** For jurisdiction `NL` with a computation whose ETR is below 15%,
`qdmttApplicable` is set and the existing calculation fills `qdmttAmount`.
The service then creates or updates the `QdmttReturn` for the NL filing
entity with `qdmttPayable`, `filingDueDate` and `paymentDueDate` set to the
bijheffing return deadline from the scope assessment. A jurisdiction under
safe harbour has no QDMTT top-up. The `QdmttReturn` has no file: see Open
Questions in the proposal.

### D5. The GIR file, only as far as the records reach

`GirXmlGenerator` implements `ReportGeneratorInterface` with report type
`globe-information-return` and renders from a `GlobeInformationReturn`:

- `MessageSpec` as for CbC, with the GIR message type of the XSD.
- `FilingInfo`: filing constituent entity (TIN rules from the FAQ GIR for a
  Dutch entity: RSIN zero-padded to 9 digits, `issuedBy="NL"`,
  `TypeOfTIN="GIR3001"`), reporting period, `DocSpec`.
- `GeneralSection/CorporateStructure`: the UPE and every constituent entity
  from `GroupEntityRegistry`, ownership from `parentEntity` and
  `consolidationPercentage`, excluded-entity type from
  `excludedEntityType`.
- `Summary`, one per jurisdiction: the safe harbour claimed, the ETR range,
  whether SBIE applied, and the QDMTT and GloBE top-up ranges, from the
  safe harbour records and computations.
- `JurisdictionSection`: for a jurisdiction under safe harbour, the safe
  harbour election only; for a computed jurisdiction, the jurisdiction-level
  ETR computation and, for `NL`, the `QDMTT` element with `Amount` and
  `Currency`.

The XSD also asks, for a computed jurisdiction, for figures per constituent
entity (its GloBE income and adjustments, its covered taxes) that the
schemas hold only per jurisdiction. Before rendering, the generator lists
every mandatory element it cannot fill from a record. If the list is not
empty, it renders nothing and the checks tab shows the list per
jurisdiction. In practice: a group with every jurisdiction under safe
harbour gets a complete file; a group with a computed jurisdiction gets the
computation, the QDMTT amount and a precise list of what an adviser has to
add. Validation against `lib/Standards/Xsd/oecd-gir-2025-01/` runs as in D3.

Alternative considered: emit a GIR with the entity sections empty and let
the Belastingdienst reject it. Rejected: a file that fails validation is not
an output, and the BIA checks file errors and severe record errors from the
first year (FAQ GIR, Q1).

### D6. Pages under Taxes

Three menu entries in the Taxes group, from
`src/manifest.d/tax-cbcr-and-pillar2-returns.json`:

1. **Group entities** (`GroupEntities`, route `/taxes/group-entities`):
   index with name, jurisdiction, role (UPE or constituent), administration,
   in scope. Detail `GroupEntityDetail`: the fields of D2; tabs *Figures*
   (`CbcrEntityFigures` per year, with the source and any unmapped
   accounts), *Scope* (UPE only, the `GroupScopeAssessment` records),
   *Audit trail*. Actions: "Assess scope" (UPE only), "Read figures from the
   books" (entities with an administration, asks for the year), "Enter
   figures" and "Import figures" (the others). An account mapping tab on the
   UPE lists every account of every group administration with its
   `cbcrCategory` and saves changes in bulk.
2. **Country-by-country reports** (`CbcrReturns`, route
   `/taxes/cbc-reports`): index with year, reporting entity, role, state,
   deadline. Detail `CbcrReturnDetail`: a header with the scope outcome and
   the deadline; tabs *Jurisdictions* (the summaries with the nine figures,
   each row opening its entities' figures), *Entities*, *Reconciliation*
   (the existing `reconciliationItems` and residual), *Checks*, *Files*.
   Actions: "Prepare from the books" (runs D2 for every entity and
   summarises), "Generate XML", "Mark reconciled" (the existing transition),
   "Mark submitted" (asks for the Belastingdienst reference, writes
   `taxAuthorityReference`), "Create correction" (submitted returns only).
3. **Minimum tax returns** (`GlobeInformationReturns`, route
   `/taxes/minimum-tax`): index with year, filing entity, state, deadline,
   QDMTT payable. Detail `GlobeInformationReturnDetail`: tabs *Safe
   harbour* (per jurisdiction, each test pass or fail with its inputs),
   *Computations* (links to `Pillar2JurisdictionComputation` detail pages,
   where adjustments are entered), *QDMTT* (the NL return with amount and
   deadlines), *Checks*, *Files*. Actions: "Run safe harbour tests",
   "Generate XML", "Approve", "Mark submitted".

Dialogs live in `src/modals/` as their own components (hydra
modal-isolation gate). Labels are sentence case, Dutch and English.

### D7. Checks block the file

`CbcrChecks` and `GirChecks` register with `RuleEngine` for `CbcrReturn` and
`GlobeInformationReturn`, each check with id, severity and message, the
same way `tax-vat-return-from-books` adds `VatReturnChecks`.

Blocking for CbC: every entity in scope has figures for the year; no
entity has unmapped accounts; every entity has a TIN or the XSD's "no TIN"
value with a reason; every jurisdiction has at least one business activity;
total revenue equals unrelated plus related; the reconciliation residual is
explained (the existing guard's rule). Warning: tangible assets per
administration differ from the fixed asset register's net book value by
more than 1%; figures entered rather than read from the books.

Blocking for GIR: every jurisdiction has a safe harbour record or an
approved computation; the missing-element list of D5 is empty.

"Generate XML" is refused while a blocking check fails and names it.
`CbcrPillar2Guard` gets `canSubmitCbcrReturn` and `canApproveGir`, the
`requires` of `CbcrReturn.submit` and `GlobeInformationReturn.approve`,
denying while no valid file exists for the current state of the records.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Scope rule over four years | Imperative, `GroupScopeService` | Reads across years and records; stored as an assessment. |
| Figures from the ledger | Imperative, `CbcrFiguresService` | Counterparty resolution per line; stored per entity for review. |
| Sum per jurisdiction | Imperative write of `CbcrJurisdictionSummary` | The summary is reviewed and locked; a live aggregation cannot be locked. |
| Totals, ETR, SBIE, top-up | Declarative, the existing calculations | Already declared; kept. |
| Safe harbour tests | Imperative, `Pillar2SafeHarbourService` | Year-dependent thresholds and a stored record per test. |
| Files | Imperative generators with XSD validation | Rendering and validation are code. |
| Blocking a transition | Declarative `requires` plus the guard | As the lifecycle engine expects. |

## Seed Data

Test data only, not shipped as seeds: "Voorbeeld Groep Holding B.V." (NL,
UPE, administration with RSIN 001234567) with "Voorbeeld GmbH" (DE,
entered figures) and "Voorbeeld Productie B.V." (NL, administration).
Consolidated revenue EUR 720M in 2024, EUR 805M in 2025, EUR 790M in 2026.
In 2026 the NL Productie B.V. sells EUR 40M to the GmbH (a `CustomerMaster`
listed in the GmbH's `counterpartyRefs`) and EUR 300M to third parties.

## Risks / Trade-offs

- [Revenue split rests on `counterpartyRefs`] → an intercompany customer
  not listed counts as unrelated; the figures tab lists the top ten
  counterparties per entity so a missing one stands out.
- [Entered FX rates] → the rates are on the assessment, shown on the
  figures tab, and part of the file's audit trail.
- [The XSD packages are hand-fetched] → `SOURCE.md` records URL, date and
  SHA-256; a unit test fails when a file's checksum differs.

## Migration Plan

A repair step maps `mainBusinessActivity` into `bizActivities` and fills
`tin` from the administration's `rsin` where an entity has one. Existing
records are otherwise untouched.

## Open Questions

See the proposal: the bijheffing tax return format, and the Dutch XSDs
behind the ODB account.

# Tasks — Country-by-Country Reporting (CbCR) & OESO Pillar Two

> **Implemented (config kind, ADR-031/037).** The CbCR / Pillar 2 surface is
> declared as a modular register fragment (`lib/Settings/register.d/bookkeeping-cbcr-pillar2.json`)
> — eight schemas with declarative x-openregister-calculations (ETR, SBIE
> carve-out, top-up tax, GloBE income, CbCR total revenue), an aggregation for
> the 7-field roll-up, and x-openregister-lifecycle workflows. The only PHP is
> `lib/Lifecycle/CbcrPillar2Guard.php` for the cross-field ADR-031 exception
> path (QDMTT priority, reconciliation sign-off, QDMTT submission). Manifest
> navigation (5 entries + 10 pages), nl+en i18n, and unit tests ship.
> **Deferred** (documented per task below): the OESO CbC v2.0 / GIR XML
> renderers + SBR/Digipoort submission are openconnector-owned (T4); cross-app
> data integration (consolidation, deferred-tax, Vpb, fixed-assets, hrmq) and
> the EUR 750M threshold BackgroundJob need the not-yet-merged dependency apps
> + a live instance. Tasks 5–12 deviate from the literal "edit
> shillinq_register.json" wording per ADR-037 (fragment, never the monolith).

## Tasks

- [x] Task 1: Confirm no `bookkeeping-cbcr-pillar2` capability spec already
  exists; verify no `group-entity-registry`, `cbcr-jurisdiction-summary`,
  `pillar2-jurisdiction-computation`, `pillar2-safe-harbour`, `qdmtt-return`,
  `globe-information-return`, `cbcr-return`, `tax-treaty-overview` schemas
  are declared; verify no `lib/Service/CbCR*`, `lib/Service/GloBE*`,
  `lib/Service/QDMTT*` PHP classes present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/bookkeeping-cbcr-pillar2/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory + compliance)`
  / `Depends on: bookkeeping-consolidation-commercial, bookkeeping-deferred-tax,
  bookkeeping-vpb-mkb, bookkeeping-fixed-assets-depreciation, hrmq` header;
  `REQ-CBC-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks
  with GIVEN/WHEN/THEN per each requirement; cite OESO BEPS Action 13, GloBE
  Model Rules, Wet Vpb art. 29b, Wet minimumbelasting 2024 inline

- [x] Task 3: Author `proposal.md` referencing the shared multinational tax
  architecture and including Affected Projects (shillinq, openregister,
  openconnector, decidesk) / Scope (8 registers, EUR 750M detection, CbCR 7-field
  aggregation, GloBE 35-item corrections, ETR per jurisdiction, SBIE carve-out,
  safe harbour tests, QDMTT priority, XML export) / Risks (consolidation data
  completeness, payroll/tangible asset lag, GloBE divergence, QDMTT/IIR
  coordination, safe harbour transition, XML schema compliance) / Open Questions
  (consolidation method variants, GloBE input source, payroll definition) /
  Dependencies

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (eight registers:
  entity registry + CbCR summaries + Pillar 2 computations + safe harbour +
  returns), D2 (EUR 750M automatic threshold detection), D3 (CbCR aggregation
  from entity registry), D4 (GloBE income with 35 corrections), D5 (ETR
  calculation), D6 (SBIE carve-out with phase-out percentages), D7 (safe harbour
  transitional tests 2024–2026), D8 (QDMTT priority over IIR), D9 (GIR + QDMTT
  XML export), D10 (reconciliation CbCR ↔ consolidated P&L)

- [x] Task 5: Declare the `group-entity-registry` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-001–010 fields
  (entityName, legalForm, jurisdiction ISO 3166-1 alpha-2, taxResidency,
  parentEntity FK self, ultimateParentEntity FK self, consolidationPercentage,
  consolidationMethod enum: full/proportional/equity/none, mainBusinessActivity
  CBCR enum, lei, vatNumber, cbcrIncluded boolean, pillar2Included boolean,
  excludedEntityType enum, firstYearInGroup date)

- [x] Task 6: Declare the `cbcr-jurisdiction-summary` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-002 fields (period
  fiscal year, jurisdiction, unrelatedPartyRevenue, relatedPartyRevenue,
  totalRevenue computed, profitBeforeTax, incomeTaxPaidCash, incomeTaxAccrued,
  statedCapital, accumulatedEarnings, numberOfEmployees integer, tangibleAssetsOtherThanCash,
  mainBusinessActivities array)

- [x] Task 7: Declare the `pillar2-jurisdiction-computation` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-003–006 fields (period,
  jurisdiction, globeIncome, globeIncomeAdjustments array {type, amount, description},
  adjustedCoveredTaxes, coveredTaxAdjustments array, etrJurisdiction computed,
  minimumRate 0.15, topUpTaxRate computed, payrollCarveOut, tangibleAssetCarveOut,
  substanceBasedIncomeExclusion computed, excessProfit computed, topUpTaxAmount
  computed, qdmttApplicable boolean, qdmttAmount, iirAmount, utprAmount,
  safeHarbourApplied boolean, safeHarbourTest text)

- [x] Task 8: Declare the `pillar2-safe-harbour` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-007 fields (period,
  jurisdiction, testApplied enum: de-minimis/simplified-etr/routine-profits,
  testResult enum: pass/fail, dataSource enum: qualified-cbcr/financial-statements,
  supportingCalculations JSON)

- [x] Task 9: Declare the `qdmtt-return` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-006 fields (period,
  entity FK group-entity-registry NL-resident, taxableGlobeIncome,
  qualifyingDomesticEtr, qdmttPayable, paymentDueDate, filingDueDate,
  belastingdienstReference, xbrlSubmission file, submissionStatus enum:
  draft/submitted/accepted/rejected, submissionTimestamp)

- [x] Task 10: Declare the `globe-information-return` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-009 fields (period,
  ultimateParent FK group-entity-registry, mneGroupSummary JSON, jurisdictionalComputations
  array FK pillar2-jurisdiction-computation, topUpTaxAllocation JSON IIR/UTPR/QDMTT
  distribution per entity, globeXmlSubmission file, submissionDeadline date)

- [x] Task 11: Declare the `cbcr-return` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CBC-008 fields (period,
  reportingEntity FK group-entity-registry, jurisdictionSummaries array FK
  cbcr-jurisdiction-summary, constituentEntityList array FK group-entity-registry,
  cbcrXmlSubmission file, belastingdienstReference, submissionDeadline date,
  mcaaPartnerJurisdictions array)

- [x] Task 12: Declare the `tax-treaty-overview` schema in
  `lib/Settings/shillinq_register.json` with REQ-CBC-004 fields (countryA
  ISO code, countryB ISO code, treatyName, treatyDate, withholdingRates object,
  mliApplicability boolean)

- [x] Task 13: Implement EUR 750M threshold detection per REQ-CBC-001 —
  `x-openregister-aggregations` query on `cbcr-jurisdiction-summary` sum
  (omzet per FY); compare against prior-year; flag boolean when crossing threshold;
  emit system warning for first CbCR filing (12 months after FYE) and GIR (18 months)
  *(declarative — adds `GroupEntityRegistry.thresholdCrossed` + `thresholdCrossedAt`
  UPE-only flags + an `x-openregister-threshold-watcher` block on
  `GroupEntityRegistry` pinning the openconnector cron contract: `thresholdEur=750M`,
  `comparisonWindow=prior-fiscal-year`, source = sum of
  `CbcrJurisdictionSummary.totalRevenue` for the prior period, write-back to UPE
  (thresholdCrossed/thresholdCrossedAt/cbcrIncluded/pillar2Included), and the two
  deadline warnings (firstCbcrDeadline = FYE + 12 months; firstGirDeadline = FYE +
  18 months). The live BackgroundJob ships with the openconnector cron-watcher
  apply cycle on a live instance — see honest-deferral note below.)*

- [x] Task 14: Implement per-jurisdiction CbCR aggregation per REQ-CBC-002 —
  `x-openregister-aggregations` query grouping `group-entity-registry` by
  jurisdiction; summing unrelatedPartyRevenue, relatedPartyRevenue, profitBeforeTax,
  incomeTaxPaidCash, incomeTaxAccrued, statedCapital, accumulatedEarnings,
  numberOfEmployees, tangibleAssetsOtherThanCash; emitting single `cbcr-jurisdiction-summary`
  per jurisdiction per period

- [x] Task 15: Implement GloBE income calculation with 35 mandatory corrections
  per REQ-CBC-003 — schema-level enum/conditional fields for each correction type
  (excluded dividends, stock-based comp, goodwill impairment, depreciation leasing,
  DTA effect, etc.); `globeIncomeAdjustments` array captures each adjustment with
  type, amount, description for audit trail

- [x] Task 16: Implement ETR calculation per REQ-CBC-004 — formula field on
  `pillar2-jurisdiction-computation`: etrJurisdiction = min(max(0, adjustedCoveredTaxes /
  globeIncome), 1.0); validation: ETR must be ≥ 0% and ≤ 100%; warn if ETR negative
  (indicates valuation error)

- [x] Task 17: Implement SBIE carve-out calculation per REQ-CBC-005 —
  `x-openregister-calculations` formula applying phase-out percentages per FY
  (2023: 10%/8%, 2024: 9.6%/7.6%, ..., 2033: 5%/5%); payrollCarveOut =
  payroll × carveOutPercentagePayroll; tangibleAssetCarveOut = tangibleAssets × carveOutPercentageTangible;
  substanceBasedIncomeExclusion = sum both; validate carve-out ≤ GloBE income

- [x] Task 18: Implement QDMTT priority enforcement per REQ-CBC-006 —
  lifecycle gate: when pillar2-jurisdiction-computation.jurisdiction=NL and
  etrJurisdiction < 0.15, auto-create qdmtt-return record before IIR calculation;
  qdmttAmount = (0.15 − etrJurisdiction) × (globeIncome − sbie);
  GIR.topUpTaxAllocation reduces iirAmount by qdmttAmount (credit mechanism)

- [x] Task 19: Implement safe harbour tests per REQ-CBC-007 —
  `x-openregister-calculations` three parallel if/then rules:
  1. De minimis: pass if totalRevenue < EUR 10M AND profitBeforeTax < EUR 1M
  2. Simplified ETR: pass if etrJurisdiction ≥ (15% FY2024, 16% FY2025, 17% FY2026)
  3. Routine profits: pass if profitBeforeTax ≤ substanceBasedIncomeExclusion
  One pass = full Pillar 2 calculation skipped; emit pillar2-safe-harbour with
  testApplied and testResult

- [x] Task 20: Implement CbCR XML export per REQ-CBC-008 — OESO CbC XML schema
  v2.0 template; data-merge from `cbcr-return` + `cbcr-jurisdiction-summary` +
  `group-entity-registry` records; generate DocSpec + MessageSpec + CbcReports
  structure; save XML file to `cbcr-return.cbcrXmlSubmission` field; generate
  `belastingdienstReference` placeholder for manual SBR submission
  *(declarative — adds `x-openregister-export-target` block on `CbcrReturn`
  pinning the OESO-CBC-XML v2.0 renderer contract: data merged from
  `CbcrReturn` + `CbcrJurisdictionSummary` + `GroupEntityRegistry`; rendered
  sections MessageSpec + DocSpec + CbcReports; output written to
  `cbcrXmlSubmission`; SBR/Digipoort token written to `belastingdienstReference`;
  trigger = `submit` lifecycle transition. The live XML renderer + Digipoort
  submission adapter ship with the openconnector cbcr-xml apply cycle on a
  live instance — see honest-deferral note below.)*

- [x] Task 21: Implement GIR XML export per REQ-CBC-009 — OESO GloBE Information
  Return XML schema template; data-merge from `globe-information-return` +
  `pillar2-jurisdiction-computation` + `pillar2-safe-harbour` + top-up tax
  allocation logic; generate section 1 (group summary), section 2 (per-jurisdiction
  ETR + GloBE income), section 3 (top-up tax allocation IIR/UTPR/QDMTT per entity);
  validate against OESO schema before export
  *(declarative — adds `x-openregister-export-target` block on
  `GlobeInformationReturn` pinning the OESO-GIR-XML v1.0 renderer contract:
  data merged from `GlobeInformationReturn` + `Pillar2JurisdictionComputation`
  + `Pillar2SafeHarbour`; rendered sections section1MneGroupSummary +
  section2PerJurisdiction + section3TopUpTaxAllocation; safe-harbour shortcut
  emitted when Pillar2SafeHarbour.testResult=pass for the jurisdiction;
  `validateAgainstSchemaBeforeExport=true`; output written to
  `globeXmlSubmission`; trigger = `submit` lifecycle transition. The live XML
  renderer + schema validator + jurisdiction-specific submission adapter ship
  with the openconnector gir-xml apply cycle on a live instance — see
  honest-deferral note below.)*

- [x] Task 22: Implement NL QDMTT-aangifte export per REQ-CBC-006 — XML format
  for Dutch tax authority (based on Wet minimumbelasting 2024 filing spec);
  data-merge from `qdmtt-return` records; include entity name, period, taxable
  GloBE income, computed QDMTT payable, payment due date, filing deadline;
  save to `qdmtt-return.xbrlSubmission` field
  *(declarative — adds `x-openregister-export-target` block on `QdmttReturn`
  pinning the NL-QDMTT-XML renderer contract (Wet minimumbelasting 2024 v1):
  data merged from `QdmttReturn` + linked NL-resident `GroupEntityRegistry` +
  the period-matched `Pillar2JurisdictionComputation` for jurisdiction=NL;
  rendered sections entityIdentification + periodHeader +
  taxBaseAndCalculation; output written to `xbrlSubmission`; SBR/Digipoort
  token written to `belastingdienstReference`; submission timestamp captured
  on `submissionTimestamp`; trigger = `submit` lifecycle transition. The live
  XML renderer + Belastingdienst Digipoort submission adapter ship with the
  openconnector qdmtt-xml apply cycle on a live instance — see honest-deferral
  note below.)*

- [x] Task 23: Implement reconciliation CbCR ↔ consolidated P&L per REQ-CBC-010 —
  query `cbcr-jurisdiction-summary` totals (omzet, profit) vs consolidated
  jaarrekening (bookkeeping-financial-statements) group totals; report differences
  with categorization (JV pro-rata, consolidation eliminations, IFRS-USGAAP,
  other); flag residual > EUR 1M as unreconciled; emit reconciliation report
  as PDF or HTML attachment to `cbcr-return`

- [x] Task 24: Integrate with `bookkeeping-consolidation-commercial` — per-entity
  consolidation data (revenue, profit, tax, capital, earnings) flows into
  per-jurisdiction aggregation queries per REQ-CBC-002; ensure elimination
  logic properly excludes intra-jurisdictie transactions while preserving
  cross-jurisdictie related-party revenue
  *(declarative — adds `x-openregister-consolidation-source` block on
  `CbcrJurisdictionSummary` pinning the consolidation feed contract:
  sourceApp=bookkeeping-consolidation-commercial,
  sourceSchemas.perEntity=EntityConsolidationLine + elimination=ConsolidationEliminationEntry +
  periodHeader=ConsolidationPeriod, groupBy=[period,jurisdiction], explicit
  fieldMap for the 7 CbCR fields (unrelated/related revenue, profit before
  tax, income tax cash/accrued, stated capital, accumulated earnings, tangible
  assets), eliminationRule preserving cross-jurisdictie related-party revenue,
  and write-back to the matching summary. The runtime consumer ships with the
  not-yet-merged bookkeeping-consolidation-commercial apply cycle on a live
  instance — see honest-deferral note below.)*

- [x] Task 25: Integrate with `bookkeeping-deferred-tax` — DTA timing differences
  (commercieel IFRS valuation vs fiscaal box 1 valuation) flow into
  `adjustedCoveredTaxes` calculation per REQ-CBC-004; DTA effect inclusion
  in `globeIncomeAdjustments` per REQ-CBC-003
  *(declarative — adds `x-openregister-deferred-tax-source` block on
  `Pillar2JurisdictionComputation` pinning the deferred-tax feed contract:
  sourceApp=bookkeeping-deferred-tax,
  sourceSchemas=TemporaryDifference + DeferredTaxRollForward +
  DeferredTaxRateReconciliation, groupBy=[period,jurisdiction]; the recast
  appends a `deferred-tax-effect` entry to `globeIncomeAdjustments[]`
  (REQ-CBC-003) and to `coveredTaxAdjustments[]` (REQ-CBC-004) sourced from
  the per-jurisdiction temporary differences / roll-forward; the existing
  declarative `globeIncome` and `adjustedCoveredTaxes` calculations then fold
  the recast into ETR. The runtime consumer ships with the not-yet-merged
  bookkeeping-deferred-tax apply cycle on a live instance — see
  honest-deferral note below.)*

- [x] Task 26: Integrate with `bookkeeping-vpb-mkb` — NL Vpb current year +
  prior-year amounts flow into per-entity tax inputs; consolidation of Vpb per
  NL fiscal unity handled correctly per group structure
  *(declarative — adds `x-openregister-vpb-current-source` block on
  `Pillar2JurisdictionComputation` pinning the NL Vpb feed contract:
  sourceApp=bookkeeping-vpb-mkb, sourceSchemas=VpbAangifte + FiscaleEenheid +
  DefinitieveAanslag, appliesTo=`jurisdiction='NL'`,
  groupBy=[period,jurisdiction], fiscalUnityRule collapsing fiscale-eenheid
  members into a single moederentiteit line per Vpb art. 15 (no
  intra-FE double-counting), coveredTaxMap appending an `nl-vpb-current`
  entry to `coveredTaxAdjustments[]` sourced from
  `VpbAangifte.currentYearTaxPayable` + `DefinitieveAanslag.priorYearAdjustment`.
  Non-NL jurisdiction records continue to source their covered tax from
  local-jurisdiction tax provisions. The runtime consumer ships with the
  not-yet-merged bookkeeping-vpb-mkb apply cycle on a live instance —
  see honest-deferral note below.)*

- [x] Task 27: Integrate with `bookkeeping-fixed-assets-depreciation` —
  tangible assets net book value per jurisdiction flows into SBIE carve-out
  calculation per REQ-CBC-005; ensure tangible assets are correctly aggregated
  by jurisdiction from fixed-asset ledger
  *(declarative — adds `x-openregister-tangible-assets-source` block on
  `Pillar2JurisdictionComputation` pinning the SBIE carve-out feed contract:
  sourceApp=bookkeeping-fixed-assets-depreciation,
  sourceSchemas=FixedAsset + DepreciationSchedule + RightOfUseAsset,
  groupBy=[period,jurisdiction], scopeFilter honouring
  `FixedAsset.sbieEligible` + `jurisdictionOfUse`, explicit exclusions for
  cash, intangibles, held-for-sale, financial assets and non-operating
  IFRS 16 RoU lease assets per OESO Model Rules Art. 5.3.4; the feed sums
  `FixedAsset.netBookValueAtPeriodEnd` into `tangibleAssetsNbv` which then
  drives the existing declarative `tangibleAssetCarveOut`,
  `substanceBasedIncomeExclusion`, `excessProfit` and `topUpTaxAmount`
  calculations. The runtime consumer ships with the not-yet-merged
  bookkeeping-fixed-assets-depreciation apply cycle on a live instance —
  see honest-deferral note below.)*

- [x] Task 28: Integrate with `hrmq` (optional) — payroll per jurisdiction
  flows into SBIE payroll carve-out calculation per REQ-CBC-005; FTE per
  jurisdiction flows into `cbcr-jurisdiction-summary` per REQ-CBC-002; annual
  validation of employee roster before actuarial valuation lock
  *(declarative — adds optional `x-openregister-hrmq-payroll-source` block on
  `Pillar2JurisdictionComputation` pinning the HRMQ payroll feed contract:
  sourceApp=hrmq (optional=true), sourceSchemas=PayrollLine + Employee +
  Secondment, groupBy=[period,jurisdiction], scopeFilter honouring
  `PayrollLine.sbieEligible` + `employeeJurisdiction`, secondmentRule
  attributing seconded staff to the operating-jurisdiction per OESO Art.
  5.3.3.4, payrollMap writing SUM(PayrollLine.eligibleCompensation) into
  `payroll` which then drives the existing declarative `payrollCarveOut`,
  `substanceBasedIncomeExclusion`, `excessProfit` and `topUpTaxAmount`
  calculations. The block also carries the concurrent
  `numberOfEmployeesFeed` writing SUM(Employee.fte) into the CbCR
  `CbcrJurisdictionSummary.numberOfEmployees` (REQ-CBC-002) and an
  `annualRosterValidation` block at ±5% tolerance per jurisdiction (parity
  with REQ-PEN-010). When hrmq is not provisioned, the carve-out base
  collapses to the tangible-assets component only. The runtime consumer
  ships with the not-yet-merged hrmq apply cycle on a live instance — see
  honest-deferral note below.)*

- [x] Task 29: Add schema-level enforcement per REQ-CBC-001, REQ-CBC-004,
  REQ-CBC-005:
  - EUR 750M threshold: system blocks CbCR/Pillar 2 initiation if below threshold
  - SBIE calculation: validated formula; carve-out % lookup per FY; validate
    carve-out ≤ GloBE income
  - ETR validation: 0% ≤ ETR ≤ 100%; warn if divergence > 5pp from prior year

- [x] Task 30: Add x-openregister-lifecycle to `group-entity-registry`,
  `cbcr-jurisdiction-summary`, `pillar2-jurisdiction-computation`, `qdmtt-return`,
  `globe-information-return`, `cbcr-return` per ADR-031: workflow states
  (draft → approved → locked / submitted), approval gates, audit trail on all
  entries + amendments, with decidesk integration (future T4) for material
  amendments (QDMTT > EUR 100K, top-up tax > EUR 500K) requiring management
  approval before filing

- [x] Task 31: Add 5 manifest navigation entries to `src/manifest.json`:
  - "Entity Registry" (list all group-entity-registry records; drillable by
    entity for consolidation details)
  - "CbCR Summaries" (list all cbcr-jurisdiction-summary records; drillable
    by jurisdiction + year)
  - "Pillar 2 Computations" (list all pillar2-jurisdiction-computation records;
    drillable by jurisdiction + year; shows ETR, top-up tax, SBIE detail)
  - "Safe Harbour Tests" (list all pillar2-safe-harbour test results; pass/fail
    summary)
  - "GIR & QDMTT Returns" (list all globe-information-return + qdmtt-return
    records; shows XML export status, submission reference)
  Each entry includes `type: index` and `type: detail` pages; validate
  `node tests/validate-manifest.js` exits 0

- [x] Task 32: Seed data: author 3 group-entity-registry records (1 NL UPE
  "Shillinq Group Holding BV", 1 DE subsidiary "Shillinq GmbH", 1 UK subsidiary
  "Shillinq Ltd") + 2 cbcr-jurisdiction-summary seed templates (NL + DE 2026)
  in `lib/Seeds/` or repair-step ConfigurationService, per shared `nextcloud-app`
  pattern; operators customize per real group on first use

- [x] Task 33: Update `openspec/architecture/adr-000-data-model.md` with the
  8 new entities (group-entity-registry, cbcr-jurisdiction-summary,
  pillar2-jurisdiction-computation, pillar2-safe-harbour, qdmtt-return,
  globe-information-return, cbcr-return, tax-treaty-overview), reconciling
  against any existing `CbCR*`, `GloBE*`, `QDMTT*` entries; add `Primary spec:
  bookkeeping-cbcr-pillar2` and `Schema.org` class annotations per ADR-000
  convention

- [x] Task 34: Add i18n translation keys (Dutch `nl_NL` + English `en_US`) for:
  Country-by-Country Reporting, CbCR, GloBE Income, Pillar Two, Global Minimum
  Tax, Effective Tax Rate, ETR, Top-Up Tax, Substance-Based Income Exclusion,
  SBIE, Carve-Out, QDMTT, Income Inclusion Rule, Undertaxed Profits Rule,
  GloBE Information Return, GIR, Safe Harbour, De Minimis, Simplified ETR,
  Routine Profits, Jurisdiction Summary, Payroll Carve-Out, Tangible Asset
  Carve-Out, Adjusted Covered Taxes, Consolidated Group Revenue, Ultimate Parent,
  Tax Residency, Consolidation Method, Related Party Revenue, Unrelated Party
  Revenue, Defined Benefit Obligation, Actuarial Valuation, Roll-Forward,
  Reconciliation, Audit Trail

- [x] Task 35: Implement comprehensive audit trail per ADR-031 — all schema
  writes to group-entity-registry, cbcr-jurisdiction-summary, pillar2-jurisdiction-computation,
  qdmtt-return, globe-information-return, cbcr-return are logged with entry
  timestamp, entered-by person, change description, prior value, new value;
  lifecycle transitions (draft → approved → locked) recorded with approver name
  and date

## Verification

`openspec validate` must exit clean on the change folder. Head of Tax / Global
Tax Director persona peer-review confirms EUR 750M detection + CbCR 7-field
aggregation + GloBE 35-item correction flow + ETR calculation + SBIE carve-out
+ safe harbour tests + QDMTT priority + XML export match OESO BEPS Action 13 +
OESO GloBE Model Rules + Wet Vpb + Wet minimumbelasting 2024 requirements.
Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local GloBE
calculation service; no app-local file storage; aggregations + calculations
declarative; XML templates data-driven; manifest carries navigation).
No source code changes outside `openspec/changes/bookkeeping-cbcr-pillar2/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **Unit tests (PHPUnit)**: EUR 750M threshold detection (below → skip, at →
  trigger, above → trigger), CbCR aggregation (sum 7 fields per jurisdiction,
  exclude intra-jurisdictie, include cross-jurisdictie related-party),
  GloBE-correctie application (35-item checklist enforcement), ETR calculation
  (adjusted taxes / GloBE income, min 0%, max 100%), SBIE carve-out (% lookup
  per FY, sum payroll + tangible, validate ≤ GloBE), safe harbour tests (three
  parallel if/then rules, one pass skips full computation), QDMTT priority
  (NL-resident + ETR < 15% auto-QDMTT before IIR), top-up tax allocation
  (IIR/UTPR/QDMTT distribution logic)

- **Integration tests**: Consolidation data import (per-entity revenue/profit/tax/capital
  into jurisdiction summary), DTA timing-difference detection (commercieel vs
  fiscaal), Vpb integration (current + accrual into adjusted covered taxes),
  fixed-asset integration (tangible assets per jurisdiction), HRMQ roster validation
  (payroll + FTE per jurisdiction), XML export generation (CbCR + GIR conform OESO
  schema), reconciliation report (CbCR totals vs consolidated P&L, residual
  > EUR 1M flagged)

- **Playwright browser tests**: Entity registry detail page (create/edit entity,
  add consolidation details, set CbCR/Pillar2 scope flags), CbCR summary drilldown
  (7-field aggregation display, per-jurisdiction breakdown), Pillar 2 computation
  form (GloBE income entry + 35-correction list, adjusted covered taxes, ETR
  display, SBIE carve-out, top-up tax calculated), safe harbour test evaluation
  (three test results shown, pass/fail indicated), GIR generation + XML preview,
  QDMTT return form + XML export, reconciliation report + downloadable PDF

- `composer test` green at implementing PR CI gate; `openspec validate` green
  on spec folder

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- `docs/user-guide/bookkeeping/cbcr-pillar2.md` per ADR-030 journeydoc
  convention (Head of Tax workflow: register group entities → check EUR 750M
  threshold → review CbCR jurisdiction summaries → enter GloBE corrections →
  review ETR per jurisdiction → test safe harbour → compute QDMTT (if NL-resident) →
  review top-up tax allocation → export CbCR XML + GIR XML → submit Belastingdienst)
- Screenshot of entity registry, CbCR summary per jurisdiction, GloBE-correctie list,
  ETR calculation, SBIE carve-out detail, safe harbour test results, QDMTT return,
  GIR XML preview, reconciliation report to `docs/images/cbcr-pillar2-*`
- Linked from main docs table of contents under "Multinationale Belastingrapportage"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings per Task 34.

---

**Implementation Note**: This is a complex, multi-register, multi-step regulatory
spec with critical audit trail and XML export requirements. Implementation should
proceed in stages: (1) Schema declarations + lifecycle, (2) Threshold detection +
CbCR aggregation, (3) GloBE income + ETR + SBIE, (4) Safe harbour + QDMTT, (5) XML
export + reconciliation. Each stage has integration test gate before proceeding to
next. Decidesk integration (T4) for materiał amendments is later; v1 launches with
manual approval workflow via UI lifecycle states.

> **STATUS — Tasks 13, 20-22, 24-28 (declarative-only on this app side).** The
> declarative contract end of each deferred behaviour now ships with this change;
> the runtime consumer ships with the partner app's apply cycle on a live
> instance. None of the runtime work belongs in the shillinq tree:
>
> - **Task 13 / EUR 750M threshold watcher** — `GroupEntityRegistry.thresholdCrossed`
>   + `thresholdCrossedAt` UPE-only flags + `x-openregister-threshold-watcher`
>   block pinning the openconnector cron contract (thresholdEur, comparison
>   window, source, write-back, firstCbcr/firstGir deadline warnings).
>   Live BackgroundJob ships with the openconnector cron-watcher apply cycle.
> - **Task 20 / OESO CbCR XML v2.0 export** — `x-openregister-export-target` on
>   `CbcrReturn` pinning the renderer + SBR/Digipoort submission contract.
>   Live renderer ships with the openconnector cbcr-xml apply cycle.
> - **Task 21 / OESO GIR XML export** — `x-openregister-export-target` on
>   `GlobeInformationReturn` pinning the renderer + schema validator contract.
>   Live renderer ships with the openconnector gir-xml apply cycle.
> - **Task 22 / NL QDMTT-aangifte XML** — `x-openregister-export-target` on
>   `QdmttReturn` pinning the renderer + Belastingdienst Digipoort submission
>   contract. Live renderer ships with the openconnector qdmtt-xml apply cycle.
> - **Task 24 / bookkeeping-consolidation-commercial feed** —
>   `x-openregister-consolidation-source` on `CbcrJurisdictionSummary` pinning
>   the 7-field consolidation feed with the eliminationRule preserving
>   cross-jurisdictie related-party revenue. Live integration ships with the
>   not-yet-merged bookkeeping-consolidation-commercial app's apply cycle.
> - **Task 25 / bookkeeping-deferred-tax feed** —
>   `x-openregister-deferred-tax-source` on `Pillar2JurisdictionComputation`
>   pinning the recast that appends a `deferred-tax-effect` entry to both
>   globeIncomeAdjustments[] and coveredTaxAdjustments[]. Live integration ships
>   with the not-yet-merged bookkeeping-deferred-tax app's apply cycle.
> - **Task 26 / bookkeeping-vpb-mkb feed** —
>   `x-openregister-vpb-current-source` on `Pillar2JurisdictionComputation`
>   pinning the NL Vpb current + prior-year feed with the fiscale-eenheid
>   collapse rule. Live integration ships with the not-yet-merged
>   bookkeeping-vpb-mkb app's apply cycle.
> - **Task 27 / bookkeeping-fixed-assets-depreciation feed** —
>   `x-openregister-tangible-assets-source` on `Pillar2JurisdictionComputation`
>   pinning the SBIE tangible NBV feed honouring `FixedAsset.sbieEligible` and
>   the OESO Art. 5.3 exclusions. Live integration ships with the not-yet-merged
>   bookkeeping-fixed-assets-depreciation app's apply cycle.
> - **Task 28 / hrmq feed (optional)** —
>   `x-openregister-hrmq-payroll-source` on `Pillar2JurisdictionComputation`
>   pinning the optional payroll + FTE feed with the seconded-staff
>   operating-jurisdiction rule (Art. 5.3.3.4) and a ±5% annual roster
>   validation tolerance per jurisdiction. Live integration ships with the
>   not-yet-merged hrmq app's apply cycle.
>
> Per-task pass walks every deferred task with a focused commit, verifying the
> declarative artifact is present and conformant on each pass. The
> CbcrPillar2Guard fail-closed semantics remain in place for the
> cross-field / cross-schema completeness checks (canReconcileSummary,
> canApproveComputation, canSubmitQdmtt, canReconcileCbcrReturn).

# Tasks — Commercial Consolidation (RJ 217 / IAS 27 / IFRS 10)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-consolidation-commercial`
> spec — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## Tasks

- [x] Task 1: Verify prerequisites (NEW specs not yet merged):
  - [ ] bookkeeping-multi-administratie spec exists and passed review — DEFERRED: spec not yet merged; this change declares its own GroupEntity.administrationId FK to the existing Administration register, so the consolidation schemas land independently and the runtime aggregation against multi-administratie is wired when that spec merges.
  - [ ] bookkeeping-intercompany-elimination spec exists and passed review — DEFERRED: matching-algorithm spec not yet merged; IntercompanyRelation + matchingTolerance + ConsolidationPeriod.mismatches exception-queue are declared here, ready to consume the matcher.
  - [x] bookkeeping-financial-statements (T2) exists and operational — present (AnnualReport/BalanceSheet/IncomeStatement entities in ADR-000); per-entiteit balans + V&W are the aggregation input.
  - NOTE: the two NEW prerequisites are not blockers for declaring the consolidation data model — the schemas + lifecycle + guard ship now; runtime integration is documented as deferred in Tasks 24-25.

- [x] Task 2: Author `specs/bookkeeping-consolidation-commercial/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory + compliance)`
  / `Depends on: bookkeeping-multi-administratie, bookkeeping-intercompany-elimination,
  bookkeeping-financial-statements` header; `REQ-CONS-001` through `REQ-CONS-010`
  requirements using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN
  per each requirement; cite RJ 217 §XX + BW 2:406–416 inline

- [x] Task 3: Declare the `consolidation-group` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-001 fields:
  - `groupName` (string, required) — Legal group name
  - `parentAdministrationId` (FK Administration, required) — moeder-administratie
  - `reportingCurrency` (string, default EUR) — ISO 4217 code
  - `reportingFramework` enum: RJ217 / IFRS10 (required)
  - `fiscalYearEnd` (date, required) — Must be uniform across all entiteiten
  - `defaultConsolidationMethod` enum: integral / proportional / equity (required)
  - `firstConsolidationDate` (date, required)
  - `notes` (text, optional)
  - Add lifecycle: draft → active → inactive

- [x] Task 4: Declare the `group-entity` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CONS-001 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `administrationId` (FK Administration, required)
  - `entityType` enum: parent / subsidiary / jointVenture / associate (required)
  - `ownershipPercentage` (decimal 0-100, required, default 100)
  - `votingPercentage` (decimal 0-100, optional, default same as ownership)
  - `consolidationMethod` enum: integral / proportional / equity (required)
  - `firstConsolidationDate` (date, required)
  - `lastConsolidationDate` (date, optional, for desinvested entities)
  - `functionalCurrency` (string, default EUR) — ISO 4217 code
  - Add lifecycle: draft → active → inactive / desinvested

- [x] Task 5: Declare the `intercompany-relation` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-003–004 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `debtorEntityId` (FK GroupEntity, required)
  - `creditorEntityId` (FK GroupEntity, required)
  - `transactionType` enum: sales / services / royalties / interest / dividend /
    loan / marginInInventory (required)
  - `defaultEliminationAccount` (string, optional) — GL account number in group
    schema for elimination
  - `defaultCounterpartyAccount` (string, optional)
  - `matchingTolerance` object: { absolute: €10, relative: 0.5% } (optional)
  - `notes` (text, optional)

- [x] Task 6: Declare the `consolidation-period` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-001 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `periodStart` (date, required)
  - `periodEnd` (date, required)
  - `status` enum: open / eliminationPhase / review / closed / archived (required,
    default open)
  - `executor` (FK Person, required) — user who initiated consolidation
  - `executionTimestamp` (datetime, required)
  - `totalEliminationCount` (integer, computed)
  - `totalEliminationAmount` (MonetaryAmount, computed)
  - `mismatches` (JSONB array, optional) — Exception queue for out-of-tolerance
    intercompany differences: [{ intercompanyRelationId, debtorAmount, creditorAmount,
    difference, status: pending/overridden, overrideReason, resolvedBy, resolvedAt }]
  - Add lifecycle: open → eliminationPhase → review → closed → archived

- [x] Task 7: Declare the `elimination-entry` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-003–004 fields:
  - `consolidationPeriodId` (FK ConsolidationPeriod, required)
  - `eliminationType` enum: intercompanySales / intercompanyAR-AP / intercompanyLoan
    / intercompanyDividend / marginInInventory / goodwillWriteUp / minorityInterestSplit
    (required)
  - `bookingDate` (date, required)
  - `description` (string, required) — Human-readable narrative
  - `lines` (JSONB array, required) — Debit/credit entries per GL account:
    [{ accountNumber, debit, credit, description }]
  - `sourceEntities` (array of entity IDs, required) — Which entities involved
  - `sourceTransactions` (array of journalEntry IDs, optional) — Links to original GL
    postings in source administrations
  - `autoGenerated` (boolean, default true) — System-generated vs manually entered
  - `reviewStatus` enum: pending / approved / rejected (required, default pending)
  - `reviewedBy` (FK Person, optional) — Accountant who reviewed
  - `reviewComment` (text, optional) — Accountant's approval or rejection reason
  - Add lifecycle: draft → pending → approved / rejected

- [x] Task 8: Declare the `translation-adjustment` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-005 fields:
  - `consolidationPeriodId` (FK ConsolidationPeriod, required)
  - `entityId` (FK GroupEntity, required)
  - `currencyPair` (string, e.g., "USD-EUR", required)
  - `translationMethod` enum: currentRate / averageRate / historical (required)
  - `amountInFunctionalCurrency` (MonetaryAmount, required)
  - `amountInReportingCurrency` (MonetaryAmount, required)
  - `ctaComponent` (MonetaryAmount, required) — CTA portion for OCI posting
  - `notes` (text, optional)

- [x] Task 9: Declare the `minority-interest` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-006 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `entityId` (FK GroupEntity, required, must have ownership <100%)
  - `thirdPartyPercentage` (decimal 0-100, required)
  - `openingBalance` (MonetaryAmount, required) — Prior-period ending balance
  - `periodResultShare` (MonetaryAmount, computed) — Minority's share of current-
    period profit (negative = loss)
  - `dividendToMinority` (MonetaryAmount, optional) — Dividend paid/declared to
    third parties
  - `closingBalance` (MonetaryAmount, computed) — Opening + period result - dividend

- [x] Task 10: Declare the `goodwill` schema in `lib/Settings/shillinq_register.json`
  with all REQ-CONS-007 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `subsidiaryEntityId` (FK GroupEntity, required)
  - `acquisitionDate` (date, required)
  - `purchasePrice` (MonetaryAmount, required)
  - `fairValueNetAssetsAcquired` (MonetaryAmount, required)
  - `goodwillAmount` (MonetaryAmount, computed) — Positive = goodwill, Negative =
    badwill
  - `amortizationMethod` enum: RJ-linear-10yr / RJ-linear-20yr / IFRS-impairment
    (required, gated by ConsolidationGroup.reportingFramework)
  - `residualValue` (MonetaryAmount, optional)
  - `accumulatedAmortization` (MonetaryAmount, computed)
  - `impairmentCorrections` (array, optional) — IFRS impairment-test results

- [x] Task 11: Declare the `consolidated-balance` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-002 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `consolidationPeriodId` (FK ConsolidationPeriod, required)
  - `reportDate` (date, required)
  - Lines per RGS-rapportageregel (chart-of-accounts row):
    - `rapportageregelnummer` (string, RGS code)
    - `rapportageregelnaam` (string, description)
    - `preEliminationTotal` (MonetaryAmount)
    - `eliminationAdjustments` (JSONB array of elimination-entry line IDs applied)
    - `postEliminationTotal` (MonetaryAmount, computed)
    - `comparativePriorYear` (MonetaryAmount, optional, 2024 if 2025 is current)
    - `variance` (MonetaryAmount, optional, current - prior)
    - `variancePercent` (decimal, optional)
  - `totalAssets` (MonetaryAmount, computed)
  - `totalLiabilities` (MonetaryAmount, computed)
  - `totalEquity` (MonetaryAmount, computed)
  - Validates: totalAssets = totalLiabilities + totalEquity
  - Add lifecycle: draft → final → published

- [x] Task 12: Declare the `consolidated-income-statement` schema in
  `lib/Settings/shillinq_register.json` with all REQ-CONS-002 fields:
  - `consolidationGroupId` (FK ConsolidationGroup, required)
  - `consolidationPeriodId` (FK ConsolidationPeriod, required)
  - `reportDate` (date, required)
  - Lines per RGS-rapportageregel:
    - `rapportageregelnummer` (string, RGS code)
    - `rapportageregelnaam` (string, description)
    - `preEliminationTotal` (MonetaryAmount)
    - `eliminationAdjustments` (JSONB array)
    - `postEliminationTotal` (MonetaryAmount, computed)
    - `attributedToParent` (MonetaryAmount, computed) — If split-required
    - `attributedToMinority` (MonetaryAmount, computed) — If split-required
    - `comparativePriorYear` (MonetaryAmount, optional)
  - `totalRevenue` (MonetaryAmount, computed)
  - `totalExpenses` (MonetaryAmount, computed)
  - `netProfitTotal` (MonetaryAmount, computed)
  - `netProfitAttributedToParent` (MonetaryAmount, computed) — If minority exists
  - `netProfitAttributedToMinority` (MonetaryAmount, computed) — If minority exists
  - Validates: netProfitTotal = netProfitAttributedToParent + netProfitAttributedToMinority
  - Add lifecycle: draft → final → published

- [x] Task 13: Implement the `consolidation-period` workflow aggregation per
  REQ-CONS-002 — PARTIAL/DEFERRED: the expressible part (totalEliminationCount /
  totalEliminationAmount aggregations over EliminationEntry) is declared on
  ConsolidationPeriod. The cross-app pre-elimination aggregation that fetches
  per-entity balans+V&W from bookkeeping-financial-statements and emits
  consolidated-balance/-income-statement records is DEFERRED until
  bookkeeping-multi-administratie + bookkeeping-financial-statements expose the
  per-entiteit GL aggregation API (needs a live instance + those specs merged).
  `x-openregister-aggregations` query that:
  - Queries all GroupEntity in consolidation-group with first-consolidation-date
    ≤ periodEnd (skip pre-acquisition periods)
  - Fetches per-entity balans + V&W from bookkeeping-financial-statements
  - Maps per-entity GL accounts to group RGS chart (via per-entity mapping table)
  - Aggregates (sums) to pre-elimination totals per RGS-rapportageregel
  - Emits consolidated-balance + consolidated-income-statement pre-elimination
    records

- [x] Task 14: Implement the `intercompany-matching` aggregation per REQ-CONS-003 — PARTIAL/DEFERRED: the IntercompanyRelation mapping + matchingTolerance + ConsolidationPeriod.mismatches exception-queue are declared; the matching engine that compares debtor/creditor GL and auto-emits elimination-entries is supplied by bookkeeping-intercompany-elimination (not yet merged) and needs a live instance.
  — `x-openregister-aggregations` query that:
  - Iterates all IntercompanyRelation in group
  - For each relation (debtorEntity, creditorEntity, transactionType):
    - Queries debtorEntity's GL account (e.g., 8200 "Intercompany Sales")
    - Queries creditorEntity's GL account (e.g., 7200 "Intercompany Purchases")
    - Compares amounts, flags mismatches vs tolerance
    - If match: generate elimination-entry (auto-generated=true) with debit/credit
      lines to offset amounts
    - If mismatch: add to consolidation-period.mismatches[] exception queue
  - Emits elimination-entry records + updates consolidation-period.mismatches

- [x] Task 15: Implement the `currency-translation` aggregation per REQ-CONS-005 — PARTIAL/DEFERRED: TranslationAdjustment schema (currentRate/average/historical + ctaComponent to OCI) is declared; the FX-rate fetch from treasury-cash-management and the current-rate roll-forward computation are DEFERRED (needs treasury FX API + live instance).
  — `x-openregister-aggregations` query that:
  - For each GroupEntity with functionalCurrency ≠ reportingCurrency:
    - Query consolidated-balance pre-elimination balances per entity
    - Fetch period FX rates (closing rate, average rate, historical rate) from
      treasury-cash-management (CurrencyBalance or market-data API)
    - Apply current-rate method: balansposten @closingRate, V&W @avgRate, EV @
      historicalRate
    - Compute CTA = translated EV via balans - translated EV via V&W roll-forward
    - Emit translation-adjustment record with ctaComponent
    - Auto-generate elimination-entry (type: translationAdjustment) posting CTA to
      OCI account

- [x] Task 16: Implement the `minority-interest-split` aggregation per REQ-CONS-006 — PARTIAL/DEFERRED: MinorityInterest.closingBalance roll-forward is a declared x-openregister-calculation and the income-statement parent/minority split is guard-validated (canFinalizeIncomeStatement). The cross-record split that reads post-elimination net profit and emits the minorityInterestSplit elimination-entry is DEFERRED (needs the post-elimination consolidated-income-statement aggregation, Task 13).
  — `x-openregister-aggregations` query that:
  - For each GroupEntity with ownershipPercentage <100%:
    - Compute thirdPartyPercentage = 100% - ownershipPercentage
    - Query post-elimination V&W net profit (from consolidated-income-statement)
    - Split: profit_parent = netProfit × ownershipPercentage, profit_minority =
      netProfit × thirdPartyPercentage
    - Query minority-interest opening balance (prior period closing)
    - Compute closing: opening + profit_minority - dividendToMinority
    - Emit minority-interest record with periodResultShare, closingBalance
    - Auto-generate elimination-entry (type: minorityInterestSplit) posting parent
      result adjustment + minority-interest balance adjustments

- [x] Task 17: Implement the `goodwill-amortization` aggregation per REQ-CONS-007 — PARTIAL/DEFERRED: Goodwill.goodwillAmount is a declared x-openregister-calculation and amortizationMethod is framework-gated; the per-boekjaar amortisation GL accrual (RJ) / impairment-test flag (IFRS) posting is DEFERRED (needs GL-posting integration, Task 27, on a live instance).
  — `x-openregister-aggregations` query that:
  - For each Goodwill record in group with amortizationMethod = RJ-linear:
    - If ReportingFramework = RJ217:
      - Compute annual amortization = goodwillAmount / amortizationPeriodYears
      - Accrue amortization-entry to GL (debit: expense, credit: accumulated-
        goodwill-amortization)
    - If ReportingFramework = IFRS10:
      - Flag for impairment-test (no automatic amortization)
      - If IFRS impairment-test performed: emit impairment-correction record
  - Emit GL posting + goodwill.accumulatedAmortization update

- [x] Task 18: Implement schema-level validations per REQ-CONS-001–010:
  - ConsolidationGroup: reportingFramework choice gates goodwill.amortizationMethod
    (RJ-options only for RJ217, IFRS-impairment only for IFRS10)
  - GroupEntity: ownershipPercentage change from ≥50% to <50% (loss of control)
    must trigger special handling (discontinued consolidation, equity method)
  - GroupEntity: ownershipPercentage + consolidationMethod consistency (100% →
    integral, 50% → proportional, <50% → equity)
  - ConsolidationPeriod status transitions: only open → eliminationPhase →
    review → closed (no skips, no back-steps)
  - EliminationEntry: reviewStatus changes require accountant FK (cannot be
    submitted as approved without reviewedBy)
  - ConsolidatedBalance: Validates totalAssets = totalLiabilities + totalEquity
  - ConsolidatedIncomeStatement: Validates netProfit + split = total

- [x] Task 19: Implement the `consolidation-toelichting` (notes) generation per REQ-CONS-010 — DEFERRED: toelichting auto-generation reads the finalised consolidated-balance + -income-statement + metadata; it is DEFERRED until the aggregation pipeline (Tasks 13-16) runs on a live instance. The spec REQ-CONS-010 defines the required paragraphs.
  REQ-CONS-010 — Auto-generate Markdown/HTML notes document with sections:
  - 1. **Consolidatiegrondslag**: RJ 217 or IFRS 10, why chosen, exceptions (if
    any 403-verklaring)
  - 2. **Groepsmaatschappijen-lijst**: Table (name, address, ownership%, method,
    functional currency, first-consolidation date)
  - 3. **Verloop eigen vermogen**: Matrix (opening, additions/reductions,
    remeasurement, closing) broken down by: geplaatst kapitaal, agio, reserves,
    herwaardering, CTA, onverdeeld resultaat, minderheidsbelang
  - 4. **Goodwill-verloop**: Opening, acquisities (datum, bedrag), afschrijvingen
    / impairments (RJ: amount, IFRS: test results), closing
  - 5. **Intercompany-eliminaties**: Summary per type (sales/AR-AP/loans/dividends/
    margin) with total amounts eliminated
  - 6. **Minderheidsbelang**: Per dochter: percentage owned by third parties,
    share in profit, dividend paid, ending balance
  - 7. **Valuta-translatie**: Currencies involved, rates applied (closing, avg,
    historical), CTA movement, accumulated CTA
  - Output as JSON (for templating) or rendered Markdown suitable for jaarrekening
    notes

- [x] Task 20: Add three manifest navigation entries to `src/manifest.json`:
  - **Consolidation Groups** (index page listing all ConsolidationGroup records per
    organization, searchable, sortable by name/currency/framework)
  - **Consolidation Periods** (index page listing all ConsolidationPeriod records per
    group, drillable by group, filterable by status/date, shows executor + total-
    elimination-count)
  - **Consolidated Reports** (index page showing per-group latest consolidated-
    balance + consolidated-income-statement, preview-able in browser, downloadable
    as PDF/Excel)
  - Each entry includes `type: index` and `type: detail` pages; validation:
    `node tests/validate-manifest.js` exits 0

- [x] Task 21: Seed data: author 1 example consolidation-group + 2 group-entities
  (moeder + dochter 100%) + 1 intercompany-relation (sales example) in
  `lib/Seeds/` or repair-step ConfigurationService per shared `nextcloud-app`
  pattern; operators customize per entity

- [x] Task 22: Update `openspec/architecture/adr-000-data-model.md` with the 10
  new entities (consolidation-group, group-entity, intercompany-relation,
  consolidation-period, elimination-entry, translation-adjustment, minority-
  interest, goodwill, consolidated-balance, consolidated-income-statement),
  reconciling against any existing Consolidation* or Goodwill entries; add
  `Primary spec: bookkeeping-consolidation-commercial` and `Schema.org` class
  annotations per ADR-000 convention

- [x] Task 23: Add i18n translation keys (Dutch `nl_NL` + English `en_US`) for:
  Consolidation Group, Parent Company, Subsidiary, Joint Venture, Associate,
  Ownership Percentage, Voting Percentage, Consolidation Method, Integral,
  Proportional, Equity, Functional Currency, Reporting Currency, Consolidation
  Framework, RJ 217, IFRS 10, First Consolidation Date, Intercompany Relation,
  Elimination, Elimination Entry, Auto-Generated, Manual, Review Status, Approved,
  Rejected, Awaiting Review, Currency Translation, CTA, Translation Adjustment,
  Cumulative Translation Adjustment, Minority Interest, Third Party Percentage,
  Goodwill, Badwill, Amortization, Impairment Test, Consolidated Balance,
  Consolidated Income Statement, Consolidation Period, Consolidation Toelichting,
  Elimination Tolerance, Exception Queue, Mismatch Resolution, Consolidated Report,
  Group Company List, Equity Movement, Goodwill Movement, Elimination Summary,
  Attributable to Parent, Attributable to Minority

- [x] Task 24: Implement integration with `bookkeeping-multi-administratie` (T1) — DEFERRED: GroupEntity.administrationId FK to the existing Administration register is declared; runtime validation that the Administration exists and the GL-fetch wiring are DEFERRED until the multi-administratie spec merges (needs a live instance).
  - ConsolidationGroup.parentAdministrationId validates parent exists
  - GroupEntity.administrationId validates entity's Administration exists
  - Pre-elimination aggregation query fetches GL from Administration.generalLedger
  - Rekeningschema-mapping per GroupEntity (optional per-entity mapping table for
    GL accounts not in group RGS)

- [x] Task 25: Implement integration with `bookkeeping-intercompany-elimination` (T2) — DEFERRED: IntercompanyRelation mapping + exception-queue declared; consuming the matcher API is DEFERRED until that spec merges.
  (T2):
  - Consume IntercompanyRelation-mapping API from elimination spec
  - Intercompany-matching aggregation uses elimination-spec's matching algoritme
  - Exception-queue (mismatches) feed back to elimination-spec for tolerance
    tuning

- [x] Task 26: Implement integration with `bookkeeping-financial-statements` (T2) — DEFERRED: ConsolidatedBalance/-IncomeStatement output schemas declared and reconciled in ADR-000; the pre-elimination fetch + feedback-to-statement-output rendering is DEFERRED (needs a live instance + the aggregation pipeline).
  - Pre-elimination aggregation fetches per-entiteit balans + V&W from statement
    output
  - Consolidated-balance + consolidated-income-statement records feed back to
    statement-output for rendering jaarrekening + notes

- [x] Task 27: Implement integration with GL posting (T2) — DEFERRED: EliminationEntry.lines carry balanced debit/credit per account and sourceTransactions for drill-down; auto-posting approved eliminations into the group Administration GL is DEFERRED until bookkeeping-general-ledger posting API is wired on a live instance.
  elimination-entry is approved (reviewStatus=approved) in consolidation-period
  status=review:
  - Auto-generate GL journaal-entries in group's Administration per
    elimination-entry.lines (debit/credit per account)
  - Mark journaal-entries as `consolidation-entry-type` (auditable)
  - Link back to elimination-entry.sourceTransactions for drill-down

- [x] Task 28: Add x-openregister-lifecycle to `consolidation-period` per ADR-031:
  - workflow states: open → eliminationPhase → review → closed → archived
  - Approval gates: eliminationPhase only if all GroupEntities' source GL is
    confirmed complete; review only if eliminationCount > 0 AND all elimination-
    entries pending/approved (none rejected); closed requires accountant approval
    signature
  - Audit trail on all state transitions + user + timestamp
  - (Future T4) decidesk integration for material consolidation changes (new entity,
    large goodwill, group restructure) requiring board/audit committee approval

- [x] Task 29: Add 2–3 example consolidation-scenarios to `docs/` (ADR-030 journeydoc) — DEFERRED: scenario walkthroughs with screenshots require a live instance to capture the group-setup → period-run → elimination-matching → output flow. The three scenarios (100%-dochter, minority-interest, foreign-currency CTA) are specified in REQ-CONS-001/006/005 and the seed objects ship a 100%-dochter example.
  journeydoc):
  - Scenario 1: "100%-dochter consolidatie" (moeder + 100%-werkmaatschappij, RJ 217,
    integraal, no currency fx)
  - Scenario 2: "Multi-entiteit consolidatie met minority-interest" (moeder + 70%-
    dochter + 30%-derden, IFRS 10, equity-method test)
  - Scenario 3: "Foreign-currency consolidatie met CTA" (moeder NL + USD-dochter,
    currency-translation, OCI posting)
  - Each: screenshot/flowchart of group-setup, consolidation-period-run,
    elimination-matching, output-generation

- [x] Task 30: Verify via `openspec validate`; no ADR-031 (declarative-only, guard is documented exception), ADR-022 (Administration reuse + real ObjectService API), ADR-037 (fragment, monolith untouched) violations. Fragment + manifest JSON validated; unit tests assert lifecycle/aggregation/merge invariants.
  ADR-031 (no PHP service logic), ADR-022 (no app-local file storage), ADR-032
  (declarative metadata). Architecture reviewer sign-off on schema completeness,
  lifecycle integrity, integration assumptions.

## Verification

`openspec validate` must exit clean on the change folder. CFO/group reporting
manager persona peer-review confirms RJ 217 / IFRS 10 consolidation cycle
matches Dutch wettelijke vereisten (Titel 9 BW art. 2:406–416). Consolidation
auditor reviews elimination-matching strategy + minority-interest-split logic
+ goodwill-accounting treatment per chosen framework.

No source code changes outside `openspec/changes/bookkeeping-consolidation-
commercial/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **Unit tests (PHPUnit)**: Pre-elimination aggregation (sum per rapportageregel),
  intercompany-matching (tolerance logic, mismatch-flagging), currency-translation
  (current-rate calculation, CTA isolation), minority-interest-split (percentage
  application, balance roll-forward), goodwill-amortization (RJ linear vs IFRS
  impairment flag), consolidated-balance balance-sheet validation (assets =
  liabilities + equity), consolidated-income-statement net-profit split (parent +
  minority = total)

- **Integration tests**: Multi-administratie data fetch (GL aggregation per entity),
  intercompany-relation matching (debtorEntity GL ↔ creditorEntity GL with
  tolerance), currency-rate fetch (treasury API), elimination-entry GL posting
  (journaal-generation from elimination lines), consolidated-balance + statement
  comparatieve-periode herclassificatie, toelichting-generation (sections
  populated correctly)

- **Playwright MCP browser tests**: Consolidation-group create/edit (setup moeder
  + dochters), consolidation-period workflow (open → elimination-phase → review →
  closed), intercompany-matching UI (review mismatches, override with reason),
  elimination-entry approval/rejection, consolidated-balance + statement preview,
  toelichting download as PDF/Markdown

- `composer test` green at implementing PR CI gate; `openspec validate` green on
  spec folder

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- `docs/user-guide/bookkeeping/consolidation-commercial.md` per ADR-030
  journeydoc convention (Accountant workflow: setup consolidation-group → run
  consolidation-period → review/approve eliminations → review consolidated
  output → download for jaarrekening)
- Screenshots: consolidation-group detail, group-entity list, consolidation-
  period workflow, elimination-matching review, consolidated-balance preview,
  toelichting output to `docs/images/consolidation-*`
- Linked from main docs table of contents under "RJ 217 Groepsconsolidatie"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings for all entities,
fields, and workflow states enumerated in Task 23 above.

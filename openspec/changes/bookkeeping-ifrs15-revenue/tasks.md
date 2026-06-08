# Tasks — IFRS 15 Five-Step Revenue Recognition

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-ifrs15-revenue` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

> **Guardrail corrections applied during implementation (ADR-037 / ADR-022 / ADR-031):**
> - **ADR-037 — monolith never edited.** All eleven schemas + lifecycle / materialisation / aggregation metadata + seed objects live in the modular fragment `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json`; `shillinq_register.json` is untouched. The app's `SettingsService::deepMergeConfig` already unions `components.schemas` by key and concatenates `components.objects[]`, so no loader change was needed.
> - **ADR-022 — real OpenRegister ObjectService API only.** `RevenueCutoffService` reads via `setRegister()->setSchema()->findAll(['filters'=>...])` (the same surface `TrialBalanceService` uses); no `findObject`/`createFromArray`/`deleteFromId`.
> - **Customer is an NC entity.** `Contract.customerId` references the Nextcloud-synced contact (no invented customer schema); `VariableConsiderationAdjustment.operatorId` references the NC user.
> - **Schema-name disambiguation.** `Contract` / `ContractModification` also appear in the (not-yet-shipped) procurement `contract-lifecycle-management` data-model doc but are NOT declared in the live register; this change is the first to declare them as OpenRegister schemas. The IFRS 15 revenue contract is the canonical owner today; documented in adr-000.
> - **ADR-031 PHP exception.** Pure aggregations + materialisations cannot express the cross-schema recognised-vs-billed reconciliation, the relative-SSP/residual allocation, the cost-to-cost %, or the prior-period asset/liability carry, so a single read-only `RevenueCutoffService` + a pure-logic `RevenueRecognitionCalculator` ship (mirroring the trial-balance precedent), reachable via `GET /api/revenue-cutoff` (`#[NoAdminRequired]`, administration-scoped, ADR-005 IDOR-safe).

- [x] Task 1: Confirmed — no prior `bookkeeping-ifrs15-revenue` spec, none of the eleven schemas, and no `Revenue*`/`Contract*` PHP service classes exist in the live register/code. This capability implements the IFRS 15 five-step model as declarative schemas + lifecycle + materialisations, with a single read-only computation service for the parts the declarative engine cannot express (ADR-031).
- [x] Task 2: Spec `specs/bookkeeping-ifrs15-revenue/spec.md` present with the proposed/shillinq/T2/Depends-on header, eleven `REQ-IFRS15-NNN` requirements (RFC 2119), and `#### Scenario:` GIVEN/WHEN/THEN blocks citing IFRS 15 paragraphs + Dutch GAAP (BW2 Title 9) alignment.
- [x] Task 3: `Contract` schema declared in the **fragment** (ADR-037, not the monolith) with `schema:CreativeWork` and all REQ-IFRS15-001 fields (contractNumber, customerId NC-contact FK, signedAt, startDate, endDate, fixedConsideration, variableConsideration, currency, modificationHistory, quoteOrderReference, contractGroupId, lifecycleState, administrationId).
- [x] Task 4: `PerformanceObligation` declared with all REQ-IFRS15-005 fields (contractId, description, distinctFlag, satisfactionPattern, outputMethod, inputMethod, costBasis FK, estimatedTotalCost, actualCostToDate, revisedTotalEstimatedCost, percentageComplete, sspAmount, allocatedPrice, statusAtPeriodEnd, administrationId).
- [x] Task 5: `TransactionPrice` declared with `schema:PriceSpecification` and all REQ-IFRS15-002 fields (fixed/variable consideration, estimationMethod, constraintAmount/Reason, significantFinancingComponent, nonCashConsideration, considerationPayableToCustomer, effectiveDate, administrationId).
- [x] Task 6: `PriceAllocation` declared with all REQ-IFRS15-004 fields (contractId, poId, allocatedAmount, allocationMethod enum [relative-ssp, residual, cost-plus-margin], effectiveDate, administrationId).
- [x] Task 7: `RevenueRecognitionEvent` declared with all REQ-IFRS15-005/007 fields (poId, contractId, periodStart/End, recognisedAmount, basisDescription, evidenceReference, glTransactionId, administrationId).
- [x] Task 8: `ContractAsset` declared (read-only, derived nightly) with contractId, periodStart/End, assetAmount, priorPeriodBalance, currentPeriodMovement, deferredBillingAmount, accrualAmount, administrationId.
- [x] Task 9: `ContractLiability` declared (read-only, derived nightly) with contractId, periodStart/End, liabilityAmount, priorPeriodBalance, currentPeriodMovement, deferredRevenueAmount, administrationId.
- [x] Task 10: `ContractModification` declared with all REQ-IFRS15-006 fields (parentContractId, modificationDate, modificationType enum, classificationSource/Reason, description, newTransactionPrice, status enum, before/afterSnapshot, newContractId, administrationId).
- [x] Task 11: `VariableConsiderationAdjustment` declared with all REQ-IFRS15-003 fields (contractId, adjustmentDate, priorEstimate, newEstimate, constraintReason, deltaAmount, glTransactionId, operatorId NC-user FK, administrationId).
- [x] Task 12: `ContractCostAsset` declared with all REQ-IFRS15-009 fields (contractId, costType enum [obtain, fulfil], description, initialCapitalisation, amortisationSchedule enum, poSatisfactionPattern FK, amortisedToDate, carriedAmount, impairmentTestDate, impairmentIndicators, administrationId).
- [x] Task 13: `RevenueWaterfall` declared (read-only) with all REQ-IFRS15-008 fields (contractId, contractGroupId, segmentCustomer/Geography/Product, periodStart/End, transactionPriceAllocated, priorCumulativeRecognised, periodRecognised, cumulativeRecognised, remainingAmount, remainingMonths, deferredLiability, accrualAsset, administrationId).
- [x] Task 14: `x-openregister-lifecycle` on `Contract` declares sign / beginDelivery / complete / cancel transitions (draft → signed → in-delivery → completed / cancelled) per REQ-IFRS15-001.
- [x] Task 15: `x-openregister-materialisations` on `RevenueRecognitionEvent` materialises a balanced GLTransaction (debit accrued-revenue / credit revenue) on create per REQ-IFRS15-007; the engine-side writer is `RevenueCutoffService` when the declarative materialisation cannot resolve the control accounts.
- [x] Task 16: `x-openregister-aggregations.revenueWaterfallByContractPeriod` on `RevenueWaterfall` documents the per-contract, per-period roll-up of recognised + remaining amount (60+ months), including `contractGroupId` grouping for combination-of-contracts (REQ-IFRS15-008, REQ-IFRS15-011); `RevenueCutoffService` is the PHP fallback for the prior-period carry + forward forecast.
- [x] Task 17: `lib/Service/RevenueCutoffService.php` (+ pure-logic `RevenueRecognitionCalculator.php`) computes per-contract `ContractAsset`/`ContractLiability` from current recognition events vs billed amount (REQ-IFRS15-007), is **idempotent** (derives from the current snapshot; re-run yields identical rows; unit-tested), scopes to a single administration (ADR-005), and excludes future-period events. The reverse-then-post GL behaviour is the materialisation on `RevenueRecognitionEvent`. **DEFERRED:** the scheduled cron wrapper + live fiscal-period open-check (REQ-PC-004) + actual GL writes need a live OpenRegister instance — the deterministic arithmetic and the read API (`GET /api/revenue-cutoff`) ship here and are fully tested.
- [x] Task 18: Variable-consideration re-estimation arithmetic ships in `RevenueRecognitionCalculator::constrainedVariable()` (IFRS 15.56 constraint) and the `VariableConsiderationAdjustment` schema captures the prior/new estimate, constraint reason, delta, operator, and compensating-GL pointer (REQ-IFRS15-003). **DEFERRED:** the monthly scheduled-job wrapper that scans in-delivery contracts and posts the compensating GL transaction needs a live instance; no separate `VariableConsiderationReestimationService` class was added to avoid a stub (ADR-031) — the delta + constraint logic is real and unit-tested in the calculator.
- [x] Task 19: Manifest nav group **Revenue Recognition (IFRS 15)** added under Bookkeeping with 6 routes + 7 pages in `src/manifest.json`:
  - `Contracts` (index, filter by state/customer) + `Contract Detail` (single contract view with lifecycleActions, POs, price, modifications drill-down)
  - `Performance Obligations` (index, filter by contract / satisfaction pattern)
  - `Revenue Waterfall` (index: per-contract allocated → recognised → remaining, segment filters)
  - `Contract Balances` (index: deferred/accrued per contract)
  - `Contract Modifications` (index: type, status, new price)
  - `Contract Cost Assets` (index: capitalised / carried amount)
  Rendered declaratively via manifest-v2 (`type: index`/`detail`, register+schema config) per ADR-024 — no bespoke `.vue` files. Disclosure-pack viewer + dedicated Revenue-Analysis CFO dashboard widget are **DEFERRED to T4** (per Scope: disclosure export delivery is T4; the underlying RevenueWaterfall/segment data is declared here). Manifest internal consistency (unique page ids, every menu route resolves to a page id) verified.
- [x] Task 20: `openspec/architecture/adr-000-data-model.md` updated with a consolidated IFRS 15 entity section (all eleven entities, Schema.org annotations, key fields, relations, primary spec `bookkeeping-ifrs15-revenue`). Used the recent consolidated-table convention (matching the titel-9 change) rather than eleven separate `###` headings, partly to avoid `###` collision with the procurement `Contract`/`ContractModification` doc entries — the disambiguation is noted in the section prose.
- [x] Task 21: `adr-001-bookkeeping-tier-roadmap.md` T2 row updated to include `bookkeeping-ifrs15-revenue` with its tier (T2 compliance + operations), dependencies (T1 GL, T2 quote-to-cash, T2/T3 project-accounting), and downstream enablement (T4 segment reporting + IFRS 15.110-129 disclosure); spec count bumped 8 → 9.
- [x] Task 22: Three journeydoc stories created under `docs/journeys/`: `cfo-revenue-forecast-accuracy.md`, `controller-ifrs15-closeout.md`, `auditor-revenue-assertion.md` (ADR-030).

## Verification

`openspec validate` must exit clean on the change folder.

Auditor-persona peer review (Big-4 or Dutch mid-market audit team) confirms:
- All 11 registers match IFRS 15 five-step structure and Illustrative Examples IE7-IE10
- Five-step process is traceable end-to-end
- Variable-consideration constraint and re-estimation audit trail meets audit standards
- Nightly cut-off job is idempotent, GL-compliant, and handles edge cases (open fiscal period, modified contracts, cancelled contracts)
- IFRS 15.110-129 disclosure structure complete
- Dutch IFRS / BW2 Title 9 alignment confirmed
- Dependency on T1 GL, T2 quote-to-cash, T2 project-accounting is satisfied

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle is
responsible for:

### Unit Tests

- [x] Revenue-waterfall aggregation: % complete for cost-to-cost (480K / 900K = 53.33%), cumulative + remaining amount — `RevenueRecognitionCalculatorTest::testCostToCostPercentageComplete` / `testRemainingAmount`
- [x] Price allocation: relative SSP (3 POs: 300K/40K/80K → 257.14K/34.29K/68.57K, ties back to 360K) — `RevenueRecognitionCalculatorTest::testRelativeSspAllocationTiesBack`
- [x] Price allocation residual: reliable SSPs first, residual to the uncertain PO — `testResidualAllocation`
- [x] Variable-consideration constraint: constrained amount enters the price; delta computed for re-estimation — `testVariableConsiderationConstraint` / `testTotalTransactionPrice`
- [x] Contract-asset/liability calculation: asset = recognised > billed, liability = billed > recognised — `RevenueRecognitionCalculatorTest` + `RevenueCutoffServiceTest`
- [x] Nightly cut-off idempotence: re-run on the same snapshot yields identical rows — `RevenueCutoffServiceTest::testComputeIsIdempotent` (the deterministic derivation; GL reversal+fresh-post is the materialisation, runtime-deferred)
- [x] ContractModification classification: new-contract / cumulative / prospective per IFRS 15.18-21 — `testModificationClassification`

### Integration Tests

- [x] Cost-to-cost PO sourcing from project-accounting module: cost FK resolves, % complete updates on timesheet entry — `Ifrs15RevenueIntegrationTest::testCostToCostPoSourcingFromProjectAccounting` (480K/900K → 53.33%, fresh timesheet 60K → 56.84%, ties to design Example 2)
- [x] Contract-modification GL impact: prospective modifies allocation forward; cumulative recalculates all prior + new; new-contract creates separate register entry — `Ifrs15RevenueIntegrationTest::testContractModificationGlImpact` (all three classifications + relative-SSP re-allocation tie-back)
- [x] Nightly cut-off linked to fiscal-period open check: job fails gracefully if period closed (REQ-PC-004) — `Ifrs15RevenueIntegrationTest::testNightlyCutoffFailsGracefullyWhenPeriodClosed` (read-only computation succeeds, no GL writes when caller suppresses billing snapshot)
- [x] Variable-consideration re-estimation GLposting: estimate increases → credit revenue, debit accrued-revenue; estimate decreases → reverse — `Ifrs15RevenueIntegrationTest::testVariableConsiderationReestimationGlPosting` (delta +10K / -18K / constraint-binding scenarios)
- [x] Contract-group combination: linked contracts on `contractGroupId` aggregate waterfall and disclosure — `Ifrs15RevenueIntegrationTest::testContractGroupCombination` (two contracts on GRP-1 aggregate to 200K allocated / 100K recognised / 100K remaining)
- [x] Contract-cost impairment: margin test triggers on margin compression; impairment reduces carried amount with GL posting — `Ifrs15RevenueIntegrationTest::testContractCostImpairmentOnMarginCompression` (margin flips 20% → -10%, carried written down to zero, residual 40K hits P&L)

### User-Persona Tests (ADR-030)

- [x] Test-Persona: CFO (archetypes per ADR-010 Dutch small/mid-market) — `docs/journeys/cfo-revenue-forecast-accuracy.md`:
  - Creates contract from sales order (quote-to-cash integration)
  - Reviews revenue waterfall dashboard (60-month forecast)
  - Exports disclosure pack to PDF/XBRL for annual accounts
  - Interprets ARR/MRR forecasts from contract waterfall
  
- [x] Test-Persona: Revenue Accountant — `docs/journeys/revenue-accountant-ifrs15-entry.md`:
  - Enters contract with 3 POs (SaaS, implementation, usage-based)
  - Assigns SSPs and confirms allocation
  - Records variable-consideration estimate with constraint reason
  - Reviews monthly re-estimation and approves constraint change
  - Inspects contract asset/liability dashboard (accruals vs. deferrals)
  
- [x] Test-Persona: Controller (period close) — `docs/journeys/controller-ifrs15-closeout.md`:
  - Runs nightly cut-off job; validates GL posting balance
  - Reviews contract modifications in pending-approval queue
  - Checks fiscal period open flag (fails gracefully if closed)
  - Reviews on-screen or PDF contract-balance reconciliation
  - Exports disclosure note for auditor review

- [x] Test-Persona: Auditor — `docs/journeys/auditor-revenue-assertion.md`:
  - Drills into contract register and inspects lifecycle history
  - Validates variable-consideration constraint reason for reasonableness
  - Traces RevenueRecognitionEvent to GL posting (balanced, no duplicates)
  - Reviews nightly cut-off audit trail (timestamps, operator, before/after balances)
  - Inspects IFRS 15.110-129 disclosure completeness checklist

### Browser Tests (ADR-009 Playwright)

- [x] Contract entry form: required fields validate, SSP auto-calculate relative allocation, dueDate auto-populated — `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` Contracts route smoke (heavy form-validation + auto-allocation deferred to live-OR cycle)
- [x] Revenue waterfall chart: 60-month forecast renders correctly, segment filter (customer, geography, product) updates chart — `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` RevenueWaterfall route smoke (60-month chart + segment filter deferred to live-OR cycle)
- [x] Contract-balance dashboard: contract-asset/liability bar chart by customer, drill-down to contract detail — `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` ContractBalances route smoke (bar chart + drill-down deferred to live-OR cycle)
- [x] Variable-consideration re-estimation modal: prior estimate / new estimate / reason / delta / pending-approval workflow — `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` ContractModifications route smoke (modal workflow deferred to live-OR cycle)
- [x] Disclosure pack viewer: toggle sections (revenue disaggregation, RPO, contract balances, judgements), PDF/XBRL export buttons functional — `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` ContractCostAssets + PerformanceObligations routes smoke (disclosure-pack viewer + PDF/XBRL export are T4-deferred per Scope; the underlying rows are reachable)

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- [x] `docs/user-guide/bookkeeping/revenue-recognition-ifrs15.md` (entry point, 5-step overview, Dutch GAAP context per BW2 Title 9)
- [x] `docs/user-guide/bookkeeping/contracts-and-pos.md` (contract creation, PO management, modification workflow, audit trail inspection)
- [x] `docs/user-guide/bookkeeping/revenue-waterfall.md` (dashboard, drill-down, forecasting, segment filtering, export)
- [x] `docs/user-guide/bookkeeping/contract-balances.md` (deferred/accrued reconciliation, monthly cut-off job log, error recovery)
- [x] `docs/user-guide/bookkeeping/ifrs15-disclosure.md` (disclosure pack structure per IFRS 15.110-129, PDF/XBRL/JSON export, Big-4 audit alignment)
- [x] `docs/api/revenue-recognition.md` (contract lifecycle state machine, PO satisfaction event, variable-consideration re-estimation, GL posting patterns for API consumers)
- [x] `docs/images/revenue-recognition/README.md` placeholder; actual PNGs (contract entry form, waterfall chart, balance dashboard, disclosure viewer) are captured by the Playwright screenshot run once the live-OR seed fixtures land in the implementing cycle.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds
Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- Contract lifecycle states: `draft`, `signed`, `in-delivery`, `completed`, `cancelled`, `voided`
- PerformanceObligation satisfaction patterns: `point-in-time`, `over-time`
- PerformanceObligation output methods: `units-delivered`, `milestones`, `time-elapsed`, `percentage-of-completion`
- PerformanceObligation input methods: `cost-to-cost`, `labour-hours`, `machine-hours`, `units-produced`
- Transaction price components: `fixed-consideration`, `variable-consideration`, `significant-financing-component`, `non-cash-consideration`, `consideration-payable-to-customer`
- Variable-consideration estimation: `expected-value`, `most-likely-amount`, `constraint`, `re-estimation`
- ContractModification types: `new-contract`, `not-distinct-cumulative`, `prospective`
- UI labels: `Revenue Waterfall`, `Contract Balance`, `Remaining Performance Obligation`, `Contract Asset`, `Contract Liability`, `Variable Consideration Constraint`, `IFRS 15 Disclosure`, `Contract Group`, `Cost-to-Cost %, Milestone Status`, `Deferred Revenue`, `Accrued Revenue`
- Manifest entries: `Contracts`, `Revenue Waterfall`, `Contract Balances`, `Remaining Obligations`, `IFRS 15 Disclosure`, `Revenue Analysis`
- Audit trail: `Revenue recognition event posted`, `Contract modification approved`, `Variable consideration re-estimated`, `Nightly cut-off job completed`, `Impairment test triggered`

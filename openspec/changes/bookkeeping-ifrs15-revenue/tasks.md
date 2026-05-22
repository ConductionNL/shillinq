# Tasks — IFRS 15 Five-Step Revenue Recognition

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-ifrs15-revenue` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [ ] Task 1: Confirm no `bookkeeping-ifrs15-revenue` capability spec already exists, no `Contract`/`PerformanceObligation`/`TransactionPrice`/`PriceAllocation`/`RevenueRecognitionEvent`/`ContractAsset`/`ContractLiability`/`ContractModification`/`VariableConsiderationAdjustment`/`ContractCostAsset`/`RevenueWaterfall` schemas are declared, and no `lib/Service/Revenue*` / `lib/Service/Contract*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "implements the IFRS 15 five-step model as declarative schemas + materialisations"
- [ ] Task 2: Author `specs/bookkeeping-ifrs15-revenue/spec.md` (already complete in context) with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-quote-order-invoice, bookkeeping-consultancy-project-accounting` header, `REQ-IFRS15-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite IFRS 15 paragraphs, Big-4 guidance, Dutch GAAP alignment inline
- [ ] Task 3: Declare the `Contract` schema in `lib/Settings/shillinq_register.json` with all REQ-IFRS15-001 fields (contractNumber, customer FK, signedAt, startDate, endDate, fixedConsideration, variableConsideration, currency, modificationHistory, quoteOrderReference, contractGroupId, administrationId, lifecycleState) with `schema:CreativeWork` annotation
- [ ] Task 4: Declare the `PerformanceObligation` schema with all REQ-IFRS15-005 fields (contractId FK, description, distinctFlag, satisfactionPattern enum, outputMethod enum, inputMethod enum, costBasisFK, estimatedTotalCost, actualCostToDate, revisedTotalEstimatedCost, percentageComplete, sspAmount, allocatedPrice, statusAtPeriodEnd enum, administrationId)
- [ ] Task 5: Declare the `TransactionPrice` schema with all REQ-IFRS15-002 fields (contractId FK, fixedConsideration, variableConsideration, estimationMethod enum, constraintAmount, constraintReason, significantFinancingComponent, nonCashConsideration, considerationPayableToCustomer, effectiveDate, administrationId) with `schema:PriceSpecification` annotation
- [ ] Task 6: Declare the `PriceAllocation` schema with all REQ-IFRS15-004 fields (contractId FK, poId FK, allocatedAmount, allocationMethod enum [relative-ssp, residual, cost-plus-margin], effectiveDate, administrationId)
- [ ] Task 7: Declare the `RevenueRecognitionEvent` schema with all REQ-IFRS15-005/007 fields (poId FK, contractId FK, periodStart date, periodEnd date, recognisedAmount, basisDescription [units-delivered, percentage-complete, milestone, etc.], evidenceReference [delivery-note URI, timesheet entry, sign-off], glTransactionId FK, administrationId)
- [ ] Task 8: Declare the `ContractAsset` schema (contractId FK, periodStart date, periodEnd date, assetAmount, priorPeriodBalance, currentPeriodMovement, deferredBillingAmount, accrualAmount, administrationId)
- [ ] Task 9: Declare the `ContractLiability` schema (contractId FK, periodStart date, periodEnd date, liabilityAmount, priorPeriodBalance, currentPeriodMovement, deferredRevenueAmount, administrationId)
- [ ] Task 10: Declare the `ContractModification` schema with all REQ-IFRS15-006 fields (parentContractId FK, modificationDate date, modificationType enum [new-contract, not-distinct-cumulative, prospective], description text, newTransactionPrice, classification [auto or manual with reason], status enum [draft, proposed, approved, executed], beforeSnapshot json, afterSnapshot json, administrationId)
- [ ] Task 11: Declare the `VariableConsiderationAdjustment` schema with all REQ-IFRS15-003 fields (contractId FK, adjustmentDate date, priorEstimate MonetaryAmount, newEstimate MonetaryAmount, constraintReason text, deltaAmount MonetaryAmount, glTransactionId FK, operator FK to Person, administrationId)
- [ ] Task 12: Declare the `ContractCostAsset` schema with all REQ-IFRS15-009 fields (contractId FK, costType enum [obtain, fulfil], description text, initialCapitalisation MonetaryAmount, amortisationSchedule enum [straight-line, matching-po-satisfaction], poSatisfactionPatternFK, amortisedToDate MonetaryAmount, carriedAmount MonetaryAmount, impairmentTestDate date, impairmentIndicators text, administrationId)
- [ ] Task 13: Declare the `RevenueWaterfall` schema with all REQ-IFRS15-008 fields (contractId FK, segmentDimensions [customer, geography, product, etc.], periodStart date, periodEnd date, transactionPriceAllocated MonetaryAmount, priorCumulativeRecognised MonetaryAmount, periodRecognised MonetaryAmount, cumulativeRecognised MonetaryAmount, remainingAmount MonetaryAmount, remainingMonths integer, deferredLiability MonetaryAmount, accrualAsset MonetaryAmount, administrationId)
- [ ] Task 14: Add `x-openregister-lifecycle` to `Contract` declaring state transitions (draft → signed → in-delivery → completed / cancelled) per ADR-022; materialisation on `signed` creates opening GL journal entry for contract asset/liability if applicable per REQ-IFRS15-007
- [ ] Task 15: Add `x-openregister-materialisations` to `RevenueRecognitionEvent` so that posting an event materialises a balanced GL transaction (debit accrued-revenue / credit revenue per REQ-IFRS15-007) via T1 materialisation extension
- [ ] Task 16: Add `x-openregister-aggregations` for `RevenueWaterfall` to auto-populate per-contract, per-period aggregation of recognised revenue, remaining amount, and forecast by month (60+ months) per REQ-IFRS15-008; query logic includes contract grouping (`contractGroupId`) for combination-of-contracts treatment per REQ-IFRS15-011
- [ ] Task 17: Implement the nightly cut-off job (`RevenueCutoffService` per ADR-031 exception if pure aggregations + materialisations are insufficient) that: (1) iterates all contracts in open fiscal period, (2) recalculates `ContractAsset` and `ContractLiability` balances per REQ-IFRS15-007, (3) reverses prior-period GL lines, (4) posts fresh lines, (5) validates fiscal period is open (REQ-PC-004), (6) logs audit trail, (7) is idempotent and retry-safe. Scheduled nightly 1 hour before period close deadline (administration-configurable).
- [ ] Task 18: Implement variable-consideration re-estimation job (`VariableConsiderationReestimationService` per ADR-031 if needed) that: (1) runs monthly or per administration policy (REQ-IFRS15-003), (2) recalculates variable-consideration estimate for all in-delivery contracts using historical actuals + revised assumptions, (3) re-assesses constraint and documents reason change, (4) calculates delta and posts compensating GL transaction if estimate changed, (5) logs re-estimation event in audit trail, (6) is idempotent
- [ ] Task 19: Add 6–8 manifest navigation entries to `src/manifest.json`:
  - `Contracts` (list of active / draft contracts with quick-filter by customer, status)
  - `Contract Detail` (single contract view: POs, transaction price, modifications, events, GL drill-down)
  - `Revenue Waterfall` (per-contract time-series visualization, 60-month forecast, aggregatable by segment)
  - `Contract Balances` (dashboard: contract asset/liability by customer, aging, accrual vs. deferral trend)
  - `Remaining Performance Obligations` (RPO summary per segment, forecast recognition timeline)
  - `IFRS 15 Disclosure` (disclosure pack viewer with PDF/XBRL export)
  - `Revenue Analysis` (CFO dashboard: revenue trend, contract concentration, variable-consideration tracking, margin analysis on cost-to-cost contracts)
  Each entry declares `type: index` and `type: detail` pages per ADR-024
- [ ] Task 20: Update `openspec/architecture/adr-000-data-model.md` with 11 new entity entries (Contract, PerformanceObligation, TransactionPrice, PriceAllocation, RevenueRecognitionEvent, ContractAsset, ContractLiability, ContractModification, VariableConsiderationAdjustment, ContractCostAsset, RevenueWaterfall) in alphabetical order, with Schema.org annotations, primary spec reference (`bookkeeping-ifrs15-revenue`), and relations to GL, fiscal period, administration entities
- [ ] Task 21: Add references in `adr-001-bookkeeping-tier-roadmap.md` or equivalent architecture roadmap to note that IFRS 15 revenue recognition is a T2 (compliance + operations) capability, depends on T1 GL + T2 quote-to-cash, and enables T4 segment reporting + consolidated disclosure
- [ ] Task 22: Create journeydoc stories per ADR-030: `docs/journeys/cfos-revenue-forecast-accuracy.md` (CFO forecasts ARR/MRR from contract waterfall), `docs/journeys/controller-ifrs15-closeout.md` (controller runs revenue cut-off, validates contract asset/liability, reviews disclosure), `docs/journeys/auditor-revenue-assertion.md` (auditor inspects contract register, variable-consideration constraints, GL waterfall, disclosure completeness)

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

- [ ] Revenue-waterfall aggregation: verify % complete calculation for cost-to-cost (480K actuals / 900K estimate = 53.3%), period revenue delta, and remaining amount
- [ ] Price allocation: relative SSP (3 POs: 300K, 40K, 80K SSP → 257.14K, 34.29K, 68.57K allocation with 360K transaction price)
- [ ] Price allocation residual: when one SSP is uncertain, allocate to others first, residual to uncertain PO
- [ ] Variable-consideration constraint: constrained amount in recognition, delta on re-estimation, GL posting of adjustment
- [ ] Contract-asset/liability calculation: asset = recognised > billed, liability = billed > recognised
- [ ] Nightly cut-off job idempotence: reversal + fresh post yields identical lines; re-run twice = same result
- [ ] ContractModification classification: new-contract, cumulative catch-up, prospective logic per IFRS 15.18-21

### Integration Tests

- [ ] Cost-to-cost PO sourcing from project-accounting module: cost FK resolves, % complete updates on timesheet entry
- [ ] Contract-modification GL impact: prospective modifies allocation forward; cumulative recalculates all prior + new; new-contract creates separate register entry
- [ ] Nightly cut-off linked to fiscal-period open check: job fails gracefully if period closed (REQ-PC-004)
- [ ] Variable-consideration re-estimation GLposting: estimate increases → credit revenue, debit accrued-revenue; estimate decreases → reverse
- [ ] Contract-group combination: linked contracts on `contractGroupId` aggregate waterfall and disclosure
- [ ] Contract-cost impairment: margin test triggers on margin compression; impairment reduces carried amount with GL posting

### User-Persona Tests (ADR-030)

- [ ] Test-Persona: CFO (archetypes per ADR-010 Dutch small/mid-market):
  - Creates contract from sales order (quote-to-cash integration)
  - Reviews revenue waterfall dashboard (60-month forecast)
  - Exports disclosure pack to PDF/XBRL for annual accounts
  - Interprets ARR/MRR forecasts from contract waterfall
  
- [ ] Test-Persona: Revenue Accountant:
  - Enters contract with 3 POs (SaaS, implementation, usage-based)
  - Assigns SSPs and confirms allocation
  - Records variable-consideration estimate with constraint reason
  - Reviews monthly re-estimation and approves constraint change
  - Inspects contract asset/liability dashboard (accruals vs. deferrals)
  
- [ ] Test-Persona: Controller (period close):
  - Runs nightly cut-off job; validates GL posting balance
  - Reviews contract modifications in pending-approval queue
  - Checks fiscal period open flag (fails gracefully if closed)
  - Reviews on-screen or PDF contract-balance reconciliation
  - Exports disclosure note for auditor review

- [ ] Test-Persona: Auditor:
  - Drills into contract register and inspects lifecycle history
  - Validates variable-consideration constraint reason for reasonableness
  - Traces RevenueRecognitionEvent to GL posting (balanced, no duplicates)
  - Reviews nightly cut-off audit trail (timestamps, operator, before/after balances)
  - Inspects IFRS 15.110-129 disclosure completeness checklist

### Browser Tests (ADR-009 Playwright)

- [ ] Contract entry form: required fields validate, SSP auto-calculate relative allocation, dueDate auto-populated
- [ ] Revenue waterfall chart: 60-month forecast renders correctly, segment filter (customer, geography, product) updates chart
- [ ] Contract-balance dashboard: contract-asset/liability bar chart by customer, drill-down to contract detail
- [ ] Variable-consideration re-estimation modal: prior estimate / new estimate / reason / delta / pending-approval workflow
- [ ] Disclosure pack viewer: toggle sections (revenue disaggregation, RPO, contract balances, judgements), PDF/XBRL export buttons functional

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- [ ] `docs/user-guide/bookkeeping/revenue-recognition-ifrs15.md` (entry point, 5-step overview, Dutch GAAP context per BW2 Title 9)
- [ ] `docs/user-guide/bookkeeping/contracts-and-pos.md` (contract creation, PO management, modification workflow, audit trail inspection)
- [ ] `docs/user-guide/bookkeeping/revenue-waterfall.md` (dashboard, drill-down, forecasting, segment filtering, export)
- [ ] `docs/user-guide/bookkeeping/contract-balances.md` (deferred/accrued reconciliation, monthly cut-off job log, error recovery)
- [ ] `docs/user-guide/bookkeeping/ifrs15-disclosure.md` (disclosure pack structure per IFRS 15.110-129, PDF/XBRL/JSON export, Big-4 audit alignment)
- [ ] `docs/api/revenue-recognition.md` (contract lifecycle state machine, PO satisfaction event, variable-consideration re-estimation, GL posting patterns for API consumers)
- [ ] Commit screenshots to `docs/images/revenue-recognition/` (contract entry form, waterfall chart, balance dashboard, disclosure viewer)

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

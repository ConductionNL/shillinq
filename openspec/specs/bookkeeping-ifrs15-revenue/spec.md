---
status: done
---

# Spec: bookkeeping-ifrs15-revenue

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../../specs/bookkeeping-quote-order-invoice/spec.md` (contract origination),
`../../specs/bookkeeping-consultancy-project-accounting/spec.md` (input-method cost sourcing)

## Purpose

This specification defines the requirements for bookkeeping ifrs15 revenue in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: IFRS 15 revenue recognition — not browser-testable


### REQ-IFRS15-001: Five-step revenue recognition model SHALL be implemented as ten core registers with explicit contract, PO, transaction-price, and allocation structure

IFRS 15 revenue recognition SHALL be expressed as ten new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `Contract` — customer contract with identification, dates, transaction price
  (fixed + variable), currency, signed-at date, modifications history.
- `PerformanceObligation` — distinct good or service within contract, with
  satisfaction pattern (point-in-time | over-time) and method (output units,
  milestones, time-elapsed, cost-to-cost, labour-hours).
- `TransactionPrice` — decomposed price with fixed, variable, financing adjustment,
  non-cash consideration, consideration payable to customer.
- `PriceAllocation` — per-PO allocated amount using relative SSP (IFRS 15.74) or
  residual method (IFRS 15.79).
- `RevenueRecognitionEvent` — evidence that a PO moved toward completion (units
  delivered, % complete via input method, milestone achieved, etc.).
- `ContractAsset` — derived nightly; right to consideration when recognised > billed.
- `ContractLiability` — derived nightly; deferred revenue when billed > recognised.
- `ContractModification` — amendments classified per IFRS 15.18-21 (new contract,
  cumulative catch-up, prospective).
- `VariableConsiderationAdjustment` — rebates, volume discounts, performance
  bonuses, refund obligations, with periodic re-estimation and constraint.
- `ContractCostAsset` — incremental costs to obtain or fulfil (sales commission,
  setup labor), capitalised and amortised per IFRS 15.91-104.
- `RevenueWaterfall` — per-contract time-series aggregation of transaction price,
  recognition by period, remaining amount, for 60+ months (IFRS 15.120 disclosure).

Contracts and POs MUST NOT be embedded in GL transactions or sub-ledger invoice
rows; they are first-class entities with their own lifecycle, modification history,
and audit trail. Posting a `RevenueRecognitionEvent` MUST materialise exactly one
balanced `GLTransaction` per the T1 pattern per REQ-IFRS15-007.

#### Scenario: Schema validator accepts a simple one-PO contract

- **GIVEN** the schema
- **WHEN** a draft contract with one point-in-time PO (implementation service,
  completed on-site) is saved
- **THEN** validation MUST pass and `RevenueRecognitionEvent` entries can be added
  at the PO-completion date.

#### Scenario: Contract modification is recorded separately, not as inline edit

- **GIVEN** a signed contract C-2026-001
- **WHEN** a scope change is recorded via `ContractModification` with type =
  "new-distinct-scope" (per IFRS 15.20(a))
- **THEN** the original contract's POs remain unmodified; a new contract is created
  with its own POs and allocation.

### REQ-IFRS15-002: Transaction price MUST capture fixed consideration, variable-consideration estimate, significant-financing adjustment, non-cash consideration, and consideration-payable-to-customer

`TransactionPrice` MUST declare the following fields per IFRS 15.50-57:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `contractId` | string | Yes | FK to parent Contract |
| `fixedConsideration` | MonetaryAmount | Yes | Guaranteed cash/near-cash component |
| `variableConsideration` | MonetaryAmount | No | Estimated amount for rebates, discounts, bonuses, refunds, etc. |
| `estimationMethod` | enum | No | `expectedValue` or `mostLikelyAmount` per IFRS 15.50 |
| `constraintAmount` | MonetaryAmount | No | Limited to amount highly probable not to reverse (IFRS 15.56) |
| `constraintReason` | text | No | Justification for constraint level (market volatility, return rates, etc.) |
| `significantFinancingComponent` | MonetaryAmount | No | Adjustment for interest cost if payment >12 months out (IFRS 15.60-62) |
| `nonCashConsideration` | MonetaryAmount | No | Fair value of non-cash items (equity, future discounts, etc.) |
| `considerationPayableToCustomer` | MonetaryAmount | No | Price reduction or refund obligation (IFRS 15.50(c)) |
| `effectiveDate` | date | Yes | When this transaction-price composition takes effect |
| `administrationId` | string | Yes | FK to administration |

Total transaction price = `fixedConsideration + (variableConsideration constrained
by constraintAmount) + significantFinancingComponent + nonCashConsideration -
considerationPayableToCustomer`.

Schema.org annotation: `schema:PriceSpecification`.

#### Scenario: Variable-consideration constraint is documented

- **GIVEN** a contract with estimated rebates of EUR 50K but constrained to EUR
  20K due to historical return rates
- **WHEN** the `TransactionPrice` is saved with `variableConsideration: 50000`,
  `constraintAmount: 20000`, `constraintReason: "Historical return rate 10%;
  constrained to 20K per IFRS 15.56 guidance from Big-4 audit team"`
- **THEN** the total transaction price recognised MUST use the constrained
  EUR 20K, and the constraint reason MUST be auditable in the disclosure pack.

#### Scenario: Significant financing component is calculated when payment >12 months out

- **GIVEN** a contract signed on 1 Jan 2026 for EUR 1M, due 1 Jan 2028 (24
  months), with implicit annual interest rate ~5%
- **WHEN** the `TransactionPrice` is created
- **THEN** a `significantFinancingComponent` (approx EUR 100K) MUST be populated
  and disclosed per IFRS 15.60-62.

### REQ-IFRS15-003: Variable consideration MUST be re-estimated at least monthly (or per administration policy), with constraint re-assessment and audit trail

The variable-consideration estimate (rebates, discounts, bonuses, refunds) MUST
be recalculated at least once per reporting period (default: monthly, customisable
per administration). Each re-estimation MUST:

1. Recalculate the unconstrained variable-consideration amount (expected value or
   most likely amount) based on current actuals and revised assumptions.
2. Re-assess the constraint limit per IFRS 15.56 and document the reason if the
   constraint changes.
3. Calculate the delta from prior period and post a compensating adjustment to GL
   if the constrained amount increases (additional revenue recognition) or decreases
   (revenue reversal) per REQ-IFRS15-007.
4. Log the re-estimation event in audit trail with timestamp, operator, prior
   estimate, new estimate, and reason.

#### Scenario: Monthly re-estimation adjusts constrained variable consideration

- **GIVEN** contract with variable-consideration estimate EUR 50K (constrained to
  30K) recorded in Jan
- **WHEN** Feb actuals show only 5K of the rebates will likely trigger (revised
  estimate EUR 25K, constrained to 25K per materiality test)
- **THEN** a `VariableConsiderationAdjustment` entry is logged (reversal EUR 5K),
  GL is posted (credit revenue, debit accrued revenue), and the revenue waterfall
  is updated.

### REQ-IFRS15-004: Allocation of transaction price MUST default to relative stand-alone selling price (SSP) method, with residual-method support and recalculation on modification

The system SHALL satisfy this requirement: Allocation of transaction price MUST default to relative stand-alone selling price (SSP) method, with residual-method support and recalculation on modification.

`PriceAllocation` records the allocation of the total transaction price across
performance obligations per IFRS 15.73-79. The allocation method MUST be one of:

1. **Relative SSP** (default): `allocatedPrice[i] = SSP[i] / SUM(SSP) × totalPrice`
2. **Residual method** (exception, per IFRS 15.79): If SSP is highly uncertain,
   allocate to POs with reliable SSP first using relative method; allocate
   remainder to PO with uncertain SSP.

Allocation MUST be:
- Calculated once when contract is signed.
- Re-calculated when `ContractModification` modifies PO scope or SSP.
- Re-calculated when variable-consideration constraint changes materially (>10%).
- Stored per PO (not derived each time) for audit trail of original allocation
  intent.

#### Scenario: Relative SSP allocation across three POs

- **GIVEN** a contract with:
  - PO-1 (SaaS subscription): SSP EUR 300K
  - PO-2 (implementation): SSP EUR 40K
  - PO-3 (usage-based): SSP EUR 80K (uncertain, but historical data gives 80K estimate)
  - Total transaction price: EUR 360K
- **WHEN** relative SSP allocation is applied
- **THEN**:
  - PO-1 allocated: 300/420 × 360 = EUR 257.14K
  - PO-2 allocated: 40/420 × 360 = EUR 34.29K
  - PO-3 allocated: 80/420 × 360 = EUR 68.57K
  - Total: EUR 360K (tying back exactly)

#### Scenario: Residual method when one SSP is too uncertain

- **GIVEN** a contract with:
  - PO-1: SSP EUR 100K (reliable, market data)
  - PO-2: SSP EUR ??? (customized service, no comparable market data)
  - Total transaction price: EUR 150K
- **WHEN** allocation method is set to residual per IFRS 15.79
- **THEN**:
  - PO-1 allocated: EUR 100K (relative SSP)
  - PO-2 allocated: EUR 50K (residual = 150 - 100)

### REQ-IFRS15-005: Over-time performance obligations MUST support input methods (cost-to-cost, labour-hours, machine-hours) and output methods (units delivered, milestones, time-elapsed)

`PerformanceObligation` MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `satisfactionPattern` | enum | Yes | `point-in-time` or `over-time` |
| `outputMethod` | enum | No (if over-time) | `units-delivered`, `milestones`, `time-elapsed`, `percentage-of-completion` |
| `inputMethod` | enum | No (if over-time) | `cost-to-cost`, `labour-hours`, `machine-hours`, `units-produced` |
| `costBasis` | FK (to project cost) | No (if input method) | Reference to project cost register for actual + estimated cost-to-date |
| `estimatedTotalCost` | MonetaryAmount | No (if input method) | Original estimated total cost; updated if revised forecast issued |
| `actualCostToDate` | MonetaryAmount | No (if input method) | Sourced from project module; current-period actuals |
| `revisedTotalEstimatedCost` | MonetaryAmount | No (if cost-to-cost) | Updated estimated total; triggers margin recalculation and impairment test |
| `percentageComplete` | number (0-100) | No (if input method) | Calculated as `actualCostToDate / revisedTotalEstimatedCost × 100` |
| `statusAtPeriodEnd` | enum | Yes | `not-started`, `in-progress`, `complete`, `cancelled` |

For input methods, the percentage of completion MUST be re-estimated every period
and used as the basis for cumulative revenue recognition (REQ-IFRS15-006).

For output methods, `RevenueRecognitionEvent` entries are posted with units delivered
or milestone achieved; no calculation needed.

#### Scenario: Cost-to-cost input method with revised estimate

- **GIVEN** PO with:
  - Original estimated total cost: EUR 800K
  - Transaction price (allocated): EUR 1M
  - Month 6: actual cost EUR 480K, revised total estimate EUR 900K
- **WHEN** period-end cut-off runs
- **THEN**:
  - % complete = 480K / 900K = 53.3%
  - Cumulative revenue = 53.3% × 1M = EUR 533K
  - Period revenue = EUR 533K - prior cumulative (delta to post to GL)
  - Revised gross margin = 1M / 900K - 1 = 11% (vs. original 25%) → alert for onerous-contract test

#### Scenario: Output method with units delivered

- **GIVEN** PO for 1000 software licenses, allocated price EUR 100K (EUR 100 per license)
- **WHEN** month 1 reports 250 licenses delivered, month 2 reports 300 licenses
- **THEN**:
  - Month 1 revenue: 250 × 100 = EUR 25K
  - Month 2 revenue: 300 × 100 = EUR 30K
  - No input method calculation needed; evidence is delivery note or shipment record.

#### Scenario: Time-elapsed method for monthly SaaS

- **GIVEN** 36-month SaaS subscription, allocated price EUR 257.14K (EUR 7,143.87 per month)
- **WHEN** each month-end closes
- **THEN**: Monthly revenue = EUR 7,143.87 (no further calculation; `RevenueRecognitionEvent`
  entries are auto-generated on 1st of month or on contract anniversary).

### REQ-IFRS15-006: Contract modifications MUST be classified per IFRS 15.18-21 and applied automatically with documented overrideability

`ContractModification` MUST classify modification type per IFRS 15.18-21:

| Type | Condition | Treatment |
|---|---|---|
| `new-contract` | Distinct goods/services at SSP not previously promised | Create separate contract; original untouched |
| `not-distinct-cumulative` | Not distinct from existing POs; pricing is cumulative | Combine into existing PO; recalculate allocation across all original + modified POs |
| `prospective` | Price-only change; no scope change | Update `TransactionPrice` for original contract; allocation unchanged; adjust future periods only |

System MUST auto-classify based on:
1. Whether modification is for goods/services already in scope (not distinct → cumulative or prospective).
2. Whether modification introduces distinct new scope (new contract).
3. Whether modification is price-only (prospective).

Classification MUST be overrideable with documented reason (e.g., "Customer insisted on separate contract for legal reasons despite technical integration").

#### Scenario: Distinct module addition = new contract

- **GIVEN** contract C-2026-001 with 3 POs (SaaS, implementation, usage-based)
- **WHEN** month 12 modification adds new custom-integration PO at SSP EUR 25K
- **THEN**:
  - System proposes: `new-contract` (integration not in original scope, distinct service)
  - Operator confirms; new contract C-2026-001-MOD-1 created with 1 PO
  - Original C-2026-001 remains untouched; both waterfall independently calculated

#### Scenario: Price adjustment = prospective treatment

- **GIVEN** contract with fixed price EUR 100K/year signed at 2026-01-01
- **WHEN** month 6 inflation adjustment adds EUR 10K for year 2 (2027-01-01 onward)
- **THEN**:
  - System proposes: `prospective` (price change, no scope change)
  - Revenue for 2026 unchanged (already accrued)
  - Revenue for 2027 updated to EUR 110K; prior allocation unchanged

### REQ-IFRS15-007: Contract asset / contract liability balances MUST be calculated nightly and posted to GL via idempotent reversal + fresh-post job, with full traceability

Per IFRS 15.116-119, a nightly job MUST:

1. For each contract, calculate:
   - `ContractAsset` = cumulative recognised revenue - cumulative billed amount
     (if >0; right to consideration)
   - `ContractLiability` = cumulative billed amount - cumulative recognised
     revenue (if >0; deferred revenue)
2. Compare to prior-period balance; calculate net movement (delta).
3. For each contract with a delta:
   - REVERSE all prior-period GL lines (`deferred-revenue` / `accrued-revenue`
     control accounts)
   - POST fresh lines for current balances
   - Record the before/after snapshot in audit trail
4. Post the net delta to GL: credit `deferred-revenue` account if liability increased
   (more billed than recognised); debit `accrued-revenue` account if asset
   increased (more recognised than billed).
5. Ensure fiscal period is open per `bookkeeping-period-close` rules
   (REQ-PC-004 or equivalent).

Job MUST be idempotent: re-running on the same period-end snapshot yields
identical GL lines (no double-posting). Retry-safe via reversal pattern.

#### Scenario: Nightly job recognises deferred revenue as PO completes

- **GIVEN**:
  - Month 1: Contract issued invoice EUR 90K, recognised EUR 30K (first month of SaaS)
  - Contract Liability: EUR 60K (deferred)
  - GL posted: CR deferred-revenue 60K / DR accrued-revenue 0
- **WHEN** month 2 ends with:
  - Cumulative recognised: EUR 60K (2 months)
  - Cumulative billed: EUR 90K (still first invoice)
  - Contract Liability: EUR 30K (60K reduction in deferral)
- **THEN** nightly job:
  - Reverses prior-period GL: DR deferred-revenue 60K / CR accrued-revenue 0
  - Posts fresh lines: CR deferred-revenue 30K / DR accrued-revenue 0
  - Net effect: CR deferred-revenue 30K (liability reduced by 30K) → revenue recognised

#### Scenario: Job is idempotent; re-run yields no duplicate lines

- **GIVEN** month-end job completes successfully; GL lines posted
- **WHEN** job is manually re-triggered on same period (e.g., dry-run mode)
- **THEN**: Reversal + fresh-post cycle yields identical GL lines; no duplicates
  or net-zero lines.

### REQ-IFRS15-008: Revenue waterfall MUST be available per contract and aggregated by segment/customer/product, showing transaction price allocated and recognised by period for 60+ months

`RevenueWaterfall` register MUST store:

| Field | Type | Purpose |
|---|---|---|
| `contractId` | string | FK to Contract |
| `periodStart` | date | Month/quarter start date (granularity per administration config) |
| `periodEnd` | date | Month/quarter end date |
| `transactionPriceAllocated` | MonetaryAmount | Total contract transaction price allocated to POs active in this period |
| `priorCumulativeRecognised` | MonetaryAmount | Cumulative recognised in prior periods |
| `periodRecognised` | MonetaryAmount | Recognised in this period only |
| `cumulativeRecognised` | MonetaryAmount | Total recognised through period end |
| `remainingAmount` | MonetaryAmount | `transactionPriceAllocated - cumulativeRecognised` |
| `remainingMonths` | integer | Estimated months until POs complete |
| `deferredLiability` | MonetaryAmount | Amount billed but not yet recognised (contract liability) |
| `accrualAsset` | MonetaryAmount | Amount recognised but not yet billed (contract asset) |

Waterfall MUST be:
- Calculated nightly after cut-off job and posted for the current period.
- Forecast-forward for 60 months (IFRS 15.120: "information about when the
  entity will recognise these amounts").
- Aggregatable by customer, segment (product, geography, contract type), and
  consolidated (all contracts).

#### Scenario: Waterfall shows 36-month SaaS revenue recognition cadence

- **GIVEN** 36-month SaaS contract allocated EUR 257.14K (EUR 7,143.87/month)
- **WHEN** waterfall is generated at contract signing
- **THEN**:
  - Months 1–36: EUR 7,143.87 per month
  - Month 37+: EUR 0 (contract complete)
  - Cumulative by month 36: EUR 257.14K
  - Remaining amount: EUR 0 by month 36
  - Disclosure shows: "All performance obligations satisfied by 28 Feb 2029"

#### Scenario: Waterfall for construction contract shows concentrated recognition mid-project

- **GIVEN** 12-month construction contract, transaction price EUR 1M
- **WHEN** cost-to-cost % complete varies (slow ramp in months 1–3, acceleration
  months 4–9, wind-down months 10–12)
- **THEN** waterfall shows:
  - Months 1–3: ~EUR 80K–150K recognised (depending on % complete)
  - Months 4–9: ~EUR 150K–200K/month (peak progress)
  - Months 10–12: ~EUR 100K–150K (tail-off)
  - Forecast aligns with project-management milestone schedule

### REQ-IFRS15-009: Costs to obtain and fulfil a contract MUST be capitalised when criteria per IFRS 15.91-95 are met, amortised on PO satisfaction pattern, and tested for impairment

`ContractCostAsset` register MUST store:

| Field | Type | Purpose |
|---|---|---|
| `contractId` | string | FK to Contract |
| `costType` | enum | `obtain` (sales commission, proposal costs) or `fulfil` (setup, customisation) |
| `description` | text | Description of the cost (e.g., "Sales commission 5%", "System setup labor") |
| `initialCapitalisation` | MonetaryAmount | Amount capitalised at contract inception |
| `amortisationSchedule` | enum | `straight-line` or `matching-po-satisfaction` |
| `poSatisfactionPattern` | string | FK to related `PerformanceObligation` satisfaction pattern; amortisation aligns to this |
| `amortisedToDate` | MonetaryAmount | Cumulative amortisation to period end |
| `carriedAmount` | MonetaryAmount | `initialCapitalisation - amortisedToDate` |
| `impairmentTestDate` | date | Date of last impairment assessment |
| `impairmentIndicators` | text | Comments on recoverability (e.g., "Contract margin decreased; perform impairment test") |

Capitalised costs MUST:
- Meet the incremental-cost test per IFRS 15.91: direct incremental costs that
  would not be incurred but for the contract.
- Be recovered from the contract revenue (not from other customers).
- Be amortised on the same pattern as the related PO satisfaction (e.g., if PO
  is recognised over 36 months, cost amortised over 36 months; if PO is point-in-
  time, cost amortised on recognition date).
- Be tested for impairment each reporting period: if contract margin turns negative
  or contract is onerous (costs > expected recovery), impairment is recognised.

#### Scenario: Sales commission on 3-year SaaS is capitalised and amortised

- **GIVEN** 36-month SaaS contract, transaction price EUR 360K, allocated to SaaS PO
  (over-time, 36 months)
- **WHEN** sales commission (5% × 360K = EUR 18K) is paid upfront
- **THEN**:
  - `ContractCostAsset` capitalised: EUR 18K
  - Amortisation: EUR 500/month (18K ÷ 36)
  - Aligned to SaaS PO satisfaction (time-elapsed, 1 month per month)
  - Month 36 carried amount: EUR 0 (fully amortised)

#### Scenario: Impairment test on onerous contract

- **GIVEN** contract with fixed price EUR 1M, estimated cost EUR 800K (20% margin)
- **WHEN** month 6 actualised costs EUR 480K with revised total estimate EUR 900K
  (90% margin reversed to -10% margin)
- **THEN**:
  - Impairment indicator triggered (contract onerous)
  - If any `ContractCostAsset` exists, carriedAmount is written down to
    recoverability limit
  - GL posting: DR contract-cost-impairment / CR contractcostasset (reduces
    capitalised balance)

### REQ-IFRS15-010: System MUST produce the full IFRS 15.110-129 disclosure pack: revenue disaggregation, contract balance reconciliation, remaining POs, significant judgements, and accounting policies

The disclosure pack MUST include:

1. **Revenue Disaggregation** (IFRS 15.114–115):
   - By contract type (SaaS, consulting, construction, etc.)
   - By customer geography (Netherlands, Europe, Rest-of-World)
   - By product line or business segment
   - By performance-obligation satisfaction pattern (point-in-time vs. over-time)

2. **Contract Balance Reconciliation** (IFRS 15.116–119):
   - Opening contract asset / contract liability per segment
   - Additions (new contracts signed)
   - Modifications (amendments to existing contracts)
   - Revenue recognised (reducing liability, increasing asset if applicable)
   - Reclassifications (contract asset → receivable upon invoicing)
   - Closing balances
   - Aged analysis of deferred revenue by maturity

3. **Remaining Performance Obligations** (IFRS 15.120):
   - Total remaining transaction price per contract
   - Forecast recognition timeline (next 60 months minimum)
   - Estimated timing of settlement ("by 31 Dec 2027" or "ratably 2027–2029")
   - Aggregated by segment and consolidated

4. **Significant Judgements and Estimates** (IFRS 15.122–123):
   - Variable-consideration constraints: amounts constrained and reasons
   - Significant financing components: calculations and impact
   - Principal-vs-agent assessments: rationale for gross vs. net revenue
   - Performance-obligation satisfaction pattern selection (point-in-time vs.
     over-time) and justification
   - Contract-cost capitalisation decisions and impairment assessments

5. **Accounting Policies** (IFRS 15.126–129):
   - Five-step model summary
   - SSP determination method (market data, cost-plus, residual)
   - Variable-consideration estimation method (expected value vs. most likely)
   - Re-estimation frequency (monthly, quarterly, on-demand)
   - Contract-modification classification and treatment
   - Costs-to-obtain/fulfil capitalisation criteria and amortisation method

All disclosure data MUST be:
- Exportable to PDF (summary disclosure + data tables)
- Exportable to XBRL (structured GL instance per GL module)
- Exportable to JSON (for integration into consolidated reporting systems)
- Navigable in the UI (interactive dashboards with drill-down by segment, customer, PO)

#### Scenario: Disclosure pack for multi-country SaaS company

- **GIVEN** SaaS company with contracts in Netherlands, Germany, France
- **WHEN** period-end close runs
- **THEN** disclosure pack includes:
  - Revenue disaggregation: EUR 1.2M SaaS (over-time), EUR 300K consulting
    (point-in-time), EUR 50K add-ons (variable)
  - Contract balances:
    - Opening contract asset EUR 100K (recognised > billed)
    - New contracts EUR 500K
    - Revenue recognised (500 - 100 = 400K)
    - Closing asset EUR 200K
  - RPO: EUR 2.5M over next 18 months, with monthly forecast chart
  - Significant judgements: "Variable add-on constrained to 10% of base SaaS due to
    historical volatility"
  - Policies: "SaaS recognised ratably over subscription term; consulting on
    delivery; costs capitalised as contract-setup labor per IFRS 15.91"

### REQ-IFRS15-011: System MUST support contract-group (combination of contracts) treatment per IFRS 15.17

The system SHALL satisfy this requirement: System MUST support contract-group (combination of contracts) treatment per IFRS 15.17.

A `Contract` MAY declare a `contractGroupId` to indicate that it is combined with
other contracts for revenue recognition purposes per IFRS 15.17 (when contracts are:
(a) negotiated as a package, (b) satisfy common performance obligation, or (c)
consideration from one affects the other).

When contracts are grouped:
- Transaction prices are combined for aggregated PO allocation purposes (if common POs)
- Revenue waterfall MAY be displayed at group level
- Disclosure flags the group relationship and rationale

#### Scenario: Multi-year SaaS + services bundle = combined contract

- **GIVEN** customer negotiates:
  - Year 1: 12 months SaaS EUR 120K + implementation EUR 40K
  - Years 2–3: SaaS EUR 130K/year + annual support EUR 20K/year
  - Total EUR 420K for 3-year bundle
- **WHEN** both orders are signed as a package with interlocked commercial terms
- **THEN**:
  - Both contracts linked via `contractGroupId: GROUP-001`
  - Combined transaction price EUR 420K allocated across all POs as if single contract
  - Disclosure notes: "Contracts C-2026-001 and C-2026-002 are combined per IFRS 15.17
    (interlocked commercial terms)"

## Testing Scenarios (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle is
responsible for:

- **Unit Tests**: Revenue-waterfall aggregation logic (verify % complete calculation,
  allocation accuracy, GL posting balances).
- **Integration Tests**: Cost-to-cost sourcing from project-accounting module;
  contract-modification classification and GL reversal/posting; nightly cut-off
  idempotence.
- **User-Persona Tests** (ADR-030 journeydoc): CFO revenue-forecast dashboard,
  bookkeeper contract-entry workflow, auditor disclosure-pack inspection.

## Verification

`openspec validate` must exit clean on the change folder.

Auditor-persona peer review (Big-4 or Dutch mid-market audit team) confirms:
- All 10 registers match IFRS 15 five-step structure and IFRS 15 Illustrative
  Examples (especially IE7-IE10 for over-time revenue)
- Five-step process is traceable end-to-end (contract → PO → price → allocation →
  recognition event → GL posting)
- Variable-consideration constraint and re-estimation audit trail meets audit
  defence standards
- Nightly cut-off job is idempotent and GL-compliant (balanced postings, no
  double-posting)
- IFRS 15.110-129 disclosure structure covers all required disclosures
- Dutch IFRS / BW2 Title 9 alignment is confirmed

No source code changes outside `openspec/changes/bookkeeping-ifrs15-revenue/`.

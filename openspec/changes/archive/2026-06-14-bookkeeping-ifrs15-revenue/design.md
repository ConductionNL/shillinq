# Design — IFRS 15 Five-Step Revenue Recognition

## Context

IFRS 15 / ASC 606 revenue recognition has been mandatory for listed companies
since 1 January 2018 and is increasingly demanded by Dutch SMEs publishing IFRS-
aligned annual accounts (BW2 Title 9). SaaS subscriptions with implementation,
project consultancy, construction contracts, telecoms bundles, and contracts with
variable consideration (rebates, volume discounts, milestone bonuses) require
explicit performance-obligation tracking and timed recognition. Simple "invoice =
revenue" posting is non-compliant.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
standard Hydra pipeline; this doc explains *why* the shape is what it is and
how the architecture ensures GAAP correctness and audit transparency.

## Goals

- Implement the **five-step IFRS 15 model** as declarative schemas + lifecycles +
  materialisations per ADR-031 — not a service-driven calculation engine.
- Produce **GAAP-correct deferred and accrued revenue balances** per contract per
  period, reconcilable to invoice and GL.
- Provide a **revenue waterfall** showing contract transaction price flowing into
  recognised revenue over time, segmented by performance obligation.
- Support **all five satisfaction patterns** (point-in-time, over-time with output/
  input methods, and variable consideration) in a unified contract data model.
- Enable **audit defence** through complete recognition timeline, variable-
  consideration estimates with constraints, and contract-modification audit trail.
- Declare the **IFRS 15.110-129 disclosure structure** so T4 can export to PDF,
  XBRL, JSON.
- Integrate **bidirectionally with quote-to-cash** (contract originates from order)
  and **project-accounting** (input-method cost sourcing).

## Non-Goals

- No PHP revenue-calculation service (per ADR-031 — revenue is schema + lifecycle
  + materialisation).
- No UBL 2.1 / Peppol BIS 3.0 outbound emission — T4.
- No multi-currency translation — T5.
- No segment reporting disaggregation rules — T4.

## Decisions

### D1 — Revenue recognition is driven by performance-obligation satisfaction events

`Contract` contains one or more `PerformanceObligation` instances, each with
satisfaction pattern (point-in-time | over-time) and method (output units,
milestones, time elapsed, cost-to-cost, labour-hours). Each `RevenueRecognitionEvent`
records that a PO moved from "not started" to "in progress" or "complete" (or
intermediate % complete). The nightly cut-off job queries `RevenueRecognitionEvent`
rows within the period and recognises revenue accordingly.

*Rationale*: Decouples the billing event (when invoice is issued) from the
recognition event (when performance obligation is satisfied). Essential for SaaS
(monthly subscription billed upfront, recognised over month) and construction
(progress % drives recognition, not billing schedule).

### D2 — Contract lifecycle is declarative with explicit modification handling

`Contract` declares a state machine (draft → signed → in-delivery → completed →
archived / voided) per ADR-022. `ContractModification` records are *separate
entities*, not inline edits, so every modification is traceable and classified per
IFRS 15.18-21 (new contract, cumulative catch-up, prospective). The classification
determines how the old transaction-price allocation reverses and the new one takes
effect.

*Rationale*: IFRS 15.18-21 compliance requires explicit modification accounting.
Inline edits lose the audit trail; separate `ContractModification` entities + their
classification provide the transparency audit teams demand.

### D3 — Transaction price is decomposed into fixed + variable + adjustments

`TransactionPrice` is not a single amount field but a structured register with:
- Fixed consideration (guaranteed cash/near-cash)
- Variable consideration (rebates, volume discounts, performance bonuses, refunds)
- Significant financing component adjustment (if payment >12 months out)
- Non-cash consideration at fair value
- Consideration payable to customer (price reduction)

Variable consideration is estimated using expected value or most likely amount,
constrained per IFRS 15.56 (highly probable not to reverse), with the constraint
reason documented.

*Rationale*: IFRS 15.50-58 requires explicit variable-consideration handling with
constraint. Storing as a single "transaction price" field hides the complexity;
decomposing into components makes the constraint and re-estimation audit trail
visible.

### D4 — Price allocation defaults to relative stand-alone selling price (SSP)

`PriceAllocation` records the per-PO allocated amount computed via relative SSP
(IFRS 15.74): sum each PO's SSP, allocate transaction price proportionally. If SSP
is highly variable or uncertain, residual method applies (allocate to POs with
reliable SSP first, remainder to PO with uncertain SSP per IFRS 15.79). Allocation
is recalculated on modification or variable-consideration re-estimation.

*Rationale*: SSP is the anchor for allocation fairness. Relative method is IFRS
15's default; residual is exception. Storing the allocation per PO (not deriving it
each time) preserves the original allocation decision for audit.

### D5 — Contract asset / contract liability balances are calculated nightly

Per IFRS 15.116-119, on every reporting date, `ContractAsset` (when recognised >
billed; right to consideration) and `ContractLiability` (when billed > recognised;
deferred revenue) balances are recalculated per contract via an idempotent nightly
job. The job REVERSEs all prior-period GL lines, then POSTs fresh lines, ensuring
no double-posting. Control accounts are deferred-revenue and accrued-revenue per
GL architecture.

*Rationale*: Revenue and billing may not align; the gap is the contract asset or
liability. Nightly recalculation ensures the GL is always synchronized with
recognition events. Reversal + fresh post is idempotent, tolerating retries.

### D6 — Costs to obtain and fulfil are capitalised when criteria met

Per IFRS 15.91-104, incremental costs to obtain (e.g. sales commission) or fulfil
(e.g. setup labor) a contract are capitalised as `ContractCostAsset` when the
entity expects to recover them from contract revenue. Capitalised costs are
amortised on the same pattern as the related PO satisfaction (time-elapsed, cost-
to-cost %, etc.) and tested for impairment at each reporting date.

*Rationale*: Contract costs that will be matched to future revenue periods should
not be expensed immediately; capitalising and amortising them aligns P&L to revenue
timing. Scope is tightly constrained per IFRS 15.91-95 to avoid abuse.

### D7 — Remaining performance obligations (RPO) are aggregated per contract and segment

`RevenueWaterfall` aggregates per contract the total transaction price, recognises
so far, and remaining amount, forecasted over the next 60+ months (IFRS 15.120).
T4 adds segment filtering (by customer, geography, product). The waterfall is a
time-series view (not a snapshot), updated on each recognition event and
modification.

*Rationale*: IFRS 15.120 discloses RPO and its timing; users demand forward-looking
revenue forecasts. The waterfall is the single source of truth for "what revenue
will we recognise when?".

### D8 — IFRS 15 disclosure structure is declared, not computed

The disclosure pack (contract balance reconciliation, RPO, revenue disaggregation,
significant judgements, accounting policies per IFRS 15.110-129) is structured in
the schema so T4 can export it. T2 declares the fields and aggregation rules; T4
adds the outbound (PDF, XBRL, JSON).

*Rationale*: Disclosure is regulation-mandated; baking the structure into the spec
ensures no field is forgotten and audit teams can validate completeness.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Contract lifecycle (draft → signed → delivery → completed) | OR `x-openregister-lifecycle` (ADR-022) | Declare on `Contract` entity; `ContractModification` records are separate entities classified per IFRS 15.18-21 |
| PO satisfaction tracking | Project-accounting module milestones + timesheet cost | FK reference from `PerformanceObligation` to project cost/milestone; input-method % complete sourced from project module |
| Contract-asset/liability calculation | T1 GL materialisation pattern | Nightly cut-off job REVERSEs + POSTs GL lines to deferred/accrued control accounts per T1 REQ-GL-007 pattern |
| Remaining-PO aggregation | OR `x-openregister-aggregations` | `RevenueWaterfall` aggregation query grouping PO by contract, summing remaining amount by month for 60+ months |
| Variable-consideration re-estimation timeline | Administration configuration | Per-administration setting (monthly at close, quarterly, on-demand); defaults to monthly; stored in administration register |
| Contract-modification audit trail | T2 `bookkeeping-audit-trail` (if available) | `ContractModification` entity + automatic audit trail on all state transitions per ADR-030 |
| Segment reporting dimensions | T4 revenue-reporting / segment module | `Contract` + `PerformanceObligation` FK to customer, product, geography; segment rules + dimension tables in T4 |
| GL control accounts | T1 Account register | Deferred-revenue and accrued-revenue accounts per Chart of Accounts; nightly job POSTs to these |
| Quote-to-cash integration | `bookkeeping-quote-order-invoice` module | `Contract` FK to `SalesOrder` / `Quote` (same legal instrument); sales-order fields prefill `Contract` form |

## Seed Data (Dutch examples)

### Example 1: 36-month SaaS contract with bundled POs and variable consideration

**Contract**
- `contractNumber`: C-2026-001
- `customer`: ABC SaaS BV (KvK 123456)
- `signedAt`: 2026-01-01
- `startDate`: 2026-01-01, `endDate`: 2028-12-31
- `fixedConsideration`: EUR 360,000
- `variableConsideration`: EUR 0 (estimated, capped at EUR 60K per PO-3 constraint)
- `currency`: EUR

**PerformanceObligation 1: SaaS Subscription (monthly software subscription)**
- `description`: Cloud-based ERP subscription, 36 months
- `satisfactionPattern`: over-time
- `outputMethod`: time-elapsed (monthly)
- `ssp`: EUR 300,000
- `allocatedPrice`: EUR 257,143 (relative SSP: 300K / 420K * 360K)
- `status`: in-progress

**PerformanceObligation 2: Implementation Service (one-off)**
- `description`: System setup, data migration, user training
- `satisfactionPattern`: point-in-time
- `completionDate`: 2026-02-28
- `ssp`: EUR 40,000
- `allocatedPrice`: EUR 34,286 (relative SSP: 40K / 420K * 360K)
- `status`: complete

**PerformanceObligation 3: Usage-based Add-on (variable consideration)**
- `description`: API calls and storage beyond standard package, capped
- `satisfactionPattern`: over-time
- `outputMethod`: usage-reported (units)
- `ssp`: EUR 80,000
- `allocatedPrice`: EUR 68,571 (relative SSP: 80K / 420K * 360K)
- `variableConsiderationEstimate`: EUR 30,000 (expected value of historical customer usage pattern)
- `constraintReason`: Probability of reversal >20% based on market volatility; constrained to 30K (conservative estimate)
- `status`: in-progress

**RevenueWaterfall (month 6 snapshot)**
- PO-1 recognised: EUR 42,857 (6 months × 7,143.80/month)
- PO-2 recognised: EUR 34,286 (completed 28 Feb)
- PO-3 recognised: EUR 12,000 (estimated based on usage reports, within constraint)
- Total recognised: EUR 89,143
- Total billed (as invoiced): EUR 90,000 (first 3 months at EUR 30K/month)
- Contract Liability: EUR 857 (billed > recognised)

### Example 2: Construction contract with cost-to-cost input method

**Contract**
- `contractNumber`: C-2026-050
- `customer`: Bouw NL BV
- `signedAt`: 2025-11-15
- `startDate`: 2025-12-01
- `estimatedEndDate`: 2026-11-30 (12 months)
- `transactionPrice`: EUR 1,000,000

**PerformanceObligation: Construction Services**
- `description`: Design + build new office facility (single PO, entire contract)
- `satisfactionPattern`: over-time
- `inputMethod`: cost-to-cost
- `baseCostEstimate`: EUR 800,000
- `ssp`: EUR 1,000,000
- `allocatedPrice`: EUR 1,000,000 (single PO = entire contract)
- `status`: in-progress

**At Period 6 (June 2026)**
- `actualCostIncurred`: EUR 480,000
- `revisedTotalEstimatedCost`: EUR 900,000 (increased scope)
- `percentageComplete`: 53.3% (480K / 900K)
- `cumulativeRevenueRecognised`: EUR 533,000 (53.3% × 1M)
- `periodRevenueRecognised`: EUR 83,000 (net of prior-period cumulative)
- `revisedGrossMargin`: 10% (down from original 20%)

**ContractCostAsset: Direct Materials + Overheads**
- `capitalised`: EUR 120,000 (incremental setup costs, design, project mgmt)
- `amortisationSchedule`: 12 months, matching PO satisfaction
- `amortised-to-date`: EUR 60,000 (6 months)
- `carriedAmount`: EUR 60,000

### Example 3: Multi-year software maintenance contract with annual price increase

**Contract**
- `contractNumber`: C-2026-100
- `customer`: Enterprise Corp NV
- `signedAt`: 2026-01-15
- `startDate`: 2026-03-01
- `endDate`: 2029-02-28 (36 months)
- Year 1: EUR 120,000
- Year 2: EUR 130,000 (8.3% escalation)
- Year 3: EUR 140,300 (7.9% escalation)
- `totalTransactionPrice`: EUR 390,300

**PerformanceObligation: Maintenance & Support**
- `description`: Software maintenance, 24/7 support, quarterly updates
- `satisfactionPattern`: over-time
- `outputMethod`: time-elapsed (annual)
- `ssp`: EUR 390,300
- `allocatedPrice`: EUR 390,300 (single PO)
- `status`: in-progress

**RevenueRecognitionEvents**
- 2026-03-01 to 2027-02-28: EUR 120,000 (year 1)
- 2027-03-01 to 2028-02-28: EUR 130,000 (year 2)
- 2028-03-01 to 2029-02-28: EUR 140,300 (year 3)

### Example 4: Contract modification (scope change = new contract)

**ContractModification (Month 12)**
- `parentContractNumber`: C-2026-001
- `modificationDate`: 2027-01-01
- `type`: new-distinct-scope (per IFRS 15.20(a))
- `description`: Additional module integration services, separate from original POs
- `newTransactionPrice`: EUR 25,000
- `treatment`: new-contract (original C-2026-001 untouched; new contract C-2026-001-MOD-1 created)

**New PerformanceObligation (from modification)**
- `description`: Custom integration module development
- `satisfactionPattern`: over-time
- `inputMethod`: labour-hours
- `ssp`: EUR 25,000
- `allocatedPrice`: EUR 25,000 (standalone price, no allocation needed)
- `status`: not-started

## Metrics & KPIs

- **Total Contracted Revenue (TCR)** per customer, segment, month: sum of all `Contract.transactionPrice` where `Contract.startDate <= period <= Contract.endDate`.
- **Remaining Performance Obligations (RPO)** per contract: `SUM(allocatedPrice - recognised) FOR all POs` where `status != complete`.
- **Contract Asset / Liability Balance**: monthly snapshot per contract; trend analysis for cash-flow forecasting.
- **Revenue Recognition Timeline**: forecast of recognised revenue by month (from `RevenueWaterfall` aggregation).
- **Variable Consideration Re-estimation Variance**: month-on-month change in constrained variable-consideration estimate (identifies accuracy issues).

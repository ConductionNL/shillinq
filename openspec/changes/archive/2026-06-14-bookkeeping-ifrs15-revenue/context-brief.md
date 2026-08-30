---
status: draft
---
# IFRS 15 Five-Step Revenue Recognition

## Purpose

Provide a complete, auditable implementation of the IFRS 15 / ASC 606 five-step revenue recognition model inside shillinq, replacing the simplistic "invoice = revenue" assumption that legacy SME bookkeeping packages still ship with. IFRS 15 has been mandatory for listed companies since 1 January 2018 and is increasingly demanded by Dutch SMEs that publish IFRS-aligned annual accounts (BW2 Title 9), grow toward an IPO, or supply enterprise customers who require IFRS-compliant invoicing of their software/services spend. SaaS subscriptions, project-based consultancy, construction contracts, telecoms bundles, and any contract with variable consideration (rebates, volume discounts, milestone bonuses, refund obligations) require explicit performance-obligation tracking and timed recognition.

The module implements all five steps as first-class entities: (1) identify the contract with a customer, (2) identify the separate performance obligations (POs) in the contract, (3) determine the transaction price, (4) allocate that price across POs using stand-alone selling prices (SSP), (5) recognise revenue when (or as) each PO is satisfied. Around these it provides the supporting machinery: contract modifications, variable consideration constraint, significant financing component, principal-vs-agent assessment, costs to obtain/fulfil a contract (capitalised per IFRS 15.91-104), and the disclosures required by IFRS 15.110-129 (disaggregation, contract balances, remaining performance obligations).

The deliverable is a revenue subledger that produces (a) GAAP-correct deferred and accrued revenue balances per contract per period, (b) a revenue waterfall showing how each contract's transaction price flows into recognised revenue over time, (c) a transition view between billed (invoiced) and recognised revenue (the "rev rec gap" that auditors live for), and (d) the complete IFRS 15 disclosure note. The output integrates with the consultancy/project-accounting module (for over-time recognition with percentage-of-completion or input/output methods) and the quote-to-cash funnel (the contract under IFRS 15 is the same legal instrument as the sales order).

## Data Model

- **Contract**: customer reference, contract identification, start/end dates, total transaction price (fixed + estimated variable), currency, signed-at date, modifications history, related quote/order reference, IFRS 15 "combination of contracts" group.
- **PerformanceObligation (PO)**: parent contract, description, distinct flag (per IFRS 15.27), satisfaction pattern (point-in-time | over-time), output method (units delivered, milestones, time elapsed) or input method (cost-to-cost, labour hours), stand-alone selling price (SSP), allocated transaction price, status (not started | in progress | complete | cancelled).
- **TransactionPrice**: components (fixed, variable, financing adjustment, non-cash consideration, consideration payable to customer), variable-consideration estimate, estimation method (expected value | most likely amount), constraint (limited to amount highly probable not to reverse).
- **PriceAllocation**: per PO allocation amount, allocation method (relative SSP, residual, expected cost plus margin), recalculation triggers on modification.
- **RevenueRecognitionEvent**: PO reference, period, recognised amount, basis (units delivered, % complete, milestone achieved), supporting evidence (timesheet entry, delivery note, milestone sign-off).
- **ContractAsset / ContractLiability**: derived nightly per contract; contract asset when recognised > billed (right to consideration), contract liability when billed > recognised (deferred revenue).
- **ContractModification**: type (additional distinct goods/services at SSP = new contract; not distinct = cumulative catch-up; price-only = prospective), effective date, before/after snapshot for audit.
- **VariableConsiderationAdjustment**: rebate, refund, performance bonus, penalty, volume discount, with periodic re-estimation log.
- **ContractCostAsset**: incremental costs to obtain (sales commission) or fulfil (setup), amortisation schedule matching PO satisfaction pattern, impairment test.
- **RevenueWaterfall**: per-contract time series showing transaction-price allocation, period-by-period recognition, deferred balance, accrued balance.

## Requirements

- **REQ-IFRS15-001** The system MUST allow a contract to contain one or many performance obligations, each independently configurable for satisfaction pattern (point-in-time or over-time) and method (output or input).
- **REQ-IFRS15-002** Transaction price MUST capture fixed consideration, variable consideration estimate, significant financing component adjustment (when payment timing exceeds 12 months), non-cash consideration at fair value, and consideration payable to the customer as a price reduction.
- **REQ-IFRS15-003** Variable consideration MUST be estimated using expected value or most likely amount and constrained to the amount highly probable not to reverse (IFRS 15.56), with the constraint level documented and re-assessed every reporting period.
- **REQ-IFRS15-004** Allocation of transaction price MUST default to the relative stand-alone selling price method (IFRS 15.74), with explicit support for residual approach when SSP is highly variable or uncertain (IFRS 15.79).
- **REQ-IFRS15-005** For over-time POs the system MUST support input methods (cost-to-cost where cost basis is sourced from the project module, labour-hours, machine-hours) and output methods (units delivered, milestones, time elapsed); the method MUST be applied consistently within a PO and re-estimated each period.
- **REQ-IFRS15-006** Contract modifications MUST be classified per IFRS 15.18-21 (new contract, cumulative catch-up, or prospective) with the chosen treatment applied automatically and overridable with a documented reason.
- **REQ-IFRS15-007** A nightly job MUST calculate contract asset and contract liability balances per contract and post the net movement to the general ledger via deferred-revenue and accrued-revenue control accounts, with full reversal traceability.
- **REQ-IFRS15-008** The revenue waterfall MUST be available per contract, customer, segment, and consolidated, showing recognised + remaining transaction price by period for the next 60 months minimum (IFRS 15.120 disclosure of remaining performance obligations).
- **REQ-IFRS15-009** Costs to obtain and fulfil a contract MUST be capitalised when criteria are met (IFRS 15.91-95), amortised on the same pattern as the related PO satisfaction, and tested for impairment at each reporting date.
- **REQ-IFRS15-010** The system MUST produce the full IFRS 15.110-129 disclosure pack: revenue disaggregation, contract balance reconciliation, remaining performance obligations, significant judgements, and accounting policies, exportable to PDF, XBRL, and JSON.

### GIVEN/WHEN/THEN scenarios

**GIVEN** a 36-month SaaS contract for EUR 360K total signed 1 January, bundling a software subscription (PO-1, over-time, time-elapsed), a one-off implementation service (PO-2, point-in-time, completed 28 February), and a usage-based add-on capped at EUR 60K (PO-3, variable consideration, recognised as usage occurs), **WHEN** the user creates the contract and assigns SSPs of EUR 300K, EUR 40K, and EUR 80K, **THEN** the system MUST allocate the EUR 360K transaction price as EUR 257K / EUR 34K / EUR 69K (relative SSP), defer EUR 257K and EUR 34K initially, recognise EUR 34K on 28 February, recognise EUR 7,139 per month thereafter for PO-1, and recognise PO-3 only as usage is reported and within the constrained estimate.

**GIVEN** a construction contract using cost-to-cost input method with original estimated cost of EUR 800K and transaction price of EUR 1M, where actual cost-to-date at period 6 is EUR 480K and revised total estimated cost is EUR 900K, **WHEN** the revenue cut-off job runs, **THEN** the system MUST calculate percentage of completion as 480/900 = 53.3%, recognise cumulative revenue of 533K, post the period delta net of prior-period recognised, raise an alert that gross margin has compressed from 20% to 10%, and create an onerous-contract test if margin turns negative.

**GIVEN** a contract modification on month 12 that adds a distinct new module priced at its stand-alone selling price, **WHEN** the modification is recorded, **THEN** the system MUST classify it as a new contract per IFRS 15.20(a), leave the original waterfall untouched, and create a separate contract record for the new module with its own POs, allocation, and recognition schedule, fully traceable to the parent contract in the customer 360 view.

## Standards & Sources

- **IFRS 15** Revenue from Contracts with Customers (effective 1 Jan 2018)
- **ASC 606** Revenue from Contracts with Customers (FASB equivalent)
- **IFRIC Update** interpretations on IFRS 15 (March 2018, March 2019, etc.)
- **IFRS 15 Illustrative Examples** IE1-IE338 (especially IE7-IE10 series for over-time)
- **BW2 Title 9 / RJ 270** (Dutch GAAP, increasingly aligned with IFRS 15 for SMEs)
- **EFRAG / IASB Transition Resource Group** discussion papers
- Big-4 implementation guides: PwC Manual of Accounting Ch.11, EY IFRS 15 Practical Guide, KPMG Insights into IFRS, Deloitte iGAAP
- Competitor reference models: Sage Intacct Contract Revenue, NetSuite Advanced Revenue Management, Workday Adaptive Planning, Tensoft RevPro, Zuora RevPro (RightRev), Salesforce Revenue Cloud (SteelBrick origin), Chargebee RevRec, Maxio (SaaSOptics + Chargify)

## Cross-app integration

- **bookkeeping-quote-order-invoice**: the legal contract under IFRS 15 originates as a quote/order; sales-order fields map directly to Contract entity, accelerating data entry and removing dual maintenance.
- **bookkeeping-consultancy-project-accounting**: cost-to-cost input method sources actual + estimated cost from project timesheets and material bookings; milestones flow to PO satisfaction events.
- **bookkeeping-accounts-receivable-core**: billing schedule on contract may differ from recognition schedule; the contract asset/liability reconciles the two and AR receives the invoice triggers.
- **bookkeeping-general-ledger**: deferred-revenue and accrued-revenue control accounts plus revenue P&L per segment/product/contract dimension.
- **bookkeeping-period-close** + **bookkeeping-soft-close-flux**: nightly soft-close runs the revenue cut-off; flux analysis explains period-over-period revenue movements per PO satisfaction pattern.
- **bookkeeping-ifrs7-disclosure / consolidation**: revenue disaggregation feeds the consolidated annual accounts.
- **openconnector**: integration with CRM systems (Salesforce, HubSpot) for contract data ingestion, and with usage-metering systems for variable consideration.
- **launchpad**: CFO tile for ARR/MRR, billing vs revenue gap, top-10 contracts by remaining performance obligation.
- **docudesk**: signed contract PDFs stored as evidence, linked to the Contract entity.

## Target users

- **CFO** of growth-stage SaaS companies, professional-services firms, construction companies, and engineering consultancies who must publish IFRS-aligned annual accounts.
- **Group Controller** responsible for ASC 606 / IFRS 15 compliance, audit defence, and the rev-rec close.
- **Revenue Accountant** (often a dedicated role above EUR 20M ARR) running the contract review, SSP analysis, and waterfall.
- **FP&A team** consuming the rev-rec output for ARR/MRR analytics, cohort revenue, and net retention dashboards.
- **External auditor** validating recognition, variable-consideration estimates, and disclosure completeness.
- **Sales operations / deal desk** approving non-standard contracts whose terms drive recognition outcomes (multi-year discounts, free months, performance penalties).
- **Investor-relations team** at pre-IPO companies preparing S-1 / prospectus revenue disclosures.

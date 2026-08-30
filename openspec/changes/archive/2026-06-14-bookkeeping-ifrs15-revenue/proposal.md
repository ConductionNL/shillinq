# Proposal: bookkeeping-ifrs15-revenue

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`Contract`, `PerformanceObligation`, `TransactionPrice`, `PriceAllocation`,
`RevenueRecognitionEvent`, `ContractAsset`, `ContractLiability`, `ContractModification`,
`VariableConsiderationAdjustment`, `ContractCostAsset`, `RevenueWaterfall`) +
materialisations (GL postings) + aggregations (remaining performance obligations,
revenue waterfalls, contract balances). Spec-only; implementation lands later
via `opsx-apply` and standard Hydra pipeline.

## Summary

Implement the **IFRS 15 / ASC 606 five-step revenue recognition model** as a T2
compliance + operations capability for Shillinq. This capability replaces the
simplistic "invoice = revenue" assumption endemic to legacy SME bookkeeping
packages, enabling proper deferral and accrual of revenue for contracts with
performance obligations, variable consideration, and non-standard satisfaction
patterns. Mandatory for listed companies since 1 Jan 2018; increasingly demanded
by Dutch SMEs publishing IFRS-aligned annual accounts (BW2 Title 9), growing
toward IPO, or supplying enterprise customers who require IFRS-compliant invoice
processing.

The change declares ten new registers (`Contract`, `PerformanceObligation`,
`TransactionPrice`, `PriceAllocation`, `RevenueRecognitionEvent`, `ContractAsset`,
`ContractLiability`, `ContractModification`, `VariableConsiderationAdjustment`,
`ContractCostAsset`, `RevenueWaterfall`) implementing the five-step model: (1)
identify contract; (2) identify separate performance obligations; (3) determine
transaction price; (4) allocate across POs using stand-alone selling prices (SSP);
(5) recognise revenue when/as each PO is satisfied. Supporting the five steps are
contract modifications, variable consideration estimation with constraint,
significant financing component adjustment, costs to obtain/fulfil (capitalised
per IFRS 15.91-104), and nightly calculation of contract asset/liability balances
and the full IFRS 15.110-129 disclosure pack.

Integration points: quote-to-cash funnel (contract originates from a sales order);
project-accounting module (cost-to-cost input method sources actual/estimated
costs from timesheets); accounts-payable-receivable core (billing schedule may
differ from recognition schedule); general ledger (deferred/accrued revenue control
accounts); period-close + soft-close flux (revenue cut-off nightly run); and
external systems (CRM for contract data ingestion, usage-metering systems for
variable consideration).

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md)
(materialises GL transactions for recognition and deferred revenue), [`add-shillinq-bookkeeping-quote-order-invoice`](../../specs/bookkeeping-quote-order-invoice/spec.md)
(contract originates from sales order), [`add-shillinq-consultancy-project-accounting`](../../specs/bookkeeping-consultancy-project-accounting/spec.md)
(input-method cost sourcing).

## Motivation

IFRS 15 has been mandatory for listed companies since 1 January 2018 and is
increasingly demanded by Dutch SMEs that publish IFRS-aligned annual accounts
(BW2 Title 9), grow toward an IPO, or supply enterprise customers who require
IFRS-compliant invoicing of their software/services spend. SaaS subscriptions
with implementation services, project-based consultancy, construction contracts,
telecoms bundles, and any contract with variable consideration (rebates, volume
discounts, milestone bonuses, refund obligations) require explicit performance-
obligation tracking and timed recognition. Simple "invoice = revenue" posting
is non-compliant and defeats audit defence.

Competitors (Sage Intacct, NetSuite, Workday, Zuora, Chargebee, Maxio, Salesforce
Revenue Cloud) all ship some form of IFRS 15 / ASC 606 capability. Shillinq's
competitive advantage is declarative, audit-transparent revenue subledger that
fits into the broader bookkeeping workflow, not a siloed contract-revenue module.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-ifrs15-revenue`); declares 10 new registers and associated
  lifecycles, materialisations, and aggregations; adds 5–7 manifest navigation
  entries (Contracts, Revenue Waterfall, Contract Balances, Remaining POs,
  IFRS 15 Disclosure, Revenue Analysis).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`, materialisation
  extensions.

## Scope

### In Scope

- One new capability spec (`bookkeeping-ifrs15-revenue`) — see `specs/` folder.
- The `Contract` register with customer reference, identification, start/end
  dates, transaction price (fixed + estimated variable), currency, signed-at
  date, modifications history, quote/order reference, contract-group reference
  for combination-of-contracts treatment.
- The `PerformanceObligation` register with parent contract FK, description,
  distinct flag, satisfaction pattern (point-in-time | over-time), output/input
  method, stand-alone selling price (SSP), allocated transaction price, and
  status lifecycle.
- The `TransactionPrice` register capturing components (fixed, variable,
  financing adjustment, non-cash consideration, consideration payable to
  customer), variable-consideration estimation method (expected value | most
  likely), and constraint limit (highly probable not to reverse).
- The `PriceAllocation` register storing per-PO allocation amount and method
  (relative SSP, residual, expected cost plus margin), recalculation on
  modification.
- The `RevenueRecognitionEvent` register tracking PO, period, recognised amount,
  basis (units delivered, % complete, milestone), and supporting evidence
  (timesheet, delivery note, sign-off).
- The `ContractAsset` and `ContractLiability` registers derived nightly per
  contract (asset = recognised > billed; liability = billed > recognised);
  net movement posted to GL.
- The `ContractModification` register classifying type (new contract, cumulative
  catch-up, prospective per IFRS 15.18-21), effective date, and before/after
  snapshot for audit.
- The `VariableConsiderationAdjustment` register for rebates, refunds,
  performance bonuses, penalties, volume discounts, with periodic re-estimation
  log.
- The `ContractCostAsset` register for incremental costs to obtain (sales
  commission) or fulfil (setup), with amortisation schedule matching PO
  satisfaction and impairment test.
- The `RevenueWaterfall` register storing per-contract time-series view of
  transaction-price allocation, period-by-period recognition, deferred balance,
  accrued balance.
- Nightly cut-off job materialising GL postings for contract asset/liability
  movements.
- IFRS 15 disclosure pack exportable to PDF, XBRL, JSON (scope: structure +
  schema; export delivery via T4).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests, CI changes deliberately out of this proposal; task list
  references them but implementation lands via separate `opsx-apply` cycle.
- **Integration with external CRM / usage-metering systems** — T4. Spec declares
  the contract-data and variable-consideration ingestion shape but does not
  build connectors here.
- **Multi-currency translation** — T5.
- **Segment reporting disaggregation** — T4. Spec declares the schema fields but
  segmentation rules and dimension tables land in T4.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-ifrs15-revenue`** — declares the 10 registers, their lifecycles
(contract draft → active → modification → completed), the nightly cut-off job
shape, materialisations (GL postings), aggregations (remaining POs, revenue
waterfalls, contract balances), and IFRS 15 disclosure structure.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement
is prefixed `REQ-IFRS15-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions (`x-openregister-lifecycle`,
`x-openregister-aggregations`, materialisation extensions) and standard GL
control-account pattern.

## Impact

- `lib/Settings/shillinq_register.json` — adds 10 new schemas with lifecycles
  and materialisations.
- `src/manifest.json` — adds 5–7 navigation entries + their detail pages.
- No new PHP services (subject to ADR-031: IF a nightly cut-off job or variable-
  consideration re-estimation algorithm cannot be expressed purely as aggregations
  + materialisation triggers, a single-purpose `RevenueCutoffService` ships with
  documented exception).
- No new bespoke Vue components beyond those needed for contract entry and
  waterfall visualisation (reuse management + configuration views).

## Cross-Project Dependencies

- **T1 general ledger** — depends on `add-shillinq-general-ledger` for
  materialised GL posting pattern on recognition events and contract asset/
  liability movements.
- **T2 quote-to-cash** — depends on `add-shillinq-bookkeeping-quote-order-invoice`
  for contract origination from sales order (same legal instrument).
- **T2 consultancy / project accounting** — depends on
  `add-shillinq-consultancy-project-accounting` for cost-to-cost input method
  and milestone tracking.

## Risks

### Risk 1: Variable-consideration constraint estimation is subjective and audit-sensitive

**Severity**: High
**Mitigation**: REQ-IFRS15-003 requires the constraint level to be documented
with reason and re-assessed each reporting period. The spec captures the re-
estimation audit trail; reviewer confirms that documentation meets Big-4 guidance
(IFRS 15 Illustrative Example IE7-IE10 series). No auto-constraint; all estimates
require operator judgment entry.

### Risk 2: Cost-to-cost input method requires real-time cost data from project module

**Severity**: Medium
**Mitigation**: REQ-IFRS15-005 sources cost from the project-accounting module
via FK reference. If project-accounting cost data is delayed or unavailable, PO
satisfaction % cannot be recalculated at period-close. Spec captures the SLA
(project costs updated by X days before close); dependency resolved during
implementing cycle.

### Risk 3: Nightly cut-off job must run idempotently and not double-post GL transactions

**Severity**: Medium-High
**Mitigation**: REQ-IFRS15-007 specifies reversals: on each run, REVERSE all
prior-period contract asset/liability GL lines, then POST fresh lines. Job MUST
be guarded by fiscal period open-check (REQ-PC-004 pattern). Spec captures the
guard; implementation includes retry-logic and dry-run mode.

### Risk 4: IFRS 15 contract-combination rules are complex and require judgment

**Severity**: Medium
**Mitigation**: REQ-IFRS15-001 requires explicit `contractGroupId` field on
Contract to mark contracts that should be combined (per IFRS 15.17). Combination
criteria and testing are documented per Big-4 guidance; operator judgment applies.

### Risk 5: Remaining-performance-obligation disclosure scope may exceed 60-month forecast

**Severity**: Low
**Mitigation**: REQ-IFRS15-008 mandates 60-month minimum visibility (IFRS 15.120).
For contracts extending beyond 60 months, a "tail" bucket aggregates years 6+.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no
runtime impact. After implementation (separate cycle), rollback follows standard
pattern: revert implementing PR; registers are non-destructive — contracts remain
queryable; GL transactions reverse via complement postings (idempotent job).

## Open Questions

1. **Variable-consideration re-estimation frequency** — monthly (at close),
   quarterly, or on-demand? Defaults to monthly per IFRS 15.50; customisable
   per administration; resolved during UX review.
2. **Cost-to-cost SLA from project-accounting module** — costs must be final X
   days before close for reliable % completion; resolved in dependency discovery.
3. **Principal-vs-agent assessment** — net-revenue or gross-revenue reporting for
   agent relationships? Spec declares the field shape; judgment per Big-4 guidance.
   Resolved during peer review.
4. **Segment reporting dimensions** — which contract attributes (product, customer
   geography, contract type) become segment dimensions in the disclosure? Resolved
   in T4 segment-reporting spec.

# Design — Treasury / In-house Bank / Cash Pooling

## Context

Treasury management for multi-entity groups requires consolidated cash-position
visibility, intra-group liquidity optimization, FX risk management, and daily
cashflow forecasting — all with full audit trail and regulatory compliance
(IFRS 7/9, OECD transfer pricing, Dutch Wft). Today, most SME groups manage
cash across 5–50 bank accounts via spreadsheets + per-bank portals.

For notional and physical (zero-balance / target-balance) pooling:
1. Pool operator configures participating administraties, accounts, and rules
2. Bank connector fetches real-time balances from each account
3. Nightly sweep job calculates movements per pool logic
4. System materializes double-sided journal entries (sweep to master account,
   offsetting entries on participant ledgers + intercompany current accounts)
5. Interest on pool balance calculated daily, allocated per configured method
   (proportional, weighted-average, fixed), posted monthly to GL

For intercompany loans: operator registers loan terms (lender/borrower,
principal, rate, schedule), system accrues interest daily and posts monthly.

For FX contracts: full trade → confirmation → settlement lifecycle, with
revaluation at period close per IFRS 9 hedge designation (cashflow | fair value
| net investment).

For cashflow forecasting: nightly job regenerates 13-week rolling forecast from
AR/AP ageing, payroll calendar, debt-service schedule, and manual adjustments.
Three scenarios (base / downside / stress) built in.

Per ADR-031, all calculation is declarative: schema metadata + aggregation
queries emit movement records + GL postings via openregister-lifecycle
automation.

## Goals

- Express the entire treasury surface as **declarative metadata** — schemas +
  lifecycle + aggregation formulas — per ADR-031.
- Make the spec a **competent-CFO readable contract** — cash pool mechanics,
  notional vs physical sweep, interest allocation, loan accrual, FX hedge
  accounting, and forecast generation all recognisable end-to-end.
- Support both **notional pooling** (interest calculated on aggregate, balances
  stay separate) and **physical pooling** (zero-balance or target-balance sweep)
  in a single register schema via discriminator field.
- Enable **multi-administratie bank reconciliation** in a single workflow without
  context-switching.
- Enforce **IFRS 9 hedge accounting** and **transfer pricing** (OECD guidelines)
  via schema constraints and audit trail, not PHP service logic.
- Produce **IFRS 7/9 disclosure pack** suitable for external auditor and
  regulatory filing.

## Non-Goals

- No real-time FX rate feeds from Bloomberg / Refinitiv (T4 connector phase).
- No Peppol inbound e-payment initiation (T4).
- No multi-currency GL posting (sweep/interest always in reporting currency).
- No formal PEC governance workflows (owned by decidesk integration T4).
- No DNB regulatory filing automation (T4).

## Decisions

### D1 — Nine registers: pool + membership + loan + transaction + FX contract + FX position + forecast + recon group + KPI

Treasury is decomposed into:

- **CashPool**: pool header (name, type, master account, currency, interest
  allocation method, rules for sweep frequency/threshold)
- **CashPoolMembership**: per-administratie per-bank-account link to pool; sweep
  direction (upstream/downstream), target balance, priority, intra-day limit
- **IntercompanyLoan**: lender administratie, borrower, principal, rate (fixed
  or floating with reference curve + spread), repayment schedule, transfer-
  pricing documentation reference
- **IntercompanyTransaction**: daily movement (sweep, loan drawdown, interest
  accrual, settlement); auto-generated double-sided GL entries
- **FXContract**: counterparty, instrument type (spot | forward | swap | NDF),
  buy currency + amount, sell currency + amount, trade date, value date, rate,
  settlement instructions, hedge designation per IFRS 9
- **FXPosition**: per-currency position per entity and consolidated, spot rate,
  fair value, unrealised P&L, last-updated timestamp
- **CashForecast**: rolling 13-week forecast, weekly buckets, inflows (AR
  collections, drawdowns), outflows (AP runs, payroll, taxes, debt service),
  opening + closing cash, 3 scenarios (base | downside | stress), variance vs
  prior forecast
- **BankReconciliationGroup**: multi-administratie reconciliation run linking
  BankTransaction to JournalEntry across pool participants; exception queue;
  auto-match score
- **LiquidityKPI**: cash conversion cycle, days cash on hand, current ratio,
  quick ratio per entity + consolidated, trend vs prior week

**Alternative considered**: Monolithic pool-valuation register with all fields
embedded. Rejected — multi-period interest accrual, per-currency FX position,
multi-scenario forecast all require first-class records for drill-down, audit
trail, and amendment tracking.

### D2 — Two pool types: notional (interest calculated, balances separate) vs physical (zero-balance | target-balance sweep)

**Notional pooling** (`type: notional`): Bank accounts remain legally separate.
Daily interest calculated on aggregate balance; allocated to participants per
configured method. No cash movement.

**Physical pooling** (`type: zero-balance` | `type: target-balance`):
- Zero-balance: End-of-day sweep brings all participant balances to zero;
  movements transferred to master account.
- Target-balance: End-of-day sweep adjusts participant balances to a configured
  target (e.g., EUR 50K for buffer), remainder to master.

**Alternative considered**: Separate registers for notional vs physical. Rejected
— both share the same participant list, master account, currency, and interest
allocation metadata; `type` discriminator is sufficient.

### D3 — Sweep execution via n8n scheduled job, not app-local service

End-of-day sweep job orchestrated by n8n (per ADR-031 path 2 — external
orchestrator). Job:
1. Fetches current balances from bank connectors
2. Calculates movements per CashPool rules
3. Creates IntercompanyTransaction records (one per participant movement)
4. Triggers GL posting lifecycle on each transaction

No `SweepJob` PHP class; no `ScheduledWorkflow` in app. Job failure logged +
operator dashboard shows pending sweeps; daily reconciliation alert if unmoved
cash detected.

**Alternative considered**: App-local `SweepJob` scheduler. Rejected — orchestration
complexity (retry, failure notification, audit trail) better handled by
external n8n; app focuses on data definition.

### D4 — Interest allocation: proportional (default), weighted-average, or fixed method configurable per pool

`CashPool.interestAllocationMethod` enum: `proportional` (each participant's
share = net balance / aggregate balance) | `weighted-average` (per configured
weights) | `fixed` (per configured percentage per participant).

Interest calculated daily from pool balance × daily rate. Allocated per method.
Posted monthly to GL (interest expense account per participant).

**Alternative considered**: Always proportional. Rejected — weighted-average
common for credit-line pools (anchor tenant gets lower rate); fixed common for
group-mandated allocations.

### D5 — Intercompany loan rates: fixed or floating (reference rate + spread)

`IntercompanyLoan.interestRate`: Fixed percentage OR floating `{referenceRate:
"EURIBOR-3M" | "SOFR" | "SARON", spread: +2.0}`.

Reference-rate snapshot at month-start fetched from openconnector (T4) or
manually entered (v1).

**Alternative considered**: No floating rates for v1. Rejected — EURIBOR-based
loans common for Dutch groups; manual entry sufficient.

### D6 — FX contracts: full IFRS 9 lifecycle with hedge designation

`FXContract` records:
- Instrument type (spot | forward | swap | NDF)
- Trade date, value date, settlement date
- Buy currency + amount, sell currency + amount, rate
- Counterparty bank, confirmation reference
- Hedge designation (cashflow | fair value | net investment)
- Settlement instructions (nostro account, etc.)

At period close, system revalues at spot rate; unrealised gain/loss posted per
hedge designation:
- **Cashflow hedge**: effective portion → OCI (non-recycling); ineffective →
  P&L.
- **Fair value hedge**: gain/loss → P&L.
- **Net investment hedge**: gain/loss → OCI, with FX difference on net investment.

**Alternative considered**: Simplified FX position tracking (no hedge accounting).
Rejected — IFRS 9 mandatory for listed groups; RJ-271 requires disclosure.

### D7 — Cashflow forecast: 13-week rolling, nightly regeneration, 3 scenarios

`CashForecast` with 13 weekly buckets. Nightly job (n8n):
1. Fetches AR ageing + expected collection dates
2. Fetches AP ageing + payment due dates
3. Applies payroll calendar (weekly/monthly runs)
4. Applies scheduled debt service (loan maturity, interest payments)
5. Regenerates base scenario (high probability)
6. Generates downside scenario (10% variance on collections + 10% on payables)
7. Generates stress scenario (30% variance)
8. Compares closing cash vs prior forecast; flags variance >EUR 50K
9. If closing cash < minimum-cash policy (configurable, default EUR 500K):
   raises treasury alert

**Alternative considered**: Manual forecast entry. Rejected — nightly
regeneration standard practice; reduces stale-data risk.

### D8 — Multi-administratie bank reconciliation: single workflow

`BankReconciliationGroup` register linking multi-administratie reconciliation
runs. Operator can match a single bank line (e.g., shared-service-centre
payment) to GL entries from multiple participating administraties without
switching context.

Per ADR-031, matching logic is declarative: schema constraints + auto-match score
(>95% auto-match suggested; operator reviews remainder).

**Alternative considered**: Per-administratie reconciliation (existing pattern).
Rejected — shared-service-centre payments span multiple entities; per-entity
matching creates duplicate work + reconciliation friction.

### D9 — FX position consolidation: per-currency, per-entity, and group total

`FXPosition` records per (entity, currency) pair. Spot rate + fair value +
unrealised P&L updated daily from bank feeds. Group total aggregated from all
participating entities. Displayed on CFO dashboard as heatmap (by currency, by
entity).

**Alternative considered**: Snapshot only at period close. Rejected — intra-period
revaluation required for hedge-effectiveness monitoring + daily risk reporting.

### D10 — IFRS 7 disclosure pack: auto-generated from aggregate data

At period close, system generates `disclosure-pack.pdf` (or XBRL):
- Credit risk concentration (by counterparty, by instrument)
- Liquidity maturity profile (cash flows by maturity bucket)
- Market risk sensitivity (FX P&L if rates move ±5%, interest rate P&L if
  rates move ±100bps)
- Hedging effectiveness (cumulative ratio for each hedged item)
- Loan-level transfer pricing (amortised cost, interest rate, remaining term)

Suitable for external auditor + regulatory filing; no redaction needed.

**Alternative considered**: Manual disclosure authoring. Rejected — auto-
generation ensures consistency + reduces transcription error.

## Reuse Analysis

| Capability | What exists | Reuse strategy |
|---|---|---|
| Sweep scheduling | n8n (external) | CashPool + CashPoolMembership metadata; n8n job parses + executes per rules |
| Bank balance fetch | bookkeeping-bank-connectors | Direct camt.053 parse; balance per (administratie, account) |
| Interest calculation | OR `x-openregister-calculations` | Formula on pool.interestAllocationMethod; aggregation emits daily interest lines |
| GL posting materialization | bookkeeping-general-ledger | IntercompanyTransaction lifecycle generates balanced JournalEntry per T2 GL pattern |
| FX rate snapshot | openconnector (T4) or manual entry (v1) | Manual entry for v1; T4 connector for Bloomberg/Refinitiv feed |
| FX revaluation | OR `x-openregister-calculations` | Formula: unrealised gain/loss = net position × (current rate − entry rate); OCI/P&L split per hedge designation |
| AR/AP ageing | bookkeeping-accounts-payable + bookkeeping-accounts-receivable | Query aggregates open invoices by due date; feeds forecast model |
| Cashflow forecast aggregation | OR `x-openregister-aggregations` | Query consumes AR/AP ageing + scheduled debt service + payroll calendar; emits CashForecast records |
| Bank-transaction matching | bookkeeping-bank-reconciliation | Existing auto-match logic; BankReconciliationGroup extends to multi-administratie scope |
| IFRS 7 disclosure | bookkeeping-financial-statements | CashPool, FXPosition, IntercompanyLoan aggregates consumed by disclosure renderer |

**Net new code in implementation cycle**: 9 schema declarations + 4 lifecycle
blocks (CashPool, IntercompanyLoan, FXContract, CashForecast) + 3 aggregation
queries (daily interest, FX revaluation, forecast regeneration) + 5 manifest
entries + 0 PHP service (all calculation declarative via openregister).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Sweep calculation (which accounts move, how much) | Declarative (`CashPool` + `CashPoolMembership` metadata; n8n parses + executes) | Configuration-driven, no business logic |
| Interest allocation (proportional | weighted-average | fixed) | Declarative (`interestAllocationMethod` enum; aggregation formula per method) | Scalar calculation, parametric by method |
| Loan interest accrual | Declarative (`IntercompanyLoan.interestRate` + aggregation query) | Pure arithmetic; no amortization schedule complexity beyond daily accrual |
| FX revaluation | Declarative (formula: unrealised = position × rate delta) | Scalar calculation; OCI/P&L split per hedge-designation enum |
| GL posting materialization | Declarative (`IntercompanyTransaction` lifecycle generates `JournalEntry`) | Per T2 GL pattern; no app-specific posting logic |
| Cashflow forecast generation | Declarative (aggregation query: AR/AP ageing + scheduled debt + payroll calendar) | Data-source join + arithmetic; no predictive ML |
| Multi-administratie reconciliation | Declarative (schema constraints + auto-match score formula) | Matching heuristic, not context-dependent logic |
| Bank balance fetch | Operational (bank-connector integration) | Runtime integration, not config; handled by connector module |

No service class authored in this envelope (per ADR-031 strict mode: zero
PHP service classes for treasury calculation).

## Seed Data

Five seed records (Dutch values):

1. **CashPool**: "Groepmaatschappij Netto Pool"
   - type: notional
   - masterAccount: NL91ABNA0417164300 (placeholder EUR account)
   - currency: EUR
   - interestAllocationMethod: proportional
   - dailyInterestRate: 0.045 (4.5% p.a.)
   - minDailyBalance: EUR 500000
   - members: 3 (placeholder administraties)

2. **CashPoolMembership**: Member-A (upstream)
   - administratieId: adm-001
   - bankAccount: NL12INGD1234567890
   - sweepDirection: upstream (balance flows to master)
   - targetBalance: null (notional, no sweep)
   - priority: 1

3. **CashPoolMembership**: Member-B (upstream)
   - administratieId: adm-002
   - bankAccount: NL34DEUTDE87654321
   - sweepDirection: upstream
   - targetBalance: null
   - priority: 2

4. **CashPoolMembership**: Master Account (central)
   - administratieId: adm-001
   - bankAccount: NL91ABNA0417164300 (master)
   - sweepDirection: central
   - priority: 0

5. **IntercompanyLoan**: "Interim Financing adm-001 → adm-002"
   - lenderAdministratieId: adm-001
   - borrowerAdministratieId: adm-002
   - principal: EUR 250000
   - currency: EUR
   - interestRate: 3.5% fixed
   - startDate: 2026-01-01
   - maturityDate: 2026-12-31
   - transferPricingDocumentReference: "docudesk://<UUID>"
   - status: active

Operators customize per entity on first use; seed data idempotent on reimport.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Sweep-job failure (bank connector down, GL period locked) leaves cash unswooped | Scheduled job retry + operator dashboard showing pending sweeps; daily reconciliation alert if unmoved cash detected |
| FX rate snapshot stale if period-close revaluation job misses deadline | Operator can trigger manual mark-to-market; UI shows last-updated timestamp with warning if >1 hour stale |
| Forecast accuracy depends on AR/AP ageing completeness | Forecast includes variance explanation vs prior week; operator can manually adjust inflows/outflows; 3 scenarios (base/downside/stress) built in |
| Intercompany loan rate negotiated per loan; no system-wide transfer-pricing policy enforcement | Transfer pricing documentation stored in docudesk; external tax advisor reviews annually; audit trail on all rate amendments |
| Notional pool interest allocation method is configuration; actual allocation may diverge from policy | Allocation table auto-generated and approved by CFO/treasurer before month-end close; variance report if allocations diverge |
| Zero-balance sweep may fail for specific accounts (e.g., cash-management account with regulatory balance requirements) | Exclude accounts via `CashPoolMembership.exclusions` array; operator manually sweeps remainder |

## Migration Plan

No legacy data migration required. Treasury operations are introduced as a new
module. Existing customers on Shillinq without pooling/IHB can opt-in. Customers
with existing treasury spreadsheets can gradually migrate by importing pool
definitions + historical intercompany loans.

## Compliance & Standards

Spec implements:
- **IFRS 7** Financial Instruments: Disclosures
- **IFRS 9** Financial Instruments (classification, hedge accounting, fair value)
- **IFRS 13** Fair Value Measurement (asset categorisation)
- **IAS 7** Statement of Cash Flows (direct + indirect method)
- **OECD Transfer Pricing Guidelines** (Chapter X: Financial Transactions, 2020)
- **EBA Guidelines** on internal governance, ICAAP/ILAAP where applicable
- **Wet op het financieel toezicht (Wft)** (Dutch financial supervision law)
- **ISO 20022** payment messaging (pain.001, camt.052/053/054)

## Documentation & Audit Trail

All pool configurations, sweep executions, interest calculations, loan amendments,
FX contract trades, and forecast regenerations are recorded with entry date,
entered-by person, and approval status. External auditors can review complete
audit trail without requesting spreadsheets.

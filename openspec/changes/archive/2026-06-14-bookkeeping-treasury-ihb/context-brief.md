---
status: draft
---
# Treasury / In-house Bank / Cash Pooling

## Purpose

Provide centralised treasury management across multiple legal entities (administraties) within a shillinq group, enabling cash pooling, inter-company financing, foreign-exchange (FX) risk management, multi-administration bank reconciliation, and daily cashflow forecasting. The module turns the bookkeeping platform from a per-entity ledger into a group-wide cash-management cockpit comparable to dedicated treasury management systems (TMS) such as Kyriba, FIS Quantum, ION Treasury, Bellin/Coupa Treasury, or Reval. It targets multi-entity SMEs, holdings, and franchise groups that currently juggle five to fifty bank accounts across multiple banks and currencies without a consolidated cash position.

A treasury function answers three operational questions every morning: how much cash do we have across all accounts and entities, where is it, and what do we need today/this week/this month. Without an in-house bank (IHB) construct, intra-group liquidity is wasted: subsidiary A holds EUR 200K on a debit account at 0% while subsidiary B pays 7% on a credit line. Cash pooling and IHB solve this. This brief defines both the notional pooling model (interest is calculated on the aggregate, balances stay legally separate) and the zero-balance / target-balance physical sweeping model (balances are physically transferred end-of-day to a master account).

The module also covers inter-company loan administration with proper transfer-pricing-aware interest accruals (OECD arm's-length), FX position management (spot, forward, swap), and a daily 13-week rolling cashflow forecast that feeds the CFO dashboard. Output is suitable for IFRS 7 (financial instruments disclosures) and the Dutch Wet op het financieel toezicht (Wft) where IHB structures are increasingly scrutinised.

## Data Model

Core entities (all OpenRegister schemas in the `shillinq` register):

- **CashPool**: pool identifier, type (notional | zero-balance | target-balance), master account, sweeping rules, currency, interest allocation method (proportional | weighted-average | fixed), participating entities (administratie references), effective period.
- **CashPoolMembership**: links an administratie + bank account to a CashPool; sweep direction, target balance, priority for upstream/downstream sweeps, intra-day limit.
- **IntercompanyLoan**: lender administratie, borrower administratie, principal, currency, interest rate (fixed or floating with reference rate + spread), repayment schedule, transfer-pricing documentation reference, IFRS classification (amortised cost | FVTPL).
- **IntercompanyTransaction**: daily IHB account movement between two administraties (sweep, loan drawdown, interest accrual, settlement); double-sided journal entry generated automatically.
- **FXContract**: counterparty bank, instrument type (spot | forward | swap | NDF), buy currency + amount, sell currency + amount, trade date, value date, rate, settlement instructions, hedge designation (cashflow | fair value | net investment).
- **FXPosition**: per-currency open position per entity and consolidated, mark-to-market valuation, unrealised P&L.
- **CashForecast**: rolling 13-week forecast, weekly buckets, inflows (AR collections, drawdowns), outflows (AP runs, payroll, taxes, debt service), opening + closing cash, scenarios (base | downside | stress).
- **BankReconciliationGroup**: multi-administratie reconciliation run linking BankTransaction (from bookkeeping-bank-connectors) to JournalEntry across pool participants, exception queue, auto-match score.
- **LiquidityKPI**: cash conversion cycle, days cash on hand, current ratio, quick ratio per entity + consolidated.

All amounts use Money objects (amount + currency) with three-decimal precision for FX rates. Audit trail via OpenRegister versioning. Soft-deleted records retained for ten years per Dutch fiscal retention.

## Requirements

- **REQ-IHB-001** The system MUST allow creation of cash pools spanning two or more administraties from the multi-administratie module, with a defined master account, base currency, and pool type (notional or zero-balance).
- **REQ-IHB-002** For zero-balance pools, the system MUST generate end-of-day sweep transactions per participating account, posting one side to the participant ledger and the offsetting side to the master ledger plus the intercompany current-account on both sides.
- **REQ-IHB-003** For notional pools, the system MUST calculate daily interest on the aggregate balance using the configured rate curve and allocate it proportionally (or per chosen method) to participants without moving cash.
- **REQ-IHB-004** Inter-company loans MUST support fixed and floating rates with reference-rate look-up (EURIBOR, SOFR, SARON) from a rate-curve source, accrue interest daily, and post monthly to both lender and borrower ledgers.
- **REQ-IHB-005** Inter-company transactions MUST produce balanced double-entry postings in both administraties simultaneously and prevent posting if the offsetting administratie is closed for the period.
- **REQ-IHB-006** FX contracts MUST be recorded with full lifecycle (trade, confirmation, settlement), revalued at each period close using closing-rate FX, with unrealised gains/losses posted to OCI or P&L depending on hedge designation per IFRS 9.
- **REQ-IHB-007** The daily group cash position MUST be available before 09:00 local time, aggregating balances from all bank connectors across all participating administraties in their reporting currency.
- **REQ-IHB-008** A 13-week rolling cashflow forecast MUST be regenerated nightly using actual AR/AP ageing, scheduled debt service, payroll calendar, and seasonality model, with explainable variance vs prior forecast.
- **REQ-IHB-009** Bank reconciliation MUST work across all pool participants in one workflow, allowing the user to match a single bank line to journal entries from multiple administraties (typical for shared-service-centre payments).
- **REQ-IHB-010** The system MUST produce an IFRS 7 disclosure pack covering credit risk concentration, liquidity maturity profile, market risk sensitivity (FX, interest rate), and hedging effectiveness, exportable to PDF and XBRL.

### GIVEN/WHEN/THEN scenarios

**GIVEN** a zero-balance pool with three participating administraties (A, B, C) and master account M, where A ends the day at +50K EUR, B at -30K EUR, C at +10K EUR, **WHEN** the end-of-day sweep job runs at 23:30, **THEN** the system MUST post a sweep credit from A to M of 50K, a sweep debit from M to B of 30K, a sweep credit from C to M of 10K, leaving A, B, C at zero and M at +30K, with corresponding intercompany current-account entries on every leg, all dated the same value date.

**GIVEN** an EUR-denominated entity holding a USD trade payable of 100K due in 60 days, **WHEN** treasury hedges by entering a USD/EUR forward at 1.08, **THEN** the system MUST record the FX contract, designate it as a cashflow hedge per IFRS 9, mark-to-market at each period close, post effective-portion changes to OCI, recycle to P&L when the hedged payable is settled, and produce a hedge-effectiveness report showing the cumulative hedge ratio.

**GIVEN** the 13-week cashflow forecast shows a projected dip below the minimum-cash policy of 500K EUR in week 7, **WHEN** the nightly forecast job completes, **THEN** the system MUST raise a treasury alert, suggest mitigation actions (draw on revolving credit facility, accelerate AR collection on top-10 overdue customers, defer non-critical AP), and notify the CFO and treasurer via the configured channel (email, Mattermost, Slack).

## Standards & Sources

- **IFRS 7** Financial Instruments: Disclosures
- **IFRS 9** Financial Instruments (classification, hedge accounting)
- **IAS 7** Statement of Cash Flows (direct + indirect method)
- **OECD Transfer Pricing Guidelines** (Chapter X: Financial Transactions, 2020)
- **EBA Guidelines** on internal governance, ICAAP/ILAAP where applicable
- **Wet op het financieel toezicht (Wft)** for Dutch IHB constructs
- **ISO 20022** payment messaging (pain.001, camt.052/053/054)
- **CFA Institute** Treasury Management body of knowledge
- **AFP (Association for Financial Professionals)** treasury KPIs benchmark
- **ACT (Association of Corporate Treasurers)** Competency Framework
- Competitor reference models: Kyriba data model, ION Treasury, Bellin/Coupa Treasury, FIS Quantum, Reval (SS&C), Serrala, TIS, Cobase, Embat

## Cross-app integration

- **bookkeeping-multi-administratie**: source of administratie list, base currency per entity, period status. Treasury cannot move cash into a closed period.
- **bookkeeping-bank-connectors**: provides real-time + intraday bank statements (camt.052), end-of-day balances (camt.053), and outbound payment initiation (pain.001) used for sweeps.
- **bookkeeping-general-ledger**: receives all journal entries generated by sweeps, interest accruals, FX revaluations, and hedge accounting entries.
- **bookkeeping-accounts-payable** + **bookkeeping-accounts-receivable**: feed expected outflows/inflows into the 13-week forecast.
- **bookkeeping-ifrs15-revenue**: contractual revenue waterfall feeds long-horizon inflow expectations.
- **openconnector**: external integrations to rate-curve providers (Bloomberg, Refinitiv, ECB SDMX), FX broker APIs (360T, FXAll), confirmation matching (Misys, Acumen).
- **launchpad**: CFO dashboard tile for group cash position, FX exposure heatmap, liquidity runway.
- **docudesk**: archive of loan agreements, ISDA master agreements, CSA, confirmations.
- **n8n**: orchestration of nightly forecast regeneration, sweep generation, alert routing.

## Target users

- **Group Treasurer / Cash Manager** at multi-entity SMEs, holdings, family offices, franchise organisations, retail chains, and PE portfolio companies (10-500 FTE per entity, 2-50 entities).
- **CFO** of mid-market groups (EUR 10M-500M revenue) who today use spreadsheets plus per-bank portals and want a single pane of glass without paying TMS list prices (Kyriba/ION start at EUR 80K/year).
- **Group Controller** responsible for intercompany reconciliation and transfer-pricing compliance.
- **Shared Service Centre (SSC) accountant** running centralised AP/AR for the group.
- **Tax advisor** producing transfer-pricing documentation for tax authorities.
- **External auditor** validating IHB substance and arm's-length pricing for the year-end audit.
- **Bank relationship manager** at the group's primary bank, who today receives ad-hoc spreadsheets and would benefit from a structured liquidity report.

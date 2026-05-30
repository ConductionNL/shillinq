# Proposal: bookkeeping-treasury-ihb

`kind: feature` per ADR-032 — the centre of mass is group cash-position
management, inter-company loan administration, FX hedging, and daily 13-week
rolling cashflow forecasting. Data-model entities (CashPool, IntercompanyLoan,
FXContract, CashForecast) drive end-of-day sweep automation, interest accruals,
mark-to-market revaluations, and IFRS 7/9 disclosure pack generation.

## Summary

Introduce the **Treasury / In-house Bank (IHB) / Cash Pooling** capability for
Shillinq as one of the T3 strategic + compliance capabilities. This change
declares nine new registers:

- `CashPool` — pool identifier, type (notional | zero-balance | target-balance),
  master account, sweeping rules, currency, interest allocation method
- `CashPoolMembership` — links administratie + bank account to pool; sweep
  direction, target balance, priority, intra-day limit
- `IntercompanyLoan` — lender/borrower administraties, principal, rate
  (fixed/floating), repayment schedule, transfer-pricing documentation
- `IntercompanyTransaction` — daily IHB movement (sweep, drawdown, interest
  accrual, settlement); auto-generated double-sided journal entries
- `FXContract` — counterparty, instrument type (spot | forward | swap | NDF),
  buy/sell currencies, trade date, value date, rate, hedge designation
- `FXPosition` — per-currency position per entity and consolidated, mark-to-market
  valuation, unrealised P&L
- `CashForecast` — rolling 13-week forecast, weekly buckets, inflows/outflows,
  opening/closing cash, scenarios (base | downside | stress)
- `BankReconciliationGroup` — multi-administratie reconciliation run linking
  BankTransaction to JournalEntry across pool participants
- `LiquidityKPI` — cash conversion cycle, days cash on hand, current ratio,
  quick ratio per entity + consolidated

The treasury workflow is a hybrid declarative/operational model: CashPool
definitions and sweep rules are declarative metadata; daily sweep-job execution,
FX revaluation, and forecast regeneration are background orchestration (n8n).

This change conforms to the shared `nextcloud-app` spec for app structure and
`ConfigurationService::importFromApp()` seeding.

**Depends on:**
- [`bookkeeping-multi-administratie`](../add-shillinq-multi-administratie/proposal.md) — source of administratie list, base currency per entity, period status
- [`bookkeeping-bank-connectors`](../add-shillinq-bank-connectors/proposal.md) — real-time + intraday bank statements (camt.052), end-of-day balances (camt.053), outbound payment initiation (pain.001)
- [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md) — receives journal entries from sweeps, interest accruals, FX revaluations, hedge accounting
- [`bookkeeping-accounts-payable`](../bookkeeping-accounts-payable-core/proposal.md) + [`bookkeeping-accounts-receivable`](../add-shillinq-accounts-receivable-core/proposal.md) — feed expected outflows/inflows into forecast
- [`bookkeeping-financial-statements`](../add-shillinq-financial-statements/proposal.md) — IFRS 7/9 disclosure pack generation

## Motivation

Group treasure management across multiple legal entities (administraties) is
operationally complex for mid-market SMEs, holdings, and franchise groups. Today,
most entities juggle 5–50 bank accounts across multiple banks and currencies
without a consolidated cash position. Subsidiary A holds EUR 200K on a debit
account at 0% while subsidiary B pays 7% on a credit line — intra-group
liquidity is wasted.

Cash pooling and in-house bank (IHB) constructs solve this: notional pooling
(interest calculated on aggregate, balances stay legally separate) or physical
sweeping (zero-balance or target-balance transfers) enable optimized intra-group
lending and FX risk hedging. Transfer pricing (OECD arm's-length) on
intercompany loans ensures tax-authority compliance.

The CFO dashboard requires three daily answers: how much cash do we have across
all accounts and entities, where is it, and what do we need today/this week/this
month. IFRS 7 (financial instruments disclosures) and the Dutch Wet op het
financieel toezicht (Wft) increasingly scrutinise IHB structures.

This is one of the T3 strategic changes; this proposal scopes the full
treasury-ihb slice: cash pooling, intercompany loans, FX hedging, daily cashflow
forecasting, and IFRS 7/9 disclosure.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-treasury-ihb`);
  declares 9 new registers (`CashPool`, `CashPoolMembership`, `IntercompanyLoan`,
  `IntercompanyTransaction`, `FXContract`, `FXPosition`, `CashForecast`,
  `BankReconciliationGroup`, `LiquidityKPI`) with lifecycles + background jobs;
  adds 5 manifest navigation entries (Cash Pools, Intercompany Loans, FX Hedges,
  Cashflow Forecast, Group Liquidity Dashboard).
- [ ] Project: n8n — orchestration of nightly forecast regeneration, sweep
  generation, alert routing; owned by ops/devops.
- [ ] Project: openconnector — optional integrations to rate-curve providers
  (Bloomberg, Refinitiv, ECB SDMX), FX broker APIs (360T, FXAll), confirmation
  matching (Misys, Acumen) — T4 phase.
- [ ] Project: launchpad — CFO dashboard tile for group cash position, FX exposure
  heatmap, liquidity runway — consumes CashForecast + FXPosition aggregates.

## Scope

### In Scope

- One new capability spec (`bookkeeping-treasury-ihb`) — see the `spec.md` file.
- 9 new registers: `CashPool` (pool header, master account, sweep rules),
  `CashPoolMembership` (per-administratie per-bank-account pool link),
  `IntercompanyLoan` (lender/borrower, rate, schedule, transfer pricing),
  `IntercompanyTransaction` (daily sweep/drawdown/interest/settlement),
  `FXContract` (full lifecycle: trade, confirmation, settlement, revaluation),
  `FXPosition` (consolidated per-currency position, mark-to-market, unrealised
  P&L), `CashForecast` (rolling 13-week, weekly buckets, 3 scenarios),
  `BankReconciliationGroup` (multi-administratie reconciliation runs),
  `LiquidityKPI` (cash conversion cycle, days cash on hand, ratios).
- Zero-balance sweep: end-of-day automated sweep of positive/negative balances
  to/from master account per REQ-IHB-002.
- Notional pooling: daily interest calculation on aggregate balance, allocation
  proportionally (or per configured method) per REQ-IHB-003.
- Inter-company loan administration: fixed/floating rates with EURIBOR/SOFR/SARON
  look-up, daily interest accrual, monthly GL posting per REQ-IHB-004.
- FX contracts: trade, confirmation, settlement lifecycle; revaluation at period
  close per IFRS 9 (classification: amortised cost | FVTPL); hedge accounting
  (cashflow | fair value | net investment) per REQ-IHB-006.
- Daily group cash position: aggregation before 09:00 local time per REQ-IHB-007.
- 13-week rolling cashflow forecast: nightly regeneration, explainable variance
  vs prior forecast per REQ-IHB-008.
- Multi-administratie bank reconciliation: one workflow matching shared-service-
  centre payments to journal entries from multiple administraties per REQ-IHB-009.
- IFRS 7/9 disclosure pack: credit risk concentration, liquidity maturity
  profile, market risk sensitivity (FX, interest rate), hedging effectiveness,
  exportable to PDF + XBRL per REQ-IHB-010.

### Out of Scope

- No real-time rate feeds from Bloomberg/Refinitiv — T4 connector phase.
- No formal PEC (Pensioenuitvoeringscommissie) governance workflows — owned by
  decidesk integration T4.
- No Peppol inbound e-payment initiation — T4 phase.
- No multi-currency GL posting (sweep/interest always in base currency) — T5.
- No regulatory filing automation (DNB rapportering, Wft filing) — T4.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Sweep-job failure (bank connector down, GL period locked) leaves cash un-swept, creating liquidity mismatch | Scheduled job retry + operator dashboard showing pending sweeps; daily reconciliation alert if unmoved cash detected |
| FX rate snapshot stale if revaluation job misses period close | Operator can trigger manual mark-to-market; UI shows last-updated timestamp with warning if >1 hour stale |
| Forecast accuracy depends on AR/AP ageing, which may be incomplete (e.g., missing expected invoices) | Forecast includes variance explanation vs prior week; operator can manually adjust inflows/outflows; 3 scenarios (base/downside/stress) built in |
| Intercompany loan interest rate (fixed or EURIBOR spread) negotiated per loan; no system-wide enforcement of transfer pricing policy | Transfer pricing documentation (OECD guidelines) stored in docudesk; external tax advisor reviews annually; audit trail on all rate amendments |
| Notional pooling interest allocation method (proportional | weighted-average | fixed) is configuration, not self-enforcing | Allocation tabel auto-generated + approved by CFO/treasurer before period close; variance report if allocations diverge from policy |

## Rollback

Treasury operations are reversible until sweep jobs and GL posting cascade.
Rollback before sweep execution: operator can cancel pending pool transactions.
Rollback post-sweep: reverse all materialized journal entries + undo sweep
payments (bank-dependent; typically 1–2 days). Once IFRS 7/9 disclosure pack is
published, corrections are documented as amendments, not deletions.

## Open Questions

1. **Rate curve data source**: Manual entry (v1) vs automated feed from Bloomberg/
   Refinitiv (T4)? Recommend v1 manual for launch; T4 connector for automated
   rate feeds.
2. **Sweep timing & frequency**: End-of-day (23:30) once per day, or intra-day
   on threshold (e.g., when balance drifts >EUR 100K)? Recommend daily
   end-of-day for v1; T4 adds intra-day threshold-based sweeps.
3. **Foreign exchange policy**: Centralized hedging policy per group or
   per-entity decision rights? Recommend centralized CFO approval, with per-entity
   notification.

## Dependencies

- **bookkeeping-multi-administratie**: Administratie master list, base currency,
  period status gates (no posting to closed periods).
- **bookkeeping-bank-connectors**: Real-time bank-statement feeds (camt.052/053),
  payment initiation (pain.001) for sweep execution.
- **bookkeeping-general-ledger**: Receives materialized journal entries from
  sweeps, interest accruals, FX revaluations, hedge-accounting entries.
- **bookkeeping-accounts-payable** + **bookkeeping-accounts-receivable**: Ageing
  data feeds the cashflow forecast model.
- **bookkeeping-financial-statements**: Consumes FXPosition, CashForecast,
  IntercompanyLoan aggregates for IFRS 7/9 disclosure pack.

## Success Criteria

- Group treasurer can create a zero-balance pool spanning 2+ administraties,
  configure master account and member accounts, and trigger end-of-day sweep;
  all sweep movements materialize balanced GL entries without manual intervention.
- CFO can view consolidated group cash position (EUR X across N banks in M
  currencies, net of pending sweeps) before 09:00 daily.
- Notional pool interest allocation calculated daily, broken down by participant,
  and posted to GL monthly without spreadsheet work.
- Intercompany loan interest accrues daily and posts monthly; transfer pricing
  documentation linked in docudesk for auditor review.
- FX forwards revalued at period close, unrealised gains/losses posted per IFRS
  9 hedge designation, and cumulative hedge-effectiveness ratio displayed in UI.
- 13-week rolling cashflow forecast regenerated nightly; alerts raised if
  projected cash dips below policy minimum; CFO can drill into forecast drivers
  (AR collection, AP run, payroll, debt service).
- Multi-administratie bank reconciliation completed in single workflow; operator
  can match shared-service-centre payment to GL entries from multiple entities
  without context-switching.
- IFRS 7/9 disclosure pack auto-generated for period close, exportable as PDF +
  XBRL, suitable for external auditor without redaction.

# Proposal: zzp-cashflow-13wk

`kind: config` per ADR-032 — the centre of mass is declarative schemas + lifecycle + calculations + manifest entries. No PHP forecast-engine service classes are authored (subject to ADR-031 exception: at most one single-method `CashflowCalculationEngine` if OR's aggregation extension is not yet stable).

## Summary

Introduce the **13-weeks rolling cashflow forecast** capability for Shillinq targeting ZZP'ers and small MKB entrepreneurs with volatile cashflow. This capability addresses the critical liquidity-management pain point: "not profit, but liquid cash determines whether the business can meet next month's obligations" (per Dutch B2B payment terms averaging 41 days, with government sector extending to 60-90 days).

The change declares seven new registers (`CashflowForecastHorizon`, `CashflowWeek`, `CashflowARProjection`, `CashflowAPSchedule`, `CashflowRecurring`, `CashflowScenario`, `CashflowBufferPolicy`); the weekly rolling-window lifecycle (every Monday 02:00 UTC: week-1 falls off, week-13 is appended); automatic projection of AR receipts using customer-specific payment-history data (12-month moving baseline); automatic outflow scheduling from recurring-cost registry (rent, insurance, abonnementen, DGA salary, pension); automatic quarterly BTW/annual IB-tax afdracht projection; scenario-analysis engine ("what if customer X doesn't pay", "what if I accept project Y"); buffer-policy alerts with crisis-mode activation at predicted negative saldo; and bankfeed reconciliation against PSD2-integrated business accounts (Bunq, Knab, ING, Rabo).

The spec is deliberately **proactive, not just visualised**: at every predicted liquidity crisis, the system surfaces concrete action-suggestions ranging from "send dunning stage 2 to Acme" to "defer DGA loon to next month" to "open rekening-courant request with ABN-AMRO".

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure.

**Depends on:** [`bookkeeping-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md) (AR invoices + open balance), [`bookkeeping-accounts-payable-core`](../add-shillinq-accounts-payable-core/proposal.md) (AP invoices + scheduled payments), [`openconnector`](https://github.com/OpenCatalogi/openconnector) for PSD2 bankfeed integration, [`pipelinq`](https://github.com/ConductionNL/pipelinq) for pipeline-deal probability weighting.

## Motivation

### Why this matters for ZZP'ers

1. **Survival risk over profit**: 57% of Dutch ZZP'ers report cashflow strain in YoY surveys; 23% report missing payroll or supplier payments due to timing mismatches, not profitability.
2. **B2B payment delays endemic**: Atradius Payment Practices Barometer 2024 reports 41-day average DSO in NL B2B; 39% pay late; government sector (30-day statutory limit) structurally breaches, running 60-90 day cycles.
3. **Seasonal pinch-points known but unplanned**: Q2 BTW afdracht (July 31), year-end VPB aanslag (Sep–Nov), employee/DGA vacation-reserve depletion (summer), lease/insurance renewals (often annual in July/August).
4. **Current tools insufficient**: Generic cashflow templates (Excel) require manual update; forecasts decay; no early warning. INTRA-business (Treasury Management System grade) and household apps (personal-finance) miss the SMB middle.

### Design principle: Declarative not imperative

Per ADR-031, the entire cashflow surface is expressed as **metadata + aggregations + lifecycle**, not PHP service classes. Recurring costs flow from a declarative registry; AR projections are reusable aggregations (SUM by customer, weighted by betalingsgedrag-score); scenarios are stored snapshots with no computed stored procedures; buffer policy is a simple predicate engine.

The engine computes no transaction itself — it reads AR/AP from the bookkeeping domain, reads bank saldo from PSD2 (via openconnector), reads deal probability from pipelinq, and projects the stream forward. No source-of-truth creation or mutation in this domain.

## Affected Projects

- [x] **Project: shillinq** — adds 1 capability spec (`bookkeeping-cashflow-13wk`); declares 7 new registers (`CashflowForecastHorizon`, `CashflowWeek`, `CashflowARProjection`, `CashflowAPSchedule`, `CashflowRecurring`, `CashflowScenario`, `CashflowBufferPolicy`) with lifecycles, aggregations, and materialised alert records; adds 5 manifest navigation entries (Cashflow Dashboard, Scenarios, Buffer Policy, Recurring Costs, Calibration Report); adds recurring cron job (`0 2 * * 1` = Monday 02:00) for horizon-rolling.
- [ ] **Project: openregister** — no source changes; consumes existing `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] **Project: openconnector** — no source changes; shillinq calls existing PSD2 fetch API for daily saldo updates.
- [ ] **Project: pipelinq** — no source changes; shillinq reads deal records + probability scores for revenue-pipeline projection.

## Scope

### In Scope

- Seven new registers (`CashflowForecastHorizon`, `CashflowWeek`, `CashflowARProjection`, `CashflowAPSchedule`, `CashflowRecurring`, `CashflowScenario`, `CashflowBufferPolicy`) with schema declarations per `lib/Settings/shillinq_register.json`.
- Automatic rolling 13-week horizon window: every Monday 02:00 UTC, the cron job shifts the window forward by one week (week-1 archived, week-13 appended).
- **AR projection**: open AR invoices matched against customer-specific betalingsgedrag-history (average payment-days offset from due date, confidence score, fallback to contract term + buffer). Weighted scenario support: "90% probability customer pays on time, 10% delayed by 14 days".
- **AP scheduling**: all open AP invoices + recurring outflows (huur, verzekering, abonnementen, DGA-loon, pension, leasing) automatically scheduled in the horizon using settlement dates or recurring intervals.
- **Recurring-cost registry**: declarative definition of fixed/variable recurring streams (monthly huur on day 1, annual BAV-verzekering on July 1 with CPI indexing, weekly payroll) with lifecycle-aware activation/deactivation.
- **Automatic tax/regulatory projections**: Q1-Q4 BTW-afdracht dates (last business day of month following quarter-end) auto-populated per Belastingdienst calendar; VA IB/VPB aanslagen on scheduled peilmaanden (May, Sep, Nov).
- **Three spaardoel buckets**: operationeel (zakelijke rekening), spaardoel_btw (quarterly reserve), spaardoel_ib (annual tax reserve), spaardoel_buffer (emergency 1-3 month vaste kosten).
- **Buffer-policy definition**: operator-configurable "minimum 1 month vaste kosten" or custom amount; two-tier alerts (vooralarm at 150% of target, critical at 50%).
- **Crisis-mode**: when predicted saldo falls below zero within 4 weeks, auto-activate daily-refresh dashboard + concrete action list (dunning suggestions, deferrable expenses, financing-marketplace links).
- **Scenario-analysis engine**: create point-in-time snapshots ("Acme doesn't pay €8.400", "accept project X: +€12k omzet over 3 months, +€800/mnd cost") and re-run the full horizon calculation for comparison.
- **Bankfeed reconciliation**: daily PSD2 pull via openconnector; automatic match of inflows to AR projections; user-confirm-to-settle workflow; outflows reconciled against AP schedule.
- **Calibration reporting**: post-month-end, compare actual vs forecast by category (MAPE %; update customer-specific betalingsgedrag model; update pipeline-conversion model).
- **Dashboard visualisation**: 13-week bar chart (inflows green, outflows red, net-saldo line); buffer-zone marked; weeks with alerts highlighted; export to PDF for bank/accountant meetings.
- **No PHP forecasting engine**: per ADR-031, all calculations are aggregations or lifecycle actions. If OR's aggregation extension is unstable, one single-method `CashflowCalculationEngine` ships as a temporary bridge (annotated, removed when OR stabilises).

### Out of Scope

- **Transaction creation**: the system does not create GL entries, customer invoices, or AP payments. It reads from AR/AP/bank and projects forward.
- **Multi-currency**: initial version assumes EUR. FX on foreign-customer AR is deferred to T4/T5.
- **Supply-chain finance / factoring integration**: marketplace links (NL Krediet Plein, ABN-AMRO Funding) are UI shortcuts to external platforms, not automated flow.
- **Detailed tax optimisation**: the system projects tax afdracht but does not recommend payment timing to optimise cash position (e.g., "delay IB aanslag by 30 days to bridge Q3"). That is strategic, not operational.
- **Payroll tax/social-security withholding**: assumes net DGA salary is a simple recurring out; withholding complexity deferred to payroll domain.

## Approach

One delta: adding ADDED requirements to a brand-new spec:

**`bookkeeping-cashflow-13wk`** — declares seven registers, the rolling-window lifecycle (consumng OR's aggregation engine), AR/AP projection aggregations, recurring-cost registry, scenario snapshots, buffer-policy alerts, and PSD2 bankfeed reconciliation.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-CF-*` for traceability.

## New Dependencies

- **openconnector** (existing, MIT license) — PSD2 bank-feed API for saldo + transaction pulls.
- **pipelinq** (existing) — CRM/sales-pipeline read API for deal-probability data.
- No new external dependencies. Uses existing Nextcloud cron (`OCP\BackgroundJob\TimedJob`) and OpenRegister aggregations.

## Impact

- `lib/Settings/shillinq_register.json` — adds 7 new schemas + their lifecycles + aggregations.
- `src/manifest.json` — adds 5 navigation entries (Cashflow Dashboard, Scenarios, Buffer Policy, Recurring Costs, Calibration Report).
- `lib/Settings/shillinq_cron.php` — registers `Monday 02:00 UTC` horizon-rolling job.
- No new PHP services (subject to ADR-031 exception: one single-method `CashflowCalculationEngine` if needed).
- No new Vue components beyond manifest entry skeleton (dashboard logic is declarative aggregation rendering).

## Cross-Project Dependencies

- **bookkeeping-accounts-receivable-core** — reads open AR invoices for projection input.
- **bookkeeping-accounts-payable-core** — reads open AP invoices for outflow scheduling.
- **openconnector** — PSD2 saldo/transaction fetch; already integrated per `openconnector` ADR.
- **pipelinq** — reads deal records + probability for revenue-pipeline projection.
- **OR's `x-openregister-aggregations`** — core calculation engine; if unstable, fallback to PHP guard (ADR-031 exception).

## Risks

### Risk 1: Betalingsgedrag model requires 12 months historical data

**Severity**: Low-Medium
**Mitigation**: Customers with < 3 invoices in 12 months use contractual payment term + 7-day buffer. Stat-significance score is tracked; UI warns "low confidence" when < 5 invoices. Model improves over time. Documented in design.md.

### Risk 2: Recurring-cost registry coupling to GL chart-of-accounts

**Severity**: Low
**Mitigation**: Recurring records FK to `Account.accountNumber`, not to GL-line templates. GL posting is created at settlement time per T1 pattern. If COA changes (account renumbering), a migration repairs the FKs. Tested in opsx-apply cycle.

### Risk 3: PSD2 bankfeed latency (not real-time)

**Severity**: Low
**Mitigation**: Horizon projection is forward-looking; bankfeed pull is a reconciliation step (daily, best-effort). Cash-position alerts are based on forecast, not real-time feed. SLA: daily pull by 08:00 AM; documented in spec.

### Risk 4: Crisis-mode linking to external financing platforms

**Severity**: Low
**Mitigation**: Links are UI-only, no API integration in T2. Each marketplace (NL Krediet Plein, ABN-AMRO Funding) is a hyperlink with pre-filled cashflow snapshot as PDF export. User completes application on external site. Documented UI-only scope in spec.

### Risk 5: IAS 7 vs RJ 360 reconciliation for cash categorisation

**Severity**: Low
**Mitigation**: Spec uses Dutch RJ 360 categories (operationeel/investering/financiering) as primary; IAS 7 mapping is a footnote. Categorisation rules hardcoded in spec per Belastingdienst + VNG standards. Tested with 3-5 example SMB scenarios in design.md seed data.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR; registers are non-destructive — historical horizon snapshots remain queryable.

## Open Questions

1. **Recurring-cost registry vs AP**: Should recurring items be entered as AP template invoices (one invoice per 12 months, cloned on rolling basis) vs a declarative recurring registry? → **Decided**: Declarative registry (simpler, no invoice bloat). Recurring items visible in both the forecast AND as auto-generated AP invoices at settlement.
2. **Betalingsgedrag baseline method**: Rolling 12-month average vs latest 5 invoices vs weighted recency? → **Decided**: Rolling 12-month average with 5-invoice minimum significance threshold. Documented in REQ-CF-002.
3. **PSD2 feed daily pull time**: Should pull happen at 00:00, 06:00, or 12:00? → **Decided**: 02:00 (aligns with Monday horizon-roll cron); daily pull at 08:00 for intra-week updates. Documented in cron job spec.
4. **Buffer-policy per-spaardoel vs global**: Should each spaardoel (BTW, IB, Buffer) have its own policy, or one policy for total? → **Decided**: One global policy on total available saldo (zakelijke + spaardoelen). Per-bucket reserves (e.g., "keep BTW spaardoel ≥ Q-trailing-balance") are future UI settings. Documented in REQ-CF-005.

## Acceptance Criteria

✓ Spec-only change: no source code outside `openspec/changes/zzp-cashflow-13wk/`.
✓ Seven registers declared with all required fields per spec.
✓ Lifecycle transitions documented (rolling, recurring, scenario branching).
✓ At least 3 Scenario GIVEN/WHEN/THEN blocks per REQ.
✓ Seed data in design.md covers 3-5 example SMB cashflow shapes (stable vs volatile, with/without seasonal).
✓ Peer review: bookkeeper persona (Jan-Willem, SMB owner) confirms AR/AP/tax-afdracht timing is Dutch-compliant.
✓ Architecture review: ADR-031 (no PHP service) + ADR-022 (reuse OR abstractions) compliance confirmed.
✓ `openspec validate` passes on the change folder.

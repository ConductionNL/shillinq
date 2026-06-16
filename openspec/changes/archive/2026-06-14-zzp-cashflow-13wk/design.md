# Design — 13-Weeks Rolling Cashflow Forecast

## Context

Dutch ZZP'ers and small MKB entrepreneurs face acute cashflow volatility: B2B payment delays average 41 days (Atradius 2024), government sector runs 60-90 days despite 30-day statute, seasonal workload varies ±50% in tourism/hospitality/construction, and fixed costs (huur, insurance, DGA salary) are immovable. The "13-week rolling cashflow forecast" is the international best-practice horizon (per TPR practice, UK Restructuring Working Group, adopted in Dutch turnaround consulting) — long enough to see quarterly and seasonal patterns, short enough to remain actionable.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- **Early liquidity warning**: operator sees predicted saldo drop below buffer within 13 weeks, gets 7-day notice with concrete action options.
- **Deterministic vs stochastic distinction**: hard obligations (rent, taxes, AP due-dates) rendered in solid color; probabilistic inflows (AR with betalingsgedrag variance, pipeline deals) in muted tones.
- **Customer-specific payment history as primary AR input**: not the contractual 30 days, but the *actual* 41-day or 52-day pattern this customer exhibits.
- **Recurring-cost registry as source of truth for operational outflows**: no manual copy-paste; huur and insurance auto-flow from configuration.
- **Scenario branching without mutation**: "what if Acme doesn't pay" produces a what-if copy, not a forecast override; user compares original vs scenario.
- **Dutch-compliant tax/regulatory timing**: BTW-afdracht, VA IB/VPB, vakantiegeld, leaseabstottingen all projected per Belastingdienst calendar.
- **Readable by a competent bookkeeper**: the forecast schema and output must map directly to RJ 360 (operationeel/investering/financiering) and Belastingdienst administrative categories, not a black-box forecast.

## Non-Goals

- Real-time (sub-daily) forecasting — weekly recompute is the minimum actionable frequency.
- Automated debt restructuring or payment rescheduling — operator still makes the call.
- Multi-currency FX forecasting — T5 future.
- Payroll tax/social-security withholding detail — treated as simple recurring out.
- Integration with external accounting-software APIs (AFAS, Exact Online) — openconnector PSD2 is the data source.

## Decisions

### D1 — The horizon is a rolling window of 13 weeks, shifted every Monday 02:00 UTC

Each week's forecast is independent (no compound-uncertainty carry-forward). Every Monday 02:00 UTC, a cron job archives the "week-1" snapshot and appends a new "week-13" (T+13 weeks). This rhythm matches SMB weekly cash-position reviews and aligns with Monday morning meetings.

**Why 13 weeks?** → 13 weeks (91 days) captures two quarterly cycles (one complete, one in-progress), one seasonal pinch-point (summer vacation or year-end), and the first 60 days of the next planning cycle. 12 weeks is too short for quarterly patterns; 4-week rolling is too frequent.

**Why Monday 02:00?** → Monday morning is the typical weekly cash-position meeting. 02:00 UTC ensures the roll is complete before the operator's timezone (Europe CET/CEST) starts work.

### D2 — AR projection uses customer-specific betalingsgedrag history (12-month moving average) weighted by confidence

An open AR invoice maturing in week-w22 with due-date May 15 is not assumed to arrive on May 15 (contract term). Instead:

1. Query historical payments for this customer in the last 12 months.
2. If ≥ 5 invoices: compute mean offset = actual-payment-date minus due-date.
3. Project forward: expected-receipt-date = due-date + mean-offset.
4. Assign confidence score = (# invoices in sample) / 12; if < 0.42 (5/12), mark "LOW_CONFIDENCE".
5. Scenario variant: weight as "80% probability on time, 20% delayed by 14 days" for variance analysis.

**Why not contract term?** → Dutch B2B data shows 41-day average vs 30-day contract. Using contract term biases forecast optimistic by 2+ weeks. Customer history is ground truth.

**Why 12 months?** → Captures seasonal variation (Q4 customer tightens, Q1 loosens). Shorter windows (3-month) miss seasonal; longer windows (24+ months) include outdated customer behavior.

**Why 5 invoices minimum?** → Below 5, sample variance is too high. Falls back to contract term + 7-day buffer.

### D3 — AP outflows come from two sources: open AP invoices (one-off) + recurring-cost registry (repeating)

Open AP invoices are due-date scheduled (per AP.dueDate). Recurring outflows (huur €850/month on day 1, BAV-verzekering €620/year on July 1) are defined once in a registry and auto-populated for each occurrence in the horizon.

**Why separate from GL chart-of-accounts?** → GL CoA is hierarchical (asset, liability, expense). Recurring registry is operational frequency (monthly, quarterly, annual, weekly). They are orthogonal.

**Why include DGA salary in recurring?** → DGA salary (loon-uitkering) is deferred income, paid from business profit, not a third-party obligation. But it's a cash exit and must appear in forecast. Recurring registry tracks it.

### D4 — Automatic tax afdracht projection based on Belastingdienst calendar, not GL estimate

Q1-Q4 BTW-afdracht dates are hardcoded per NL statute:
- Q1 (Jan–Mar): due 30 April (last business day)
- Q2 (Apr–Jun): due 31 July (last business day)
- Q3 (Jul–Sep): due 31 October (last business day)
- Q4 (Oct–Dec): due 31 January (last business day)

VA IB/VPB aanslagen occur on peilmaanden: May, September, November (rough estimates; final aanslagen lag by months). The system projects these as hard outflows in the horizon.

**Why hardcoded vs dynamic from GL?** → Operator confidence: they know Q2 BTW is always July 31. A GL-derived estimate (which varies based on monthly realized turnover) is noise until closed. Hardcoded is predictable.

### D5 — Three spaardoel buckets with separate reserve targets

- `spaardoel_btw`: reserve for quarterly BTW afdracht (rolling 3-month trailing tax). Operator configures "hold Q-trailing-balance in this bucket".
- `spaardoel_ib`: reserve for annual IB/VPB aanslag (estimated from prior-year aanslag + growth). Operator configures "hold 50% of prior-year aanslag".
- `spaardoel_buffer`: emergency reserve (1-3 months fixed costs). Operator configures "hold 1 month vaste kosten".

Forecast shows total available (zakelijke + all spaardoelen) but flags when individual buckets fall below their configured minimum.

**Why not single pool?** → Psychologically, the entrepreneur needs to see earmarked reserves (people resist raiding BTW-reserve for operations even when saldo > buffer). Regulatory: BTW is trust money (not yours legally); IB-aanslag is owed to tax authority. Separate display reduces misuse.

### D6 — Buffer policy is a precondition (alert) not a hard constraint (block)

When forecast predicts saldo < buffer in week-w25, a yellow alert appears ("VOORALARM: buffer line will be breached in 3 weeks"). When saldo < 50% buffer, alert is red ("CRISIS: predicted negative saldo in 1 week"). No transaction is blocked; the alert is *information*.

**Why alert not block?** → Operators sometimes strategically run negative saldo in the last days of a quarter (DGA salary deferred to next quarter, BTW refund expected). Blocking would prevent this optimization.

### D7 — Scenarios are immutable snapshots, not permanent overrides

Create scenario "Acme doesn't pay €8.400": a copy of the current horizon is made, that specific AR projection is zeroed, and the horizon is recomputed. The original horizon is unchanged. Scenarios accumulate; the operator switches between them for comparison.

**Why snapshots not mutations?** → Mutation creates ambiguity ("which forecast am I looking at?"). Snapshots are clear audit trail. Per ADR-031, no stored procedures or triggers on forecast — scenarios are data, not logic.

### D8 — Recurring costs support lifecycle (activation/deactivation) and indexing (CPI)

A recurring item like "huur €850/month" can be:
- Active from 2024-09-01 (ignores earlier weeks).
- Deactivated on 2026-12-31 (ignores later weeks).
- Indexed annually by CPI (BAV-verzekering €620 on 2024-07-01, then €639 on 2025-07-01 if CPI was +3.2%).

Indexing rule is declarative: `indexation: "CPI_PRIOR_YEAR"` or `indexation: "FIXED"`.

**Why lifecycle?** → Contracts change; a lease ending needs to be off-forecast after expiry.

**Why CPI indexing declarative?** → Automatic annual update; no data-entry required. CPI published by CBS on 1st of month; horizon refresh on Monday picks it up.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| AR invoice master data | `bookkeeping-accounts-receivable-core` (T2) | FK to AR register; read-only for projection input |
| AP invoice master data | `bookkeeping-accounts-payable-core` (T2) | FK to AP register; read-only for outflow scheduling |
| Bank saldo + transactions | `openconnector` PSD2 (existing) | Daily pull; reconciliation match against projected inflows/outflows |
| Pipeline revenue + probability | `pipelinq` CRM (existing) | Read-only; deal.probability × deal.amount → revenue projection |
| GL chart-of-accounts | `bookkeeping-general-ledger` (T1) | Recurring costs FK to Account.accountNumber |
| Aggregation engine | OR `x-openregister-aggregations` (per ADR-031) | SUM(AR by customer), GROUP BY (bucket, week), filtering (paid/unpaid) |
| Lifecycle (rolling, alert escalation) | OR `x-openregister-lifecycle` (per ADR-031) | Horizon state machine (active → rolling → archived); recurring items (active → suspended → ended) |
| Audit trail | `bookkeeping-audit-trail` (T2) | Automatic on scenario creation, buffer-policy change, recurring-registry update |
| Manifest navigation | T1 manifest pattern | 5 entries (Dashboard, Scenarios, Buffer Policy, Recurring Costs, Calibration) + pages |
| PDF export for bank meetings | `openregister` PDF renderer (existing) | Horizon snapshot → PDF with graph + assumptions table |

**Net new code in implementation cycle**: 7 schema declarations + 4 lifecycle blocks + 6 aggregations + 1 cron-job handler + 5 manifest entry pairs. At most 1 single-method PHP helper (`CashflowCalculationEngine`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Rolling horizon window (shift every Monday) | Lifecycle transition on `CashflowForecastHorizon` | State machine (active → rolled); cron fires transition |
| AR projection | Aggregation (`SUM` + weighted average + variance calc) | Pure math; no stored procedure |
| AP scheduling from open invoices | Aggregation (FK-join to AP + date filter) | Pure query; no stored procedure |
| Recurring-cost expansion into week slots | Aggregation (cross-join recurring × weeks + filter by active window) | Pure math; no stored procedure |
| Scenario branching | Lifecycle action (snapshot creation on `CashflowScenario.create`) | Create snapshot, recompute aggregations on snapshot |
| Buffer-policy alert escalation | Lifecycle transition (VOORALARM → CRISIS based on saldo trend) | State machine; OR lifecycle engine handles it |
| Betalingsgedrag model update (post-month-end) | Aggregation + batch update (monthly kalibratie batch) | Pure statistical update; no new service |
| PSD2 bankfeed reconciliation | Lifecycle action (user-confirm to settle AR/AP) | Transition AR from "issued" to "paid" on bankfeed match confirmation |

**No service class authored in this envelope** (subject to ADR-031 exception: at most one single-method `CashflowCalculationEngine` if OR's aggregation extension is not yet stable for weighted-average AR projection).

## Seed Data

### Example 1: Stable B2B consultant (low volatility)

```json
{
  "ondernemingId": "ond-zzp-c001",
  "naam": "Erik van den Berg - IT Consultancy",
  "zakelije_saldo": 45000,
  "spaardoel_btw": 8200,
  "spaardoel_ib": 12500,
  "spaardoel_buffer": 6800,
  "scenario": "baseline"
}
```

**Recurring outflows (monthly)**:
- Huur kantoor: €1200 (day 1)
- Verzekering: €185 (day 15)
- Software abos: €240/month (day 10)
- DGA salary: €3500 (day 25)

**Q2 projection (weeks 18-26, mid-Apr through mid-Jun)**:
- Week 18: AR in €12.5k (3 stable clients, avg +3 days), AP out €2.1k, DGA out €3.5k → net €+6.9k
- Week 19: AR in €8.2k, AP out €2.1k, DGA out €3.5k → net €+2.6k
- Week 22 (Jul 1 start Q2): AR in €10.1k, AP out €2.1k, **BTW afdracht out €4.2k**, DGA out €3.5k → net €+0.3k ⚠️ buffer nearing
- Week 23: AR in €9.8k, AP out €2.1k, DGA out €3.5k → net €+4.2k (buffer recovers)

**Buffer policy**: "min 1 month vaste kosten" = €7.5k (huur + insurance + software + avg client-overhead). Forecast stays above €7.5k throughout; no alerts.

### Example 2: Volatile project-based (seasonal summer dip)

```json
{
  "ondernemingId": "ond-zzp-vol-001",
  "naam": "Sarah Chen - Event Production",
  "zakelijke_saldo": 22000,
  "spaardoel_btw": 4100,
  "spaardoel_ib": 3900,
  "spaardoel_buffer": 5200,
  "scenario": "baseline"
}
```

**Recurring outflows (monthly)**:
- Huur studio: €2800 (day 1)
- DGA salary: €2200 (day 20)
- Freelancer payroll: €0-€8000/week (variable, 0 in summer)

**AR mix**:
- 2 regular clients: €4.5k + €3.2k, monthly, avg +7 days
- 3 project clients: €8-€15k/project, 30–45 day payment history, confidence 0.65

**May-August projection (weeks 18-34)**:
- Week 18 (May): AR in €10.2k, AP (freelancers) out €6.8k, DGA out €2.2k, huur out €2.8k → net €-1.6k ⚠️ **RED CRISIS ALERT**
- Week 19: AR in €8.1k, AP out €4.2k, DGA out €2.2k, huur out €2.8k → net €-1.1k ⚠️ saldo reaches €18.3k (still above buffer)
- Week 20 (June): Large project pays in €14.5k, AR in €5.2k; AP out €2.1k, DGA out €2.2k, huur out €2.8k → net €+12.6k (recovers)
- **Weeks 22-26 (July summer dip)**: No freelancer projects, AR dries up → no inflows except €3.2k monthly client; outflows €7k/week (huur + salary) → saldo erodes to €9.1k by week 26 → **YELLOW VOORALARM** triggered at week 24
- Week 27+ (August): Project pipeline deal "Design gig €18k, 65% probability" enters projection → expected in-week €11.7k (€18k × 0.65) helps bridge

**Action suggestions** (auto-generated):
- Week 22: "Consider deferring freelancer engagement to August" (cost reduction)
- Week 24: "Contact 2 pipeline prospects for accelerated close (current forecast shows shortfall by week 26)"
- Week 26: "DGA salary deferral to Q3 would resolve buffer shortfall (adds €2.2k × 4 weeks = €8.8k to saldo)"

### Example 3: Government/semi-public contractor (extended payment terms)

```json
{
  "ondernemingId": "ond-zzp-gov-001",
  "naam": "Jan Pieterse - Public Sector Consultancy",
  "zakelijke_saldo": 18500,
  "spaardoel_btw": 3100,
  "spaardoel_ib": 2800,
  "spaardoel_buffer": 3500,
  "scenario": "baseline"
}
```

**Recurring outflows (monthly)**:
- Home office rental: €400 (day 1)
- Vereiniging VPB leden: €50 (day 5)
- DGA salary: €1800 (day 25)

**AR mix**:
- Municipality of Amsterdam: 1 open €12k invoice, statutory 30-day term but **actual history 78-day average** (internal workflows). Confidence 0.95 (6 prior invoices).
- Province of Utrecht: 1 open €8.5k, actual 62-day average, confidence 0.88 (5 prior invoices).
- Private client "Acme BV": €5.2k, actual 38-day average, confidence 0.92 (8 prior invoices).

**May-August projection (weeks 18-34)**:
- Week 18: AR projected in: Acme (due May 15 + 38 days = June 22, week 25); municipality + province both delayed beyond 13-week horizon. Confidence: Acme **HIGH** (0.92), municipality/province **MEDIUM-LOW** within horizon. Only outflows €2.25k → saldo dips to €16.25k
- Weeks 19-22: No inflows projected (government clients assume 60+ days out). Outflows €2.25k/week → saldo drops to €7k by week 22 → **RED ALERT by week 21**
- Week 25: Acme pays in €5.2k (confidence 0.92) → saldo recovers to €12.2k
- **Weeks 26-34**: Government AR still out-of-horizon (due late August or September) → saldo erodes again unless new contracts close

**Scenario**: "What if municipality delays by additional 30 days (80 days total)?"
- Recompute: municipality receipt pushed to week 34 (outside 13-week window). Saldo bottoms at €3.2k in weeks 27-30 → **CRISIS ENTIRE SUMMER**
- Suggestion: "Secure rekening-courant or async-financing for €8k to cover summer gap" OR "Negotiate partial upfront with municipality (50% on signature, 50% on delivery)"

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Betalingsgedrag model requires 12+ months data; new customers undefined | Use contract term + 7-day buffer for < 5 historical invoices; mark confidence LOW; model improves month-by-month |
| PSD2 bankfeed latency (daily, not real-time); forecast stale intra-week | Forecast is leading-indicator, not real-time cash position; bankfeed is reconciliation check (not the forecast source) |
| Recurring-cost registry maintenance burden (huur increases, insurance renews, freelancer rates change) | Operator maintains recurring registry (same as Excel template overhead); lifecycle (activation/deactivation) + indexing (CPI) reduce manual edits |
| Scenarios accumulate without cleanup (10+ scenarios → confusion) | UI shows "last 5" by default; operator can archive/delete old scenarios; snapshot retention policy (e.g., keep 3 months) enforced by cron |
| BTW-afdracht hardcoded per calendar; special situations (KOR, VAE exemption) not modeled | Special rules (KOR, VAE) documented as "out of scope T2"; operator can create manual adjustment scenario ("Q2 BTW now €0 due to KOR"); T3 enhancement to read from BTW-aangifte register |
| DGA salary as simple recurring; tax/social-security withholding not modeled | Documented as "net salary assumption"; T5 payroll-integration enhancement will refine |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the seven schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 5 new menu entries + their pages (additive).
3. `lib/Cron/HorizonRollingJob.php` is authored (registers as `0 2 * * 1`).
4. If OR's aggregation extension is not yet stable, `lib/Service/CashflowCalculationEngine.php` ships (single method, ~50 LOC, ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes forecast artifacts but does not delete AR/AP/bank data.

## Testing Strategy (Company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (`opsx-apply`) is responsible for:
- **PHPUnit**: 15-20 unit tests covering horizon rolling, AR projection with betalingsgedrag variance, AP scheduling, recurring-cost expansion, scenario branching, buffer-alert escalation.
- **Playwright MCP**: 8-10 browser tests for manifest pages + CRUD operations (create scenario, change buffer policy, edit recurring cost).
- **Integration test scenario**: Load 3 seed-data SMB profiles from design.md, run forecasts, verify saldo predictions ±5% of expected baseline.
- `composer test` green at PR CI gate.

## Acceptance Criteria

✓ Spec-only change: no source code outside `openspec/changes/zzp-cashflow-13wk/`.
✓ Seven registers declared with all required fields per spec.
✓ Lifecycle transitions documented (rolling, recurring, scenario branching).
✓ At least 2 Scenario GIVEN/WHEN/THEN blocks per major REQ.
✓ Seed data covers 3 SMB archetypes (stable, volatile, government-heavy).
✓ Peer review: bookkeeper persona (Jan-Willem) confirms forecasts map to Dutch RJ 360 + Belastingdienst calendars.
✓ Architecture review: ADR-031 (no PHP service) + ADR-022 (reuse OR) compliance confirmed.
✓ `openspec validate` passes on the change folder.

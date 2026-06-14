# Design — Retainer Billing Engine

## Context

Retainer billing is foundational for client-based service delivery where
monthly budgets (retainers) are established per client/project, time entries
draw against the pool, and unused balances roll over or reset. The original
Shillinq scope (invoicing) requires accurate retainer tracking; competitors
show that multi-month carryover policies (carryover cap, reset option) and
period-end true-up reconciliation are standard.

The change is **spec-only**. Implementation lands later through `opsx-apply`
and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire retainer-pool surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Establish **monthly pool lifecycle** with deterministic drawdown tracking
  and period-end reconciliation.
- Enable **rollover policies** (carryover cap, reset) so unused balances are
  managed consistently per client expectation.
- Make the spec a **Dutch SMB accountant-readable contract** — retainer setup,
  drawdown tracking, and true-up are recognisable and predictable.
- Provide **drawdown aggregation** (no PHP service) and **materialized audit
  trail** (RetainerDrawdown, RetainerTrueUp) for billing reconciliation and
  dispute prevention.

## Non-Goals

- No PHP retainer-calculation service; no `RetainerService.php`.
- No multi-month carryover analysis or what-if forecasting; period-by-period
  true-up only.
- No cross-client/project pool consolidation or reallocation.
- No real-time alerts or anomaly detection — observability T5.

## Decisions

### D1 — Retainer pools are per-client/project with effective-period windows

`RetainerPool` defines a monthly allocation (amount, currency, rollover policy)
with start/end dates. Multiple pools can exist per client (e.g., "Project A
Jan 2026" and "Project A Feb 2026") with non-overlapping periods. This
decouples retainer structure from billing period lifecycle.

### D2 — Drawdowns are time-entry consumption records, not rates

`RetainerDrawdown` records each time entry's consumption of the retainer pool
(drawdown-date, drawdown-amount, rate-applied, time-entry reference). The
drawdown-amount is (hours × retainer-rate), not the time-entry rate. This
separates retainer tracking from timesheet billing.

### D3 — Rollover policies are pool-level configuration

Each `RetainerPool` specifies rollover behavior: carryover-max (amount or
hours), reset-balance (y/n), and carryover-cap-unit (EUR or hours). This
allows per-client retainer philosophy (e.g., Client A always carries over
up to 50 hours; Client B resets monthly).

### D4 — Drawdown is an aggregation query, not a service

Given (pool-id, as-of-date), the drawdown-balance aggregation returns current
available balance: pool-amount - sum(drawdowns) - sum(true-up adjustments).
If OR's `x-openregister-aggregations` is stable, this is pure declarative
metadata. Per ADR-031 exception, if not stable, a single-method
`RetainerDrawdownGuard` ships, documented.

### D5 — True-up is period-end, automatic, and materialized

On period close, `RetainerTrueUp` is auto-triggered: compare actual drawdown
vs. pool-amount, calculate overage (if drawdown > pool), apply standard
overage-rate from rate-card-engine, and generate an immutable `RetainerTrueUp`
record with settlement status. Optional: auto-generate adjustment invoice.

### D6 — Rollover amounts are immutable after period close

For deterministic historical queries, rollover-amounts calculated at period-end
are immutable; future changes to the rollover policy don't retroactively adjust
prior periods. Audit trail is preserved in `RetainerRollover` register.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Monthly retainer structure | Budget entity (ADR-000, budget-planning-control) | Distinct from Budget; retainer pools are per-client/project with drawdown tracking + rollover, not departmental budget allocation. |
| Period lifecycle (month start/end) | OR `x-openregister-lifecycle` (ADR-031) | RetainerPool and RetainerTrueUp use period start/end; OR lifecycle extension for period-triggered actions. |
| Drawdown aggregation (balance queries) | OR `x-openregister-aggregations` | Aggregation query (pool-amount - sum of drawdowns ± rollovers) per pool-id and date; falls back to `RetainerDrawdownGuard` per ADR-031 exception. |
| Materialized audit trail | T2 `bookkeeping-audit-trail` | RetainerDrawdown, RetainerRollover, RetainerTrueUp registers store all pool mutations with timestamp + effective period. |
| Overage-rate lookup | rate-card-engine (T2 RateSchedule) | Overage billing uses standard rate from rate-card-engine for consistency. |
| Manifest navigation | T1 manifest pattern | 4 entries (Retainer Pools, Drawdowns, Rollovers, True-Ups) + their index/detail pages. |

## Seed Data

Example retainer-pool structure for a Dutch SMB (3 clients, 2 projects):

**RetainerPool (RETN-2026-01-001):**
- Client: "Gemeente Amsterdam"
- Period: 2026-01-01 to 2026-01-31
- Pool amount: €3,000
- Retainer rate: €75/hour
- Rollover policy: carryover-max 50 hours, reset-balance: n
- Currency: EUR
- Status: active

**RetainerPool (RETN-2026-01-002):**
- Client: "TechStartup BV"
- Project: "Platform Migration"
- Period: 2026-01-01 to 2026-01-31
- Pool amount: €5,000
- Retainer rate: €100/hour
- Rollover policy: carryover-max €2,000, reset-balance: y (no carryover)
- Currency: EUR
- Status: active

**RetainerDrawdown examples (Jan 2026):**
1. Date: 2026-01-10, Pool: RETN-2026-01-001, Hours: 10, Rate: €75/hour, Amount: €750
2. Date: 2026-01-15, Pool: RETN-2026-01-001, Hours: 15, Rate: €75/hour, Amount: €1,125
3. Date: 2026-01-20, Pool: RETN-2026-01-001, Hours: 20, Rate: €75/hour, Amount: €1,500
   - Total drawdown: €3,375 (exceeds pool)
4. Date: 2026-01-05, Pool: RETN-2026-01-002, Hours: 25, Rate: €100/hour, Amount: €2,500
   - Remaining pool: €2,500

**RetainerTrueUp (Jan 2026 period-end):**
- Pool: RETN-2026-01-001
- Pool amount: €3,000
- Actual drawdown: €3,375
- Overage amount: €375
- Overage rate: €85/hour (standard rate from rate-card-engine)
- True-up adjustment: €375 (owed by Gemeente)
- Status: pending-invoice
- Created: 2026-02-01T10:00:00Z

**RetainerRollover (Feb 2026 opening, from Jan):**
- Previous period pool: RETN-2026-01-001
- Carryover hours: 0 (pool overspent)
- Carryover amount: €0
- New period pool: RETN-2026-02-001 (fresh €3,000 for Feb)
- Rollover cap applied: carryover-max 50 hours → 0 (overspent)

## Design Trade-offs

| Trade-off | Choice | Rationale |
|---|---|---|
| Per-client vs. per-contract pool | Per-client/project (period-based) | Simplicity; multi-contract pools (2026-Q3+) can layer on top. Matches Dutch SMB retainer practice. |
| Carryover cap in amount vs. hours | Both supported | Amount for cost predictability; hours for capacity planning. Configured per pool. |
| Auto-true-up vs. manual trigger | Auto at period close | Deterministic; manual override allowed by approvers. Reduces operator burden. |
| Drawdown rate = retainer rate vs. billable rate | Retainer rate (configured on pool) | Pool consumption is separate from invoice-billing rate. Simplifies reconciliation. |
| Single pool per period vs. multi-month | Single per period | Non-overlapping periods prevent ambiguity. Multi-month pools (2026-Q3+) future extension. |
| True-up invoice auto-generation | Optional; settable per org policy | Flexibility for organizations that prefer manual review before invoice. Default: auto-generate if overage. |

## Implementation Constraints

1. **Non-overlapping periods**: For each (client, project) pair, RetainerPool
   effective-periods MUST NOT overlap. Validation at schema or aggregation
   precondition level.

2. **Drawdown rate immutability**: Drawdown-amount MUST be calculated using
   the pool's configured retainer-rate, not the time-entry billable rate.
   Rate changes to RateCard do not affect historical drawdowns.

3. **Period-close automation**: True-up triggering relies on calendar-driven
   period-close events. If period definition is delayed, true-up manual
   trigger must be available via UI.

4. **Rollover rounding**: Carryover calculations (e.g., remaining hours,
   carryover-cap enforcement) MUST round consistently (floor, ceil, or
   banker's) per org policy; default: banker's rounding.

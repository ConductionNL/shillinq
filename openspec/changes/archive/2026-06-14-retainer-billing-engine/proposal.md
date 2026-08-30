# Proposal: retainer-billing-engine

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`RetainerPool`, `RetainerDrawdown`, `RetainerRollover`, `RetainerTrueUp`) +
pool management (monthly balance, drawdown tracking, rollover policy) with
automatic true-up at period end. Consumption via `TimeEntry` retainer-pool
lookup or AR invoice line retainer adjustment. No PHP retainer-calculation
service (ADR-031 exception: single guard for drawdown logic if OR
aggregation extension is not stable).

## Summary

Introduce the **retainer-billing-engine** capability for Shillinq as a
cross-tier retainer management system enabling monthly retainer pools
with drawdown from time entries, rollover of unused balances, and true-up
at period end. The capability declares `RetainerPool` (monthly pool per
client/project), `RetainerDrawdown` (time entry consumption tracking),
`RetainerRollover` (unused balance rollover policy), and `RetainerTrueUp`
(period-end reconciliation) registers; provides drawdown aggregation for
available-balance queries; and materializes resolved drawdowns into audit
trail for billing reconciliation.

This capability supports billable time tracking with retainer constraints:
time entries booked at a specific rate consume from a monthly retainer pool;
unused balance either rolls over to the next month (with optional cap) or
resets; work beyond the pool is billed at standard overage rate; period-end
true-up reconciles actual vs. forecasted drawdown and generates adjustment
invoices.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure.

**Depends on:** rate-card-engine (standard rate lookup for overage billing).

## Motivation

Retainer billing is demanded by 9/26 competitors (BigTime, Clio, Deltek,
Everhour, Kantata, Moneybird via subscriptions, Replicon, Tempo, Yuki).
The common pattern: monthly retainer pool per client/project with drawdown
from time entries, rollover policy (unused hours roll to next month or reset),
and true-up at period end for billing reconciliation.

Per ADR-022, drawdown calculation and pool-balance queries are declarative
aggregation, not a `RetainerService`. Per ADR-031, drawdown materialization
for audit trail is a declarative lifecycle action, not custom code.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`retainer-billing-management`); declares 4 new registers
  (`RetainerPool`, `RetainerDrawdown`, `RetainerRollover`, `RetainerTrueUp`)
  with period lifecycle; adds drawdown-balance aggregation; adds 4 manifest
  navigation entries (Retainer Pools, Drawdowns, Rollovers, True-Ups).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle` (period windows), `x-openregister-aggregations`
  (balance queries).
- [ ] Project: (future) time-tracking, AR invoicing — consumes drawdown
  resolution and overage-rate lookup via aggregation query.

## Scope

### In Scope

- One new capability spec (`retainer-billing-management`) — see the
  `specs/` folder.
- The `RetainerPool` register with client/project, monthly amount, currency,
  rollover policy (carryover-max, reset), and effective-period windows.
- The `RetainerDrawdown` register tracking each time entry's consumption of
  retainer pool (drawdown-date, drawdown-amount, rate-applied, entity reference).
- The `RetainerRollover` register recording unused-balance rollover from
  month-end to month-start, with carryover cap and reset-balance tracking.
- The `RetainerTrueUp` register for period-end reconciliation (actual vs.
  forecasted drawdown, adjustment amount, overage-rate applied, settlement
  status).
- Drawdown-balance aggregation: given (pool-id, as-of-date), resolve current
  available balance (pool-amount - sum of drawdowns ± rollover adjustments).
- Period-end true-up automation: trigger on period close, calculate actual
  drawdown vs. pool, generate `RetainerTrueUp` record and optional adjustment
  invoice for overage.
- Manifest navigation: 4 entries (Retainer Pools, Drawdowns, Rollovers,
  True-Ups) with their `type: index` / `type: detail` pages.

### Out of Scope

- No multi-month carryover analysis or what-if forecasting — period-by-period
  true-up only.
- No retainer-pool reallocation mid-period or pool-merge workflows.
- No real-time drawdown alerts or budget-exhaustion warnings — T5 observability.
- No cross-client/project retainer pooling — pools are per-entity for now.

### Dependencies

- **Depends on:** rate-card-engine (standard overage rate lookup per TimeEntry).
- **Feeds into:** Time tracking (retainer-pool balance check on time-entry
  creation), AR invoicing (retainer-drawdown materialization, true-up
  adjustment invoices).

### Constraints

- Pool amounts, rollover policies, and period windows MUST be immutable once
  a period closes so historical true-ups remain consistent.
- Drawdown MUST be recorded at the rate agreed in the pool definition (not
  the timesheet entry rate) for pool consumption tracking.
- True-up MUST trigger automatically on period close; manual adjustments are
  permitted only by designated approvers.
- Overage rate MUST be resolved from rate-card-engine for consistency.

## Risks

1. **Drawdown calculation stability**: If OR's `x-openregister-aggregations`
   extension is not stable, a single-method `RetainerDrawdownGuard` ships as
   per ADR-031 exception; documented in spec.
2. **Period-close automation timing**: True-up triggering relies on period-close
   calendar; if period definition is delayed or skipped, true-up may not fire.
   Mitigation: manual trigger + audit trail in RetainerTrueUp register.
3. **Rollover policy enforcement**: Carryover cap and reset rules must be
   configured per pool; misconfiguration (e.g., unlimited carryover) may cause
   billing disputes. Mitigation: journeydoc pattern (ADR-030) for pool setup.

## Rollback

Retainer-billing-engine is optional: if rolled back, retainer pools revert
to simple monthly subscriptions (flat-rate per month with no drawdown tracking).
No data loss (registers are preserved; just not queried). Existing time entries
and invoices unaffected.

## Open Questions

1. Should `RetainerPool` be shareable across administrations, or
   per-administration only? → Per-administration for now (OU isolation);
   multi-OU sharing (2026-Q3+) is a future T4 addition.
2. Should rollover cap be expressed as hours, amount, or percentage? → Both
   amount and hours supported; cap configured per pool (e.g., max €500 or
   50 hours carry-forward).
3. Should true-up be mandatory at period close, or optional for approver
   review? → Auto-trigger on period close; approver review before invoice
   generation per REQ-RETN-011.
4. Should overage work beyond the pool be auto-billed, or flagged for manual
   review? → Auto-billed at standard rate; flagged as "overage" for visibility.

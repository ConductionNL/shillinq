# Proposal: bookkeeping-trial-balance

**Kind:** Reporting & Analytics

Introduce the trial balance reporting capability for Shillinq as **Tier 2 of the 5-tier bookkeeping rollout**. This change adds a declarative trial balance aggregation view showing account balances at period start (opening), period activity (movements), and period end (closing) — consumed as an OpenRegister aggregation schema per ADR-031, wired into `src/manifest.json`, and rendering via `CnChartWidget` + `CnDetailCard` components from `@conduction/nextcloud-vue`.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure and `ConfigurationService::importFromApp()` repair-step patterns.

## Summary

Trial balance is the foundational period-close procedure: a report reconciling all general ledger accounts at fiscal period boundaries. It surfaces:

1. **Opening balance** — account balance at period start (closing balance from prior period)
2. **Period movements** — net debits and credits posted in the current period
3. **Closing balance** — opening + movements (proof of GL balancing)

Currently Shillinq ships T1 (bookkeeping-foundation) with chart-of-accounts, general ledger, and journals, but no aggregated reporting. Trial balance closes the gap between individual GL postings and management/financial reporting, enabling period close workflows and upstream T3 (VAT/tax) and T4 (financial statements).

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md) for the canonical 5-tier breakdown. This change delivers **Tier 2** analysis — **trial balance** — as a read-only aggregation.

## Motivation

T1 provides balanced GL postings with `period_id` stamped on every line, but offers no aggregated view. Operators cannot:
- Verify accounts are balanced at period boundaries without manual query-and-sum
- Produce a trial balance report for auditors / statutory filing
- Confirm period activity before advancing to period close
- Start T3 (VAT filing, financial statements) without a trial balance to reconcile

Trial balance is a MUST-HAVE blocker for downstream tiers. No invoice, tax, or financial reporting capability can post without first confirming the GL is balanced. RGS and Dutch bookkeeping standards require trial balance as the formal control point.

## Affected Projects

- [x] Project: shillinq — adds 1 new read-only aggregation schema to `lib/Settings/shillinq_register.json`, adds 1 manifest navigation entry in `src/manifest.json`, adds backend `TrialBalanceController` for period-range queries
- [ ] Project: openregister — no source changes; this change consumes existing OR aggregations (`x-openregister-aggregations`)
- [ ] Project: docudesk — no source changes

## Scope

### In Scope

- Read-only aggregation schema `TrialBalance` — captures (period_id, accountNumber, accountName, accountType, openingBalance, debit, credit, closingBalance)
- Period-range filtering: operator selects fiscal period(s), system computes aggregates
- Opening balance logic: for period N, opening = closing balance from period N-1 (or zero if first period)
- Seed data: 5 example trial balance snapshots per administration in design.md
- Manifest navigation entry: Bookkeeping > Trial Balance, `type: detail` page with table + summary KPIs
- Backend service: `TrialBalanceService::compute(administrationId, periodId, filters)` → aggregated dataset
- Frontend components: list view via `CnDataTable`, KPI cards (Total Assets, Total Liabilities, Total Equity), period selector

### Out of Scope

- **Automated posting generation** — trial balance is read-only, does not create GL entries
- **Multi-currency revaluation** — FX adjustments handled in T4-base
- **Budget variance** — budget-vs-actual comparison is separate spec
- **Drill-down into GL details** — hyperlinks to GL transactions deferred to T3
- **PDF export of trial balance** — docudesk integration deferred
- **Customizable account sorting / grouping** — future enhancement
- **T3+ features** (VAT filing, financial statements, period close workflow) — separate changes

## Approach

Single delta adding one new capability spec:

1. **`bookkeeping-trial-balance`** — declares a read-only `TrialBalance` aggregation schema computed from `GLTransaction` + `GLLine` joined with `Account` hierarchy. Aggregation groups by `(period_id, accountNumber)` and sums `debit`, `credit`, computes `closingBalance = openingBalance + debit - credit`. Persisted as materialized view or computed on-demand per `TrialBalanceService` — design.md explains the trade-off.

The spec follows conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement prefixed `REQ-TB-NNN`.

## New Dependencies

None — consumes existing OpenRegister aggregations and `@conduction/nextcloud-vue` (already pinned from T1).

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 read-only aggregation schema (`TrialBalance`)
- `src/manifest.json` — adds 1 navigation entry (Trial Balance) with 1 `type: detail` page
- `lib/Controller/TrialBalanceController.php` — GET endpoint for period-range aggregation queries
- `lib/Service/TrialBalanceService.php` — compute method wrapping the aggregation logic
- `lib/Service/OpenRegisterService.php` (existing) — calls to fetch GL + Account data for aggregation
- No new Vue components — uses `CnDetailCard` + `CnDataTable` from library

## Cross-Project Dependencies

- **OpenRegister** — depends on aggregation support being stable (`x-openregister-aggregations` or equivalent). If the engine does not support cross-table joins in aggregations, trial balance computation moves into `TrialBalanceService::compute()` in PHP (design.md explains fallback).
- **T1 foundation** (`bookkeeping-foundation` spec) — trial balance reads from `GLTransaction`, `GLLine`, `Account` schemas; requires T1 to be deployed and seeded

## Risks

### Risk 1: OpenRegister aggregation engine cannot join across GL + Account tables

**Severity**: Medium  
**Mitigation**: If OR's aggregation primitive does not support cross-table joins, `TrialBalanceService::compute()` fetches raw GL + Account data from OR via ObjectService and computes sums in PHP (2-3 queries, O(N) memory, ~100 LOC). Slower than a DB view but acceptable for typical administrations (10K accounts, 100K GL lines per period). Design.md explains chosen path.

### Risk 2: Opening balance cardinality — first period vs inherited

**Severity**: Low  
**Mitigation**: Trial balance explicitly defines opening = closing from prior period; if no prior period exists, opening = 0. Spec requirement REQ-TB-004 makes this mandatory.

### Risk 3: Period selector edge cases — multi-period queries, period gaps

**Severity**: Low  
**Mitigation**: Trial balance report restricted to single period (UX simplicity). Multi-period comparison deferred to T4 (budget variance, year-over-year). Requirement REQ-TB-005 mandates single-period mode initially.

## Rollback Strategy

Aggregation-only change. To roll back: revert the commit; delete the change folder; no GL data affected (trial balance is read-only). After implementation, rollback follows standard pattern: revert implementing PR, re-run repair step (unused aggregation schema remains queryable but unreferenced).

## Open Questions

1. **Materialized view vs. on-demand computation** — design.md Rationale section explores both; `opsx-ff` discovery phase settles the choice based on administration size, query frequency, and storage constraints.
2. **Opening-balance source: carry from prior period or recompute from GL?** — if GL history is immutable, opening = prior closing is safe; if GL has reversals/corrections, should opening be computed fresh from GL opening-balance transactions? REQ-TB-004 assumes prior-period closure.
3. **Period naming: fiscal-period ID vs. calendar date range?** — T3 defines `FiscalPeriod` schema; trial balance accepts period_id FK. If T3 slips, trial balance accepts period identifier string per T1 patterns.

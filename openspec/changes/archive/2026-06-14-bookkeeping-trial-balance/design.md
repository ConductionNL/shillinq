# Design — Trial Balance (Tier 2)

## Context

Shillinq T1 (bookkeeping-foundation) delivers balanced GL postings with hierarchical chart-of-accounts, but no aggregated period reporting. Trial balance is the canonical period-control procedure: a tabular view of every account's opening balance, period debits/credits, and closing balance. It serves four critical functions:

1. **Period reconciliation** — proof the GL is balanced (sum of debits = sum of credits)
2. **Audit control** — required statutory document in Dutch bookkeeping (RGS, BBV)
3. **Staging for downstream tiers** — T3 (VAT, financial statements) depends on a validated trial balance before advancing period close
4. **Management reporting** — operators confirm account activity before posting period-close adjustments

Currently, T1's GL is queryable but not aggregated. Operators cannot produce a trial balance without manual SQL-like exploration. Trial balance closes this gap with a read-only aggregation schema and period-range API.

## Goals

- Express trial balance as a **read-only OpenRegister aggregation** — no custom tables, no imperative balance logic, declarative aggregation rules per ADR-031.
- Make the report **period-scoped** — operator selects a fiscal period, system computes opening (from prior period closing) + movements (GL activity) + closing (opening + net activity).
- Support **hierarchy roll-up** — account balances include child-account contributions automatically via account hierarchy traversal.
- Keep the UI **simple** — single table with sortable columns (account number, name, opening, debit, credit, closing), summary KPI cards (total assets, liabilities, equity).
- Enable **downstream T3 workflows** — trial balance becomes the validation gate before period close and VAT filing.

## Non-Goals

- No customizable sorts / groupings — account order is fixed (by chart hierarchy)
- No drill-down links to GL details — deferred to T3
- No PDF / Excel export — docudesk integration deferred
- No budget variance or multi-period comparisons — T4 features
- No automated adjustment postings — trial balance is read-only

## Decisions

### D1 — Read-only aggregation, not imperative service

Trial balance is fundamentally a SQL aggregation: `GROUP BY period_id, accountNumber; SUM(debit), SUM(credit)`. Per ADR-031, aggregations are declared in `x-openregister-aggregations`, not authored as PHP service methods. The TrialBalance schema is read-only: operators cannot POST/PUT/DELETE TrialBalance records. The schema is materialized by the OR engine on demand or persisted as a view.

**Alternative considered**: Build a BookkeepingReportingService with `getTrialBalance(period)` method. Rejected — that is bespoke, non-reusable imperative code. Aggregation declaration makes the shape auditable and portable across tools.

### D2 — Opening balance sourced from prior-period closing

Every period N's trial balance carries:
- `openingBalance = TrialBalance(period_N-1).closingBalance`
- `closingBalance = openingBalance + (debit - credit for GL activity in period N)`

This assumes prior-period closing balance is the GL source of truth. If an account carries no GL activity in period N, its closing balance equals opening balance (inherited from prior period).

**Alternative considered**: Compute opening from GL opening-balance transactions. Rejected — requires explicit opening-balance journal entries, which adds ceremony. Prior-period closure is simpler and aligns with RGS practice.

### D3 — Materialization strategy: on-demand computed, not persisted view

The TrialBalance aggregation is computed at query time from GL + Account data. No background job materializes the view. Rationale:

1. **Simplicity** — no schedule/trigger machinery, no storage overhead
2. **Consistency** — each query reflects current GL state; no stale data
3. **Scale** — typical administrations (10K accounts, 100K GL lines per period) compute in <1s

For very large administrations (100K+ accounts, multi-million GL lines), a materialized view or materialized table with refresh schedule can be added in T4+ optimization.

**Alternative considered**: Daily materialized snapshots via scheduled job. Rejected — adds scheduling complexity and stale-data risk during operator edits. On-demand suffices for now.

### D4 — Hierarchy roll-up: natural hierarchy from Account.parentAccountNumber

The chart-of-accounts defines a tree: T1–T5 tier accounts, sub-accounts, etc. Trial balance inherits this hierarchy:

- Leaf accounts (no children) carry GL postings directly
- Parent accounts (node accounts with children) roll up all descendants' balances automatically via traversal

The aggregation's `GROUP BY accountNumber` includes both leaf and node accounts. The closing balance for a parent = sum of closing balances of its children. No separate roll-up schema.

**Alternative considered**: Separate account-hierarchy denormalization table. Rejected — adds ceremony; account tree is already defined in Account; aggregation traversal is built into OR.

### D5 — Period field: mandatory, single-period filtering

Every TrialBalance record carries `period_id` (FK to FiscalPeriod, or string identifier for T1 compatibility). Queries always filter by single period: `TrialBalance.filter({ period_id: "2026-Q1" })`.

Multi-period comparisons (Y-o-Y, quarter-over-quarter) are deferred to T4 reporting.

**Alternative considered**: Support multi-period queries returning time-series data. Rejected — UI complexity; single-period trial balance is the blocking feature for T3 period close; time-series can be added later.

### D6 — No custom GL posting rules in aggregation

The trial balance aggregation sums existing GL postings without modifying them. It does not trigger automated adjustments (depreciation, accruals, rounding) — those are separate T1 journal workflows. Trial balance is **read-only on the GL**.

### D7 — Seed data: 5 realistic example trial balances

Design.md includes 5 trial-balance snapshots (seed data) for:
1. SMB (mid-market manufacturing) — 2026-01-31 (opening after year-end close)
2. ZZP (freelancer) — 2026-01-31 (simplified account set, few accounts active)
3. Government entity (municipality) — 2026-01-31 (BBV chart, many sub-accounts)
4. Multi-account closure mid-year (mid-market) — 2026-06-30 (half-year close)
5. Period with corrections (SMB after bank-reconciliation adjustments) — 2026-02-28 (shows debit/credit rebalancing)

Each seed record includes concrete account numbers (from RGS templates), realistic amounts (EUR), and correct hierarchy roll-up.

## Reuse Analysis

| Feature | Reused From | Why |
|---------|-----------|-----|
| GL data fetch | ObjectService | OR service, stable, tested |
| Account hierarchy | Account register + relations | T1 provides account tree |
| Aggregation grouping | `x-openregister-aggregations` | OR declarative primitive |
| Period field stamping | T1 GLLine.period_id | T1 already stamps period |
| UI table display | `CnDataTable` from @conduction/nextcloud-vue | Sort, pagination, rendering |
| Period selector | `NcSelect` from @nextcloud/vue | Standard Nextcloud component |

No custom code for aggregation, no duplicate period logic, no reimplemented GL queries.

## Declarative-vs-Imperative Decision

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| Aggregation rule | Declarative `x-openregister-aggregations` in schema metadata | Per ADR-031; auditable, portable, stable |
| Opening-balance logic | Declarative in aggregation precondition (period_id + prior-period reference) | No imperative lookup needed if prior period exists |
| Hierarchy roll-up | Built-in to Account.parentAccountNumber traversal | Avoid denormalization; leverage Account schema |
| Guard on period-range query | None — single-period filtering only | Simplicity; multi-period deferred to T4 |
| Balance-invariant check | Read-only aggregation (no modification gate needed) | GL invariant already enforced by T1 |

## Trial Balance Schema Detail

```json
{
  "TrialBalance": {
    "description": "Read-only period trial balance aggregation from GL transactions",
    "properties": {
      "period_id": { "type": "string", "description": "Fiscal period identifier" },
      "accountNumber": { "type": "string", "description": "RGS account code" },
      "accountName": { "type": "string", "description": "Human-readable account name" },
      "accountType": { "type": "string", "enum": ["assets", "liabilities", "equity", "revenue", "expenses"] },
      "openingBalance": { "type": "number", "description": "Balance at period start (prior-period closing)" },
      "debitMovement": { "type": "number", "description": "Sum of debits posted in period" },
      "creditMovement": { "type": "number", "description": "Sum of credits posted in period" },
      "closingBalance": { "type": "number", "description": "Opening + (debit - credit)" },
      "currency": { "type": "string", "default": "EUR", "description": "Account currency" },
      "parentAccountNumber": { "type": "string", "description": "Parent account for hierarchy (inherited from Account)" }
    },
    "x-openregister-aggregations": {
      "groupBy": ["period_id", "accountNumber"],
      "aggregates": {
        "debitMovement": "SUM(GLLine.amount WHERE GLLine.side = 'debit')",
        "creditMovement": "SUM(GLLine.amount WHERE GLLine.side = 'credit')"
      },
      "joins": [
        { "table": "GLLine", "on": "GLLine.period_id = period_id" },
        { "table": "Account", "on": "Account.accountNumber = accountNumber" }
      ],
      "readonly": true
    }
  }
}
```

## Seed Data

### Example 1: SMB Opening Balance (2026-01-31)
- Account 1000 (Assets): openingBalance=50000, debit=10000, credit=5000, closingBalance=55000
- Account 2000 (Liabilities): openingBalance=20000, debit=3000, credit=8000, closingBalance=25000
- Account 3000 (Equity): openingBalance=30000, debit=0, credit=0, closingBalance=30000
- (Totals balance: Assets 55000 = Liabilities 25000 + Equity 30000)

### Example 2: ZZP (2026-01-31)
- Account 1000 (Bank): openingBalance=5000, debit=2000, credit=1000, closingBalance=6000
- Account 4000 (Revenue): openingBalance=0, debit=0, credit=15000, closingBalance=-15000
- Account 6000 (Expenses): openingBalance=0, debit=12000, credit=0, closingBalance=12000
- (Simplified set, 3 active accounts)

### Example 3: Government Entity (2026-01-31)
- Account 1100 (Cash): openingBalance=100000, movements as per BBV chart
- Account 2100 (Grants Payable): openingBalance=50000, movements
- Account 3100 (General Fund): openingBalance=50000, movements
- (Full BBV chart with 20+ accounts showing municipality cash/grants model)

### Example 4: Mid-Year Close (2026-06-30)
- Comprehensive snapshot at 6-month boundary showing half-year activity accumulation

### Example 5: Post-Reconciliation (2026-02-28)
- Trial balance after bank-reconciliation adjustments, showing corrected account balances

## Backend Architecture

**TrialBalanceController** (new):
- `GET /api/trial-balance?period_id=<id>` → JSON trial balance for period
- Calls `TrialBalanceService::compute(administrationId, periodId)`

**TrialBalanceService** (new):
- Queries OR aggregation schema if available
- Falls back to PHP computation (fetch GL + Account, compute sums in-memory) if OR aggregation unavailable
- Returns structured dataset: `[ { periodId, accountNumber, accountName, opening, debit, credit, closing }, ... ]`

**OpenRegisterService** (existing):
- Used by TrialBalanceService to fetch raw GL and Account data if fallback is needed

## Frontend Architecture

**TrialBalanceDetailPage** (Vue component, future):
- Period selector (NcSelect dropdown, defaults to current period)
- KPI cards: Total Assets, Total Liabilities, Total Equity, summary message
- CnDataTable: sortable columns (account number, name, opening, debit, credit, closing)
- Optional sub-account expansion (if hierarchy UI matures)

For MVP (this change), use `CnDetailCard` from @conduction/nextcloud-vue rendering the aggregation as read-only detail; future iteration adds custom detail-page component.

## Testing Strategy

- **Unit**: `TrialBalanceService::compute()` with mock GL/Account data; verify opening-balance precedence, sum correctness
- **Integration**: Query OR aggregation with real GL + Account data; verify group-by and sum accuracy
- **Acceptance**: Manual test in dev env: create GL postings → query trial balance → validate balances match GL aggregate
- **Seed data**: 5 examples verified against manual RGS-calculation spreadsheet

## Migration Path

No data migration required. TrialBalance is read-only, computed on demand from existing GL. If prior period has no closing snapshot, opening = 0 for first period.

## Documentation

- User Guide: Trial Balance section explains what opening/closing means, how to read the table
- Admin Guide: notes on performance (typical query time), when to materialize view for large administrations
- Architecture Guide: explains aggregation declaration, fallback to PHP computation, opening-balance logic

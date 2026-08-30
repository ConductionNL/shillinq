# Proposal: financial-dashboard-graphs

`kind: feature` — frontend-only change. The Financial overview
dashboard (manifest page `Dashboard`, route `/`) currently shows
eight `stats-block` count cards (GL transactions, ledger accounts,
bank accounts, …). Counts of bookkeeping artifacts are operator
plumbing, not financial insight: an organisation reads its position
through margin, turnover, cashflow, billable utilisation and the
open debtor/creditor positions.

## Summary

Replace the count cards with a financial-insight dashboard:

- **KPI strip** (full width): turnover YTD, margin YTD (€ + %),
  open debiteuren (€ + count), open crediteuren (€ + count),
  billable share this month (% + hours), cash position.
- **Turnover per month** — bar chart, trailing 12 months, computed
  from posted `GLTransaction` + `GLLine` on `revenue` accounts.
- **Margin per month** — revenue/cost columns + margin line with a
  **€ / % toggle** in the widget.
- **Cashflow per month** — realized in/out columns + net line from
  posted GL lines on liquid-asset accounts, with the 13-week
  `CashflowWeek` forecast appended as dimmed projection columns.
- **Billable hours per month** — stacked billable / non-billable
  columns from `UrenRegistratie`, with a **total / % toggle**.
- **Open debiteuren table** — `ARInvoice` in `issued`/`overdue`
  lifecycle states, overdue rows flagged, sorted by due date.
- **Open crediteuren table** — `APTransaction` in open states
  (`received`/`issued`/`partially-paid`/`overdue`), same treatment.

All data is fetched client-side from the OpenRegister objects API
(register slug `shillinq`) through one shared fetch-once module so
the seven widgets issue a single round of requests per page load.
No PHP is added (ADR-022: apps consume OR abstractions; the read
volume at SME scale does not justify server-side aggregation
endpoints).

Custom widgets are wired through the manifest page's `slots` map
(`widget-<id>` → registry component, ADR-036 kind:"widget"
entries) — the page stays a declarative `type: "dashboard"` entry.

A committed seed script (`scripts/seed-demo-financials.py`)
generates a coherent 12-month demo administration (chart of
accounts, balanced GL transactions, customers/vendors, AR/AP
invoices in mixed states, hour registrations, 13-week cashflow
forecast) so the dashboard demonstrates meaningfully on a fresh
environment.

## Motivation

The dashboard is the app's landing page; eight count cards (half of
them showing 0) communicate nothing about the health of the
company. Margin, turnover, cashflow and utilisation are the four
numbers every SME owner/bookkeeper checks first; debiteuren and
crediteuren are the two work queues they act on.

## Non-goals

- No server-side aggregation endpoints or schema
  `x-openregister-aggregations` changes.
- No administration switcher on the dashboard (follows the app-wide
  administration context when that lands; the widgets sum across
  what the API returns).
- No drill-down/filter interactions beyond row links and the two
  toggles.

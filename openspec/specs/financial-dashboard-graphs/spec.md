---
status: done
---

# financial-dashboard-graphs Specification

## Purpose
Provides a financial overview dashboard that visualises an administration's bookkeeping data, including a six-metric KPI strip and charts for turnover, margin, cashflow with forecast overlay, and billable hours, each with value/percentage toggles where relevant. The dashboard also lists open debtor and creditor invoices flagged by due date, draws all widget data from a single shared fetch per schema, and ships an idempotent demo seed script that populates a coherent 12-month bookkeeping story.
## Requirements
### Requirement: Financial KPI strip

The dashboard SHALL render a full-width KPI strip with six metrics
computed from register data: turnover year-to-date, margin
year-to-date (absolute and percentage), open debiteuren (amount and
invoice count), open crediteuren (amount and invoice count),
billable share of the current month (percentage and hours), and
cash position (net balance of liquid-asset accounts).

#### Scenario: KPI strip renders six metrics

- **GIVEN** the register holds posted GL transactions, open AR/AP
  invoices and hour registrations
- **WHEN** the user opens the Financial overview dashboard
- **THEN** the KPI strip shows six labelled metric tiles with
  EUR-formatted amounts

### Requirement: Turnover per month chart

The dashboard SHALL render a bar chart of turnover per calendar
month for the trailing 12 months. Turnover for a month is the sum
of credit minus debit `GLLine` amounts on accounts with
`accountType: revenue`, restricted to lines whose parent
`GLTransaction` is in state `posted`.

#### Scenario: Monthly turnover from posted revenue lines

- **GIVEN** posted GL transactions with revenue lines across
  several months
- **WHEN** the dashboard loads
- **THEN** the turnover chart shows one column per month with the
  summed revenue of that month

### Requirement: Margin per month chart with value/percentage toggle

The dashboard SHALL render a margin chart with a toggle between
absolute (€) and percentage view. The absolute view shows revenue
and cost columns plus a margin line (revenue − costs, both from
posted GL lines classified by `accountType`). The percentage view
shows margin as a percentage of revenue per month.

#### Scenario: Margin toggle switches between euro and percentage

- **GIVEN** the margin chart is rendered in the default € view
- **WHEN** the user activates the % toggle
- **THEN** the chart re-renders showing margin percentage per month

### Requirement: Cashflow per month chart with forecast overlay

The dashboard SHALL render a cashflow chart with realized inflow
and outflow columns plus a net line per month, computed from posted
GL lines on liquid-asset accounts (accounts with
`accountType: assets` whose account number starts with `10` or
whose name identifies a bank/cash account). When `CashflowWeek`
forecast objects exist, future months derived from them SHALL be
appended as visually-dimmed projection columns.

#### Scenario: Realized cashflow with dimmed forecast

- **GIVEN** posted GL lines on bank accounts and CashflowWeek
  forecast rows
- **WHEN** the dashboard loads
- **THEN** the cashflow chart shows realized months followed by
  dimmed forecast months

### Requirement: Billable hours chart with total/percentage toggle

The dashboard SHALL render a stacked column chart of billable
versus non-billable hours per month for the trailing 12 months from
`UrenRegistratie` (billable = `recognisedRate` greater than zero),
with a toggle to a percentage view showing the billable share per
month.

#### Scenario: Billable hours toggle to percentage view

- **GIVEN** hour registrations with and without a recognised rate
- **WHEN** the user activates the % toggle on the billable hours
  widget
- **THEN** the chart shows the billable percentage per month
  instead of stacked hour totals

### Requirement: Open debiteuren table

The dashboard SHALL render a table of open `ARInvoice` objects
(lifecycle states `issued` and `overdue`) sorted by due date
ascending, showing invoice number, customer, invoice date, due
date, amount and state. Invoices past their due date SHALL be
visually flagged as overdue. Rows SHALL link to the AR invoice
detail page.

#### Scenario: Overdue debtor invoice is flagged

- **GIVEN** an issued ARInvoice whose due date is in the past
- **WHEN** the dashboard loads
- **THEN** the debiteuren table shows the invoice flagged as
  overdue

### Requirement: Open crediteuren table

The dashboard SHALL render a table of open `APTransaction` objects
(states `received`, `issued`, `partially-paid`, `overdue`) sorted
by due date ascending, showing invoice number, vendor, invoice
date, due date, amount and state, with past-due rows visually
flagged. Rows SHALL link to the AP transaction detail page.

#### Scenario: Open creditor invoices listed by due date

- **GIVEN** APTransaction objects in open and paid states
- **WHEN** the dashboard loads
- **THEN** the crediteuren table lists only the open ones, ordered
  by due date

### Requirement: Single shared data fetch

The dashboard widgets SHALL obtain register data through one shared
fetch-once module so a dashboard page load issues at most one
request per consumed schema.

#### Scenario: Widgets share one fetch round
@e2e exclude network-call-count is asserted at unit level, not observable as UI behaviour

- **GIVEN** seven dashboard widgets that consume overlapping
  schemas
- **WHEN** the dashboard mounts
- **THEN** each schema is requested exactly once

### Requirement: Demo seed script

The repository SHALL provide a committed, idempotent seed script
that populates a demo administration with a coherent 12-month
bookkeeping story: chart of accounts, balanced posted GL
transactions (revenue, costs, bank movements), customers and
vendors, AR/AP invoices in mixed lifecycle states, hour
registrations with and without recognised rates, and a 13-week
cashflow forecast.

#### Scenario: Seed script populates a meaningful dashboard
@e2e exclude operator CLI tooling against a live instance, exercised manually and by the seeded e2e fixtures themselves

- **GIVEN** a running Nextcloud with shillinq and an empty demo
  administration
- **WHEN** the operator runs `scripts/seed-demo-financials.py`
- **THEN** every dashboard widget renders non-empty data


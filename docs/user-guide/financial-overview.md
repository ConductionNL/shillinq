---
sidebar_position: 1
title: Financial overview
description: The Shillinq Financial overview dashboard — KPI cards, turnover and margin charts, cashflow, and open invoice tables, all filtered by a single date range.
---

# Financial overview

The **Financial overview** is Shillinq's home screen. It shows the key
financial figures of your company derived from your bookkeeping register in
real time.

![Financial overview dashboard showing KPI cards and charts](\screenshots/financial-overview-dashboard.png)

## What you see

### KPI strip

The top row shows the four most important figures for the selected period:

| Card | What it measures |
|------|-----------------|
| **Turnover (YTD)** | Total invoiced revenue in the period |
| **Margin (YTD)** | Revenue minus direct costs, with percentage |
| **Open debtors** | Sum of unpaid customer invoices (accounts receivable) |
| **Open creditors** | Sum of unpaid supplier bills (accounts payable) |
| **Cash position** | Current bank balance(s) across all reconciled accounts |
| **Billable this month** | Uninvoiced time entries eligible for billing |

### Charts

Each chart shows data for the selected date range and has its own
**date chip** in the top-right corner of the widget. Click the chip to change
the period without leaving the dashboard.

| Widget | What it shows |
|--------|--------------|
| **Turnover per month** | Monthly revenue bars |
| **Margin per month** | Revenue vs. costs vs. margin |
| **Cashflow** | Weekly net cash in/out |
| **Billable hours** | Recorded vs. invoiced hours per month |

### Open invoice tables

Below the charts, two tables list outstanding items that need attention:

- **Open debtors** — customer invoices not yet paid, oldest first
- **Open creditors** — supplier bills not yet paid, oldest first

## Date range

All KPI cards and charts respond to the same date range picker, shown as a
pill on each widget (e.g. *Jun 15, 2025 – Jun 14, 2026*). Click any pill to
open the picker and choose a preset or a custom range:

| Preset | Period |
|--------|--------|
| Last 3 months | Rolling 91 days |
| Last 6 months | Rolling 183 days |
| Last 12 months | Rolling 365 days |
| Last 24 months | Rolling 730 days |

The selected range is saved per browser session and restored on next visit.

## Quick actions

The three buttons in the top-right of the page header provide shortcuts to
the most common daily tasks:

| Button | Takes you to |
|--------|-------------|
| **Import bill** | [Supplier invoices](../guides/import-bills.md) — record a new purchase invoice |
| **Create invoice** | [Accounts receivable](../guides/create-invoices.md) — draft a new customer invoice |
| **Import bank** | [Bank reconciliation](../guides/import-bank-statements.md) — upload a bank statement |

## Understanding the numbers

The financial figures are derived from the bookkeeping register. They are only
accurate when:

1. All supplier bills are recorded (see [Import a bill](../guides/import-bills.md)).
2. All customer invoices are created and sent (see [Create an invoice](../guides/create-invoices.md)).
3. Bank statements are reconciled up to the current period (see [Import bank statements](../guides/import-bank-statements.md)).

If a number looks wrong, check whether there are unmatched bank transactions
in **Bookkeeping → Bank reconciliation**.

## What's next

- [Set up your chart of accounts](../guides/setup-chart-of-accounts.md) — configure the accounts that drive these figures
- [Bookkeeping foundations](bookkeeping/bookkeeping-foundations.md) — understand what accounts, ledgers, bills, and invoices mean

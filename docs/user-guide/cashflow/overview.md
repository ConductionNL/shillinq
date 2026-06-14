---
sidebar_position: 1
title: Cashflow management
description: Monitor and forecast your cash position, model scenarios, and manage liquidity buffers in Shillinq.
---

# Cashflow management

The **Cashflow** section gives you a forward-looking view of your cash position — not just what the bank says today, but what it will look like in 30, 60, and 90 days based on open invoices due, bills to pay, and recurring commitments.

## Cashflow dashboard

The **Cashflow dashboard** shows your projected cash position over a configurable horizon (default: 13 weeks). It combines:

| Source | How it is included |
|--------|-------------------|
| Open invoices | Expected on the invoice due date (adjusted for customer's average payment delay) |
| Open bills | Expected outflow on the bill due date |
| Recurring commitments | Salaries, rent, subscriptions, loan repayments |
| Bank balance | The closing balance from your last reconciled bank statement |

The chart shows the minimum projected balance during the period — a dip below zero is a liquidity warning.

## Scenarios

**Scenarios** let you model "what if" situations without affecting real data:
- What if the largest customer pays 30 days late?
- What if we win a new contract worth €50,000?
- What if we delay a major supplier payment?

Create a scenario by copying the base forecast and adjusting specific items. Scenarios are independent — they don't post to the ledger.

## Buffer policy

The **Buffer policy** page sets the minimum cash reserve your organisation wants to maintain. Shillinq flags any period in the forecast where the projected balance drops below the buffer threshold.

Configure:
- **Minimum buffer** — absolute minimum cash balance
- **Target buffer** — desired comfortable level
- **Buffer currency** — EUR (or functional currency if different)

## Recurring items

**Recurring cashflow items** are known future cash movements that don't come from bills or invoices — salary runs, quarterly tax payments, loan instalments, lease payments, standing order subscriptions.

Add each recurring item with:
- Amount and direction (in or out)
- Frequency (weekly, monthly, quarterly, annual)
- Start date and optional end date

Shillinq includes these in the cashflow forecast automatically.

## Related

- [Bank connections](../bookkeeping/bank-connections.md) — live bank balance feeds the starting position
- [Bank reconciliation](../../guides/import-bank-statements.md) — keeping the base balance current
- [Accounts receivable](../../guides/create-invoices.md) — open invoices drive the inflow forecast
- [Accounts payable](../../guides/import-bills.md) — open bills drive the outflow forecast

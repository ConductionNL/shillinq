---
sidebar_position: 11
title: Trial balance
description: Use the trial balance (proefbalans) in Shillinq to verify that debits equal credits and identify any account imbalances.
---

# Trial balance

The **trial balance** (*proefbalans*) lists every GL account with its total debit balance, total credit balance, and net balance for a period. It is the primary tool for verifying that your bookkeeping is in balance — all debit totals must equal all credit totals.

![Trial balance page showing account list with debit and credit columns](/screenshots/trial-balance.png)

## What the trial balance shows

Each row is one GL account. The columns are:

| Column | Meaning |
|--------|---------|
| Account number | RGS code |
| Account name | Description from your chart of accounts |
| Opening balance | Balance at the start of the period |
| Debit movements | Total debits posted in the period |
| Credit movements | Total credits posted in the period |
| Closing balance | Opening + debits − credits |

The bottom row shows totals. If the bookkeeping is correct, **total debits = total credits** in both the movement columns and the balance columns.

## Typical uses

- **Period-end check** — before closing a period, run the trial balance to confirm everything balances.
- **Find a misposting** — filter by a specific account or amount to trace an unexpected balance.
- **Provide to your accountant** — a standard deliverable for external review or annual accounts preparation.
- **Reconcile sub-ledgers** — compare the `Debiteuren` account balance here against the total of open invoices in Accounts Receivable.

## Set the period

Use the **period selector** at the top to choose a start and end date. Shillinq calculates all movements and opening/closing balances for that period from posted transactions.

## Filter and group

- **Search** by account number or name to jump to a specific account.
- **Group by type** to see subtotals per account type (asset, liability, equity, revenue, expense).
- **Hide zero-balance accounts** to focus on accounts with activity.

## Export

Click **Export** to download as:
- **CSV** — import into Excel or your accountant's software
- **PDF** — formatted trial balance for archiving

## Trial balance by account

The **Trial Balance (by account)** view (accessible from the Bookkeeping menu) shows the same data but split into individual transaction lines per account — useful when you need to trace the transactions that make up an account balance.

## Related

- [Balance sheet](balance-sheet.md) — the summarised financial position
- [General ledger](general-ledger.md) — full transaction-level detail
- [Journals](journals.md) — post corrections when you find an imbalance

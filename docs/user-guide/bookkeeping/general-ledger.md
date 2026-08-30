---
sidebar_position: 3
title: General ledger
description: Browse all posted journal entries per account in Shillinq's general ledger (grootboek).
---

# General ledger

The **general ledger** (*grootboek*) is the complete, auditable record of
every financial transaction in your organisation. Every time a bill is
approved, an invoice is sent, a bank statement is reconciled, or a manual
journal entry is posted, it ends up as one or more lines in the general ledger.

![General ledger showing accounts list](\screenshots/general-ledger.png)

## What you see

Each row in the general ledger is one **account** from your
[chart of accounts](../../guides/setup-chart-of-accounts.md). The columns
show:

| Column | Meaning |
|--------|---------|
| **Account number** | The RGS account code (see [RGS account numbering](rgs-account-numbering.md)) |
| **Account name** | The human-readable label |
| **Debit** | Total debit postings to this account in the selected period |
| **Credit** | Total credit postings to this account in the selected period |
| **Balance** | Net balance (debit minus credit, or credit minus debit depending on the account type) |

## Filter by period

Use the date filter at the top of the page to restrict the view to a specific
fiscal period. This is useful for period-end reporting and reconciliation.

## Drill through to account detail

Click any account row to open the **account detail** view, which lists every
individual journal entry line posted to that account, with:

- Date
- Description (from the originating bill, invoice, or bank transaction)
- Debit or credit amount
- Running balance

This is the audit trail. Every entry is traceable back to its source document
(supplier invoice, customer invoice, or bank statement).

## Typical use cases

- **Period-end check**: verify that all revenue is posted to income accounts
  and all expenses to the correct cost accounts.
- **Audit support**: drill into a specific account to show an auditor the
  individual transactions that make up the balance.
- **Reconciliation**: compare the balance on account `10100` (bank) to the
  closing balance of your last imported bank statement.

## Trial balance export

From the general ledger list, click **Actions → Export** to download a trial
balance in CSV format, suitable for import into your accountant's tools or
for year-end reporting.

## Related

- [Chart of accounts](../../guides/setup-chart-of-accounts.md) — set up the
  accounts that appear in the ledger
- [Bookkeeping foundations](bookkeeping-foundations.md) — understand debits,
  credits, and double-entry bookkeeping

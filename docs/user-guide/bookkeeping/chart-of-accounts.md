---
sidebar_position: 4
title: Chart of accounts
description: Browse, add, and manage the ledger accounts in Shillinq's chart of accounts (rekeningschema).
---

# Chart of accounts

The **chart of accounts** (*rekeningschema*) is the index of every account in
your general ledger. Each account represents one specific type of financial
activity — revenue, an expense category, an asset, a liability, or equity.

![Chart of accounts index](\screenshots/chart-of-accounts.png)

## What you see

The table lists all accounts in your chart, sorted by account number. Each row
shows:

| Column | Meaning |
|--------|---------|
| **Account number** | The RGS code (see [RGS account numbering](rgs-account-numbering.md)) |
| **Name** | Human-readable label |
| **Type** | `asset`, `liability`, `equity`, `revenue`, or `expense` |
| **VAT code** | Applicable VAT treatment (blank = non-VAT account) |
| **Balance** | Current running balance from the general ledger |

## Add an account

Click **+ Add account** and fill in:
- `accountNumber` — unique RGS code
- `accountName` — clear label
- `accountType` — the account's financial classification
- `vatCode` — leave blank for non-VAT accounts

See [RGS account numbering](rgs-account-numbering.md) for guidance on choosing
the right number.

## Edit an account

Click any account row to open its detail view. You can change the name, VAT
code, and description. The account number and type cannot be changed after the
account has transactions posted to it.

## Import from RGS 3.4

To populate your chart from the Dutch national standard, go to **Actions →
Import RGS 3.4**. See [Set up your chart of accounts](../../guides/setup-chart-of-accounts.md)
for the full import walkthrough.

## Deactivate an account

Accounts with posted transactions cannot be deleted, but they can be
deactivated. Deactivated accounts no longer appear in the GL account dropdown
when creating bills or invoices, but their transaction history is preserved.

Click an account → **Deactivate**.

## Related

- [Set up your chart of accounts](../../guides/setup-chart-of-accounts.md) — step-by-step setup guide
- [RGS account numbering](rgs-account-numbering.md) — understand and apply the Dutch numbering standard
- [General ledger](general-ledger.md) — view the posted transactions per account

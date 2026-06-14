---
sidebar_position: 3
title: Journals
description: View and post manual journal entries in Shillinq's general journal.
---

# Journals

The **Journals** page is where you create and review **manual journal entries** (*memoriaalboekingen*) — entries that don't come from a bill, invoice, or bank statement. Use this for corrections, accruals, depreciation postings, opening balances, and other non-automated entries.

![Journals index showing empty state with Add Journal Entry button](/screenshots/journals.png)

## When to use manual journal entries

| Situation | Example |
|-----------|---------|
| Opening balance | Load existing account balances when migrating from another system |
| Depreciation | Monthly depreciation entry for a fixed asset not yet automated |
| Accrual | Accrue a cost that is incurred but not yet invoiced |
| Correction | Reclassify a posting from the wrong GL account to the right one |
| Period-end allocation | Spread shared costs across cost centres |

For bill and invoice postings, Shillinq creates journal entries automatically — you should not re-enter those here.

## Create a journal entry

1. Click **+ Add Journal Entry**.
2. Fill in the header:
   - `date` — the posting date (determines which accounting period this entry falls in)
   - `description` — a clear explanation of what this entry represents
   - `reference` — optional internal reference or document number
3. Add journal lines (you need at least two — one debit, one credit):
   - `account` — pick from your chart of accounts
   - `debit` or `credit` — enter the amount on the correct side
   - `costCentre` — optional, if you are tracking by cost centre
   - `description` — optional line-level note
4. Verify that total debits equal total credits (the entry must balance to zero).
5. Click **Save**. The entry is posted immediately to the general ledger.

:::tip Double-entry rule
Every journal entry must balance: the sum of all debit amounts must equal the sum of all credit amounts. Shillinq validates this before saving.
:::

## Recurring journal entries

For entries you post every month (e.g. depreciation, rent accrual), you can save a template:

1. Create and save a journal entry as normal.
2. Click **Save as template**.
3. Next month, go to **Actions → From template**, select your template, adjust the date if needed, and post.

## Reverse a journal entry

To undo a posted journal entry:

1. Open the journal entry detail.
2. Click **Reverse**.
3. Confirm the reversal date (defaults to today, can be changed to the original date).

Shillinq creates a new entry with all debits and credits swapped, and links both entries together so the audit trail is clear.

## Related

- [Bookkeeping foundations](bookkeeping-foundations.md) — understand double-entry bookkeeping
- [General ledger](general-ledger.md) — view all posted transactions per account
- [Chart of accounts](chart-of-accounts.md) — the accounts you can post to

---
sidebar_position: 2
title: Import a bill
description: Record a supplier invoice (accounts payable) in Shillinq — manual entry and PDF import.
---

# Import a bill

A **bill** is a purchase invoice that a supplier sent to you. You owe them
money. In accounting terms this is an **accounts payable (AP)** transaction;
in Dutch it is a *crediteur* or *inkoopfactuur*.

![Supplier invoices index — empty state showing Add Supplier Invoice button](\screenshots/supplier-invoices.png)

:::info Terminology reminder
Shillinq uses **bill** for what you receive, and **invoice** for what you
send. See [Bookkeeping foundations](../user-guide/bookkeeping/bookkeeping-foundations.md)
for the full breakdown.
:::

## Method 1 — Manual entry

1. Open **Inkoop → Supplier invoices**.
2. Click **+ New supplier invoice** (or use the **Import bill** shortcut on
   the Financial overview dashboard).
3. Fill in the header:
   - `supplier` — pick from your Nextcloud contacts list. If the supplier is
     new, create them in Nextcloud Contacts first.
   - `invoiceNumber` — the invoice number printed on the supplier's document.
   - `invoiceDate` — the date on the supplier's invoice.
   - `dueDate` — the payment due date (check the supplier's payment terms).
   - `currency` — defaults to EUR.
4. Add invoice lines. Each line represents one type of expense:
   - `description` — short description of what was purchased.
   - `quantity` and `unitPrice` — Shillinq calculates the line total.
   - `glAccount` — the expense account from your chart of accounts (e.g.
     `83600` for ICT costs).
   - `vatCode` — the applicable VAT treatment (21 %, 9 %, 0 %, or exempt).
5. Verify the totals at the bottom: subtotal, VAT per rate, and grand total.
6. Click **Save**. Shillinq creates the journal entry automatically:

   | Account | Debit | Credit |
   |---------|-------|--------|
   | Expense account (e.g. `83600`) | line total | |
   | BTW voorbelasting `51020` | VAT amount | |
   | Crediteuren `50000` | | invoice total |

7. The bill appears in the **Open bills** list until it is paid and matched
   during bank reconciliation.

## Method 2 — PDF import (OCR)

If you have a PDF of the supplier invoice:

1. Open **Inkoop → Supplier invoices → Import**.
2. Upload the PDF. Shillinq runs OCR and attempts to extract supplier name,
   invoice number, date, amount, and VAT.
3. Review the extracted values. Correct anything the OCR misread.
4. Map each line to a GL account.
5. Click **Save** to confirm the bill.

:::tip
After confirming a few invoices from the same supplier, Shillinq learns to
suggest the correct GL account for that supplier's expense type automatically.
:::

## Approve and post a bill

By default, bills are created in **Draft** status. Depending on your
organisation's approval settings:

- **No approval required** — the bill is posted immediately on save and the
  AP balance updates at once.
- **Requires approval** — the bill stays in Draft until an approver clicks
  **Approve**. Only then is the journal entry posted and the AP balance
  updated.

## Mark a bill as paid (without bank import)

If you pay a bill in cash or via a method not covered by your imported bank
statement, you can mark it paid manually:

1. Open the bill detail.
2. Click **Mark as paid**.
3. Enter the `paymentDate` and `bankAccount`.
4. Shillinq creates the payment journal entry and closes the open item.

For bank-imported payments, see [Import your bank statement](import-bank-statements.md)
— reconciliation closes bills automatically.

## View open bills

**Inkoop → Supplier invoices** shows all bills with their payment status. The
**Financial overview** dashboard widget **Open payables** lists the oldest
unpaid bills so you can prioritise payments.

## Common issues

| Issue | Cause | Fix |
|-------|-------|-----|
| Supplier not found in autocomplete | Not in Nextcloud Contacts | Add the supplier to Nextcloud Contacts first |
| VAT does not match supplier invoice | Wrong VAT code on a line | Set the correct `vatCode` (21 %, 9 %, exempt) on the offending line |
| Duplicate warning | Invoice number already exists for this supplier | Check if the bill was already entered; if it is a different invoice, change the invoice number |

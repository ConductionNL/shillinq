---
sidebar_position: 4
title: Import bank statements
description: Import a CAMT.053 bank statement into Shillinq and reconcile bank payments against open bills and invoices.
---

# Import bank statements

**Bank reconciliation** (*bankverzoening*) matches the payments on your bank
statement to the open bills (what you owe suppliers) and open invoices (what
customers owe you). After reconciliation, both the bank side and the
supplier/customer side are marked as settled.

Use the **Import bank** shortcut on the Financial overview dashboard, or go
to **Bookkeeping → Bank reconciliation**.

![Bank reconciliation index showing import and statement history](\screenshots/bank-reconciliation.png)

## Step 1 — Import your bank statement

Dutch banks export statements in **CAMT.053** (ISO 20022) XML format. Download
this file from your bank's online portal and upload it into Shillinq.

1. Go to **Bookkeeping → Bank reconciliation → Import**.
2. Upload the CAMT.053 file (`.xml`). Shillinq reads:
   - Each payment and receipt on the statement.
   - The IBAN of the counterparty.
   - The payment reference (often contains an invoice number).
   - The amount and value date.
3. Click **Import**. The statement appears in the **Unmatched transactions**
   list.

:::tip MT940 format
If your bank only supports MT940 (older SWIFT format), Shillinq can import
those too. Use **Import → MT940** instead. CAMT.053 is preferred because it
carries more structured data that improves automatic matching.
:::

## Step 2 — Automatic matching

Shillinq tries to match each bank transaction to an open bill or invoice by:

1. **Invoice number in the reference** — if the payment description contains
   your invoice number (e.g. `F2026-042`), Shillinq matches it automatically.
2. **IBAN of the counterparty** — known supplier/customer IBANs narrow the
   candidate list.
3. **Amount** — exact match wins; partial matches are flagged for manual
   review.
4. **Due date proximity** — payments close to the invoice due date rank higher.

Automatically matched transactions show a green tick. Review them quickly;
unmatched transactions are yellow and require your attention.

## Step 3 — Manual matching

For unmatched transactions:

1. Click the transaction row.
2. Shillinq shows candidate open bills or invoices ranked by likelihood.
3. Select the correct open item. If the payment covers multiple invoices, add
   each one; Shillinq splits the matched amounts.
4. If the payment is not an invoice payment (e.g. a bank charge, salary
   payment, tax transfer), click **Assign to account** and pick the GL account
   directly (e.g. `83100` for bank charges).
5. Click **Confirm match**.

## Step 4 — Post the reconciliation

Once all transactions on the statement are either matched or assigned to a GL
account, click **Post reconciliation**. Shillinq:

1. Marks matched invoices as **Paid** and clears them from the open items list.
2. Marks matched bills as **Paid** and clears them from the crediteuren balance.
3. Posts each bank transaction as a journal entry (bank account debit or credit
   vs. AP/AR or GL account).
4. Updates the bank account balance in the general ledger to match the closing
   balance on the statement.

## Reconciliation summary report

After posting, the reconciliation summary shows:

- Opening bank balance
- Total receipts matched to invoices
- Total payments matched to bills
- Total assigned to GL accounts directly
- Closing bank balance (must match the statement's closing balance)

If the closing balance does not match, there is an unmatched transaction. Check
the **Unmatched** tab and resolve it before the period can be closed.

## Bank connections (automatic import)

If you have a bank connection configured (Bunq, ING, Rabobank via PSD2, or
Mollie), Shillinq can pull transactions automatically — no manual file download
needed. Configure connections in **Settings → Bank connections**.

Once a connection is active, Shillinq fetches the latest transactions every
night and adds them to the reconciliation queue automatically. You only need to
review and confirm matches.

## Common issues

| Issue | Cause | Fix |
|-------|-------|-----|
| Import fails | Wrong file format | Verify the file is CAMT.053 XML (not CSV or PDF); re-export from your bank |
| No automatic matches | Invoice numbers not in payment description | Match manually; ask customers to include invoice number in payment reference |
| Closing balance does not match | Unmatched transaction | Check the **Unmatched** tab; an amount may have been posted to the wrong account |
| Double import | Same statement imported twice | The second import is detected and rejected automatically |

## What's next

After your first full reconciliation cycle (import bills → create invoices →
import bank) your books are up to date. You can now:

- Run a **Profit & Loss** report (**Reports → P&L**) to see revenue vs. costs.
- File your **VAT declaration** (**Reports → VAT → Quarterly declaration**).
- Check **Bookkeeping → General ledger** for a full transaction history per
  account.

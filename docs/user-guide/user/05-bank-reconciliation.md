---
sidebar_position: 5
title: Reconcile a bank statement
description: Import a CAMT.053 bank statement, let Shillinq match transactions to invoices and bills, and close out the unmatched lines.
---

# Reconcile a bank statement

Import the daily / weekly bank statement (CAMT.053, ISO 20022) into Shillinq, let the matching engine pair each transaction with the right invoice, bill, or ledger line, and close out anything that didn't match automatically. After reconciliation the bank balance in Shillinq agrees with the bank's balance to the cent.

## Goal

By the end the statement will be reconciled: every transaction either matched to an open invoice/bill or posted to a ledger account, the bank account's Shillinq balance equal to the bank's reported balance.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to reconcile, bookkeeping role on the Shillinq instance.
- A **bank account** record in Shillinq linked to the IBAN the statement is for.
- A bank statement in **CAMT.053** XML (ISO 20022), pulled from the bank's portal or via PSD2 (when wired up).
- Open invoices / bills in Shillinq to match against, Shillinq has nothing to pair if the AR / AP ledgers are empty.

## Steps

1. Open **Banking → Reconciliation**. Pick the bank account, then **Import statement** and drop the CAMT.053 file. Shillinq parses it, lists each transaction, and shows the running balance.

   ![Statement imported](/screenshots/user-guide/user/05-bank-reconciliation-01.png)

2. The matching engine runs. For each transaction it scores the open AR / AP records by amount, counterparty IBAN, mandate / SEPA reference, and the human-readable description. High-confidence matches auto-pair; medium / low confidence land in the *Needs review* pane.

   ![Matching engine results](/screenshots/user-guide/user/05-bank-reconciliation-02.png)

3. Work through *Needs review*. For each transaction, accept the suggested match, pick an alternative, split the transaction across multiple invoices / bills, or post it to a ledger account directly (e.g. *Bank charges* for a SEPA fee, *Interest received* for a credit).

   ![Reconciling a needs-review transaction](/screenshots/user-guide/user/05-bank-reconciliation-03.png)

4. Tackle any **unmatched** lines. These are transactions Shillinq couldn't pair to anything, typically deposits from new customers or one-off ledger postings. Post each to the right ledger account. If the transaction is actually an invoice that's not yet in Shillinq, create the invoice first, then come back and match.

   ![Posting unmatched transactions](/screenshots/user-guide/user/05-bank-reconciliation-04.png)

5. When the *Difference* indicator reads `0,00`, click **Close**. Shillinq writes the period's reconciliation snapshot (statement balance, Shillinq balance, who closed it, when) to the audit trail. The matched invoices / bills move to *Paid*; the ledger reflects each posting.

   ![Reconciliation closed with zero difference](/screenshots/user-guide/user/05-bank-reconciliation-05.png)

## Verification

The reconciliation page shows *Difference: 0,00*, the matched invoices / bills moved to *Paid*, the bank's GL account in Shillinq agrees with the statement's closing balance, and the audit trail has an entry naming who closed the reconciliation and when.

## Common issues

| Symptom | Fix |
|---|---|
| CAMT.053 import fails | Some bank exports are CAMT.052 (intra-day) or PDF, Shillinq only accepts CAMT.053. Pull the daily/weekly XML from the bank's portal. |
| High-confidence match looks wrong | Override the suggestion in *Needs review*; the matching engine learns from your override so the same supplier/customer pairs better next time. |
| Difference is small (a few cents) | Usually a bank rounding line, or a SEPA fee not yet booked, post the difference to *Bank charges* (or *Wisselkoersresultaat* for FX). |
| Multiple invoices in one bank line | Use the split function, assign the line across the matching invoices in the right amounts; the residual (if any) goes to a ledger account. |
| Customer paid with a different reference | The matching engine relies on the SEPA EndToEndId / RemittanceInformation; if the customer used their own ref, override the match in *Needs review*. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Send your first invoice](02-send-invoice.md), the invoice side that gets matched.
- [Record a supplier bill](03-record-bill.md), the bill side that gets matched.
- [Prepare a VAT return](07-vat-return.md), accurate cash matching is what the VAT-return reconciliation reports lean on.
- [Shillinq architecture overview](../../Technical/architecture.md), CAMT.053, ISO 20022, SEPA.

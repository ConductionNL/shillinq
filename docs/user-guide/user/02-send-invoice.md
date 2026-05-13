---
sidebar_position: 2
title: Send your first invoice
description: Create a sales invoice, add line items with VAT, render it as a UBL/Peppol e-invoice, and send it to the customer.
---

# Send your first invoice

Create a customer-facing sales invoice in Shillinq, add the line items, let it compute the VAT, render it as a UBL/Peppol e-invoice (NLCIUS-compliant), and send it.

## Goal

By the end you will have one *sent* invoice in Shillinq: customer set, lines totalled, VAT computed, an invoice number assigned from the running sequence, a UBL/Peppol document generated, and a journal entry posted to the general ledger.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to create invoices, bookkeeping role on the Shillinq instance.
- A **customer** record (counterparty) and the **chart of accounts** set up so revenue accounts exist (see [Set up your chart of accounts](../admin/01-chart-of-accounts.md)).
- The default VAT rates known to Shillinq (BTW 21%, 9%, 0%, vrijgesteld), preloaded with the chart of accounts.

## Steps

1. Open **Sales → Invoices** from the Shillinq navigation and click **New invoice**. The invoice form opens.

   ![New invoice form](/screenshots/user-guide/user/02-send-invoice-01.png)

2. Pick the **customer** (counterparty). Shillinq pulls the customer's address, KvK number, BTW number, and default payment terms onto the invoice. Set the **invoice date** and **due date** (the customer's payment-term default fills in automatically). Click **Add line** to add the first line.

   ![Invoice header filled in](/screenshots/user-guide/user/02-send-invoice-02.png)

3. For each line, set the **description**, **quantity**, **unit price**, **VAT rate**, and pick the **revenue account** the line should post against. Shillinq computes the line total, the VAT amount per rate, and the invoice grand total live as you type. The invoice number is assigned from the running sequence when you save.

   ![Invoice lines with VAT computed](/screenshots/user-guide/user/02-send-invoice-03.png)

4. Click **Render UBL** to preview the UBL/Peppol XML (NLCIUS Semantic Model e-Factuur). This is the machine-readable e-invoice the customer's accounting system reads. Click **Send**, the invoice goes out by email, by Peppol to the customer's Peppol endpoint, or both.

   ![UBL/Peppol preview and send dialog](/screenshots/user-guide/user/02-send-invoice-04.png)

5. The invoice now shows as *Sent* in the **Invoices** list. The general ledger picks up the journal entry, **Debtors** debited, the picked **revenue account(s)** credited, **VAT payable** credited per rate, and the customer's AR balance goes up by the invoice total.

   ![Invoices list with the new sent invoice](/screenshots/user-guide/user/02-send-invoice-05.png)

## Verification

The invoice shows in **Sales → Invoices** with status *Sent*, an invoice number from the sequence, and the correct total. The customer's outstanding AR balance includes the new invoice. The general ledger journal shows the offsetting debits and credits with **VAT payable** picking up the right amount per rate. The UBL XML is downloadable from the invoice's detail view.

## Common issues

| Symptom | Fix |
|---|---|
| **New invoice** button missing | The invoicing schema isn't imported, or you don't have the bookkeeping role, see [Manage Shillinq settings](../admin/03-admin-settings.md). |
| VAT amount is 0 even though a rate is set | The revenue account picked on the line is set to *Vrijgesteld*, switch to a taxable account, or change the line's VAT rate. |
| Peppol send returns "no endpoint" | The customer record has no Peppol participant ID, add one on the customer (KvK + endpoint scheme) and resend. |
| Invoice number jumps backwards or duplicates | Two invoices were posted in parallel against the same sequence; Shillinq's sequence service serialises numbers, if you see a gap or a duplicate, file an issue with the invoice IDs. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Record a supplier bill](03-record-bill.md), the parallel flow for accounts payable.
- [Prepare a VAT return](07-vat-return.md), the VAT-payable side of a sent invoice flows into here.
- [Set up your chart of accounts](../admin/01-chart-of-accounts.md), the revenue accounts the invoice line picks from.
- [Shillinq architecture overview](../../Technical/architecture.md), NLCIUS, UBL, Peppol, RGS.

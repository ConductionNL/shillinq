---
sidebar_position: 3
title: Record a supplier bill
description: Capture a supplier invoice, attach the PDF, code the lines, route it for approval, and schedule the payment.
---

# Record a supplier bill

Record an incoming supplier invoice (bill) in Shillinq's accounts-payable. Attach the original PDF or UBL document, code each line against the right expense account and VAT rate, route it through the configured approval chain, and schedule the payment.

## Goal

By the end the bill will be in Shillinq with status *Approved*, posted to the general ledger, and queued for payment on its due date, with the original document attached for the fiscal seven-year retention (Fiscale bewaarplicht, AWR art. 52).

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to enter bills, bookkeeping role on the Shillinq instance.
- The **supplier** record exists, the **chart of accounts** has the right expense accounts, and the **approval chain** for the bill's amount band is configured (see [Configure supplier approval chains](../admin/02-approval-chains.md)).
- The supplier's invoice, either as a PDF, or as a UBL/Peppol e-invoice received via the Peppol inbox.

## Steps

1. Open **Purchases → Bills** from the Shillinq navigation and click **New bill**. The bill form opens.

   ![New bill form](/screenshots/user-guide/user/03-record-bill-01.png)

2. Drop the supplier's PDF onto the form (or pick the UBL document from the Peppol inbox). Shillinq's document-capture reads the supplier KvK / BTW number, the invoice number, the total, the due date, what it could detect from the document is pre-filled; you confirm or correct.

   ![PDF dropped, header pre-filled](/screenshots/user-guide/user/03-record-bill-02.png)

3. Code the lines. For each line, set the **description**, **amount**, **VAT rate**, and pick the **expense account**. Shillinq tracks the VAT recoverable per rate, splits it into the right VAT-return box, and posts the line to the ledger.

   ![Bill lines coded](/screenshots/user-guide/user/03-record-bill-03.png)

4. Save the bill. It enters the approval chain that matches its total band (see [Configure supplier approval chains](../admin/02-approval-chains.md)). The approver(s) review the bill, approve, or send it back with comments. Each approval step is logged on the bill's audit trail.

   ![Bill awaiting approval](/screenshots/user-guide/user/03-record-bill-04.png)

5. Once approved, the bill is posted to the ledger, **Expense account(s)** debited, **VAT recoverable** debited per rate, **Trade payables** credited. Schedule the payment via SEPA (ISO 20022 pain.001) on the due date; the payment run picks the bill up automatically.

   ![Approved bill posted and queued for payment](/screenshots/user-guide/user/03-record-bill-05.png)

## Verification

The bill shows in **Purchases → Bills** with status *Approved*, the original document attached, the audit trail naming each approver, the general ledger reflecting the posting, and the payment scheduled for the due date. The supplier's AP balance includes the bill total.

## Common issues

| Symptom | Fix |
|---|---|
| Document capture didn't pre-fill anything | The PDF is a scan without an OCR layer, or it's a non-NLCIUS UBL, fill the fields manually. The original document still attaches for retention. |
| Bill stuck "awaiting approval" forever | The approval chain references an approver who no longer exists, or whose email bounces, see [Configure supplier approval chains](../admin/02-approval-chains.md). |
| VAT recoverable amount is split wrong | The line's VAT rate doesn't match the supplier's invoice, re-pick the rate; Shillinq re-allocates. |
| Same bill posted twice | Shillinq's duplicate-detection compares supplier KvK + invoice number + date; if a duplicate slipped through, void one of the two and reconcile. |
| Payment didn't get picked up by the SEPA run | The bill isn't on *Approved* status, or the supplier has no IBAN, fix and re-queue. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Send your first invoice](02-send-invoice.md), the AR mirror of this flow.
- [Reconcile a bank statement](05-bank-reconciliation.md), where the SEPA payment gets matched off the bill.
- [Configure supplier approval chains](../admin/02-approval-chains.md), who approves bills of which amount.
- [Set up your chart of accounts](../admin/01-chart-of-accounts.md), the expense accounts the bill picks from.
- [Shillinq architecture overview](../../Technical/architecture.md), SEPA, Wet OB, Fiscale bewaarplicht.

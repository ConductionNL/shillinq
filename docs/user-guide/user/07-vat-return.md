---
sidebar_position: 7
title: Prepare a VAT return
description: Roll up the quarter's VAT into a Belastingdienst-format aangifte omzetbelasting, review the boxes, file it via SBR / Digipoort, and post the settlement.
---

# Prepare a VAT return

Roll up the quarter's (or month's) VAT into a Dutch *aangifte omzetbelasting* in the standard six-box layout. Shillinq pulls the VAT-payable and VAT-recoverable per rate from the ledger, slots them into the right boxes, lets you review and adjust, then files via SBR / Digipoort and posts the settlement journal.

## Goal

By the end the period's VAT return will be filed with the Belastingdienst, the settlement journal will be posted to the ledger, and the period will be closed for VAT, no further changes to invoices / bills in that period without a correction filing.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to file tax returns, tax role on the Shillinq instance.
- The period's invoices and bills are all entered and the bank is reconciled (see [Reconcile a bank statement](05-bank-reconciliation.md)), VAT on cash-basis returns particularly depends on the bank match.
- A registered Digipoort certificate or eHerkenning credential for the SBR filing channel.
- The right **VAT scheme** set on the organisation (kwartaalaangifte / maandaangifte, factuurstelsel / kasstelsel).

## Steps

1. Open **VAT → Returns** and click **New return**. Pick the period (the current open quarter or month). Shillinq runs the calculation across all posted invoices, bills, and bank entries in the period.

   ![New VAT return, period picker](/screenshots/user-guide/user/07-vat-return-01.png)

2. Review the six boxes:
   - **1a/1b/1c/1d/1e**, by-rate revenue + VAT (21%, 9%, 0%, special, exempt)
   - **2a**, revenue reverse-charged to you
   - **3a/3b/3c**, supplies to EU customers (intra-community, exports, distance sales)
   - **4a/4b**, supplies from outside NL where VAT shifts to you
   - **5a/5b**, VAT payable - VAT recoverable, and the payable subtotal
   - **5c/5d/5e**, KOR / VAT installments / amount due

   Shillinq fills every box from the ledger. Click into a box to see the underlying invoices/bills.

   ![Six-box layout with values](/screenshots/user-guide/user/07-vat-return-02.png)

3. Adjust if needed. Common adjustments, bad-debt write-off (article 29), private use of business goods, BUA/auto-correctie, mixed business/private adjustments. Each adjustment posts as its own journal entry and re-rolls the boxes.

   ![Adjustment entered on a VAT return](/screenshots/user-guide/user/07-vat-return-03.png)

4. **Submit** via SBR / Digipoort. Shillinq packages the return as the Belastingdienst's XBRL taxonomy, signs it with your Digipoort certificate (or eHerkenning), and POSTs it through the SBR channel. You get back a *Kenmerk* (reference) and a delivery receipt.

   ![SBR submission successful, kenmerk returned](/screenshots/user-guide/user/07-vat-return-04.png)

5. **Post the settlement**. Shillinq posts the journal to clear the VAT-payable / VAT-recoverable accounts in the period and create a single *VAT payable to Belastingdienst* (or *VAT receivable from Belastingdienst*) balance, then schedules the payment via SEPA on the return's due date. The period closes for further VAT changes.

   ![Settlement journal posted, period closed](/screenshots/user-guide/user/07-vat-return-05.png)

## Verification

The return shows in **VAT → Returns** with the kenmerk from Digipoort, the delivery receipt attached, the settlement journal posted, and the period closed. The aangifte total agrees to the cent with the box-5d value. The SEPA payment is queued for the Belastingdienst on the due date.

## Common issues

| Symptom | Fix |
|---|---|
| A box value doesn't match what you expected | Drill into the box, Shillinq lists every invoice/bill that contributed. A wrong VAT rate on one of them is the usual culprit; correct the underlying record (it'll need to be on a period that's still open). |
| SBR submission fails with a certificate error | Digipoort certificate is expired or the chain is wrong, rotate the certificate and retry. |
| Filing succeeded but the kenmerk is missing | The asynchronous *afhaalbericht* hasn't arrived yet, give it five minutes and refresh; the kenmerk lands when Digipoort delivers it. |
| Period closed too early | A late invoice came in for the closed period, file a **suppletie** (correction) for the period rather than reopening it. |
| Difference between cash basis and accrual basis | Check the VAT scheme on the organisation (factuurstelsel vs kasstelsel), picking the wrong scheme produces the wrong basis. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Send your first invoice](02-send-invoice.md), the VAT-payable side that flows into box 1.
- [Record a supplier bill](03-record-bill.md), the VAT-recoverable side that flows into box 5b.
- [Reconcile a bank statement](05-bank-reconciliation.md), for kasstelsel returns, the bank match drives the period.
- [Shillinq architecture overview](../../Technical/architecture.md), SBR, Digipoort, Wet OB, XBRL.

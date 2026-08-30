---
sidebar_position: 4
title: Create a purchase order
description: Raise a PO to a supplier, route it for approval, send it, and use it to three-way-match the supplier's bill when it arrives.
---

# Create a purchase order

A purchase order (PO) is the commitment to a supplier, *"we order X at price Y, deliver by date Z"*. Shillinq tracks the PO from creation through approval, dispatch to the supplier, goods receipt, and three-way matching against the supplier's bill.

:::note
A purchase order is an **Order of type `purchase`**. POs now live in the unified
**Orders** workspace (filter by type), alongside sales orders, bookings and grants —
see [Work with Orders](13-orders-workspace.md). The workflow below is unchanged.
:::

## Goal

By the end you will have a PO in *Sent* state at a supplier, with the right approver having signed it off, ready to be three-way-matched against the goods-receipt note and the supplier's bill when both arrive.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to raise POs, procurement role on the Shillinq instance.
- The **supplier** record exists (or you have the right to create one), the **chart of accounts** has the right expense accounts (see [Set up your chart of accounts](../admin/01-chart-of-accounts.md)), and the **approval chain** for the PO's amount band is configured (see [Configure supplier approval chains](../admin/02-approval-chains.md)).

## Steps

1. Open **Purchases → Purchase orders** and click **New PO**. The PO form opens.

   ![New PO form](/screenshots/user-guide/user/04-create-purchase-order-01.png)

2. Pick the **supplier** (counterparty). Shillinq pulls the supplier's address, KvK, default payment terms, and delivery address. Set the **order date** and the **expected delivery date**.

   ![PO header filled in](/screenshots/user-guide/user/04-create-purchase-order-02.png)

3. Add lines. For each line set the **description**, **quantity**, **unit price**, **VAT rate**, and pick the **expense account** the line will be coded against (used for the budget check and later for the bill's posting). Shillinq computes line totals, VAT, and the grand total live as you type.

   ![PO lines with budget check](/screenshots/user-guide/user/04-create-purchase-order-03.png)

4. Save. The PO enters the approval chain matching its amount band. The approver(s) see the PO with the budget impact, the supplier's risk profile, and any active contract with this supplier. Each approval/rejection lands on the audit trail.

   ![PO awaiting approval](/screenshots/user-guide/user/04-create-purchase-order-04.png)

5. Once approved, send the PO to the supplier, by email (PDF + UBL Order), by Peppol BIS Order, or both. The PO moves to *Sent*. When the goods arrive, record a goods-receipt note against the PO; when the supplier's bill arrives, three-way-match it against the PO + goods-receipt to detect price or quantity discrepancies before posting.

   ![PO sent and ready for three-way match](/screenshots/user-guide/user/04-create-purchase-order-05.png)

## Verification

The PO shows in **Purchase orders** with status *Sent*, the audit trail naming the approver(s), and the supplier acknowledging receipt (when the Peppol/email channel returns an ack). The PO contributes its committed amount to the budget burn-down. Once the supplier's bill is matched against this PO, the bill shows the PO reference; mismatches are surfaced.

## Common issues

| Symptom | Fix |
|---|---|
| Budget warning blocks the save | The PO exceeds the remaining budget for the picked expense account, either pick a different account, reduce the PO, or have the budget owner top it up. |
| PO stays on *Approved* but doesn't dispatch | The supplier has no email and no Peppol participant ID, add one of those and click **Send** again. |
| Three-way match flags a discrepancy you accept | Tolerance bands can be set on the supplier (or globally), within tolerance, the bill posts; outside, it's held for review. |
| Same line on two POs | POs are independent records; if you're consolidating, raise a new PO and void the old ones. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Record a supplier bill](03-record-bill.md), three-way match closes the bill against the PO.
- [Manage a contract](06-manage-contract.md), a PO against a supplier-contract reads the contract's terms automatically.
- [Configure supplier approval chains](../admin/02-approval-chains.md), who approves a PO of which amount.
- [Shillinq architecture overview](../../Technical/architecture.md), Peppol BIS, Aanbestedingswet, DigiInkoop.

---
sidebar_position: 6
title: Manage a contract
description: Create a supplier or customer contract — track obligations, get a heads-up before auto-renewal, and tie POs and invoices back to it.
---

# Manage a contract

Track a supplier or customer contract from signing through renewal in Shillinq's contract module. Each contract carries its term, value cap, payment terms, obligation list (deliverables, SLAs, notice periods), and any clauses that gate Shillinq workflows downstream (e.g. *"all POs against this supplier must reference contract X"*).

## Goal

By the end the contract will be in Shillinq with its term, value cap, obligations, the signed PDF attached, and the renewal reminder armed so it fires the right number of days before the notice period ends.

## Prerequisites

- Shillinq open and the OpenRegister back end connected (see [Open Shillinq for the first time](01-first-launch.md)).
- The right to manage contracts — contract role on the Shillinq instance.
- The counterparty exists as a **customer** or **supplier** record.
- The signed contract document, ideally as a PDF.

## Steps

1. Open **Contracts** and click **New contract**. The contract form opens.

   ![New contract form](/screenshots/tutorials/user/06-manage-contract-01.png)

2. Fill in the contract — **title**, **counterparty** (customer or supplier), **contract type** (framework, SaaS, service, lease, …), **start date**, **end date**, **value cap** (total amount Shillinq budgets against the contract), **payment terms**, **notice period in days**, and an **auto-renew** toggle. Drop the signed PDF onto the document slot.

   ![Contract header fields](/screenshots/tutorials/user/06-manage-contract-02.png)

3. Add **obligations**. Each obligation is a discrete thing one party must do — *"Monthly status report on the first working day"*, *"99.5% uptime SLA"*, *"Right to terminate for convenience with 30 days' notice"*. Set the obligation's **owner** (who in your org tracks it), **due cadence**, and any **evidence** the obligation requires.

   ![Obligation list on a contract](/screenshots/tutorials/user/06-manage-contract-03.png)

4. Arm the **renewal reminder**. Shillinq computes the notice deadline (= contract end date − notice period) and schedules a reminder a configurable number of days before — typically twice the notice period — so the contract owner has time to decide *renew*, *renegotiate*, or *let it lapse*.

   ![Renewal reminder configured](/screenshots/tutorials/user/06-manage-contract-04.png)

5. Save. The contract appears in the **Contracts** list with its status (*Active*), the value cap and current burn-down (driven by POs / bills posted against the contract). New POs against this counterparty offer the contract on the **Contract** field; the burn-down updates live.

   ![Contracts list with the new contract](/screenshots/tutorials/user/06-manage-contract-05.png)

## Verification

The contract shows in **Contracts** with status *Active*, its value-cap burn-down visible, the obligations listed with their owners, the signed PDF attached, and the renewal reminder armed. A test PO raised against the supplier shows the contract on the *Contract* field, and posting the PO reduces the burn-down by the PO's amount.

## Common issues

| Symptom | Fix |
|---|---|
| Renewal reminder doesn't fire | The contract's **notice period** or **end date** is empty; the reminder needs both. Notification delivery also needs the user's notification settings configured. |
| Value-cap burn-down doesn't move | POs/bills aren't tagged with the contract — either back-tag them, or wait until users start picking the contract on new POs. |
| Auto-renew flipped the contract to a new term unexpectedly | Auto-renew runs at end-date minus notice-period; to prevent renewal, terminate before that. |
| Obligation owner gets no reminders | Same as renewal — the obligation needs a cadence and the owner needs notification settings on. |
| Cannot edit the contract after sign-off | Approved contracts are version-locked; create an **amendment** (a child contract version) rather than editing in place. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Create a purchase order](04-create-purchase-order.md) — POs against a supplier draw on the contract's value cap.
- [Read your financial statements](08-financial-statements.md) — contract commitments are disclosed in the notes to the accounts.
- [Configure supplier approval chains](../admin/02-approval-chains.md) — high-value contracts may need higher approval bands than ordinary POs.
- [Shillinq architecture overview](../../ARCHITECTURE.md) — Accord Project, contract lifecycle.

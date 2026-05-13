---
sidebar_position: 2
title: Configure supplier approval chains
description: Set the amount bands and the approver(s) per band, Shillinq routes each bill and PO through the right chain automatically.
---

# Configure supplier approval chains

An *approval chain* in Shillinq is the rule that says "a bill or PO of amount X needs approver A; over Y also needs B; over Z also needs C". Each bill and PO is routed through the chain that matches its total band when it's saved.

## Goal

By the end the instance will have one or more approval chains configured, at minimum a default chain that covers every band, and a test bill / PO will route through them correctly.

## Prerequisites

- Admin on the Nextcloud instance (or the Shillinq admin role).
- The **OpenRegister** app installed and enabled, the Shillinq register imported, and the chart of accounts in place (see [Set up your chart of accounts](01-chart-of-accounts.md)).
- A clear picture of the organisation's signing matrix, who can approve what amount, who escalates to whom.
- Nextcloud accounts (or group memberships) for every approver in the matrix.

## Steps

1. Open **Settings → Administration → Shillinq** and switch to the **Approval chains** section. Click **New chain**.

   ![Approval chain settings](/screenshots/user-guide/admin/02-approval-chains-01.png)

2. Name the chain, *Default*, *Procurement (under €5k)*, *Capex*, and pick which **document types** it applies to (Bills, POs, both). The chain is selected when a document of those types is saved; if multiple chains match, the most specific wins.

   ![Chain header fields](/screenshots/user-guide/admin/02-approval-chains-02.png)

3. Add **bands**. A band is an amount range with one or more required approvers, *"€0 – €1.000: budget owner"*, *"€1.000 – €10.000: budget owner + finance"*, *"€10.000+: budget owner + finance + director"*. Pick approvers by Nextcloud user or group; group membership resolves at routing time.

   ![Approval bands](/screenshots/user-guide/admin/02-approval-chains-03.png)

4. Set per-band options, **parallel** (any one approver in the list can approve, the others get notified) vs **serial** (the next approver only sees the document after the previous one approved); **escalation timeout** (the chain escalates to the next approver after N business days of silence); **delegate-allowed** (whether an approver can hand off to a deputy).

   ![Band options, parallel / serial / escalation](/screenshots/user-guide/admin/02-approval-chains-04.png)

5. Save. The chain is now live. Raise a test bill (see [Record a supplier bill](../user/03-record-bill.md)), the bill should pick this chain and route to the right band's approvers; the audit trail on the bill records each approval / rejection / delegation.

   ![Test bill routing through the chain](/screenshots/user-guide/admin/02-approval-chains-05.png)

## Verification

The chain shows in the **Approval chains** list with its name, document types, and band count. A test bill of any amount routes through the right band; the approvers in that band get a notification; on approval the bill moves to *Approved*. The audit trail records each step.

## Common issues

| Symptom | Fix |
|---|---|
| Bill stuck "awaiting approval" forever | The band's approver list is empty, or the named group has no members, fix in the chain config; pending bills re-route on save. |
| Two chains both match a bill | Most-specific wins, make the more specific chain's amount band narrower or its document type tighter. If they're truly equivalent, merge them. |
| Approver got no notification | Their Nextcloud notification settings are off, or their email bounces, check the user record. |
| Escalation fires too quickly / not at all | The **escalation timeout** is in *business days* (Mon–Fri); set it to a wallclock duration via the *Working-hours calendar* in the same section. |
| Delegated approval breaks the audit trail | It shouldn't, the audit trail records both the delegator and the eventual approver. If an entry is missing, file an issue with the bill ID. |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Record a supplier bill](../user/03-record-bill.md), bills are the most common chain consumer.
- [Create a purchase order](../user/04-create-purchase-order.md), POs route through the same chains, can use a different chain per amount band.
- [Manage Shillinq settings](03-admin-settings.md), the section sits next to the chart-of-accounts and supplier settings.
- [Shillinq architecture overview](../../Technical/architecture.md), Aanbestedingswet, DigiInkoop, signing matrices.

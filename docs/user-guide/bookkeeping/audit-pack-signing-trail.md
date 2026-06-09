---
sidebar_position: 95
title: Audit Pack — Signing Trail
description: Surface the signing audit trail for any bookkeeping or procurement document — who approved it, when, with which comment, and whether the chain is complete.
---

# Audit Pack — Signing Trail

> Capability `bookkeeping-rekenkamer-audit-pack`, requirement REQ-RAP-002.
> See ADR-022 (audit-trail-immutable consumed from OR) and ADR-010
> (user-facing documentation discipline).

The **Signing Audit Trail** answers the accountant's first question on
every controle: *"who approved this document, and when?"* It is the
top-level Bookkeeping navigation entry under
`Bookkeeping > Signing audit trail`.

## Goal

By the end of this guide you will be able to:

- Open the signing audit trail filtered to a given period or actor.
- Trace a multi-step approval chain (department manager → budget owner
  → accountant) and verify every step.
- Cross-reference a single entry against the underlying document via
  the per-object audit sidebar (REQ-RAP-007) on every detail page.

## Where it lives

Sidebar: **Bookkeeping → Signing audit trail** (order 96, immediately
below the generic "Audit Trail" entry). The page is rendered by OR's
audit-log component with a Shillinq-supplied filter — there is no
bespoke Vue view per ADR-022.

## What you see

The page lists every approval / signing lifecycle decision across the
in-scope bookkeeping and procurement registers. Columns:

| Column | Meaning |
|---|---|
| Approval timestamp | When the decision was recorded (ISO-8601). |
| Approval actor | Nextcloud user who recorded the decision. |
| Object type | OR schema slug (e.g. `PurchaseOrder`, `APInvoice`). |
| Object | UUID of the signed object (clickable through to the detail page). |
| Signature status | `approved`, `rejected`, `delegated`, or the
  underlying `update` action on `signedBy` / `approvedBy`. |
| Approval comment | The approver's free-form comment / rejection reason. |

Sort defaults to **newest first** so the most recent decisions surface
on top; click any column header to re-sort.

## How filtering works

Behind the scenes the page reads OR's audit-trail API with the
following query string:

```
/index.php/apps/openregister/api/audit-trails
  ?objectTypes=Account,GLTransaction,…,PurchaseOrder,Tender,Bid,
               AwardDecision,ApprovalRequest,ApprovalTask,
               SigningAuthority,Receipt,Payment
  &action=lifecycle,update
  &fields=signedBy,approvedBy,signedAt,approvedAt,
          signingStatus,approvalStatus
```

Only events that touch one of those object types AND match one of
those actions / fields appear; everything else (e.g. a budget edit on
an unrelated cost-centre) stays out of the trail.

## Worked example — three-step purchase-order approval

A `PurchaseOrder` (`po-2026-0042`) carries a three-step approval chain:

1. Department manager (Bob) approves at 2026-05-15 10:30:00.
2. Budget owner (Carol) approves at 2026-05-15 11:45:00.
3. Accountant (Dave) signs at 2026-05-16 08:15:00; the PO transitions
   from `pending_approval` to `approved`.

In the Signing audit trail you see three rows, sorted newest first
(Dave → Carol → Bob). Each row carries the actor's UID, the precise
timestamp, the action (`update` on the `approvalChain` for steps 1-2;
`lifecycle:pending_approval→approved` for step 3), and the approver's
free-form comment.

## Per-object signing trail (REQ-RAP-007)

Every bookkeeping / procurement detail page carries an **Audit Trail**
sidebar tab. Opening a `PurchaseOrder` detail page shows the same
events as above — pre-filtered to that one PO's UUID — without leaving
the document. The sidebar reuses the same OR audit-log component as
the top-level trail; the filter is `:id`-templated to the detail
page's UUID.

## Permission scope

OR's audit-trail endpoint is permission-scoped — you only see events
on objects you have read access to. There is no separate ACL on the
signing trail; the canonical Nextcloud session permission set governs
visibility.

## Verifying signatures

Every audit row carries an OR hash-chain entry (`previousHash` +
`eventHash`) you can validate via OR's verification API. The Signing
Trail surface does not render the hash inline (to keep the row legible)
— click any row to drill into the OR audit-log detail, which shows the
chain link and a green "verified" checkmark when the chain is intact.

## Related surfaces

- `Bookkeeping → Destruction Report` — REQ-RAP-003 (legal proof of
  Archiefwet-compliant disposal).
- `Bookkeeping → Change History` — REQ-RAP-004 (before/after diffs for
  every mutation, not just signing).
- `Bookkeeping → Compliance Export` — REQ-RAP-005 (PII-filtered CSV /
  JSON export for external auditors).
- `Bookkeeping → Activity Feed` — REQ-RAP-006 (the same lifecycle
  events surfaced through Nextcloud Activity for dashboard / email /
  push pipelines).

## Compliance citations

| Norm | Requirement satisfied |
|---|---|
| Burgerlijk Wetboek Boek 2 art. 2:10 | Decision authenticity + 7-year retention. |
| Archiefwet article 7 | Audit trail certification of archival actions. |
| Algemene wet bestuursrecht (Awb) | Public-sector decision transparency. |
| Controleprotocol (NVCOS) | Accountantscontrole evidence chain. |

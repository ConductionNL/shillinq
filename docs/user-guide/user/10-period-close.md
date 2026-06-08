---
sidebar_position: 10
title: Close a fiscal period
description: Run the guided month-end or quarter-end close — work the AI-flagged checklist, freeze the period against new postings, reopen with an audit-trailed reason, and lock for audit.
---

# Close a fiscal period

A period close (Dutch: *periodeafsluiting*) turns a running ledger into auditable
financial history. Once a period is closed, postings dated inside it are
rejected, so the figures behind your VAT return and financial statements can no
longer change silently. Shillinq runs this as a **guided close** with an
**AI-powered close assistant** that flags incomplete work before you freeze the
period.

## The close lifecycle

Every period moves through four states:

| State | What it means | Who can act |
|-------|---------------|-------------|
| **Open** | Postings dated in the period are accepted. | Anyone with posting rights |
| **Closing** | The close checklist is being worked through. | Period closer |
| **Closed** | Frozen — new postings are rejected. Still reversible. | Period closer (reopen) / Auditor (lock) |
| **Audit locked** | Irreversibly frozen after auditor sign-off. | Nobody — terminal |

The flow is `open → closing → closed → audit-locked`. `closed` is reversible by a
period closer; `audit-locked` is permanent.

## Step 1 — Open the period detail

Open **Bookkeeping → Period Close** and click the period you want to close. The
detail page shows the period dates and fiscal year, the lifecycle state, the
close checklist, the close-assistant flags, and the close/reopen audit trail.

## Step 2 — Review the close assistant

The AI close assistant scans the ledger and surfaces warnings for incomplete
pre-close tasks:

| Category | What it detects |
|----------|-----------------|
| **AP** | Open accounts-payable transactions (unposted invoices/dunning notices) in the period. |
| **AR** | Open accounts-receivable transactions not yet collected or provisioned. |
| **Bank** | Bank statements with movements that have no matching general-ledger posting. |
| **Expense claims** | Expense claims awaiting approval or reimbursement. |

These flags are **warnings, not blockers** — you review them and resolve the
underlying records (or accept the position) before closing. The mandatory
checklist items (AP and AR) must be marked resolved before the period can move
from *closing* to *closed*.

## Step 3 — Start and complete the close

1. With the period **open**, click **Start close** — the state becomes *closing*.
2. Work the checklist until every mandatory (AP, AR) item is resolved.
3. Click **Close period**. Shillinq records the close timestamp and your user id
   in the audit trail, and the state becomes *closed*.

From now on, any attempt to post a transaction dated inside the period is
rejected with *"Period \{periodId\} is closed; reopen required for corrections"*.

## Step 4 — Reopen for a correction (if needed)

If you must post a correction into a closed period:

1. Click **Reopen** on the period detail.
2. Enter a **close reason** (for example *"Posted correction for invoice
   #2026-0042"*). The reason is required.
3. Confirm. The state returns to *open*, and the original close timestamp + actor
   are preserved in the reopen history alongside your reason.

Reopening is an elevated action — only a **period closer** may do it, and every
reopen is audit-trailed.

## Step 5 — Lock for audit

When the period is finalised and ready for sign-off, an **auditor** clicks
**Lock for audit** on a *closed* period. This sets the audit-lock timestamp and
actor and moves the period to **audit locked**.

:::warning Audit lock is irreversible
Once a period is audit-locked it can never be reopened. Late corrections after
audit-lock require a compensating journal in the next open period — this matches
the behaviour of Exact, AFAS, and Twinfield.
:::

## Roles

| Role | Can do |
|------|--------|
| **Period closer** | Start close, close, reopen (with reason) |
| **Auditor** | Lock a closed period for audit |
| **Administrator** | Any transition (Nextcloud admin default) |

Buttons you are not allowed to use are hidden on the detail page.

## What is *not* covered here

Year-end close — opening-balance journal generation and retained-earnings
rollover — is a separate workflow planned for a later release. This page covers
the monthly and quarterly close cycle.

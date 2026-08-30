---
sidebar_position: 11
title: Post a journal entry
description: Author a manual journal entry (memoriaalboeking), a recurring template, or a reversing accrual — Shillinq materialises a balanced general-ledger transaction on post, with audit and approval-gate from OpenRegister.
---

# Post a journal entry

Shillinq's *journal entries* (*journaalposten*) are the human-authored
construct above the general ledger. Every invoice, every bill, every
bank line eventually lives in the GL — but the journal-entry page is
where you author the postings the rest of the system cannot derive on
its own. T1 of the bookkeeping foundation ships three sub-types:

- **Manual** (*memoriaalboeking*) — a one-off correction, write-off,
  reclassification, or opening-balance entry.
- **Recurring** — a template that materialises a posting on a cadence
  (monthly subscription, depreciation schedule, periodic accrual).
- **Reversing** — an accrual that automatically posts the opposite
  side at the start of the next designated period (year-end accrual,
  prepaid-expense recognition).

The journal entry is the *author's view*; on post, Shillinq
materialises exactly one balanced `GLTransaction` (per
[REQ-JE-007](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md))
and gates the transition through OpenRegister's approval-workflow
abstraction (per
[REQ-JE-008](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md))
— **never** through an app-local approvers table.

## Goal

By the end one balanced journal entry is *posted* in Shillinq: lines
are entered, the debit/credit totals balance, a sequential
`journalNumber` is assigned, the materialised `GLTransaction` is
linked back from the journal, the audit trail records the operator
and timestamp, and (if the administration policy requires it) the
approval-gate has cleared.

## Prerequisites

- Shillinq open and the OpenRegister back end connected
  (see [Open Shillinq for the first time](01-first-launch.md)).
- The **chart of accounts** set up so the line accounts exist
  (see [Set up your chart of accounts](../admin/01-chart-of-accounts.md)).
- The **bookkeeping role** on the Shillinq instance.
- For an above-threshold journal: an approver in the
  `bookkeeping-approver` role (configured through OR's
  approval-workflow extension — not through Shillinq).

## Steps

1. Open **Bookkeeping → Journaalposten** from the Shillinq navigation
   and click **New journal**. The journal form opens. Pick the
   `journalType` — *manual*, *recurring*, or *reversing*. Set the
   **entry date** and a human-readable **description**.

   ![New journal form](/screenshots/user-guide/user/11-post-journal-entry-01.png)

2. Add the lines. Each line has an **account number** (picked from the
   chart of accounts), a **side** (*debit* or *credit*), and a
   **non-negative amount** in the administration's base currency.
   Shillinq computes the running debit and credit totals live as you
   type; the **Post** button stays disabled until they match (per the
   balance invariant on the GL transaction lifecycle).

   ![Journal lines with running totals](/screenshots/user-guide/user/11-post-journal-entry-02.png)

3. (Optional) Attach a **source document**. Pick a docudesk
   attachment (Shillinq references it by URI; the file blob never
   lives in the bookkeeping register, per
   [REQ-JE-006](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md)).
   On a *manual* memoriaal, this is typically the supporting PDF; on a
   *recurring* depreciation template, it's the asset register
   evidence; on a *reversing* accrual, it's the year-end working
   paper.

   ![Source document attached](/screenshots/user-guide/user/11-post-journal-entry-03.png)

4. (Recurring only) Configure the **cadence**: `interval` (daily,
   weekly, monthly, yearly), `anchor` (the ISO date the schedule
   starts from), `endsOn` (an end date, or *null* for open-ended), and
   `count` (a number of occurrences, or *null* for unbounded). The
   OpenRegister `ScheduledWorkflow` primitive consumes this cadence and
   materialises a new `GLTransaction` per tick — Shillinq does **not**
   run its own background job.

   ![Recurring cadence configuration](/screenshots/user-guide/user/11-post-journal-entry-04.png)

5. (Reversing only) Pick the **reversesOn** period. The journal posts
   in the current period; at the start of the named period
   (typically the next month or the next fiscal year), Shillinq
   auto-materialises the inverse `GLTransaction` and back-links it
   via `reversesTransactionId`.

6. Click **Post**. If the administration policy says journals above a
   threshold (commonly €5 000) require approval, the journal
   transitions `draft → pending` with `approvalState: pending` — an
   approver in the configured role then approves or rejects. Below
   threshold (or when no policy applies), the journal transitions
   `draft → posted` directly with `approvalState: not-required` and
   the materialised `GLTransaction` appears in the general ledger.

   ![Posted journal with link to GL transaction](/screenshots/user-guide/user/11-post-journal-entry-05.png)

## Verification

The journal appears in the **Journaalposten** list with `state:
posted` and the assigned `journalNumber`. Opening the detail page
shows the `journalType`, the `approvalState`, the `sourceDocumentUri`
(when attached), and a link to the materialised `GLTransaction`. The
GL transaction's lines mirror the journal's lines exactly (1:1),
`postingDate` matches `entryDate`, and the sum of debit lines equals
the sum of credit lines. The audit trail (provided by OpenRegister
per ADR-022) records the operator, the entry/edit/approve/post
events, and the timestamps.

## Common issues

| Symptom | Fix |
|---|---|
| **Post** button stays disabled | The debit total does not match the credit total. The balance invariant lives on the `GLTransaction.post` lifecycle transition (per [REQ-GL-005](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md)); fix a line amount until the totals match. |
| Journal stays in `pending` | The administration policy requires approval; no approver in `bookkeeping-approver` has acted yet. Approvers see the entry on their **My approvals** queue (rendered by OR's approval-workflow extension). |
| Recurring template did not fire on the cadence tick | The OR `ScheduledWorkflow` engine is not running, or the cadence's `endsOn` / `count` is already reached. Re-check the cadence object and the OR scheduler status. |
| Reversing journal did not auto-invert | The `reversesOn` period has not yet started, or the OR lifecycle action is not registered for the period boundary. T1 supports both the `ScheduledWorkflow` path and the period-close lifecycle path (per [REQ-JE-004](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md)); confirm which the instance uses. |
| **Void** transition fails with *"storneer eerst de grootboektransactie"* | A posted journal cannot be voided until its materialised `GLTransaction` is reversed (per [REQ-JE-010](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md)). Reverse the GL transaction first, then void the journal. |
| Journal type `closing` is rejected | T1 ships three sub-types only (`manual`, `recurring`, `reversing`); period-close adjustments belong to T3 (per [REQ-JE-003](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md)). |
| Source-document URL points at a missing file | The docudesk attachment was deleted; replace the URI or upload a new attachment in docudesk and reference it. The bookkeeping register does not embed the file blob (per [REQ-JE-006](../../../openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md)). |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Set up your chart of accounts](../admin/01-chart-of-accounts.md), the account numbers the journal lines pick from.
- [Read your trial balance](09-trial-balance.md), where the materialised `GLTransaction` ultimately rolls up.
- [Read your financial statements](08-financial-statements.md), the period-end view of all posted journals.
- [Shillinq architecture overview](../../Technical/architecture.md), RGS, BBV, OR lifecycle, approval-workflow.
- Foundation spec: [`add-shillinq-bookkeeping-foundation`](../../../openspec/changes/add-shillinq-bookkeeping-foundation/proposal.md) — the three deltas (chart of accounts, general ledger, journal entries).

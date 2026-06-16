---
sidebar_position: 96
title: Audit Pack — Destruction Report
description: Track and certify the disposal of bookkeeping records per Archiefwet — mark records for destruction, approve in batches, and prove compliance to external auditors.
---

# Audit Pack — Destruction Report

> Capability `bookkeeping-rekenkamer-audit-pack`, requirements
> REQ-RAP-003 + REQ-RAP-008. See ADR-022 (audit-trail-immutable
> consumed from OR) and ADR-010 (user-facing documentation discipline).

The **Destruction Report** is the legal proof an external auditor
needs to confirm your organisation disposed of bookkeeping records in
line with the Dutch Archiefwet. It is exposed under
`Bookkeeping > Destruction report` (order 97).

## Goal

By the end of this guide you will be able to:

- Identify records eligible for destruction (≥ 7 years old per
  Archiefwet article 7).
- Mark a batch for destruction with the right legal basis
  (`selectielijstCode`) and approve as a compliance officer.
- Execute the destruction so the record transitions to
  `destruction-completed` (a TERMINAL state — the record is preserved
  as proof, NEVER truly deleted).
- Hand the auditor a verification trail with hash-chain certification.

## The state machine (REQ-RAP-008)

```
active
  └─→ marked-for-destruction   (compliance officer + record ≥ 7 years)
        ├─→ active             (rollback / unmark — allowed while the
        │                       destruction order is unexecuted)
        └─→ destruction-completed (execution — TERMINAL)
```

Key rules enforced by `lib/Lifecycle/DestructionScheduleGuard`:

- **Active → marked-for-destruction** requires both (a) `actorRoles`
  contains `compliance-officer` AND (b) the record's `createdAt` is
  older than `RETENTION_FLOOR_YEARS = 7` (Archiefwet article 7).
- **Marked → destruction-completed** requires a compliance officer or
  the `shillinq-destruction-runner` system actor (for scheduled jobs).
- **Marked → active** is the rollback path; allowed while the
  destruction order is unexecuted.
- **Destruction-completed** is TERMINAL — `canModify` and `canDelete`
  both return `false`. The record itself is preserved (Archiefwet
  requires proof of destruction; "destruction-completed" IS the proof,
  not a true delete).

## Where it lives

Sidebar: **Bookkeeping → Destruction report**. The page is rendered by
OR's audit-log component, pre-filtered to lifecycle transitions
landing in `marked-for-destruction` or `destruction-completed` across
every bookkeeping / procurement register plus the dedicated
`DestructionOrder` / `DestructionSchedule` objects.

## What you see

| Column | Meaning |
|---|---|
| Approval timestamp | When the destruction-schedule transition was recorded. |
| Compliance officer | Actor UID who approved the transition. |
| Record type | OR schema slug of the record (e.g. `APInvoice`). |
| Record | UUID, clickable through to the record's detail page. |
| Lifecycle transition | The `lifecycle:from→to` action. |
| Legal basis (Selectielijst/Archiefwet) | `selectielijstCode` (default `5.1.2` for Financial Records) + `legalBasis` citation. |

## Worked example — batch of 50 invoices from 2016

A compliance officer (Eve) inspects fifty `APInvoice` records dated
2016-Q4 — all older than 7 years. She:

1. Selects them and clicks **Mark for destruction**.
2. Confirms the legal basis: `selectielijstCode: 5.1.2`
   (`legalBasis: Archiefwet Article 7`).
3. The system creates a `DestructionOrder` linking the 50 records and
   records 50 audit events `lifecycle:active→marked-for-destruction`
   with `actor = Eve`.
4. The destruction is scheduled for the next maintenance window.
5. The `shillinq-destruction-runner` job executes the order: each
   invoice transitions to `destruction-completed` and the audit-trail
   records 50 `lifecycle:marked-for-destruction→destruction-completed`
   events with hash-chain certification.
6. Eve exports the Destruction Report as a PDF (via the OR audit-log
   UI's export menu) and hands it to the external auditor as proof.

## Verifying the destruction

External auditors verify destructions in three steps:

1. **Read the Destruction Report** — every entry shows the OR
   audit-trail row certifying the transition.
2. **Walk the hash chain** — OR's verification API returns
   `valid: true` for every entry whose `eventHash` chains correctly
   back to its `previousHash`.
3. **Confirm the legal basis** — the `selectielijstCode` and
   `legalBasis` fields cite the Archiefwet article that authorised the
   destruction; the auditor checks this against the organisation's
   selectielijst.

## Rolling back a marked-for-destruction batch

Until the destruction is executed, the compliance officer can revert
the batch:

1. Open the `DestructionOrder` detail page.
2. Click **Unmark for destruction**.
3. Each record transitions back to `active`; the audit trail records
   the reversal as `lifecycle:marked-for-destruction→active` with the
   compliance officer as actor.

Once the destruction is `destruction-completed`, this option is no
longer available — the state is terminal.

## What is NOT supported

- **Automatic destruction at 7-year boundary.** Per design.md D3, the
  proposal explicitly rejected auto-delete; every destruction goes
  through compliance officer approval to prevent accidental data loss.
- **Hard deletion.** Destruction is a state transition; the record
  itself is never `DELETE FROM …`'d. Archiefwet requires the proof to
  be queryable years after the fact.
- **External SIEM / log shipping.** Out of scope per proposal Scope —
  owned by Nextcloud / cluster ops.

## Related surfaces

- `Bookkeeping → Signing audit trail` — REQ-RAP-002 (approval-side
  trail; the destruction-schedule approval itself shows up here too).
- `Bookkeeping → Change History` — REQ-RAP-004 (every mutation in
  scope, not just destruction).
- `Bookkeeping → Compliance Export` — REQ-RAP-005 (CSV / JSON export
  for external auditors with PII excluded).

## Compliance citations

| Norm | Requirement satisfied |
|---|---|
| Archiefwet article 7 | 7-year retention floor before destruction. |
| Archiefwet Besluit | Selectielijst-driven categorisation of records. |
| BBV (Besluit begroting en verantwoording) | Financial record retention for municipalities / provinces / water boards. |
| Burgerlijk Wetboek Boek 2 art. 2:10 | 7-year retention for company-law records. |
| AVG / GDPR article 17 | Right-to-erasure verification trail for vendors / subjects. |

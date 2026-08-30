---
sidebar_position: 1
title: GDPR / AVG — Subject Access Export
description: Honour a GDPR article-15 subject-access request with a PII-filtered audit trail export — what the requester sees, what is excluded, and how the request itself is logged.
---

# GDPR / AVG — Subject Access Export

> Capability `bookkeeping-rekenkamer-audit-pack`, requirements
> REQ-RAP-005 + REQ-RAP-009. See ADR-022 (audit-trail-immutable
> consumed from OR) and ADR-010 (user-facing documentation discipline).

GDPR article 15 (right of access) entitles every data subject —
employee, contractor, vendor — to a copy of the personal data your
organisation holds about them. Shillinq satisfies the request with a
**PII-filtered** export of the OR audit-trail, scoped to events where
the subject is the actor (or, on explicit legal request, the
referenced subject).

The export is exposed under **Bookkeeping → Compliance export**
(order 99) and via `GET /index.php/apps/shillinq/api/audit/export`.

## Goal

By the end of this guide you will be able to:

- Generate a GDPR-compliant subject-access export with PII excluded.
- Explain to the requester what fields ARE in the export and which
  are excluded (and why).
- Show the auditor the export-request itself recorded in the OR
  audit-trail (REQ-RAP-005 scenario 3).
- Combine the export with the Destruction Report for a GDPR article 17
  (right-to-erasure) verification trail.

## Who can run a subject-access export

The endpoint is `#[NoAdminRequired]` — visible to logged-in users —
but RBAC-locked to:

- Members of the `auditor` group, OR
- Nextcloud admins.

Non-eligible callers receive `403 Forbidden`. Anonymous callers
receive `401 Unauthorized`.

## API contract

```
GET /index.php/apps/shillinq/api/audit/export
    ?from=YYYY-MM-DD              # inclusive start date
    &to=YYYY-MM-DD                # inclusive end date
    &format=csv|json              # default csv
    &scope=all|subject            # default all
    &actor=<uid>                  # optional; default current session
                                  # when scope=subject
```

Responses:

| Status | When |
|---|---|
| `200 OK` (CSV) | `format=csv` — `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="shillinq-audit-export-{from}_{to}.csv"`. |
| `200 OK` (JSON envelope) | `format=json` — the full envelope including PII-filtered rows + headers + generation metadata. |
| `400` | Missing / malformed `from` / `to`, invalid `scope` / `format`. |
| `401` | Anonymous caller. |
| `403` | Caller not in the `auditor` group and not admin. |
| `500` | Unrecoverable error; no stack trace leaks to the client. |

## What IS in the export

| Column | Meaning |
|---|---|
| `timestamp` | When the event was recorded (ISO-8601). |
| `objectType` | OR schema slug (e.g. `APInvoice`). |
| `objectId` | UUID of the affected record. |
| `action` | `create`, `update`, `delete`, `lifecycle:{from}→{to}`. |
| `actor` | Actor UID — **NOT** display name. |
| `fields_changed` | JSON array of non-PII field names whose value changed. |
| `beforeValue` | PII-stripped before snapshot. |
| `afterValue` | PII-stripped after snapshot. |

## What is EXCLUDED (PII fields)

Per REQ-RAP-005 + REQ-RAP-009, the following fields are stripped
recursively from `beforeValue`, `afterValue`, and the `fields_changed`
diff list — there is no escape hatch via query parameter or scope:

- `email`
- `phone`
- `address`
- `displayName`
- `firstName`
- `lastName`
- `birthDate`
- `socialSecurityNumber`
- `taxId`
- `personId`
- `ipAddress`

If an external auditor needs PII-complete audit (a separate legal
basis), it MUST be requested through a dedicated compliance export
channel with additional legal review — that channel is NOT exposed
through this endpoint.

## Worked example 1 — employee asks "what did I change in the last 90 days?"

Frank (UID `frank.de.boer`) requests his GDPR article 15 export.
The auditor (Eve, in the `auditor` group) calls:

```
GET /index.php/apps/shillinq/api/audit/export
    ?from=2026-03-10
    &to=2026-06-10
    &scope=subject
    &actor=frank.de.boer
    &format=csv
```

The response is a CSV with every audit event where Frank is the
actor — typically GL entry edits, period-close sign-offs, expense
claim approvals — with the `actor` column listing only his UID, no
display name. Frank's email / address / SSN are absent from every
row even when the affected object stores them (the OR audit-trail
captures the snapshot but the export pipeline strips PII at render
time).

The export operation itself is recorded in the OR audit-trail with
`action: export_request`, `actor: eve`, `scope: subject`,
`actorFilter: frank.de.boer`, so an auditor can later prove who
exported what and when.

## Worked example 2 — vendor requests GDPR article 17 destruction proof

A deleted vendor's lawyer requests proof their records were destroyed
under GDPR article 17 (right to erasure). The compliance officer:

1. Combines the Destruction Report (REQ-RAP-003) — pre-filtered to
   `lifecycle:*→destruction-completed` for the vendor's UUID — with
2. A subject-access export (REQ-RAP-009) scoped to events where the
   vendor is the actor or the subject.

The combined PDF/CSV bundle shows the destruction transition with
`actor`, `selectielijstCode`, `legalBasis`, the hash-chain `eventHash`
certifying the destruction, and Frank's activity log within the
retention window — with all PII excluded per the table above.

## Accountability — the export request itself is auditable

REQ-RAP-005 scenario 3 mandates that every export request is recorded
on the OR audit-trail-immutable channel. The controller writes an
`action: export_request` event with:

- `actor` (caller UID)
- `timestamp` (when the export was generated)
- `scope`, `format`, `from`, `to`, `actorFilter`
- `eventCount` (how many rows were rendered)
- `requirementId: REQ-RAP-005`

If OR's audit-trail service does not expose `recordEvent` / `log`, the
controller falls back to the app logger so the request is never
silently dropped. The export is best-effort logged but always
generated — the caller is never blocked.

## External auditor workflow

For accountantscontrole / Rekenkamer audits, the external auditor
typically requests a date-range export with `scope=all`:

```
GET /index.php/apps/shillinq/api/audit/export
    ?from=2026-01-01
    &to=2026-12-31
    &scope=all
    &format=csv
```

The result is the complete PII-filtered audit trail for the fiscal
year. Combined with the Destruction Report (REQ-RAP-003) and the
Signing audit trail (REQ-RAP-002), this is the evidence chain the
auditor needs to confirm:

1. Every booking has a recorded actor + timestamp.
2. Every approval / sign-off is on the chain.
3. Every record disposed of is certified per Archiefwet.

## Compliance citations

| Norm | Requirement satisfied |
|---|---|
| AVG / GDPR article 15 | Right of access — subject can request their data. |
| AVG / GDPR article 17 | Right to erasure — verification trail for destructions. |
| AVG / GDPR article 5(1)(a) | Transparency — every export is itself logged. |
| AVG / GDPR article 5(1)(c) | Data minimisation — PII fields excluded by default. |
| Burgerlijk Wetboek Boek 2 art. 2:10 | 7-year retention before destruction is permissible. |

# GDPR / AVG Subject Access Request

This guide explains how to fulfill a GDPR Article 15 subject access request (SAR)
using Shillinq's compliance export surface.

## Overview

When a data subject (employee, contractor, vendor) requests their personal data
(GDPR Article 15) or proof of destruction (GDPR Article 17), the compliance officer
can export audit events filtered to that subject, with PII fields excluded.

## Accessing the Compliance Export

Navigate to **Bookkeeping > Compliance Export** in the sidebar.

**Required role:** `auditor` group or Nextcloud superadmin.

## Export Parameters

The compliance export surface links to OpenRegister's audit-trail export API with
the following parameters:

| Parameter | Values | Description |
|-----------|--------|-------------|
| `from` | `YYYY-MM-DD` | Start date of the audit period |
| `to` | `YYYY-MM-DD` | End date of the audit period |
| `format` | `csv`, `xlsx`, `json` | Export file format |
| `scope` | `all`, `subject` | `subject` filters to a specific actor/subject ID |
| `actor` | UUID | When `scope=subject`, filter to events where this user is the actor |

## Fields Included (PII-Safe)

| Field | Description |
|-------|-------------|
| `timestamp` | ISO-8601 UTC timestamp of the event |
| `objectType` | Register schema name (e.g. `Account`, `Iv3Export`) |
| `objectId` | UUID of the affected record |
| `action` | `create`, `update`, `lifecycle:X→Y`, `export_request` |
| `actor` | User UUID only (NOT display name) |
| `fields_changed` | JSON array of field names changed |
| `beforeValue` | Non-PII field values before the change |
| `afterValue` | Non-PII field values after the change |

## Fields Excluded (PII)

The following fields are **always excluded** from compliance exports to prevent
PII leakage:

- `email`, `phone`, `address`
- `displayName`, `firstName`, `lastName`
- `birthDate`, `socialSecurityNumber`, `taxId`
- `personId`, `ipAddress`

If a full PII-complete audit is required (e.g. for legal proceedings), request it
via a separate compliance export channel with additional legal review and DPO sign-off.

## Fulfilling a Subject Access Request (SAR)

### Article 15 — "Show me what you have about me"

1. Navigate to **Bookkeeping > Compliance Export**.
2. Set date range covering the subject's entire relationship with the organisation.
3. Set `scope = subject` and enter the subject's user UUID.
4. Select format `xlsx` or `csv`.
5. Download and deliver the export to the subject.
6. The export request itself is automatically logged in the audit trail:
   `action: export_request`, actor = compliance officer, timestamp, scope details.

### Article 17 — "Prove my data was destroyed"

1. Navigate to **Bookkeeping > Destruction Report**.
2. Filter by `actor = {subject UUID}` or `objectId = {record UUID}`.
3. Look for events with `action = lifecycle:marked-for-destruction→destruction-completed`.
4. Export the filtered events as the destruction proof document.
5. The hash-chain certification entry proves the event was not tampered with.

## Accountability Trail

Every compliance export request is **itself logged** in the audit trail:

```
action: export_request
actor: <compliance officer UUID>
timestamp: <ISO-8601>
scope: all | subject
from: <date>
to: <date>
format: csv | xlsx | json
```

This allows a Rekenkamer auditor to verify "who exported what, when" — satisfying
GDPR Article 5(1)(a) accountability requirements.

## Legal References

- **GDPR Article 15** — Right of access by the data subject
- **GDPR Article 17** — Right to erasure / right to be forgotten
- **GDPR Article 5(1)(a)** — Accountability principle
- **AVG (Dutch implementation of GDPR)** — Same rights, Dutch jurisdiction
- **Archiefwet Article 7** — 7-year retention for financial records

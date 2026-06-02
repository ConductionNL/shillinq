# Destruction Report

The Destruction Report shows financial records scheduled or completed for destruction
per the Archiefwet (Dutch Archives Act) 7-year retention policy.

## Access

Navigate to **Bookkeeping > Destruction Report** in the sidebar. The view opens
OpenRegister's audit-log UI pre-filtered to `marked-for-destruction` and
`destruction-completed` lifecycle transitions.

## Destruction Lifecycle

Records follow a three-stage state machine:

```
status: active → status: marked-for-destruction → status: destruction-completed
```

| State | Meaning |
|-------|---------|
| `active` | Normal operational record |
| `marked-for-destruction` | Eligible for destruction; pending compliance officer approval |
| `destruction-completed` | Permanently closed; terminal state |

Destruction is **not a deletion** — it is a state transition that is itself recorded
in the immutable audit trail. The record's content is preserved for legal verification.

## Marking Records for Destruction

Records older than 7 years (Archiefwet Article 7, Selectielijst 5.1.1) may be
marked for destruction by a user with the `compliance-officer` role:

1. Open the record's detail page.
2. In the lifecycle actions panel, click **Mark for destruction**.
3. Enter the Selectielijst code (e.g. `5.1.1`) and the legal basis citation.
4. Confirm. The record transitions to `marked-for-destruction` and an audit event
   is emitted with your actor ID, timestamp, and the legal basis fields.
5. The **auditor** and **compliance-officer** groups receive an Activity notification.

## Completing a Destruction Batch

After marking, a compliance officer must also execute the destruction:

1. Open **Bookkeeping > Destruction Report**.
2. Filter by `status = marked-for-destruction`.
3. Select the batch and click **Complete destruction**.
4. Each record transitions to `destruction-completed`.
5. OR emits a hash-chain certified audit event:
   `lifecycle:marked-for-destruction→destruction-completed` with `selectielijstCode`,
   `legalBasis`, actor, and timestamp.

## Legal Proof of Compliance

The destruction report itself (exported from this view) serves as Archiefwet
compliance proof for external auditors. The hash chain entry (visible in each
record's audit trail) can be verified via OR's verification API.

## Compliance Note

This workflow satisfies **Archiefwet Article 7** (7-year retention) and
**Selectielijst Gemeenten 2020** category 5.1.1 for financial administration records.
No records are physically deleted from the database — the state transition model
provides durable proof while preserving historical data for Rekenkamer audits.

## Related

- [Signing Audit Trail](audit-pack-signing-trail.md)
- [GDPR Subject Access](../compliance/gdpr-subject-access.md)

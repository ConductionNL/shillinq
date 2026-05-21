# Design — Document Retention (Archiefwet Compliance)

## Context

Dutch government organizations must comply with Archiefwet 1995 for all records,
including financial documents. The law requires documented retention schedules,
periodic review, and audit trails for document disposal. Per ADR-022, retention
workflow comes from OR's archival-destruction abstraction, not from an app-local
table. Per ADR-031, compliance reporting is a declarative aggregation.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire retention-policy surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Consume OR's archival-destruction abstraction — per ADR-022. Zero parallel
  retention table.
- Make the spec a **Dutch compliance auditor–readable contract** — Archiefwet
  requirements recognisable end-to-end (policy definition, schedule assignment,
  compliance review, disposal audit).
- Support **exception handling** — legal holds, regulatory exemptions, court
  orders — without bloating the core lifecycle.
- Enable **organization-wide configuration** — retention periods per document
  type, review frequency per administration policy.

## Non-Goals

- No PHP retention-service class.
- No automatic document deletion — T2 schedules only; T3 adds the scheduled-job
  execution.
- No Data anonymization during archival — T5 (GDPR path).
- No Peppol integration for retention status — T4.

## Decisions

### D1 — Retention is metadata-driven, lifecycle is consumed from OR

`DocumentRetention` is a register tracking metadata (policy reference, schedule
reference, review status) and consuming OR's archival-destruction workflow for
the lifecycle itself (`active → under-review → retained → scheduled-for-deletion → deleted`).
No app-local `RetentionService`.

### D2 — RetentionPolicy is organization-wide + document-type-scoped

`RetentionPolicy` declares the retention period (in years), legal-hold
allowances, and exemption categories per document type. Each administration can
override the default policy. Pure configuration metadata.

### D3 — RetentionSchedule maps documents to their retention period

`RetentionSchedule` captures the start-of-retention marker (document creation
date, invoice date, etc.), the computed retention-end date, and the review-due
date (Archiefwet requires review before disposal). Declarative aggregation over
`DocumentRetention`.

### D4 — Compliance aggregations: overdue-review, legal-holds, disposal summary

Three aggregations support compliance reporting:
- Overdue reviews: count of `DocumentRetention` records where `review_due < today`
  and status is not `deleted`.
- Legal holds: count of `DocumentRetention.legalHold == true`.
- Disposal summary: count of documents disposed per month, grouped by disposal
  method, for audit trail.

### D5 — Exception handling is modeled as document-level flags + reason

Legal holds, regulatory exceptions, and court orders are captured on
`DocumentRetention.exceptions[]` (a structured array with type + reason +
authority). The lifecycle respects the flag; no complex hold-management service.

### D6 — Deadline alerts are computed from retention schedules

Review-due dates are pre-computed in `RetentionSchedule` (creation date + policy
period = retention end; review due = retention end - 30 days per Archiefwet
standard). Alerts are auto-generated via OR's notification abstraction when due.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Document retention lifecycle | OR `x-openregister-lifecycle` + `archival-destruction` (ADR-022) | Lifecycle on `DocumentRetention` (`active → under-review → retained → scheduled-for-deletion → deleted`); consumed from OR |
| Legal-hold / exception management | OR `x-openregister-lifecycle` guard conditions | Exceptions modeled as `DocumentRetention.exceptions[]` (type + reason + authority); lifecycle respects the array |
| Compliance aggregations | OR `x-openregister-aggregations` | Overdue-review count, legal-hold count, disposal summary — pure aggregations |
| Notification on review-due | OR NotificationService (ADR-022) | Review-due dates computed in schedule; OR's notification system fires alerts |
| Audit trail for disposal | OR AuditTrailService (immutable, ADR-022) | Lifecycle transitions automatically captured; disposal reasons in audit |
| Manifest navigation | T1 manifest pattern | 2 entries (Retention Policies, Retention Dashboard) + their pages |
| Document scope | docudesk integration + OR relations | Documents tagged with `RetentionPolicy` via `DocumentRetention.policyId` FK |

**Net new code in implementation cycle**: 3 schema declarations + 1 lifecycle block
+ 3 aggregations + 2 manifest entry pairs. At most 1 single-method PHP guard
(`RetentionGuard`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Document retention lifecycle | Declarative (`x-openregister-lifecycle`) + consumed from OR `archival-destruction` | Pure state machine |
| Retention-period enforcement | Declarative (`RetentionSchedule` computed from `RetentionPolicy`) | SUM period years from policy + creation date |
| Review-due notification | Computed aggregation + OR NotificationService | Pure date math + notification dispatch |
| Compliance aggregations (overdue, holds, disposal) | Declarative (`x-openregister-aggregations`) | GROUP BY + COUNT |
| Legal-hold override | Lifecycle guard condition (exceptions array) | Flag + reason in document metadata |
| Exception tracking (court orders, regulatory) | `DocumentRetention.exceptions[]` structured array | No new service |
| Disposal audit trail | Lifecycle transition + OR AuditTrailService | Automatic capture on state change |

No service class authored in this envelope (subject to ADR-031 exception: at most
one single-method `RetentionGuard`).

## Seed Data

**Default Retention Policies** (Dutch Archiefwet National Archival Guidelines):

```json
{
  "@self": {
    "register": "shillinq",
    "schema": "RetentionPolicy",
    "slug": "default-financial-5yr"
  },
  "name": "Financial Records — Standard (5 years)",
  "documentType": "financial-record",
  "retentionYears": 5,
  "legalHoldAllowed": true,
  "exemptionCategories": ["court-order", "regulatory-exception"],
  "description": "Per Archiefwet & VAT directive: invoices, receipts, ledgers retained 5 years"
}
```

Three seed policies will ship:
1. **Financial Records (5 years)** — invoices, receipts, GL entries
2. **Tax Documents (7 years)** — tax returns, audit workpapers (tax law override)
3. **General Administration (3 years)** — meeting notes, internal memos

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR archival-destruction not yet stable | Spec shape-neutral; PHP guard fallback (`RetentionGuard`, single-method, ~20 LOC) per ADR-031 exception; remove when OR extension lands |
| Retention period defaults misaligned with sector law | Research regulatory basis during discovery; implement cycle includes compliance doc linking each default to its source regulation |
| Legal-hold complexity (multi-authority holds, overlapping court orders) | T2 keeps holds simple (boolean + reason); multi-hold sequencing deferred to T3 |
| Aggregation performance with millions of documents | Pre-aggregated cache via OR's aggregation extension if gates trip; per-spec optimisation in implementing cycle |
| Disposal audit trail gaps (who authorized deletion, when, why) | Lifecycle transition + OR AuditTrailService captures reason; disposal initiator captured via `$user->getUID()` not displayName |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the three schemas
   (additive — no existing schema changes).
2. `src/manifest.json` is patched with 2 new menu entries + their pages (additive).
3. Seed data: three default policies imported via `ConfigurationService::importFromApp()`
   repair step (idempotent).
4. If OR's archival-destruction extension is not yet stable, `lib/Lifecycle/RetentionGuard.php`
   ships (single method, ~20 LOC, ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes the manifest
entries; retention policies and schedules remain queryable but unreferenced.

## Open Questions

1. **OR archival-destruction stability** — resolved in `opsx-ff` discovery;
   OR issue filed if needed.
2. **Sector-specific retention periods** — research defaults for health-care,
   education, municipality finance; finalized in implementing cycle.
3. **Multi-authority legal holds** — T2 keeps it simple (one hold flag); multi-hold
   sequencing & priority deferred to T3 if required by audit findings.
4. **Disposal job scheduling** — who triggers scheduled deletions? T2 defines the
   metadata; T3 adds the scheduled job. Resolved during implementing cycle.

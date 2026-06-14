# Design — SiSa Single Information Single Audit

## Context

Dutch government grants (WBSO, BBV, NSO, Tozo) require Single Information
Single Audit (SiSa) compliance. One organization, one unified audit trail
per fiscal year, one audit opinion. Prior to T2, Shillinq has no audit-trail
capability or compliance reporting surface.

**Status:** pr-created

Implementation delivered via `opsx-apply` (Hydra builder, issue #142); this doc explains
*why* the shape is what it is.

## Goals

- Express the entire SiSa audit + compliance surface as **declarative metadata**
  — schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Consume OR's audit-trail abstraction — per ADR-022. Zero parallel audit table.
- Make the spec a **competent auditor readable contract** — Dutch government
  SiSa flow recognisable end-to-end (transaction audit trail capture,
  document signing audit, compliance finding aggregation, management letter,
  audit opinion).
- Carry forward the **original Shillinq audit & compliance scope** under the
  declarative T2 envelope.
- Declare the management letter schema so T4 can attach external auditor
  signature and authority submission additively.

## Non-Goals

- No PHP audit service, no `SisaReportService` (subject to ADR-031 exception).
- No external audit firm API integration — T4.
- No blockchain / merkle-tree hashing — OR's hash-chained audit trail suffices.
- No multi-currency compliance translation — T5.

## Decisions

### D1 — SiSa is a unified audit trail + compliance report, not a separate database

Symmetric to D1 of `add-shillinq-accounts-payable-core`: `SisaReport`
is a compliance aggregation over OR's immutable audit trail; it does not
build its own events table. Every financial transaction participates via
its audit trail lifecycle (APTransaction.audit, ARInvoice.audit,
JournalEntry.audit, etc.). The `SisaReport` register holds the aggregate
opinion and management letter reference.

### D2 — Document signing audit trail is captured via OR's audit service

Every schema participating in SiSa (APTransaction, ARInvoice, JournalEntry,
GRInvoice) declares an audit-trail lifecycle. When a document is signed
(issued, approved, posted), OR's audit service automatically captures:
user, timestamp, reason, before/after state. No app-local signing table.

If OR's audit-trail extension is not yet stable, ADR-031's exception path
applies: a single-method read-only `OCA\Shillinq\Lifecycle\AuditGuard`
ships, cited in the spec.

### D3 — Compliance audit trail is a separate register for findings and observations

`ComplianceAuditTrail` is a per-administration record of audit findings
(critical, major, minor), observations (governance improvement), and
remediation tracking (due date, status, completion). This is the auditor's
working log; `SisaReport` is the formal audit opinion.

### D4 — SiSa Report aggregates transaction compliance metrics

On close of fiscal year, a declarative aggregation computes:
- Total transactions by type (AP, AR, GL)
- On-time settlement %
- Audit findings count (critical/major/minor)
- Observations count
- Remediation completion status
- Overall audit opinion (unqualified, qualified, adverse, disclaimer)

Pure aggregation; no PHP service.

### D5 — Management Letter is a declarative schema holding auditor communication

`ManagementLetter` holds the formal auditor-to-management communication:
sections (findings, observations, remediation recommendations), issue date,
due response date. T4 attaches external auditor signature and authority
submission. T2 provides the data structure only.

### D6 — Grant eligibility check is a `SisaEligible` boolean on Grant

`Grant` schema gains a `isSISAEligible` boolean. The SiSa report
aggregation filters to eligible grants only. Eligibility is determined by
grant scheme rules (WBSO requires SiSa audit, Tozo does not, etc.);
configuration resolved during implementing cycle's admin UX.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Audit trail (immutable) | OR `x-openregister-audit-trail` | Every transaction schema declares audit lifecycle; OR service captures events |
| Document signing audit | OR audit service (per-object event log) | When document state changes (draft → issued → signed), event logged automatically |
| Compliance findings tracking | OR `x-openregister-aggregations` + new ComplianceAuditTrail register | Aggregation query groups findings by severity; ComplianceAuditTrail holds working log |
| SiSa compliance report | OR `x-openregister-aggregations` | GROUP BY fiscal year + SUM(transaction count, on-time %) + COUNT(findings); query-only, no PHP |
| Management letter schema | New T2 register (declared in this spec) | Holds auditor communication; T4 attaches signature/submission |
| Grant SiSa eligibility | Grant.isSISAEligible boolean (add to existing Grant schema) | Per-grant flag; SiSa report filters to eligible grants |
| Audit opinion calculation | Declarative rule (findings severity → opinion mapping) | If OR's conditional aggregations support rule evaluation; else ADR-031 exception |
| Manifest navigation | T1 manifest pattern | 3 entries (Compliance Audit, Management Letter, SiSa Reports) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 1 lifecycle
block + 3 aggregations + 3 manifest entry pairs. At most 1 single-method
read-only PHP helper (`AuditGuard` or `SisaReportingService`) gated by
ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Audit trail capture on transaction state change | Declarative (`x-openregister-audit-trail`) | OR's audit service is immutable + hash-chained; no PHP needed |
| Document signing audit | Lifecycle action (state → audit event) | Pure trigger → event; no business logic |
| Compliance findings aggregation | Declarative (`x-openregister-aggregations` + new register) | GROUP BY severity, SUM count — pure query |
| SiSa report generation | Declarative (aggregation query + manifest report view) | Fiscal year filtered GROUP BY + SUM; no calculations |
| Management letter data structure | Declarative (schema fields) | Holds auditor communication; T4 attaches signature |
| Grant SiSa eligibility check | Schema boolean field | Per-grant policy flag; filtering done in aggregations |
| Audit opinion assignment | Declarative rule (findings → opinion) if OR supports; else single-method `SisaReportingService` per ADR-031 exception | If OR's conditional aggregations mature; else read-only helper |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method read-only `SisaReportingService`).

## Seed Data

3-5 example objects per schema with realistic Dutch values:

- **SisaReport**: 2026 fiscal year, 5 organizations, mixed opinions (unqualified, qualified)
- **ComplianceAuditTrail**: 2-3 findings per report (critical/major), 2-3 observations, 1-2 overdue remediations
- **AuditDocument**: example AP invoice, AR invoice, GL entry with signing timestamps

Loaded via `importFromApp()` at install.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR audit-trail aggregation queries not yet stable | Spec shape-neutral; read-only `SisaReportingService` fallback per ADR-031 exception; remove when OR extension lands |
| Grant SiSa eligibility overlaps with grant-subsidy-management | Per ADR-022 review during implementing cycle; boolean field on Grant is conservative |
| Management letter format unclear with external auditor | Schema declares data structure (sections, dates); T4 defines signature/submission format |
| SiSa report aggregation slow with 10k+ transactions | Pre-aggregated cache via OR's aggregation extension if performance gates trip; per-spec optimization in implementing cycle |
| Audit opinion assignment needs business rule engine | If OR's conditional aggregations support rule evaluation, use that; else single-method read-only helper per ADR-031 exception |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. `Grant.isSISAEligible` boolean is added to existing Grant schema
   (backward-compatible additive change).
3. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).
4. If OR's audit-trail aggregation extension is not yet stable,
   `lib/Service/SisaReportingService.php` ships (read-only, ~50 LOC,
   ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; audit records remain queryable but unreferenced.

## Open Questions

1. **OR audit-trail aggregation stability** — resolved in `opsx-ff`
   discovery; OR issue filed if needed.
2. **Grant SiSa eligibility rules** — which schemes require SiSa?
   (WBSO yes, BBV yes, Tozo no); rules resolved during implementing
   cycle's admin UX review.
3. **Audit opinion algorithm** — 0 findings = unqualified; 1-2 major = qualified;
   3+ major or any critical = adverse; remediation overdue = disclaimer;
   algorithm finalized during implementing cycle's compliance review.
4. **Management letter sections** — standard sections: findings summary,
   observations (governance), remediation recommendations, opinion,
   auditor attestation; layout resolved during implementing cycle's
   auditor template review.

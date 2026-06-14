# Proposal: bookkeeping-sisa-reporting

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`SisaReport`, `AuditDocument`, `ComplianceAuditTrail`) + declarative
audit-trail lifecycle consuming OR's audit trail service per ADR-022 +
manifest entries. No PHP audit service classes are authored (subject to
ADR-031 exception: audit trail queries may require a single read-only
`SisaReportingService` if OR's aggregation queries are not yet stable).

## Summary

Introduce the **Single Information Single Audit (SiSa) reporting** capability
for Shillinq as a T2 compliance + audit capability (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability enables government
organizations to meet Dutch government audit requirements (BBV, Wbso) by
declaring a unified audit trail and compliance report generation surface
across all financial transactions. The change declares the `SisaReport`,
`AuditDocument`, and `ComplianceAuditTrail` registers; the audit-trail
lifecycle consuming OR's audit service per ADR-022; document signing audit
trails; management letter generation; and compliance reporting aggregations.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-bookkeeping-foundation`](../add-shillinq-bookkeeping-foundation/proposal.md)
(GL transactions), [`add-shillinq-audit-trail`](../add-shillinq-audit-trail/proposal.md)
(document versioning), [`add-shillinq-grant-subsidy-management`](../add-shillinq-grant-subsidy-management/proposal.md)
(grant eligibility checks).

## Motivation

Dutch government grants (WBSO, BBV, NSO, Tozo) require Single Information
Single Audit (SiSa) compliance — one unified audit trail per organization,
one audit opinion per fiscal year, automatically aggregated from all
underlying transactions. Prior to T2, Shillinq has no audit-trail capability
or compliance reporting surface. This spec captures the audit trail + report
generation + management letter path as a unified T2 declarative capability,
leveraging OR's audit service rather than building a parallel audit table.

The legacy AP/AR draft cluster from intelligence-db calls out audit trails
and compliance reporting as top-tier government customer needs alongside
accounts payable and receivable.

This is one of eight T2 capability changes; this proposal scopes only the
SiSa audit + compliance reporting slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-sisa-reporting`); declares 3 new registers
  (`SisaReport`, `AuditDocument`, `ComplianceAuditTrail`) with audit
  lifecycles and aggregations; adds 3 manifest navigation entries
  (Compliance Audit, Management Letter, SiSa Reports).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-audit-trail`, `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] Project: nextcloud-vue — no source changes; uses existing
  `CnObjectSidebar` audit trail tab and `CnAuditTrailTab` component.

## Scope

### In Scope

- One new capability spec (`bookkeeping-sisa-reporting`) — see the `specs/` folder.
- The `SisaReport` register with fiscal year reference, audit opinion,
  management letter FK, compliance status, total transaction amount.
- The `AuditDocument` register with document type, signing audit trail,
  version history, attached GL transaction references.
- The `ComplianceAuditTrail` register tracking audit findings,
  observations, and remediation status per administration.
- Document signing audit trail: every state change on financial documents
  (invoices, purchase orders, journal entries) captured with user, timestamp,
  reason via OR's audit service.
- Management letter generation: formal auditor communication documenting
  findings and recommendations, linked to compliance audit.
- Compliance reporting: aggregated SiSa report showing on-time settlement %,
  transaction integrity, audit opinion per fiscal year.
- SiSa eligibility check on grants: `isSISAEligible` boolean evaluated
  against grant record per administration's compliance policy.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Multi-currency SiSa translation** — T5.
- **External audit firm integration (API calls)** — future capability,
  not T2.
- **Blockchain / merkle-tree audit hashing** — out of scope; OR's
  hash-chained audit trail suffices.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-sisa-reporting`** — declares the three registers, the
audit-trail lifecycle (consuming OR's audit service), the signing audit
path, the management letter shape, the SiSa report aggregations, and
the grant eligibility check.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-SISA-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions (`AuditTrailService`,
`x-openregister-lifecycle`, `x-openregister-aggregations`) and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`SisaReport`, `AuditDocument`, `ComplianceAuditTrail`); declares
  audit lifecycle on `AuditDocument`; aggregations for SiSa compliance
  reporting.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  read-only `SisaReportingService` if OR's aggregation queries
  are not yet stable).
- No new bespoke Vue components (reuses `CnAuditTrailTab` from
  `CnObjectSidebar`).

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-audit-trail`
  (immutable event log per object), `x-openregister-lifecycle` (audit
  state machines), `x-openregister-aggregations` (SiSa compliance queries).
- **T1 general ledger** — depends on `add-shillinq-bookkeeping-foundation`
  for GL transaction audit trail backing.
- **T2 document-attachment-integration** — documents linked to GL
  transactions carry audit trails.
- **T2 grant-subsidy-management** — grant eligibility check integrated
  with SiSa report aggregation.

## Risks

### Risk 1: OR audit-trail aggregation queries not yet stable

**Severity**: Medium
**Mitigation**: If OR's aggregation extension (for computing SiSa
compliance % per fiscal year) is still draft, the spec captures the gap,
files an OR issue, and the implementing cycle MAY ship a single-method
read-only `OCA\Shillinq\Service\SisaReportingService` per ADR-031 §
"PHP guards remain a legitimate seam". The service is removed once OR's
extension lands. Spec is shape-neutral.

### Risk 2: Grant eligibility check overlaps with grant-subsidy-management

**Severity**: Low-Medium
**Mitigation**: Per ADR-022, prefer the OR abstraction. The spec
declares SiSa eligibility as a field on `Grant` if that capability
is available; otherwise a separate check in `ComplianceAuditTrail`.
Resolved during the implementing cycle.

### Risk 3: Document signing audit trail requires cross-schema versioning

**Severity**: Low
**Mitigation**: Every schema that participates in SiSa reporting
(APTransaction, ARInvoice, JournalEntry) declares an audit-trail
lifecycle via OR's extension. OR's immutable audit service handles
the storage; T2 declares the triggers.

### Risk 4: Management letter format may require external auditor input

**Severity**: Low
**Mitigation**: REQ-SISA-007 declares management letter as a
register with predefined sections (findings, observations, remediation,
opinion). T2 provides the data structure; T4 (external auditor integration)
attaches the audit firm's signature and submits to authorities.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — audit records remain queryable.

## Open Questions

1. **OR audit-trail aggregation stability** — see Risk 1; resolved in
   `opsx-ff` discovery; OR issue filed if needed.
2. **Grant SiSa eligibility location** — see Risk 2; resolved per
   ADR-022 review.
3. **Default audit findings categories** — findings (critical, major,
   minor), observations (governance improvement), remediation tracking
   (due date, status); defaults resolved during the implementing cycle's
   compliance review.
4. **External auditor API integration** — deferred to T4; T2 provides
   the data structure only.

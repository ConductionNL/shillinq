# Proposal: bookkeeping-archiefwet-retention

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(RetentionPolicy, RetentionSchedule, DocumentRetention) + lifecycle consuming
OR's archival-destruction workflow per ADR-022 + aggregations + manifest entries.
No PHP retention-service classes; retention schedules and compliance tracking
are declarative metadata.

## Summary

Introduce the **document retention (Archiefwet compliance)** capability for Shillinq
as one of the operational governance features required by Dutch law. This capability
formalizes document lifecycle management according to Dutch archival retention
requirements (Archiefwet 1995, as amended 2024).

The change declares three core registers:
- **RetentionPolicy** — organization-wide or document-type-specific retention rules
- **RetentionSchedule** — temporal schedules mapping documents to retention periods
- **DocumentRetention** — per-document tracking of retention status, review deadlines, and disposal

The retention lifecycle (`active → under-review → retained → scheduled-for-deletion → deleted`)
consumes OR's archival-destruction workflow per ADR-022. Compliance reporting and deadline
alerts are declarative aggregations. This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** none directly (retention policies are metadata-only in T2). T3
depends on `bookkeeping-audit-trail` for immutable lifecycle audit; T4 depends on
document attachment services for retention scope definition.

## Motivation

Dutch government organizations must comply with Archiefwet 1995 for all records
(including financial documents). The law requires:

1. **Documented retention schedules** — every document type must have a defined
   retention period (e.g. 5 years for invoices per National Archival Guidelines).
2. **Periodic compliance review** — records must be reviewed before disposal; review
   findings recorded.
3. **Audit trail** — destruction or archival must be logged with reason and authority.
4. **Exception handling** — legal holds, disputes, and exemptions must override
   standard schedules.

Per ADR-022, retention workflow (review, hold, disposal) comes from OR's
archival-destruction abstraction, not from an app-local service. Per ADR-031,
compliance reporting is a declarative aggregation, not a `ComplianceReportService`.

The legacy AP/AR draft cluster from intelligence-db calls out retention policy +
compliance reporting as mandatory for public-sector finance apps.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-archiefwet-retention`); declares 3 new registers
  (`RetentionPolicy`, `RetentionSchedule`, `DocumentRetention`) with
  lifecycle and aggregations; adds 2 manifest navigation entries
  (Retention Policies, Retention Dashboard).
- [ ] Project: openregister — no source changes; consumes existing
  archival-destruction workflow (if stable; else ADR-031 exception).
- [ ] Project: docudesk — integration point; document attachments are
  scoped by retention policy.

## Scope

### In Scope

- One new capability spec (`bookkeeping-archiefwet-retention`) — see the `specs/` folder.
- The `RetentionPolicy` register with document-type classification,
  default retention period (in years), legal hold capabilities, exemption categories.
- The `RetentionSchedule` register with per-document-type schedules,
  start-of-retention markers, review-due dates, disposal methods (delete / archive).
- The `DocumentRetention` register tracking retention status per document,
  review completion dates, legal holds, disposal audit trail.
- The retention lifecycle (`active → under-review → retained → scheduled-for-deletion → deleted`)
  consuming OR's archival-destruction workflow per ADR-022.
- Compliance aggregations: overdue-review count, legal-hold count,
  disposal audit summary (for Archiefwet compliance reporting).
- Exception handling: legal holds, regulatory exceptions, court orders.
- Deadline alerts: automatic notification when review is due per Archiefwet timelines.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests are deliberately not in this proposal; the task list references
  them but the implementation lands via a separate `opsx-apply` cycle.
- **VAT/invoice retention automation** — T3 (automatically applies retention to
  invoices per tax law).
- **Multi-language retention notices** — T3.
- **Peppol integration for retention status** — T4.
- **Data anonymization during archival** — T5 (GDPR compliance path).

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-archiefwet-retention`** — declares the three registers,
the retention lifecycle (consuming OR archival-destruction), the compliance
aggregations, the exception-handling pattern, and the deadline-alert model.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-RET-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`RetentionPolicy`, `RetentionSchedule`, `DocumentRetention`);
  declares lifecycle on `DocumentRetention`, aggregations on
  compliance metrics.
- `src/manifest.json` — adds 2 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one single-method
  `RetentionGuard` if OR's archival-destruction extension is not yet stable).
- No new bespoke Vue components beyond standard `CnIndexPage` / `CnDetailPage`.

## Cross-Project Dependencies

- **OpenRegister** — depends on archival-destruction workflow (ADR-022 —
  if stable; else ADR-031 exception path), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T3 VAT/invoice retention** — depends on this spec for policy model;
  adds tax-law automation on top.
- **T3 audit-trail integration** — depends on OR's audit-trail immutability
  for compliance audit.

## Risks

### Risk 1: Archival-destruction workflow not yet stable on OR

**Severity**: Medium
**Mitigation**: If OR's archival-destruction extension is still draft at T2
implementation time, the spec captures the gap, files an OR issue, and the
implementing cycle MAY ship a single-method `OCA\Shillinq\Lifecycle\RetentionGuard`
per ADR-031 §"PHP guards remain a legitimate seam". The guard is removed once
OR's extension lands. Spec is shape-neutral.

### Risk 2: Retention period defaults may violate sector-specific regulations

**Severity**: Medium
**Mitigation**: Spec declares retention periods per Archiefwet defaults
(5 years for financial records). Each administration can override per policy.
Implementing cycle includes a guidance doc linking each default to its
regulatory basis.

### Risk 3: Legal-hold override complexity

**Severity**: Low-Medium
**Mitigation**: Legal holds are modeled as an exception type on
`DocumentRetention.legalHold` (boolean flag + reason). The lifecycle
transition respects the flag; no complex hold-revert machinery in T2.

### Risk 4: Compliance aggregations may run slowly with large document sets

**Severity**: Low
**Mitigation**: Aggregations are pre-computed via OR's aggregation extension
if performance gates trip; per-spec optimisation in implementing cycle.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder;
no runtime impact. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR; registers are non-destructive —
retention policies and schedules remain queryable.

## Open Questions

1. **Archival-destruction stability on OR** — see Risk 1; resolved in
   `opsx-ff` discovery; OR issue filed if needed.
2. **Sector-specific retention periods** — research regulatory basis for
   health-care, education, municipality finance defaults; defaults finalized
   in implementing cycle.
3. **Court-order override mechanism** — legal holds + court-order exemptions
   modeled as `DocumentRetention.exceptions[]`; exact access control
   (who can set/clear) resolved in implementing cycle.

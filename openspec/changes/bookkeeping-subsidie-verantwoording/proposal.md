# Proposal: bookkeeping-subsidie-verantwoording

`kind: governance` — searchable subsidie registry with accountability report generation, auditor statement verification, and overview dashboards.

## Summary

Introduce **subsidie administratie & verantwoording (grant accountability & reporting)** for Shillinq as a T3 capability for Dutch government and public-sector organizations managing subsidies. This change declares the `SubsidieVerantwoording` (accountability record) and `AuditorStatement` registers; search and filtering on grant administrative records; accountability report generation from grant lifecycle events; auditor verification workflows for large subsidies; and overview dashboards showing compliance status, audit findings, and settlement status.

Relates to and extends the existing `grant-subsidy-management` spec by adding governance, auditability, and accountability reporting layers.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** T1 `bookkeeping-general-ledger` and T2 `grant-subsidy-management` (accountability reports reference Grant records).

## Motivation

Every Dutch grant recipient (gemeente subsidies, provincie subsidies, EU-fund recipients) and every subsidie-distributing government body faces accountability obligations under Awb 4.2, financial audit requirements, and transparency mandates. Current practice splits this work across multiple tools — grant tracking in one system, accountability reporting in another, auditor communication via email. A unified subsidie administratie layer enables:

- **Searchable grant registry** — find subsidies by recipient, program, status, award date, due date
- **Accountability reports** — auto-generated from grant state changes (awarding, disbursement, status change)
- **Auditor verification** — structured workflow for auditor sign-off on large subsidies (SISA-eligible, >€25k)
- **Compliance dashboard** — overview of pending audits, overdue reports, settlement status
- **Audit trail immutability** — every grant state change audit-logged per OR abstractions

## Affected Projects

- [x] Project: shillinq — adds 2 new registers (`SubsidieVerantwoording`, `AuditorStatement`), adds 2 manifest navigation entries (`Subsidies > Accountability Reports`, `> Auditor Statements`), ships seed data for audit-finding templates.
- [ ] Project: openregister — no source changes; consumes existing audit-trail (ADR-022), approval-workflow (ADR-022), notifications (ADR-031).
- [ ] Project: docudesk — referenced by URI from `AuditorStatement.attestationDocumentUri` for auditor sign-off PDFs.

## Scope

### In Scope

- One new capability spec (`bookkeeping-subsidie-verantwoording`).
- `SubsidieVerantwoording` register with accountability reporting lifecycle (draft → submitted → approved → final) and search/filter on Grant master.
- `AuditorStatement` register with verification workflow (pending → under-review → approved/rejected/conditional) for large grants (configurable threshold, default €25k).
- Accountability report generation triggered on grant state transitions (awarded, disbursed, status-changed).
- Auditor verification workflow with audit-finding template seed data.
- Compliance overview dashboard showing pending reports, overdue audits, settlement status.
- Manifest navigation under `Subsidies` (visible for all admin types).

### Out of Scope

- **PDF generation of accountability reports** — stored as DigitalDocument references, PDF rendering owned by docudesk.
- **Multi-language accountability templates** — English templates seeded; operators customize per administration.
- **Advanced audit scheduling** — out of scope (roadmap).
- **Implementation code** — spec-only change.

## Approach

One delta with ADDED Requirements under `REQ-SUBV-*` for accountability reporting and auditor verification.

## New Dependencies

None. Consumes T1 GL + T2 grant-subsidy-management + existing OR abstractions + docudesk.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas with lifecycle.
- `lib/Settings/seeds/audit-finding-templates.json` — new file (audit-finding categories, severity levels, remediation templates).
- `src/manifest.json` — adds 2 navigation entries under `Subsidies`.
- Repair step extension to import the audit-finding seed.
- No new PHP services.

## Cross-Project Dependencies

- **OpenRegister** — relies on lifecycle + audit-trail + cross-schema FK to Grant. Standard shape.
- **docudesk** — symbolic URI reference for auditor attestation documents; no coupling.

## Risks

### Risk 1: Auditor statement sign-off scope

**Severity**: Low → mitigated
**Mitigation**: Configurable threshold (default €25k); auditor statement template reviewed with finance officer / compliance officer persona.

### Risk 2: Accountability report timing

**Severity**: Low
**Mitigation**: Reports generated on grant state change; operators can manually re-trigger. Audit-logged per OR.

### Risk 3: Multi-jurisdiction variations

**Severity**: Medium → mitigated
**Mitigation**: Audit-finding templates operator-editable per administration; seed provides Dutch defaults per Awb 4.2 + VNG guidelines.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the change folder. After implementation: revert the PR, run the repair step in down-direction. Registers are non-destructive.

## Open Questions

1. **Auditor threshold** — Default €25k for SISA-eligible grants. Confirm with procurement/grant-officer personas.
2. **Accountability report schedule** — Auto-triggered on grant state change. Confirm whether periodic batch-generation is needed.
3. **Audit-finding linkage** — Should audit findings be FK-linked to specific grant transactions or remain at grant level?

# Design — Subsidie Administratie & Verantwoording

## Context

Awb 4.2 + Archiefwet + Dutch government subsidy-distribution regulations require every grant recipient and subsidy-distributing body to maintain accountability records, submit accountability reports, undergo audits (SISA for large grants), and maintain audit trails. Every gemeente subsidies department, provincie subsidieverstrekker, and grant recipient faces this pattern.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change adds accountability reporting and auditor verification to the existing grant-subsidy-management layer.

## Goals

- Declare `SubsidieVerantwoording` as a register with accountability report lifecycle (draft → submitted → approved → final).
- Declare `AuditorStatement` register for auditor verification (pending → under-review → approved/rejected/conditional).
- Surface searchable subsidie registry with filters on status, recipient, program, award date, settlement status.
- Auto-generate accountability reports on grant state transitions (awarded, disbursed).
- Auditor workflow with configurable threshold (default €25k) for large-grant verification.
- Audit-finding seed templates (categories, severity, remediation).

## Non-Goals

- No PDF generation here (docudesk owns rendering).
- No app-local `AccountabilityService` (ADR-031 anti-pattern).
- No advanced audit scheduling logic.

## Decisions

### D1 — `SubsidieVerantwoording` accountability report lifecycle

`draft → submitted → approved → final` declared via `x-openregister-lifecycle`. The graph permits:
- `draft → submitted` (operator initiates, auto-audit-logged)
- `submitted → approved` (approval-gated, e.g., by finance officer)
- `approved → final` (published, timestamp recorded)
- `final → submitted` (resubmission if corrections needed)

### D2 — `AuditorStatement` verification workflow

`pending → under-review → approved / rejected / conditional` declared via `x-openregister-lifecycle`. Replaces email workflows. Auditor can:
- Add findings as ForeignKey to audit-finding templates
- Upload attestation document (URI to docudesk)
- Transition to `approved` (grant settlement can proceed) or `rejected` (block disbursement)

### D3 — Large-grant threshold (configurable, default €25k)

Auditor statement auto-required when `Grant.awardedAmount >= administrationConfig.auditThreshold`. Declared as a precondition on `SubsidieVerantwoording.submitted → approved` transition.

### D4 — Accountability report auto-generation

On grant state transitions (awarded, disbursed, status-changed), OR lifecycle action creates a `SubsidieVerantwoording` record in `state: draft`. Operator reviews and submits.

### D5 — Audit-finding seed templates

`audit-finding-templates.json` ships 6+ categories (eligibility, documentation, financial-control, tax, compliance, other) with severity levels (critical, high, medium, low). Per-administration override allowed.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `SubsidieVerantwoording` lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on schema |
| Approval gate on accountability submission | OR approval-workflow (ADR-022) | Consumed via `requires` |
| Auditor statement attachment | docudesk attachment URI (ADR-022) | `AuditorStatement.attestationDocumentUri` |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| RBAC (subsidie-officer, auditor) | OR authorization (ADR-022) | Per-schema role |
| Notifications (report due, audit overdue) | `x-openregister-notifications` (ADR-031) | Standard event-driven |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 2 menu entries under `Subsidies` |
| Audit-finding templates seed | `ConfigurationService::importFromApp()` | `audit-finding-templates.json` |
| Grant lookup/search | Existing Grant register | Search index + filter sidebar |

**Net new code in implementation**: 2 schema declarations + 2 manifest entries + 1 seed JSON + 2 dashboard cards (overview). No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Accountability report lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| Auditor statement workflow | Declarative (lifecycle + approval gate) | Standard precondition |
| Large-grant threshold check | Declarative precondition or guard | Transparent, audit-logged |
| Accountability report auto-generation | Declarative lifecycle action on Grant state change | Triggered by OR lifecycle |
| Audit findings | Declarative FK to template + custom notes | Opaque to audit otherwise |
| Notifications | Declarative (`x-openregister-notifications`) | Standard event-driven |
| Audit trail | OR audit-trail-immutable | ADR-022 |

No service class authored.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/audit-finding-templates.json` | 6+ audit-finding categories with severity levels and remediation templates | 8-12 | Awb 4.2 + VNG grant-management guidelines |

SPDX header + `_meta.source: "Awb 4.2 + VNG guidelines"` + `_meta.version` field. Loaded via the repair step. Per-administration override allowed for category names/translations but not severity mapping.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Auditor threshold variability | Configurable per administrationConfig; default €25k documented |
| Report generation timing | Auto on grant state change; operators can manually re-trigger and re-edit |
| Audit-finding completeness | Template seed provides defaults; operators add custom findings inline |
| Multi-year/multi-tranche grants | FK to Grant at top level; tranches handled via separate Grant records |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 2 schemas (additive).
2. `src/manifest.json` adds 2 navigation entries.
3. The repair step imports the audit-finding seed (idempotent).

Down-direction: revert the implementing PR, run the repair step in down-direction. Existing accountability reports remain queryable.

## Open Questions

1. **Report generation timing** — Should accountability reports be auto-generated on EVERY grant state change, or only on `awarded` + `disbursed`? Confirm during spec review.
2. **Audit-finding attachment** — Should findings be individually attached as documents, or remain as text notes? Docudesk integration scope?
3. **Tranche vs. single-grant model** — Multi-year subsidies with annual tranches: one Grant record per tranche (current) or one Grant with tranche sub-records (future)?

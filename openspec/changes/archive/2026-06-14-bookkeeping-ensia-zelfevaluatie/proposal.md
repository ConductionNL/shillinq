# Proposal: bookkeeping-ensia-zelfevaluatie

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`ENSIAJaarcyclus`, `Evaluatievraag`, `Bevinding`) +
`x-openregister-lifecycle` consuming OR's workflow extension per ADR-022
+ manifest entries. No PHP ENSIA orchestrator classes are authored (subject to ADR-031 exception:
at most one single-method `ENSIAValidationGuard` if OR's extension
is not yet stable).

## Summary

Introduce the **ENSIA Zelfevaluatie (Self-Evaluation) capability** for Shillinq
as one of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability formalises the annual
Dutch information security self-evaluation (ENSIA) workflow under the
declarative T2 envelope. The change declares the
`ENSIAJaarcyclus`, `Evaluatievraag`, and `Bevinding` registers;
the evaluation lifecycle (`in-voorbereiding → in-uitvoering → peer-review → college-akkoord → ingediend → afgerond`)
consuming OR's workflow extension per ADR-022; the peer-review path; the
college-approval flow; XML export to the national ENSIA portal per VNG specification;
audit-trail per question change with diffs; automated finding generation
from VNG norm deviations; mitigation action tracking; and college-verklaring
(college declaration) generation.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(materialises risk mitigation tracking),
[`add-shillinq-document-attachment-integration`](../add-shillinq-document-attachment-integration/proposal.md)
(evidence document attachment via docudesk).

## Motivation

ENSIA is the annual mandatory information security self-evaluation for
Dutch municipalities, provinces, and waterschaps under VNG/IPO/UvW direction.
Current practice is fragmentary: organisations use a mix of Excel templates,
expensive GRC tools, and loose Word documents. Evidence (policies, audit reports,
control screenshots) scatters across fileshares. The link between control
answers and actual evidence is fragile — external IT audits spend days
reconstructing question-to-evidence chains.

Shillinq's compliance envelope provides a structural home for the ENSIA workflow:
per BIO-onderwerp and per verantwoordingsdomein, a question tree with scoring,
evidence attachment, peer-review gates, college approval, and automated portal
export. Audit-trail per answer change ensures external auditors can trace
question-history. Automated finding generation from VNG norms surfaces risks
for mitigation planning (linking to planix for action tracking).

This is one of eight T2 capability changes; this proposal scopes only the
ENSIA core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-ensia-zelfevaluatie`); declares 3 new registers
  (`ENSIAJaarcyclus`, `Evaluatievraag`, `Bevinding`) with lifecycles
  and workflows; adds 5 manifest navigation entries (ENSIA Cycles,
  Evaluations, Findings, Audit Trail, College Verklaring).
- [ ] Project: openregister — no source changes; consumes existing
  workflow extension (if stable; else ADR-031 exception),
  `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] Project: docudesk — no source changes; evidence documents
  referenced by FK URI per `bookkeeping-document-attachment-integration`.
- [ ] Project: planix — no source changes; mitigation actions can
  be cross-linked to planix tasks for project tracking.

## Scope

### In Scope

- One new capability spec (`bookkeeping-ensia-zelfevaluatie`) —
  see the `specs/` folder.
- The `ENSIAJaarcyclus` register with year, organisation KvK, process owner
  (CISO), deadline dates, selected domains, and status lifecycle.
- The `Evaluatievraag` register with question code (BIO-9.1.1), text,
  answer type (ja-nee-nvt / maturity 1-5 / free text), evidence attachments,
  peer-review status, and audit-trail per change.
- The `Bevinding` register for risks and improvement opportunities identified
  by VNG norm comparisons, mitigation action tracking, and status.
- Evaluation lifecycle (`in-voorbereiding → in-uitvoering → peer-review → college-akkoord → ingediend → afgerond`)
  consuming OR's workflow extension per ADR-022.
- Peer-review path: per-question akkoord/wijziging-gevraagd with commentaar
  routing back to answerer; prevents college-akkoord without resolved wijzigingen.
- College-verklaring generation: Word document on VNG template with auto-filled
  org data, per-domain summaries, top findings, and signature fields.
- XML export to landelijke ENSIA portal (VNG format, no direct API in 2026).
- Maturity-score validation: score ≥ 3 requires evidence + toelichting ≥ 50 chars.
- Audit-trail per answer change: timestamp, user, old/new values, required
  reason field post peer-review, diff-view for external auditors.
- Automated finding generation: when maturity < VNG norm (often 3), system
  generates concept-Bevinding for risk acceptance or mitigation.
- Mitigation action tracking with owner, deadline, status (open/in-behandeling/gerealiseerd/geaccepteerd).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **VNG question-set fetching** — assumes VNG bron is available as
  openregister bio-nen7510 register or external API.
- **Multi-language support beyond Dutch** — T2 focuses on nl_NL; en_US
  localisation deferred.
- **DigiD integration** — authentication scope outside this spec.
- **Automatic report compliance scoring** — calculation deferred to T3.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-ensia-zelfevaluatie`** — declares the three
registers, the evaluation lifecycle (consuming OR workflow extension), the
peer-review path, the college-akkoord flow, the XML export contract, the
audit-trail pattern, the finding auto-generation rule, and the
college-verklaring document shape.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-ENSIA-*` for
traceability.

## New Dependencies

- **openregister bio-nen7510** — bron for VNG question sets (existing register
  or external API integration).
- **docudesk** — evidence document storage (existing FK contract).
- **planix** (optional) — cross-link mitigation actions to project tasks.
- **launchpad** — voortgangs-widget for ENSIA cycle dashboard.
- **decidesk** — collegebesluit-workflow for college-verklaring approval.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`ENSIAJaarcyclus`, `Evaluatievraag`, `Bevinding`); declares
  lifecycle on cycles and evaluations; aggregations for finding rollup.
- `src/manifest.json` — adds 5 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `ENSIAValidationGuard` if OR's workflow extension
  is not yet stable).
- No new Vue components (reuse approval-flow + document-list patterns).

## Cross-Project Dependencies

- **OpenRegister** — depends on workflow extension (ADR-022 — if
  stable; else ADR-031 exception path), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **docudesk** — evidence document FK contract (per
  `bookkeeping-document-attachment-integration`).
- **planix** — optional cross-link for mitigation action tracking.
- **launchpad** — ENSIA cycle voortgang widget (read-only aggregation).
- **decidesk** — collegebesluit approval workflow (existing integration pattern).

## Risks

### Risk 1: OR workflow extension not yet stable

**Severity**: Medium
**Mitigation**: If OR's workflow extension is still draft
at T2 implementation time, the spec captures the gap, files an OR
issue, and the implementing cycle MAY ship a single-method
`OCA\Shillinq\Lifecycle\ENSIAValidationGuard` per ADR-031 §"PHP guards
remain a legitimate seam". The guard is removed once OR's
extension lands. Spec is shape-neutral.

### Risk 2: VNG question-set format may change

**Severity**: Low
**Mitigation**: Per ADR-022, assume openregister bio-nen7510
is the canonical bron. If external API is required, implement a
loader per `bookkeeping-document-attachment-integration` contract.
Spec declares the FK to question-set version for audit trail.

### Risk 3: College-verklaring Word template drifts

**Severity**: Low
**Mitigation**: REQ-ENSIA-006 declares the shape contract
(auto-filled fields, org data, per-domain summary). T2 delegates
Word-doc generation to a template library (reuse pattern from
approval-workflow-management). Template versioning per compliance
cycle.

### Risk 4: XML export may require iterative refinement with VNG

**Severity**: Low-Medium
**Mitigation**: REQ-ENSIA-007 declares the XML contract per
ENSIA-XSD. Implement via an exportable aggregation that VNG can
validate. First-pass export assumed; refinement cycles tracked as
post-T2 issue.

### Risk 5: Audit-trail diff complexity for external auditor UI

**Severity**: Low
**Mitigation**: REQ-ENSIA-008 declares the audit-trail shape.
UI for diff-view deferred to implementing cycle; assume basic
before/after + timestamp + user.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — ENSIA cycles remain queryable.

## Open Questions

1. **OR workflow extension stability** — see Risk 1; resolved in
   `opsx-ff` discovery; OR issue filed if needed.
2. **VNG question-set bron** — is openregister bio-nen7510 stable?
   If not, does VNG provide a REST API? Resolved during implementing
   cycle's data-layer review.
3. **College-verklaring template library** — reuse existing Word-template
   pattern or new integration? Resolved during implementing cycle's
   UX review.
4. **Mitigation action project-task cross-link** — optional planix
   integration or post-T2 enhancement? Resolved during scope refinement.
5. **Default evaluation cadence** — annually before 1 May per VNG;
   custom cycles configurable per administration? Resolved during
   implementing cycle's configuration UX.

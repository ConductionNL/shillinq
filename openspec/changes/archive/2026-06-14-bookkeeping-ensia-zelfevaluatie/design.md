# Design — ENSIA Zelfevaluatie (Self-Evaluation)

## Context

ENSIA (Eenduidge Normatiek Single Information Audit) is the annual mandatory
information security self-evaluation for Dutch public-sector organisations.
Current practice scatters evidence across fileshares and loose documents.
The link between BIO-controle answers and daadwerkelijk bewijs (actual evidence)
is fragile — external IT audits rebuild question-to-evidence chains from scratch.

Shillinq's compliance envelope homes the ENSIA workflow: per BIO-onderwerp
and per verantwoordingsdomein, a vragenboom with maturity scoring, evidence
attachment, peer-review gates, college approval, and automated portal export.
Audit-trail per answer change traces the question-history for external auditors.
Automated finding generation from VNG norms surfaces risks for mitigation
planning.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire ENSIA-core surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per
  ADR-031.
- Consume OR's workflow abstraction — per ADR-022. Zero
  parallel ENSIA orchestrator table.
- Make the spec a **compliance-officer-readable contract** —
  Dutch ENSIA process recognisable end-to-end (cycle intake,
  question answering, peer-review, college approval, portal export,
  audit trail).
- Declare the **evaluation lifecycle** with peer-review gates and
  college-akkoord workflow.
- Declare the **evidence attachment** and **audit-trail** patterns for
  external IT auditor access.
- Declare the **college-verklaring** document shape for formal submission.
- Declare **automated finding generation** from VNG norm comparisons.

## Non-Goals

- No PHP ENSIA orchestrator service, no `ENSIACoordinatorService.php`.
- No VNG portal direct API integration — manual XML export + download.
- No automatic compliance scoring — calculation deferred to T3.
- No multi-language support beyond Dutch (en_US deferred to T5).
- No DigiD integration — authentication scope outside this spec.

## Decisions

### D1 — ENSIA evaluation is a lifecycle-driven workflow, not a questionnaire app

ENSIA is not a simple form-fill; it is a multi-stage governance process:
intake → answering → peer-review → college-akkoord → portal submission → closure.
Each stage gates the next. The evaluation lifecycle consumes OR's workflow
extension (ADR-022) with explicit status transitions.

### D2 — Questions are declaratively linked to VNG BIO/domein norms

`Evaluatievraag` carries a `vraagCode` (e.g., "BIO-9.1.1") that is a stable
reference to the VNG source-of-truth question set. The question-set version
is tracked per cycle for audit trail. No app-local question table — fetch
from openregister bio-nen7510 or external API on cycle initialisation (REQ-ENSIA-001).

### D3 — Peer-review gates college-akkoord without full consensus

Per REQ-ENSIA-004, if any peer-review wijziging-gevraagd is unresolved,
the cycle cannot advance to college-akkoord. This prevents premature escalation
and keeps question ownership explicit.

### D4 — Findings are auto-generated from VNG norm deviations

When maturity score < VNG normniveau (often 3), the system auto-generates
a concept-Bevinding (REQ-ENSIA-005). No manual finding creation required.
Findings track mitigation ownership and deadline; status cycles
open → in-behandeling → gerealiseerd / geaccepteerd.

### D5 — Audit-trail captures every answer change with reason-requirement post peer-review

Per REQ-ENSIA-008, every question-answer edit records timestamp, user,
old/new values, and (post peer-review) a required reason field. External
auditors can view the diff-sequence per question. No separate audit log table —
OR's auditTrail built-in field.

### D6 — College-verklaring is a generated Word document, not a form

Per REQ-ENSIA-006, college-verklaring is a Word document on VNG template
with auto-filled org data, per-domain summaries, top findings, and
handtekeningvelden. Template versioning per compliance cycle. No bespoke
college-form UI.

### D7 — XML export is declarative and VNG-schema-compliant

Per REQ-ENSIA-007, XML export is an aggregation that produces a file
conforming to ENSIA-XSD. Operators download and upload to the VNG portal
(no direct API in 2026). Export includes answer hashes and college-brief
reference for portal traceability.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| ENSIA evaluation lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `ENSIAJaarcyclus` (`in-voorbereiding → in-uitvoering → peer-review → college-akkoord → ingediend → afgerond`); gates per stage |
| Peer-review workflow | OR workflow extension (ADR-022) or `ENSIAValidationGuard` (ADR-031 exception) | Per-question peer-reviewer assignment; wijziging-gevraagd blocks college-akkoord; commentaar routes back to answerer |
| Evidence attachment | T2 `bookkeeping-document-attachment-integration` | `Evaluatievraag.bewijsstukken` array of file-references + omschrijving |
| Finding auto-generation | New rule (VNG norm comparison) | Triggered on peer-review completion; generates concept-Bevinding with question as context |
| Mitigation tracking | OR aggregations + status enum | `Bevinding.mitigatieActie`, `verantwoordelijke`, `streefDatum`, `status` |
| College-verklaring generation | Word-template library (reuse from approval-workflow-management) | VNG template + auto-fill + handtekeningvelden |
| XML export | OR aggregations + custom formatter | VNG-XSD-compliant file generation as downloadable artifact |
| Audit trail | OR `auditTrail` built-in field | Records per question-answer change + reason (post peer-review) |
| Question-set bron | openregister bio-nen7510 (or external API) | Fetch on cycle init; store `vraagSetVersion` per cycle for audit trail |
| Manifest navigation | T1 manifest pattern | 5 entries (ENSIA Cycles, Evaluations, Findings, Audit Trail, College Verklaring) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 1
lifecycle block + 1 finding auto-generation rule + 1 college-verklaring
template library binding + 1 XML export aggregation formatter + 5 manifest
entry pairs. At most 1 single-method PHP guard (`ENSIAValidationGuard`)
gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| ENSIA evaluation lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine with gating preconditions |
| Peer-review workflow | Consumed from OR workflow extension if stable; else single-method `ENSIAValidationGuard` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Maturity validation | Declarative precondition on persist | Score ≥ 3 requires evidence + toelichting; pure data validation |
| Finding auto-generation | Declarative rule (VNG norm comparison) | Triggered on peer-review completion; no complex business logic |
| College-verklaring generation | Template library binding (Word-doc generator) | Pure document assembly; no process logic |
| XML export | Aggregation formatter | Transforms register data to VNG-XSD format; no workflow logic |
| Audit-trail recording | OR `auditTrail` built-in + reason field on change | Automatic per field change; reason required post peer-review |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `ENSIAValidationGuard`).

## Seed Data

- **VNG question sets** — fetched from openregister bio-nen7510 on
  cycle initialisation; no seed data shipped in app. BIO-1.04 + domain-specific
  questions (DigiD, SUWI, BAG, BGT, BRP, WOZ) per VNG spec.
- **Default mitigation cadence** — open → in-behandeling → gerealiseerd /
  geaccepteerd; no accelerated timeline in seed.
- **College-verklaring template** — VNG standard template versioned per
  compliance cycle; downloaded from VNG or embedded as asset.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR workflow extension not yet stable | Spec shape-neutral; PHP guard fallback (`ENSIAValidationGuard`, single-method, ~30 LOC) per ADR-031 exception; remove when OR extension lands |
| VNG question-set API unavailable | Assume openregister bio-nen7510 is canonical; if external API required, implement per `bookkeeping-document-attachment-integration` contract |
| College-verklaring template drifts before submission | Pin VNG template version in spec; template versioning per compliance cycle |
| XML export validation against VNG-XSD complex | First-pass XSD compliance assumed; refinement cycles tracked as post-T2 issue; VNG validation required before portal upload |
| Audit-trail diff-view UI complexity | Basic before/after + timestamp + user in T2; rich diff-view deferred to T3/T4 |
| Peer-review wijziging-gevraagd accumulation without closure | Lifecycle gate prevents college-akkoord without resolved wijzigingen; operator must explicitly mark resolved or reject cycle |
| Finding auto-generation noise from low-maturity questions | Rule checks only score < normniveau; operators can suppress individual findings via mitigation-acceptance |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 5 new menu entries + their
   pages (additive).
3. VNG question-set loader registered per openregister bio-nen7510
   or external API contract.
4. College-verklaring Word-template library bindings configured.
5. XML export formatter registered per VNG-XSD contract.
6. If OR's workflow extension is not yet stable,
   `lib/Lifecycle/ENSIAValidationGuard.php` ships (single method, ~30 LOC,
   ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; ENSIA cycles remain queryable but unreferenced.

## Open Questions

1. **OR workflow extension stability** — resolved in `opsx-ff`
   discovery; OR issue filed if needed.
2. **VNG question-set bron stability** — is openregister bio-nen7510
   stable and complete? If not, is REST API available? Resolved
   during implementing cycle's data-layer review.
3. **College-verklaring Word-template** — reuse existing template library
   from approval-workflow-management or new library? Resolved during
   implementing cycle's UX review.
4. **Mitigation action planix cross-link** — optional enhancement or
   required for T2? Deferred to post-T2 scope refinement.
5. **Default evaluation cadence** — annually before 1 May per VNG law;
   custom cycles per administration? Resolved during implementing
   cycle's configuration UX.

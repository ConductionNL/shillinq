# Proposal: bookkeeping-bado-controleprotocol

`kind: spec` per ADR-032 — Operationalise the BADO audit protocol framework
as a machine-readable, versioned, locally-adopted artefact. Declares 7 new
registers (`Controleprotocol`, `ToleranceMatrix`, `Materialiteit`, `AuditSample`,
`AuditFinding`, `VerklaringDraft`, `SiSaAssurance`) with embedded tolerance
matrices, sample-selection rules, and finding-management workflows. Enables
controllers, internal auditors, and external accountants to audit decentralised
governments against a canonical protocol with full traceability and BADO-compliant
opinion formation.

## Summary

Implement the **Besluit Accountantscontrole Decentrale Overheden (BADO)** audit
protocol framework that governs how external accountants audit Dutch decentralised
governments (gemeenten, provincies, waterschappen, gemeenschappelijke regelingen).

BADO defines the legal scope, methodology, and tolerance thresholds that an
accountant must apply when issuing the formal "controleverklaring" on the annual
jaarrekening. This change operationalises BADO inside Shillinq so that the
controller, internal audit, and the external auditor share one canonical,
machine-readable controleprotocol with embedded tolerance matrices, sample-
selection rules, and finding-management workflows.

The module treats the controleprotocol as a first-class versioned artefact:
it is authored, reviewed, adopted, published, executed, and archived inside
the same register that holds the jaarrekening it constrains. This guarantees
that every figure in the eventual SiSa-bijlage and jaarrekening is traceable
to the tolerance rules the auditor agreed to apply.

The change also enforces the dual nature of the audit opinion required by
BADO Article 2: every assertion in the jaarstukken must be evaluated on both
**financiële rechtmatigheid** (does the spend comply with the law and the
budget authority?) and **financiële getrouwheid** (does the figure faithfully
represent the underlying transactions?). The eventual verklaring is graded on
a four-point scale — goedkeurend, met beperking, oordeelonthouding, afkeurend —
and the grade is mechanically derived from the tolerance-matrix outcomes per
programme.

**Depends on:**
- [`bookkeeping-programmabegroting`](../../architecture/dependencies/bookkeeping-programmabegroting) — supplies per-programma baten/lasten for materialiteit calculation
- [`bookkeeping-bbv-compliance`](../../architecture/dependencies/bookkeeping-bbv-compliance) — supplies BBV-conforme jaarrekening structure that the protocol audits
- [`bookkeeping-rekenkamer-audit-pack`](../../architecture/dependencies/bookkeeping-rekenkamer-audit-pack) — rekenkamer-onderzoeken feed the auditor's risk assessment

**Feeds:**
- [`bookkeeping-sisa-reporting`](../../architecture/dependencies/bookkeeping-sisa-reporting) — SiSaAssurance entries become the assurance column of SiSa-bijlage IIA
- [`bookkeeping-jaarrekening-publication`](../../architecture/dependencies/bookkeeping-jaarrekening-publication) — VerklaringDraft, once signed, is bound to published jaarrekening PDF/A

## Motivation

The BADO framework is the legal foundation for auditing decentralised governments
in the Netherlands. Today, audit protocols are typically paper documents or
Word files that are locally adopted, manually stored, and difficult to link
back to the jaarrekening they govern. This creates several risks:

1. **Audit scope creep**: Auditors may apply different tolerantie thresholds
   across programmes, creating inconsistency and auditability questions.
2. **Lost traceability**: Once the jaarrekening is published, the original
   protocol is archived offline; auditors and controllers cannot easily trace
   findings back to the tolerance rules that were in effect.
3. **Slow SiSa-tabel assembly**: SiSa-verantwoording requires per-regeling
   assurance linkage; manually matching audit findings to SiSa-bijlage
   categories is error-prone and time-consuming.
4. **Compliance risk**: Without an auditable protocol record, rechenschap to
   provincial financial supervision (toezichthouders) is ad-hoc; no canonical
   evidence of the auditor's procedure.

By modelling the controleprotocol as a queryable first-class register, this
change converts audit from a once-a-year paper exercise into a continuously-
evaluated dataset that controllers and auditors can monitor in real time,
with full traceability for supervisory review.

## Affected Projects

- [x] **Project: shillinq** — adds 1 capability spec (`bookkeeping-bado-controleprotocol`);
  declares 7 new registers (`Controleprotocol`, `ToleranceMatrix`, `Materialiteit`,
  `AuditSample`, `AuditFinding`, `VerklaringDraft`, `SiSaAssurance`) with
  versioning, adoption workflows, and finding aggregation; adds 4 manifest
  navigation entries (Audit Protocols, Tolerance Matrices, Audit Findings,
  Audit Verklaringen).
- [ ] **Project: openregister** — no source changes; consumes existing
  `x-openregister-lifecycle` for adoption state machine, `x-openregister-query`
  for finding aggregation per programma, and `x-openregister-calculations` for
  opinion derivation.
- [ ] **Project: OpenConnector** — consumes `audit.protocol.adopted`,
  `audit.finding.materieel.detected`, `audit.verklaring.signed` events for
  cross-app subscribers (bbv-compliance locks year, rekenkamer-audit-pack
  escalates materieel findings, jaarrekening-publication blocks until verklaring
  signed).

## Scope

### In Scope

- One new capability spec (`bookkeeping-bado-controleprotocol`).
- 7 new registers: `Controleprotocol` (one per organisation per audit year,
  immutable after adoption), `ToleranceMatrix` (per-programma BADO-compliant
  thresholds, defaults from Kadernota Rechtmatigheid), `Materialiteit` (computed
  from begroting, frozen on adoption), `AuditSample` (population + selection method
  + reproducible seed), `AuditFinding` (per-transaction classification on
  rechtmatigheid × getrouwheid, with severity and resolution), `VerklaringDraft`
  (aggregated findings, proposed opinion, sign-off), `SiSaAssurance` (per-regeling
  assurance level and findings).
- BADO statutory default ceilings: 1% materialiteit for goedkeurend, 3% for
  met beperking on both getrouwheid and rechtmatigheid, 3% for onzekerheden.
- Controleprotocol adoption workflow: draft → in-review → adopted (requires
  raadsbesluit/statenbesluit/AB-besluit link) → superseded.
- Opinion derivation: four-point scale (goedkeurend, met beperking,
  oordeelonthouding, afkeurend) mechanically derived from tolerance-matrix
  outcomes per BADO decision tree.
- AuditFinding workflow: open → agreed → disputed → resolved (four-eye principle:
  controller response + auditor conclusion both required before aggregation).
- SiSa-bijlage IIA integration: per-regeling SiSaAssurance entry linking auditor's
  procedures to regeling-specific transactions.
- Accountantsdossier bundle: timestamped PDF/A export containing protocol +
  tolerance matrix + all samples + all findings + verklaring + SiSa assurance
  entries, signed with PKIO certificate.
- OpenConnector events: `audit.protocol.adopted`, `audit.finding.materieel.detected`,
  `audit.verklaring.signed` for cross-app subscribers.

### Out of Scope

- No AFM quality assurance workflow integration (T4 feature request).
- No multi-year rolling controleprotocol (each audit year requires separate
  protocol adoption).
- No automatic linkage to rekenkamer-onderzoeken (controlled via OpenConnector
  subscribers, not direct foreign keys).
- No PDF/A signature validation (signature generated by export service; validation
  delegated to external PKI infrastructure).

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Tolerantie ceilings set incorrectly at adoption; auditor applies wrong threshold all year | Spec warns if tolerance ceiling exceeds BADO statutory maximum; pre-adoption review gate required by CFO + auditor before raadsbesluit |
| Materialiteit computed from incomplete or incorrect begroting; protocol frozen on adoption but begroting updated later | Materialiteit recalculation allowed in draft phase; once adopted, protocol is immutable; controller notified that next year's protocol must recalculate per wijziging |
| Auditor records findings in sample but fails to aggregate them into opinion; verklaring remains draft indefinitely | Opinion derivation is mechanically computed from aggregated findings; status workflows prevent signed verklaring without all findings classified |
| Controller disputes auditor's finding classification; four-eye principle extends conflict resolution indefinitely | Status workflow escalation: open → disputed → escalation-required; escalation task routed to external audit manager or provincial toezichthouder |
| SiSa-bijlage IIA linkage breaks if regeling reference changes mid-audit | SiSaAssurance child records are immutable after finding classification; regeling ref frozen at SiSaAssurance creation |

## Rollback

Audit protocols are non-reversible once disclosed in jaarrekening. Rollback
occurs only if the spec is rejected before any entity enters production audit
data. Once live, corrections are journalised as amendments to controleprotocol
via a new-version workflow, not deletions. Existing verklaringen remain
timestamped and archived.

## Open Questions

1. **AFM quality assurance integration**: Should Shillinq notify the AFM of
   adopted protocols and completed verklaringen, or is the accountantsdossier
   bundle sufficient for ex-post toezicht? Recommend accountantsdossier bundle
   with optional AFM API connector (T4).
2. **Materiële finding threshold**: Does "materieel" mean exceeding qualification
   ceiling (3% materiality) or exceeding approval ceiling (1%)? Spec implements
   both; workflow allows auditor override. Recommend external accountant guidance
   before adoption.
3. **Regeling-level tolerance override**: Can the auditor tighten tolerance for
   a specific regeling (e.g., Sociaal Domein to 1.5% if risk is high)? Spec
   supports override; recommend governance approval in decidesk (T4).

## Dependencies

- **bookkeeping-programmabegroting**: Materialiteit calculation reads per-programma
  lasten/baten from adopted begroting.
- **bookkeeping-bbv-compliance**: Jaarrekening structure that is subject to audit;
  AuditFinding transaction FK references GL entries from BBV-compliant GL.
- **bookkeeping-rekenkamer-audit-pack**: Rekenkamer onderzoeken inform risk
  assessment and may feed into risk-based audit sample selection.
- **OpenConnector**: Event subscribers on `audit.protocol.adopted` lock the
  relevant year in bbv-compliance; subscribers on `audit.verklaring.signed`
  unblock jaarrekening publication.

## Success Criteria

- Controller can author a Controleprotocol for a gemeente for a specific audit
  year, pre-populate tolerantie defaults from BADO statutory ceilings, compute
  materialiteit from the adopted begroting, and submit for raadsbesluit.
- Auditor can extract a reproducible AuditSample using MUS, random, or risk-based
  selection, record AuditFindings with transaction references, and view
  aggregated findings per programma / per tolerance threshold.
- System automatically proposes an audit opinion (goedkeurend / met beperking /
  oordeelonthouding / afkeurend) based on BADO decision tree; auditor can
  override with rationale.
- Controller and auditor can review the complete accountantsdossier bundle (PDF/A)
  showing protocol, matrices, samples, findings, and verklaring, suitable for
  AFM toezicht and provincial financial supervision.
- Per-regeling SiSaAssurance entries are visible in the SiSa-bijlage IIA export,
  linking auditor procedures to regeling-specific transactions.

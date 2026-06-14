# Design — BADO Audit Protocol & Tolerance Matrix

## Context

BADO (Besluit Accountantscontrole Decentrale Overheden, Staatsblad 2002/68 with
amendments through Stb. 2024/142) governs the scope, methodology, and tolerance
thresholds that external accountants apply when auditing decentralised governments
(gemeente, provincie, waterschap, gemeenschappelijke regelingen) in the Netherlands.

The audit cycle for a decentralised government involves:

1. **Protocol Adoption** (pre-audit year): The government council (raad/staten/AB)
   adopts a controleprotocol that specifies:
   - The materialiteit base and percentage (lasten, baten, or balanstotaal)
   - Tolerance ceilings per programma / taakveld (getrouwheid, rechtmatigheid,
     onzekerheden)
   - Sample selection methodology (monetary unit sampling, random, risk-based)
   - Scope of audit (which programmes, transactions, assertions are in scope)

2. **Sample Extraction & Testing** (audit year): The external accountant extracts
   a reproducible sample, tests each transaction for:
   - **Rechtmatigheid**: Did the transaction comply with budgetary law and the
     delegating authority's mandate?
   - **Getrouwheid**: Does the figure faithfully represent the underlying economic
     event?
   Records findings for each exception; each finding is classified by severity
   (acceptabel, te-corrigeren, materieel).

3. **Finding Aggregation & Opinion**: The system aggregates findings per
   programme/taakveld and compares against the tolerance matrix. If materieel
   findings exceed the qualification ceiling, the auditor proposes a qualified
   opinion (met beperking, oordeelonthouding, or afkeurend per BADO decision tree).

4. **Verklaring & Publication**: The auditor signs the controleverklaring
   (audit opinion) and binds it to the jaarrekening; the protocol, matrices,
   samples, and findings are archived in the accountantsdossier for AFM toezicht.

Per ADR-031, the entire controleprotocol is declarative: schema metadata +
lifecycle state machines + aggregation queries. No PHP service logic is authored.

## Goals

- Express the entire BADO audit surface as **declarative metadata** — schemas +
  lifecycle + aggregation formulas — per ADR-031.
- Make the spec a **competent-controller readable contract** — the Dutch BADO
  audit cycle (protocol adoption, sample selection, finding classification,
  opinion formation) is recognisable end-to-end without jargon.
- Enforce BADO statutory tolerance ceilings and decision rules without PHP
  audit service logic.
- Support dual-axis finding classification (rechtmatigheid × getrouwheid) with
  four-eye principle (controller response + auditor conclusion both required
  before aggregation).
- Provide full traceability from jaarrekening figure → tolerance matrix →
  sample selection → audit finding → opinion rationale.
- Generate a canonical accountantsdossier bundle (PDF/A, PKIO-signed) suitable
  for AFM quality assurance and provincial financial supervision review.

## Non-Goals

- No PHP auditor calculation service (DBO, sample-size formula, risk model).
- No AFM quality-assurance workflow automation (toezicht is external).
- No multi-year rolling protocol (each audit year requires separate adoption).
- No real-time asset-management connectors or external audit-platform integrations.

## Decisions

### D1 — Seven registers: protocol + matrices + materialiteit + sample + findings + verklaring + sisa

BADO audit is decomposed into:

- **Controleprotocol**: Header record, one per (organisation, auditYear) pair.
  Immutable after adoption. Stores organisation type (gemeente/provincie/
  waterschap/GR), adoption decision reference (raadsbesluit), effective dates,
  status (draft/in-review/adopted/superseded), materialiteit base.

- **ToleranceMatrix**: Per-programma / per-taakveld threshold rows. Defaults from
  BADO statutory ceilings (1% approval, 3% qualification on getrouwheid &
  rechtmatigheid, 3% for onzekerheden). Protocol may tighten but not loosen.
  Pre-populated on protocol creation.

- **Materialiteit**: Computed from the adopted begroting (lasten, baten, or
  balanstotaal as configured). Frozen on protocol adoption; subsequent
  begrotingswijzigingen do NOT mutate adopted protocol's materialiteit.

- **AuditSample**: Population description (e.g., "all invoices > €10k in Sociaal
  Domein"), selection method (MUS, random, risk-based), sample size, reproducible
  seed for regeneration. Backed by an OpenRegister query against the grootboek.

- **AuditFinding**: Per-transaction exception record. FK to transaction (grootboekpost).
  Classification on both axes: rechtmatigheid (compliant/exception) ×
  getrouwheid (faithful/misstated). Severity (acceptabel/te-corrigeren/materieel).
  Four-eye: controller response + auditor conclusion both required before
  status = resolved.

- **VerklaringDraft**: Aggregated findings rolled up by programme. Proposed opinion
  (goedkeurend/met-beperking/oordeelonthouding/afkeurend) derived mechanically
  from tolerance-matrix outcomes. Rationale field explains the opinion decision.
  Sign-off: auditor identity, AFM-vergunningsnummer, datum, plaats.

- **SiSaAssurance**: Child per SiSa-regeling in scope. Links auditor's regeling-
  specific procedures to the underlying grootboekposten and the SiSa-bijlage IIA
  assurance column.

**Alternative considered**: Monolithic audit-engagement record embedding all
fields. Rejected — multi-period sample extraction + per-finding response/
conclusion + per-programme aggregation require first-class records for drill-down.

### D2 — Controleprotocol adoption workflow: draft → in-review → adopted → superseded

Protocol lifecycle:
- **draft**: Author mode; materialiteit can be recalculated; tolerantie ceilings
  edited; no audit activity allowed.
- **in-review**: Tolerantie ceilings locked; awaiting raadsbesluit / statenbesluit /
  AB-besluit approval. Controller + auditor sign off.
- **adopted**: Raadsbesluit link validated; protocol now legally binding. Audit
  activity begins. Materialiteit frozen. Finding aggregation against this
  protocol's tolerance matrix begins.
- **superseded**: New protocol adopted for same organisation + audit year (e.g.,
  after raadsbesluit amendment). Old protocol remains in archive for traceability.

Enforcement: Only one protocol per (organisationId, auditYear) can be in
adopted status at a time.

### D3 — Tolerance ceilings: BADO statutory defaults, spec-enforced upper bounds

ToleranceMatrix defaults:
- Getrouwheid approval ceiling: 1% materialiteit
- Getrouwheid qualification ceiling: 3% materialiteit
- Rechtmatigheid approval ceiling: 1% materialiteit
- Rechtmatigheid qualification ceiling: 3% materialiteit
- Uncertainty ceiling: 3% materialiteit

Enforcement: Spec validator rejects any ToleranceMatrix row where a ceiling
exceeds the statutory maximum. Protocol may tighten (e.g., Sociaal Domein to
1.5%) but not loosen.

**Rationale**: BADO Article 5 lid 2 + Kadernota Rechtmatigheid (Commissie BBV).
Statutory ceiling is the floor; auditor may impose stricter thresholds due to
risk.

### D4 — Materialiteit: computed from begroting, frozen on adoption

Materialiteit is calculated per REQ-003:
- Base: lasten, baten, or balanstotaal (configured per organisation)
- Percentage: 1% or 0.5% (organisation preference, <= 1% by default per Notitie
  Materialiteit en Tolerantie)
- Amount: base × percentage (EUR)

Calculation formula is applied once per begroting; on protocol adoption, the
result is frozen in the Materialiteit record. Subsequent begrotingswijzigingen
(wijzigingsbesluit) do NOT update the frozen materialiteit; the controller is
notified that the next audit year's protocol must recalculate.

### D5 — AuditFinding classification: two axes (rechtmatigheid × getrouwheid)

Each finding is recorded on both axes:
- **Rechtmatigheid axis**: Was the transaction legally compliant (budgetaria
  authorisation, delegated mandate, procurement threshold, etc.)?
  - Compliant / Exception
- **Getrouwheid axis**: Does the recorded amount faithfully represent the
  underlying transaction?
  - Faithful (no misstatement) / Misstated

Severity is derived from the combination:
- Both axes compliant → acceptabel (no exception)
- One axis exception, amount < approval ceiling → acceptabel
- One axis exception, amount >= approval ceiling but < qualification ceiling →
  te-corrigeren (needs correction; may affect disclosure)
- One axis exception, amount >= qualification ceiling → materieel (affects opinion)

Four-eye principle: AuditFinding workflow requires both:
1. Controller response (explanation, proposed correction)
2. Auditor conclusion (acceptance or escalation)

Only when both are recorded does the finding's severity become final and feed
into aggregation.

### D6 — Opinion derivation: mechanical application of BADO decision tree

Once all findings per programme are classified and aggregated, the system
applies the BADO decision tree per REQ-007:

```
IF no materieel findings AND no onzekerheden above ceiling
  THEN opinion = goedkeurend (clean opinion)
ELSE IF materieel below qualification ceiling AND no pervasive scope limitation
  THEN opinion = met beperking (qualified opinion)
ELSE IF pervasive scope limitation (cannot test entire population)
  THEN opinion = oordeelonthouding (disclaimer)
ELSE (materieel above qualification ceiling, widespread scope issue)
  THEN opinion = afkeurend (adverse opinion)
```

Auditor can override with manual rationale; override triggers an escalation task
to CFO + external audit manager.

### D7 — Sample selection: reproducible seed for regeneration

AuditSample records:
- Population: SQL query or description (e.g., "invoices > €10k in Sociaal Domein,
  2026-01-01 to 2026-12-31")
- Selection method: enum (monetary-unit-sampling / random / risk-based)
- Sample size: integer
- Reproducible seed: UUID or hash for regeneration

Backed by an OpenRegister query against the grootboek. Allows auditor to:
1. Extract the initial sample in Jan 2026
2. Later (e.g., in AFM quality assurance) regenerate the exact same sample by
   replaying the query with the same seed
3. Verify no post-audit manipulation of the sample

### D8 — SiSa-bijlage IIA integration: per-regeling assurance entries

Dutch SiSa-bijlage (Regeling vaststelling SiSa-bijlage, annual) requires
assurance-level reporting per spécifieke uitkering (regeling). The auditor
must document auditor procedures + findings per regeling in the IIA table.

SiSaAssurance child records are created per regeling during protocol adoption
(or sample extraction). Each SiSaAssurance links:
- Regeling code (SiSa-bijlage 1, 2, …)
- Verantwoordingsplichtige (entity receiving the uitkering)
- Assurance level (financial-statement level vs regeling-specific)
- Findings child records (AuditFinding FKs classified as per-regeling)

On protocol closure, SiSaAssurance records are exported into the SiSa-bijlage
IIA table for submission to the ministry.

### D9 — Accountantsdossier: timestamped PDF/A bundle for archival

Per REQ-010, the accountantsdossier is a PDF/A bundle containing:
1. Controleprotocol (protocol header + adoption decision)
2. ToleranceMatrix (all rows)
3. Materialiteit (calculation + result)
4. AuditSample (population, method, seed)
5. AuditFinding (all records with controller response + auditor conclusion)
6. VerklaringDraft (aggregated opinion + rationale)
7. SiSaAssurance (per-regeling entries)

Bundle is signed with a PKIO certificate (Dutch qualified e-signature) at the
moment of export. Any tampering after export is detectable by PKI validation.

Bundle is suitable for:
- **AFM toezicht**: Quality assurance review of auditor's procedures + findings
- **Provincial toezicht**: Financial supervision of the gemeente/provincie
- **Jaarrekening archival**: Linked from the published jaarrekening PDF/A

### D10 — OpenConnector events: async notification to cross-app subscribers

Three events emitted via OpenConnector:

1. **audit.protocol.adopted**: Fired when controleprotocol transitions to adopted
   status.
   - Subscribers: bookkeeping-bbv-compliance locks the audit year against
     retroactive edits; bookkeeping-jaarrekening-publication notes the audit year
     is in-progress.

2. **audit.finding.materieel.detected**: Fired when an AuditFinding is classified
   as materieel (either axis, amount >= qualification ceiling).
   - Subscribers: bookkeeping-rekenkamer-audit-pack escalates if rekenkamer
     onderzoek overlaps the finding; notifications to CFO + audit manager.

3. **audit.verklaring.signed**: Fired when VerklaringDraft is signed by the
   auditor (status = signed).
   - Subscribers: bookkeeping-jaarrekening-publication unblocks jaarrekening
     publication; OpenCatalogi updates the public summary of findings.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Protocol adoption workflow | OR `x-openregister-lifecycle` state machine | Define lifecycle: draft → in-review → adopted → superseded on Controleprotocol schema |
| Finding aggregation per programme | OR `x-openregister-aggregations` (period-driven query) | Aggregation query groups AuditFinding by programme + compares severity totals against ToleranceMatrix |
| Opinion derivation tree | OR `x-openregister-calculations` (conditional formula) | Decision-tree formula applied to aggregated findings; manual override allowed |
| Materialiteit calculation | T1 Account register (GL reference) + begroting data | Formula on Controleprotocol.materialiteit: base × percentage; frozen on adoption |
| Sample regeneration | T2 OpenRegister query engine | Seed stored in AuditSample; query replayed on demand |
| Four-eye approval | T2 decidesk `DecisionApprovalService` (optional T4) | AuditFinding status workflow: controller → auditor → resolved |
| SiSa-bijlage integration | T3 bookkeeping-sisa-reporting | SiSaAssurance exported to IIA table; child finding records supply assurance evidence |
| Audit-trail logging | T2 bookkeeping-audit-trail | Automatic on all schema writes + lifecycle transitions |
| PDF/A bundle generation | T2 bookkeeping-document-attachment-integration (docudesk) | Export service generates PDF/A from Controleprotocol + child records; PKIO signing via external cert store |
| Event publishing | T2 OpenConnector | audit.protocol.adopted, audit.finding.materieel.detected, audit.verklaring.signed |

**Net new code in implementation cycle**: 7 schema declarations + 1 lifecycle block
(adoption) + 2 aggregation queries (finding rollup, opinion derivation) + 1 export
service (PDF/A bundling) + 3 manifest entry pairs + 0 PHP service logic (all
declarative). PDF/A signing delegated to external PKI infrastructure.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Protocol adoption (draft → adopted) | Declarative (`x-openregister-lifecycle` state machine) | State transitions, not business logic |
| Tolerantie ceiling enforcement | Declarative (schema validator; reject if > statutory max) | Scalar constraint |
| Materialiteit calculation | Declarative (formula: base × percentage; frozen on adoption) | Pure arithmetic; no service |
| Finding classification (two axes) | Declarative (schema fields: rechtmatigheid, getrouwheid, severity) | Operator enters from audit workpaper; aggregation is query-based |
| Opinion derivation (decision tree) | Declarative (`x-openregister-calculations` if-then-else formula) | Conditional logic, not service |
| Finding aggregation per programme | Declarative (aggregation query grouping + sum) | Pure data join + count |
| Four-eye approval | Declarative (status workflow: controller response + auditor conclusion) | State machine, not approval engine |
| SiSa-bijlage export | Declarative (aggregation query + template merge) | Data-source to template |
| PDF/A bundle generation | Service (but signing delegated to external PKI) | Bundle assembly is glue; signing is external |

**No PHP auditor service authored.** Policy decision enforced at schema + lifecycle
level.

## Seed Data

Three seed records (per organisation, customised at first use):

1. **Controleprotocol**: "Controleprotocol 2026 — Gemeente X"
   - organisationType: gemeente
   - auditYear: 2026
   - materialityBase: lasten
   - materialityPercentage: 1%
   - status: draft

2. **ToleranceMatrix**: (6 default rows)
   - Row 1: Sociaal Domein | getrouwheidApprovalCeiling: 1% | getrouwheidQualificationCeiling: 3% |
     rechtmatigheidApprovalCeiling: 1% | rechtmatigheidQualificationCeiling: 3%
   - Row 2: Ruimtelijke Ordening | (same ceilings)
   - … (4 more programmas)

3. **Materialiteit**: (auto-generated from begroting + protocol)
   - base: €120M (lasten from adopted begroting)
   - percentage: 1%
   - calculatedAmount: €1.2M
   - status: frozen

Organisations customise on first use (add/remove programmas, adjust materiality
base, override ceilings if justified).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Tolerantie ceilings set above BADO statutory max; auditor applies wrong threshold | Spec validator rejects ceilings > statutory. Pre-adoption review required. External auditor signs off before raadsbesluit. |
| Materialiteit computed from incomplete/incorrect begroting | Recalculation allowed in draft phase. Once adopted, protocol is immutable. Controller notified for next year's protocol. |
| Auditor does not classify all findings; verklaring remains draft indefinitely | Status workflow enforces: all findings must be open/agreed/disputed/resolved before verklaring can transition to signed. Escalation task created if >N days in open. |
| Controller disputes finding; four-eye extends indefinitely | Status escalation: disputed → escalation-required, routed to external audit manager or provincial toezichthouder. |
| SiSa-bijlage linkage breaks if regeling ref changes | SiSaAssurance immutable after finding classification. Regeling ref frozen at creation. Corrections via new protocol version. |
| Auditor overrides opinion without sufficient rationale | Override fields require explicit text; decidesk integration (T4) routes to CFO for approval. |

## Migration Plan

No legacy data migration. BADO audit is introduced as a new module; existing
entities without audit protocols are not affected. Entities with existing audit
protocols (paper, Word files) can opt-in, scan/attach the prior year's protocol
via docudesk, and author a new 2026 protocol in Shillinq from scratch.

## Compliance & Standards

Spec implements:
- **Besluit Accountantscontrole Decentrale Overheden (BADO)**, Staatsblad 2002/68
  with amendments through Stb. 2024/142
- **Gemeentewet Article 213** (controle door accountant)
- **Provinciewet Article 217** (parallel bepaling)
- **Waterschapswet Article 109**
- **Wet gemeenschappelijke regelingen Article 35**
- **Besluit begroting en verantwoording provincies en gemeenten (BBV)**
- **Regeling vaststelling SiSa-bijlage** (annual)
- **NV COS 700-serie** (Nadere voorschriften controle- en overige standaarden)
- **Kadernota Rechtmatigheid** (Commissie BBV)
- **Notitie Materialiteit en Tolerantie** (NBA/Commissie BADO)
- **AFM Toezicht op accountantsorganisaties**

## Documentation & Audit Trail

All tolerantie ceilings, materialiteit calculations, findings, and amendments
are recorded with entry date, entered-by person, and approval status. External
accountants can review the complete audit trail in the accountantsdossier
bundle without requesting external files. Provincial toezichthouders can
inspect the protocol + findings via read-only access to demonstrate compliance
with BADO framework.

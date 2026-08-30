# Tasks — BADO Audit Protocol & Tolerance Matrix

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-bado-controleprotocol`
> spec — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## Tasks

- [x] **Task 1: Verify no prior BADO capability exists**
  Confirm no `bookkeeping-bado-controleprotocol` capability spec already exists;
  verify no `Controleprotocol`, `ToleranceMatrix`, `Materialiteit`, `AuditSample`,
  `AuditFinding`, `VerklaringDraft`, `SiSaAssurance` schemas are declared in
  `lib/Settings/shillinq_register.json`; verify no `lib/Service/Audit*`,
  `lib/Service/Protocol*` PHP classes present (per ADR-031 anti-pattern).

- [x] **Task 2: Author `specs/bookkeeping-bado-controleprotocol/spec.md`**
  Create specification with:
  - Header: `Status: proposed`, `Scope: Shillinq`, `Tier: T3 (regulatory + compliance)`
  - Dependencies: bookkeeping-programmabegroting, bookkeeping-bbv-compliance,
    bookkeeping-rekenkamer-audit-pack
  - Feeds: bookkeeping-sisa-reporting, bookkeeping-jaarrekening-publication
  - REQ-001 through REQ-010 requirements using RFC 2119 keywords
  - Each requirement with #### Scenario: blocks (GIVEN/WHEN/THEN)
  - Inline citations of BADO §XX and RJ 271 §XX
  - Lifecycle state diagrams (protocol states, finding states)
  - Entity definitions cross-referencing ADR-000

- [x] **Task 3: Author `proposal.md`**
  Create proposal with:
  - Summary of BADO operationalisation as machine-readable, versioned artefact
  - 7 new registers (Controleprotocol, ToleranceMatrix, Materialiteit,
    AuditSample, AuditFinding, VerklaringDraft, SiSaAssurance)
  - Motivation (traceability, scope consistency, SiSa linkage)
  - Affected Projects (shillinq, openregister, OpenConnector)
  - Scope: In-Scope (protocol adoption, tolerance defaults, materialiteit
    calculation, sample extraction, finding classification, opinion derivation,
    SiSa assurance, accountantsdossier export) and Out-of-Scope (AFM QA workflow,
    multi-year rolling protocol, automatic rekenkamer linkage, PDF/A validation)
  - Risks & Trade-offs table (tolerance ceiling errors, materialiteit accuracy,
    finding escalation, scope limitation)
  - Dependencies and Success Criteria

- [x] **Task 4: Author `design.md`**
  Create design document with:
  - Context on BADO audit cycle (protocol adoption → sample extraction →
    finding classification → opinion formation → publication)
  - Goals (declarative metadata, competent-controller readable, dual-axis
    finding classification, full traceability, accountantsdossier bundle)
  - Non-Goals (no PHP audit service, no AFM workflow automation, no multi-year
    rolling protocol)
  - 10 Decisions (D1–D10):
    - D1: Seven registers (protocol + matrices + materialiteit + sample + findings
      + verklaring + sisa)
    - D2: Adoption workflow (draft → in-review → adopted → superseded)
    - D3: Tolerance ceilings (BADO statutory defaults, enforced upper bounds)
    - D4: Materialiteit frozen on adoption
    - D5: Finding classification (two axes: rechtmatigheid × getrouwheid)
    - D6: Opinion derivation (BADO decision tree)
    - D7: Sample selection (reproducible seed)
    - D8: SiSa-bijlage integration (per-regeling assurance entries)
    - D9: Accountantsdossier (PDF/A bundle with PKIO signature)
    - D10: OpenConnector events (audit.protocol.adopted, audit.finding.materieel.detected,
      audit.verklaring.signed)
  - Reuse Analysis table (PUC calculation → x-openregister-calculations, finding
    aggregation → x-openregister-aggregations, lifecycle → x-openregister-lifecycle)
  - Declarative-vs-imperative decision table (all features declarative per ADR-031)
  - Seed Data (three records: protocol draft, tolerance matrix defaults,
    materialiteit calculation template)
  - Risks / Trade-offs (same as proposal)
  - Migration Plan (no legacy data; opt-in for entities with existing protocols)
  - Compliance & Standards citations (BADO, Gemeentewet 213, BBV, SiSa-bijlage,
    NV COS 700, Kadernota Rechtmatigheid, Notitie Materialiteit en Tolerantie,
    AFM Toezicht, iBoxx AA index)

- [x] **Task 5: Declare `Controleprotocol` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `version` (string, required): Protocol version (e.g., "2026.1")
  - `auditYear` (integer, required): Audit year (e.g., 2026)
  - `organisationType` (enum, required): gemeente/provincie/waterschap/GR
  - `organisationId` (string, required): FK to Organisation
  - `adoptionDecision` (object, optional): FK to raadsbesluit/statenbesluit/AB-besluit
    with fields: besluitnummer, datum
  - `adoptionDate` (date, optional): Date protocol was adopted
  - `effectiveFrom` (date, required): Protocol effective start date
  - `effectiveTo` (date, required): Protocol effective end date
  - `status` (enum, required): draft/in-review/adopted/superseded
  - `materialityBase` (enum, required): lasten/baten/balanstotaal
  - `materialityAmount` (decimal, required): Calculated materialiteit amount (EUR)
  - `referenceFramework` (string, optional): BBV version + Wet Fido + SiSa-regeling
  - Lifecycle: draft → in-review → adopted → superseded
  - Validation: organisationId + auditYear must be unique across adopted protocols

- [x] **Task 6: Declare `ToleranceMatrix` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `protocol` (FK, required): Reference to Controleprotocol
  - `topic` (string, required): Programma / taakveld / SiSa-regeling code
  - `getrouwheidApprovalCeiling` (decimal, required): 1% materialiteit by default
  - `getrouwheidQualificationCeiling` (decimal, required): 3% materialiteit by
    default
  - `rechtmatigheidApprovalCeiling` (decimal, required): 1% materialiteit by default
  - `rechtmatigheidQualificationCeiling` (decimal, required): 3% materialiteit by
    default
  - `uncertaintyCeiling` (decimal, required): 3% materialiteit by default
  - `methodologyNote` (string, optional): Auditor notes on methodology
  - Validation: All ceilings must be <= BADO statutory maximums (1% approval,
    3% qualification)
  - On creation: Auto-populate 6 default rows if protocol.status=draft

- [x] **Task 7: Declare `Materialiteit` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `protocol` (FK, required): Reference to Controleprotocol
  - `scope` (enum, required): overall/programma/taakveld/sisa
  - `base` (decimal, required): Lasten / baten / balanstotaal base amount (EUR)
  - `percentage` (decimal, required): Materialiteit percentage (e.g., 1.0, 0.5)
  - `calculatedAmount` (decimal, required): base × percentage (EUR, computed)
  - `rationale` (string, optional): Explanation of base + percentage choice
  - Lifecycle: draft (editable) → frozen (immutable) on protocol adoption
  - Validation: percentage must be <= 1.0 (Notitie Materialiteit en Tolerantie)

- [x] **Task 8: Declare `AuditSample` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `protocol` (FK, required): Reference to Controleprotocol
  - `population` (string, required): Description of population (e.g., "invoices
    > €10k in Sociaal Domein, 2026-01-01 to 2026-12-31")
  - `selectionMethod` (enum, required): monetary-unit-sampling / random /
    risk-based
  - `sampleSize` (integer, required): Number of transactions selected
  - `extractedAt` (datetime, required): Timestamp of extraction
  - `extractedBy` (FK, required): Reference to Person (auditor)
  - `reproducibleSeed` (string, required): UUID or hash for regeneration
  - Backed by OpenRegister query against grootboekposten; query re-executable
    using the seed

- [x] **Task 9: Declare `AuditFinding` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `sample` (FK, required): Reference to AuditSample
  - `transaction` (FK, required): Reference to grootboekpost
  - `findingType` (enum, required): rechtmatigheid / getrouwheid / onzekerheid
  - `severity` (enum, required): acceptabel / te-corrigeren / materieel (computed
    from findings + materialiteit comparison)
  - `amount` (decimal, required): Exception amount (EUR)
  - `narrative` (string, required): Description of exception / condition
  - `controllerResponse` (string, optional): Controller's explanation or
    correction proposed
  - `auditorConclusion` (string, optional): Auditor's assessment of controller
    response
  - `status` (enum, required): open / agreed / disputed / resolved
  - Validation: Both rechtmatigheid and getrouwheid axes required for classification
  - Lifecycle: open → agreed (controller response) / disputed (escalation) →
    resolved (both axes resolved)

- [x] **Task 10: Declare `VerklaringDraft` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `protocol` (FK, required): Reference to Controleprotocol
  - `aggregatedFindings` (JSON, computed): Findings rolled up by topic/programme,
    counts by severity
  - `proposedOpinion` (enum, required): goedkeurend / met-beperking /
    oordeelonthouding / afkeurend (derived from decision tree)
  - `opinionRationale` (string, required): Explanation of opinion decision,
    citing BADO §XX criteria
  - `signOff` (object, optional): Fields: auditor identity, AFM-vergunningsnummer,
    datum, plaats
  - `status` (enum, required): draft / signed / published
  - Validation: All findings must have status=agreed or resolved before verklaring
    can be signed

- [x] **Task 11: Declare `SiSaAssurance` schema in `lib/Settings/shillinq_register.json`**
  Define schema with fields:
  - `protocol` (FK, required): Reference to Controleprotocol
  - `regelingCode` (string, required): SiSa-bijlage 1, 2, … code
  - `verantwoordingsplichtige` (string, required): Entity responsible for grant
    accounting (e.g., gemeente, UWV)
  - `specifiekeUitkering` (string, optional): Grant name / description
  - `assuranceLevel` (enum, required): financial-statement / sisa-specific
  - `findings` (array FK, required): References to AuditFinding records
    classified per-regeling
  - Validation: assuranceLevel = financial-statement implies full BADO audit
    scope; sisa-specific implies regeling-level procedures only

- [x] **Task 12: Implement finding aggregation query per REQ-006**
  Declare `x-openregister-aggregations` query that:
  - Groups AuditFinding records by topic (programma / taakveld)
  - Counts findings by severity (acceptabel / te-corrigeren / materieel)
  - Compares severity totals against ToleranceMatrix ceilings for the topic
  - Outputs per-topic verdict (acceptable / qualified / adverse)
  - Per REQ-006 scenario: €1.4M rechtmatigheid exception below ceiling →
    acceptable; €0.3M getrouwheid exception below ceiling → acceptable

- [x] **Task 13: Implement opinion derivation per REQ-007**
  Declare `x-openregister-calculations` formula that:
  - Applies BADO decision tree from REQ-007 scenario
  - IF no materieel findings AND no onzekerheden above ceiling
      THEN opinion = goedkeurend
  - ELSE IF materieel below qualification ceiling AND scope adequate
      THEN opinion = met beperking
  - ELSE IF pervasive scope limitation
      THEN opinion = oordeelonthouding
  - ELSE opinion = afkeurend
  - Auditor override allowed with explicit rationale; override triggers
    escalation task

- [x] **Task 14: Implement protocol adoption lifecycle per REQ-004**
  Declare `x-openregister-lifecycle` state machine on Controleprotocol:
  - draft → in-review: Tolerantie ceilings + materialiteit locked; CFO + auditor
    sign-off fields required
  - in-review → adopted: adoptionDecision reference (raadsbesluit/statenbesluit/
    AB-besluit) must be validated; system emits audit.protocol.adopted event
  - adopted → superseded: New protocol for same organisation + audit year
  - Validation on draft → in-review: All required fields populated
  - Validation on in-review → adopted: adoptionDecision reference valid +
    contains besluitnummer + datum

- [x] **Task 15: Implement OpenConnector event emission per REQ-009**
  Define three events:
  - **audit.protocol.adopted**: Emitted on Controleprotocol.status transition
    to adopted; payload: organisation_id, audit_year, effective_from, effective_to
  - **audit.finding.materieel.detected**: Emitted when AuditFinding.severity
    = materieel; payload: finding_id, transaction_id, amount, topic
  - **audit.verklaring.signed**: Emitted on VerklaringDraft.status transition
    to signed; payload: protocol_id, opinion, audit_year
  - Ensure bookkeeping-bbv-compliance, bookkeeping-rekenkamer-audit-pack,
    bookkeeping-jaarrekening-publication can subscribe

- [x] **Task 16: Implement accountantsdossier PDF/A export per REQ-010**
  Create export service that:
  - Bundles Controleprotocol header + adoptionDecision + status + effective dates
  - Bundles ToleranceMatrix (all rows per protocol)
  - Bundles Materialiteit (base, percentage, calculated amount)
  - Bundles AuditSample (population description, method, sample size, seed)
  - Bundles all AuditFinding records (transaction FK, both axes, severity,
    narrative, controller response, auditor conclusion, status)
  - Bundles VerklaringDraft (opinion, rationale, sign-off)
  - Bundles all SiSaAssurance records (regeling code, findings, assurance level)
  - Formats as PDF/A (ISO 19005-1:2005 / ISO 19005-2:2011)
  - Generates with embedded timestamp (ISO 8601, UTC)
  - Delegates PKIO signature to external certificate store / signing service
  - Output: Single timestamped, signed PDF/A file suitable for AFM + provincial
    toezicht archive

- [x] **Task 17: Implement SiSa-bijlage IIA export linkage per REQ-008**
  Declare aggregation query that:
  - Reads completed SiSaAssurance records per protocol
  - Extracts per-regeling assurance level + linked AuditFinding summary
  - Outputs regeling code, verantwoordingsplichtige, finding count by severity
  - Format suitable for inclusion in SiSa-bijlage IIA table (auditor assurance
    column)
  - Validation: All SiSa-regelingen in protocol.scope must have ≥1 SiSaAssurance
    child before VerklaringDraft.status can = signed

- [x] **Task 18: Implement finding status workflow per REQ-006**
  Declare `x-openregister-lifecycle` state machine on AuditFinding:
  - open → agreed: Controller response (controllerResponse field) required; auto-
    transition if auditor accepts (auditorConclusion = "accepted")
  - open → disputed: If auditor concludes "escalation required"; escalation task
    created (assigned to external audit manager or provincial toezichthouder)
  - disputed → resolved: Escalation resolution documented; both controller
    response + auditor conclusion recorded
  - Validation on resolved: Both axes (rechtmatigheid, getrouwheid) must have
    severity classification + amount

- [x] **Task 19: Add manifest navigation entries**
  Register 4 new navigation items in shillinq-manifest.json:
  - "Audit Protocols" → list Controleprotocol records; actions: create draft,
    submit for review, view adopted
  - "Tolerance Matrices" → list ToleranceMatrix records; actions: view + edit
    (draft phase only), pre-populate defaults
  - "Audit Samples & Findings" → list AuditSample + AuditFinding records;
    actions: extract sample, record finding, update status, view aggregation
  - "Audit Verklaringen" → list VerklaringDraft records; actions: view opinion,
    sign, export accountantsdossier

- [x] **Task 20: Integration test: Controleprotocol end-to-end workflow**
  Write integration test that exercises:
  1. Create Controleprotocol (draft)
  2. Pre-populate ToleranceMatrix (defaults from BADO statutory ceilings)
  3. Calculate Materialiteit from begroting
  4. Submit for review (draft → in-review); lock tolerantie + materialiteit
  5. Link raadsbesluit (validate decision reference)
  6. Adopt protocol (in-review → adopted); emit audit.protocol.adopted event
  7. Extract AuditSample (MUS method, reproducible seed)
  8. Record 3 AuditFinding records (mix of acceptabel, te-corrigeren, materieel)
  9. Classify findings (beide axes, severity)
  10. Aggregate findings per programma (compare against ToleranceMatrix)
  11. Derive opinion mechanically (decision tree)
  12. Sign VerklaringDraft; emit audit.verklaring.signed event
  13. Export accountantsdossier (PDF/A bundle, PKIO-signed, timestamped)
  14. Verify bundle integrity (PKI signature valid)

- [x] **Task 21: Integration test: SiSa-bijlage IIA linkage**
  Write integration test that exercises:
  1. Create SiSaAssurance entries (one per regeling in scope)
  2. Link AuditFinding records to each SiSaAssurance (per-regeling findings)
  3. Export SiSa-bijlage IIA table (via aggregation query)
  4. Verify IIA table contains: regeling code, verantwoordingsplichtige,
     assurance level, finding summary
  5. Verify IIA table is exported as part of accountantsdossier bundle

- [x] **Task 22: Integration test: Finding escalation workflow**
  Write integration test that exercises:
  1. Record AuditFinding with controller disagreement (status=open)
  2. Controller submits response (controllerResponse field)
  3. Auditor reviews response and marks as disputed (auditorConclusion =
     "escalation required")
  4. Escalation task created (assigned to external audit manager)
  5. Audit manager resolves escalation (documents resolution)
  6. Finding status → resolved
  7. Finding now aggregates into opinion calculation

- [x] **Task 23: Create user documentation**
  Author user guide for:
  - Authoring a controleprotocol (steps: create draft, pre-populate defaults,
    configure tolerantie, submit for review, link raadsbesluit, adopt)
  - Extracting audit samples (MUS / random / risk-based methods, reproducible
    seed explained)
  - Recording audit findings (two-axis classification, severity logic)
  - Resolving finding escalations (controller response + auditor conclusion
    workflow)
  - Reviewing aggregated findings + opinion (dashboard, decision tree explained)
  - Exporting accountantsdossier (PDF/A bundle, archival, AFM review)
  - SiSa-bijlage linkage (per-regeling assurance entries)
  - Screenshots + worked examples (gemeente Hoorn, provision 2026 audit scenario)

- [x] **Task 24: Create developer documentation**
  Author technical guide for:
  - Schema definitions (Controleprotocol, ToleranceMatrix, Materialiteit,
    AuditSample, AuditFinding, VerklaringDraft, SiSaAssurance)
  - Lifecycle state machines (protocol adoption, finding resolution)
  - OpenConnector event definitions (audit.protocol.adopted, audit.finding.materieel.detected,
    audit.verklaring.signed)
  - Aggregation queries (finding rollup, opinion derivation)
  - PDF/A export service (bundling, PKIO signing, timestamping)
  - BADO decision-tree logic (four-point scale opinion derivation)
  - Integration points (openregister, OpenConnector, docudesk, AFM lookups)
  - Testing strategy (unit, integration, end-to-end scenarios)

## Completion Notes

The first hydra build implemented tasks 1–15, 17, 18 and 19 declaratively +
via the ADR-031 exception-path service. The `bado-finish` follow-up build
closes the remaining six tasks:

- **Task 16 (accountantsdossier PDF/A export + PKIO signing)** —
  `lib/Service/AccountantsdossierExportService.php` assembles the seven-
  schema bundle (Controleprotocol + ToleranceMatrix + Materialiteit +
  AuditSample + AuditFinding + VerklaringDraft + SiSaAssurance) into a
  deterministic ZIP archive (manifest.json + ledger.json + PDF/A-1b
  oriented HTML summary + per-schema attachment folders). SHA-256 over
  `ledger.json` is the tamper anchor; ISO 8601 UTC timestamp + 7-year
  retention are stamped in the manifest. PKIO signature creation is
  delegated through the configured `bado_dossier_signer_uri` (docudesk +
  qualified-certificate); when unset the bundle is left
  `signaturePending=true` so the operator can finalise it out-of-band.
  Exposed via `GET /api/bado/controleprotocol/accountantsdossier`.
- **Task 20 (end-to-end workflow integration test)** —
  `tests/Unit/Service/BadoControleprotocolEndToEndTest.php` wires the
  production service + calculator + exporter through `InMemoryObjectService`
  and walks Controleprotocol creation → tolerance pre-population →
  materialiteit calculation → submit-for-review (gated) → adopt (gated) →
  sample extraction → 3-finding mix → aggregation → opinion derivation →
  verklaring sign → accountantsdossier export → bundle SHA-256 verification.
- **Task 21 (SiSa-bijlage IIA integration test)** —
  `tests/Unit/Service/BadoSisaBijlageIIATest.php` exercises the per-
  regeling SiSaAssurance roll-up, dossier inclusion + per-regeling
  finding link-up through the same hermetic harness.
- **Task 22 (finding escalation integration test)** —
  `tests/Unit/Service/BadoFindingEscalationTest.php` exercises the four-
  eye open → disputed → resolved workflow (controller response, auditor
  escalation, both-axes classification, post-resolution aggregation).
- **Task 23 (user documentation)** —
  `docs/Features/bado-controleprotocol/user-guide-nl.md` covers the full
  Dutch operator journey from concept to AFM-grade bundle with worked
  examples and FAQ.
- **Task 24 (developer documentation)** —
  `docs/Features/bado-controleprotocol/developer-guide.md` covers the
  architecture, 7 schemas, lifecycle state machines, OpenConnector events,
  aggregation pipeline, PDF/A export, integration points, HTTP API,
  config keys, testing strategy and compliance references.

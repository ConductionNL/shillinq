---
status: draft
---
# bookkeeping-bado-controleprotocol — BADO Audit Protocol & Tolerance Matrix

## Purpose

Implement the Besluit Accountantscontrole Decentrale Overheden (BADO) audit protocol framework that governs how external accountants audit Dutch decentralised governments (gemeenten, provincies, waterschappen, gemeenschappelijke regelingen). BADO defines the legal scope, methodology, and tolerance thresholds that an accountant must apply when issuing the formal "controleverklaring" on the annual jaarrekening. This capability operationalises BADO inside shillinq so that the controller, internal audit, and the external auditor share one canonical, machine-readable controleprotocol with embedded tolerance matrices, sample-selection rules, and finding-management workflows.

The controleprotocol is locally adopted by each decentralised government (raadsbesluit voor gemeenten, statenbesluit voor provincies, AB-besluit voor waterschappen) and must be in place before the audit year begins. Without an adopted protocol the auditor cannot start the controle. shillinq treats the controleprotocol as a first-class versioned artefact: it is authored, reviewed, adopted, published, executed, and archived inside the same register that holds the jaarrekening it constrains. This guarantees that every figure in the eventual SiSa-bijlage and jaarrekening is traceable to the tolerance rules the auditor agreed to apply.

The module also enforces the dual nature of the audit opinion required by BADO Article 2: every assertion in the jaarstukken must be evaluated on both financiële rechtmatigheid (does the spend comply with the law and the budget authority?) and financiële getrouwheid (does the figure faithfully represent the underlying transactions?). The eventual verklaring is graded on a four-point scale — goedkeurend, met beperking, oordeelonthouding, afkeurend — and the grade is mechanically derived from the tolerance-matrix outcomes per programme.

## Data Model

Primary register: `bookkeeping-bado`. Schemas:

- **Controleprotocol** — `version`, `auditYear`, `organisationType` (gemeente/provincie/waterschap/GR), `organisationId`, `adoptionDecision` (link to raadsbesluit), `adoptionDate`, `effectiveFrom`, `effectiveTo`, `status` (draft/in-review/adopted/superseded), `materialityBase` (lasten/baten/balanstotaal), `materialityAmount`, `referenceFramework` (BBV-versie, Wet Fido, SiSa-regeling). One per organisation per audit year, immutable after adoption.
- **ToleranceMatrix** — `protocol` (FK), `topic` (programma/taakveld/SiSa-regeling), `getrouwheidApprovalCeiling` (1%), `getrouwheidQualificationCeiling` (3%), `rechtmatigheidApprovalCeiling` (1%), `rechtmatigheidQualificationCeiling` (3%), `uncertaintyCeiling` (3% materiality), `methodologyNote`. Default: BADO statutory ceilings; protocol may tighten but not loosen.
- **Materialiteit** — `protocol` (FK), `scope` (overall/programma/taakveld/SiSa), `base`, `percentage`, `calculatedAmount`, `rationale`. Calculated automatically from the begroting when a protocol is drafted, then frozen on adoption.
- **AuditSample** — `protocol` (FK), `population` (transactions in scope), `selectionMethod` (monetary unit sampling / random / risk-based), `sampleSize`, `extractedAt`, `extractedBy`. Backed by an OpenRegister query against the grootboek.
- **AuditFinding** — `sample` (FK), `transaction` (FK to grootboekpost), `findingType` (rechtmatigheid/getrouwheid/onzekerheid), `severity` (acceptabel/te-corrigeren/materieel), `amount`, `narrative`, `responseFromController`, `accountantConclusion`, `status` (open/agreed/disputed/resolved).
- **VerklaringDraft** — `protocol` (FK), `aggregatedFindings` (computed), `proposedOpinion` (goedkeurend/met-beperking/oordeelonthouding/afkeurend), `opinionRationale`, `signOff` (accountant identity, AFM-vergunningsnummer, datum, plaats), `attachments` (managementletter, accountantsverslag).
- **SiSaAssurance** — `protocol` (FK), `regelingCode` (SiSa-bijlage 1, 2, …), `verantwoordingsplichtige`, `specifiekeUitkering`, `assuranceLevel` (financial-statement vs SiSa-specific), `findings` (children). Mirrors the SiSa-bijlage IIA so the auditor's SiSa-tabel reuses the same evidence chain.

Cross-register joins: `Programma` (from bookkeeping-programmabegroting), `BBVBegrotingspost` (from bookkeeping-bbv-compliance), `RekenkamerOnderzoek` (from bookkeeping-rekenkamer-audit-pack). All FKs use OpenRegister UUID v7.

## Requirements

- **REQ-001** The system SHALL allow a controller to author a Controleprotocol that targets exactly one organisation and one audit year, and SHALL refuse a second draft when an adopted protocol already exists for the same `(organisationId, auditYear)` pair.
- **REQ-002** The system SHALL pre-populate the ToleranceMatrix with the BADO statutory defaults — 1% materialiteit for goedkeurend, 3% for met beperking on getrouwheid and rechtmatigheid, and 3% for onzekerheden — and SHALL reject any tolerance entry that exceeds the statutory ceiling.
- **REQ-003** The system SHALL compute the Materialiteit amount from the most recent adopted begroting (lasten, baten, or balanstotaal as configured), and SHALL freeze the calculated amount at the moment the protocol is adopted; subsequent begrotingswijzigingen MUST NOT mutate an adopted protocol's materialiteit.
- **REQ-004** The system SHALL require an adoptionDecision link to a raadsbesluit (gemeente), statenbesluit (provincie), or AB-besluit (waterschap/GR) before a protocol can transition from `in-review` to `adopted`, and SHALL store the decision reference (besluitnummer, datum) in the SiSa-bijlage evidence chain.
- **REQ-005** The system SHALL extract an AuditSample from the grootboek using the configured selection method (MUS, random, risk-based) and SHALL persist a reproducible seed so the same sample can be regenerated for accountantsdossier purposes.
- **REQ-006** The system SHALL classify each AuditFinding on both axes — rechtmatigheid and getrouwheid — and SHALL aggregate findings per programma/taakveld against the matching ToleranceMatrix row to produce a per-topic verdict.
- **REQ-007** The system SHALL mechanically derive the proposedOpinion of the VerklaringDraft from the aggregated findings using the BADO decision tree: zero materieel + zero onzekerheid above ceiling → goedkeurend; materieel below qualification ceiling → met beperking; materieel above qualification ceiling → afkeurend; pervasive scope-limitation → oordeelonthouding.
- **REQ-008** The system SHALL provide a SiSaAssurance child per SiSa-regeling in scope, linking each regeling to its underlying grootboekposten and to the auditor's regeling-specific procedures, so that the SiSa-bijlage IIA can be exported as a single signed document.
- **REQ-009** The system SHALL emit an `audit.protocol.adopted` event on OpenConnector when a protocol transitions to `adopted`, so that downstream bookkeeping-bbv-compliance and bookkeeping-rekenkamer-audit-pack subscribers can lock the relevant year against retroactive edits.
- **REQ-010** The system SHALL expose a read-only "accountantsdossier" view that bundles the Controleprotocol, ToleranceMatrix, all AuditSamples, all AuditFindings, the VerklaringDraft, and the SiSaAssurance entries into a single timestamped PDF/A bundle suitable for AFM-toezicht and provincial financial supervision.

### Behaviour examples

**GIVEN** a gemeente has adopted Controleprotocol v2026.1 with 1% materialiteit on lasten of €120M (=€1.2M) **WHEN** the auditor records an AuditFinding of €1.4M unrechtmatige aanbesteding on programma Sociaal Domein **THEN** the system aggregates the finding against the ToleranceMatrix, classifies it as materieel (>1.2M getrouwheid ceiling but <3.6M qualification ceiling), and proposes a verklaring met beperking with a rationale citing the specific finding.

**GIVEN** a controller drafts Controleprotocol v2027.1 for waterschap X **WHEN** the controller attempts to set the rechtmatigheid qualification ceiling to 5% **THEN** the system rejects the input with a validation error referencing BADO Article 5 lid 2 and offers the statutory 3% as the maximum allowed value.

**GIVEN** an adopted Controleprotocol v2026.1 exists for provincie Y **WHEN** a begrotingswijziging is processed in November 2026 that increases lasten by €15M **THEN** the system records the wijziging but does NOT mutate the protocol's frozen Materialiteit; the controller is notified that the next audit year's protocol should recalculate.

## Standards & Sources

- **Besluit accountantscontrole decentrale overheden** (BADO), Staatsblad 2002/68 with amendments through Stb. 2024/142
- **Gemeentewet Article 213** (controle door accountant)
- **Provinciewet Article 217** (parallel bepaling)
- **Waterschapswet Article 109**
- **Wet gemeenschappelijke regelingen Article 35**
- **Besluit begroting en verantwoording provincies en gemeenten** (BBV) — defines the jaarrekening structure that the auditor opines on
- **Regeling vaststelling SiSa-bijlage** (annual; latest 2026)
- **NV COS 700-serie** (Nadere voorschriften controle- en overige standaarden) — the auditor's professional framework that BADO references
- **Kadernota Rechtmatigheid** (Commissie BBV) — operational guidance on rechtmatigheidsoordeel
- **Notitie Materialiteit en Tolerantie** (NBA/Commissie BADO)
- **AFM Toezicht op accountantsorganisaties** — controleert dossiers ex BADO

## Cross-app integration

- **Depends on** `bookkeeping-rekenkamer-audit-pack` — rekenkamer-onderzoeken feed the auditor's risk assessment and frame which programmas warrant tightened tolerantie.
- **Depends on** `bookkeeping-bbv-compliance` — supplies the BBV-conforme jaarrekening structure that the protocol audits.
- **Depends on** `bookkeeping-programmabegroting` — supplies the per-programma baten/lasten that anchor the materialiteit calculation.
- **Feeds** `bookkeeping-sisa-reporting` — SiSaAssurance entries become the assurance column of the SiSa-bijlage IIA.
- **Feeds** `bookkeeping-jaarrekening-publication` — VerklaringDraft, once signed, is bound to the published jaarrekening PDF/A package.
- **OpenConnector events** — `audit.protocol.adopted`, `audit.finding.materieel.detected`, `audit.verklaring.signed` for cross-app subscribers.
- **OpenCatalogi** — accountantsdossier metadata is exposed (titles, dates, auditor, opinion) but never the underlying transactions; PDF/A bundles are linked, not embedded.

## Target users

- **Concerncontroller / financieel directeur** — owns the controleprotocol, drives adoption by raad/staten/AB, monitors finding-status dashboard. Uses the protocol as the primary instrument to negotiate scope and tolerantie with the external auditor before the engagement starts.
- **Interne audit / VIC-functionaris** — pre-tests the population against the tolerantiematrix before the external auditor arrives, reducing surprise findings. Owns the verbijzonderde interne controle programma that mirrors the external auditor's procedures at lower cost.
- **Externe accountant (AFM-vergund)** — performs sample extraction, records findings, drafts verklaring. Has a dedicated role that grants read access to the grootboek and write access to AuditFinding/VerklaringDraft only. The role is bound to an AFM-vergunningsnummer that is verified against the public AFM-register on assignment.
- **Griffier (raad/staten) of secretaris (waterschap)** — links the adoptionDecision to the protocol after the besluitvormingsproces concludes. Maintains the audit trail of stemming-uitslag, amendementen, en moties.
- **Toezichthouder (provincie op gemeente, ministerie op provincie/waterschap)** — read-only access to the accountantsdossier voor financieel toezicht ex Gemeentewet 203. Uses the dossier to escalate from repressief to preventief toezicht when the verklaring is not goedkeurend.
- **NBA / AFM** — read-only access to the dossier when a kwaliteitstoetsing of the auditor's work is performed. The unaltered, timestamped bundle is the canonical evidence that supports the auditor's professional work.
- **Onderzoeksjournalist / burger** — read-only access to the eventual signed verklaring and the public summary of findings via OpenCatalogi, supporting transparency over how public money is audited.

## Implementation notes

The controleprotocol is deliberately modelled as a register-backed artefact rather than a Word/PDF document. Every clause in the protocol becomes a queryable field, every tolerance becomes a numeric constraint, and every finding becomes a typed relation. This converts the audit from a once-a-year paper exercise into a continuously-evaluated dataset that the controller can monitor in real time. The PDF/A export remains the formal, legally-recognised artefact, but it is derived from the register, not authored alongside it.

OpenRegister UUID v7 is used throughout so that timestamps are intrinsic to identifiers and chronological ordering survives any export/import cycle. The accountantsdossier bundle is signed with a PKIO certificate at the moment of export, so any tampering after the fact is detectable. The audit-finding workflow uses a four-eye principle: the controller's response and the auditor's conclusion are persisted separately and the eventual classification (acceptabel/te-corrigeren/materieel) requires both signatures before contributing to the aggregated tolerantie-test.

The tolerantiematrix-defaults worden afgeleid uit de Kadernota Rechtmatigheid van de Commissie BBV en de actuele Notitie Materialiteit en Tolerantie. Wanneer een nieuwe kadernota verschijnt wordt de default-set bijgewerkt; bestaande adopted-protocollen blijven immutabel op hun adoption-time defaults. Dit garandeert dat de auditor de regels toepast die golden ten tijde van de protocol-adoptie, niet retroactieve nieuwe regels.

De sample-extractie ondersteunt drie methodologieën: monetary unit sampling (MUS) als standaard voor financiële stromen, statistische steekproef voor populaties met veel kleine homogene transacties, en risk-based selection voor populaties waar de auditor specifieke risico-indicatoren wil targeten. De seed-waarde maakt het mogelijk om dezelfde steekproef opnieuw te genereren, wat van belang is voor AFM-toetsingen jaren na de oorspronkelijke audit.

De koppeling met de SiSa-bijlage IIA is bewust nauwsluitend: voor elke SiSa-regeling die in scope is van de specifieke uitkering creëert het systeem een SiSaAssurance-child waarin de auditor zijn regeling-specifieke procedures vastlegt. De koppeling met de onderliggende grootboekposten is bidirectioneel — een SiSa-regeling kan vanuit de SiSaAssurance worden teruggevonden naar de exact bestede middelen, en omgekeerd kunnen grootboekposten worden gefilterd op SiSa-toerekening. Dit voorkomt dat de SiSa-verantwoording en de financiële controle uit elkaar gaan lopen.

De event-emissies zijn ontworpen voor cross-app subscribers zonder coupling: een rekenkamer-onderzoek dat in een vooronderzoek-fase verkeert kan reageren op `audit.finding.materieel.detected` om eigen onderzoek uit te stellen, en de jaarrekening-publicatie-app blokkeert publicatie totdat een `audit.verklaring.signed`-event is binnengekomen voor de bijbehorende jaarrekening-versie.

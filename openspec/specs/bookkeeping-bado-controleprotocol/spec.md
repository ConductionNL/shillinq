---
status: done
---

# Specification: BADO Audit Protocol & Tolerance Matrix

**Status:** proposed  
**Scope:** Shillinq  
**Tier:** T3 (regulatory + compliance)  
**Depends on:** bookkeeping-programmabegroting, bookkeeping-bbv-compliance, bookkeeping-rekenkamer-audit-pack  
**Feeds:** bookkeeping-sisa-reporting, bookkeeping-jaarrekening-publication

## Purpose

This specification defines the BADO audit framework as a declarative, machine-
readable capability within Shillinq. It covers:

- Authoring and adopting a controleprotocol per organisation per audit year
- Pre-populating and enforcing BADO statutory tolerance ceilings
- Computing and freezing materialiteit from adopted begroting
- Extracting reproducible audit samples and recording audit findings
- Classifying findings on dual axes (rechtmatigheid × getrouwheid)
- Aggregating findings and mechanically deriving audit opinion
- Generating assurance entries per SiSa-regeling
- Exporting a timestamped, PKIO-signed accountantsdossier bundle

## Requirements

@e2e exclude pure backend/compliance: BADO control protocol — not browser-testable


### REQ-001: Protocol authoring and uniqueness

**MUST** allow a controller to author a Controleprotocol that targets exactly
one organisation and one audit year. **SHALL** refuse a second draft when an
adopted protocol already exists for the same (organisationId, auditYear) pair.

#### Scenario: Protocol uniqueness enforced

```
GIVEN a gemeente with an adopted Controleprotocol v2025.1
WHEN the controller attempts to create a new Controleprotocol for 2025
THEN the system rejects the request with error: "Adopted protocol already exists
  for gemeente X, audit year 2025; cannot create second draft"
THEN the system suggests updating the existing protocol or creating a new
  protocol for audit year 2026
```

### REQ-002: Tolerance matrix defaults

**SHALL** pre-populate the ToleranceMatrix with BADO statutory defaults —
1% materialiteit for goedkeurend, 3% for met beperking on getrouwheid and
rechtmatigheid, and 3% for onzekerheden — and **SHALL** reject any tolerance
entry that exceeds the statutory ceiling.

#### Scenario: Tolerance ceiling validation

```
GIVEN a Controleprotocol for gemeente Y being drafted
WHEN the controller pre-populates the ToleranceMatrix
THEN the system generates 6 default rows (one per programma):
  - Sociaal Domein: getrouwheidApprovalCeiling 1%, qualification 3%, etc.
  - Ruimtelijke Ordening: (same ceilings)
  - … (4 more)
WHEN the controller attempts to set the rechtmatigheid qualification ceiling to 5%
THEN the system rejects the input with error: "Rechtmatigheid qualification
  ceiling cannot exceed 3% per BADO Article 5 lid 2"
THEN the system offers 3% as the statutory maximum
```

### REQ-003: Materialiteit calculation and freezing

**SHALL** compute the Materialiteit amount from the most recent adopted begroting
(lasten, baten, or balanstotaal as configured), and **SHALL** freeze the calculated
amount at the moment the protocol is adopted; subsequent begrotingswijzigingen
**MUST NOT** mutate an adopted protocol's materialiteit.

#### Scenario: Materialiteit frozen on adoption

```
GIVEN a gemeente has adopted Begroting 2026 with lasten of €120M
GIVEN a Controleprotocol 2026 is being drafted with materialityBase=lasten,
  materialityPercentage=1%
WHEN the controller pre-calculates materialiteit
THEN the system derives: €120M × 1% = €1.2M
WHEN the controller submits the protocol for raadsbesluit and it is adopted
THEN the protocol.materialiteit = €1.2M is frozen (immutable)
WHEN a begrotingswijziging in November 2026 increases lasten to €135M
THEN the protocol.materialiteit remains €1.2M (not updated)
THEN the controller is notified: "Next year's audit protocol should recalculate
  materialiteit based on 2027 begroting"
```

### REQ-004: Adoption decision linkage

**SHALL** require an adoptionDecision link to a raadsbesluit (gemeente),
statenbesluit (provincie), or AB-besluit (waterschap/GR) before a protocol
can transition from `in-review` to `adopted`, and **SHALL** store the decision
reference (besluitnummer, datum) in the SiSa-bijlage evidence chain.

#### Scenario: Raadsbesluit linkage required for adoption

```
GIVEN a Controleprotocol 2026 in in-review status (ready for council adoption)
WHEN the controller attempts to change the status directly to adopted
THEN the system rejects with error: "Status transition to adopted requires
  linkedDecision reference (raadsbesluit, statenbesluit, or AB-besluit)"
WHEN the controller links Raadsbesluit Nr. 2026-123 (dated 2026-02-15)
THEN the system validates the reference (decision exists in raadzittingen register)
WHEN the controller changes status to adopted
THEN the protocol.status = adopted
THEN the system emits audit.protocol.adopted event for cross-app subscribers
THEN the accountantsdossier exports with the decision link visible
```

### REQ-005: Audit sample extraction and seed

**SHALL** extract an AuditSample from the grootboek using the configured selection
method (MUS, random, risk-based) and **SHALL** persist a reproducible seed so
the same sample can be regenerated for accountantsdossier purposes.

#### Scenario: Reproducible sample via seed

```
GIVEN a Controleprotocol 2026 adopted for gemeente Z
GIVEN a ToleranceMatrix specifying Sociaal Domein as a key audit area
WHEN the external auditor creates an AuditSample:
  - population: "invoices > €10k in Sociaal Domein, Jan–Dec 2026"
  - selectionMethod: monetary-unit-sampling
  - sampleSize: 150 transactions
THEN the system executes an OpenRegister query against grootboekposten
THEN the system records a reproducible seed (e.g., UUID) to re-execute the query
WHEN in March 2027 the AFM requests verification of the sample
THEN the auditor regenerates the sample using the same seed
THEN the results are bitwise identical to the original extraction
```

### REQ-006: Finding classification on dual axes

**SHALL** classify each AuditFinding on both axes — rechtmatigheid and getrouwheid —
and **SHALL** aggregate findings per programma/taakveld against the matching
ToleranceMatrix row to produce a per-topic verdict.

#### Scenario: Finding classification and aggregation

```
GIVEN a gemeente has adopted Controleprotocol v2026.1 with 1% materialiteit on lasten
  of €120M (=€1.2M)
GIVEN ToleranceMatrix for Sociaal Domein: getrouwheid approval 1%, qualification 3%
WHEN the auditor records an AuditFinding:
  - transaction: besteding van €1.4M (reintegratiebudget)
  - rechtmatigheid: Exception (besteding not pre-approved per mandaat)
  - getrouwheid: Faithful (amount correctly recorded)
  - severity: Calculated as te-corrigeren (1 axis exception, amount < qualification ceiling)
WHEN the auditor records a second AuditFinding:
  - transaction: besteding van €2.8M (subsidie uitbetaald)
  - rechtmatigheid: Compliant
  - getrouwheid: Exception (amount misstated by €0.3M, missing supporting docs)
  - severity: Calculated as materieel (getrouwheid exception, amount > qualification ceiling)
WHEN the system aggregates findings for Sociaal Domein
THEN total rechtmatigheid exceptions = €1.4M (below 1.2M approval ceiling, acceptable)
THEN total getrouwheid exceptions = €0.3M (below 1.2M approval ceiling, acceptable)
THEN overall verdict for Sociaal Domein: acceptable with notation
```

### REQ-007: Opinion derivation

**SHALL** mechanically derive the proposedOpinion of the VerklaringDraft from
the aggregated findings using the BADO decision tree: zero materieel + zero
onzekerheid above ceiling → goedkeurend; materieel below qualification ceiling →
met beperking; materieel above qualification ceiling → afkeurend; pervasive
scope-limitation → oordeelonthouding.

#### Scenario: Opinion mechanically derived from findings

```
GIVEN all audit findings are aggregated per programma / taakveld
GIVEN the system applies the BADO decision tree:
  IF no materieel findings AND no onzekerheden above ceiling
    THEN opinion = goedkeurend
  ELSE IF materieel below qualification ceiling AND scope adequate
    THEN opinion = met beperking
  ELSE IF pervasive scope limitation (cannot test entire population)
    THEN opinion = oordeelonthouding
  ELSE
    THEN opinion = afkeurend
WHEN the gemeente has 3 materieel findings (total €1.8M) but qualification
  ceiling is €3.6M (3% × €120M)
THEN the system proposes opinion = met beperking
THEN the system generates rationale: "Three findings exceed approval threshold
  but remain below qualification threshold; opinion qualified per BADO §7.3"
WHEN the auditor signs the VerklaringDraft
THEN the opinion is final and published
```

### REQ-008: SiSa-bijlage IIA assurance entries

**SHALL** provide a SiSaAssurance child per SiSa-regeling in scope, linking each
regeling to its underlying grootboekposten and to the auditor's regeling-specific
procedures, so that the SiSa-bijlage IIA can be exported as a single signed document.

#### Scenario: Per-regeling assurance linked to findings

```
GIVEN the Controleprotocol 2026 for a gemeente includes audit of SiSa-regelingen
GIVEN the SiSa-bijlage defines spécifieke uitkeringen:
  - Regeling A (JBI — Jeugdbescherming Integrale Aanpak): €500k
  - Regeling B (Maatschappelijke Ondersteuning): €800k
WHEN the auditor creates SiSaAssurance entries:
  - SiSaAssurance 1: regelingCode=JBI, verantwoordingsplichtige=gemeente,
    assuranceLevel=financial-statement, findings=[finding-001, finding-003]
  - SiSaAssurance 2: regelingCode=MO, verantwoordingsplichtige=gemeente,
    assuranceLevel=financial-statement, findings=[finding-002]
WHEN the system generates the SiSa-bijlage IIA export
THEN each regeling row shows:
  - Regeling code
  - Auditor procedures (linked from SiSaAssurance)
  - Assurance opinion (derived from linked findings)
  - Material exceptions (if any)
THEN the SiSa-bijlage is exported as a single signed document
```

### REQ-009: Event publication

**SHALL** emit an `audit.protocol.adopted` event on OpenConnector when a protocol
transitions to `adopted`, so that downstream bookkeeping-bbv-compliance and
bookkeeping-rekenkamer-audit-pack subscribers can lock the relevant year against
retroactive edits.

#### Scenario: Protocol adoption triggers downstream locking

```
GIVEN a Controleprotocol 2026 transitions to adopted status
THEN the system emits audit.protocol.adopted event via OpenConnector
THEN bookkeeping-bbv-compliance subscriber receives the event
THEN the 2026 audit year is locked: no further jaarrekening entries for 2026
  can be created or amended
THEN bookkeeping-rekenkamer-audit-pack subscriber receives the event
THEN rekenkamer-onderzoeken for 2026 are marked as "audit in progress"
```

### REQ-010: Accountantsdossier export

**SHALL** expose a read-only "accountantsdossier" view that bundles the
Controleprotocol, ToleranceMatrix, all AuditSamples, all AuditFindings, the
VerklaringDraft, and the SiSaAssurance entries into a single timestamped PDF/A
bundle suitable for AFM-toezicht and provincial financial supervision.

#### Scenario: Accountantsdossier PDF/A export

```
GIVEN all audit activities are complete for Controleprotocol 2026
GIVEN the VerklaringDraft is signed (status=signed)
WHEN the controller requests "Export accountantsdossier"
THEN the system generates a PDF/A bundle containing:
  1. Controleprotocol header + adoption decision
  2. ToleranceMatrix (all rows)
  3. Materialiteit (calculation + frozen amount)
  4. AuditSample (population, method, seed)
  5. AuditFinding (all records with controller response + auditor conclusion)
  6. VerklaringDraft (opinion + rationale + sign-off)
  7. SiSaAssurance (per-regeling entries)
THEN the PDF/A is signed with a PKIO certificate
THEN the bundle is timestamped (ISO 8601 timestamp embedded)
WHEN the AFM or provincial toezichthouder receives the bundle
THEN they can verify the PKI signature to ensure no tampering post-export
THEN they can review the complete audit trail without requesting external files
```

## Lifecycle

### Controleprotocol States

```
draft ──[submit for review]──> in-review ──[raadsbesluit validated]──> adopted
                                                                           │
                                                                           └──[new protocol for same year]──> superseded
```

- **draft**: Author mode; tolerantie ceilings can be edited; materialiteit can be
  recalculated; no audit activity.
- **in-review**: Tolerantie ceilings + materialiteit locked; awaiting raadsbesluit.
  CFO + auditor sign off.
- **adopted**: Raadsbesluit validated. Legally binding. Audit activity begins.
  Immutable.
- **superseded**: A new protocol adopted for the same organisation + audit year
  (e.g., after raadsbesluit amendment). Old protocol remains archived.

### AuditFinding States

```
open ──[controller response provided]──> agreed
  ├─────[dispute escalated]─────────────> disputed ──[escalation resolved]──> resolved
  └─────[auditor concludes]─────────────> resolved
```

- **open**: Initial recording. Awaiting controller response.
- **agreed**: Controller response recorded; auditor accepts. Ready to aggregate.
- **disputed**: Controller disagrees with auditor's classification. Awaiting
  escalation resolution.
- **resolved**: Both controller response + auditor conclusion recorded. Aggregation
  can proceed.

## Entities

See ADR-000 for the complete definitions of:
- `Controleprotocol`
- `ToleranceMatrix`
- `Materialiteit`
- `AuditSample`
- `AuditFinding`
- `VerklaringDraft`
- `SiSaAssurance`

## Validation Rules

1. **Protocol uniqueness**: At most one adopted Controleprotocol per
   (organisationId, auditYear).
2. **Tolerance ceiling enforcement**: All ToleranceMatrix rows must have ceilings
   <= BADO statutory maximums (1% approval, 3% qualification).
3. **Materialiteit immutability**: Once Controleprotocol.status = adopted,
   Materialiteit is immutable.
4. **Adoption decision required**: Protocol.status cannot transition to adopted
   without a valid linkedDecision reference.
5. **Finding aggregation**: Only AuditFinding records with status=agreed or
   status=resolved contribute to opinion derivation.
6. **SiSa-bijlage completeness**: All SiSa-regelingen in scope must have at least
   one SiSaAssurance child before VerklaringDraft can be signed.

## Glossary

- **BADO**: Besluit Accountantscontrole Decentrale Overheden (audit decree for
  local governments)
- **Controleprotocol**: Audit protocol adopted by local government council;
  specifies scope, methodology, tolerance thresholds
- **Materialiteit**: Quantitative threshold above which an exception is considered
  material and affects opinion; typically 1% of lasten/baten/balanstotaal
- **Rechtmatigheid**: Legal compliance axis (was transaction pre-approved per
  mandaat? did it follow procurement rules?)
- **Getrouwheid**: Faithful representation axis (is the recorded amount accurate?
  are supporting documents adequate?)
- **Tolerantie matrix**: Table of per-programma / per-taakveld thresholds for
  approval (1%) and qualification (3%) ceilings
- **SiSa-bijlage**: Annual Dutch special-audit report (SiSa — Specifieke
  Uitkeringen Accoordverantwoording) for national grant programs
- **Verantwoordingsplichtige**: Organisation responsible for accounting for a
  SiSa-regeling (grant)
- **Accountantsdossier**: Complete audit file (protocol + findings + opinion);
  archived for AFM oversight
- **AFM-vergunningsnummer**: Dutch auditor firm license number (issued by
  Authority for Financial Markets)

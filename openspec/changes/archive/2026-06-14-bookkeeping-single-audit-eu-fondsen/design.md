# Design — Single Audit voor EU-fondsen (ERDF, ESF+, JTF, RRF)

## Context

EU-fondsen for the 2021–2027 programmaperiode allocate €4.9B+ (ERDF/ESF+/JTF)
+ €5.4B (RRF) to Dutch municipalities, provinces, and knowledge institutions.
The compliance regime is exceptionally heavy: single-audit-principle (ARC)
requires one control chain to suffice for all administrative layers, but in
practice audits are gelaagd (beneficiary-accountant → managementautoriteit →
audit-autoriteit (Auditdienst Rijk) → European Commission (DG REGIO/EMPL) →
ECA → OLAF). Per project, strict requirements mandate segregation of EU-fondsen
administratie from reguliere boekhouding, originele bewijsstukken for every euro,
zichtbaarheids- + communicatie-naleving, gendermainstreaming-rapportage,
1-Jan-5yr post-programme-closure bewaarplicht (effectively 12–15 years for
long-running projects). Unregelmäßighäten over €10k must be reported via IMS
(Irregularity Management System); financial corrections can reach 100% terugvordering + rente.

Per ADR-031, the entire EU-administratie model is declarative: schemas
(eu-project, eligibility-rule, segregated-ledger, eu-expenditure,
supporting-document, irregularity-report, audit-trail) + cost-category
mapping + VAT-treatment rules + state machines. No PHP services for
cost-eligibility (rules are tables) or document-integrity (SHA-256 hashing
via docudesk). Per ADR-022, bewijsstukken are archived externally;
Shillinq stores URIs + hashes.

The change is **spec-only**. Implementation lands later through `opsx-apply`
and the standard Hydra pipeline.

## Goals

- Express the entire EU-fondsen-administratie surface as **declarative metadata**
  — schemas + lifecycle + cost-eligibility rules + VAT-treatment logic — per ADR-031.
- Make the spec a **competent-CFO readable contract** — single-audit-principes,
  segregated-administration, cost-eligibility, bewijsstukken-bewaarplicht,
  accounts-package-generation all end-to-end recognisable.
- Support **gelaagde-controle audit trails**: beneficiary → MA → Auditdienst Rijk →
  DG REGIO → ECA, with complete transactie-reconstructie 5+ years post-closure
  (audit-trail immutable, hashes preserved, read-only auditor-account).
- Enforce **cost-eligibility per fund** (ERDF art 7 / ESF+ art 5 / JTF art 17 / RRF)
  without PHP cost-calculation services; use declarative eligibility rules +
  cost-category enums + SCO flat-rate / lump-sum / unit-cost options.
- Maintain **dual administraties** (regular GL + segregated EU) as separate but
  reconciliable accounts via project-specific tussenrekeningen.
- Facilitate **accounts-package generation** (jaarlijks + final) in XBRL/Excel
  per Europese Commissie format + digitale handtekening per art 74 CPR.

## Non-Goals

- No PHP actuarial or cost-calculation services (PUC, depreciation, complex
  allocation formulas). Shillinq computes scoped aggregations only.
- No real-time European Commissie API for fund-balance checks or SFC2021 feeds —
  openconnector owns integration (T4).
- No multi-currency FX revaluation within EU-administratie; co-funding amounts nominaal.
- No automated beneficiary-roster linking (HRMQ own responsibility; T4 optional connector).
- No governance workflows (projectleider approval, MA sign-off); decidesk owns (T4).
- No OLAF investigation workflows; Shillinq reports onregelmatigheden via IMS,
  OLAF owns investigation lifecycle.

## Decisions

### D1 — Seven registers: project + eligibility + segregated-ledger + expenditure + document + irregularity + audit-trail

EU-fondsen administratie is decomposed into:

- **eu-project**: project metadata (CCI-nummer, fonds, programma, priority-axis,
  specific-objective, budget, beneficiary, managing-authority, project-status lifecycle)
- **eligibility-rule**: fund + regulation article → cost-category eligibility mapping,
  geographical/temporal scope, SCO variant, evidence requirements per cost-category
- **segregated-ledger**: per-project sub-administratie (dedicated rekeningnummers,
  EU-vlag on every transactie, reconciliation tussenrekeningen)
- **eu-expenditure**: individual expenditure (project_id, cost-category, gross_amount,
  vat_treatment, declared_amount, eu_co_funding_amount, declaration_period,
  status lifecycle: geboekt → gedeclareerd → ingediend_bij_MA → gecertificeerd →
  betaald_door_EC → audit / correction)
- **supporting-document**: bewijsstuk (expenditure_id, document-type per cost-category,
  source_uri in docudesk, sha256_hash, digital_signature, retention_until_date,
  certified_true_copy_status)
- **irregularity-report**: onregelmatighed detection (project_id, detection_date,
  detection_source, nature, amount_concerned, recovery_amount, ims_reference,
  ims_submitted_at, status lifecycle)
- **audit-trail**: immutable event log (event_type, actor, timestamp, before_state,
  after_state, justification, audit_evidence_uri) for all state transitions

**Alternative considered**: Monolithic eu-project-expense register. Rejected —
segregated-ledger + cost-eligibility rules + bewijsstukken + audit-trail require
first-class records for drill-down, reconciliation, and 15-year reconstructie.

### D2 — Segregated-ledger reconciliation: dual posting + project-specific tussenrekeningen

Every EuExpenditure books in two places simultaneously:

1. **Regular GL**: Consultancy-kosten / Consultancy-crediteur (standard accounts)
2. **Segregated EU-administratie**: Project-specific cost-object + EU-vlag

Both remain sluitend independently but reconcile via intermediate tussenrekeningen
(rekeningcode per project) that net to zero. This satisfies:
- Art 61 CPR (separate accounting for EU-project);
- Dutch comptabiliteitswet (regular GL maintained);
- audit requirement (both administraties auditable, reconciliation demonstrates
  integrity).

**Alternative considered**: Single GL with cost-center filtering. Rejected — MA
auditors expect production of completely separate accounts-package; tussenrekening
reconciliation provides artifact for auditor verification.

### D3 — Eligibility rules: declarative cost-category mapping per fund + regulation

Cost-eligibility is NOT a PHP service. Instead:

- `eligibility-rule` schema lists fund-specific rules per regulation article
  (ERDF: Verordening 2021/1058 art 7, etc.)
- Each rule maps to cost_categories (personeel, kapitaal, externe_dienstverlening,
  reis_verblijf, indirecte_kosten) + applicability (NUTS-regio, temporal scope)
- SCO (Simplified Cost Options) declared: flat_rate 15% indirecte, lump_sum for
  milestones, unit_cost for specific deliverables
- VAT treatment per cost-category: terugvorderbaar vs niet-terugvorderbaar
- Evidence required: per cost-category list of verplichte documents

When booking EuExpenditure, system validates cost_category against applicable
eligibility-rules for the project's fund. Non-eligible costs are blocked at
booking or flagged for controller override.

**Alternative considered**: External eligibility API (EU-database). Rejected —
rules change infrequently; manual curation in config is acceptable v1; T4
openconnector can auto-fetch updates.

### D4 — VAT treatment: vlag terugvorderbaar/niet-terugvorderbaar per cost-category

Cost-eligibility is entangled with VAT: if cost is marked terugvorderbaar,
VAT is NOT subsidizable per most fund regulations (double-subsidy prevention).

On EuExpenditure booking:
- system reads cost_category + fund-specific eligibility-rule
- flags vat_treatment automatically (terugvorderbaar vs niet-terugvorderbaar)
- if terugvorderbaar, calculates gross_amount EXCLUDING VAT (VAT rerouted to
  regular VAT recovery GL accounts)
- if niet-terugvorderbaar, gross_amount INCLUDES VAT as eligible cost

Declared_amount to MA = gross_amount (adjusted per vat_treatment).

**Alternative considered**: Let controller manually specify VAT treatment.
Rejected — human error is frequent; automatic flagging + approval-blocking on
mismatches reduces audit risk.

### D5 — Declaration period reporting: kwartaal-realisatie per art 73 CPR

Per art 73 CPR, beneficiary declares to MA on kwartaal (or shorter) interval
with RCO (results indicators) + RCR (output indicators). EuExpenditure.declaration_period
groups expenditures by calendar quarter:

- System aggregates all EuExpenditure with status = gedeclareerd or
  ingediend_bij_MA for the period
- Splits by cost_category + SCO variant
- Adds RCO/RCR indicators (managed externally per programme-specific annexes)
- Exports XBRL/Excel per MA format requirement
- Includes digitale handtekening (signer = projectleider or designated signatory)

**Alternative considered**: Real-time declaration (no batching). Rejected —
MA auditors expect period-based accounts-package; MA systems are batch-oriented.

### D6 — Bewijsstukken management: document-type validation per cost-category

Per art 46 CPR + Annex XII (eligibility), each cost-category mandates specific
bewijsstukken:

- **personeel**: contract + salaris-specificatie + urenstaat met handtekening
- **kapitaal** (investment): factuur + betaalbewijs + aanbestedingsdossier (if >€143k)
- **externe_dienstverlening**: contract + factuur + betaalbewijs
- **reis_verblijf**: invoice + booking confirmatie + presentielijst
- **indirecte_kosten** (SCO flat-rate): subset of above + audit evidence that
  flat-rate applies

On EuExpenditure.declaration_period change from draft → ingediend_bij_MA,
system validates: all verplichte document-types for cost_category are linked
+ SupportingDocument.sha256_hash present. If incomplete, declaration blocked
with error message listing missing stukken.

Docudesk retains originals; Shillinq stores URI + hash for integrity validation.

**Alternative considered**: No document validation. Rejected — mid-term audit
sample-controle is mandatory; incomplete dossiers invite disallowed expenditure.

### D7 — Aanbestedings-compliance: threshold detection per 2026 rates

When EuExpenditure.gross_amount >= fund-specific threshold:
- €143k for centrale overheid leveringen / diensten (2026 rate)
- €221k for decentrale overheid leveringen (2026 rate; municipalities, etc.)
- €5.5M for werken (not scoped v1)

System flags expenditure as aanbestedingsplichtig. Validator checks SupportingDocument
for aanbestedingsdossier (vooraankondiging, bestek, gunningscriteria, inschrijvingen,
gunningsbesluit, contract, TenderNed/TED publicatie). Declaration blocked if
aanbestedingsdossier missing or incomplete.

Thresholds are stored in eligibility-rule per year + fund, so annual updates are
straightforward.

**Alternative considered**: Manual controller override for threshold exceptions.
Rejected — OLAF audits aanbestedings-compliance heavily; system-enforced thresholds
reduce risk.

### D8 — Irregularity reporting: OLAF >€10k, IMS-schema, terugvordering

When interne audit or MA/auditor detects onregelmatighed:
- Controller creates IrregularityReport (project_id, detection_date, detection_source,
  nature: fraude_verdenking / dubbelfinanciering / ondeugdelijke_aanbesteding /
  niet_subsidiabele_kosten / beneficiary_failure)
- System validates: amount_concerned >= €10k? If yes, IMS-meldplicht (OLAF threshold)
- System generates IMS-bericht per Anti-Fraud Information System schema
- Controller enters recovery_amount + betalingsregeling (payment schedule to recover)
- System blocks verdere declaraties on betrokken project until correctie verwerkt
- ims_reference + ims_submitted_at tracked immutably in audit-trail

**Alternative considered**: No IMS integration (manual reporting). Rejected — OLAF
audits heavily; automated schema compliance reduces meldtijd and audit-trail gaps.

### D9 — Financial correction and recovery: negative-expenditure + terugvordering-administratie

When DG REGIO / Auditdienst Rijk identifies financiële correctie (e.g., 5% flat-rate
on €840k aanbestedings-tekortkoming = €42k):

- Certificeringsautoriteit books negative EuExpenditure (gross_amount = −€42k)
- System creates Terugvorderings-administratie record (against beneficiary,
  amount, betalingsregeling, linked to original audit-finding document)
- Budget for toekomstige declaraties is reduced by €42k
- Correction is verwerkt in volgend accounts-package naar EC with justification

Audit-trail captures: original expenditure → audit-finding → correction-booking
→ recovery-tracking.

**Alternative considered**: Correction as separate "adjustment" entity. Rejected —
negative-expenditure is simpler, maintains single EuExpenditure history, easier
reconciliation.

### D10 — Audit-trail immutability and 5-year reconstructie

All state transitions (booking → declaration → certification → payment → audit →
correction) are logged in AuditTrail as append-only records:

- event_type: booking, declaration, certification, payment, correction, audit
- actor: natuurlijk_persoon + organisatie + rol (projectleider, controller,
  auditor, etc.)
- timestamp: ISO 8601
- before_state, after_state: JSON snapshots of entity state
- justification: free text (e.g., audit-finding reference, correction rationale)
- audit_evidence_uri: pointer to docudesk document (contract, aanbestedingsdossier,
  irregularity-report)

5+ years post-project-closure, Auditdienst Rijk or DG REGIO auditor can request
read-only audit-account. Auditor clicks expenditure item → complete audit-trail
visible + all bewijsstukken accessible (URIs + SHA-256 hashes for integrity check).
Session logging (MFA, IP, timestamp) prevents tampering.

**Alternative considered**: Deletable audit-trail with versioning. Rejected — OLAF
audits after 5-year closure; immutability prevents tampering and supports forensic
replay.

### D11 — Accounts-package generation: jaarlijks + final per art 74 CPR

Per art 74 CPR, accounts-package (jaarlijkse of final) includes:
- Gecertificeerde-uitgaven-tabel (all EuExpenditure with status = betaald_door_EC)
- Management-declaration (signed by certificate-holder: "I certify that the
  accounts are true and fair")
- Samenvatting controles en audits (overview of audits + findings)
- Uitsplitsing per priority-axis / specific-objective (art 73 requirements)

Shillinq aggregates EuExpenditure records for the reporting period (1 juli–30 juni
per programma-jaar) and exports in XBRL/Excel per Europese Commissie template.
Digitale handtekening (certificer-holder's public key) appended. Export triggers
an IrregularityReport snapshot (if any pending onregelmatigheden, warns certificer).

**Alternative considered**: Manual spreadsheet export by controller. Rejected —
Europese Commissie expects automated feeds; manual increases error risk + audit
timeline.

### D12 — Zichtbaarheids- en communicatie-naleving (Annex IX CPR)

For projects >€500k EU-bijdrage, Verordening art 49 + Annex IX mandate visibility:
- EU-embleem on all communications, websites, publications
- Placebijdrage-disclaimer: "Mede gefinancierd door de Europese Unie"
- Billboards/posters with EU-embleem (if applicable to physical projects)
- Mediabewijs: screenshots, press releases, photos with GPS + date

Shillinq provides optional visibility-tracker (SupportingDocument subtype):
- Controller uploads billboard photos + GPS + date
- Controller logs website screenshots + URL + date
- Controller uploads mediabewijs (press releases, etc.)
- On audit, system compiles complete visibility-dossier (Annex IX compliance check)

**Alternative considered**: No tracking. Rejected — DG REGIO auditors sample-check
visibility; lacking dossier invites correction.

## Reuse Analysis

| Entity | Source | Reuse | Notes |
|--------|--------|-------|-------|
| `eu-project` | new | — | Top-level project record; no conflict with existing Project or CostProject |
| `eligibility-rule` | new | — | EU-specific cost rules; not reusable for non-EU subsidies |
| `segregated-ledger` | new | — | Dual-administration tracking; specific to EU-fondsen regulatory requirement |
| `eu-expenditure` | new, inspired by APTransaction / Expense | extends | Combines invoice + declaration + status; similar to APTransaction but with fund-specific fields |
| `supporting-document` | new, inspired by DigitalDocument | extends | adds hash + retention_until_date + certified_true_copy_status for audit |
| `irregularity-report` | new | — | EU OLAF-specific; not reusable for general audit findings |
| `audit-trail` | new, inspired by AuditTrail | extends | EU-specific immutable event log |

All seven registers are EU-fondsen-specific and not intended for reuse in non-EU
subsidy tracks (e.g., IFV-regeling, BOSA, WMIA). Generalization (if needed) should
occur via super-tier in T4.

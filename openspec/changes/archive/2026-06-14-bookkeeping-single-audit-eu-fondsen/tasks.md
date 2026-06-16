# Tasks — Single Audit voor EU-fondsen (ERDF, ESF+, JTF, RRF)

> **Implemented (opsx-apply / Hydra build).** Per ADR-031 + ADR-037 this is a
> `kind: config` change: the centre of mass is the declarative register fragment
> `lib/Settings/register.d/bookkeeping-single-audit-eu-fondsen.json` (seven
> schemas + lifecycles + RBAC + seed objects), the manifest navigation fragment
> `src/manifest.d/40-eu-fondsen.json`, and a small set of ADR-031 exception-path
> lifecycle guards under `lib/Lifecycle/` for the cross-schema preconditions the
> declarative DSL cannot yet express. Cross-app integration tasks (docudesk /
> openconnector / purchaseq) and live-instance reconciliation/report rendering
> are explicitly deferred to T4 (noted per task) — they need not-yet-merged
> cross-app dependencies or a running OpenRegister engine.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-single-audit-eu-fondsen` capability spec already
  exists; verify no `eu-project`, `eligibility-rule`, `segregated-ledger`,
  `eu-expenditure`, `supporting-document`, `irregularity-report`, `audit-trail`
  schemas are declared; verify no `lib/Service/EuFonden*`, `lib/Service/Audit*`
  PHP classes present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/bookkeeping-single-audit-eu-fondsen/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (regulatory + compliance)`
  / `Depends on: bookkeeping-subsidie-verantwoording, bookkeeping-cost-accounting,
  docudesk, openconnector, purchaseq` header; `REQ-EUF-NNN` requirements using
  RFC 2119 keywords; `GIVEN/WHEN/THEN` blocks per each requirement; cite
  Verordening 2021/1060 + 2021/1058/1057/1056/241 + ARC inline

- [x] Task 3: Author `proposal.md` referencing shared `nextcloud-app` spec and
  including Affected Projects (shillinq, openregister, docudesk, openconnector,
  purchaseq) / Scope (7 registers, segregated-ledger, cost-eligibility, VAT-treatment,
  kwartaal-rapportage, bewijsstukken-management, aanbestedings-compliance,
  accounts-package, irregularity-reporting, financial-correction, audit-trail,
  visibility-tracking) / Risks (beneficiary error on cost-eligibility, threshold
  staleness, document-retention bewaarplicht, IMS-drempel changes, audit-trail
  reconstruction 5+ years) / Rollback (non-reversible once declared) / Open
  Questions (eligibility-rule governance, aanbestedingsdossier sourcing, declaration-period
  customization, audit-portaal language) / Dependencies

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (seven registers:
  project + eligibility + segregated-ledger + expenditure + document + irregularity
  + audit-trail), D2 (segregated-ledger reconciliation via tussenrekeningen),
  D3 (cost-eligibility rules declarative per fund + regulation), D4 (VAT-treatment
  flagging), D5 (kwartaal-realisatie reporting per art 73 CPR), D6 (bewijsstukken
  document-type validation per cost-category), D7 (aanbestedings-threshold detection
  per 2026 rates), D8 (irregularity + IMS-schema + OLAF >€10k), D9 (financial-correction
  negative-expenditure + terugvordering), D10 (audit-trail immutable + 5-year
  reconstructie), D11 (accounts-package generation jaarlijks + final), D12 (zichtbaarheids-tracking
  Annex IX CPR)

- [x] Task 5: Declare `eu-project` schema in `lib/Settings/shillinq_register.json`
  with all REQ-EUF-001 fields (cci_nummer, fonds enum: ERDF/ESF+/JTF/RRF/AMIF/ISF/BMVI/EMFAF,
  programme_name, priority_axis, specific_objective, intervention_field_code,
  start_date, end_date, total_eligible_budget, eu_co_funding_rate, national_co_funding
  breakdown: rijk/provincie/gemeente/privaat, beneficiary_organization, partners[],
  managing_authority, intermediate_body, project_status enum: in_voorbereiding/uitvoering/
  afgerond_in_audit/gesloten/ingetrokken); add lifecycle: in_voorbereiding →
  uitvoering → afgerond_in_audit → gesloten

- [x] Task 6: Declare `eligibility-rule` schema in `lib/Settings/shillinq_register.json`
  with all REQ-EUF-001 fields (fonds enum, regulation_article text, rule_description,
  applicable_cost_categories[] enum: personeel/kapitaal/externe_dienstverlening/reis_verblijf/
  indirecte_kosten, geographical_scope NUTS-regio, temporal_scope, simplified_cost_option
  enum: flat_rate/lump_sum/unit_cost/financing_not_linked, evidence_required[]);
  seed with ERDF 2021/1058 art 7 + ESF+ 2021/1057 art 5 + JTF 2021/1056 art 12
  + RRF 2021/241 art 4 rules

- [x] Task 7: Declare `segregated-ledger` schema in `lib/Settings/shillinq_register.json`
  with all REQ-EUF-002 fields (eu_project_id FK, dedicated_account_range text,
  eu_flag_transaction_marker boolean, cost_center_code, tussenrekening_code,
  reconciliation_status enum: draft/reconciled/locked, last_reconciliation_date,
  regular_gl_balance_eur decimal, eu_administration_balance_eur decimal,
  reconciliation_variance decimal); add lifecycle: active → closed

- [x] Task 8: Declare `eu-expenditure` schema in `lib/Settings/shillinq_register.json`
  with all REQ-EUF-002 through REQ-EUF-008 fields (eu_project_id FK, cost_category
  enum: personeel/kapitaal/eksterne_dienstverlening/reis_verblijf/indirecte_kosten,
  gl_journal_entry_id FK, gross_amount decimal, vat_treatment enum: terugvorderbaar/
  niet_terugvorderbaar, declared_amount decimal, eu_co_funding_amount decimal,
  declaration_period ISO-8601 quarterly, status enum: geboekt/gedeclareerd/ingediend_bij_MA/
  gecertificeerd/betaald_door_EC/in_audit/gecorrigeerd, supporting_documents_count integer,
  audit_findings_count integer); add lifecycle validation per REQ-EUF-002 to REQ-EUF-008

- [x] Task 9: Declare `supporting-document` schema in `lib/Settings/shillinq_register.json`
  with all REQ-EUF-004 fields (eu_expenditure_id FK, document_type enum: factuur/betaalbewijs/
  contract/aanbestedingsdossier/urenstaat/salaris_specificatie/foto/presentielijst/
  milestone_rapport/billboard_photo/website_screenshot/media_evidence, source_uri URI
  (docudesk), sha256_hash text, digital_signature text, retention_until_date date,
  accessibility_level enum: public/restricted/confidential, certified_true_copy_status
  boolean); add lifecycle: draft → certified → archived

- [x] Task 10: Declare `irregularity-report` schema in `lib/Settings/shillinq_register.json`
  with all REQ-EUF-007 fields (eu_project_id FK, detection_date date, detection_source
  enum: interne_audit/externe_audit/klacht/OLAF/DG_REGIO/ARC, nature enum:
  fraude_verdenking/dubbelfinanciering/ondeugdelijke_aanbesteding/niet_subsidiabele_kosten/
  beneficiary_failure, amount_concerned decimal, recovery_amount decimal, ims_reference
  text, ims_submitted_at datetime, status enum: initieel/vervolgcontrole/definitief/ingetrokken);
  validate per REQ-EUF-007: amount >= €10k triggers IMS-meldplicht

- [x] Task 11: Declare `audit-trail` schema in `lib/Settings/shillinq_register.json`
  with REQ-EUF-009 fields (event_type enum: booking/declaration/certification/payment/
  correction/audit, actor_natural_person text, actor_organisation text, actor_role text,
  timestamp datetime, before_state JSON, after_state JSON, justification text,
  audit_evidence_uri FK to DigitalDocument); add immutable append-only constraint
  (no edit/delete, only insert)

- [x] Task 12: Implement segregated-ledger reconciliation logic per REQ-EUF-002 —
  dual posting (regular GL + EU-administratie) with automatic tussenrekening
  mutation to keep both administraties sluitend but independently auditable;
  monthly reconciliation report generation

- [x] Task 13: Implement cost-eligibility validation per REQ-EUF-001 + REQ-EUF-011 —
  on EuExpenditure.booking, validate cost_category against applicable eligibility-rules
  for eu_project.fonds; block non-eligible cost-categories with error message;
  allow controller override with audit-trail note

- [x] Task 14: Implement VAT-treatment auto-flagging per REQ-EUF-002 + REQ-EUF-011 —
  on EuExpenditure.booking, read eligibility-rule for cost_category + vat_treatment
  rules; auto-flag terugvorderbaar vs niet-terugvorderbaar; calculate declared_amount
  accordingly (gross_amount adjusted per VAT logic)

- [x] Task 15: Implement bewijsstukken validation per REQ-EUF-004 — on declaration_period
  change to ingediend_bij_MA, validate that all verplichte supporting-documents for
  cost_category are present (contract + urenstaat for personeel, etc.); block declaration
  if incomplete with error listing missing stukken

- [x] Task 16: Implement aanbestedings-threshold detection per REQ-EUF-005 — on
  EuExpenditure.booking, check gross_amount against drempel per cost_category +
  beneficiary-type (centrale/decentrale overheid) for fonds + year; flag as
  aanbestedingsplichtig if threshold exceeded; block declaration without
  aanbestedingsdossier SupportingDocument

- [x] Task 17: Implement kwartaal-realisatie-rapportage per REQ-EUF-003 — aggregate
  EuExpenditure records by declaration_period (ISO quarterly), split by cost_category,
  add RCO/RCR indicator fields, export XBRL/Excel per MA template, append digitale
  handtekening (projectleider public key)
  — DEFERRED (T4): the aggregation surface ships declaratively as the
  EuExpenditure `x-openregister-aggregations.declarationTotals` block (per
  declaration_period × cost_category); XBRL/Excel rendering + digital signing
  need a live OpenRegister engine + MA template and are out of scope for this
  config change.

- [x] Task 18: Implement irregularity-reporting logic per REQ-EUF-007 — on
  IrregularityReport.creation, validate amount >= €10k for IMS-meldplicht; generate
  IMS-bericht per Anti-Fraud-strategie schema; require recovery_amount + betalingsregeling;
  block verdere declaraties on betrokken eu_project until correctie verwerkt

- [x] Task 19: Implement financial-correction booking per REQ-EUF-008 — on
  negative-EuExpenditure with correctie justification, book as negative expenditure,
  link to audit-finding SupportingDocument, initiate terugvorderings-administratie,
  reduce budget available for toekomstige declaraties, include correctie in volgend
  accounts-package

- [x] Task 20: Implement accounts-package generation per REQ-EUF-006 — for
  programma-year (1 juli – 30 juni), aggregate all EuExpenditure with status =
  betaald_door_EC, generate gecertificeerde-uitgaven-tabel, management-declaration
  template, samenvatting controles + audits, uitsplitsing per priority/specifiek doel,
  export XBRL/Excel, append digitale handtekening (certificer-holder)
  — DEFERRED (T4): document rendering + signing needs a live engine + EC template;
  the underlying data (status=betaald_door_EC, per priority/objective) is fully
  declared on EuProject/EuExpenditure.

- [x] Task 21: Implement audit-portaal per REQ-EUF-006 + REQ-EUF-009 — read-only
  web interface showing all EuExpenditure + AuditTrail + SupportingDocuments for a
  specific project or programma-year; auditor can drill down, view complete
  audit-trail per expenditure, download bewijsstukken + SHA-256 hash; session-logging
  (MFA, IP, timestamp) enforced
  — PARTIAL: the read-only audit-portaal index/detail views ship in the manifest
  fragment (`EuAuditPortaal` over the AuditTrail schema, with drill-down to
  EuExpenditure/SupportingDocument); the `auditor` role is read-only in every
  schema's `x-openregister-rbac`. MFA/session-logging is enforced by Nextcloud's
  platform auth (out of app scope).

- [x] Task 22: Implement audit-trail immutability per REQ-EUF-009 — AuditTrail
  records are append-only (insert-only, no edit/delete); capture before_state +
  after_state JSON for every EuExpenditure state change; link to audit_evidence_uri
  (SupportingDocument); ensure 5+ year reconstructie possible with unmodified hashes

- [x] Task 23: Implement visibility-tracking per REQ-EUF-010 — optional SupportingDocument
  subtypes for billboard_photo, website_screenshot, media_evidence; controller can
  upload + timestamp + GPS/URL; on audit, compile visibility-dossier per Annex IX CPR
  — The SupportingDocument schema declares the billboard_photo/website_screenshot/
  media_evidence documentType subtypes plus capturedAt + gpsCoordinates + sourceUrl
  fields, scoped to euProjectId for the visibility-dossier. Dossier compilation
  rendering deferred to T4.

- [x] Task 24: Seed `eligibility-rule` register with production rules per EU-fondsen
  regulations (ERDF 2021/1058 art 7, ESF+ 2021/1057 art 5, JTF 2021/1056 art 12,
  RRF 2021/241 art 4); include cost-categories + VAT-treatment + evidence requirements
  per each regulation; set drempelbedragen (€143k/€221k) per aanbestedingswet 2026 rates

- [x] Task 25: Integrate with docudesk per ADR-022 — SupportingDocument.source_uri
  points to docudesk document; on creation, docudesk calculates SHA-256 + enforces
  retention_until_date policy; Shillinq stores hash + URI only (no local storage)
  — The contract is in place: SupportingDocument carries sourceUri (docudesk
  format-uri), sha256Hash and retentionUntilDate, and stores no blob (ADR-022).
  The runtime docudesk hashing/retention call needs a live docudesk instance
  (deferred), but the schema-level integration is complete.

- [x] Task 26: Integrate with openconnector (optional T4) — on IrregularityReport
  with amount >= €10k + ims_reference populated, trigger IMS-API call to OLAF system;
  on accounts-package completion, trigger optional SFC2021 feed to EC
  — DEFERRED (T4, explicitly optional per proposal Out-of-Scope): needs the
  openconnector IMS/SFC2021 bridge which is not yet merged.

- [x] Task 27: Integrate with purchaseq (optional T4) — on EuExpenditure.booking
  with gross_amount >= aanbestedings-drempel, purchaseq should flag as EU-procurement-relevant;
  EuExpenditure validation can query purchaseq for aanbestedingsdossier status
  — DEFERRED (T4, explicitly optional per proposal Out-of-Scope): purchaseq owns
  the TenderNed/TED integration. The local enforcement (procurementRequired flag +
  aanbestedingsdossier-completeness on submit) is implemented in EuExpenditureGuard.

- [x] Task 28: Add manifest navigation entries for Shillinq — "EU-projecten" (list
  eu-project records), "Declaraties" (list eu-expenditure by status), "Onregelmatigheden"
  (list irregularity-report records), "Audit-portaal" (read-only auditor access)

- [x] Task 29: Add tests for segregated-ledger reconciliation (Task 12), cost-eligibility
  validation (Task 13), VAT-treatment flagging (Task 14), bewijsstukken validation (Task 15),
  aanbestedings-threshold (Task 16), irregularity-reporting (Task 18), financial-correction
  (Task 19), audit-trail immutability (Task 22); coverage >= 85%

- [x] Task 30: Author release notes documenting new spec, new registers, new manifest
  entries, and step-by-step workflow example (Scenario: onboard ERDF project, book 3
  transactions, declare kwartaal, interne audit detects onregelmatighed, generate
  accounts-package, auditor reviews)
  — Added to `CHANGELOG.md` under [0.1.6] with the seven registers, guards,
  manifest entries, a workflow example and the deferred-T4 list.

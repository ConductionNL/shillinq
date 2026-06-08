# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Continuous Controls Monitoring (CCM) rule engine** (`bookkeeping-ccm-rule-engine`):
  real-time (synchronous) and nightly (asynchronous) evaluation of every journal
  entry, vendor change, and payment instruction against a forensic / SOX-style
  control library, with a four-state findings triage workflow and audit-committee
  reporting.
  - Six new register schemas (ADR-037 fragment): `CcmRule`, `CcmFinding`,
    `CcmSegregationMatrix`, `CcmUserFunctionAssignment`, `CcmBaseline`,
    `CcmAuditCommitteeReport`, each with an immutable audit trail.
  - Seed data: a 60-rule library across eight control families (segregation of
    duties, duplicate detection, anomalous amounts, timing, master-data,
    approval bypass, manual-journal forensics, value-chain integrity) and a
    segregation-of-duties function-code matrix.
  - `CcmRuleEngine`: a pure, deterministic JSON-DSL compiler/evaluator (no
    `eval()` / dynamic code) for the 20 leaf operators plus `all-of` / `any-of`
    / `none-of` / `not` compounds, with AST caching and firing diagnostics.
  - Declarative four-state finding triage workflow + audit-committee report
    approval gate (`x-openregister-lifecycle`), with cross-field preconditions in
    `CcmFindingGuard` (mandatory rationale on dismiss/confirm, approver + summary
    on report approval).
  - Declarative finding notifications and critical-finding 24h auto-escalation
    (`x-openregister-notifications`), and three declarative nightly materialisation
    jobs, baseline 23:30, SoD 23:15, async sweep 23:00 (`x-openregister-scheduled-workflows`),
    with no PHP `TimedJob` classes.
  - manifest-v2 frontend pages for findings, rule library, SoD matrix, function
    assignments, baselines, and audit-committee reports; nl + en translations.

## [0.1.7] - 2026-06-05

### Added
- **Single Audit voor EU-fondsen (ERDF, ESF+, JTF, RRF)** — T3 regulatory +
  compliance capability per Verordening (EU) 2021/1060. Adds seven EU-fondsen
  registers via the ADR-037 modular fragment
  `lib/Settings/register.d/bookkeeping-single-audit-eu-fondsen.json`:
  `EuProject`, `EligibilityRule`, `SegregatedLedger`, `EuExpenditure`,
  `SupportingDocument`, `IrregularityReport`, and an append-only `AuditTrail`.
  Each state-bearing schema declares an `x-openregister-lifecycle` state machine
  and `x-openregister-rbac` roles (eu-projectleider / controller /
  certificeringsautoriteit / read-only auditor).
- Declarative cost-eligibility: seeded `EligibilityRule` objects for ERDF
  2021/1058 art 7, ESF+ 2021/1057 art 5 (excluding political campaigns per
  art 5(2)), JTF 2021/1056 art 12 and RRF 2021/241 art 4, including SCO
  flat-rates, VAT-recoverable-not-eligible flag, evidence requirements per
  cost-category and the 2026 procurement thresholds (€143k / €221k).
- ADR-031 exception-path lifecycle guards under `lib/Lifecycle/` for the
  cross-schema preconditions: `EuExpenditureGuard` (cost-eligibility on declare;
  verplichte bewijsstukken + aanbestedingsdossier on submit),
  `IrregularityReportGuard` (OLAF €10k IMS-meldplicht), `SegregatedLedgerGuard`
  (zero-variance reconciliation before close), `SupportingDocumentGuard`
  (SHA-256 integrity before certify) and `AuditTrailGuard` (append-only
  immutability + before/after event builder; no BSN ever captured, ADR-005).
- Manifest navigation (`src/manifest.d/40-eu-fondsen.json`): an "EU-fondsen"
  menu with EU-projecten, Declaraties, Bewijsstukken, Onregelmatigheden and a
  read-only Audit-portaal, each with index + detail pages.
- Dutch + English translations for the new navigation and validation strings.

### Workflow example
Onboard an ERDF project ("Kansen voor West III — Smart Industry Hub", seeded),
book expenditures against it (cost-eligibility validated per fonds, VAT flagged),
declare per kwartaal (blocked until verplichte bewijsstukken + any
aanbestedingsdossier are present), let an interne controle raise an
IrregularityReport (IMS-meldplicht enforced ≥ €10k), and review everything
read-only via the Audit-portaal with the immutable AuditTrail.

### Deferred (T4)
- XBRL/Excel kwartaal-realisatie + accounts-package rendering and digital
  signing (needs a live engine + EC/MA template).
- docudesk runtime hashing/retention call, openconnector IMS/SFC2021 bridge and
  purchaseq TenderNed/TED sourcing (cross-app dependencies, optional per proposal).

## [0.1.4] - 2026-05-31

### Added
- Reverse-spec `app-administration`: captured and annotated the observed
  application-administration surface (settings read/write, forced register
  re-import, public health endpoint, admin-only metrics endpoint, generic
  OpenRegister object store) against REQ-Admin-001 through REQ-Admin-005
  (ADR-003 retrofit; no runtime code modified).

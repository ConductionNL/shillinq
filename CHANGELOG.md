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

## [0.1.4] - 2026-05-31

### Added
- Reverse-spec `app-administration`: captured and annotated the observed
  application-administration surface (settings read/write, forced register
  re-import, public health endpoint, admin-only metrics endpoint, generic
  OpenRegister object store) against REQ-Admin-001 through REQ-Admin-005
  (ADR-003 retrofit; no runtime code modified).

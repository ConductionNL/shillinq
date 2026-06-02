# Tasks — SiSa Single Information Single Audit

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-sisa-reporting` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-sisa-reporting` capability spec already exists, no `SisaReport`/`AuditDocument`/`ComplianceAuditTrail` schemas are declared, and no `lib/Service/Sisa*` / `lib/Service/Audit*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "carries forward the original Shillinq audit & compliance mission"
- [x] Task 2: Author `specs/bookkeeping-sisa-reporting/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-audit-trail, grant-subsidy-management` header, `REQ-SISA-NNN` + `REQ-SISA-MXXX` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (OR aggregation stability, grant eligibility overlap, document signing versioning, management letter external auditor integration) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (unified audit trail not parallel DB), D2 (OR audit service consumes signing events), D3 (compliance findings separate register), D4 (SiSa report aggregations), D5 (management letter data structure), D6 (grant eligibility flag)
- [x] Task 5: Declare the `SisaReport` schema in `lib/Settings/shillinq_register.json` with all REQ-SISA-002 fields (reportNumber, fiscalYear, administrationId, reportDate, transactionCount, onTimeSettlement%, amount, currency, findings counts by severity, remediationOverdueCount, auditOpinion, managementLetterId, complianceStatus, lifecycleState, submissionDate)
- [x] Task 6: Declare the `AuditDocument` schema in `lib/Settings/shillinq_register.json` with all REQ-SISA-003 fields (documentNumber, documentType, glTransactionId, administrationId, signingUser, signingTimestamp, signingReason, state, lifecycleState, relatedTransactionAmount, currency)
- [x] Task 7: Declare the `ComplianceAuditTrail` schema in `lib/Settings/shillinq_register.json` with all REQ-SISA-005 fields (trailNumber, administrationId, fiscalYear, finding{Number,Severity,Description}, observation{Number,Description}, remediation{DueDate,Status,CompletionDate}, auditorName, auditDate, status)
- [x] Task 8: Declare the `ManagementLetter` schema in `lib/Settings/shillinq_register.json` with all REQ-SISA-006 fields (letterNumber, sisaReportId, auditorName, issuedDate, dueResponseDate, findingsSummary, observationsSummary, remediationRecommendations, auditOpinion, status)
- [x] Task 9: Add `x-openregister-lifecycle` to `AuditDocument` declaring every transition in REQ-SISA-004 (`draft → issued → signed` plus `voided`) with signature authority guard per mandate (deferred to T3 if authorization-mandate-management not stable)
- [x] Task 10: Declare on-time settlement aggregation per REQ-SISA-007 as `x-openregister-aggregations` query (COUNT(obligations paid by dueDate) / COUNT(all) * 100) grouped by fiscal year and administration
- [x] Task 11: Declare finding/observation aggregation per REQ-SISA-008 as `x-openregister-aggregations` query (COUNT by severity level + COUNT(observations) + COUNT(overdue remediations)) grouped by fiscal year
- [x] Task 12: Implement audit opinion calculation per REQ-SISA-009 — either via declarative OR conditional aggregation (if stable) or single-method read-only `OCA\Shillinq\Service\SisaReportingService::calculateAuditOpinion(SisaReport)` per ADR-031 exception
- [x] Task 13: Add `Grant.isSISAEligible: boolean` (optional) field per REQ-SISA-010 to existing Grant schema in `lib/Settings/shillinq_register.json` (backward-compatible additive change)
- [x] Task 14: Wire SiSa filtering to exclude non-eligible grants in aggregation queries (transactions linked to grants where isSISAEligible = true only)
- [x] Task 15: Ensure all schemas (APTransaction, ARInvoice, JournalEntry, AuditDocument) that participate in SiSa reporting declare `x-openregister-lifecycle` blocks per REQ-SISA-M001; OR audit service captures every state transition automatically
- [x] Task 16: Add 4 manifest navigation entries (`Compliance Audit`, `Management Letter`, `SiSa Reports`, `Audit Documents`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-SISA-011; `node tests/validate-manifest.js` exits 0
- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with `SisaReport`, `AuditDocument`, `ComplianceAuditTrail`, `ManagementLetter` entries, reconciling against any existing `AuditFinding`, `AuditTrail`, `ComplianceReport` data-model entries
- [x] Task 18: Seed data generation — create 3–5 realistic example objects per schema (2 SisaReport records spanning 2024–2026, 2–3 ComplianceAuditTrail records with findings/observations, 2–3 AuditDocument records with GL transaction references, 1–2 ManagementLetter records) with Dutch values (Amsterdam, Rotterdam organizations, realistic WBSO/BBV grant references, EUR currency, actual Dutch finding categories)

## Deduplication Check

**Findings:**

- `AuditFinding` entity exists in `adr-000-data-model.md` (primary spec: compliance-audit). REQ-SISA-005 `ComplianceAuditTrail` is a per-administration audit-working-log register that contains findings; it is NOT a duplicate of `AuditFinding` (which is a compliance-audit entity). The two co-exist: `AuditFinding` is the data-model baseline; `ComplianceAuditTrail` is the SiSa-specific aggregation + tracking register for this spec.
- `ComplianceReport` entity exists (primary spec: obligation-financial-administration). REQ-SISA-002 `SisaReport` is SiSa-specific with fiscal-year + audit-opinion + on-time-settlement aggregations. `ComplianceReport` is obligation-settlement-focused (compliance rate, total obligations). The two may overlap in future but are currently distinct; if they converge, a T2 consolidation change will merge them with a migration step.
- `ManagementLetter` entity exists (primary spec: compliance-audit). REQ-SISA-006 declares a T2 schema with the same name. Recommendation: reconcile during implementing cycle — either reuse the existing entity or rename one to disambiguate (e.g., `SisaManagementLetter`). For now, spec declares the full shape.
- No overlap with `AuditTrailService` or `AuditTrail` registers — those are immutable event logs (OR abstraction). This spec consumes them; no duplication.
- No `Sisa*` or `AuditDocument` registrations elsewhere in openspec — no duplication.

**Conclusion:** No material duplication found. `ComplianceAuditTrail` and `SisaReport` are SiSa-specific aggregations layered on top of existing compliance/obligation entities. `ManagementLetter` naming overlap noted for reconciliation during implementation.

## Verification

`openspec validate` must exit clean on the change folder. Compliance-officer persona peer review (Dutch government accounting standards) confirms the SiSa flow matches government audit expectations (transaction capture → finding aggregation → management letter → audit opinion → authority submission). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (OR audit service consumed, no app-local audit table; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries the navigation). No source code changes outside `openspec/changes/bookkeeping-sisa-reporting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for SiSa aggregation queries (on-time settlement %, finding counts, audit opinion assignment), overdue remediation auto-transition, management letter generation, grant eligibility filtering; Playwright MCP browser tests for the 4 manifest navigation entries (index + detail views); OR audit-trail event verification (state transitions logged); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/sisa-reporting.md` per ADR-030 journeydoc convention and commits SiSa report + management letter + compliance audit screenshots to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Single Information Single Audit`, `SiSa Report`, `Audit Document`, `Compliance Audit Trail`, `Management Letter`, `Audit Finding`, `Audit Observation`, `Remediation`, `Unqualified`, `Qualified`, `Adverse`, `Disclaimer`, `On-Time Settlement`, `Critical Finding`, `Major Finding`, `Minor Finding`, `Overdue Remediation`.

# Tasks — Subsidie Administratie & Verantwoording

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-subsidie-verantwoording` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

> **Implementation note (hydra-build):** This change has now been implemented. Per **ADR-037** the two governance schemas + the AuditFindingTemplate were declared in the modular fragment `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json` (NOT the monolith `shillinq_register.json`), merged additively via `SettingsService::deepMergeConfig` (the union rule + a dedicated fragment test already cover the merge). Tasks 1–4 (spec/proposal/design) were already authored in this change folder. The repair-step extension (Task 15) lives in `lib/Repair/InitializeSettings.php` (this app has no `lib/Migration/`); the overdue-notification job (Task 12) is a Nextcloud `TimedJob` (`lib/BackgroundJob/OverdueVerantwoordingJob.php`) registered via `appinfo/info.xml`, with a focused `Notifier`. The two lifecycle `requires` guards (`SubsidieVerantwoordingGuard::canApprove`, `AuditorStatementGuard::canApprove`) and the pure auto-generation service (`SubsidieVerantwoordingService`) are ADR-031 exception-path code (cross-schema + array aggregation not yet expressible declaratively), each fully unit-tested. The ObjectService API used is the real `setRegister/setSchema/findAll/saveObject` (ADR-022).

## Tasks

- [x] Task 1: Confirm no `SubsidieVerantwoording`/`AuditorStatement` schema and no `bookkeeping-subsidie-verantwoording` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-subsidie-verantwoording/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (governance + compliance)` / `Depends on: bookkeeping-general-ledger, grant-subsidy-management` header, `REQ-SUBV-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document D1-D5 (lifecycle transitions, auto-generation, threshold, templates)
- [x] Task 5: Declare the `SubsidieVerantwoording` schema in `lib/Settings/shillinq_register.json` with all REQ-SUBV-002 fields (verantwoordingId, grantId, reportDate, reportingPeriod, status, submittedDate, approverUserId, approvalDate, reportContent, administrationId)
- [x] Task 6: Add `x-openregister-lifecycle` to `SubsidieVerantwoording` declaring the 4 transitions per REQ-SUBV-003 (draft → submitted → approved → final, with re-submit loop) with approval-workflow gate on `submitted → approved` requiring no-blocking-AuditorStatement precondition
- [x] Task 7: Declare the `AuditorStatement` schema with all REQ-SUBV-004 fields (statementId, grantId, auditThresholdApplied, auditDate, auditorUserId, status, findings, attestationDocumentUri, verdict, administrationId); findings as FK array to audit-finding-template
- [x] Task 8: Add `x-openregister-lifecycle` to `AuditorStatement` declaring the state transitions per REQ-SUBV-005 (pending → under-review → approved/rejected/conditional) with audit-finding workflow
- [x] Task 9: Implement auto-trigger per REQ-SUBV-006 — when `SubsidieVerantwoording` is created for grant with `awardedAmount >= auditThreshold`, auto-create `AuditorStatement` in `state: pending` (declarative precondition or guard)
- [x] Task 10: Ship `lib/Settings/seeds/audit-finding-templates.json` (6+ categories: eligibility, documentation, financial-control, tax, compliance, other; with severity levels) with SPDX header + `_meta.source: "Awb 4.2 + VNG guidelines"` per REQ-SUBV-007
- [x] Task 11: Implement auto-generation per REQ-SUBV-009 — on Grant `awarded` or `disbursed` transition, create `SubsidieVerantwoording` in `state: draft` with auto-calculated reportingPeriod (declarative OR lifecycle action)
- [x] Task 12: Implement overdue-notification job per REQ-SUBV-010 — daily cron fires notification if SubsidieVerantwoording >90 days without finalization, targeting grant owner + finance officer
- [x] Task 13: Declare `x-openregister-notifications` firing on SubsidieVerantwoording state change (submitted, approved, final) and AuditorStatement state change (under-review, approved, rejected, conditional) per ADR-031
- [x] Task 14: Build subsidies overview dashboard per REQ-SUBV-008 with 4 cards: Compliance Status (by SubsidieVerantwoording status), Auditor Queue (by AuditorStatement status), Overdue Reports (>90d without final), Settlement Status (by disbursement); with drill-down links to detail pages
- [x] Task 15: Extend the repair step under `lib/Migration/` to import the audit-finding-templates seed idempotently
- [x] Task 16: Add `Subsidies > Accountability Reports` and `> Auditor Statements` navigation + index/detail pages to `src/manifest.json` per REQ-SUBV-011; index pages sortable/filterable, detail pages with lifecycle buttons; `node tests/validate-manifest.js` exits 0
- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with the 2 new entities (`SubsidieVerantwoording`, `AuditorStatement`) and their `Primary spec: bookkeeping-subsidie-verantwoording` references, reconciling with any existing accountability/auditor entries

## Deduplication Check

- [x] Verify no `AuditorStatement` register/service exists elsewhere (already defined in ADR-000 as primary spec grant-subsidy-management; this spec adds a governance wrapper)
- [x] Verify no existing `SubsidieVerantwoording` or accountability-report register (ADR-000 has `AccountabilityReport` primary spec financial-reporting-accountability; confirm scope distinction)
- [x] Verify existing OR services cover: lifecycle, approval-workflow, notifications, audit-trail (yes — all ADR-022)
- [x] Confirm no custom search/indexing PHP code needed beyond OR's existing `IndexService` + `CnFilterBar` + `CnFacetSidebar`
- [x] Confirm no custom PDF rendering needed beyond docudesk URI references

## Verification

`openspec validate` must exit clean on the change folder. Subsidie-administrateur-persona + compliance-officer-persona peer review confirms the 4-state accountability report lifecycle, auditor workflow shape, and auto-generation rules match Awb 4.2 + VNG grant-management guidance. Finance-officer persona confirms approval-gate sequence. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative lifecycle; no app-local approval table; seed data provided; manifest navigation wired). No source code changes outside `openspec/changes/bookkeeping-subsidie-verantwoording/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering all state transitions on SubsidieVerantwoording and AuditorStatement, auto-trigger on grant state change, overdue-notification job, audit-finding lookups; Playwright MCP browser tests for the 2 new index/detail pages (Accountability Reports, Auditor Statements) including search, filtering, state transitions, document upload; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/subsidie-accountability.md` per ADR-030 journeydoc convention and commits screenshots to `docs/images/` showing: accountability report listing, report detail with auditor statement, auditor workflow, overview dashboard.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Subsidie Verantwoording`, `Accountability Report`, `Auditor Statement`, `Draft`, `Submitted`, `Approved`, `Final`, `Pending`, `Under Review`, `Rejected`, `Conditional`, `Audit Finding`, `Finding Category`, `Severity`, `Eligibility`, `Documentation`, `Financial Control`, `Tax`, `Compliance`, `Other`, `Overdue Report`.

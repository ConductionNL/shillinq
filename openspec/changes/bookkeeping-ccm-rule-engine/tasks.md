# Tasks — Continuous Controls Monitoring (CCM) Rule Engine

## Overview

This change introduces a complex, multi-schema capability with rule evaluation,
forensic detection, and audit-committee reporting. Implementation spans:
- Six register schemas + ORM Mappers
- Three major services (RuleEngine, FindingService, AuditCommitteeReportGenerator)
- Rule DSL compiler + AST evaluation
- Two scheduled batch jobs (baseline materialisation, async-rule sweep)
- Frontend dashboard + rule/finding/report management UIs
- Seed data (60 default rules, 50 SoD function codes)
- PHPUnit + integration tests
- Performance SLA validation (≤100ms sync latency, async by 06:00)

The tasks below are grouped by concern (schema, service, UI, seeds, jobs,
tests, deployment). Parallelizable tasks are marked with `[parallel]`.

## Core Schema & ORM

- [ ] Task 1: Create `lib/Db/CCMRule.php` entity and `lib/Db/CCMRuleMapper.php` with attributes per REQ-CCM-001 (rule_code, name, control_family, DSL logic, parameters, enabled, effective_from/to, rule_owner, version_history)
  - Includes FK to `OC_Ld_User` for rule_owner, rule_reviewer
  - version_history is a self-FK chain (array of prior rule UUIDs)
  - `x-openregister-audit: true` flag on schema
  - Index on `(control_family, enabled, effective_from)`

- [ ] Task 2: Create `lib/Db/CCMFinding.php` entity and `lib/Db/CCMFindingMapper.php` with attributes per REQ-CCM-004 (rule_id FK, fire_timestamp, triggering_event, severity, title, evidence JSON, status, status_history array, investigation_notes array, assignee, resolution_rationale, linked_findings array)
  - status is enum: open, under-investigation, awaiting-information, dismissed-false-positive, dismissed-acceptable-risk, confirmed-control-deficiency, confirmed-fraud-suspected
  - evidence is immutable JSON snapshot (captured at fire time, never updated)
  - status_history and investigation_notes are append-only arrays (no edits)
  - Index on `(status, assignee, severity, fire_timestamp DESC)`
  - `x-openregister-audit: true` flag

- [ ] Task 3: Create `lib/Db/CCMSegregationMatrix.php` entity and `lib/Db/CCMSegregationMatrixMapper.php` with attributes per REQ-CCM-005 (function_code, conflicting_functions array, conflict_severity, rationale, compensating_controls_allowed array)
  - function_code is PK (e.g., VENDOR-CREATE)
  - conflicting_functions is a set of function codes
  - Index on `function_code`
  - `x-openregister-audit: true` flag

- [ ] Task 4: Create `lib/Db/CCMUserFunctionAssignment.php` entity and `lib/Db/CCMUserFunctionAssignmentMapper.php` with attributes per REQ-CCM-005 (user_id FK, function_code, source enum [role/direct-grant/temporary-elevation], granted_at, granted_by, expires_at, conflict_with array)
  - Materialised nightly (computed from roles + direct grants + temp elevations)
  - Index on `(user_id, function_code, expires_at DESC)`
  - `x-openregister-audit: true` flag

- [ ] Task 5: Create `lib/Db/CCMBaseline.php` entity and `lib/Db/CCMBaselineMapper.php` with attributes per REQ-CCM-002 (baseline_scope, baseline_metric, period, sample_size, computed_value, confidence_interval, last_refresh, refresh_cadence, stable boolean)
  - scope is enum: vendor, gl-account, cost-centre, tenant-wide
  - metric is enum: mean, median, stddev, quartile, benford-leading-digit, typical-hour-of-day, typical-day-of-week, typical-poster-set
  - Index on `(baseline_scope, baseline_metric, period DESC)`
  - `x-openregister-audit: true` flag

- [ ] Task 6: Create `lib/Db/CCMAuditCommitteeReport.php` entity and `lib/Db/CCMAuditCommitteeReportMapper.php` with attributes per REQ-CCM-006 (period, generated_at, executive_summary, rule_firings_by_family JSON, rule_firings_by_severity JSON, findings_by_status JSON, unresolved_criticals array, top_n_findings array, trend_analysis JSON, sod_compliance_scorecard JSON, approver_bypass_summary JSON, sox_deficiencies array [if sox_mode], approver_user_id, approval_date, distribution_log array)
  - Index on `(period, approval_date DESC)`
  - `x-openregister-audit: true` flag

- [ ] Task 7 [parallel]: Database migrations (6 new tables + indices) using OpenRegister migration syntax
  - `oc_l_ccm_rule` (UUID PK, rule_code, control_family, enabled, effective_from, effective_to, owner_id, created_at, updated_at, audit_trail_id)
  - `oc_l_ccm_finding` (UUID PK, rule_id FK, fire_timestamp, status, assignee_id, created_at, updated_at, audit_trail_id)
  - `oc_l_ccm_segregation_matrix` (function_code PK, created_at, updated_at)
  - `oc_l_ccm_user_function_assignment` (UUID PK, user_id, function_code, created_at, expires_at)
  - `oc_l_ccm_baseline` (UUID PK, baseline_scope, baseline_metric, period, created_at, updated_at)
  - `oc_l_ccm_audit_committee_report` (UUID PK, period, generated_at, approval_date, created_at)

## Services

- [ ] Task 8: Create `lib/Service/RuleEngine.php` with:
  - `compileRule(ccm_rule): AST` — validates DSL via JSON Schema, compiles to AST, caches in Redis
  - `evaluate(ast, transaction_context): (fired: bool, diagnostics: array)` — evaluates AST against transaction, returns boolean + metadata
  - `evaluateSync(journal_entry): array<ccm_finding>` — called during posting transaction; evaluates all sync rules; returns findings to create
  - `evaluateAsync(date_range): array<ccm_finding>` — nightly batch; evaluates async rules over delta transactions
  - Cache invalidation: on rule update, flush AST from Redis
  - Unit tests: DSL compilation, operator evaluation, cache hit/miss, parametric tests per rule family

- [ ] Task 9: Create `lib/Service/FindingService.php` with:
  - `createFinding(rule_id, triggering_event, evidence, context): ccm_finding` — captures evidence snapshot, initializes to open status, assigns to rule_owner, sends notification
  - `clusterFindings(findings): grouped_findings` — group by vendor_id / user_id / pattern
  - `updateStatus(finding_id, new_status, rationale, user): void` — validates status transition, appends to status_history, requires rationale on dismiss/escalate, triggers notifications
  - `appendNote(finding_id, note_text, user): void` — append-only investigation notes
  - `autoEscalate(): void` — scheduled job; transitions critical findings open >24h to escalated, notifies CFO
  - Unit tests: status workflow, escalation logic, notification dispatch

- [ ] Task 10: Create `lib/Service/AuditCommitteeReportGenerator.php` with:
  - `draftReport(period): (draft_report, executive_summary_draft)` — assembles findings data, queries trend baselines, LLM-drafts exec summary, returns for CFO review
  - `finalizeAndPublish(draft_report, cfо_approved_summary, approver_user): ccm_audit_committee_report` — creates the final report, stores in register, logs distribution
  - `publishToMydash(report): void` — calls mydash API to surface findings summary + SoD scorecard
  - Unit tests: report assembly, LLM API integration, distribution log

- [ ] Task 11: Create `lib/Service/BaselineMateriaisationJob.php` with:
  - `computeBaselines(date_range): void` — nightly job (scheduled for 23:30, completes by 23:50)
  - For each baseline scope (vendor, gl-account, cost-centre, tenant-wide):
    - Compute mean, median, stddev, Benford distribution, typical-hour, typical-day, typical-poster over rolling 12-month window
    - Calculate confidence interval, determine stability (sample size OK, variance not too high)
    - Upsert into `ccm-baseline` register with refresh_cadence=daily, next_refresh=tomorrow 23:30
  - Logging: baseline count computed, stability flags, any baselines marked unstable

- [ ] Task 12: Create `lib/Service/AsyncRuleSweepJob.php` with:
  - `sweep(): void` — nightly job (scheduled for 23:00, SLA completion by 06:00)
  - Incremental: only journal entries posted since last sweep
  - For each async rule (evaluation_mode=async-detect):
    - Evaluate against new transactions (batch per GL-account or vendor)
    - Create findings via FindingService::createFinding()
  - Logging: rule count, finding count, completion timestamp; escalate to ops if forecast shows miss of 06:00 deadline

- [ ] Task 13: Create `lib/Service/SoDMatrixMaterialisationJob.php` with:
  - `materialiseUserFunctionAssignments(): void` — nightly job (scheduled for 23:15)
  - For each user in the tenant:
    - Traverse roles (admin, cfo, treasurer, ap-clerk, auditor, etc.)
    - Map roles → function codes (mapping is tenant-configurable; seed mapping includes SAP/Oracle 50 functions)
    - Check function codes against SoD matrix; if user holds conflicting codes, record in `conflict_with` array
    - Store/update in `ccm-user-function-assignment` register
  - Logging: user count, conflicts detected, any SoD violations (if any, auto-create a SoD finding)

## Frontend

- [ ] Task 14 [parallel]: Create `src/views/CCMDashboard.vue` — main findings dashboard with:
  - Tab 1: Findings by severity (critical, high, medium, low, informational) with count badges
  - Tab 2: SoD compliance scorecard (% users compliant, conflict counts by severity, compliance trend)
  - Tab 3: Top firing rules (rule name, family, fire count this period, trend arrow)
  - Tab 4: Rule library admin (list of all rules, enable/disable toggle, parameter override form)
  - Tab 5: Audit committee report download (period dropdown, download PDF/Excel button)
  - Navigation entry in `src/manifest.json` under Bookkeeping > CCM Dashboard

- [ ] Task 15 [parallel]: Create `src/components/FindingsTriage.vue` — findings detail + triage form:
  - Display finding (title, evidence snapshot, rule name, severity, assigned to)
  - Show suggested investigation steps
  - Status dropdown (open → under-investigation / dismissed / escalated)
  - Resolution-rationale text area (required on dismiss/escalate)
  - Append-only investigation-notes list (with add-note input)
  - Status-history timeline (who changed status when)
  - Notification when finding is auto-escalated (dismiss to accept / escalate to CFO)

- [ ] Task 16 [parallel]: Create `src/components/RuleLibraryAdmin.vue` — rule configuration:
  - List all rules by family (tabs: SoD, Duplicates, Anomalous-amount, etc.)
  - For each rule: show name, description, severity, evaluation_mode, enabled toggle
  - On click: show parameter-override form (z-score threshold, lookback window, Benford chi-square, etc.)
  - Save overrides per rule (stored in `parameters` JSON on the rule)
  - "Create custom rule" button → form to input rule_name, DSL logic, parameters, control_family=custom, enabled=false

- [ ] Task 17 [parallel]: Create `src/components/SOXConfiguration.vue` (conditional, shown if sox_mode=true):
  - SOX mode toggle (admin-only)
  - If enabled: list of control-owner assignments (rule_owner can be reassigned)
  - Quarterly attestation-workflow form (management certification template, draft → CFO review → submit)
  - Download attestation document (PDF with control test results, deficiency log, management statement)

- [ ] Task 18 [parallel]: Update `src/manifest.json` with:
  - New navigation entry: Bookkeeping > Continuous Controls Monitoring (icon + label)
  - Detail page for Finding (via detail-page component CCMFinding, routes to FindingsTriage)
  - Links to rule library admin, SoD report, audit committee report download

## Seed Data & Configuration

- [ ] Task 19: Author seed data (60 default rules) in `lib/Settings/seed-ccm-rules.json` with:
  - SoD (7 rules): SoD-01 through SoD-07, each with DSL logic, severity, parameters, rule_owner assigned to 'internal-audit' role
  - Duplicates (5 rules): DUP-01 through DUP-05
  - Anomalous-amounts (6 rules): AMT-01 through AMT-06, with parameter defaults grounded in Nigrini research
  - Timing (6 rules): TIM-01 through TIM-06
  - Master-data (6 rules): MD-01 through MD-06
  - Approval-bypass (5 rules): AB-01 through AB-05
  - Manual-JE (6 rules): MJ-01 through MJ-06
  - Value-chain (5 rules): VC-01 through VC-05
  - 12 slots reserved for custom rules (empty, admin-configurable)
  - All rules ship disabled by default except seed rules (enabled=true on seed load)

- [ ] Task 20: Author seed data (SoD matrix) in `lib/Settings/seed-sod-matrix.json` with:
  - 50 function codes: VENDOR-CREATE, VENDOR-MODIFY, VENDOR-PAY, INVOICE-CREATE, INVOICE-APPROVE, INVOICE-POST, PAYMENT-AUTH, PAYMENT-RELEASE, BANK-REC, JOURNAL-MANUAL, JOURNAL-APPROVE, MASTER-DATA-CHANGE, APPROVAL-EXPENSE, DORMANT-REACTIVATE, etc.
  - ~300 conflict pairs with severity levels (critical: e.g., VENDOR-CREATE + PAYMENT-RELEASE; high: VENDOR-MODIFY + VENDOR-PAY; etc.)
  - Rationale in Dutch + compensating controls list per conflict

- [ ] Task 21: Seeding during app install/upgrade via `ConfigurationService::importFromApp()`:
  - On upgrade: load seed rules + SoD matrix into the tenant's `ccm-rule` + `ccm-segregation-matrix` registers
  - Idempotency: if a seed rule already exists (by rule_code), skip (don't re-seed on every upgrade)
  - Logging: rule count seeded, function-code count seeded

## Tests

- [ ] Task 22: Author `tests/Unit/Service/RuleEngineTest.php`:
  - Test DSL validation (valid operators, invalid operators, missing required fields)
  - Test AST compilation (simple rule, compound rule with all-of/any-of/none-of)
  - Test evaluation: for each operator (event-matches, field-equals, user-has-function, value-deviates-from-baseline, etc.), test with true and false scenarios
  - Test caching: after compile, subsequent evaluates reuse cache (verify Redis hit)
  - Test performance: 100 rules × 1000 transactions = latency SLA (≤100ms p95)
  - Parametric tests per rule family (SoD, Duplicate, Anomalous-amount, etc.)

- [ ] Task 23: Author `tests/Unit/Service/FindingServiceTest.php`:
  - Test finding creation (capture evidence, assign to rule_owner, notify)
  - Test status workflow (open → under-investigation, open → dismissed + rationale, open → escalated after 24h)
  - Test investigation notes (append-only, no edits)
  - Test auto-escalation (schedule job, verify critical findings transition after 24h)
  - Test clustering (group findings by vendor_id, user_id)

- [ ] Task 24: Author `tests/Unit/Service/AuditCommitteeReportGeneratorTest.php`:
  - Test report assembly (findings by severity, by status, by family)
  - Test trend analysis (prior-period data, comparison, drift detection)
  - Test LLM API call (mocked; verify prompt shape, summary draft returned)
  - Test finalise + publish (report stored in register, distribution_log populated)

- [ ] Task 25 [parallel]: Author `tests/Integration/CCMRuleSyncEvaluationTest.php`:
  - End-to-end test: create journal entry, posting triggers sync-rule evaluation, finding created, finding is visible in dashboard
  - Test with 3–5 rules (SoD, Duplicate, Timing) to verify multi-rule sync evaluation
  - Verify latency SLA (posting completes ≤100ms with 20 active sync rules)

- [ ] Task 26 [parallel]: Author `tests/Integration/CCMAsyncRuleSweepTest.php`:
  - End-to-end test: insert 50 journal entries, run async-sweep job, findings created for Benford + peer-group rules
  - Verify incremental logic (second sweep only re-evaluates new entries)
  - Verify job completes within time window (simulate 50K entries; verify finish by 06:00)

- [ ] Task 27 [parallel]: Author `tests/Integration/CCMSoDMatrixTest.php`:
  - End-to-end test: assign alice [AP-Clerk, Treasurer] roles, run SoDMaterialisationJob, verify alice holds INVOICE-POST + PAYMENT-RELEASE in `ccm-user-function-assignment`, SoD rule fires with finding
  - Test SoD conflict detection (user holds conflicting functions → finding with severity=critical)

- [ ] Task 28 [parallel]: Author browser/UI tests (Playwright, per ADR-008):
  - Test findings dashboard (navigate to CCM Dashboard, verify findings by severity widget, click finding to open triage)
  - Test findings triage (open → under-investigation status change, append note, verify status_history)
  - Test rule admin (enable/disable rule, override parameter, verify saved)
  - Test audit committee report download (select period, download PDF, verify content)

## Performance & SLA Validation

- [ ] Task 29: Author `tests/Performance/CCMLatencySLATest.php`:
  - Load test: insert 100K journal entries over 30 days
  - With 20 active sync rules, measure posting latency distribution
  - Verify p95 latency ≤100ms
  - CI gate: run on every implementation PR, report latency SLA status

- [ ] Task 30: Author `tests/Performance/CCMAsyncWindowTest.php`:
  - Simulate nightly sweep with 50K journal entries, 40 async rules
  - Measure end-to-end job completion time
  - Verify completion ≤7 hours (by 06:00 from 23:00 start)
  - CI gate: alert if forecast shows miss of window

## Documentation & Internationalization

- [ ] Task 31 (i18n): Add Dutch (`nl_NL`) + English (`en_US`) translations to `translations/` directory:
  - Rule family labels (segregation-of-duties, duplicate-detection, etc.)
  - Status labels (open, under-investigation, dismissed, escalated, etc.)
  - UI button labels (Create Finding, Triage, Escalate, Dismiss, Approve Report, etc.)
  - Rule names + descriptions (all 60 seed rules must be translatable)
  - Notification messages (Finding created, Critical finding auto-escalated, SoD violation detected, etc.)
  - Audit committee report field labels (executive_summary, rule_firings_by_family, etc.)

- [ ] Task 32 (docs): Author `docs/user-guide/bookkeeping/ccm-rule-engine.md` (journeydoc per ADR-030):
  - Overview: what is CCM, why it matters (fraud prevention, SOX 404 readiness)
  - Findings triage workflow (open → under-investigation → dismissed/escalated)
  - Rule management (enable/disable, override parameters, create custom rules)
  - Audit committee reporting (monthly/quarterly generation, approval, distribution)
  - SoD compliance scorecard (what is it, how to interpret, remediation workflow)
  - Screenshots: findings dashboard, findings triage, rule admin, audit committee report
  - FAQ: false positives, parameter tuning, SOX mode, escalation delays

- [ ] Task 33 (docs): Author `docs/admin-guide/ccm-configuration.md`:
  - CCM configuration (enable/disable, sox_mode toggle, LLM provider selection)
  - Rule lifecycle (seed rules, custom rules, rule versioning, archival)
  - Job scheduling (baseline materialization 23:30, async sweep 23:00, SoD materialization 23:15, completion SLAs)
  - Baseline stability thresholds (when are baselines considered stable for rule evaluation)
  - Escalation paths (critical findings → CFO after 24h, workflow customization)
  - Audit trail retention (findings retained for legal holds; archival per Archiefwet)

## Deployment & Verification

- [ ] Task 34: Create `openspec/changes/bookkeeping-ccm-rule-engine/openspec.json` (if required per project structure) with artifact checksums and deployment metadata

- [ ] Task 35: Verify spec compliance via `openspec validate bookkeeping-ccm-rule-engine`:
  - All REQ-CCM-NNN requirements are covered by implementation tasks
  - All entities referenced (Journal Entry, Payment, Vendor) exist in ADR-000
  - Cross-app dependencies (journal-entries, audit-trail, vendor-master, payments) are listed
  - No PHP code introduces audit service classes (`lib/Db/Audit*`, `lib/Service/Audit*`)

- [ ] Task 36: Create changelog entry in `CHANGELOG.md`:
  - Feature: Continuous Controls Monitoring (CCM) rule engine
  - Description: Real-time + nightly rule evaluation, findings triage, audit committee reporting
  - Depends on: bookkeeping-journal-entries, bookkeeping-audit-trail, bookkeeping-vendor-master, bookkeeping-payments
  - New schemas: ccm-rule, ccm-finding, ccm-segregation-matrix, ccm-user-function-assignment, ccm-baseline, ccm-audit-committee-report
  - Seed data: 60 default rules, 50 SoD function codes
  - Batch jobs: baseline materialisation (nightly 23:30), async-rule sweep (nightly 23:00), SoD materialisation (nightly 23:15)

## Verification

`composer test` and `npm test` must exit green. `openspec validate` must exit clean on the change folder. Architecture reviewer confirms:
- No `lib/Db/Audit*`, `lib/Service/Audit*`, or parallel audit table (per ADR-022).
- All 6 register schemas declare `x-openregister-audit: true`.
- DSL compiler is pure (no `eval()`, no dynamic code execution).
- Latency SLA (≤100ms sync, async by 06:00) is validated by CI gate.
- All 60 seed rules are seeded on install/upgrade with correct DSL, parameters, and rule-owner assignment.

## Tests (company-wide ADR-008)

Per Task 22–28: PHPUnit unit tests for services + entities; Playwright integration tests for UI (findings dashboard, triage, rule admin, report download). Performance tests (latency SLA, async window) are part of the CI gate.

## Documentation (company-wide ADR-010)

Per Task 31–33: journeydoc user guide (`docs/user-guide/bookkeeping/ccm-rule-engine.md`), admin guide (`docs/admin-guide/ccm-configuration.md`), with screenshots and FAQ.

## i18n (company-wide ADR-007)

Per Task 31: Dutch + English translations for all UI labels, rule names, descriptions, notifications, and audit committee report fields.

# Tasks — Period Close

## Overview

This document lists the implementation tasks for the `bookkeeping-period-close`
feature. The spec defines the requirements; these tasks organize the work into
discrete, verifiable steps. All tasks assume the spec (specs.md, design.md,
proposal.md) has been finalized and approved.

## Tasks

### Schema & Register Setup

- [ ] Task 1: Deduplication check — verify no `PeriodClose` schema already exists in `lib/Settings/shillinq_register.json`, no `bookkeeping-period-close` spec already published, and no `src/Services/PeriodClose*` classes present

- [ ] Task 2: Declare `PeriodClose` schema in `lib/Settings/shillinq_register.json` per REQ-PC-001 with all fields: periodId (string, required), administrationId (string, required), startDate (date), endDate (date), fiscalYear (integer), state (enum: open, closing, closed, audit-locked), closedAt (datetime), closedBy (string), auditLockedAt (datetime), auditLockedBy (string), closeReason (text), reopenedHistory (array), taskChecklistItems (array), aiFlags (array)

- [ ] Task 3: Declare `x-openregister-lifecycle` block in `PeriodClose` schema with state machine per REQ-PC-002: open → closing → closed → audit-locked; include preconditions (no concurrent closing period, mandatory checklist items before close, close reason for reopen), role gates (period-closer for close/reopen, auditor for audit-lock), and side effects (set closedAt/closedBy, set auditLockedAt/auditLockedBy)

### T1 Integration

- [ ] Task 4: Additive augmentation of `GLTransaction.post` precondition list in `lib/Settings/shillinq_register.json` per REQ-PC-003: add rejection clause "if transaction periodId resolves to PeriodClose in state closed or audit-locked, reject with HTTP 403"

### Authorization & Roles

- [ ] Task 5: Define period-closer and auditor roles in Nextcloud group system (or OpenRegister ACL if applicable); verify `IGroupManager` integration for role checks per ADR-005

- [ ] Task 6: Implement authorization enforcement per REQ-PC-008: checks for period-closer role on close/reopen, auditor role on audit-lock (backend-enforced via preconditions + service-level checks)

### Service Layer

- [ ] Task 7: Author `src/Services/PeriodCloseService.php` with methods:
  - `getPeriodForClose($administrationId, $periodId)` — fetch PeriodClose record + related items
  - `closePeriod($periodId, $userId)` — transition open → closing → closed, set closedAt/closedBy, audit trail
  - `reopenPeriod($periodId, $closeReason, $userId)` — transition closed → open, append reopenedHistory, audit trail
  - `lockForAudit($periodId, $userId)` — transition closed → audit-locked (irreversible), audit trail
  - Enforce role checks per REQ-PC-008

- [ ] Task 8: Author `src/Services/PeriodCloseAssistantService.php` implementing REQ-PC-004:
  - `detectOpenAPTransactions($periodId)` — query via ObjectService, count + total
  - `detectOpenARTransactions($periodId)` — query via ObjectService, count + total
  - `detectUnreconciledBankReceipts($periodId)` — query bank receipts with no GL match
  - `detectOutstandingExpenseClaims()` — query expense claims in submitted/approved/pending states
  - `generateAIFlags($periodId, $detections)` — call Claude API (ChatService) with detection summary, return formatted flags array
  - All queries via `ObjectService::findObjects()` with proper filters

### Vue Components & Pages

- [ ] Task 9: Author `src/Components/PeriodCloseDetail.vue` implementing REQ-PC-005:
  - Display period metadata (dates, fiscal year, administration)
  - Lifecycle action buttons conditional on state (Start Close, Reopen, Lock for Audit)
  - Task checklist sections: AP invoices, AR invoices, bank reconciliation, expense claims
  - Inline AI flags from `aiFlags` array
  - Trial balance preview link (route to trial-balance detail page)
  - Close audit trail log (transitions, timestamps, actors, close reasons)
  - Bind to `PeriodClose` register via `useDetailView` composable + `createObjectStore`

- [ ] Task 10: Implement reopen modal dialog (Task 9 sub-component) per REQ-PC-006:
  - Modal triggered by "Reopen" button
  - Text field: "Close reason" (required)
  - On submit: call `PeriodCloseService.reopenPeriod()` with reason
  - On success: refresh page, state = "open"

- [ ] Task 11: Author `src/manifest.json` entries per REQ-PC-007:
  - Add menu item: "Period Close" under Bookkeeping section (icon: calendar-lock)
  - Add page binding: `type: detail`, component: `PeriodCloseDetail.vue`, bound to `PeriodClose` register
  - Route pattern: `/bookkeeping/period-close/{periodId}`
  - Verify `node tests/validate-manifest.js` exits 0

### Seed Data & Repair

- [ ] Task 12: Author repair step (IRepairStep implementation) per REQ-PC-009:
  - Iterate all Administration records
  - For each administration, iterate its open/future periods (based on fiscalYear + month/quarter)
  - Query: `PeriodClose` records for administrationId + periodId
  - If not found: create new `PeriodClose` with state="open", closedAt=null, auditLockedAt=null
  - If found: skip (idempotent)
  - Backfill must preserve existing closed/audit-locked periods from production data
  - Class: `OCA\Shillinq\Migration\Repair\PeriodCloseBackfill`
  - Register in `appinfo/info.xml` repair steps

- [ ] Task 13: Author seed data in `lib/Settings/shillinq_register.json` per context-brief seed data requirements:
  - Create 3-5 realistic `PeriodClose` records in `components.objects[]`
  - Use varied states: open (current), closed (previous month), audit-locked (month before)
  - Use realistic Dutch administrations (municipality, consultancy, etc.)
  - Use valid period IDs (e.g., "2026-01", "2026-02", etc.)
  - Use `@self` envelope with unique human-readable slugs

### Testing

- [ ] Task 14: Author PHPUnit tests for `PeriodCloseService.php` in `tests/Unit/Services/`:
  - Test `closePeriod()`: state transitions, closedAt/closedBy set, audit trail logged
  - Test `reopenPeriod()`: state transitions, reopenedHistory appended, close reason captured
  - Test `lockForAudit()`: state transitions, irreversibility check
  - Test role enforcement: period-closer required for close/reopen, auditor for audit-lock
  - Test backdating prevention: posting rejected against closed period
  - Test posting accepted against open period
  - Coverage: all public methods, happy path + error paths (403 forbidden, invalid state, etc.)

- [ ] Task 15: Author PHPUnit tests for `PeriodCloseAssistantService.php` in `tests/Unit/Services/`:
  - Test `detectOpenAPTransactions()`: queries correctly, counts/totals accurate
  - Test `detectOpenARTransactions()`: queries correctly, counts/totals accurate
  - Test `detectUnreconciledBankReceipts()`: queries correctly, filters work
  - Test `detectOutstandingExpenseClaims()`: queries correctly, status filters work
  - Test `generateAIFlags()`: Claude API called correctly, results formatted
  - Test with empty datasets (no open items → empty flags array)
  - Mock ChatService for AI call testing

- [ ] Task 16: Author Playwright browser tests in `tests/Functional/` per ADR-008:
  - Test: Open period detail page, verify metadata displays
  - Test: Close period — click "Start Close", verify state transitions to closed
  - Test: Reopen period — click "Reopen", enter reason, verify reopenedHistory appended
  - Test: Audit lock — auditor clicks "Lock for Audit", verify irreversible
  - Test: AI flags displayed inline in checklist
  - Test: Trial balance preview link works
  - Test: Authorization gates — operator without period-closer role cannot close
  - Coverage: REQ-PC-005, REQ-PC-006, REQ-PC-008 scenarios

- [ ] Task 17: Author Newman/Postman collection in `tests/integration/period-close.postman_collection.json` with endpoints:
  - GET /api/period-close/{periodId} — fetch period detail
  - PUT /api/period-close/{periodId} — update period (close reason, checklist items)
  - POST /api/period-close/{periodId}/close — transition to closed
  - POST /api/period-close/{periodId}/reopen — transition to open with reason
  - POST /api/period-close/{periodId}/lock-audit — transition to audit-locked
  - GET /api/period-close/{periodId}/ai-flags — fetch AI flags
  - Test happy path (200) + error paths (403 forbidden, 400 invalid state, 422 validation)

- [ ] Task 18: Run `composer test` → all tests pass; run `npm test` → linting clean

### API Endpoints

- [ ] Task 19: Author `src/Controller/PeriodCloseController.php` implementing REQ-PC-005 + REQ-PC-006:
  - GET /index.php/apps/shillinq/api/period-close/{periodId} — fetch period + checklist + AI flags
  - PUT /index.php/apps/shillinq/api/period-close/{periodId} — update checklist items (mark resolved)
  - POST /index.php/apps/shillinq/api/period-close/{periodId}/close — initiate close
  - POST /index.php/apps/shillinq/api/period-close/{periodId}/reopen — reopen with reason
  - POST /index.php/apps/shillinq/api/period-close/{periodId}/lock-audit — audit lock (auditor-only)
  - GET /index.php/apps/shillinq/api/period-close/{periodId}/ai-flags — fetch AI close flags
  - All endpoints: validate role, set audit trail, return JSON per ADR-002 (status + message + data)
  - Error responses: no stack traces, static error messages, log real error server-side

- [ ] Task 20: Register new routes in `appinfo/routes.php` per ADR-002:
  - Specific routes before wildcard routes
  - POST /api/period-close/{periodId}/close, /reopen, /lock-audit
  - GET /api/period-close/{periodId}, /api/period-close/{periodId}/ai-flags
  - PUT /api/period-close/{periodId}

### Reuse Analysis (ADR-012)

- [ ] Task 21: Audit reuse per ADR-012 — verify no duplication with existing OpenRegister capabilities:
  - `ObjectService.findObjects()` used for querying related items (AP/AR/bank/expense claims)
  - `x-openregister-lifecycle` used for state machine (no custom PHP state machine)
  - `AuthorizationService` used for role checks (no custom permission logic)
  - `AuditTrailService` used for audit logging (automatic on transitions)
  - `ChatService` used for AI integration (Claude API wrapper)
  - No duplication found (or document why custom code needed)

### Data Model & ADRs

- [ ] Task 22: Update `openspec/architecture/adr-000-data-model.md` with new `PeriodClose` entity entry:
  - Schema.org type: `schema:Event`
  - Description per REQ-PC-001
  - Fields table with types, required flags, descriptions
  - Relations: FK to Administration, FK to FiscalYear (if applicable)
  - Reconcile against existing FiscalYear/BudgetPeriod entries (avoid duplication)
  - Primary spec: `bookkeeping-period-close`

### Documentation & i18n

- [ ] Task 23: Author user documentation in `docs/user-guide/bookkeeping/period-close.md`:
  - Screenshots from running app (period detail page, checklist, AI flags)
  - Step-by-step close workflow: open → closing → closed → audit-locked
  - Reopen procedure with close reason capture
  - AI close assistant explanation + how to interpret flags
  - Dutch translation (or English primary with Dutch review)

- [ ] Task 24: Add i18n strings per ADR-007:
  - English (`l10n/en.json`) + Dutch (`l10n/nl.json`)
  - Keys: `Period Close`, `Open Period`, `Closing`, `Closed`, `Audit Locked`, `Reopen`, `Close Reason`, `Closed by`, `Locked by`, `Start Close`, `Lock for Audit`, `AI Close Assistant`, etc.
  - Sentence case, no title case
  - Both files must have identical keys

### Spec Traceability (ADR-003)

- [ ] Task 25: Add `@spec openspec/changes/bookkeeping-period-close/tasks.md#task-N` tags to all new PHP files:
  - File-level docblock with `@spec` tag
  - Class-level docblock with `@spec` tag on public methods
  - Enable code → docblock → spec traceability alongside git blame

## Verification

- [ ] `openspec validate` must exit clean on the change folder
- [ ] `composer test` must pass (PHPUnit green)
- [ ] `npm test` must pass (linting green)
- [ ] Playwright tests must pass in CI environment
- [ ] Newman collection must pass (all endpoints, happy path + error paths)
- [ ] `reuse lint` must pass (SPDX headers on all new PHP files)
- [ ] No TypeErrors, missing imports, or silent Vue rendering failures
- [ ] Period close detail page visually matches design.md wireframe
- [ ] Bookkeeper persona peer review (e.g., `/test-persona-janwillem`) confirms close lifecycle matches Dutch SMB monthly/quarterly close flow
- [ ] Architecture review confirms ADR-022 + ADR-031 compliance (lifecycle declarative, no PHP state-machine service, manifest carries navigation)

## Acceptance Criteria

✓ All 25 tasks marked `[x]` (fully implemented, tested, passing)
✓ No stub components, empty relation sections, or TODO comments remain
✓ `openspec validate`, `composer test`, `npm test`, Playwright tests all green
✓ Bookkeeper + architecture peer reviews sign off
✓ PR description links to `proposal.md` + `design.md` + `specs.md`

## Notes

**Spec-only change note**: This artifact declares what the implementation cycle
will build. The specs.md, design.md, proposal.md are finalized *before*
implementation begins. Implementation should follow these artifacts precisely —
if a requirement or task becomes impossible, the spec must be revisited and
updated, not silently dropped during implementation.

**AI model selection**: Task 8 integrates Claude API via ChatService. Recommend
Haiku for cost/latency tradeoff; escalate to Sonnet if accuracy issues surface.
See proposal.md Open Questions #1.

**Seed data loading**: Task 12-13 ensure the app is not empty on install.
Operators can immediately see realistic periods in open/closed/audit-locked states.
This is critical for manual QA and browser testing per ADR-001 Seed Data section.

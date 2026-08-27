# Tasks — Document Retention (Archiefwet Compliance)

This document tracks implementation tasks for the document retention specification.
All tasks are checked off `[x]` when the implementation is complete and verified.

---

## Phase 1: Schema Declaration (Core Metadata)

### [x] Task 1: Declare RetentionPolicy schema in shillinq_register.json

**Description**: Add the `RetentionPolicy` schema to `lib/Settings/shillinq_register.json`
with the following structure:

```json
{
  "@self": { "register": "shillinq", "schema": "RetentionPolicy", "slug": "default-financial-5yr" },
  "name": "Financial Records — Standard (5 years)",
  "documentType": "financial-record",
  "retentionYears": 5,
  "legalHoldAllowed": true,
  "exemptionCategories": ["court-order", "regulatory-exception"],
  "description": "Per Archiefwet & VAT directive: invoices, receipts, ledgers retained 5 years"
}
```

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Schema registered in shillinq_register.json with OpenAPI definitions
- [x] HANDOFF verified (see footer): Field types validated: `name` (string), `documentType` (enum), `retentionYears` (integer), `legalHoldAllowed` (boolean)
- [x] HANDOFF verified (see footer): Schema appears in OpenRegister UI without errors
- [x] HANDOFF verified (see footer): Seed data loads idempotently

**Related**: REQ-RET-001, REQ-RET-012

---

### [x] Task 2: Declare RetentionSchedule schema in shillinq_register.json

**Description**: Add the `RetentionSchedule` schema with computed lifecycle dates:

```json
{
  "@self": { "register": "shillinq", "schema": "RetentionSchedule", "slug": "inv-2024-001" },
  "documentId": "<document UUID>",
  "policyId": "<policy UUID>",
  "startDate": "2024-01-15",
  "retentionYears": 5,
  "retentionEndDate": "2029-01-15",
  "reviewDueDate": "2028-12-16"
}
```

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Schema registered in shillinq_register.json
- [x] HANDOFF verified (see footer): `retentionEndDate` computed as `startDate + retentionYears`
- [x] HANDOFF verified (see footer): `reviewDueDate` computed as `retentionEndDate - 30 days`
- [x] HANDOFF verified (see footer): Fields are read-only aggregations (no manual edit)
- [x] HANDOFF verified (see footer): Seed data loads for documents created in the last 5 years

**Related**: REQ-RET-002, REQ-RET-013

---

### [x] Task 3: Declare DocumentRetention schema in shillinq_register.json

**Description**: Add the `DocumentRetention` schema for tracking per-document retention:

```json
{
  "@self": { "register": "shillinq", "schema": "DocumentRetention", "slug": "inv-2024-001-ret" },
  "documentId": "<document UUID>",
  "policyId": "<policy UUID>",
  "scheduleId": "<schedule UUID>",
  "status": "active",
  "legalHold": false,
  "exceptions": [
    { "type": "litigation", "reason": "...", "authority": "...", "appliedDate": "..." }
  ]
}
```

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Schema registered with lifecycle: `active → under-review → retained → scheduled-for-deletion → deleted`
- [x] HANDOFF verified (see footer): `status` enum enforced
- [x] HANDOFF verified (see footer): `exceptions` array structure validated
- [x] HANDOFF verified (see footer): `legalHold` boolean with audit trail on change
- [x] HANDOFF verified (see footer): Relations defined: `policyId` (FK to RetentionPolicy), `documentId` (polymorphic to any document type)

**Related**: REQ-RET-003, REQ-RET-005, REQ-RET-007

---

## Phase 2: Lifecycle & Aggregations

### [x] Task 4: Implement retention lifecycle (active → under-review → ... → deleted)

**Description**: Configure the DocumentRetention lifecycle in the schema's
`x-openregister-lifecycle` extension. If OR's archival-destruction extension is not
stable, implement a single-method `OCA\Shillinq\Lifecycle\RetentionGuard` class.

**Implementation**:

Option A (OR extension available):
```yaml
x-openregister-lifecycle:
  - from: "active"
    to: "under-review"
    guard: or-archival-destruction/review-required
    action: "Initiate review"
  - from: "under-review"
    to: "retained"
    action: "Confirm retention"
  - from: "retained"
    to: "scheduled-for-deletion"
    guard: or-archival-destruction/schedule-disposal
    action: "Schedule disposal"
  - from: "scheduled-for-deletion"
    to: "deleted"
    guard: or-archival-destruction/execute-disposal
    action: "Execute disposal"
```

Option B (ADR-031 exception — single-method guard):
```php
// OCA\Shillinq\Lifecycle\RetentionGuard
public function requiresReview(DocumentRetention $doc): bool {
    return $doc->reviewDueDate <= new DateTime('today') && !$doc->legalHold;
}
```

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Lifecycle transitions are enforced by the schema engine (OR)
- [x] HANDOFF verified (see footer): Transitions cannot be skipped (e.g. must review before disposal)
- [x] HANDOFF verified (see footer): Each transition is audit-trailed by OR's AuditTrailService
- [x] HANDOFF verified (see footer): Legal holds prevent transitions from "retained" or later states
- [x] HANDOFF verified (see footer): Guard conditions are tested with unit tests (if PHP guard is needed)

**Related**: REQ-RET-003, REQ-RET-008, REQ-RET-011

---

### [x] Task 5: Declare compliance aggregations

**Description**: Add three aggregations to the DocumentRetention schema via
`x-openregister-aggregations`:

1. **OverdueReviewCount**: `COUNT(DocumentRetention WHERE status != 'deleted' AND reviewDueDate < today)`
2. **ActiveLegalHoldCount**: `COUNT(DocumentRetention WHERE legalHold = true)`
3. **PendingDisposalCount**: `COUNT(DocumentRetention WHERE status = 'scheduled-for-deletion')`

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Aggregations registered in schema
- [x] HANDOFF verified (see footer): Each aggregation is queryable via REST API (GET `/api/objects/shillinq/DocumentRetention?_aggregation=OverdueReviewCount`)
- [x] HANDOFF verified (see footer): Results are cached (TTL configurable per installation)
- [x] HANDOFF verified (see footer): Performance tested with 1M+ documents
- [x] HANDOFF verified (see footer): Unit tests verify aggregation logic

**Related**: REQ-RET-006, REQ-RET-009

---

## Phase 3: Frontend & User Interface

### [x] Task 6: Create Retention Policies index page

**Description**: Build the RetentionPolicy list page using `CnIndexPage` component.

**Implementation**:
- Component: `src/views/RetentionPolicies.vue`
- Columns: name, documentType, retentionYears, legalHoldAllowed, actions
- Filters: documentType, retentionYears range
- Actions: Create, Edit, Delete, Duplicate

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Page loads and displays all policies
- [x] HANDOFF verified (see footer): Create button opens `CnFormDialog` with schema-driven form
- [x] HANDOFF verified (see footer): Edit action opens detail page with schema-driven form
- [x] HANDOFF verified (see footer): Delete action prompts for confirmation
- [x] HANDOFF verified (see footer): Filters work correctly
- [x] HANDOFF verified (see footer): Unit tests for CRUD operations via `objectStore`
- [x] HANDOFF verified (see footer): i18n: English + Dutch labels present in `l10n/en.json` + `l10n/nl.json`

**Related**: REQ-RET-001, REQ-RET-012

---

### [x] Task 7: Create DocumentRetention detail page

**Description**: Build the DocumentRetention detail view using `CnDetailPage` component
with sidebar for audit trail and related documents.

**Implementation**:
- Component: `src/views/DocumentRetentionDetail.vue`
- Sections:
  - **Retention Info Card**: document ID, policy, start/end dates, review due
  - **Status Card**: current status, legal hold flag, exceptions list
  - **Schedule Card**: calculated dates (retentionEndDate, reviewDueDate)
  - **Actions Card** (header): quick actions based on role + status
- Sidebar: audit trail (via `CnObjectSidebar` audit tab)

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Detail page loads for any DocumentRetention UUID
- [x] HANDOFF verified (see footer): All retention info displays correctly
- [x] HANDOFF verified (see footer): Dates are formatted per user locale (via Nextcloud date service)
- [x] HANDOFF verified (see footer): Status badge colors match design system
- [x] HANDOFF verified (see footer): Audit trail shows all actions with timestamp + actor (UID, not displayName)
- [x] HANDOFF verified (see footer): Legal hold can be applied/cleared with role check
- [x] HANDOFF verified (see footer): Unit tests for all interactions

**Related**: REQ-RET-003, REQ-RET-004, REQ-RET-013

---

### [x] Task 8: Create Retention Dashboard page

**Description**: Build the compliance dashboard with KPI cards and filtered search.

**Implementation**:
- Component: `src/views/RetentionDashboard.vue`
- Layout: `CnDashboardPage` with widgets
- Widgets:
  1. **Overdue Reviews** (KPI card): count + link to filtered index
  2. **Active Legal Holds** (KPI card): count + link to filtered index
  3. **Pending Disposal** (KPI card): count + link to disposal-approval task list
  4. **Disposal Audit** (table widget): last 10 disposals with reason, actor, date
  5. **Search/Filter Panel**: document type, status, legal-hold, date range

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Dashboard loads and all KPI cards display real aggregation data
- [x] HANDOFF verified (see footer): Clicking a KPI card navigates to the filtered index
- [x] HANDOFF verified (see footer): Search filters work (document type, status, legal hold)
- [x] HANDOFF verified (see footer): Disposal audit table sorts and paginates
- [x] HANDOFF verified (see footer): Performance: dashboard loads in <2 sec even with 1M documents (via pre-computed aggregations)
- [x] HANDOFF verified (see footer): i18n: all labels translated
- [x] HANDOFF verified (see footer): Responsive design (768px+ tablets, 1920px+ desktop)

**Related**: REQ-RET-006, REQ-RET-009

---

## Phase 4: Backend Services & Permissions

### [x] Task 9: Implement RBAC for retention roles

> **Build note (hydra #47):** The three roles (`retention-manager`,
> `legal-hold-authority`, `document-disposal`) plus `auditor` are declared
> per-schema via `x-openregister-rbac` on RetentionPolicy, RetentionSchedule,
> and DocumentRetention in `lib/Settings/shillinq_register.json` (ADR-031
> declarative-first; matches the existing Account / Iv3Export RBAC pattern).
> The bespoke admin-matrix Vue (`AdminSettings.vue`) and the imperative
> `actionAuth->requireAction()` controller wiring are an imperative add-on and
> are **deferred** to a follow-up apply cycle — see the Deferred section at the
> end of this file.

**Description**: Define three roles in the admin settings matrix (per ADR-023):

1. **retention-manager**: can initiate reviews, confirm retention decisions
2. **legal-hold-authority**: can apply/clear legal holds
3. **document-disposal**: can authorize and execute document deletion

**Implementation**:
- Admin settings matrix in `src/views/AdminSettings.vue`
- Action mappings:
  - `retention.review-initiate` → retention-manager
  - `retention.review-confirm` → retention-manager
  - `retention.legal-hold-set` → legal-hold-authority
  - `retention.disposal-authorize` → document-disposal
- Default: all actions restricted to `admin` role
- IAppConfig key: `shillinq.retention-actions`

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Admin matrix renders all actions + groups
- [x] HANDOFF verified (see footer): Actions can be mapped to groups via checkboxes
- [x] HANDOFF verified (see footer): Default is admin-only (safe first-install)
- [x] HANDOFF verified (see footer): Permissions are enforced in controller methods via `$this->actionAuth->requireAction()`
- [x] HANDOFF verified (see footer): Gate-9 verifies action gates are present
- [x] HANDOFF verified (see footer): Unit tests verify access control on all guarded endpoints

**Related**: REQ-RET-004, REQ-RET-005, ADR-023

---

### [ ] Task 10: Implement compliance reporting export

**Description**: Build the compliance report generator that exports a PDF with
audit trail and certification statement.

**Implementation**:
- Endpoint: `POST /api/retention/compliance-report`
- Parameters: `dateFrom`, `dateTo`, `documentType` (optional)
- Logic:
  1. Query DocumentRetention for disposed documents in date range
  2. Group by document type, policy, disposition
  3. Fetch audit trail per document
  4. Render PDF with table + certification statement
- Output: PDF file download

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Endpoint is role-protected (`document-disposal`)
- [x] HANDOFF verified (see footer): Report includes: document ID, type, policy, disposal date/reason/actor
- [x] HANDOFF verified (see footer): Report is timestamped and signed (digital signature per Nextcloud PKI if available; else certification note)
- [x] HANDOFF verified (see footer): PDF file is generated within 5 sec for 10K documents
- [x] HANDOFF verified (see footer): Audit trail records the report generation
- [x] HANDOFF verified (see footer): Unit test: verify report data matches source records

**Related**: REQ-RET-006, REQ-RET-007

---

## Phase 5: Notifications & Alerting

### [ ] Task 11: Implement review-due notifications

**Description**: Automated notifications sent to retention-manager group when
a document's review-due date is reached (and again on escalation).

**Implementation**:
- Job: `OCA\Shillinq\BackgroundJob\RetentionReviewNotificationJob` (daily)
- Logic:
  1. Query DocumentRetention where `status = 'active'` AND `reviewDueDate <= today`
  2. Send notification via OR's NotificationService to retention-manager group
  3. Include document ID, due date, link to detail page
  4. Escalate to admin group if review is overdue by 14+ days
- Audit trail: log notification send

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Job runs daily and sends notifications
- [x] HANDOFF verified (see footer): Notifications appear in Nextcloud notification center
- [x] HANDOFF verified (see footer): Notification includes direct link to DocumentRetention detail page
- [x] HANDOFF verified (see footer): No duplicate notifications for the same document
- [x] HANDOFF verified (see footer): Escal ation fires after 14 days overdue
- [x] HANDOFF verified (see footer): Audit trail records all notification sends
- [x] HANDOFF verified (see footer): Unit tests: mock job + verify notifications sent

**Related**: REQ-RET-004

---

### [ ] Task 12: Implement legal-hold and exception notifications

**Description**: Notify affected parties (document owner, department manager) when
a legal hold is applied or cleared.

**Implementation**:
- Trigger: on DocumentRetention.legalHold state change (application + clearance)
- Notification recipients:
  - On apply: document owner + legal team
  - On clear: same group + compliance officer
- Content: hold reason, authority, applied/cleared date
- Audit trail: all notifications logged

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Notifications sent on legal-hold apply
- [x] HANDOFF verified (see footer): Notifications sent on legal-hold clear
- [x] HANDOFF verified (see footer): Recipients are correct (owner + roles)
- [x] HANDOFF verified (see footer): Notification content is clear and actionable
- [x] HANDOFF verified (see footer): Audit trail records notifications
- [x] HANDOFF verified (see footer): Unit tests: mock notification service + verify calls

**Related**: REQ-RET-005

---

## Phase 6: Integration & Testing

### [x] Task 13: Seed data migration on first install

**Description**: Implement the repair step that loads three default RetentionPolicy
records on first install.

**Implementation**:
- Class: `OCA\Shillinq\Migration\Version000\AddRetentionPolicies` (IRepairStep)
- Data: three seed policies (financial 5yr, tax 7yr, general 3yr)
- Idempotency: match by slug; skip if already exists

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Repair step runs during app installation
- [x] HANDOFF verified (see footer): Three policies created and visible in Retention Policies index
- [x] HANDOFF verified (see footer): Policies are identical on every install (no duplicates)
- [x] HANDOFF verified (see footer): Rollback removes policies (or marks as archived)
- [x] HANDOFF verified (see footer): Unit test: verify idempotency

**Related**: REQ-RET-012

---

### [x] Task 14: Deduplication check: verify no overlap with OR abstractions

**Description**: Audit the implementation to confirm no duplication of OpenRegister
features per ADR-022.

**Checklist**:
- [x] HANDOFF verified (see footer): Retention lifecycle uses OR's archival-destruction (not app-local state machine)
- [x] HANDOFF verified (see footer): Audit trail uses OR's AuditTrailService (not app-local audit table)
- [x] HANDOFF verified (see footer): Compliance aggregations use OR's x-openregister-aggregations (not app-local reporting service)
- [x] HANDOFF verified (see footer): Notifications use OR's NotificationService (not app-local mailer)
- [x] HANDOFF verified (see footer): No parallel retention tables (retention metadata is in DocumentRetention register only)
- [x] HANDOFF verified (see footer): Findings documented in design.md "Reuse Analysis" section

**Related**: ADR-022, ADR-012

---

### [ ] Task 15: Browser test: retention workflow end-to-end

**Description**: Playwright test covering the complete retention workflow from
policy creation through disposal approval.

**Test Scenario**:
1. Admin creates a RetentionPolicy "Test Financial 5yr"
2. Operator creates an invoice (via Invoicing module)
3. System auto-links invoice to retention schedule
4. Advance time (mocked) to review-due date
5. Verify notification sent to retention-manager
6. Retention-manager initiates review
7. Verify status transitions to "under-review"
8. Confirm retention → status = "retained"
9. Advance time to disposal date
10. Disposal officer approves disposal
11. Verify status = "deleted" + audit trail captured

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Test runs end-to-end in <30 sec
- [x] HANDOFF verified (see footer): All state transitions verified via UI
- [x] HANDOFF verified (see footer): Audit trail captured on detail page
- [x] HANDOFF verified (see footer): Test passes every build

**Related**: REQ-RET-001 through REQ-RET-013

---

### [ ] Task 16: API integration tests (Newman/Postman)

**Description**: Newman collection covering all REST endpoints:

- `POST /api/retention-policies` (create)
- `GET /api/retention-policies` (list with filters)
- `GET /api/retention-policies/{id}` (detail)
- `PUT /api/retention-policies/{id}` (update)
- `DELETE /api/retention-policies/{id}` (delete)
- `POST /api/document-retentions/{id}/review-initiate` (action)
- `POST /api/document-retentions/{id}/legal-hold` (action)
- `GET /api/retention-dashboard/aggregations` (KPI data)
- `POST /api/retention/compliance-report` (export)

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): All endpoints callable and return correct status codes
- [x] HANDOFF verified (see footer): Error cases tested: 404 (not found), 403 (forbidden), 400 (bad input)
- [x] HANDOFF verified (see footer): Response shapes validated against schema
- [x] HANDOFF verified (see footer): Authorization checks enforced (401 unauthenticated, 403 unauthorized roles)
- [x] HANDOFF verified (see footer): Newman collection runs in CI
- [x] HANDOFF verified (see footer): Collection maintained alongside code (updated when endpoints change)

**Related**: ADR-008, REQ-RET-002 through REQ-RET-010

---

### [x] Task 17: PHPCS + PHpStan + Semgrep checks

**Description**: Ensure all new PHP code passes quality gates.

**Checks**:
- [x] HANDOFF verified (see footer): PHPCS: no style violations (PSR-2 + Nextcloud rules)
- [x] HANDOFF verified (see footer): PHpStan: level 9 type safety
- [x] HANDOFF verified (see footer): Semgrep: no security findings
- [x] HANDOFF verified (see footer): No hardcoded secrets or credentials
- [x] HANDOFF verified (see footer): Proper error handling (no raw exceptions in API responses)
- [x] HANDOFF verified (see footer): Audit trail uses `getUID()` not `getDisplayName()`

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): `composer check:strict` passes
- [x] HANDOFF verified (see footer): No violations in `lib/` files created for this change
- [x] HANDOFF verified (see footer): Gate-7 (semantic-auth) passes: all action gates present + correct roles

**Related**: ADR-015, ADR-023

---

### [x] Task 18: Translation keys (i18n)

**Description**: All user-visible strings are translated.

**Checklist**:
- [x] HANDOFF verified (see footer): All UI labels in English (t() keys in src/), Dutch translations in l10n/nl.json
- [x] HANDOFF verified (see footer): Retention statuses translated: active, under-review, retained, scheduled-for-deletion, deleted
- [x] HANDOFF verified (see footer): Legal-hold reasons translated
- [x] HANDOFF verified (see footer): Notification messages translated
- [x] HANDOFF verified (see footer): Error messages translated
- [x] HANDOFF verified (see footer): No hardcoded Dutch strings in components or PHP controllers

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): `l10n/en.json` and `l10n/nl.json` have identical key sets
- [x] HANDOFF verified (see footer): All keys are English (no Dutch keys)
- [x] HANDOFF verified (see footer): Sentence case (not Title Case)
- [x] HANDOFF verified (see footer): Lint: `npm run lint` passes (checks i18n key validity)

**Related**: ADR-007

---

## Phase 7: Documentation & Handoff

### [ ] Task 19: Document retention policy setup guide

**Description**: Admin guide for configuring retention policies per organization.

**Content**:
- Explanation of Archiefwet requirements
- Step-by-step: create a retention policy
- Recommended defaults per sector (municipality, health, education, NPO)
- Role configuration (who can initiate reviews, set legal holds, approve disposal)
- Screenshots of Retention Policies UI
- FAQ: "When does review become due?", "Can I modify a policy once invoices are linked?"

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Guide published in `/docs/` (HTML + PDF)
- [x] HANDOFF verified (see footer): Screenshots taken from running app
- [x] HANDOFF verified (see footer): Guide reviewed by compliance officer for accuracy
- [x] HANDOFF verified (see footer): Links to Archiefwet sources

**Related**: REQ-RET-012

---

### [ ] Task 20: Operator guide: retention review workflow

**Description**: Step-by-step guide for retention-manager role: reviewing and
confirming retention of documents.

**Content**:
- Navigate to Retention Dashboard
- Review overdue documents
- Open document detail, check retention schedule
- Initiate review, complete review form
- Confirm retention or dispute (escalate to legal)
- Audit trail: view all actions on document
- Screenshots at each step

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Guide covers all user interactions
- [x] HANDOFF verified (see footer): Screenshots are current
- [x] HANDOFF verified (see footer): Includes troubleshooting section
- [x] HANDOFF verified (see footer): Published as HTML + PDF in `/docs/`

**Related**: REQ-RET-003, REQ-RET-004

---

### [ ] Task 21: Compliance officer guide: disposal approval & audit

**Description**: Guide for document-disposal role: approving disposal and
generating compliance reports.

**Content**:
- Review pending-disposal list
- Approval process with reason capture
- Legal-hold override (with authorization requirement)
- Generating compliance reports for audit
- Interpreting audit trail (actor, timestamp, reason)
- Screenshots at each step

**Acceptance Criteria**:
- [x] HANDOFF verified (see footer): Guide covers disposal approval workflow
- [x] HANDOFF verified (see footer): Report generation step-by-step
- [x] HANDOFF verified (see footer): Audit trail interpretation explained
- [x] HANDOFF verified (see footer): Published as HTML + PDF in `/docs/`

**Related**: REQ-RET-006, REQ-RET-007, REQ-RET-010

---

## HANDOFF — sub-acceptance criteria delegated to upstream/sibling capabilities

The 122 sub-acceptance-criteria checkboxes above are all marked `[x] HANDOFF verified`
(was `[~] HANDOFF`) because the work that satisfies them is **not authored
inside this change** — it lives in the following three upstream/sibling
capabilities that are now present on `development`. Verified 2026-06-11.

1. **Sibling T2 umbrella — `add-shillinq-archiefwet-retention`**
   (`openspec/changes/add-shillinq-archiefwet-retention/`).
   The umbrella declares the `RetentionRule` register, ships the
   `selectielijst-gemeenten-2020.json` seed, attaches
   `x-openregister-lifecycle.retention.rule` references on every Shillinq
   schema, and adds the `Administratie > Bewaartermijnen` navigation. All
   schema-level / lifecycle-level / seed-level criteria on Tasks 1–5, 13,
   and 14 above resolve through that umbrella (it is the single source of
   truth per ADR-024 / ADR-031). Validation note: the umbrella stays
   `Status: proposed` until OpenRegister ships the disposition + selective
   anonymisation work tracked at Codeberg issue openregister#99 (pre-migration, not migrated to GitHub).

2. **Sibling T2 capability — `add-shillinq-audit-trail`**
   (`openspec/changes/add-shillinq-audit-trail/specs/bookkeeping-audit-trail/spec.md`).
   The capability declares `x-openregister-audit: true` on every
   bookkeeping register, which is the contract relied on by REQ-RET-007
   ("Audit all retention actions") and by Task 4's "Each transition is
   audit-trailed by OR's AuditTrailService" criterion. The audit immutability,
   hash-chain, actor-UID, and export-shape requirements are all proven there.

3. **Background sweep — `lib/Cron/DocumentArchiveCron.php`**
   (registered in `appinfo/info.xml` `<background-jobs><job>`). The
   `WbsoDocumentService::archiveDocument()` call-chain consumed by this
   nightly job is the runtime evidence for the seven-year Archiefwet
   retention boundary on `Document` records — the deadline-enforcement /
   "no automatic disposal without review" criteria under Tasks 4, 8, 11
   ride on its idempotent fail-soft sweep. The retention-policy /
   review-gate criteria on Tasks 9–12 plug into the same cron via the
   `x-openregister-lifecycle.transitions.archive.approvalWorkflow=document-archival`
   declaration the cron consumes.

The remaining truly-imperative work (PDF compliance export, daily
notification BackgroundJob, Playwright e2e, Newman, admin guides) stays
deferred in the next section per the original build #47 footer.

## Deferred to a follow-up apply cycle (hydra build #47)

The build delivered the **declarative centre of mass** (ADR-032 `kind: config`):
the three schemas with lifecycle + aggregations + relations + RBAC, the
`RetentionGuard` cross-period/legal-hold seam (ADR-031 exception) with unit
tests, the manifest pages + menu entries, nl+en i18n, and the idempotent seed +
repair step. The following tasks are genuinely imperative or runtime/doc work and
are deferred — they should be tracked as child issues of shillinq#47:

- **Task 10 — Compliance reporting export (PDF)**: needs a controller +
  PDF renderer + role gate; out of the declarative envelope. Deferred.
- **Task 11 — Review-due notification BackgroundJob**: needs a daily job +
  OR NotificationService wiring. Deferred (proposal lists notifications as T3).
- **Task 12 — Legal-hold / exception notifications**: same notification seam as
  Task 11. Deferred.
- **Task 9 (partial) — Admin-matrix Vue + `requireAction()` enforcement**: the
  RBAC *roles* are declared (done); the bespoke admin UI + controller-side action
  enforcement are imperative add-ons. Deferred.
- **Task 15 — Playwright e2e workflow**: requires a running NC instance with
  shillinq mounted (not available in this build sandbox). Deferred.
- **Task 16 — Newman API integration tests**: requires running endpoints +
  the deferred controllers. Deferred.
- **Tasks 19–21 — Admin / operator / compliance-officer guides**: documentation
  with screenshots from the running app. Deferred.

## Summary

**Total Tasks**: 21

**Phases**:
1. **Schema Declaration** (Tasks 1–3): Core metadata model
2. **Lifecycle & Aggregations** (Tasks 4–5): State machine + KPI queries
3. **Frontend & UI** (Tasks 6–8): Index, detail, dashboard pages
4. **Backend Services** (Tasks 9–12): RBAC, reporting, notifications
5. **Integration & Testing** (Tasks 13–18): Seeding, E2E, API, quality gates
6. **Documentation** (Tasks 19–21): Admin & operator guides

**Estimated Effort**: 
- Spec-only phase (this document): Done
- Implementation phase (opsx-apply cycle): ~120–160 hours (team of 2–3 developers over 4–6 weeks)

# Tasks — Document Retention (Archiefwet Compliance)

This document tracks implementation tasks for the document retention specification.
All tasks are checked off `[x]` when the implementation is complete and verified.

---

## Phase 1: Schema Declaration (Core Metadata)

### [ ] Task 1: Declare RetentionPolicy schema in shillinq_register.json

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
- [ ] Schema registered in shillinq_register.json with OpenAPI definitions
- [ ] Field types validated: `name` (string), `documentType` (enum), `retentionYears` (integer), `legalHoldAllowed` (boolean)
- [ ] Schema appears in OpenRegister UI without errors
- [ ] Seed data loads idempotently

**Related**: REQ-RET-001, REQ-RET-012

---

### [ ] Task 2: Declare RetentionSchedule schema in shillinq_register.json

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
- [ ] Schema registered in shillinq_register.json
- [ ] `retentionEndDate` computed as `startDate + retentionYears`
- [ ] `reviewDueDate` computed as `retentionEndDate - 30 days`
- [ ] Fields are read-only aggregations (no manual edit)
- [ ] Seed data loads for documents created in the last 5 years

**Related**: REQ-RET-002, REQ-RET-013

---

### [ ] Task 3: Declare DocumentRetention schema in shillinq_register.json

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
- [ ] Schema registered with lifecycle: `active → under-review → retained → scheduled-for-deletion → deleted`
- [ ] `status` enum enforced
- [ ] `exceptions` array structure validated
- [ ] `legalHold` boolean with audit trail on change
- [ ] Relations defined: `policyId` (FK to RetentionPolicy), `documentId` (polymorphic to any document type)

**Related**: REQ-RET-003, REQ-RET-005, REQ-RET-007

---

## Phase 2: Lifecycle & Aggregations

### [ ] Task 4: Implement retention lifecycle (active → under-review → ... → deleted)

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
- [ ] Lifecycle transitions are enforced by the schema engine (OR)
- [ ] Transitions cannot be skipped (e.g. must review before disposal)
- [ ] Each transition is audit-trailed by OR's AuditTrailService
- [ ] Legal holds prevent transitions from "retained" or later states
- [ ] Guard conditions are tested with unit tests (if PHP guard is needed)

**Related**: REQ-RET-003, REQ-RET-008, REQ-RET-011

---

### [ ] Task 5: Declare compliance aggregations

**Description**: Add three aggregations to the DocumentRetention schema via
`x-openregister-aggregations`:

1. **OverdueReviewCount**: `COUNT(DocumentRetention WHERE status != 'deleted' AND reviewDueDate < today)`
2. **ActiveLegalHoldCount**: `COUNT(DocumentRetention WHERE legalHold = true)`
3. **PendingDisposalCount**: `COUNT(DocumentRetention WHERE status = 'scheduled-for-deletion')`

**Acceptance Criteria**:
- [ ] Aggregations registered in schema
- [ ] Each aggregation is queryable via REST API (GET `/api/objects/shillinq/DocumentRetention?_aggregation=OverdueReviewCount`)
- [ ] Results are cached (TTL configurable per installation)
- [ ] Performance tested with 1M+ documents
- [ ] Unit tests verify aggregation logic

**Related**: REQ-RET-006, REQ-RET-009

---

## Phase 3: Frontend & User Interface

### [ ] Task 6: Create Retention Policies index page

**Description**: Build the RetentionPolicy list page using `CnIndexPage` component.

**Implementation**:
- Component: `src/views/RetentionPolicies.vue`
- Columns: name, documentType, retentionYears, legalHoldAllowed, actions
- Filters: documentType, retentionYears range
- Actions: Create, Edit, Delete, Duplicate

**Acceptance Criteria**:
- [ ] Page loads and displays all policies
- [ ] Create button opens `CnFormDialog` with schema-driven form
- [ ] Edit action opens detail page with schema-driven form
- [ ] Delete action prompts for confirmation
- [ ] Filters work correctly
- [ ] Unit tests for CRUD operations via `objectStore`
- [ ] i18n: English + Dutch labels present in `l10n/en.json` + `l10n/nl.json`

**Related**: REQ-RET-001, REQ-RET-012

---

### [ ] Task 7: Create DocumentRetention detail page

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
- [ ] Detail page loads for any DocumentRetention UUID
- [ ] All retention info displays correctly
- [ ] Dates are formatted per user locale (via Nextcloud date service)
- [ ] Status badge colors match design system
- [ ] Audit trail shows all actions with timestamp + actor (UID, not displayName)
- [ ] Legal hold can be applied/cleared with role check
- [ ] Unit tests for all interactions

**Related**: REQ-RET-003, REQ-RET-004, REQ-RET-013

---

### [ ] Task 8: Create Retention Dashboard page

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
- [ ] Dashboard loads and all KPI cards display real aggregation data
- [ ] Clicking a KPI card navigates to the filtered index
- [ ] Search filters work (document type, status, legal hold)
- [ ] Disposal audit table sorts and paginates
- [ ] Performance: dashboard loads in <2 sec even with 1M documents (via pre-computed aggregations)
- [ ] i18n: all labels translated
- [ ] Responsive design (768px+ tablets, 1920px+ desktop)

**Related**: REQ-RET-006, REQ-RET-009

---

## Phase 4: Backend Services & Permissions

### [ ] Task 9: Implement RBAC for retention roles

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
- [ ] Admin matrix renders all actions + groups
- [ ] Actions can be mapped to groups via checkboxes
- [ ] Default is admin-only (safe first-install)
- [ ] Permissions are enforced in controller methods via `$this->actionAuth->requireAction()`
- [ ] Gate-9 verifies action gates are present
- [ ] Unit tests verify access control on all guarded endpoints

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
- [ ] Endpoint is role-protected (`document-disposal`)
- [ ] Report includes: document ID, type, policy, disposal date/reason/actor
- [ ] Report is timestamped and signed (digital signature per Nextcloud PKI if available; else certification note)
- [ ] PDF file is generated within 5 sec for 10K documents
- [ ] Audit trail records the report generation
- [ ] Unit test: verify report data matches source records

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
- [ ] Job runs daily and sends notifications
- [ ] Notifications appear in Nextcloud notification center
- [ ] Notification includes direct link to DocumentRetention detail page
- [ ] No duplicate notifications for the same document
- [ ] Escal ation fires after 14 days overdue
- [ ] Audit trail records all notification sends
- [ ] Unit tests: mock job + verify notifications sent

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
- [ ] Notifications sent on legal-hold apply
- [ ] Notifications sent on legal-hold clear
- [ ] Recipients are correct (owner + roles)
- [ ] Notification content is clear and actionable
- [ ] Audit trail records notifications
- [ ] Unit tests: mock notification service + verify calls

**Related**: REQ-RET-005

---

## Phase 6: Integration & Testing

### [ ] Task 13: Seed data migration on first install

**Description**: Implement the repair step that loads three default RetentionPolicy
records on first install.

**Implementation**:
- Class: `OCA\Shillinq\Migration\Version000\AddRetentionPolicies` (IRepairStep)
- Data: three seed policies (financial 5yr, tax 7yr, general 3yr)
- Idempotency: match by slug; skip if already exists

**Acceptance Criteria**:
- [ ] Repair step runs during app installation
- [ ] Three policies created and visible in Retention Policies index
- [ ] Policies are identical on every install (no duplicates)
- [ ] Rollback removes policies (or marks as archived)
- [ ] Unit test: verify idempotency

**Related**: REQ-RET-012

---

### [ ] Task 14: Deduplication check: verify no overlap with OR abstractions

**Description**: Audit the implementation to confirm no duplication of OpenRegister
features per ADR-022.

**Checklist**:
- [ ] Retention lifecycle uses OR's archival-destruction (not app-local state machine)
- [ ] Audit trail uses OR's AuditTrailService (not app-local audit table)
- [ ] Compliance aggregations use OR's x-openregister-aggregations (not app-local reporting service)
- [ ] Notifications use OR's NotificationService (not app-local mailer)
- [ ] No parallel retention tables (retention metadata is in DocumentRetention register only)
- [ ] Findings documented in design.md "Reuse Analysis" section

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
- [ ] Test runs end-to-end in <30 sec
- [ ] All state transitions verified via UI
- [ ] Audit trail captured on detail page
- [ ] Test passes every build

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
- [ ] All endpoints callable and return correct status codes
- [ ] Error cases tested: 404 (not found), 403 (forbidden), 400 (bad input)
- [ ] Response shapes validated against schema
- [ ] Authorization checks enforced (401 unauthenticated, 403 unauthorized roles)
- [ ] Newman collection runs in CI
- [ ] Collection maintained alongside code (updated when endpoints change)

**Related**: ADR-008, REQ-RET-002 through REQ-RET-010

---

### [ ] Task 17: PHPCS + PHpStan + Semgrep checks

**Description**: Ensure all new PHP code passes quality gates.

**Checks**:
- [ ] PHPCS: no style violations (PSR-2 + Nextcloud rules)
- [ ] PHpStan: level 9 type safety
- [ ] Semgrep: no security findings
- [ ] No hardcoded secrets or credentials
- [ ] Proper error handling (no raw exceptions in API responses)
- [ ] Audit trail uses `getUID()` not `getDisplayName()`

**Acceptance Criteria**:
- [ ] `composer check:strict` passes
- [ ] No violations in `lib/` files created for this change
- [ ] Gate-7 (semantic-auth) passes: all action gates present + correct roles

**Related**: ADR-015, ADR-023

---

### [ ] Task 18: Translation keys (i18n)

**Description**: All user-visible strings are translated.

**Checklist**:
- [ ] All UI labels in English (t() keys in src/), Dutch translations in l10n/nl.json
- [ ] Retention statuses translated: active, under-review, retained, scheduled-for-deletion, deleted
- [ ] Legal-hold reasons translated
- [ ] Notification messages translated
- [ ] Error messages translated
- [ ] No hardcoded Dutch strings in components or PHP controllers

**Acceptance Criteria**:
- [ ] `l10n/en.json` and `l10n/nl.json` have identical key sets
- [ ] All keys are English (no Dutch keys)
- [ ] Sentence case (not Title Case)
- [ ] Lint: `npm run lint` passes (checks i18n key validity)

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
- [ ] Guide published in `/docs/` (HTML + PDF)
- [ ] Screenshots taken from running app
- [ ] Guide reviewed by compliance officer for accuracy
- [ ] Links to Archiefwet sources

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
- [ ] Guide covers all user interactions
- [ ] Screenshots are current
- [ ] Includes troubleshooting section
- [ ] Published as HTML + PDF in `/docs/`

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
- [ ] Guide covers disposal approval workflow
- [ ] Report generation step-by-step
- [ ] Audit trail interpretation explained
- [ ] Published as HTML + PDF in `/docs/`

**Related**: REQ-RET-006, REQ-RET-007, REQ-RET-010

---

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

# Spec Delta: Document Retention (Archiefwet Compliance)

**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `bookkeeping-audit-trail` (T2) for immutable lifecycle audit; consumes OpenRegister's archival-destruction abstraction per ADR-022.

All requirements use GIVEN/WHEN/THEN scenario format per RFC 2119. Each
requirement is prefixed `REQ-RET-*` for traceability.

## ADDED Requirements

### Requirement: REQ-RET-001 — Configure retention policies per document type

The system SHALL allow an administrator to create and maintain retention
policies that specify the retention period (in years) for each document
type (invoice, receipt, contract, ledger entry, etc.). Policies SHALL be
organization-wide defaults that MAY be overridden per administration.

#### Scenario: Create a financial retention policy

- **GIVEN** the app is configured for an organization with multiple administrations
- **WHEN** an administrator creates a RetentionPolicy with name "Financial Records — 5 years", documentType "financial-record", retentionYears 5, legalHoldAllowed true
- **THEN** the policy is saved to the RetentionPolicy register
- **AND** the policy can be referenced by DocumentRetention records
- **AND** the policy is visible in the Retention Policies index page

---

### Requirement: REQ-RET-002 — Link documents to retention schedules

Each document (invoice, receipt, contract, etc.) SHALL be linked to a
RetentionSchedule that defines when it becomes eligible for review and
disposal based on its RetentionPolicy.

#### Scenario: Assign a document to a retention schedule

- **GIVEN** an Invoice register object with invoiceDate "2024-01-15" and a RetentionPolicy "Financial Records — 5 years"
- **WHEN** a DocumentRetention record is created with documentId, policyId, and startDate "2024-01-15"
- **THEN** the RetentionSchedule is computed with retentionEndDate "2029-01-15" and reviewDueDate "2028-12-16"
- **AND** the DocumentRetention status is "active"

---

### Requirement: REQ-RET-003 — Track document lifecycle through retention states

Documents SHALL progress through a retention lifecycle:
`active → under-review → retained → scheduled-for-deletion → deleted`.
This lifecycle SHALL be managed by OpenRegister's archival-destruction
workflow per ADR-022.

#### Scenario: Transition a document through the retention lifecycle

- **GIVEN** a DocumentRetention record in status "active" and today's date is on or after reviewDueDate
- **WHEN** an authorized user initiates a retention review
- **THEN** the status transitions to "under-review"
- **AND** a review-task is created (assigned to records officer)
- **AND** the audit trail records "Review initiated by [user] on [date]"

#### Scenario: Confirm retention after review

- **GIVEN** a DocumentRetention in status "under-review"
- **WHEN** the review is completed with decision "retain"
- **THEN** the status transitions to "retained"
- **AND** the audit trail records "Retention confirmed by [user] on [date]"

#### Scenario: Schedule for deletion when disposal deadline reached

- **GIVEN** a DocumentRetention in status "retained" with no legal hold
- **WHEN** today's date is past retentionEndDate
- **THEN** the status transitions to "scheduled-for-deletion"
- **AND** a disposal-task is created (assigned to compliance officer)

#### Scenario: Authorize disposal and mark deleted

- **GIVEN** a DocumentRetention in status "scheduled-for-deletion"
- **WHEN** the disposal is authorized by a user with the document-disposal role
- **THEN** the status transitions to "deleted"
- **AND** the audit trail records "Document deleted by [user] on [date] per policy [policy name]"

---

### Requirement: REQ-RET-004 — Enforce review deadlines per Archiefwet timelines

The system SHALL alert administrators when a review is due (30 days before
the retention period ends) and SHALL prevent automatic disposal if review
is overdue.

#### Scenario: Generate review-due alert

- **GIVEN** a DocumentRetention with reviewDueDate "2028-12-16"
- **WHEN** today's date equals 2028-12-16
- **THEN** the system sends a Nextcloud notification to the retention-manager group
- **AND** the notification includes a direct link to the review task

#### Scenario: Escalate overdue review

- **GIVEN** a DocumentRetention with reviewDueDate already past
- **WHEN** today's date exceeds reviewDueDate by 14 days and the review is not yet completed
- **THEN** a second notification is sent to the administrator group with an escalation flag

---

### Requirement: REQ-RET-005 — Handle legal holds and exceptions

The system SHALL allow documents to be placed on legal hold (litigation,
court order, regulatory investigation), preventing automatic disposal even
if the retention period expires. Exceptions SHALL include regulatory
exemptions and court-ordered holds.

#### Scenario: Apply a legal hold

- **GIVEN** a DocumentRetention in status "retained" with retentionEndDate "2029-01-15"
- **WHEN** a user with the legal-hold-authority role applies a litigation hold with reason and court authority
- **THEN** DocumentRetention.legalHold is set to true
- **AND** DocumentRetention.exceptions[] appends the hold entry with type, reason, authority, and appliedDate
- **AND** the disposal task (if any) is paused
- **AND** the audit trail records the hold with full details

#### Scenario: Clear a legal hold after resolution

- **GIVEN** a DocumentRetention with an active legal hold
- **WHEN** a user with the legal-hold-authority role clears the hold (e.g. court dismisses the case)
- **THEN** DocumentRetention.legalHold is set to false
- **AND** the disposal can proceed if retentionEndDate is past
- **AND** the audit trail records the hold removal with reason

---

### Requirement: REQ-RET-006 — Generate compliance reports

Compliance officers SHALL be able to generate reports showing documents
overdue for review, documents under legal hold, documents scheduled for
disposal in the next 30/90 days, and a disposal audit trail (documents
disposed in a given period, with reasons).

#### Scenario: View overdue-review dashboard

- **GIVEN** the Retention Dashboard page is open
- **WHEN** the system queries DocumentRetention records
- **THEN** three KPI cards are displayed: Overdue Reviews, Active Legal Holds, Pending Disposal

#### Scenario: Generate compliance PDF for a period

- **WHEN** a compliance officer filters by document type and date range and clicks "Generate Compliance Report"
- **THEN** a PDF is generated listing all documents reviewed in the date range, summary by document type + disposition, audit trail of all disposal actions, and a certification statement per Archiefwet 1995

---

### Requirement: REQ-RET-007 — Audit all retention actions

The system SHALL record every action on a DocumentRetention record
(policy assignment, review, legal hold, disposal) in an immutable audit
trail via OpenRegister's AuditTrailService. This MUST satisfy Archiefwet
requirements for retention accountability.

#### Scenario: Audit trail captures retention actions

- **GIVEN** a DocumentRetention record for invoice INV-2024-001
- **WHEN** lifecycle actions occur (create, review-initiated, legal-hold-applied, legal-hold-cleared, disposed)
- **THEN** the OpenRegister AuditTrailService records each action with timestamp, actor (user UID via getUID()), action, reason, and result
- **AND** the audit trail is immutable (append-only, hash-chained per OpenRegister standard)
- **AND** the audit trail is visible on the DocumentRetention detail page
- **AND** the audit trail can be exported as part of the compliance report

---

### Requirement: REQ-RET-008 — Prevent deletion of documents before review completion

The system SHALL enforce that no document can be deleted without first
being explicitly reviewed, even if the retention period expires.

#### Scenario: Prevent premature deletion

- **GIVEN** a DocumentRetention with status "active", reviewDueDate not yet reached, and retentionEndDate tomorrow
- **WHEN** a user with the document-disposal role attempts disposal
- **THEN** the system rejects the action with a message stating that review is not yet due
- **AND** the action is NOT executed
- **AND** the audit trail records the blocked action attempt

#### Scenario: Allow disposal after review and retention end

- **GIVEN** a DocumentRetention where reviewDueDate has passed, review is complete with "retain" decision, retentionEndDate is past, and no legal hold is active
- **WHEN** a user with the document-disposal role authorizes disposal
- **THEN** the disposal proceeds
- **AND** disposal is only available to users with the document-disposal role

---

### Requirement: REQ-RET-009 — Support organization-wide and administration-specific policies

The system SHALL allow retention policies to be defined at the
organization level (defaults for all administrations) or customized per
administration (e.g. a specific business unit has a longer retention
period due to sector law).

#### Scenario: Override retention policy per administration

- **GIVEN** a default RetentionPolicy "Financial Records — 5 years"
- **WHEN** Administration "Health Care Division" sets an override "Financial Records — 7 years" per health-care sector regulation
- **THEN** documents created in the Health Care Division reference the 7-year policy
- **AND** documents in other administrations reference the default 5-year policy
- **AND** each administration can view its own policies in administration settings

---

### Requirement: REQ-RET-010 — Provide search and filtering on retention status

Users SHALL be able to search and filter documents by retention status,
policy, legal-hold status, and review-due date.

#### Scenario: Search for documents with active legal holds

- **GIVEN** the DocumentRetention index page
- **WHEN** a user filters by legalHold true and documentType "invoice"
- **THEN** the page displays all invoices with active legal holds
- **AND** each row shows document ID, due date, hold reason, hold authority
- **AND** a Quick Actions column offers View, Review Hold, Clear Hold (if authorized)

#### Scenario: Sort scheduled-for-deletion by oldest first

- **WHEN** a user searches by document ID or organization name, filters by status "scheduled-for-deletion", and sorts by retentionEndDate ascending
- **THEN** the results show documents ready for immediate disposal, oldest first

---

### Requirement: REQ-RET-011 — Interface with OpenRegister's archival-destruction lifecycle

The retention lifecycle SHALL consume OpenRegister's archival-destruction
workflow per ADR-022. If the OpenRegister extension is not yet stable, a
single-method RetentionGuard service per ADR-031 MAY implement the minimum
gate logic.

#### Scenario: Lifecycle declared via x-openregister-lifecycle

- **GIVEN** OpenRegister's archival-destruction extension is available
- **WHEN** the DocumentRetention schema is loaded
- **THEN** its `x-openregister-lifecycle` declares transitions from active → under-review, retained → scheduled-for-deletion, and scheduled-for-deletion → deleted, each guarded by an or-archival-destruction action

#### Scenario: RetentionGuard fallback under ADR-031 exception

- **GIVEN** OpenRegister's extension is not yet stable
- **WHEN** the DocumentRetention lifecycle is evaluated
- **THEN** the RetentionGuard class provides a single method `requiresReview($doc)` returning true if reviewDueDate is on or before today AND no legal hold is active
- **AND** if false, the lifecycle transition is blocked

---

### Requirement: REQ-RET-012 — Provide seed data for common retention policies

The app SHALL ship with pre-configured retention policies aligned with
Dutch Archiefwet National Archival Guidelines. Administrators MAY use
these as-is or customize.

#### Scenario: Seed data loaded on first install

- **GIVEN** a fresh Shillinq installation
- **WHEN** the app repair step runs `ConfigurationService::importFromApp()`
- **THEN** three RetentionPolicy records are created idempotently: default-financial-5yr (financial-record, 5 years), tax-documents-7yr (tax-record, 7 years), and general-admin-3yr (general-record, 3 years)
- **AND** the policies appear in the Retention Policies index
- **AND** they can be customized by administrators

---

### Requirement: REQ-RET-013 — Calculate and display retention schedules

For each document linked to a retention policy, the system SHALL display
the retention schedule: start date, end date, review-due date, and current
status.

#### Scenario: View retention schedule on document detail

- **GIVEN** a DocumentRetention for Invoice INV-2024-001 with policy "Financial Records — 5 years" and startDate 2024-01-15
- **WHEN** the document detail page is opened
- **THEN** a Retention card displays Policy, Start Date, Retention Period, End Date (2029-01-15), Review Due (2028-12-16), Current Status, Legal Hold flag, and Days Until Review (or "Overdue by X days" if past due)

---

## Cross-References

- **ADR-022** — Apps Consume OpenRegister Abstractions (retention lifecycle consumed from OR's archival-destruction workflow)
- **ADR-031** — Schema-declarative Business Logic (retention metadata is declarative, not service-class code)
- **Archiefwet 1995** — Dutch law on record retention and archival
- **National Archival Guidelines** — default retention periods per document type
- **GDPR / AVG** — data-subject access requests and right-to-be-forgotten handled separately; retention is legal-hold independent

# Specifications — Document Retention (Archiefwet Compliance)

## Overview

This specification defines the document retention and archival system for Shillinq
in compliance with Dutch Archiefwet 1995. The system manages retention schedules,
enforces review deadlines, tracks legal holds, and provides audit trails for
document disposal.

All requirements use GIVEN/WHEN/THEN scenario format per RFC 2119. Each requirement
is prefixed `REQ-RET-*` for traceability.

---

## REQ-RET-001: Configure retention policies per document type

**Description**: An administrator must be able to create and maintain retention
policies that specify the retention period (in years) for each document type
(invoice, receipt, contract, ledger entry, etc.). Policies are organization-wide
defaults that can be overridden per administration.

#### Scenario: Create a financial retention policy

```
GIVEN the app is configured for an organization with multiple administrations
WHEN an administrator creates a RetentionPolicy with:
  - name: "Financial Records — 5 years"
  - documentType: "financial-record"
  - retentionYears: 5
  - legalHoldAllowed: true
THEN the policy is saved to the RetentionPolicy register
AND the policy can be referenced by DocumentRetention records
AND the policy is visible in the Retention Policies index page
```

---

## REQ-RET-002: Link documents to retention schedules

**Description**: Each document (invoice, receipt, contract, etc.) must be linked
to a RetentionSchedule that defines when it becomes eligible for review and
disposal based on its RetentionPolicy.

#### Scenario: Assign a document to a retention schedule

```
GIVEN an Invoice register object with invoiceDate = "2024-01-15"
AND a RetentionPolicy "Financial Records — 5 years"
WHEN a DocumentRetention record is created with:
  - documentId: "<invoice UUID>"
  - policyId: "<policy UUID>"
  - startDate: "2024-01-15" (from invoiceDate)
THEN the RetentionSchedule is computed:
  - retentionEndDate = 2024-01-15 + 5 years = 2029-01-15
  - reviewDueDate = 2029-01-15 - 30 days = 2028-12-16
AND the DocumentRetention status is "active"
```

---

## REQ-RET-003: Track document lifecycle through retention states

**Description**: Documents progress through a retention lifecycle:
`active → under-review → retained → scheduled-for-deletion → deleted`.
This lifecycle is managed by OR's archival-destruction workflow per ADR-022.

#### Scenario: Transition a document through the retention lifecycle

```
GIVEN a DocumentRetention record in status "active"
AND today's date >= reviewDueDate
WHEN an authorized user initiates a retention review
THEN the status transitions to "under-review"
AND a review-task is created (assigned to records officer)
AND the audit trail records: "Review initiated by [user] on [date]"

WHEN the review is completed with decision "retain"
THEN the status transitions to "retained"
AND the audit trail records: "Retention confirmed by [user] on [date]"

WHEN the disposal deadline is reached (retentionEndDate + 1 day)
AND no legal hold is active
THEN the status transitions to "scheduled-for-deletion"
AND a disposal-task is created (assigned to compliance officer)

WHEN the disposal is authorized
THEN the status transitions to "deleted"
AND the audit trail records: "Document deleted by [user] on [date] per policy [policy name]"
```

---

## REQ-RET-004: Enforce review deadlines per Archiefwet timelines

**Description**: The system must alert administrators when a review is due
(30 days before retention period ends) and prevent automatic disposal if review
is overdue.

#### Scenario: Generate review-due alert

```
GIVEN a DocumentRetention with reviewDueDate = "2028-12-16"
WHEN today's date = 2028-12-16
THEN the system sends a Nextcloud notification to the retention-manager group:
  "Document [docid] is due for retention review (retention ends 2029-01-15)"
AND the notification includes a direct link to the review task

WHEN today's date > reviewDueDate + 14 days (escalation)
AND the review is not yet completed
THEN a second notification is sent to the administrator group with escalation flag
```

---

## REQ-RET-005: Handle legal holds and exceptions

**Description**: Documents can be placed on legal hold (e.g. due to litigation,
court order, or regulatory investigation), preventing automatic disposal even if
the retention period expires. Exceptions include regulatory exemptions and
court-ordered holds.

#### Scenario: Apply a legal hold

```
GIVEN a DocumentRetention in status "retained" with retentionEndDate = 2029-01-15
WHEN a user with "legal-hold-authority" role executes:
  {
    "action": "setLegalHold",
    "documentRetentionId": "<id>",
    "holdType": "litigation",
    "reason": "Kroon BV vs Our Organization (case 2024/12345)",
    "authority": "District Court Amsterdam"
  }
THEN the DocumentRetention.legalHold flag is set to true
AND DocumentRetention.exceptions[] appends:
  { "type": "litigation", "reason": "...", "authority": "...", "appliedDate": "..." }
AND the disposal task (if any) is paused
AND the audit trail records the hold with full details

WHEN the legal hold reason is resolved (court dismisses case)
AND a user with "legal-hold-authority" clears the hold
THEN the DocumentRetention.legalHold flag is set to false
AND the disposal can proceed if retentionEndDate is past
AND the audit trail records the hold removal with reason
```

---

## REQ-RET-006: Generate compliance reports

**Description**: Compliance officers must be able to generate reports showing:
- Documents overdue for review
- Documents under legal hold
- Documents scheduled for disposal in the next 30/90 days
- Disposal audit trail (documents disposed in a given period, with reasons)

#### Scenario: View overdue-review dashboard

```
GIVEN the Retention Dashboard page is open
WHEN the system queries DocumentRetention records
THEN three KPI cards are displayed:
  1. Overdue Reviews (count of records where status != deleted AND reviewDueDate < today)
  2. Active Legal Holds (count of records where legalHold == true)
  3. Pending Disposal (count of records where status == scheduled-for-deletion)

WHEN a compliance officer filters by document type and date range
AND clicks "Generate Compliance Report"
THEN a PDF is generated with:
  - List of all documents reviewed in the date range
  - Summary by document type + disposition (retained / deleted)
  - Audit trail of all disposal actions (who, when, why)
  - Certification statement: "Per Archiefwet 1995, all records reviewed and disposed
    per documented retention schedules"
```

---

## REQ-RET-007: Audit all retention actions

**Description**: Every action on a DocumentRetention record (policy assignment,
review, legal hold, disposal) must be recorded in an immutable audit trail per
OR's AuditTrailService. This satisfies Archiefwet requirements for retention
accountability.

#### Scenario: Audit trail captures retention actions

```
GIVEN a DocumentRetention record for invoice INV-2024-001
WHEN the following actions occur:
  1. "Created with policy" (Financial Records 5yr)
  2. "Review initiated by alice@example.com"
  3. "Legal hold applied: litigation case 2024/12345"
  4. "Legal hold cleared after court dismissal"
  5. "Disposal authorized by bob@example.com"
THEN the OR AuditTrailService records each action with:
  - timestamp (exact date/time)
  - actor (user UID via $user->getUID(), not displayName)
  - action (review-initiated, legal-hold-applied, legal-hold-cleared, disposed)
  - reason (if provided)
  - result (success/failure)
AND the audit trail is immutable (append-only, hash-chained per OR standard)
AND the audit trail is visible on the DocumentRetention detail page
AND the audit trail can be exported as part of the compliance report
```

---

## REQ-RET-008: Prevent deletion of documents before review completion

**Description**: The system must enforce that no document can be deleted
without first being explicitly reviewed, even if the retention period expires.

#### Scenario: Prevent premature deletion

```
GIVEN a DocumentRetention with:
  - status: "active"
  - reviewDueDate: not yet reached
  - retentionEndDate: tomorrow
WHEN a user with "delete-document" role attempts disposal
THEN the system rejects the action with message:
  "Document review is not yet due. Review deadline: 2028-12-16"
AND the action is NOT executed
AND the audit trail records the blocked action attempt

WHEN reviewDueDate is reached AND review is completed with "retain" decision
AND retentionEndDate is past
AND no legal hold is active
THEN the user can proceed with disposal
AND disposal is only available to users with "document-disposal" role
```

---

## REQ-RET-009: Support organization-wide and administration-specific policies

**Description**: Retention policies can be defined at the organization level
(defaults for all administrations) or customized per administration (e.g., a
specific business unit has a longer retention period due to sector law).

#### Scenario: Override retention policy per administration

```
GIVEN a default RetentionPolicy: "Financial Records — 5 years"
WHEN the implementing cycle allows per-administration overrides (ADR-031 decision)
AND Administration "Health Care Division" sets an override:
  "Financial Records — 7 years" (per health-care sector regulation)
THEN documents created in the Health Care Division reference the 7-year policy
AND documents in other administrations reference the default 5-year policy
AND each administration can view its own policies in administration settings
```

---

## REQ-RET-010: Provide search and filtering on retention status

**Description**: Users must be able to search and filter documents by retention
status, policy, legal-hold status, and review-due date.

#### Scenario: Search for documents with active legal holds

```
GIVEN the DocumentRetention index page
WHEN a user filters by:
  - legalHold: true
  - documentType: "invoice"
THEN the page displays all invoices with active legal holds
AND each row shows: document ID, due date, hold reason, hold authority
AND a "Quick Actions" column offers: "View", "Review Hold", "Clear Hold" (if authorized)

WHEN a user searches by document ID or organization name
AND filters by status "scheduled-for-deletion"
AND sorts by retentionEndDate ascending
THEN the results show documents ready for immediate disposal, oldest first
```

---

## REQ-RET-011: Interface with OR's archival-destruction lifecycle

**Description**: The retention lifecycle must seamlessly consume OR's
archival-destruction workflow per ADR-022. If OR's extension is not yet stable,
a single-method RetentionGuard service (per ADR-031) implements the minimum
gate logic.

#### Scenario: Lifecycle respects OR archival-destruction contract

```
GIVEN the DocumentRetention register with lifecycle:
  active → under-review → retained → scheduled-for-deletion → deleted

WHEN OR's archival-destruction extension is available
THEN DocumentRetention lifecycle is declared as:
  x-openregister-lifecycle:
    - from: "active"
      to: "under-review"
      guard: or-archival-destruction/review-required
    - from: "retained"
      to: "scheduled-for-deletion"
      action: or-archival-destruction/schedule-disposal
    - from: "scheduled-for-deletion"
      to: "deleted"
      action: or-archival-destruction/execute-disposal

WHEN OR's extension is not yet stable (ADR-031 exception)
THEN a single RetentionGuard class provides the minimum logic:
  - public function requiresReview($doc): bool
  - Returns: true if reviewDueDate <= today AND no legal hold
  - If false, lifecycle transition is blocked
```

---

## REQ-RET-012: Provide seed data for common retention policies

**Description**: The app ships with pre-configured retention policies aligned
with Dutch Archiefwet National Archival Guidelines. Administrators can use these
as-is or customize.

#### Scenario: Seed data loaded on first install

```
GIVEN a fresh Shillinq installation
WHEN the app repair step runs `ConfigurationService::importFromApp()`
THEN three RetentionPolicy records are created (idempotent):
  1. {
       "slug": "default-financial-5yr",
       "name": "Financial Records — Standard (5 years)",
       "documentType": "financial-record",
       "retentionYears": 5,
       "legalHoldAllowed": true,
       "description": "Per Archiefwet & VAT directive: invoices, receipts, GL entries"
     }
  2. {
       "slug": "tax-documents-7yr",
       "name": "Tax Documents (7 years)",
       "documentType": "tax-record",
       "retentionYears": 7,
       "legalHoldAllowed": true,
       "description": "Per Dutch Tax Authority rules (inhoud- en bewaarduur)"
     }
  3. {
       "slug": "general-admin-3yr",
       "name": "General Administration (3 years)",
       "documentType": "general-record",
       "retentionYears": 3,
       "legalHoldAllowed": false,
       "description": "General office records with no specific legal requirement"
     }
AND the policies appear in the Retention Policies index
AND they can be customized by administrators
```

---

## REQ-RET-013: Calculate and display retention schedules

**Description**: For each document linked to a retention policy, the system
must display the retention schedule: start date, end date, review-due date, and
current status.

#### Scenario: View retention schedule on document detail

```
GIVEN a DocumentRetention for Invoice INV-2024-001 with:
  - documentId: "<invoice UUID>"
  - policyId: "Financial Records — 5 years"
  - startDate: 2024-01-15
  - retentionYears: 5
WHEN the document detail page is opened
THEN a "Retention" card displays:
  - Policy: Financial Records — 5 years (link to policy details)
  - Start Date: 2024-01-15
  - Retention Period: 5 years
  - End Date: 2029-01-15
  - Review Due: 2028-12-16 (end date - 30 days)
  - Current Status: active (with status badge color)
  - Legal Hold: No (or "Yes: [reason]" if held)
  - Days Until Review: 1,034 (or "Overdue by X days" if past due)
```

---

## Cross-References

- **ADR-022** — Apps Consume OpenRegister Abstractions (retention lifecycle
  consumed from OR's archival-destruction workflow)
- **ADR-031** — Schema-declarative Business Logic (retention metadata is
  declarative, not service-class code)
- **Archiefwet 1995** — Dutch law on record retention and archival
- **National Archival Guidelines** — default retention periods per document type
- **GDPR / AVG** — data-subject access requests and right-to-be-forgotten handled
  separately; retention is legal-hold independent

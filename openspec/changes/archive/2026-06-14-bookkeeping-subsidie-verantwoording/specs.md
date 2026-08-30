# Specs — Subsidie Administratie & Verantwoording

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (governance + compliance)
**Depends on:** bookkeeping-general-ledger (T1), grant-subsidy-management (T2)

---

## REQ-SUBV-001: Searchable Subsidie Registry

The system MUST provide a full-text searchable and filterable registry of all grants (Subsidies) in the administration, indexed by subsidie number, recipient organization name, program label, award date, status, and amount.

#### Scenario: Search by recipient name

```
GIVEN a subsidie administrator has 150 active grants
WHEN the administrator searches for "Gemeente Amsterdam"
THEN the system returns all grants awarded to Gemeente Amsterdam
  sorted by awardDate descending, with filters for status/program/amount available
```

#### Scenario: Filter by award date and status

```
GIVEN the subsidie registry is populated
WHEN the administrator filters by awardDate (after 2024-01-01) AND status (active)
THEN the system returns 47 active grants awarded in 2024
  with 'Pending Accountability Report' indicators on grants without a final SubsidieVerantwoording
```

**Rationale:** Feature demand (105) specifies search capability; Dutch government users need rapid lookup of grant portfolio.

---

## REQ-SUBV-002: Subsidie Verantwoording Register Schema

The system MUST declare a `SubsidieVerantwoording` (accountability record) register in `lib/Settings/shillinq_register.json` with the following properties:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| verantwoordingId | string | Yes | Unique accountability record identifier |
| grantId | string | Yes | FK to Grant record |
| reportDate | datetime | Yes | Date the accountability report was generated |
| reportingPeriod | string | Yes | Period covered (e.g., "2024-01-01 to 2024-12-31") |
| status | enum | Yes | draft, submitted, approved, final |
| submittedDate | datetime | No | Date submitted for approval |
| approverUserId | string | No | User who approved the report |
| approvalDate | datetime | No | Date of approval |
| reportContent | string | No | Full text or summary of accountability |
| administrationId | string | Yes | FK to Administration |

#### Scenario: Create accountability report on grant disbursement

```
GIVEN a grant with status "awarded" and awardedAmount €50,000
WHEN the grant transitions to "disbursed"
THEN the system auto-creates a SubsidieVerantwoording record
  in state: draft
  with reportDate = today
  with reportingPeriod auto-calculated from grant award date to today
```

**Rationale:** ADR-031 (declarative schema definition); governance layer requires structured accountability tracking.

---

## REQ-SUBV-003: Subsidie Verantwoording Lifecycle

The system MUST declare `x-openregister-lifecycle` on `SubsidieVerantwoording` with the following state transitions:

- `draft → submitted` (operator-initiated, audit-logged)
- `submitted → approved` (approval-gated by finance officer, requires no blocking AuditorStatement)
- `approved → final` (operator publishes, timestamp recorded)
- `final → submitted` (resubmission if corrections needed)

Transitions MUST fire notifications on state change. Auto-archive is not required.

#### Scenario: Submit accountability report for approval

```
GIVEN a SubsidieVerantwoording in state "draft"
WHEN an operator clicks "Submit for Approval"
THEN the system transitions to "submitted"
  creates an ApprovalTask assigned to the finance officer
  fires a "Report Ready for Approval" notification
```

#### Scenario: Approval blocked by pending auditor statement (large grant)

```
GIVEN a SubsidieVerantwoording for a grant with awardedAmount €50,000 (above threshold)
AND the corresponding AuditorStatement is in state "pending" or "rejected"
WHEN an operator attempts "submitted → approved" transition
THEN the system BLOCKS the transition
  returns message "Auditor statement required and not yet approved"
```

**Rationale:** Governance requirement; Awb 4.2 mandates approval chain before finalization.

---

## REQ-SUBV-004: Auditor Statement Register Schema

The system MUST declare an `AuditorStatement` register in `lib/Settings/shillinq_register.json` with the following properties:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| statementId | string | Yes | Unique auditor statement identifier |
| grantId | string | Yes | FK to Grant record |
| auditThresholdApplied | boolean | Yes | Whether grant exceeded auditor threshold |
| auditDate | datetime | Yes | Date of audit verification |
| auditorUserId | string | Yes | User (auditor) who performed verification |
| status | enum | Yes | pending, under-review, approved, rejected, conditional |
| findings | array | No | FK array to audit-finding-template + custom notes |
| attestationDocumentUri | string | No | URI to docudesk PDF of auditor sign-off |
| verdict | string | No | approved, rejected, or conditional-with-caveats |
| administrationId | string | Yes | FK to Administration |

#### Scenario: Auditor marks large grant for review

```
GIVEN a grant with awardedAmount €50,000 (above default €25k threshold)
AND no AuditorStatement exists
WHEN an operator opens the SubsidieVerantwoording detail page
THEN the system displays a "Requires Auditor Verification" badge
  and provides a workflow to create an AuditorStatement record
  in state: pending
```

**Rationale:** Large-grant oversight per SISA (Single Information Single Audit) framework; Awb 4.2.

---

## REQ-SUBV-005: Auditor Verification Workflow

The system MUST declare `x-openregister-lifecycle` on `AuditorStatement` with the following state transitions:

- `pending → under-review` (auditor accepts the task)
- `under-review → approved` (auditor signs off, uploads attestation document)
- `under-review → rejected` (auditor identifies issues, blocks grant settlement)
- `under-review → conditional` (auditor approves with caveats, requires followup)

Auditor can add audit findings (FK to audit-finding-template) and attach notes per finding. Transition to `approved` requires at least one of: no findings, or all findings marked "resolved".

#### Scenario: Auditor approves large grant with conditional findings

```
GIVEN an AuditorStatement in state "under-review"
WHEN the auditor:
  1. Adds 2 findings: "Documentation Incomplete" (medium severity) + "Tax Registration Unclear" (low)
  2. Notes: "Will re-verify after Q2 submission"
  3. Uploads auditor sign-off PDF via docudesk
  4. Transitions to "conditional"
THEN the system records the findings + document URI
  sends "Auditor Statement — Conditional Approval" notification to grant owner
  allows SubsidieVerantwoording to proceed with a compliance flag
```

**Rationale:** Large-grant governance; auditor involvement required per Dutch government audit standards.

---

## REQ-SUBV-006: Auditor Statement Auto-Trigger on Large Grants

The system MUST auto-create an `AuditorStatement` record (state: pending) when a `SubsidieVerantwoording` is created for a grant where `awardedAmount >= administrationConfig.auditThreshold` (default €25,000).

#### Scenario: Auto-create auditor statement on large grant accountability report

```
GIVEN a grant with awardedAmount €30,000
AND the administration's auditThreshold is €25,000
WHEN a SubsidieVerantwoording is created for this grant
THEN the system auto-creates an AuditorStatement record
  in state: pending
  with auditThresholdApplied: true
  visible in the grant detail > "Auditor Statement" section
```

**Rationale:** Feature demand (57) specifies auditor statement as explicit requirement; automation reduces manual overhead.

---

## REQ-SUBV-007: Audit-Finding Template Seed Data

The system MUST ship `lib/Settings/seeds/audit-finding-templates.json` with 6+ audit-finding categories and severity levels, loaded via repair step. Template structure:

```json
{
  "categoryId": "string",
  "categoryName": "string",
  "severity": "critical|high|medium|low",
  "defaultRemediationTemplate": "string"
}
```

Predefined categories: eligibility, documentation, financial-control, tax, compliance, other.

#### Scenario: Auditor selects finding from template

```
GIVEN an AuditorStatement in "under-review" state
WHEN the auditor clicks "Add Finding" and searches for "documentation"
THEN the system returns pre-seeded findings:
  - "Grant Application Incomplete" (medium, eligibility)
  - "Required Documentation Missing" (high, documentation)
  - "Financial Records Unclear" (high, financial-control)
```

**Rationale:** ADR-031 seed data; reduces auditor data-entry burden, ensures consistent categorization.

---

## REQ-SUBV-008: Subsidies Overview Dashboard

The system MUST provide an overview dashboard showing:
1. **Compliance Status** — breakdown of grants by SubsidieVerantwoording status (no-report, draft, pending-approval, approved, final)
2. **Auditor Queue** — count of AuditorStatements by status (pending, under-review, approved, rejected)
3. **Overdue Reports** — grants where SubsidieVerantwoording is overdue (>90 days since grant award without final report)
4. **Settlement Status** — breakdown of grants by disbursement status

Each card MUST link to the detail page for drill-down. Filters by administration, period, program.

#### Scenario: Compliance officer reviews subsidies dashboard

```
GIVEN a compliance officer opens the "Subsidies > Overview" page
WHEN the dashboard loads
THEN the officer sees:
  - "47 active grants, 12 pending accountability reports"
  - "3 under auditor review, 1 rejected"
  - "2 reports overdue (>90 days)"
  - "€450,000 disbursed this quarter"
```

**Rationale:** Feature demand (42) specifies overview capability; governance decision-makers need rapid insight.

---

## REQ-SUBV-009: Accountability Report Auto-Generation on Grant State Change

The system MUST auto-create a `SubsidieVerantwoording` (state: draft) when a Grant transitions to `awarded` or `disbursed` states.

#### Scenario: Report auto-generated on award

```
GIVEN a grant in state "proposed"
WHEN it transitions to "awarded" (e.g., by approval workflow)
THEN the system auto-creates a SubsidieVerantwoording
  reportDate = today
  reportingPeriod = grant.awardDate to today
  status = draft
```

**Rationale:** Reduces manual report initiation; ensures no grant falls through audit cracks.

---

## REQ-SUBV-010: Notification on Accountability Report Overdue

The system MUST fire a notification when a `SubsidieVerantwoording` is not finalized within 90 days of grant award.

#### Scenario: Notification fires on overdue accountability

```
GIVEN a grant awarded 2024-01-01
AND SubsidieVerantwoording is still in state "draft" or "submitted" on 2024-04-01 (>90 days)
WHEN the system runs the daily overdue-notification job
THEN it fires a notification to the grant owner + finance officer:
  "Accountability report for [Grant] overdue — please submit or approve"
```

**Rationale:** Governance accountability; ensures timely audit compliance.

---

## REQ-SUBV-011: Manifest Navigation and Pages

The system MUST add 2 new navigation entries to `src/manifest.json`:

1. **Subsidies > Accountability Reports**
   - `type: index` — list view of SubsidieVerantwoording records, sortable/filterable by status, grantee, amount, reportDate
   - `type: detail` — detail view with lifecycle buttons (Submit/Approve/Finalize), auditor statement block, notifications

2. **Subsidies > Auditor Statements**
   - `type: index` — list view of AuditorStatement records, filterable by status (pending/approved/rejected), grant, auditor
   - `type: detail` — detail view with workflow buttons, findings list, document uploader, conditional-approval notes

Both navigation entries MUST be visible to all admin types (subsidie-officer, finance-officer, auditor).

#### Scenario: Compliance officer navigates to accountability reports

```
GIVEN a compliance officer logged into Shillinq
WHEN they click "Subsidies > Accountability Reports" in the sidebar
THEN they see a list of SubsidieVerantwoording records
  with columns: grant ID, grantee, amount, status, reportDate, submittedDate
  with filter sidebar: status, grantee, program, date range
  with actions: View Detail, Submit for Approval (if draft), Re-submit (if final)
```

**Rationale:** ADR-024 (manifest-driven navigation); UX requirement for governance workflows.

---

## REQ-SUBV-012: Spec Completeness Verification

The specification MUST pass `openspec validate` without errors. Architecture reviewer MUST confirm:
- ADR-022 compliance (declarative lifecycle, no parallel state machine)
- ADR-024 compliance (manifest carries navigation)
- ADR-031 compliance (seed data provided, no app-local service classes)
- No source code changes outside `openspec/changes/bookkeeping-subsidie-verantwoording/`

**Rationale:** Quality gate per company ADRs.

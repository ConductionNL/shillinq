---
status: done
---

# Spec: bookkeeping-sisa-reporting

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../add-shillinq-audit-trail/specs/bookkeeping-audit-trail/spec.md` (document versioning),
`../add-shillinq-grant-subsidy-management/specs/grant-subsidy-management/spec.md` (grant eligibility)

## Purpose

This specification defines the requirements for bookkeeping sisa reporting in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: SISA compliance audit page not yet implemented


### REQ-SISA-001: Single Information Single Audit SHALL be declared as `SisaReport` + `AuditDocument` + `ComplianceAuditTrail` registers, not a parallel audit database

Single Information Single Audit compliance MUST be expressed as three new
registers in `lib/Settings/shillinq_register.json` per ADR-024:

- `SisaReport` — formal audit opinion per fiscal year (transaction count,
  on-time settlement %, findings count, remediation status, audit opinion,
  management letter FK).
- `AuditDocument` — document participating in SiSa audit (document type,
  GL transaction FK, signing timestamp, signing user, audit trail captured
  via OR's audit service).
- `ComplianceAuditTrail` — auditor working log (findings by severity,
  observations, remediation tracking, due dates, completion status).

This capability **carries forward the original Shillinq audit & compliance
mission** — government grant compliance was core to Shillinq's pre-bookkeeping
evolution, and this spec formalises it as the SiSa half of the bookkeeping
engine. Signing an `AuditDocument` MUST trigger an audit-trail event captured
by OR's audit service, not by a bespoke app table.

#### Scenario: Reviewer confirms no parallel audit database

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `sisa_*`, `audit_trail_*`, `compliance_*`, or `audit_event_*`
- **THEN** no such classes SHALL exist.

#### Scenario: Audit event is captured when document is signed

- **GIVEN** T2 is live and `APTransaction INV-S-2026-0001` is issued
- **WHEN** the `AuditDocument` record is inspected via `CnObjectSidebar`
  audit tab
- **THEN** the OR audit service MUST show one event: user, timestamp,
  action (issued), before/after state.

### REQ-SISA-002: The `SisaReport` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `SisaReport` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reportNumber` | string | Yes | Unique report identifier per administration |
| `fiscalYear` | integer | Yes | Fiscal year (e.g., 2026) |
| `administrationId` | string | Yes | FK to administration |
| `reportDate` | datetime | Yes | Date report was generated or finalized |
| `totalTransactionCount` | integer | No | Total transactions in period |
| `onTimeSettlementPercent` | number | No | Percentage of obligations settled by due date (0-100) |
| `totalAmount` | number | No | Total transaction value in EUR |
| `currency` | string | Yes | ISO 4217 currency code (EUR) |
| `criticalFindingsCount` | integer | No | Count of critical-severity audit findings |
| `majorFindingsCount` | integer | No | Count of major-severity findings |
| `minorFindingsCount` | integer | No | Count of minor-severity findings |
| `observationsCount` | integer | No | Count of governance observations |
| `remediationOverdueCount` | integer | No | Count of overdue remediation actions |
| `auditOpinion` | enum | Yes | One of: unqualified, qualified, adverse, disclaimer |
| `managementLetterId` | string | No | FK to ManagementLetter record |
| `complianceStatus` | enum | Yes | One of: compliant, non-compliant, under-review |
| `lifecycleState` | enum | Yes | One of: draft, finalized, submitted, archived |
| `submissionDate` | datetime | No | Date report submitted to authorities |

Schema.org annotation: `schema:Report`.

#### Scenario: Schema validator accepts a minimal SiSa report

- **GIVEN** the schema
- **WHEN** `{reportNumber:"SISA-2026-ADM001", fiscalYear:2026, administrationId:"adm-1", reportDate:"2026-12-31", currency:"EUR", auditOpinion:"unqualified", complianceStatus:"compliant", lifecycleState:"draft"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Audit opinion is calculated from findings

- **GIVEN** a SisaReport with `criticalFindingsCount: 1`, `majorFindingsCount: 0`
- **WHEN** the report is finalized
- **THEN** `auditOpinion` MUST auto-transition to `adverse` per the
  finding-severity-to-opinion mapping (0 findings = unqualified, 1-2 major
  = qualified, 3+ major or any critical = adverse, overdue remediation
  = disclaimer).

### REQ-SISA-003: The `AuditDocument` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `AuditDocument` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `documentNumber` | string | Yes | Unique document identifier per administration |
| `documentType` | enum | Yes | One of: invoice, purchase-order, journal-entry, payment |
| `glTransactionId` | string | Yes | FK to GLTransaction |
| `administrationId` | string | Yes | FK to administration |
| `signingUser` | string | Yes | User ID who signed/issued the document (captured by OR audit) |
| `signingTimestamp` | datetime | Yes | Timestamp when document was signed (captured by OR audit) |
| `signingReason` | string | No | Optional reason or comment on signing |
| `state` | enum | Yes | One of: draft, issued, signed, voided (per REQ-SISA-004) |
| `lifecycleState` | enum | Yes | One of: active, archived |
| `relatedTransactionAmount` | number | No | Amount of related GL transaction for context |
| `currency` | string | Yes | ISO 4217 currency code |

Schema.org annotation: `schema:DigitalDocument`.

Every state change on `AuditDocument` (draft → issued → signed) MUST trigger
an audit-trail event captured by OR's `x-openregister-audit-trail` service.
No bespoke app-local event table.

#### Scenario: Schema validator accepts a minimal audit document

- **GIVEN** the schema
- **WHEN** an audit document with `{documentNumber:"DOC-2026-00001", documentType:"invoice", glTransactionId:"uuid-123", administrationId:"adm-1", signingUser:"user@example.com", signingTimestamp:"2026-06-15T10:30:00Z", state:"draft", lifecycleState:"active", currency:"EUR"}` is saved
- **THEN** validation MUST pass; OR audit service captures the create event.

#### Scenario: State change triggers audit event

- **GIVEN** an AuditDocument in `draft` state
- **WHEN** state transitions to `issued` by user "alice@example.com"
- **THEN** OR audit service MUST capture one event: actor=alice, timestamp,
  action=issued, before={state: draft}, after={state: issued}.

### REQ-SISA-004: `AuditDocument` SHALL declare a declarative draft → issued → signed lifecycle

`AuditDocument` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `issued` | operator issue | document format valid per GL transaction |
| `issued` | `signed` | operator signature | signature authority verified per mandate |
| `signed` | `voided` | operator reversal | compensating GL posting created per T1 REQ-GL-004 |
| `draft` | `voided` | operator cancellation | none |

The `issued → signed` transition MUST verify that the signing user holds the
appropriate `SigningAuthority` mandate per T2 `authorization-mandate-management`.
If mandate verification is not yet available, REQ-SISA-004 guard is deferred
until T3.

#### Scenario: Signature authority is checked on signing

- **GIVEN** a SiSa audit document in `issued` state and `user alice@example.com` (with SigningAuthority mandate for amount €100,000)
- **WHEN** alice attempts to sign a document with amount €50,000
- **THEN** the transition MUST succeed; mandate verified and event logged.

#### Scenario: Unauthorized user cannot sign

- **GIVEN** a SiSa audit document in `issued` state and `user bob@example.com` (no SigningAuthority mandate)
- **WHEN** bob attempts to sign
- **THEN** the transition MUST fail with "signing authority required" error.

### REQ-SISA-005: The `ComplianceAuditTrail` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `ComplianceAuditTrail` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `trailNumber` | string | Yes | Unique compliance audit trail identifier |
| `administrationId` | string | Yes | FK to administration |
| `fiscalYear` | integer | Yes | Fiscal year under audit |
| `findingNumber` | string | No | Reference to individual audit finding |
| `findingSeverity` | enum | No | One of: critical, major, minor |
| `findingDescription` | text | No | Detailed description of the finding |
| `observationNumber` | string | No | Reference to individual observation |
| `observationDescription` | text | No | Governance improvement observation |
| `remediationDueDate` | date | No | Target date for remediation |
| `remediationStatus` | enum | No | One of: pending, in-progress, completed, overdue |
| `remediationCompletionDate` | date | No | Date remediation was completed |
| `auditorName` | string | No | Auditor or firm name |
| `auditDate` | date | Yes | Date of audit |
| `status` | enum | Yes | One of: draft, submitted, closed |

Schema.org annotation: `schema:Event`.

#### Scenario: Compliance trail tracks findings and remediation

- **GIVEN** a ComplianceAuditTrail for fiscal 2026
- **WHEN** a critical finding is added with `{findingSeverity: "critical", findingDescription: "VAT posting discrepancy", remediationDueDate: "2026-12-31", remediationStatus: "pending"}`
- **THEN** the trail MUST save and mark the SisaReport's `criticalFindingsCount++`.

#### Scenario: Overdue remediation triggers disclaimer opinion

- **GIVEN** a SisaReport with one `major` finding marked overdue (remediationStatus: "overdue", remediationDueDate < today)
- **WHEN** the report is finalized
- **THEN** `auditOpinion` MUST be `disclaimer` (overdue remediation overrides normal finding mapping).

### REQ-SISA-006: `ManagementLetter` schema holds auditor communication

A new `ManagementLetter` schema (or extend existing if present) SHALL
declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `letterNumber` | string | Yes | Unique identifier |
| `sisaReportId` | string | Yes | FK to SisaReport |
| `auditorName` | string | Yes | Auditor firm / name |
| `issuedDate` | date | Yes | Date letter was issued |
| `dueResponseDate` | date | No | Management response deadline |
| `findingsSummary` | text | No | Summary of findings |
| `observationsSummary` | text | No | Summary of observations |
| `remediationRecommendations` | text | No | Recommended corrective actions |
| `auditOpinion` | string | No | Auditor's audit opinion (pre-computed from SisaReport) |
| `status` | enum | Yes | One of: draft, issued, acknowledged, archived |

Schema.org annotation: `schema:DigitalDocument`.

T2 provides the data structure; T4 attaches external auditor signature and
authority submission.

#### Scenario: Management letter references audit findings

- **GIVEN** a SisaReport with 2 major findings and 1 observation
- **WHEN** a ManagementLetter is generated
- **THEN** it MUST include summaries of all 3 items + remediation due dates.

### REQ-SISA-007: `SisaReport` aggregation SHALL compute on-time settlement compliance

An `x-openregister-aggregations` query SHALL calculate for each fiscal year:

```
onTimeSettlementPercent = 
  COUNT(obligations settled by dueDate) / COUNT(all obligations) * 100
```

excluding `draft`, `voided`, `written-off` obligations. Source: `Obligation`
and `Payment` records from T1 GL.

#### Scenario: Settlement percentage is calculated from obligations

- **GIVEN** fiscal 2026 with 100 obligations: 95 paid on time, 5 paid late
- **WHEN** SisaReport aggregation runs
- **THEN** `onTimeSettlementPercent` MUST be 95.

### REQ-SISA-008: `SisaReport` aggregation SHALL group findings by severity

An `x-openregister-aggregations` query SHALL calculate:

```
criticalFindingsCount = COUNT(findings where severity = critical)
majorFindingsCount = COUNT(findings where severity = major)
minorFindingsCount = COUNT(findings where severity = minor)
observationsCount = COUNT(observations)
remediationOverdueCount = COUNT(findings where remediationStatus = overdue AND remediationDueDate < today)
```

Source: `ComplianceAuditTrail.finding*` and `ComplianceAuditTrail.observation*`.

#### Scenario: Finding counts aggregate correctly

- **GIVEN** fiscal 2026 with 1 critical, 2 major, 3 minor findings
- **WHEN** aggregation runs
- **THEN** SisaReport MUST show: `criticalFindingsCount: 1`, `majorFindingsCount: 2`, `minorFindingsCount: 3`.

### REQ-SISA-009: Audit opinion SHALL be assigned via declarative rule or guarded service

The system SHALL satisfy this requirement: Audit opinion SHALL be assigned via declarative rule or guarded service.

`SisaReport.auditOpinion` is calculated via one of:

1. **Declarative conditional aggregation** (if OR supports rule evaluation):
   - 0 findings + 0 overdue remediations → `unqualified`
   - 1–2 major findings + no critical + 0 overdue → `qualified`
   - 3+ major OR any critical + 0 overdue → `adverse`
   - any overdue remediation → `disclaimer`

2. **Single-method read-only service** (per ADR-031 exception if OR's
   conditional aggregations are not stable):
   `OCA\Shillinq\Service\SisaReportingService::calculateAuditOpinion(SisaReport)`
   — immutable, reads-only, no write authority.

#### Scenario: Opinion transitions to adverse

- **GIVEN** a SisaReport with 3 major findings finalized
- **WHEN** the opinion calculation runs
- **THEN** `auditOpinion` MUST transition to `adverse`.

### REQ-SISA-010: Grant records MAY carry `isSISAEligible` flag for SiSa filtering

The system SHALL satisfy this requirement: Grant records MAY carry `isSISAEligible` flag for SiSa filtering.

The `Grant` schema (from T2 `grant-subsidy-management`) MAY gain an
optional `isSISAEligible: boolean` field indicating whether a grant
requires SiSa audit compliance (WBSO = yes, BBV = yes, Tozo = no, etc.).

`SisaReport` aggregation queries filter to grants where `isSISAEligible = true`
only.

#### Scenario: SiSa filtering excludes non-eligible grants

- **GIVEN** fiscal 2026 with 2 grants: Grant-A (WBSO, isSISAEligible: true), Grant-B (Tozo, isSISAEligible: false)
- **WHEN** SisaReport aggregation filters transactions
- **THEN** only transactions linked to Grant-A SHALL be included in compliance metrics.

### REQ-SISA-011: `SisaReport` manifest entries SHALL expose Compliance Audit, Management Letter, and SiSa Reports

Four new manifest navigation entries MUST be added to `src/manifest.json`:

1. `compliance-audit` — index page listing all ComplianceAuditTrail records, detail view for editing findings/observations
2. `management-letter` — index page listing ManagementLetter records, detail view for viewing/acknowledging
3. `sisa-reports` — index page listing SisaReport records per fiscal year, detail view with aggregation summary
4. `audit-documents` — index page listing AuditDocument records (signing audit trail), detail view with OR audit tab

Each entry declares `type: index` and `type: detail` pages per ADR-015
manifest pattern. `node tests/validate-manifest.js` must exit 0.

#### Scenario: Compliance Audit page is navigable

- **GIVEN** the manifest
- **WHEN** a user navigates to `/apps/shillinq/compliance-audit`
- **THEN** the index page MUST load and display all ComplianceAuditTrail records.

#### Scenario: SiSa Report detail shows aggregations

- **GIVEN** a SisaReport detail page
- **WHEN** the page is opened
- **THEN** the aggregated metrics (settlement %, findings count, opinion) MUST display.

## MODIFIED Requirements

### REQ-SISA-M001: Audit trail is captured automatically on state transitions

Every schema that participates in SiSa (`APTransaction`, `ARInvoice`,
`JournalEntry`, `AuditDocument`) declares an `x-openregister-lifecycle`
block. When state transitions, OR's audit service automatically captures
the event (no app-local logging needed).

Rationale: Per ADR-022, prefer OR's abstractions; avoid parallel audit
tracking.

## Dependencies

- **OpenRegister**: `x-openregister-audit-trail` (immutable event log),
  `x-openregister-lifecycle` (state machines), `x-openregister-aggregations`
  (SiSa metrics queries).
- **T1 bookkeeping-general-ledger**: GL transactions as the source of
  transaction audit data.
- **T2 audit-trail**: document versioning and signing audit.
- **T2 grant-subsidy-management**: grant eligibility flags for SiSa filtering.
- **T2 authorization-mandate-management** (if available for signature authority
  verification; else deferred to T3).

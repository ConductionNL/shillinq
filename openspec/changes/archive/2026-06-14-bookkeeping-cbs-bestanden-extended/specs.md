# Specification: CBS Bestanden Extended (Beyond IV3)

**Status:** proposed  
**Scope:** Shillinq (budgetq)  
**Tier:** T3 (operations) + T4-extended  
**Depends on:** bookkeeping-chart-of-accounts, bookkeeping-general-ledger, financial-reporting-accountability  

## Overview

This specification defines the CBS (Centraal Bureau voor de Statistiek) statistical reporting capability for Shillinq. Organizations with Dutch regulatory reporting obligations must submit financial summary data in IV3 format to the CBS. This spec defines the schemas, lifecycle, export mechanics, and validation rules for generating and managing CBS submissions.

## Requirements

### REQ-CBS-001: CBSSubmission Schema

A `CBSSubmission` object represents a complete IV3 statistical report package for a reporting period.

**GIVEN** an organization with an active Administration  
**WHEN** an operator creates a new CBS submission  
**THEN** the system initializes a CBSSubmission record with:
- Unique `submissionNumber` identifier
- Reporting period (start and end dates, typically one fiscal year)
- Organization metadata (legal name, KVK number, tax ID)
- Status field initialized to `draft`
- Reference to the owning Administration

```yaml
CBSSubmission:
  type: object
  required: [submissionNumber, reportingPeriodStartDate, reportingPeriodEndDate, 
             organizationLegalName, kvkNumber, taxIdentificationNumber, administrationId, status]
  properties:
    submissionNumber:
      type: string
      description: "Unique submission identifier (e.g., CBS-2025-001)"
    reportingPeriodStartDate:
      type: string
      format: date
      description: "First day of the reporting period"
    reportingPeriodEndDate:
      type: string
      format: date
      description: "Last day of the reporting period"
    organizationLegalName:
      type: string
      description: "Legal name of the reporting organization"
    kvkNumber:
      type: string
      pattern: "^[0-9]{8}$"
      description: "Dutch Chamber of Commerce registration number"
    taxIdentificationNumber:
      type: string
      pattern: "^NL[0-9]{10}B[0-9]{2}$"
      description: "Dutch VAT/BTW identification number"
    administrationId:
      type: string
      description: "Foreign key to Administration"
    status:
      type: string
      enum: [draft, validated, submitted, accepted, rejected]
      description: "Submission lifecycle state"
    submissionDate:
      type: string
      format: datetime
      nullable: true
      description: "Timestamp when submission was sent to CBS"
    description:
      type: string
      nullable: true
      description: "Optional notes or memo on this submission"
```

**Rationale:** Header-line split (D1) allows atomic submission tracking; metadata fields conform to CBS IV3 requirements.

### REQ-CBS-002: CBSLine Schema

A `CBSLine` object represents an aggregated financial category within a CBS submission, summing GL transactions across a configured account range.

**GIVEN** a CBSSubmission in draft state  
**WHEN** the export service aggregates GL data  
**THEN** the system creates CBSLine records with:
- Reference to the parent CBSSubmission
- CBS line classification (e.g., Revenue, OperatingCosts)
- CBS official line number from IV3 format
- Account range mapping (start and end account codes)
- Aggregated amount (sum of GL lines in that range)
- Currency (inherited from Chart of Accounts)

```yaml
CBSLine:
  type: object
  required: [cbsSubmissionId, cbsLineClassification, cbsLineNumber, 
             accountRangeStart, accountRangeEnd, aggregatedAmount, currency]
  properties:
    cbsSubmissionId:
      type: string
      description: "Foreign key to CBSSubmission"
    cbsLineClassification:
      type: string
      enum: [Revenue, OperatingCosts, Depreciation, Interest, Taxes, OtherIncome, OtherExpenses]
      description: "CBS line category from IV3 format"
    cbsLineNumber:
      type: string
      description: "Official CBS line number in IV3 (e.g., 1000, 2000)"
    accountRangeStart:
      type: string
      description: "GL account code range start (e.g., 4000)"
    accountRangeEnd:
      type: string
      description: "GL account code range end (e.g., 4999)"
    aggregatedAmount:
      type: number
      description: "Sum of GL line amounts for this range (in base currency)"
    currency:
      type: string
      pattern: "^[A-Z]{3}$"
      description: "ISO 4217 currency code"
    description:
      type: string
      nullable: true
      description: "Notes on the aggregation logic or variance"
```

**Rationale:** Aggregation reflects GL structure; line classifications align with CBS IV3 reporting dimensions.

### REQ-CBS-003: Submission Lifecycle

A CBSSubmission follows a deterministic state machine.

**GIVEN** a new CBSSubmission  
**WHEN** the operator transitions state  
**THEN** the system enforces:
- `draft` → `validated` — precondition: all GL transactions posted and balanced
- `validated` → `submitted` — precondition: IV3 file generated and validated
- `submitted` → `accepted` — CBS feedback received (manual or webhook)
- `submitted` → `rejected` — CBS rejection received (manual or webhook)
- Any state → any state — transitions tracked via audit trail (ADR-022)

```yaml
x-openregister-lifecycle:
  - from: draft
    to: validated
    trigger: validate
    preconditions:
      - "all GL transactions balanced"
      - "no account mapping conflicts"
    action: "generate IV3 file, validate structure"
  - from: validated
    to: submitted
    trigger: submit
    preconditions:
      - "IV3 file attached"
    action: "record submission timestamp"
  - from: submitted
    to: accepted
    trigger: accept
    action: "record CBS acceptance"
  - from: submitted
    to: rejected
    trigger: reject
    action: "record CBS rejection reason"
```

**Rationale:** State machine is declarative per ADR-031; transitions are immutable and audited.

### REQ-CBS-004: IV3 Export Service

The `CBSExportService` generates IV3-format data from Chart of Accounts and GL transactions.

**GIVEN** a CBSSubmission and its administration's GL data  
**WHEN** `CBSExportService::generateSubmission()` is called  
**THEN** the service:
1. Queries active Account records in the administration
2. Loads GL transactions + GL lines for the reporting period
3. Applies the account → CBS line mapping (from configurable external source)
4. Aggregates GL line amounts by CBS classification
5. Creates CBSLine records for each classification
6. Generates IV3-format JSON representation
7. Stores JSON as file attachment via FileService
8. Returns submission in `draft` state, ready for validation

```php
public function generateSubmission(
    string $administrationId,
    \DateTimeInterface $periodStart,
    \DateTimeInterface $periodEnd
): CBSSubmission {
    // 1. Query accounts
    // 2. Load GL transactions + lines
    // 3. Load account → CBS mapping (from settings)
    // 4. Aggregate by CBS classification
    // 5. Create CBSLine records
    // 6. Generate IV3 JSON
    // 7. Attach file
    // 8. Return submission
}
```

**Rationale:** Stateless service (ADR-003); configuration external (ADR-031); uses OpenRegister ObjectService + FileService (ADR-022).

### REQ-CBS-005: Account → CBS Line Mapping

The mapping from GL account ranges to CBS line classifications is configurable per administration.

**GIVEN** an administration  
**WHEN** the export service processes GL transactions  
**THEN** it applies a mapping table (from settings or admin UI) that defines:
- Account range (start, end) → CBS line classification
- Conflicts are detected (same account in multiple ranges → validation error)
- Unmapped accounts are reported as warnings (not errors)

Example mapping:
```json
{
  "4000-4999": "Revenue",
  "5000-5999": "OperatingCosts",
  "6000-6999": "Depreciation",
  "7000-7099": "Interest",
  "7100-7199": "Taxes",
  "8000-8999": "OtherIncome",
  "9000-9999": "OtherExpenses"
}
```

**Rationale:** Flexibility per ADR-031; declarative mapping; operators can override per administration.

### REQ-CBS-006: IV3 File Format

The exported IV3 package is a JSON file with structure conforming to CBS data requirements.

**GIVEN** a CBSSubmission with computed CBSLines  
**WHEN** `CBSExportService` generates the file  
**THEN** the file contains:
- Format version and generation timestamp
- Submission metadata (number, period, organization)
- Array of line items with classification, amount, currency
- Checksum for integrity verification

```json
{
  "format": "iv3-extended",
  "version": "1.0",
  "generatedAt": "2026-03-15T10:30:00Z",
  "submission": {
    "submissionNumber": "CBS-2025-001",
    "reportingPeriod": "2025",
    "organizationLegalName": "Gemeente Amsterdam",
    "kvkNumber": "34365416",
    "taxId": "NL814022818B01"
  },
  "lines": [
    { "classification": "Revenue", "lineNumber": "1000", "amount": 125000000, "currency": "EUR" }
  ],
  "checksum": "sha256:..."
}
```

**Rationale:** Self-contained, transformation-ready format; enables JSON → XML conversion.

### REQ-CBS-007: File Attachment Integration

The generated IV3 file is stored as an OpenRegister file attachment to the CBSSubmission.

**GIVEN** a CBSSubmission in draft state  
**WHEN** `CBSExportService::generateSubmission()` completes  
**THEN** the service:
- Calls `FileService::createFile()` to store the IV3 JSON
- Links the file to the submission via OpenRegister relations
- File remains accessible via `CnObjectSidebar` → Files tab
- Operators can download the file from the UI

**Rationale:** Uses platform abstractions (ADR-022); no custom file handling; audit-trail-linked.

### REQ-CBS-008: Validation Rules

CBS submissions are validated before marking as `validated` state.

**GIVEN** a CBSSubmission  
**WHEN** `validate()` transition is triggered  
**THEN** the system checks:
1. **Structural:** All required CBSLine records exist; no missing classifications
2. **Balancing:** Sum of all CBSLine amounts equals total GL posted amount for the period
3. **Accounting:** No GL account appears in multiple CBS classifications
4. **Completeness:** Organization KVK and tax ID are correctly formatted (regex match)
5. **Period:** Reporting period does not overlap with other accepted/submitted submissions for same org

Validation errors block state transition; warnings are recorded in audit trail but allow transition.

**Scenario: Valid submission passes validation**
```
GIVEN a CBSSubmission with 7 CBSLine records totaling €1,000,000
AND the GL posting total for the period is €1,000,000
WHEN the operator triggers validate transition
THEN the submission status changes to validated
AND the IV3 file is attached
AND no validation errors are recorded
```

**Scenario: Unbalanced submission fails validation**
```
GIVEN a CBSSubmission with CBSLine total of €1,000,000
AND the GL posting total for the period is €1,050,000
WHEN the operator triggers validate transition
THEN the transition is blocked
AND an error is recorded: "GL total €1,050,000 does not match CBS total €1,000,000"
```

**Rationale:** Preconditions per ADR-031; validation service follows ADR-003 (stateless, single responsibility).

### REQ-CBS-009: Manifest Navigation

CBS Submissions are navigable via app manifest entries, providing index and detail views.

**GIVEN** the Shillinq app  
**WHEN** manifest is loaded  
**THEN** the app includes:
- Navigation entry: `Bookkeeping > CBS Submissions` (or top-level per UX)
- Index page (`type: index`) — list of CBSSubmission records with filters (status, period, organization)
- Detail page (`type: detail`) — single submission with:
  - Header fields (submission number, period, organization, status)
  - CBSLine table (classification, account range, amount)
  - Files tab (IV3 package download)
  - Audit trail tab (state transitions, validation results)
  - Actions: Validate, Submit, Accept, Reject (conditional on state)

Pages are rendered by generic `CnIndexPage` / `CnDetailPage` from `@conduction/nextcloud-vue`, bound through manifest.

**Rationale:** Declarative per ADR-024; no custom Vue components required; reuses platform navigation + sidebar.

### REQ-CBS-010: Reuse Analysis

This spec leverages existing OpenRegister capabilities per ADR-022:

| Capability | Platform Service | Notes |
|-----------|------------------|-------|
| Object CRUD | `ObjectService` | Create, read, update CBSSubmission + CBSLine |
| State machine | `x-openregister-lifecycle` | Draft → Validated → Submitted → Accepted/Rejected |
| Audit trail | `AuditTrailService` (automatic) | All state transitions immutably logged |
| RBAC | `AuthorizationService` | Role-based access to submissions list/detail |
| File attachment | `FileService` + OR relations | IV3 file stored as object file |
| Validation | Custom service logic | CBS-specific rules not in OR core |
| GL aggregation | `ObjectService.findAll()` + filtering | Query GL lines by account range |
| Navigation | Manifest (`src/manifest.json`) | Index + detail pages via generic renderers |

**Rationale:** Minimal custom code; maximum platform reuse per architecture ADRs.

## Deduplication Check

**Task:** Verify no overlap with existing export/reporting capabilities.

- ✓ Confirmed: No existing `CBSSubmission` or `CBSLine` schemas in `openregister/openspec/specs/` or `shillinq/openspec/specs/`
- ✓ Confirmed: No custom CBS export service in `lib/Service/` across Conduction apps
- ✓ Confirmed: `financial-reporting-accountability` spec (T2) covers FinancialReport generation; CBS export is distinct (aggregation + validation)
- ✓ Confirmed: No overlap with `contract-lifecycle-management`, `procurement-integration`, or other specs
- Result: **No duplication found.** CBS export is a new capability.

## Dependencies

This spec depends on:
- `bookkeeping-chart-of-accounts` (T1) — provides Account schema and hierarchy
- `bookkeeping-general-ledger` (T1) — provides GLTransaction + GLLine for aggregation
- `financial-reporting-accountability` (T2) — provides FinancialReport schema (optional, for future enhancements)

No downstream specs currently depend on this one.

# Design — CBS Bestanden Extended (Beyond IV3)

## Context

Shillinq's T1-T2 bookkeeping foundation supports double-entry ledger, financial statements, and audit trails. Dutch organizations with CBS (Centraal Bureau voor de Statistiek) reporting obligations require a structured export mechanism that translates general ledger data into the IV3 (Italian-style corporate income tax report) format, providing statistical visibility for the Dutch government's *Verordening Statistieken Bedrijven* data collection program.

This change extends the bookkeeping feature set to include regulatory statistical reporting without reimplementing audit, RBAC, or approval workflows — leveraging existing OpenRegister abstractions.

## Goals

- Provide a **declarative, configurable IV3 export mechanism** that translates GL data into CBS line classification without hard-coded service logic.
- Consume OpenRegister's audit trail, RBAC, and file attachment abstractions — no custom implementation per ADR-022.
- Support Dutch government reporting standards and CBS data structure requirements.
- Enable operators to track submission status, retrieve generated packages, and maintain audit history for regulatory compliance.

## Non-Goals

- Custom CBS API integration or automated portal submission — out-of-scope; submission package is downloaded and manually uploaded by operator.
- Advanced multi-period consolidation or elimination rules — deferred to future tiers.
- Intercompany or multi-entity submissions — single-entity per submission.
- Real-time CBS validation feedback integration.

## Decisions

### D1 — Header/Line Split for CBS Submissions

CBS submissions carry two layers:

- `CBSSubmission` — header with metadata (submission period, organization identifier, submission date, lifecycle state, file reference)
- `CBSLine` — aggregated line items per CBS classification (revenue, COGS, operating costs, depreciation, etc.), computed from general ledger

This mirrors the header/line pattern established in T1 (GLTransaction + GLLine), supporting:
- Atomic submission packages (all lines belong to one submission period)
- Independent audit of each line's aggregation logic
- Resubmission without data duplication (new submission header, recomputed lines)

### D2 — Declarative Account → CBS Line Mapping

Each Account (from chart of accounts) maps to a CBS line classification via a configurable mapping function:
- Account ranges (e.g., `4000-4999`) map to CBS `Revenue` line
- Account ranges (e.g., `5000-5999`) map to CBS `OperatingCosts` line
- Mapping is external to the export service (stored in `lib/Settings/` or admin settings)
- Service reads the mapping and aggregates accordingly
- Operators can override per-administration

Alternative considered: Hard-code the mapping in `CBSExportService`. Rejected — RGS account structures vary; mapping must be externally configurable per ADR-031.

### D3 — Export Output Format: JSON Primary, XML Transformation

CBS requests XML format, but internal representation is JSON:
- `CBSExportService` produces a normalized JSON object tree
- Optional `CBSXmlTransformer` converts JSON → XML on demand
- File attachment stores JSON as canonical; XML is derived on request
- Enables future format versions without service rewrite

### D4 — Lifecycle: Draft → Validated → Submitted → Accepted/Rejected

CBS submissions follow a four-state workflow:
- **Draft** — submission created, lines computed but not finalized
- **Validated** — structure checked against CBS rules, ready for submission
- **Submitted** — operator has downloaded and submitted to CBS portal
- **Accepted/Rejected** — CBS feedback recorded (manual update by operator or webhook integration if CBS offers it)

State transitions are declarative via `x-openregister-lifecycle` on the `CBSSubmission` schema.

## Reuse Analysis

| Capability | OpenRegister Abstraction | Notes |
|-----------|--------------------------|-------|
| Audit trail of state changes | `AuditTrailService` (automatic) | No custom logging required |
| RBAC on submissions list/detail | `AuthorizationService` | Field-level + object-level permissions |
| File attachment (IV3 package) | `FileService` + OpenRegister relations | Stored as object files, not custom blob |
| Submission status tracking | `x-openregister-lifecycle` | State machine declared on schema |
| Approval workflow (if required) | OR `approval-workflow` extension | Optional; can be added to `submitted` transition |
| Export validation | Custom service logic | CBS-specific rules not in OR core |
| GL aggregation | `ObjectService.findAll()` | Query GL lines by account range |

## Seed Data

### CBSSubmission Records

Three example submissions (2026 tax years, fictional Dutch organizations):

```json
[
  {
    "@self": {
      "register": "cbs-submissions",
      "schema": "CBSSubmission",
      "slug": "cbs-sub-2025-org-001"
    },
    "submissionNumber": "CBS-2025-001",
    "reportingPeriodStartDate": "2025-01-01",
    "reportingPeriodEndDate": "2025-12-31",
    "organizationLegalName": "Gemeente Amsterdam",
    "kvkNumber": "34365416",
    "taxIdentificationNumber": "NL814022818B01",
    "status": "draft",
    "submissionDate": null,
    "administrationId": "adm-001",
    "description": "IV3 submission for FY2025 — Municipality reporting"
  },
  {
    "@self": {
      "register": "cbs-submissions",
      "schema": "CBSSubmission",
      "slug": "cbs-sub-2025-org-002"
    },
    "submissionNumber": "CBS-2025-002",
    "reportingPeriodStartDate": "2025-01-01",
    "reportingPeriodEndDate": "2025-12-31",
    "organizationLegalName": "Conduction B.V.",
    "kvkNumber": "63184301",
    "taxIdentificationNumber": "NL814062348B01",
    "status": "validated",
    "submissionDate": "2026-03-15",
    "administrationId": "adm-002",
    "description": "IV3 submission for FY2025 — Private enterprise"
  },
  {
    "@self": {
      "register": "cbs-submissions",
      "schema": "CBSSubmission",
      "slug": "cbs-sub-2026-org-003"
    },
    "submissionNumber": "CBS-2026-003",
    "reportingPeriodStartDate": "2026-01-01",
    "reportingPeriodEndDate": "2026-12-31",
    "organizationLegalName": "Nederlandse Spoorwegen N.V.",
    "kvkNumber": "10000000",
    "taxIdentificationNumber": "NL814062348B01",
    "status": "submitted",
    "submissionDate": "2027-03-10",
    "administrationId": "adm-003",
    "description": "IV3 submission for FY2026 — Large enterprise"
  }
]
```

### CBSLine Records

Example lines for the first submission (Gemeente Amsterdam):

```json
[
  {
    "@self": {
      "register": "cbs-submissions",
      "schema": "CBSLine",
      "slug": "cbs-line-2025-001-revenue"
    },
    "cbsSubmissionId": "cbs-sub-2025-org-001",
    "cbsLineClassification": "Revenue",
    "cbsLineNumber": "1000",
    "accountRangeStart": "8000",
    "accountRangeEnd": "8999",
    "aggregatedAmount": 125000000,
    "currency": "EUR",
    "description": "General government revenue (taxes, grants, services)"
  },
  {
    "@self": {
      "register": "cbs-submissions",
      "schema": "CBSLine",
      "slug": "cbs-line-2025-001-costs"
    },
    "cbsSubmissionId": "cbs-sub-2025-org-001",
    "cbsLineClassification": "OperatingCosts",
    "cbsLineNumber": "2000",
    "accountRangeStart": "5000",
    "accountRangeEnd": "5999",
    "aggregatedAmount": 95000000,
    "currency": "EUR",
    "description": "Personnel, materials, and operating expenses"
  }
]
```

## Data Model Integration

### CBSSubmission Schema Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| submissionNumber | string | Yes | Unique CBS submission identifier |
| reportingPeriodStartDate | date | Yes | Start date of the reporting period (usually Jan 1) |
| reportingPeriodEndDate | date | Yes | End date of the reporting period (usually Dec 31) |
| organizationLegalName | string | Yes | Legal name of the reporting organization |
| kvkNumber | string | Yes | Dutch Chamber of Commerce registration number |
| taxIdentificationNumber | string | Yes | VAT/BTW identification number |
| status | enum | Yes | One of: draft, validated, submitted, accepted, rejected |
| submissionDate | datetime | No | Date submission was sent to CBS |
| administrationId | string | Yes | FK to Administration entity |
| description | string | No | Human-readable notes on the submission |

### CBSLine Schema Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| cbsSubmissionId | string | Yes | FK to CBSSubmission |
| cbsLineClassification | string | Yes | CBS line type (Revenue, OperatingCosts, Depreciation, etc.) |
| cbsLineNumber | string | Yes | CBS official line number in IV3 format |
| accountRangeStart | string | Yes | GL account range start (e.g., "4000") |
| accountRangeEnd | string | Yes | GL account range end (e.g., "4999") |
| aggregatedAmount | number | Yes | Summed GL transaction amount in base currency |
| currency | string | Yes | ISO 4217 currency code |
| description | string | No | Explanation of the line aggregation |

## Export Service Logic

`CBSExportService::generateSubmission(administrationId, periodStartDate, periodEndDate): CBSSubmission`

1. Query Account records with active status in the target administration
2. Load GL transactions + GL lines for the reporting period
3. For each CBS line classification (from configurable mapping):
   - Sum GL lines where account number falls in the mapped range
   - Create a CBSLine record with aggregated amount
4. Create CBSSubmission header with computed lines
5. Generate IV3-format JSON from submission + lines
6. Attach JSON file to submission via FileService
7. Return submission in draft state

Validation service checks:
- All GL lines are balanced (precondition already enforced at posting time)
- No account appears in multiple CBS line classifications
- Aggregated amounts match GL totals (checksummed)

## File Attachment Format

The IV3 package is stored as a JSON file attached to the CBSSubmission object:

```json
{
  "format": "iv3-extended",
  "version": "1.0",
  "generatedAt": "2026-03-15T10:30:00Z",
  "submission": {
    "submissionNumber": "CBS-2025-001",
    "reportingPeriod": "2025",
    "organization": {
      "legalName": "Gemeente Amsterdam",
      "kvkNumber": "34365416",
      "taxId": "NL814022818B01"
    }
  },
  "lines": [
    {
      "classification": "Revenue",
      "lineNumber": "1000",
      "amount": 125000000,
      "currency": "EUR"
    }
  ],
  "checksum": "sha256:..."
}
```

This representation is self-contained and can be transformed to XML/CSV as needed.

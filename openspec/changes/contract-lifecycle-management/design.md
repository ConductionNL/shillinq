# Design: Contract Lifecycle Management — Shillinq

## Architecture Overview

This change adds a contract lifecycle management layer on top of the existing core, document-management, approval-workflow-management, supplier-management, and scheduling infrastructure. All new entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. PHP services implement lifecycle orchestration, AI integration, and renewal/deadline logic. Two background jobs run daily via Nextcloud's `ITimedJobList`. A Nextcloud global search provider indexes contract and obligation data for full-text retrieval.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Contract, ContractObligation CRUD)
    │
    └─ Shillinq OCS API
            ├─ ContractController           (CRUD, submit-for-approval,
            │                               upload-signed, advance-status)
            ├─ ContractObligationController (CRUD, complete, block)
            └─ ContractAiController         (extract-obligations,
                                             run-redline)
                    │
                    └─ PHP Services
                            ├─ ContractApprovalService
                            ├─ ContractAiService
                            ├─ ContractRedlineService
                            └─ ContractSearchProvider
                    │
                    └─ Background Jobs
                            ├─ ContractRenewalJob       (daily)
                            └─ ObligationDeadlineJob    (daily)
```

## Data Model

### Contract (`schema:Agreement`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| contractNumber | string | Yes | — | Unique identifier, auto-generated on creation (format: CNT-YYYY-NNNN) |
| title | string | Yes | — | Human-readable contract title |
| description | string | No | — | Contract summary and scope of work |
| status | string | Yes | draft | Enum: draft / review / approved / signed / executed / expired / renewed |
| contractType | string | No | standard | Enum: standard / framework / scheduling / master |
| startDate | datetime | Yes | — | Effective start date |
| endDate | datetime | No | — | Contract end date (null for indefinite contracts) |
| expiryDate | datetime | No | — | Hard expiration date triggering status change to `expired` |
| renewalDate | datetime | No | — | Proactive renewal notification date (typically 90 days before expiryDate) |
| contractValue | number | No | — | Total contract value in base currency |
| currency | string | No | EUR | ISO 4217 currency code |
| signingDate | datetime | No | — | Date the contract was signed by all parties |
| supplierId | string | No | — | OpenRegister object ID of linked SupplierProfile |
| ownerUserId | string | Yes | — | Nextcloud userId of the contract owner |
| departmentId | string | No | — | OpenRegister object ID of the responsible Organisation/department |
| approvalWorkflowId | string | No | — | OpenRegister object ID of linked ApprovalWorkflow (type: Contract) |
| approvalRequestId | string | No | — | OpenRegister object ID of the active ApprovalRequest |
| parentContractId | string | No | — | OpenRegister object ID of parent Contract (for sub-contracts and releases) |
| templateId | string | No | — | OpenRegister object ID of the Document used as contract template |
| procurementRef | string | No | — | Reference to procurement procedure (aanbestedingsreferentie) |
| privacyImpact | boolean | No | false | Whether the contract involves personal data processing (AVG flag) |
| verwerkersovereenkomstStatus | string | No | not_required | Enum: not_required / required_pending / in_place |
| aiRedlineStatus | string | No | not_run | Enum: not_run / running / completed / failed |
| createdDate | datetime | Yes | — | Creation timestamp (set by OpenRegister) |
| lastModifiedDate | datetime | No | — | Last modification timestamp (set by OpenRegister) |

**Relations:**
- `supplierId` → SupplierProfile (many-to-one)
- `departmentId` → Organization (many-to-one)
- `approvalWorkflowId` → ApprovalWorkflow (many-to-one)
- `parentContractId` → Contract (many-to-one, self-reference for hierarchy)
- ContractObligation → Contract (one-to-many, via `obligationId` on ContractObligation)
- Document → Contract (one-to-many, via document attachment mechanism)

### ContractObligation (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| obligationId | string | Yes | — | Unique identifier (UUID) |
| contractId | string | Yes | — | OpenRegister object ID of the parent Contract |
| title | string | Yes | — | Obligation title |
| description | string | No | — | Detailed description of the obligation |
| obligationType | string | Yes | delivery | Enum: delivery / payment / service / reporting / compliance |
| status | string | Yes | pending | Enum: pending / inProgress / completed / overdue / blocked |
| dueDate | datetime | Yes | — | Obligation due date |
| completionDate | datetime | No | — | Actual completion timestamp |
| priority | string | No | medium | Enum: low / medium / high / critical |
| aiGenerated | boolean | Yes | false | Whether this obligation was extracted by ContractAiService |
| automatedDeadlineTracking | boolean | No | true | Whether ObligationDeadlineJob monitors this obligation |
| assignedUserId | string | No | — | Nextcloud userId of the responsible user |
| milestoneId | string | No | — | OpenRegister object ID of a linked ContractMilestone (future schema) |
| notes | string | No | — | Additional remarks or context |

**Relations:**
- `contractId` → Contract (many-to-one)
- `assignedUserId` → Nextcloud User (many-to-one)

## Component Architecture

### PHP Services

| Service | Responsibility |
|---------|---------------|
| `ContractApprovalService` | Validates the contract is in `draft` status and all required fields are present; calls `WorkflowRoutingService::route()` with `requestType: Contract` and the contract entity; advances `Contract.status` to `review` on submission; advances to `approved` when the `ApprovalRequest` closes as approved; updates to `signed` when a signed document is uploaded |
| `ContractAiService` | Extracts plain text from the attached contract document (PDF text layer); sends text to the configured `AIProfile` endpoint via `IClientService`; parses the AI response JSON into `ContractObligation` creation payloads; persists obligations with `aiGenerated: true`; updates `Contract.aiRedlineStatus` to `running` / `completed` / `failed` |
| `ContractRedlineService` | Loads the approved template document for the contract's `contractType` from OpenRegister; extracts template text; calls `AIProfile` to compare contract text against template; maps response to `Comment` objects tagged `redline` with severity and clause reference; stores comments on the Contract entity |
| `ContractSearchProvider` | Implements Nextcloud `IProvider` for global search; queries OpenRegister for Contract and ContractObligation objects matching the search term across `title`, `description`, `contractNumber`, and `ownerUserId` fields; returns up to 20 results with type icon, title, and status badge |

### Background Jobs

| Job | Schedule | Behaviour |
|-----|----------|-----------|
| `ContractRenewalJob` | Daily (ITimedJobList, interval 86400 s) | Queries all `Contract` objects with `status` in (executed, signed) where `renewalDate` is not null and within `renewalLeadDays` (AppSettings, default 90) from today; for each match: sends Nextcloud notification to `ownerUserId` and department head; creates a `ContractObligation` of `obligationType: compliance` with title "Vernieuwing beoordelen" and `dueDate: renewalDate`; writes `AuditTrail` record; idempotent via `(contractId, 'renewal_notified_YYYY-MM-DD')` key |
| `ObligationDeadlineJob` | Daily (ITimedJobList, interval 86400 s) | Queries all `ContractObligation` objects with `automatedDeadlineTracking: true` and `status` not in (completed, blocked); sets `status: overdue` and notifies `assignedUserId` where `dueDate` < today; sends a reminder notification where `dueDate` is between today and today+7 days; writes `AuditTrail` record for each status change; idempotent: skips obligations already in `overdue` status |

### Vue Component Structure

```
src/
├── views/
│   ├── contract/
│   │   ├── ContractIndex.vue              (CnIndexPage)
│   │   ├── ContractDetail.vue             (CnDetailPage — tabs: Overzicht, Verplichtingen, Documenten, Prestaties, Hiërarchie)
│   │   └── ContractForm.vue              (CnFormDialog)
│   └── contractObligation/
│       ├── ContractObligationIndex.vue    (CnIndexPage)
│       ├── ContractObligationDetail.vue   (CnDetailPage — tabs: Details, Geschiedenis)
│       └── ContractObligationForm.vue    (CnFormDialog)
├── components/
│   ├── ContractKpiPanel.vue              (KPI metrics: value, obligations, overdue, days to expiry)
│   ├── ContractHierarchyPanel.vue        (breadcrumb parent + child contracts list)
│   ├── RedlineAnnotationPanel.vue        (AI redline clause diff with severity badges)
│   ├── PrivacyImpactBanner.vue           (AVG/privacy guidance banner for DPO)
│   └── ObligationStatusBadge.vue        (colour-coded status chip for obligations)
└── store/modules/
    ├── contract.js                       (createObjectStore('contract'))
    └── contractObligation.js             (createObjectStore('contractObligation'))
```

## Seed Data

### Contract seed objects (5)

```json
[
  {
    "contractNumber": "CNT-2026-0001",
    "title": "Raamovereenkomst ICT-dienstverlening",
    "description": "Raamovereenkomst voor de levering van ICT-diensten en beheer voor de periode 2026–2028.",
    "status": "executed",
    "contractType": "framework",
    "startDate": "2026-01-01T00:00:00Z",
    "endDate": "2028-12-31T00:00:00Z",
    "expiryDate": "2028-12-31T00:00:00Z",
    "renewalDate": "2028-10-01T00:00:00Z",
    "contractValue": 480000,
    "currency": "EUR",
    "signingDate": "2025-12-15T00:00:00Z",
    "ownerUserId": "maria.jansen",
    "procurementRef": "ANB-2025-0042",
    "privacyImpact": true,
    "verwerkersovereenkomstStatus": "in_place",
    "aiRedlineStatus": "completed",
    "createdDate": "2025-11-01T09:00:00Z"
  },
  {
    "contractNumber": "CNT-2026-0002",
    "title": "Schoonmaakdiensten kantoorpand Utrecht",
    "description": "Overeenkomst voor dagelijkse schoonmaak en periodieke grondige reiniging van het kantoorpand aan de Utrechtseweg 12.",
    "status": "signed",
    "contractType": "standard",
    "startDate": "2026-03-01T00:00:00Z",
    "endDate": "2027-02-28T00:00:00Z",
    "expiryDate": "2027-02-28T00:00:00Z",
    "renewalDate": "2026-12-01T00:00:00Z",
    "contractValue": 36000,
    "currency": "EUR",
    "signingDate": "2026-02-20T00:00:00Z",
    "ownerUserId": "peter.smit",
    "procurementRef": "",
    "privacyImpact": false,
    "verwerkersovereenkomstStatus": "not_required",
    "aiRedlineStatus": "not_run",
    "createdDate": "2026-02-01T10:00:00Z"
  },
  {
    "contractNumber": "CNT-2026-0003",
    "title": "Softwarelicentie ERP-systeem",
    "description": "Licentieovereenkomst voor het gebruik van het ERP-platform inclusief jaarlijkse onderhoudsvergoeding.",
    "status": "review",
    "contractType": "standard",
    "startDate": "2026-07-01T00:00:00Z",
    "endDate": "2029-06-30T00:00:00Z",
    "expiryDate": "2029-06-30T00:00:00Z",
    "renewalDate": "2029-04-01T00:00:00Z",
    "contractValue": 125000,
    "currency": "EUR",
    "ownerUserId": "jan.de.vries",
    "procurementRef": "ANB-2026-0011",
    "privacyImpact": true,
    "verwerkersovereenkomstStatus": "required_pending",
    "aiRedlineStatus": "completed",
    "createdDate": "2026-04-01T08:30:00Z"
  },
  {
    "contractNumber": "CNT-2026-0004",
    "title": "Beveiligingsdiensten — subovereenkomst nachtdienst",
    "description": "Subovereenkomst onder raamcontract CNT-2026-0001 voor nachtbewaking van het servergebouw.",
    "status": "draft",
    "contractType": "standard",
    "startDate": "2026-06-01T00:00:00Z",
    "endDate": "2027-05-31T00:00:00Z",
    "contractValue": 28800,
    "currency": "EUR",
    "ownerUserId": "karin.bakker",
    "privacyImpact": false,
    "verwerkersovereenkomstStatus": "not_required",
    "aiRedlineStatus": "not_run",
    "createdDate": "2026-04-05T11:00:00Z"
  },
  {
    "contractNumber": "CNT-2026-0005",
    "title": "Consultancy overeenkomst digitale transformatie",
    "description": "Overeenkomst voor strategisch advies en begeleiding bij de digitale transformatie van de backoffice-processen.",
    "status": "approved",
    "contractType": "standard",
    "startDate": "2026-05-01T00:00:00Z",
    "endDate": "2026-12-31T00:00:00Z",
    "expiryDate": "2026-12-31T00:00:00Z",
    "renewalDate": "2026-10-01T00:00:00Z",
    "contractValue": 95000,
    "currency": "EUR",
    "ownerUserId": "tom.visser",
    "procurementRef": "ANB-2026-0007",
    "privacyImpact": false,
    "verwerkersovereenkomstStatus": "not_required",
    "aiRedlineStatus": "not_run",
    "createdDate": "2026-03-15T09:00:00Z"
  }
]
```

### ContractObligation seed objects (5)

```json
[
  {
    "obligationId": "obl-001",
    "contractId": "<Contract CNT-2026-0001 object ID>",
    "title": "Kwartaalrapportage ICT-dienstverlening Q1 2026",
    "description": "Leverancier dient uiterlijk 15 april 2026 een kwartaalrapportage in met serviceniveaus en incidentenlijst.",
    "obligationType": "reporting",
    "status": "completed",
    "dueDate": "2026-04-15T00:00:00Z",
    "completionDate": "2026-04-10T14:30:00Z",
    "priority": "high",
    "aiGenerated": true,
    "automatedDeadlineTracking": true,
    "assignedUserId": "maria.jansen"
  },
  {
    "obligationId": "obl-002",
    "contractId": "<Contract CNT-2026-0001 object ID>",
    "title": "Jaarlijkse penetratietest uitvoeren",
    "description": "Conform artikel 8.3 dient leverancier jaarlijks een penetratietest te laten uitvoeren en het rapport aan te leveren.",
    "obligationType": "compliance",
    "status": "pending",
    "dueDate": "2026-12-01T00:00:00Z",
    "priority": "critical",
    "aiGenerated": true,
    "automatedDeadlineTracking": true,
    "assignedUserId": "maria.jansen"
  },
  {
    "obligationId": "obl-003",
    "contractId": "<Contract CNT-2026-0002 object ID>",
    "title": "Oplevering schoonmaakprotocol",
    "description": "Opdrachtnemer levert uiterlijk 14 maart 2026 een schoonmaakprotocol aan inclusief ecologische productenlijst.",
    "obligationType": "delivery",
    "status": "overdue",
    "dueDate": "2026-03-14T00:00:00Z",
    "priority": "medium",
    "aiGenerated": false,
    "automatedDeadlineTracking": true,
    "assignedUserId": "peter.smit"
  },
  {
    "obligationId": "obl-004",
    "contractId": "<Contract CNT-2026-0003 object ID>",
    "title": "Verwerkersovereenkomst afsluiten",
    "description": "Op grond van de AVG dient vóór inwerkingtreding van het ERP-contract een verwerkersovereenkomst te worden ondertekend.",
    "obligationType": "compliance",
    "status": "inProgress",
    "dueDate": "2026-06-30T00:00:00Z",
    "priority": "critical",
    "aiGenerated": false,
    "automatedDeadlineTracking": true,
    "assignedUserId": "jan.de.vries"
  },
  {
    "obligationId": "obl-005",
    "contractId": "<Contract CNT-2026-0005 object ID>",
    "title": "Tussentijdse voortgangsrapportage augustus 2026",
    "description": "Consultant levert maandelijks een voortgangsrapportage aan conform bijlage B van de overeenkomst.",
    "obligationType": "reporting",
    "status": "pending",
    "dueDate": "2026-08-31T00:00:00Z",
    "priority": "medium",
    "aiGenerated": true,
    "automatedDeadlineTracking": true,
    "assignedUserId": "tom.visser"
  }
]
```

## Security Considerations

- Only users with `contract_manager` CollaborationRole or `admin` may create, edit, or advance the status of a `Contract`; enforced in `ContractController` via `AccessControl`
- `Contract.status` transitions are validated server-side: a contract may not skip states (e.g. `draft` → `signed` without passing through `review` and `approved`)
- AI service calls are made server-side via `IClientService`; the AI endpoint URL and API key are stored in `AppSettings` with `x-sensitive: true` and never exposed to the frontend
- Contract document text sent to the AI service is stripped of any fields tagged `x-sensitive: true` (e.g. IBAN, BSN) before transmission, using the same masking utility as the approval-workflow-management revision diff
- `ContractObligation` records with `aiGenerated: true` may only be deleted by a user with `contract_manager` or `admin` role, preventing accidental removal of AI-extracted compliance obligations
- The procurement compliance threshold check (`contractValue >= tenderThreshold`) is enforced server-side in `ContractController`; the frontend banner is a UX convenience only
- AVG privacy-impact tagging (`privacyImpact: true`) triggers an `AuditTrail` entry when set, recording the userId who enabled the flag and the timestamp, to support DPO audit requirements
- Full-text search results are filtered by the calling user's Nextcloud access rights; a user who cannot access a contract object via `AccessControl` will not see it in search results

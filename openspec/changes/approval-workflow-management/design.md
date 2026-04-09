# Design: Approval & Workflow Management — Shillinq

## Architecture Overview

This change adds a configurable multi-step approval engine on top of the existing core, access-control-authorisation, supplier-management, and accounts-payable-receivable infrastructure. All new entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. The routing engine and signing integration are PHP services that are called from OCS controllers. The escalation job runs hourly via Nextcloud's `ITimedJobList`.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (ApprovalWorkflow, ApprovalStep,
    │                          ApprovalRequest, ApprovalDecision CRUD)
    │
    └─ Shillinq OCS API
            ├─ ApprovalWorkflowController  (CRUD, activate/deactivate)
            ├─ ApprovalRequestController   (submit, approve, reject,
            │                               request_info, resubmit, batch)
            └─ ContractSigningController   (initiate, poll, complete)
                    │
                    └─ PHP Services
                            ├─ WorkflowRoutingService
                            ├─ ApprovalDecisionService
                            ├─ BatchPaymentApprovalService
                            └─ ContractSigningService
                    │
                    └─ Background Job
                            └─ ApprovalEscalationJob  (every hour)
```

## Data Model

### ApprovalWorkflow (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| workflowId | string | Yes | — | Unique identifier (UUID) |
| name | string | Yes | — | Human-readable workflow name |
| description | string | No | — | Purpose and scope of the workflow |
| workflowType | string | Yes | — | Enum: PurchaseOrder / Bill / ExpenseClaim / Expenditure / Requisition / Contract |
| isActive | boolean | Yes | true | Whether this workflow is used for new requests |
| workflowConfig | object | Yes | — | JSON: ordered step definitions, routing conditions, escalation rules (see designer schema below) |
| organizationId | string | No | — | OpenRegister object ID of the owning Organisation |
| createdBy | string | Yes | — | userId who created the workflow |
| createdDate | datetime | Yes | — | Creation timestamp |
| modifiedDate | datetime | No | — | Last modification timestamp |

**workflowConfig JSON shape:**
```json
{
  "steps": [
    {
      "stepOrder": 1,
      "stepName": "Budget Holder Approval",
      "approverType": "costCentreOwner",
      "approverRef": null,
      "conditionExpression": "amount >= 0",
      "escalationAfterHours": 24,
      "isMandatory": true
    }
  ],
  "version": 1
}
```

### ApprovalStep (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| workflowId | string | Yes | — | OpenRegister object ID of the parent ApprovalWorkflow |
| stepOrder | integer | Yes | — | Execution order (1-based) |
| stepName | string | Yes | — | Display label for the step |
| approverType | string | Yes | — | Enum: user / role / costCentreOwner / seniorApprover |
| approverRef | string | No | — | userId or role name (null for dynamic types like costCentreOwner) |
| conditionExpression | string | No | — | Simple expression evaluated against the request entity (e.g. `amount >= 5000`) |
| escalationAfterHours | integer | No | 48 | Hours before this step is escalated |
| isMandatory | boolean | Yes | true | If false the step can be skipped by an admin |
| seniorApproverRef | string | No | — | userId or role to escalate to; falls back to organizationId admin if absent |

### ApprovalRequest (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| workflowId | string | Yes | — | OpenRegister object ID of the ApprovalWorkflow used |
| requestType | string | Yes | — | Enum: PurchaseOrder / Bill / ExpenseClaim / Expenditure / Requisition / Contract / Batch |
| entityId | string | Yes | — | OpenRegister object ID of the linked entity (PO, bill, etc.) |
| entityType | string | Yes | — | Schema name of the linked entity |
| requesterId | string | Yes | — | userId of the person who submitted the request |
| status | string | Yes | draft | Enum: draft / pending / in_review / approved / rejected / cancelled / escalated |
| currentStepOrder | integer | No | — | The step currently awaiting a decision |
| assignedApproverId | string | No | — | userId of the approver at the current step |
| submittedAt | datetime | No | — | When the request was submitted (moved from draft to pending) |
| resolvedAt | datetime | No | — | When the final decision was recorded |
| revisionOf | string | No | — | Object ID of a prior ApprovalRequest this supersedes |
| revisionDiff | object | No | — | JSON diff of changed fields that triggered re-approval |
| supportingDocumentIds | array | No | [] | Array of Document object IDs attached by the requester |
| batchBillIds | array | No | [] | For requestType Batch: array of Bill object IDs in the batch |
| costCentreId | string | No | — | Cost centre code used for budget holder resolution |
| notes | string | No | — | Requester's notes submitted with the request |

### ApprovalDecision (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| requestId | string | Yes | — | OpenRegister object ID of the ApprovalRequest |
| stepOrder | integer | Yes | — | The step this decision applies to |
| approverId | string | Yes | — | userId of the approver who made the decision |
| decision | string | Yes | — | Enum: approved / rejected / request_info / delegated |
| justification | string | Yes | — | Mandatory explanation for the decision |
| decidedAt | datetime | Yes | — | Timestamp of the decision (immutable) |
| delegatedToId | string | No | — | userId to whom the request is delegated (only for decision: delegated) |
| signingSessionRef | string | No | — | External PKIoverheid signing session reference (only for Contract requests) |
| certificateRef | string | No | — | PKIoverheid certificate subject reference after QES is applied |
| signedAt | datetime | No | — | Timestamp when QES was applied |

## Component Architecture

### PHP Services

| Service | Responsibility |
|---------|---------------|
| `WorkflowRoutingService` | Loads active `ApprovalWorkflow` for the requestType; evaluates `conditionExpression` against the entity; resolves the first pending step; sets `assignedApproverId`; sends Nextcloud notification via `INotifier` |
| `ApprovalDecisionService` | Validates decision preconditions (justification non-empty, correct approver); records `ApprovalDecision`; advances request to next step or closes with final status; triggers re-routing for `request_info` decision |
| `BatchPaymentApprovalService` | Accepts array of Bill IDs; validates all bills are in accounts-payable approved state; creates a single `ApprovalRequest` of type Batch; on approval marks each linked bill as `payment_authorised` via OpenRegister |
| `ContractSigningService` | Initiates PKIoverheid signing session via configurable external URL (AppSettings key `pkioverheid.signingUrl`); stores session reference; polls status via Nextcloud `IJobList` or on-demand; stores QES fields in `ApprovalDecision` on completion |

### Background Job

| Job | Schedule | Behaviour |
|-----|----------|-----------|
| `ApprovalEscalationJob` | Hourly (ITimedJobList, interval 3600 s) | Queries all `ApprovalRequest` objects in `in_review` or `pending` status where `submittedAt` + (`currentStep.escalationAfterHours` × 3600) < now; sets status `escalated`; resolves senior approver from step config or org admin; sends notifications to original and senior approvers; records escalation event in `AuditTrail` |

### Vue Component Structure

```
src/
├── views/
│   ├── approvalWorkflow/
│   │   ├── ApprovalWorkflowIndex.vue      (CnIndexPage)
│   │   ├── ApprovalWorkflowDetail.vue     (CnDetailPage — tabs: Overview, Steps, Requests)
│   │   └── ApprovalWorkflowForm.vue       (CnFormDialog)
│   └── approvalRequest/
│       ├── ApprovalRequestIndex.vue       (CnIndexPage — unified approval inbox)
│       ├── ApprovalRequestDetail.vue      (CnDetailPage — tabs: Details, History, Documents)
│       └── ApprovalRequestForm.vue        (CnFormDialog — submit new request)
├── components/
│   ├── WorkflowDesigner.vue               (drag-and-drop step canvas, HTML5 DnD API)
│   ├── ApprovalStepCard.vue               (draggable card within WorkflowDesigner)
│   ├── ApprovalDecisionPanel.vue          (approve/reject/request_info panel for current approver)
│   ├── ApprovalTimeline.vue               (chronological list of ApprovalDecision records)
│   ├── RevisionDiffPanel.vue              (before/after field comparison for revised entities)
│   └── BatchApprovalSelector.vue          (multi-select bill list for batch approval)
└── store/modules/
    ├── approvalWorkflow.js                (createObjectStore('approvalWorkflow'))
    ├── approvalStep.js                    (createObjectStore('approvalStep'))
    ├── approvalRequest.js                 (createObjectStore('approvalRequest'))
    └── approvalDecision.js                (createObjectStore('approvalDecision'))
```

## Seed Data (ADR-016)

### ApprovalWorkflow seed objects

```json
[
  {
    "workflowId": "wf-001",
    "name": "Inkooporder goedkeuring",
    "description": "Standaard goedkeuringsproces voor inkooporders tot EUR 50.000",
    "workflowType": "PurchaseOrder",
    "isActive": true,
    "workflowConfig": {
      "steps": [
        {
          "stepOrder": 1,
          "stepName": "Budgethouder accordering",
          "approverType": "costCentreOwner",
          "approverRef": null,
          "conditionExpression": "amount >= 0",
          "escalationAfterHours": 24,
          "isMandatory": true
        },
        {
          "stepOrder": 2,
          "stepName": "Financieel controller review",
          "approverType": "role",
          "approverRef": "financieel_controller",
          "conditionExpression": "amount >= 10000",
          "escalationAfterHours": 48,
          "isMandatory": false
        }
      ],
      "version": 1
    },
    "createdBy": "admin",
    "createdDate": "2026-01-15T09:00:00Z"
  },
  {
    "workflowId": "wf-002",
    "name": "Leveranciersfactuur goedkeuring",
    "description": "Drieweg-matching en budgethouder goedkeuring voor leveranciersfacturen",
    "workflowType": "Bill",
    "isActive": true,
    "workflowConfig": {
      "steps": [
        {
          "stepOrder": 1,
          "stepName": "Inkooporder matching",
          "approverType": "role",
          "approverRef": "inkoper",
          "conditionExpression": "amount >= 0",
          "escalationAfterHours": 24,
          "isMandatory": true
        },
        {
          "stepOrder": 2,
          "stepName": "Senior accordering boven drempel",
          "approverType": "seniorApprover",
          "approverRef": null,
          "conditionExpression": "amount >= 25000",
          "escalationAfterHours": 24,
          "isMandatory": true
        }
      ],
      "version": 1
    },
    "createdBy": "admin",
    "createdDate": "2026-01-15T09:05:00Z"
  }
]
```

### ApprovalStep seed objects (4, linked to seed workflows above)

| workflowId | stepOrder | stepName | approverType | approverRef | escalationAfterHours |
|-----------|-----------|----------|-------------|-------------|----------------------|
| wf-001 | 1 | Budgethouder accordering | costCentreOwner | — | 24 |
| wf-001 | 2 | Financieel controller review | role | financieel_controller | 48 |
| wf-002 | 1 | Inkooporder matching | role | inkoper | 24 |
| wf-002 | 2 | Senior accordering boven drempel | seniorApprover | — | 24 |

### ApprovalRequest seed objects (3)

```json
[
  {
    "workflowId": "wf-001",
    "requestType": "PurchaseOrder",
    "entityId": "<PO seed object ID>",
    "entityType": "purchaseOrder",
    "requesterId": "jan.de.vries",
    "status": "approved",
    "currentStepOrder": 2,
    "assignedApproverId": "maria.jansen",
    "submittedAt": "2026-03-10T08:30:00Z",
    "resolvedAt": "2026-03-10T14:15:00Z",
    "costCentreId": "CC-100",
    "notes": "Kantoorbenodigdheden Q2 2026"
  },
  {
    "workflowId": "wf-002",
    "requestType": "Bill",
    "entityId": "<Bill seed object ID>",
    "entityType": "bill",
    "requesterId": "karin.bakker",
    "status": "in_review",
    "currentStepOrder": 1,
    "assignedApproverId": "peter.smit",
    "submittedAt": "2026-04-01T10:00:00Z",
    "costCentreId": "CC-200",
    "notes": "Factuur Acme BV — leverantie maart 2026"
  },
  {
    "workflowId": "wf-001",
    "requestType": "Expenditure",
    "entityId": "<Expenditure seed object ID>",
    "entityType": "expenditure",
    "requesterId": "tom.visser",
    "status": "pending",
    "currentStepOrder": 1,
    "assignedApproverId": "lisa.de.groot",
    "submittedAt": "2026-04-05T09:00:00Z",
    "costCentreId": "CC-100",
    "notes": "Onvoorziene uitgave: reparatie serverruimte"
  }
]
```

### ApprovalDecision seed objects (2)

```json
[
  {
    "requestId": "<ApprovalRequest PO seed ID>",
    "stepOrder": 1,
    "approverId": "lisa.de.groot",
    "decision": "approved",
    "justification": "Budget beschikbaar op kostenplaats CC-100; inkooporder conform raamcontract.",
    "decidedAt": "2026-03-10T11:00:00Z"
  },
  {
    "requestId": "<ApprovalRequest PO seed ID>",
    "stepOrder": 2,
    "approverId": "maria.jansen",
    "decision": "approved",
    "justification": "Bedrag valt binnen goedgekeurde budgettolerantie; leverancier gekwalificeerd.",
    "decidedAt": "2026-03-10T14:15:00Z"
  }
]
```

## Security Considerations

- Only the currently assigned approver (`assignedApproverId`) and users with `admin` CollaborationRole may record a decision on an `ApprovalRequest`; enforced by `ApprovalDecisionService` and verified by `AccessControl`
- `ApprovalDecision` records are append-only; no DELETE or PATCH endpoint is registered for `ApprovalDecision`
- PKIoverheid signing session URL and API credentials are stored in `AppSettings` with `editable: false` when set via environment variable; never exposed to the frontend
- Revision diffs stored in `revisionDiff` must exclude sensitive fields (IBAN, personal data) — the diff utility masks field values for properties tagged `x-sensitive: true` in the schema
- Batch payment approval enforces a maximum of 100 bill IDs per batch to prevent abuse of bulk operations
- `conditionExpression` in `ApprovalStep` is evaluated by a restricted expression parser that only supports comparison operators and numeric literals against a whitelist of entity field names; no arbitrary PHP or JavaScript is executed

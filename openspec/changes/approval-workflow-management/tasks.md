# Tasks: approval-workflow-management

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `approvalWorkflow` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `approvalWorkflow` MUST be registered with all properties from the data model
    - AND `workflowId`, `name`, `workflowType`, `isActive`, `workflowConfig`, `createdBy`, `createdDate` MUST be marked required
    - AND `workflowType` MUST have enum `["PurchaseOrder","Bill","ExpenseClaim","Expenditure","Requisition","Contract"]`
    - AND `isActive` MUST be type boolean with default `true`
    - AND `workflowConfig` MUST be type object
    - AND `x-schema-org` annotation MUST be `schema:Thing`

- [ ] 1.2 Add `approvalStep` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `approvalStep` MUST exist with `workflowId` (required), `stepOrder` (required, integer), `stepName` (required), `approverType` (required), `isMandatory` (required, boolean)
    - AND `approverType` MUST have enum `["user","role","costCentreOwner","seniorApprover"]`
    - AND `escalationAfterHours` MUST be type integer with default `48`
    - AND `isMandatory` MUST have default `true`
    - AND `x-schema-org` MUST be `schema:Action`

- [ ] 1.3 Add `approvalRequest` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-002`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `approvalRequest` MUST exist with `workflowId` (required), `requestType` (required), `entityId` (required), `entityType` (required), `requesterId` (required), `status` (required)
    - AND `requestType` MUST have enum `["PurchaseOrder","Bill","ExpenseClaim","Expenditure","Requisition","Contract","Batch"]`
    - AND `status` MUST have enum `["draft","pending","in_review","approved","rejected","cancelled","escalated"]` with default `"draft"`
    - AND `supportingDocumentIds` MUST be type array with default `[]`
    - AND `batchBillIds` MUST be type array with default `[]`
    - AND `x-schema-org` MUST be `schema:Action`

- [ ] 1.4 Add `approvalDecision` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-003`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `approvalDecision` MUST exist with `requestId` (required), `stepOrder` (required), `approverId` (required), `decision` (required), `justification` (required), `decidedAt` (required)
    - AND `decision` MUST have enum `["approved","rejected","request_info","delegated"]`
    - AND `decidedAt` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Action`

## 2. Seed Data

- [ ] 2.1 Add ApprovalWorkflow seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 2 ApprovalWorkflow objects MUST be created: "Inkooporder goedkeuring" (PurchaseOrder, isActive true) and "Leveranciersfactuur goedkeuring" (Bill, isActive true)
    - AND each workflow MUST have `workflowConfig` populated with the step definitions from the design seed data
    - AND idempotency check MUST use `ApprovalWorkflow.name` as the unique key

- [ ] 2.2 Add ApprovalStep seed objects to repair step
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 4 ApprovalStep objects MUST be created as defined in the seed data table
    - AND idempotency check MUST use `(workflowId, stepOrder)` as the composite unique key

- [ ] 2.3 Add ApprovalRequest seed objects to repair step
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 ApprovalRequest seed objects MUST be created: one PurchaseOrder (approved), one Bill (in_review), one Expenditure (pending)
    - AND idempotency check MUST use `(requesterId, entityType, submittedAt)` as the composite unique key

- [ ] 2.4 Add ApprovalDecision seed objects to repair step
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 2 ApprovalDecision objects MUST be created linked to the approved PurchaseOrder ApprovalRequest seed
    - AND idempotency check MUST use `(requestId, stepOrder, approverId)` as the composite unique key

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/WorkflowRoutingService.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-002`
  - **files**: `lib/Service/WorkflowRoutingService.php`
  - **acceptance_criteria**:
    - GIVEN a requestType and entity object are passed
    - THEN the service loads the active `ApprovalWorkflow` matching the requestType via OpenRegister query
    - AND evaluates each step's `conditionExpression` against the entity fields using a whitelist expression parser (field names + comparison operators + numeric literals only; no eval)
    - AND resolves `costCentreOwner` by querying Organisation objects for `costCentreCode` matching the entity's `costCentreId`
    - AND creates an `ApprovalRequest` in `pending` status with `currentStepOrder: 1` and `assignedApproverId` set
    - AND sends a Nextcloud notification to `assignedApproverId` via `INotifier` with message "U heeft een goedkeuringsverzoek ontvangen voor {requestType} {entityId}"
    - AND throws `NoActiveWorkflowException` if no active workflow is found for the requestType

- [ ] 3.2 Create `lib/Service/ApprovalDecisionService.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-003`
  - **files**: `lib/Service/ApprovalDecisionService.php`
  - **acceptance_criteria**:
    - GIVEN a decision request arrives with `requestId`, `decision`, and `justification`
    - THEN the service verifies the acting userId matches `ApprovalRequest.assignedApproverId` or has `admin` CollaborationRole; returns 403 if not
    - AND validates `justification` is non-empty; returns 422 if empty
    - AND creates an `ApprovalDecision` record with all required fields and `decidedAt` set to now
    - AND on `decision: approved`: loads the next step; if a next step exists, advances `currentStepOrder`, resolves the next approver, and notifies; if no next step exists, sets `ApprovalRequest.status: approved` and `resolvedAt`
    - AND on `decision: rejected`: sets `ApprovalRequest.status: rejected` and `resolvedAt`; notifies `requesterId`
    - AND on `decision: request_info`: creates a `Comment` on the `ApprovalRequest` tagged `information_request`; sets `status: draft`; notifies `requesterId` with the question text
    - AND on `decision: delegated`: validates `delegatedToId` is provided; sets `assignedApproverId: delegatedToId` and notifies the delegate
    - AND all decisions are written to `AuditTrail` via OpenRegister

- [ ] 3.3 Create `lib/Service/BatchPaymentApprovalService.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-006`
  - **files**: `lib/Service/BatchPaymentApprovalService.php`
  - **acceptance_criteria**:
    - GIVEN an array of Bill object IDs is passed (max 100)
    - THEN the service validates each bill exists and has status `approved` in accounts-payable; returns 422 listing non-compliant bills if any fail
    - AND creates a single `ApprovalRequest` of `requestType: Batch` with `batchBillIds` populated
    - AND on batch approval: updates each linked bill's `paymentStatus` to `payment_authorised` via OpenRegister
    - AND if the batch exceeds 100 bills the service returns 422 "Batch size limit exceeded (max 100)"

- [ ] 3.4 Create `lib/Service/ContractSigningService.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-007`
  - **files**: `lib/Service/ContractSigningService.php`
  - **acceptance_criteria**:
    - GIVEN an `ApprovalRequest` of `requestType: Contract` is in `in_review` status
    - THEN the service reads the signing URL from AppSettings key `pkioverheid.signingUrl`; throws `SigningConfigException` if absent
    - AND calls the signing endpoint via `IClientService` with the document reference and returns a session URL for frontend redirect
    - AND stores the `signingSessionRef` on the `ApprovalRequest` via OpenRegister update
    - AND on polling call: queries the signing endpoint for session status; on `completed` response stores `certificateRef` and `signedAt` in a new `ApprovalDecision` with `decision: approved` and `justification: "PKIoverheid QES applied"`
    - AND the signed document is stored via the document attachment mechanism linking to the `ApprovalRequest` entity

- [ ] 3.5 Implement PO/Bill revision re-approval trigger in `lib/Service/WorkflowRoutingService.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-004`
  - **files**: `lib/Service/WorkflowRoutingService.php`
  - **acceptance_criteria**:
    - GIVEN a `PurchaseOrder` or `Bill` entity is updated via OpenRegister PATCH
    - WHEN a prior `ApprovalRequest` for this entity exists with `status: approved`
    - THEN the service computes a field diff between the stored and updated entity values (excluding read-only and audit fields)
    - AND if material fields changed (amount, supplierId, deliveryDate for PO; amount, billDate for Bill): cancels the existing `ApprovalRequest` (status: cancelled), stores `revisionDiff` on a new `ApprovalRequest` with `revisionOf` referencing the cancelled request, and starts routing from step 1
    - AND if no material fields changed: no new `ApprovalRequest` is created

## 4. Background Job

- [ ] 4.1 Create `lib/BackgroundJob/ApprovalEscalationJob.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-005`
  - **files**: `lib/BackgroundJob/ApprovalEscalationJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 3600 seconds (hourly)
    - WHEN the job runs THEN it queries all `ApprovalRequest` objects with `status: in_review` or `status: pending`
    - AND for each request: loads the current `ApprovalStep` and checks if `submittedAt + (escalationAfterHours × 3600)` is in the past
    - AND if escalation threshold is exceeded: sets `status: escalated`, resolves the senior approver from the step's `seniorApproverRef` (or the org admin if absent), updates `assignedApproverId`, and sends notifications to both the original and senior approvers
    - AND records the escalation event in `AuditTrail` with actor `system` and reason "Escalation threshold exceeded"
    - AND the job is idempotent: if the request is already `escalated` it is skipped

## 5. Backend Controllers

- [ ] 5.1 Create `lib/Controller/ApprovalWorkflowController.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-001`
  - **files**: `lib/Controller/ApprovalWorkflowController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/approval-workflows` is called THEN all ApprovalWorkflow objects are returned; filtered by `workflowType` query param if provided
    - GIVEN `POST /api/v1/approval-workflows/{id}/activate` is called THEN `isActive` is set to true; if another workflow of the same type is active it is deactivated first
    - GIVEN `POST /api/v1/approval-workflows/{id}/deactivate` is called THEN `isActive` is set to false; 409 if no other active workflow of that type would remain

- [ ] 5.2 Create `lib/Controller/ApprovalRequestController.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-002, #REQ-AWF-003`
  - **files**: `lib/Controller/ApprovalRequestController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/approval-requests` is called with `{entityType, entityId, requestType, costCentreId, notes, supportingDocumentIds}` THEN `WorkflowRoutingService::route()` is called and the created `ApprovalRequest` is returned; 422 if no active workflow found
    - GIVEN `POST /api/v1/approval-requests/{id}/decide` is called with `{decision, justification, delegatedToId?}` THEN `ApprovalDecisionService::decide()` is called; 403 if caller is not the assigned approver or admin; 422 if justification is empty
    - GIVEN `POST /api/v1/approval-requests/{id}/resubmit` is called THEN the request must be in `draft` status; status changes to `pending` and the assigned approver is re-notified; 422 if status is not `draft`
    - GIVEN `GET /api/v1/approval-requests?assignedTo=me` is called THEN only requests where `assignedApproverId` matches the authenticated userId are returned
    - GIVEN `POST /api/v1/approval-requests/batch` is called with `{billIds: [...]}` THEN `BatchPaymentApprovalService::createBatch()` is called; 422 if bill count > 100 or any bill is not approval-eligible

- [ ] 5.3 Create `lib/Controller/ContractSigningController.php`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-007`
  - **files**: `lib/Controller/ContractSigningController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/approval-requests/{id}/initiate-signing` is called THEN `ContractSigningService::initiateSession()` is called and the signing URL is returned as JSON `{signingUrl: "..."}` for frontend redirect
    - GIVEN `GET /api/v1/approval-requests/{id}/signing-status` is called THEN `ContractSigningService::pollStatus()` is called; returns `{status: "pending"|"completed"|"failed", signedAt?, certificateRef?}`

## 6. Pinia Stores

- [ ] 6.1 Create `src/store/modules/approvalWorkflow.js`
  - **files**: `src/store/modules/approvalWorkflow.js`
  - **acceptance_criteria**:
    - THEN `useApprovalWorkflowStore` MUST be created via `createObjectStore('approvalWorkflow')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.2 Create `src/store/modules/approvalStep.js`
  - **files**: `src/store/modules/approvalStep.js`
  - **acceptance_criteria**:
    - THEN `useApprovalStepStore` MUST be created via `createObjectStore('approvalStep')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.3 Create `src/store/modules/approvalRequest.js`
  - **files**: `src/store/modules/approvalRequest.js`
  - **acceptance_criteria**:
    - THEN `useApprovalRequestStore` MUST be created via `createObjectStore('approvalRequest')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.4 Create `src/store/modules/approvalDecision.js`
  - **files**: `src/store/modules/approvalDecision.js`
  - **acceptance_criteria**:
    - THEN `useApprovalDecisionStore` MUST be created via `createObjectStore('approvalDecision')`
    - AND the store MUST be registered in `src/store/store.js`

## 7. Frontend Views — Approval Workflow

- [ ] 7.1 Create `src/views/approvalWorkflow/ApprovalWorkflowIndex.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-001`
  - **files**: `src/views/approvalWorkflow/ApprovalWorkflowIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('approvalWorkflow')`
    - AND filter chips for `workflowType` and `isActive` MUST be present via `filtersFromSchema()`
    - AND a "New Workflow" button opens `ApprovalWorkflowForm.vue`
    - AND an active/inactive toggle button is displayed per row calling the activate/deactivate endpoint

- [ ] 7.2 Create `src/views/approvalWorkflow/ApprovalWorkflowDetail.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-001`
  - **files**: `src/views/approvalWorkflow/ApprovalWorkflowDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Overview, Steps, Requests
    - AND the Steps tab embeds `WorkflowDesigner.vue` showing the current step chain
    - AND the Requests tab lists all `ApprovalRequest` objects linked to this workflow with status chips

- [ ] 7.3 Create `src/views/approvalWorkflow/ApprovalWorkflowForm.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-001`
  - **files**: `src/views/approvalWorkflow/ApprovalWorkflowForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with `fieldsFromSchema('approvalWorkflow')`
    - AND the `workflowConfig` field is replaced by an embedded `WorkflowDesigner.vue` component
    - AND saving the form serialises the designer state into `workflowConfig` JSON before submission

## 8. Frontend Views — Approval Request

- [ ] 8.1 Create `src/views/approvalRequest/ApprovalRequestIndex.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-002`
  - **files**: `src/views/approvalRequest/ApprovalRequestIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('approvalRequest')`
    - AND a "My Pending Approvals" mode is toggled by a button that sets `?assignedTo=me` query param
    - AND filter chips for `status`, `requestType`, and overdue (computed from escalation threshold) MUST be present
    - AND overdue requests MUST display a red clock icon in the status column

- [ ] 8.2 Create `src/views/approvalRequest/ApprovalRequestDetail.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-002, #REQ-AWF-003`
  - **files**: `src/views/approvalRequest/ApprovalRequestDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Details, History, Documents
    - AND the Details tab embeds `ApprovalDecisionPanel.vue` showing the current step and approve/reject/request_info/delegate buttons only when the authenticated user is the assigned approver
    - AND the History tab renders `ApprovalTimeline.vue` listing all `ApprovalDecision` records in chronological order with actor, decision badge, justification, and timestamp
    - AND if `revisionDiff` is set a "View Changes" button opens `RevisionDiffPanel.vue` showing before/after field values
    - AND the Documents tab embeds the document attachment panel scoped to the `ApprovalRequest` entity

- [ ] 8.3 Create `src/views/approvalRequest/ApprovalRequestForm.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-002`
  - **files**: `src/views/approvalRequest/ApprovalRequestForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with fields: requestType selector, entityId search (filtered by entityType), costCentreId, notes, and supporting document upload
    - AND on `requestType: Batch` selection, the `entityId` field is replaced by `BatchApprovalSelector.vue` for multi-select bill selection

## 9. Frontend Components

- [ ] 9.1 Create `src/components/WorkflowDesigner.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-009`
  - **files**: `src/components/WorkflowDesigner.vue`, `src/components/ApprovalStepCard.vue`
  - **acceptance_criteria**:
    - GIVEN the designer renders with an existing `workflowConfig` THEN the existing steps are displayed as ordered draggable cards
    - AND steps can be reordered by dragging using the HTML5 native DnD API (no external library)
    - AND clicking "Add Step" appends a new empty `ApprovalStepCard.vue` at the bottom of the chain
    - AND each step card shows inline fields for `stepName`, `approverType`, `approverRef`, `conditionExpression`, `escalationAfterHours`, `isMandatory`
    - AND `approverType: costCentreOwner` or `seniorApprover` hides the `approverRef` field
    - AND the designer emits `update:modelValue` with the serialised `workflowConfig` object on any change

- [ ] 9.2 Create `src/components/ApprovalDecisionPanel.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-003`
  - **files**: `src/components/ApprovalDecisionPanel.vue`
  - **acceptance_criteria**:
    - GIVEN an `ApprovalRequest` prop is passed and the authenticated user is the `assignedApproverId` THEN Approve, Reject, Request Info, and Delegate buttons are rendered
    - AND clicking Approve or Reject opens an inline confirmation with a mandatory justification textarea; submission is blocked until the field is non-empty
    - AND clicking Request Info opens a dialog with a free-text question field; the question is submitted as the justification
    - AND clicking Delegate opens a user-picker dialog; selecting a user sets `delegatedToId` and submits `decision: delegated`
    - AND if the user is NOT the assigned approver THEN only the current step status and assigned approver name are shown; all action buttons are hidden

- [ ] 9.3 Create `src/components/ApprovalTimeline.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-003`
  - **files**: `src/components/ApprovalTimeline.vue`
  - **acceptance_criteria**:
    - GIVEN a `requestId` prop is passed THEN all `ApprovalDecision` records for the request are fetched and rendered in chronological order
    - AND each entry shows: step number, approver name, decision badge (approved=green, rejected=red, request_info=amber, delegated=blue), justification text, and `decidedAt` timestamp
    - AND PKIoverheid decisions additionally show the `certificateRef` as a truncated monospace string with a copy button

- [ ] 9.4 Create `src/components/RevisionDiffPanel.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-004`
  - **files**: `src/components/RevisionDiffPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `revisionDiff` object prop is passed THEN each changed field is displayed as a two-column table: field name | before value | after value
    - AND changed numeric values show the delta (e.g. "+ EUR 5.000") highlighted in amber
    - AND fields absent from `revisionDiff` are not displayed

- [ ] 9.5 Create `src/components/BatchApprovalSelector.vue`
  - **spec_ref**: `specs/approval-workflow-management/spec.md#REQ-AWF-006`
  - **files**: `src/components/BatchApprovalSelector.vue`
  - **acceptance_criteria**:
    - GIVEN the component renders THEN it shows a filterable list of Bill objects with `status: approved` in accounts-payable
    - AND each bill row has a checkbox; the total selected count and aggregate amount are shown in a sticky footer
    - AND when the selection exceeds 100 bills the "Add to Batch" button is disabled with tooltip "Maximaal 100 facturen per batch"
    - AND `update:modelValue` is emitted with the array of selected bill IDs on change

## 10. Sidebar Navigation Update

- [ ] 10.1 Add approval management sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN an "Goedkeuringen" section MUST be present with nav items: Mijn verzoeken, Goedkeuringsworkflows, Alle verzoeken
    - AND the "Mijn verzoeken" item MUST show a badge with the count of `ApprovalRequest` objects where `assignedApproverId` matches the authenticated userId and `status` is `pending` or `in_review`
    - AND a "Settings" sub-item for Workflow Configuration MUST be present under the Settings section

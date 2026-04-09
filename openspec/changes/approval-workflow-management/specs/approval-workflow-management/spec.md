---
status: proposed
---

# Approval & Workflow Management — Shillinq

## Purpose

Defines functional requirements for Shillinq's approval and workflow management capabilities: a configurable multi-step approval engine with drag-and-drop workflow design, automated routing for purchase orders, vendor bills, expense claims, expenditure requests, requisitions, and contracts, PKIoverheid-compatible contract signing with qualified electronic signatures, re-approval on entity revision with change tracking, batch payment approval, and escalation management.

Stakeholders: Treasurer, Group Controller, Customer.

User stories addressed: Receive and review contract signing request, Sign contract with PKIoverheid certificate, Countersign contract requiring multiple authorities, Reject contract before signing, View audit trail of signature events.

## Requirements

### REQ-AWF-001: ApprovalWorkflow and ApprovalStep Schema Registration [must]

The app MUST register the `ApprovalWorkflow` schema (`schema:Thing`) and `ApprovalStep` schema (`schema:Action`) in OpenRegister via `lib/Settings/shillinq_register.json`. The `ApprovalWorkflow` entity MUST carry a `workflowConfig` JSON object serialised from the drag-and-drop workflow designer. Each workflow MUST have at most one active instance per `workflowType` at any time. Deactivating a workflow MUST be blocked if it would leave the type with no active workflow.

**Scenarios:**

1. **GIVEN** an administrator opens the workflow list **WHEN** they create a new `ApprovalWorkflow` with `workflowType: PurchaseOrder` and `isActive: true` **THEN** the workflow is saved in OpenRegister with a generated `workflowId`, `createdDate` set to now, and the admin receives no error.

2. **GIVEN** an active `ApprovalWorkflow` of type `PurchaseOrder` already exists **WHEN** a second workflow of type `PurchaseOrder` is activated via `POST /api/v1/approval-workflows/{id}/activate` **THEN** the previously active workflow is set to `isActive: false` and the new one is set to `isActive: true`; only one active workflow per type exists at any time.

3. **GIVEN** an administrator opens the workflow detail page **WHEN** the Steps tab renders **THEN** `WorkflowDesigner.vue` displays all linked `ApprovalStep` objects as ordered draggable cards with their step name, approver type, condition expression, and escalation timer visible.

4. **GIVEN** the admin drags step 2 above step 1 in the designer and saves **WHEN** the save completes **THEN** `ApprovalStep.stepOrder` values are updated in OpenRegister to reflect the new order and `workflowConfig` is re-serialised with the updated sequence.

5. **GIVEN** `POST /api/v1/approval-workflows/{id}/deactivate` is called and no other active workflow of that type exists **WHEN** the request is processed **THEN** the API returns 409 with "Minimaal één actieve workflow per type is vereist."

6. **GIVEN** a step has `approverType: costCentreOwner` **WHEN** the `ApprovalStepCard.vue` renders **THEN** the `approverRef` field is hidden and a label "Budgethouder wordt bepaald via kostenplaats" is shown in its place.

### REQ-AWF-002: ApprovalRequest Submission and Routing [must]

The app MUST register the `ApprovalRequest` schema (`schema:Action`) and provide a `WorkflowRoutingService` that evaluates the active workflow for the given requestType, resolves the correct approver, and notifies them via Nextcloud. Submitting a request MUST create an audit trail entry. The approval request inbox MUST show all requests assigned to the authenticated user.

**Scenarios:**

1. **GIVEN** a procurement officer submits a purchase order via `POST /api/v1/approval-requests` with `requestType: PurchaseOrder`, `entityId: po-001`, `costCentreId: CC-100` **WHEN** `WorkflowRoutingService` runs **THEN** an `ApprovalRequest` is created with `status: pending`, `currentStepOrder: 1`, and `assignedApproverId` set to the userId of the Organisation with `costCentreCode: CC-100`; the assigned approver receives a Nextcloud notification.

2. **GIVEN** the active PurchaseOrder workflow has a second step with `conditionExpression: amount >= 10000` **WHEN** the first step is approved for a PO with `amount: 5000` **THEN** the second step condition evaluates to false, the step is skipped, and the `ApprovalRequest` moves directly to `status: approved`.

3. **GIVEN** a department manager submits an expenditure request with `requestType: Expenditure` and `notes: "Onvoorziene reparatie serverruimte"` **WHEN** the request is submitted **THEN** an `ApprovalRequest` is created in `pending` status and the resolved budget holder receives a notification including the amount and the notes text.

4. **GIVEN** no active `ApprovalWorkflow` exists for `requestType: ExpenseClaim` **WHEN** `POST /api/v1/approval-requests` is called with that requestType **THEN** the API returns 422 with "Geen actieve workflow gevonden voor ExpenseClaim."

5. **GIVEN** the authenticated user navigates to the approval request inbox **WHEN** the "Mijn verzoeken" filter is active **THEN** only `ApprovalRequest` objects where `assignedApproverId` equals the userId and `status` is `pending` or `in_review` are displayed; overdue requests show a red clock icon.

6. **GIVEN** a requester resubmits a request that is in `draft` status (returned for more info) **WHEN** `POST /api/v1/approval-requests/{id}/resubmit` is called **THEN** the status changes to `pending`, the current step approver is re-notified, and a `Comment` is added to the timeline noting the resubmission timestamp.

### REQ-AWF-003: ApprovalDecision Capture and Audit Trail [must]

The app MUST register the `ApprovalDecision` schema (`schema:Action`) and enforce that only the currently assigned approver (or an admin) may record a decision. Justification MUST be non-empty for all decision types. `ApprovalDecision` records MUST be append-only. All decisions MUST be written to the `AuditTrail` via OpenRegister.

**Scenarios:**

1. **GIVEN** an approver opens the `ApprovalRequestDetail` page **WHEN** they click "Goedkeuren" and enter justification "Budget beschikbaar; leverancier gekwalificeerd" and confirm **THEN** `ApprovalDecisionService` creates an `ApprovalDecision` with `decision: approved`, `decidedAt` set to now, and advances the `ApprovalRequest` to the next step or to `status: approved` if no further step exists.

2. **GIVEN** an approver clicks "Afwijzen" and leaves the justification field empty **WHEN** the form is submitted **THEN** a validation error "Toelichting is verplicht" is displayed and no `ApprovalDecision` is created.

3. **GIVEN** an approver clicks "Aanvullende informatie opvragen" and enters the question "Kunt u een offerte bijvoegen?" **WHEN** the form is submitted **THEN** an `ApprovalDecision` with `decision: request_info` is created, a `Comment` tagged `information_request` with the question text is added to the `ApprovalRequest`, `status` is set to `draft`, and the requester receives a notification.

4. **GIVEN** an approver clicks "Delegeren" and selects user `lisa.de.groot` **WHEN** the delegation is submitted **THEN** an `ApprovalDecision` with `decision: delegated` and `delegatedToId: lisa.de.groot` is created, `assignedApproverId` on the `ApprovalRequest` is updated to `lisa.de.groot`, and the delegate receives a notification "Goedkeuringsverzoek gedelegeerd aan u."

5. **GIVEN** a user who is NOT the assigned approver calls `POST /api/v1/approval-requests/{id}/decide` **WHEN** the request is processed **THEN** the API returns 403 "U bent niet de aangewezen goedkeurder voor dit verzoek."

6. **GIVEN** the `ApprovalTimeline.vue` renders for a request with three decisions **WHEN** the timeline is displayed **THEN** each entry shows the approver name, a colour-coded decision badge, the justification text, and the `decidedAt` timestamp in chronological order.

7. **GIVEN** a signed audit trail is required **WHEN** any `ApprovalDecision` is created **THEN** an `AuditTrail` object is written via OpenRegister with fields: `actor`, `action: decision`, `targetType: approvalRequest`, `targetId`, `timestamp`, `details: {decision, stepOrder}`.

### REQ-AWF-004: PO and Bill Revision Re-approval [must]

When a `PurchaseOrder` or `Bill` entity is updated after an `ApprovalRequest` for it has reached `status: approved`, the app MUST automatically cancel the existing request, store a revision diff, and create a new `ApprovalRequest` from step 1. The revision diff MUST be visible in the approval request detail view.

**Scenarios:**

1. **GIVEN** a PurchaseOrder with `amount: 8000` has an approved `ApprovalRequest` **WHEN** the PO `amount` is updated to `12000` **THEN** the existing `ApprovalRequest` status changes to `cancelled`, a new `ApprovalRequest` is created with `revisionOf: <cancelled request ID>`, `revisionDiff: {amount: {before: 8000, after: 12000}}`, `status: pending`, and routing starts from step 1.

2. **GIVEN** a revised `ApprovalRequest` detail page is open **WHEN** the user clicks "Wijzigingen bekijken" **THEN** `RevisionDiffPanel.vue` renders a two-column table showing the before and after values for each changed field; unchanged fields are not shown.

3. **GIVEN** a PurchaseOrder's `deliveryDate` is updated but no other material fields change **WHEN** `WorkflowRoutingService` evaluates the change **THEN** no new `ApprovalRequest` is created and the existing approved request remains `approved`.

4. **GIVEN** a Bill's `supplierId` is changed after approval **WHEN** the revision is evaluated **THEN** the existing request is cancelled and a new `ApprovalRequest` is created because supplier changes are considered material for bills.

5. **GIVEN** a new `ApprovalRequest` is created as a revision of a prior one **WHEN** an approver opens the detail page **THEN** a banner "Dit is een herbeoordelingsverzoek — bekijk de wijzigingen" with a link to the revision diff is displayed at the top of the Details tab.

### REQ-AWF-005: Approval Escalation [must]

The app MUST run an hourly background job that identifies overdue approval steps and escalates them to the senior approver defined on the step. Escalation events MUST be recorded in the `AuditTrail` and trigger Nextcloud notifications to both the original and senior approvers.

**Scenarios:**

1. **GIVEN** an `ApprovalRequest` is in `in_review` status with `currentStepOrder: 1` and the step has `escalationAfterHours: 24` **WHEN** 25 hours have elapsed since `submittedAt` and the `ApprovalEscalationJob` runs **THEN** `status` changes to `escalated`, `assignedApproverId` is updated to the `seniorApproverRef` of the step, and both the original approver and the senior approver receive notifications.

2. **GIVEN** a step has no `seniorApproverRef` **WHEN** the escalation job resolves the senior approver **THEN** the job falls back to the user with the `admin` CollaborationRole for the request's Organisation; if no admin is found the escalation is logged as a warning in the Nextcloud log and the request remains in its current status.

3. **GIVEN** an `ApprovalRequest` is already in `escalated` status **WHEN** the `ApprovalEscalationJob` runs again **THEN** the request is skipped; no duplicate notifications are sent and no second escalation record is created.

4. **GIVEN** an escalation event occurs **WHEN** the `AuditTrail` record is written **THEN** it contains `actor: system`, `action: escalated`, `details: {previousApproverId, newApproverId, escalationAfterHours}`, and the current timestamp.

5. **GIVEN** the authenticated user views the approval inbox after escalation **WHEN** the page renders **THEN** the escalated request appears at the top of the list with an amber "Geëscaleerd" status badge.

### REQ-AWF-006: Batch Payment Approval [must]

The app MUST allow a treasurer to group multiple vendor bills into a single approval batch. The batch MUST be limited to 100 bills. On batch approval, all linked bills MUST be marked as payment-authorised in a single operation.

**Scenarios:**

1. **GIVEN** a treasurer opens the batch approval form and selects 15 approved vendor bills totalling EUR 142.300 **WHEN** they submit the batch **THEN** `BatchPaymentApprovalService` creates one `ApprovalRequest` of `requestType: Batch` with `batchBillIds` containing the 15 bill IDs and the aggregate amount displayed in the request details.

2. **GIVEN** the treasurer selects 101 bills **WHEN** they click "Toevoegen aan batch" **THEN** the button is disabled and the tooltip shows "Maximaal 100 facturen per batch."

3. **GIVEN** a batch `ApprovalRequest` is approved **WHEN** `ApprovalDecisionService` processes the approval **THEN** `BatchPaymentApprovalService` sets `paymentStatus: payment_authorised` on each of the 15 linked bill objects in OpenRegister.

4. **GIVEN** one of the 15 bills is not in `approved` state **WHEN** the batch is submitted via `POST /api/v1/approval-requests/batch` **THEN** the API returns 422 with a list of the non-eligible bill IDs and no `ApprovalRequest` is created.

5. **GIVEN** the batch `ApprovalRequest` detail page renders **WHEN** the Details tab is open **THEN** a table of all linked bills is shown with columns: bill reference, supplier name, amount, and current payment status.

### REQ-AWF-007: PKIoverheid Contract Signing [must]

When an `ApprovalRequest` of `requestType: Contract` reaches its signing step, the app MUST initiate a PKIoverheid-compatible signing session. The resulting qualified electronic signature (QES) timestamp and certificate reference MUST be stored in the `ApprovalDecision` record. The signed document MUST be stored via the document attachment mechanism.

**Scenarios:**

1. **GIVEN** a contract `ApprovalRequest` reaches a step with `approverType: user` and the approver is a mandated signing authority **WHEN** the approver opens the detail page **THEN** an "Onderteken met PKIoverheid" button is shown in `ApprovalDecisionPanel.vue` instead of the standard Approve button.

2. **GIVEN** the approver clicks "Onderteken met PKIoverheid" **WHEN** `POST /api/v1/approval-requests/{id}/initiate-signing` is called **THEN** `ContractSigningService` returns a `signingUrl` and the frontend redirects the user to the signing session; `signingSessionRef` is stored on the `ApprovalRequest`.

3. **GIVEN** the signing ceremony completes **WHEN** `GET /api/v1/approval-requests/{id}/signing-status` returns `status: completed` **THEN** an `ApprovalDecision` is created with `decision: approved`, `signingSessionRef`, `certificateRef`, and `signedAt`; the `ApprovalRequest` advances to the next step or closes as `approved`.

4. **GIVEN** a second signatory is required (two internal signatories) **WHEN** the first signatory completes signing **THEN** the `ApprovalRequest` status changes to `in_review` and the second signatory's step is activated with a notification "Contract vereist uw handtekening."

5. **GIVEN** all required signatories have signed **WHEN** the last `ApprovalDecision` with `decision: approved` is recorded **THEN** the `ApprovalRequest` status changes to `approved`, the contract entity status is updated to `fully_executed`, and all parties receive a Nextcloud notification with a link to the signed document.

6. **GIVEN** the signing session URL is not configured in AppSettings **WHEN** the initiate-signing endpoint is called **THEN** the API returns 503 "PKIoverheid ondertekeningsdienst is niet geconfigureerd."

7. **GIVEN** the `ApprovalTimeline.vue` renders a signed decision **WHEN** the entry is displayed **THEN** the certificate reference is shown as a truncated monospace string (first 16 and last 8 characters) with a copy-to-clipboard button.

### REQ-AWF-008: Seed Data [must]

The app MUST load demo seed data for all new schemas via the repair step. Seed data MUST be idempotent — running the repair step multiple times MUST NOT create duplicate records.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** all seed records are created: 2 ApprovalWorkflows, 4 ApprovalSteps, 3 ApprovalRequests, and 2 ApprovalDecisions.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate records are created; idempotency keys (`ApprovalWorkflow.name`, composite `(workflowId, stepOrder)`, composite `(requesterId, entityType, submittedAt)`, composite `(requestId, stepOrder, approverId)`) are checked before insertion.

3. **GIVEN** the seed data is loaded **WHEN** the "Inkooporder goedkeuring" workflow is viewed **THEN** it has exactly 2 linked `ApprovalStep` objects with `stepOrder` 1 and 2.

4. **GIVEN** the seed ApprovalRequest with `requestType: PurchaseOrder` is created **WHEN** the `ApprovalRequestDetail` is opened **THEN** 2 `ApprovalDecision` records are visible in the timeline, both with `decision: approved`, for steps 1 and 2 respectively.

### REQ-AWF-009: No-code Workflow Designer [must]

The app MUST provide a drag-and-drop workflow designer (`WorkflowDesigner.vue`) embedded in the `ApprovalWorkflowDetail` Steps tab and in the `ApprovalWorkflowForm`. The designer MUST use the native HTML5 Drag and Drop API without introducing external drag-and-drop packages. The designer serialises the step chain into the `workflowConfig` JSON field.

**Scenarios:**

1. **GIVEN** the workflow designer renders with a two-step chain **WHEN** the user drags step 2 above step 1 **THEN** the cards reorder visually and `update:modelValue` is emitted with the `workflowConfig` containing the updated `stepOrder` values.

2. **GIVEN** the user clicks "Stap toevoegen" in the designer **WHEN** the new step card appears **THEN** it is appended at the bottom with default values: `stepName: ""`, `approverType: user`, `escalationAfterHours: 48`, `isMandatory: true`, and the `stepName` field is focused automatically.

3. **GIVEN** a step card has `approverType: costCentreOwner` selected **WHEN** the card renders **THEN** the `approverRef` input is hidden and replaced with the label "Budgethouder op basis van kostenplaats"; no approverRef value is stored for this step.

4. **GIVEN** the user saves the workflow form **WHEN** the form submission is processed **THEN** the `workflowConfig.steps` array in the saved OpenRegister object has the same count and order as displayed in the designer, each step having non-empty `stepName` and valid `approverType`.

5. **GIVEN** the designer contains a step with empty `stepName` **WHEN** the user clicks Save **THEN** a validation error "Stapnaam is verplicht" is shown on the empty step card and the form is not submitted.

---
status: proposed
source: specter
features: [po-revision-management-change-tracking-re-approval-workflow, vendor-bill-management-approval-workflow-before-payment, bills-purchase-order-management-approval-workflows, submit-expenditure-request-budget-holder-approval, route-requisition-through-approval-workflow]
---

# Approval & Workflow Management — Shillinq

## Summary

Implements a configurable approval and workflow engine for Shillinq: a drag-and-drop workflow designer for building multi-step approval chains without coding, automated routing for purchase orders, vendor bills, expense claims, expenditure requests, and requisitions, PKIoverheid-compatible contract signing with full audit trail, budget holder approval for expenditure requests, re-approval triggering on PO or bill revision, and batch payment approval scheduling. These capabilities address the five highest-demand approval-workflow features identified in the Specter intelligence model and integrate with the core, access-control-authorisation, collaboration, supplier-management, catalog-purchase-management, and accounts-payable-receivable infrastructure already in place.

## Demand Evidence

Top features by market demand score:

- **PO revision management with change tracking and re-approval workflow** (demand: 2468) — the highest-ranked approval-workflow feature. Procurement teams need full change tracking on purchase orders after initial approval so that any material revision (amount, supplier, delivery date) automatically triggers a new approval cycle. Finance controllers need to compare the original and revised PO side-by-side.
- **Vendor bill management with approval workflow before payment** (demand: 1789) — accounts-payable officers need vendor bills routed through a configurable approval chain — two-way match against the PO, three-way match with goods receipt, and budget holder sign-off — before a payment instruction is released. Bills exceeding threshold amounts must escalate to a senior approver automatically.
- **Bills and purchase order management with approval workflows** (demand: 1679) — organisations need unified workflow configuration covering both purchase orders and vendor bills so that approval rules (approver roles, thresholds, escalation timers) are defined once and applied consistently across the procure-to-pay cycle.
- **Submit expenditure request for budget holder approval** (demand: 1431) — department managers need to raise ad-hoc expenditure requests outside the standard PO process and route them to the relevant budget holder (identified by cost centre ownership) for approval or rejection with a documented reason.
- **Route requisition through approval workflow** (demand: 1179) — procurement officers need purchase requisitions automatically routed based on configurable rules: amount thresholds, commodity category, cost centre, and supplier risk level. Each routing step must be visible in a status timeline.

Key stakeholder pain points addressed:

- **Treasurer**: manual approval chains managed over email with no audit trail; payment instructions released without confirmed sign-off — addressed by configurable multi-step approval chains, mandatory justification capture, and payment hold until approval is complete.
- **Group Controller**: no visibility into approval bottlenecks or escalation rate across entities; month-end close delayed by outstanding approvals — addressed by approval request dashboards, escalation timers with notifications, and consolidated approval status reporting.
- **Customer**: expenditure requests and requisitions submitted without a clear status update, leading to repeated chasing — addressed by departmental request status tracking with in-app notifications at each workflow step.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, supplier-management, catalog-purchase-management, accounts-payable-receivable, document-management, and scheduling changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `AccessControl`, `Comment`, `CollaborationRole`, `Document`, `DocumentVersion`, `SupplierProfile`, `SupplierCertification`, `Budget`, `Account`, `AuditTrail`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- Document attachment panel and supplier portal token mechanism
- Purchase order and vendor bill schemas from catalog-purchase-management and accounts-payable-receivable

### What Is Missing

- No `ApprovalWorkflow` schema for defining multi-step approval chains with drag-and-drop configuration
- No `ApprovalStep` schema for individual steps within a workflow (approver, conditions, escalation)
- No `ApprovalRequest` schema for tracking individual approval instances per entity
- No `ApprovalDecision` schema for recording each approver's decision with justification and timestamp
- No workflow routing engine that evaluates conditions (amount thresholds, cost centre, risk level) and assigns requests to the correct approver
- No re-approval trigger on PO or bill revision after initial approval
- No budget-holder resolution via cost centre ownership for expenditure requests
- No escalation background job for overdue approval steps
- No PKIoverheid-compatible signing flow integration for contract approval
- No batch payment approval queue for mass payment scheduling
- No drag-and-drop workflow designer Vue component

## Scope

### In Scope

1. **ApprovalWorkflow Schema** — OpenRegister `ApprovalWorkflow` schema (`schema:Thing`) defining the workflow template: name, workflowType (PurchaseOrder, Bill, ExpenseClaim, Expenditure, Requisition, Contract), isActive flag, and a `workflowConfig` JSON object that captures the ordered step definitions, routing conditions, and escalation rules. Views at `src/views/approvalWorkflow/`; store at `src/store/modules/approvalWorkflow.js`.

2. **ApprovalStep Schema** — OpenRegister `ApprovalStep` schema (`schema:Action`) for each step within a workflow: step order, approver type (user / role / costCentreOwner / seniorApprover), approver reference, amount threshold trigger, escalation timer in hours, and whether the step is mandatory. Each step belongs to one `ApprovalWorkflow`.

3. **ApprovalRequest Schema** — OpenRegister `ApprovalRequest` schema (`schema:Action`) for each approval instance: linked entity type and ID, current step, status (draft / pending / in_review / approved / rejected / cancelled / escalated), requester userId, submitted timestamp, resolved timestamp, and optional supporting document IDs. Views at `src/views/approvalRequest/`; store at `src/store/modules/approvalRequest.js`.

4. **ApprovalDecision Schema** — OpenRegister `ApprovalDecision` schema (`schema:Action`) for each approver decision on an `ApprovalRequest` step: approver userId, decision (approved / rejected / request_info / delegated), mandatory justification text, timestamp, and optional delegation target userId. Decisions are append-only.

5. **Workflow Routing Engine** — `lib/Service/WorkflowRoutingService.php` evaluates routing conditions for a given entity (PO, bill, requisition, expenditure, contract) against the active `ApprovalWorkflow` for that type, resolves the first pending step, assigns the `ApprovalRequest` to the correct approver, and sends a Nextcloud notification. Conditions supported: amount range, workflowType, cost centre ID, supplier risk level (from SupplierProfile), and CPV category.

6. **PO and Bill Re-approval on Revision** — when a `PurchaseOrder` or `Bill` entity is updated after an approved `ApprovalRequest` exists for it, `WorkflowRoutingService` detects the revision, stores a change summary (before/after field diff), sets the existing `ApprovalRequest` status to `cancelled`, and creates a new `ApprovalRequest` from step 1. A change-tracking panel in the PO/bill detail view lists all revision events.

7. **Expenditure Request Routing** — expenditure requests are submitted as `ApprovalRequest` objects with `requestType: Expenditure`. `WorkflowRoutingService` resolves the budget holder by looking up the cost centre owner from `Organization.costCentreOwnerId`. The budget holder receives a notification with the requested amount, cost centre, and justification text.

8. **No-code Workflow Designer** — `WorkflowDesigner.vue` provides a drag-and-drop canvas for building `ApprovalWorkflow` step chains. Steps are represented as draggable cards; connections define the routing order. Conditions (amount ≥ X, costCentre = Y) are set via form fields on each step card. The designer serialises the chain into `workflowConfig` JSON on save. Uses Vue 2.7 with no external drag-and-drop library (uses native HTML5 drag-and-drop API).

9. **Approval Request Dashboard** — `ApprovalRequestIndex.vue` provides a unified inbox of `ApprovalRequest` objects assigned to the current user, grouped by `requestType`. Filter chips for status, workflowType, and overdue flag. A "My Pending Approvals" widget is embedded in the Shillinq home dashboard.

10. **Escalation Background Job** — `lib/BackgroundJob/ApprovalEscalationJob.php` runs every hour, identifies `ApprovalRequest` objects in `in_review` status where the current step has exceeded its `escalationAfterHours` without a decision, sets status to `escalated`, moves the request to the senior approver defined in the step, and sends escalation notifications to both the original approver and the escalation target.

11. **PKIoverheid Contract Signing Integration** — `lib/Service/ContractSigningService.php` wraps the PKIoverheid signing flow for `ApprovalRequest` objects with `requestType: Contract`. It initiates an external signing session URL, stores the session reference on the `ApprovalRequest`, and polls for completion. On completion, a qualified electronic signature (QES) timestamp and certificate reference are stored in an `ApprovalDecision` record. The signed document is stored via the document attachment mechanism.

12. **Batch Payment Approval** — `ApprovalRequest` objects with `requestType: Bill` can be grouped into a batch by a treasurer. `lib/Service/BatchPaymentApprovalService.php` creates a single `ApprovalRequest` per batch linking multiple bill IDs. The treasurer approves the batch in one action; the service then marks each linked bill as payment-authorised. Batch size limit: 100 bills per batch.

13. **Supporting Document Request** — an approver can set decision to `request_info` with a free-text question. This creates a `Comment` on the `ApprovalRequest` tagged as an information request, notifies the requester, and moves the request back to `draft` status. The requester can attach additional documents and resubmit.

14. **Seed Data** — demo records for all new schemas (ADR-016): 2 ApprovalWorkflows, 4 ApprovalSteps, 3 ApprovalRequests (one per requestType), and 2 ApprovalDecisions. Loaded via the repair step idempotently.

### Out of Scope

- Full UBL/Peppol electronic procurement message exchange for approval events — deferred to procurement change
- AI-assisted routing suggestions based on historical approval patterns — deferred
- Mobile push notifications for approval requests — Nextcloud mobile handles this via its own push layer
- Integration with external BPM engines (Camunda, Flowable) — no external package dependency allowed
- Supplier self-service approval portal — handled by the supplier-management change
- Multi-currency threshold evaluation — amounts compared in the base currency only (EUR); FX conversion deferred

## Acceptance Criteria

1. GIVEN an active `ApprovalWorkflow` of type `PurchaseOrder` exists WHEN a new PO is submitted THEN `WorkflowRoutingService` creates an `ApprovalRequest` in `pending` status, resolves the first approver step, and sends a Nextcloud notification to the assigned approver
2. GIVEN an `ApprovalRequest` is in `approved` status WHEN the linked PO is revised (amount or supplier changed) THEN the existing request is cancelled, a revision diff is stored, and a new `ApprovalRequest` is created from step 1 with `requestType: PurchaseOrder`
3. GIVEN an expenditure request is submitted with cost centre `CC-100` WHEN `WorkflowRoutingService` resolves the approver THEN the approver is set to the userId recorded as `costCentreOwnerId` on the Organisation whose `costCentreCode` matches `CC-100`
4. GIVEN an approval step has `escalationAfterHours: 24` and no decision has been recorded WHEN the `ApprovalEscalationJob` runs and 24 hours have passed THEN the request status changes to `escalated`, it is assigned to the senior approver, and both the original and senior approvers receive notifications
5. GIVEN an approver sets decision to `request_info` with a question WHEN the requester receives the notification and uploads a document and resubmits THEN the `ApprovalRequest` returns to `in_review` at the same step and the approver is re-notified
6. GIVEN a `WorkflowDesigner` canvas has two steps configured WHEN the configuration is saved THEN the `ApprovalWorkflow.workflowConfig` JSON contains both steps in order with their conditions and escalation timers correctly serialised

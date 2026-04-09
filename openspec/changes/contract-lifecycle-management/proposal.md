---
status: proposed
source: specter
features: [ai-obligation-task-management-with-automated-deadline-tracking, contract-repository-with-full-text-search, ai-powered-automated-contract-redlining-and-task-management, contract-lifecycle-management-from-award-through-execution-to-expiration, route-contract-for-approval]
---

# Contract Lifecycle Management — Shillinq

## Summary

Implements a complete contract lifecycle management module for Shillinq: a central contract repository with full-text search and expiration alerting, AI-assisted obligation extraction and automated deadline tracking, contract lifecycle orchestration from draft through award, execution, renewal, and expiration, configurable approval routing integration with the existing ApprovalWorkflow engine, contract hierarchy management linking master agreements to sub-contracts, and contract performance KPI monitoring with obligation deliverable tracking. These capabilities address the five highest-demand contract-lifecycle features identified in the Specter intelligence model and integrate with the approval-workflow-management, document-management, supplier-management, and scheduling infrastructure already in place.

## Demand Evidence

Top features by market demand score:

- **AI obligation task management with automated deadline tracking** (demand: 2484) — the highest-ranked CLM feature. Contract managers need AI-extracted obligation tasks with automated due-date tracking, priority escalation, and status reporting so that no contractual commitment is missed. Finance and legal teams need a single view of all upcoming deliverables across the contract portfolio.
- **Contract Repository with Full-Text Search** (demand: 2447) — organisations need a single authoritative repository where all contracts are searchable by title, counterparty, clause text, obligation, and metadata. The inability to search across contracts stored on shared drives is a top pain point for contract managers and internal auditors.
- **AI-powered automated contract redlining and task management** (demand: 2138) — legal and procurement teams need AI assistance to identify non-standard clauses, flag deviations from approved templates, and auto-generate obligation tasks from contract text so that manual review effort is reduced and risk is surfaced earlier.
- **Contract lifecycle management from award through execution to expiration** (demand: 2001) — organisations need the full lifecycle managed in one system: contract award linked to procurement records, execution milestones tracked against KPIs, and expiration alerts triggering renewal or termination workflows automatically.
- **Route contract for approval** (demand: 1935) — contract managers need to route draft contracts through a configurable multi-step approval chain before activation. This is the most commonly requested feature across competitor analysis and user stories, and must integrate with the existing `ApprovalWorkflow` engine.

Key stakeholder pain points addressed:

- **Contract Manager**: contracts scattered across shared drives with no automated renewal alerts and no standard clause enforcement — addressed by the central repository, AI redlining, template enforcement, and automated obligation task extraction with deadline tracking.
- **Internal Auditor**: incomplete audit trails for contract approvals, no centralised evidence repository, time-consuming manual sampling — addressed by the full lifecycle audit trail, tamper-evident decision history, and exportable evidence packages integrated with `AuditTrail`.
- **Data Protection Officer**: no automated flag for data-processing contracts, difficulty tracking verwerkersovereenkomst status, manual retention period tracking — addressed by automatic privacy-impact tagging, integrated verwerkersovereenkomst template support, and AVG retention period enforcement on contracts.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, supplier-management, catalog-purchase-management, accounts-payable-receivable, document-management, scheduling, approval-workflow-management, and authorization-mandate-management changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `AccessControl`, `Comment`, `Document`, `DocumentVersion`, `SupplierProfile`, `Budget`, `Account`, `AuditTrail`, `ApprovalWorkflow`, `ApprovalRequest`, `ApprovalDecision`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- Document attachment panel and version history
- Multi-step approval routing engine with escalation background job
- Scheduling and milestone infrastructure

### What Is Missing

- No `Contract` schema (`schema:Agreement`) for storing contract lifecycle data from draft through expiration
- No `ContractObligation` schema (`schema:Thing`) for obligation tracking with AI-generated task support and automated deadline alerting
- No contract repository with full-text search across contract document content and metadata
- No AI obligation extraction service that reads contract text and auto-generates `ContractObligation` tasks
- No AI redlining service that flags clause deviations from approved templates
- No contract renewal background job that proactively notifies owners before `renewalDate`
- No contract hierarchy linking master agreements to sub-contracts and releases
- No contract performance KPI dashboard
- No AVG/privacy-impact tagging and verwerkersovereenkomst tracking on contracts
- No integration between `Contract` entities and the existing `ApprovalWorkflow` engine for routing

## Scope

### In Scope

1. **Contract Schema** — OpenRegister `Contract` schema (`schema:Agreement`) covering the full lifecycle: `contractNumber`, `title`, `description`, `status` (draft / review / approved / signed / executed / expired / renewed), `contractType` (standard / framework / scheduling / master), `startDate`, `endDate`, `expiryDate`, `renewalDate`, `contractValue`, `currency`, `signingDate`, `parentContractId` for hierarchy, `supplierId`, `ownerUserId`, `departmentId`, `approvalWorkflowId`, `privacyImpact` flag, `verwerkersovereenkomstStatus`, `procurementRef`, `templateId`, and `aiRedlineStatus`. Views at `src/views/contract/`; store at `src/store/modules/contract.js`.

2. **ContractObligation Schema** — OpenRegister `ContractObligation` schema (`schema:Thing`) for obligations and AI-generated deliverable tasks: `obligationId`, `contractId`, `title`, `description`, `obligationType` (delivery / payment / service / reporting / compliance), `status` (pending / inProgress / completed / overdue / blocked), `dueDate`, `completionDate`, `priority` (low / medium / high / critical), `aiGenerated`, `automatedDeadlineTracking`, `assignedUserId`, `milestoneId`. Views at `src/views/contractObligation/`; store at `src/store/modules/contractObligation.js`.

3. **Contract Repository with Full-Text Search** — Nextcloud global search provider (`lib/Search/ContractSearchProvider.php`) indexes contract titles, descriptions, counterparty names, and obligation titles. A dedicated search bar in `ContractIndex.vue` supports free-text query against OpenRegister's built-in full-text search capability. Search results link directly to `ContractDetail.vue`.

4. **AI Obligation Extraction Service** — `lib/Service/ContractAiService.php` accepts a contract document (PDF or plain text) extracted from the attached document, sends it to the configured AI profile (`AIProfile` from core), and returns a structured list of obligation objects. These are persisted as `ContractObligation` records with `aiGenerated: true`. A "Verplichtingen extraheren" button in `ContractDetail.vue` triggers extraction. The AI profile endpoint and model are configurable via `AppSettings`.

5. **AI Redlining Service** — `lib/Service/ContractRedlineService.php` compares the contract document text against the approved template for the contract's `contractType`, identifies clause deviations, and returns a list of redline annotations. Annotations are stored as `Comment` objects tagged `redline` on the `Contract` entity. A `RedlineAnnotationPanel.vue` component in `ContractDetail.vue` shows flagged clauses with severity (informational / warning / critical) and the accepted template text side-by-side.

6. **Contract Approval Routing** — `lib/Service/ContractApprovalService.php` integrates with `WorkflowRoutingService` from approval-workflow-management to route a `Contract` entity through the active `ApprovalWorkflow` of `workflowType: Contract`. Routing is triggered by a "Ter goedkeuring indienen" button in `ContractDetail.vue`. The `Contract.status` advances from `draft` to `review` on submission, to `approved` on final approval, and to `signed` when the signed document is uploaded.

7. **Contract Renewal Background Job** — `lib/BackgroundJob/ContractRenewalJob.php` runs daily via `ITimedJobList`. It queries all `Contract` objects with `status: executed` or `status: signed` where `renewalDate` is within the configured lead time (default 90 days, configurable in `AppSettings`). For each matching contract, it sends a Nextcloud notification to `ownerUserId` and the department head, creates a `ContractObligation` of `obligationType: compliance` with the title "Vernieuwing beoordelen" and `dueDate` set to `renewalDate`, and records the alert in `AuditTrail`.

8. **Obligation Deadline Tracking Job** — `lib/BackgroundJob/ObligationDeadlineJob.php` runs daily. It queries all `ContractObligation` objects with `automatedDeadlineTracking: true` and `status` not in (completed / blocked). For obligations where `dueDate` is in the past, it sets `status: overdue` and notifies `assignedUserId`. For obligations due within 7 days, it sends a reminder notification. Both events are logged in `AuditTrail`.

9. **Contract Hierarchy** — `Contract.parentContractId` stores an OpenRegister object ID reference to a parent `Contract`. `ContractDetail.vue` shows a "Contracthiërarchie" panel with breadcrumb navigation to the parent and a child contracts list. Sub-contracts and releases inherit the parent's `supplierId` by default but can be overridden.

10. **Contract Performance KPI Panel** — `ContractKpiPanel.vue` in `ContractDetail.vue` displays: total contract value, obligations completed vs. total, overdue obligations count, days until expiry, and renewal status. Data is computed from linked `ContractObligation` objects and contract metadata. No new schema is required; all values are derived from existing data.

11. **AVG Privacy Tagging** — when `Contract.privacyImpact: true` is set, a `PrivacyImpactBanner.vue` component renders in `ContractDetail.vue` with guidance for the Data Protection Officer. `verwerkersovereenkomstStatus` (not_required / required_pending / in_place) is a field on `Contract`. When `privacyImpact: true` and `verwerkersovereenkomstStatus: required_pending`, the system creates a `ContractObligation` of `obligationType: compliance` with title "Verwerkersovereenkomst afsluiten" on contract save.

12. **Procurement Compliance Warning** — when a `Contract` with `contractValue` exceeding the Dutch mandatory tender threshold (EUR 215.000 for services, configurable in `AppSettings`) is saved as `draft`, and `procurementRef` is empty, a warning banner is shown in `ContractDetail.vue`: "Het contractbedrag overschrijdt de aanbestedingsdrempel. Voeg een aanbestedingsreferentie toe vóór activering." Activation to `status: approved` is blocked until `procurementRef` is filled.

13. **Seed Data** — demo records for all new schemas (ADR-016): 5 Contract objects and 5 ContractObligation objects with Dutch-language values. Loaded via the repair step idempotently.

### Out of Scope

- UBL/Peppol electronic contract exchange — deferred to a procurement integration change
- Electronic signature via PKIoverheid within the contract detail view — this is handled by the approval-workflow-management `ContractSigningService`; this change only routes the contract to the signing step
- AI large-language-model hosting — the AI service calls an external `AIProfile` endpoint configured in AppSettings; no self-hosted model is bundled
- Automated contract drafting from scratch — AI assistance is limited to obligation extraction and redlining; full drafting is deferred
- Multi-currency contract value comparison — amounts compared in base currency (EUR); FX conversion deferred
- Supplier portal access to contract status — handled by supplier-management change
- CMIS/SharePoint document repository sync — no external package dependency allowed

## Acceptance Criteria

1. GIVEN a contract manager submits the new contract form with all required fields WHEN the form is saved THEN a `Contract` record is created with `status: draft` and a unique `contractNumber` is assigned
2. GIVEN a contract's value exceeds EUR 215.000 and `procurementRef` is empty WHEN the user attempts to advance status to `approved` THEN a 422 error "Aanbestedingsreferentie verplicht boven drempel" is returned and the status change is blocked
3. GIVEN a contract document is uploaded WHEN the user clicks "Verplichtingen extraheren" THEN `ContractAiService` returns at least one `ContractObligation` with `aiGenerated: true` and the obligations appear in the Obligations tab
4. GIVEN an active `ApprovalWorkflow` of type `Contract` exists WHEN the user submits a contract for approval THEN `WorkflowRoutingService` creates an `ApprovalRequest` in `pending` status and the contract `status` changes to `review`
5. GIVEN a contract has `renewalDate` within 90 days WHEN `ContractRenewalJob` runs THEN the contract owner receives a Nextcloud notification and a renewal obligation task is created
6. GIVEN a `ContractObligation` with `automatedDeadlineTracking: true` has a `dueDate` in the past WHEN `ObligationDeadlineJob` runs THEN the obligation `status` changes to `overdue` and the assigned user is notified

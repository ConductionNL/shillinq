# Tasks: contract-lifecycle-management

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `contract` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `contract` MUST be registered with `x-schema-org: schema:Agreement`
    - AND `contractNumber`, `title`, `status`, `startDate`, `ownerUserId`, `createdDate` MUST be marked required
    - AND `status` MUST have enum `["draft","review","approved","signed","executed","expired","renewed"]` with default `"draft"`
    - AND `contractType` MUST have enum `["standard","framework","scheduling","master"]` with default `"standard"`
    - AND `verwerkersovereenkomstStatus` MUST have enum `["not_required","required_pending","in_place"]` with default `"not_required"`
    - AND `aiRedlineStatus` MUST have enum `["not_run","running","completed","failed"]` with default `"not_run"`
    - AND `privacyImpact` MUST be type boolean with default `false`
    - AND `contractValue` MUST be type number
    - AND `currency` MUST be type string with default `"EUR"`

- [ ] 1.2 Add `contractObligation` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `contractObligation` MUST be registered with `x-schema-org: schema:Thing`
    - AND `obligationId`, `contractId`, `title`, `obligationType`, `status`, `dueDate`, `aiGenerated` MUST be marked required
    - AND `obligationType` MUST have enum `["delivery","payment","service","reporting","compliance"]` with default `"delivery"`
    - AND `status` MUST have enum `["pending","inProgress","completed","overdue","blocked"]` with default `"pending"`
    - AND `priority` MUST have enum `["low","medium","high","critical"]` with default `"medium"`
    - AND `aiGenerated` MUST be type boolean with default `false`
    - AND `automatedDeadlineTracking` MUST be type boolean with default `true`

## 2. Seed Data

- [ ] 2.1 Add Contract seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-011`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 5 Contract objects MUST be created as defined in the design seed data with correct Dutch-language values
    - AND idempotency check MUST use `Contract.contractNumber` as the unique key
    - AND no duplicate records are created when the repair step runs multiple times

- [ ] 2.2 Add ContractObligation seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-011`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 5 ContractObligation objects MUST be created as defined in the design seed data
    - AND idempotency check MUST use `ContractObligation.obligationId` as the unique key
    - AND the `contractId` field on each obligation MUST reference the correct seed contract by `contractNumber`

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/ContractApprovalService.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-006`
  - **files**: `lib/Service/ContractApprovalService.php`
  - **acceptance_criteria**:
    - GIVEN a `Contract` in `draft` status is passed
    - THEN the service validates required fields are present; returns 422 with field name if a required field is missing
    - AND calls `WorkflowRoutingService::route()` with `requestType: Contract` and the contract entity
    - AND sets `Contract.status: review` and `Contract.approvalRequestId` to the created request ID
    - AND on `ApprovalRequest.status: approved` event: advances `Contract.status` to `approved` and notifies `ownerUserId`
    - AND on `ApprovalRequest.status: rejected` event: reverts `Contract.status` to `draft`, notifies `ownerUserId` with rejection justification, and appends a `Comment` tagged `rejection` on the contract
    - AND if no active `ApprovalWorkflow` of `workflowType: Contract` exists: returns 422 "Geen actieve goedkeuringsworkflow gevonden voor contracten."

- [ ] 3.2 Create `lib/Service/ContractAiService.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-003`
  - **files**: `lib/Service/ContractAiService.php`
  - **acceptance_criteria**:
    - GIVEN a contract ID is passed
    - THEN the service loads the latest attached document for the contract and extracts its plain text
    - AND sets `Contract.aiRedlineStatus: running` before calling the AI endpoint
    - AND sends the text to the AIProfile endpoint configured in AppSettings via `IClientService`
    - AND parses the AI response JSON into `ContractObligation` creation payloads and persists each with `aiGenerated: true`, `automatedDeadlineTracking: true`, and `contractId` set
    - AND sets `Contract.aiRedlineStatus: completed` on success or `failed` on error
    - AND if AppSettings key `ai.profileEndpoint` is not set: throws `AiProfileNotConfiguredException` returning 503 "AI-profiel is niet geconfigureerd."

- [ ] 3.3 Create `lib/Service/ContractRedlineService.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-004`
  - **files**: `lib/Service/ContractRedlineService.php`
  - **acceptance_criteria**:
    - GIVEN a contract ID is passed
    - THEN the service loads the approved template document for `Contract.contractType` from OpenRegister
    - AND if no template is found: returns 422 "Geen goedgekeurd sjabloon gevonden voor contracttype {contractType}"
    - AND calls `AIProfile` endpoint via `IClientService` with both contract text and template text
    - AND maps each response deviation to a `Comment` on the contract entity with tags: `redline`, severity, `clauseReference`, and `templateText`
    - AND stores all comments via OpenRegister

- [ ] 3.4 Create `lib/Search/ContractSearchProvider.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-002`
  - **files**: `lib/Search/ContractSearchProvider.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the provider is registered via `IProvider` in `Application.php`
    - WHEN a global search query is executed THEN the provider queries OpenRegister for Contract and ContractObligation objects matching the term across `title`, `description`, `contractNumber` fields
    - AND results are filtered to objects the calling user can access via `AccessControl`
    - AND up to 20 results are returned, each with type icon, title, subtitle (parent contract title for obligations), status badge, and a direct URL to `ContractDetail.vue` or `ContractObligationDetail.vue`

- [ ] 3.5 Implement procurement threshold check in `lib/Service/ContractApprovalService.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-010`
  - **files**: `lib/Service/ContractApprovalService.php`
  - **acceptance_criteria**:
    - GIVEN `submit-for-approval` is called and `Contract.contractValue` >= AppSettings `procurement.tenderThreshold` (default 215000)
    - WHEN `Contract.procurementRef` is empty THEN the service returns 422 "Aanbestedingsreferentie is verplicht voor contracten boven de aanbestedingsdrempel (EUR {threshold})."
    - AND when `procurementRef` is present the check passes and routing proceeds normally

- [ ] 3.6 Implement AVG privacy obligation creation in `lib/Service/ContractPrivacyService.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-009`
  - **files**: `lib/Service/ContractPrivacyService.php`
  - **acceptance_criteria**:
    - GIVEN `Contract.privacyImpact: true` and `Contract.verwerkersovereenkomstStatus: required_pending` are set on save
    - THEN `ContractPrivacyService` creates a `ContractObligation` with `obligationType: compliance`, `title: "Verwerkersovereenkomst afsluiten"`, `priority: critical`, `dueDate: Contract.startDate`
    - AND writes an `AuditTrail` record with `action: privacy_impact_enabled`, `actor: {userId}`, `targetId`, `timestamp`
    - AND if AppSettings key `dpo.notifyUserId` is set: sends a Nextcloud notification to that userId
    - AND when `verwerkersovereenkomstStatus` is updated to `in_place`: sets the linked compliance obligation to `status: completed` with `completionDate: now`

## 4. Background Jobs

- [ ] 4.1 Create `lib/BackgroundJob/ContractRenewalJob.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-007`
  - **files**: `lib/BackgroundJob/ContractRenewalJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `Contract` objects with `status` in (executed, signed) and non-null `renewalDate`
    - AND for each contract where `renewalDate` <= today + `renewalLeadDays` (AppSettings, default 90): sends Nextcloud notification to `ownerUserId` and the department head
    - AND creates a `ContractObligation` with `obligationType: compliance`, `title: "Vernieuwing beoordelen"`, `dueDate: renewalDate`, `contractId` linked to the contract
    - AND writes an `AuditTrail` record with `actor: system`, `action: renewal_alert`
    - AND idempotency is checked via existing `AuditTrail` entry with key `(contractId, 'renewal_notified_' + renewalDate.toDateString())`; if found the contract is skipped
    - AND contracts with `status: expired` are skipped regardless of `renewalDate`

- [ ] 4.2 Create `lib/BackgroundJob/ObligationDeadlineJob.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `lib/BackgroundJob/ObligationDeadlineJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `ContractObligation` objects with `automatedDeadlineTracking: true` and `status` not in (completed, blocked)
    - AND for obligations where `dueDate` < today and `status != overdue`: sets `status: overdue`, notifies `assignedUserId` "Verplichting '{title}' is vervallen.", writes `AuditTrail` record with `actor: system`, `action: obligation_overdue`
    - AND for obligations where `dueDate` is between today and today+7 days: sends reminder notification "Verplichting '{title}' vervalt over {n} dagen." without changing status
    - AND obligations already in `overdue` status are skipped (idempotent)

## 5. Backend Controllers

- [ ] 5.1 Create `lib/Controller/ContractController.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-001, #REQ-CLM-006`
  - **files**: `lib/Controller/ContractController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/contracts` is called with required fields THEN a contract is created with `status: draft` and auto-generated `contractNumber`; 422 if required fields are missing
    - GIVEN `POST /api/v1/contracts/{id}/submit-for-approval` is called THEN `ContractApprovalService::submit()` is called; 422 if no active workflow or procurement threshold violation
    - GIVEN `POST /api/v1/contracts/{id}/upload-signed` is called with a PDF file THEN the file is stored via document attachment, `Contract.status` advances to `signed`, and `Contract.signingDate` is set
    - GIVEN `PATCH /api/v1/contracts/{id}` is called with an invalid `status` transition THEN 422 "Ongeldige statusovergang van {current} naar {new}" is returned
    - GIVEN `GET /api/v1/contracts` is called THEN all contracts accessible to the user are returned; supports `search`, `status`, `contractType`, `ownerUserId` query params

- [ ] 5.2 Create `lib/Controller/ContractObligationController.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `lib/Controller/ContractObligationController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/contract-obligations?contractId={id}` is called THEN all obligations for the specified contract are returned
    - GIVEN `PATCH /api/v1/contract-obligations/{id}` is called with `status: completed` THEN `completionDate` is set to now and an `AuditTrail` record is written
    - GIVEN `DELETE /api/v1/contract-obligations/{id}` is called for an obligation with `aiGenerated: true` by a non-manager user THEN 403 is returned

- [ ] 5.3 Create `lib/Controller/ContractAiController.php`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-003, #REQ-CLM-004`
  - **files**: `lib/Controller/ContractAiController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/contracts/{id}/extract-obligations` is called THEN `ContractAiService::extractObligations()` is called; 503 if AI profile not configured
    - GIVEN `POST /api/v1/contracts/{id}/run-redline` is called THEN `ContractRedlineService::runRedline()` is called; 422 if no template found for contractType
    - Both endpoints return 202 Accepted immediately and process asynchronously if the AI call is long-running; `Contract.aiRedlineStatus` reflects the current processing state

## 6. Pinia Stores

- [ ] 6.1 Create `src/store/modules/contract.js`
  - **files**: `src/store/modules/contract.js`
  - **acceptance_criteria**:
    - THEN `useContractStore` MUST be created via `createObjectStore('contract')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.2 Create `src/store/modules/contractObligation.js`
  - **files**: `src/store/modules/contractObligation.js`
  - **acceptance_criteria**:
    - THEN `useContractObligationStore` MUST be created via `createObjectStore('contractObligation')`
    - AND the store MUST be registered in `src/store/store.js`

## 7. Frontend Views — Contract

- [ ] 7.1 Create `src/views/contract/ContractIndex.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-001, #REQ-CLM-002`
  - **files**: `src/views/contract/ContractIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('contract')`
    - AND filter chips for `status`, `contractType`, `ownerUserId`, `privacyImpact` MUST be present via `filtersFromSchema()`
    - AND a search bar dispatches a filtered OpenRegister query on input
    - AND a "Nieuw contract" button opens `ContractForm.vue`
    - AND contracts approaching `expiryDate` (within 30 days) display an amber warning icon in the expiry date column

- [ ] 7.2 Create `src/views/contract/ContractDetail.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-001, #REQ-CLM-003, #REQ-CLM-004, #REQ-CLM-006, #REQ-CLM-007, #REQ-CLM-008, #REQ-CLM-009, #REQ-CLM-010`
  - **files**: `src/views/contract/ContractDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Overzicht, Verplichtingen, Documenten, Prestaties, Hiërarchie
    - AND the Overzicht tab shows contract metadata, status timeline, `PrivacyImpactBanner.vue` when `privacyImpact: true`, procurement warning banner when threshold exceeded and `procurementRef` empty, and renewal banner when `renewalDate` is approaching
    - AND the Overzicht tab shows a "Ter goedkeuring indienen" button for contracts in `draft` status and an embedded `ApprovalTimeline.vue` for contracts in `review` status
    - AND the Verplichtingen tab lists `ContractObligation` objects via `CnIndexPage`, a "Verplichtingen extraheren" button, and an `ObligationStatusBadge.vue` per row
    - AND the Prestaties tab renders `ContractKpiPanel.vue`
    - AND the Hiërarchie tab renders `ContractHierarchyPanel.vue`
    - AND the Documenten tab embeds the document attachment panel scoped to the contract entity
    - AND the `RedlineAnnotationPanel.vue` is shown in the Overzicht tab when `aiRedlineStatus: completed` and redline comments exist

- [ ] 7.3 Create `src/views/contract/ContractForm.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-001`
  - **files**: `src/views/contract/ContractForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with `fieldsFromSchema('contract')`
    - AND `supplierId` renders as a searchable supplier lookup (by name or KVK number) using the existing supplier store
    - AND `ownerUserId` renders as a Nextcloud user picker
    - AND `departmentId` renders as an organisation picker
    - AND `privacyImpact` toggle triggers a confirmation dialog "Dit contract verwerkt persoonsgegevens. Zorg voor een verwerkersovereenkomst." before being enabled

- [ ] 7.4 Create `src/views/contractObligation/ContractObligationIndex.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `src/views/contractObligation/ContractObligationIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('contractObligation')`
    - AND filter chips for `status`, `obligationType`, `priority`, `aiGenerated` MUST be present
    - AND overdue obligations show an `ObligationStatusBadge.vue` with a red background and clock icon

- [ ] 7.5 Create `src/views/contractObligation/ContractObligationDetail.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `src/views/contractObligation/ContractObligationDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Details, Geschiedenis
    - AND the Details tab shows all obligation fields, an `ObligationStatusBadge.vue`, and a "Markeer als voltooid" button for obligations not in (completed, blocked)
    - AND the "Gegenereerd door AI" badge is shown when `aiGenerated: true`
    - AND the Geschiedenis tab shows the `AuditTrail` records for this obligation

- [ ] 7.6 Create `src/views/contractObligation/ContractObligationForm.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `src/views/contractObligation/ContractObligationForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with `fieldsFromSchema('contractObligation')`
    - AND `contractId` is pre-filled when opened from `ContractDetail.vue` and rendered as read-only
    - AND `assignedUserId` renders as a Nextcloud user picker

## 8. Frontend Components

- [ ] 8.1 Create `src/components/ContractKpiPanel.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-001`
  - **files**: `src/components/ContractKpiPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `contract` prop is passed THEN the panel renders: total contract value (formatted as EUR), obligations completed vs. total, overdue obligations count (red badge if > 0), days until `expiryDate` (amber if < 30), and `verwerkersovereenkomstStatus` badge
    - AND all values are computed from the `contractStore` and `contractObligationStore` without a dedicated API endpoint

- [ ] 8.2 Create `src/components/ContractHierarchyPanel.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-008`
  - **files**: `src/components/ContractHierarchyPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `contract` prop is passed and `parentContractId` is set THEN the panel renders a breadcrumb with a link to the parent contract
    - AND child contracts (where `parentContractId` equals this contract's ID) are listed with `contractNumber`, `title`, `status`, and `contractValue`
    - AND clicking a child contract navigates to its `ContractDetail.vue` page

- [ ] 8.3 Create `src/components/RedlineAnnotationPanel.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-004`
  - **files**: `src/components/RedlineAnnotationPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `contractId` prop is passed THEN the panel fetches `Comment` objects tagged `redline` for the contract
    - AND critical annotations are shown first with a red badge, warnings with amber, informational with grey
    - AND each annotation shows the contract clause text and accepted template text in a two-column layout
    - AND a "Markeer als opgelost" button on each annotation sets the comment to resolved status

- [ ] 8.4 Create `src/components/PrivacyImpactBanner.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-009`
  - **files**: `src/components/PrivacyImpactBanner.vue`
  - **acceptance_criteria**:
    - GIVEN `privacyImpact: true` is passed as a prop THEN a yellow banner renders with text "Dit contract verwerkt persoonsgegevens. Zorg dat een verwerkersovereenkomst aanwezig is." and a link to the AVG compliance checklist
    - AND `verwerkersovereenkomstStatus` is shown as a badge: "Niet vereist" (grey), "Vereist — in behandeling" (amber), "Aanwezig" (green)

- [ ] 8.5 Create `src/components/ObligationStatusBadge.vue`
  - **spec_ref**: `specs/contract-lifecycle-management/spec.md#REQ-CLM-005`
  - **files**: `src/components/ObligationStatusBadge.vue`
  - **acceptance_criteria**:
    - GIVEN a `status` prop is passed THEN the badge renders: pending=grey, inProgress=blue, completed=green, overdue=red with clock icon, blocked=dark grey
    - AND when `aiGenerated: true` is also passed a small robot icon is appended to the badge

## 9. Sidebar Navigation Update

- [ ] 9.1 Add contract management sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Contracten" section MUST be present with nav items: Alle contracten, Mijn contracten, Verplichtingen
    - AND the "Verplichtingen" item MUST show a badge with the count of `ContractObligation` objects where `assignedUserId` matches the authenticated userId and `status` is `overdue` or `pending`
    - AND a "Settings" sub-item for Contract Configuration (renewal lead time, tender threshold, AI profile) MUST be present under the Settings section

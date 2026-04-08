# Tasks: document-management

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `document` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `document` MUST be registered with all properties from the data model
    - AND `title`, `documentType`, `status`, `confidentialityLevel`, `uploadedBy`, `createdDate` MUST be marked required
    - AND `status` MUST have enum `["draft","under_review","approved","rejected","archived"]`
    - AND `confidentialityLevel` MUST have enum `["public","internal","confidential","restricted"]`
    - AND `x-schema-org` annotation MUST be `schema:DigitalDocument`

- [ ] 1.2 Add `documentVersion` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `documentVersion` MUST exist with properties: `documentId` (required), `versionNumber` (required), `filePath` (required), `fileName` (required), `mimeType` (required), `uploadedBy` (required), `uploadedAt` (required)
    - AND `fileSizeBytes` MUST be of type integer
    - AND `uploadedAt` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Thing`

- [ ] 1.3 Add `supplierQuestionnaire` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `supplierQuestionnaire` MUST exist with `title` (required), `category` (required), `isActive` (required), `questions` (required, array), `createdBy` (required), `createdDate` (required)
    - AND `category` MUST have enum `["qualification","onboarding","renewal","audit"]`
    - AND `isActive` MUST default to `true`
    - AND `x-schema-org` MUST be `schema:Survey`

- [ ] 1.4 Add `questionnaireResponse` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `questionnaireResponse` MUST exist with `questionnaireId` (required), `supplierProfileId` (required), `status` (required), `answers` (required, array)
    - AND `status` MUST have enum `["draft","submitted","under_review","approved","rejected","overdue"]`
    - AND `submittedAt` MUST have `format: date-time`

- [ ] 1.5 Add `supplierPortalProfile` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `supplierPortalProfile` MUST exist with `organizationName` (required), `contactName` (required), `contactEmail` (required), `status` (required)
    - AND `status` MUST have enum `["invited","profile_complete","qualification_submitted","qualified","rejected"]`
    - AND `portalTokenHash` MUST NOT be included in default API responses (marked `x-hidden: true` or equivalent)
    - AND `country` MUST default to `"NL"`
    - AND `x-schema-org` MUST be `schema:Organization`

- [ ] 1.6 Add `contractTemplate` schema to `lib/Settings/shillinq_register.json` (replace any existing stub)
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-006`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `contractTemplate` MUST exist with all properties from the data model including `clauses` (array) and `defaultApprovalChainId`
    - AND `templateName`, `category`, `isActive`, `createdDate` MUST be required
    - AND `category` MUST have enum `["service","purchase","employment","NDA","license","framework","other"]`
    - AND `isActive` MUST default to `true`
    - AND `version` MUST default to `"1.0"`
    - AND `x-schema-org` MUST be `schema:CreativeWork`

- [ ] 1.7 Add `contractClause` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-006`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `contractClause` MUST exist with `clauseTitle` (required), `clauseType` (required), `content` (required), `currentVersion` (required), `status` (required), `createdDate` (required)
    - AND `clauseType` MUST have enum `["payment","liability","confidentiality","termination","warranty","general"]`
    - AND `status` MUST have enum `["draft","pending_approval","approved","deprecated"]`
    - AND `clauseHistory` MUST be type array with default `[]`
    - AND `currentVersion` MUST default to `"1.0"`
    - AND `x-schema-org` MUST be `schema:CreativeWork`

- [ ] 1.8 Add `documentRoutingRule` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-008`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `documentRoutingRule` MUST exist with `ruleName` (required), `isActive` (required), `triggerEntityType` (required), `actionType` (required)
    - AND `actionType` MUST have enum `["assign_workflow","notify_group","set_status"]`
    - AND `isActive` MUST default to `true`
    - AND `priority` MUST be type integer with default `100`
    - AND `x-schema-org` MUST be `schema:Action`

## 2. Seed Data

- [ ] 2.1 Add Document and DocumentVersion seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-010`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 2 Document objects MUST be created: "Bank Agreement 2026" (approved, confidential, contract type) and "ISO Certificate Acme BV" (approved, internal, certificate type)
    - AND 1 DocumentVersion object MUST be created for each Document with `versionNumber: "1.0"`
    - AND idempotency check MUST use `Document.title` as the unique key

- [ ] 2.2 Add SupplierQuestionnaire seed object to repair step
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-010`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 SupplierQuestionnaire seed object MUST be created: "Standard Supplier Qualification" with exactly 3 questions as defined in REQ-DOC-010 scenario 4
    - AND idempotency check MUST use `SupplierQuestionnaire.title`

- [ ] 2.3 Add SupplierPortalProfile seed object to repair step
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-010`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 SupplierPortalProfile seed object MUST be created: "Acme BV Supplier Portal" with `status: qualification_submitted`
    - AND `portalTokenHash` MUST be set to a static test hash and NOT a real active token
    - AND idempotency check MUST use `SupplierPortalProfile.organizationName`

- [ ] 2.4 Add ContractTemplate and ContractClause seed objects to repair step
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-010`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 ContractClause seed objects MUST be created: "Payment Terms 30 Days", "Liability Cap 2× Contract Value", "Standard Confidentiality" — all at `status: approved`, `currentVersion: "1.0"`
    - AND 2 ContractTemplate seed objects MUST be created: "Standard Service Agreement" (clauses: all 3) and "Non-Disclosure Agreement" (clauses: "Standard Confidentiality" only)
    - AND idempotency checks MUST use `clauseTitle` and `templateName` respectively

- [ ] 2.5 Add DocumentRoutingRule seed object to repair step
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-010`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 DocumentRoutingRule MUST be created with all fields exactly as specified in REQ-DOC-010 scenario 5
    - AND idempotency check MUST use `DocumentRoutingRule.ruleName`

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/DocumentVersionService.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-001`
  - **files**: `lib/Service/DocumentVersionService.php`
  - **acceptance_criteria**:
    - GIVEN an upload is received
    - THEN the service determines the next version number by fetching existing `DocumentVersion` objects for the document and incrementing the minor version
    - AND the file is stored via `IRootFolder` at `shillinq/documents/{documentId}/{versionNumber}/{fileName}`
    - AND a SHA-256 checksum is computed and stored
    - AND the parent `Document.currentVersionId` is updated via OpenRegister

- [ ] 3.2 Create `lib/Service/DocumentAccessService.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-009`
  - **files**: `lib/Service/DocumentAccessService.php`
  - **acceptance_criteria**:
    - GIVEN a user requests a Document
    - THEN `public` level allows any authenticated user; `internal` allows any Shillinq user; `confidential` requires `reviewer` or `approver` CollaborationRole; `restricted` requires explicit AccessControl grant
    - AND the service returns `false` (deny) for any unauthenticated request regardless of level

- [ ] 3.3 Create `lib/Service/DocumentRoutingService.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-008`
  - **files**: `lib/Service/DocumentRoutingService.php`
  - **acceptance_criteria**:
    - GIVEN a new Document is created
    - THEN all active rules are loaded ordered by `priority` ASC
    - AND the service evaluates each rule's `triggerEntityType`, `matchDocumentType`, and `matchConfidentialityLevel` against the new document
    - AND only the first matching rule fires
    - AND routing decisions are logged at INFO level

- [ ] 3.4 Create `lib/Service/SupplierPortalTokenService.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-005`
  - **files**: `lib/Service/SupplierPortalTokenService.php`
  - **acceptance_criteria**:
    - GIVEN an invitation is triggered
    - THEN 64 bytes are generated via `random_bytes`, SHA-256 hashed, and stored in `SupplierPortalProfile.portalTokenHash`
    - AND `portalTokenExpiresAt` is set to `now + 7 days`
    - AND the raw token is returned only once and NOT stored
    - AND validation hashes the incoming token and compares to stored hash, rejects if expired
    - AND `IThrottler` is used with key `supplier_portal_token` to enforce max 10 attempts per IP per minute

- [ ] 3.5 Create `lib/Service/ContractClauseVersionService.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-007`
  - **files**: `lib/Service/ContractClauseVersionService.php`
  - **acceptance_criteria**:
    - GIVEN a clause update proposal is submitted
    - THEN the service sets `ContractClause.status` to `pending_approval` without changing `content` or `currentVersion`
    - GIVEN an approver approves the update
    - THEN the old `{version, content, approvedBy, approvedAt}` is prepended to `clauseHistory`
    - AND `content` is replaced with the new text, `currentVersion` is incremented (minor if patch, major if breaking)
    - AND `status` returns to `approved`
    - GIVEN a diff request arrives for `from` and `to` versions
    - THEN the service finds both versions in `clauseHistory` or `currentVersion` and returns a line-by-line diff array using PHP's `array_diff` on `explode("\n", $content)` without external packages

## 4. Backend Controllers

- [ ] 4.1 Create `lib/Controller/DocumentController.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-001, #REQ-DOC-002, #REQ-DOC-003`
  - **files**: `lib/Controller/DocumentController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN a file upload request arrives at `POST /api/v1/documents/{id}/upload`
    - THEN `DocumentVersionService` is called to store the file and create the version record
    - AND `DocumentRoutingService` is called on Document create
    - AND workflow transition endpoints (`submit-for-review`, `approve`, `reject`) enforce role checks via `DocumentAccessService`
    - AND the download endpoint streams the file via Nextcloud's `IRootFolder` with correct Content-Type header

- [ ] 4.2 Create `lib/Controller/SupplierPortalController.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-005`
  - **files**: `lib/Controller/SupplierPortalController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN unauthenticated endpoints are called with a raw portal token
    - THEN `SupplierPortalTokenService::validate()` is called first and rejects expired or invalid tokens with 401
    - AND all unauthenticated endpoints are decorated with `@NoAdminRequired` and `@PublicPage` annotations
    - AND the questionnaire submit endpoint triggers notification to the procurement manager on success

- [ ] 4.3 Create `lib/Controller/ContractTemplateController.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-006`
  - **files**: `lib/Controller/ContractTemplateController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/contract-templates/{id}/instantiate` is called
    - THEN each clause ID in `ContractTemplate.clauses` is resolved to a `ContractClause` object
    - AND a snapshot object is built for each clause with `clauseId`, `clauseSnapshotVersion`, and `clauseContent`
    - AND the instantiate endpoint returns 422 if `ContractTemplate.isActive` is `false`

- [ ] 4.4 Create `lib/Controller/ContractClauseController.php`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-007`
  - **files**: `lib/Controller/ContractClauseController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/contract-clauses/{id}/propose-update` is called with new clause text
    - THEN `ContractClauseVersionService::proposeUpdate()` is called and the clause status changes to `pending_approval`
    - GIVEN `POST /api/v1/contract-clauses/{id}/approve-version` is called by an `approver`
    - THEN `ContractClauseVersionService::approveVersion()` is called and the new version is live
    - GIVEN `GET /api/v1/contract-clauses/{id}/diff?from=1.0&to=2.0` is called
    - THEN `ContractClauseVersionService::diff()` is called and a JSON diff array is returned

## 5. Pinia Stores

- [ ] 5.1 Create `src/store/modules/document.js`
  - **files**: `src/store/modules/document.js`
  - **acceptance_criteria**:
    - THEN `useDocumentStore` MUST be created via `createObjectStore('document')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 5.2 Create `src/store/modules/documentVersion.js`
  - **files**: `src/store/modules/documentVersion.js`
  - **acceptance_criteria**:
    - THEN `useDocumentVersionStore` MUST be created via `createObjectStore('documentVersion')`

- [ ] 5.3 Create `src/store/modules/supplierQuestionnaire.js`
  - **files**: `src/store/modules/supplierQuestionnaire.js`
  - **acceptance_criteria**:
    - THEN `useSupplierQuestionnaireStore` MUST be created via `createObjectStore('supplierQuestionnaire')`

- [ ] 5.4 Create `src/store/modules/questionnaireResponse.js`
  - **files**: `src/store/modules/questionnaireResponse.js`
  - **acceptance_criteria**:
    - THEN `useQuestionnaireResponseStore` MUST be created via `createObjectStore('questionnaireResponse')`

- [ ] 5.5 Create `src/store/modules/supplierPortalProfile.js`
  - **files**: `src/store/modules/supplierPortalProfile.js`
  - **acceptance_criteria**:
    - THEN `useSupplierPortalProfileStore` MUST be created via `createObjectStore('supplierPortalProfile')`

- [ ] 5.6 Create `src/store/modules/contractTemplate.js`
  - **files**: `src/store/modules/contractTemplate.js`
  - **acceptance_criteria**:
    - THEN `useContractTemplateStore` MUST be created via `createObjectStore('contractTemplate')`

- [ ] 5.7 Create `src/store/modules/contractClause.js`
  - **files**: `src/store/modules/contractClause.js`
  - **acceptance_criteria**:
    - THEN `useContractClauseStore` MUST be created via `createObjectStore('contractClause')`

- [ ] 5.8 Create `src/store/modules/documentRoutingRule.js`
  - **files**: `src/store/modules/documentRoutingRule.js`
  - **acceptance_criteria**:
    - THEN `useDocumentRoutingRuleStore` MUST be created via `createObjectStore('documentRoutingRule')`

## 6. Frontend Views — Document

- [ ] 6.1 Create `src/views/document/DocumentIndex.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-002`
  - **files**: `src/views/document/DocumentIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the document list page loads
    - THEN it MUST use `CnIndexPage` with `columnsFromSchema('document')`
    - AND a `confidentialityLevel` filter chip MUST be present using `filtersFromSchema()`
    - AND confidential documents MUST be excluded from the default view for `viewer` role users

- [ ] 6.2 Create `src/views/document/DocumentDetail.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-003`
  - **files**: `src/views/document/DocumentDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders
    - THEN it MUST use `CnDetailPage` with tabs: Details, Versions, Workflow
    - AND the Workflow tab MUST include `DocumentWorkflowPanel.vue`
    - AND the Versions tab MUST list all `DocumentVersion` objects for the document ordered by `versionNumber` descending

- [ ] 6.3 Create `src/components/DocumentAttachment.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-002`
  - **files**: `src/components/DocumentAttachment.vue`
  - **acceptance_criteria**:
    - GIVEN `targetType` and `targetId` props are passed
    - THEN the component fetches documents filtered by those values and renders a file list with status chips
    - AND an upload button opens a file picker restricted to allowed MIME types
    - AND unsupported MIME types show a client-side validation error before any network request

- [ ] 6.4 Create `src/components/DocumentWorkflowPanel.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-003`
  - **files**: `src/components/DocumentWorkflowPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a Document prop is passed
    - THEN the panel renders the current status as an NL Design System status badge
    - AND transition buttons (Submit for Review, Approve, Reject) are shown only for permitted roles
    - AND clicking Approve or Reject dispatches the corresponding OCS API call and refreshes the document state

## 7. Frontend Views — Supplier Portal

- [ ] 7.1 Create `src/views/supplierQuestionnaire/SupplierQuestionnaireIndex.vue` and `SupplierQuestionnaireDetail.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-004`
  - **files**: `src/views/supplierQuestionnaire/SupplierQuestionnaireIndex.vue`, `src/views/supplierQuestionnaire/SupplierQuestionnaireDetail.vue`, `src/views/supplierQuestionnaire/SupplierQuestionnaireForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page loads THEN it uses `CnIndexPage` with `columnsFromSchema('supplierQuestionnaire')`
    - GIVEN the detail page loads THEN it uses `CnDetailPage` with a "Questions" tab showing each question sub-object
    - AND a "Responses" tab shows all `QuestionnaireResponse` objects linked to this questionnaire

- [ ] 7.2 Create `src/views/supplierPortal/SupplierPortalPage.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-005`
  - **files**: `src/views/supplierPortal/SupplierPortalPage.vue`
  - **acceptance_criteria**:
    - GIVEN the portal page is accessed with a valid token in the URL
    - THEN the page renders outside the main Nextcloud shell (no sidebar, no top navigation)
    - AND it calls `GET /api/v1/supplier-portal/{token}/profile` to load profile data
    - AND it renders the self-service profile edit form and links to available questionnaires
    - GIVEN the token is expired THEN a "Link expired" message is shown and no data is fetched

- [ ] 7.3 Create `src/views/supplierPortal/SupplierPortalIndex.vue` and `SupplierPortalDetail.vue` (internal views)
  - **files**: `src/views/supplierPortal/SupplierPortalIndex.vue`, `src/views/supplierPortal/SupplierPortalDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the internal index page loads THEN it uses `CnIndexPage` with `columnsFromSchema('supplierPortalProfile')`
    - AND status filter chips for `invited`, `profile_complete`, `qualification_submitted`, `qualified`, `rejected` are present
    - GIVEN the detail page loads THEN it shows profile fields, questionnaire response history, and uploaded documents via `DocumentAttachment.vue`

## 8. Frontend Views — Contract Template and Clause

- [ ] 8.1 Create `src/views/contractTemplate/ContractTemplateIndex.vue`, `ContractTemplateDetail.vue`, `ContractTemplateForm.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-006`
  - **files**: `src/views/contractTemplate/ContractTemplateIndex.vue`, `src/views/contractTemplate/ContractTemplateDetail.vue`, `src/views/contractTemplate/ContractTemplateForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` and by default filters `isActive: true`
    - GIVEN the detail page renders THEN it uses `CnDetailPage` with a "Clauses" tab containing `ClauseSelector.vue`
    - AND an "Instantiate Contract" button is present, calling `POST /api/v1/contract-templates/{id}/instantiate`
    - AND the form uses `CnFormDialog` with `fieldsFromSchema('contractTemplate')`

- [ ] 8.2 Create `src/views/contractClause/ContractClauseIndex.vue`, `ContractClauseDetail.vue`, `ContractClauseForm.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-007`
  - **files**: `src/views/contractClause/ContractClauseIndex.vue`, `src/views/contractClause/ContractClauseDetail.vue`, `src/views/contractClause/ContractClauseForm.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it uses `CnDetailPage` with tabs: Details, Version History
    - AND the Version History tab lists all `clauseHistory` entries in reverse chronological order
    - AND "Propose Update" and "Approve Version" buttons appear based on clause `status` and user role
    - AND a "View Diff" button is present on each historical version, opening `ClauseDiffView.vue`

- [ ] 8.3 Create `src/components/ClauseSelector.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-006`
  - **files**: `src/components/ClauseSelector.vue`
  - **acceptance_criteria**:
    - GIVEN a `clauses` array prop is passed
    - THEN each clause is rendered as a chip showing `clauseTitle`, `currentVersion`, and status badge
    - AND clauses can be reordered using the browser's native drag-and-drop API
    - AND on reorder, an `update:clauses` event is emitted with the new ordered array

- [ ] 8.4 Create `src/views/contractClause/ClauseDiffView.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-007`
  - **files**: `src/views/contractClause/ClauseDiffView.vue`
  - **acceptance_criteria**:
    - GIVEN `clauseId`, `fromVersion`, and `toVersion` props are passed
    - THEN the component calls `GET /api/v1/contract-clauses/{id}/diff?from={from}&to={to}`
    - AND renders a line-by-line diff: additions in green with `+` prefix, deletions in red with `-` prefix

## 9. Frontend Views — Document Routing Rules

- [ ] 9.1 Create `src/views/documentRoutingRule/DocumentRoutingRuleIndex.vue` and `DocumentRoutingRuleForm.vue`
  - **spec_ref**: `specs/document-management/spec.md#REQ-DOC-008`
  - **files**: `src/views/documentRoutingRule/DocumentRoutingRuleIndex.vue`, `src/views/documentRoutingRule/DocumentRoutingRuleForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` showing `ruleName`, `triggerEntityType`, `actionType`, `priority`, and `isActive` columns
    - AND the form uses `CnFormDialog` with `fieldsFromSchema('documentRoutingRule')`
    - AND the form conditionally shows `workflowId`, `notifyGroupId`, or `targetStatus` based on the selected `actionType`

## 10. Sidebar Navigation Update

- [ ] 10.1 Add document management sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Documents" section MUST be present with nav items for: Documents, Supplier Questionnaires, Supplier Portal, Contract Templates, Contract Clauses
    - AND a "Settings" sub-item for Document Routing Rules MUST be present under the Settings section
    - AND each nav item MUST show a badge count fetched from OpenRegister object counts for the corresponding schema

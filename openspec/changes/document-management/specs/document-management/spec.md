---
status: proposed
---

# Document Management — Shillinq

## Purpose

Defines functional requirements for Shillinq's document management capabilities: a versioned document store with approval workflow, supplier qualification questionnaires, a supplier self-service portal, contract template and clause authoring, clause version management, document routing rules, and confidentiality-based access control. These requirements build on the core, access-control-authorisation, and collaboration changes.

Stakeholders: Municipal Treasurer.

User stories addressed: View consolidated daily cash position (auditable treasury document storage), Produce 13-week rolling cash flow forecast (version-controlled financing agreements), Register short-term cash investment (deposito confirmation documents).

## Requirements

### REQ-DOC-001: Document Schema Registration and Version Control [must]

The app MUST register the `Document` and `DocumentVersion` schemas in OpenRegister via `lib/Settings/shillinq_register.json` using `schema:DigitalDocument` and `schema:Thing` respectively. `DocumentVersion` records MUST be append-only; deletion is not permitted. Each new file upload MUST increment the version number using a semantic minor versioning scheme (1.0 → 1.1 → 1.2).

**Scenarios:**

1. **GIVEN** Shillinq's repair step runs **WHEN** it completes **THEN** schemas `Document` and `DocumentVersion` exist in the `shillinq` register with all properties from the data model, correct types, and required flags.

2. **GIVEN** a `Document` object exists with `currentVersionId` referencing version `1.1` **WHEN** a user uploads a new file via `POST /api/v1/documents/{id}/upload` **THEN** a new `DocumentVersion` object is created with `versionNumber: 1.2`, the `Document.currentVersionId` is updated, and `uploadedBy` and `uploadedAt` are set to the current user and timestamp.

3. **GIVEN** a `DocumentVersion` object exists **WHEN** a client attempts to delete it via the OpenRegister API **THEN** the request is rejected with 403 Forbidden and the version record is preserved for audit.

4. **GIVEN** a file is uploaded **WHEN** `DocumentVersionService` stores the file **THEN** a SHA-256 checksum is computed and stored in `DocumentVersion.checksum`, and the file is stored at `shillinq/documents/{documentId}/{versionNumber}/{fileName}` within Nextcloud's `IRootFolder`.

5. **GIVEN** the same file content is uploaded twice to the same Document **WHEN** the second upload completes **THEN** a new `DocumentVersion` with an incremented version number is still created (upload is always accepted) but the `checksum` fields on both versions are identical, allowing deduplication detection.

### REQ-DOC-002: Document Upload and Attachment Panel [must]

The app MUST provide a `DocumentAttachment.vue` panel embeddable in any entity detail page. The panel MUST list all `Document` objects linked to a `(targetType, targetId)` pair, show version badges and status chips, and provide an upload action. Upload MUST support PDF, DOCX, XLSX, PNG, JPEG, and ZIP MIME types; other types MUST be rejected with a user-facing validation message.

**Scenarios:**

1. **GIVEN** an Invoice detail page renders with `targetType: Invoice` and `targetId: {invoiceId}` **WHEN** the Documents tab is selected **THEN** the `DocumentAttachment.vue` panel fetches and lists all `Document` objects where `targetType = Invoice` and `targetId = {invoiceId}`.

2. **GIVEN** the panel is displayed with 3 documents **WHEN** the user clicks the upload button and selects a PDF file **THEN** the file is uploaded, a `Document` record is created with `status: draft`, and a `DocumentVersion` `1.0` record is created; the panel list refreshes to show the new document.

3. **GIVEN** the user attempts to upload an `.exe` file **WHEN** the MIME type check runs **THEN** the upload is rejected before any server call with the message "File type not allowed. Supported formats: PDF, DOCX, XLSX, PNG, JPEG, ZIP."

4. **GIVEN** a document in the list has `confidentialityLevel: confidential` and the current user has `viewer` role **WHEN** the panel renders **THEN** the confidential document row is not shown; the panel shows only documents the user is permitted to see.

5. **GIVEN** the user clicks a document row **WHEN** navigation occurs **THEN** the user is taken to the `DocumentDetail.vue` page for that document.

### REQ-DOC-003: Document Review and Approval Workflow [must]

The app MUST implement a three-state workflow for documents: `draft → under_review → approved | rejected`. Each status transition MUST be recorded in the `Document` object with the actor userId and timestamp. Transitions MUST be access-controlled: only users with `reviewer` or higher `CollaborationRole` on the document (or on the parent entity) may approve or reject.

**Scenarios:**

1. **GIVEN** a `Document` is in `draft` status **WHEN** a user with `contributor` role or higher clicks "Submit for Review" **THEN** `Document.status` changes to `under_review`, `workflowId` is assigned if a matching `DocumentRoutingRule` exists, and the assigned reviewer group receives a Nextcloud notification.

2. **GIVEN** a `Document` is `under_review` **WHEN** a user with `reviewer` or `approver` role clicks "Approve" **THEN** `Document.status` changes to `approved`, `Document.approvedBy` is set to the acting userId, `Document.approvedAt` is set to the current timestamp, and the document uploader receives a Nextcloud in-app notification.

3. **GIVEN** a `Document` is `under_review` **WHEN** a reviewer clicks "Reject" and provides a reason **THEN** `Document.status` changes to `rejected`, `Document.rejectionReason` is stored, and the document uploader is notified with the rejection reason text.

4. **GIVEN** a `Document` is in `approved` status **WHEN** a user uploads a new file version **THEN** the status reverts to `draft` and the approver is notified that a new version requires re-approval.

5. **GIVEN** a `viewer` role user opens the `DocumentWorkflowPanel` **WHEN** the panel renders **THEN** the "Approve", "Reject", and "Submit for Review" buttons are absent or disabled with a tooltip "Insufficient permission."

6. **GIVEN** a `Document` transitions from `under_review` to `approved` **THEN** the transition is recorded in the OpenRegister audit log with `actor`, `from_status`, `to_status`, and `timestamp` fields (Municipal Treasurer user story: auditable treasury document storage).

### REQ-DOC-004: Supplier Qualification Questionnaire [must]

The app MUST register the `SupplierQuestionnaire` and `QuestionnaireResponse` schemas. Procurement managers MUST be able to create questionnaires with up to 30 questions of types: `text`, `boolean`, `file_upload`, `select`. Required questions MUST block submission if unanswered. `file_upload` questions MUST accept the same MIME types as REQ-DOC-002.

**Scenarios:**

1. **GIVEN** a procurement manager creates a `SupplierQuestionnaire` with 5 questions (2 text, 1 boolean, 1 file_upload, 1 select) **WHEN** the questionnaire is saved **THEN** the object is stored in OpenRegister with all 5 question sub-objects in the `questions` array, each with a unique `questionId`.

2. **GIVEN** a questionnaire with `isActive: true` **WHEN** a supplier opens the portal link and navigates to the questionnaire **THEN** all questions are rendered: text questions as textarea, boolean as toggle, file_upload as file picker, select as dropdown.

3. **GIVEN** a questionnaire with 2 required questions **WHEN** the supplier attempts to submit without answering a required question **THEN** submission is blocked and the unanswered required question is highlighted with "This answer is required."

4. **GIVEN** a supplier successfully submits a `QuestionnaireResponse` **WHEN** the submission is saved **THEN** `QuestionnaireResponse.status` is set to `submitted`, `submittedAt` is set to the current timestamp, the linked `SupplierPortalProfile.status` is updated to `qualification_submitted`, and the procurement manager who owns the questionnaire receives a Nextcloud notification.

5. **GIVEN** a `file_upload` question in the questionnaire **WHEN** the supplier uploads a file for that question **THEN** a `Document` object is created with `targetType: QuestionnaireResponse`, `targetId: {responseId}`, `documentType: certificate`, and a `DocumentVersion 1.0` is created; the answer in `QuestionnaireResponse.answers` stores the resulting `documentId`.

6. **GIVEN** a questionnaire has `dueWithinDays: 14` and was sent on 2026-04-08 **WHEN** the supplier opens the portal on 2026-04-23 **THEN** the portal page shows "Deadline passed" and submission is blocked (status becomes `overdue`).

### REQ-DOC-005: Supplier Portal with Self-Service Profile Management [must]

The app MUST register the `SupplierPortalProfile` schema and provide a portal page accessible without a Nextcloud account via a time-limited, rate-limited access token. The portal page MUST be served at a dedicated route outside the main Nextcloud app shell and MUST allow the supplier to edit their own profile and submit questionnaire responses.

**Scenarios:**

1. **GIVEN** a procurement manager clicks "Invite Supplier" for an `Organization` **WHEN** the invitation is created **THEN** a `SupplierPortalProfile` with `status: invited` is created, a 64-byte token is generated, its SHA-256 hash is stored in `portalTokenHash`, `portalTokenExpiresAt` is set to `now + 7 days`, and the raw token is returned once and sent to `contactEmail`.

2. **GIVEN** a supplier follows the portal link containing the raw token **WHEN** `SupplierPortalTokenService` validates it **THEN** the incoming token is hashed, compared to `portalTokenHash`, and accepted only if the hashes match and `portalTokenExpiresAt` is in the future; on success, the portal profile page renders.

3. **GIVEN** the same portal link is used more than 10 times from the same IP within 60 seconds **WHEN** the 11th attempt arrives **THEN** `IThrottler` returns a 429 Too Many Requests response.

4. **GIVEN** a supplier updates their `SupplierPortalProfile` via `PUT /api/v1/supplier-portal/{token}/profile` **WHEN** the save succeeds **THEN** the updated fields are persisted to OpenRegister and `SupplierPortalProfile.status` advances to `profile_complete` if all required fields (`organizationName`, `contactName`, `contactEmail`) are now filled.

5. **GIVEN** the portal token has expired **WHEN** a supplier attempts to access the portal **THEN** the portal page shows "This link has expired. Please contact your procurement manager to request a new link." and no profile data is returned.

6. **GIVEN** a supplier completes their profile and submits a questionnaire response **WHEN** the procurement manager opens the `SupplierPortalDetail.vue` page **THEN** they see the profile data, all questionnaire responses with their statuses, and the uploaded documents with download links.

### REQ-DOC-006: Contract Template Library and Clause Management [must]

The app MUST fully implement the `ContractTemplate` schema (extending the partial definition from the data model with `clauses` and `defaultApprovalChainId` properties) and the `ContractClause` schema. A contract MUST be instantiatable from a template via a dedicated OCS API action that snapshots each clause at its current approved version.

**Scenarios:**

1. **GIVEN** a `ContractTemplate` with `clauses: ["clause-id-1", "clause-id-2", "clause-id-3"]` **WHEN** a user calls `POST /api/v1/contract-templates/{id}/instantiate` **THEN** a new contract entity (handled by the contracts change) is created with the template's clauses embedded as snapshots, each storing `clauseId`, `clauseSnapshotVersion`, and `clauseContent` at the time of instantiation.

2. **GIVEN** a `ContractTemplate` has `isActive: false` **WHEN** a user attempts to instantiate a contract from it **THEN** the API returns 422 with error "This template is inactive and cannot be used to create new contracts."

3. **GIVEN** a procurement manager opens `ContractTemplateDetail.vue` **WHEN** the page renders **THEN** the "Clauses" tab shows the ordered list of clauses using `ClauseSelector.vue`, with each clause displaying `clauseTitle`, `currentVersion`, and status badge.

4. **GIVEN** a user reorders clauses in `ClauseSelector.vue` **WHEN** they save the template **THEN** `ContractTemplate.clauses` is updated with the new order and saved to OpenRegister.

5. **GIVEN** a `ContractClause` has `status: draft` **WHEN** a user with `approver` role opens the clause detail **THEN** an "Approve Clause" button is visible; clicking it changes `status` to `approved` and sets `approvedBy` and `approvedAt`.

6. **GIVEN** a `ContractTemplate` with 3 clauses all at `status: approved` **WHEN** the user searches for templates **THEN** only templates with `isActive: true` appear in the default list view; archived/inactive templates can be shown by toggling a "Show inactive" filter.

### REQ-DOC-007: Clause Version Management [must]

The app MUST implement a version lifecycle for `ContractClause` objects. Each clause update MUST archive the previous version to `clauseHistory` before replacing the current content. Contracts that used an older clause version MUST display a notification badge indicating that a newer approved version exists. A diff view MUST be available for comparing any two clause versions.

**Scenarios:**

1. **GIVEN** a `ContractClause` at version `1.0` with `status: approved` **WHEN** a user proposes an update to the clause text **THEN** a pending draft is created (a new entry in `clauseHistory` with `status: pending`), the `currentVersion` remains `1.0` until the update is approved, and `ContractClause.status` changes to `pending_approval`.

2. **GIVEN** a `ContractClause` has a pending version update **WHEN** a user with `approver` role calls `POST /api/v1/contract-clauses/{id}/approve-version` **THEN** the old version `{version: "1.0", content: "...", approvedBy: "...", approvedAt: "..."}` is archived to `clauseHistory`, `ContractClause.content` is updated to the new text, `currentVersion` is incremented to `2.0`, and `status` returns to `approved`.

3. **GIVEN** a contract was created from a template when clause `payment-terms-30` was at version `1.0` **WHEN** that clause is updated to version `2.0` **THEN** the contract detail page shows a badge "1 clause updated — review recommended" next to the "Clauses" tab; the stored `clauseSnapshotVersion` on the contract remains `1.0` and the contract text is NOT changed.

4. **GIVEN** a user opens `ClauseDiffView.vue` and selects `from: 1.0, to: 2.0` for a clause **WHEN** the diff renders **THEN** deleted lines are shown in red with a minus prefix, added lines in green with a plus prefix, and unchanged lines in neutral; the diff is computed from `clauseHistory` entries.

5. **GIVEN** a `ContractClause` has 12 historical versions in `clauseHistory` **WHEN** a user opens the "Version History" tab on `ContractClauseDetail.vue` **THEN** all 12 versions are listed in reverse chronological order with `version`, `approvedBy`, and `approvedAt`; the list is paginated at 10 per page.

6. **GIVEN** a clause is updated to version `3.0` **WHEN** `ContractClauseVersionService` archives the previous version **THEN** the `clauseHistory` array entry for the archived version contains `version`, `content`, `approvedBy`, and `approvedAt` and is immutable — no edit action is available for archived versions.

### REQ-DOC-008: Document Routing Rules [should]

The app MUST register the `DocumentRoutingRule` schema and evaluate active rules on each `Document` create event. Rules MUST be evaluated in priority order (ascending integer). Only the first matching rule fires. Admin users MUST be able to create, edit, and delete rules from the admin settings UI.

**Scenarios:**

1. **GIVEN** two active rules exist: Rule A (priority 50, triggerEntityType: Invoice, actionType: assign_workflow) and Rule B (priority 100, triggerEntityType: Invoice, actionType: notify_group) **WHEN** a new Document with `targetType: Invoice` is created **THEN** only Rule A fires; Rule B is not evaluated because Rule A matched first.

2. **GIVEN** a `DocumentRoutingRule` with `matchDocumentType: certificate` and `actionType: assign_workflow` **WHEN** a `Document` of `documentType: invoice` is created on an Invoice entity **THEN** the rule does NOT fire because `matchDocumentType` does not match.

3. **GIVEN** a routing rule with `actionType: notify_group` and `notifyGroupId: finance` **WHEN** the rule fires for a new document **THEN** all members of the `finance` Nextcloud group receive an in-app notification "New document requires review: {document.title}" with a link to the document detail page.

4. **GIVEN** an admin opens the Document Routing Rules list in admin settings **WHEN** they click "New Rule" **THEN** a `CnFormDialog` opens with all rule fields; saving creates the rule in OpenRegister and it takes effect immediately for subsequent document creates.

5. **GIVEN** no routing rule matches a new document **WHEN** `DocumentRoutingService` completes evaluation **THEN** the document is created with `workflowId: null` and no notification is sent; the absence of a match is logged at DEBUG level.

### REQ-DOC-009: Confidentiality-Based Access Control for Documents [must]

The app MUST enforce `confidentialityLevel` on `Document` objects. The `DocumentAccessService` MUST gate all document metadata reads and file downloads based on the user's `CollaborationRole` or `AccessControl` grant. Confidential documents MUST be invisible in list views to users without the required role.

**Scenarios:**

1. **GIVEN** a `Document` with `confidentialityLevel: public` **WHEN** any authenticated Shillinq user requests the document list or detail **THEN** the document is visible and downloadable.

2. **GIVEN** a `Document` with `confidentialityLevel: confidential` **WHEN** a user with `viewer` `CollaborationRole` on the parent entity requests the document list **THEN** the document is NOT included in the response (filtered server-side by `DocumentAccessService`).

3. **GIVEN** a `Document` with `confidentialityLevel: confidential` **WHEN** a user with `reviewer` `CollaborationRole` on the document or parent entity requests it **THEN** the document metadata and download URL are returned.

4. **GIVEN** a `Document` with `confidentialityLevel: restricted` **WHEN** a user without an explicit `AccessControl` grant for that document requests it **THEN** `DocumentAccessService` returns 403 Forbidden, regardless of the user's `CollaborationRole`.

5. **GIVEN** a Municipal Treasurer sets a treasury mandate document to `confidentialityLevel: restricted` **WHEN** non-treasury staff open the invoice or contract linked to that mandate **THEN** the document does not appear in the `DocumentAttachment.vue` panel; the panel shows "X document(s) hidden due to access restrictions" if the user has at least `internal` clearance (user story: auditable treasury document storage with controlled access).

### REQ-DOC-010: Seed Data for Document Management [must]

The app MUST load seed data for all document management schemas via the repair step. Seed data MUST be idempotent: re-running the repair step MUST NOT create duplicate objects. Each schema MUST have at least one seed object to demonstrate the data model and enable UI testing without manual data entry.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** the following seed objects are created: 2 Documents, 2 DocumentVersions, 1 SupplierQuestionnaire (with 3 questions), 1 SupplierPortalProfile, 2 ContractTemplates, 3 ContractClauses, and 1 DocumentRoutingRule.

2. **GIVEN** the repair step has already run and seed objects exist **WHEN** the repair step runs again **THEN** no new seed objects are created; idempotency is enforced by checking a stable unique key per schema (e.g., `Document.title`, `ContractClause.clauseTitle`, `DocumentRoutingRule.ruleName`).

3. **GIVEN** the seed ContractTemplate "Standard Service Agreement" is created **THEN** it references all 3 seed ContractClause objects in its `clauses` array so that the contract template detail page can be rendered immediately after installation.

4. **GIVEN** the seed SupplierQuestionnaire is created **THEN** it contains exactly the following questions: (1) `questionId: q1, type: text, text: "KvK registration number", required: true`; (2) `questionId: q2, type: file_upload, text: "Upload valid insurance certificate", documentType: certificate, required: true`; (3) `questionId: q3, type: boolean, text: "Does your organisation have a data processing agreement in place?", required: false`.

5. **GIVEN** the seed DocumentRoutingRule is created **THEN** it has `ruleName: "Internal Invoice Documents → Finance Review"`, `triggerEntityType: Invoice`, `matchConfidentialityLevel: internal`, `actionType: notify_group`, `notifyGroupId: finance`, and `isActive: true`.

# Design: Document Management — Shillinq

## Architecture Overview

This change adds document management, supplier qualification, supplier portal, contract authoring, and clause version management capabilities on top of the core, access-control-authorisation, and collaboration infrastructure. All new entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. Binary file content is stored in Nextcloud's native file system via `IRootFolder`; OpenRegister objects hold metadata and file path references only.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Document, DocumentVersion, SupplierQuestionnaire,
    │                          QuestionnaireResponse, SupplierPortalProfile,
    │                          ContractTemplate, ContractClause,
    │                          DocumentRoutingRule CRUD)
    │
    └─ Shillinq OCS API
            ├─ DocumentController        (upload, download, workflow transitions)
            ├─ SupplierPortalController  (token issue, profile save, response submit)
            ├─ ContractTemplateController (instantiate contract from template)
            ├─ ContractClauseController  (version bump, diff)
            └─ DocumentRoutingController (rule evaluation, manual trigger)
                    │
                    └─ PHP Services
                            ├─ DocumentVersionService
                            ├─ DocumentAccessService
                            ├─ DocumentRoutingService
                            ├─ SupplierPortalTokenService
                            └─ ContractClauseVersionService
```

## Data Model

### Document (`schema:DigitalDocument`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | Human-readable document name |
| description | string | No | — | Optional summary |
| documentType | string | Yes | — | Enum: contract / invoice / certificate / policy / questionnaire / other |
| status | string | Yes | draft | Enum: draft / under_review / approved / rejected / archived |
| confidentialityLevel | string | Yes | internal | Enum: public / internal / confidential / restricted |
| targetType | string | No | — | Parent entity type (e.g. Invoice, PurchaseOrder, Contract) |
| targetId | string | No | — | OpenRegister object ID of the parent entity |
| currentVersionId | string | No | — | OpenRegister object ID of the current DocumentVersion |
| uploadedBy | string | Yes | — | Nextcloud userId of initial uploader |
| createdDate | datetime | Yes | — | Creation timestamp |
| lastModifiedDate | datetime | No | — | Last update timestamp |
| approvedBy | string | No | — | userId who approved the document |
| approvedAt | datetime | No | — | Approval timestamp |
| rejectionReason | string | No | — | Reason text when status is rejected |
| workflowId | string | No | — | ID of the ApprovalWorkflow assigned to this document |
| tags | array | No | [] | Free-text tags for search |

### DocumentVersion (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| documentId | string | Yes | — | OpenRegister object ID of the parent Document |
| versionNumber | string | Yes | — | Semantic version string e.g. `1.0`, `1.1`, `2.0` |
| filePath | string | Yes | — | Absolute path within Nextcloud `IRootFolder` |
| fileName | string | Yes | — | Original uploaded file name |
| mimeType | string | Yes | — | MIME type e.g. `application/pdf` |
| fileSizeBytes | integer | No | — | File size in bytes |
| uploadedBy | string | Yes | — | Nextcloud userId |
| uploadedAt | datetime | Yes | — | Upload timestamp |
| changeNote | string | No | — | Optional note describing what changed |
| checksum | string | No | — | SHA-256 hex digest of the file content |

### SupplierQuestionnaire (`schema:Survey`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | Questionnaire name |
| description | string | No | — | Purpose and instructions for suppliers |
| category | string | Yes | — | Enum: qualification / onboarding / renewal / audit |
| isActive | boolean | Yes | true | Whether new responses can be submitted |
| questions | array | Yes | — | Array of question objects (see sub-schema below) |
| createdBy | string | Yes | — | userId of procurement manager who created it |
| createdDate | datetime | Yes | — | Creation timestamp |
| dueWithinDays | integer | No | — | Number of days suppliers have to respond |
| requiredDocumentTypes | array | No | [] | List of `documentType` values required for qualification |

**Question sub-object structure (stored in `questions` array):**

| Field | Type | Notes |
|-------|------|-------|
| questionId | string | Unique identifier within this questionnaire |
| text | string | Question text shown to supplier |
| type | string | Enum: text / boolean / file_upload / select |
| required | boolean | Whether an answer is mandatory |
| options | array | For `select` type: list of allowed option strings |
| documentType | string | For `file_upload`: expected document type |

### QuestionnaireResponse (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| questionnaireId | string | Yes | — | ID of the parent SupplierQuestionnaire |
| supplierProfileId | string | Yes | — | ID of the SupplierPortalProfile |
| submittedBy | string | No | — | userId if submitted by authenticated user; null if via portal token |
| portalTokenId | string | No | — | AccessControl object ID of the portal token used |
| submittedAt | datetime | No | — | Submission timestamp |
| status | string | Yes | draft | Enum: draft / submitted / under_review / approved / rejected |
| answers | array | Yes | — | Array of answer objects keyed by `questionId` |
| reviewedBy | string | No | — | userId who reviewed the response |
| reviewedAt | datetime | No | — | Review timestamp |
| reviewNotes | string | No | — | Reviewer notes |

### SupplierPortalProfile (`schema:Organization`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| organizationName | string | Yes | — | Supplier's legal name |
| kvkNumber | string | No | — | Kamer van Koophandel registration number |
| contactName | string | Yes | — | Primary contact full name |
| contactEmail | string | Yes | — | Primary contact email address |
| contactPhone | string | No | — | Primary contact phone number |
| address | string | No | — | Street address |
| city | string | No | — | City |
| country | string | No | NL | ISO 3166-1 alpha-2 country code |
| iban | string | No | — | Bank account number for payments |
| status | string | Yes | invited | Enum: invited / profile_complete / qualification_submitted / qualified / rejected |
| linkedOrganizationId | string | No | — | ID of the matching `Organization` object if already in Shillinq |
| portalTokenHash | string | No | — | SHA-256 hash of the most recently issued portal access token |
| portalTokenExpiresAt | datetime | No | — | Expiry of the active portal token |
| invitedBy | string | No | — | userId who sent the portal invitation |
| invitedAt | datetime | No | — | Invitation timestamp |

### ContractTemplate (`schema:CreativeWork`)

This schema extends the partial definition from the intelligence data model with the missing `clauses` and `approvalWorkflowId` properties.

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| templateName | string | Yes | — | Name of the contract template |
| description | string | No | — | Purpose and use cases for this template |
| category | string | Yes | — | Enum: service / purchase / employment / NDA / license / framework / other |
| isActive | boolean | Yes | true | Whether template is available for creating new contracts |
| createdDate | datetime | Yes | — | Creation timestamp |
| lastModifiedDate | datetime | No | — | Last modification timestamp |
| createdByUserId | string | No | — | userId of template creator |
| defaultApprovalChainId | string | No | — | ID of default ApprovalWorkflow for contracts from this template |
| version | string | No | 1.0 | Template version number |
| clauses | array | No | [] | Ordered array of ContractClause object IDs |
| language | string | No | nl | ISO 639-1 language code |
| jurisdiction | string | No | NL | Applicable legal jurisdiction |

### ContractClause (`schema:CreativeWork`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| clauseTitle | string | Yes | — | Short descriptive title of the clause |
| clauseType | string | Yes | — | Enum: payment / liability / confidentiality / termination / warranty / general |
| content | string | Yes | — | Current approved clause text (markdown) |
| currentVersion | string | Yes | 1.0 | Current version string |
| status | string | Yes | draft | Enum: draft / pending_approval / approved / deprecated |
| clauseHistory | array | No | [] | Array of `{version, content, approvedBy, approvedAt}` objects |
| createdBy | string | No | — | userId of clause author |
| createdDate | datetime | Yes | — | Creation timestamp |
| lastModifiedDate | datetime | No | — | Last modification timestamp |
| approvedBy | string | No | — | userId who approved the current version |
| approvedAt | datetime | No | — | Approval timestamp for current version |
| language | string | No | nl | ISO 639-1 language code |

### DocumentRoutingRule (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| ruleName | string | Yes | — | Human-readable rule name |
| isActive | boolean | Yes | true | Whether the rule is evaluated on new documents |
| triggerEntityType | string | Yes | — | Entity type that triggers evaluation (e.g. Invoice) |
| matchDocumentType | string | No | — | Only match documents of this documentType; null = any |
| matchConfidentialityLevel | string | No | — | Only match documents at this level; null = any |
| actionType | string | Yes | — | Enum: assign_workflow / notify_group / set_status |
| workflowId | string | No | — | Target ApprovalWorkflow ID when actionType = assign_workflow |
| notifyGroupId | string | No | — | Nextcloud group ID when actionType = notify_group |
| targetStatus | string | No | — | Status to set when actionType = set_status |
| priority | integer | No | 100 | Evaluation order; lower number = higher priority |
| createdBy | string | No | — | userId who created the rule |

## OpenRegister Register Updates

New schemas to add to `lib/Settings/shillinq_register.json`:

- `Document`
- `DocumentVersion`
- `SupplierQuestionnaire`
- `QuestionnaireResponse`
- `SupplierPortalProfile`
- `ContractTemplate` (updated from stub to full definition)
- `ContractClause`
- `DocumentRoutingRule`

## Backend Components

### `lib/Controller/DocumentController.php`
OCS API controller. Routes:
- `GET /apps/shillinq/api/v1/documents` — list documents (filtered by targetType/targetId, confidentiality-gated)
- `POST /apps/shillinq/api/v1/documents` — create Document metadata record
- `GET /apps/shillinq/api/v1/documents/{id}` — get document metadata (access-gated)
- `PUT /apps/shillinq/api/v1/documents/{id}` — update metadata
- `DELETE /apps/shillinq/api/v1/documents/{id}` — soft-delete (sets status to `archived`)
- `POST /apps/shillinq/api/v1/documents/{id}/upload` — multipart upload; creates `DocumentVersion` via `DocumentVersionService`
- `GET /apps/shillinq/api/v1/documents/{id}/download` — streams the current version file via `IRootFolder`
- `POST /apps/shillinq/api/v1/documents/{id}/submit-for-review` — transitions status to `under_review`
- `POST /apps/shillinq/api/v1/documents/{id}/approve` — transitions status to `approved`
- `POST /apps/shillinq/api/v1/documents/{id}/reject` — transitions status to `rejected` with reason

### `lib/Controller/SupplierPortalController.php`
OCS API controller (some endpoints unauthenticated, protected by portal token). Routes:
- `POST /apps/shillinq/api/v1/supplier-portal/invite` — creates `SupplierPortalProfile` and issues a token via `SupplierPortalTokenService`
- `GET /apps/shillinq/api/v1/supplier-portal/{token}/profile` — returns profile for token holder (unauthenticated)
- `PUT /apps/shillinq/api/v1/supplier-portal/{token}/profile` — supplier updates their own profile (unauthenticated)
- `GET /apps/shillinq/api/v1/supplier-portal/{token}/questionnaire/{questionnaireId}` — returns questionnaire for supplier (unauthenticated)
- `POST /apps/shillinq/api/v1/supplier-portal/{token}/questionnaire/{questionnaireId}/submit` — submits `QuestionnaireResponse` (unauthenticated)
- `POST /apps/shillinq/api/v1/supplier-portal/{token}/upload` — uploads a document file as part of questionnaire response (unauthenticated)

### `lib/Controller/ContractTemplateController.php`
OCS API controller. Routes:
- `POST /apps/shillinq/api/v1/contract-templates/{id}/instantiate` — creates a new contract object from the template, snapshotting each clause at its `currentVersion`

### `lib/Controller/ContractClauseController.php`
OCS API controller. Routes:
- `POST /apps/shillinq/api/v1/contract-clauses/{id}/propose-update` — creates a draft of updated clause text, bumps to next minor version
- `POST /apps/shillinq/api/v1/contract-clauses/{id}/approve-version` — approves pending draft, archives old version to `clauseHistory`, sets `currentVersion`
- `GET /apps/shillinq/api/v1/contract-clauses/{id}/diff` — returns diff between two version strings (`?from=1.0&to=2.0`)

### `lib/Controller/DocumentRoutingController.php`
OCS API controller. Routes:
- `GET /apps/shillinq/api/v1/document-routing-rules` — list rules
- `POST /apps/shillinq/api/v1/document-routing-rules` — create rule
- `PUT /apps/shillinq/api/v1/document-routing-rules/{id}` — update rule
- `DELETE /apps/shillinq/api/v1/document-routing-rules/{id}` — delete rule

### `lib/Service/DocumentVersionService.php`
Handles version number calculation (increment minor on upload; increment major on explicit bump), file storage via `IRootFolder` under `shillinq/documents/{documentId}/{versionNumber}/`, creates the `DocumentVersion` OpenRegister object, and updates `Document.currentVersionId`. Computes SHA-256 checksum on upload.

### `lib/Service/DocumentAccessService.php`
Checks whether the current user may read or write a `Document` object. Read access: public level = any authenticated user; internal = any Shillinq user; confidential = `reviewer` or higher `CollaborationRole` on the document; restricted = explicit `AccessControl` grant. Write access always requires `contributor` or higher. Integrates with `AccessControl` and `CollaborationRoleService` from previous changes.

### `lib/Service/DocumentRoutingService.php`
Called from `DocumentController` after a `Document` create event. Loads all active `DocumentRoutingRule` objects ordered by priority, evaluates `triggerEntityType`, `matchDocumentType`, and `matchConfidentialityLevel` against the new document, and executes the first matching rule's action. Logs routing decisions to the Nextcloud log at INFO level.

### `lib/Service/SupplierPortalTokenService.php`
Issues a time-limited portal token: generates a 64-byte cryptographically random token via `random_bytes`, computes its SHA-256 hash, stores the hash and expiry in `SupplierPortalProfile.portalTokenHash` / `portalTokenExpiresAt`, and returns the raw token once. Token validation: hash the incoming raw token and compare to stored hash, check expiry, rate-limit via `IThrottler` (max 10 attempts per IP per minute).

### `lib/Service/ContractClauseVersionService.php`
Handles clause version lifecycle: proposes a draft update (creates a pending version), approves or rejects the proposal (archives current to `clauseHistory`, sets new current), and generates a unified diff between two version strings using PHP's built-in `array_diff` on line arrays (no external diff library needed).

## Frontend Components

### Directory Structure

```
src/
  views/
    document/
      DocumentIndex.vue           # CnIndexPage — document list with confidentiality filter
      DocumentDetail.vue          # CnDetailPage — metadata, version history tab, workflow tab
    documentVersion/
      DocumentVersionList.vue     # CnIndexPage — version history per document
    supplierQuestionnaire/
      SupplierQuestionnaireIndex.vue  # CnIndexPage
      SupplierQuestionnaireDetail.vue # CnDetailPage with question builder
      SupplierQuestionnaireForm.vue   # CnFormDialog
    questionnaireResponse/
      QuestionnaireResponseIndex.vue  # CnIndexPage — responses per questionnaire
      QuestionnaireResponseDetail.vue # CnDetailPage — answers + uploaded docs
    supplierPortal/
      SupplierPortalIndex.vue     # CnIndexPage — portal profile list (internal)
      SupplierPortalDetail.vue    # CnDetailPage — profile + response history
      SupplierPortalPage.vue      # Unauthenticated portal page (minimal layout)
      SupplierPortalForm.vue      # Self-service profile edit form
      SupplierPortalQuestionnaire.vue  # Questionnaire fill-in for supplier
    contractTemplate/
      ContractTemplateIndex.vue   # CnIndexPage
      ContractTemplateDetail.vue  # CnDetailPage with clause list tab
      ContractTemplateForm.vue    # CnFormDialog
    contractClause/
      ContractClauseIndex.vue     # CnIndexPage
      ContractClauseDetail.vue    # CnDetailPage with version history tab
      ContractClauseForm.vue      # CnFormDialog
      ClauseDiffView.vue          # Side-by-side diff between two versions
    documentRoutingRule/
      DocumentRoutingRuleIndex.vue  # CnIndexPage
      DocumentRoutingRuleForm.vue   # CnFormDialog
  components/
    DocumentAttachment.vue        # Reusable upload panel (embeds in any CnDetailPage)
    DocumentWorkflowPanel.vue     # Status badge + transition buttons + approval thread
    ClauseSelector.vue            # Drag-and-drop clause ordering for ContractTemplate
  store/
    modules/
      document.js                 # createObjectStore('document')
      documentVersion.js          # createObjectStore('documentVersion')
      supplierQuestionnaire.js    # createObjectStore('supplierQuestionnaire')
      questionnaireResponse.js    # createObjectStore('questionnaireResponse')
      supplierPortalProfile.js    # createObjectStore('supplierPortalProfile')
      contractTemplate.js         # createObjectStore('contractTemplate')
      contractClause.js           # createObjectStore('contractClause')
      documentRoutingRule.js      # createObjectStore('documentRoutingRule')
```

### Key UI Patterns

**DocumentAttachment.vue** is a reusable panel that accepts a `targetType` and `targetId` prop. It fetches all `Document` objects linked to that target, renders a file list with version badges and status chips, and provides an upload button that calls `DocumentController.upload`. The panel is inserted into any `CnDetailPage` via a "Documents" tab slot.

**DocumentWorkflowPanel.vue** renders the current status as an NL Design System status badge, shows the available transition actions as buttons (Submit for Review / Approve / Reject), and disables actions the current user lacks permission for. A compact comment thread (from `DocumentCollaborationPanel`) is shown below the status for workflow discussion.

**ClauseSelector.vue** is used in `ContractTemplateDetail.vue` to display an ordered list of clauses from the template. Clauses can be reordered via drag-and-drop (using the browser's native drag API to avoid adding a new package). Each clause chip shows the `clauseTitle`, `currentVersion`, and status badge.

**ClauseDiffView.vue** fetches two versions from `clauseHistory` (or `currentVersion`) and renders a line-by-line diff using a simple in-browser diff algorithm (additions highlighted green, deletions red) with no external diff library.

## Seed Data

Loaded by `lib/Repair/CreateDefaultConfiguration.php` (idempotent, keyed on stable identifiers):

| Schema | Seed Objects |
|--------|-------------|
| Document | "Bank Agreement 2026" (approved, confidential, contract type); "ISO Certificate Acme BV" (approved, internal, certificate type) |
| DocumentVersion | Version 1.0 for each seed Document |
| SupplierQuestionnaire | "Standard Supplier Qualification" (active, qualification category, 3 questions: KvK number text, Insurance certificate file_upload, Data processing agreement boolean) |
| SupplierPortalProfile | "Acme BV Supplier Portal" (qualification_submitted, linked to Acme BV Organization) |
| ContractTemplate | "Standard Service Agreement" (active, service category, 3 clauses); "Non-Disclosure Agreement" (active, NDA category, 2 clauses) |
| ContractClause | "Payment Terms 30 Days" (approved, payment type, v1.0); "Liability Cap 2× Contract Value" (approved, liability type, v1.0); "Standard Confidentiality" (approved, confidentiality type, v1.0) |
| DocumentRoutingRule | "Internal Invoice Documents → Finance Review" (active, triggerEntityType: Invoice, matchConfidentialityLevel: internal, actionType: notify_group, notifyGroupId: finance) |

---
status: proposed
source: specter
features: [document-management-version-control-workflow, supplier-qualification-management, supplier-portal-self-service, contract-authoring-template-library, clause-version-management]
---

# Document Management — Shillinq

## Summary

Implements document management capabilities for Shillinq: a versioned document store with workflow-driven review and approval, supplier qualification management with configurable questionnaires and document requirements, a supplier portal for self-service profile management and document submission, contract authoring with a template library and clause management, and clause version management. These capabilities address the five highest-demand document-management features identified in the Specter intelligence model and build on the core, access-control-authorisation, and collaboration infrastructure changes.

## Demand Evidence

Top features by market demand score:

- **Document management with version control and workflow** (demand: 2548) — the top-ranked feature across all categories. Finance teams, procurement managers, and legal staff need a single place to store, version, and approve business documents without resorting to shared drives or email.
- **Supplier qualification management with configurable questionnaires and document requirements** (demand: 2434) — procurement teams need to gate supplier engagement behind a structured qualification process that collects, validates, and archives the right documents (e.g., Kamer van Koophandel extract, ISO certificates, insurance certificates).
- **Supplier portal with self-service profile management and document submission** (demand: 2419) — suppliers need a lightweight self-service interface to update their own profile data and submit requested documents without a full Nextcloud account.
- **Contract authoring with template library, clause management, and approval workflow** (demand: 1832) — legal and procurement teams need to compose contracts from pre-approved clause libraries and route them through a structured approval chain before signing.
- **Clause version management** (demand: 1659) — clause text changes over time due to legal updates; teams need to track which version of a clause was used in a given contract and be notified when a newer approved version is available.

Key stakeholder pain points addressed:

- **Municipal Treasurer**: requires reliable, auditable document storage for treasury mandates, bank agreements, and deposito confirmations — versioned documents with full audit trail satisfy internal and external audit requirements.
- **Municipal Treasurer** (cash flow forecast context): supplier payment terms, framework contracts, and financing agreements must be retrievable and version-controlled to feed accurate assumptions into the 13-week forecast and the schatkistbankieren compliance view.

## What Shillinq Already Has

After the core, access-control-authorisation, and collaboration changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `AccessControl`, `Comment`, `CollaborationRole`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- `ContractTemplate` schema stub referenced in the intelligence data model (to be fully implemented here)

### What Is Missing

- No `Document` schema for storing file references with version history
- No `DocumentVersion` schema for tracking file revisions
- No `SupplierQuestionnaire` schema for configurable qualification forms
- No `QuestionnaireResponse` schema for supplier answers and document submissions
- No `SupplierPortalProfile` schema for self-service supplier data
- No `ContractClause` schema for reusable legal clause library
- No document upload UI tied to business entities (invoices, purchase orders, contracts)
- No supplier portal access token mechanism for unauthenticated document submission
- No clause diff or version comparison view

## Scope

### In Scope

1. **Document Schema and Version Control** — OpenRegister `Document` schema (`schema:DigitalDocument`) and `DocumentVersion` schema (`schema:Thing`) for storing file metadata, version history, and status transitions. Full CRUD for `Document` via OpenRegister REST; versions are append-only. Views at `src/views/document/` and `src/views/documentVersion/`.

2. **Document Upload and Attachment** — a reusable `DocumentAttachment.vue` panel embeddable in any `CnDetailPage` (Invoice, PurchaseOrder, Contract, SupplierPortalProfile). File upload uses Nextcloud's `Files` API for binary storage; the `Document` object records the file path, MIME type, size, and uploader. Multiple files can be attached to a single parent entity.

3. **Document Review and Approval Workflow** — a `DocumentWorkflow` status machine: `draft → under_review → approved | rejected`. Status transitions are triggered by OCS API actions and guarded by the `AccessControl` / `CollaborationRole` service. Each transition records the actor userId, timestamp, and optional comment. The `DocumentCollaborationPanel` from the collaboration change shows the approval thread inline.

4. **Supplier Qualification Questionnaire** — a `SupplierQuestionnaire` schema (`schema:Survey`) with configurable sections and questions (free-text, yes/no, file upload). A `QuestionnaireResponse` schema captures the supplier's answers and attached documents. Procurement managers create questionnaires; suppliers submit responses via the portal. Views at `src/views/supplierQuestionnaire/` and `src/views/questionnaireResponse/`.

5. **Supplier Portal with Self-Service Profile and Document Submission** — a `SupplierPortalProfile` schema (`schema:Organization`) for supplier-managed contact, banking, and certification data. Portal access uses a time-limited token (stored in `AccessControl`) to allow unauthenticated submissions. The portal view at `src/views/supplierPortal/` renders with a minimal layout outside the main Nextcloud shell; document submission triggers the qualification questionnaire flow.

6. **Contract Template Library and Clause Management** — full implementation of the `ContractTemplate` schema (already partially defined in the data model) and a `ContractClause` schema (`schema:CreativeWork`) for reusable clause text. Contract templates reference an ordered list of clauses. New contracts are instantiated from a template, inheriting the current active clause versions. Views at `src/views/contractTemplate/` and `src/views/contractClause/`.

7. **Clause Version Management** — `ContractClause` maintains a `currentVersion` string and a `clauseHistory` array of `{version, content, approvedBy, approvedAt}` objects. When a clause is updated, the previous version is archived to history and the current version number is incremented. Contracts record `clauseSnapshotVersion` per clause so auditors can see exactly which text was in force at signing. A diff view (`ClauseDiffView.vue`) shows changes between any two clause versions.

8. **Document Routing Rules** — `DocumentRoutingRule` schema (`schema:Action`) defines rules for automatic document routing: on upload to a target entity type, route to a specific approval workflow or notify a named group. Rules are configured in admin settings and evaluated by `DocumentRoutingService.php` on each `Document` create event.

9. **Access Restriction for Confidential Documents** — a `confidentialityLevel` field on `Document` (public / internal / confidential / restricted). The `DocumentAccessService.php` gate checks `AccessControl` and `CollaborationRole` before serving document metadata or file download URLs. Confidential documents are hidden from list views for users below `reviewer` role.

10. **Seed Data** — demo records for all new schemas (ADR-016): 2 Documents, 2 DocumentVersions, 1 SupplierQuestionnaire with 3 questions, 1 SupplierPortalProfile, 2 ContractTemplates, 3 ContractClauses, 1 DocumentRoutingRule. Loaded via the repair step idempotently.

### Out of Scope

- Full e-signature integration (DocuSign, eHerkenning) — deferred to a separate change
- PDF generation from contract templates — deferred (requires PDF rendering dependency decision)
- OCR and automated document data extraction — deferred
- External supplier registration via public web form without any token — security risk, deferred
- WebDAV-level document locking — Nextcloud handles this at the file level
- Generate Perspectiefnota council document (demand: 1011) — partially covered by template library; standalone council-document generation deferred

## Acceptance Criteria

1. GIVEN a user uploads a file to a Document object WHEN the upload succeeds THEN a DocumentVersion record is created with version `1.0`, the file path, uploader userId, and upload timestamp; subsequent uploads to the same Document create versions `1.1`, `1.2`, etc.
2. GIVEN a Document is in `draft` status WHEN a user with `reviewer` role clicks "Submit for Review" THEN the status changes to `under_review`, the assigned reviewer receives a Nextcloud notification, and the transition is recorded in the audit trail.
3. GIVEN a Document is `under_review` WHEN the reviewer approves it THEN the status changes to `approved`, the document author receives a notification, and a timestamp + reviewer userId are stored on the Document object.
4. GIVEN a procurement manager creates a SupplierQuestionnaire with 5 questions including 2 file-upload questions WHEN a supplier opens the portal link THEN the supplier sees all 5 questions rendered correctly and can upload files for the file-upload questions without a Nextcloud account.
5. GIVEN a supplier submits a QuestionnaireResponse WHEN all required questions are answered THEN the response is saved to OpenRegister, the procurement manager receives a Nextcloud notification, and the supplier's SupplierPortalProfile status is updated to `qualification_submitted`.
6. GIVEN a ContractTemplate has 4 clauses WHEN a user creates a new contract from the template THEN each clause is copied as a snapshot with the `clauseSnapshotVersion` recorded; the contract body reflects the current approved clause text.
7. GIVEN a ContractClause is updated to version `2.0` WHEN a reviewer approves the new version THEN existing contracts that used version `1.0` show a notification badge "Clause updated — review recommended"; the contract text is NOT automatically changed.
8. GIVEN a Document has `confidentialityLevel: confidential` WHEN a user with `viewer` role opens the document list THEN the confidential document is not visible; a user with `reviewer` role can see and open it.
9. GIVEN a DocumentRoutingRule targets `Invoice` documents with `confidentialityLevel: internal` WHEN a matching Document is created THEN the routing service automatically assigns it to the configured approval workflow without manual intervention.
10. GIVEN a fresh Shillinq installation WHEN the repair step runs THEN all seed data for Document, SupplierQuestionnaire, SupplierPortalProfile, ContractTemplate, ContractClause, and DocumentRoutingRule is created and no duplicates are produced on re-run.

## Risks and Dependencies

- **Nextcloud Files API for binary storage**: Document versions store file paths in Nextcloud's `files` storage. The `Document` object in OpenRegister holds metadata only; actual binary transfer uses Nextcloud's `ISimpleFS` or `IRootFolder` API. File deletion must be co-ordinated to avoid orphaned files.
- **Supplier portal unauthenticated access**: Portal tokens must be time-limited (default 7 days), single-use for sensitive operations, and rate-limited via Nextcloud's `IThrottler` to resist brute-force enumeration. Token secrets must be stored hashed (SHA-256); the raw token is returned only at creation time.
- **Clause history array size**: Storing clause version history as a JSON array inside `ContractClause` may grow large for frequently amended clauses. A pagination-aware history endpoint is needed; very long histories should be monitored against OpenRegister's maximum object size.
- **`ContractTemplate` schema gap**: The intelligence data model defines `ContractTemplate` without `clauses` (array of clause IDs) or `approvalWorkflowId` as explicit schema properties. These properties must be added to the register JSON in this change.
- **Access token security**: Portal tokens are stored as `AccessControl` objects with a hashed secret field. The pattern mirrors Nextcloud app passwords to avoid introducing a custom credential storage mechanism.

---
status: proposed
---

# Contract Lifecycle Management — Shillinq

## Purpose

Defines functional requirements for Shillinq's contract lifecycle management capabilities: a central contract repository with full-text search, AI-assisted obligation extraction and redlining, complete lifecycle orchestration from draft through award, execution, renewal, and expiration, approval routing integration with the existing ApprovalWorkflow engine, contract hierarchy management, obligation deadline tracking, and AVG/privacy compliance support.

Stakeholders: Contract Manager, Internal Auditor, Data Protection Officer.

User stories addressed: Create new contract draft, Select contract template by type, Assign contract owner and responsible department, Attach supplier from supplier register, Upload signed contract document.

## Requirements

### REQ-CLM-001: Contract Schema Registration and Lifecycle Status Machine [must]

The app MUST register the `Contract` schema (`schema:Agreement`) in OpenRegister via `lib/Settings/shillinq_register.json`. The `Contract` entity MUST support a defined status lifecycle: `draft → review → approved → signed → executed → expired / renewed`. Status transitions MUST be validated server-side. A unique `contractNumber` (format: CNT-YYYY-NNNN) MUST be auto-assigned on creation.

**Scenarios:**

1. **GIVEN** a contract manager submits the new contract form with title, supplierId, contractType, startDate, and ownerUserId **WHEN** the form is saved **THEN** a `Contract` record is created in OpenRegister with `status: draft`, `contractNumber` set to the next sequential value for the current year (e.g. CNT-2026-0001), and `createdDate` set to the current timestamp.

2. **GIVEN** a `Contract` is in `draft` status **WHEN** a user calls `POST /api/v1/contracts/{id}/submit-for-approval` **THEN** `ContractApprovalService` validates all required fields are present, creates an `ApprovalRequest` via `WorkflowRoutingService`, and advances `Contract.status` to `review`; if a required field is missing the API returns 422 with the field name.

3. **GIVEN** a `Contract` is in `review` status and the linked `ApprovalRequest` reaches `status: approved` **WHEN** `ContractApprovalService` processes the approval event **THEN** `Contract.status` advances to `approved` and the contract owner receives a Nextcloud notification "Uw contract is goedgekeurd en klaar voor ondertekening."

4. **GIVEN** a `Contract` is in `approved` or `draft` status **WHEN** a signed PDF is uploaded and marked as the signed version **THEN** the document is stored with a timestamp and uploader identity, `Contract.status` advances to `signed`, and `Contract.signingDate` is set to the upload timestamp.

5. **GIVEN** a `Contract` is in `signed` status **WHEN** `Contract.startDate` is reached (checked by `ContractRenewalJob`) **THEN** `Contract.status` advances to `executed`.

6. **GIVEN** a user attempts to call `PATCH /api/v1/contracts/{id}` with `status: signed` while the current status is `draft` (skipping intermediate states) **WHEN** the request is processed **THEN** the API returns 422 "Ongeldige statusovergang van draft naar signed."

7. **GIVEN** the contract list page (`ContractIndex.vue`) renders **WHEN** the page loads **THEN** it uses `CnIndexPage` with `columnsFromSchema('contract')`, filter chips for `status`, `contractType`, `ownerUserId`, and `privacyImpact` via `filtersFromSchema()`, and a "Nieuw contract" button that opens `ContractForm.vue`.

### REQ-CLM-002: Contract Repository with Full-Text Search [must]

The app MUST provide a Nextcloud global search provider (`ContractSearchProvider`) that indexes `Contract` and `ContractObligation` data and returns results ranked by relevance. A dedicated search bar in `ContractIndex.vue` MUST support free-text queries against contract title, description, contract number, and obligation titles.

**Scenarios:**

1. **GIVEN** a user types "ICT-dienstverlening" in the global Nextcloud search **WHEN** the search executes **THEN** the `ContractSearchProvider` returns a result for "Raamovereenkomst ICT-dienstverlening" with the contract number, status badge, and a direct link to `ContractDetail.vue`.

2. **GIVEN** a contract has an obligation with title "Jaarlijkse penetratietest uitvoeren" **WHEN** the user searches for "penetratietest" **THEN** the global search results include that obligation with its parent contract title as subtitle and the obligation status as badge.

3. **GIVEN** a user does not have access rights to a contract via `AccessControl` **WHEN** a full-text search query would match that contract **THEN** the contract is excluded from the search results returned to that user.

4. **GIVEN** the search bar in `ContractIndex.vue` has a query entered **WHEN** the user submits the query **THEN** the `contractStore` dispatches a filtered OpenRegister query using the `search` parameter and the displayed list updates to show only matching contracts without a full page reload.

5. **GIVEN** a contract manager searches by contract number "CNT-2026-0001" **WHEN** the search executes **THEN** the exact-matching contract is returned as the first result.

### REQ-CLM-003: AI Obligation Extraction [must]

The app MUST provide a `ContractAiService` that extracts obligation tasks from contract document text and persists them as `ContractObligation` records with `aiGenerated: true`. The AI endpoint MUST be configurable via `AppSettings`. Extraction MUST be triggered manually by the contract manager from `ContractDetail.vue`.

**Scenarios:**

1. **GIVEN** a contract has an attached PDF document **WHEN** the contract manager clicks "Verplichtingen extraheren" in the Obligations tab **THEN** `POST /api/v1/contracts/{id}/extract-obligations` is called, `Contract.aiRedlineStatus` is set to `running`, and a spinner is displayed; when extraction completes the status updates to `completed` and the extracted obligations appear in the list.

2. **GIVEN** `ContractAiService` receives the contract text **WHEN** the AI response is parsed **THEN** each obligation in the response is persisted as a `ContractObligation` with `aiGenerated: true`, `automatedDeadlineTracking: true`, `contractId` set to the current contract, and all response fields (title, description, obligationType, dueDate, priority) mapped.

3. **GIVEN** the AI profile endpoint is not configured in AppSettings **WHEN** the extract-obligations endpoint is called **THEN** the API returns 503 "AI-profiel is niet geconfigureerd. Configureer een AIProfile in de instellingen."

4. **GIVEN** extraction has already run and produced 3 obligations **WHEN** extraction is run again **THEN** new obligations are appended to the existing list (duplicates are not deleted automatically); a banner warns "Extractie heeft {n} nieuwe verplichtingen toegevoegd. Controleer op duplicaten."

5. **GIVEN** a `ContractObligation` with `aiGenerated: true` is displayed **WHEN** the contract manager opens the obligation detail **THEN** a badge "Gegenereerd door AI" is shown and an "Archiveer" button is available to set `status: blocked` (soft-delete equivalent).

### REQ-CLM-004: AI Contract Redlining [must]

The app MUST provide a `ContractRedlineService` that compares the contract document text against the approved template for the contract's `contractType`, identifies clause deviations, and stores them as `Comment` objects tagged `redline` on the `Contract` entity. A `RedlineAnnotationPanel.vue` MUST display flagged clauses with severity and template comparison.

**Scenarios:**

1. **GIVEN** a contract has `contractType: standard` and a standard template document is stored in OpenRegister **WHEN** the contract manager clicks "Redlining uitvoeren" in `ContractDetail.vue` **THEN** `POST /api/v1/contracts/{id}/run-redline` is called and `ContractRedlineService` sends both texts to the AI profile for comparison.

2. **GIVEN** `ContractRedlineService` receives the AI response with clause deviations **WHEN** the response is processed **THEN** each deviation is stored as a `Comment` on the `Contract` entity with tags: `redline`, severity (`informational` / `warning` / `critical`), `clauseReference`, and `templateText` (the accepted clause) in the comment body.

3. **GIVEN** `RedlineAnnotationPanel.vue` renders **WHEN** the contract has 2 warning and 1 critical redline comments **THEN** the critical annotation is shown first with a red badge, warnings follow with amber badges, and each entry shows the contract clause text alongside the accepted template text in a two-column layout.

4. **GIVEN** a contract has no template defined for its `contractType` **WHEN** `run-redline` is called **THEN** the API returns 422 "Geen goedgekeurd sjabloon gevonden voor contracttype standard."

5. **GIVEN** a critical redline annotation exists on a contract in `draft` status **WHEN** the contract manager attempts to submit for approval **THEN** a warning banner is shown: "Dit contract heeft {n} kritieke redline-annotaties. Controleer deze vóór indiening." The submission is not blocked but the warning is logged in `AuditTrail`.

### REQ-CLM-005: ContractObligation Schema Registration and Deadline Tracking [must]

The app MUST register the `ContractObligation` schema (`schema:Thing`) in OpenRegister. The `ObligationDeadlineJob` MUST run daily, set obligations to `overdue` when their `dueDate` has passed, and send reminder notifications 7 days before due date. All status changes MUST be recorded in `AuditTrail`.

**Scenarios:**

1. **GIVEN** a `ContractObligation` with `automatedDeadlineTracking: true` has `dueDate: 2026-04-08` and `status: pending` **WHEN** the `ObligationDeadlineJob` runs on 2026-04-09 **THEN** the obligation `status` changes to `overdue`, the `assignedUserId` receives a notification "Verplichting 'Kwartaalrapportage Q1 2026' is vervallen.", and an `AuditTrail` record is written with `actor: system` and `action: obligation_overdue`.

2. **GIVEN** a `ContractObligation` has `dueDate` 5 days from today **WHEN** the `ObligationDeadlineJob` runs **THEN** the `assignedUserId` receives a reminder notification "Verplichting '{title}' vervalt over 5 dagen." and `status` remains `pending`.

3. **GIVEN** a `ContractObligation` already has `status: overdue` **WHEN** `ObligationDeadlineJob` runs again **THEN** the obligation is skipped; no duplicate notification is sent and no duplicate `AuditTrail` record is created.

4. **GIVEN** a contract manager opens `ContractObligationIndex.vue` **WHEN** the list renders **THEN** it uses `CnIndexPage` with `columnsFromSchema('contractObligation')`, filter chips for `status`, `obligationType`, `priority`, and `aiGenerated`, and overdue obligations display an `ObligationStatusBadge.vue` with a red background.

5. **GIVEN** a contract manager marks an obligation as complete via `PATCH /api/v1/contract-obligations/{id}` with `status: completed` **WHEN** the update is saved **THEN** `completionDate` is set to the current timestamp and an `AuditTrail` record is written with the acting userId and the previous status.

6. **GIVEN** a `ContractObligation` has `automatedDeadlineTracking: false` **WHEN** `ObligationDeadlineJob` runs **THEN** the obligation is excluded from all deadline checks; no status change or notification is triggered.

### REQ-CLM-006: Contract Approval Routing Integration [must]

The app MUST integrate with the existing `ApprovalWorkflow` engine (approval-workflow-management change) to route contracts through a configurable multi-step approval chain. A `Contract.approvalWorkflowId` field links the contract to an `ApprovalWorkflow` of `workflowType: Contract`. The contract `status` advances automatically in response to `ApprovalRequest` status changes.

**Scenarios:**

1. **GIVEN** an active `ApprovalWorkflow` of `workflowType: Contract` exists **WHEN** the contract manager clicks "Ter goedkeuring indienen" on a contract in `draft` status **THEN** `ContractApprovalService` calls `WorkflowRoutingService::route()` with the `Contract` entity, an `ApprovalRequest` is created in `pending` status, `Contract.status` advances to `review`, and the first approver is notified.

2. **GIVEN** no active `ApprovalWorkflow` of `workflowType: Contract` exists **WHEN** the submit-for-approval endpoint is called **THEN** the API returns 422 "Geen actieve goedkeuringsworkflow gevonden voor contracten."

3. **GIVEN** the linked `ApprovalRequest` reaches `status: rejected` **WHEN** `ContractApprovalService` processes the event **THEN** `Contract.status` reverts to `draft`, the contract owner receives a notification with the rejection justification text, and the justification is appended as a `Comment` on the `Contract` entity.

4. **GIVEN** a `ContractDetail.vue` page renders for a contract in `review` status **WHEN** the approval timeline is displayed **THEN** an embedded `ApprovalTimeline.vue` component shows all `ApprovalDecision` records for the active `ApprovalRequest` in chronological order.

5. **GIVEN** a contract is in `approved` status **WHEN** the contract manager uploads a signed document **THEN** the document is stored via the document attachment mechanism with `documentType: signed_contract` and `Contract.status` advances to `signed`.

### REQ-CLM-007: Contract Renewal Management [must]

The app MUST run a daily `ContractRenewalJob` that sends proactive renewal notifications to contract owners when `renewalDate` is within the configured lead time (default 90 days, configurable in `AppSettings`). The job MUST create a renewal obligation task on first alert. The job MUST be idempotent.

**Scenarios:**

1. **GIVEN** a `Contract` has `status: executed`, `renewalDate: 2026-07-01`, and today is 2026-04-10 (82 days before renewal) and the configured lead time is 90 days **WHEN** `ContractRenewalJob` runs **THEN** the contract owner (`ownerUserId`) and the department head receive a Nextcloud notification "Contract 'Raamovereenkomst ICT-dienstverlening' nadert de verlengingsdatum (2026-07-01)." and a `ContractObligation` with title "Vernieuwing beoordelen" and `dueDate: 2026-07-01` is created.

2. **GIVEN** the `ContractRenewalJob` already ran and created the renewal obligation **WHEN** the job runs again on the next day **THEN** no duplicate notification is sent and no duplicate obligation is created; idempotency is checked via the key `(contractId, 'renewal_notified_' + renewalDate.toDateString())` in `AuditTrail`.

3. **GIVEN** a `Contract` has `status: expired` **WHEN** `ContractRenewalJob` runs **THEN** the contract is skipped regardless of its `renewalDate`.

4. **GIVEN** the renewal lead time is set to 30 days in AppSettings **WHEN** a contract has `renewalDate` 45 days away **THEN** `ContractRenewalJob` does NOT trigger an alert for that contract; the alert fires when the date falls within 30 days.

5. **GIVEN** the contract manager opens `ContractDetail.vue` for a contract that has received a renewal alert **WHEN** the Overzicht tab renders **THEN** a yellow banner "Verlenging nadert: {renewalDate}" is displayed with a "Start verlengingsproces" button that opens a new sub-contract form pre-filled with the parent's `supplierId` and `contractType`.

### REQ-CLM-008: Contract Hierarchy Management [must]

The app MUST support contract hierarchy via `Contract.parentContractId`. Sub-contracts inherit the parent's `supplierId` by default. `ContractDetail.vue` MUST show a hierarchy panel with breadcrumb navigation to the parent and a list of child contracts.

**Scenarios:**

1. **GIVEN** a `Contract` with `contractType: master` exists (CNT-2026-0001) **WHEN** a new contract is created with `parentContractId` referencing CNT-2026-0001 **THEN** the new contract is linked as a sub-contract and `supplierId` is pre-filled with the parent's `supplierId` (editable).

2. **GIVEN** a sub-contract's `ContractDetail.vue` renders **WHEN** the `ContractHierarchyPanel.vue` renders **THEN** it shows a breadcrumb "CNT-2026-0001 > CNT-2026-0004" with a link to the parent contract detail page.

3. **GIVEN** the parent contract detail page renders **WHEN** `ContractHierarchyPanel.vue` renders **THEN** it shows a list of all direct child contracts with their `contractNumber`, `title`, `status`, and `contractValue`.

4. **GIVEN** a user attempts to set `parentContractId` to the same contract's own ID **WHEN** the save is processed **THEN** the API returns 422 "Een contract kan niet zijn eigen bovenliggende overeenkomst zijn."

5. **GIVEN** a parent contract reaches `status: expired` **WHEN** any child contract is still in `executed` or `signed` status **THEN** a warning banner appears on the parent contract detail: "Dit contract heeft {n} actieve subovereenkomsten. Controleer de verlenging van de subovereenkomsten."

### REQ-CLM-009: AVG Privacy Compliance Support [must]

The app MUST support AVG/GDPR compliance workflows: automatic `ContractObligation` creation for missing verwerkersovereenkomst, `PrivacyImpactBanner.vue` in contract detail, and `AuditTrail` entries whenever `privacyImpact` is toggled. The Data Protection Officer MUST be notifiable when a new privacy-impacting contract is created.

**Scenarios:**

1. **GIVEN** a contract is saved with `privacyImpact: true` and `verwerkersovereenkomstStatus: required_pending` **WHEN** the save completes **THEN** a `ContractObligation` with `obligationType: compliance`, title "Verwerkersovereenkomst afsluiten", `priority: critical`, and `dueDate` set to `Contract.startDate` is automatically created and linked to the contract.

2. **GIVEN** a contract has `privacyImpact: true` **WHEN** `ContractDetail.vue` renders **THEN** `PrivacyImpactBanner.vue` is shown with text "Dit contract verwerkt persoonsgegevens. Zorg dat een verwerkersovereenkomst aanwezig is." and a link to the AVG compliance checklist.

3. **GIVEN** `privacyImpact` is toggled from `false` to `true` by a user **WHEN** the save is processed **THEN** an `AuditTrail` record is written with `actor: {userId}`, `action: privacy_impact_enabled`, `targetType: contract`, `targetId`, and `timestamp`.

4. **GIVEN** a new contract with `privacyImpact: true` is created **WHEN** the AppSettings key `dpo.notifyUserId` is configured **THEN** the Data Protection Officer (`dpo.notifyUserId`) receives a Nextcloud notification "Nieuw privacygevoelig contract aangemaakt: {contractNumber} — {title}."

5. **GIVEN** `verwerkersovereenkomstStatus` is updated to `in_place` **WHEN** the save completes **THEN** the linked "Verwerkersovereenkomst afsluiten" obligation (if present) is automatically set to `status: completed` with `completionDate` set to now.

### REQ-CLM-010: Procurement Compliance Warning [must]

The app MUST warn contract managers when `contractValue` exceeds the Dutch mandatory tender threshold and block activation when `procurementRef` is absent. The threshold MUST be configurable in `AppSettings` (default EUR 215.000 for services).

**Scenarios:**

1. **GIVEN** a contract is saved with `contractValue: 300000` and `procurementRef: ""` **WHEN** the save is processed **THEN** the contract is saved as `draft` and a warning banner is shown in `ContractDetail.vue`: "Het contractbedrag overschrijdt de aanbestedingsdrempel (EUR 215.000). Voeg een aanbestedingsreferentie toe vóór activering."

2. **GIVEN** a contract has `contractValue: 300000` and `procurementRef: ""` **WHEN** `POST /api/v1/contracts/{id}/submit-for-approval` is called **THEN** the API returns 422 "Aanbestedingsreferentie is verplicht voor contracten boven de aanbestedingsdrempel (EUR 215.000)."

3. **GIVEN** a contract has `contractValue: 300000` and `procurementRef: "ANB-2026-0011"` **WHEN** `submit-for-approval` is called **THEN** the procurement compliance check passes and the approval workflow proceeds normally.

4. **GIVEN** the AppSettings key `procurement.tenderThreshold` is set to EUR 100.000 **WHEN** a contract with `contractValue: 120000` is saved **THEN** the warning banner and activation block apply at the configured threshold, not at the default EUR 215.000.

5. **GIVEN** a contract has `contractValue: 50000` (below threshold) **WHEN** the contract is saved **THEN** no procurement warning is shown and `procurementRef` is optional.

### REQ-CLM-011: Seed Data [must]

The app MUST load demo seed data for all new schemas via the repair step. Seed data MUST be idempotent — running the repair step multiple times MUST NOT create duplicate records.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** all seed records are created: 5 Contract objects and 5 ContractObligation objects with Dutch-language field values as defined in the design seed data.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate records are created; idempotency is checked using `Contract.contractNumber` as the unique key for contracts and `ContractObligation.obligationId` for obligations.

3. **GIVEN** the seed data is loaded **WHEN** a user opens the contract list **THEN** all 5 seed contracts are visible with their correct status values (executed, signed, review, draft, approved).

4. **GIVEN** the seed data is loaded **WHEN** a user opens the obligation list for CNT-2026-0001 **THEN** 2 obligations are shown: "Kwartaalrapportage ICT-dienstverlening Q1 2026" (completed) and "Jaarlijkse penetratietest uitvoeren" (pending).

# Tasks: supplier-management

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `supplierProfile` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `supplierProfile` MUST be registered with all properties from the data model
    - AND `organizationId`, `supplierCategory`, `qualificationStatus` MUST be marked required
    - AND `supplierCategory` MUST have enum `["preferred","approved","probation","blocked"]`
    - AND `qualificationStatus` MUST have enum `["pending_verification","qualified","disqualified","suspended"]`
    - AND `riskLevel` MUST have enum `["low","medium","high","critical"]` with default `"low"`
    - AND `ibanVerificationStatus` MUST have enum `["unverified","verified","mismatch"]`
    - AND `cpvCodes` MUST be type array with default `[]`
    - AND `contractCount` MUST be type integer with default `0`
    - AND `x-schema-org` annotation MUST be `schema:Organization`

- [ ] 1.2 Add `supplierCertification` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-002`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `supplierCertification` MUST exist with `supplierProfileId` (required), `certificationType` (required), `issuer` (required), `issuedDate` (required), `expiryDate` (required), `verificationStatus` (required)
    - AND `certificationType` MUST have enum `["ISO9001","ISO27001","UEA","insurance","tax_clearance","sustainability","other"]`
    - AND `verificationStatus` MUST have enum `["verified","pending","expired","revoked"]` with default `"pending"`
    - AND `issuedDate` and `expiryDate` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Certification`

- [ ] 1.3 Add `categoryStrategy` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `categoryStrategy` MUST exist with `categoryName` (required), `cpvCodes` (required, array), `strategicImportance` (required)
    - AND `strategicImportance` MUST have enum `["critical","high","medium","low"]`
    - AND `spendTarget` and `spendActualYTD` MUST be type number
    - AND `supplierCount` MUST be type integer with default `0`
    - AND `x-schema-org` MUST be `schema:Thing`

- [ ] 1.4 Add `negotiationEvent` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-006`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `negotiationEvent` MUST exist with `supplierProfileId` (required), `eventType` (required), `actorId` (required), `actorRole` (required), `createdAt` (required)
    - AND `eventType` MUST have enum `["offer","counter_offer","acceptance","rejection","clarification"]`
    - AND `actorRole` MUST have enum `["buyer","supplier"]`
    - AND `currency` MUST default to `"EUR"`
    - AND `amount` MUST be type number
    - AND `x-schema-org` MUST be `schema:Action`

- [ ] 1.5 Add `sourcingEvent` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `sourcingEvent` MUST exist with `title` (required), `eventType` (required), `status` (required), `createdBy` (required)
    - AND `eventType` MUST have enum `["RFI","RFQ","RFP"]`
    - AND `status` MUST have enum `["draft","published","closed","cancelled","awarded"]` with default `"draft"`
    - AND `cpvCodes` MUST be type array with default `[]`
    - AND `invitedSupplierIds` MUST be type array with default `[]`
    - AND `x-schema-org` MUST be `schema:Event`

- [ ] 1.6 Add `sourcingEventResponse` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `sourcingEventResponse` MUST exist with `sourcingEventId` (required), `supplierProfileId` (required), `status` (required)
    - AND `status` MUST have enum `["invited","accepted","declined","responded","evaluated"]` with default `"invited"`
    - AND `documentIds` MUST be type array with default `[]`
    - AND `currency` MUST default to `"EUR"`
    - AND `x-schema-org` MUST be `schema:Thing`

## 2. Seed Data

- [ ] 2.1 Add SupplierProfile seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 2 SupplierProfile objects MUST be created: "Acme BV" (preferred, qualified, riskLevel low, ibanVerificationStatus verified) and "Beta Supplies BV" (approved, pending_verification, riskLevel medium)
    - AND Acme BV MUST have `cpvCodes: [{code:"30192000",description:"Office supplies"}]` and `spendYTD: 42500.00`
    - AND idempotency check MUST use `SupplierProfile.kvkNumber` as the unique key

- [ ] 2.2 Add SupplierCertification seed objects to repair step
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 SupplierCertification objects MUST be created as defined in the seed data table
    - AND idempotency check MUST use `(supplierProfileId, certificationType)` as the composite unique key

- [ ] 2.3 Add CategoryStrategy seed object to repair step
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 CategoryStrategy object MUST be created: "Office Supplies & Stationery" with cpvCodes `["30192000","30197000"]`, strategicImportance `medium`, spendTarget `80000.00`
    - AND idempotency check MUST use `CategoryStrategy.categoryName`

- [ ] 2.4 Add NegotiationEvent seed objects to repair step
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 NegotiationEvent objects MUST be created in chronological order: offer (2026-03-01), counter_offer (2026-03-05), acceptance (2026-03-08), all linked to the Acme BV SupplierProfile seed
    - AND idempotency check MUST use `(supplierProfileId, eventType, createdAt)` as the composite unique key

- [ ] 2.5 Add SourcingEvent and SourcingEventResponse seed objects to repair step
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 SourcingEvent MUST be created: "RFQ — Office Supplies Q3 2026" (RFQ, published, responseDeadline 2026-05-31)
    - AND 2 SourcingEventResponse objects MUST be created: Acme BV (responded) and Beta Supplies BV (invited)
    - AND idempotency check MUST use `SourcingEvent.title`

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/KvkLookupService.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-001`
  - **files**: `lib/Service/KvkLookupService.php`
  - **acceptance_criteria**:
    - GIVEN a valid 8-digit KvK number is passed
    - THEN the service calls the KvK Open Data API using `IClientService::newClient()`
    - AND maps `naam`, `rechtsvorm`, `adressen[0]`, and `sbiActiviteiten` from the response to SupplierProfile fields
    - AND caches the raw response keyed by KvK number for 24 h using `ICache`
    - AND throws `KvkNotFoundException` for 404 responses and `KvkApiException` for other non-2xx responses
    - AND the API key is read from `AppSettings` key `kvk.apiKey`; if absent, the service throws `KvkConfigException`

- [ ] 3.2 Create `lib/Service/IbanVerificationService.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-003`
  - **files**: `lib/Service/IbanVerificationService.php`
  - **acceptance_criteria**:
    - GIVEN an IBAN and expected company name are passed
    - THEN the service calls the configurable verification endpoint (AppSettings key `iban.verificationUrl`) via `IClientService`
    - AND maps the response to `ibanVerificationStatus: verified` (match) or `mismatch` (name mismatch)
    - AND stores `ibanVerifiedAt` and `ibanVerifiedBy` on the SupplierProfile object via OpenRegister
    - AND raw IBAN values are NEVER written to the Nextcloud log at any level

- [ ] 3.3 Create `lib/Service/CertificateChecklistService.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-002`
  - **files**: `lib/Service/CertificateChecklistService.php`
  - **acceptance_criteria**:
    - GIVEN a `SupplierProfile` object is passed
    - THEN the service loads the required certification types for the profile's `supplierCategory` from `AppSettings` key `certChecklist.{category}`
    - AND fetches all `SupplierCertification` objects where `supplierProfileId` matches
    - AND returns an array of `{type, status}` objects where status is `present`, `missing`, or `expired`
    - AND the service is called by `SupplierApprovalService` before any approval transition

- [ ] 3.4 Create `lib/Service/SupplierApprovalService.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-004`
  - **files**: `lib/Service/SupplierApprovalService.php`
  - **acceptance_criteria**:
    - GIVEN an approval request arrives for a SupplierProfile
    - THEN the service calls `CertificateChecklistService` and rejects the approval if any required certification is missing or expired
    - AND verifies `ibanVerificationStatus` is `verified`; rejects if not
    - AND on successful approval: sets `qualificationStatus: qualified`, stores `approvalDecision`, `approvedBy`, `approvedAt`
    - AND sends a Nextcloud notification to the supplier's `contactEmail` via `INotifier`
    - AND on rejection: sets `qualificationStatus: disqualified`, records the rejection reason
    - AND all approval/rejection decisions are written to the OpenRegister audit log

## 4. Background Jobs

- [ ] 4.1 Create `lib/BackgroundJob/CertificateExpiryJob.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-002`
  - **files**: `lib/BackgroundJob/CertificateExpiryJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `SupplierCertification` objects where `expiryDate` is within the next 30 days and `verificationStatus` is not `expired`
    - AND for each expiring certificate: sends a Nextcloud notification to `SupplierProfile.assignedOfficerId` with message "Certificate {certificationType} for {supplierName} expires on {expiryDate}"
    - AND for certifications with `expiryDate` in the past: sets `verificationStatus: expired` via OpenRegister and sends a separate "Certificate expired" notification
    - AND the job is idempotent: running twice in the same day does not create duplicate notifications (uses `INotificationManager` deduplication key)

- [ ] 4.2 Create `lib/BackgroundJob/SupplierRiskJob.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-008`
  - **files**: `lib/BackgroundJob/SupplierRiskJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered with an interval of 604800 seconds (weekly)
    - WHEN the job runs THEN it recalculates `riskLevel` for all SupplierProfiles with `qualificationStatus: qualified`
    - AND the scoring formula uses configurable weights from AppSettings: expired certifications (+2), unverified IBAN (+1), zero spend YTD (+1), high contract count (>10, -1)
    - AND total score maps to: 0 = low, 1 = medium, 2-3 = high, 4+ = critical
    - AND when `riskLevel` increases compared to the stored value, the `assignedOfficerId` receives a notification
    - AND the previous and new risk levels are stored in the audit log

## 5. Backend Controllers

- [ ] 5.1 Create `lib/Controller/SupplierController.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-001, #REQ-SUP-003, #REQ-SUP-004`
  - **files**: `lib/Controller/SupplierController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/suppliers/kvk-lookup?kvk={number}` is called
    - THEN `KvkLookupService::lookup()` is called and the mapped fields are returned as JSON; no SupplierProfile is created at this point
    - AND `KvkNotFoundException` returns 404 with message "KvK number not found or inactive"
    - GIVEN `POST /api/v1/suppliers/{id}/verify-iban` is called
    - THEN `IbanVerificationService::verify()` is called and the result is returned; 422 if IBAN field is empty on the profile
    - GIVEN `POST /api/v1/suppliers/{id}/approve` is called with `{justification: "..."}` body
    - THEN `SupplierApprovalService::approve()` is called; 422 returned if preconditions fail with a list of blocking items
    - GIVEN `POST /api/v1/suppliers/{id}/reject` is called with `{reason: "..."}` body
    - THEN rejection reason is required; 422 if empty; `SupplierApprovalService::reject()` is called on success

- [ ] 5.2 Create `lib/Controller/SourcingEventController.php`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-007`
  - **files**: `lib/Controller/SourcingEventController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/sourcing-events/{id}/invite` is called with `{supplierIds: [...]}` body
    - THEN each supplier ID is appended to `SourcingEvent.invitedSupplierIds`, a `SourcingEventResponse` object is created for each with `status: invited`, and a Nextcloud notification is sent to each supplier's `assignedOfficerId`
    - AND duplicate invitations (supplier already in `invitedSupplierIds`) are silently ignored
    - GIVEN `POST /api/v1/sourcing-events/{id}/close` is called
    - THEN `SourcingEvent.status` changes to `closed` and `closedAt` is set; 422 if status is already `closed` or `cancelled`

## 6. Pinia Stores

- [ ] 6.1 Create `src/store/modules/supplierProfile.js`
  - **files**: `src/store/modules/supplierProfile.js`
  - **acceptance_criteria**:
    - THEN `useSupplierProfileStore` MUST be created via `createObjectStore('supplierProfile')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.2 Create `src/store/modules/supplierCertification.js`
  - **files**: `src/store/modules/supplierCertification.js`
  - **acceptance_criteria**:
    - THEN `useSupplierCertificationStore` MUST be created via `createObjectStore('supplierCertification')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.3 Create `src/store/modules/categoryStrategy.js`
  - **files**: `src/store/modules/categoryStrategy.js`
  - **acceptance_criteria**:
    - THEN `useCategoryStrategyStore` MUST be created via `createObjectStore('categoryStrategy')`

- [ ] 6.4 Create `src/store/modules/negotiationEvent.js`
  - **files**: `src/store/modules/negotiationEvent.js`
  - **acceptance_criteria**:
    - THEN `useNegotiationEventStore` MUST be created via `createObjectStore('negotiationEvent')`

- [ ] 6.5 Create `src/store/modules/sourcingEvent.js`
  - **files**: `src/store/modules/sourcingEvent.js`
  - **acceptance_criteria**:
    - THEN `useSourcingEventStore` MUST be created via `createObjectStore('sourcingEvent')`

## 7. Frontend Views — Supplier Profile

- [ ] 7.1 Create `src/views/supplierProfile/SupplierProfileIndex.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-001`
  - **files**: `src/views/supplierProfile/SupplierProfileIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('supplierProfile')`
    - AND filter chips for `qualificationStatus`, `supplierCategory`, and `riskLevel` MUST be present via `filtersFromSchema()`
    - AND a "New Supplier" button opens `SupplierProfileForm.vue` with the KvK lookup field prominent

- [ ] 7.2 Create `src/views/supplierProfile/SupplierProfileDetail.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-001, #REQ-SUP-004`
  - **files**: `src/views/supplierProfile/SupplierProfileDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Details, Certifications, Negotiations, Documents, Sourcing
    - AND the Details tab embeds `SupplierApprovalPanel.vue` showing current `qualificationStatus` and approve/reject buttons for permitted roles
    - AND the Certifications tab embeds `CertificationChecklist.vue` listing required and optional certifications with status chips
    - AND the Negotiations tab renders `NegotiationTimeline.vue` in reverse chronological order
    - AND the Documents tab embeds `DocumentAttachment.vue` with `targetType: SupplierProfile`
    - AND the Sourcing tab lists all `SourcingEventResponse` objects linked to this supplier

- [ ] 7.3 Create `src/views/supplierProfile/SupplierProfileForm.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-001`
  - **files**: `src/views/supplierProfile/SupplierProfileForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with `fieldsFromSchema('supplierProfile')`
    - AND the `kvkNumber` field MUST have a "Look up" button that calls `GET /api/v1/suppliers/kvk-lookup` and pre-fills company name, address, and SBI codes
    - AND on KvK lookup failure the error message MUST be displayed inline and the form MUST NOT be submittable until the error is resolved or the user manually fills required fields
    - AND `qualificationStatus` MUST be pre-set to `pending_verification` and not editable during creation

## 8. Frontend Views — Supplier Certification

- [ ] 8.1 Create `src/views/supplierCertification/SupplierCertificationIndex.vue`, `SupplierCertificationDetail.vue`, `SupplierCertificationForm.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-002`
  - **files**: `src/views/supplierCertification/SupplierCertificationIndex.vue`, `src/views/supplierCertification/SupplierCertificationDetail.vue`, `src/views/supplierCertification/SupplierCertificationForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` with filter chips for `certificationType` and `verificationStatus`
    - AND a warning badge MUST be shown on rows where `expiryDate` is within 30 days (amber) or past (red)
    - GIVEN the form opens THEN it uses `CnFormDialog` with a date picker for `issuedDate` and `expiryDate`
    - AND the form warns if `expiryDate` is less than 90 days from today with "Certificate will expire soon"

- [ ] 8.2 Create `src/components/CertificationChecklist.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-002`
  - **files**: `src/components/CertificationChecklist.vue`
  - **acceptance_criteria**:
    - GIVEN a `supplierProfileId` prop is passed THEN the component calls `CertificateChecklistService` via the OCS API and renders the checklist
    - AND each checklist row shows the certification type, status chip (present / missing / expired), and expiry date if present
    - AND missing or expired items are highlighted with an amber or red NL Design System alert colour token
    - AND an "Add Certificate" button per missing row opens `SupplierCertificationForm.vue` pre-filled with the required type

## 9. Frontend Views — Category Strategy

- [ ] 9.1 Create `src/views/categoryStrategy/CategoryStrategyIndex.vue`, `CategoryStrategyDetail.vue`, `CategoryStrategyForm.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-005`
  - **files**: `src/views/categoryStrategy/CategoryStrategyIndex.vue`, `src/views/categoryStrategy/CategoryStrategyDetail.vue`, `src/views/categoryStrategy/CategoryStrategyForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` with columns: `categoryName`, `strategicImportance`, `supplierCount`, `spendTarget`, `spendActualYTD`
    - GIVEN the detail page renders THEN it uses `CnDetailPage` with tabs: Overview, Supplier Landscape, Intelligence
    - AND the Supplier Landscape tab lists all `SupplierProfile` objects whose `cpvCodes` array contains at least one CPV code from the strategy's `cpvCodes`
    - AND the Intelligence tab renders `marketIntelligence` and `strategyNotes` as editable rich-text fields

- [ ] 9.2 Create `src/components/CpvCodeSelector.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-005`
  - **files**: `src/components/CpvCodeSelector.vue`, `src/assets/cpv-codes.json`
  - **acceptance_criteria**:
    - GIVEN a search term of at least 2 characters is entered THEN the component filters `cpv-codes.json` by code prefix or description substring and renders matching results in a dropdown
    - AND selected codes appear as removable chips below the input
    - AND on selection `update:modelValue` is emitted with the full `{code, description}` array
    - AND no network request is made; all filtering is client-side from the bundled JSON

## 10. Frontend Views — Negotiation and Sourcing

- [ ] 10.1 Create `src/views/negotiationEvent/NegotiationTimeline.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-006`
  - **files**: `src/views/negotiationEvent/NegotiationTimeline.vue`
  - **acceptance_criteria**:
    - GIVEN a `supplierProfileId` prop is passed THEN all `NegotiationEvent` objects for the supplier are fetched and rendered in reverse chronological order
    - AND each event shows `eventType` badge, `amount` (if present), `actorRole`, `actorId`, `createdAt`, and `notes`
    - AND an "Add Event" button at the top opens a `CnFormDialog` for creating a new `NegotiationEvent` (eventType, amount, currency, validUntil, notes)
    - AND the form does NOT allow editing or deleting existing events (append-only)

- [ ] 10.2 Create `src/views/sourcingEvent/SourcingEventIndex.vue`, `SourcingEventDetail.vue`, `SourcingEventForm.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-007`
  - **files**: `src/views/sourcingEvent/SourcingEventIndex.vue`, `src/views/sourcingEvent/SourcingEventDetail.vue`, `src/views/sourcingEvent/SourcingEventForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` with filter chips for `eventType` and `status`
    - GIVEN the detail page renders THEN it uses `CnDetailPage` with tabs: Details, Invited Suppliers, Responses
    - AND the Invited Suppliers tab shows a supplier search (filtered by matching CPV codes) and an "Invite" button calling `POST /api/v1/sourcing-events/{id}/invite`
    - AND the Responses tab lists all `SourcingEventResponse` objects with status chips and inline score/evaluation fields for `evaluated` status responses

## 11. Frontend Components — Approval Panel

- [ ] 11.1 Create `src/components/SupplierApprovalPanel.vue`
  - **spec_ref**: `specs/supplier-management/spec.md#REQ-SUP-004`
  - **files**: `src/components/SupplierApprovalPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `SupplierProfile` prop is passed THEN the panel renders the current `qualificationStatus` as an NL Design System status badge
    - AND "Approve" and "Reject" buttons are shown only for users with `reviewer` or `approver` CollaborationRole on the profile
    - AND clicking "Approve" shows a confirmation dialog requiring a non-empty `justification` text before calling `POST /api/v1/suppliers/{id}/approve`
    - AND clicking "Reject" shows a dialog requiring a non-empty rejection reason before calling `POST /api/v1/suppliers/{id}/reject`
    - AND a precondition warning section lists any blocking items returned by the API (missing certifications, unverified IBAN)

## 12. Sidebar Navigation Update

- [ ] 12.1 Add supplier management sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Suppliers" section MUST be present with nav items for: Suppliers, Certifications, Category Strategies, Sourcing Events
    - AND each nav item MUST show a badge count from OpenRegister object counts for the corresponding schema
    - AND a "Settings" sub-item for Certificate Checklist Configuration MUST be present under the Settings section

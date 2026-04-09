# Tasks: authorization-mandate-management

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `mandate` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `mandate` MUST be registered with all properties from the data model
    - AND `mandateId`, `type`, `status`, `authorizedAmount`, `createdDate` MUST be marked required
    - AND `type` MUST have enum `["SEPA_CORE","SEPA_B2B","ELECTRONIC","MULTI_SCHEME"]`
    - AND `status` MUST have enum `["draft","pending","active","expired","revoked"]` with default `"draft"`
    - AND `policyFindings` MUST be type array with default `[]`
    - AND `x-schema-org` annotation MUST be `schema:Authorization`

- [ ] 1.2 Add `mandateScheme` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `mandateScheme` MUST exist with `schemeCode` (required), `schemeName` (required), `country` (required), `currency` (required), `minPreNotificationDays` (required, integer), `isActive` (required, boolean, default true)
    - AND `schemeCode` MUST have enum `["SEPA_CORE","SEPA_B2B","BACS_AUDDIS","BETALINGSSERVICE"]`
    - AND `validationRules` MUST be type object
    - AND `x-schema-org` MUST be `schema:Thing`

- [ ] 1.3 Add `mandateCollection` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `mandateCollection` MUST exist with `collectionId` (required), `mandateId` (required), `scheduledDate` (required, datetime), `amount` (required, number), `status` (required), `currency` (required)
    - AND `status` MUST have enum `["scheduled","submitted","settled","failed","returned"]` with default `"scheduled"`
    - AND `x-schema-org` MUST be `schema:PaymentChargeSpecification`

- [ ] 1.4 Add `purchaseOrderLineChange` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `purchaseOrderLineChange` MUST exist with `changeId` (required), `purchaseOrderId` (required), `changeType` (required), `description` (required), `requestDate` (required), `status` (required)
    - AND `changeType` MUST have enum `["addition","removal","modification","quantity","delivery-date"]`
    - AND `status` MUST have enum `["draft","submitted","approved","acknowledged","rejected","implemented"]` with default `"draft"`
    - AND `priority` MUST have enum `["low","medium","high","urgent"]` with default `"medium"`
    - AND `x-schema-org` MUST be `schema:Thing`

- [ ] 1.5 Add `supplierAcknowledgment` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `supplierAcknowledgment` MUST exist with `acknowledgmentId` (required), `poLineChangeId` (required), `status` (required)
    - AND `status` MUST have enum `["pending","accepted","rejected","counter-proposed"]` with default `"pending"`
    - AND `x-schema-org` MUST be `schema:Thing`

- [ ] 1.6 Add `mandateSignatureEvent` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-003`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `mandateSignatureEvent` MUST exist with `mandateId` (required), `signedAt` (required, datetime), `ipAddress` (required), `channel` (required)
    - AND `channel` MUST have enum `["web_link","email","paper"]` with default `"web_link"`
    - AND `x-schema-org` MUST be `schema:Thing`

## 2. Seed Data

- [ ] 2.1 Add MandateScheme seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 2 MandateScheme objects MUST be created: "SEPA Core Direct Debit" (SEPA_CORE, NL) and "SEPA Business to Business Direct Debit" (SEPA_B2B, NL)
    - AND idempotency check MUST use `schemeCode` as the unique key

- [ ] 2.2 Add Mandate seed objects to repair step
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 Mandate objects MUST be created as defined in the design seed data
    - AND one must be `status: active` (mnd-001), one `status: active` (mnd-002), one `status: pending` (mnd-003)
    - AND idempotency check MUST use `reference` as the unique key

- [ ] 2.3 Add MandateCollection seed objects to repair step
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 MandateCollection objects MUST be created: one scheduled, one settled, one failed
    - AND idempotency check MUST use `(mandateId, scheduledDate)` as the composite unique key

- [ ] 2.4 Add PurchaseOrderLineChange seed objects to repair step
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 3 PurchaseOrderLineChange objects MUST be created as defined in the design seed data
    - AND idempotency check MUST use `changeId` as the unique key

- [ ] 2.5 Add SupplierAcknowledgment seed objects to repair step
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 2 SupplierAcknowledgment objects MUST be created linked to seed PurchaseOrderLineChange objects
    - AND idempotency check MUST use `(poLineChangeId)` as the unique key

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/MandateLifecycleService.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `lib/Service/MandateLifecycleService.php`
  - **acceptance_criteria**:
    - GIVEN `activate(mandateId)` is called
    - THEN the service validates `signatureDate` is present or sets it to now; validates `creditorIdentifier` matches the scheme's `identifierFormat` regex
    - AND computes `nextCollectionDate` based on `frequency` and `signatureDate`: monthly → same day next month, quarterly → +3 months, yearly → +12 months, once → `expiryDate`
    - AND transitions status to `active` and sends notification "Mandaat {reference} is geactiveerd"
    - GIVEN `advanceCollectionDate(mandateId)` is called after a collection is settled
    - THEN `nextCollectionDate` is advanced by the frequency period and stored via OpenRegister
    - GIVEN `revoke(mandateId, reason)` is called
    - THEN status transitions to `revoked`, `revocationReason` is stored, and the mandate owner is notified
    - AND writes an `AuditTrail` entry for every status transition

- [ ] 3.2 Create `lib/Service/MandateSigningService.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-003`
  - **files**: `lib/Service/MandateSigningService.php`
  - **acceptance_criteria**:
    - GIVEN `generateSigningLink(mandateId)` is called
    - THEN a 256-bit CSPRNG token is generated via `random_bytes(32)`; its bcrypt hash is stored as `signingToken`; `signingTokenExpiresAt` is set to now + 72 hours; the plain token URL is returned once and not stored
    - GIVEN `acceptSigning(token, ipAddress, userAgent)` is called
    - THEN the token is bcrypt-verified against `signingToken`; if expired (> 72 h) returns 410; on match records a `MandateSignatureEvent`; transitions mandate to `active` via `MandateLifecycleService`
    - AND generates a PDF evidence document via Nextcloud's `ISimpleFile` containing mandate summary, IP, user-agent, timestamp; stores it linked to the mandate via document attachment mechanism
    - AND writes `AuditTrail` entry with `action: mandate_signed`, `details: {ipAddress, userAgent, channel}`

- [ ] 3.3 Create `lib/Service/PainExportService.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-002`
  - **files**: `lib/Service/PainExportService.php`
  - **acceptance_criteria**:
    - GIVEN `exportPain008(collectionDate, schemeCode)` is called
    - THEN the service queries `MandateCollection` objects with `scheduledDate: collectionDate` and parent mandate `type` matching `schemeCode`
    - AND validates each IBAN using MOD97 check; validates BIC against ISO 9362 pattern; validates UMR as ≤ 35 alphanumeric chars; returns 422 listing all validation failures if any fail
    - AND builds a PAIN.008.003.02 XML document with one `PmtInf` per mandate grouped by `creditorIdentifier`
    - AND sets service level to `CORE` for SEPA_CORE and `B2B` for SEPA_B2B
    - AND on successful export sets each `MandateCollection.status` to `submitted` and `submittedAt` to now

- [ ] 3.4 Create `lib/Service/MandateRegisterExportService.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-005`
  - **files**: `lib/Service/MandateRegisterExportService.php`
  - **acceptance_criteria**:
    - GIVEN `export(format, scope, officerName)` is called
    - THEN `scope: active` filters mandates by `status: active`; `scope: all` includes all statuses
    - AND `format: pdf` returns a PDF with Nextcloud's PDF helper; `format: xlsx` returns an XLSX file with two sheets
    - AND every page/header includes: "Gegenereerd op: {date}", "Autoriserende functionaris: {officerName}", "Versie: {n}"
    - AND version number is read from `AppSettings` key `mandate.exportVersion`, incremented, and written back after each export
    - AND IBAN fields are masked (last 4 digits only) for callers without `treasurer` or `admin` role

- [ ] 3.5 Create `lib/Service/MandatePolicyService.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-006`
  - **files**: `lib/Service/MandatePolicyService.php`
  - **acceptance_criteria**:
    - GIVEN `checkMandate(mandateId)` is called
    - THEN the service reads policy rules from `AppSettings` keys: `mandate.policy.maxAmount.{type}`, `mandate.policy.allowedCostCategories`, `mandate.policy.roleCeilings`
    - AND checks `authorizedAmount` against `maxAmount` for the mandate type; adds finding `MAX_AMOUNT_EXCEEDED` if violated
    - AND checks for duplicate: same `debtorContactId` + `creditorOrganizationId` + `type` with `status: active`; adds finding `DUPLICATE_MANDATE` with the conflicting mandate ID
    - AND returns a `PolicyFinding[]` array with each finding's `code`, `message`, and `entityId`
    - AND writes an `AuditTrail` entry per finding with `action: policy_check`
    - GIVEN `checkPoLineChange(changeId)` is called
    - THEN the service evaluates `impactAmount` against the requesting contact's role ceiling; adds finding `ROLE_CEILING_EXCEEDED` if violated

- [ ] 3.6 Create `lib/Service/PoChangeWorkflowService.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `lib/Service/PoChangeWorkflowService.php`
  - **acceptance_criteria**:
    - GIVEN `submit(changeId)` is called
    - THEN the service validates the parent `PurchaseOrder` exists and has `orderStatus: approved`; returns 422 if not
    - AND calls `WorkflowRoutingService::route()` with `requestType: PurchaseOrderLineChange` to create an internal `ApprovalRequest`; stores the `approvalRequestId` on the change
    - AND transitions `PurchaseOrderLineChange.status` to `submitted`
    - GIVEN `notifySupplier(changeId)` is called after internal approval
    - THEN generates a 30-day `acknowledgmentToken` (256-bit CSPRNG, bcrypt-hashed); creates a `SupplierAcknowledgment` in `pending` status; sends email to `SupplierProfile` contact with the acknowledgment URL; sets `supplierNotifiedAt`
    - GIVEN `recordAcknowledgment(token, response, notes)` is called
    - THEN validates token not expired; on `accepted`: updates `PurchaseOrder` lines and recalculates `subtotal` and `totalAmount`; transitions change to `acknowledged`; on `rejected`: transitions change back to `draft`; notifies procurement officer with supplier's reason
    - AND writes `AuditTrail` entry for every status transition

## 4. Background Job

- [ ] 4.1 Create `lib/BackgroundJob/MandateExpiryJob.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `lib/BackgroundJob/MandateExpiryJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `Mandate` objects with `status: active` and `expiryDate` < now
    - AND for each match: sets `status: expired`; notifies mandate owner; records `AuditTrail` entry with `actor: system`, `action: mandate_expired`
    - AND queries mandates with `expiryDate` between now and now + 90 days with `status: active`; emits a Nextcloud notification for each to the treasurer: "Mandaat {reference} verloopt op {expiryDate}"
    - AND the job is idempotent: mandates already `expired` are skipped

## 5. Backend Controllers

- [ ] 5.1 Create `lib/Controller/MandateController.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001, #REQ-AMM-002, #REQ-AMM-005`
  - **files**: `lib/Controller/MandateController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/mandates` is called THEN mandates are returned filtered by `status`, `type`, `country` query params
    - GIVEN `POST /api/v1/mandates/{id}/activate` is called THEN `MandatePolicyService::checkMandate()` is called first; if findings exist returns 422 with findings; otherwise calls `MandateLifecycleService::activate()`
    - GIVEN `POST /api/v1/mandates/{id}/revoke` is called with `{reason}` THEN `MandateLifecycleService::revoke()` is called; `reason` is required, returns 422 if absent
    - GIVEN `GET /api/v1/mandates/export/pain008` is called THEN role is checked (`treasurer` or `admin`); 403 if not; calls `PainExportService::exportPain008()`; returns XML file
    - GIVEN `GET /api/v1/mandates/export` is called THEN role is checked (`head_of_finance` or `admin`); 403 if not; calls `MandateRegisterExportService::export()`; returns file
    - IBAN values in list/detail responses are masked for callers without `treasurer` or `admin` role

- [ ] 5.2 Create `lib/Controller/MandateSigningController.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-003`
  - **files**: `lib/Controller/MandateSigningController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/mandates/{id}/generate-signing-link` is called (requires auth) THEN calls `MandateSigningService::generateSigningLink()`; returns `{signingUrl: "..."}` once
    - GIVEN `GET /api/v1/mandates/sign/{token}` is called (no auth) THEN verifies token not expired; returns mandate summary JSON; 410 if expired
    - GIVEN `POST /api/v1/mandates/sign/{token}/accept` is called (no auth) with client IP from `IRequest` THEN calls `MandateSigningService::acceptSigning(token, ip, userAgent)`; returns 200 on success; 410 if token expired; 409 if mandate already active

- [ ] 5.3 Create `lib/Controller/PoChangeController.php`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `lib/Controller/PoChangeController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/po-line-changes` is called THEN changes are returned filtered by `purchaseOrderId`, `status` query params
    - GIVEN `POST /api/v1/po-line-changes/{id}/submit` is called THEN calls `PoChangeWorkflowService::submit()`; 422 if parent PO not in `approved` status
    - GIVEN `POST /api/v1/po-line-changes/{id}/notify-supplier` is called THEN change must be in `approved` status; calls `PoChangeWorkflowService::notifySupplier()`; 422 if change not approved
    - GIVEN `GET /api/v1/po-line-changes/acknowledge/{token}` is called (no auth) THEN returns change summary for the supplier; 410 if token expired
    - GIVEN `POST /api/v1/po-line-changes/acknowledge/{token}/accept` is called (no auth) THEN calls `PoChangeWorkflowService::recordAcknowledgment(token, accepted, notes)`
    - GIVEN `POST /api/v1/po-line-changes/acknowledge/{token}/reject` is called (no auth) with `{responseNotes}` THEN calls `PoChangeWorkflowService::recordAcknowledgment(token, rejected, notes)`

## 6. Pinia Stores

- [ ] 6.1 Create `src/store/modules/mandate.js`
  - **files**: `src/store/modules/mandate.js`
  - **acceptance_criteria**:
    - THEN `useMandateStore` MUST be created via `createObjectStore('mandate')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.2 Create `src/store/modules/mandateScheme.js`
  - **files**: `src/store/modules/mandateScheme.js`
  - **acceptance_criteria**:
    - THEN `useMandateSchemeStore` MUST be created via `createObjectStore('mandateScheme')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.3 Create `src/store/modules/mandateCollection.js`
  - **files**: `src/store/modules/mandateCollection.js`
  - **acceptance_criteria**:
    - THEN `useMandateCollectionStore` MUST be created via `createObjectStore('mandateCollection')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.4 Create `src/store/modules/purchaseOrderLineChange.js`
  - **files**: `src/store/modules/purchaseOrderLineChange.js`
  - **acceptance_criteria**:
    - THEN `usePurchaseOrderLineChangeStore` MUST be created via `createObjectStore('purchaseOrderLineChange')`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 6.5 Create `src/store/modules/supplierAcknowledgment.js`
  - **files**: `src/store/modules/supplierAcknowledgment.js`
  - **acceptance_criteria**:
    - THEN `useSupplierAcknowledgmentStore` MUST be created via `createObjectStore('supplierAcknowledgment')`
    - AND the store MUST be registered in `src/store/store.js`

## 7. Frontend Views — Mandate

- [ ] 7.1 Create `src/views/mandate/MandateIndex.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `src/views/mandate/MandateIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('mandate')`
    - AND filter chips for `status`, `type`, and `country` MUST be present via `filtersFromSchema()`
    - AND a "Nieuw mandaat" button opens `MandateForm.vue`
    - AND expired mandates show a red label; mandates expiring within 90 days show an amber warning icon

- [ ] 7.2 Create `src/views/mandate/MandateDetail.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001, #REQ-AMM-003`
  - **files**: `src/views/mandate/MandateDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Overzicht, Incasso's, Handtekeningen, Beleid
    - AND the Overzicht tab shows mandate properties with masked IBAN (unless treasurer/admin)
    - AND the Incasso's tab embeds `MandateCollectionTimeline.vue`
    - AND the Handtekeningen tab embeds `MandateSignatureBadge.vue` and lists all `MandateSignatureEvent` records
    - AND the Beleid tab embeds `MandatePolicyFindingPanel.vue` showing current `policyFindings` with resolution actions
    - AND an "Activeren" button calls `POST /api/v1/mandates/{id}/activate` (visible only when status is `draft` or `pending`)
    - AND an "Intrekken" button opens a dialog for revocation reason capture (visible only when status is `active`)
    - AND a "Ondertekeningslink genereren" button calls `POST /api/v1/mandates/{id}/generate-signing-link` (visible for `pending` mandates)

- [ ] 7.3 Create `src/views/mandate/MandateForm.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-004`
  - **files**: `src/views/mandate/MandateForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with `fieldsFromSchema('mandate')`
    - AND the scheme selector field embeds `MandateSchemeSelector.vue` which dynamically toggles visible fields based on the selected scheme (IBAN/BIC for SEPA; sort code/account number for BACS)
    - AND client-side validation applies `MandateScheme.validationRules` patterns before submission
    - AND saving creates the mandate in `draft` status

## 8. Frontend Views — PO Line Change

- [ ] 8.1 Create `src/views/purchaseOrderLineChange/PoLineChangeIndex.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `src/views/purchaseOrderLineChange/PoLineChangeIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('purchaseOrderLineChange')`
    - AND filter chips for `status`, `changeType`, and `priority` MUST be present
    - AND a "Nieuwe wijziging" button opens `PoLineChangeForm.vue`

- [ ] 8.2 Create `src/views/purchaseOrderLineChange/PoLineChangeDetail.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `src/views/purchaseOrderLineChange/PoLineChangeDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with tabs: Details, Goedkeuring, Leverancier
    - AND the Details tab shows change properties and the parent PO summary
    - AND the Goedkeuring tab links to the internal `ApprovalRequest` detail and shows approval status
    - AND the Leverancier tab embeds `SupplierAcknowledgmentPanel.vue`
    - AND a "Indienen" button calls `POST /api/v1/po-line-changes/{id}/submit` (visible when status is `draft`)
    - AND a "Leverancier notificeren" button calls `POST /api/v1/po-line-changes/{id}/notify-supplier` (visible when status is `approved`)

- [ ] 8.3 Create `src/views/purchaseOrderLineChange/PoLineChangeForm.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `src/views/purchaseOrderLineChange/PoLineChangeForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with `fieldsFromSchema('purchaseOrderLineChange')`
    - AND `purchaseOrderId` field is a searchable selector showing only POs with `orderStatus: approved`
    - AND saving creates the change in `draft` status

## 9. Frontend Components

- [ ] 9.1 Create `src/components/MandateSchemeSelector.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-004`
  - **files**: `src/components/MandateSchemeSelector.vue`
  - **acceptance_criteria**:
    - GIVEN the component renders THEN it shows a dropdown of active `MandateScheme` objects
    - AND on selection it emits `update:modelValue` with the scheme ID and emits `schemeChanged` with the scheme object for the parent form to update visible fields
    - AND SEPA schemes show IBAN + BIC fields; BACS_AUDDIS shows sort code + account number; BETALINGSSERVICE shows FI registration number

- [ ] 9.2 Create `src/components/MandateCollectionTimeline.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `src/components/MandateCollectionTimeline.vue`
  - **acceptance_criteria**:
    - GIVEN a `mandateId` prop is passed THEN all `MandateCollection` objects for the mandate are fetched and displayed in reverse chronological order
    - AND each entry shows: scheduled date, amount, status badge (colour-coded), `pain008BatchRef`, settled/failed date, and `failureReason` if applicable
    - AND a "PAIN.008 exporteren" button is shown for `scheduled` collections (visible to treasurer/admin only)

- [ ] 9.3 Create `src/components/MandateSignatureBadge.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-003`
  - **files**: `src/components/MandateSignatureBadge.vue`
  - **acceptance_criteria**:
    - GIVEN a mandate prop is passed THEN if `signingToken` exists and not expired: shows a "Signing link actief" badge with expiry countdown
    - AND if the mandate has `MandateSignatureEvent` records: shows the most recent signing with signedAt, masked IP (last octet hidden), and channel
    - AND a "Link kopiëren" button copies the signing URL to the clipboard

- [ ] 9.4 Create `src/components/MandatePolicyFindingPanel.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-006`
  - **files**: `src/components/MandatePolicyFindingPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `policyFindings` array prop is passed THEN each finding is displayed as a row with `code` badge, `message` text, and an "Oplossen" button
    - AND if `policyFindings` is empty: shows a green "Alle beleidschecks geslaagd" message
    - AND "Oplossen" opens a dialog for admin override with mandatory justification; on confirm calls `POST /api/v1/mandates/{id}/override-finding`

- [ ] 9.5 Create `src/components/SupplierAcknowledgmentPanel.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-007`
  - **files**: `src/components/SupplierAcknowledgmentPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `poLineChangeId` prop is passed THEN the `SupplierAcknowledgment` for that change is fetched and displayed
    - AND shows: status badge, supplier contact name, `responseDate`, and `responseNotes`
    - AND if `status: pending` and `tokenExpiresAt` is in the past: shows "Link verlopen" warning with a "Opnieuw versturen" button
    - AND if `status: counter-proposed`: shows `counterProposalDetails` in a collapsible block with "Accepteer tegenbod" and "Afwijzen" actions

- [ ] 9.6 Create `src/components/UpcomingCollectionWidget.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-001`
  - **files**: `src/components/UpcomingCollectionWidget.vue`
  - **acceptance_criteria**:
    - GIVEN the widget is embedded in the home dashboard THEN it fetches `MandateCollection` objects with `status: scheduled` and `scheduledDate` within the next 14 days
    - AND lists them sorted by `scheduledDate` ascending showing: mandate reference, debtor name, amount, collection date, and pre-notification status ("Verzonden" in green or "Nog te verzenden" in amber)
    - AND clicking a row navigates to the mandate detail page

- [ ] 9.7 Create `src/components/MandateSignPage.vue`
  - **spec_ref**: `specs/authorization-mandate-management/spec.md#REQ-AMM-003`
  - **files**: `src/components/MandateSignPage.vue`
  - **acceptance_criteria**:
    - GIVEN the component is rendered at the public signing URL (no Nextcloud auth)
    - THEN it fetches mandate summary via `GET /api/v1/mandates/sign/{token}` and displays: UMR, creditor identifier, authorized amount, frequency, masked IBAN, and validity period
    - AND shows an "Ik ga akkoord" button that calls `POST /api/v1/mandates/sign/{token}/accept`
    - AND on success shows: "Uw mandaat is ondertekend. Een bevestigingsmail is verzonden."
    - AND if token is expired (410 response): shows "Deze ondertekeningslink is verlopen. Neem contact op met de afzender voor een nieuwe link."
    - AND does not include any Nextcloud navigation elements; uses NL Design System CSS variables for styling only

## 10. Sidebar Navigation Update

- [ ] 10.1 Add mandate and PO change sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Mandaten" section MUST be present with nav items: Mandaatregister, Incasso's, Mandaatschema's
    - AND the "Mandaatregister" item MUST show a badge with the count of mandates expiring within 90 days
    - AND a "Wijzigingsbeheer" section MUST be present under Inkoop with nav item: Regelwijzigingen (showing count of PO changes pending supplier acknowledgment)

---
status: proposed
---

# Supplier Management — Shillinq

## Purpose

Defines functional requirements for Shillinq's supplier management capabilities: a supplier master data registry with KvK-based onboarding and data validation, certificate and compliance document checklists with expiry tracking, IBAN verification, a structured supplier approval workflow, CPV code assignment, category strategy management with market intelligence views, negotiation event capture with audit trail, and sourcing event management with supplier invitation and response tracking.

Stakeholders: Supplier Onboarding Officer, Supplier Account Manager.

User stories addressed: Register supplier via KvK number, Collect mandatory onboarding documents, Verify supplier IBAN bank account, Approve or reject completed supplier profile, Assign CPV codes and product categories to supplier.

## Requirements

### REQ-SUP-001: SupplierProfile Schema Registration and KvK Auto-population [must]

The app MUST register the `SupplierProfile` schema in OpenRegister via `lib/Settings/shillinq_register.json` using `schema:Organization`. On supplier creation, an 8-digit KvK number lookup against the KvK Handelsregister Open Data API MUST auto-populate company name, legal form, registered address, and SBI codes. Invalid or inactive KvK numbers MUST block registration with a user-facing error. New supplier profiles MUST be created with `qualificationStatus: pending_verification`.

**Scenarios:**

1. **GIVEN** a supplier onboarding form is open **WHEN** the user enters KvK number `12345678` and clicks "Look up" **THEN** `KvkLookupService` calls the KvK Open Data API and the form pre-fills `name: "Acme BV"`, `legalForm: "Besloten Vennootschap"`, `address: "Teststraat 1, 1234AB Amsterdam"`, and `sbiCodes: ["4791"]`; no SupplierProfile is created until the user confirms.

2. **GIVEN** the user enters KvK number `00000000` (not found) **WHEN** the lookup runs **THEN** the form shows "KvK number not found or inactive. Please verify the number and try again." and the form cannot be submitted.

3. **GIVEN** company details are pre-filled from the KvK lookup **WHEN** the user clicks "Save" **THEN** a `SupplierProfile` is created in OpenRegister with `qualificationStatus: pending_verification`, `kvkVerifiedAt` set to the current timestamp, and all KvK-sourced fields stored.

4. **GIVEN** the KvK API is unavailable (timeout or 5xx) **WHEN** the lookup is attempted **THEN** the form shows "KvK lookup unavailable. Please fill in the company details manually." and manual entry is allowed without a KvK verification timestamp.

5. **GIVEN** the same KvK number is looked up within 24 hours **WHEN** the second lookup is triggered **THEN** `KvkLookupService` returns the cached response without a new API call (verified by absence of HTTP request in the audit log).

### REQ-SUP-002: Certificate Checklist and Expiry Tracking [must]

The app MUST register the `SupplierCertification` schema (`schema:Certification`) and provide a configurable checklist of required certification types per supplier category. Approval MUST be blocked when required certifications are missing or expired. A daily background job MUST identify certifications expiring within 30 days and notify the assigned onboarding officer.

**Scenarios:**

1. **GIVEN** a new `SupplierProfile` is created with `supplierCategory: preferred` **WHEN** the profile is saved **THEN** `CertificateChecklistService` generates a checklist based on the AppSettings key `certChecklist.preferred` (e.g. ISO9001, insurance, tax_clearance) and the Certifications tab shows each required type with status `missing`.

2. **GIVEN** a document checklist exists for a supplier **WHEN** a `SupplierCertification` of type `ISO9001` is created and linked to the profile **THEN** the checklist item for `ISO9001` changes from `missing` to `present` with the issuer name and expiry date displayed.

3. **GIVEN** a required certification has `expiryDate` in the past **WHEN** the `CertificateExpiryJob` runs **THEN** `verificationStatus` is set to `expired` and the status on the checklist changes from `present` to `expired` with a red alert.

4. **GIVEN** a required certification expires in 20 days **WHEN** the `CertificateExpiryJob` runs **THEN** the `assignedOfficerId` on the SupplierProfile receives a Nextcloud notification "Certificate ISO9001 for Acme BV expires on {date}" and the checklist row shows an amber warning badge.

5. **GIVEN** at least one required certification has status `missing` or `expired` **WHEN** the onboarding officer attempts to approve the supplier via `POST /api/v1/suppliers/{id}/approve` **THEN** the API returns 422 with `{blocking: [{type:"ISO9001", status:"missing"}]}` and no approval is recorded.

6. **GIVEN** all required certifications are `present` and none are expired **WHEN** the API precondition check runs **THEN** certification is not listed as a blocking item and approval can proceed (subject to other preconditions).

### REQ-SUP-003: IBAN Verification [must]

The app MUST integrate with a configurable IBAN verification endpoint to validate the supplier's IBAN against the registered company name. A mismatch MUST flag the `SupplierProfile` with `ibanVerificationStatus: mismatch` and block approval. Successful verification MUST store the verification result and timestamp.

**Scenarios:**

1. **GIVEN** a supplier has submitted their IBAN `NL91ABNA0417164300` **WHEN** the onboarding officer triggers IBAN verification via `POST /api/v1/suppliers/{id}/verify-iban` **THEN** `IbanVerificationService` calls the configured endpoint with the IBAN and company name, and on match stores `ibanVerificationStatus: verified` and `ibanVerifiedAt: {timestamp}` on the SupplierProfile.

2. **GIVEN** the verification service returns a name mismatch **WHEN** the result is stored **THEN** `ibanVerificationStatus` is set to `mismatch`, the SupplierProfile is flagged with a warning badge in the list view, and the approval panel shows "IBAN mismatch — resolve before approving."

3. **GIVEN** `ibanVerificationStatus` is `mismatch` **WHEN** the approval precondition check runs **THEN** the API returns 422 with `{blocking: [{field:"iban", status:"mismatch"}]}` and no approval is recorded.

4. **GIVEN** the IBAN field on the SupplierProfile is empty **WHEN** `POST /api/v1/suppliers/{id}/verify-iban` is called **THEN** the API returns 422 with "IBAN is required for verification."

5. **GIVEN** IBAN verification succeeds **WHEN** the confirmation is stored **THEN** `ibanVerifiedBy` is set to the userId of the officer who triggered the verification, creating a non-repudiable audit record.

### REQ-SUP-004: Supplier Approval Workflow [must]

The app MUST implement a four-state qualification workflow for SupplierProfile: `pending_verification → qualified | disqualified | suspended`. Each transition MUST require a mandatory justification text and be recorded with actor userId and timestamp. Approval MUST be blocked until all checklist certifications are present and non-expired, and IBAN is verified. Approved suppliers MUST receive an onboarding confirmation notification.

**Scenarios:**

1. **GIVEN** all checklist certifications are present and non-expired and `ibanVerificationStatus: verified` **WHEN** the onboarding officer opens the approval screen **THEN** a summary showing company details, certification checklist statuses, and IBAN verification result is displayed with "Approve" and "Reject" buttons.

2. **GIVEN** the officer clicks "Approve" and enters justification "All documents verified, supplier qualifies for preferred tier" **WHEN** the approval is submitted **THEN** `qualificationStatus` changes to `qualified`, `approvedBy` and `approvedAt` are set, the decision and justification are written to the audit log, and the supplier's `assignedOfficerId` receives a Nextcloud notification "Supplier Acme BV has been approved."

3. **GIVEN** the officer clicks "Reject" without entering a reason **WHEN** the form is submitted **THEN** a validation error "Rejection reason is required" prevents the submission.

4. **GIVEN** the officer clicks "Reject" with reason "Insurance certificate issuer not recognised" **WHEN** the rejection is submitted **THEN** `qualificationStatus` changes to `disqualified`, the rejection reason is stored in `approvalDecision`, and the audit log records actor, timestamp, and reason.

5. **GIVEN** a `qualified` supplier has a certificate expire **WHEN** the `CertificateExpiryJob` marks the certification `expired` **THEN** `qualificationStatus` changes to `suspended` automatically and the `assignedOfficerId` is notified "Supplier Acme BV suspended: ISO9001 certificate expired."

6. **GIVEN** a user with `viewer` CollaborationRole opens the `SupplierApprovalPanel` **WHEN** the panel renders **THEN** the "Approve" and "Reject" buttons are absent; only the current qualification status badge is shown.

### REQ-SUP-005: CPV Code Assignment and Category Strategy Management [must]

The app MUST allow CPV codes to be assigned to approved supplier profiles via a client-side search against a bundled CPV code list. The app MUST register the `CategoryStrategy` schema and provide views for managing category-level spend targets, market intelligence, and supplier landscape analysis. Suppliers MUST appear in CPV-filtered sourcing event shortlists.

**Scenarios:**

1. **GIVEN** an approved SupplierProfile is open **WHEN** the user searches for "office" in the CPV code selector **THEN** `CpvCodeSelector.vue` filters the bundled `cpv-codes.json` and shows matching results such as `30192000 — Office supplies` and `30197000 — Small office equipment`; no network request is made.

2. **GIVEN** the user selects CPV codes `30192000` and `30197000` and saves **WHEN** the save completes **THEN** `SupplierProfile.cpvCodes` contains `[{code:"30192000",description:"Office supplies"},{code:"30197000",description:"Small office equipment"}]` and all assigned codes are displayed on the supplier detail page.

3. **GIVEN** a `CategoryStrategy` exists covering CPV code `30192000` **WHEN** the strategy detail page "Supplier Landscape" tab is opened **THEN** all `SupplierProfile` objects with `cpvCodes` containing `30192000` are listed with their `supplierCategory`, `qualificationStatus`, and `spendYTD`.

4. **GIVEN** a `SourcingEvent` with `cpvCodes: ["30192000"]` is open **WHEN** the procurement manager opens the "Invite Suppliers" panel **THEN** the supplier search is pre-filtered to show only SupplierProfiles with `cpvCodes` containing `30192000` and `qualificationStatus: qualified`.

5. **GIVEN** a procurement manager updates `CategoryStrategy.spendTarget` to `100000` and `marketIntelligence` text **WHEN** the update is saved **THEN** the values are persisted to OpenRegister and visible on the strategy overview tab immediately.

### REQ-SUP-006: Negotiation Event Capture and Audit Trail [must]

The app MUST register the `NegotiationEvent` schema (`schema:Action`) and provide an append-only timeline view of negotiation steps per supplier. Each event MUST record event type, amount, currency, actor role, actor userId, and creation timestamp. Deletion and editing of existing events MUST NOT be permitted.

**Scenarios:**

1. **GIVEN** the onboarding officer opens the "Negotiations" tab on a SupplierProfile **WHEN** they click "Add Event" and select event type `offer`, amount `45000`, currency `EUR`, valid until `2026-04-30`, notes "Initial offer Q2 2026" **THEN** a `NegotiationEvent` is created with `actorRole: buyer`, `actorId: {officerUserId}`, and `createdAt` set to the current timestamp; it appears at the top of the timeline.

2. **GIVEN** a `NegotiationEvent` exists in the timeline **WHEN** a user attempts to edit the event record via the OpenRegister API **THEN** the `NegotiationEvent` controller returns 405 Method Not Allowed; no edit endpoint is registered for this schema.

3. **GIVEN** the supplier submits a counter-offer via the portal **WHEN** the counter-offer is recorded **THEN** a new `NegotiationEvent` of type `counter_offer` is appended with `actorRole: supplier` and the proposed amount; the previous offer event remains unchanged.

4. **GIVEN** a negotiation timeline contains 3 events: offer, counter_offer, acceptance **WHEN** the timeline is rendered **THEN** events are displayed in reverse chronological order (acceptance first) with appropriate NL Design System colour coding: offer (blue), counter_offer (amber), acceptance (green), rejection (red).

5. **GIVEN** a `NegotiationEvent` is linked to a `SourcingEvent` via `sourcingEventId` **WHEN** the SourcingEvent detail page "Responses" tab is viewed **THEN** the negotiation events for that sourcing event are accessible via a "View Negotiations" link to the supplier's negotiation timeline.

### REQ-SUP-007: Sourcing Event Management with Invitation and Response Tracking [must]

The app MUST register the `SourcingEvent` schema (`schema:Event`) and `SourcingEventResponse` schema. Procurement managers MUST be able to create RFI, RFQ, and RFP events, invite suppliers filtered by CPV code, and track each supplier's response status. Invitations MUST trigger Nextcloud notifications. Supplier responses MUST support file attachments via the document attachment mechanism.

**Scenarios:**

1. **GIVEN** a procurement manager creates a `SourcingEvent` of type `RFQ` with title "RFQ — Office Supplies Q3 2026", CPV codes `["30192000"]`, and `responseDeadline: 2026-05-31` **WHEN** the event is saved with `status: draft` **THEN** the event appears in the sourcing event list and no invitations are sent.

2. **GIVEN** the event is in `draft` status **WHEN** the manager clicks "Publish" **THEN** `SourcingEvent.status` changes to `published`, `publishedAt` is set to the current timestamp, and the event is visible to invited suppliers via the portal.

3. **GIVEN** the manager opens the "Invite Suppliers" panel and selects Acme BV **WHEN** they click "Send Invitation" **THEN** `POST /api/v1/sourcing-events/{id}/invite` is called, a `SourcingEventResponse` with `status: invited` and `invitedAt: {timestamp}` is created for Acme BV, and the `assignedOfficerId` for Acme BV receives a Nextcloud notification "You have been invited to respond to RFQ — Office Supplies Q3 2026."

4. **GIVEN** a supplier opens the sourcing event via the portal **WHEN** they submit a response with text and an attached PDF **THEN** a `Document` is created linked to the `SourcingEventResponse`, `SourcingEventResponse.status` changes to `responded`, and `respondedAt` is set to the current timestamp.

5. **GIVEN** a supplier was already invited (status `invited`) **WHEN** the manager calls invite again for the same supplier **THEN** the API silently ignores the duplicate and returns 200 without creating a second `SourcingEventResponse`.

6. **GIVEN** the `responseDeadline` has passed **WHEN** a supplier attempts to submit a response via the portal **THEN** the portal shows "The response deadline for this event has passed." and submission is blocked.

7. **GIVEN** the manager clicks "Close Event" on a published event **THEN** `SourcingEvent.status` changes to `closed`, `closedAt` is set, and all invited suppliers with `status: invited` receive a notification "Sourcing event RFQ — Office Supplies Q3 2026 has been closed."

### REQ-SUP-008: Supplier Risk Monitoring [should]

The app MUST maintain a `riskLevel` field on `SupplierProfile` (low / medium / high / critical) recalculated weekly by a background job using configurable scoring weights. Onboarding officers MUST be notified when a supplier's risk level increases.

**Scenarios:**

1. **GIVEN** a SupplierProfile has `ibanVerificationStatus: unverified` (+1 point) and 1 expired certification (+2 points) **WHEN** `SupplierRiskJob` runs **THEN** the total score is 3 → `riskLevel` is set to `high` and the stored value is updated in OpenRegister.

2. **GIVEN** a supplier's `riskLevel` was `low` and `SupplierRiskJob` recalculates it as `medium` **WHEN** the job completes **THEN** `assignedOfficerId` receives a Nextcloud notification "Risk level increased for Acme BV: low → medium."

3. **GIVEN** a supplier's `riskLevel` remains unchanged after recalculation **WHEN** the job completes **THEN** no notification is sent and no OpenRegister update is performed (idempotent).

4. **GIVEN** the admin changes the scoring weight for "unverified IBAN" from 1 to 3 in AppSettings **WHEN** the next job run occurs **THEN** the new weights are applied and risk levels are recalculated accordingly.

### REQ-SUP-009: Seed Data [must]

The app MUST load demo seed data for all new schemas via the repair step. Seed data MUST be idempotent — running the repair step multiple times MUST NOT create duplicate records.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** all seed records are created: 2 SupplierProfiles, 3 SupplierCertifications, 1 CategoryStrategy, 3 NegotiationEvents, 1 SourcingEvent, 2 SourcingEventResponses.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate records are created; idempotency keys (`kvkNumber`, composite `(supplierProfileId, certificationType)`, `categoryName`, `(supplierProfileId, eventType, createdAt)`, `title`) are checked before insertion.

3. **GIVEN** the seed SourcingEvent "RFQ — Office Supplies Q3 2026" is created **WHEN** the seed data is loaded **THEN** the two SourcingEventResponse seed records reference the correct SupplierProfile IDs for Acme BV (status `responded`) and Beta Supplies BV (status `invited`).

4. **GIVEN** the seed SupplierProfile for Acme BV is created **WHEN** the seed data is loaded **THEN** all 3 NegotiationEvent seed records are linked to the Acme BV SupplierProfile object ID resolved at seed time (not hard-coded).

---
status: proposed
---

# Authorization & Mandate Management — Shillinq

## Purpose

Defines functional requirements for Shillinq's authorization and mandate management capabilities: a structured mandate register covering SEPA Core, SEPA B2B, electronic, and multi-scheme mandates with full lifecycle management; a PAIN.008 export for bank submission; a digital mandate signing flow; purchase order change management with internal approval and supplier acknowledgment workflow; and a compliance policy engine that enforces organization-defined rules on mandates and PO changes.

Stakeholders: Treasurer, Group Controller, Customer.

User stories addressed: Create a new financial mandate entry, Assign a mandate to a named person, Export the mandate register for audit, Archive expired mandate entries, Review mandate register for compliance completeness.

## Requirements

### REQ-AMM-001: Mandate Schema Registration and Lifecycle [must]

The app MUST register the `Mandate` schema (`schema:Authorization`), `MandateScheme` schema (`schema:Thing`), and `MandateCollection` schema (`schema:PaymentChargeSpecification`) in OpenRegister via `lib/Settings/shillinq_register.json`. A `Mandate` MUST progress through statuses `draft → pending → active → expired | revoked`. Only one `active` mandate per debtor-creditor-scheme combination is allowed (enforced by `MandatePolicyService`). A `MandateScheme` MUST define the minimum pre-notification days and maximum collection amount for its scheme.

**Scenarios:**

1. **GIVEN** a treasurer opens the mandate list **WHEN** they create a new `Mandate` with `type: SEPA_CORE`, `authorizedAmount: 2500`, `frequency: monthly`, and `expiryDate: 2027-01-12` **THEN** the mandate is saved with `status: draft`, a generated `mandateId`, and `createdDate` set to now; no error is returned.

2. **GIVEN** a mandate in `draft` status has passed all `MandatePolicyService` checks **WHEN** `POST /api/v1/mandates/{id}/activate` is called **THEN** `status` changes to `active`, `signatureDate` is set to now if not already present, `nextCollectionDate` is computed by `MandateLifecycleService` based on the scheme frequency, and the treasurer receives a Nextcloud notification "Mandaat {reference} is geactiveerd."

3. **GIVEN** a `Mandate` has `frequency: monthly` and `nextCollectionDate: 2026-05-01` **WHEN** a `MandateCollection` for that date is settled **THEN** `MandateLifecycleService.advanceCollectionDate()` sets `nextCollectionDate` to `2026-06-01`.

4. **GIVEN** a treasurer calls `POST /api/v1/mandates/{id}/revoke` with `{reason: "Klant heeft service opgezegd"}` **WHEN** the mandate is in `active` status **THEN** `status` changes to `revoked`, `revocationReason` is stored, and the mandate owner receives a notification.

5. **GIVEN** a `MandateScheme` has `minPreNotificationDays: 5` **WHEN** a `MandateCollection` is scheduled for `2026-05-01` **THEN** `MandateLifecycleService` calculates the pre-notification date as `2026-04-25` and stores it; the treasurer is notified if the pre-notification has not been sent by that date.

6. **GIVEN** `MandateExpiryJob` runs on `2026-07-09` **WHEN** a `Mandate` has `expiryDate: 2026-07-08` and `status: active` **THEN** `status` is changed to `expired`, the mandate owner receives a notification, and an `AuditTrail` entry is created with `actor: system` and `action: mandate_expired`.

7. **GIVEN** the start of fiscal year or any day the treasurer opens the mandate register **WHEN** any mandate has `expiryDate` within the next 90 days **THEN** a banner shows "X mandaten verlopen binnen 90 dagen" with a link to the filtered mandate list.

### REQ-AMM-002: SEPA PAIN.008 Export [must]

The app MUST provide a `PainExportService` that generates a valid PAIN.008 XML file for a given collection date. IBAN, BIC, and UMR values MUST be validated before export. The export MUST support both SDD Core and SDD B2B service levels. Only users with the `treasurer` or `admin` CollaborationRole may access the export endpoint.

**Scenarios:**

1. **GIVEN** three `MandateCollection` objects exist with `scheduledDate: 2026-05-01` and `status: scheduled` **WHEN** `GET /api/v1/mandates/export/pain008?collectionDate=2026-05-01&schemeCode=SEPA_CORE` is called by a user with `treasurer` role **THEN** a valid PAIN.008 XML file is returned with one `PmtInf` block per mandate, each containing the correct IBAN, BIC, UMR, creditor identifier, and amount.

2. **GIVEN** a `MandateCollection` references a mandate with an invalid IBAN `NL00INVALID` **WHEN** the PAIN.008 export is requested **THEN** the API returns 422 with "Ongeldig IBAN voor mandaat {mandateId}: NL00INVALID."

3. **GIVEN** a user without the `treasurer` or `admin` role calls the PAIN.008 export endpoint **WHEN** the request is processed **THEN** the API returns 403 "Toegang geweigerd: alleen penningmeesters en beheerders kunnen PAIN.008 exporteren."

4. **GIVEN** a PAIN.008 export is generated for collection date `2026-05-01` **WHEN** the export completes **THEN** all included `MandateCollection` objects have `status` updated to `submitted` and `submittedAt` set to the current timestamp.

### REQ-AMM-003: Digital Mandate Signing Flow [must]

The app MUST provide a digital signing flow for mandates via a time-limited shareable link. The signing page MUST be accessible without Nextcloud authentication. On acceptance the app MUST record a `MandateSignatureEvent` with IP address, user-agent, and timestamp, and generate a PDF evidence document. The signing token MUST be a 256-bit CSPRNG value with a 72-hour TTL.

**Scenarios:**

1. **GIVEN** a `Mandate` is in `pending` status **WHEN** `POST /api/v1/mandates/{id}/generate-signing-link` is called **THEN** a unique signing URL is returned (e.g. `https://instance/apps/shillinq/mandate/sign/{token}`), `signingToken` is stored as a bcrypt hash on the mandate, and `signingTokenExpiresAt` is set to 72 hours from now.

2. **GIVEN** a counterpart opens the signing URL and views the mandate summary **WHEN** they click "Ik ga akkoord" **THEN** a `MandateSignatureEvent` is created with `signedAt` (UTC), `ipAddress` (of the requester), `userAgent`, and `channel: web_link`; `Mandate.status` changes to `active`; `signatureDate` is set to now.

3. **GIVEN** a counterpart opens the signing URL after the 72-hour token has expired **WHEN** the page loads **THEN** the response is 410 "Deze ondertekeningslink is verlopen. Neem contact op met de afzender voor een nieuwe link."

4. **GIVEN** the signing is accepted **WHEN** the `MandateSignatureEvent` is created **THEN** a PDF evidence document is generated containing the mandate summary, the signatory's IP address, browser, and timestamp, and stored as a Nextcloud file linked to the mandate via the document attachment mechanism.

5. **GIVEN** the `MandateSignPage.vue` renders for a valid token **WHEN** the scheme is `SEPA_CORE` **THEN** the page displays the mandate reference (UMR), creditor identifier, authorized amount, frequency, and debtor's masked IBAN in a clean read-only layout with Shillinq branding.

### REQ-AMM-004: Multi-Scheme Mandate Support [must]

The app MUST support mandate creation for any active `MandateScheme`. The mandate form MUST dynamically display scheme-specific fields based on the selected scheme. `MandateScheme.validationRules` MUST be applied to field values before submission.

**Scenarios:**

1. **GIVEN** a `MandateScheme` with `schemeCode: BACS_AUDDIS` exists **WHEN** a user selects it in the mandate form **THEN** UK sort code (6 digits) and account number (8 digits) fields are shown instead of IBAN and BIC; the `country` field defaults to `GB`.

2. **GIVEN** a `MandateScheme` has a `validationRules.reference` pattern `^[A-Za-z0-9+?/:().,\- ]{1,35}$` **WHEN** a user enters a reference with a forbidden character (e.g. `#`) **THEN** a client-side validation error "Ongeldige mandaatverwijzing" is shown and form submission is blocked.

3. **GIVEN** multiple `MandateScheme` objects exist with `isActive: true` **WHEN** the treasurer opens the mandate form **THEN** only active schemes are listed in the scheme selector; deactivated schemes are not shown.

4. **GIVEN** a mandate is created for `schemeCode: BETALINGSSERVICE` **WHEN** the scheme's `maxCollectionAmount: 5000` **AND** `authorizedAmount: 7000` is entered **THEN** a validation error "Bedrag overschrijdt het schema-maximum van EUR 5.000" is shown.

### REQ-AMM-005: Mandate Register Export for Audit [must]

The app MUST provide a register export in PDF or XLSX format, with support for active-only or full-history scope. Each output MUST include the generation date, authorising officer name, and a version number on every page. Only users with the `head_of_finance` or `admin` CollaborationRole may trigger the export.

**Scenarios:**

1. **GIVEN** the mandate register contains 5 active and 3 historical (expired or revoked) mandates **WHEN** `GET /api/v1/mandates/export?format=pdf&scope=active` is called **THEN** the returned PDF contains only the 5 active mandates with all required fields and a page header including "Gegenereerd op: 2026-04-09 | Autoriserende functionaris: J. de Vries | Versie: 7."

2. **GIVEN** `GET /api/v1/mandates/export?format=xlsx&scope=all` is called **THEN** the XLSX file contains two sheets: "Actieve mandaten" and "Historische mandaten," each with columns for mandate ID, type, status, authorized amount, debtor (masked IBAN), creditor, reference, valid from, valid to, and last modified.

3. **GIVEN** a user without the `head_of_finance` or `admin` role calls the export endpoint **THEN** the API returns 403 "Alleen het hoofd financiën en beheerders kunnen het mandaatregister exporteren."

4. **GIVEN** the export is generated **WHEN** a subsequent export is requested **THEN** the version number in the header is incremented by 1 from the last recorded export version (stored in `AppSettings`).

### REQ-AMM-006: Mandate Completeness and Compliance Check [should]

The app MUST provide a `MandatePolicyService` that checks mandates against configurable policy rules. A completeness check MUST verify that every active organizational role has a mandate for each mandatory cost category. The completeness report MUST be exportable as a management summary.

**Scenarios:**

1. **GIVEN** a `Mandate` is submitted for activation with an `authorizedAmount` exceeding the policy ceiling for its type **WHEN** `MandatePolicyService` evaluates it **THEN** a finding `MAX_AMOUNT_EXCEEDED` is added to `policyFindings` and activation is blocked until the finding is resolved or overridden by an admin with a documented justification.

2. **GIVEN** a new mandate has the same debtor contact ID, creditor organization ID, and scheme code as an existing `active` mandate **WHEN** `MandatePolicyService` runs **THEN** a finding `DUPLICATE_MANDATE` is returned with the ID of the conflicting mandate; the treasurer is warned before saving.

3. **GIVEN** a compliance officer triggers the completeness check **WHEN** the system analyses active mandates against the organizational role list from `Organization` objects **THEN** a report lists every role-cost-category combination that lacks an active mandate, formatted as a management summary with totals.

4. **GIVEN** the completeness report is generated **WHEN** the compliance officer chooses to export it **THEN** the output is a PDF titled "Volledigheidsrapportage Mandaatregister" with the generation date, officer name, and a table of gaps with columns: Role, Cost Category, Status, Action Required.

5. **GIVEN** a `PurchaseOrderLineChange` exceeds the policy ceiling for the requesting role **WHEN** the change is submitted for internal approval **THEN** `MandatePolicyService` adds finding `ROLE_CEILING_EXCEEDED` to the `ApprovalRequest` notes and the approval is escalated to the next role with sufficient mandate.

### REQ-AMM-007: PO Change Management with Supplier Notification and Acknowledgment [must]

The app MUST register the `PurchaseOrderLineChange` schema (`schema:Thing`) and `SupplierAcknowledgment` schema (`schema:Thing`). A change request MUST progress through internal approval before a supplier notification is sent. The supplier MUST be able to accept, reject, or counter-propose via a token-authenticated public URL. On supplier acceptance the `PurchaseOrder` lines MUST be updated automatically.

**Scenarios:**

1. **GIVEN** a procurement officer creates a `PurchaseOrderLineChange` with `changeType: quantity`, `description: "Hoeveelheid van 10 naar 15 stuks"`, and `impactAmount: 1250` **WHEN** they submit it **THEN** an `ApprovalRequest` is created via `WorkflowRoutingService` for the change, and the change `status` moves to `submitted`.

2. **GIVEN** a `PurchaseOrderLineChange` has `status: approved` after internal approval **WHEN** `POST /api/v1/po-line-changes/{id}/notify-supplier` is called **THEN** an email is sent to the supplier contact with a unique `acknowledgmentToken` link; `supplierNotifiedAt` is recorded; a `SupplierAcknowledgment` object is created with `status: pending`.

3. **GIVEN** the supplier opens the acknowledgment URL and clicks "Accepteren" **WHEN** `POST /api/v1/po-line-changes/acknowledge/{token}/accept` is called **THEN** `SupplierAcknowledgment.status` changes to `accepted`, `responseDate` is set to now, the `PurchaseOrderLineChange.status` moves to `acknowledged`, and the `PurchaseOrder` lines and totals are updated to reflect the change.

4. **GIVEN** the supplier clicks "Afwijzen" on the acknowledgment page **WHEN** `POST /api/v1/po-line-changes/acknowledge/{token}/reject` is called with `{responseNotes: "Hoeveelheid niet beschikbaar in gevraagde termijn"}` **THEN** `SupplierAcknowledgment.status` changes to `rejected`, `PurchaseOrderLineChange.status` returns to `draft` for revision, and the procurement officer receives a notification with the supplier's reason.

5. **GIVEN** a supplier opens an acknowledgment link more than 30 days after it was generated **WHEN** the page loads **THEN** the response is 410 "Deze bevestigingslink is verlopen. Neem contact op met de inkoper."

6. **GIVEN** a `PurchaseOrderLineChange` with `status: acknowledged` exists **WHEN** the procurement officer views the PO detail page **THEN** the change history tab shows all `PurchaseOrderLineChange` and `SupplierAcknowledgment` records for the PO in reverse chronological order, with status badges and impact amounts.

### REQ-AMM-008: Mandate and PO Change Audit Trail [must]

Every status transition on a `Mandate` or `PurchaseOrderLineChange`, every `MandateSignatureEvent`, and every `SupplierAcknowledgment` response MUST be written to the `AuditTrail` schema via OpenRegister. Audit records MUST be immutable and visible to users with the `auditor` or `admin` CollaborationRole.

**Scenarios:**

1. **GIVEN** a `Mandate` transitions from `pending` to `active` **WHEN** the activation is recorded **THEN** an `AuditTrail` entry is created with `actor: {userId}`, `action: mandate_activated`, `targetType: mandate`, `targetId: {mandateId}`, `timestamp: now`, `details: {signatureDate, creditorIdentifier, reference}`.

2. **GIVEN** a `MandateSignatureEvent` is recorded **WHEN** the signing is confirmed **THEN** an `AuditTrail` entry is created with `actor: system`, `action: mandate_signed`, `targetId: {mandateId}`, `details: {ipAddress, userAgent, channel}`.

3. **GIVEN** a `SupplierAcknowledgment` response is received **WHEN** the acknowledgment is recorded **THEN** an `AuditTrail` entry is created with `actor: system`, `action: supplier_acknowledged`, `targetType: purchaseOrderLineChange`, `targetId: {changeId}`, `details: {status, responseNotes}`.

4. **GIVEN** a `MandatePolicyService` check produces findings **WHEN** the check completes **THEN** an `AuditTrail` entry is created per finding with `actor: system`, `action: policy_check`, `targetType: mandate`, `details: {findingCode, findingDescription}`.

### REQ-AMM-009: Seed Data [must]

The app MUST load seed data for all new schemas via the repair step idempotently (ADR-016). Seed data MUST include Dutch-language example values with all required fields populated.

**Scenarios:**

1. **GIVEN** the Shillinq app is installed or the repair step is run **WHEN** no seed mandates exist **THEN** 2 `MandateScheme` objects (SEPA_CORE, SEPA_B2B), 3 `Mandate` objects, 3 `MandateCollection` objects, 3 `PurchaseOrderLineChange` objects, and 2 `SupplierAcknowledgment` objects are created as defined in the design seed data.

2. **GIVEN** the repair step is run a second time **WHEN** seed objects already exist **THEN** no duplicate objects are created; idempotency is enforced using the natural keys defined in the tasks.

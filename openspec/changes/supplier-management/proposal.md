---
status: proposed
source: specter
features: [supplier-document-management-certificate-compliance-tracking, supplier-information-management-self-service-profile-updates, category-strategy-management-market-intelligence, supplier-negotiation-tools-audit-trail, sourcing-event-management-supplier-invitation-response-tracking]
---

# Supplier Management — Shillinq

## Summary

Implements supplier master data management for Shillinq: a centralised supplier registry with KvK-based onboarding, mandatory document and certification checklists, IBAN verification, a structured approval workflow, CPV code assignment, category strategy management with supplier landscape analysis, negotiation tools with counter-offer audit trail, and sourcing event management with invitation and response tracking. These capabilities address the five highest-demand supplier-management features identified in the Specter intelligence model and build on the core, access-control-authorisation, collaboration, and document-management infrastructure changes.

## Demand Evidence

Top features by market demand score:

- **Supplier document management with certificate and compliance tracking** (demand: 2467) — the top-ranked supplier-management feature. Procurement teams must keep certificates (ISO, UEA, insurance, tax clearance) on file, flag expiry, and block supplier approval when mandatory documents are missing.
- **Supplier information management with self-service profile updates and data validation** (demand: 1732) — supplier onboarding officers need a single authoritative supplier profile auto-populated from the Dutch KvK Handelsregister, with suppliers able to maintain their own data through a self-service portal validated against the register.
- **Category strategy management with market intelligence and supplier landscape analysis** (demand: 1730) — procurement managers need to group suppliers by CPV category, track spend per category, visualise the supplier landscape (preferred / approved / probation / blocked), and record category-level notes and strategy targets.
- **Supplier negotiation tools with counter-offer management and audit trail** (demand: 1708) — procurement officers conduct price negotiations through Shillinq; every offer, counter-offer, acceptance, and rejection must be timestamped and attributed to the acting user to satisfy audit requirements.
- **Sourcing event management with supplier invitation and response tracking** (demand: 1688) — procurement teams create RFx events (RFI, RFQ, RFP), invite shortlisted suppliers, track invitation acceptance or decline, and record supplier responses with version history.

Key stakeholder pain points addressed:

- **Supplier Onboarding Officer**: manual cross-referencing of UBO registers and Justis exclusion databases, expired certificates not flagged automatically, suppliers submitting incomplete ESPD forms — addressed by automated document checklists, expiry alerts, and KvK auto-population.
- **Supplier Account Manager**: complex and repetitive ESPD forms across contracting authorities, unclear tender requirements, short response windows, difficulty tracking submission status — addressed by the self-service portal, sourcing event notifications, and response status tracking.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, and document-management changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `AccessControl`, `Comment`, `CollaborationRole`, `Document`, `DocumentVersion`, `SupplierQuestionnaire`, `QuestionnaireResponse`, `SupplierPortalProfile`, `ContractTemplate`, `ContractClause`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- Document attachment panel and supplier portal token mechanism

### What Is Missing

- No `SupplierProfile` schema for qualification status, risk level, and spend aggregates
- No `SupplierCertification` schema for certificate lifecycle and expiry tracking
- No `NegotiationEvent` schema for offer/counter-offer audit trail
- No `SourcingEvent` schema for RFx creation, supplier invitation, and response collection
- No `CategoryStrategy` schema for CPV-level market intelligence and spend targets
- No KvK Handelsregister API integration for auto-population of company details
- No IBAN verification service integration
- No certificate expiry background job or alert mechanism
- No CPV code assignment or supplier-category discovery views

## Scope

### In Scope

1. **SupplierProfile Schema** — OpenRegister `SupplierProfile` schema (`schema:Organization`) extending the base `Organization` with qualification status, risk level, supplier category, spend YTD, contract count, last evaluation date, and onboarded date. Views at `src/views/supplierProfile/`; store at `src/store/modules/supplierProfile.js`.

2. **SupplierCertification Schema** — OpenRegister `SupplierCertification` schema (`schema:Certification`) for certificates and compliance documents attached to a supplier: certificate type (ISO9001, ISO27001, UEA, insurance, tax clearance, sustainability, other), issuer, issue date, expiry date, and verification status. Views at `src/views/supplierCertification/`; store at `src/store/modules/supplierCertification.js`.

3. **KvK Handelsregister Auto-population** — on supplier creation, an 8-digit KvK number entry triggers a lookup against the KvK Handelsregister Open Data API; company name, legal form, registered address, and SBI codes are pre-filled. The lookup is performed by `lib/Service/KvkLookupService.php` using Nextcloud's `IClientService` (no new Composer packages). Invalid or inactive KvK numbers return a user-facing error and block registration.

4. **IBAN Verification** — `lib/Service/IbanVerificationService.php` validates supplier IBAN against the registered company name via a configurable verification endpoint (admin-configurable URL in AppSettings). Mismatch flags the `SupplierProfile` with `ibanVerificationStatus: mismatch` and blocks approval. Successful verification stores result and timestamp on the profile.

5. **Certificate Checklist and Expiry Tracking** — per supplier category (preferred, approved, probation), a configurable checklist of required `SupplierCertification` types is defined in `AppSettings`. A background job (`lib/BackgroundJob/CertificateExpiryJob.php`) runs daily, identifies certifications expiring within 30 days or already expired, and sends Nextcloud notifications to the assigned onboarding officer. Approval is blocked when required certifications are missing or expired.

6. **Supplier Approval Workflow** — a four-state qualification workflow: `pending_verification → qualified | disqualified | suspended`. Transitions are guarded by the `AccessControl` service; approvals require `reviewer` or `approver` CollaborationRole. Each decision records the actor userId, timestamp, and mandatory justification text. Approved suppliers receive an onboarding confirmation notification.

7. **CPV Code Assignment** — procurement managers assign EU CPV codes to approved suppliers. A `cpvCodes` array property on `SupplierProfile` stores selected CPV objects (code + description). A `CpvCodeSelector.vue` component provides keyword and code-number search against a bundled CPV code list (JSON file, no external API dependency). Suppliers appear in CPV-filtered sourcing event shortlists.

8. **CategoryStrategy Schema and Views** — `CategoryStrategy` schema (`schema:Thing`) groups suppliers by CPV category with fields for strategy notes, spend target, strategic importance, and market intelligence text. Views at `src/views/categoryStrategy/`; store at `src/store/modules/categoryStrategy.js`. The category detail page shows a supplier landscape table filtered by CPV codes matching the category.

9. **NegotiationEvent Schema and Audit Trail** — `NegotiationEvent` schema (`schema:Action`) records each negotiation step between a procurement officer and a supplier: event type (offer, counter_offer, acceptance, rejection), amount, currency, valid until date, notes, and actor. Each event is append-only. Views at `src/views/negotiationEvent/`; store at `src/store/modules/negotiationEvent.js`. The negotiation timeline is embedded in `SupplierProfile` detail under a "Negotiations" tab.

10. **SourcingEvent Schema and Invitation Tracking** — `SourcingEvent` schema (`schema:Event`) supports RFI, RFQ, and RFP event types. Procurement managers create events, add a supplier shortlist, and send invitations via Nextcloud notifications. Suppliers respond via the self-service portal. `SourcingEventResponse` schema records each supplier's response with status (invited, accepted, declined, responded, evaluated) and attached documents. Views at `src/views/sourcingEvent/`; store at `src/store/modules/sourcingEvent.js`.

11. **Supplier Risk Monitoring** — `riskLevel` (low / medium / high / critical) on `SupplierProfile` is computed from a configurable risk scoring formula: financial health indicators, certificate expiry status, past performance score, and spend concentration. A background job (`lib/BackgroundJob/SupplierRiskJob.php`) recalculates scores weekly and notifies onboarding officers when a supplier risk level increases.

12. **Seed Data** — demo records for all new schemas (ADR-016): 2 SupplierProfiles, 3 SupplierCertifications, 1 CategoryStrategy, 3 NegotiationEvents, 1 SourcingEvent with 2 SourcingEventResponses. Loaded via the repair step idempotently.

### Out of Scope

- Full UBL/Peppol supplier message exchange — deferred to procurement change
- Supplier performance scorecards with weighted KPI calculation — deferred
- Automated Justis exclusion database lookup — requires government API agreement, deferred
- Multi-level approval chains (delegation, deputies) — handled by ApprovalWorkflow change
- Payment method management (ACH, virtual cards) — deferred to accounts payable change
- Public supplier registration without portal token — security risk, deferred

## Acceptance Criteria

1. GIVEN a supplier onboarding form is open WHEN a valid 8-digit KvK number is entered THEN company name, legal form, registered address, and SBI codes are pre-filled from the KvK API and a draft `SupplierProfile` is created with `qualificationStatus: pending_verification`
2. GIVEN a supplier category is assigned to a new profile WHEN the profile is saved THEN the system generates a document checklist of required `SupplierCertification` types based on the category configuration
3. GIVEN a supplier submits their IBAN WHEN IBAN verification is triggered THEN the result and timestamp are stored on the `SupplierProfile`; a mismatch sets `ibanVerificationStatus: mismatch` and blocks approval
4. GIVEN all checklist items are complete and IBAN is verified WHEN the onboarding officer approves THEN `qualificationStatus` changes to `qualified`, the decision is logged with actor and justification, and the supplier receives a Nextcloud notification
5. GIVEN CPV codes are assigned to an approved supplier WHEN a sourcing event is filtered by CPV category THEN the supplier appears in the shortlist for that event
6. GIVEN a `SupplierCertification` is expiring within 30 days WHEN the daily background job runs THEN the onboarding officer assigned to that supplier receives a Nextcloud expiry alert notification

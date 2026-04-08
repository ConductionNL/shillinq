# Design: Supplier Management — Shillinq

## Architecture Overview

This change adds supplier master data management, certificate compliance tracking, category strategy management, negotiation event capture, and sourcing event management on top of the core, access-control-authorisation, collaboration, and document-management infrastructure. All new entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. The KvK lookup and IBAN verification are outbound HTTP calls made by PHP services using Nextcloud's `IClientService`.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (SupplierProfile, SupplierCertification,
    │                          CategoryStrategy, NegotiationEvent,
    │                          SourcingEvent, SourcingEventResponse CRUD)
    │
    └─ Shillinq OCS API
            ├─ SupplierController        (KvK lookup, IBAN verify, approval transitions)
            ├─ SupplierCertificationController (checklist status, expiry check)
            ├─ SourcingEventController   (invite suppliers, record responses)
            └─ NegotiationEventController (append negotiation steps)
                    │
                    └─ PHP Services
                            ├─ KvkLookupService
                            ├─ IbanVerificationService
                            ├─ CertificateChecklistService
                            └─ SupplierApprovalService
                    │
                    └─ Background Jobs
                            ├─ CertificateExpiryJob  (daily)
                            └─ SupplierRiskJob       (weekly)
```

## Data Model

### SupplierProfile (`schema:Organization`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| organizationId | string | Yes | — | OpenRegister object ID of the parent Organization |
| supplierCategory | string | Yes | — | Enum: preferred / approved / probation / blocked |
| qualificationStatus | string | Yes | pending_verification | Enum: pending_verification / qualified / disqualified / suspended |
| riskLevel | string | No | low | Enum: low / medium / high / critical |
| kvkNumber | string | No | — | 8-digit KvK Handelsregister number |
| kvkVerifiedAt | datetime | No | — | Timestamp of last successful KvK lookup |
| iban | string | No | — | Supplier IBAN |
| ibanVerificationStatus | string | No | — | Enum: unverified / verified / mismatch |
| ibanVerifiedAt | datetime | No | — | Timestamp of IBAN verification |
| ibanVerifiedBy | string | No | — | userId who triggered verification |
| cpvCodes | array | No | [] | Array of {code: string, description: string} objects |
| sbiCodes | array | No | [] | SBI sector codes from KvK |
| spendYTD | number | No | — | Year-to-date spend with this supplier (EUR) |
| contractCount | integer | No | 0 | Number of active contracts |
| lastEvaluationDate | datetime | No | — | Date of last performance evaluation |
| onboardedAt | datetime | No | — | Timestamp when supplier was first qualified |
| assignedOfficerId | string | No | — | userId of the assigned onboarding officer |
| approvalDecision | string | No | — | Last approval decision text / justification |
| approvedBy | string | No | — | userId who last approved or rejected |
| approvedAt | datetime | No | — | Timestamp of last approval decision |
| notes | string | No | — | Internal notes visible to onboarding officers only |

### SupplierCertification (`schema:Certification`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| supplierProfileId | string | Yes | — | OpenRegister object ID of the parent SupplierProfile |
| certificationType | string | Yes | — | Enum: ISO9001 / ISO27001 / UEA / insurance / tax_clearance / sustainability / other |
| certificateNumber | string | No | — | Certificate reference number from issuer |
| issuer | string | Yes | — | Issuing organisation name |
| issuedDate | datetime | Yes | — | Date certificate was issued |
| expiryDate | datetime | Yes | — | Date certificate expires |
| verificationStatus | string | Yes | pending | Enum: verified / pending / expired / revoked |
| verifiedAt | datetime | No | — | Timestamp when the certificate was verified |
| verifiedBy | string | No | — | userId who verified the certificate |
| documentId | string | No | — | OpenRegister object ID of the linked Document (uploaded PDF) |
| notes | string | No | — | Reviewer notes |

### CategoryStrategy (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| categoryName | string | Yes | — | Human-readable category label |
| cpvCodes | array | Yes | — | Array of CPV code strings this strategy covers |
| strategicImportance | string | Yes | — | Enum: critical / high / medium / low |
| spendTarget | number | No | — | Annual spend target for this category (EUR) |
| spendActualYTD | number | No | — | Actual YTD spend across all suppliers in category |
| supplierCount | integer | No | 0 | Computed: number of SupplierProfiles with matching CPV codes |
| marketIntelligence | string | No | — | Free-text market analysis and landscape notes |
| strategyNotes | string | No | — | Internal strategy decisions and rationale |
| reviewDate | datetime | No | — | Scheduled next strategy review date |
| ownerId | string | No | — | userId of the category owner (procurement manager) |

### NegotiationEvent (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| supplierProfileId | string | Yes | — | OpenRegister object ID of the SupplierProfile |
| sourcingEventId | string | No | — | Optional link to a SourcingEvent |
| eventType | string | Yes | — | Enum: offer / counter_offer / acceptance / rejection / clarification |
| amount | number | No | — | Monetary amount of the offer/counter-offer |
| currency | string | No | EUR | ISO 4217 currency code |
| validUntil | datetime | No | — | Offer validity deadline |
| actorId | string | Yes | — | userId of the acting party (procurement officer or supplier) |
| actorRole | string | Yes | — | Enum: buyer / supplier |
| notes | string | No | — | Negotiation notes or justification |
| createdAt | datetime | Yes | — | Immutable creation timestamp |

### SourcingEvent (`schema:Event`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | Event title, e.g. "RFQ — Office Supplies 2026" |
| eventType | string | Yes | — | Enum: RFI / RFQ / RFP |
| status | string | Yes | draft | Enum: draft / published / closed / cancelled / awarded |
| cpvCodes | array | No | [] | CPV codes this event covers |
| description | string | No | — | Scope and requirements for suppliers |
| publishedAt | datetime | No | — | When the event was published to suppliers |
| responseDeadline | datetime | No | — | Deadline for supplier responses |
| closedAt | datetime | No | — | When the event was closed |
| createdBy | string | Yes | — | userId of the procurement manager |
| invitedSupplierIds | array | No | [] | Array of SupplierProfile object IDs invited |
| awardedSupplierId | string | No | — | SupplierProfile object ID of the awarded supplier |

### SourcingEventResponse (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| sourcingEventId | string | Yes | — | OpenRegister object ID of the SourcingEvent |
| supplierProfileId | string | Yes | — | OpenRegister object ID of the SupplierProfile |
| status | string | Yes | invited | Enum: invited / accepted / declined / responded / evaluated |
| invitedAt | datetime | No | — | Timestamp invitation was sent |
| respondedAt | datetime | No | — | Timestamp supplier submitted response |
| responseText | string | No | — | Supplier's narrative response |
| proposedAmount | number | No | — | Supplier's proposed price |
| currency | string | No | EUR | ISO 4217 currency code |
| documentIds | array | No | [] | Array of Document object IDs submitted with response |
| evaluationScore | number | No | — | Numeric score assigned during evaluation |
| evaluationNotes | string | No | — | Evaluator's notes |
| evaluatedBy | string | No | — | userId of the evaluator |

## Component Architecture

### PHP Services

| Service | Responsibility |
|---------|---------------|
| `KvkLookupService` | HTTP GET to KvK Open Data API; maps response to SupplierProfile fields; caches result in AppSettings for 24 h using `ICache` |
| `IbanVerificationService` | HTTP POST to configurable IBAN verification endpoint; stores result on SupplierProfile; never logs raw IBAN |
| `CertificateChecklistService` | Loads category checklist from AppSettings; compares against existing SupplierCertification objects; returns missing and expired items |
| `SupplierApprovalService` | Validates preconditions (all certifications present, IBAN verified); writes approval decision with audit fields; triggers Nextcloud notification |

### Background Jobs

| Job | Schedule | Behaviour |
|-----|----------|-----------|
| `CertificateExpiryJob` | Daily (ITimedJobList) | Queries all SupplierCertifications with `expiryDate` within 30 days or past; sends notification to `assignedOfficerId`; sets `verificationStatus: expired` for past-expiry records |
| `SupplierRiskJob` | Weekly (ITimedJobList) | Recalculates `riskLevel` for all active SupplierProfiles using configurable scoring weights; notifies `assignedOfficerId` on level increase |

### Vue Component Structure

```
src/
├── views/
│   ├── supplierProfile/
│   │   ├── SupplierProfileIndex.vue      (CnIndexPage)
│   │   ├── SupplierProfileDetail.vue     (CnDetailPage — tabs: Details, Certifications, Negotiations, Documents, Sourcing)
│   │   └── SupplierProfileForm.vue       (CnFormDialog)
│   ├── supplierCertification/
│   │   ├── SupplierCertificationIndex.vue
│   │   ├── SupplierCertificationDetail.vue
│   │   └── SupplierCertificationForm.vue
│   ├── categoryStrategy/
│   │   ├── CategoryStrategyIndex.vue
│   │   ├── CategoryStrategyDetail.vue    (tabs: Overview, Supplier Landscape, Intelligence)
│   │   └── CategoryStrategyForm.vue
│   ├── negotiationEvent/
│   │   └── NegotiationTimeline.vue       (embedded in SupplierProfileDetail)
│   └── sourcingEvent/
│       ├── SourcingEventIndex.vue
│       ├── SourcingEventDetail.vue       (tabs: Details, Invited Suppliers, Responses)
│       └── SourcingEventForm.vue
├── components/
│   ├── CpvCodeSelector.vue               (keyword + code search against bundled JSON)
│   ├── CertificationChecklist.vue        (checklist panel embedded in SupplierProfileDetail)
│   └── SupplierApprovalPanel.vue         (approve/reject with mandatory justification)
└── store/modules/
    ├── supplierProfile.js                (createObjectStore('supplierProfile'))
    ├── supplierCertification.js          (createObjectStore('supplierCertification'))
    ├── categoryStrategy.js               (createObjectStore('categoryStrategy'))
    ├── negotiationEvent.js               (createObjectStore('negotiationEvent'))
    └── sourcingEvent.js                  (createObjectStore('sourcingEvent'))
```

## Seed Data (ADR-016)

### SupplierProfile seed objects

| Field | Value (Acme BV) | Value (Beta Supplies BV) |
|-------|----------------|--------------------------|
| kvkNumber | `12345678` | `87654321` |
| supplierCategory | `preferred` | `approved` |
| qualificationStatus | `qualified` | `pending_verification` |
| riskLevel | `low` | `medium` |
| ibanVerificationStatus | `verified` | `unverified` |
| cpvCodes | `[{code:"30192000",description:"Office supplies"}]` | `[]` |
| spendYTD | `42500.00` | `0.00` |

### SupplierCertification seed objects

| certificationType | issuer | issuedDate | expiryDate | verificationStatus | supplierProfileId |
|------------------|--------|------------|------------|-------------------|------------------|
| `ISO9001` | Bureau Veritas | 2024-01-15 | 2027-01-14 | `verified` | Acme BV |
| `insurance` | Allianz NV | 2026-01-01 | 2027-01-01 | `verified` | Acme BV |
| `tax_clearance` | Belastingdienst | 2026-03-01 | 2026-09-01 | `pending` | Beta Supplies BV |

### CategoryStrategy seed object

| Field | Value |
|-------|-------|
| categoryName | Office Supplies & Stationery |
| cpvCodes | `["30192000","30197000"]` |
| strategicImportance | `medium` |
| spendTarget | `80000.00` |
| marketIntelligence | Demo market intelligence text |

### NegotiationEvent seed objects (3, linked to Acme BV)

1. `offer` — buyer — EUR 45 000 — 2026-03-01
2. `counter_offer` — supplier — EUR 43 000 — 2026-03-05
3. `acceptance` — buyer — EUR 43 000 — 2026-03-08

### SourcingEvent seed object

| Field | Value |
|-------|-------|
| title | RFQ — Office Supplies Q3 2026 |
| eventType | `RFQ` |
| status | `published` |
| cpvCodes | `["30192000"]` |
| responseDeadline | 2026-05-31 |

With 2 SourcingEventResponse objects: Acme BV (`responded`), Beta Supplies BV (`invited`).

## Security Considerations

- KvK API key stored in `AppSettings` with `editable: false` when set via environment variable; never exposed to frontend
- IBAN values stored in OpenRegister objects; raw IBAN is never logged at INFO level or above
- Negotiation events are append-only; no DELETE endpoint is registered for `NegotiationEvent`
- Sourcing event invitations use Nextcloud's `INotifier` and do not expose supplier email addresses to other invited suppliers
- CPV code search uses a bundled JSON file; no external network call is made during search

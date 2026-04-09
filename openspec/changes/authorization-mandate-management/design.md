# Design: Authorization & Mandate Management — Shillinq

## Architecture Overview

This change adds a mandate register and purchase order change workflow on top of the existing core, access-control-authorisation, approval-workflow-management, supplier-management, and accounts-payable-receivable infrastructure. All new entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. Lifecycle services and the compliance policy engine are PHP services called from OCS controllers. The mandate expiry job runs daily via Nextcloud's `ITimedJobList`.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Mandate, MandateScheme, MandateCollection,
    │                          PurchaseOrderLineChange, SupplierAcknowledgment CRUD)
    │
    └─ Shillinq OCS API
            ├─ MandateController        (CRUD, activate, revoke, export)
            ├─ MandateSigningController (generate-link, public-accept)
            ├─ PoChangeController       (CRUD, submit, notify-supplier)
            └─ SupplierAcknowledgmentController (accept, reject, counter-propose)
                    │
                    └─ PHP Services
                            ├─ MandateLifecycleService
                            ├─ MandateSigningService
                            ├─ PainExportService
                            ├─ MandateRegisterExportService
                            ├─ MandatePolicyService
                            ├─ PoChangeWorkflowService
                            └─ (reuses) WorkflowRoutingService (approval-workflow-management)
                    │
                    └─ Background Job
                            └─ MandateExpiryJob  (daily, interval 86400 s)
```

## Data Model

### Mandate (`schema:Authorization`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| mandateId | string | Yes | — | Unique mandate identifier (UUID) |
| type | string | Yes | — | Enum: SEPA_CORE / SEPA_B2B / ELECTRONIC / MULTI_SCHEME |
| status | string | Yes | draft | Enum: draft / pending / active / expired / revoked |
| authorizedAmount | number | Yes | — | Maximum amount authorised per collection |
| frequency | string | No | — | Enum: once / recurring / monthly / quarterly / yearly |
| country | string | No | — | ISO 3166-1 alpha-2 country code |
| createdDate | datetime | Yes | — | Creation timestamp (auto-set) |
| signatureDate | datetime | No | — | Date mandate was signed by the debtor |
| expiryDate | datetime | No | — | Mandate validity end date |
| reference | string | No | — | Unique Mandate Reference (UMR) for SEPA; auto-generated |
| creditorIdentifier | string | No | — | SEPA creditor identifier (CI) in format AT-XXXXXXXXX or DE98XXXXXXXX |
| nextCollectionDate | datetime | No | — | Calculated date of the next collection event |
| debtorContactId | string | No | — | OpenRegister object ID of the debtor Contact |
| creditorOrganizationId | string | No | — | OpenRegister object ID of the creditor Organization |
| mandateSchemeId | string | No | — | OpenRegister object ID of the MandateScheme |
| signingToken | string | No | — | Time-limited token for digital signing link (72 h TTL) |
| signingTokenExpiresAt | datetime | No | — | Expiry timestamp of the signing token |
| revocationReason | string | No | — | Reason captured when status is set to revoked |
| policyFindings | array | No | [] | Array of policy finding codes from last MandatePolicyService run |

### MandateScheme (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| schemeCode | string | Yes | — | Enum: SEPA_CORE / SEPA_B2B / BACS_AUDDIS / BETALINGSSERVICE |
| schemeName | string | Yes | — | Human-readable scheme name |
| country | string | Yes | — | ISO 3166-1 alpha-2 country code |
| currency | string | Yes | EUR | ISO 4217 currency code |
| minPreNotificationDays | integer | Yes | 5 | Minimum days notice before collection |
| maxCollectionAmount | number | No | — | Scheme-imposed maximum single collection amount |
| isActive | boolean | Yes | true | Whether scheme is available for new mandates |
| validationRules | object | No | — | JSON object with field-level validation expressions |
| identifierFormat | string | No | — | Regex pattern for mandate reference (UMR) validation |

### MandateCollection (`schema:PaymentChargeSpecification`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| collectionId | string | Yes | — | Unique collection event ID (UUID) |
| mandateId | string | Yes | — | OpenRegister object ID of the parent Mandate |
| scheduledDate | datetime | Yes | — | Planned collection date |
| amount | number | Yes | — | Collection amount (must not exceed mandate authorizedAmount) |
| status | string | Yes | scheduled | Enum: scheduled / submitted / settled / failed / returned |
| pain008BatchRef | string | No | — | PAIN.008 message ID in which this collection was included |
| submittedAt | datetime | No | — | When the collection was submitted to the bank |
| settledAt | datetime | No | — | When the collection was confirmed settled |
| failureReason | string | No | — | ISO 20022 reason code on failure or return (e.g. AC01, MD01) |
| preNotificationSentAt | datetime | No | — | When the pre-notification was sent to the debtor |
| currency | string | Yes | EUR | ISO 4217 currency code |

### PurchaseOrderLineChange (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| changeId | string | Yes | — | Unique change request ID (UUID) |
| purchaseOrderId | string | Yes | — | OpenRegister object ID of the parent PurchaseOrder |
| changeType | string | Yes | — | Enum: addition / removal / modification / quantity / delivery-date |
| description | string | Yes | — | Human-readable description of the change |
| requestDate | datetime | Yes | — | Date the change was raised |
| status | string | Yes | draft | Enum: draft / submitted / approved / acknowledged / rejected / implemented |
| approvalStatus | string | No | — | Internal approval workflow status (mirrors ApprovalRequest.status) |
| reason | string | No | — | Business justification for the change |
| impactAmount | number | No | — | Financial impact of the change (positive = cost increase) |
| priority | string | No | medium | Enum: low / medium / high / urgent |
| requestedById | string | No | — | OpenRegister object ID of the requesting Contact |
| approvedById | string | No | — | OpenRegister object ID of the approving Employee |
| approvalRequestId | string | No | — | OpenRegister object ID of the linked ApprovalRequest |
| supplierNotifiedAt | datetime | No | — | When the supplier notification email was sent |
| implementedAt | datetime | No | — | When the change was applied to the PurchaseOrder |

### SupplierAcknowledgment (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| acknowledgmentId | string | Yes | — | Unique acknowledgment ID (UUID) |
| poLineChangeId | string | Yes | — | OpenRegister object ID of the PurchaseOrderLineChange |
| status | string | Yes | pending | Enum: pending / accepted / rejected / counter-proposed |
| supplierContactId | string | No | — | OpenRegister object ID of the supplier Contact |
| responseDate | datetime | No | — | Date the supplier responded |
| responseNotes | string | No | — | Supplier's free-text response |
| counterProposalDetails | string | No | — | Structured counter-proposal JSON (delivery date, quantity, price) |
| acknowledgmentToken | string | No | — | Time-limited token for supplier acknowledgment link |
| tokenExpiresAt | datetime | No | — | Expiry timestamp of the acknowledgment token |

### MandateSignatureEvent (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| mandateId | string | Yes | — | OpenRegister object ID of the signed Mandate |
| signedAt | datetime | Yes | — | UTC timestamp of the signing action |
| ipAddress | string | Yes | — | IP address of the signing party |
| userAgent | string | No | — | Browser user-agent string |
| channel | string | Yes | web_link | Enum: web_link / email / paper |
| pdfEvidenceDocumentId | string | No | — | OpenRegister Document object ID of the generated PDF evidence |

## Component Architecture

### PHP Services

| Service | Responsibility |
|---------|---------------|
| `MandateLifecycleService` | Activates mandates (validates signature date, CI format); computes `nextCollectionDate` based on scheme frequency rules; marks mandates expired; handles revocation with reason capture; sends Nextcloud notifications on status changes |
| `MandateSigningService` | Generates 72-hour signing token; renders mandate summary for the public sign page; records `MandateSignatureEvent` (IP, user-agent, timestamp); transitions mandate to `active`; generates PDF evidence via `ISimpleFile` |
| `PainExportService` | Builds PAIN.008 XML for a set of `MandateCollection` objects grouped by collection date; validates IBAN (MOD97), BIC (ISO 9362), UMR (35-char max, alphanumeric); supports SDD Core and SDD B2B service levels; returns `ISimpleFile` |
| `MandateRegisterExportService` | Exports mandate register as PDF (TCPDF via Nextcloud's PDF helper) or XLSX (using Nextcloud's spreadsheet export helper); supports active-only or full-history scope; stamps generation date, authorising officer, and version number on each page |
| `MandatePolicyService` | Evaluates a Mandate or PurchaseOrderLineChange against AppSettings policy rules: max amount per type, allowed cost category codes, role ceiling overrides, duplicate detection (same debtor + creditor + scheme); returns structured `PolicyFinding[]`; writes each finding to `AuditTrail` |
| `PoChangeWorkflowService` | Validates change request against parent PO; routes through `WorkflowRoutingService` for internal approval; on approval sends supplier notification email with token-authenticated acknowledgment link; records `SupplierAcknowledgment`; on supplier acceptance, applies changes to `PurchaseOrder` lines and re-calculates totals |

### Background Job

| Job | Schedule | Behaviour |
|-----|----------|-----------|
| `MandateExpiryJob` | Daily (ITimedJobList, interval 86400 s) | Queries all `Mandate` objects with `status: active` where `expiryDate` < now; sets status to `expired`; notifies mandate owner; writes to `AuditTrail`. Also queries mandates expiring within 90 days and emits a renewal-prompt notification to the treasurer |

### Vue Component Structure

```
src/
├── views/
│   ├── mandate/
│   │   ├── MandateIndex.vue              (CnIndexPage)
│   │   ├── MandateDetail.vue             (CnDetailPage — tabs: Overview, Collections, Signatures, Policy)
│   │   └── MandateForm.vue               (CnFormDialog — scheme-adaptive fields)
│   └── purchaseOrderLineChange/
│       ├── PoLineChangeIndex.vue         (CnIndexPage)
│       ├── PoLineChangeDetail.vue        (CnDetailPage — tabs: Details, Approval, Supplier)
│       └── PoLineChangeForm.vue          (CnFormDialog)
├── components/
│   ├── MandateSchemeSelector.vue         (scheme picker that updates visible fields dynamically)
│   ├── MandateCollectionTimeline.vue     (chronological list of MandateCollection events)
│   ├── MandateSignatureBadge.vue         (shows signing status with IP/timestamp details)
│   ├── MandatePolicyFindingPanel.vue     (lists PolicyFinding results with resolution actions)
│   ├── SupplierAcknowledgmentPanel.vue   (shows acknowledgment status and supplier response)
│   ├── UpcomingCollectionWidget.vue      (home dashboard — next 14 days collections)
│   └── MandateSignPage.vue              (public-facing sign page, no Nextcloud auth required)
└── store/modules/
    ├── mandate.js                        (createObjectStore('mandate'))
    ├── mandateScheme.js                  (createObjectStore('mandateScheme'))
    ├── mandateCollection.js              (createObjectStore('mandateCollection'))
    ├── purchaseOrderLineChange.js        (createObjectStore('purchaseOrderLineChange'))
    └── supplierAcknowledgment.js         (createObjectStore('supplierAcknowledgment'))
```

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/mandates` | List mandates; filter by `status`, `type`, `country` |
| POST | `/api/v1/mandates` | Create a mandate in `draft` status |
| PATCH | `/api/v1/mandates/{id}` | Update a draft mandate |
| POST | `/api/v1/mandates/{id}/activate` | Run policy check then activate |
| POST | `/api/v1/mandates/{id}/revoke` | Revoke with `{reason}` body |
| POST | `/api/v1/mandates/{id}/generate-signing-link` | Generate 72-h signing token and return URL |
| GET | `/api/v1/mandates/sign/{token}` | Public — render mandate summary for signing (no auth) |
| POST | `/api/v1/mandates/sign/{token}/accept` | Public — record `MandateSignatureEvent` and activate |
| GET | `/api/v1/mandates/export/pain008` | Query param: `collectionDate`, `schemeCode`; return PAIN.008 XML |
| GET | `/api/v1/mandates/export` | Query params: `format=pdf\|xlsx`, `scope=active\|all`; return file |
| GET | `/api/v1/po-line-changes` | List change requests; filter by `purchaseOrderId`, `status` |
| POST | `/api/v1/po-line-changes` | Create a change request in `draft` status |
| POST | `/api/v1/po-line-changes/{id}/submit` | Submit for internal approval |
| POST | `/api/v1/po-line-changes/{id}/notify-supplier` | Send supplier notification email |
| GET | `/api/v1/po-line-changes/acknowledge/{token}` | Public — render change summary for supplier |
| POST | `/api/v1/po-line-changes/acknowledge/{token}/accept` | Public — record `SupplierAcknowledgment` accepted |
| POST | `/api/v1/po-line-changes/acknowledge/{token}/reject` | Public — record `SupplierAcknowledgment` rejected |

## Seed Data

### MandateScheme seed objects (2)

```json
[
  {
    "schemeCode": "SEPA_CORE",
    "schemeName": "SEPA Core Direct Debit",
    "country": "NL",
    "currency": "EUR",
    "minPreNotificationDays": 5,
    "maxCollectionAmount": 100000,
    "isActive": true,
    "validationRules": {
      "reference": "^[A-Za-z0-9+?/:().,\\- ]{1,35}$",
      "creditorIdentifier": "^[A-Z]{2}[0-9]{2}[A-Z]{3}[A-Za-z0-9+?/:().,\\- ]{1,28}$"
    },
    "identifierFormat": "NL[0-9]{2}[A-Z]{3}[A-Za-z0-9]{1,16}"
  },
  {
    "schemeCode": "SEPA_B2B",
    "schemeName": "SEPA Business to Business Direct Debit",
    "country": "NL",
    "currency": "EUR",
    "minPreNotificationDays": 2,
    "maxCollectionAmount": 999999,
    "isActive": true,
    "validationRules": {
      "reference": "^[A-Za-z0-9+?/:().,\\- ]{1,35}$",
      "creditorIdentifier": "^[A-Z]{2}[0-9]{2}[A-Z]{3}[A-Za-z0-9+?/:().,\\- ]{1,28}$"
    },
    "identifierFormat": "NL[0-9]{2}[A-Z]{3}[A-Za-z0-9]{1,16}"
  }
]
```

### Mandate seed objects (3)

```json
[
  {
    "mandateId": "mnd-001",
    "type": "SEPA_CORE",
    "status": "active",
    "authorizedAmount": 2500.00,
    "frequency": "monthly",
    "country": "NL",
    "createdDate": "2026-01-10T09:00:00Z",
    "signatureDate": "2026-01-12T14:30:00Z",
    "expiryDate": "2027-01-12T00:00:00Z",
    "reference": "NL13ZZZ123456780001",
    "creditorIdentifier": "NL13ZZZ123456780000",
    "nextCollectionDate": "2026-05-01T00:00:00Z"
  },
  {
    "mandateId": "mnd-002",
    "type": "SEPA_B2B",
    "status": "active",
    "authorizedAmount": 15000.00,
    "frequency": "quarterly",
    "country": "NL",
    "createdDate": "2026-02-01T10:00:00Z",
    "signatureDate": "2026-02-03T11:00:00Z",
    "expiryDate": "2028-02-03T00:00:00Z",
    "reference": "NL13ZZZ123456780002",
    "creditorIdentifier": "NL13ZZZ123456780000",
    "nextCollectionDate": "2026-07-01T00:00:00Z"
  },
  {
    "mandateId": "mnd-003",
    "type": "ELECTRONIC",
    "status": "pending",
    "authorizedAmount": 500.00,
    "frequency": "once",
    "country": "NL",
    "createdDate": "2026-04-08T08:00:00Z",
    "expiryDate": "2026-07-08T00:00:00Z",
    "reference": "NL13ZZZ123456780003",
    "creditorIdentifier": "NL13ZZZ123456780000"
  }
]
```

### MandateCollection seed objects (3)

```json
[
  {
    "collectionId": "col-001",
    "mandateId": "mnd-001",
    "scheduledDate": "2026-05-01T00:00:00Z",
    "amount": 2500.00,
    "status": "scheduled",
    "currency": "EUR",
    "preNotificationSentAt": "2026-04-25T09:00:00Z"
  },
  {
    "collectionId": "col-002",
    "mandateId": "mnd-001",
    "scheduledDate": "2026-04-01T00:00:00Z",
    "amount": 2500.00,
    "status": "settled",
    "currency": "EUR",
    "pain008BatchRef": "MSGID-2026040101",
    "submittedAt": "2026-03-28T12:00:00Z",
    "settledAt": "2026-04-01T18:00:00Z"
  },
  {
    "collectionId": "col-003",
    "mandateId": "mnd-002",
    "scheduledDate": "2026-04-01T00:00:00Z",
    "amount": 15000.00,
    "status": "failed",
    "currency": "EUR",
    "pain008BatchRef": "MSGID-2026040102",
    "submittedAt": "2026-03-29T10:00:00Z",
    "failureReason": "AC01"
  }
]
```

### PurchaseOrderLineChange seed objects (3)

```json
[
  {
    "changeId": "chg-001",
    "changeType": "quantity",
    "description": "Hoeveelheid bureaustoelen verhoogd van 10 naar 15 stuks",
    "requestDate": "2026-03-15T10:00:00Z",
    "status": "acknowledged",
    "approvalStatus": "approved",
    "reason": "Uitbreiding team afdeling Financiën met 5 medewerkers",
    "impactAmount": 1250.00,
    "priority": "medium"
  },
  {
    "changeId": "chg-002",
    "changeType": "delivery-date",
    "description": "Leverdatum verschoven van 2026-04-01 naar 2026-05-15 wegens leveranciersprobleem",
    "requestDate": "2026-03-20T14:00:00Z",
    "status": "approved",
    "approvalStatus": "approved",
    "reason": "Leverancier heeft productievertraging gemeld",
    "impactAmount": 0,
    "priority": "high"
  },
  {
    "changeId": "chg-003",
    "changeType": "addition",
    "description": "Toevoeging van 5 extra monitoren aan de order",
    "requestDate": "2026-04-02T09:30:00Z",
    "status": "submitted",
    "approvalStatus": "pending",
    "reason": "Aanvullende apparatuur voor nieuwe werkplekken",
    "impactAmount": 2250.00,
    "priority": "low"
  }
]
```

### SupplierAcknowledgment seed objects (2)

```json
[
  {
    "acknowledgmentId": "ack-001",
    "poLineChangeId": "chg-001",
    "status": "accepted",
    "responseDate": "2026-03-17T11:00:00Z",
    "responseNotes": "Akkoord met de gewijzigde hoeveelheid. Levering wordt aangepast."
  },
  {
    "acknowledgmentId": "ack-002",
    "poLineChangeId": "chg-002",
    "status": "accepted",
    "responseDate": "2026-03-22T09:15:00Z",
    "responseNotes": "Nieuwe leverdatum 15 mei 2026 bevestigd."
  }
]
```

## Security Considerations

- The public mandate signing endpoint (`/api/v1/mandates/sign/{token}`) and the supplier acknowledgment endpoint (`/api/v1/po-line-changes/acknowledge/{token}`) operate without Nextcloud authentication; they authenticate via the time-limited token only. Tokens are 256-bit random values (CSPRNG); stored as bcrypt hashes in the Mandate/SupplierAcknowledgment objects
- `signingToken` and `acknowledgmentToken` are never returned in list or detail API responses after initial generation; only the signed URL is returned once
- The mandate PDF evidence file is stored in Nextcloud's internal data directory, not in a user-accessible folder; accessible only via the OCS API with valid Nextcloud session
- PAIN.008 export requires the `treasurer` or `admin` CollaborationRole; enforced by `MandateController`
- `policyFindings` on the Mandate object are read-only from the frontend; only `MandatePolicyService` may write them
- IBAN values are masked in all API responses (showing only last 4 digits) unless the caller has the `treasurer` or `admin` role; enforced at the controller level
- `MandatePolicyService.conditionExpression` evaluation uses the same whitelist expression parser as `WorkflowRoutingService` (field names + comparison operators + numeric literals; no eval or dynamic code execution)

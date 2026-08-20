# ADR: Data Model — Shillinq

**Status:** accepted
**Entities:** 254

## Context

All data entities are OpenRegister schemas. This ADR is the single source of truth
for the app's data model. Individual specs REFERENCE these entities but do not redefine them.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

> **Audit-trail-on-every-bookkeeping-register rule (add-shillinq-audit-trail,
> 2026-06-08, capability `bookkeeping-audit-trail`):** Every bookkeeping
> schema declared in shillinq — every register listed below MINUS the
> explicit non-bookkeeping opt-out set (bookings, inventory, notification
> delivery, the scaffolding `example` schema) — MUST carry
> `x-openregister-audit-trail: { enabled: true }` so OR's audit-trail-
> immutable abstraction (ADR-022 D2) captures actor, action,
> before/after snapshot, timestamp, and hash chain on every create /
> update / lifecycle transition / delete. The rule is enforced
> declaratively at CI by `tests/validate-registers.js` (REQ-AT-001);
> PRs introducing bookkeeping schemas without the flag fail the gate.
> Per ADR-022 anti-pattern enumeration, shillinq MUST NOT author
> parallel audit storage: no `lib/Db/Audit*` / `lib/Db/EventLog*` /
> `lib/Db/ChangeLog*` Mappers, no `lib/Service/AuditService.php` /
> `lib/Service/AuditLogger.php`, no `lib/BackgroundJob/*Audit*.php` /
> `lib/Cron/*Audit*.php`, no `lib/Service/*Retention*.php`, no
> `src/views/Audit*.vue` or `src/components/Audit*.vue` general
> surface — every audit event flows through OR and surfaces through
> OR's audit-log UI (manifest `BookkeepingAuditTrail` page +
> per-detail-page `sidebarProps.tabs[audit]`). Retention is governed
> by OR's archival + destruction workflow (Archiefwet, 7 years for
> Belastingdienst-mandated financial records); shillinq adds no
> cleanup job. See `openspec/changes/archive/2026-06-08-add-shillinq-audit-trail/`
> (post-archive) for the full capability spec and the audit-pattern
> scan that confirms the rule is met today.

> **Source-document attachment URI contract (add-shillinq-document-attachment-integration,
> 2026-06-09, capability `bookkeeping-document-attachment-integration`):**
> Source documents (PDF invoices, scanned receipts, bank statements, payment-run
> SEPA artefacts, contracts) MUST be stored in **docudesk** and referenced from
> shillinq bookkeeping registers via the canonical foreign-key URI
> `docudesk://attachments/<uuid>/<filename>` (REQ-DA-002). The contract surfaces
> through one declarative schema field shape — `sourceDocumentUri` (string, URI
> format) — on every bookkeeping register that needs a source-document reference,
> with T1's `JournalEntry` additionally carrying `sourceDocumentApp` (enum
> `docudesk` | `external`) as the pattern T2 registers extend additively. Per
> ADR-022 anti-pattern enumeration, shillinq MUST NOT author parallel attachment
> storage: no `lib/Db/Attachment*` Mappers, no `lib/Service/Attachment*` services,
> no `lib/Controller/*Attachment*` proxy controllers, no `multipart/form-data`
> upload endpoints, no `base64` / `binary` schema fields. Mime-type expectations
> per attachment role (`invoice` → PDF + PNG + JPEG + UBL XML; `receipt` → PDF +
> PNG + JPEG + HEIC; `statement` → CAMT.053 XML + MT940 + PDF; `archive-xml` →
> pain.001 XML; `contract` → PDF) are declared as schema metadata, not enforced
> at the shillinq layer — enforcement is docudesk's responsibility. When docudesk
> is transiently unavailable, the bookkeeping save MUST succeed with the URI
> persisted; the OR audit trail records the gap; the detail page renders a
> non-blocking warning banner with a retry action (REQ-DA-004). Consuming
> capabilities today: `bookkeeping-journal-entries` (T1), `bookkeeping-accounts-payable-core`,
> `bookkeeping-accounts-receivable-core`, `bookkeeping-bank-reconciliation`,
> `bookkeeping-bank-connectors` (all T2). The audit-side-panel manifest binding
> lives in `src/manifest.json` on each detail page's `sidebarProps.tabs[]` as a
> `documents` tab pointing at the `docudesk-attachment-viewer` widget. See
> `openspec/changes/add-shillinq-document-attachment-integration/` for the
> capability spec.

## Entities

### APTransaction
**Schema.org:** `schema:Invoice`
_Accounts payable sub-ledger invoice recording the vendor billing and payment obligation. Posting (issued transition) materialises a balanced GLTransaction per the T1 REQ-JE-007 pattern. The lifecycle covers draft → received → issued → paid with partially-paid / overdue / disputed / written-off / voided branches; write-off materialises a compensating GL posting._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Vendor invoice number; unique per administration + vendorId |
| vendorId | string | Yes | FK to Payee UUID |
| invoiceDate | date | Yes | Date the vendor issued the invoice |
| dueDate | date | Yes | Auto-calculated from invoiceDate + Payee.paymentTermDays; operator overrideable |
| currency | string | Yes | ISO 4217 currency code; T2: base currency only (T5 adds multi-currency) |
| totalAmount | number | Yes | Total amount including tax in administration base currency |
| taxAmount | number | No | VAT/BTW amount |
| lines | array | Yes | Line items: {description, accountNumber, amount, taxCode, quantity, unitPrice} |
| sourceDocumentUri | string | No | docudesk FK URI per bookkeeping-document-attachment-integration |
| state | enum | Yes | One of draft, received, issued, partially-paid, paid, overdue, disputed, written-off, voided |
| glTransactionId | string | No | Back-reference to materialised GLTransaction once posted |
| writeOffReason | string | No | Audit-trailed reason required on the writeOff transition |
| writeOffGlTransactionId | string | No | Back-reference to compensating GLTransaction on write-off |
| periodId | string | No | FK to the FiscalPeriod for GL posting (resolved on issue transition) |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → Payee (many-to-one, via vendorId)
- → GLTransaction (many-to-one, via glTransactionId — materialised on issue and write-off)
- → DunningNotice (one-to-many, dunning timeline per AP invoice)
- → FiscalPeriod (many-to-one, via periodId)
- → Administration (many-to-one)

> **Reconciliation note (bookkeeping-accounts-payable-core, 2026-06-09):** This
> entry has been updated from the prior generic `accounts-payable-receivable`
> draft to the canonical T2 shape registered in
> `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`. The fields
> mirror the AR side (`ARInvoice`). The prior `VendorMaster` / `APInvoice` /
> `PaymentRun` entries below (added by `add-shillinq-bookkeeping-compliance`)
> remain a parallel pre-T2 flavour; new AP register declarations MUST use
> `APTransaction`. See `openspec/changes/bookkeeping-accounts-payable-core/
> dedup-notes.md` for the migration boundary.

### ARInvoice
**Schema.org:** `schema:Invoice`
_Accounts receivable sub-ledger invoice recording the customer billing and payment obligation. Posting an ARInvoice materialises a balanced GLTransaction per the T1 REQ-JE-007 pattern. The lifecycle covers draft → issued → paid with overdue / disputed / written-off branches; write-off materialises a compensating GL posting._
**Primary spec:** bookkeeping-accounts-receivable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Shillinq-side invoice number (auto-generated per administration) |
| customerId | string | Yes | FK to CustomerMaster UUID |
| invoiceDate | date | Yes | Date of invoice issuance |
| dueDate | date | Yes | Auto-calculated from invoiceDate + customer.paymentTermDays; overrideable |
| currency | string | Yes | ISO 4217 currency code; T2: base currency only (T5 adds multi-currency) |
| totalAmount | number | Yes | Total amount including tax |
| taxAmount | number | No | Tax/VAT amount |
| lines | array | Yes | Line items: {description, accountNumber, amount, taxCode, quantity, unitPrice} |
| sourceDocumentUri | string | No | docudesk FK URI per bookkeeping-document-attachment-integration |
| ublXml | string | No | UBL 2.1 / Peppol BIS 3.0 XML (populated by T4 e-invoicing; null in T2) |
| state | enum | Yes | One of draft, issued, partially-paid, paid, overdue, disputed, written-off, voided |
| glTransactionId | string | No | Back-reference to materialised GLTransaction once posted |
| peppolDispatchedAt | datetime | No | Timestamp of Peppol dispatch (set by T4) |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → CustomerMaster (many-to-one, via customerId)
- → GLTransaction (many-to-one, via glTransactionId — materialised on issue and write-off)
- → DunningRecord (one-to-many, dunning timeline per invoice)
- → Administration (many-to-one)

> **Reconciliation note (add-shillinq-accounts-receivable-core, 2026-06-01):** The existing
> `Invoice` entry (primary spec: obligation-financial-administration) is a generic invoice
> schema. `ARInvoice` is the shillinq bookkeeping-tier AR sub-ledger invoice with full
> lifecycle, GL materialisation, dunning, and UBL field shape declaration. New AR register
> declarations in shillinq MUST use `ARInvoice`. The `Invoice` entry is retained for
> generic obligation-financial-administration usage outside the bookkeeping tier.

### Account
**Schema.org:** `schema:DefinedTerm`
_Hierarchical chart-of-accounts entry conforming to the RGS (Referentie Grootboek Schema) standard. Canonical bookkeeping entity for T1–T5 tiers. Supersedes the earlier `GeneralLedgerAccount` entry (see reconciliation note below)._
**Primary spec:** bookkeeping-chart-of-accounts

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | RGS-style account code (e.g. 1000, 4100) |
| name | string | Yes | Human-readable account name |
| accountType | enum | Yes | One of assets, liabilities, equity, revenue, expenses |
| currency | string | Yes | ISO 4217 currency code (e.g. EUR); default EUR |
| parentAccountNumber | string | No | FK to parent Account.accountNumber for hierarchy |
| isClosingAccount | boolean | No | Designates this as the administration's single closing account |
| administrationId | string | Yes | FK to the Administration owning this account |
| lifecycleState | enum | Yes | One of active, blocked, archived |
| description | string | No | Operator-authored free-text description |
| vatApplicable | boolean | No | Whether VAT/BTW applies to transactions on this account |
| iban | string | No | Dutch IBAN for bank/cash accounts |
| esaClassifier | enum | No | ESA 2010 sector code (S.1311/S.1312/S.1313/S.1314/S.11/S.12/S.13/S.14/S.15/S.2) driving EMU-saldo computation — see annotation below |
| iv3FieldCode | string | No | CBS IV3 field code this account maps to (e.g. K1000, K2100). Optional; if set, IV3 report aggregation groups GL transactions for this account under the given field code per REQ-IV3-003 (bookkeeping-iv3-reporting). |

**Relations:**
- self → Account (many-to-one, via parentAccountNumber → accountNumber; hierarchy navigation)
- → GLLine (one-to-many, from T1 general-ledger change)
- → Administration (many-to-one)
- → IV3ReportLine (one-to-many, via iv3FieldCode grouping in quarterly aggregation)

> **ESA-2010 classifier annotation (add-shillinq-emu-reporting, 2026-06-01):** The
> optional `esaClassifier` field added by the T4-specialized change
> `add-shillinq-emu-reporting` carries the canonical ESA 2010 (European System of
> Accounts) sector code for each account. This field drives the EMU-saldo and
> EMU-schuld computations declared as `x-openregister-aggregations` on the Account
> schema per REQ-EMU-002. The canonical classifier list ships as
> `lib/Settings/seeds/esa-2010-classifier.json`. See
> `openspec/changes/add-shillinq-emu-reporting/design.md` for the full
> Reuse Analysis and the ADR-031 declarative-vs-imperative decision.

> **Reconciliation note (add-shillinq-chart-of-accounts, 2026-05-18):** The earlier
> `GeneralLedgerAccount` entry (Schema.org `schema:Product`, primary spec
> financial-reporting-accountability) has been reconciled into this `Account` entry.
> `Account` is the canonical T1 chart-of-accounts schema registered in
> `lib/Settings/shillinq_register.json`. The `GeneralLedgerAccount` entry below is
> retained for historical reference but MUST NOT be used for new register declarations;
> downstream specs (T2 trial balance, T3 VAT, T4 multi-currency) MUST reference
> `Account.accountNumber` as the FK target. The Schema.org type is corrected to
> `schema:DefinedTerm` — a ledger account code is a coded financial classifier
> (DefinedTerm), not a product.

> **Deferred-tax category hint annotation (bookkeeping-deferred-tax, 2026-06-08):**
> The T3 change `bookkeeping-deferred-tax` adds an optional `taxBasisDifferenceCategory`
> enum field to `Account` (depreciation / provision / receivable-impairment /
> inventory-valuation / development-cost / fair-value-adjustment / lease-ifrs16 /
> pension / other) per REQ-DT-001. When set, the GL detection logic uses the hint
> to automatically classify the account's commercial-vs-tax difference into the
> matching `TemporaryDifference.category`. When null, no automatic detection runs
> for that account; the operator may still create `TemporaryDifference` records
> manually. The extension is additive (nullable) — existing T1 / T2 callers stay
> correct. Declared as an `x-openspec-extend` patch in
> `lib/Settings/register.d/bookkeeping-deferred-tax.json` (ADR-037 modular fragment;
> never edit the monolith).

### AccountabilityReport
**Schema.org:** `schema:Report`
_An official accountability report submitted by an organization for a fiscal period covering financial position and transactions_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the accountability report |
| reportDate | datetime | Yes | Date the report was generated |
| submissionDate | datetime | No | Date the report was submitted to relevant authority |
| status | string | Yes | Status (draft, submitted, approved, rejected) |
| content | string | No | Full text content of the accountability report |
| approvalStatus | string | Yes | Approval status (pending, approved, rejected) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (one-to-many)

### Administration
**Schema.org:** `schema:DigitalDocument`
_Accounting administration unit for a specific business year of a corporation. Supports multi-administration management for tracking financial records per fiscal year._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationNumber | string | Yes | Unique identifier for this administration unit |
| businessYear | string | Yes | Business year (YYYY format) |
| accountingPeriod | string | Yes | Period type: monthly, quarterly, or annual |
| startDate | date | Yes | Start date of the accounting period |
| endDate | date | Yes | End date of the accounting period |
| accountantName | string | No | Responsible accountant or accounting firm name |
| submissionDate | date | No | Date administration was submitted (if applicable) |

**Relations:**
- → Corporation (many-to-one)

### AllocationRule
**Schema.org:** `schema:Thing`
_Cost-allocation rule declared as schema metadata per ADR-031 (design D2). Stores the rule shape: source account pattern, named driver (fixed-percentage, fixed-amount, volume, headcount), targets with target dimension (cost-center, kosten-drager, project), and cadence (per-posting, monthly, period-close). Per-posting rules fire as x-openregister-lifecycle action on GLTransaction.post; monthly/period-close rules fire via OR ScheduledWorkflow. No AllocationService.allocate() ever executes the rule. A fixed-percentage precondition that target percentages sum to 100 is declared as x-openregister-lifecycle.requires on AllocationRule.save per REQ-CC-004._
**Primary spec:** bookkeeping-cost-centers-dimensions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Operator-readable rule name |
| sourceAccountPattern | string | Yes | Glob or range pattern matching source GL accounts (e.g. 1000-1099) |
| driver | enum | Yes | One of fixed-percentage, fixed-amount, volume, headcount |
| targets | array | Yes | At least 2 targets; percentages MUST sum to 100 when driver = fixed-percentage |
| targetDimension | enum | Yes | One of cost-center, kosten-drager, project |
| cadence | enum | Yes | One of per-posting, monthly, period-close |
| lifecycleState | enum | Yes | One of active, paused, archived |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- → CostCenter (many-to-one, via targets[].code when targetDimension = cost-center)
- → KostenDrager (many-to-one, via targets[].code when targetDimension = kosten-drager)
- → Project (many-to-one, via targets[].code when targetDimension = project)

> **Reconciliation note (add-shillinq-cost-centers-dimensions, 2026-06-03):** The earlier `AllocationRule` entry (primary spec: cost-accounting-allocation) described a generic allocation rule with `ruleType/percentage/fixedAmount/frequency/isActive/startDate/endDate` shape. This entry supersedes it for the shillinq bookkeeping tier with the T4 schema-declarative shape per ADR-031 and REQ-CC-004: `sourceAccountPattern/driver/targets/targetDimension/cadence/lifecycleState`. Key changes: (1) no PHP `AllocationService` — rule declared in schema metadata; (2) four named drivers replace free-form `ruleType`; (3) cadence routes execution to lifecycle action (per-posting) or OR ScheduledWorkflow (monthly/period-close); (4) `fixed-percentage` sum-to-100 precondition declared as `x-openregister-lifecycle.requires`. Example seeds ship in `lifecycleState: paused` under `lib/Settings/seeds/allocation-rules/`.

### AnalyticalDimension
**Schema.org:** `schema:DefinedTerm`
_An operator-defined custom analytical dimension (e.g. region, product line, channel). Allows administrations to extend GLLine.dimensions with new dimension types without code changes per REQ-CD-001 and REQ-CD-006. Dimensions are declared as OR-managed registers; their values are stored as separate OR register instances identified by the dimension code. The GLLine.dimensions free-form map uses the dimension code as key and the value record code as value, validated via OR's relation engine — no PHP DimensionService. Lifecycle covers active → blocked → archived to govern which dimension keys may be referenced in new GLLine entries._
**Primary spec:** bookkeeping-cost-centers-dimensions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique dimension identifier used as GLLine.dimensions map keys (e.g. region, product-line); lowercase kebab-case |
| name | string | Yes | Human-readable dimension name (e.g. Regio, Productlijn) |
| description | string | No | Description of what this dimension captures |
| dataType | enum | Yes | One of string, number, date — determines value validation |
| isHierarchical | boolean | No | Whether values support parent-child relationships for hierarchical roll-up |
| lifecycleState | enum | Yes | One of active, blocked, archived |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- → GLLine (one-to-many, via GLLine.dimensions map entries keyed by this dimension's code)

> **Reconciliation note (bookkeeping-cost-centers-dimensions, 2026-06-03):** This entry introduces `AnalyticalDimension` as a new OR-managed schema in the shillinq register. No prior `AnalyticalDimension` entity existed in this ADR. Custom dimensions are operator-extensible: an administration creates an `AnalyticalDimension` record to define a new dimension type (Region, Department, Channel) without PHP or Vue code changes per REQ-CD-001. Values for each dimension (NL, BE, DE for Region) are stored as separate OR register instances identified by the dimension code, and referenced in `GLLine.dimensions` map entries per REQ-CD-003.

### ApprovalChain
**Schema.org:** `ApprovalChain`
_Configurable approval workflows that define the sequence of approvers for different document types_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| chainId | string | Yes | Unique approval chain identifier |
| name | string | Yes | Name of the approval chain |
| documentType | string | Yes | Type of document this applies to (PurchaseOrder, Document, ExpenseClaim, etc.) |
| description | string | No | Workflow description |
| status | string | No | active or inactive |
| approverSequence | array | Yes | Ordered list of approver roles or users |
| requiresSignature | boolean | No | Whether approval requires digital signature |

**Relations:**
- → ApprovalTask (one-to-many)

### ApprovalRequest
**Schema.org:** `schema:Event`
_Approval workflow management for purchase orders and documents_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique approval request ID |
| description | text | Yes | What requires approval and business justification |
| startDate | date | Yes | Approval workflow initiation date |
| dueDate | date | No | Target approval deadline |
| requiredApproversCount | integer | Yes | Number of approvals required |
| currentApprovalCount | integer | No | Current approval count |
| approverEmails | string | No | Comma-separated approver contact list |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Document (many-to-one)

### ApprovalRoute
**Schema.org:** `schema:Event`
_Workflow defining contract approval steps and authorized approvers_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of approval workflow |
| description | string | No | Description of approval process |
| approverSequence | array | No | Ordered list of approver names/roles/groups |
| priority | string | No | Workflow priority (Low, Medium, High) |
| estimatedDays | number | No | Estimated days to complete approvals |

### ApprovalTask
**Schema.org:** `schema:Action`
_Individual approval task assigned to a user within an approval workflow_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskId | string | Yes | Unique approval task identifier |
| approvalChainId | string | Yes | Reference to the approval chain configuration |
| documentId | string | Yes | Reference to the document being approved |
| approvalRequestId | string | Yes | Reference to the approval request |
| stepNumber | number | Yes | Step number in the approval sequence |
| assignedTo | string | Yes | Person/User ID assigned this task |
| status | string | Yes | pending/approved/rejected/delegated |
| dueDate | datetime | No | When approval is due |
| completedDate | datetime | No | When approval was completed |
| approvalComment | string | No | Comments from the approver |

**Relations:**
- → ApprovalChain (many-to-one)
- → ApprovalRequest (many-to-one)
- → Document (many-to-one)
- → Person (many-to-one)

### Appointment
**Schema.org:** `schema:Reservation`
_A booked appointment linking a customer Contact to a Service + Resource at a specific UTC time window. Owned by `bookings-create-appointment`. Extended by `bookings-confirm-flow` with the customer-facing confirmation workflow: customer self-service bookings start as `pending_confirmation` and require a redeemed `ConfirmationToken` to move to `confirmed`; admin-created bookings start directly at `confirmed`. The confirmationDeadline + auto-cancel sweep (CancelUnconfirmedAppointmentsJob) clears stale pending rows daily. Other phase-2 changes (bookings-cancellation-rules, bookings-deposits) operate on the same record._
**Primary spec:** bookings-create-appointment
**Extending specs:** bookings-confirm-flow (lifecycle confirmViaToken / autoCancelExpired + confirmationDeadline / confirmedAt / confirmationTokenId fields), bookings-resource-calendar, bookings-cancellation-rules

**Confirmation fields (bookings-confirm-flow):**

| Field | Type | Required | Description |
|---|---|---|---|
| confirmationDeadline | datetime | No | Latest moment the customer can confirm before auto-cancel |
| confirmedAt | datetime | No | Timestamp when the appointment moved to `confirmed` |
| confirmationTokenId | string | No | FK to the currently-active ConfirmationToken |

**Lifecycle transitions added by bookings-confirm-flow:**
- pending_confirmation → confirmed (`confirmViaToken`, guarded by token validation)
- pending_confirmation → cancelled (`autoCancelExpired`, by CancelUnconfirmedAppointmentsJob)

### ConfirmationToken
**Schema.org:** `schema:AuthorizationToken`
_A short-lived secret used by a customer to confirm a pending Appointment via email link or the web confirmation portal. Tokens are stored as salted bcrypt hashes (cost 12) — never plaintext — and validated in constant time by TokenValidator::verify. Lifecycle: `active` → `redeemed` (on confirmation) or `revoked` (on resend) or `expired` (when expiresAt passes). One Appointment may have multiple ConfirmationToken rows over time (e.g. after resend); the appointment's `confirmationTokenId` points at the current active one._
**Primary spec:** bookings-confirm-flow

**Fields:**

| Field | Type | Required | Description |
|---|---|---|---|
| tokenId | string (UUID) | Yes | Unique token identifier |
| appointmentId | string (FK) | Yes | FK to Appointment.appointmentId |
| tokenString | string | Yes | Salted bcrypt hash of the plaintext token |
| expiresAt | datetime | Yes | ISO 8601 UTC expiry (+7 days default) |
| status | enum | Yes | active / redeemed / revoked / expired |
| redeemedAt | datetime | No | Timestamp when redeemed |
| createdAt | datetime | Yes | Auto-set creation timestamp |
| createdBy | string | Yes | Actor that triggered creation (system / admin / customer id) |

**Relations:**
- → Appointment (many-to-one)

**Lifecycle transitions:**
- active → redeemed (`redeem`, on token validation by ConfirmationApiController::confirm)
- active → revoked (`revoke`, on customer resend by ConfirmationApiController::resend)
- active → expired (`expire`, when expiresAt < now, swept by background job)

### AssessmentCriteria
**Schema.org:** `schema:Thing`
_Weighted criteria schema for property scoring and evaluation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes |  |
| category | string | Yes | structure, condition, location, market, etc |
| description | string | Yes |  |
| weight | number | Yes | Weight percentage 0-100 |
| rubric | string | No | Scoring guide |
| applicability | string | Yes | required, optional, conditional |
| active | boolean | Yes |  |

**Relations:**
- → PropertyAssessment (many-to-many)

### Assignment
**Schema.org:** `schema:AggregateOffer`
_A specific work assignment or engagement of a freelancer with a client_
**Primary spec:** freelancers-zzp

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Assignment title |
| description | string | No | Assignment description |
| startDate | datetime | Yes | Assignment start date |
| endDate | datetime | No | Assignment end date |
| hourlyRate | number | No | Hourly rate for this assignment |
| status | string | Yes | Assignment status |

**Relations:**
- → Freelancer (many-to-one)
- → Organization (many-to-one)
- → TimeEntry (one-to-many)

### Auction
**Schema.org:** `schema:AuctionEvent`
_Auction format for competitive bidding with multiple formats and real-time bid tracking_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auctionId | string | Yes | Unique auction identifier |
| auctionType | string | Yes | Type: english, dutch, sealedbid, reverse |
| startDate | datetime | Yes | Auction start time |
| endDate | datetime | Yes | Auction end time |
| status | string | Yes | Status: pending, active, closed, awarded |

**Relations:**
- → Lot (many-to-one)
- → Offer (one-to-many)

### AuditDocument
**Schema.org:** `schema:DigitalDocument`
_A financial document participating in SiSa audit (invoice, purchase order, journal entry, payment). Every state transition triggers an immutable audit-trail event via OR's audit service per REQ-SISA-001 and REQ-SISA-003._
**Primary spec:** bookkeeping-sisa-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentNumber | string | Yes | Unique document identifier per administration |
| documentType | enum | Yes | One of: invoice, purchase-order, journal-entry, payment |
| glTransactionId | string | Yes | FK to GLTransaction (T1 general-ledger) |
| administrationId | string | Yes | FK to Administration |
| signingUser | string | Yes | Nextcloud user ID who signed or issued the document |
| signingTimestamp | datetime | Yes | Timestamp when document was signed (captured by OR audit service) |
| signingReason | string | No | Optional reason or comment on signing |
| state | enum | Yes | One of: draft, issued, signed, voided (lifecycle per REQ-SISA-004) |
| lifecycleState | enum | Yes | One of: active, archived |
| relatedTransactionAmount | number | No | Amount of related GL transaction for context display |
| currency | string | Yes | ISO 4217 currency code |

**Relations:**
- → GLTransaction (many-to-one)
- → Administration (many-to-one)

> **Rekenkamer / accountantscontrole audit-pack annotation (T4 capability `bookkeeping-rekenkamer-audit-pack` — REQ-REK-001..006):** The rekenkamer + accountantscontrole audit-pack capability adds three external-auditor-facing deliverables on top of `AuditDocument` and the OR `audit-trail-immutable` event log — a NIVRA-bestand export (the NBA `auditfile-financieel` XML projection bundling every transaction + line + audit event + period trial-balance + chart-of-accounts in effect, per REQ-REK-002), a deterministic-seed steekproef sampler returning a reproducible random sample of `GLTransaction` records given `(periodId, sampleSize, seed)` for substantive testing (REQ-REK-003), and a ledenraadpleging-export with `redactFor: ['raadsleden']` metadata replacing free-text `description`-level fields and AP/AR sub-ledger counterparty references by stable hash or `[REDACTED]` placeholder while preserving numeric and account-code fields (REQ-REK-004). Per ADR-022, the audit-pack does **NOT** introduce a parallel audit register — no `RekenkamerExport`, no `NivraRecord`, no `lib/Db/Audit*` class — every output is a declarative `x-openregister-aggregations` projection over the existing audit-trail-immutable surface + `GLTransaction` / `GLLine` / `TrialBalance` / `Account`, rendered through three docudesk templates (`nivra-bestand-xml`, `steekproef-werkpapier`, `ledenraadpleging-export`) and optionally pushed to an external accountant portal via a single openconnector source row per accountant per administration (protocol mapping lives openconnector-side per ADR-019). Every audit-pack export is itself recorded as an immutable audit event (`audit-pack.{nivra,steekproef,ledenraadpleging}.exported`) on the OR audit engine — operator id + period id + document URI + SHA-256 — NOT via app-local logging (REQ-REK-005). The capability surfaces via a feature-flag-controlled (`featureFlags.gov-rekenkamer`) manifest navigation entry `Bookkeeping > Audit pack` with three sub-pages rendered by the generic `CnIndexPage` / `CnDetailPage` per ADR-024 Tier-4; no bespoke Vue files (REQ-REK-006). The T2 envelope's REQ-REK-* surface (external-auditor deliverables: NIVRA / steekproef / ledenraadpleging) is intentionally orthogonal to the sibling T3 `bookkeeping-rekenkamer-audit-pack` REQ-RAP-* surface (internal audit views: signing trail / destruction report / change history / compliance export / activity feed) — both sets of requirements ADD to the same capability spec without name overlap. Per ADR-031, deterministic seed reproducibility is preferred declaratively via the OR aggregation engine; if engine support is missing, the implementing cycle's fallback is bounded to a single-method ~20-LOC PHP sampler at `lib/Aggregation/SteekproefSampler.php` annotated with the ADR-031 exception reference.

### AuditFinding
**Schema.org:** `schema:Report`
_Individual finding or observation from audit requiring management action or response_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| findingType | enum | Yes | Type: deficiency, observation, or finding |
| severity | enum | Yes | Priority: critical, high, medium, or low |
| description | text | Yes | Detailed finding description |
| remediation | text | No | Recommended remediation actions |
| dueDate | date | No | Target remediation completion date |

**Relations:**
- → Person (many-to-one)
- → ManagementLetter (many-to-one)

### AuditorStatement
**Schema.org:** `schema:Statement`
_An auditor statement registering and verifying grant compliance and authenticity for large subsidies_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| statementId | string | Yes | Unique statement identifier |
| verificationDate | datetime | Yes | Date of auditor verification |
| isVerified | boolean | Yes |  |
| findings | string | No | Audit findings and observations |
| verdict | string | No | Audit verdict: approved, rejected, conditional |

**Relations:**
- → Grant (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (one-to-one)

### AwardDecision
**Schema.org:** `schema:Order`
_Award decision documenting bid evaluation outcome, selected supplier, and contract authorization_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Award decision identifier |
| description | string | No | Summary of award rationale |
| awardDate | date | Yes | Date the award was decided |
| awardedAmount | number | Yes | Contract value of awarded bid |
| currency | string | Yes | Currency code for contract value |
| justification | string | No | Evaluation summary and decision rationale |

**Relations:**
- → BidEvaluation (many-to-one)
- → SupplierBid (many-to-one)
- → Supplier (many-to-one)
- → Contract (one-to-one)

### AwardNotice
**Schema.org:** `schema:CreativeWork`
_Legal notice of award with publication deadline and standstill enforcement for compliance_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeId | string | Yes | Unique award notice identifier |
| publicationDate | datetime | Yes | Date notice was published |
| legalDeadline | datetime | Yes | End of standstill period after publication |
| status | string | Yes | Status: draft, published, enforced, archived |
| archiveDate | datetime | No | Compliance archive date |

**Relations:**
- → AwardDecision (many-to-one)
- → Lot (many-to-many)

### BalanceSheet
**Schema.org:** `schema:Table`
_A financial statement showing assets, liabilities, and equity at a fiscal-period snapshot. A read-only aggregate over GL transactions — totals (totalAssets, totalLiabilities, totalEquity, isBalanced) are computed via x-openregister-aggregations from GLLine entries grouped by Account.accountType per REQ-FS-004. No BalanceSheetService or FinancialStatementLine table. Lifecycle: draft → final → published → archived per REQ-FS-003 consuming OR publication extension or ConsolidationGuard fallback per ADR-031._
**Primary spec:** bookkeeping-financial-statements

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Snapshot date of the balance sheet (typically fiscal-year-end) |
| totalAssets | number | No | Computed total assets in base currency (x-openregister-aggregations) |
| totalLiabilities | number | No | Computed total liabilities in base currency (x-openregister-aggregations) |
| totalEquity | number | No | Computed total equity in base currency (x-openregister-aggregations) |
| isBalanced | boolean | No | Computed flag: totalAssets = totalLiabilities + totalEquity |
| currency | string | Yes | ISO 4217 base currency code; default EUR |
| status | enum | Yes | One of draft, final, published, archived |
| fiscalYearId | string | Yes | FK to FiscalYear |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → FiscalYear (many-to-one)
- → GLLine (one-to-many, via aggregation — not a direct DB join)

> **Note (bookkeeping-financial-statements, 2026-06-02):** This entry supersedes
> the earlier BalanceSheet entry (primary spec: financial-reporting-accountability).
> Key changes: (1) declares this as a **read-only aggregate** over GL transactions
> — no separate FinancialStatementLine table; (2) adds fiscalYearId + administrationId
> required fields; (3) adds isBalanced computed flag; (4) updates lifecycle to
> draft → final → published → archived; (5) removes stale → Organization and
> → GeneralLedgerEntry relations (GeneralLedgerEntry is deprecated; GLLine is the
> canonical T1 posting schema; aggregation is computed, not a FK join).

### TrialBalance
**Schema.org:** `schema:Table`
_A read-only aggregate listing all GL accounts with debit/credit balances for period verification. isBalanced flag (totalDebits = totalCredits) is computed via x-openregister-aggregations per REQ-FS-005. No TrialBalanceService. Lifecycle: draft → verified → final → published → archived per REQ-FS-003._
**Primary spec:** bookkeeping-financial-statements

> **Trial-balance T2 capability (add-shillinq-trial-balance, 2026-06-09).** The
> Tier-2 `bookkeeping-trial-balance` capability binds the per-account
> opening / movement / closing roll-up to two declarative `x-openregister-aggregations`
> blocks on this `TrialBalance` schema in `lib/Settings/shillinq_register.json` —
> `trialBalanceTotals` (period-wide debit/credit roll-up + `isBalanced` check)
> and `trialBalanceByAccount` (per-account roll-up joined to `Account` for
> name/type display columns). The choice to compose two aggregations on the
> existing snapshot schema rather than aggregating directly on `GLLine` is
> the ADR-022 path of least storage (no parallel report table; reuses the
> single-row `TrialBalance` snapshot already declared for REQ-FS-005). The
> debit-credit balance invariant lands as a declarative `check` operation
> on `trialBalanceTotals` (ADR-031). The sibling implementation cycle did
> ship `lib/Service/TrialBalanceService.php` + `TrialBalanceCalculator.php`
> as a constrained ADR-031 deviation (documented inline in the sibling
> change's `specs.md`) to keep the existing snapshot lifecycle working;
> new report-builders MUST NOT follow that precedent.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Snapshot date (typically fiscal-year-end) |
| totalDebits | number | No | Sum of all debit balances (x-openregister-aggregations) |
| totalCredits | number | No | Sum of all credit balances (x-openregister-aggregations) |
| isBalanced | boolean | No | Computed flag: totalDebits = totalCredits |
| status | enum | Yes | One of draft, verified, final, published, archived |
| preparedBy | string | No | Actor who prepared or verified (audit trail) |
| fiscalYearId | string | Yes | FK to FiscalYear |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → FiscalYear (many-to-one)
- → GLLine (one-to-many, via aggregation)

### ConsolidationGroup
**Schema.org:** `schema:Organization`
_A group of organizations consolidated together for consolidated financial reporting across multiple administrations. Holds the consolidation method (full/proportional/equity per IFRS 10/11/12) and inter-company elimination rules. Consumed by ConsolidatedReport per REQ-FS-006._
**Primary spec:** bookkeeping-financial-statements

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the consolidation group |
| consolidationMethod | enum | Yes | One of full, proportional, equity (IFRS 10/11/12) |
| status | enum | Yes | One of active, inactive, archived |
| parentOrganizationId | string | No | FK to parent organization |
| eliminationRules | object | No | Inter-company elimination rules (offset-by-FK, percentage-based, custom) |
| administrationIds | array of string | Yes | FKs to Administration records being consolidated |

**Relations:**
- → ConsolidatedReport (one-to-many)

> **Note (bookkeeping-financial-statements, 2026-06-02):** This entry supersedes
> the earlier ConsolidationGroup entry (primary spec: financial-reporting-accountability).
> Key changes: (1) adds administrationIds required field (array of FK strings);
> (2) replaces parentOrganization (string name) with parentOrganizationId (FK);
> (3) adds lifecycle (active → inactive → archived); (4) removes stale
> → Organization (one-to-many) relation — consolidated administrations are now
> referenced by administrationIds array field.

### ConsolidatedReport
**Schema.org:** `schema:Report`
_A read-only aggregate combining financials across multiple administrations with consolidation method and inter-company elimination tracking. Lifecycle: draft → final → published → archived per REQ-FS-003. Consolidation workflow consumes OR consolidation extension (ADR-022) or ConsolidationGuard fallback per ADR-031._
**Primary spec:** bookkeeping-financial-statements

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the consolidated report |
| reportDate | datetime | Yes | Consolidation snapshot date |
| consolidationGroupId | string | Yes | FK to ConsolidationGroup |
| consolidationMethod | enum | Yes | One of full, proportional, equity |
| eliminationsApplied | boolean | No | Whether inter-company eliminations have been applied |
| status | enum | Yes | One of draft, final, published, archived |
| fiscalYearId | string | Yes | FK to FiscalYear |

**Relations:**
- → ConsolidationGroup (many-to-one)
- → FiscalYear (many-to-one)

> **Note (bookkeeping-financial-statements, 2026-06-02):** This entry supersedes
> the earlier ConsolidatedReport entry (primary spec: financial-reporting-accountability).
> Key changes: (1) replaces isPublished boolean with full lifecycle (draft → final
> → published → archived); (2) adds consolidationGroupId FK (replaces stale
> → ConsolidationGroup many-to-one relation); (3) adds fiscalYearId required field;
> (4) removes stale → BalanceSheet (one-to-many) relation — the consolidated
> report aggregates over member administrations' BalanceSheets via aggregation,
> not a FK join; (5) removes finalized/archived from status enum — these are now
> expressed as lifecycle states.

### Barcode
**Schema.org:** `schema:Product`
**Primary spec:** inventory-barcode-sku
_A scannable code (EAN, GTIN, UPC, SSCC, or INTERNAL) bound to a Product at a specific unit-of-measure. A single Product carries 1..N barcodes — one per UoM or channel (EAN-13 per unit, GTIN-14 per carton, SSCC per pallet). Declared as a separate register (not an inline array on Product) so each barcode has its own queryable identity, audit trail, and lifecycle (deactivate without delete). Consumed by the shillinq `/api/barcode/lookup/{code}` endpoint and the pipelinq pos-barcode-scan module._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| barcode | string | Yes | The barcode value as printed/scanned (e.g. 5410317126589) |
| barcodeType | enum | Yes | One of EAN, GTIN, UPC, SSCC, INTERNAL |
| format | string | Yes | Specific format (EAN-13, EAN-8, GTIN-14, UPC-A, SSCC-18, INTERNAL) |
| productSku | string | Yes | FK to Product.sku — the product this barcode identifies |
| uomCode | string | Yes | UN/CEFACT unit code (EA, CA, PL, …) the barcode represents |
| quantity | number | Yes | How many base units this barcode represents (>= 1) |
| isDefault | boolean | No | True when this is the primary/default barcode for scanning |
| isActive | boolean | No | False excludes the barcode from lookup per REQ-SKU-008 |
| notes | string | No | Operator-authored free text |

**Relations:**
- → Product (many-to-one, via `productSku → sku` per inventory-barcode-sku)

**Uniqueness:** `(productSku, barcodeType, uomCode)` — one EAN per UoM per product, but multiple UoMs per product per REQ-SKU-005.

**Additive Product fields (inventory-barcode-sku, REQ-SKU-006):** the Product schema (primary spec: inventory-product-catalog) gains three optional nullable fields here — `skuTemplate` (reference to a SKU generation template in `lib/Settings/sku-templates.json`), `defaultBarcode` (the value scanned by default at POS), and `barcodeFormat` (preferred format for new barcodes on this product). All three are non-breaking — existing Products remain valid.

### BankAccount
**Schema.org:** `schema:BankAccount`
_A bank account held by a Shillinq administration. Carries the IBAN / BIC / bank name plus the optional `primaryCurrency` (ISO 4217, REQ-MC-002) declaration. Existing single-currency deployments may leave `primaryCurrency` null — the system treats null as EUR on read (REQ-MC-001 scenario "Single-currency account without primaryCurrency declared"). Multi-currency tracking is delegated to CurrencyBalance snapshots keyed on (accountId, currency)._
**Primary spec:** bookkeeping-multi-currency (additive `primaryCurrency` field) / bookkeeping-chart-of-accounts (T1 base entity)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountName | string | Yes | Account display name |
| iban | string | Yes | IBAN number |
| bic | string | No | BIC/SWIFT code |
| bankName | string | No | Name of the bank |
| primaryCurrency | string | No | ISO 4217 currency code declaring the native currency (REQ-MC-002); nullable — treated as `EUR` on read (REQ-MC-001) |
| administrationId | string | No | FK to the Administration owning this account |
| lifecycleState | enum | No | One of `active`, `closed`, `frozen` |
| notes | string | No | Free-text operator notes |

**Relations:**
- → CurrencyBalance (one-to-many, via `CurrencyBalance.accountId`)

### BankConnection
**Schema.org:** `schema:FinancialProduct`
_A PSD2 AIS bank connection authorising access to one or more bank accounts via an openconnector aggregator source. Credentials (OAuth tokens, client secrets) live exclusively in openconnector's Source registry; shillinq carries the consent reference only — a non-credential identifier returned from the SCA flow. The connection lifecycle (`pending → active → expiring → expired / revoked`) is declared as `x-openregister-lifecycle` with a time-based `active → expiring` auto-transition firing 14 days before `consentExpiresAt`. Consent renewal routes through openconnector's SCA endpoint (no SCA logic in shillinq). Transaction polling is an OR ScheduledWorkflow (no TimedJob). See `add-shillinq-bank-connectors` change for full rationale._
**Primary spec:** bookkeeping-bank-connectors

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| connectionNumber | string | Yes | Operator-readable reference |
| aggregator | enum | Yes | One of tink, klarna-kosma, plaid-eu, yapily, manual |
| aggregatorSourceSlug | string | Yes | FK to the openconnector Source slug that holds aggregator credentials |
| bankBic | string | Yes | BIC of the bank the connection covers |
| bankCountry | string | Yes | ISO 3166-1 alpha-2 country code |
| bankAccountIban | string | Yes | IBAN of the linked account |
| consentReference | string | Yes | Aggregator-issued consent identifier (non-credential) |
| consentGrantedAt | date-time | Yes | When consent was granted |
| consentExpiresAt | date-time | Yes | When consent expires (PSD2: 90-day max) |
| lastSyncAt | date-time | No | Most recent successful transaction pull |
| lifecycleState | enum | Yes | One of pending, active, expiring, expired, revoked |
| administrationId | string | Yes | FK to the Administration owning this connection |

**Relations:**
- → BankStatement (one-to-many, via bankConnectionId)

> **Reconciliation note (add-shillinq-bank-connectors, 2026-06-01):** `BankConnection` is the T4 PSD2 connectivity record declared in `lib/Settings/shillinq_register.json`. Aggregator credentials are not stored here — they live in openconnector's Source registry, referenced by `aggregatorSourceSlug`. The `consentReference` is the only aggregator-issued field; it is non-credential metadata. Consent renewal routes through openconnector's `reauthorise` source action; no SCA logic exists in shillinq. The `active → expiring` time-based auto-transition fires 14 days before `consentExpiresAt` (declared as `x-openregister-lifecycle.transitions.warnExpiry.timeBased`). Transaction polling is an OR `ScheduledWorkflow` (slug: `shillinq-bank-transaction-polling`), not a per-app TimedJob. See `openspec/changes/add-shillinq-bank-connectors/design.md` decisions D1–D5 for the full rationale.

### BankStatement
**Schema.org:** `schema:Report`
_A bank statement — either generated by the `shillinq-bank-transaction-polling` ScheduledWorkflow (T4 PSD2 path) or imported by an operator from CAMT.053 / MT940 / CSV (T2 bank-reconciliation path). The original file is attached via docudesk. New-statement notifications are declared as `x-openregister-notifications` — no BankNotificationService._
**Primary spec:** bookkeeping-bank-reconciliation (T2 reconciliation lifecycle); bookkeeping-bank-connectors (T4 PSD2 generation)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| statementId | string | Yes | Unique statement identifier (e.g. BS-2026-001) per REQ-BR-002 |
| bankConnectionId | string | No | FK to the BankConnection that sourced this statement (T4 path) |
| statementFormat | enum | No | camt.053.001.08 (T4 path) |
| statementDate | date-time | No | Statement generation timestamp (T4 path) |
| statementAttachmentUri | string | No | docudesk attachment URI for the CAMT.053 XML (T4 path) |
| bankAccountIban | string | No | IBAN of the reconciled bank account per REQ-BR-002 |
| periodStart | date | No | First date covered by the statement per REQ-BR-002 |
| periodEnd | date | No | Last date covered by the statement per REQ-BR-002 |
| openingBalance | number | No | Opening balance per the bank (EUR) per REQ-BR-002 |
| closingBalance | number | No | Closing balance per the bank (EUR) per REQ-BR-002 |
| currency | string | No | ISO 4217 currency code (default EUR) |
| importFormat | enum | No | camt053 / mt940 / csv / manual per REQ-BR-002 |
| importedAt | date-time | No | Operator-upload timestamp per REQ-BR-002 |
| importedBy | string | No | UUID of the uploading user per REQ-BR-002 |
| fileChecksum | string | No | SHA-256 hash for duplicate-import rejection per REQ-BR-009 |
| transactionCount | integer | No | Number of BankStatementLine rows parsed |
| lifecycleState | enum | No | imported / in-progress / reconciled / audit-locked per REQ-BR-008 |
| sourceDocumentUri | string | No | Docudesk FK URI for the original bank-statement file per REQ-BR-002 |
| administrationId | string | Yes | FK to the Administration owning this statement |

**Relations:**
- → BankConnection (many-to-one, via bankConnectionId) — T4 PSD2 path only
- → BankStatementLine (one-to-many, via statementId)

> **Reconciliation note (add-shillinq-bank-reconciliation, 2026-06-08):** The T2 `bookkeeping-bank-reconciliation` change additively overlays the bank-connectors `BankStatement` skeleton with the operator-import fields (`statementId`, `bankAccountIban`, `periodStart`/`periodEnd`, `openingBalance`/`closingBalance`, `importFormat`, `importedAt`/`importedBy`, `fileChecksum`, `lifecycleState`, `sourceDocumentUri`) plus the `imported → in-progress → reconciled → audit-locked` lifecycle and the declarative duplicate-import uniqueness on `(administrationId, fileChecksum)` + `(administrationId, bankAccountIban, periodStart, periodEnd)` range-overlap per REQ-BR-009. Per ADR-037 the overlay ships in `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`; the monolith `lib/Settings/shillinq_register.json` is NOT edited.

### BankingRule
**Schema.org:** `schatkist:BankingRule`
_Configurable compliance criterion evaluated during TreasuryAccount lifecycle transitions per REQ-SCHATKIST-003. Operator-authored per administration; the evaluationCriteria payload shape varies by ruleType (iban-format → pattern, segregation → checkDuplicates, approval-required → requiresTreasurerApproval, transaction-limit → maxAmount, reporting-period → cadence). Severity (blocking/warning/informational) drives whether a failure blocks the transition. Three seed records ship via ConfigurationService::importFromApp per REQ-SCHATKIST-010 (rule-iban-format, rule-segregation, rule-approval-required) and are idempotent on re-import._
**Primary spec:** bookkeeping-schatkistbankieren

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleNumber | string | Yes | Stable identifier per REQ-SCHATKIST-003 |
| name | string | Yes | Human-readable rule name |
| description | string | No | Detailed rule rationale |
| ruleType | enum | Yes | iban-format / segregation / approval-required / transaction-limit / reporting-period |
| evaluationCriteria | object | Yes | Criteria payload — shape varies by ruleType |
| severity | enum | Yes | informational / warning / blocking |
| isActive | boolean | Yes | Whether enforced in the administration |
| administrationId | string | Yes | FK to administration enabling per-org rule customization |

**Relations:**
- → Administration (many-to-one, via administrationId)
- ← TreasuryAccount (many-to-many via lifecycle-precondition evaluation per REQ-SCHATKIST-005)
- ← ComplianceReport (one-to-many — every passing/failing rule appears in ComplianceReport.criteriaResults per REQ-SCHATKIST-006)

### BankStatementLine
**Schema.org:** `schema:MonetaryAmount`
_A single transaction line within a BankStatement per REQ-BR-003. Parsed from CAMT.053, MT940, or manual CSV import. Matched against AR/AP via MatchingRule predicates (REQ-BR-004/REQ-BR-005/REQ-BR-006); unmatched lines route to a designated suspense account (REQ-BR-007). Declares `x-openregister-lifecycle` with states `unmatched → matched` and `unmatched → routed-to-suspense`._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineId | string | Yes | Unique line identifier within the statement |
| statementId | string | Yes | FK to BankStatement.statementId |
| lineNumber | integer | No | Ordinal position within the statement (1-based) |
| valueDate | date | Yes | Value date (boekingsdatum) of the transaction |
| transactionDate | date | No | Transaction date if different from value date |
| amount | number | Yes | Transaction amount (positive=credit, negative=debit, EUR) |
| currency | string | Yes | ISO 4217 currency code (default EUR) |
| remittanceInfo | string | No | Payment reference / omschrijving |
| narrative | string | No | Free-form bank-side description (CAMT AddtlNtryInf / MT940 :86:) |
| counterpartyName | string | No | Name of counterparty |
| counterpartyIban | string | No | IBAN of counterparty |
| endToEndRef | string | No | SEPA end-to-end reference |
| rawPayload | string | No | Raw source-line text preserved for auditor traceability |
| status | enum | Yes | unmatched / matched / routed-to-suspense per REQ-BR-003 |
| reconciliationMatchId | string | No | FK to ReconciliationMatch.matchId once matched |

**Relations:**
- → BankStatement (many-to-one, via statementId)
- → ReconciliationMatch (one-to-one when matched, via reconciliationMatchId)
- → Account (many-to-one routed-to-suspense, via designated `Account.isSuspenseAccount=true`)

### MatchingRule
**Schema.org:** `schema:Action`
_A predicate-based rule that matches bank statement lines against AR/AP invoices or journals per REQ-BR-004/REQ-BR-005. Rule predicates are declared as schema metadata (ADR-031); an `x-openregister-aggregations` query on ReconciliationMatch consumes them to emit candidates. No PHP rule-engine. Per REQ-BR-004 the first matching rule by priority emits the candidate; cross-rule duplicates are blocked._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique rule identifier |
| name | string | Yes | Human-readable name |
| administrationId | string | Yes | FK to the owning Administration |
| priority | integer | Yes | Lower number = higher priority per REQ-BR-004 |
| isActive | boolean | Yes | Whether the rule is active |
| lifecycleState | enum | No | active / disabled / archived per REQ-BR-004 |
| targetType | enum | Yes | ar-invoice / ap-invoice / gl-transaction / customer / vendor |
| autoConfirm | boolean | No | True = auto-confirm candidates; default false (REQ-BR-006) |
| confidenceScore | number | No | Per-rule confidence in [0,1] surfaced on emitted candidates |
| predicates | array | Yes | Ordered predicate objects per REQ-BR-005 (exact-amount, amount-range, reference-match, counterparty-fuzzy, date-window) combined with logical AND |

**Relations:**
- → Administration (many-to-one, via administrationId)

### ReconciliationMatch
**Schema.org:** `schema:Action`
_A candidate or confirmed match between a bank statement line and an AR/AP invoice, GLTransaction, or suspense routing per REQ-BR-006. Emitted by the matching aggregation (confidence=auto) or operator-created (confidence=manual); the operator confirms or rejects through the standard register UI. Confirmed matches emit a `reconciliation-match-confirmed` event consumed by AR/AP invoice lifecycles per REQ-BR-006 (event-driven, no shillinq matcher service forwards the event)._
_**T4 bookkeeping-reconciliation-reports extension (2026-06-09):** the same register schema carries the T4 fields needed by the BankReconciliation session — `reconId` (FK to BankReconciliation), `matchAlgorithm` (exact/fuzzy/manual per REQ-REC-005 — T4 supports `exact` + `manual` only), `confidenceScoreT4`, `matchedAt`, `manualOverride`, `resolutionStatus` + `resolutionReason` (REQ-REC-004 unmatched-item classification: matched/timing/pending/adjustment), and the polymorphic FK shortcuts `arInvoiceId` / `apTransactionId` / `glTransactionId`. The T2 confirm event triggers `ReconciliationMatchToReportListener` which stamps these fields in-place (no parallel match table; see `lib/Settings/register.d/bookkeeping-reconciliation-reports.json`)._
**Primary spec:** bookkeeping-bank-reconciliation
**Extension spec:** bookkeeping-reconciliation-reports

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| matchId | string | Yes | Unique match identifier |
| bankStatementLineId | string | Yes | FK to BankStatementLine.lineId (single-line case) |
| bankLineRefs | array<string> | No | Multi-line case per REQ-BR-006 (N×M cardinality) |
| targetRefs | array<string> | No | Multi-target case per REQ-BR-006 (N×M cardinality) |
| matchType | enum | Yes | ar-invoice / ap-invoice / journal / gl-transaction / suspense |
| matchedObjectId | string | Yes | UUID of the matched object (single-target case) |
| matchedAmount | number | Yes | Amount matched (EUR) |
| partial | boolean | No | True = partial match; AR → partially-paid (REQ-BR-006) |
| confidence | enum | Yes | auto (rule-matched) / manual (operator-created) |
| confidenceScore | number | No | Confidence in [0,1] copied from the emitting MatchingRule |
| status | enum | Yes | pending / confirmed / rejected (lifecycle field) |
| confirmedBy | string | No | UUID of confirming operator |
| confirmedAt | date-time | No | Confirmation timestamp |
| rejectedReason | string | No | Operator-supplied rejection reason |
| reconId | string | No | T4: FK to BankReconciliation session (REQ-REC-005). Empty for pre-T4 records. |
| glTransactionId | string | No | T4: FK shortcut to GLTransaction.id when matchType=gl-transaction/journal |
| bankLineId | string | No | T4: alias of bankStatementLineId for T4 session schema |
| matchAlgorithm | enum | No | T4: exact / fuzzy / manual — T4 supports exact + manual only (REQ-REC-005) |
| confidenceScoreT4 | number | No | T4: confidence in [0,1] for the T4 record (distinct from T2's confidenceScore) |
| matchedAt | date-time | No | T4: when the T4 ReconciliationMatch record was stamped |
| manualOverride | boolean | No | T4: true when operator-created or operator-corrected (REQ-REC-004) |
| resolutionStatus | enum | No | T4: matched / timing / pending / adjustment (REQ-REC-004 classification of unmatched items). Null while unclassified. |
| resolutionReason | string | No | T4: operator-supplied reason for the classification (audit-trailed per REQ-REC-004) |
| arInvoiceId | string | No | T4: FK to ARInvoice.id when the match is AR-based (REQ-REC-005 semantic shortcut) |
| apTransactionId | string | No | T4: FK to APInvoice/APTransaction id when the match is AP-based (REQ-REC-005) |

**Lifecycle:** `pending → confirmed` (emits `reconciliation-match-confirmed` event) / `pending → rejected` (line returns to unmatched).

**Relations:**
- → BankStatementLine (many-to-one or many-to-many for N×M)
- → ARInvoice / APInvoice / GLTransaction / Account-as-suspense (via matchType + matchedObjectId or targetRefs)
- → BankReconciliation (many-to-one via `reconId`, T4)

**T4 Aggregations:**
- `matchesByRecon` — count grouped by `reconId` filtered to `resolutionStatus = matched` (drives BankReconciliation.matchedCount)
- `varianceByType` — count grouped by `(reconId, resolutionStatus)` per REQ-REC-007 variance-by-type
- `unresolvedByRecon` — count grouped by `reconId` filtered to `resolutionStatus IS NULL` (drives REQ-REC-006 pre-close summary)

### BankReconciliation
**Schema.org:** `schema:Report`
_A bounded reconciliation session for one bank account + one statement period per REQ-REC-001 (bookkeeping-reconciliation-reports). Captures opening/closing balances, expected GL balance, computed variance, lifecycle status (draft → in-progress → verified → closed), preparer/verifier signatures, and the closedAt timestamp. Not a live dashboard — an immutable audit artifact once closed. Matching is delegated to T2 bookkeeping-bank-reconciliation; T4 records outcomes through `ReconciliationMatch.reconId` and closes the audit loop. The single PHP seam (ADR-031 §exception) is `OCA\Shillinq\Guard\StatementVerifyGuard` for the cross-object GL-balance lookup the declarative engine cannot yet express._
**Primary spec:** bookkeeping-reconciliation-reports

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bankAccountId | string | Yes | FK to the bank account / IBAN this reconciliation belongs to (REQ-REC-001) |
| statementDate | date | Yes | Statement issue date (period-end by convention) |
| statementPeriodStart | date | Yes | First day of the reconciliation period (inclusive) |
| statementPeriodEnd | date | Yes | Last day of the reconciliation period (inclusive) |
| openingBalance | number | Yes | Statement opening balance in account currency (REQ-REC-001) |
| closingBalance | number | Yes | Statement closing balance in account currency (REQ-REC-001) |
| expectedGLBalance | number | No | Server-computed per REQ-REC-002 formula; populated by StatementVerifyGuard |
| variance | number | No | \|closingBalance - expectedGLBalance\|; server-derived per REQ-REC-002 |
| reconciliationStatus | enum | Yes | draft / in-progress / verified / closed / cancelled (REQ-REC-003) |
| preparedBy | string | No | Nextcloud UID of the operator who initiated the reconciliation (REQ-REC-009) |
| verifiedBy | string | No | Nextcloud UID of the verifier who signed off (REQ-REC-006) |
| closedAt | date-time | No | UTC datetime when the session transitioned `verified → closed` |
| signOffComment | string | No | Verifier sign-off note required on the verify transition (REQ-REC-006) |
| matchedCount | integer | No | Server-derived count of matched ReconciliationMatch records |
| unmatchedGLCount | integer | No | Server-derived count of GL transactions in the period without a confirmed match |
| unmatchedBankCount | integer | No | Server-derived count of bank statement lines without a confirmed match |
| administrationId | string | Yes | FK to Administration; scopes uniqueness of (bankAccountId, statementPeriodEnd) |

**Lifecycle:** `draft → in-progress` (guard: `StatementVerifyGuard::verifyStatementBalance`, REQ-REC-002 — never blocks but persists expectedGLBalance + variance) / `in-progress → verified` (guard: `StatementVerifyGuard::requireResolvedAndSignedOff`, REQ-REC-004 + REQ-REC-006 — rejects when matches unclassified or signOffComment empty) / `verified → closed` (stamps closedAt; immutable thereafter per REQ-REC-003) / `draft → cancelled` (operator abandon) / `in-progress → draft` (operator revert for investigation).

**Relations:**
- → ReconciliationMatch (one-to-many via `reconId`)
- → ReconciliationReport (one-to-one via `reconId` — created on close)
- → Account / BankConnection (many-to-one via `bankAccountId`)
- → Administration (many-to-one via `administrationId`)

**Aggregations:**
- `varianceByAccount` — sum of `variance` grouped by `bankAccountId`, filtered to `reconciliationStatus = closed` (REQ-REC-007 — open reconciliations excluded)
- `varianceByPeriod` — sum of `variance` grouped by `(bankAccountId, statementPeriodEnd)`, filtered to `reconciliationStatus = closed`
- `reconciliationCount` — count grouped by `bankAccountId` filtered to closed

### ReconciliationReport
**Schema.org:** `schema:Report`
_A signed-off audit artifact captured when a BankReconciliation transitions verified → closed per REQ-REC-001/REQ-REC-006 (bookkeeping-reconciliation-reports). Records the final matched/unmatched counts, total variance, preparer + verifier UIDs, and the verifier sign-off comment for permanent retention. Immutable once created. Distinct from `BankReconciliation` (the session) — `ReconciliationReport` is the sealed certificate produced at close._
**Primary spec:** bookkeeping-reconciliation-reports

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reconId | string | Yes | FK to the BankReconciliation this report finalises (REQ-REC-001) |
| reportDate | date-time | Yes | UTC datetime the report was sealed (mirrors parent's closedAt) |
| matchedCount | integer | Yes | Final count of confirmed matches per REQ-REC-006 |
| unmatchedGLCount | integer | Yes | Final count of unmatched GL transactions per REQ-REC-006 |
| unmatchedBankCount | integer | Yes | Final count of unmatched bank lines per REQ-REC-006 |
| totalVariance | number | Yes | Final \|closingBalance - expectedGLBalance\| per REQ-REC-006 |
| preparedBy | string | Yes | Nextcloud UID of the preparer (mirrors parent) |
| verifiedBy | string | Yes | Nextcloud UID of the verifier (mirrors parent) |
| signOffComment | string | No | Verifier sign-off note copied from the parent at close time (REQ-REC-006) |
| administrationId | string | Yes | FK to Administration (mirrors parent) |

**Relations:**
- → BankReconciliation (many-to-one via `reconId`; one-to-one in practice — one report per closed session)
- → Administration (many-to-one via `administrationId`)

**Aggregations:**
- `totalVarianceByAdmin` — sum of `totalVariance` grouped by `administrationId` (REQ-REC-007 admin-level variance dashboard)

### BbvAccountMapping
**Schema.org:** `schema:PropertyValue`
**Primary spec:** bookkeeping-bbv-compliance
_Per-administration mapping that links an RGS account to its BBV (Besluit Begroting en Verantwoording) taakveld + programma + paragraaf + autorisatieniveau (REQ-BBV-002). The mapping is operator-overrideable per administration — one gemeente may map account `4250 Subsidies cultuur` to taakveld `5.3 Cultuurpresentatie`, another to `5.6 Media`. Default mapping is seeded from `lib/Settings/seeds/rgs-to-bbv-mapping.json` for `gemeente`/`provincie`/`waterschap` administrations only (REQ-BBV-006); non-municipal administrations bypass. Per ADR-022 this is a register — not an enum on `Account` and not a parallel link table — and per ADR-031 the BBV-mapping precondition on `GLTransaction.post` is declared via `x-openregister-lifecycle.preconditions` with a single thin guard `OCA\Shillinq\Lifecycle\BbvComplianceGuard::allLinesMappedForMunicipalAdmin`, forward-only by `postingDate` ≥ the app-config-stamped install date (REQ-BBV-003). Carries `bcfCompensable` + `compensablePercentage` driving BCF aggregation (REQ-BCF-004) and `iv3Bucket` driving IV3 export (REQ-IV3-003); both extension fields were declared by sibling changes and are preserved here additively. `_meta.source` (`"seeded"` / `"operator-edited"`) lets the repair step distinguish operator overrides on re-run (REQ-BBV-006)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the owning gemeente/provincie/waterschap administration |
| accountNumber | string | Yes | FK to `Account.accountNumber` (T1) |
| taakveld | string | Yes | BBV taakveld code per `bbv-taakvelden-2024.json` (REQ-BBV-005) |
| programmaCode | string | No | Operator-defined programma code |
| paragraafCode | string | No | Optional paragraaf code |
| autorisatieniveau | enum | No | One of `I`, `II`, `III` — the raadsautorisatie level |
| bcfCompensable | boolean | No | True if postings on this account are BCF-compensable (REQ-BCF-003) |
| compensablePercentage | integer | No | Percentage of VAT recoverable from BCF (0..100, default 0) |
| iv3Bucket | string | No | CBS IV3-bestand bucket name (REQ-IV3-003) |
| _meta.source | enum | No | `"seeded"` or `"operator-edited"` — drives the repair-step idempotency (REQ-BBV-006) |
| _meta.bbvVersion | string | No | BBV revision the mapping was seeded against (e.g. `2024`) |

**Uniqueness:** `(administrationId, accountNumber)` — exactly one mapping per account per administration (REQ-BBV-002).

**Relations:**
- → Administration (many-to-one, via `administrationId`)
- → Account (many-to-one, via `accountNumber`)
- → BbvTaakveld (many-to-one, via `taakveld → BbvTaakveld.code`)

**Aggregations:**
- `byProgrammaCode` — sum of GLLine debit/credit grouped by `BbvAccountMapping.programmaCode` within the administration (REQ-BBV-004)
- `byAutorisatieniveau` — sum of GLLine debit/credit grouped by `BbvAccountMapping.autorisatieniveau` (REQ-BBV-004)
- `byTaakveld` — sum of GLLine debit/credit grouped by taakveld (REQ-BBV-007 roll-up consumed by IV3 export)

### BbvTaakveld
**Schema.org:** `schema:DefinedTerm`
**Primary spec:** bookkeeping-bbv-compliance
_Canonical Besluit BBV bijlage IV taakveld catalogue (REQ-BBV-005). The catalogue evolves at every BBV revision so it is a register, not a hard enum; a future revision ships as `bbv-taakvelden-YYYY.json` with a new `bbvVersion` stamp and coexists with prior revisions. Operators MAY add custom sub-taakvelden alongside the canonical catalogue; re-running the repair step does not delete them (REQ-BBV-005 scenario). Doubles as the enum source for `BbvAccountMapping.taakveld`._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Taakveld code (e.g. `0.1`, `5.3`, `7.1`) |
| description | string | Yes | Taakveld description |
| name | string | No | Official taakveld name per Besluit BBV bijlage IV |
| category | enum | No | Canonical category (`bestuur`, `veiligheid`, `verkeer`, `economie`, `onderwijs`, `sport-cultuur-recreatie`, `sociaal-domein`, `volksgezondheid-milieu`, `vhrosv`, `algemene-dekkingsmiddelen`, `overhead`) |
| legalBasis | string | No | Statutory citation (e.g. `Besluit BBV bijlage IV §0.1`) |
| effectiveFrom | date | No | First date this code is valid (defaults to the BBV revision's effective date) |
| effectiveTo | date | No | Last date this code is valid (null = currently valid) |
| programmaFocus | string | No | Hint linking the taakveld to a typical programma |
| bbvVersion | string | No | BBV catalogue version this entry belongs to (e.g. `2024`) |

**Uniqueness:** `code` per `bbvVersion`.

### BcfClaim
**Schema.org:** `schema:MonetaryAmount`
**Primary spec:** bookkeeping-bcf-vat-compensation
_Quarterly Btw-compensatiefonds (BCF) claim through which a Dutch public body (gemeente, provincie, waterschap) recovers non-recoverable VAT from the central BCF fund per Wet op het btw-compensatiefonds. Distinct from VatReturn (no co-mingling per REQ-BCF-001). The compensable total and per-account breakdown are computed server-side by `OCA\Shillinq\Service\BcfClaimService` from existing GLLine + GLTransaction + BbvAccountMapping data via the real ObjectService API, weighting each compensable account by `BbvAccountMapping.compensablePercentage`; the declarative aggregation shape is documented as `x-openregister-aggregations.compensableVatBreakdown` on the schema. Submission is an OR `ScheduledWorkflow` consuming the `digikoppeling-bcf` OpenConnector source — no app-local HTTP client._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration (gemeente / provincie / waterschap) that owns this claim |
| claimQuarter | string | Yes | Quarter identifier this claim covers (e.g. 2026-Q1); aligns with T2 fiscal period boundaries; forward-only ≥ install date |
| totalCompensableAmount | number | No | Total compensable VAT for the quarter in EUR; derived/read-only, computed by BcfClaimService and frozen at submission |
| breakdown | array | No | Per-account compensable-VAT breakdown rows (`accountNumber`, `amount`, `compensablePercentage`, `compensableAmount`); derived/read-only; frozen at submission |
| state | enum | Yes | One of `draft`, `submitted`, `accepted`, `settled` — driven by the x-openregister-lifecycle state machine |
| submittedOn | datetime | No | Timestamp the claim was submitted to Belastingdienst via DigiKoppeling |
| acceptedOn | datetime | No | Timestamp Belastingdienst accepted the claim |
| settledOn | datetime | No | Timestamp Belastingdienst settled the payment (from the settlement webhook) |
| settledAmount | number | No | Amount settled by Belastingdienst in EUR (from the settlement webhook) |
| attachmentUri | string | No | Reference to uploaded supporting documents (PDF, spreadsheet) for the claim |
| notes | string | No | Operator notes; not transmitted to Belastingdienst |

**Relations:**
- → Administration (many-to-one, via `administrationId`)
- → BbvAccountMapping (many-via-breakdown, via `breakdown[].accountNumber → BbvAccountMapping.accountNumber`)
- → GLLine (aggregated via `(administrationId, claimQuarter)` filtered by `BbvAccountMapping.bcfCompensable=true`)

**Lifecycle (x-openregister-lifecycle):**
- draft → submitted (operator submits; guarded by `OCA\Shillinq\Lifecycle\BcfClaimGuard::canSubmit` — REQ-BCF-003 server-authoritative recomputation: non-empty compensable total + closed claim quarter)
- submitted → accepted (Belastingdienst event; no local guard)
- accepted → settled (settlement webhook from `digikoppeling-bcf` source; manual fallback when webhook is lost)

**Submission:** OR ScheduledWorkflow (cron `0 0 1 */3 *`) via OpenConnector `digikoppeling-bcf` source (ADR-019). No app-local HTTP client.

**Additive BbvAccountMapping fields (bookkeeping-bcf-vat-compensation, REQ-BCF-004):** the BbvAccountMapping schema (primary spec: bookkeeping-bbv-compliance / bookkeeping-operations) gains two optional fields here — `bcfCompensable` (boolean, default `false`; whether VAT posted to this account is eligible for BCF compensation) and `compensablePercentage` (integer 0–100, default `100`; weighting for mixed-use accounts). Both are non-breaking — existing mappings remain valid with VAT excluded by default.

### Bevinding
**Schema.org:** `schema:Report`
_An ENSIA compliance finding — risk, shortcoming, or improvement opportunity identified from VNG norm comparison. Auto-generated when maturity score < VNG normniveau; tracked through mitigation lifecycle._
**Primary spec:** bookkeeping-ensia-zelfevaluatie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| cyclusId | string | Yes | FK to ENSIAJaarcyclus (register relation) |
| vraagId | string | No | FK to Evaluatievraag (nullable; null for manually added findings) |
| type | enum | Yes | Finding type: tekortkoming, verbeterpunt, risico-acceptatie |
| beschrijving | string | Yes | Finding description and context (auto-populated from question + score gap) |
| impact | string | No | Impact assessment of the identified risk |
| kans | string | No | Likelihood assessment of the risk materialising |
| mitigatieActie | string | No | Planned mitigation action description |
| verantwoordelijke | string | No | User-reference: owner responsible for mitigation |
| streefDatum | date | No | Target date for mitigation or acceptance |
| status | enum | Yes | Mitigation status: open, in-behandeling, gerealiseerd, geaccepteerd |
| administrationId | string | Yes | FK to Administration owning this finding |

**Relations:**
- → ENSIAJaarcyclus (many-to-one)
- → Evaluatievraag (many-to-one, nullable)

### Bid
**Schema.org:** `schema:Offer`
_A supplier's response to a tender with proposed pricing and terms; includes sealed bid handling and multi-round bidding_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bidNumber | string | Yes | Unique identifier for the bid |
| submissionDate | datetime | Yes | Date and time the bid was submitted |
| amount | number | No | Bid price or quote amount |
| currency | string | No | Currency code for the bid |
| status | string | Yes | Status: submitted, received, under review, evaluated, accepted, rejected, withdrawn |
| isSealed | boolean | No | Whether the bid is encrypted for sealed bid opening |
| evaluationScore | number | No | Numerical score assigned during evaluation |
| evaluationRank | number | No | Ranking relative to other bids (1=best) |
| notes | string | No | Evaluation comments or clarifications |

**Relations:**
- → Tender (many-to-one)
- → TenderLot (many-to-one)
- → Organization (many-to-one)
- → DigitalDocument (one-to-many)
- → BiddingRound (many-to-one)

### BidEvaluation
**Schema.org:** `schema:Event`
_Automated evaluation process for competitive bids with configurable scoring criteria and rules_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Evaluation process name or tender reference |
| description | string | No | Procurement description and requirements |
| startDate | date | Yes | Evaluation opening/start date |
| endDate | date | Yes | Evaluation closing/completion date |
| evaluationCriteria | json | Yes | Configurable criteria (price weighting, quality factors, technical specs) |
| scoringRules | json | No | Automated scoring formulas and calculation rules |
| minimumScore | number | No | Minimum threshold score to qualify for award |

**Relations:**
- → SupplierBid (one-to-many)
- → AwardDecision (one-to-one)

### BiddingRound
**Schema.org:** `schema:Thing`
_A round of bidding within a multi-round procurement process, supporting sequential RFQ, RFP, and reverse auction workflows_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| roundNumber | number | Yes | Sequential round number within the tender |
| roundType | string | No | Type: initial, clarification, final, auction, or negotiation |
| startDate | datetime | Yes | Start date of the bidding round |
| closingDate | datetime | Yes | Deadline for submissions in this round |
| status | string | Yes | Status: pending, open, closed, evaluated, completed |
| minBidReduction | number | No | Minimum bid reduction required for auction rounds |
| extensionEnabled | boolean | No | Whether extension of deadlines is allowed |

**Relations:**
- → Tender (many-to-one)
- → Bid (one-to-many)

### BlanketPurchaseOrder
**Schema.org:** `schema:Order`
_Master purchase order with authorized spend limit, scheduled release management, and consumption tracking for blanket purchasing arrangements_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| blanketPoNumber | string | Yes | Unique blanket PO identifier |
| validFrom | datetime | Yes | Blanket PO effective start date |
| validUntil | datetime | Yes | Blanket PO expiration date |
| totalAuthorizedAmount | number | Yes | Total authorized spend limit |
| consumedAmount | number | No | Amount spent against blanket PO to date |
| remainingAmount | number | No | Remaining authorized spend |
| releaseSchedule | array | No | Scheduled release dates and amounts |
| status | string | Yes | active, closed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → ProcurementCatalog (many-to-one)
- → PurchaseOrder (one-to-many)
- → ApprovalRequest (many-to-one)

### Branch
**Schema.org:** `schema:LocalBusiness`
_Physical or organizational branch location for branch-wise tracking of payments, inventory, sales, and purchasing_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes |  |
| address | string | Yes |  |
| city | string | Yes |  |
| province | string | Yes |  |
| branchType | string | No | main office, warehouse, retail, etc |
| headcount | number | No |  |
| status | string | Yes | active, inactive, planned |
| establishedDate | datetime | No |  |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)

### Budget
_A financial plan allocating resources for a specific period, organization, and location_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| budgetName | string | Yes | Name or identifier of the budget |
| totalAmount | number | Yes | Total budgeted amount in the specified currency |
| startDate | datetime | Yes | Date when the budget becomes effective |
| endDate | datetime | Yes | Date when the budget expires |
| description | string | No | Detailed description or purpose of the budget |
| currency | string | Yes | Currency code (ISO 4217), defaults to EUR for Dutch organizations |
| budgetCategory | string | Yes | Category of the budget (e.g., operational expenses, capital expenses, revenue) |
| amountSpent | number | No | Current amount spent or committed against this budget |
| alertThreshold | number | No | Percentage (0-100) at which to trigger spending alerts |
| budgetType | string | No | Type of budget (fixed, flexible, rolling, zero-based) |
| fiscalYear | integer | Yes | Fiscal year this budget applies to (e.g., 2026) |
| costCenter | string | No | Cost center or department code for budget allocation |
| attachments | array | No | Supporting documents and justification files |

**Relations:**
- → Organization (many-to-one)
- → Location (many-to-one)
- → Person (many-to-one)
- → BudgetPeriod (many-to-one)
- → BudgetAllocation (one-to-many)
- → BudgetAmendment (one-to-many)
- → ExpenditureRequest (one-to-many)

**Reconciliation note — `bookkeeping-reconciliation-reports` (T4 envelope, REQ-RR-004):** the shillinq capability `bookkeeping-reconciliation-reports` introduces an **account-period budget shape** alongside the existing programme-level `Budget` documented above. The account-period shape — `(accountNumber, periodId, budgetAmount, currency, administrationId, lifecycleState)` with FK `accountNumber → Account.accountNumber` — is the join target for the variance-vs-actual saved-query that compares aggregated `GLLine` activity to budgeted amounts at the account-per-period grain (REQ-RR-004). Two prior `Budget` declarations already ship in `lib/Settings/register.d/` fragment files — `bookkeeping-verplichtingenadministratie.json` (commitment shape: `geautoriseerd_bedrag` / `gerealiseerd_bedrag` / `openstaande_verplichtingen` / `vrije_ruimte`) and `bookkeeping-provincies-bbv-variant.json` (BBV-programme shape: `budgetName` / `totalAmount` / `programmaStructure` / `fiscalYear`) — neither of which carries the account-per-period fields the variance query needs; the implementing cycle (separate `opsx-apply` against `bookkeeping-reconciliation-reports`) decides whether to add a third Budget fragment owned by the reconciliation-reports capability or to extend one of the existing shapes (Task 5 carries the discovery-deferred decision). The four reconciliation saved queries (sub-ledger ↔ GL control match, intercompany match, variance vs Budget, controller exception report) declared by this capability per REQ-RR-002 / -003 / -004 / -005 are consumed by launchpad via runtime GraphQL on OR's schema with no install-time dependency in either direction per ADR-022 and `feedback_launchpad-no-or-dependency.md`; cites ADR-031 (saved-query / `x-openregister-aggregations` not a PHP `ReportingService`; severity as calculated field; cross-administration intercompany match and cross-schema budget-variance join carry a conditional single-method PHP guard fallback if engine cannot express declaratively — `lib/Aggregation/IntercompanyMatchGuard.php` and `lib/Aggregation/BudgetVarianceJoinGuard.php`, ADR-031-exception-annotated, ~20 LOC each).

### BudgetAllocation
_A subdivision of budget resources allocated to a specific department, funding source, or purpose_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| allocationNumber | string | Yes | Unique identifier for the allocation |
| amount | number | Yes | Allocated amount |
| status | string | Yes | Status: pending, approved, allocated, spent, closed |
| description | string | No | Details about the allocation |

**Relations:**
- → Budget (many-to-one)
- → FundingSource (many-to-one)
- → Organization (many-to-one)

### BudgetAmendment
_A proposed or executed change to an approved budget amount_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amendmentNumber | string | Yes | Unique identifier for the amendment |
| originalAmount | number | Yes | Original budgeted amount |
| newAmount | number | Yes | Revised budget amount |
| reason | string | Yes | Reason for the amendment |
| status | string | Yes | Status: proposed, pending_approval, approved, rejected, executed |
| effectiveDate | datetime | No | When amendment takes effect |

**Relations:**
- → Budget (many-to-one)
- → ApprovalRequest (many-to-one)

### BudgetPeriod
_A defined time period for budget planning, such as fiscal year, calendar year, or quarter_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the period (e.g., 'FY2024', 'Q1 2024') |
| type | string | Yes | Period type: fiscal_year, calendar_year, quarter, month, or custom |
| startDate | datetime | Yes | Period start date |
| endDate | datetime | Yes | Period end date |
| fiscalYear | string | No | Associated fiscal year (e.g., '2024') |

**Relations:**
- → Budget (one-to-many)

### CallOffOrder
**Schema.org:** `schema:Order`
_An order placed against a blanket or framework agreement, with delivery scheduling and consumption tracking_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callOffNumber | string | Yes | Unique call-off order number |
| orderDate | datetime | Yes | Date the call-off order was created |
| status | string | Yes | Status: draft, issued, accepted, in progress, partially delivered, delivered, closed |
| orderedQuantity | number | No | Total quantity ordered |
| consumedQuantity | number | No | Quantity already delivered or consumed |
| unitPrice | number | No | Unit price for items |
| totalAmount | number | No | Total order amount |
| currency | string | No | Currency code |
| deliverySchedule | array | No | Planned delivery dates and quantities |

**Relations:**
- → Order (many-to-one)
- → Organization (many-to-one)
- → Product (many-to-many)

### CBSLine
**Schema.org:** `schema:MonetaryAmount`
_Aggregated financial line item within a CBSSubmission, summing GL transactions whose account number falls within a configured account range and classified by CBS line code (Revenue, OperatingCosts, Depreciation, Interest, Taxes, OtherIncome, OtherExpenses). Computed by CBSExportService from Chart-of-Accounts + general-ledger data per REQ-CBS-002._
**Primary spec:** cbs-bestanden-extended

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| cbsSubmissionId | string | Yes | FK to CBSSubmission |
| cbsLineClassification | enum | Yes | One of Revenue, OperatingCosts, Depreciation, Interest, Taxes, OtherIncome, OtherExpenses |
| cbsLineNumber | string | Yes | Official CBS line number in IV3 format (e.g., 1000, 2000) |
| accountRangeStart | string | Yes | GL account code range start (e.g., 4000) |
| accountRangeEnd | string | Yes | GL account code range end (e.g., 4999) |
| aggregatedAmount | number | Yes | Sum of GL line amounts for this range (MonetaryAmount, integer-cent EUR) |
| glLineCount | integer | No | Number of GL lines aggregated (auditing aid) |
| currency | string | Yes | ISO 4217 currency code |
| description | string | No | Notes on the aggregation logic or variance |

**Relations:**
- → CBSSubmission (many-to-one, via cbsSubmissionId)

**Reconciliation:** Reconciles bookkeeping-cbs-bestanden-extended REQ-CBS-002 with the canonical CBS IV3-extended line taxonomy. Computed by CBSExportService::generateSubmission(); never edited directly by operators.

### CBSSubmission
**Schema.org:** `schema:GovernmentService`
_Complete CBS (Centraal Bureau voor de Statistiek) IV3-extended statistical submission package for a reporting period. Header tracking submission metadata (number, period, organization KvK + tax id, lifecycle state, generated file reference). Lifecycle covers draft → validated → submitted → accepted/rejected per REQ-CBS-003. Distinct from Iv3Export (overheid IV3 quarterly cbs-iv3 source) — this is the SMB/sole-proprietor extended annual flow per Verordening Statistieken Bedrijven._
**Primary spec:** cbs-bestanden-extended

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| submissionNumber | string | Yes | Unique CBS submission identifier (e.g., CBS-2025-001) |
| reportingPeriodStartDate | date | Yes | First day of the reporting period |
| reportingPeriodEndDate | date | Yes | Last day of the reporting period |
| organizationLegalName | string | Yes | Legal name of the reporting organization |
| kvkNumber | string | Yes | Dutch Chamber of Commerce registration number (`^[0-9]{8}$`) |
| taxIdentificationNumber | string | Yes | Dutch VAT/BTW identification number (`^NL[0-9]{10}B[0-9]{2}$`) |
| administrationId | string | Yes | FK to Administration |
| status | enum | Yes | One of draft, validated, submitted, accepted, rejected |
| submissionDate | datetime | No | Timestamp when submission was sent to CBS |
| iv3FileUri | string | No | OpenRegister file reference to the generated IV3 JSON package |
| iv3Checksum | string | No | SHA-256 checksum of the generated IV3 file content |
| validationErrors | array | No | Recorded validation errors blocking the validate transition |
| description | string | No | Optional operator notes |
| currency | string | No | ISO 4217 currency, default EUR |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → CBSLine (one-to-many, via cbsSubmissionId; aggregated GL lines)

**Lifecycle (x-openregister-lifecycle):**
- draft → validated (validate transition runs structural + balancing validation, attaches IV3 file)
- validated → submitted (operator submits IV3 file to CBS portal; records submission timestamp)
- submitted → accepted (CBS feedback received; manual operator update or webhook)
- submitted → rejected (CBS feedback received; correction submission required)

**Reconciliation:** Reconciles bookkeeping-cbs-bestanden-extended REQ-CBS-001 / REQ-CBS-003 / REQ-CBS-006 / REQ-CBS-007. Distinct from Iv3Export (overheid IV3 quarterly) and IV3Report (SMB IV3 quarterly) — CBSSubmission is the annual IV3-extended statistical aggregation flow. Audit-trail + 10-year retention per Archiefwet (REQ-CBS-010).

### CashAccount
**Schema.org:** `schema:BankAccount`
_Track bank accounts, petty cash, and cash equivalents for liquidity management and multi-account consolidation_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountType | string | Yes | BankAccount, PettyCash, or CashEquivalent |
| accountCode | string | Yes | Internal GL account code |
| riskLevel | string | No | Low, Medium, High |

**Relations:**
- → Organization (many-to-one)

### CashFlowItem
**Schema.org:** `schema:MonetaryAmount`
_Kasstroomregel geclassificeerd naar IV3 (hoofdstuk-functie-categorie) per REQ-EMU-010. Shared entity with bookkeeping-iv3-reporting: one classified dataset feeds both the IV3-kwartaalaangifte and the EMU-aangifte; EMU filters on `kasOfTransactiebasis="kas"`. Carries the betaalmoment (kasmoment) and an optional factuurmoment used to detect transactiemoment-corrections (Wet Hof art. 3, REQ-EMU-002). All bedrag values are MonetaryAmount in integer-cent precision per ADR-022 (negative = uitstroom)._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to EMUReport |
| datum | date | Yes | Transaction (kas) date |
| bedrag | number | Yes | Cash amount in cents (negative = uitstroom) |
| iv3 | object | No | hoofdstuk/functie/categorie per CBS IV3-taxonomie |
| taakveld | string | No | BBV taakveld code |
| tegenrekening | object | No | leverancier/klant/begunstigde + naam + nummer |
| kasOfTransactiebasis | enum | Yes | kas / transactie |
| betaalmoment | datetime | No | Actual cash transaction timestamp |
| factuurmoment | datetime | No | Invoice date when different from cash date |
| currency | string | No | ISO 4217 currency (default EUR) |

**Relations:**
- → EMUReport (many-to-one)
- → Account (many-to-one, optional)

### CatalogItem
**Schema.org:** `schema:Product`
_Individual product or service in a procurement catalog with pricing, availability, lead time, and purchase price information_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| itemCode | string | Yes | Unique item code within catalog |
| itemName | string | Yes | Display name of the item |
| description | string | No | Detailed item description |
| basePrice | number | Yes | Base unit price |
| unit | string | Yes | Pricing unit: piece, kg, liter, hour, etc |
| minimumQuantity | number | No | Minimum order quantity |
| leadTime | number | No | Delivery lead time in days |
| status | string | Yes | active, discontinued |
| validFrom | datetime | No |  |
| validUntil | datetime | No |  |

**Relations:**
- → ProcurementCatalog (many-to-one)
- → Product (many-to-one)
- → PricingRule (one-to-many)

### ChargebackDispute
**Schema.org:** `schema:Service`
_A chargeback dispute tracking status, evidence, and resolution of payment disputes and chargebacks_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| disputeNumber | string | Yes | Unique dispute identifier |
| chargebackReference | string | Yes | Associated chargeback reference from payment processor |
| status | string | Yes | Status: filed, under-review, resolved, won, or lost |
| filedDate | datetime | Yes | Date the dispute was filed |
| resolutionDate | datetime | No | Date the dispute was resolved |
| disputeAmount | number | Yes | Amount in dispute |
| disputeReason | string | Yes | Reason for the chargeback |

**Relations:**
- → Payment (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)
- → Person (many-to-one)

### ComplianceAssessment
**Schema.org:** `schema:QualitativeRating`
_Assessment of EU Directive 2014/24/EU compliance for procurement activities_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentNumber | string | Yes | Unique assessment reference number |
| assessmentDate | datetime | Yes | Date of compliance assessment |
| complianceStatus | string | Yes | Status: compliant, non-compliant, partial, pending |
| riskLevel | string | Yes | Risk assessment: low, medium, high, critical |
| findings | array | No | List of compliance findings or violations |
| recommendedActions | string | No | Recommended corrective actions |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Organization (many-to-one)
- → ComplianceRisk (one-to-many)

### ComplianceAudit
**Schema.org:** `schema:Event`
_A formal compliance audit documenting findings, risks, and remediation tracking with management letter outcomes_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditNumber | string | Yes | Unique audit number |
| auditType | string | Yes | Type of audit: internal, external, or regulatory |
| status | string | Yes | Audit status: planned, in-progress, completed, or draft |
| startDate | datetime | Yes | Audit start date |
| endDate | datetime | No | Audit completion date |
| scope | string | No | Audit scope and objectives |

**Relations:**
- → AuditFinding (one-to-many)
- → ManagementLetter (one-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### ComplianceDocument
**Schema.org:** `schema:DigitalDocument`
_Audit evidence and compliance documentation (policies, procedures, attestations)_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| complianceArea | string | Yes | Compliance domain (e.g., accounting, GDPR, tax, labor) |
| category | enum | Yes | Type: policy, procedure, evidence, or attestation |
| required | boolean | Yes | Is this document mandatory |
| expiryDate | date | No | Review or validity expiration date |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### ComplianceReport
**Schema.org:** `schema:Report`
_Analytics report tracking obligation and payment compliance metrics, supporting 99% on-time settlement performance goal and PowerBI dashboards_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Reporting period (e.g., 2026-Q1 or 2026-01) |
| generatedDate | date | Yes | Date report was generated |
| complianceRate | number | Yes | Percentage of obligations settled on-time (0-100) |
| totalObligations | integer | Yes | Total obligations in period |
| onTimeObligations | integer | Yes | Obligations settled by due date |
| overdueObligations | integer | No | Obligations settled after due date |
| totalAmount | MonetaryAmount | No | Total financial value of all obligations |
| averagePaymentDays | number | No | Average days to payment after due date (negative = early) |
| powerBiUrl | string | No | URL to Power BI dashboard for this report |

**Relations:**
- → Obligation (one-to-many)
- → Payment (one-to-many)
- → SettlementDecision (one-to-many)

**Additive fields (bookkeeping-schatkistbankieren, REQ-SCHATKIST-006):** The same `ComplianceReport` register is reused as the schatkistbankieren compliance snapshot. The two capabilities scope reports by `administrationId` + the natural primary spec key (settlement metrics vs. schatkist criteria) so they never collide. The schatkistbankieren capability adds the optional fields `reportNumber` (sequential per administration), `treasuryAccountId` (FK to TreasuryAccount; null when aggregated), `complianceScore` (declarative x-openregister-calculations, 0-100 weighted rule-match), `criteriaResults` (calculated array of per-BankingRule pass/fail), `status` (draft / reviewed / approved-for-export / exported), `regulatoryExportFormat` (csv-master-list / xml-regulatory / json-audit), `regulatoryExportUri` (docudesk FK), and `administrationId` (Yes for schatkist usage). Three aggregations are declared per REQ-SCHATKIST-007 (complianceByAdministrationAndPeriod, complianceByRuleType, agingByLastCompliantDate over TreasuryAccount).

### ComplianceRisk
**Schema.org:** `schema:Report`
_Risk assessment for regulatory, operational, and compliance threats with mitigation tracking_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskName | string | Yes | Risk title |
| riskCategory | enum | Yes | Category: regulatory, operational, financial, or strategic |
| description | text | Yes | Risk description and context |
| probability | enum | Yes | Likelihood: remote, low, medium, high, or certain |
| impact | enum | Yes | Potential impact: negligible, minor, moderate, major, or critical |
| mitigations | text | No | Controls and mitigation strategies |

**Relations:**
- → Organization (many-to-one)
- → ComplianceDocument (one-to-many)

### ComplianceAuditTrail
**Schema.org:** `schema:Event`
_Auditor working log per administration tracking SiSa audit findings (critical/major/minor), governance observations, and remediation status per REQ-SISA-005. Referenced by SisaReport aggregation to compute finding counts and overall opinion._
**Primary spec:** bookkeeping-sisa-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| trailNumber | string | Yes | Unique compliance audit trail identifier |
| administrationId | string | Yes | FK to Administration under audit |
| fiscalYear | integer | Yes | Fiscal year under audit |
| findingNumber | string | No | Reference number of an individual audit finding |
| findingSeverity | enum | No | One of: critical, major, minor |
| findingDescription | text | No | Detailed description of the audit finding |
| observationNumber | string | No | Reference number of a governance observation |
| observationDescription | text | No | Governance improvement observation |
| remediationDueDate | date | No | Target date for remediation completion |
| remediationStatus | enum | No | One of: pending, in-progress, completed, overdue |
| remediationCompletionDate | date | No | Date remediation was completed |
| auditorName | string | No | Auditor or audit firm name |
| auditDate | date | Yes | Date of the audit fieldwork or finding issuance |
| status | enum | Yes | One of: draft, submitted, closed |

**Relations:**
- → Administration (many-to-one)
- → SisaReport (many-to-one, via administrationId + fiscalYear aggregation)

> **Deduplication note:** `AuditFinding` (primary spec: compliance-audit) is the baseline data-model entity for individual findings. `ComplianceAuditTrail` is the SiSa-specific aggregation and working-log register that contains findings per administration/fiscal year. They coexist: `AuditFinding` is the data-model baseline; `ComplianceAuditTrail` is the SiSa-specific register.

### ConsentRecord
**Schema.org:** `schema:Action`
_A record of regulatory consent (PSD2, GDPR, etc.) with renewal tracking and compliance management_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| consentNumber | string | Yes | Unique consent identifier |
| consentType | string | Yes | Type of consent: PSD2, GDPR, or other |
| status | string | Yes | Status: active, revoked, expired, or pending-renewal |
| grantedDate | datetime | Yes | Date consent was granted |
| expiryDate | datetime | No | Date consent expires |
| renewalDueDate | datetime | No | Date when renewal is due |
| scope | string | No | Scope and purpose of granted consent |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### ConsolidatedReport
**Schema.org:** `schema:Report`
_A consolidated financial report combining data from multiple organizations with automatic inter-company eliminations_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the consolidated report |
| reportDate | datetime | Yes | Date of the consolidated report |
| consolidationMethod | string | Yes | Method used for consolidation |
| status | string | Yes | Status (draft, finalized, published, archived) |
| eliminationsApplied | boolean | No | Whether inter-company eliminations have been applied |
| isPublished | boolean | No | Whether the consolidated report is published |

**Relations:**
- → ConsolidationGroup (many-to-one)
- → FiscalYear (many-to-one)
- → BalanceSheet (one-to-many)

### ConsolidationGroup
**Schema.org:** `schema:Organization`
_A group of organizations consolidated together for consolidated financial reporting across administrations_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the consolidation group |
| consolidationMethod | string | Yes | Method used for consolidation (full, proportional, equity) |
| status | string | Yes | Status of the consolidation group |
| parentOrganization | string | No | Parent organization identifier |
| eliminationRules | object | No | Consolidation elimination rules for inter-company transactions |

**Relations:**
- → Organization (one-to-many)
- → ConsolidatedReport (one-to-many)

### Contract
**Schema.org:** `schema:DigitalDocument`
_Legal contract document with spend tracking, approval routing, and full-text search capability_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| contractNumber | string | Yes | Unique contract reference number |
| description | string | Yes | Contract description and summary |
| contractValue | number | Yes | Total contract value in specified currency |
| currency | string | Yes | Currency code (e.g., EUR) |
| startDate | datetime | Yes | Contract start date |
| endDate | datetime | Yes | Contract end date |
| contractType | string | Yes | Type of contract (e.g., Service, Supply, Lease, Maintenance) |
| counterpartyName | string | Yes | Name of the supplier, vendor, or counterparty |
| counterpartyNumber | string | No | Supplier/customer registration or reference number |
| paymentTerms | string | Yes | Payment terms (e.g., Net 30, 2/10 Net 30) |
| invoiceFrequency | string | Yes | Billing frequency (e.g., monthly, quarterly, annual, one-time) |
| taxPercentage | number | Yes | Applicable VAT or tax percentage |
| contractDocument | file | No | Signed contract document or PDF |
| nextReviewDate | datetime | No | Date for next contract review or renewal consideration |
| vestigingsnummer | string | No | Dutch business establishment number (vestigingsnummer KvK) |
| renewalOption | boolean | No | Whether contract has automatic renewal or renewal option |
| bankAccount | string | No | Counterparty IBAN for payment processing |

**Relations:**
- → ContractParty (many-to-many)
- → ApprovalRoute (many-to-one)
- → ContractRedline (one-to-many)
- → ContractSpendRecord (one-to-many)

### ContractClause
**Schema.org:** `schema:Thing`
_Reusable clause with version control for contract assembly and updates_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Clause name or identifier |
| text | string | Yes | Full clause text and provisions |
| version | number | Yes | Clause version number |
| category | string | No | Category (Payment, Liability, Termination, IP, etc.) |
| status | string | Yes | Status (active, archived, deprecated) |
| createdDate | datetime | Yes | Date clause was created |

**Relations:**
- → ContractTemplate (many-to-one)

### ContractMilestone
**Schema.org:** `schema:Event`
_Milestone within contract lifecycle with KPI targets and performance monitoring_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the milestone |
| description | string | No | Description of milestone objectives |
| dueDate | datetime | Yes | Target completion date |
| status | string | Yes | Status (pending, in-progress, completed, at-risk, blocked) |
| kpiTarget | number | No | Target KPI or metric value |
| actualValue | number | No | Actual KPI value achieved |

**Relations:**
- → Contract (many-to-one)
- → ContractObligation (one-to-many)

### ContractModification
**Schema.org:** `schema:UpdateAction`
_Amendments, changes, and modifications to contracts with audit trail and approval_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the modification or amendment |
| description | string | Yes | Details of what was modified |
| modificationDate | datetime | Yes | Date modification was made |
| type | string | Yes | Modification type (amendment, extension, material-change, termination-notice) |
| status | string | Yes | Status (draft, proposed, approved, rejected, executed) |
| reason | string | No | Business reason for modification |

**Relations:**
- → Contract (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (many-to-one)

### ContractObligation
**Schema.org:** `schema:Action`
_Tracked obligations and deliverables within a contract with completion status_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the obligation or deliverable |
| description | string | No | Detailed description of deliverables and requirements |
| dueDate | datetime | Yes | Due date for the obligation |
| status | string | Yes | Status (pending, in-progress, completed, overdue) |
| priority | string | No | Priority (low, medium, high, critical) |
| completionDate | datetime | No | Actual completion date |

**Relations:**
- → Contract (many-to-one)
- → Person (many-to-one)
- → ContractMilestone (many-to-one)

### ContractParty
**Schema.org:** `schema:Organization`
_Organization party to a contract with banking and contact details_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal name of organization |
| kvkNumber | string | No | Dutch Chamber of Commerce registration number |
| vatID | string | No | VAT identification number |
| email | string | No | Organization email address |
| iban | string | No | International Bank Account Number for payments |
| role | string | No | Party role (Vendor, Service Provider, Client) |

### ContractPerformance
**Schema.org:** `schema:Thing`
_Performance metrics, KPIs, and analytics for contract monitoring and risk assessment_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| metricName | string | Yes | Name of the performance metric or KPI |
| metricValue | number | Yes | Current or actual metric value |
| targetValue | number | No | Target or baseline value |
| reportingDate | datetime | Yes | Date of the performance measurement |
| status | string | Yes | Performance status (on-track, at-risk, exceeded, failed) |
| notes | string | No | Context or analysis notes |

**Relations:**
- → Contract (many-to-one)
- → Report (many-to-one)

### ContractRedline
**Schema.org:** `schema:DigitalDocument`
_AI-powered and manual suggested changes to contract terms with risk severity_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| description | string | Yes | Description of suggested change or issue |
| originalText | string | No | Original contract text being flagged |
| suggestedText | string | No | Proposed replacement text |
| lineNumber | number | No | Line number in contract |
| aiGenerated | boolean | No | True if suggested by automated redlining system |
| severity | string | No | Risk level (Low, Medium, High, Critical) |

**Relations:**
- → Contract (many-to-one)

### ContractRenewal
**Schema.org:** `schema:Event`
_Renewal period management with proactive notification and renegotiation tracking_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| renewalDate | datetime | Yes | Date when renewal becomes effective |
| notificationDate | datetime | Yes | Date when renewal notification must be sent |
| negotiationDeadline | datetime | No | Deadline for renewal negotiations |
| status | string | Yes | Renewal status (pending, in-negotiation, approved, completed, cancelled) |
| automaticRenewal | boolean | No | Whether contract auto-renews without action |
| renewalTerms | string | No | Conditions or terms for renewal |

**Relations:**
- → Contract (many-to-one)
- → Organization (many-to-one)

### ContractSpendRecord
**Schema.org:** `schema:Order`
_Invoice and payment record for contract spend dashboard and financial tracking_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier |
| invoiceDate | date | Yes | Date invoice was issued |
| amount | number | Yes | Invoice amount |
| currency | string | No | ISO 4217 currency code |
| paymentDate | date | No | Date payment was made |
| paymentTerms | string | No | Payment terms (e.g., Net 30) |
| description | string | No | Invoice line items and details |

**Relations:**
- → Contract (many-to-one)
- → ContractParty (many-to-one)

### ContractTemplate
**Schema.org:** `schema:CreativeWork`
_Reusable template for contract authoring with predefined structure and clause library_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the contract template |
| description | string | No | Purpose and use cases for this template |
| category | string | No | Contract type (Service, Purchase, Employment, NDA, etc.) |
| templateContent | string | Yes | Template structure and markup |
| status | string | Yes | Template status (active, archived, deprecated) |
| createdDate | datetime | Yes | Date template was created |

**Relations:**
- → ContractClause (one-to-many)
- → Organization (many-to-one)

### Corporation
**Schema.org:** `schema:Organization`
_A registered Dutch business entity (BV, NV, eenmanszaak, CV) with independent tax and legal obligations. Core entity for multi-entity management._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official registered business name |
| tradeName | string | No | Trading name if different from legal name |
| kvkNumber | string | Yes | Dutch Chamber of Commerce (KvK) registration number |
| vatID | string | No | Dutch VAT number (BTW-nummer) |
| iban | string | No | Primary business bank account IBAN |
| businessType | string | Yes | Legal form: eenmanszaak, CV, BV, NV, CVOA, Vennootschap onder firma |
| foundationDate | date | Yes | Official business establishment date |
| dissolutionDate | date | No | Date business was closed (if applicable) |

**Relations:**
- → Shareholder (one-to-many)
- → Administration (one-to-many)
- → JointVenture (many-to-many)

### CostAllocation
**Schema.org:** `schema:Offer`
_Transaction allocating or distributing costs from one cost center to another, with version control for model changes and multi-dimensional analysis_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Description or name of the allocation |
| allocationDate | datetime | Yes | Effective date of the allocation |
| sourceAmount | number | Yes | Total amount to allocate |
| allocationPercentage | number | No | Percentage of source amount allocated |
| allocationAmount | number | No | Calculated allocated amount |
| period | string | Yes | Period type: monthly, quarterly, yearly |
| status | string | Yes | Status: draft, approved, or allocated |
| version | number | Yes | Version number for change tracking and rollback |
| description | string | No |  |

**Relations:**
- → CostCenter (many-to-one)
- → CostCenter (many-to-one)

### CostCenter
**Schema.org:** `schema:Organization`
_An analytical cost center (kostenplaats) for tracking, allocating, and analysing departmental or functional expenses. Declared as an OR-managed register per REQ-CC-001 and REQ-CC-002. Hierarchy is navigable via the parentCode self-relation. The same shape is shared by KostenDrager and Project; the distinction is semantic per Dutch GAAP. Segment P&L aggregation is declared as x-openregister-aggregations on GLLine keyed by costCenterCode per REQ-CC-005._
**Primary spec:** bookkeeping-cost-centers-dimensions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Operator-assigned unique reference within the administration |
| name | string | Yes | Human-readable cost center name |
| description | string | No | Detailed cost center description and responsibilities per REQ-CD-002 |
| parentCode | string | No | FK to parent CostCenter.code for hierarchy via self-relation |
| responsibleUser | string | No | NC user id of the cost-center owner (maps to spec field 'manager' per REQ-CD-002) |
| budget | number | No | Allocated annual or periodic budget amount in EUR per REQ-CD-002 |
| lifecycleState | enum | Yes | One of active, blocked, archived (mirrors Account lifecycle per REQ-CoA-005; maps to spec field 'status') |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- self → CostCenter (many-to-one, via parentCode → code; hierarchy navigation)
- → GLLine (one-to-many, via costCenterCode FK, additive dimension field per REQ-CC-003)

> **Reconciliation note (add-shillinq-cost-centers-dimensions, 2026-06-03):** The earlier `CostCenter` entry (primary spec: cost-accounting-allocation) described a generic cost center with `description/status/budget/createdDate`. This entry supersedes it for the shillinq bookkeeping tier with the T4 dimensional accounting shape per REQ-CC-002: `parentCode` self-relation for hierarchy, `lifecycleState` enum mirroring Account, and `administrationId` FK. The OR-managed register pattern (ADR-022) replaces any parallel database table. No new PHP classes — this is a schema-only declaration per ADR-031.
>
> **Additive extension note (bookkeeping-consultancy-project-accounting, 2026-06-09):** The `CostCenter` schema is additively extended (non-breaking) with the optional fields `description` (operator-readable), `status` (alias enum `active/inactive` for external integrations expecting the simpler shape; `lifecycleState` remains authoritative), `budget` (integer cents — operator-set baseline), `spentToDate` (integer cents — derived via `x-openregister-aggregations` summing `GLLine.amount` filtered by `costCenterCode = @self.code AND side='debit'`, recursive over descendant cost centers via `parentCode`), `allocatedBudget` (integer cents — derived via `x-openregister-calculations` rolling up children's `allocatedBudget` + own `budget`), and `organizationId` (FK to owning Organization). Existing records remain valid without the new fields. All extensions are declarative per ADR-031 — no `BudgetRollupService` or `CostCenterSpendService` PHP class authored.

### KostenDrager
**Schema.org:** `schema:Product`
_An analytical cost unit (kostendrager / cost object) for tracking costs per product, service, or cost bearer per Dutch GAAP. Same field shape as CostCenter; the distinction is semantic. Hierarchy navigable via parentCode self-relation per REQ-CC-002. Segment P&L aggregation declared on GLLine keyed by kostenDragerCode per REQ-CC-005._
**Primary spec:** bookkeeping-cost-centers-dimensions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Operator-assigned unique reference within the administration |
| name | string | Yes | Human-readable kostendrager name |
| parentCode | string | No | FK to parent KostenDrager.code for hierarchy |
| responsibleUser | string | No | NC user id of the kostendrager owner |
| lifecycleState | enum | Yes | One of active, blocked, archived |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- self → KostenDrager (many-to-one, via parentCode → code; hierarchy navigation)
- → GLLine (one-to-many, via kostenDragerCode FK, additive dimension field per REQ-CC-003)

### CostProject
**Schema.org:** `schema:Project`
_Analytical project register for consultancy and departmental project accounting. Captures the management-accounting view of a project (authorised budget, estimated costs, costs incurred to date, lifecycle) — distinct from and complementing the RJ 270 revenue-recognition `Project` register. Costs and P&L derived from GL via x-openregister-aggregations; budget rollup via x-openregister-calculations._
**Primary spec:** bookkeeping-consultancy-project-accounting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectNumber | string | Yes | Operator-assigned unique reference within the administration |
| name | string | Yes | Human-readable project name |
| description | string | No | Project description / scope |
| startDate | date | No | Project activation date |
| endDate | date | No | Target close date |
| totalBudget | integer (cents) | Yes | Authorised spend ceiling |
| totalEstimatedCosts | integer (cents) | Yes | Project-manager estimate |
| costsIncurredToDate | integer (cents) | No | Derived via x-openregister-aggregations |
| administrationId | string | No | FK to Administration |
| organizationId | string | No | FK to owning Organization |
| costCenterCode | string | No | FK to CostCenter.code — associates project to a department |
| lifecycleState | enum | Yes | One of draft, active, on-hold, closed, archived |

**Relations:**
- → CostCenter (many-to-one, via costCenterCode → code)
- → ProjectBudget (one-to-many, via id ← projectId)
- → GLLine (one-to-many, via subLedgerType='cost-project' AND subLedgerRef=id; for costsIncurredToDate + profitAndLoss aggregations)

> **Reconciliation note (bookkeeping-consultancy-project-accounting, 2026-06-09):** The earlier `CostProject` entry (primary spec: cost-accounting-allocation) described a generic project cost object with `code/name/description/budget/totalCost/startDate/endDate/status`. This entry supersedes it as the **declared shillinq register**: the shape is realigned on `projectNumber` (not `code`, to avoid collision with the bookkeeping-tier `Project.code` analytical dimension), money fields move to **integer cents** per the suite's money rule, the lifecycle expands to `draft → active → on-hold → closed → archived`, and `costsIncurredToDate` + `profitAndLoss` are declared via `x-openregister-aggregations` over `GLLine` rather than stored. The OR-managed register pattern (ADR-022) replaces any parallel database table. No PHP service authored — all behaviour is `x-openregister-lifecycle / -aggregations / -calculations` per ADR-031. The existing RJ 270 / IFRS 15 revenue-recognition `Project` register (primary spec: bookkeeping-consultancy-project-accounting — REQ-CPA-001) coexists: `Project` carries `totalContractValue` / `recognisedRevenue` / `wipBalance` for revenue-side accounting, while `CostProject` carries `totalBudget` / `costsIncurredToDate` / `profitAndLoss` for management-accounting. Both share the same set of GL postings (filtered by sub-ledger reference). Companion register `ProjectBudget` (period-level allocation, lifecycle pending → approved → allocated → spent) is also declared by this spec.

### CostProjectBudget (a.k.a. ProjectBudget)
**Schema.org:** `schema:MonetaryAmount`
_Period-level budget allocation for a `CostProject` with lifecycle pending → approved → allocated → spent. Allows operators to allocate budget per fiscal period (Q1, Q2 …) and track which allocations have been authorised vs consumed._
**Primary spec:** bookkeeping-consultancy-project-accounting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| allocationNumber | string | Yes | Operator-assigned unique reference |
| amount | integer (cents) | Yes | Allocation amount |
| status | enum | Yes | One of pending, approved, allocated, spent |
| projectId | string | Yes | FK to CostProject.id |
| fiscalPeriod | string | Yes | FK to FiscalPeriod.code |
| administrationId | string | No | FK to Administration |
| description | string | No | Operator-readable description |

**Relations:**
- → CostProject (many-to-one, via projectId → id)

### CreditNote
**Schema.org:** `schema:Invoice`
_A document issued to reduce customer debt due to returns or corrections_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| creditNoteNumber | string | Yes | Unique credit note identifier |
| creditDate | datetime | Yes | Date when credit note was issued |
| totalAmount | number | Yes | Credit amount |
| reason | string | Yes | Reason for credit (return, correction, discount) |
| status | string | Yes | Credit note status |
| notes | string | No | Additional notes |

**Relations:**
- → Invoice (many-to-one)
- → Organization (many-to-one)
- → InvoiceLine (one-to-many)

### CurrencyBalance
**Schema.org:** `schema:Thing`
_Point-in-time per-currency balance snapshot for a (BankAccount, currency) pair (REQ-MC-003). Snapshots are operator-entered or refreshed by T4 bank-connector synchronisation — Shillinq NEVER auto-calculates `balance` by summing GL postings (REQ-MC-D4). One latest record per (accountId, currency); on duplicate writes the latest timestamp wins (REQ-MC-003 scenario "Prevent duplicate (accountId, currency) records"). The multi-account aggregation behind REQ-MC-004 is declared as `x-openregister-aggregations.balanceByCurrency` on the schema — no app-local PHP balance aggregator._
**Primary spec:** bookkeeping-multi-currency

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| balanceId | string | Yes | Stable unique snapshot identifier (e.g. `bal-usd-2026-05-21`) |
| accountId | string | Yes | FK to BankAccount.slug; forms one half of the (accountId, currency) uniqueness constraint |
| currency | string | Yes | ISO 4217 currency code (three uppercase letters); forms the second half of the (accountId, currency) uniqueness constraint |
| balance | number | Yes | Current balance amount in the specified currency |
| previousBalance | number | No | Previous balance for variance tracking + detail-page percentage-change rendering |
| lastUpdated | datetime | Yes | ISO 8601 timestamp of the most recent balance update; tiebreaker on upsert |
| notes | string | No | Free-text operator notes (source statement reference, manual-entry justification) |

**Relations:**
- → BankAccount (many-to-one, via `accountId` → `BankAccount.slug`)

**Uniqueness:** `(accountId, currency)` — one latest snapshot per pair per REQ-MC-003.

**Additive BankAccount fields (bookkeeping-multi-currency, REQ-MC-002):** the BankAccount schema (primary spec: bookkeeping-multi-currency / bookkeeping-chart-of-accounts) gains one optional field here — `primaryCurrency` (string, ISO 4217 code; nullable, treated as `EUR` on read for backward compatibility per REQ-MC-001). Non-breaking — existing BankAccount records remain valid without the field.

**T4 multi-currency engine envelope (add-shillinq-multi-currency, REQ-MC-002 → REQ-MC-006 of the umbrella spec).** The T3 sibling above covers per-(account, currency) cash-position snapshots — the operator-readable cash side of multi-currency. The T4 envelope `add-shillinq-multi-currency` covers the orthogonal posting-side: it introduces (a) a separate `FxRate` register with `schema:ExchangeRateSpecification` annotation and uniqueness on (`transactionCurrency`, `baseCurrency`, `date`, `source`) for daily ECB / manual / internal-policy rates; (b) an additive multi-currency extension on `GLLine` declaring `baseCurrencyAmount` / `transactionCurrency` (renamed from T1 `currency`) / `baseCurrency` / `fxRate` / `fxRateSource` / `fxRateDate` per its MODIFIED REQ-GL-003 — the on-the-wire `amount` property name is preserved as `transactionAmount` semantically (no destructive migration); (c) a daily ECB ingestion `ScheduledWorkflow` per ADR-031 path 2 (no `FxRateImportJob extends TimedJob`); (d) a period-end revaluation `ScheduledWorkflow` per REQ-MC-004 plus an `x-openregister-lifecycle` action for realised gain/loss on settlement (no `FxRevaluationService`); (e) an IAS 21 consolidation translation declared as an OR `Mapping` referencing the `FxRate` register per REQ-MC-005 (no `ConsolidationTranslationService`). Both T3 and T4 reuse the existing `BookkeepingMultiCurrency` manifest menu (the T4 envelope adds an `FXRates` sibling child to `BankAccounts` + `CurrencyBalances`, not a parallel menu). Each piece (a)–(e) is currently a tracked GAP on the umbrella `add-shillinq-multi-currency` change tasks.md (Tasks 5–10); the T2 envelope is spec-only per its `proposal.md ## Scope > Out of Scope`. The shared FX orientation contract (`baseCurrencyAmount = transactionAmount × fxRate`; ECB feed inverted once on ingest; `GLLine.fxRate` and `FxRate.rate` join with no reciprocation) is the authoritative cross-spec invariant per ADR-022 (reuse OR abstractions) and ADR-031 (declarative workflows over services).

### CustomerMaster
**Schema.org:** `schema:Organization`
_Customer party record for accounts receivable. Holds billing details, credit limit, payment terms, and dunning policy reference for a customer within a single administration._
**Primary spec:** bookkeeping-accounts-receivable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| customerNumber | string | Yes | Stable identifier per administration |
| name | string | Yes | Legal name |
| tradingName | string | No | Alternate / DBA name |
| kvkNumber | string | No | Dutch KvK number (8 digits) |
| btwNumber | string | No | Dutch BTW / EU VAT number |
| paymentTermDays | integer | Yes | Default payment term in days (default 30) |
| defaultRevenueAccountNumber | string | No | FK to Account.accountNumber for default revenue coding |
| creditLimit | number | No | Credit limit ≥ 0; if set, REQ-AR-006 evaluates open AR balance |
| dunningPolicyRef | string | No | FK to OR dunning-workflow policy record if extension is stable per ADR-022; else null |
| peppolEndpoint | string | No | Peppol BIS endpoint identifier (used by T4 e-invoicing) |
| address | object | No | Street/number/postcode/city/country |
| email | string | No | Primary billing email |
| phone | string | No | Primary contact phone |
| administrationId | string | Yes | FK to administration |
| lifecycleState | enum | Yes | One of active, blocked, archived |
| contactRef | string | No | FK to OR contact abstraction if stable per ADR-022; else null |

**Relations:**
- → ARInvoice (one-to-many, outstanding invoices for credit-limit aggregation)
- → Administration (many-to-one)

### DebitNote
**Schema.org:** `schema:Invoice`
_A document issued to increase vendor debt for account adjustments_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| debitNoteNumber | string | Yes | Unique debit note identifier |
| debitDate | datetime | Yes | Date when debit note was issued |
| totalAmount | number | Yes | Debit amount |
| reason | string | Yes | Reason for debit |
| status | string | Yes | Debit note status |
| notes | string | No | Additional notes |

**Relations:**
- → Payee (many-to-one)

### Deduction
**Schema.org:** `schema:PriceSpecification`
_Payroll deduction such as taxes, social security, or garnishments_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deductionType | string | Yes | Type of deduction (tax, social_security, garnishment, insurance) |
| amount | number | Yes | Deduction amount |
| description | string | No | Deduction description |
| reason | string | No | Reason for deduction |

**Relations:**
- → Payroll (many-to-one)

### DeferredTaxMovement
**Schema.org:** `schema:MonetaryAmount`
_Roll-forward of the deferred-tax balance per fiscal period per jurisdiction per category (REQ-DT-009 / IAS 12 §81(g) / RJ 272). One record per `(periodId, jurisdiction, category, administrationId)`. Opening balance plus originations, reversals, rate-change effects, business-combination acquisitions and FX translation give the computed closing balance; `recognisedInPL` is the schema-declared P&L component. All amounts integer euro cents (ADR-022 money rule). Roll-forward is the audit-trail of how DTA/DTL on the balance sheet moved between two balansdata; the `linkedJournalEntries` array points to the GL postings that materialised the movement._
**Primary spec:** bookkeeping-deferred-tax

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | FK to FiscalPeriod (balansdatum) |
| jurisdiction | string | Yes | ISO 3166-1 alpha-2 country code (REQ-DT-007) |
| category | enum | Yes | One of depreciation, provision, receivable-impairment, inventory-valuation, development-cost, fair-value-adjustment, lease-ifrs16, pension, other (matches TemporaryDifference.category) |
| openingBalance | integer | Yes | Deferred-tax position at start of period in euro cents; positive = DTL, negative = DTA |
| originatedInPeriod | integer | Yes | New deferred-tax positions created during the period in euro cents |
| reversedInPeriod | integer | Yes | Deferred-tax positions that reversed during the period in euro cents (typically negative) |
| rateChangeAdjustment | integer | Yes | Adjustment from an enacted rate change per REQ-DT-005 in euro cents |
| acquiredViaBusinessCombination | integer | Yes | DTA/DTL acquired via a business combination (IFRS 3) in euro cents |
| translationAdjustment | integer | Yes | FX translation effect on deferred-tax balances of foreign operations in euro cents (OCI component) |
| recognisedInPL | integer | Yes | Computed P&L effect: `originatedInPeriod + reversedInPeriod + rateChangeAdjustment + acquiredViaBusinessCombination` in euro cents |
| recognisedInOCI | integer | Yes | Deferred-tax recognised directly in OCI (pension / hedging) in euro cents |
| closingBalance | integer | Yes | Computed closing balance: `openingBalance + originatedInPeriod + reversedInPeriod + rateChangeAdjustment + acquiredViaBusinessCombination + translationAdjustment + recognisedInOCI` in euro cents |
| linkedJournalEntries | array | No | FK references to GL journal entries posting the deferred-tax movement (REQ-DT-009 / REQ-DT-010) |
| administrationId | string | Yes | FK to the Administration this record belongs to |

**Relations:**
- → FiscalPeriod (many-to-one, via periodId)
- → JournalEntry (one-to-many, via linkedJournalEntries[])
- → TaxProvision (logical: contributes to dtaTotal/dtlTotal per period/jurisdiction)
- → Administration (many-to-one)

**Cites:** ADR-022 (audit-trail-immutable, money rule), ADR-031 (schema-declarative calculations), ADR-037 (modular register fragment).

### DebtPosition
**Schema.org:** `schema:MonetaryAmount`
_Uitstaande schuld per instrument per peildatum (kwartaal- of jaar-ultimo) per REQ-EMU-004. Bruto nominaal amount classified per Eurostat ESA2010: AF.2-deposits / AF.3-securities / AF.4-loans count toward the EMU-schuld; AF.7-derivatives do not (Wet Hof art. 4). May be sourced from the schatkistbankieren daily sync (REQ-EMU-011) or entered manually. `teltMeeInEmuSchuld` materialises the ESA filter; `tegenpartij.consolidatieEMU` drives the S.1313 intercompany-eliminatie per REQ-EMU-005. All amounts integer-cent per ADR-022._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to EMUReport |
| peildatum | date | Yes | Measurement date (kwartaal- of jaar-ultimo) |
| instrument | enum | Yes | vaste-geldlening / obligatie / kasgeldlening / schatkistbankieren-rekeningcourant / crediteurensaldo-1j+ / derivaten-passief / voorziening-juridisch |
| tegenpartij | object | No | naam, soort (sector-S122-bank / sector-S11-nfv / sector-S13-government), consolidatieEMU |
| hoofdsomOorspronkelijk | number | No | Original principal in cents |
| uitstaandeSchuld | number | Yes | Outstanding nominal balance in cents |
| rentevoet | number | No | Annual interest rate (%) |
| rentevorm | enum | No | vast / variabel |
| looptijdJaren | integer | No | Original term in years |
| einddatum | date | No | Maturity date |
| teltMeeInEmuSchuld | boolean | Yes | ESA2010 filter: AF.2/AF.3/AF.4 = true, AF.7 = false |
| categorieEurostat | enum | Yes | AF.2-deposits / AF.3-securities / AF.4-loans / AF.7-derivatives / overig |
| currency | string | No | ISO 4217 currency (default EUR) |

**Relations:**
- → EMUReport (many-to-one)

**Cites:** ADR-022 (money rule), ADR-031 (schema-declarative aggregations), ADR-037 (modular register fragment).

### Delegation
**Schema.org:** `schema:Action`
_A delegation of mandate authority from one signing authority to another for a specified period_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| delegationNumber | string | Yes | Unique delegation identifier |
| reason | string | No | Reason for delegation (e.g., out-of-office, temporary increase, absence) |
| startDate | datetime | Yes | Start date of the delegation |
| endDate | datetime | Yes | End date of the delegation |
| status | string | Yes | Status of delegation (active/revoked/expired) |
| revokedDate | datetime | No | Date when delegation was revoked |
| revokeReason | string | No | Reason for early revocation |

**Relations:**
- → SigningAuthority (many-to-one)
- → SigningAuthority (many-to-one)
- → Mandate (many-to-one)
- → DelegationRule (many-to-one)

### DelegationRule
**Schema.org:** `schema:Action`
_Rules for delegating approval tasks during out-of-office periods and escalation scenarios_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique delegation rule identifier |
| ruleType | string | Yes | outOfOffice/escalation/substitute |
| delegateFrom | string | Yes | Person/User ID delegating approvals |
| delegateTo | string | Yes | Person/User ID receiving delegated tasks |
| startDate | datetime | Yes | When delegation starts |
| endDate | datetime | No | When delegation ends |
| scope | string | No | allApprovals or specificChain |
| status | string | No | active or inactive |
| escalationPriority | number | No | Priority order for escalation chain (1=first, 2=fallback, etc.) |

**Relations:**
- → Person (many-to-one)
- → Person (many-to-one)

### DepreciationSchedule
**Schema.org:** `schema:Thing`
_Per-asset, per-fiscal-year depreciation schedule record (REQ-FA-003). Immutable append-only history backing the audit trail for Wet Vpb / Wet IB tax compliance. `depreciationAmount` is materialised at period close (or on demand by the `DepreciationCalculator`) using the asset's `depreciationMethod`, `annualRate` and the Nextcloud System Settings Float Precision rounding (REQ-FA-005). `bookValue` is a derived `x-openregister-calculations` field (asset.acquisitionCost − accumulatedDepreciation) and not stored separately. `status` tracks the schedule lifecycle (planned → active → completed)._
**Primary spec:** bookkeeping-fixed-assets-depreciation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scheduleNumber | string | Yes | Unique identifier within an administration (e.g. SCH-FA-0001-2026) |
| assetRef | string | Yes | FK to FixedAsset UUID — the asset this schedule covers |
| depreciationMethod | enum | Yes | One of linear, declining-balance, units-of-production |
| annualRate | number ≥ 0 | Yes | Annual depreciation rate (percentage for linear, declining percentage for declining-balance, units/year for units-of-production). Rounded to System Settings Float Precision per REQ-FA-005 |
| rateType | enum | Yes | One of percentage, fixed-amount, units-per-year |
| periodStartDate | date | Yes | Start date of the depreciation period (typically fiscal-year start) |
| periodEndDate | date | Yes | End date of the depreciation period (typically fiscal-year end) |
| depreciationAmount | number ≥ 0 | Yes | Depreciation expense for this period in base currency |
| accumulatedDepreciation | number ≥ 0 | Yes | Total accumulated depreciation across all periods through periodEndDate |
| fiscalYear | integer | Yes | Fiscal year this schedule covers (e.g. 2026) |
| status | enum | Yes | One of planned, active, completed |
| costCenterCode | string | No | Cost-centre allocation at schedule-creation time (REQ-FA-006) |
| glTransactionRef | string | No | FK to the materialised yearly depreciation-expense GLTransaction (REQ-FA-007) |
| calculationFloatPrecision | integer (0..8) | No | Float Precision value captured at calculation time (REQ-FA-005) |
| administrationId | string | Yes | FK to the Administration owning this schedule |

**Calculated fields (x-openregister-calculations, not stored):**
- `bookValue` — net book value at periodEndDate (asset.acquisitionCost − accumulatedDepreciation)

**Relations:**
- → FixedAsset (many-to-one, via `assetRef` → FixedAsset.id; the depreciation schedule references back to its asset record per REQ-FA-003 — see the FixedAsset entry above for the sub-ledger GL-link pattern)
- → Administration (many-to-one, via administrationId)
- → GLTransaction (many-to-one, via `glTransactionRef` → GLTransaction.id; the yearly depreciation-expense posting materialised per REQ-FA-007, balanced against the debit-depreciation-expense / credit-accumulated-depreciation pattern that consumes the canonical sub-ledger reference `GLLine.subLedgerType = "fa"` + `GLLine.subLedgerRef = <FixedAsset UUID>`)

> **Reconciliation note (bookkeeping-fixed-assets-depreciation, 2026-06-09):** The earlier
> stub entry (primary spec `obligation-financial-administration`) is superseded by the
> field set above, which conforms to REQ-FA-003 of the T3
> `bookkeeping-fixed-assets-depreciation` spec. DepreciationSchedule lands as an ADR-037
> register-fragment new schema at `lib/Settings/register.d/bookkeeping-fixed-assets-depreciation.json`,
> not by editing the monolith. The schema is the materialised audit-trail backing the
> on-demand `x-openregister-calculations` derived fields on FixedAsset
> (`monthlyDepreciation`, `currentBookValue`, `commercialBookValue`, `fiscalBookValue`)
> — design D2 keeps the per-tick computation declarative; D4 (immutable append-only)
> applies to the persisted history that anchors the tax-audit trail.

### DigitalDocument
**Schema.org:** `schema:DigitalDocument`
_Schema.org DigitalDocument — standard vocabulary for digitaldocument data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document name/title |
| documentType | string | Yes | Document type (contract, tender, report, etc.) |
| description | string | No | Document description |
| encodingFormat | string | No | MIME type (application/pdf, etc.) |
| contentSize | string | No | File size |

### Dividend
**Schema.org:** `schema:MonetaryAmount`
_Dividend payment or distribution to shareholders_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amount | number | Yes | Dividend amount per share or total in EUR |
| paymentDate | datetime | Yes | Date the dividend was or will be paid |
| declarationDate | datetime | No | Date the dividend was declared |
| fiscalYear | string | No | Fiscal year for which dividend is paid |
| frequency | string | No | Annual, semi-annual, quarterly, one-time, etc. |
| status | string | Yes | Pending, paid, cancelled, etc. |

**Relations:**
- → Shareholder (many-to-one)
- → Entity (many-to-one)
- → Payment (many-to-one)

### Document
**Schema.org:** `schema:DigitalDocument`
_Managed document with version control for bookkeeping (invoices, contracts, receipts)_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document title |
| documentType | string | Yes | Category (invoice, receipt, contract, amendment) |
| description | text | No | Document summary |
| encodingFormat | string | No | File format (PDF, DOCX, JPG) |
| contentSize | integer | No | File size in bytes |
| fileLocation | string | No | Storage path or repository URL |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Person (many-to-one)

### DunningNotice
**Schema.org:** `schema:Message`
_Per-AP-invoice dunning timeline entry recording each reminder level dispatched against an overdue APTransaction. Written by the AP lifecycle (or OR's dunning-workflow engine when stable per ADR-022) when a reminder fires; read by the APTransaction detail page to surface the dunning timeline. Symmetric to `DunningRecord` on the AR side._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceRef | string | Yes | FK to APTransaction UUID |
| reminderLevel | enum | Yes | Dunning escalation level (reminder-1, reminder-2, formal-notice, collection) |
| dispatchedAt | datetime | Yes | When the reminder was dispatched |
| dispatchedBy | string | Yes | Actor that dispatched ("system" or operator user-id) |
| templateRef | string | No | FK to OR notification template used for dispatch |
| acknowledgedAt | datetime | No | When the vendor acknowledged the notice |
| administrationId | string | Yes | FK to the administration owning this dunning notice |

**Relations:**
- → APTransaction (many-to-one, via invoiceRef)
- → NotificationTemplate (many-to-one, via templateRef — OR-owned)
- → Administration (many-to-one)

> **Reconciliation note (bookkeeping-accounts-payable-core, 2026-06-09):** This
> entry has been updated from the prior generic `accounts-payable-receivable`
> draft to the canonical T2 shape registered in
> `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`. The fields
> mirror `DunningRecord` on the AR side. Distinct from the
> `bookkeeping-credit-control-dunning` ladder-run orchestrator (see
> `DunningRun` / `DunningLadder` entries elsewhere in this document) which
> operates on AR receivables via the credit-control ladder; this
> `DunningNotice` is a per-AP-invoice timeline record per REQ-AP-005. See
> `openspec/changes/bookkeeping-accounts-payable-core/dedup-notes.md` for
> the boundary.

### DunningRecord
**Schema.org:** `schema:Event`
_Per-invoice dunning timeline entry recording each reminder level dispatched to the customer. Written by the AR lifecycle when the dunning-workflow engine fires; read by the AR invoice detail page to surface the dunning timeline._
**Primary spec:** bookkeeping-accounts-receivable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceRef | string | Yes | FK to ARInvoice UUID |
| reminderLevel | enum | Yes | One of reminder-1, reminder-2, formal-notice, collection |
| dispatchedAt | datetime | Yes | When the reminder was dispatched |
| dispatchedBy | string | Yes | Actor (system or operator) |
| templateRef | string | No | FK to OR notification template |
| acknowledgedAt | datetime | No | When the customer responded |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → ARInvoice (many-to-one, via invoiceRef)
- → Administration (many-to-one)

### ENSIAJaarcyclus
**Schema.org:** `schema:Event`
_Annual ENSIA (Eenduidige Normatiek Single Information Audit) compliance evaluation cycle for Dutch public-sector organisations. Governs the full lifecycle from intake through portal submission._
**Primary spec:** bookkeeping-ensia-zelfevaluatie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| jaar | integer | Yes | Calendar year of the ENSIA evaluation (e.g., 2026) |
| organisatieNaam | string | Yes | Official organisation name |
| organisatieKvK | string | Yes | KvK registration number of the organisation |
| status | enum | Yes | Lifecycle status: in-voorbereiding, in-uitvoering, peer-review, college-akkoord, ingediend, afgerond |
| startDatum | date | Yes | Date cycle was initiated |
| deadlineColleges | date | Yes | College approval deadline |
| deadlineMinister | date | Yes | Minister submission deadline (1 May per VNG law) |
| verantwoordingsdomeinen | array | Yes | Selected VNG domains: BIO, DigiD, SUWI, BAG, BGT, BRP, WOZ |
| procesEigenaar | string | Yes | User-reference: CISO or FIB responsible for the cycle |
| vraagSetVersion | string | No | Version of the VNG question set used (e.g., BIO-1.04-2026); set on cycle init |
| verklaringFile | string | No | File-reference: signed college declaration document |
| administrationId | string | Yes | FK to Administration owning this cycle |

**Relations:**
- → Evaluatievraag (one-to-many)
- → Bevinding (one-to-many)

### EMUAdjustment
**Schema.org:** `schema:MonetaryAmount`
_Individuele accrual→kas correctie gekoppeld aan een grootboekmutatie of macroregel per Wet Hof art. 3 (REQ-EMU-002). One of eight macro types: eliminatie-afschrijving, eliminatie-voorzieningdotatie, eliminatie-onttrekking-reserve, toevoeging-bruto-investering, toevoeging-aflossing, eliminatie-boekwinst-desinvestering, correctie-transactiemoment, intercompany-eliminatie. `richting` (saldo-verhogend / saldo-verlagend / saldo-neutraal) carries the sign; `bedrag` is non-negative. `consolidatieEMU` drives the S.1313 intercompany-eliminatie (REQ-EMU-005). Concerncontroller can override via `overridden=true`; OR audit-trail logs the change. All amounts integer-cent per ADR-022._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to EMUReport |
| type | enum | Yes | One of 8 Wet Hof art. 3 macro types (REQ-EMU-002/005) |
| richting | enum | Yes | saldo-verhogend / saldo-verlagend / saldo-neutraal |
| bedrag | number | Yes | Adjustment amount in cents (non-negative) |
| bron | object | No | grootboekrekening, omschrijving, taakveld, programma |
| regel | string | Yes | Legal-basis citation (e.g. "Wet Hof art. 3 lid 2") |
| toelichting | string | No | Free-text business context |
| overridden | boolean | No | True when manually overridden (audit-trail logged) |
| consolidatieEMU | enum | No | extern / intern-S1313 / internal-entity (REQ-EMU-005) |
| currency | string | No | ISO 4217 currency (default EUR) |

**Relations:**
- → EMUReport (many-to-one)
- → GLLine (many-to-one, optional via bron.grootboekrekening)

**Cites:** ADR-022 (money rule + audit-trail), ADR-031 (PHP guard exception for macro-rule classification), ADR-037 (modular register fragment).

### EMUReport
**Schema.org:** `schema:GovernmentService`
_Ingediende of in-progress EMU-aangifte voor een periode (kwartaal of jaar) per REQ-EMU-001. Atomic versioned submission unit; carries the computed EMU-saldo, bruto EMU-schuld ultimo, BBV-aansluiting and CBS-bevestigingsnummer. Lifecycle concept → ingediend → herzien per Wet Hof art. 10. Submission to CBS via SBR/Digipoort is the `indienen` lifecycle transition, gated by `EmuSubmissionGuard::requireApproval` (ADR-031 exception). 10-jaar retention per Archiefwet 1995 + Selectielijst Gemeenten 2020 (REQ-EMU-012). All amounts integer-cent per ADR-022._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the reporting Administration |
| rapporterendeOrganisatie | object | No | RSIN/gemeentecode/naam/soort (gemeente/provincie/waterschap/GR) |
| periodeJaar | integer | Yes | Calendar year of the period |
| periodeKwartaal | integer | No | Quarter 1-4 (required when periodeType=kwartaal-emu-saldo) |
| periodeType | enum | Yes | kwartaal-emu-saldo / jaar-emu-saldo / jaar-emu-schuld |
| status | enum | Yes | concept / ingediend / herzien |
| indieningsdatum | datetime | No | Timestamp of CBS submission |
| cbsBevestigingsnummer | string | No | CBS confirmation reference |
| cbsTemplateVersion | string | No | CBS-enquête template version (default 2026) |
| emuSaldoBerekend | number | No | Computed EMU cash saldo in cents (negative = tekort) |
| emuSaldoBegroot | number | No | Budgeted EMU saldo in cents |
| emuSaldoAfwijking | number | No | berekend − begroot (derived) |
| emuSaldoAfwijkingPercentage | number | No | (afwijking / abs(begroot)) × 100 |
| emuSchuldBruto | number | No | Bruto nominal EMU-schuld in cents (AF.2+AF.3+AF.4) |
| emuSchuldWettelijkeNorm | number | No | Individuele EMU-referentiewaarde in cents |
| emuSchuldRuimte | number | No | wettelijkeNorm − bruto (derived) |
| bbvSaldoBatenLasten | number | No | BBV jaarrekening saldo baten/lasten for reconciliation |
| bbvTotaleAdjustments | number | No | Sum of EMUAdjustment.bedrag for the year (derived) |
| bbvAansluitingscontrole | enum | No | geslaagd / mislukt / niet-uitgevoerd |
| toelichting | string | No | Concerncontroller toelichting (auto-seeded with top-3 contributors) |
| currency | string | No | ISO 4217 currency (default EUR) |

**Relations:**
- → Administration (many-to-one)
- → EMUAdjustment (one-to-many)
- → CashFlowItem (one-to-many)
- → DebtPosition (one-to-many)

**Cites:** ADR-022 (money rule + audit-trail + retention), ADR-031 (EmuSubmissionGuard exception), ADR-032 (manifest detail), ADR-037 (modular register fragment).

### Entitlement
_Grant of access or permission to use specific features, resources, or data within the system_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Entitlement name or what is entitled |
| description | string | No | Detailed description of what is entitled |
| status | string | Yes | Entitlement status (active, pending, expired, revoked) |
| grantedAt | datetime | Yes | Date entitlement was granted |
| expiresAt | datetime | No | Date entitlement expires |

**Relations:**
- → User (many-to-one)

### Entity
**Schema.org:** `schema:Organization`
_A legal entity or business managed within a multi-entity system_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Legal name of the entity |
| registrationNumber | string | Yes | Company registration number (KvK) |
| taxId | string | Yes | Tax identification number (VAT/BTW ID) |
| businessType | string | No | Business form (BV, NV, Eenmanszaak, etc.) |
| foundingDate | datetime | No | Date of establishment |
| country | string | No | Country of incorporation |
| status | string | Yes | Active, inactive, dissolved, etc. |

**Relations:**
- → Organization (many-to-one)
- → Person (one-to-many)

### Evaluatievraag
**Schema.org:** `schema:Question`
_An individual ENSIA evaluation question within a jaarcyclus. Carries the BIO/domain question code, answer, maturity score, evidence attachments, peer-review status, and full audit trail per change._
**Primary spec:** bookkeeping-ensia-zelfevaluatie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| cyclusId | string | Yes | FK to ENSIAJaarcyclus (register relation) |
| domein | enum | Yes | VNG domain: BIO, DigiD, SUWI, BAG, BGT, BRP, WOZ |
| onderwerp | string | Yes | Question subject area (e.g., Toegangsbeveiliging, Backup & Recovery) |
| vraagCode | string | Yes | Stable VNG question code (e.g., BIO-9.1.1) |
| vraagtekst | string | Yes | Full question text from VNG question set |
| antwoordType | enum | Yes | Answer type: ja-nee-nvt, volwassenheidsniveau-1-5, vrije-tekst |
| antwoord | string | No | The answer value (yes/no/nvt, 1-5, or free text) |
| volwassenheidsScore | integer | No | Maturity score 1-5 (nullable; only for antwoordType volwassenheidsniveau-1-5) |
| toelichting | string | No | Textual justification (≥ 50 chars required when score ≥ 3) |
| beantwoorder | string | No | User-reference: assigned answerer for this question |
| peerReviewer | string | No | User-reference: assigned peer-reviewer (nullable until peer-review phase) |
| peerReviewStatus | enum | Yes | Peer-review status: nog-niet-beoordeeld, akkoord, wijziging-gevraagd |
| peerReviewCommentaar | string | No | Reviewer comment routed back to beantwoorder on wijziging-gevraagd |
| bewijsstukken | array | No | Evidence attachments: array of {fileRef: docudesk-URI, omschrijving: string} |
| administrationId | string | Yes | FK to Administration owning this question |

**Relations:**
- → ENSIAJaarcyclus (many-to-one)
- → Bevinding (one-to-many, via vraagId)

### EvaluationCriterion
**Schema.org:** `schema:Thing`
_Evaluation criteria with weights and scoring formulas documenting award methodology_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| criterionId | string | Yes | Unique criterion identifier |
| name | string | Yes | Criterion name (price, quality, delivery time, etc) |
| weight | number | Yes | Weight in total score 0-100 |
| maxScore | number | Yes | Maximum achievable score for this criterion |
| scoringFormula | string | No | Automated scoring formula or reference |
| sequenceNumber | number | No | Display order in evaluation |

### Event
**Schema.org:** `schema:Event`
_Schema.org Event — standard vocabulary for event data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Event/tender name |
| description | string | No | Description |
| startDate | datetime | Yes | Start/publication date |
| endDate | datetime | No | End/deadline date |
| eventStatus | string | Yes | Status (active, closed, cancelled) |
| maximumAttendeeCapacity | integer | No | Max participants/lots |

### ExemptionCertificate
**Schema.org:** `schema:DigitalDocument`
_Tax exemption credential (research, export, environmental, humanitarian). Stores certificate metadata, validity, and linked exemptions for workflow automation._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| certificateNumber | string | Yes | Official certificate ID from issuing authority |
| certificateType | enum | Yes | research, export, environmental, humanitarian, innovation, vat-reverse, other |
| issueDate | date | Yes | Certificate issuance date |
| expiryDate | date | No | Expiration date; null = perpetual |
| exemptionReason | string | Yes | Legal basis or reason code |
| documentURL | uri | No | Link to official document or scan |

**Relations:**
- → Organization (many-to-one)
- → TaxDeclaration (many-to-many)

### ExpenditureEscalation
**Schema.org:** `schema:Order`
_An expenditure request that exceeds the mandate ceiling and requires escalation to higher authority for approval_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| escalationNumber | string | Yes | Unique escalation identifier |
| totalAmount | number | Yes | Total expenditure amount |
| mandateLimit | number | Yes | The mandate ceiling that was exceeded |
| exceedingAmount | number | Yes | Amount by which expenditure exceeds mandate |
| reason | string | No | Justification for the expenditure above mandate |
| status | string | Yes | Status of escalation (pending/approved/rejected) |
| createdDate | datetime | Yes | Date the escalation was created |
| decisionDate | datetime | No | Date when escalation was approved or rejected |

**Relations:**
- → ApprovalRequest (many-to-one)
- → Mandate (many-to-one)
- → Person (many-to-one)

### ExpenditureRequest
_A request to spend funds from an allocated budget, requiring review and approval_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique identifier for the request |
| amount | number | Yes | Requested expenditure amount |
| purpose | string | Yes | Purpose or description of the expenditure |
| status | string | Yes | Status: draft, submitted, approved, rejected, executed |
| requestDate | datetime | Yes | Date request was made |

**Relations:**
- → Budget (many-to-one)
- → ApprovalRequest (many-to-one)
- → Person (many-to-one)

### Expense
**Schema.org:** `schema:Invoice`
_Business expenditure with receipt documentation and reimbursement processing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| expenseNumber | string | Yes | Unique expense identifier |
| expenseDate | datetime | Yes | Date when expense was incurred |
| amount | number | Yes | Expense amount |
| category | string | Yes | Expense category (travel, meals, supplies) |
| status | string | Yes | Expense status (submitted, approved, reimbursed) |
| approvalStatus | string | No | Approval workflow status |
| description | string | No | Expense description |

**Relations:**
- → Person (many-to-one)
- → Receipt (one-to-many)

### ExpenseCategory
**Schema.org:** `schema:Thing`
_A category or dimension for coding and tracking expenses, enabling multi-dimensional reporting by department, region, cost type, or other organizational structures_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Human-readable category name |
| code | string | Yes | Unique code used for automated coding and reporting |
| type | string | Yes | Category dimension: department, region, costType, project, costCenter, etc. |
| description | string | No | Description of this category |
| parentCode | string | No | Parent category code for hierarchical grouping |

**Relations:**
- → Organization (many-to-one)

### ExpenseClaim
**Schema.org:** `schema:Invoice`
_Expense claim submissions with receipt tracking, approval workflow, and reimbursement management_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| claimId | string | Yes | Unique expense claim identifier |
| submittedBy | string | Yes | Person/User ID who submitted the claim |
| totalAmount | number | Yes | Total amount claimed |
| currency | string | No | ISO 4217 currency code |
| status | string | No | draft/submitted/approved/rejected/reimbursed |
| description | string | No | Overall claim description and purpose |
| submittedDate | datetime | No | When the claim was submitted |
| approvalDueDate | datetime | No | Approval deadline |
| approvedDate | datetime | No | When the claim was approved |
| reimbursedDate | datetime | No | When reimbursement was made |
| reimbursementAmount | number | No | Final approved amount for reimbursement |
| attachments | array | No | File references for supporting receipts and documentation |

**Relations:**
- → Person (many-to-one)
- → ApprovalRequest (one-to-many)
- → Receipt (one-to-many)
- → Payment (many-to-one)

> **Reconciliation note (expense-capture-core, 2026-06-03):** The existing `ExpenseClaim` entry (primary spec: approval-workflow-management) is a generic claim schema without cost-centre allocation, multi-currency support, or GL materialisation. `ExpenseClaimEntry` is the shillinq bookkeeping-tier expense claim with full lifecycle (draft → submitted → approved → posted → reimbursed), OR approval-workflow routing per ADR-022, GL materialisation per REQ-EC-012, multi-currency, and cost-centre allocation per line. New expense-capture implementations in shillinq MUST use `ExpenseClaimEntry`. The `ExpenseClaim` entry is retained for generic approval-workflow-management usage outside the bookkeeping tier.

> **Extension note (expense-reimbursement-or-passthrough, 2026-06-07):** `Receipt`, `MileageEntry`, and `PerDiem` are extended with dual-mode settlement metadata: `settlementMode` (enum: `reimbursable` | `pass-through`), `linkedCustomerId` (FK to Organization), `markupRuleId` (FK to PassThroughMarkupRule), `markupRateApplied` (number), `markupAmountCalculated` (number), and `passthroughDebitAccountCode` (string). `ExpenseClaimEntry` gains claim-level `settlementMode`, `totalReimbursableAmount`, `totalPassThroughAmount`, `passThroughCustomerIds`, `glReimbursableTransactionId`, `glPassThroughTransactionId`, and `reimbursementPolicyId`; the lifecycle adds the `invoiced` closure state and the `markInvoiced` / `changeSettlementMode` transitions per REQ-ERP-007 / REQ-ERP-011. Two new master-data schemas — `ReimbursementPolicy` (per-administration auto-approve + markup-approval thresholds + employee bank account mapping) and `PassThroughMarkupRule` (per-customer / per-category percentage or fixed markup with priority customer+category > customer > global) — are declared per REQ-ERP-004 + REQ-ERP-005. All changes are non-destructive; reads remain queryable against historic data, and markup rates lock on claim submission for audit immutability per REQ-ERP-010.

### ReimbursementPolicy
**Schema.org:** `schema:Thing`
_Per-administration master-data record configuring expense settlement policy: auto-approve threshold for reimbursable claims, optional markup-approval threshold for pass-through claims, default GL account code for the pass-through debit, and the employee bank-account mapping reference for the SEPA reimbursement notification._
**Primary spec:** expense-reimbursement-or-passthrough

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| policyId | string | Yes | Unique policy identifier per administration (e.g. POL-NL-01) |
| name | string | Yes | Human-readable policy name |
| description | string | No | Policy description and scope |
| autoApproveThreshold | number | No | Expense claims with totalAmount ≤ this value auto-approve (REQ-ERP-004) |
| requiresMarkupApprovalThreshold | number | No | Pass-through markup amounts ≥ this value require an extra approver (REQ-ERP-006) |
| passthroughDebitAccountCode | string | No | Default GL account code for the pass-through debit line (customer AR / deferred revenue) |
| employeeBankAccountMapping | string | No | Reference for resolving the employee SEPA account in the reimbursement notification (REQ-ERP-008) |
| notes | string | No | Operator notes (policy rationale, regulatory references) |
| administrationId | string | Yes | FK to the Administration owning this policy |

**Relations:**
- → Administration (many-to-one)

### PassThroughMarkupRule
**Schema.org:** `schema:Offer`
_Per-administration master-data record defining the markup applied to pass-through expenses. Lookup priority — customer+category > customer-only > global default — is enforced declaratively on Receipt / MileageEntry / PerDiem x-openregister-calculations._
**Primary spec:** expense-reimbursement-or-passthrough

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique rule identifier per administration (e.g. RULE-001) |
| targetCustomerId | string | No | FK to Organization (customer); null = global |
| targetCategory | string | No | Expense category (travel, meals, etc.); null = all categories |
| markupType | string | Yes | percentage \| fixedAmount (REQ-ERP-005) |
| markupValue | number | Yes | Percentage (0.15) or fixedAmount (2.50) |
| currency | string | Yes | ISO 4217 currency code (EUR in T2) |
| effectiveFromYear | integer | Yes | Fiscal year this rule applies to |
| effectiveToYear | integer | No | Last fiscal year (inclusive); null = open-ended |
| priority | integer | No | Tie-breaker when multiple rules match |
| notes | string | No | Operator notes (rule rationale, contract reference) |
| administrationId | string | Yes | FK to the Administration owning this rule |

**Relations:**
- → Administration (many-to-one)
- → Organization (many-to-one, optional — when targeting a specific customer)

### ExpenseLineItem
**Schema.org:** `schema:Thing`
_A line item within an expense record with detailed coding for department allocation and cost center tracking_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | string | Yes | Sequence number within the parent expense |
| amount | number | Yes | Amount for this line item |
| description | string | Yes | Description of the goods or services provided |
| department | string | No | Department code for cost allocation |
| costCenter | string | No | Cost center code for tracking and reporting |
| quantity | number | No | Quantity of items or units |

**Relations:**
- → Expense (many-to-one)
- → ExpenseCategory (many-to-one)

### ExpenseReport
**Schema.org:** `schema:Report`
_Spend and expense report by category with approval and budget tracking_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type: SPEND_ANALYSIS, EXPENSE_SUMMARY, BUDGET_VS_ACTUAL |
| period | string | Yes | Report period: MONTHLY, QUARTERLY, YEARLY |
| generatedAt | datetime | Yes | Report generation timestamp |
| totalAmount | number | Yes | Total spend amount |
| currency | string | Yes | Currency code (EUR) |
| expenseCategory | string | No | Primary expense category |
| approvalStatus | string | No | Approval status: DRAFT, SUBMITTED, APPROVED |
| budgetAmount | number | No | Budget amount for variance analysis |

**Relations:**
- → ProcurementOrder (many-to-many)
- → Supplier (many-to-many)

### FXExposure
**Schema.org:** `schema:MonetaryAmount`
_Track foreign exchange risk across currencies with current rates, valuations, and unrealized gains/losses_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| baseCurrency | string | Yes | EUR or company base currency |
| foreignCurrency | string | Yes | ISO 4217 code |
| exposureAmount | number | Yes | Amount in foreign currency |
| currentExchangeRate | number | Yes | Foreign/base rate |
| valuationDate | string | Yes | ISO 8601 rate snapshot date |
| unrealizedGainLoss | number | No | P&L in base currency |
| riskLevel | string | No | Low, Medium, High |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### FinancialDecision
**Schema.org:** `schema:Report`
_Financial decision (approval, allocation, or payment authorization) auto-published to stakeholders_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decisionType | string | Yes | Type: APPROVAL, ALLOCATION, DISBURSEMENT, or PAYMENT_AUTHORIZATION |
| amount | number | Yes | Financial amount in EUR |
| decisionDate | date | Yes | Date decision was made |
| approverName | string | Yes | Name of decision maker |
| approverRole | string | Yes | Role or title of decision maker |
| publicationDate | date | Yes | Date published to stakeholders |
| isAutoPublished | boolean | Yes | Whether automatically published without manual intervention |

**Relations:**
- → Organization (many-to-one)

### FinancialReport
**Schema.org:** `schema:Report`
_Exported financial statements (annual, management, or consolidated) generated for a fiscal year._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportType | string | Yes | Annual, Management, or Consolidated |
| reportFormat | string | Yes | Export format: PDF, Excel, XML, or JSON |
| reportStatus | string | No | Draft, Approved, or Published |
| generatedAt | dateTime | Yes | Timestamp of report generation |

**Relations:**
- → FiscalYear (many-to-one)

### ClosingEntry
**Schema.org:** `schema:Thing`
_A manual or automated year-end closing entry per REQ-YEC-001. Captures accrual postings, reversals, revenue/expense closings to the income-summary account, depreciation postings, retained-earnings rollforward, and opening-balance seeding. Lifecycle (`draft → pending-approval → approved → posted` + `reversed`); the `post` transition materialises a balanced `GLTransaction` via the same `x-openregister-create-related` extension T1's `JournalEntry` uses (ADR-031). All amounts in integer cents (money-safe)._
**Primary spec:** bookkeeping-year-end-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| closingEntryNumber | string | Yes | Sequential reference unique per administration (e.g. CE-2026-0001) |
| fiscalYearId | string | Yes | FK to FiscalYear |
| entryDate | date | Yes | Posting date (typically the FY end date; or next FY start date for opening-balance entries) |
| entryType | enum | Yes | One of `revenue-closing`, `expense-closing`, `accrual-reversal`, `depreciation`, `retained-earnings`, `opening-balance`, `manual` |
| description | string | No | Operator-authored description |
| automationTemplate | string | No | FK to ClosingEntryTemplate.templateId when generated automatically |
| amountCents | integer ≥ 0 | Yes | Total closing-entry amount in integer cents |
| currency | string (ISO 4217) | Yes | Currency code (default EUR) |
| glTransactionId | string | No | FK to the materialised GLTransaction (set on `transition:post`) |
| approvalStatus | enum | Yes | One of `draft`, `pending-approval`, `approved`, `posted`, `reversed` |
| approvedBy | string | No | NC user id of the approving CFO / financial officer |
| approvedAt | date-time | No | ISO 8601 timestamp of approval |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → FiscalYear (many-to-one)
- → ClosingEntryTemplate (many-to-one, via `automationTemplate`)
- → GLTransaction (one-to-one, via `glTransactionId`; set on post)
- → Administration (many-to-one)

**Lifecycle (x-openregister-lifecycle):**
- `draft → pending-approval` (submit): bookkeeper submits for CFO review
- `pending-approval → approved` (approve): approver / CFO approves (`requiresRole: approver`); sets `approvedAt`, `approvedBy`
- `approved → posted` (post): materialises a balanced GLTransaction (`onPost` hook, `x-openregister-create-related`) with `sourceReference: closing-entry:@self.closingEntryNumber`; back-references `glTransactionId` via `setBackReference`
- `posted → reversed` (reverse): superseded by a compensating closing entry (audit-only)

### RetainedEarnings
**Schema.org:** `schema:Thing`
_Sub-ledger position for the retained-earnings GL account, one record per `(fiscalYearId, administrationId)` per REQ-YEC-002. Tracks `openingBalanceCents`, `netIncomeCents`, `distributionsCents`, and `closingBalanceCents` (all signed integer cents). The FY close lifecycle materialises this record via `onCloseMaterialiseRetainedEarnings` using helper aggregations (`priorRetainedEarningsClosingCents`, `netIncomeCents`, `distributionsCents`). Two declarative aggregations enforce rollforward and carryforward identity within a 1-cent tolerance._
**Primary spec:** bookkeeping-year-end-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| retainedEarningsId | string | Yes | Operator-stable identifier |
| fiscalYearId | string | Yes | FK to FiscalYear (composite uniqueness with administrationId) |
| openingBalanceCents | integer (signed) | Yes | Opening balance in cents (equals prior FY closingBalanceCents) |
| netIncomeCents | integer (signed) | Yes | Net income for the FY (revenue − expense) in cents; negative = net loss |
| distributionsCents | integer ≥ 0 | No | Dividends / distributions paid (default 0) |
| closingBalanceCents | integer (signed) | Yes | `openingBalanceCents + netIncomeCents - distributionsCents` |
| currency | string (ISO 4217) | Yes | Currency code (default EUR) |
| closingEntryId | string | No | FK to the ClosingEntry that materialised the retained-earnings transfer |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → FiscalYear (many-to-one)
- → ClosingEntry (one-to-one, via `closingEntryId`)
- → Administration (many-to-one)

**Declarative aggregations (x-openregister-aggregations):**
- `validateRollforward` — `closingBalanceCents == openingBalanceCents + netIncomeCents - (distributionsCents | 0)` (tolerance 1 cent, `onFail: block-transition`)
- `validateCarryforward` — `openingBalanceCents == @prior.closingBalanceCents` (tolerance 1 cent, `onFail: warn`)

### ClosingAccount
**Schema.org:** `schema:Thing`
_Designates the single closing / income-summary account per administration (typically 9900) per REQ-YEC-003. Supports time-bounded historicity via `effectiveFrom` so administrations can rotate their closing account across fiscal years (e.g. 9900 → 9990) without rewriting prior closings. Admin-only write; read for bookkeeper / approver / auditor._
**Primary spec:** bookkeeping-year-end-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | FK to Account.accountNumber (typically 9900) |
| administrationId | string | Yes | FK to Administration (one active record per admin at any point in time) |
| isActive | boolean | No | Whether this is the currently active closing account (default `true`) |
| effectiveFrom | date | No | Optional start date if rotating closing accounts (null = applies indefinitely) |

**Relations:**
- → Account (many-to-one, via `accountNumber`)
- → Administration (many-to-one)

### ClosingEntryTemplate
**Schema.org:** `schema:Thing`
_Declarative rule driving automated closing-entry generation per REQ-YEC-004 / REQ-YEC-010. Each template matches a GL account range (`accountPattern`), transfers the matched balances through the configured `closingAccountNumber`, and optionally emits a companion accrual-reversal in the next FY (`reverseNextPeriod: true`). Lifecycle (`active → paused → archived`) lets operators disable a template without deleting it. Consumed by the FiscalYear `transition:beginClose` hook `onBeginCloseGenerateClosingEntries`._
**Primary spec:** bookkeeping-year-end-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| templateId | string | Yes | Stable operator-readable id, unique per administrationId (e.g. `revenue-closing`) |
| templateName | string | Yes | Display name |
| description | string | No | Operator documentation |
| accountPattern | string | Yes | Account range or regex (e.g. `4000-4999`) |
| closingAccountNumber | string | Yes | FK to Account.accountNumber (the target closing / income-summary account, e.g. 9900) |
| reverseNextPeriod | boolean | No | When true, auto-emit a companion accrual-reversal in the next FY (default `false`) |
| automationTrigger | enum | No | One of `manual`, `on-close` (default), `on-check` |
| administrationId | string | Yes | FK to Administration |
| lifecycleState | enum | Yes | One of `active`, `paused`, `archived` (default `active`) |
| createdAt | date-time | Yes | Creation timestamp |
| modifiedAt | date-time | No | Last-modified timestamp |

**Relations:**
- → Account (many-to-one, via `closingAccountNumber`)
- → Administration (many-to-one)

**Lifecycle (x-openregister-lifecycle):**
- `active → paused` (pause): stop firing during the next close cycle (approver / admin)
- `paused → active` (resume): re-enable (approver / admin)
- `paused → archived` (archive): permanently retire, kept for audit (admin only)

**Seed records (REQ-YEC-012):** `revenue-closing` (4000-4999 → 9900), `expense-closing` (5000-6999 → 9900), `accrual-reversal` (9700-9799 → 5900, `reverseNextPeriod: true`).

### FiscalYear
**Schema.org:** `schema:AccountingPeriod`
_A fiscal year lifecycle record tracking the `open → closing → closed → reopened` state machine for the year-end close process. Closing emits a balanced retained-earnings transfer `JournalEntry` (manual sub-type) and an opening-balance `JournalEntry` for the next year (balance-sheet accounts only). Dimensional rollover fires via CloudEvents consumed by CostCenter, KostenDrager, and Project registers. Admin-only reopen emits two reversing `JournalEntry` records pairing with the original journals for full audit traceability. All close logic is schema-declared per ADR-031; no `YearEndCloseService` PHP class._
**Primary spec:** bookkeeping-year-end-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| yearNumber | integer | Yes | Calendar year reference (e.g. 2026). Unique per `administrationId` (composite uniqueness constraint) |
| startDate | date | Yes | First day of the fiscal year (typically YYYY-01-01; supports broken fiscal years) |
| endDate | date | Yes | Last day of the fiscal year |
| state | enum | Yes | One of `open`, `closing`, `closed`, `reopened` |
| closingJournalId | string | No | FK to the `JournalEntry` that posted the year-end retained-earnings transfer (set by `open → closing` action) |
| openingJournalId | string | No | FK to the `JournalEntry` that posted the next-year opening balances (set by `closing → closed` action) |
| closedAt | date-time | No | When the close completed (required when `state = closed`) |
| closedBy | string | No | NC user id of the actor who completed the close (required when `state = closed`) |
| reopenedAt | date-time | No | When the year was reopened (required when `state = reopened`) |
| reopenedBy | string | No | NC user id of the admin who reopened the year (required when `state = reopened`) |
| reopenReason | string | No | Operator-supplied justification for the reopen (required when initiating `closed → reopened`) |
| administrationId | string | Yes | FK to the Administration. Combined with `yearNumber` forms the composite uniqueness key |

**Relations:**
- → JournalEntry (one-to-many, via `closingJournalId` and `openingJournalId` for the close/reopen journal pair; reversing entries reference original journal ids for full audit traceability)
- → CostCenter, KostenDrager, Project (via CloudEvents on `closing → closed` for dimensional rollover)

**Lifecycle (x-openregister-lifecycle):**
- `open → closing` (beginClose): requires all T3 fiscal periods closed (FiscalYearGuard); emits retained-earnings transfer JournalEntry. Per `bookkeeping-year-end-close` (REQ-YEC-010), the `closing` state is the spec's `in-progress` and triggers the `onBeginCloseGenerateClosingEntries` hook which iterates active `ClosingEntryTemplate` records and creates one `pending-approval` `ClosingEntry` per template (sums `accountPattern`-matched GL balances; emits a `draft` companion accrual-reversal on the next FY when `reverseNextPeriod: true`).
- `closing → closed` (close): emits opening-balance JournalEntry for next year; fires dimensional rollover CloudEvents; sets `closedAt`, `closedBy`. Per REQ-YEC-006, the transition is gated by a `requirePreconditions` array — `trial-balance-verified`, `accruals-recorded`, `depreciation-posted`, `fx-declared`, `related-party-reviewed` — each backed by a named aggregation. CFO override path is declarative via `overrideField: overrideChecklist` + `overrideRequiresRole: approver` + `overrideRequiresMemo: true` + `overrideAuditTrail: true`. On entry to `closed`, the `onCloseMaterialiseRetainedEarnings` hook creates the corresponding `RetainedEarnings` record using helper aggregations.
- `closed` state: `immutablePeriod: enabled: true` per REQ-YEC-007 — all GLTransaction rows on this FY (and their GLLine rows via `matchVia: transactionId`) become read-only; new postings rejected with `Fiscal Year @self.yearNumber is closed and immutable.`
- `closed → reopened` (reopen): admin-only (`requiresRole: admin`); requires non-empty `reopenReason`; emits two reversing JournalEntry records; sets `reopenedAt`, `reopenedBy`.

**Declarative aggregations (x-openregister-aggregations) — added by `bookkeeping-year-end-close`:**
- `trialBalanceImbalanceCents`, `accrualReversalCoverage`, `accrualAccountBalanceCents`, `depreciationClosingEntryCount`, `activeFixedAssetCount`, `foreignCurrencyFxCoverage`, `foreignCurrencyAccountCount`, `relatedPartyUnacknowledgedCount`, `relatedPartyTransactionCount` — back the closing-checklist preconditions (REQ-YEC-006)
- `netIncomeCents`, `priorRetainedEarningsClosingCents`, `distributionsCents` — feed the `onCloseMaterialiseRetainedEarnings` hook (REQ-YEC-002)
- `balanceCarryforwardValid` — counts cross-FY account-level mismatches between prior closing and current opening; `onFail: warn` (REQ-YEC-008)

> **Reconciliation note (add-shillinq-year-end-close, 2026-06-03):** The original `FiscalYear` entry (primary spec: `financial-reporting-accountability`) declared a minimal 5-field schema (`year`, `startDate`, `endDate`, `isClosed`, `closingDate`) without lifecycle machinery. This entry supersedes it with the full T4 year-end close lifecycle declared in `lib/Settings/shillinq_register.json`. The field `year` is renamed `yearNumber` (integer type, composite uniqueness with `administrationId`); `isClosed` and `closingDate` are replaced by the `state` enum and `closedAt`/`closedBy` pair. New `JournalEntry` records now reference `FiscalYear` via `fiscalYearId` for close/reopen journal pairing (previously the reference existed only in the generic `JournalEntry → FiscalYear` relation). Implementations using the old field names must migrate to the new schema shape.

> **FiscalPeriod sibling & enactedTaxRates annotation (bookkeeping-deferred-tax, 2026-06-08):**
> The T3 fiscal sub-year period schema (`FiscalPeriod`, registered as a sibling
> of `FiscalYear` in `lib/Settings/shillinq_register.json`) is the canonical
> balansdatum entity referenced by `periodId` foreign keys throughout the
> deferred-tax registers (`TemporaryDifference`, `DeferredTaxMovement`,
> `TaxRateReconciliation`, `TaxProvision`) and by other T3 capabilities (vpb-mkb,
> innovatiebox, BTW). The deferred-tax change adds an optional `enactedTaxRates`
> object to `FiscalPeriod` keyed by jurisdiction (ISO 3166-1 alpha-2), where each
> entry carries `rate` (basis points, 1/10000) and `effectiveDate` (date) for the
> enacted statutory tax rate. `TaxCalculationService` reads this map to
> re-measure deferred-tax positions reversing on or after `effectiveDate` per
> REQ-DT-005 / IAS 12 §47–48, producing `DeferredTaxMovement.rateChangeAdjustment`
> entries. The extension is additive (nullable) — existing FiscalPeriod consumers
> stay correct. Declared as an `x-openspec-extend` patch in
> `lib/Settings/register.d/bookkeeping-deferred-tax.json` (ADR-037).

### FiscalPeriod
**Schema.org:** `schema:DateRange`
_A monthly / quarterly / weekly accounting period with a declarative
`open → closing → closed → audit-locked` lifecycle that gates posting and
freezes auditable history once an auditor signs off. Promotes T1's
stub-string `GLLine.periodId` field to a real foreign key via the
ADR-037 modular register fragment `lib/Settings/register.d/bookkeeping-period-close.json`._
**Primary spec:** bookkeeping-period-close (T2)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | Stable identifier within an administration (e.g. `2026-Q1`, `2026-M03`, `2026-W12`) |
| name | string | Yes | Human-readable name surfaced in index columns and the detail header (e.g. `Q1 2026`, `March 2026`) |
| administrationId | string | Yes | FK to the Administration owning the period; reads are scoped to the requesting user's administration |
| startDate | date | Yes | Inclusive first day of the period |
| endDate | date | Yes | Inclusive last day of the period |
| fiscalYear | integer | Yes | The fiscal year this period belongs to |
| state | enum | Yes | One of `open`, `closing`, `closed`, `audit-locked` |
| closedAt | date-time | No | Timestamp the period was closed; set by the close transition |
| closedBy | string | No | NC user id of the close operator |
| auditLockedAt | date-time | No | Timestamp the period was audit-locked; set by the lockForAudit transition |
| auditLockedBy | string | No | NC user id of the auditor who locked the period |
| closeReason | string | No | Operator-supplied justification captured when a closed period is reopened |
| reopenedHistory | array | No | Append-only audit trail of `{closedAt, closedBy, reopenedAt, reopenedBy, closeReason}` cycles |
| taskChecklistItems | array | No | Pre-close checklist rows operators mark resolved before the `closing → closed` transition |
| aiFlags | array | No | Non-blocking close-assistant warnings (open AP / AR / unreconciled bank receipts / expense claims) |

**Relations:**
- → Administration (many-to-one, via `administrationId`)
- → GLLine (one-to-many, via `periodId` — promoted from T1's stub string by the `add-shillinq-period-close` change; existing string values resolve by exact match — no destructive data migration)
- → GLTransaction (one-to-many, via `periodId` — the `GLTransaction.post` transition rejects postings whose resolved period is in state `closed` or `audit-locked`)
- → VATAuditRecord, APInvoice, ARInvoice, BcfClaim, WipBalance, Kostenpost (one-to-many, via `periodId` — fleet-wide references already use `relatedSchema: FiscalPeriod`)

**Lifecycle (x-openregister-lifecycle):**
- `open → closing` (startClose): role `period-closer`; no preconditions.
- `closing → closed` (close): role `period-closer`; precondition `PeriodCloseGuard::mandatoryChecklistResolved`; declarative `set-fields` action stamps `closedAt = @now`, `closedBy = @currentUser`.
- `closed → open` (reopen): role `period-closer`; precondition `PeriodCloseGuard::closeReasonSupplied` (non-empty `closeReason`); declarative `append-reopen-history` action preserves `{closedAt, closedBy, reopenedAt, reopenedBy, closeReason}` in the append-only `reopenedHistory` array; clears `closedAt` / `closedBy`.
- `closed → audit-locked` (lockForAudit): role `auditor`; declarative `set-fields` action stamps `auditLockedAt = @now`, `auditLockedBy = @currentUser`.
- `audit-locked`: no outgoing transition declared → irreversibility at the schema level (REQ-PC-003). Late corrections require a compensating journal in the next open period.

**Posting precondition (additive on T1 GLTransaction.post):** `PeriodCloseGuard::periodOpen` resolves the posting's `periodId` (or `postingDate` when `periodId` is absent) to a FiscalPeriod record scoped to the transaction's administration and rejects when state ∈ `{closed, audit-locked}`. The merge is additive — T1's existing `BalanceGuard::isBalanced` requires and allocation action survive (verified by `PeriodCloseFragmentTest::testAugmentsGlTransactionPostAdditively`).

**Repair-step backfill:** `lib/Repair/BackfillFiscalPeriods.php` (wired in `appinfo/info.xml` `<post-migration>` after `InitializeSettings`) lists every distinct `(administrationId, periodId)` tuple on `GLLine` and creates a minimal `state: open` FiscalPeriod record for any that lacks one. Idempotent — re-runs produce zero saves when every tuple already has a record. The derived `name` / `startDate` / `endDate` / `fiscalYear` are parsed from the periodId slug (Q-quarter, M-month, W-ISO-week, FY-fiscal-year, or year-only fallback).

**Reconciliation note (add-shillinq-period-close, 2026-06-08):** This entry supersedes the inline `FiscalPeriod` callout under the `FiscalYear` section (2026-06-08 deferred-tax note). The `enactedTaxRates` extension referenced in that callout remains additive — declared in the deferred-tax fragment and additive to the schema declared here. The previously merged `bookkeeping-period-close` change shipped the lifecycle + service implementation under the schema slug `PeriodClose`; that slug was renamed to the canonical `FiscalPeriod` by `add-shillinq-period-close` to align with the existing AR / AP / VAT / WIP / ICP / R&D-subsidies sibling schemas that already declared `relatedSchema: FiscalPeriod`.

### FixedAsset
**Schema.org:** `schema:Thing`
_A capitalised tangible or intangible business asset with declarative depreciation rules (linear, degressive, units-of-production, none), parallel commercial/fiscal streams, and a managed lifecycle (proposed → active → disposed → archived). Depreciation values are derived on demand via `x-openregister-calculations` — no materialised schedule table._
**Primary spec:** bookkeeping-fixed-assets-depreciation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetNumber | string | Yes | Operator-assigned unique reference within the administration (e.g. FA-0001) |
| name | string | Yes | Human-readable asset name |
| assetCategory | enum | Yes | One of buildings, vehicles, machinery, it-equipment, furniture, intangibles |
| acquisitionDate | date | Yes | Date the asset entered service |
| acquisitionCost | number ≥ 0 | Yes | Original cost in the administration's base currency |
| currency | string (ISO 4217) | Yes | Currency of the acquisition cost |
| usefulLifeMonths | integer ≥ 1 | Yes | Useful life expressed in months |
| residualValue | number ≥ 0 | Yes | Estimated salvage value at end of useful life |
| depreciationMethod | enum | Yes | One of linear, degressive, units-of-production, none |
| degressiveRate | number | No | Annual declining-balance percentage when depreciationMethod = degressive |
| commercialRate | number | No | Annual rate for commercial books (IFRS / Dutch GAAP) — enables parallel commercial stream |
| fiscalRate | number | No | Annual rate for fiscal books (Wet IB / Wet VPB) — may differ from commercialRate |
| assetAccountNumber | string | Yes | FK to Account carrying the asset's gross value |
| accumulatedDepAccountNumber | string | Yes | FK to contra Account for accumulated depreciation |
| depreciationExpenseAccountNumber | string | Yes | FK to P&L Account for the depreciation expense charge |
| disposalDate | date | No | Date the asset was disposed of (sale, scrap, donation) |
| disposalAccountingTreatment | enum | No | One of sale, scrap, donation, transfer (required when disposalDate is set) |
| lifecycleState | enum | Yes | One of proposed, active, disposed, archived |
| administrationId | string | Yes | FK to the Administration owning the asset |

**Calculated fields (x-openregister-calculations, not stored):**
- `monthlyDepreciation` — monthly charge for the current period
- `currentBookValue` — net book value as of today
- `commercialBookValue` — book value under the commercial rate stream
- `fiscalBookValue` — book value under the fiscal rate stream

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Account (many-to-one, via assetAccountNumber → accountNumber; gross value account)
- → Account (many-to-one, via accumulatedDepAccountNumber → accountNumber; accumulated depreciation)
- → Account (many-to-one, via depreciationExpenseAccountNumber → accountNumber; P&L expense)
- → GLLine (one-to-many, via `GLLine.subLedgerRef` when `GLLine.subLedgerType = fixed-asset`; the general ledger lines arising from depreciation postings reference back to this asset)

> **Reconciliation note (add-shillinq-fixed-assets-depreciation, 2026-06-01):** The earlier
> `FixedAsset` entry (primary spec `obligation-financial-administration`, using `purchaseDate`
> / `purchaseCost` / `assetType` / `status`) has been superseded by this updated entry.
> `FixedAsset` is now the canonical T4 fixed-assets register schema declared in
> `lib/Settings/shillinq_register.json`, conforming to REQ-FA-002 of the
> `bookkeeping-fixed-assets-depreciation` spec. The `DepreciationSchedule` relation below
> is replaced by `x-openregister-calculations` derived fields — no materialised schedule
> table (design D2). The `GLLine.subLedgerRef` link is the only cross-register pointer;
> downward specs referencing `FixedAsset` MUST use `assetNumber` as the FK target and
> `administrationId` for administration scoping.

> **Reconciliation note (bookkeeping-fixed-assets-depreciation, 2026-06-09):** The T3
> `bookkeeping-fixed-assets-depreciation` change carries forward the canonical FixedAsset
> schema above and unions REQ-FA-002 operator-facing property aliases (`assetType` ↔
> `assetCategory`, `usefulLifeYears` ↔ `usefulLifeMonths`, `purchaseDate` ↔ `acquisitionDate`,
> `purchaseCost` ↔ `acquisitionCost`, `declineRate` ↔ `degressiveRate`, `status` ↔
> `lifecycleState`, `accumulatedDepreciationAccountNumber` ↔ `accumulatedDepAccountNumber`)
> plus four genuinely new optional fields (`description`, `productionUnits`,
> `capitalizationAccountNumber`, `location`, `costCenterCode`, `retirementDate`,
> `salvageProceeds`, `transferSourceAssetRef`). Aliases share the canonical field's
> semantics; the canonical fields remain required. The aliasing lands as an ADR-037
> register-fragment overlay at `lib/Settings/register.d/bookkeeping-fixed-assets-depreciation.json`,
> never editing the monolith. The lifecycle is extended with `transferInternal` (cost-centre
> reallocation; no GL posting) and `splitTransfer` (proportional split into a new FixedAsset
> with `transferSourceAssetRef` pointing back), and the `activate` action acquires a
> `emit-journal-entry-and-schedule` subtype that auto-generates the first DepreciationSchedule
> + acquisition GL posting per REQ-FA-008.

### FrameworkAgreement
**Schema.org:** `schema:Service`
_Framework agreement enabling mini-competition and direct award within procurement_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| agreementNumber | string | Yes | Unique framework agreement identifier |
| title | string | Yes | Framework agreement title |
| status | string | Yes | Status: active, expired, suspended, archived |
| awardDate | datetime | Yes | Date framework was awarded |
| expiryDate | datetime | Yes | Framework expiration date |
| minCompetitionThreshold | number | No | Minimum suppliers required for mini-competition |

**Relations:**
- → Supplier (many-to-many)
- → Contract (one-to-many)

### Freelancer
**Schema.org:** `schema:Person`
_A self-employed professional or contractor managing their own work and time_
**Primary spec:** freelancers-zzp

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| expertise | array | No | Professional expertise areas |
| hourlyRate | number | No | Default hourly billing rate |
| status | string | Yes | Freelancer status (active/inactive) |

**Relations:**
- → Person (many-to-one)
- → TimeEntry (one-to-many)
- → Assignment (one-to-many)

### FundAllocation
**Schema.org:** `schema:MonetaryAmount`
_Budget allocation and fund management for public sector spending with fiscal year tracking_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Fund or budget name |
| totalAmount | number | Yes | Total allocated amount in decimal format |
| currency | string | Yes | Currency code (EUR) |
| fiscalYear | integer | Yes | Fiscal year of allocation |
| availableAmount | number | Yes | Remaining available amount for allocation |
| allocationType | string | Yes | Type: operational, investment, grant, or subsidy |
| budgetCode | string | Yes | Government budget code reference |

**Relations:**
- → GovernmentEntity (many-to-one)
- → SpendingRecord (one-to-many)

### FundingSource
_A source of funds that can be allocated to budgets and expenditures_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the funding source |
| totalAmount | number | Yes | Total available funds |
| status | string | Yes | Status: active, inactive, depleted |
| description | string | No | Details about the funding source |

**Relations:**
- → BudgetAllocation (one-to-many)

### GeneralLedgerAccount
**Schema.org:** `schema:Product` _(deprecated — use `Account` with `schema:DefinedTerm` instead)_
_**DEPRECATED.** Superseded by the `Account` entry (bookkeeping-chart-of-accounts, 2026-05-18). Retained here for historical reference only. New register declarations MUST use `Account`. The `currentBalance` field is not carried forward — balance is computed from GL lines by the general ledger tier._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | The unique account code (e.g., 1000, 4100) |
| accountName | string | Yes | The descriptive account name |
| accountType | string | Yes | Account classification: Asset, Liability, Equity, Revenue, or Expense |
| currency | string | Yes | ISO 4217 currency code for the account |
| currentBalance | object | No | Current balance as {value, currency} following MonetaryAmount schema |

**Relations:**
- → JournalEntry (one-to-many)

### GeneralLedgerEntry
**Schema.org:** `schema:Thing` _(deprecated — use `GLTransaction` + `GLLine` instead)_
_**DEPRECATED.** Superseded by the `GLTransaction` / `GLLine` header-line split (bookkeeping-general-ledger, 2026-06-02). The flat single-entry model could not express the balance invariant declaratively (see design.md Decision D2). Retained here for historical reference only; new register declarations MUST use `GLTransaction` and `GLLine`. Downstream specs (trial balance T3, financial reporting T4) MUST reference `GLTransaction` as the posting header and `GLLine.accountNumber` as the FK target._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryDate | datetime | Yes | Date of the GL entry |
| accountNumber | string | Yes | General ledger account code |
| accountName | string | Yes | Name of the GL account |
| debitAmount | number | No | Debit amount in base currency |
| creditAmount | number | No | Credit amount in base currency |
| description | string | Yes | Description of the transaction |
| reference | string | No | Reference document number or transaction ID |
| status | string | Yes | Status (draft, posted, reversed) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → APTransaction (many-to-one)

> **Reconciliation note (bookkeeping-general-ledger, 2026-06-02):** `GeneralLedgerEntry` is superseded
> by the `GLTransaction` (header) + `GLLine` (line) split introduced in the
> `bookkeeping-general-ledger` change. The flat model was rejected because the balance constraint
> (SUM debits = SUM credits) cannot be expressed declaratively on a single-entry shape — it requires
> grouping over a *set* of lines. The header/line split is canonical in RGS and every reference SMB
> accounting product. Spec: `openspec/changes/bookkeeping-general-ledger/design.md` Decision D1.

### GoodsReceipt
**Schema.org:** `schema:Thing`
_Receipt and verification of goods delivered at multiple locations with delivery confirmation_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| receiptNumber | string | Yes | Unique goods receipt identifier |
| receivedDate | datetime | Yes | Date goods were received |
| location | string | Yes | Physical receiving location or site |
| quantity | number | Yes | Quantity of items received |
| notes | string | No | Quality notes, damage, or discrepancies |
| signatureRequired | boolean | No | Whether signature is required for delivery |
| status | string | Yes | Receipt status (draft, received, verified, closed) |

**Relations:**
- → PurchaseOrder (many-to-one)
- → InventoryStock (many-to-many)
- → Organization (many-to-one)

### GovernmentEntity
**Schema.org:** `schema:Organization`
_Dutch government organization with GBA/BRP integration and CCH research access for public sector bookkeeping_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official legal name of the government entity |
| kvkNumber | string | No | Dutch Chamber of Commerce registration number |
| bsnNumber | string | No | Citizen Service Number for GBA linking |
| brkNumber | string | No | Land Registry number for BRP linking |
| govLevel | string | Yes | Government level: municipality, province, national, or waterboard |
| cchAccessCode | string | No | Central Code Bank (CCH) research access identifier |
| email | string | No | Organization contact email |
| telephone | string | No | Organization contact telephone |

**Relations:**
- → FundAllocation (one-to-many)
- → SpendingRecord (one-to-many)
- → SubmissionDossier (one-to-many)

### Grant
**Schema.org:** `schema:Grant`
_A financial grant or subsidy awarded to an organization for specified purposes under a subsidy scheme_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| grantId | string | Yes | Unique grant identifier |
| name | string | Yes | Grant name |
| awardedAmount | number | Yes | Amount awarded |
| awardDate | datetime | Yes | Date grant was awarded |
| status | string | Yes | Grant status: active, completed, suspended, revoked |
| accountingStandard | string | No | Governmental accounting standard applied |
| isSISAEligible | boolean | No | Eligible for Single Information Single Audit |

**Relations:**
- → SubsidyScheme (many-to-one)
- → Organization (many-to-one)
- → GrantPortfolio (many-to-one)

### GrantPortfolio
**Schema.org:** `schema:Collection`
_A managed collection of grants for organizational tracking, compliance monitoring, and concentration risk analysis_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| portfolioId | string | Yes | Unique portfolio identifier |
| name | string | Yes | Portfolio name |
| description | string | No |  |
| totalGrantValue | number | No | Total value of all grants |
| complianceStatus | string | No | Compliance status: compliant, non-compliant, under-review |
| concentrationRiskLevel | string | No | Risk level: low, medium, high |
| lastAuditDate | datetime | No |  |

**Relations:**
- → Organization (many-to-one)
- → Grant (one-to-many)

### GLLine
**Schema.org:** `schema:MonetaryAmount`
_A debit-or-credit line within a GLTransaction, encoding polarity in the `side` enum. `amount` is always non-negative; sign lives in `side`. Supersedes the flat `GeneralLedgerEntry` shape (see reconciliation note on that entry). Extended with `eliminationFlag` for GR consolidation per REQ-GRC-003._
**Primary spec:** bookkeeping-general-ledger

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | FK to the parent GLTransaction.id |
| lineNumber | integer | Yes | Stable 1-based ordering within the transaction |
| accountNumber | string | Yes | FK to Account.accountNumber |
| side | enum | Yes | One of debit, credit |
| amount | number ≥ 0 | Yes | Non-negative amount in the transaction's currency |
| currency | string | Yes | ISO 4217 currency code; must equal GLTransaction.currency (T1 single-currency invariant) |
| periodId | string | No | Auto-resolved by lifecycle engine on GLTransaction.post transition (stub string in T1, FK to FiscalPeriod in T3) |
| subLedgerType | enum | No | One of ap, ar, project, inventory, none (T2 owns the sub-ledger registers; `inventory` added by inventory-cogs-posting 2026-06-08 — REQ-CG-005 + REQ-GL-009 extension; subLedgerRef points to InventoryValuation.id) |
| subLedgerRef | string | No | FK identifier into the sub-ledger when subLedgerType ≠ none |
| costCenter | string | No | Cost-center code for allocation reporting (backwards-compatible alias; see costCenterCode) |
| description | string | No | Line-level description |
| eliminationFlag | boolean | No | When true, excludes line from consolidated trial-balance (GR consolidation per REQ-GRC-003) |
| costCenterCode | string | No | FK to CostCenter.code for dimension-tagged analytical reporting per REQ-CC-003 |
| kostenDragerCode | string | No | FK to KostenDrager.code for cost-unit analytical reporting per REQ-CC-003 |
| projectCode | string | No | FK to Project.code for project accounting and WBSO pre-positioning per REQ-CC-003 + REQ-CC-007 |
| dimensions | object | No | Free-form key→value map for custom analytical dimensions; each key matches a registered custom dimension register, each value matches that register's code field; validated via OR relations engine per REQ-CC-003 |

**Relations:**
- → GLTransaction (many-to-one, via transactionId → GLTransaction.id)
- → Account (many-to-one, via accountNumber → Account.accountNumber)
- → CostCenter (many-to-one, via costCenterCode → CostCenter.code; additive per REQ-CC-003)
- → KostenDrager (many-to-one, via kostenDragerCode → KostenDrager.code; additive per REQ-CC-003)
- → Project (many-to-one, via projectCode → Project.code; additive per REQ-CC-003 + REQ-CC-007)
- → AnalyticalDimension (many-to-one, via dimensions map keys → AnalyticalDimension.code; custom analytical dimensions validated via OR relations engine per REQ-CD-003)

> **Reconciliation note (add-shillinq-cost-centers-dimensions, 2026-06-03):** The T1 `GLLine` schema is additively extended with four new optional fields (`costCenterCode`, `kostenDragerCode`, `projectCode`, `dimensions`) per REQ-CC-003. The existing `costCenter` field is retained as the backwards-compatible alias for `costCenterCode`. T1 single-dimension callers remain correct — the new fields are nullable and non-required. Segment P&L aggregations (`segmentPnlByCostCenter`, `segmentPnlByKostenDrager`, `segmentPnlByProject`) are declared on `GLLine` as `x-openregister-aggregations` per ADR-031 + REQ-CC-005; no PHP `SegmentReportService` is authored.

> **Reconciliation note (bookkeeping-cost-centers-dimensions, 2026-06-03):** The `dimensions` free-form map in `GLLine` is further defined by the `bookkeeping-cost-centers-dimensions` spec (REQ-CD-003). Each key in `dimensions` MUST match a registered `AnalyticalDimension.code` for that administration; each value MUST resolve to an existing record code in that dimension's register. Validation is declared via OR's relation engine — no PHP DimensionValidationService. The `AnalyticalDimension` schema (new in this spec) governs the dimension type definitions; see its ADR-000 entry above. Custom analytical dimensions are operator-extensible without PHP or Vue code changes per REQ-CD-001.

### GLTransaction
**Schema.org:** `schema:AccountingTransaction`
_Double-entry general-ledger posting header. Owns the lifecycle (draft → posted → reversed) and the balance invariant (SUM debits = SUM credits across child GLLine rows). Introduced in T1 (bookkeeping-general-ledger, 2026-06-02) as the canonical replacement for the flat `GeneralLedgerEntry` shape (see that entry's reconciliation note). Balance precondition references `OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced` as an ADR-031 exception-path guard._
**Primary spec:** bookkeeping-general-ledger

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Sequential number unique per administration + fiscal year |
| postingDate | date | Yes | Effective accounting date |
| periodId | string | Yes | FK to FiscalPeriod (T3); plain string identifier in T1 |
| currency | string | Yes | ISO 4217 base currency for the posting |
| description | string | Yes | Human-readable summary |
| sourceReference | string | No | External document number (invoice, bank statement ref, asset repair ID) |
| state | enum | Yes | One of draft, posted, reversed |
| journalEntryId | string | No | Back-reference to the JournalEntry that materialised this posting |
| administrationId | string | Yes | FK to the Administration owning the posting |
| reversesTransactionId | string | No | FK to the GLTransaction that this transaction reverses |

**Relations:**
- → GLLine (one-to-many, via id → GLLine.transactionId)

> **T1 split rationale (bookkeeping-general-ledger, 2026-06-02):** The header/line split is required
> for the balance constraint to be expressible declaratively (ADR-031): the invariant operates over
> a *group* of lines, not a single row. A flat `GeneralLedgerEntry` model would force the check into
> application code at write-time. Spec: `openspec/changes/bookkeeping-general-ledger/design.md` D1–D2.

### GRDeelnemer
**Schema.org:** `schema:Organization`
_A deelnemer (participating municipality, province, or waterboard) of a gemeenschappelijke regeling (GR). Holds the quotum-aandeel and an optional cross-administration FK enabling doorbelasting materialisation when the deelnemer also runs shillinq. The active/archived lifecycle is declarative; no PHP service. Cross-referencing spec: `bookkeeping-gr-consolidation` (add-shillinq-gr-consolidation, 2026-06-01)._
**Primary spec:** bookkeeping-gr-consolidation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deelnemerType | enum | Yes | One of gemeente, provincie, waterschap |
| deelnemerNaam | string | Yes | Official name of the deelnemer |
| administrationId | string | No | Optional FK to the deelnemer's own shillinq administration; drives cross-admin doorbelasting materialisation on GR period-close |
| aandeel | number | Yes | Quotum-aandeel 0 ≤ x ≤ 1; sum across active deelnemers SHOULD equal 1.0 |
| actief | boolean | No | Whether this deelnemer currently participates; default true |
| lifecycleState | enum | Yes | One of active, archived |

**Relations:**
- → GRVerdeelsleutel (one-to-many, through costClusterAccountNumbers apportionment)

### GRVerdeelsleutel
**Schema.org:** `schema:Thing`
_An apportionment rule parameterising the per-deelnemer split of a cost cluster within a gemeenschappelijke regeling. Multiple verdeelsleutels MAY apply to the same cost cluster, sequenced by lineNumber. The declarative `x-openregister-aggregations.doorbelastingPerDeelnemer` block drives the doorbelasting calculation without a PHP consolidation service. Cross-referencing spec: `bookkeeping-gr-consolidation` (add-shillinq-gr-consolidation, 2026-06-01)._
**Primary spec:** bookkeeping-gr-consolidation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sleutelNaam | string | Yes | Human-readable name of this apportionment rule |
| costClusterAccountNumbers | array | Yes | Array of Account.accountNumber strings identifying the cost cluster |
| verdelingsType | enum | Yes | One of vast-percentage, inwoner-aantal, gewogen-oppervlak, custom-formula |
| parameters | object | No | Per-deelnemer split parameters validated against verdelingsType |
| lineNumber | integer | Yes | Sequence number controlling application order when multiple sleutels cover the same cost cluster |

**Relations:**
- → Account (many-to-many, via costClusterAccountNumbers → Account.accountNumber)

### IntercompanyTransaction
**Schema.org:** `schema:FinancialProduct`
_Transaction between related entities for transfer pricing, loans, or intercompany netting_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionDate | datetime | Yes | Date of the transaction |
| amount | number | Yes | Transaction amount in EUR |
| type | string | Yes | Service fee, goods transfer, loan, transfer pricing, netting, etc. |
| description | string | No | Transaction description and purpose |
| reference | string | No | Reference number or invoice number |
| interestRate | number | No | Interest rate if applicable |
| status | string | Yes | Pending, completed, settled, cancelled, etc. |

**Relations:**
- → Entity (many-to-one)
- → Entity (many-to-one)
- → APTransaction (many-to-one)

### InventoryItem
**Schema.org:** `schema:Product`
_Product tracked in inventory with stock levels and sourcing information_
**Primary spec:** procurement-integration
> **Additive field (inventory-lot-batch-expiry):** `requiresLotTracking` (boolean, default: false) — when true, every inbound receipt of this SKU must be assigned to an InventoryLot. Non-breaking additive field per REQ-LOT-008.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | Yes | Stock keeping unit identifier |
| description | string | No | Detailed product description |
| category | string | Yes | Product category for spend management |
| unitPrice | number | Yes | Unit purchase price |
| currency | string | Yes | Currency code (EUR) |
| unitCode | string | No | Unit of measure (ST, KG, L, etc) |
| taxRate | number | No | Applicable VAT percentage |
| currentStock | number | Yes | Current quantity in stock |
| minimumStock | number | No | Minimum stock level for reordering |
| reorderQuantity | number | No | Standard quantity to order |
| storageLocation | string | No | Physical storage location code |
| requiresLotTracking | boolean | No | When true, every receipt must include an InventoryLot. Default: false. |

**Relations:**
- → Supplier (many-to-one)
- → ProcurementOrder (many-to-many)
- ← InventoryLot (one-to-many; via InventoryLot.productSku)

### InventoryStock
**Schema.org:** `schema:Thing`
_Stock level per SKU at a specific bin Location. Always recorded at bin (most-granular) level; warehouse/zone quantities are aggregated via Location.stockRollup per REQ-LOC-005 and REQ-LOC-009._
**Primary spec:** inventory-multi-warehouse
**Co-declaring spec:** procurement-integration (original generic shape)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sku | string | Yes | Product SKU — references Product.sku |
| locationId | string | Yes | FK to Location (must be bin type per REQ-LOC-009) |
| quantity | number | Yes | On-hand stock quantity at this bin location |
| reservedQuantity | number | No | Quantity reserved for pending orders |
| unitCost | number | No | Unit cost at this location for inventory valuation |
| lastMovementDate | date | No | Date of most recent stock movement |
| administrationId | string | Yes | FK to Administration — scoped per org |

**Relations:**
- → Product (many-to-one)
- → Location (many-to-one, locationId FK → bin-type Location)
- → Organization (many-to-one)
- → InventoryReorderRule (one-to-many, via inventoryStockId — reorder policies per location)

### InventoryReorderRule
**Schema.org:** `schema:Thing`
_Per-item, per-location reorder policy declaring min/max stock levels, lead-time-aware reorder-point thresholds, low-stock alert policy, and optional auto-purchase-order generation. Reorder decision logic is fully declarative via x-openregister-lifecycle, x-openregister-aggregations, and x-openregister-notifications per ADR-031 and ADR-022._
**Primary spec:** inventory-reorder-automation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| inventoryStockId | string (FK) | Yes | Reference to the InventoryStock record (item + location) |
| supplierId | string (FK) | No | Primary supplier for reorder |
| minimumLevel | number ≥ 0 | Yes | Stock quantity at or below which alert triggers |
| maximumLevel | number > minimumLevel | Yes | Target stock quantity for replenishment |
| reorderPoint | number ≥ minimumLevel | Yes | Stock quantity at which reorder is triggered (factors lead time) |
| reorderQuantity | number > 0 | Yes | Standard quantity to order on alert |
| leadTimeDays | integer ≥ 0 | No | Supplier lead time in days (defaults to supplier.leadTimeDays) |
| safetyStockDays | integer ≥ 0 | No | Safety margin in days above lead time (default: 1) |
| alertThreshold | number ≥ 0 | No | Percentage above minimum for early-warning alert (default: 20%) |
| autoPurchaseOrder | boolean | Yes (default: false) | Whether to auto-generate PO on alert |
| autoPurchaseOrderApprovalRequired | boolean | No (default: true) | Whether auto-PO requires operator approval |
| spendingLimit | number ≥ 0 | No | Maximum spend per auto-generated PO; excess blocks auto-order |
| alertChannel | enum | No | Notification channel: email, dashboard, slack, webhook (default: dashboard) |
| alertRecipients | array of string | No | Email addresses or Nextcloud user IDs to notify |
| snoozeUntil | datetime | No | Suppress alerts until this datetime |
| lifecycleState | enum | Yes | One of `active`, `paused`, `archived` |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → InventoryStock (many-to-one, via inventoryStockId)
- → Supplier (many-to-one, via supplierId)
- → Organization (many-to-one, via administrationId)

> **Annotation (inventory-reorder-automation, 2026-06-05):** All reorder decision logic is declarative. `x-openregister-lifecycle` governs the active/paused/archived state machine. `x-openregister-aggregations` evaluates `SUM(InventoryStock.quantity) ≤ reorderPoint` on every stock quantity change and powers the Stock Levels Dashboard by location. `x-openregister-notifications` dispatches low-stock alerts via the configured channel with Order Now / Snooze / Update Rule action links. No PHP `InventoryReorderService` — this is the canonical ADR-031 pattern for reorder automation.

> **Reconciliation note (inventory-multi-warehouse, 2026-06-05):** Original `InventoryStock` (primary spec: procurement-integration) used a free-text `location` string. This entry supersedes it with `locationId` FK to the hierarchical Location entity per REQ-LOC-009. Warehouse/zone-level stock is computed via `Location.stockRollup` aggregation (declarative, no separate warehouse table). The `quantity` field is now bin-only; calls for warehouse-level totals must use the `stockRollup` aggregation endpoint.

### InventoryLot
**Schema.org:** `schema:Product`
_Lot/batch identifier for a homogeneous quantity of a Product received together. Sits below InventoryStock — InventoryStock is the aggregate position per (sku, locationId); InventoryLot adds the per-lot granularity required for FEFO (First-Expiry-First-Out) picking, expiry tracking, quality quarantine, and recall traceability._
**Primary spec:** inventory-lot-batch-expiry

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Warehouse-assigned lot identifier (unique per administration) |
| batchCode | string | No | Supplier-assigned batch reference |
| productSku | string (FK) | Yes | FK to Product.sku — identifies the product in this lot |
| manufactureDate | date | No | Date of manufacture / production |
| expiryDate | date | No | Legal expiry date; drives FEFO sort (ASC NULLS LAST) |
| bestBeforeDate | date | No | Best-before (THT) quality date |
| quantity | number ≥ 0 | Yes | Current available (un-consumed) quantity in this lot |
| unitCode | string | No | UN/CEFACT unit of measure code (ZAK, BLIK, KG, ST, CA) |
| unitCost | number ≥ 0 | No | Cost per unit at time of receipt in EUR |
| warehouseLocation | string | No | Physical bin/rack location code |
| lotStatus | enum | Yes | One of `active`, `quarantined`, `expired`, `exhausted` |
| receivedDate | date | No | Date this lot was received |
| goodsReceiptId | string (FK) | No | FK to GoodsReceipt.id — receipt event that created the lot |
| administrationId | string (FK) | Yes | FK to Administration (adminScope enforced) |
| notes | string | No | Operator-authored free text |

**Relations:**
- → Product (many-to-one, via productSku FK → Product.sku)
- → GoodsReceipt (many-to-one, via goodsReceiptId)
- ← StockMove (one-to-many, reverse via StockMove.lotId — additive patch when stock-movement-ledger gains lot awareness)
- ← ExpiryAlert (one-to-many, reverse via ExpiryAlert.lotId)
- → Organization (many-to-one, via administrationId)

> **Annotation (inventory-lot-batch-expiry, 2026-06-07):** Lot lifecycle is fully declarative — `x-openregister-lifecycle` governs the four-state machine (active / quarantined / expired / exhausted) with guard validations (`expiryDateReached`, `quantityZero`, `expiryAfterManufacture`). FEFO order is declared via `x-openregister-sort` on `expiryDate ASC NULLS LAST`; the `OCA\Shillinq\Sort\FefoSort::sortLots` PHP guard is the ADR-031 exception path activated only when the directive is advisory at the API query layer (Risk 1 in proposal.md). Unique key (administrationId, lotNumber) keeps lot numbers tenant-scoped. RBAC: warehouse_manager / warehouse_operator / inventory / auditor.

### ExpiryAlert
**Schema.org:** `schema:Action`
_Notification record for lots approaching expiry, lots that have crossed expiry, and lots received without an expiry date for tracked products. Distinct register (not embedded on InventoryLot) so multiple thresholds per lot can coexist, acknowledgement is tracked independently of lot state, and the alert history remains queryable after a lot is exhausted or expired._
**Primary spec:** inventory-lot-batch-expiry

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotId | string (FK) | Yes | FK to InventoryLot.id — the lot triggering the alert |
| alertType | enum | Yes | One of `approaching_expiry`, `expired`, `missing_expiry_date` |
| daysBeforeExpiry | integer ≥ 0 | No | Days before expiry at which the alert was raised (e.g. 30, 7); null for expired/missing |
| alertDate | date | Yes | Date the alert was generated |
| status | enum | Yes | One of `pending`, `acknowledged`, `resolved` |
| resolvedDate | date | No | Date the alert was acknowledged or resolved |
| recipientId | string (FK) | No | FK to Person.id — the operator notified |
| administrationId | string (FK) | Yes | FK to Administration (adminScope enforced) |
| notes | string | No | Operator acknowledgement notes |

**Relations:**
- → InventoryLot (many-to-one, via lotId)
- → Person (many-to-one, via recipientId)
- → Organization (many-to-one, via administrationId)

> **Annotation (inventory-lot-batch-expiry, 2026-06-07):** Alert generation is the responsibility of `OCA\Shillinq\BackgroundJob\LotExpiryAlertJob`
(moved from `OCA\Shillinq\Cron\LotExpiryAlertJob` by background-job-consolidation,
ADR-069 D1) — a daily TimedJob that raises `approaching_expiry` alerts at 30-day and 7-day thresholds and `expired` alerts past `InventoryLot.expiryDate`. Idempotent via the uniqueness key (lotId, alertType, daysBeforeExpiry). Alert lifecycle is declarative — `pending → acknowledged → resolved` with the `resolve` transition stamping `resolvedDate`. Per ADR-032 the daily sweep is a `kind: code` companion to the `kind: config` schema in this same change because the declarative engine cannot express date-arithmetic thresholds across all lots.

> **Annotation (inventory-lot-batch-expiry, 2026-06-07):** The existing Product schema (the shillinq catalogue slug for the spec entity 'InventoryItem') gained one additive optional field via this change: `requiresLotTracking: boolean (default: false)`. When `true`, every receipt of that SKU MUST reference an `InventoryLot` — enforced by the `OCA\Shillinq\Lifecycle\LotTrackingReceiptGuard` on `GoodsReceipt` save (REQ-LOT-008). The patch is non-breaking; existing Product objects without the field default to `false` (lot tracking disabled).

### InventoryLot
**Schema.org:** `schema:Product`
_A discrete, homogeneous quantity of a product received together from one supplier on one date, carrying a single expiryDate. Foundation for FEFO picking and expiry alerting._
**Primary spec:** inventory-lot-batch-expiry

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot identifier (e.g. LOT-2026-001) |
| batchCode | string | No | Supplier-assigned batch or charge reference |
| productSku | string | Yes | FK to InventoryItem.sku — identifies the product |
| manufactureDate | date | No | Date of manufacture |
| expiryDate | date | No | Legal expiry date; drives FEFO sort (NULL-last) |
| bestBeforeDate | date | No | Best-before quality date; may precede expiryDate |
| quantity | number | Yes | Current available quantity in this lot (minimum: 0) |
| unitCode | string | No | UN/CEFACT unit of measure (ZAK, BLIK, KG, ST) |
| unitCost | number | No | Cost per unit in EUR at time of receipt |
| warehouseLocation | string | No | Physical bin/rack location code |
| lotStatus | enum | Yes | One of active, quarantined, expired, exhausted |
| receivedDate | date | No | Date this lot was received into the warehouse |
| goodsReceiptId | string | No | FK to GoodsReceipt.id — receipt event that created this lot |
| notes | string | No | Operator-authored free text |

**Lifecycle states:** `active` → `quarantined` ↔ `active`; `active`/`quarantined` → `expired` (guard: today > expiryDate); `active` → `exhausted` (guard: quantity == 0). `expired` and `exhausted` are terminal.

**FEFO sort:** `x-openregister-sort: [{field: expiryDate, direction: asc, nulls: last}]` — all GET /objects/shillinq/InventoryLot responses return lots in FEFO order by default.

**Relations:**
- → InventoryItem (many-to-one; via productSku → InventoryItem.sku)
- → GoodsReceipt (many-to-one; via goodsReceiptId, optional)
- ← StockMovement (one-to-many; declared on StockMovement.lotId in inventory-stock-movement-ledger)
- ← ExpiryAlert (one-to-many; via ExpiryAlert.lotId)

### ExpiryAlert
**Schema.org:** `schema:Thing`
_Tracks approaching-expiry and missing-expiry notifications for InventoryLot records. Separate entity so multiple alerts at different thresholds can exist per lot and alert history is queryable after lot exhaustion._
**Primary spec:** inventory-lot-batch-expiry

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotId | string | Yes | FK to InventoryLot.id — the lot triggering this alert |
| alertType | enum | Yes | One of approaching_expiry, expired, missing_expiry_date |
| daysBeforeExpiry | number | No | Days before expiry at which alert was generated |
| alertDate | date | Yes | Date the alert was generated |
| status | enum | Yes | One of pending, acknowledged, resolved |
| resolvedDate | date | No | Date the alert was acknowledged or resolved |
| recipientId | string | No | FK to Person.id — the operator notified |
| notes | string | No | Operator acknowledgement notes |

**Lifecycle states:** `pending` → `acknowledged` → `resolved`; or `pending` → `resolved` directly.

**Relations:**
- → InventoryLot (many-to-one; via lotId → InventoryLot.id, required)
- → Person (many-to-one; via recipientId → Person.id, optional)

### InventoryValuation
**Schema.org:** `schema:Product`
_Valuation of on-hand inventory items using cost accounting methods such as FIFO or average cost for P&L and balance sheet reporting_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| quantity | number | Yes | Quantity of items currently in stock |
| unitCost | number | Yes | Cost per unit under the selected valuation method |
| totalValue | number | Yes | Total inventory value (quantity × unitCost) |
| valuationMethod | string | Yes | Costing method: FIFO, average, specific, or weighted average |
| date | datetime | Yes | Date of valuation or inventory count |
| warehouse | string | No | Warehouse or storage location identifier |
| status | string | Yes | Status: active, adjusted, or obsolete |
| glTransactionId | string (FK) | No | Back-reference to the most recently materialised GLTransaction.id produced by the postCOGS / postReceipt / postVariance lifecycle action; closes the drill-down loop from the inventory snapshot to its GL impact (additive per inventory-cogs-posting, 2026-06-08, REQ-CG-005 D5) |
| postingEvent | enum | No | The stock-movement event that drove the most recent GL posting: saleDispatch, goodsReceipt, countVariance, returnDispatch (additive per inventory-cogs-posting, 2026-06-08, dispatch key for postCOGS / postReceipt / postVariance) |

**Relations:**
- → Product (many-to-one)
- → CostCenter (many-to-one)
- → GLTransaction (many-to-one, via glTransactionId → GLTransaction.id; back-reference for inventory-posted GL transactions per inventory-cogs-posting REQ-CG-005 D5)

> **Reconciliation note (inventory-cogs-posting, 2026-06-08):** Additively extended with `glTransactionId` + `postingEvent` so each InventoryValuation snapshot carries a drill-down link to its materialised GL impact (REQ-CG-005 D5) and a dispatch key (REQ-CG-002 / REQ-CG-003 / REQ-CG-004) routing the lifecycle to `postCOGS` (saleDispatch → Dr COGS / Cr Inventory Asset), `postReceipt` (goodsReceipt → Dr Inventory Asset / Cr GR/IR clearing) or `postVariance` (countVariance → Dr/Cr Inventory Adjustment ↔ Inventory Asset, direction resolved by `InventoryPostingGuard::direction`). Both fields are optional — pre-existing snapshots without a GL posting remain valid. The account routing is read from the per-administration `InventoryGLConfig` register (REQ-CG-001).

### InventoryGLConfig
**Schema.org:** `schema:Thing`
_Per-administration mapping from inventory stock-movement event types (sale dispatch, goods receipt, count variance) to General Ledger account numbers. One active record per administrationId; drives the declarative posting lifecycle on `InventoryValuation`. FK invariant: every account number MUST resolve to an `Account` record within the same administration; the `InventoryPostingGuard::accountExists` validation rule enforces this on save. When `isActive = false` or no config exists, the lifecycle action skips materialisation and emits a structured warning (no zero-cost / partial GL entry is ever written). Default seed (`seedInventoryGLConfig`): NL RGS 3.5 MKB (7000 / 1400 / 1800 / 7100)._
**Primary spec:** inventory-cogs-posting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string (FK) | Yes | FK to Administration owning this posting configuration |
| cogsAccountNumber | string (FK) | Yes | FK to Account.accountNumber — debited on sale/dispatch (Dr COGS); default RGS 7000 |
| inventoryAssetAccountNumber | string (FK) | Yes | FK to Account.accountNumber — debited on goods receipt / credited on COGS posting; default RGS 1400 |
| grIrClearingAccountNumber | string (FK) | Yes | FK to Account.accountNumber — credited on goods receipt and subsequently debited by AP invoice posting per REQ-AP-003 (GR/IR two-step pattern); default RGS 1800 |
| inventoryAdjustmentAccountNumber | string (FK) | Yes | FK to Account.accountNumber — debited on negative count variance / credited on positive count variance; default RGS 7100 |
| isActive | boolean | Yes | Whether perpetual inventory GL posting is enabled for this administration |
| description | string | No | Operator notes (e.g. "NL RGS 3.5 MKB defaults applied") |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Account (many-to-one, via cogsAccountNumber, inventoryAssetAccountNumber, grIrClearingAccountNumber, inventoryAdjustmentAccountNumber → Account.accountNumber; FK invariant per REQ-CG-001)

> **Annotation (inventory-cogs-posting, 2026-06-08):** New config register declared by the `inventory-cogs-posting` change. ADR-022 alignment: account routing is configuration data, not hardcoded logic; ADR-031 alignment: the lifecycle posting (`postCOGS` / `postReceipt` / `postVariance` on `InventoryValuation`) reads the active config record at action time. The single PHP guard `InventoryPostingGuard` (ADR-031 exception path) handles canPost / canPostVariance / direction (sign conditional for count variance) / accountExists (FK invariant) — the declarative DSL cannot express those four predicates inline today.

### InventoryAdjustment
**Schema.org:** `schema:UpdateAction`
_Stub. Inventory adjustment transaction linking an InventoryCycleCount to a posted StockMove for full audit traceability per REQ-ICC-007. In the inventory-cycle-count change the adjustment is **not** materialised as its own register row — the originating CycleCount lines + the resulting `cycle-count-variance` StockMove pair already carry the full provenance (line.adjustmentStockMoveId on the source side, StockMove.referenceDocumentUri='shillinq://cycle-count/<countId>' on the sink side). A future inventory-adjustment spec (T2 follow-up) will promote this to a top-level register when manual on-hand adjustments outside the cycle-count flow are required (e.g. supplier RMA, scrap, founder write-off)._
**Primary spec:** inventory-cycle-count (stub) → inventory-adjustment (deferred T2 follow-up)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| adjustmentId | string | Yes | Stable identifier of the adjustment (planned: ADJ-YYYY-NNNNN) |
| cycleCountId | string (FK) | No | Back-reference to InventoryCycleCount when the adjustment originated from a cycle count per REQ-ICC-007 |
| stockMoveId | string (FK) | Yes | FK to StockMove that materialised the on-hand + GL change (movementReason='cycle-count-variance' for cycle-count-origin) |
| reasonCode | string (FK) | Yes | FK to InventoryVarianceReason per REQ-ICC-005 |
| administrationId | string (FK) | Yes | FK to Administration; tenant scope per REQ-MA-001 |

**Hierarchy / invariants:**
- Every InventoryAdjustment MUST reference exactly one posted StockMove for the on-hand + GL effect (REQ-ICC-007).
- Cycle-count adjustments carry a `cycleCountId`; manual adjustments (future inventory-adjustment spec) omit it.
- Each (administrationId, cycleCountId) MAY produce 0..N InventoryAdjustment rows (one per non-zero-variance line).

**Relations:**
- → InventoryCycleCount (many-to-one, via cycleCountId — only for cycle-count-origin)
- → StockMove (one-to-one, via stockMoveId)
- → InventoryVarianceReason (many-to-one, via reasonCode)
- → Administration (many-to-one, via administrationId)

> **Annotation (inventory-cycle-count, 2026-06-07):** Stub. The cycle-count flow does **not** persist this entity yet — the (InventoryCycleCountLine.adjustmentStockMoveId, StockMove.referenceDocumentUri) pair already encodes the full provenance and the audit reader can re-derive every cycle-count adjustment from those two FKs. Promotion to a real register is deferred to the inventory-adjustment spec (T2 follow-up) which will also cover non-cycle-count manual adjustments.

### InventoryCycleCount
**Schema.org:** `schema:InventoryAction`
_Periodic stock-take batch per REQ-ICC-001 + REQ-ICC-002. Carries the count scope (full or partial by location/category), the lifecycle state (draft → submitted → counting → posted → reconciled, plus cancelled from any non-terminal state per REQ-ICC-006), the aggregated expected/counted/variance values (snapshotted from the line set on transition counting → posted) and the configurable variance thresholds (5%% qty / EUR 500 absolute by default per REQ-ICC-004)._
**Primary spec:** inventory-cycle-count

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| countId | string | Yes | Sequential CC-YYYY-MM-NNNNN; unique per administration per REQ-ICC-002 |
| countDate | date | Yes | Physical count date per REQ-ICC-002 |
| initiatedBy | string (FK) | Yes | FK to Person initiating the count per REQ-ICC-002 |
| countType | enum | Yes | `full` (all SKUs) / `partial` (scoped to locationFilter or categoryFilter) per REQ-ICC-002 |
| locationFilter | string (FK) | No | Optional FK to Location.id when countType=partial per REQ-ICC-008 |
| categoryFilter | string | No | Optional product-category when countType=partial per REQ-ICC-008 |
| expectedValue | number ≥ 0 (multipleOf 0.01) | No | SUM(line.expectedValue); null while draft per REQ-ICC-002 |
| countedValue | number ≥ 0 (multipleOf 0.01) | No | SUM(line.countedValue); null until all lines counted |
| varianceValue | number (multipleOf 0.01) | No | countedValue − expectedValue; signed |
| variancePercentage | number | No | (varianceValue / expectedValue) × 100; null when expectedValue=0 |
| state | enum | Yes | One of draft / submitted / counting / posted / reconciled / cancelled per REQ-ICC-006 |
| notes | string | No | Supervisor notes |
| submittedAt | datetime | No | Stamped on draft → submitted |
| postedAt | datetime | No | Stamped on counting → posted |
| reconciledAt | datetime | No | Stamped on posted → reconciled |
| cancelledAt | datetime | No | Stamped on any → cancelled |
| administrationId | string (FK) | Yes | FK to Administration; tenant scope per REQ-MA-001 |

**Hierarchy / invariants (per REQ-ICC-002 + REQ-ICC-006 + REQ-ICC-008):**
- Partial counts MUST carry at least one of locationFilter / categoryFilter (VarianceGate::requireValidScope on submit).
- The line snapshot is created on draft → submitted by CycleCountService::snapshotScope (one InventoryCycleCountLine per in-scope (sku, locationId) pair).
- Counting → posted is denied unless every line with `requiresReason=true` carries an active reasonCode (VarianceGate::requireReasonsOnPost).
- Posted → reconciled emits one StockMove per non-zero-variance line via CycleCountService::emitAdjustments (movementReason='cycle-count-variance', referenceDocumentUri='shillinq://cycle-count/<countId>'); the StockMove lifecycle handles InventoryStock + GL.
- Cancel is reachable from any non-terminal state; no StockMoves emitted; snapshot lines remain queryable for audit.

**Declarative extensions (ADR-031):**
- `x-openregister-lifecycle`: draft → submitted → counting → posted → reconciled (+ cancelled); submit/post/reconcile gated by VarianceGate.
- `x-openregister-metadata`: quantityVarianceThresholdPercent (default 5), valueVarianceThresholdAbsolute (default 500) per REQ-ICC-004.
- `x-openregister-indexes`: unique (administrationId, countId); (administrationId, state, countDate) for the index view.
- Guards (ADR-031 exception path): `VarianceGate::requireValidScope`, `VarianceGate::requireReasonsOnPost`; service: `CycleCountService::snapshotScope`, `CycleCountService::emitAdjustments`, `CycleCountService::recalculateLine`.

**Relations:**
- → Person (many-to-one, via initiatedBy)
- → Location (many-to-one, via locationFilter — only for partial counts)
- → InventoryCycleCountLine (one-to-many, via countId)
- → InventoryAdjustment (one-to-many, via stub cycleCountId — see InventoryAdjustment stub above)
- → Administration (many-to-one, via administrationId)

> **Annotation (inventory-cycle-count, 2026-06-07):** Lifecycle, line snapshot fan-out, variance threshold logic, and adjustment posting are all declarative per ADR-031 — no `CycleCountController` or domain Mapper was added. Two ADR-031 exception-path classes live in `lib/Lifecycle/VarianceGate.php` (scope + reason gates) and `lib/Service/CycleCountService.php` (snapshot fan-out + adjustment fan-out + recalculation). All arithmetic is integer-cent via `multipleOf 0.01` schema discipline; the post-time check recomputes `requiresReason` from raw counted/expected/unitCost rather than trusting a potentially stale stored flag.

### InventoryCycleCountLine
**Schema.org:** `schema:Quantity`
_One snapshot row inside an InventoryCycleCount per REQ-ICC-003. Captures the (sku, locationId) pair, the expectedQuantity snapshotted from InventoryStock at submission, the operator-entered countedQuantity, the derived variance fields, the requiresReason flag (computed against the parent count's variance thresholds per REQ-ICC-004), and the optional reasonCode FK (mandatory when requiresReason=true per REQ-ICC-005)._
**Primary spec:** inventory-cycle-count

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineId | string | Yes | Sequential CC-YYYY-MM-NNNNN-LLL; unique per administration per REQ-ICC-003 |
| countId | string (FK) | Yes | FK to InventoryCycleCount per REQ-ICC-003 |
| sku | string (FK) | Yes | FK to Product.sku per REQ-ICC-003 |
| productName | string | No | Denormalized product-name snapshot for audit traceability |
| locationId | string (FK) | Yes | Bin Location.id; required because the resulting adjustment StockMove targets this location |
| expectedQuantity | number ≥ 0 (multipleOf 0.01) | Yes | InventoryStock.quantity snapshot at submission per REQ-ICC-003 |
| countedQuantity | number ≥ 0 (multipleOf 0.01) | No | Operator-entered physical count; null while un-counted; negative rejected per REQ-ICC-003 |
| unitCost | number ≥ 0 (multipleOf 0.01) | Yes | Unit cost at count date; used for valueVariance + GL posting per REQ-ICC-003 |
| expectedValue | number ≥ 0 (multipleOf 0.01) | Yes | Calculated expectedQuantity × unitCost per REQ-ICC-003 |
| countedValue | number ≥ 0 (multipleOf 0.01) | No | Calculated countedQuantity × unitCost; null until counted |
| quantityVariance | number (multipleOf 0.01) | No | Calculated countedQuantity − expectedQuantity; signed |
| valueVariance | number (multipleOf 0.01) | No | Calculated countedValue − expectedValue; signed |
| requiresReason | boolean | No (default false) | Calculated flag per REQ-ICC-004: true when threshold crossed; drives VarianceGate on post |
| reasonCode | string (FK) | No | FK to InventoryVarianceReason.reasonId; MUST be populated for lines with requiresReason=true per REQ-ICC-005 |
| notes | string | No | Line-level investigation notes per REQ-ICC-003 |
| adjustmentStockMoveId | string (FK) | No | Back-reference to the StockMove emitted by CycleCountService::emitAdjustments on reconcile per REQ-ICC-007 |
| administrationId | string (FK) | Yes | FK to Administration; tenant scope per REQ-MA-001 |

**Hierarchy / invariants (per REQ-ICC-003 + REQ-ICC-004 + REQ-ICC-007):**
- Exactly one InventoryCycleCountLine per (countId, sku, locationId); enforced by the snapshot logic.
- countedQuantity may not be negative.
- requiresReason is recomputed by VarianceGate (raw counted/expected/unitCost vs. threshold) on every post — a stale stored flag never masks a flagged line.
- adjustmentStockMoveId remains null for zero-variance lines and for un-reconciled counts; once stamped, partial-retry idempotency in CycleCountService::emitAdjustments skips the line.

**Declarative extensions (ADR-031):**
- `x-openregister-calculations`: expectedValue, countedValue, quantityVariance, valueVariance, requiresReason expressions per REQ-ICC-003 + REQ-ICC-004.
- `x-openregister-indexes`: unique (administrationId, lineId); (administrationId, countId) for the count detail; (administrationId, countId, requiresReason) for the post-time gate.

**Relations:**
- → InventoryCycleCount (many-to-one, via countId)
- → Product (many-to-one, via sku)
- → Location (many-to-one, via locationId)
- → InventoryVarianceReason (many-to-one, via reasonCode)
- → StockMove (one-to-one, via adjustmentStockMoveId)
- → Administration (many-to-one, via administrationId)

### InventoryVarianceReason
**Schema.org:** `schema:DefinedTerm`
_Configurable reason-code taxonomy backing the InventoryCycleCountLine.reasonCode FK per REQ-ICC-005. Each administration maintains its own set; seven standard codes (DMG / OBS / ERR-COUNT / ERR-STOCK / THEFT / SYS / OTHER) are auto-seeded on first count. Inactive codes (isActive=false) stay queryable for audit but are hidden from the UI dropdown and rejected by VarianceGate so historical lines retain their FK while new lines cannot pick them. The category enum is closed so variance reports roll up consistently._
**Primary spec:** inventory-cycle-count

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reasonId | string | Yes | Reason code (e.g. DMG, OBS, ERR-COUNT); pattern `^[A-Z][A-Z0-9\-]{0,31}$` |
| name | string | Yes | Human-readable reason name |
| category | enum | Yes | Closed: damage / loss / obsolescence / error-counting / error-stocking / system-discrepancy / other |
| description | string | No | Extended explanation for audit trail |
| isActive | boolean | Yes (default true) | When false, hidden from UI + rejected by VarianceGate; historical FKs preserved |
| administrationId | string (FK) | Yes | FK to Administration; tenant scope per REQ-MA-001 |

**Hierarchy / invariants (per REQ-ICC-005):**
- Unique (administrationId, reasonId).
- VarianceGate::requireReasonsOnPost only accepts codes that are isActive=true for the count's administration.

**Declarative extensions (ADR-031):**
- `x-openregister-indexes`: unique (administrationId, reasonId); (administrationId, isActive) for the dropdown query.

**Relations:**
- → Administration (many-to-one, via administrationId)
- ← InventoryCycleCountLine (many-to-one inverse, via reasonCode)

### Investment
**Schema.org:** `schema:FinancialProduct`
_Investment or capital contribution in an entity with terms and expected returns_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amount | number | Yes | Investment amount in EUR |
| investmentDate | datetime | Yes | Date the investment was made |
| investmentType | string | Yes | Equity, debt, convertible, preferred, etc. |
| expectedReturn | number | No | Expected return percentage or amount |
| maturityDate | datetime | No | Expected maturity or exit date |
| terms | string | No | Investment terms and conditions |

**Relations:**
- → Entity (many-to-one)
- → Person (many-to-one)

### InnovatieboxElection
**Schema.org:** `schema:Event`
_Per-fiscal-year innovatiebox route election per Wet Vpb art. 12b/12bg. Records whether the forfaitair (art. 12bg: 25% of operating profit capped at EUR 25 000) or afpelmethode (art. 12b: explicit per-IP-asset profit attribution) route applies for a given administration. Exactly one election per (administrationId, fiscalYear). No PHP service — route selection and innovatiebox computation are fully declarative via x-openregister-calculations and x-openregister-aggregations._
**Primary spec:** bookkeeping-innovatiebox-administratie

> **Annotation (add-shillinq-innovatiebox-administratie, 2026-06-01):** Cross-references `IPAssetValuation` (afpelmethode assets) and `WinstToerekening` (per-period profit attribution) via the `innovatieboxAdministratie` aggregation. The applicable tariff defaults to 0.09 (9%) per Wet Vpb art. 12b 2026; statutory rate changes ship as a new `innovatiebox-tariefen-YYYY.json` seed file, not as a code change.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration owning this election |
| fiscalYear | integer | Yes | The fiscal year for which this route election applies |
| route | enum | Yes | One of forfaitair, afpelmethode |
| applicableTariff | number | Yes | Innovatiebox tariff for the fiscal year; default 0.09 per seed |
| forfaitairCapBedrag | number | Yes (route=forfaitair) | Statutory cap EUR 25 000 per Wet Vpb art. 12bg |
| forfaitairPercentage | number | Yes (route=forfaitair) | Default 0.25 (25%) per Wet Vpb art. 12bg |
| operatingProfit | number | No | Operating profit for forfaitair calculation; source: Vpb-balans |

**Relations:**
- → IPAssetValuation (one-to-many, via administrationId + fiscalYear; afpelmethode only)
- → WinstToerekening (indirectly via IPAssetValuation; afpelmethode only)

### IPAssetValuation
**Schema.org:** `schema:Intangible`
_Immaterieel activum eligible for the innovatiebox under the afpelmethode (Wet Vpb art. 12b). Declares the asset type (S&O-certificaat, octrooi, kwekersrecht, softwareprogrammatuur, model-tekening), capitalised valuation, and applicable tariff. Only populated when InnovatieboxElection.route = afpelmethode; forfaitair taxpayers do NOT register per-asset valuations._
**Primary spec:** bookkeeping-innovatiebox-administratie

> **Annotation (add-shillinq-innovatiebox-administratie, 2026-06-01):** FK to `WinstToerekening` (one-to-many, winsttoerekening entries) and to `VpbBalansLink` (vpbBalansLinkId). When assetType = s-en-o-certificaat the wbsoVerklaringNummer FK links to the WBSO S&O-verklaring in the wbso-sno-administratie capability.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetNaam | string | Yes | Human-readable name of the IP asset |
| assetType | enum | Yes | One of s-en-o-certificaat, octrooi, kwekersrecht, softwareprogrammatuur, model-tekening |
| wbsoVerklaringNummer | string | No | FK to WBSO S&O-verklaring (required when assetType = s-en-o-certificaat) |
| octrooiNummer | string | No | Patent registration number (required when assetType = octrooi) |
| valuationBedrag | number | Yes | Capitalised valuation in euros |
| valuationDate | date | Yes | Effective valuation date |
| applicableTariff | number | Yes | Innovatiebox tariff in effect at valuationDate; default 0.09 per seed |
| vpbBalansLinkId | string | Yes | FK to VpbBalansLink (REQ-VPB-002) |
| administrationId | string | Yes | FK to the Administration |
| fiscalYear | integer | Yes | Fiscal year this asset valuation applies to |

**Relations:**
- → WinstToerekening (one-to-many, via ipAssetId)
- → InnovatieboxElection (many-to-one, via administrationId + fiscalYear)

### QualifyingAsset / NexusCalculation / IBProfitAttribution / IBExpenseAllocation / CarryForwardLoss
**Primary spec:** bookkeeping-innovatiebox-administratie

> **Annotation (bookkeeping-innovatiebox-administratie, 2026-06-09):** Five Tier-4 schemas declared in `lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json` (ADR-037 fragment; never the monolith). `QualifyingAsset` is the IP-asset registry with `toegangsticket` validation (S&O / octrooi / combinatie routes per Wet Vpb art. 12ba, REQ-IBA-001) — the `schema:CreativeWork` annotation is on the schema itself. `NexusCalculation` is the immutable per-asset OECD BEPS Action 5 modified nexus per boekjaar (`x-openregister.immutable: true`, REQ-IBA-002). `IBProfitAttribution` is per-asset winsttoerekening with three statutory methods (afpelmethode / forfaitair_25pct / cost_plus, REQ-IBA-003) plus the `vso_locked` + `forfaitair_cap_applied` flags that gate the audit-trail listener. `IBExpenseAllocation` carries the doorsnijdingsverbod `exclusief_in_winstbepaling` flag for the year-end duplication check (REQ-IBA-004). `CarryForwardLoss` is the asset-specific immutable voortwenteling-verlies queue with `verrekend_boekjaar` entries (REQ-IBA-005). Cross-references: `InnovatieboxElection` (year-level route choice that drives which IB schemas the aggregation walks); `WBSO-uren-tagging` (S&O-route ticket validation source); `Vpb-corporate-tax.regel23` (grand-total target).

### InnovatieboxAuditEvent
**Primary spec:** bookkeeping-innovatiebox-administratie

> **Annotation (bookkeeping-innovatiebox-administratie, 2026-06-09):** Append-only audit-trail record per innovatiebox lifecycle transition (REQ-IBA-008, REQ-IBA-009). Declared with `x-openregister.immutable: true`. Captures one event per `NexusCalculation.calculated`, `IBProfitAttribution.created`/`finalized`/`amendment_attempt_blocked` (the last on a write that arrives under a VSO-locked year), `CarryForwardLoss.created`/`offset_applied`, `DoorsnijdingsVerbod.check_run` and `ForfaitairCap.applied`. Used by Belastingdienst VSO defence to reproduce the calculation chain end-to-end; the actor uid is stamped from the active session (never a BSN per ADR-005). Wired via the new `InnovatieboxAuditTrailListener` on OR's `ObjectCreatedEvent` + `ObjectUpdatedEvent`.

### Invoice
**Schema.org:** `schema:DigitalDocument`
_Financial document detailing goods/services provided and creating an obligation for payment_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier (Dutch: factuurnummer) |
| invoiceDate | datetime | Yes | Date the invoice was issued (Dutch law requires this) |
| dueDate | datetime | Yes | Payment deadline date |
| grossAmount | number | Yes | Total amount including VAT |
| vatAmount | number | Yes | Value Added Tax amount |
| netAmount | number | Yes | Amount excluding VAT (gross - vat) |
| vatRate | number | Yes | VAT percentage (e.g., 21, 9, 6, 0 for Dutch standard rates) |
| currency | string | Yes | ISO 4217 currency code (e.g., EUR) |
| creditor | object | Yes | Issuing company (supplier/seller) |
| recipient | object | Yes | Receiving company (customer/debtor) |
| lineItems | array | Yes | Invoice line items with description, quantity, unit price, amount |
| paymentTerms | string | Yes | Payment conditions (e.g., net 30 days, prepayment) |
| documentFormat | string | Yes | File format (e.g., PDF, XML, UBL) |
| paymentMethod | string | No | Payment method (e.g., SEPA transfer, bank transfer, direct debit) |
| reference | string | No | Purchase order number or reference number |
| attachments | array | No | Supporting documents or file references (PDF, receipt, etc.) |

**Relations:**
- → Obligation (one-to-one)
- → Payment (one-to-many)

### InvoiceLine
**Schema.org:** `schema:InvoiceItem`
_A line item detailing goods or services on an invoice. In Shillinq's ARInvoice this is the lines[] sub-document; bookkeeping-invoice-vat-kassakoppeling extends it with per-line Dutch VAT fields (REQ-VAT-001) so each line carries vatRate, vatAmount, and serviceCategory used by the REQ-VAT-002 issuance precondition and the REQ-VAT-003 GL VATPayable bucket posting._
**Primary spec:** accounts-payable-receivable; bookkeeping-invoice-vat-kassakoppeling (VAT extension)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | number | Yes | Sequential line number |
| description | string | Yes | Item description |
| quantity | number | Yes | Quantity of items |
| unitPrice | number | Yes | Price per unit |
| lineAmount | number | Yes | Total line amount before tax (net of VAT) |
| tax | number | No | Legacy tax-on-line field (free-form; retained for backward compatibility) |
| taxCode | string | No | Legacy free-form tax code (e.g. BTW21); superseded by structured vatRate for new VAT logic |
| vatRate | integer enum | Yes (T3) | Dutch VAT rate in percent (21, 9, 6, 0) per REQ-VAT-001; defaults to administration's standard rate |
| vatAmount | number | Yes (T3) | Per-line VAT in decimal euros, banker's-rounded to 2 decimals per REQ-VAT-007 |
| serviceCategory | enum | Yes (T3) | product / service / exempt — gates vatRate validity per REQ-VAT-002 |
| unit | string | No | Unit of measurement |

**Relations:**
- → Invoice (many-to-one)
- → Product (many-to-one)
- → VATAuditRecord (one-to-many; each issued line generates an audit record per REQ-VAT-004)
- → ServiceCategoryOverride (many-to-one optional; consulted by REQ-VAT-002bis when the default matrix would reject)

### Iv3Export
**Schema.org:** `schema:Dataset`
_Quarterly IV3 (Informatie voor Derden) export submitted to CBS by Dutch decentralised government administrations (gemeente, provincie, waterschap). Lifecycle covers generation, XML validation, CBS submission, and acceptance/rejection. Buckets aggregation is declarative via x-openregister-aggregations over GLLine joined with BbvAccountMapping._
**Primary spec:** bookkeeping-iv3-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration (gemeente/provincie/waterschap) |
| reportingYear | integer | Yes | Calendar year (e.g. 2026) |
| reportingQuarter | enum | Yes | One of Q1, Q2, Q3, Q4 |
| iv3Version | string | Yes | CBS IV3-bestand specification version (e.g. 2026.1) |
| buckets | object | Yes | Aggregated GL values keyed by IV3 bucket code (derived via x-openregister-aggregations) |
| xmlAttachmentUri | string | No | Docudesk URI of the generated CBS IV3 XML file |
| state | enum | Yes | One of generated, validated, submitted, accepted, rejected, corrected |
| generatedAt | datetime | No | Timestamp when the export was generated |
| submittedAt | datetime | No | Timestamp when the export was submitted to CBS |
| acceptedAt | datetime | No | Timestamp when CBS accepted the export |
| cbsMessageId | string | No | CBS-side message identifier returned on submission |
| correctionOf | string | No | FK to a prior Iv3Export.id superseded by this correction |

**Relations:**
- → Administration (many-to-one, via administrationId)
- self → Iv3Export (many-to-one, via correctionOf → id; correction chain)

**Lifecycle (x-openregister-lifecycle):**
- generated → validated (operator validates XML against CBS schema)
- validated → submitted (operator submits via OpenConnector cbs-iv3)
- submitted → accepted (CBS callback via cbs-iv3 source)
- submitted → rejected (CBS callback via cbs-iv3 source)
- rejected → validated (re-validate after operator corrects)
- accepted → corrected (file a new Iv3Export with correctionOf set)

**Submission:** OR ScheduledWorkflow (cron `0 0 1 */3 *`) via OpenConnector `cbs-iv3` source (ADR-019). No app-local HTTP client.

### IV3Report
**Schema.org:** `schema:Report`
_Quarterly IV3 (Informatie voor Derden) report for Dutch SMB and non-profit administrations. Represents a GL aggregation for a single calendar quarter, materialised into IV3ReportLine items and submitted to CBS via the cbs-gateway app. Distinct from Iv3Export (overheid/BBV flow): IV3Report is SMB/ZZP-focused and uses Account.iv3FieldCode mapping rather than BbvAccountMapping._
**Primary spec:** bookkeeping-iv3-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique IV3 report identifier, auto-assigned on creation |
| administrationId | string | Yes | FK to Administration; determines GL source for aggregation |
| fiscalYear | integer | Yes | Reporting year (e.g. 2026) |
| quarter | enum | Yes | One of Q1, Q2, Q3, Q4 |
| status | enum | Yes | One of draft, validated, submitted, filed |
| reportDate | datetime | No | Date and time the report was generated |
| submissionDate | datetime | No | Date and time submitted to CBS |
| filedDate | datetime | No | Date and time CBS confirmed filing |
| cbsReceiptNumber | string | No | Receipt number returned by CBS gateway on submission |
| notes | string | No | Operator comments or submission notes |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → IV3ReportLine (one-to-many, via reportId; materialised from quarterly GL aggregation)

**Lifecycle (x-openregister-lifecycle):**
- draft → validated (operator validates; precondition: all mandatory CBS fields K1000, K1100, K2000, K2100, K3000, K4000, K5000 have ≥1 mapped Account.iv3FieldCode)
- validated → submitted (operator submits; hook POSTs to cbs-gateway /api/iv3/submit; receipt recorded)
- submitted → filed (CBS gateway callback confirms receipt; filedDate recorded; terminal state)

**Aggregation (x-openregister-aggregations):**
- `quarterlyGlSum`: SUM(GLLine.amount) grouped by Account.iv3FieldCode, filtered to quarter boundaries, excluding GLLine.eliminationFlag = true; materialises IV3ReportLine items on creation.
- `mandatoryFieldCheck`: Verifies all mandatory CBS IV3 fields (K1000, K1100, K2000, K2100, K3000, K4000, K5000) are mapped in chart of accounts. Used as validate precondition.

### IV3ReportLine
**Schema.org:** `schema:MonetaryAmount`
_A single aggregated line item within an IV3Report, representing the sum of GL transactions for one CBS IV3 field code in a given quarter. Materialised declaratively from GL aggregation via x-openregister-aggregations; not manually entered._
**Primary spec:** bookkeeping-iv3-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to the parent IV3Report.id |
| iv3FieldCode | string | Yes | CBS IV3 field code (e.g. K1000, K2100) this line represents |
| accountNumber | string | Yes | RGS account code from chart of accounts aggregated into this field |
| debitAmount | number | No | Total aggregated debit amount from GL for this account/field in EUR |
| creditAmount | number | No | Total aggregated credit amount from GL for this account/field in EUR |
| netAmount | number | Yes | Net amount (creditAmount - debitAmount) in EUR; negative values valid |
| sequence | integer | Yes | Display order within the IV3 report |

**Relations:**
- → IV3Report (many-to-one, via reportId)
- → Account (many-to-one, via accountNumber → Account.accountNumber)

### JointVenture
**Schema.org:** `schema:Organization`
_Formal partnership or joint venture between multiple corporations with shared profits/losses. Enables joint venture management across the multi-entity structure._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official legal name of the joint venture |
| kvkNumber | string | No | Chamber of Commerce registration number if formally registered |
| vatID | string | No | VAT number if applicable |
| startDate | date | Yes | Date joint venture was formed |
| endDate | date | No | Date joint venture was dissolved |
| managingPartner | string | No | Lead partner responsible for operations |
| profitDistributionMethod | string | Yes | Distribution method: equal, proportional to investment, or custom |

**Relations:**
- → Corporation (many-to-many)

### JournalEntry
_A balanced transaction record affecting two or more GL accounts (debits equal credits)._
**Primary spec:** financial-reporting-accountability

> **Journal-entries T1 capability (add-shillinq-journal-entries, 2026-06-09).** The
> Tier-1 `bookkeeping-journal-entries` capability binds the human-author surface
> of the bookkeeping foundation to the `JournalEntry` schema declared in
> `lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json` (fragment file;
> not the monolithic `shillinq_register.json`). The Tier-1 shape supersets this
> legacy ADR-000 entry: `entryNumber` → `journalNumber`, the flat
> `debitAmount`/`creditAmount`/`accountCode` is replaced by a `lines[]` array of
> `{accountNumber, side, amount, description}` (each row materialises into a
> `GLLine` on post, REQ-JE-007), the stored `isBalanced` boolean is replaced by
> the ADR-031 declarative balance derivation on the materialised `GLTransaction`,
> and `vatAmount` is deferred to Tier 5 (per `add-shillinq-journal-entries` Out
> of Scope; the Tier-1 schema does not carry a `vatAmount` field). A
> `journalType` closed enum (`manual` / `recurring` / `reversing`), `cadence`
> object, `reversesOn` periodId, and `approvalState` enum are added. ADR-022 is
> cited inline (consume OR's approval-workflow + audit-trail-immutable + docudesk
> FK abstractions; no app-local approval table, no embedded source-document
> blob); ADR-031 is cited inline (declarative `x-openregister-lifecycle`
> `draft → pending → posted → voided` state machine + declarative balance
> derivation + OR `ScheduledWorkflow` primitive for recurring/reversing
> materialisation; no `RecurringJournalService` PHP class). The sibling
> implementation cycle did ship `OCA\Shillinq\Lifecycle\JournalEntryGuard::canPost`
> wired into the `post`/`postDirect`/`void` transitions' `requires` field as a
> constrained ADR-031 deviation (delegates the policy decision rather than
> reimplementing approval state). New lifecycle gates SHOULD wire
> `requires: {approval-workflow: {policy: "@self.amountPolicy"}}` declaratively
> once OR's approval-workflow extension exposes amount-threshold policy binding
> (proposal Risk 1).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryDate | datetime | Yes | Date of the journal entry |
| entryNumber | string | Yes | Unique sequential journal entry number |
| description | string | Yes | Transaction description |
| debitAmount | number | Yes | Debit amount in EUR |
| creditAmount | number | Yes | Credit amount in EUR |
| isBalanced | boolean | Yes | Whether debits equal credits |
| accountCode | string | Yes | General ledger account number |
| journalCode | string | Yes | Journal type (sales, bank, cash, general, etc.) |
| reference | string | No | External reference (invoice, check, or document number) |
| vatAmount | number | No | VAT/BTW amount (21% standard, 9% reduced, etc.) |
| departmentCode | string | No | Cost center or department code |
| memo | string | No | Additional notes or clarification |

**Relations:**
- → GeneralLedgerAccount (many-to-many)
- → FiscalYear (many-to-one)

### KorRegime
**Schema.org:** `schema:GovernmentPermit`
_KOR (Kleine Ondernemersregeling) opt-in/opt-out regime record per administrationId and calendar year. Tracks the 5-state lifecycle (outside → opted-in → threshold-warning → threshold-exceeded → opted-out), YTD revenue (declarative x-openregister-calculations over Invoice T2), threshold from KorThreshold seed, and generates a pending JournalEntry on threshold-exceeded → opted-out per REQ-KOR-006 safety constraint. Visible only to mkb/zzp administration types per REQ-KOR-001._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the administration owning this KOR regime record |
| state | enum | Yes | One of outside, opted-in, threshold-warning, threshold-exceeded, opted-out |
| currentCalendarYear | integer | Yes | Calendar year tracked by ytdRevenue |
| ytdRevenue | number | Yes | Derived via x-openregister-calculations from Invoice (T2) within currentCalendarYear |
| thresholdAmount | number | Yes | Active KOR omzetdrempel for the year; read from KorThreshold seed (default €20,000) |
| warningPercentage | integer | Yes | Warning fires at warningPercentage % of thresholdAmount; from seed (default 80) |
| optedInAt | date | No | Date of formal opt-in (Belastingdienst-reported) |
| optedOutAt | date | No | Date of opt-out or auto-switch |
| exceededAt | date | No | Date when ytdRevenue first crossed the full threshold |
| notes | string | No | Operator-authored context (e.g. 'Opt-out due to ICP-omzet uitsluiting') |

**Relations:**
- → KorThreshold (many-to-one, via fiscalYear → KorThreshold.fiscalYear)
- → JournalEntry (one-to-many, created on threshold-exceeded → opted-out; state: pending per REQ-KOR-006)
- → Invoice (one-to-many, via x-openregister-calculations ytdRevenue aggregation)

**Lifecycle (x-openregister-lifecycle):**
- outside → opted-in (operator action; sets optedInAt)
- opted-in → threshold-warning (auto / calculation-crossing: ytdRevenue ≥ warningPercentage% of thresholdAmount)
- threshold-warning → threshold-exceeded (auto / calculation-crossing: ytdRevenue ≥ thresholdAmount; sets exceededAt)
- threshold-warning → opted-in (year-rollover when ytdRevenue resets; calculation-crossing guard)
- threshold-exceeded → opted-out (operator action; generates pending JournalEntry via hook; sets optedOutAt)
- opted-in → opted-out (operator voluntary opt-out; sets optedOutAt)
- opted-out → outside (operator, after 3-year lock-out per Wet OB 1968 art. 25 lid 3; KorLockoutGuard)

**Retention:** 7 years per AWR art. 52 (selectielijst:5.1.2).

### KorThreshold
**Schema.org:** `schema:DefinedTerm`
_Versioned statutory KOR threshold record per Wet OB 1968 art. 25 lid 1. Seeded from kor-thresholds-2026.json via the repair step. Multiple records with non-overlapping effectiveFrom/effectiveTo windows support future statutory revisions without code changes per REQ-KOR-003. The pre-2020 sliding-scale regime is not modelled; only the post-2020 fixed-ceiling form is tracked._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| thresholdAmount | number | Yes | Statutory KOR omzetdrempel in EUR (currently €20,000) |
| warningPercentage | integer | Yes | Percentage of thresholdAmount at which threshold-warning fires (default 80) |
| fiscalYear | integer | Yes | Fiscal year this threshold applies to |
| citation | string | Yes | Statutory citation (e.g. Wet OB 1968 art. 25 lid 1) |
| effectiveFrom | date | Yes | Date from which this threshold record is effective |
| effectiveTo | date | No | Date after which this threshold is superseded; null = currently in force |

**Relations:**
- → KorRegime (one-to-many, via fiscalYear)

### KORRegistration
**Schema.org:** `schema:DefinedTerm`
_Tier-2 KOR (Kleine Ondernemersregeling) formal registration per onderneming per regime (NL-KOR / KOR-EU). Carries the aanmeldgegevens (REQ-KOR-001), the drie-jaars lock-in window (REQ-KOR-007), the Belastingdienst-referentie, and the status lifecycle that gates voorbelasting-aftrek (REQ-KOR-006), factuurvermelding (REQ-KOR-005) and de revocatie-flow (REQ-KOR-004). The pre-existing `KorRegime` (YTD-revenue tracker) co-exists side-by-side; KORRegistration formalises the full T2 capability per Wet OB 1968 art. 25 / 25a-25d._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ondernemingId | string | Yes | FK to the onderneming (Corporation/Entity); KOR is per KvK-nummer |
| regime | enum | Yes | KOR_NL or KOR_EU; immutable after create (design D1) |
| status | enum | Yes | draft, ACTIEF, GEEINDIGD_OVERSCHRIJDING, GEEINDIGD_VRIJWILLIG |
| aanmeldDatum | date | No | Date of aanmelding at mijnbelastingdienst.nl/zakelijk |
| ingangsDatum | date | Yes | Effective start date; typically 1-1 of next calendar year |
| lockInEindDatum | date | No | Last day of third calendar year after ingangsDatum (REQ-KOR-007) |
| vroegsteOpzegDatum | date | No | Earliest opt-out window date; 3 months before lockInEindDatum |
| belastingdienstReferentie | string | No | Aanvraag-kenmerk from the Belastingdienst |
| aanmeldKanaal | enum | No | MIJN_BELASTINGDIENST_ZAKELIJK / MIJN_BELASTINGDIENST_KORUS |
| drempelJaar | number | No | Year threshold in EUR (default 20000 for NL-KOR) |
| voorgaandeOmzet | object | No | Year-indexed historical omzet for scenario-analysis |
| omzettingsRegeling | boolean | No | Legacy KOR (pre-2020 sliding-scale) indicator |
| fiscalEenheidId | string | No | FK if part of a fiscale eenheid (else null) |
| administrationId | string | Yes | FK to Administration; reads scoped per administration |

**Relations:**
- → Corporation (many-to-one, via ondernemingId)
- → Administration (many-to-one)
- → KORAnnualTurnover (one-to-many, via registrationId)
- → KORThresholdAlert (one-to-many)
- → KORRevocation (one-to-many; typically zero or one)
- → KOREUTurnover (one-to-many, when regime=KOR_EU)

**Lifecycle (x-openregister-lifecycle):**
- draft → ACTIEF (at ingangsDatum; publishes `kor.registration.activated`)
- ACTIEF → GEEINDIGD_OVERSCHRIJDING (synchronous on drempel >100%; publishes `kor.registration.revoked`)
- ACTIEF → GEEINDIGD_VRIJWILLIG (after opt-out window; publishes `kor.registration.revoked`)

**Retention:** 7 years per AWR art. 52 (selectielijst:5.1.2).

### KORAnnualTurnover
**Schema.org:** `schema:MonetaryAmount`
_Running KOR-eligible omzet per calendar year with drempel-benutting and monthly prognose (REQ-KOR-002). KorMonitorService computes this on demand from KOR-eligible AR invoices (vrijstellingsGrondslag = KOR_ART25_OB); the declarative `x-openregister-aggregations.korTurnoverByYear` block on the schema documents the equivalent declarative shape per ADR-031. Vrijgestelde / intracommunautaire / onroerend-goed posten are excluded._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| registrationId | string | Yes | FK to KORRegistration |
| jaar | integer | Yes | Calendar year |
| lopendeOmzet | number | Yes | Year-to-date KOR-eligible omzet in EUR |
| drempel | number | Yes | Annual threshold in EUR (default 20000) |
| drempelBenutting | number | Yes | Fraction lopendeOmzet/drempel (0..1+) |
| perMaand | object | No | YYYY-MM-indexed omzet per month |
| uitgeslotenPosten | array | No | Array of {type, bedrag, grondslag} explicitly excluded items |
| prognoseEindeJaar | number | No | Linear-trend end-of-year omzet projection |
| prognoseStatus | enum | No | ONDER_DREMPEL, WAARSCHUWING, OVERSCHRIJDING_VERWACHT |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → KORRegistration (many-to-one)
- → Administration (many-to-one)

**Retention:** 7 years per AWR art. 52.

### KORThresholdAlert
**Schema.org:** `schema:Action`
_Waarschuwingsevent zodra de drempel-benutting een schijf passeert (REQ-KOR-003): 80% VROEG, 90% KRITIEK, 100% OVERSCHRIJDING. Each schijf fires exactly once. Logs omzet-at-moment, ernst, kanaal and aanbeveling; publishes to `notifications.dispatch` per the schema's declarative kanaal field._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| registrationId | string | Yes | FK to KORRegistration |
| trigger | enum | Yes | DREMPEL_80PCT, DREMPEL_90PCT, DREMPEL_100PCT |
| uitgeloostOp | datetime | Yes | Dispatch timestamp |
| omzetOpMoment | number | Yes | YTD omzet at moment of dispatch (EUR) |
| drempelBenutting | number | Yes | Benutting at moment of dispatch (0..1+) |
| prognoseEindeJaar | number | No | Projected end-of-year omzet |
| ernst | enum | Yes | VROEG, KRITIEK, OVERSCHRIJDING |
| aanbeveling | text | No | Operator-facing recommendation |
| kanaal | array | Yes | Subset of EMAIL, IN_APP, DASHBOARD |
| bevestigdDoor | string | No | FK to User who acknowledged the alert |
| actieOndernomen | text | No | Action taken by the operator |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → KORRegistration (many-to-one)
- → Administration (many-to-one)

**Retention:** 7 years per AWR art. 52.

### KORRevocation
**Schema.org:** `schema:Action`
_Beëindiging van de KOR (REQ-KOR-004 / REQ-KOR-007): gedwongen bij overschrijding of vrijwillig na de lock-in. Bij overschrijding is `revocatieDatum` ALTIJD de leveringsdatum van de triggerfactuur — niet einde-maand/kwartaal/jaar (design D4). Bevat het berekende suppletie-bedrag (bedrag · 0.21 / 1.21 over alle KOR-facturen tussen ingangsDatum en revocatieDatum) en de drie-jaars heraanmeld-blokkade._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| registrationId | string | Yes | FK to KORRegistration |
| type | enum | Yes | OVERSCHRIJDING, VRIJWILLIG_NA_LOCKOUT |
| revocatieDatum | date | Yes | Leveringsdatum of triggerfactuur (NOT year-end) |
| triggerFactuurId | string | No | FK to ARInvoice (the overschrijdingsfactuur) |
| omzetOpMoment | number | No | YTD omzet at revocatie moment |
| btwSuppletieBedrag | number | No | Computed: Σ bedrag · 0.21 / 1.21 over KOR-facturen |
| herrekeningRange | object | No | {van, tot} date range for de hermarkering |
| nieuwRegime | enum | No | REGULIER_BTW (default) |
| blokkadeHeraanmelding | date | No | revocatieDatum + 3 years |
| belastingdienstNotificatie | object | No | {verzonden, verzondenOp, bevestigingsnummer} |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → KORRegistration (many-to-one)
- → ARInvoice (many-to-one, via triggerFactuurId)
- → Administration (many-to-one)

**Retention:** 7 years per AWR art. 52.

### KOREUTurnover
**Schema.org:** `schema:MonetaryAmount`
_Cross-border KOR-EU omzetregistratie (alleen bij regime KOR_EU) per 1-1-2025 (REQ-KOR-008): EX-nummer, EU-brede EUR 100.000-drempel, en omzet/drempel/benutting per lidstaat. Per-lidstaat drempels zijn DATA (design D7). Carries quarterly opgaaf-status (Q1-Q4) for de art. 284 VAT-richtlijn 2006/112/EG-aangifte._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| registrationId | string | Yes | FK to KORRegistration where regime=KOR_EU |
| exNummer | string | No | EX-nummer (e.g. EX-NL-2026-019234) from Belastingdienst |
| jaar | integer | Yes | Calendar year |
| totaalEUOmzet | number | Yes | Total cross-border KOR-EU omzet |
| drempelEUBrut | number | Yes | EU-wide ceiling (default 100000) |
| perLidstaat | object | No | Per-country keys: {omzet, drempel, benutting} |
| kwartaalopgaafStatus | object | No | Q1..Q4 keys with enum OPEN/DRAFT/INGEDIEND |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → KORRegistration (many-to-one)
- → Administration (many-to-one)

**Retention:** 10 years per VAT Directive 2006/112/EG art. 244 (cross-border surplus).

### LiquidityForecast
**Schema.org:** `schema:Report`
_Daily/weekly/monthly cash flow projections for liquidity planning, including inflow/outflow/net position_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| period | string | Yes | Daily, Weekly, or Monthly |
| forecastDate | string | Yes | ISO 8601 generation date |
| projectionDays | integer | Yes | Days ahead to forecast |
| projectedInflow | number | Yes | Expected cash in |
| projectedOutflow | number | Yes | Expected cash out |
| netProjection | number | Yes | Inflow minus outflow |
| currency | string | Yes | ISO 4217 code |
| confidence | string | No | Low, Medium, High |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### Location
**Schema.org:** `schema:Place`
_Physical or virtual storage location in the warehouse hierarchy (warehouse → zone → bin → in-transit). Extended from simple multi-site budget location to full hierarchical warehouse management per inventory-multi-warehouse spec._
**Primary spec:** inventory-multi-warehouse
**Co-declaring spec:** budget-planning-control (original simple Location shape)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Human-readable location name |
| locationCode | string | Yes | Unique code per administration (immutable after creation per REQ-LOC-002) |
| locationType | enum | Yes | warehouse, zone, bin, in-transit |
| parentLocationId | string | No | FK to parent Location (null for warehouse + in-transit; required for zone + bin) |
| address | string | No | Physical address of warehouse |
| region | string | No | Geographic region for rollup reporting |
| binNamingConvention | enum | No | bin, slot, compartment, location (admin-configurable per Task 6) |
| capacity | number | No | Maximum storage capacity in capacityUnit units |
| capacityUnit | string | No | Unit for capacity (pallets, items, kg, etc.) |
| status | enum | No | active, inactive, archived |
| administrationId | string | Yes | FK to Administration — all queries scoped per org (REQ-LOC-008) |
| notes | string | No | Operator notes |

**Hierarchy rules (per REQ-LOC-001 through REQ-LOC-004):**
- `warehouse` type: no parent, may have zone children.
- `zone` type: requires parent (warehouse), may have bin children.
- `bin` type: requires parent (zone), leaf node — InventoryStock recorded at this level.
- `in-transit` type: no parent, virtual holding during transfers (D2).
- Max hierarchy depth: 4 levels (warehouse → zone → aisle → bin) per REQ-LOC-003.
- Circular references blocked by `LocationHierarchyGuard.validateNoCircle` per REQ-LOC-018.

**Declarative extensions (ADR-031):**
- `x-openregister-lifecycle`: status (active/inactive/archived), code-immutability validation on update.
- `x-openregister-aggregations`: stockRollup (SUM InventoryStock.quantity for all descendant bins), childCount, descendantCount, inTransitStock.
- `x-openregister-calculations`: hierarchyPath (full slash-separated code path), hierarchyDepthValue (0-3), stockAvailabilityBadge.
- Guards: `LocationHierarchyGuard` for recursive operations (depth validation, circle detection, path building) per ADR-031 exception-path seam.

**Relations:**
- ↔ Location (self-referential parent-child for hierarchy, one-to-many)
- → InventoryStock (one-to-many, bin-level stock; warehouse/zone rollup via aggregation)
- → InventoryStockTransfer (one-to-many as sourceLocationId or destinationLocationId)
- → Organization (many-to-one)
- → Budget (one-to-many, from co-declaring budget-planning-control spec)

> **Reconciliation note (inventory-multi-warehouse, 2026-06-05):** The original `Location` entry (primary spec: budget-planning-control) described a simple location with `name/code/address/region` for multi-site budget tracking. This entry supersedes it for the shillinq inventory tier with the full hierarchical warehouse-management shape per REQ-LOC-001 through REQ-LOC-009: `locationCode/locationType/parentLocationId` for hierarchy, `status` lifecycle, `administrationId` tenant scope, and declarative aggregations for stock rollup. The `budget-planning-control` shape is retained as a co-declaring spec relationship — the budget FK to Location remains valid. Key change: `code` (budget-planning-control) renamed to `locationCode` in the inventory shape; implementors should map between them when combining tiers. No PHP service class added — location hierarchy is fully declarative per ADR-031.

### Lot
**Schema.org:** `schema:Product`
_Grouping of items in procurement process for evaluation and award at lot level_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot identifier |
| description | string | Yes | Description of lot contents and requirements |
| status | string | Yes | Status: draft, published, awarded, closed |
| estimatedValue | number | No | Estimated contract value in currency units |

**Relations:**
- → BidEvaluation (one-to-many)
- → AwardDecision (one-to-one)

### ManagementLetter
**Schema.org:** `schema:DigitalDocument`
_Auditor communication documenting findings and observations from annual audits. Two related declarations exist: the original compliance-audit entity (fields below) and the SiSa-specific register in bookkeeping-sisa-reporting (letterNumber, sisaReportId, findingsSummary, observationsSummary, remediationRecommendations, status). A T2/T4 consolidation change will reconcile or disambiguate these._
**Primary spec:** compliance-audit
**Co-declaring spec:** bookkeeping-sisa-reporting (SiSa-specific fields)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditDate | date | Yes | Date of the audit (compliance-audit) |
| auditScope | string | Yes | Scope of audit e.g. annual financial statements 2025 (compliance-audit) |
| auditorName | string | Yes | Auditing firm or auditor name |
| findings | text | No | Summary of audit findings (compliance-audit) |
| letterNumber | string | Yes | Unique management letter identifier (bookkeeping-sisa-reporting) |
| sisaReportId | string | Yes | FK to SisaReport (bookkeeping-sisa-reporting) |
| issuedDate | date | Yes | Date letter was issued (bookkeeping-sisa-reporting) |
| dueResponseDate | date | No | Management response deadline (bookkeeping-sisa-reporting) |
| findingsSummary | text | No | Summary of findings (bookkeeping-sisa-reporting) |
| observationsSummary | text | No | Summary of observations (bookkeeping-sisa-reporting) |
| remediationRecommendations | text | No | Recommended corrective actions (bookkeeping-sisa-reporting) |
| auditOpinion | string | No | Auditor's opinion pre-computed from SisaReport (bookkeeping-sisa-reporting) |
| status | enum | Yes | One of: draft, issued, acknowledged, archived (bookkeeping-sisa-reporting) |

**Relations:**
- → Organization (many-to-one)
- → AuditFinding (one-to-many)
- → SisaReport (many-to-one, via sisaReportId)

### Mandate
**Schema.org:** `schema:DigitalDocument`
_Electronic authorization granting a person or organization the right to perform financial transactions on behalf of another_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| mandateNumber | string | Yes | Unique identifier for the mandate |
| mandateType | string | Yes | Type of mandate: SEPA Direct Debit, domestic transfer, signing authority, etc. |
| granteeId | string | Yes | ID of person/organization receiving authority |
| grantorId | string | Yes | ID of person/organization granting authority |
| validFrom | date | Yes | Effective date of mandate |
| validThrough | date | No | Expiration date of mandate |
| maximumAmount | decimal | No | Maximum transaction amount in base currency |
| currency | string | Yes | ISO 4217 currency code |
| scheme | string | Yes | Reference to MandateScheme |
| documentHash | string | No | Hash of supporting document for audit trail |

**Relations:**
- → MandateScheme (many-to-one)
- → MandateRequest (one-to-many)

### MandateAuditLog
**Schema.org:** `schema:Event`
_Audit log tracking all changes, delegations, approvals, and usage of a mandate for compliance and historical review_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| logEntryNumber | string | Yes | Unique log entry identifier |
| action | string | Yes | Action performed (created/modified/delegated/approved/revoked/archived/violated) |
| actionDate | datetime | Yes | Timestamp of the action |
| description | string | Yes | Human-readable description of the action |
| details | object | No | Additional metadata about the action |

**Relations:**
- → Mandate (many-to-one)
- → Person (many-to-one)

### MandateRequest
**Schema.org:** `schema:Order`
_Request to create, modify, or temporarily increase a mandate authorization_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique request identifier |
| requestType | string | Yes | Type: new-mandate, increase, modify, revoke |
| relatedMandateId | string | No | Reference to existing Mandate if modifying |
| requestedAmount | decimal | No | Requested or new limit amount |
| currency | string | No | ISO 4217 currency code |
| requestedDuration | integer | No | Duration in days for temporary increases |
| reason | string | No | Business justification for request |
| submittedDate | date | Yes | Date request was submitted |
| requestStatus | string | Yes | Status: pending, approved, rejected, expired |

**Relations:**
- → Mandate (many-to-one)

### MandateScheme
**Schema.org:** `schema:Product`
_Classification and regulatory framework for different mandate types (SEPA, domestic, international)_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| schemeName | string | Yes | Name of mandate scheme: SEPA-DD, iDEAL, Domestic Transfer, etc. |
| schemeCode | string | Yes | Standardized code for the scheme |
| description | string | No | Purpose and use cases for this scheme |
| regulatoryFramework | string | No | Applicable regulation: PSD2, SEPA, national law |
| applicableCountries | string | No | Comma-separated ISO country codes |
| requiresManualApproval | boolean | Yes | Whether mandates under this scheme need approval |
| maxValidityPeriod | integer | No | Maximum validity duration in days |

### MandateViolation
**Schema.org:** `schema:Event`
_Record of a violation or breach of mandate rules, procedures, or authority limits_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| violationNumber | string | Yes | Unique violation identifier |
| violationType | string | Yes | Type of violation (exceededThreshold, unauthorizedApprover, expiredMandate, revokedAuthority) |
| description | string | Yes | Detailed description of the violation |
| severity | string | Yes | Severity level (critical/high/medium/low) |
| detectedDate | datetime | Yes | Date when violation was detected |
| status | string | Yes | Status of violation (reported/reviewed/resolved) |
| resolvedDate | datetime | No | Date when violation was resolved |
| resolution | string | No | Description of how the violation was resolved |

**Relations:**
- → Mandate (many-to-one)
- → Person (many-to-one)
- → AuditFinding (many-to-one)

### MarketplaceApp
**Schema.org:** `schema:SoftwareApplication`
_Individual application, plugin, or extension listed on marketplace with installation and rating capabilities_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| appId | string | Yes | Unique app identifier |
| name | string | Yes | Application name |
| version | string | Yes | Current application version |
| description | string | Yes | Application description and features |
| category | string | Yes | Category: billing, communication, integration, etc |
| status | string | Yes | Availability status |
| installationUrl | string | No | URL for app installation or documentation |
| ratingScore | number | No | Average user rating 0-5 |
| downloadCount | number | No | Total installations or downloads |

**Relations:**
- → MarketplaceIntegration (many-to-one)
- → Organization (many-to-one)
- → Person (many-to-one)

### MarketplaceIntegration
**Schema.org:** `schema:Service`
_Integration with external marketplaces providing unified catalog access and search across suppliers, apps, and platforms_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| integrationId | string | Yes | Unique integration identifier |
| name | string | Yes | Marketplace platform name |
| type | string | Yes | Integration type: supplier, app, extension, or external |
| url | string | Yes | Marketplace API or access URL |
| status | string | Yes | Active status |
| apiKey | string | No | Encrypted API authentication credential |
| lastSyncDate | datetime | No | Last successful catalog synchronization |
| catalogItemCount | number | No | Count of items in synchronized catalog |

**Relations:**
- → Organization (many-to-one)
- → MarketplaceApp (one-to-many)
- → Offer (one-to-many)

### MaverickSpendAlert
**Schema.org:** `schema:Event`
_Alert for unauthorized, off-contract, or non-compliant departmental spending requiring escalation_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| alertDate | date | Yes | Date alert was triggered |
| departmentName | string | Yes | Department responsible for spend |
| vendorName | string | Yes | Vendor/supplier involved |
| spendAmount | MonetaryAmount | Yes | Amount of unauthorized spend |
| severity | enum | Yes | low, medium, or high |
| alertReason | string | Yes | Why flagged (no PO, off-contract, policy violation, etc.) |
| budgetCode | string | No | Associated budget/cost center code |
| resolvedDate | date | No | Date alert was resolved/remediated |
| resolutionNotes | string | No | How violation was addressed |
| departmentAcknowledged | boolean | No | Department confirmed receipt of alert |

**Relations:**
- → ProcurementComplianceReport (many-to-one)

### MonetaryAmount
**Schema.org:** `schema:MonetaryAmount`
_Schema.org MonetaryAmount — standard vocabulary for monetaryamount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | number | Yes | Numeric value |
| currency | string | Yes | ISO 4217 currency code |

### OAuthIntegration
**Schema.org:** `schema:Thing`
_OAuth 2.0 authentication configuration enabling secure partner integrations and platform access_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| integrationId | string | Yes | Unique OAuth integration identifier |
| name | string | Yes | Integration display name |
| clientId | string | Yes | OAuth client identifier |
| status | string | Yes | Active status |
| scope | string | Yes | OAuth scopes (space-separated) |
| redirectUri | string | Yes | Authorization callback URL |
| createdDate | datetime | Yes | Integration creation date |
| lastUsedDate | datetime | No | Last authentication attempt |
| expiresAt | datetime | No | Token or credential expiration date |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)

### Obligation
**Schema.org:** `schema:Order`
_A financial commitment that must be fulfilled by a specific due date, with tracking for AI task automation and compliance reporting_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| obligationNumber | string | Yes | Unique reference number for the obligation |
| obligationDate | date | Yes | Date the obligation was created |
| dueDate | date | Yes | Date by which the obligation must be settled |
| amount | MonetaryAmount | Yes | Financial amount owed |
| creditor | Organization | Yes | Organization to whom the obligation is owed |
| obligationType | string | No | Type of obligation (invoice, contract, standing order) |
| description | string | No | Details or reason for the obligation |
| settledOnTime | boolean | No | Whether obligation was settled by due date |

**Relations:**
- → Invoice (many-to-one)
- → Payment (one-to-many)
- → SettlementDecision (many-to-one)

### ObligationSettlement
**Schema.org:** `schema:Thing`
_A formal decision record to settle and finalize an obligation, including verification of completion and approval of final amounts_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| settlementNumber | string | Yes | Unique identifier for the settlement decision |
| settlementDate | datetime | Yes | Date when the settlement was finalized |
| settledAmount | number | Yes | Final amount settled |
| status | string | Yes | Current status: draft, approved, finalized |
| settlementType | string | No | Type of settlement: full, partial, amended |
| notes | string | No | Additional notes or remarks about the settlement |

**Relations:**
- → Obligation (many-to-one)
- → ApprovalRequest (many-to-one)

### ObligationTask
**Schema.org:** `schema:Task`
_An automated task for managing obligation lifecycle, including AI-generated deadline tracking and compliance monitoring_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskNumber | string | Yes | Unique identifier for the task |
| title | string | Yes | Title of the task |
| description | string | No | Detailed description of the task |
| dueDate | datetime | Yes | Calculated or assigned due date with deadline tracking |
| priority | string | No | Priority level: low, medium, high |
| status | string | Yes | Current status: open, in-progress, completed |
| aiGenerated | boolean | No | Indicates if the task was automatically generated by AI |

**Relations:**
- → Obligation (many-to-one)
- → Person (many-to-one)

### Offer
**Schema.org:** `schema:Offer`
_Schema.org Offer — standard vocabulary for offer data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Offer/quote name |
| price | number | Yes | Offered price |
| priceCurrency | string | Yes | Currency |
| validFrom | datetime | No | Offer valid from |
| validThrough | datetime | No | Offer valid until |
| availability | string | No | Availability status |

### Order
**Schema.org:** `schema:Order`
_Schema.org Order — standard vocabulary for order data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | datetime | Yes | Date of order |
| orderStatus | string | Yes | Order status |
| totalPrice | number | Yes | Total order amount |
| currency | string | Yes | ISO 4217 currency code |
| deliveryDate | datetime | No | Expected delivery date |
| paymentTerms | string | No | Payment terms (e.g., NET30) |

### Organization
**Schema.org:** `schema:Organization`
_Schema.org Organization — standard vocabulary for organization data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal name of the organization |
| tradeName | string | No | Trade/brand name |
| kvkNumber | string | No | Dutch Chamber of Commerce number |
| vatID | string | No | VAT identification number |
| email | string | No | Primary email address |
| telephone | string | No | Primary phone number |
| url | string | No | Website URL |
| iban | string | No | IBAN bank account number |

### Payee
**Schema.org:** `schema:Organization`
_Vendor / supplier party record for accounts payable. Holds vendor contact details, payment terms, bank IBAN, dunning policy reference, and default expense account. Symmetric to `CustomerMaster` on the AR side. Posting an APTransaction (issued transition) consults `paymentTermDays` for the due-date default and `dunningPolicyRef` for the OR dunning-workflow cadence in the overdue state._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorNumber | string | Yes | Stable vendor identifier unique per administration |
| name | string | Yes | Legal name of the vendor |
| tradingName | string | No | Alternate or DBA trading name |
| kvkNumber | string | No | Dutch KvK number (8 digits) |
| btwNumber | string | No | Dutch BTW / EU VAT number |
| paymentTermDays | integer | Yes | Default payment term in days (auto-sets APTransaction.dueDate); default 30 |
| defaultExpenseAccountNumber | string | No | FK to Account.accountNumber for default expense coding |
| bankAccount | string | No | Payee bank account IBAN for outgoing payments |
| creditTerms | string | No | Free-text payment terms or reference to OR terms record |
| dunningPolicyRef | string | No | FK to OR dunning-workflow policy record per ADR-022 |
| address | object | No | Postal address (street, houseNumber, postcode, city, country) |
| email | string | No | Primary contact email for remittance advice and queries |
| phone | string | No | Primary contact phone number |
| contactRef | string | No | FK to OR contact abstraction if stable per ADR-022; else null |
| administrationId | string | Yes | FK to the administration owning this vendor record |
| lifecycleState | enum | Yes | One of active, blocked, archived |

**Relations:**
- → APTransaction (one-to-many, via vendorId)
- → Account (many-to-one, via defaultExpenseAccountNumber)
- → DunningPolicy (many-to-one, via dunningPolicyRef — OR-owned per ADR-022)
- → Administration (many-to-one)

> **Reconciliation note (bookkeeping-accounts-payable-core, 2026-06-09):** This
> entry has been updated from the prior generic `accounts-payable-receivable`
> draft to the canonical T2 shape registered in
> `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`. The fields
> mirror `CustomerMaster` on the AR side. The pre-T2 `VendorMaster` entry
> below (added by `add-shillinq-bookkeeping-compliance`) remains a parallel
> historical flavour during the migration window; new AP register
> declarations MUST use `Payee`. See `openspec/changes/
> bookkeeping-accounts-payable-core/dedup-notes.md` for the migration boundary.

### Payment
**Schema.org:** `schema:Order`
_Record of payment made against accounts payable or receivable transaction._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentDate | date | Yes | Date when payment was made |
| amount | MonetaryAmount | Yes | Payment amount |
| paymentMethod | enum | Yes | Payment method used |
| reference | string | No | Bank transaction ID or payment reference number |
| paymentStatus | enum | Yes | Current payment status |
| description | string | No | Payment notes or reconciliation details |

**Relations:**
- → APTransaction (many-to-one)

### PaymentBatch
**Schema.org:** `schema:Payment`
_Batch grouping of multiple payments for mass processing, approval, and scheduled execution_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| batchNumber | string | Yes | Unique batch identifier |
| totalAmount | number | Yes | Sum of all payments in batch |
| totalPayments | number | Yes | Count of payments in batch |
| status | string | Yes | Status: pending, processing, completed, failed |
| approvalStatus | string | Yes | Approval status: pending, approved, rejected |
| scheduledDate | datetime | No | Scheduled execution date for batch |
| createdDate | datetime | Yes | Date batch was created |

**Relations:**
- → Organization (many-to-one)
- → Payment (one-to-many)

### PaymentFraudAssessment
**Schema.org:** `schema:Report`
_Fraud risk assessment using payment intelligence and behavioral pattern analysis_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentId | string | Yes | Unique assessment identifier |
| fraudRiskScore | decimal | Yes | Fraud risk probability (0.0-1.0) |
| reportType | string | Yes | Always: payment-fraud-assessment |
| generatedAt | datetime | Yes | Assessment generation timestamp |
| riskFactors | array | No | List of detected risk indicators (JSON array) |
| riskLevel | string | Yes | Risk level: low, medium, high, critical |
| anomalyDetected | boolean | Yes | Behavioral anomaly detected |
| confidenceScore | decimal | Yes | Assessment confidence (0.0-1.0) |

**Relations:**
- → Transaction (many-to-one)
- → Organization (many-to-one)
- → BankAccount (many-to-one)

### PaymentRiskScore
**Schema.org:** `schema:Thing`
_Fraud risk assessment and intelligence scoring for payment transactions_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskLevel | string | Yes | low, medium, high, critical |
| score | number | Yes | 0-100, higher = more risk |
| riskFactors | array | No | velocity, amount, patterns, etc |
| fraudIndicators | array | No |  |
| assessmentDate | datetime | Yes |  |
| notes | string | No |  |

**Relations:**
- → Payment (many-to-one)
- → Person (many-to-one)

### Payroll
**Schema.org:** `schema:Invoice`
_Payroll record for wage, salary, and deduction processing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| payrollNumber | string | Yes | Unique payroll identifier |
| payrollDate | datetime | Yes | Payroll payment date |
| period | string | Yes | Payroll period (e.g., Jan 2026) |
| grossAmount | number | Yes | Gross salary amount |
| netAmount | number | Yes | Net amount after deductions |
| totalAmount | number | Yes | Total payroll amount |
| status | string | Yes | Payroll status (draft, approved, processed) |

**Relations:**
- → Person (many-to-one)
- → Deduction (one-to-many)

### PensionPlan
**Schema.org:** `schema:FinancialProduct`
_IAS 19 / RJ 271 employee-benefit pension plan (regeling) header: plan type (DB/DC/CDC/hybrid), accrual, eligibility, provider, governance, participant counts and the optional HRMQ deelnemersbestand link. DB plans drive full Projected Unit Credit measurement; DC plans get light disclosure only (IAS 19 §53). Lifecycle: draft → active → paused → terminated._
**Primary spec:** bookkeeping-pension-ias19

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| planName | string | Yes | Human-readable plan name |
| planType | enum | Yes | DB / DC / CDC / hybrid |
| regulatoryFramework | enum | Yes | Pensioenwet / BPW / vrijgesteld / IORP-II-buitenland |
| funded | boolean | Yes | Whether the plan has segregated plan assets |
| provider | string | Yes | Provider name (pensioenfonds, verzekeraar, eigen beheer) |
| accrualRate | number | No | Annual DB accrual rate as a fraction of pensioengrondslag |
| retirementAge | integer | Yes | Statutory retirement age |
| participantCountActive | integer | Yes | Active employees with accruing benefits |
| linkedHrmqGroup | string | No | Optional FK to an HRMQ pension-administration group |
| status | enum | Yes | draft / active / paused / terminated |
| administrationId | string | Yes | FK to the owning administration (read scope) |

**Relations:**
- → ActuarialValuation (one-to-many)
- → PensionMovement (one-to-many)
- → PensionDisclosureTabel (one-to-many)

### ActuarialValuation
**Schema.org:** `schema:MonetaryAmount`
_Per-balansdatum measurement of a DB plan's defined-benefit obligation (DBO) and plan assets with the actuarial assumptions and actuaris sign-off. PUC methodology is mandatory for DB plans (IAS 19 §67); the discount rate must be market-referenced (IAS 19 §83); netPensionLiability applies the IFRIC 14 asset ceiling when overfunded. Lifecycle: draft → approved → locked._
**Primary spec:** bookkeeping-pension-ias19

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| plan | FK | Yes | FK to PensionPlan |
| valuationDate | date | Yes | As-of date (typically 31-12-yyyy) |
| methodology | enum | Yes | PUC (DB) / DC (contribution-only) |
| dboGross | number | Yes | Total defined-benefit obligation (EUR) |
| discountRate | number | Yes | Discount rate (% p.a.) |
| discountRateSource | string | Yes | Market source for the audit trail |
| planAssetsFairValue | number | Yes | Fair value of plan assets (EUR) |
| assetCeilingApplied | number | No | IFRIC 14 asset-ceiling adjustment (EUR) |
| netPensionLiability | number | Yes | Computed dboGross − planAssetsFairValue + assetCeilingApplied |
| approvalStatus | enum | Yes | draft / approved / locked |
| administrationId | string | Yes | FK to the owning administration (read scope) |

**Relations:**
- → PensionPlan (many-to-one)
- → PensionAssumptionSensitivity (one-to-many)
- → PensionAssetDetail (one-to-many)

### PensionMovement
**Schema.org:** `schema:MonetaryAmount`
_Per-period roll-forward of DBO and plan assets, split into the three IAS 19R buckets: service + past service + settlement (P&L), net interest (P&L) and actuarial gain/loss (OCI, non-recycling per IAS 19 §122). Closing balances and the P&L/OCI split are computed roll-forward fields._
**Primary spec:** bookkeeping-pension-ias19

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| plan | FK | Yes | FK to PensionPlan |
| period | string | Yes | Period identifier (e.g. 2026, 2026-H1) |
| serviceCostCurrent | number | Yes | Current-period service cost (P&L) |
| pastServiceCost | number | Yes | Past service cost on plan amendment (P&L, IAS 19 §103) |
| netInterestCost | number | Yes | Net interest = discount rate × opening net liability (P&L) |
| actuarialLossGainDBO | number | Yes | Actuarial change on DBO (OCI) |
| actuarialGainLossAssets | number | Yes | Actual − expected return on assets (OCI) |
| dboClosing | number | Yes | Computed closing DBO |
| planAssetsClosing | number | Yes | Computed closing plan assets |
| netPensionMovementPL | number | Yes | Computed total P&L charge |
| netPensionMovementOCI | number | Yes | Computed total OCI remeasurement (non-recycling) |
| administrationId | string | Yes | FK to the owning administration (read scope) |

**Relations:**
- → PensionPlan (many-to-one)

### PensionAssumptionSensitivity
**Schema.org:** `schema:MonetaryAmount`
_DBO sensitivity line for one assumption delta on one ActuarialValuation per IAS 19 §145. Each DB valuation generates eight lines: discount rate ±0.5pp, salary growth ±0.5pp, mortality ±1yr, inflation ±0.5pp._
**Primary spec:** bookkeeping-pension-ias19

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| valuation | FK | Yes | FK to ActuarialValuation |
| assumption | enum | Yes | discount-rate / salary-growth / mortality / inflation |
| direction | string | Yes | +0.5pp / -0.5pp / +1yr / -1yr |
| effectOnDBO | number | Yes | Impact on DBO (EUR) |
| effectOnServiceCost | number | Yes | Impact on service cost (EUR) |
| administrationId | string | Yes | FK to the owning administration (read scope) |

**Relations:**
- → ActuarialValuation (many-to-one)

### PensionAssetDetail
**Schema.org:** `schema:MonetaryAmount`
_Plan-asset breakdown by category with the IFRS 13 fair-value level (1 quoted / 2 observable / 3 unobservable) for one ActuarialValuation. The per-valuation category fair values sum to ActuarialValuation.planAssetsFairValue._
**Primary spec:** bookkeeping-pension-ias19

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| valuation | FK | Yes | FK to ActuarialValuation |
| assetCategory | enum | Yes | cash / equities-quoted / bonds-government / bonds-corporate / real-estate / alternative / derivatives |
| fairValue | number | Yes | Fair value of assets in this category (EUR) |
| level | integer | Yes | IFRS 13 fair-value level (1/2/3) |
| administrationId | string | Yes | FK to the owning administration (read scope) |

**Relations:**
- → ActuarialValuation (many-to-one)

### PensionDisclosureTabel
**Schema.org:** `schema:Table`
_Auto-generated jaarrekening disclosure table per IAS 19 §135–149 for a plan on a balansdatum (assumptions, DBO movement, asset movement, P&L + OCI summary, asset breakdown, duration, expected employer contribution). For DC plans it carries only the contribution amount + regeling summary. Lifecycle: draft → approved → published._
**Primary spec:** bookkeeping-pension-ias19

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| plan | FK | Yes | FK to PensionPlan |
| valuationDate | date | Yes | Balansdatum |
| tableContent | JSON | Yes | Structured disclosure table, auto-generated |
| status | enum | Yes | draft / approved / published |
| administrationId | string | Yes | FK to the owning administration (read scope) |

**Relations:**
- → PensionPlan (many-to-one)

### PeppolAccessPoint
**Schema.org:** `schema:Service`
_Peppol Access Point providing gateway services for e-invoicing and document exchange_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accessPointId | string | Yes | Unique access point identifier |
| name | string | Yes | Access point name or provider |
| endpoint | string | Yes | API endpoint URL for document submission |
| protocol | string | Yes | Communication protocol (AS4, AS2, SFTP, HTTP) |
| documentTypes | array | No | Supported document types (Invoice, Order, Despatch Advice, etc.) |
| supportContact | string | No | Support contact email or phone |
| status | string | Yes | Access point status (active, inactive, testing, deprecated) |

**Relations:**
- → Organization (many-to-one)
- → PeppolParticipant (many-to-one)

### PeppolParticipant
**Schema.org:** `schema:Thing`
_Peppol network participant identifier registration for e-invoicing and EDI communication_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| participantId | string | Yes | Unique Peppol participant identifier |
| scheme | string | Yes | Identifier scheme (GLN, VAT, DUNS, etc.) |
| organizationName | string | Yes | Legal organization name |
| country | string | No | Country code (ISO 3166-1 alpha-2) |
| registeredDate | datetime | No | Date of Peppol network registration |
| expiryDate | datetime | No | Peppol registration expiry date |
| status | string | Yes | Participant status (active, inactive, pending, revoked) |

**Relations:**
- → Organization (many-to-one)

### PerDiem
**Schema.org:** `schema:Offer`
_Daily allowance for employees on company travel, calculated based on country-specific rates, nights away, and configurable per diem policies_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| date | datetime | Yes | Date for which per diem is claimed |
| country | string | Yes | Country where travel occurred |
| nights | number | No | Number of nights away from home base |
| rate | number | Yes | Per diem rate applicable for the country/date |
| amount | number | Yes | Total per diem allowance amount |
| status | string | Yes | Status: draft, approved, or paid |
| approvedDate | datetime | No | Date when per diem was approved |
| description | string | No | Travel purpose or notes |

**Relations:**
- → Person (many-to-one)
- → CostCenter (many-to-one)

> **Reconciliation note (expense-capture-core, 2026-06-03):** The existing `PerDiem` entry (primary spec: cost-accounting-allocation) is a generic daily-allowance schema (date, country, nights, rate, amount). The expense-capture-core `PerDiem` schema in `lib/Settings/shillinq_register.json` is the shillinq bookkeeping-tier per-diem record with travelStartDate/travelEndDate, nightCount, ISO-3166 country validation, auto-calculated allowanceAmount (nightCount × dailyRate from PerDiemRate master table), cost-centre allocation, and FK to `ExpenseClaimEntry`. New per-diem implementations in shillinq expense flows MUST use the expense-capture-core schema. The generic `PerDiem` entry is retained for cost-accounting-allocation usage outside the expense-capture flow.

### PerformanceImprovementAction
**Schema.org:** `schema:Action`
_Action plan for addressing performance gaps and improving supplier performance against metrics and SLAs_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| actionId | string | Yes | Unique action identifier |
| description | string | Yes | Description of the improvement action |
| targetCompletionDate | datetime | Yes | Target completion date |
| owner | string | Yes | Person or role responsible for action |
| expectedImpact | string | No | Expected improvement or benefit |
| priority | string | Yes | Priority level (high, medium, low) |
| status | string | Yes | Status (planned, in_progress, completed, cancelled) |
| createdDate | datetime | No | Date action was created |

**Relations:**
- → Organization (many-to-one)
- → SupplierPerformanceScorecard (many-to-one)

### PerformanceScore
**Schema.org:** `schema:Rating`
_Individual KPI score recorded for a supplier within a scorecard evaluation period_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scoreId | string | Yes | Unique score identifier |
| achievedValue | number | Yes | Actual measured value achieved |
| targetValue | number | No | Target value for comparison |
| scoredDate | datetime | Yes | Date when score was recorded |
| notes | string | No | Additional notes or observations |
| status | string | Yes | Score status (recorded, reviewed, approved) |

**Relations:**
- → SupplierPerformanceScorecard (many-to-one)
- → SupplierKPI (many-to-one)

### Permission
_Granular access permission for a specific resource and action_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Unique permission name |
| description | string | No | Detailed permission description |
| resource | string | Yes | Resource this permission applies to (e.g., users, documents, fields) |
| action | string | Yes | Action allowed (read, write, delete, approve) |
| isActive | boolean | Yes | Whether the permission is active |

**Relations:**
- → Role (many-to-many)

### Person
**Schema.org:** `schema:Person`
_Schema.org Person — standard vocabulary for person data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | First name |
| familyName | string | Yes | Last name |
| email | string | No | Email address |
| telephone | string | No | Phone number |
| jobTitle | string | No | Job title/role |

### PolicyRule
**Schema.org:** `schema:Thing`
_A spending policy rule that defines constraints, approval requirements, and limits for expense compliance enforcement_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the policy rule |
| description | string | No | Detailed description of what the rule enforces |
| thresholdAmount | number | No | Amount threshold that triggers the policy rule |
| ruleType | string | Yes | Type of rule: approval, limit, travel, delegation, etc. |
| isActive | boolean | Yes | Whether the rule is currently enforced |
| priority | number | No | Evaluation priority when multiple rules apply |

**Relations:**
- → Organization (many-to-one)
- → ExpenseCategory (many-to-one)
- → PolicyViolation (one-to-many)

### PolicyViolation
**Schema.org:** `schema:Thing`
_A detected violation or breach of a spending policy rule that requires attention and resolution_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| violationDate | datetime | Yes | Date when the violation was detected |
| severity | string | Yes | Severity level: low, medium, high, critical |
| description | string | Yes | Description of the specific policy violation |
| amount | number | No | The amount that exceeded or violated the policy threshold |
| status | string | Yes | Status: open, acknowledged, resolved, escalated |

**Relations:**
- → PolicyRule (many-to-one)
- → Expense (many-to-one)
- → Person (many-to-one)

### PricingRule
**Schema.org:** `schema:PriceSpecification`
_Volume discounts, tiered pricing, bundle discounts, and promotional pricing rules with validity periods and application priorities_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleCode | string | Yes | Unique pricing rule identifier |
| description | string | No | Rule description and conditions |
| ruleType | string | Yes | volumeDiscount, tierPricing, bundleDiscount, periodDiscount |
| minQuantity | number | No | Minimum quantity for rule application |
| maxQuantity | number | No | Maximum quantity for rule application |
| discountPercentage | number | No | Percentage discount (0-100) |
| discountAmount | number | No | Fixed discount amount in base currency |
| priority | number | No | Priority order for rule application |
| validFrom | datetime | No |  |
| validUntil | datetime | No |  |

**Relations:**
- → CatalogItem (many-to-one)

### ProcurementAuditLog
**Schema.org:** `schema:Action`
_Immutable audit trail recording all procurement actions, approvals, rejections, and changes for transparency, compliance, and decision accountability_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditId | string | Yes | Unique audit log entry identifier |
| entityType | string | Yes | Entity type: requisition, purchaseOrder, invoice, payment, approval |
| entityId | string | Yes | ID of the entity being audited |
| actionType | string | Yes | created, updated, approved, rejected, posted, received |
| timestamp | datetime | Yes | When the action occurred |
| reason | string | No | Reason or comment for the action |
| changes | object | No | Changed fields with old and new values |
| referenceDocuments | array | No | Related document identifiers |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### ProcurementCatalog
**Schema.org:** `schema:Catalog`
_Master catalog of products and services available for organizational procurement with support for multiple formats (cXML, CIF, internal)_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| catalogNumber | string | Yes | Unique catalog identifier |
| catalogName | string | Yes | Display name of the catalog |
| description | string | No | Catalog description and scope |
| catalogFormat | string | No | Format type: internal, cxml, cif |
| status | string | Yes | draft, active, archived |
| validFrom | datetime | No | Catalog effective start date |
| validUntil | datetime | No | Catalog expiration date |

**Relations:**
- → Organization (many-to-one)
- → CatalogItem (one-to-many)

### ProcurementCategory
**Schema.org:** `schema:Thing`
_Strategic procurement category with sourcing plans and market intelligence for supplier management and spend analysis_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique category code |
| name | string | Yes | Category name |
| sourcingStrategy | string | No | Strategic sourcing approach and policy |
| marketIntelligence | object | No | Market data, price trends, and competitive intelligence |
| status | string | Yes | Category status (active, inactive, archived) |

**Relations:**
- → Product (one-to-many)
- → Organization (many-to-one)

### ProcurementComplianceReport
**Schema.org:** `schema:Report`
_Organization-wide procurement compliance dashboard/aggregation per period_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Period identifier (e.g., 2026-Q1, monthly) |
| startDate | date | Yes | Period start date |
| endDate | date | Yes | Period end date |
| totalProcurementValue | MonetaryAmount | Yes | Sum of all orders in period |
| publicProcurementValue | MonetaryAmount | Yes | Value subject to public procurement rules |
| totalOrderCount | number | Yes | Total orders placed in period |
| complianceScore | number | Yes | Percentage compliance (0-100) |
| violationCount | number | No | Number of detected compliance violations |
| maverickSpendCount | number | No | Count of unauthorized/off-contract spend alerts |
| missingProofOfDelivery | number | No | Orders lacking delivery proof submission |
| expiredQualifications | number | No | Vendors with expired UEA declarations |

**Relations:**
- → MaverickSpendAlert (one-to-many)

### ProcurementOrder
**Schema.org:** `schema:Order`
_Procurement order with compliance tracking for Dutch public procurement rules (BBI, threshold checking)_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorName | string | Yes | Supplier/vendor name |
| vendorKvk | string | No | Dutch business registration number (KVK) |
| vendorVatID | string | No | EU VAT identification number |
| isPublicProcurement | boolean | Yes | Subject to public procurement rules (BBI threshold €15k) |
| procurementCategory | enum | Yes | supplies, services, works, or combined |
| estimatedValue | MonetaryAmount | Yes | Estimated order value for threshold compliance |
| deliveryDate | date | Yes | Expected delivery/completion date |
| paymentTerms | string | No | Payment conditions (e.g., net 30) |
| requiresProofOfDelivery | boolean | No | Portal submission of delivery proof required |

**Relations:**
- → ProofOfDelivery (one-to-many)
- → QualificationDeclaration (one-to-many)

### ProcurementProcedure
**Schema.org:** `ProcurementProcedure`
_Procurement procedure type defining governance rules and compliance requirements_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| procedureName | string | Yes | Name of the procurement procedure |
| procedureType | string | Yes | Procedure type: open, restricted, negotiated, below-threshold |
| estimatedValue | number | Yes | Estimated contract value in EUR |
| euThreshold | number | Yes | EU threshold value that determines procedure type |
| requiresEUCompliance | boolean | Yes | Whether EU Directive 2014/24/EU applies |
| status | string | Yes | Status: draft, active, completed, cancelled |

**Relations:**
- → PurchaseOrder (one-to-many)
- → Organization (many-to-one)

### ProcurementQuote
**Schema.org:** `schema:Offer`
_Supplier quote for goods or services with validity period_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Quote title or reference |
| quoteNumber | string | Yes | Unique quote identifier |
| quoteDate | date | Yes | Date quote was issued |
| validFrom | date | Yes | Quote validity start date |
| validThrough | date | Yes | Quote validity end date |
| totalPrice | number | Yes | Total quote amount |
| currency | string | Yes | Currency code (EUR) |
| deliveryTime | string | No | Estimated delivery timeframe |

**Relations:**
- → Supplier (many-to-one)
- → InventoryItem (many-to-many)

### Product
**Schema.org:** `schema:Product`
_Schema.org Product — standard vocabulary for product data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | No | Stock keeping unit |
| description | string | No | Product description |
| category | string | No | Product category |
| unitPrice | number | Yes | Unit price |
| currency | string | Yes | ISO 4217 currency code |
| unitCode | string | No | Unit of measure (UN/CEFACT) |
| taxRate | number | No | Applicable tax rate percentage |

> **Reconciliation note (inventory-product-catalog, 2026-06-08):** This basic `Product` outline is superseded by the fuller `Product` register declared in `lib/Settings/shillinq_register.json` per primary spec `inventory-product-catalog` (T1, shillinq app). The fuller declaration carries forward the existing fields (name, sku, description, category, unitPrice, currency, unitCode, taxRate) and additively adds `primaryBarcode`, `barcodes` (JSON array supporting multi-UoM codes per inventory-barcode-sku), `status` (lifecycleState), `organizationId`, and the unique constraint on `(organizationId, sku)` via `x-openregister-unique`. RBAC is declared on the register (procurement: CRUD, inventory: CRU, auditor: R). A companion `ProductAttribute` register adds typed extensibility per category. This entry is retained as a historical snapshot; consumers MUST treat the inventory-product-catalog declaration as canonical.

### Project
**Schema.org:** `schema:Project`
_An analytical project for tracking time, materials, and costs per project in the shillinq bookkeeping tier. Same field shape as CostCenter and KostenDrager per REQ-CC-002. The `timeBookingEnabled` flag pre-positions the WBSO time-per-project shape: a WBSO capability can join `TimeEntry.projectCode` to `Project.code` and aggregate hours per project per fiscal year per REQ-CC-007 without modifying this schema. Segment P&L aggregation declared on GLLine keyed by projectCode per REQ-CC-005._
**Primary spec:** bookkeeping-cost-centers-dimensions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Operator-assigned unique reference within the administration |
| name | string | Yes | Human-readable project name |
| parentCode | string | No | FK to parent Project.code for hierarchy |
| responsibleUser | string | No | NC user id of the project owner |
| timeBookingEnabled | boolean | No | When true, time bookings may reference this project for WBSO derivation per REQ-CC-007 |
| lifecycleState | enum | Yes | One of active, blocked, archived |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- self → Project (many-to-one, via parentCode → code; hierarchy navigation)
- → GLLine (one-to-many, via projectCode FK, additive dimension field per REQ-CC-003)

> **Reconciliation note (add-shillinq-cost-centers-dimensions, 2026-06-03):** The earlier `Project` entry (primary spec: approval-workflow-management) described a generic project management container with `projectId/description/status/owner/startDate/endDate/budget` fields related to ProjectTask and Milestone. That entry is for the approval-workflow-management domain and is NOT the bookkeeping-tier `Project` register declared by `add-shillinq-cost-centers-dimensions`. These are distinct OR registers: the approval-workflow Project is a management entity; the bookkeeping-tier Project declared here is an analytical dimension for cost tracking and WBSO pre-positioning. The bookkeeping-tier `Project` uses `code` (not `projectId`) as the primary key, mirrors the CostCenter shape, and carries the `timeBookingEnabled` flag. Both entries coexist.

> **CPA extension note (add-shillinq-consultancy-project-accounting, 2026-06-01):** The consultancy
> project accounting capability (`bookkeeping-consultancy-project-accounting`, T3) declares a
> purpose-built `Project` schema in `lib/Settings/shillinq_register.json` with the full T3 financial
> field set (projectNumber, customerId, totalContractValue, totalEstimatedCosts, costsIncurredToDate,
> recognisedRevenue, billedRevenue, wipBalance, recognitionMethod, recognitionStage) plus
> `x-openregister-lifecycle` (`offerte → active → on-hold → closed → archived`) and
> `x-openregister-calculations` for percentage-of-completion revenue recognition per RJ 270 §3 /
> IFRS 15 §B14-B19. The approval-workflow `Project` entry above remains the canonical entity for
> task/milestone management; the T3 CPA `Project` is a distinct bookkeeping register schema for
> consultancy project financial tracking. **Primary spec (CPA variant):** bookkeeping-consultancy-project-accounting

### ProjectAssignment
**Schema.org:** `schema:JobPosting`
_Assignment of a person to a consultancy project with rate-card reference and utilisation tracking._
**Primary spec:** bookkeeping-consultancy-project-accounting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectId | string | Yes | FK to the parent Project.id |
| personId | string | Yes | FK to the assigned person (Nextcloud user or OR Person record) |
| rateCardId | string | Yes | FK to the RateCard effective at assignment time |
| recognisedRate | number | Yes | Snapshot of RateCard.hourlyRate at assignment time per RJ 270 §3.2.4 — immutable after creation |
| estimatedHours | number | No | Operator estimate of hours this person will spend on this project |
| startDate | date | Yes | Assignment start date |
| endDate | date | No | Assignment end date (nullable = open-ended) |
| state | enum | Yes | One of planned, active, completed |
| capacityHoursPerWeek | number | No | Weekly capacity hours for utilisation calculation; default 40 per Wet IB |
| utilization | number | No | Derived: billableHoursThisPeriod / capacityHoursThisPeriod (x-openregister-calculations, REQ-CPA-011) |

**Relations:**
- → Project (many-to-one)
- → RateCard (many-to-one)
- → UrenRegistratie (one-to-many)

### ProjectTask
**Schema.org:** `schema:Action`
_Tasks within a project with hierarchy support, time estimation, and status tracking_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskId | string | Yes | Unique task identifier |
| projectId | string | Yes | Parent project ID |
| title | string | Yes | Task title |
| description | string | No | Task description and acceptance criteria |
| parentTaskId | string | No | Parent task ID for nested subtasks |
| assignedTo | string | No | Person/User ID assigned to this task |
| status | string | No | new/inProgress/completed/blocked/onHold |
| priority | string | No | high/medium/low |
| estimatedHours | number | No | Estimated hours to complete |
| actualHours | number | No | Actual hours spent |
| dueDate | datetime | No | Task due date |
| completedDate | datetime | No | Actual completion date |

**Relations:**
- → Project (many-to-one)
- → ProjectTask (many-to-one)
- → Person (many-to-one)
- → TimeEntry (one-to-many)

### ProofOfDelivery
**Schema.org:** `schema:DigitalDocument`
_Portal submission documenting goods/services received per order with receiver verification_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deliveryDate | date | Yes | Date goods/services were received |
| receivingDepartment | string | Yes | Organizational department that received delivery |
| goodsDescription | string | Yes | Description of what was delivered |
| quantity | number | No | Quantity of items delivered |
| unitOfMeasure | string | No | Unit (pieces, kg, hours, etc.) |
| conditionNotes | string | No | Assessment of delivered condition/quality |
| verifiedByName | string | Yes | Name of person verifying receipt |
| verifiedByJobTitle | string | No | Role/title of verifying person |
| submissionDate | date | Yes | Date proof submitted via portal |

**Relations:**
- → ProcurementOrder (many-to-one)

### Property
**Schema.org:** `schema:Place`
_Real estate property subject to assessment, valuation, and interactive mapping_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| address | string | Yes | Street address |
| city | string | Yes |  |
| province | string | Yes |  |
| latitude | number | Yes | Latitude for mapping |
| longitude | number | Yes | Longitude for mapping |
| propertyType | string | Yes | residential, commercial, industrial, or mixed |
| acquisitionValue | number | No |  |
| currentValue | number | No |  |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)
- → PropertyAssessment (one-to-many)
- → WOZAssessment (one-to-many)

### PropertyAssessment
**Schema.org:** `schema:Assessment`
_Assessment scoring a property against defined weighted criteria_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentDate | datetime | Yes |  |
| totalScore | number | Yes | Score 0-100 |
| status | string | Yes | draft, in-progress, completed, rejected |
| completionDate | datetime | No |  |
| notes | string | No |  |

**Relations:**
- → Property (many-to-one)
- → Person (many-to-one)
- → AssessmentCriteria (many-to-many)

### PublicProcurement
**Schema.org:** `schema:Service`
_European public procurement announcement for TED/OJEU publication with tender documents and timelines_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| procurementId | string | Yes | Unique procurement identifier |
| title | string | Yes | Procurement announcement title |
| description | string | Yes | Detailed procurement description |
| status | string | Yes | Publication status |
| publicationDate | datetime | No | Actual TED/OJEU publication date |
| dueDate | datetime | Yes | Tender submission deadline |
| publishingAuthority | string | Yes | Organization publishing the procurement |
| tedReference | string | No | TED publication reference number |
| procurementType | string | Yes | Type: goods, services, or works |
| estimatedBudget | number | No | Estimated contract value |

**Relations:**
- → Organization (many-to-one)
- → Document (one-to-many)
- → PublicationAmendment (one-to-many)
- → DigitalDocument (many-to-one)

### PublicationAmendment
**Schema.org:** `schema:Thing`
_Material or minor changes to published procurement announcements requiring re-publication to TED/OJEU_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amendmentId | string | Yes | Unique amendment identifier |
| publicationId | string | Yes | Reference to PublicProcurement being amended |
| changeType | string | Yes | Classification: material or minor change |
| description | string | Yes | Details of the amendment |
| amendmentDate | datetime | Yes | When amendment was flagged |
| status | string | Yes | Processing status |
| reason | string | No | Reason for amendment |

**Relations:**
- → PublicProcurement (many-to-one)
- → DigitalDocument (many-to-one)

### PublicationLog
**Schema.org:** `schema:Event`
_Audit trail recording publication events including creation, updates, downloads and external platform notifications_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| logId | string | Yes | Unique log entry identifier |
| publicationId | string | Yes | Reference to related publication entity |
| logType | string | Yes | Event type: created, published, amended, downloaded, notified, or error |
| timestamp | datetime | Yes | When event occurred |
| details | object | No | Additional event details as key-value pairs |
| ipAddress | string | No | Source IP address of action |
| userAgent | string | No | Client user agent string |
| description | string | No | Human-readable log entry description |

**Relations:**
- → DigitalDocument (many-to-one)
- → Person (many-to-one)
- → Organization (many-to-one)

### PublicationNotice
**Schema.org:** `schema:Thing`
_A notice published to external procurement channels (TenderNed, TED) including tender publication, award notices, corrigenda, and DPS notices_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeId | string | Yes | Unique identifier for the publication notice |
| noticeType | string | Yes | Type: tender, award, corrigendum, dps_admission |
| publicationChannel | string | Yes | Channel where notice is published: TenderNed, TED, or both |
| externalNoticeId | string | No | ID assigned by external system (TenderNed or TED reference number) |
| status | string | Yes | Status: draft, submitted, published, failed, withdrawn |
| publishedDate | datetime | No | Date the notice was published |
| submissionDate | datetime | No | Date the notice was submitted for publication |
| isAboveThreshold | boolean | No | Whether this is an above-threshold EU notice |
| errorMessage | string | No | Error message if publication failed |

**Relations:**
- → Tender (many-to-one)
- → DigitalDocument (one-to-many)

### PurchaseOrder
**Schema.org:** `schema:Order`
_Purchase order with approval tracking for Dutch bookkeeping workflow_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Unique purchase order number for identification and reference |
| orderDate | datetime | Yes | Date when the purchase order was created |
| totalPrice | number | Yes | Total price including tax and shipping |
| currency | string | Yes | Currency code (e.g., EUR, USD) |
| taxAmount | number | Yes | Total tax amount for the purchase order |
| paymentTerms | string | No | Payment terms (e.g., net 30, net 60) |
| deliveryDate | datetime | Yes | Expected delivery date |
| vendorName | string | Yes | Name of the vendor/supplier |
| vendorKvk | string | Yes | Dutch KvK (Chamber of Commerce) registration number |
| lineItems | array | Yes | Array of ordered items with quantity, unit price, and description |
| internalReference | string | No | Internal reference number or cost center code |
| deliveryAddress | object | Yes | Delivery address with street, city, postal code, and country |
| discountAmount | number | No | Discount amount applied to the order |
| shippingCost | number | No | Shipping or delivery cost |
| vendorEmail | string | No | Email address of the vendor contact |
| invoiceReference | string | No | Reference to the linked invoice number |
| departmentCode | string | No | Department or cost center code for cost allocation |
| description | string | No | General description or purpose of the purchase order |

**Relations:**
- → PurchaseOrderRevision (one-to-many)
- → ApprovalRequest (one-to-many)
- → Product (many-to-many)

### PurchaseOrderChange
**Schema.org:** `schema:Order`
_Purchase order amendment with full version tracking and change audit trail_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| changeNumber | string | Yes | Unique change order identifier |
| changeDate | date | Yes | Date change was requested |
| originalPoNumber | string | Yes | Original PO reference |
| versionNumber | integer | Yes | PO version (e.g., 1, 2, 3) |
| changedFields | text | Yes | JSON: {field: oldValue → newValue} for audit purposes |
| changeReason | text | Yes | Business reason for change |

**Relations:**
- → Organization (many-to-one)
- → Product (many-to-many)

### PurchaseOrderRevision
**Schema.org:** `schema:DigitalDocument`
_Tracks PO revisions and amendments with change history and version control_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| revisionNumber | integer | Yes | Sequential revision number |
| revisedAt | datetime | Yes | Revision timestamp |
| changeDescription | text | Yes | Detailed description of changes |
| amendmentReason | string | No | Reason for amendment (price, quantity, scope) |
| documentType | string | Yes | Document type (revision|amendment) |
| encodingFormat | string | No | File format (PDF, DOCX) |
| contentSize | integer | No | File size in bytes |

**Relations:**
- → PurchaseOrder (many-to-one)

### PurchaseRequisition
**Schema.org:** `schema:Order`
_A formal request for goods or services with multiple line items and custom fields, supporting multi-location and multi-entity procurement workflows_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requisitionNumber | string | Yes | Unique requisition identifier |
| requisitionDate | datetime | Yes | Date requisition was created |
| status | string | Yes | draft, submitted, approved, rejected, ordered |
| purpose | string | No | Purpose or business justification |
| deliveryDate | datetime | No | Requested delivery date |
| customFields | object | No | Custom fields for procurement-specific data |
| totalAmount | number | No | Estimated total value |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)
- → ApprovalRequest (one-to-many)

### QualificationDeclaration
**Schema.org:** `schema:DigitalDocument`
_UEA (Uniforme Europese Aanbestedingsdocument) self-certification by vendor for procurement qualification_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorName | string | Yes | Declaring organization/vendor name |
| vendorKvk | string | Yes | Dutch KVK registration of vendor |
| declarationDate | date | Yes | Date of UEA self-declaration submission |
| validFrom | date | Yes | Declaration validity start date |
| validUntil | date | Yes | Declaration expiry date |
| declarationStatus | enum | Yes | submitted, accepted, rejected, or expired |
| excludedFromProcurement | boolean | No | Vendor exclusion grounds present (bankruptcy, criminal record, etc.) |
| professionalLicenses | string | No | Relevant professional certifications held |
| economicOperatorRegister | string | No | Registration in EPER or similar EU register |
| declarationNotes | string | No | Additional compliance statements |

### QualityManagementSystem
**Schema.org:** `Thing`
_A quality management system defining procedures, controls, and certifications for organizational quality assurance_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| qmsNumber | string | Yes | Unique QMS identifier |
| qmsName | string | Yes | Name or title of the QMS |
| version | string | No | Current version number |
| status | string | Yes | Status: active, inactive, or under-review |
| effectiveDate | datetime | Yes | Date the QMS became effective |
| scope | string | No | Scope of the quality management system |
| certifications | array | No | List of certifications (ISO 9001, etc.) |

**Relations:**
- → Organization (many-to-one)
- → Document (one-to-many)
- → ComplianceAudit (one-to-many)

### Quote
**Schema.org:** `schema:Offer`
_Supplier response to tender with pricing and terms_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| quoteNumber | string | Yes | Unique quote identifier |
| price | number | Yes | Total quoted price (in cents) |
| priceCurrency | string | Yes | Currency (EUR) |
| validFrom | date | Yes | Quote valid-from date |
| validThrough | date | Yes | Quote expiration date |
| paymentTerms | string | No | Payment terms (Net30, etc.) |

**Relations:**
- → Tender (many-to-one)
- → Supplier (many-to-one)

### RateCard
**Schema.org:** `schema:Thing`
_Supplier rate and pricing structure matching contract terms with volume discounts and payment terms_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rateCardId | string | Yes | Unique rate card identifier |
| rateCardName | string | Yes | Name or title of the rate card |
| effectiveDate | datetime | Yes | Date rate card becomes effective |
| expiryDate | datetime | No | Date rate card expires |
| currency | string | Yes | Currency for pricing |
| rateType | string | Yes | Type of pricing: hourly, daily, fixedPrice, or volumeDiscount |
| rates | array | Yes | Array of rate entries with position/service and corresponding rates |
| paymentTerms | string | No | Payment terms and conditions |

**Relations:**
- → Supplier (many-to-one)
- → Contract (many-to-one)

> **CPA extension note (add-shillinq-consultancy-project-accounting, 2026-06-01):** The consultancy
> project accounting capability declares a purpose-built `RateCard` schema in
> `lib/Settings/shillinq_register.json` with per-level (junior/medior/senior/partner) hourly rates,
> `effectiveFrom`/`effectiveTo` effectivity windows, and ISO 4217 currency. Default templates
> seeded from `lib/Settings/seeds/rate-card-templates.json`. The supplier-management `RateCard`
> entry above remains the canonical entity for supplier pricing; the T3 CPA `RateCard` is a
> distinct personnel rate-card register schema. **Primary spec (CPA variant):**
> bookkeeping-consultancy-project-accounting


### RateCardTemplate
**Schema.org:** `schema:Thing`
_Reusable rate-card structure definition for multi-tier billing rates (user / role / project / client / blended). Versioned via RateCardVersion. Per-OU isolation._
**Primary spec:** rate-card-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| templateId | string | Yes | Unique rate-card template identifier |
| name | string | Yes | Human-readable template name |
| description | string | No | Purpose and scope of this template |
| tierStructure | enum array | Yes | Ordered list of tiers: `["user", "role", "project", "client", "blended"]` |
| currency | string | Yes | ISO 4217 currency code (default EUR) |
| administrationId | string | Yes | FK to administration (per-OU isolation; REQ-RATE-002) |
| lifecycleState | enum | Yes | One of `active`, `archived` |
| createdAt | datetime | Yes | Template creation timestamp (immutable) |

**Relations:**
- → RateCardVersion (one-to-many)

> **Note:** `RateCardTemplate` is distinct from the supplier-management `RateCard` entity (above). `RateCard` covers supplier pricing; `RateCardTemplate`/`RateCardVersion`/`RateSchedule` cover employee/project/client billing rate hierarchies in the rate-card-engine capability.

### RateCardVersion
**Schema.org:** `schema:Thing`
_Effective-dated variant of a RateCardTemplate. Multiple concurrent versions per administration, each covering a non-overlapping effective-date window. REQ-RATE-003._
**Primary spec:** rate-card-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| versionId | string | Yes | Unique version identifier |
| templateId | string | Yes | FK to `RateCardTemplate` |
| effectiveDate | date | Yes | Start date this version is active (inclusive); MUST be ≥ today at creation |
| expiryDate | date | No | End date (inclusive); null = open-ended |
| status | enum | Yes | One of `draft`, `active`, `expired`, `archived` |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → RateCardTemplate (many-to-one)
- → RateSchedule (one-to-many)

### RateSchedule
**Schema.org:** `schema:Thing`
_Tier-specific rate entry for a RateCardVersion. Non-overlapping effective windows per (tier, entityId) enforced (REQ-RATE-006)._
**Primary spec:** rate-card-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scheduleId | string | Yes | Unique rate schedule identifier |
| versionId | string | Yes | FK to `RateCardVersion` |
| tier | enum | Yes | One of `user`, `role`, `project`, `client`, `blended` |
| entityId | string | No | Entity identifier (userId, roleId, projectId, clientId); null for blended-default |
| rate | number | Yes | Fixed rate per unit in template currency |
| unit | enum | Yes | One of `hourly`, `daily`, `monthly`, `fixedPrice` |
| effectiveDate | date | Yes | Start date this rate is active (inclusive) |
| expiryDate | date | No | End date (inclusive); null = open-ended |
| volumeBrackets | array | No | Optional volume-discount brackets `[{minUnits, maxUnits, rate}]` |
| administrationId | string | Yes | FK to administration |
| status | enum | Yes | One of `active`, `inactive`, `archived` |

**Relations:**
- → RateCardVersion (many-to-one)
- → RateRecord (one-to-many, via resolvedScheduleId)

### RateRecord
**Schema.org:** `schema:Thing`
_Immutable materialized audit-trail entry created for each rate lookup. Stores resolved tier, rate, effective window, and lookup context. Historical rates remain queryable and disputable (REQ-RATE-007, REQ-RATE-010)._
**Primary spec:** rate-card-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| recordId | string | Yes | Unique audit record identifier |
| lookupDate | date | Yes | Date the rate lookup occurred |
| userId | string | No | Input userId from lookup request |
| roleId | string | No | Input roleId from lookup request |
| projectId | string | No | Input projectId from lookup request |
| clientId | string | No | Input clientId from lookup request |
| resolvedTier | enum | Yes | Winning tier: user/role/project/client/blended |
| resolvedScheduleId | string | Yes | FK to winning `RateSchedule` (immutable snapshot) |
| resolvedRate | number | Yes | Final resolved rate (immutable) |
| resolvedUnit | enum | Yes | hourly/daily/monthly/fixedPrice |
| effectiveWindowStart | date | Yes | Schedule's effective start at lookup time |
| effectiveWindowEnd | date | No | Schedule's effective end at lookup time |
| administrationId | string | Yes | FK to administration |
| createdAt | datetime | Yes | Materialization timestamp (immutable) |

**Relations:**
- → RateSchedule (many-to-one, via resolvedScheduleId)

### RetainerPool
**Schema.org:** `schema:MonetaryGrant`
_Monthly retainer allocation per (clientId, projectId) with effective-period window, fixed pool amount + currency, retainer rate (hourly / daily / fixed) used to convert time-entry hours into drawdown amount, and pool-level rollover policy (carryover cap by amount or hours, or reset-balance for monthly reset). Operator-driven lifecycle draft → active → closed → archived. Once closed, pool amount/rate/policy are immutable so the true-up calculation remains deterministic. Overlapping periods for the same (client, project) pair are rejected at activation (REQ-RETN-001). Depends on rate-card-engine for overage-rate lookup (REQ-RETN-005)._
**Primary spec:** retainer-billing-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| poolId | string | Yes | Unique pool identifier (REQ-RETN-001) |
| clientId | string | Yes | FK to the client this retainer pool serves |
| projectId | string | No | Optional FK to a project within the client engagement |
| periodStart | date | Yes | Effective-period start (inclusive) |
| periodEnd | date | Yes | Effective-period end (inclusive) |
| poolAmount | number | Yes | Allocated retainer amount in pool currency (multipleOf 0.01) |
| currency | string | Yes | ISO 4217 currency code; default EUR |
| retainerRate | number | Yes | Rate used to convert time-entry hours into drawdownAmount (multipleOf 0.01) |
| retainerRateUnit | enum | No | hour / day / fixed (default hour) |
| rolloverPolicy | object | Yes | resetBalance + carryoverMaxAmount + carryoverMaxHours + carryoverCapUnit |
| administrationId | string | Yes | FK to administration (per-OU isolation) |
| status | enum | Yes | draft / active / closed / archived |
| sourcePoolId | string | No | FK to prior-period pool when auto-created via rollover (REQ-RETN-009) |
| description | string | No | Operator notes |

**Relations:**
- → Organization / Administration (many-to-one, via administrationId)
- → Client / Contact (many-to-one, via clientId — Nextcloud contact entity)
- → RetainerPool (many-to-one self-reference, via sourcePoolId — prior-period rollover chain)
- → RetainerDrawdown (one-to-many)
- → RetainerTrueUp (one-to-many, typically one per pool per period)
- → RetainerRollover (one-to-many as sourcePeriodPoolId; one-to-one as targetPeriodPoolId)

### RetainerDrawdown
**Schema.org:** `schema:Action`
_Immutable per-time-entry consumption record against a RetainerPool. Created by the TimeEntry create lifecycle hook (REQ-RETN-002). drawdownAmount = hoursOrAmount × drawdownRate where drawdownRate is the pool's retainerRate at materialization time — NOT the timesheet's billable rate. Once status=materialized, the record is read-only; reversal/adjustment creates a new record linking back via reversalOfDrawdownId for an immutable audit chain._
**Primary spec:** retainer-billing-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| drawdownId | string | Yes | Unique drawdown identifier |
| poolId | string | Yes | FK to RetainerPool being consumed |
| timeEntryId | string | Yes | FK to consuming TimeEntry (audit link) |
| drawdownDate | date | Yes | Date of the underlying time entry |
| hoursOrAmount | number | Yes | Hours consumed (or fixed amount when pool.retainerRateUnit=fixed) |
| drawdownRate | number | Yes | Snapshot of pool retainerRate at materialization (immutable) |
| drawdownAmount | number | Yes | hoursOrAmount × drawdownRate (multipleOf 0.01) |
| administrationId | string | Yes | FK to administration |
| status | enum | Yes | pending / materialized / reversed / adjusted |
| reversalOfDrawdownId | string | No | FK to prior drawdown this record reverses (audit chain) |
| reversalReason | string | No | Free-text audit reason |
| description | string | No | Cached time-entry description |

**Relations:**
- → RetainerPool (many-to-one, via poolId)
- → TimeEntry (many-to-one, via timeEntryId)
- → RetainerDrawdown (many-to-one self-reference, via reversalOfDrawdownId)

### RetainerRollover
**Schema.org:** `schema:Action`
_Immutable carryover record between consecutive RetainerPools for the same (clientId, projectId) pair (REQ-RETN-004, REQ-RETN-009). Created on pool close, captures the unused balance, cap applied (if any), and whether resetBalance forced carryover to zero. Adjustments MUST create a new record (status=adjusted) referencing the original via adjustsRolloverId; original is never mutated._
**Primary spec:** retainer-billing-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rolloverId | string | Yes | Unique rollover identifier |
| sourcePeriodPoolId | string | Yes | FK to the closing RetainerPool |
| targetPeriodPoolId | string | No | FK to the next-period RetainerPool (null until next pool is created) |
| carryoverAmount | number | Yes | Amount carried forward in pool currency (multipleOf 0.01) |
| carryoverHours | number | No | Hours equivalent (= carryoverAmount / retainerRate); null when retainerRateUnit=fixed |
| carryoverCapApplied | boolean | No | True when unused balance exceeded the policy cap and was trimmed |
| capValueApplied | number | No | Cap value used when carryoverCapApplied=true |
| resetBalance | boolean | Yes | Mirrors policy at execution; true forces carryoverAmount=0 |
| administrationId | string | Yes | FK to administration |
| status | enum | Yes | planned / executed / adjusted / archived |
| adjustsRolloverId | string | No | FK to a prior rollover this record adjusts (audit chain) |
| adjustmentReason | string | No | Free-text reason for adjustment |

**Relations:**
- → RetainerPool (many-to-one as sourcePeriodPoolId; many-to-one as targetPeriodPoolId)
- → RetainerRollover (many-to-one self-reference, via adjustsRolloverId)

### RetainerTrueUp
**Schema.org:** `schema:Action`
_Period-end reconciliation record per RetainerPool (REQ-RETN-006). Auto-created by the period-close lifecycle hook on RetainerPool.transitions.close or by manual operator trigger (REQ-RETN-007). Captures actualDrawdown vs. poolAmount, resolved overage rate via rate-card-engine (REQ-RETN-005), and the resulting overageInvoiceAmount destined for an adjustment Invoice (REQ-RETN-008). Operator-driven lifecycle generated → pending-approval → approved → invoiced → settled, with reversed as an out-of-band correction state (REQ-RETN-007). Approvals require the retainer:approve-true-up permission per ADR-023 (REQ-RETN-011)._
**Primary spec:** retainer-billing-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| trueUpId | string | Yes | Unique true-up identifier |
| poolId | string | Yes | FK to the closing RetainerPool |
| actualDrawdown | number | Yes | Sum of RetainerDrawdown.drawdownAmount over the pool period |
| poolAmount | number | Yes | Snapshot of RetainerPool.poolAmount at true-up (immutable) |
| overageAmount | number | Yes | max(0, actualDrawdown - poolAmount) |
| overageRate | number | No | Standard rate resolved from rate-card-engine (REQ-RETN-005); null when no overage |
| overageInvoiceAmount | number | Yes | Overage converted from retainer-rate hours into standard-rate billing amount |
| underUtilisationAmount | number | Yes | max(0, poolAmount - actualDrawdown) — for credit-note workflows |
| administrationId | string | Yes | FK to administration |
| status | enum | Yes | generated / pending-approval / approved / invoiced / settled / reversed |
| generatedAt | datetime | Yes | Timestamp at true-up creation |
| generatedBy | string | No | Operator UID that triggered (or 'system' for auto-close) |
| approvedBy | string | No | UID of approver who advanced generated → approved |
| approvalDate | datetime | No | Timestamp at approval |
| invoiceId | string | No | FK to the generated adjustment Invoice once status=invoiced |
| reversalOfTrueUpId | string | No | FK to a prior true-up this record reverses (audit chain) |
| reversalReason | string | No | Free-text reason for reversal/adjustment |
| manualTriggerReason | string | No | Free-text reason when triggered manually after a missed auto-close |

**Relations:**
- → RetainerPool (many-to-one, via poolId)
- → Invoice (one-to-one, via invoiceId — adjustment invoice from REQ-RETN-008)
- → Person / NC User (many-to-one, via approvedBy — REQ-RETN-011 approver)
- → RetainerTrueUp (many-to-one self-reference, via reversalOfTrueUpId)
- ← rate-card-engine.RateSchedule (overage-rate lookup, REQ-RETN-005)

### Receipt
**Schema.org:** `schema:DigitalDocument`
_Digital document storing scanned receipts, invoices, or proof of transaction for audit trail and digital archiving._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentType | enum | Yes | Type of document stored |
| fileName | string | Yes | Original filename as uploaded |
| encodingFormat | string | Yes | MIME type (e.g., application/pdf, image/jpeg) |
| contentSize | number | Yes | File size in bytes |
| uploadDate | datetime | Yes | Date and time document was uploaded |
| documentDate | date | No | Date on the receipt or document itself |
| description | string | No | Notes about the document or extraction notes |

**Relations:**
- → APTransaction (many-to-one)

> **Reconciliation note (expense-capture-core, 2026-06-03):** The existing `Receipt` entry (primary spec: accounts-payable-receivable) is a generic document-attachment schema (fileName, encodingFormat, contentSize) linked to APTransaction. The expense-capture-core `Receipt` schema in `lib/Settings/shillinq_register.json` is the shillinq expense-capture receipt with amount, currency, multi-currency conversion, category, vendorName, and FK to `ExpenseClaimEntry`. The two schemas serve different purposes — the AP receipt is a document reference; the expense receipt is a financial entry. Implementations MUST use the AP-linked schema for AP document attachments and the expense-capture schema for employee expense receipts.

### Report
**Schema.org:** `schema:Report`
_Schema.org Report — standard vocabulary for report data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type (financial, compliance, etc.) |
| period | string | No | Reporting period |
| generatedAt | datetime | No | When the report was generated |

### RequestForQuotation
**Schema.org:** `schema:Quotation`
_Request for quotation supporting RFx management with templated events, multi-round negotiations, and digital lockbox_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rfqNumber | string | Yes | Unique RFQ identifier |
| title | string | Yes | RFQ title or description |
| deadline | datetime | Yes | Submission deadline for responses |
| round | number | Yes | Negotiation round number |
| status | string | Yes | Status: draft, published, closed, awarded, cancelled |
| lockboxEnabled | boolean | Yes | Enable digital lockbox to prevent bid viewing before deadline |
| estimatedValue | number | No | Estimated procurement value |
| createdDate | datetime | Yes | RFQ creation date |

**Relations:**
- → Organization (many-to-one)
- → Payee (many-to-many)
- → Offer (one-to-many)

### RevenueStream
**Schema.org:** `schema:Offer`
_A categorized source or type of revenue for tracking income by origin and supporting revenue management analysis._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| streamName | string | Yes | The name of the revenue source |
| category | string | Yes | Revenue classification (e.g., product sales, service fees, licensing) |
| currency | string | Yes | ISO 4217 currency code |
| annualTarget | object | No | Target revenue as {value, currency} following MonetaryAmount schema |
| isActive | boolean | No | Whether this revenue stream is currently active |

**Relations:**
- → JournalEntry (one-to-many)

### RiskCriteria
_Weighted assessment criteria for dynamic risk scoring and evaluation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| criteriaName | string | Yes | Name of assessment criteria |
| criteriaType | string | Yes | Type: financial, operational, compliance, behavioral |
| weight | decimal | Yes | Weight in assessment (0.0-1.0, normalized across criteria set) |
| threshold | decimal | Yes | Threshold value for this criteria (e.g., days overdue) |
| description | string | No | Criteria definition and calculation method |
| riskLevel | string | No | Risk level if threshold breached: low, medium, high |
| active | boolean | Yes | Whether criteria is active in scoring |

### Role
_Collection of permissions defining access level and capabilities within the system_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Unique role name |
| description | string | No | Role description and purpose |
| isSystemRole | boolean | No | Whether this is a built-in system role |
| level | number | No | Role hierarchy level for permission evaluation |
| isActive | boolean | Yes | Whether the role is active |

**Relations:**
- → Permission (many-to-many)
- → User (many-to-many)

### SavingsOpportunity
**Schema.org:** `schema:Thing`
_A tracked initiative to reduce spending with projected and realized savings amounts for portfolio management_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the savings opportunity or initiative |
| description | string | No | Detailed description of the savings initiative |
| projectedSavings | number | Yes | Expected annual savings amount in currency units |
| realizedSavings | number | No | Actual savings achieved to date |
| startDate | datetime | Yes | When the initiative started or is planned to start |
| completionDate | datetime | No | Expected or actual completion date |
| status | string | Yes | Status: pipeline, active, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → ExpenseCategory (many-to-one)

### ScheduledPayment
**Schema.org:** `schema:Payment`
_Payment scheduled for future execution with support for recurring transactions_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentReference | string | Yes | Unique payment reference or confirmation number |
| amount | number | Yes | Payment amount |
| currency | string | Yes | Currency code (ISO 4217) |
| scheduledDate | datetime | Yes | Date payment is scheduled for execution |
| frequency | string | No | Recurrence frequency: once, daily, weekly, monthly, yearly |
| recurringEndDate | datetime | No | End date for recurring payments |
| status | string | Yes | Status: pending, approved, executed, failed, cancelled |
| lastExecutionDate | datetime | No | Date of last payment execution |

**Relations:**
- → Payee (many-to-one)
- → BankAccount (many-to-one)
- → Payment (one-to-many)

### ServiceCategoryOverride
**Schema.org:** `schema:Permit`
_Append-only register capturing per-administration exceptions to the default REQ-VAT-002 service-category / VAT-rate matrix. Lookup by (administrationId, serviceCategory, vatRate); the ARInvoice.issue lifecycle precondition (REQ-VAT-002) consults this register before rejecting a non-default combination per REQ-VAT-002bis. createdAt + createdBy are immutable once written; reason is the operator-authored audit-trail text retained for Belastingdienst inspection._
**Primary spec:** bookkeeping-invoice-vat-kassakoppeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the administration owning the override |
| serviceCategory | enum | Yes | product, service, or exempt — category the override applies to |
| vatRate | integer enum | Yes | 21, 9, 6, or 0 — the rate the override permits for this category |
| reason | string | Yes | Free-text audit reason (retained for Belastingdienst inspection) |
| createdAt | datetime | Yes | Set on creation; immutable |
| createdBy | string | Yes | Nextcloud user id of the creator; immutable |

**Relations:**
- → Administration (many-to-one)
- → VATAuditRecord (one-to-many; each VATAuditRecord captures `overrideId` when this override authorised the line)

**Cites:** ADR-022 (immutable audit), ADR-031 (declarative-only).

### Service
**Schema.org:** `schema:Service`
_A bookable service offering — the foundational data-model for all scheduling, booking and appointment workloads (REQ-SC-001..008 of `bookings-service-catalog`). Carries identification (`serviceId`, stable per-administration `code` slug, `name`, `description`), temporal dimensions (`duration`, `prepareTime`, `bufferBefore`, `bufferAfter` — all in minutes; total calendar block = `prepareTime + duration + bufferAfter`), pricing (`basePrice` with two-decimal precision + `currency` ISO-4217 + `dynamicPricing` flag for T2+ rule engines), categorisation (`serviceCategory` flat string per REQ-SC-006, `resourceTypeRef` forward FK per REQ-SC-007), and lifecycle (`status`: draft → active → archived per REQ-SC-005). Code uniqueness is per-administration (REQ-SC-008). `dynamicPricing: true` signals downstream consumers that `basePrice` is a fallback only. `resourceTypeRef` resolution (skill/room/staff specialty/equipment class) is owned by dependent specs (`bookings-resource-calendar`, `bookings-availability-rules`). `duration: 0` is valid for non-scheduled services (subscriptions, products). Schema is declared declaratively in `lib/Settings/register.d/bookings-service-catalog.json` per ADR-031 + ADR-037; no `ServiceService` PHP class — validation/uniqueness/lifecycle/aggregations are expressed as `x-openregister-*` metadata._
**Primary spec:** bookings-service-catalog

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| serviceId | string | Yes | Operator-assigned unique service identifier within the administration |
| code | string | Yes | Stable per-administration slug (`^[a-z0-9][a-z0-9-]*$`); MUST NOT change once assigned (REQ-SC-008) |
| name | string | Yes | Human-readable service name |
| description | string | No | Detailed service description for UI |
| duration | integer ≥ 0 | Yes | Service duration in minutes; 0 for non-scheduled services |
| prepareTime | integer ≥ 0 | Yes | Setup time in minutes applied before the service window (default 0) |
| bufferBefore | integer ≥ 0 | Yes | Gap in minutes required before the service starts (default 0) |
| bufferAfter | integer ≥ 0 | Yes | Gap in minutes after the service ends for turnover (default 0) |
| basePrice | decimal ≥ 0 | Yes | Base unit price (multipleOf 0.01); final price when `dynamicPricing = false` |
| currency | string | Yes | ISO 4217 currency code (default EUR) |
| dynamicPricing | boolean | Yes | When true, dependent T2+ pricing specs MUST compute the actual price (default false) |
| serviceCategory | string | Yes | Flat category grouping (e.g. "Hair Services") |
| resourceTypeRef | string | No | Forward FK to resource-type concept owned by dependent specs |
| status | enum | Yes | One of draft, active, archived (REQ-SC-005) |

**Relations:**
- → Administration (many-to-one, via administrationId)
- ← Appointment (one-to-many, via Appointment.serviceId — bookings-create-appointment)

### ServiceLevelAgreement
**Schema.org:** `schema:Service`
_Formal agreement defining service level targets, performance expectations, and remedies with a supplier_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| slaId | string | Yes | Unique SLA identifier |
| slaName | string | Yes | SLA name or title |
| description | string | No | Detailed SLA description |
| serviceMetric | string | Yes | Metric being measured (e.g., Response Time, Availability, Uptime) |
| targetLevel | string | Yes | Target service level (e.g., 99.5%, <4 hours) |
| acceptablePenalty | string | No | Consequence of non-compliance |
| effectiveDate | datetime | Yes | SLA effective date |
| expiryDate | datetime | No | SLA expiration date |
| status | string | Yes | Status (draft, active, expired, terminated) |

**Relations:**
- → Organization (many-to-one)

### SettlementDecision
**Schema.org:** `schema:DigitalDocument`
_Formal decision to finalize and mark one or more obligations as settled, issued by authorized personnel_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decisionNumber | string | Yes | Unique decision identifier |
| decisionDate | date | Yes | Date decision was issued |
| issuedBy | Person | Yes | Authorized person who issued the decision |
| totalSettledAmount | MonetaryAmount | Yes | Total financial amount being settled |
| obligationCount | integer | No | Number of obligations included in settlement |
| decisionRationale | string | No | Reason or basis for settlement decision |
| documentUrl | string | No | Reference to decision document or file |

**Relations:**
- → Obligation (one-to-many)
- → ComplianceReport (many-to-one)

### Share
**Schema.org:** `schema:Product`
_Represents an ownership stake in a corporation. Tracks share quantity, type, nominal value, and acquisition date for investment tracking across multi-entity portfolio._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| shareNumber | string | Yes | Unique share class or certificate identifier |
| quantity | integer | Yes | Number of shares held |
| shareType | string | Yes | Share category: common, preferred, or founder shares |
| nominalValue | decimal | Yes | Nominal value per share in EUR |
| totalInvestmentAmount | decimal | Yes | Total investment in EUR (quantity × nominalValue) |
| acquisitionDate | date | Yes | Date shares were acquired or issued |
| votingRights | string | No | Voting rights status: full, limited, or none |

**Relations:**
- → Shareholder (many-to-one)
- → Corporation (many-to-one)

### Shareholder
**Schema.org:** `schema:Person`
_Person or organization holding ownership shares in one or more corporations. Tracks investors across the multi-entity portfolio._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | Given name (for individuals) |
| familyName | string | Yes | Family name (for individuals) |
| companyName | string | No | Organization name (for corporate shareholders) |
| email | string | No | Email address for shareholder contact |
| telephone | string | No | Telephone number for shareholder contact |
| shareholderType | string | Yes | Type: individual, organization, or foundation |
| residenceAddress | string | No | Residential or business address |

**Relations:**
- → Share (one-to-many)
- → Corporation (many-to-many)

### SigningAuthority
**Schema.org:** `schema:Person`
_Delegation of signing rights to a specific person with defined scope and limits_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| authorityNumber | string | Yes | Unique identifier for this signing authority |
| holderId | string | Yes | ID of person holding signing authority |
| signingScope | string | Yes | Types of documents/transactions: invoices, contracts, cheques, all |
| signingLimit | decimal | No | Maximum amount per transaction |
| currency | string | Yes | ISO 4217 currency code |
| validFrom | date | Yes | When this authority becomes effective |
| validThrough | date | No | When this authority expires |
| delegatedBy | string | Yes | ID of authorized representative or director |
| signatureMethod | string | No | Signature method: handwritten, digital, both |

**Relations:**
- → Mandate (many-to-one)

### SisaRegelingIndicator
**Schema.org:** `schema:Report`
_Per-regeling indicator for the annual SiSa (Single Information Single Audit) bijlage at jaarrekening. Attaches to a Subsidie of subtype specifieke-uitkering via `subsidieId` FK per ADR-022 child-register pattern — no parallel SiSa subsidie register. Records carry `regelingCode` (BZK regeling identifier, e.g. `D8`), `indicatorCode` (per controleprotocol, e.g. `D8.01`), `indicatorWaarde`, `indicatorEenheid`, `peilDatum`, `controleprotocol` version, and `fiscalYear`. Grouped by `(regelingCode, controleprotocol)` via `x-openregister-aggregations` to produce the annual SiSa-bijlage per REQ-SISA-003. Seeded from `lib/Settings/seeds/sisa-controleprotocol-2026.json` when `featureFlags.gov-sisa` is enabled per REQ-SISA-002. BZK submission rides the openconnector source `bzk-sisa-upload-2026`; every submission writes an immutable audit event of type `sisa.submitted` linked to the parent jaarrekening via the audit-trail hash chain per REQ-SISA-004 and REQ-SISA-005. See `openspec/changes/add-shillinq-sisa-reporting/specs/bookkeeping-sisa-reporting/spec.md` for the full requirement set and GIVEN/WHEN/THEN scenarios._
**Primary spec:** bookkeeping-sisa-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subsidieId | string | Yes | FK to parent Subsidie record (subtype specifieke-uitkering) |
| regelingCode | string | Yes | BZK regeling identifier (e.g. D8) |
| indicatorCode | string | Yes | Indicator code per controleprotocol (e.g. D8.01) |
| indicatorOmschrijving | string | Yes | Human-readable indicator description from controleprotocol |
| indicatorWaarde | number or string | Yes | Reported indicator value for the fiscal year |
| indicatorEenheid | string | No | Unit of the indicator value (e.g. personen, euro, %) |
| peilDatum | date | Yes | Reference date (peildatum) for indicator measurement |
| controleprotocol | string | Yes | Version of the BZK SiSa controleprotocol (e.g. 2026) |
| fiscalYear | integer | Yes | Fiscal year for which the indicator is reported |
| administrationId | string | Yes | FK to Administration owning this indicator |
| sisaSubmissionStatus | enum | No | One of: not-submitted, submitted, accepted, rejected |
| bijlageDocumentUri | string | No | docudesk URI of the generated SiSa-bijlage document |
| submissionAuditEventId | string | No | FK to the immutable audit event written on BZK submission |

**Relations:**
- → Subsidie (many-to-one, via subsidieId — child register of T3 specifieke-uitkering)

> **Note (add-shillinq-sisa-reporting, 2026-06-03):** `SisaRegelingIndicator` is the T4-specialized entity for the annual SiSa-bijlage per BZK controleprotocol. It attaches to the existing T3 `Subsidie` register as a child via FK (ADR-022 D1); no parallel SiSa subsidie register exists. The bijlage is produced as a declarative `x-openregister-aggregations` declaration — no PHP SiSa-bijlage service (ADR-031). Controleprotocol indicators are seeded from `sisa-controleprotocol-2026.json`; the seed is version-pinned so the 2027 release ships as `sisa-controleprotocol-2027.json` alongside the existing file.

### SisaReport
**Schema.org:** `schema:Report`
_Single Information Single Audit (SiSa) compliance report per fiscal year for a Dutch government administration. Aggregates transaction counts, on-time settlement %, audit findings from ComplianceAuditTrail, and overall audit opinion (unqualified/qualified/adverse/disclaimer) per REQ-SISA-001 and REQ-SISA-002._
**Primary spec:** bookkeeping-sisa-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique report identifier per administration |
| fiscalYear | integer | Yes | Fiscal year covered by this SiSa report |
| administrationId | string | Yes | FK to Administration |
| reportDate | datetime | Yes | Date the report was generated or finalised |
| totalTransactionCount | integer | No | Total number of financial transactions in the fiscal period |
| onTimeSettlementPercent | number | No | Percentage of obligations settled by due date (0–100) |
| totalAmount | number | No | Total financial value of all transactions |
| currency | string | Yes | ISO 4217 currency code (EUR) |
| criticalFindingsCount | integer | No | Count of critical-severity audit findings |
| majorFindingsCount | integer | No | Count of major-severity audit findings |
| minorFindingsCount | integer | No | Count of minor-severity audit findings |
| observationsCount | integer | No | Count of governance observations |
| remediationOverdueCount | integer | No | Count of overdue remediation actions |
| auditOpinion | enum | Yes | One of: unqualified, qualified, adverse, disclaimer |
| managementLetterId | string | No | FK to ManagementLetter record |
| complianceStatus | enum | Yes | One of: compliant, non-compliant, under-review |
| lifecycleState | enum | Yes | One of: draft, finalized, submitted, archived |
| submissionDate | datetime | No | Date report submitted to the relevant authority |

**Relations:**
- → Administration (many-to-one)
- → ComplianceAuditTrail (one-to-many, aggregated per administrationId + fiscalYear)
- → ManagementLetter (one-to-one, via managementLetterId)

> **Deduplication note:** `ComplianceReport` (primary spec: obligation-financial-administration) tracks obligation settlement compliance metrics. `SisaReport` is SiSa-specific with fiscal-year + audit-opinion + on-time-settlement aggregations. They coexist; if they converge, a T2 consolidation change will merge them with a migration step.

### SourcingEvent
**Schema.org:** `schema:Event`
_Sourcing event (RFQ, RFP, RFI) with supplier invitation and response tracking_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eventId | string | Yes | Unique sourcing event identifier |
| eventType | string | Yes | Type of sourcing event: RFQ, RFP, or RFI |
| eventName | string | Yes | Title or name of the sourcing event |
| description | string | No | Detailed description of requirements and scope |
| releaseDate | datetime | Yes | Date the sourcing event is released to suppliers |
| deadline | datetime | Yes | Response submission deadline |
| status | string | Yes | Event status: draft, published, closed, or awarded |
| estimatedBudget | number | No | Estimated budget for the sourcing opportunity |

**Relations:**
- → Supplier (many-to-many)
- → PurchaseOrder (one-to-one)
- → Document (one-to-many)

### SpendCategory
**Schema.org:** `schema:Thing`
_Hierarchical category for organizing and analyzing supplier spending by type and business function_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| categoryId | string | Yes | Unique category identifier |
| name | string | Yes | Category name (e.g., IT Services, Maintenance, Staffing) |
| description | string | No | Category description |
| parentCategoryId | string | No | Parent category ID for hierarchical organization |
| level | number | No | Hierarchical level in category tree |
| status | string | Yes | Status (active, inactive, archived) |

### SpendTransaction
**Schema.org:** `schema:Order`
_Purchase order and transaction tracking for spend analytics_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | date | Yes | Date order was placed |
| invoiceNumber | string | No | Associated invoice number |
| totalPrice | number | Yes | Total transaction amount |
| currency | string | Yes | Currency code (EUR) |
| category | string | Yes | Spend category for analytics |
| deliveryDate | date | No | Actual or expected delivery date |
| deliveryOnTime | boolean | No | Whether delivered per SLA target |
| paymentStatus | string | Yes | Payment status (pending/paid/overdue) |

**Relations:**
- → Supplier (many-to-one)

### SpendingRecord
**Schema.org:** `schema:Order`
_Individual spending transaction for government transparency and audit compliance_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | Unique transaction identifier |
| transactionDate | date | Yes | Date of spending transaction |
| amount | number | Yes | Transaction amount in decimal format |
| currency | string | Yes | Currency code (EUR) |
| vendorName | string | Yes | Name of vendor or service provider |
| category | string | Yes | Spending category: personnel, operations, investment, or services |
| approvalStage | string | Yes | Current approval stage: draft, submitted, approved, or rejected |
| documentUri | string | No | Reference URI to supporting documentation |

**Relations:**
- → FundAllocation (many-to-one)
- → GovernmentEntity (many-to-one)
- → SubmissionDossier (many-to-one)

### StatementOfWork
**Schema.org:** `schema:CreativeWork`
_Detailed specification of deliverables, milestones, payment terms, and service scope for statement-of-work-based procurement and service ordering_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sowNumber | string | Yes | Unique SOW identifier |
| sowDate | datetime | Yes | Date SOW was created |
| title | string | Yes | SOW title |
| description | string | No | Detailed description of work |
| scope | string | No | Work scope and boundaries |
| deliverables | array | No | Array of deliverable items with descriptions and due dates |
| milestones | array | No | Payment milestone objects with completion dates and invoice triggers |
| totalValue | number | Yes | Total SOW value |
| currency | string | Yes | Currency code |
| status | string | Yes | draft, active, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)
- → Contract (many-to-one)
- → PurchaseOrder (one-to-many)

### StockMove
**Schema.org:** `schema:Event`
_Immutable double-entry stock-movement ledger entry (Odoo / Tryton Stock Move pattern). One row atomically debits a source location and credits a destination location for a single (item + quantity + unit cost) tuple. Five movement types — receipt (null sourceLocationId), transfer (both populated, no GL), issue (null destinationLocationId, posts COGS), manufacture (assembly), repack (consolidation)._
**Primary spec:** inventory-stock-movement-ledger

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| movementNumber | string | Yes | Sequential SM-YYYY-NNNN, unique per (administrationId, movementNumber) per REQ-SM-002 |
| itemId | string (FK) | Yes | FK to Product.sku (inventory-product-catalog). Spec text refers to the catalogue entity as "Item" |
| quantity | number ≥ 0 (multipleOf 0.01) | Yes | Units moved; direction encoded by source/destination nullability + movementType |
| unitCost | number ≥ 0 (multipleOf 0.01) | Yes | Cost per unit in administration base currency; GL amount = quantity * unitCost per REQ-SM-006 |
| movementType | enum | Yes | One of receipt / transfer / issue / manufacture / repack per REQ-SM-002 |
| sourceLocationId | string (FK) | No | FK to Location.id; null for receipt; differs from destination when both present |
| destinationLocationId | string (FK) | No | FK to Location.id; null for issue; differs from source when both present |
| referenceDocumentUri | string (URI) | No | PO (receipt), sales order (issue), production plan (manufacture); docudesk:// URIs accepted |
| movementReason | enum | Yes | Standard set (normal, damaged, expired, shrinkage, inter-warehouse, adjustment, sample, demo, theft, loss, cancellation, manufacture, repack) unioned with per-administration custom codes; mandatory on post per REQ-SM-007 |
| notes | string | No | Operator notes; offset rows carry "Offset for <original.movementNumber>" |
| draftedAt | datetime | Yes | Stamped on create |
| postedAt | datetime | No | Stamped on draft → posted |
| cancelledAt | datetime | No | Stamped on cancel |
| administrationId | string (FK) | Yes | FK to Administration; tenant scope per REQ-MA-001 |
| locked | boolean | No (default false) | true on posted; edits to load-bearing fields rejected with HTTP 409 per REQ-SM-003 |
| glTransactionId | string (FK) | No | Back-reference to materialised GLTransaction (receipt/issue/manufacture only) |
| offsetOfMoveId | string (FK) | No | When this row is a cancellation offset, FK to the original posted StockMove per REQ-SM-003 |
| lifecycleState | enum | Yes | One of draft / posted / cancelled per REQ-SM-003 |

**Hierarchy / invariants (per REQ-SM-002 + REQ-SM-003 + REQ-SM-005):**
- Receipt: sourceLocationId MUST be null; destinationLocationId MUST be present.
- Issue: destinationLocationId MUST be null; sourceLocationId MUST be present.
- Transfer / manufacture / repack: both locations populated; sourceLocationId ≠ destinationLocationId.
- Posted moves are immutable (`locked = true`); cancellation creates an offsetting StockMove with swapped locations rather than patching the original.
- `InventoryStock.quantity` reconciles to `initialStock + SUM(destination posted moves) - SUM(source posted moves)`, excluding cancelled — verifiable via `StockLedgerService::quantityForLocation`.

**Declarative extensions (ADR-031):**
- `x-openregister-lifecycle`: draft → posted → cancelled; post stamps postedAt+locked, runs commit-stock-reservation + materialise-gl-transaction (receipt/issue/manufacture); cancel forks on @previous (draft-cancel releases reservation; posted-cancel runs StockMoveOffsetCreator).
- `x-openregister-aggregations`: netQuantityForLocation (REQ-SM-005), reservedQuantityForLocation (REQ-SM-009), movesByType (REQ-SM-008).
- `x-openregister-indexes`: (administrationId, sourceLocationId, itemId, lifecycleState), (administrationId, destinationLocationId, itemId, lifecycleState), unique (administrationId, movementNumber).
- Guards (ADR-031 exception path): `StockMoveReasonGuard::requireReasonOnPost`, `StockMoveImmutabilityGuard::rejectLockedEdit`/`canCancel`, `StockReservationGuard::reserveReservation`/`commitReservation`/`releaseReservation`, `StockMoveOffsetCreator::emitOffset`.

**Relations:**
- → Product (many-to-one, via itemId)
- → Location (many-to-one, sourceLocationId — null for receipt)
- → Location (many-to-one, destinationLocationId — null for issue)
- → GLTransaction (one-to-one, via glTransactionId — receipt/issue/manufacture only)
- → StockMove (many-to-one self-relation, via offsetOfMoveId — cancellation offset pair)
- → Organization (many-to-one, via administrationId)

> **Annotation (inventory-stock-movement-ledger, 2026-06-07):** Lifecycle, GL materialisation, reservation CAS, audit trail and aggregations are all declarative per ADR-031 — no `StockMoveService` was added. Four ADR-031 exception-path guards live in `lib/Lifecycle/` for the predicates the declarative DSL cannot yet express (mandatory reason on post, locked-edit rejection, optimistic-lock CAS, posted-cancel offset materialisation). Read-side drill-down is served by `StockLedgerService` + `GET /api/stock-ledger/trace`; the engine never SUMs cents floats — all arithmetic is integer-cent via `multipleOf 0.01` schema discipline.

> **Annotation (inventory-cycle-count, 2026-06-07):** Reused without modification. `movementReason` enum extended additively with `cycle-count-variance` so REQ-ICC-007 variance posting flows through the existing lifecycle (InventoryStock + GL materialisation) untouched. The originating `countId` is preserved in `referenceDocumentUri` as `shillinq://cycle-count/<countId>` for audit trace-back. See InventoryCycleCount above for the snapshot + adjustment fan-out service.

### SubmissionDossier
**Schema.org:** `schema:DigitalDocument`
_Council submission dossier aggregating spending records and compliance documentation for public sector reporting_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Dossier title or reference name |
| dossierType | string | Yes | Type: annual report, quarterly report, audit submission, or grant report |
| submissionDate | date | Yes | Planned or actual submission date to council |
| completionPercentage | integer | Yes | Completion status as percentage (0-100) |
| contentSummary | string | No | Summary of dossier contents and key figures |

**Relations:**
- → GovernmentEntity (many-to-one)
- → SpendingRecord (one-to-many)

### Subscription
**Schema.org:** `schema:Offer`
_Recurring subscription arrangement with plan and quantity tracking for billing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subscriptionNumber | string | Yes | Unique subscription identifier |
| planName | string | Yes | Name of subscription plan |
| quantity | number | Yes | Quantity of units in subscription |
| startDate | datetime | Yes | Subscription start date |
| endDate | datetime | No | Subscription end date |
| amount | number | Yes | Recurring billing amount |
| frequency | string | Yes | Billing frequency (monthly, quarterly, yearly) |
| status | string | Yes | Subscription status |

**Relations:**
- → Organization (many-to-one)
- → Product (many-to-one)
- → Invoice (one-to-many)

### SubsidyApplication
**Schema.org:** `schema:Application`
_An application for a subsidy or grant under a specific subsidy scheme with supporting documentation_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| applicationId | string | Yes | Unique application identifier |
| requestedAmount | number | Yes | Requested grant amount |
| status | string | Yes | Application status: draft, submitted, under-review, approved, rejected |
| submissionDate | datetime | No |  |
| reviewDate | datetime | No |  |
| notes | string | No |  |

**Relations:**
- → SubsidyScheme (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### Subsidie
**Schema.org:** `schema:Grant` (materialises as `schema:ResearchProject` when `subsidieRegeling ∈ {mit, sbir, eu-horizon, efro, react-eu}`)
_ASV-model subsidie register covering the full lifecycle (aanvraag → verleend → vastgesteld → uitbetaald → teruggevorderd → afgehandeld) for both outgoing and incoming grants. The `subsidieRegeling` overlay (bookkeeping-r-d-subsidies-mkb, 2026-06-01) extends the base register with an R&D regeling discriminator that selects per-regeling kostencategorieën constraints (REQ-RDS-002), voortgangsrapportage aggregations (REQ-RDS-003), budget-monitoring calculations (REQ-RDS-005), and docudesk audit-pack templates (REQ-RDS-004). No parallel RDSubsidie register; no PHP regeling-resolver service — all declared via x-openregister-* extensions per ADR-031._
**Primary spec:** bookkeeping-subsidie-verantwoording (base) + bookkeeping-r-d-subsidies-mkb (subsidieRegeling overlay)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration owning this subsidie record |
| direction | enum | Yes | outgoing (admin grants to beneficiary) or incoming (admin receives from granting body) |
| subsidieNumber | string | Yes | Unique subsidie reference per administration and tax year |
| counterpartyName | string | Yes | Beneficiary name (outgoing) or granting body name (incoming) |
| counterpartyId | string | No | Optional FK to a contact record |
| regelingNaam | string | Yes | Name of the underlying subsidie regeling |
| regelingArtikel | string | No | Specific article reference within the regeling |
| subsidieRegeling | enum | No | R&D regeling discriminator: mit, sbir, eu-horizon, efro, react-eu, other (null for non-R&D subsidies) |
| aanvraagDate | date | Yes | Date of the subsidie aanvraag |
| beschikkingDate | date | No | Date of the verleningsbeschikking |
| vaststellingDate | date | No | Date of the vaststellingsbeschikking |
| aangevraagdBedrag | number | Yes | Applied-for amount in EUR |
| verleendBedrag | number | No | Granted amount — set on the verleen transition |
| vastgesteldBedrag | number | No | Settled amount — set on the vaststel transition |
| uitbetaaldBedrag | number | No | Paid amount — set on the uitbetaal transition |
| teruggevorderdBedrag | number | No | Reclaimed amount — set on the terugvorder transition |
| state | enum | Yes | ASV-model lifecycle state: aanvraag, verleend, vastgesteld, uitbetaald, teruggevorderd, afgehandeld |
| beschikkingUri | string | No | Docudesk URI of the verleningsbeschikking PDF |
| vaststellingUri | string | No | Docudesk URI of the vaststellingsbeschikking PDF |
| prestatieverantwoording | string | No | Free-text prestatieverantwoording |
| repaymentPlanId | string | No | FK to a RepaymentInstallment parent record if a settlement plan applies |

**Relations:**
- → Kostenpost (one-to-many, via Kostenpost.subsidieId)

### Kostenpost
**Schema.org:** `schema:MonetaryAmount`
_An individual cost item within a subsidie's kostendossier. The allowed kostencategorie values are constrained per subsidieRegeling via JSON Schema if/then rules: MIT allows (personnel, materials, external-services, equipment-depreciation, other-direct); SBIR (personnel, materials, equipment-depreciation, other-direct); EU Horizon (personnel, subcontracting, other-direct, indirect-25-percent); EFRO (personnel, external-services, materials, equipment, other, indirect-flat-rate); REACT-EU (same as EFRO + green-recovery) per REQ-RDS-002. An invalid (subsidieRegeling, kostencategorie) combination fails at save time with a schema validation error. Per ADR-031, no PHP category validator. Cross-referencing spec: bookkeeping-r-d-subsidies-mkb (add-shillinq-r-d-subsidies-mkb, 2026-06-01)._
**Primary spec:** bookkeeping-r-d-subsidies-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subsidieId | string | Yes | FK to the parent Subsidie.id |
| subsidieRegeling | enum | Yes | Denormalized regeling discriminator from the parent Subsidie for schema-level kostencategorie validation |
| kostencategorie | string | Yes | Cost category; allowed values depend on subsidieRegeling |
| periodId | string | Yes | Reporting period identifier (e.g. 2026-Q1) for voortgangsrapportage grouping |
| amount | number | Yes | Kostenpost amount in EUR |
| currency | string | Yes | ISO 4217 currency code (default EUR) |
| description | string | No | Free-text description |
| attachmentUris | array | No | Docudesk or external URIs of supporting documents; EU Horizon personnel kostenpost MUST include S&O-uren-staat URI references per REQ-RDS-004 |

**Relations:**
- → Subsidie (many-to-one, via subsidieId)

### SubsidyScheme
**Schema.org:** `schema:GovernmentService`
_A government subsidy program defining eligibility criteria, award conditions, and funding framework_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| schemeId | string | Yes | Unique scheme identifier |
| name | string | Yes | Subsidy scheme name |
| description | string | No |  |
| maxGrant | number | No | Maximum grant amount |
| minGrant | number | No | Minimum grant amount |
| isPublished | boolean | No | Published to public portal |
| publishedDate | datetime | No |  |
| governmentLevel | string | No | national, provincial, or municipal |

**Relations:**
- → Organization (many-to-one)
- → Grant (one-to-many)

### Supplier
**Schema.org:** `schema:Organization`
_Master data for suppliers participating in bid evaluations and framework agreements_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official company legal name |
| tradeName | string | No | Commercial trading name |
| kvkNumber | string | Yes | Dutch Chamber of Commerce registration number |
| vatID | string | Yes | VAT identification number |
| email | string | Yes | Contact email address |
| telephone | string | No | Contact telephone number |
| url | string | No | Company website URL |
| iban | string | Yes | IBAN for payment processing |

**Relations:**
- → Person (one-to-many)

### SupplierBid
**Schema.org:** `schema:Offer`
_Supplier bid submitted for procurement evaluation with price, terms, and evaluation score_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Bid identifier or reference number |
| price | number | Yes | Bid amount offered |
| priceCurrency | string | Yes | Currency code (ISO 4217, e.g. EUR) |
| validFrom | date | Yes | Bid validity start date |
| validThrough | date | Yes | Bid validity expiration date |
| paymentTerms | string | No | Proposed payment terms (e.g., NET30) |
| deliverySchedule | string | No | Proposed delivery timeline or milestones |
| evaluationScore | number | No | Score assigned during automated evaluation |

**Relations:**
- → Supplier (many-to-one)
- → BidEvaluation (many-to-one)

### SupplierCertificate
**Schema.org:** `schema:Thing`
_Certification and compliance tracking for suppliers including ISO, safety, quality, and environmental certifications_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| certificateId | string | Yes | Unique certificate identifier |
| certificateType | string | Yes | Type of certification: ISO, safety, quality, environmental, etc. |
| certificationBody | string | No | Name of issuing certification organization |
| issuedDate | datetime | Yes | Date certificate was issued |
| expiryDate | datetime | No | Certificate expiration date |
| certificateNumber | string | No | Unique certificate number from issuing body |
| validationStatus | string | Yes | Current status: valid, expired, or revoked |

**Relations:**
- → Supplier (many-to-one)
- → Document (one-to-one)

### SupplierDocument
**Schema.org:** `schema:DigitalDocument`
_Certifications, licenses, insurance, and other supplier verification documents_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document or certificate name |
| documentType | string | Yes | Classification of document |
| description | string | No | Document details and contents |
| certificationBody | string | No | Issuing organization |
| issuanceDate | date | Yes | Issue date |
| expiryDate | date | No | Expiration or renewal date |
| encodingFormat | string | No | MIME type (e.g. application/pdf) |
| contentSize | integer | No | File size in bytes |
| verificationStatus | string | Yes | Verification approval status |

**Relations:**
- → Supplier (many-to-one)

### SupplierKPI
**Schema.org:** `schema:Thing`
_Key Performance Indicator definition for measuring supplier performance across delivery, quality, cost, and responsiveness categories_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| kpiId | string | Yes | Unique KPI identifier |
| name | string | Yes | KPI name (e.g., On-Time Delivery Rate, Quality Score) |
| description | string | No | Detailed description of the KPI |
| unitOfMeasure | string | Yes | Unit of measurement (%, days, count, score) |
| targetValue | number | Yes | Target or benchmark value |
| weight | number | No | Importance weighting (0-1) in aggregate scoring |
| category | string | Yes | KPI category (delivery, quality, cost, responsiveness, compliance) |
| status | string | Yes | Status (active, inactive) |

### SupplierPerformanceReport
**Schema.org:** `schema:Report`
_Aggregated supplier performance reporting for period analysis_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Report period (YYYY-MM format) |
| reportType | string | Yes | Fixed value: supplier-performance |
| generatedAt | date | Yes | Report generation date |
| averageScore | number | Yes | Average performance score (0-10) |
| onTimeDeliveryPercent | number | Yes | On-time delivery percentage (0-100) |
| qualityScore | number | Yes | Period quality score (0-10) |
| totalSpend | number | Yes | Total spend in period |
| transactionCount | integer | Yes | Number of transactions in period |
| recommendations | text | No | Performance improvement recommendations |

**Relations:**
- → Supplier (many-to-one)

### SupplierPerformanceScore
**Schema.org:** `schema:Offer`
_Multi-dimensional performance metrics for supplier evaluation_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scoringDate | date | Yes | Date score was calculated |
| overallScore | number | Yes | Overall performance score (0-10) |
| deliveryScore | number | Yes | On-time delivery score (0-10) |
| qualityScore | number | Yes | Product/service quality score (0-10) |
| responsivenessScore | number | Yes | Customer responsiveness score (0-10) |
| complianceScore | number | No | Contract/SLA compliance score (0-10) |
| scoringPeriod | string | Yes | Period covered (monthly/quarterly/annual) |

**Relations:**
- → Supplier (many-to-one)
- → SupplierSLA (many-to-one)

### SupplierPerformanceScorecard
**Schema.org:** `schema:AggregateRating`
_Comprehensive performance scorecard tracking supplier metrics against KPIs during a defined evaluation period_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scorecardId | string | Yes | Unique scorecard identifier |
| period | string | Yes | Evaluation period identifier (e.g., Q1-2024) |
| overallScore | number | No | Aggregate performance score (0-100) |
| startDate | datetime | Yes | Evaluation period start date |
| endDate | datetime | No | Evaluation period end date |
| status | string | Yes | Scorecard status (draft, active, completed, archived) |

**Relations:**
- → Organization (many-to-one)
- → PerformanceScore (one-to-many)

### SupplierPortalAccount
**Schema.org:** `schema:Thing`
_Self-service portal account for supplier profile management, document submission, and order visibility_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountId | string | Yes | Unique portal account identifier |
| username | string | Yes | Portal login username |
| accountStatus | string | Yes | Account status: active, inactive, or pending |
| lastLogin | datetime | No | Timestamp of most recent login |
| accessLevel | string | Yes | Portal access level: basic or full |
| emailNotification | boolean | Yes | Enable email notifications |
| twoFactorEnabled | boolean | Yes | Two-factor authentication enabled |

**Relations:**
- → Supplier (one-to-one)
- → Person (one-to-one)

### SupplierPortalUser
**Schema.org:** `schema:Person`
_Self-service portal account for supplier staff with profile management and access control_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | First name |
| familyName | string | Yes | Last name |
| email | string | Yes | Login email and notification address |
| jobTitle | string | No | Job title at supplier |
| accessLevel | string | Yes | Portal permission level |
| lastLoginDate | datetime | No | Last successful portal login |
| profileCompleteness | integer | No | Supplier profile completion percentage (0-100) |
| preferredLanguage | string | Yes | Portal interface language |

**Relations:**
- → Supplier (many-to-one)

### SupplierQualification
**Schema.org:** `schema:Document`
_UEA self-declaration for supplier qualification in EU procurement_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationNumber | string | Yes | Unique declaration reference number |
| declarationDate | datetime | Yes | Date of declaration submission |
| validUntil | datetime | Yes | Expiration date of qualification |
| declarationType | string | Yes | Type of declaration: UEA, ISO, other |
| status | string | Yes | Status: pending, approved, rejected, expired |

**Relations:**
- → Organization (many-to-one)
- → ComplianceDocument (one-to-many)

### SupplierRiskProfile
**Schema.org:** `schema:Organization`
_Supply chain risk profile with geographic positioning and compliance monitoring_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskScore | integer | Yes | Overall risk score (0-100) |
| geoLocation | string | Yes | Geographic coordinates (latitude,longitude) or address |
| country | string | Yes | ISO 3166 country code |
| complianceStatus | string | Yes | Compliance status: compliant, warning, non-compliant |
| paymentDefaultHistory | integer | No | Count of late/missed payments |
| lastAssessmentDate | date | No | Date of most recent risk assessment |
| creditLimit | decimal | No | Maximum credit exposure in EUR |
| geopoliticalRiskLevel | string | No | Geopolitical risk: low, medium, high |

**Relations:**
- → Organization (one-to-one)
- → Transaction (one-to-many)

### SupplierSLA
**Schema.org:** `schema:Offer`
_Service Level Agreement defining expected performance standards_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| slaNumber | string | Yes | Unique SLA identifier |
| description | string | Yes | SLA terms and conditions |
| deliveryTargetDays | integer | Yes | Target delivery time in days |
| qualityThresholdPercent | number | Yes | Minimum quality acceptance threshold (0-100%) |
| responseTimeHours | number | Yes | Target response time in hours |
| penaltyPercentage | number | No | Non-compliance penalty as % of invoice |
| validFrom | date | Yes | SLA effective date |
| validThrough | date | No | SLA expiration date |

**Relations:**
- → Supplier (many-to-one)

### SupplierSurvey
**Schema.org:** `schema:Survey`
_Assessment or feedback survey collecting quantitative and qualitative supplier performance data for evaluation and analysis_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| surveyId | string | Yes | Unique survey identifier |
| surveyName | string | Yes | Survey name or title |
| respondentScore | number | No | Quantitative score from respondent (0-100) |
| surveyDate | datetime | Yes | Date survey was completed |
| feedbackText | string | No | Qualitative feedback or comments |
| respondentName | string | No | Name of respondent |
| status | string | Yes | Status (draft, submitted, reviewed, approved) |

**Relations:**
- → Organization (many-to-one)
- → SupplierPerformanceScorecard (many-to-one)

### SupplyChainRisk
**Schema.org:** `schema:Thing`
_Supply chain risk monitoring including geopolitical and natural disaster impact assessment_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskType | string | Yes | geopolitical, natural-disaster, supplier-failure, regulatory, financial |
| severity | string | Yes | critical, high, medium, low |
| description | string | Yes |  |
| affectedCountries | array | No | ISO country codes |
| impactArea | string | No |  |
| geopoliticalFactors | object | No |  |
| naturalDisasterFactors | object | No |  |
| assessmentDate | datetime | Yes |  |
| nextReviewDate | datetime | No |  |
| status | string | Yes | identified, monitoring, escalated, resolved |

**Relations:**
- → Organization (many-to-one)

### TaxConfiguration
**Schema.org:** `schema:Thing`
_System-wide tax settings, rules, and thresholds for a specific jurisdiction and tax year_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| configId | string | Yes | Unique configuration identifier |
| taxYear | number | Yes | Tax year this configuration applies to |
| jurisdiction | string | Yes | Tax jurisdiction code (NL, UK, US, etc.) |
| effectiveDate | datetime | Yes | Date when this configuration becomes effective |
| description | string | No | Configuration description and compliance notes |

**Relations:**
- → Organization (many-to-one)
- → TaxRate (one-to-many)

### TaxDeclaration
**Schema.org:** `schema:Report`
_Primary tax declaration submission (VAT, BCF, exemptions). Aggregates tax lots and manages workflow from draft to submission._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationType | enum | Yes | BCF, VAT-NL, ICP, or other Dutch tax form type |
| taxYear | integer | Yes | Calendar or fiscal year (e.g. 2025) |
| declarationStatus | enum | Yes | draft, approved, submitted, acknowledged, rejected |
| totalTaxAmount | MonetaryAmount | Yes | Net tax liability or credit |
| submissionDate | date | No | Actual submission timestamp to authorities |
| businessTaxID | string | Yes | Taxpayer BSN/KVK or VAT ID |

**Relations:**
- → Organization (many-to-one)
- → TaxLot (one-to-many)
- → ExemptionCertificate (many-to-many)

### TaxEstimate
**Schema.org:** `schema:Table`
_Real-time annual income tax (IB) liability projection for Dutch ZZP freelancers. Materialized view consuming GL year-to-date snapshot and TaxRegimeConfiguration. Records calculation inputs (ytdIncome, glTransactionCount, configurationVersionId, snapshotDate) for audit traceability per D5. Superseded on each GL mutation; prior estimates retained immutably. No PHP TaxEstimationService — pure aggregation per ADR-031. Cross-referencing spec: `bookkeeping-zzp-tax-regime` (bookkeeping-zzp-tax-regime, 2026-06-01)._
**Primary spec:** bookkeeping-zzp-tax-regime

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the administration this estimate belongs to |
| fiscalYear | integer | Yes | Fiscal year for which the estimate projects annual liability |
| snapshotDate | date | Yes | Date through which GL transactions are included; operators see "estimate as of [date]" for GL lag awareness |
| configurationVersionId | string | Yes | FK to TaxRegimeConfiguration.versionId; enables retroactive comparison when rules change |
| glTransactionCount | integer | No | Count of GL transactions included; sanity check for GL completeness per REQ-TAX-009 |
| ytdTaxableIncome | number | Yes | YTD income from GL income categories (EUR) |
| ytdTaxableExpenses | number | Yes | YTD deductible expenses from GL expense categories (EUR) |
| ytdNetIncome | number | Yes | ytdTaxableIncome − ytdTaxableExpenses (EUR) |
| estimatedAnnualIncome | number | Yes | ytdTaxableIncome × (12 / months-elapsed) (EUR) |
| estimatedAnnualExpenses | number | Yes | ytdTaxableExpenses × (12 / months-elapsed) (EUR) |
| estimatedAnnualNetIncome | number | Yes | estimatedAnnualIncome − estimatedAnnualExpenses (EUR) |
| estimatedTaxableIncome | number | Yes | estimatedAnnualNetIncome after statutory allowances (EUR) |
| estimatedIncomeTax | number | Yes | estimatedTaxableIncome × configurationRate (EUR) |
| witholdingCredits | number | No | Accumulated withheld tax / advance payments (EUR) |
| estimatedNetLiability | number | Yes | estimatedIncomeTax − witholdingCredits (EUR; negative = refund due) |
| currency | string | Yes | ISO 4217 currency code (EUR) |
| status | enum | Yes | One of current, superseded |

**Relations:**
- → Administration (many-to-one)
- → TaxRegimeConfiguration (many-to-one, via configurationVersionId → versionId)
- → TaxSummaryReport (one-to-many, YTD aggregation source)
- → GLLine (one-to-many, underlying GL transactions included through snapshotDate)

### TaxExemption
**Schema.org:** `schema:Offer`
_Reusable exemption rule or policy: qualifies transactions or amounts as exempt. Linked to certificates and applied during tax lot calculation._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| exemptionCode | string | Yes | Statutory code (e.g. 021 for research) |
| exemptionName | string | Yes | Display name (e.g. 'Research & Development Exemption') |
| applicableTaxTypes | array | Yes | List of tax categories this exemption applies to (VAT, profit, withholding, etc.) |
| effectiveFrom | date | Yes | Start of exemption period |
| effectiveUntil | date | No | End of exemption period; null = ongoing |

**Relations:**
- → Organization (many-to-one)
- → ExemptionCertificate (many-to-one)

### TaxLossCarryForward
**Schema.org:** `schema:MonetaryAmount`
_Compensable tax loss per `jurisdiction` per `originatingYear`, tracked under the applicable Wet Vpb regime (pre-2019 6-year expiry, 2019–2021 transitional, 2022+ unlimited with the 50%-above-EUR-1M cap per REQ-DT-003). `remainingAmount` = `originalAmount` − `utilisedAmount` (declarative calculation). DTA recognition is operator-judged (not auto), and the `dtaRecoverabilityRationale` text field is required when a DTA is recognised — citing the projection horizon and `linkedProjections` per REQ-DT-004 / IAS 12 §34. All amounts integer euro cents (ADR-022)._
**Primary spec:** bookkeeping-deferred-tax

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| jurisdiction | string | Yes | ISO 3166-1 alpha-2 country code of the tax jurisdiction (REQ-DT-007) |
| originatingYear | integer | Yes | Fiscal year in which the loss originated (determines applicable regime per REQ-DT-003) |
| originalAmount | integer | Yes | Original loss amount in euro cents (positive integer) |
| utilisedAmount | integer | Yes | Cumulative amount utilised for offset in euro cents (positive integer) |
| remainingAmount | integer | Yes | Computed: `originalAmount − utilisedAmount` in euro cents |
| expirationYear | integer | No | Year the loss expires under the applicable regime; null for 2022+ unlimited regime |
| applicableRegime | enum | Yes | One of `pre-2019-6year`, `2019-2021-transition`, `2022-onwards` (REQ-DT-003 / Wet Vpb art. 20 & 20a) |
| dtaRecognised | integer | Yes | DTA recognised on this loss in euro cents (may be less than `remainingAmount × taxRate` if recoverability assessment limits recognition per REQ-DT-004) |
| dtaRecoverabilityRationale | string | No | Required text rationale for the recognised DTA percentage; cites the projection basis (REQ-DT-004) |
| recoverabilityHorizon | integer | No | Number of years over which recoverability is projected |
| linkedProjections | array | No | FK references to forecast records in `bookkeeping-budget-multi-year` supporting the recoverability assessment |
| administrationId | string | Yes | FK to the Administration this record belongs to |

**Relations:**
- → Administration (many-to-one)
- → VpbAangifte (logical, via the period's linked Vpb return that supplies the regime metadata)
- → BudgetForecast (many-to-many, via linkedProjections[]; optional T4 dependency)

**Cites:** ADR-022 (money rule, audit-trail), ADR-031 (declarative calculation of remainingAmount), ADR-037 (modular register fragment).

### TaxLot
**Schema.org:** `schema:MonetaryAmount`
_Individual tax line item: single transaction or aggregate category contributing to declaration. Tracks category, amount, and justification._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique identifier within declaration (e.g. VAT-001) |
| taxCategory | string | Yes | VAT standard/reverse/zero rate, profit, withholding, excise, etc. |
| amount | decimal | Yes | Gross or net tax amount |
| currency | string | Yes | EUR or other currency code |
| transactionDate | date | Yes | Date of underlying transaction or period start |
| description | string | No | Narrative or reference (e.g. invoice number, period) |

**Relations:**
- → TaxDeclaration (many-to-one)
- → BankAccount (many-to-one)

### TaxProvision
**Schema.org:** `schema:MonetaryAmount`
_Aggregated balance-sheet deferred-tax position per fiscal period per jurisdiction (REQ-DT-008 / IAS 12 §71–78 / RJ 272.413). Holds current-tax payable / prepaid, total DTA and DTL across all categories, the computed net DTA-DTL position, the saldering presentation choice (`gross` vs `net`) and the FK to the Vpb return for current-tax reconciliation (REQ-DT-010). One record per `(periodId, jurisdiction, administrationId)`. Netting is per-jurisdiction only and gated by the entity's legal right to offset (IAS 12 §71). All amounts integer euro cents (ADR-022)._
**Primary spec:** bookkeeping-deferred-tax

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | FK to FiscalPeriod (balansdatum) |
| jurisdiction | string | Yes | ISO 3166-1 alpha-2 country code (REQ-DT-007); one record per period per jurisdiction |
| currentTaxPayable | integer | Yes | Current Vpb payable for the period in euro cents (positive) |
| currentTaxPrepaid | integer | Yes | Vpb prepaid / voorheffing in euro cents (positive reduces net payable) |
| dtaTotal | integer | Yes | Total DTA across all categories in euro cents (positive); sourced from DeferredTaxMovement.closingBalance components |
| dtlTotal | integer | Yes | Total DTL across all categories in euro cents (positive); sourced from DeferredTaxMovement.closingBalance components |
| netDtaDtlPosition | integer | Yes | Computed: `dtlTotal − dtaTotal` in euro cents (positive = net liability) |
| presentationOnBalanceSheet | enum | Yes | One of `gross` (separate DTA and DTL lines) or `net` (combined). Per IAS 12 §71–78; gated by legal right to offset within the same jurisdiction (REQ-DT-008) |
| linkedVpbReturn | string | No | FK to the Vpb-aangifte record in `bookkeeping-vpb-mkb` for current-tax reconciliation (REQ-DT-010) |
| administrationId | string | Yes | FK to the Administration this record belongs to |

**Relations:**
- → FiscalPeriod (many-to-one, via periodId)
- → DeferredTaxMovement (one-to-many, contributes to dtaTotal / dtlTotal via closingBalance per category)
- → VpbAangifte (many-to-one, via linkedVpbReturn; T3 bookkeeping-vpb-mkb dependency)
- → Administration (many-to-one)

**Cites:** ADR-022 (money rule, audit-trail), ADR-031 (declarative netDtaDtlPosition calculation), ADR-037 (modular register fragment).

### TaxRate
**Schema.org:** `schema:Thing`
_Individual tax rate rules for income, sales, VAT, capital gains, or other tax types with effective date management_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rateId | string | Yes | Unique rate identifier |
| rateType | string | Yes | Type of tax: income, sales, vat, capital_gains, tds, gst, or other |
| percentage | number | Yes | Tax rate as percentage |
| effectiveDate | datetime | Yes | Date when this rate becomes effective |
| expiryDate | datetime | No | Date when this rate expires or is superseded |

**Relations:**
- → TaxConfiguration (many-to-one)
- → Product (many-to-one)

### TaxRateReconciliation
**Schema.org:** `schema:Report`
_Effective Tax Rate (ETR) reconciliation per period per jurisdiction (REQ-DT-006 / IAS 12 §81(c) / RJ 272 jaarrekening note). `statutoryTaxExpense`, `effectiveTaxExpense` and `effectiveTaxRate` are produced declaratively as `x-openregister-calculations` output (ADR-031, design D2 of the deferred-tax change) — no PHP report service. The `reconciliationItems[]` array bridges `statutoryTaxExpense = profitBeforeTax × statutoryRate` to `effectiveTaxExpense` through ordered `permanent`, `temporary`, `rate-change`, and `prior-year` items (each with a `taxEffect` in euro cents). One record per `(periodId, jurisdiction, administrationId)`. Marked `readonly: true` — the engine writes; the operator only reads. All monetary amounts integer euro cents; rates in basis points (1/10000, ADR-022)._
**Primary spec:** bookkeeping-deferred-tax

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | FK to FiscalPeriod (balansdatum) |
| jurisdiction | string | Yes | ISO 3166-1 alpha-2 country code (REQ-DT-007); one record per period per jurisdiction |
| profitBeforeTax | integer | Yes | Profit before income tax in euro cents per P&L (REQ-DT-006) |
| statutoryRate | integer | Yes | Blended statutory tax rate in basis points (1/10000); e.g. 2580 = 25.80% |
| statutoryTaxExpense | integer | Yes | Computed: `profitBeforeTax × statutoryRate / 10000` in euro cents |
| reconciliationItems | array | No | Ordered list of `{description, type, amount, taxEffect}`; `type` ∈ permanent / temporary / rate-change / prior-year (REQ-DT-006) |
| effectiveTaxExpense | integer | Yes | Computed: `statutoryTaxExpense + sum(reconciliationItems[].taxEffect)` in euro cents |
| effectiveTaxRate | integer | Yes | Computed: `effectiveTaxExpense × 10000 / profitBeforeTax` in basis points (0 when profitBeforeTax == 0) |
| disclosureNarrative | string | No | Free-form / structured narrative for jaarrekening note disclosure per RJ 272 / IAS 12 §81 |
| administrationId | string | Yes | FK to the Administration this record belongs to |

**Relations:**
- → FiscalPeriod (many-to-one, via periodId)
- → TemporaryDifference (logical, contributes via reconciliationItems[type=temporary])
- → DeferredTaxMovement (logical, contributes via reconciliationItems[type=rate-change])
- → Administration (many-to-one)

**Cites:** ADR-022 (money rule, audit-trail), ADR-031 (declarative calculations — readonly schema), ADR-037 (modular register fragment).

### TaxRegimeConfiguration
**Schema.org:** `schema:Thing`
_ZZP tax regime parameters: fiscal year, income tax rate, statutory allowances, filing deadline, and GL account → statutory category mapping rules. Configuration-driven per ADR-031 D2 and REQ-TAX-002; no hardcoded PHP mapping constants. Versioned (versionId) so TaxEstimate records can be retroactively recalculated when statutory rules change mid-year. Cross-referencing spec: `bookkeeping-zzp-tax-regime` (bookkeeping-zzp-tax-regime, 2026-06-01)._
**Primary spec:** bookkeeping-zzp-tax-regime

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the administration this configuration applies to |
| fiscalYear | integer | Yes | Fiscal year this configuration governs |
| regimeType | enum | Yes | One of zzp-sole-trader, partnership, cv |
| name | string | Yes | Human-readable configuration name |
| incomeTaxRate | number | Yes | Marginal income tax rate as decimal (e.g. 0.25 for 25%) |
| generalAllowance | number | No | General statutory allowance EUR (algemene heffingskorting equivalent) |
| soleTraderAllowance | number | No | Sole trader deduction EUR (zelfstandigenaftrek) if applicable |
| filingDeadline | date | Yes | Statutory filing deadline (e.g. 2027-04-20 for FY2026) |
| categoryMappingRules | object | Yes | JSON: GL account range → statutory tax category (e.g. "4000-4099" → "self-employment-income"); individual account keys take precedence over ranges per REQ-TAX-005 |
| allowanceAmounts | object | No | Per-category allowance overrides (e.g. { "business-expenses": 5000 }) |
| versionId | string | Yes | Semantic version enabling retroactive recalculation (e.g. "zzp-2026-v1") |
| effectiveFrom | date | Yes | Date this configuration becomes active |
| effectiveUntil | date | No | Date configuration expires; null = open-ended |
| status | enum | Yes | One of active, archived, superseded |

**Relations:**
- → Administration (many-to-one)
- → TaxSummaryReport (one-to-many, drives GL account → category mapping)
- → TaxEstimate (one-to-many, provides rates and allowances for projection)

### TaxReturn
**Schema.org:** `schema:Thing`
_A formal tax return filing for income, VAT, or other tax obligations with workflow management and compliance tracking_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| returnId | string | Yes | Unique identifier for the tax return |
| filingPeriod | string | Yes | Period covered by this return (e.g., Q1 2026) |
| taxYear | number | Yes | Calendar year for tax reporting |
| totalIncome | number | No | Total income for the period |
| totalExpenses | number | No | Total deductible expenses |
| status | string | Yes | Current status: draft, submitted, approved, or rejected |
| filedDate | datetime | No | Date when the return was submitted |

**Relations:**
- → Organization (many-to-one)
- → TaxConfiguration (many-to-one)

### TaxSummaryReport
**Schema.org:** `schema:Table`
_GL-aggregated income and expense summary by statutory tax category and fiscal period. Materialized from GLLine transactions grouped by (administrationId, fiscalYear, reportingPeriod, taxCategory) using TaxRegimeConfiguration.categoryMappingRules. No parallel tax table — aggregation is the single source of truth per ADR-031 D1. Updated automatically on each GLLine posting via x-openregister-lifecycle hook; amended status triggered by GL repost after finalization. Cross-referencing spec: `bookkeeping-zzp-tax-regime` (bookkeeping-zzp-tax-regime, 2026-06-01)._
**Primary spec:** bookkeeping-zzp-tax-regime

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the administration this report belongs to |
| fiscalYear | integer | Yes | Fiscal year this report covers |
| reportingPeriod | enum | Yes | One of year, quarter-1 … quarter-4, month-01 … month-12 |
| taxCategory | string | Yes | Statutory category resolved via categoryMappingRules (e.g. "self-employment-income", "deductible-business-expenses") |
| glTransactionCount | integer | No | Count of GLLine transactions in this aggregation for sanity checks |
| grossAmount | number | Yes | Sum of GLLine amounts for this category and period (EUR) |
| deductionsAmount | number | No | Statutory deductions or allowances applicable to this category (EUR) |
| netAmount | number | Yes | grossAmount − deductionsAmount (EUR); basis for TaxEstimate income calculation |
| currency | string | Yes | ISO 4217 currency code (EUR) |
| snapshotDate | date | Yes | Date the aggregation was computed; makes GL posting lag explicit |
| configurationVersionId | string | Yes | FK to TaxRegimeConfiguration.versionId used for the GL account → category mapping |
| status | enum | Yes | One of draft, finalized, amended |

**Relations:**
- → Administration (many-to-one)
- → TaxRegimeConfiguration (many-to-one, via configurationVersionId → versionId)
- → GLLine (one-to-many, aggregated source transactions)
- → TaxEstimate (many-to-one, provides YTD basis for annual projection)

### TaxableTransaction
**Schema.org:** `schema:Thing`
_Business transaction classified and tracked for tax reporting, audit trail, and automated tax calculation with receipt scanning support_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | Unique transaction identifier |
| amount | number | Yes | Transaction amount |
| transactionDate | datetime | Yes | Date of the transaction |
| taxCategory | string | Yes | Tax classification category for reporting |
| taxRate | number | No | Applied tax rate percentage |
| description | string | No | Transaction description for audit trail |

**Relations:**
- → TaxReturn (many-to-one)
- → Receipt (many-to-one)
- → Payment (many-to-one)

### Team
**Schema.org:** `schema:Organization`
_Group of users organized for collaboration with shared access and permissions_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Team name |
| description | string | No | Team description and purpose |
| isActive | boolean | Yes | Whether the team is active |
| createdAt | datetime | No | Team creation date |

**Relations:**
- → Account (many-to-one)
- → User (many-to-many)

### TemporaryDifference
**Schema.org:** `schema:MonetaryAmount`
_Per-account, per-period detection of a timing difference between commercial (IFRS / RJ 272) carrying amount and tax basis at balansdatum (REQ-DT-001 / IAS 12 §5). One record per `(periodId, jurisdiction, accountNumber, category, administrationId)`. `temporaryDifference = commercialCarryingAmount − taxCarryingAmount` and `deferredTaxBalance = round(temporaryDifference × taxRate / 10000)` are declarative `x-openregister-calculations` outputs (ADR-031). `type` ∈ taxable / deductible — taxable creates DTL, deductible creates DTA. Permanent differences (e.g. deelnemingsvrijstelling) MUST NOT be stored here; they appear in `TaxRateReconciliation.reconciliationItems` with `type=permanent` (REQ-DT-002). `Account.taxBasisDifferenceCategory` provides an optional pre-tag for auto-classification. All amounts integer euro cents; tax rate in basis points (ADR-022)._
**Primary spec:** bookkeeping-deferred-tax

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | FK to FiscalPeriod (balansdatum) |
| jurisdiction | string | Yes | ISO 3166-1 alpha-2 country code (REQ-DT-007) |
| accountNumber | string | Yes | RGS general-ledger account code (FK to Account.accountNumber, REQ-DT-001) |
| category | enum | Yes | One of depreciation / provision / receivable-impairment / inventory-valuation / development-cost / fair-value-adjustment / lease-ifrs16 / pension / other |
| commercialCarryingAmount | integer | Yes | Commercial carrying amount at balansdatum in euro cents (IAS 12 §5) |
| taxCarryingAmount | integer | Yes | Tax basis (fiscale boekwaarde) at balansdatum in euro cents (IAS 12 §5) |
| temporaryDifference | integer | Yes | Computed: `commercialCarryingAmount − taxCarryingAmount` in euro cents (negative for deductible differences) |
| type | enum | Yes | One of `taxable` (creates DTL) or `deductible` (creates DTA) per REQ-DT-001 |
| reversalPattern | enum | Yes | One of `short-term`, `long-term`, `indefinite`; used to determine whether a rate change applies (REQ-DT-005) |
| expectedReversalYear | integer | No | Year the difference is expected to reverse; required when `reversalPattern=long-term` (REQ-DT-005) |
| taxRate | integer | Yes | Applied tax rate in basis points (1/10000); sourced from `FiscalPeriod.enactedTaxRates[jurisdiction]` for the expectedReversalYear (REQ-DT-005) |
| deferredTaxBalance | integer | Yes | Computed: `round(temporaryDifference × taxRate / 10000)` in euro cents (positive = DTL, negative = DTA) |
| notes | string | No | Free-text auditor notes on the specific difference |
| administrationId | string | Yes | FK to the Administration this record belongs to |

**Relations:**
- → FiscalPeriod (many-to-one, via periodId)
- → Account (many-to-one, via accountNumber; reads optional taxBasisDifferenceCategory hint for auto-classification per REQ-DT-001)
- → DeferredTaxMovement (logical, contributes origination / reversal flows by category)
- → Administration (many-to-one)

**Cites:** ADR-022 (money rule, audit-trail), ADR-031 (declarative calculations), ADR-037 (modular register fragment).

### Tender
**Schema.org:** `schema:Order`
_Digital solicitation request for goods or services from multiple suppliers_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Tender title |
| description | string | Yes | Detailed description of the tender scope |
| closingDate | datetime | Yes | Deadline for submitting bids |
| publicationDate | datetime | Yes | Date when tender was published |
| totalBudget | number | Yes | Total budget allocated for the tender |
| budgetCurrency | string | Yes | Currency code (EUR) |
| minimumQuoteCount | integer | Yes | Minimum number of required quotes |
| referenceNumber | string | Yes | Unique tender reference number (aanbestedingsnummer) |
| procurementType | string | Yes | Procurement procedure type (open, restricted, negotiated) |
| contactPerson | string | Yes | Name of responsible contact |
| contactEmail | string | Yes | Email address for inquiries |
| deliveryLocation | string | Yes | Address where goods/services are delivered |
| documents | array | Yes | Tender specifications and requirements documents |
| estimatedDuration | string | No | Contract duration (e.g., 24 months) |
| category | string | No | Category of goods or services |
| paymentTerms | string | No | Payment conditions |
| consultationDeadline | datetime | No | Deadline for clarification questions |
| contractStartDate | datetime | No | Planned contract start date |

**Relations:**
- → Supplier (many-to-many)
- → TenderLineItem (one-to-many)
- → Quote (one-to-many)
- → TenderDocument (one-to-many)

### TenderAmendment
**Schema.org:** `schema:DigitalDocument`
_Amendment to published tender, flagged as material or non-material change_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Amendment title |
| changeDescription | string | Yes | Detailed description of what was changed |
| isMaterialChange | boolean | Yes | True if material change requiring republication |
| publicationDate | date | Yes | Amendment publication date |
| tedReferenceId | string | No | TED/OJEU amendment reference ID |
| newClosingDate | date | No | New submission deadline if extended |

**Relations:**
- → TenderNotice (many-to-one)

### TenderDocument
**Schema.org:** `schema:DigitalDocument`
_Specifications, terms, and attachments for tender process_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentType | enum | Yes | Document role |
| uploadedDate | date | No | Upload date |
| requiredForBidding | boolean | No | Mandatory review before submitting quote |

**Relations:**
- → Tender (many-to-one)

### TenderLineItem
**Schema.org:** `schema:Product`
_Individual product or service line in tender request_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| description | text | Yes | Item or service description |
| quantity | number | Yes | Quantity needed |
| unitCode | string | Yes | Unit (pcs, kg, hours, etc.) |
| unitPrice | number | No | Estimated unit price (cents) |
| category | string | No | Product/service category |
| specifications | text | No | Technical or quality requirements |

**Relations:**
- → Tender (many-to-one)

### TenderLot
**Schema.org:** `schema:Thing`
_A distinct portion of a tender that can be evaluated and awarded separately with independent budgets and evaluation criteria_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot number or identifier within the tender |
| title | string | Yes | Title or description of the lot |
| description | string | No | Detailed scope of work or goods included in this lot |
| budgetAmount | number | No | Budget allocated to this specific lot |
| currency | string | No | Currency code for budget |
| status | string | No | Status: draft, open, evaluation, awarded, closed |
| evaluationCriteria | array | No | Weighted evaluation criteria with scoring rules |
| minParticipants | number | No | Minimum number of suppliers required |
| maxParticipants | number | No | Maximum number of suppliers allowed |

**Relations:**
- → Tender (many-to-one)
- → Bid (one-to-many)
- → Product (many-to-many)

### TenderNotice
**Schema.org:** `schema:DigitalDocument`
_Tender or procurement notice published to TED/OJEU and market platforms for public competition_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the tender |
| tenderType | string | Yes | Type: SERVICES, SUPPLIES, WORKS, or CONCESSION |
| publicationDate | date | Yes | Date published |
| tedReferenceId | string | No | TED/OJEU publication ID |
| estimatedValue | number | No | Estimated contract value in EUR |
| closingDate | date | Yes | Submission deadline |
| scope | string | Yes | Geographic scope: EUROPEAN, NATIONAL, or REGIONAL |

**Relations:**
- → Organization (many-to-one)

### TenderNedAanbesteding
**Schema.org:** `schema:Order`
_A procurement tender imported from TenderNed (Logius central platform). Wraps the public dossier metadata (REQ-001) and links to the materialised Shillinq Verplichting. Consumed by both the aanbestedende dienst (public buyer) and the inschrijvende leverancier (winning vendor), with role-based visibility filtering (design D6)._
**Primary spec:** bookkeeping-tenderned-integratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| aanbestedingId | string | Yes | TenderNed dossier identifier (unique per administration). |
| tenderNedUrl | string | No | Deeplink to the public TenderNed dossier (REQ-007 drill-through). |
| titel | string | Yes | Human-readable tender title. |
| beschrijving | string | No | Tender description. |
| cpvCodes | array | No | CPV-2008 classification codes (design D7). |
| aanbestedendeDienst | string | Yes | KvK + name of the public buyer running the procurement. |
| gunningsDatum | date | No | Award date (Aanbestedingswet 2012 art. 2.135). |
| contractWaarde | number | Yes | Contract value excl. BTW in the administration's base currency. |
| looptijdStart | date | No | Contract term start — drives REQ-003 milestone plan. |
| looptijdEind | date | No | Contract term end — drives REQ-003 milestone plan. |
| gegundeLeverancier | string | No | KvK + name of the awarded supplier (REQ-002 / REQ-008 vendor filter). |
| opdrachttype | enum | Yes | levering-in-fases / dienstverlening-doorlopend / other (REQ-003 template selector). |
| verplichtingId | string | No | FK to the materialised Shillinq Verplichting. |
| status | enum | Yes | open / gegund / in-uitvoering / afgerond / beëindigd. |
| administrationId | string | Yes | Tenant administration. |

**Lifecycle (x-openregister-lifecycle):**
- `open → gegund` via `gunnen` (TenderNedAanbestedingGuard.canGunnen: REQ-002 award gate).
- `gegund → in-uitvoering` automatic on Verplichting promotion (REQ-002 listener).
- `in-uitvoering → afgerond` via `afronden` (TenderNedAanbestedingGuard.canAfronden: REQ-006 eindoplevering gate).

**Relations:**
- → Verplichting (one-to-one, FK on verplichtingId).

### Verplichting
**Schema.org:** `schema:Order`
_A financial commitment (obligation) tracked in Shillinq with optional TenderNed provenance, mijlpaalplanning, and cross-app budget-impact emission. The schema is OWNED by the `bookkeeping-verplichtingenadministratie` fragment, which is the single place its `required` list is declared; `20-bookkeeping-tenderned-integratie.json` is an additive overlay contributing bron/bronReferentie/mijlpalen and the concept → active enrichment lifecycle. Only one fragment may declare `required`, because ADR-037 CONCATENATES list values on merge._
**Primary spec:** bookkeeping-tenderned-integratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| verplichtingsnummer | string | Yes | Unique obligation reference. **A second spelling is live in production**: `verplichtingNummer` is written by `TenderNedAwardDetectedListener` and read by `VerplichtingGuard`, while `verplichtingsnummer` is written by `CommitmentMaterialisationService` and read by `BudgetBlocker::canCommit`, `MandaatEnforcer` and `RequisitionService`. Only the latter is in `required`, so a record written through the TenderNed path does not yet satisfy it. Collapsing the two is a DATA migration (MagicMapper discards undeclared properties) and belongs to the English domain rename (`Verplichting` → `Commitment`), which owns the repair step. |
| soort | enum | Yes | inkooporder / raamovereenkomst / arbeidscontract / subsidiebeschikking / huurovereenkomst / leasing / overig. |
| omschrijving | string | No | Short description (e.g. tender title). |
| bron | enum | No | manual / tenderned / inkooporder (default: manual). |
| bronReferentie | string | Cond. | Required when bron=tenderned (aanbestedingId FK). |
| bedrag | number | No | Committed amount in the administration's base currency. |
| kostenplaats | string | No | Cost centre (required for activation). |
| grootboekrekening | string | No | GL account (required for activation). |
| looptijdStart | date | No | Contract term start. |
| looptijdEind | date | No | Contract term end. |
| mijlpalen | array&lt;Mijlpaal&gt; | No | Embedded mijlpaalplan (REQ-003). |
| status | enum | Yes | concept / active / completed / cancelled. |
| administrationId | string | Yes | Tenant administration. |

**Lifecycle:** `concept → active` via `activeren` (VerplichtingGuard.canActiveren: requires kostenplaats + grootboekrekening + milestone dates within term).

**Mijlpaal (embedded value object):** mijlpaalId, datum, omschrijving, percentage (0–100), opleveringsType (deeloplevering | eindoplevering), status (planned / in-progress / completed / cancelled), factuurnummer.

**Relations:**
- → TenderNedAanbesteding (many-to-one via bronReferentie when bron=tenderned).
- → OpdrachtUitvoering (one-to-many on verplichtingId).
- → launchpad budget-impact widget via `shillinq.obligation.activated` CloudEvent (REQ-007).

### OpdrachtUitvoering
**Schema.org:** `schema:DeliveryEvent`
_A milestone delivery (oplevering) on a Verplichting, carrying the proof-of-delivery (bewijsstuk) file references and the approval state. Used to gate completion (REQ-004) and to trigger the REQ-006 TenderNed status-sync on the eindoplevering._
**Primary spec:** bookkeeping-tenderned-integratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| verplichtingId | string | Yes | FK to the parent Verplichting. |
| mijlpaalId | string | Yes | FK to the embedded mijlpaal being delivered. |
| opleveringsDatum | date | Yes | Delivery date. |
| opleveringsType | enum | Yes | deeloplevering / eindoplevering. |
| goedgekeurd | boolean | Yes | Approval marker (only true → completed succeeds with proof). |
| goedkeurder | string | No | Approver UID. |
| bewijsstukken | array | Yes | File-references to docudesk-stored proof (REQ-004). |
| status | enum | Yes | in-progress / completed / cancelled. |
| administrationId | string | Yes | Tenant administration. |

**Lifecycle:** `in-progress → completed` via `voltooien` (OpdrachtUitvoeringGuard.canVoltooien: at least one bewijsstuk with non-empty documentId — REQ-004). On completion of an approved eindoplevering, OpdrachtUitvoeringTransitionListener triggers TenderNedStatusSync → openconnector outbound (REQ-006).

**Relations:**
- → Verplichting (many-to-one on verplichtingId).
- → docudesk file (many-to-many via bewijsstukken[].documentId).

### TimeEntry
**Schema.org:** `TimeEntry`
_Time tracking entries for project tasks including manual entry and timer-based tracking_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryId | string | Yes | Unique time entry identifier |
| taskId | string | Yes | Project task this time is logged against |
| projectId | string | Yes | Project associated with this entry |
| userId | string | Yes | Person/User ID who logged the time |
| date | datetime | Yes | Date of the time entry |
| duration | number | Yes | Duration in hours |
| description | string | No | Details of work performed |
| entryType | string | No | manual or timer |
| billable | boolean | No | Whether this time is billable to client |

**Relations:**
- → ProjectTask (many-to-one)
- → Project (many-to-one)
- → Person (many-to-one)

### Timesheet
**Schema.org:** `schema:Report`
_Periodic summary of time entries for an employee, aggregating hours and utilization metrics by week or month_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodStart | datetime | Yes | Start date of the reporting period |
| periodEnd | datetime | Yes | End date of the reporting period |
| totalHours | number | Yes | Total hours logged in period |
| utilizationPercentage | number | No | Utilization rate as percentage of available hours |
| totalCost | number | No | Total cost based on hourly rates |
| status | string | Yes | Status: draft, submitted, or approved |
| submittedDate | datetime | No | Date when submitted for approval |
| approvedDate | datetime | No | Date when approved |

**Relations:**
- → Person (many-to-one)
- → TimeEntry (one-to-many)
- → ApprovalRequest (many-to-one)

### Transaction
**Schema.org:** `schema:Order`
_Financial transaction in the bookkeeping system (debit/credit entry)_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Unique transaction reference |
| transactionType | string | Yes | Type: invoice, payment, transfer, credit |
| amount | decimal | Yes | Transaction amount |
| currency | string | Yes | ISO 4217 currency code |
| description | string | No | Transaction description/memo |
| transactionDate | date | Yes | Date of transaction |
| paymentTerms | string | No | Payment terms (e.g., net30) |
| orderStatus | string | Yes | Status: pending, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → BankAccount (many-to-one)
- → PaymentFraudAssessment (one-to-many)

### TreasuryAccount
**Schema.org:** `schema:BankAccount`
_Treasury-managed bank account governed under Dutch schatkistbankieren regulations per REQ-SCHATKIST-002. Carries IBAN (validated against `^NL[0-9]{2}[A-Z]{4}[0-9]{10}$`), compliance classification (master-list/subsidiary/suspense/temporary), master-list status (active/pending-review/blocked/archived), optional GL linkage via `linkedAccountNumber → Account.accountNumber`, optional treasurer/CFO approval, and a seven-state lifecycle (draft → configured → active → monitored → compliant; plus suspended / archived). The `configured → active` transition is gated by a multi-criteria compliance precondition that evaluates every active BankingRule scoped to the administration per REQ-SCHATKIST-005; if any rule fails the transition is blocked and the failure is recorded as a compliance audit event per REQ-SCHATKIST-008. Lifecycle transitions materialise audit-trail-immutable events per ADR-022 / T2 bookkeeping-audit-trail; the activation side-effect creates a ComplianceReport with per-rule criteriaResults. Treasury accounts are deliberately separate from the generic `BankAccount` entity (commercial / single-currency tracking) — see Reconciliation note below._
**Primary spec:** bookkeeping-schatkistbankieren

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | Stable identifier per administration (REQ-SCHATKIST-002) |
| iban | string | Yes | Dutch IBAN, schema-validated |
| bic | string | No | BIC / SWIFT code |
| bankName | string | No | Bank name for display |
| accountName | string | Yes | Treasury account label |
| description | string | No | Business purpose and governance notes |
| complianceClassification | enum | Yes | master-list / subsidiary / suspense / temporary |
| masterListStatus | enum | Yes | active / pending-review / blocked / archived |
| administrationId | string | Yes | FK to owning Administration |
| linkedAccountNumber | string | No | FK to `Account.accountNumber` for GL classification (T1) |
| requiresApproval | boolean | Yes | Whether activation requires CFO/treasurer approval (default true) |
| approvalStatus | enum | Yes | not-required / pending / approved / rejected |
| lifecycleState | enum | Yes | draft / configured / active / monitored / compliant / suspended / archived |
| lastCompliantDate | datetime | No | Last datetime all active rules passed; drives the aging aggregation per REQ-SCHATKIST-007 |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Account (many-to-one, via linkedAccountNumber → Account.accountNumber)
- → BankingRule (many-to-many via lifecycle precondition evaluation per REQ-SCHATKIST-005)
- → ComplianceReport (one-to-many — every activation/monitoring cycle generates a report)

> **Reconciliation note (bookkeeping-schatkistbankieren, 2026-06-09):** `TreasuryAccount` is intentionally a separate register from the generic `BankAccount` (bookkeeping-multi-currency / bookkeeping-chart-of-accounts base entity) and from `SchatkistbankierenSaldo` (Wet Fido T3, per-period sweep balance). The three carry disjoint concerns: `BankAccount` is the commercial single-currency bank-account master, `SchatkistbankierenSaldo` is the per-period drempelbedrag/sweep snapshot for Wet HOF reporting, and `TreasuryAccount` is the governance/compliance master with lifecycle-gated activation against the BankingRule register. Operators link a `TreasuryAccount` to its underlying `BankAccount` via the optional `linkedAccountNumber → Account.accountNumber` FK; sweep snapshots accumulate under `SchatkistbankierenSaldo` and feed the Wet Fido quarterly rapportage rather than the schatkist compliance ledger.

### TreasuryTask
**Schema.org:** `schema:Event`
_Unified AP/AR/spend task list for cash flow management with due dates and counterparty tracking_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskType | string | Yes | AccountsPayable, AccountsReceivable, or CapitalExpenditure |
| amount | number | Yes | Transaction amount |
| currency | string | Yes | ISO 4217 code |
| dueDate | string | Yes | ISO 8601 date |
| counterpartyName | string | No | Vendor, customer, or counterparty |
| description | string | No | Task details and notes |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### TrialBalance
**Schema.org:** `schema:Table`
_A report listing all general ledger accounts with debit or credit balances for verification and audit purposes_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Date of the trial balance |
| totalDebits | number | No | Total of all debit balances |
| totalCredits | number | No | Total of all credit balances |
| isBalanced | boolean | No | Whether debits equal credits |
| status | string | Yes | Status (draft, verified, final) |
| preparedBy | string | No | Name or identifier of person who prepared the trial balance |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → GeneralLedgerEntry (one-to-many)

### UrenRegistratie
**Schema.org:** `schema:HowToStep`
_Billable hour log entry for ZZP / consultancy work. Base schema from bookkeeping-zzp-tax-regime (T3); extended by bookkeeping-consultancy-project-accounting (T3) with `recognisedRate` (rate-at-write snapshot per RJ 270 §3.2.4) and `projectAssignmentId` (FK to ProjectAssignment)._
**Primary spec:** bookkeeping-consultancy-project-accounting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration owning this hour entry |
| personId | string | Yes | FK to the person logging the hours |
| date | date | Yes | Date on which the work was performed (performance date, NOT invoice date) |
| hours | number | Yes | Number of hours worked |
| description | string | Yes | Description of work performed |
| projectId | string | No | Optional FK to the Project this hour is billed against |
| projectAssignmentId | string | No | FK to the ProjectAssignment governing this hour entry — added by CPA extension (REQ-CPA-009) |
| recognisedRate | number | No | Snapshot of the applicable RateCard.hourlyRate at write time per RJ 270 §3.2.4 — immutable after creation; subsequent rate-card revisions do NOT retroactively change this field (REQ-CPA-009) |
| glTransactionId | string | No | FK to the GLTransaction when this hour is posted to GL |
| wbsoTagId | string | No | FK to WBSOTag — auto-assigned from Project.wbsoTagId on creation if project carries WBSO metadata; null for non-subsidized entries (REQ-WBSO-004) |
| activityCodeId | string | No | FK to WBSOActivityCode — auto-assigned from Project.activityCodeId on creation if project carries WBSO metadata; null for non-subsidized entries (REQ-WBSO-004) |
| wbsoTaggedAt | datetime | No | Timestamp when WBSO tags were assigned — auto or manual (REQ-WBSO-004) |
| tagSource | enum | No | How WBSO tags were assigned: auto (from project metadata), manual (operator), or untagged (pending assignment) (REQ-WBSO-004) |

**Relations:**
- → ProjectAssignment (many-to-one, via projectAssignmentId)
- → Project (many-to-one, via projectId)
- → GLTransaction (many-to-one, via glTransactionId)
- → WBSOTag (many-to-one, via wbsoTagId)
- → WBSOActivityCode (many-to-one, via activityCodeId)


### WBSOActivityCode
**Schema.org:** `schema:DefinedTerm`
_Activity classification register for WBSO subsidy hours — A-codes (allowed R&D activities) and B-codes (non-eligible overhead). Baseline codes ship with the app; administrations may add custom variants per subsidy agreement via parentActivityCode FK (REQ-WBSO-003, REQ-WBSO-009). Per ADR-024 this is a full OR register, not a config table._
**Primary spec:** bookkeeping-wbso-hours-tagging-and-export

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| activityCode | string | Yes | Activity code identifier: A001–A999 for allowed R&D activities, B001+ for restricted/overhead, or custom per subsidy agreement |
| description | string | Yes | Activity description per RVO guidelines |
| category | enum | Yes | One of: research-development, support, non-eligible, infrastructure |
| isAllowed | boolean | Yes | true = eligible for WBSO subsidy hours; false = tracked but excluded from RVO submission totals |
| parentActivityCode | string | No | FK to the baseline activityCode this entry is a custom variant of; custom variants inherit isAllowed from parent unless explicitly overridden (REQ-WBSO-009) |
| administrationId | string | Yes | FK to the Administration owning this code entry |
| lifecycleState | enum | Yes | One of: active, archived, deprecated |

**Relations:**
- → UrenRegistratie (one-to-many, via activityCodeId)
- → WBSOActivityCode (many-to-one, via parentActivityCode — custom variant hierarchy)

### WBSOExportLog
**Schema.org:** `schema:DigitalDocument`
_Tracks WBSO uren-export operations and their RVO validation lifecycle. Lifecycle: draft → generated → validated → submitted → archived (with generated → rejected fallback). Validation guards enforce complete WBSO tagging on all included UrenRegistratie entries before RVO submission (REQ-WBSO-005, REQ-WBSO-006). Per ADR-031 the lifecycle is fully declarative — no PHP export service._
**Primary spec:** bookkeeping-wbso-hours-tagging-and-export

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| exportId | string | Yes | Unique export identifier in format EXP-{YEAR}-{PERIOD}-{SEQ} |
| periodStart | date | Yes | Start of the export date range (inclusive), typically fiscal quarter start |
| periodEnd | date | Yes | End of the export date range (inclusive), typically fiscal quarter end |
| exportFormat | enum | Yes | One of: csv, pdf, xml |
| status | enum | Yes | One of: draft, generated, validated, submitted, archived, rejected |
| recordCount | integer | Yes | Number of UrenRegistratie entries included in this export |
| totalHours | number | Yes | Total eligible hours (A-codes) included in the export; excludes B-code entries |
| totalHoursIneligible | number | No | Total non-eligible hours (B-codes) tracked but excluded from RVO submission totals |
| exportFilters | object | No | Filter parameters applied on generation: wbsoTagId, activityCodeId, isAllowed, employee, date range (REQ-WBSO-008) |
| generatedAt | datetime | No | Timestamp when the export file was materialized |
| validatedAt | datetime | No | Timestamp when RVO validation passed |
| validationErrors | array | No | List of validation error messages; empty if validated successfully |
| submittedAt | datetime | No | Timestamp when submitted to RVO portal or operator recorded manual upload |
| fileUri | string | No | Storage path or cloud URL of the generated export file |
| administrationId | string | Yes | FK to the Administration owning this export log |

**Relations:**
- → UrenRegistratie (one-to-many — export covers entries in the period matching the filters)
- → Administration (many-to-one, via administrationId)

### WBSOTag
**Schema.org:** `schema:DefinedTerm`
_WBSO project code register — SO (Stand-alone Project), TWO (TechnoWise Open), SMART (SME Collaboration) and custom codes per WBSO legislation (Wet Bevordering Speur- en Ontwikkelingswerk). Administration-configurable; not hardcoded. Per ADR-024 this is a full OR register, not a config table (REQ-WBSO-002). Unique NL moat — zero competitor coverage (2026-05-20 market research)._
**Primary spec:** bookkeeping-wbso-hours-tagging-and-export

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| wbsoCode | string | Yes | Official WBSO project code (SO, TWO, SMART) or custom code per WBSO legislation — not an enum, operators may add custom codes per subsidy agreement |
| displayName | string | Yes | Dutch display name shown in UI and exports |
| description | string | No | RVO alignment notes and subsidy conditions for this code |
| rvoCertificationUrl | string | No | Link to the official RVO directive or legislation reference |
| administrationId | string | Yes | FK to the Administration owning this WBSO code entry |
| lifecycleState | enum | Yes | One of: active, archived, deprecated |

**Relations:**
- → UrenRegistratie (one-to-many, via wbsoTagId — auto-assigned from Project metadata)
- → Project (one-to-many, via Project.wbsoTagId — project carries the WBSO code metadata that triggers auto-tagging)

### User
**Schema.org:** `schema:Person`
_System account for authentication and access control with assigned permissions and team memberships_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| username | string | Yes | Unique username for login |
| email | string | Yes | Email address for the account |
| firstName | string | No | First name of the user |
| lastName | string | No | Last name of the user |
| isActive | boolean | Yes | Whether the account is active |
| twoFactorEnabled | boolean | No | Whether 2FA is enabled |
| createdAt | datetime | Yes | Account creation date |
| lastLogin | datetime | No | Date of last login |

**Relations:**
- → Person (many-to-one)
- → Team (many-to-many)
- → Role (many-to-many)
- → Account (many-to-many)
- → Entitlement (one-to-many)
- → UserPreference (one-to-many)

### UserPreference
_User-specific preferences for display settings, notifications, language, and other customization options_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| key | string | Yes | Preference key or identifier |
| value | string | Yes | Preference value |
| category | string | No | Category of preference (display, notification, language, accessibility) |
| updatedAt | datetime | No | Last update date |

**Relations:**
- → User (many-to-one)

### VATAuditRecord
**Schema.org:** `schema:Invoice`
_Kassakoppeling-compliant per-line VAT audit record per bookkeeping-invoice-vat-kassakoppeling REQ-VAT-004. Append-only timeline: one immutable record per ARInvoice line per lifecycle event (issued, paid, written_off, reversed). All amounts are decimal-euro copies taken at event time, never mutated after creation. PATCH and DELETE return 409 SCHEMA_IMMUTABLE per ADR-022. Created declaratively by the ARInvoice.issue lifecycle materialisation (and follow-up paid / written_off / reversed transitions) per ADR-031; no app-local PHP VAT-audit service exists. Belastingdienst audits inspect these records via OR's generic CRUD API. Carries the vatByPeriod x-openregister-aggregation used by the REQ-VAT-009 reporting query and powering the VATByPeriod manifest entry (REQ-VAT-010)._
**Primary spec:** bookkeeping-invoice-vat-kassakoppeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | ARInvoice.invoiceNumber at event time (immutable copy) |
| invoiceDate | date | Yes | ARInvoice.invoiceDate at issuance (immutable copy) |
| lineSequence | integer | Yes | 1-based index of the line within ARInvoice.lines[] |
| lineDescription | string | Yes | Immutable copy of the line description at event time |
| lineAmount | number | Yes | Immutable copy of line.amount in decimal euros |
| vatRate | integer enum | Yes | 21, 9, 6, or 0 — the rate applied at event time |
| vatAmount | number | Yes | Immutable copy of the line's vatAmount (banker's-rounded to 2 decimals) |
| serviceCategory | enum | Yes | product, service, or exempt — immutable copy |
| lifecycleEvent | enum | Yes | issued, paid, written_off, or reversed |
| eventDate | datetime | Yes | UTC timestamp when the lifecycle event was recorded |
| paymentDate | date | No | Populated only when lifecycleEvent=paid |
| settlementPeriod | string | Yes | FK to FiscalPeriod (periodId) bound at issue time per REQ-VAT-005; immutable |
| overrideId | string | No | Optional FK to ServiceCategoryOverride if the line was authorised under an exception |
| administrationId | string | Yes | FK to the administration |

**Relations:**
- → ARInvoice (many-to-one, via invoiceNumber)
- → FiscalPeriod (many-to-one, via settlementPeriod)
- → ServiceCategoryOverride (many-to-one optional, via overrideId)
- → Administration (many-to-one)

**Cites:** ADR-022 (immutable audit channel, kassakoppeling tamper-proofing), ADR-031 (declarative-only, no PHP VAT-audit service).

### VATGLAccounts
**Schema.org:** `schema:PropertyValueSpecification`
_Per-administration GL account mapping from VAT rate bucket to VATPayable* liability account, consumed by the ARInvoice.issue lifecycle action's vatBucketMapping per REQ-VAT-006. One record per administrationId. Defaults from the RGS (Referentie Grootboekschema) baseline (vat21=2020, vat9=2021, vat6=2022, vat0=2023) and seeded on installer first-run. The admin UI validates that all four accounts exist in Account for the administration and are unique within the record (REQ-VAT-006)._
**Primary spec:** bookkeeping-invoice-vat-kassakoppeling

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the administration; one record per administration |
| vat21Account | string | Yes | GL account number credited for 21% VAT lines (default 2020) |
| vat9Account | string | Yes | GL account number credited for 9% VAT lines (default 2021) |
| vat6Account | string | Yes | GL account number credited for 6% VAT lines (default 2022) |
| vat0Account | string | Yes | GL account number credited for 0% / exempt VAT lines (default 2023) |
| createdAt | datetime | Yes | Set on first save |
| updatedAt | datetime | Yes | Updated on each save |

**Relations:**
- → Administration (many-to-one)
- → Account (one-to-many; the four GL accounts MUST exist in Account for the administration)

**Cites:** ADR-022 (configuration audit trail), ADR-031 (declarative-only).

### VATReturn
**Schema.org:** `schema:Thing`
_VAT-specific tax return showing collected VAT, paid VAT, and net amount due for MTD compliance and electronic filing_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vatReturnId | string | Yes | Unique VAT return identifier |
| reportingPeriod | string | Yes | VAT reporting period: monthly, quarterly, or annually |
| collectedVAT | number | Yes | VAT collected from customers |
| paidVAT | number | Yes | VAT paid on business purchases and expenses |
| netAmount | number | Yes | Net VAT payable (positive) or refundable (negative) |
| status | string | Yes | Status: draft, submitted, approved, or rejected |
| submissionDate | datetime | No | Date when VAT return was submitted to authorities |

**Relations:**
- → Organization (many-to-one)
- → TaxReturn (many-to-one)

### VatReturn
**Schema.org:** `schema:Invoice`
_Dutch periodic BTW return (kwartaal / maand) per administration. Owns the declarative draft → submitted → accepted → corrected lifecycle (REQ-VBTW-005); rubrieken are derived via `x-openregister-aggregations` from period-filtered GL postings tagged by VatTariff (REQ-VBTW-004); submission to the Belastingdienst is dispatched via the `digipoort-sbr` OpenConnector source on the `submit` transition (REQ-VBTW-010). Audit + retention are consumed from OR's abstractions (REQ-VBTW-012). No app-local PHP `VatReturnService` (ADR-031), no parallel storage (ADR-022)._
**Primary spec:** bookkeeping-vat-btw-filing

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration owning the return |
| periodType | enum | Yes | `month` or `quarter` per administration |
| periodYear | integer | Yes | Calendar year of the period |
| periodMonth | integer | No | 1-12 when `periodType=month` |
| periodQuarter | integer | No | 1-4 when `periodType=quarter` |
| state | enum | Yes | `draft`, `submitted`, `accepted`, `rejected`, `corrected` |
| amount | number | Yes | Net `teBetalenOfTeruggave` (negative = refund) in euros |
| currency | string | Yes | ISO 4217 currency code (EUR for NL) |
| approvalThreshold | number | No | Approval-gate threshold per REQ-VBTW-006 |
| submittedAt | datetime | No | Set on transition to `submitted` |
| acceptedAt | datetime | No | Set on Belastingdienst ack |
| attachmentUri | string | No | docudesk URI of the rendered aangifte PDF |
| notes | string | No | Operator-visible note |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → VatCorrection (one-to-many, via VatCorrection.originalReturnId)

**Cites:** ADR-022 (audit / approval / retention), ADR-031 (declarative lifecycle + aggregations), ADR-019 (OpenConnector for SBR/Digipoort).

### IcpStatement
**Schema.org:** `schema:Invoice`
_Intracommunautaire prestaties (ICP) opgaaf — periodic statement of intra-EU B2B sales per administration and period. Separate register from VatReturn (REQ-VBTW-007) because ICP filing has its own Belastingdienst surface and lifecycle. Audit + retention consumed from OR's abstractions._
**Primary spec:** bookkeeping-icp-opgaaf

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration owning the statement |
| periodType | enum | Yes | `month` or `quarter` per administration |
| periodYear | integer | Yes | Calendar year of the period |
| periodMonth | integer | No | 1-12 when `periodType=month` |
| periodQuarter | integer | No | 1-4 when `periodType=quarter` |
| state | enum | Yes | ICP filing lifecycle state (`draft`, `submitted`, `accepted`, `rejected`, `corrected`) |
| currency | string | Yes | ISO 4217 currency code (EUR for NL) |
| notes | string | No | Operator-visible note |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → IcpLine (one-to-many, per `bookkeeping-icp-opgaaf`)

**Cites:** ADR-022, ADR-031, REQ-VBTW-007.

### VatCorrection
**Schema.org:** `schema:Invoice`
_Suppletie-aangifte — standalone BTW correction filed when a prior accepted return contained an error above the Belastingdienst materiality threshold (REQ-VBTW-009). MUST carry a mandatory FK to the prior VatReturn; lifecycle `draft → submitted → accepted` mirrors the parent return. Below-threshold corrections fold into the next regular VatReturn rather than producing a VatCorrection._
**Primary spec:** bookkeeping-vat-btw-filing

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the Administration owning the correction |
| periodType | enum | Yes | `month` or `quarter` matching the original return |
| periodYear | integer | Yes | Calendar year of the period being corrected |
| periodMonth | integer | No | 1-12 when `periodType=month` |
| periodQuarter | integer | No | 1-4 when `periodType=quarter` |
| correctionReason | string | Yes | Free-text explanation surfaced on the aangifte |
| originalReturnId | string | Yes | Mandatory FK to the VatReturn this corrects (REQ-VBTW-009) |
| adjustmentAmount | number | Yes | Signed correction amount in euros (+ owed, − refund) |
| currency | string | Yes | ISO 4217 currency code |
| state | enum | Yes | `draft`, `submitted`, `accepted` |
| notes | string | No | Operator-visible note |

**Relations:**
- → VatReturn (many-to-one, via originalReturnId)
- → Administration (many-to-one, via administrationId)

**Cites:** ADR-022, ADR-031, REQ-VBTW-009.

### VatTariff
**Schema.org:** `schema:PriceSpecification`
_Statutory BTW tariff catalogue (21%, 9%, 0%, vrijgesteld, verleggingsregeling) per Wet OB 1968 (REQ-VBTW-003). Seeded from `lib/Settings/seeds/btw-tariffs-2026.json` via `ConfigurationService::importFromApp()` in the repair step (REQ-VBTW-003 / REQ-VBTW-011). Operators MAY add additional sector-specific or future EU-imposed rates; the canonical Belastingdienst rates remain authoritative. Verleggingsregeling is modelled as a tariff category, not a separate code path (REQ-VBTW-008)._
**Primary spec:** bookkeeping-vat-btw-filing

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Stable identifier (e.g. `21pct`, `9pct`, `0pct`, `vrij`, `verlegd`) |
| label | string | Yes | Human-readable label (Dutch) |
| ratePercentage | number | Yes | Rate as a percentage (e.g. 21.0, 9.0, 0.0) |
| rgsAccountHint | string | No | Suggested RGS GL account for postings using this tariff |
| reverseCharge | boolean | No | True for verleggingsregeling tariffs (REQ-VBTW-008) |
| effectiveFrom | date | Yes | First day this tariff is valid |
| effectiveTo | date | No | Last day this tariff is valid (nullable) |
| legalBasis | string | No | Citation (`Wet OB 1968 art. 9` etc.) |

**Relations:** none — referenced symbolically by `GLLine.vatTariffCode` per the GL spec.

**Cites:** ADR-022 (seed-via-repair-step), ADR-031 (declarative catalogue, not enum), REQ-VBTW-003 / REQ-VBTW-008.

### VendorBill
**Schema.org:** `schema:Invoice`
_Vendor invoice with approval workflow before payment processing_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| billNumber | string | Yes | Unique vendor bill identifier |
| invoiceDate | datetime | Yes | Date the invoice was issued |
| dueDate | datetime | Yes | Payment due date |
| totalAmount | number | Yes | Total invoice amount |
| currency | string | Yes | Currency code |
| status | string | Yes | Bill status: received, approved, rejected, or paid |
| approvalStatus | string | Yes | Approval workflow status: pending, approved, or rejected |
| poReference | string | No | Reference to linked purchase order |

**Relations:**
- → Supplier (many-to-one)
- → PurchaseOrder (many-to-one)
- → ApprovalRequest (one-to-one)
- → Payment (one-to-one)
- → Document (one-to-many)

### WipBalance
**Schema.org:** `schema:MonetaryAmount`
_Period-end work-in-progress snapshot per project, generated by an OR ScheduledWorkflow on T2 period close (REQ-CPA-008). One record per project per period. Read-only; never manually created._
**Primary spec:** bookkeeping-consultancy-project-accounting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectId | string | Yes | FK to the snapshotted Project.id |
| periodId | string | Yes | FK to the FiscalPeriod for which this snapshot was taken |
| recognisedRevenue | number | Yes | Snapshot of Project.recognisedRevenue at period close |
| billedRevenue | number | Yes | Snapshot of Project.billedRevenue at period close |
| wipBalance | number | Yes | Snapshot: recognisedRevenue − billedRevenue at period close |
| costsIncurredToDate | number | Yes | Snapshot of Project.costsIncurredToDate at period close |
| createdAt | datetime | Yes | Timestamp when this snapshot was generated by the ScheduledWorkflow |

**Relations:**
- → Project (many-to-one)
- → FiscalYear (many-to-one, via periodId)

### WinstToerekening
**Schema.org:** `schema:Thing`
_Per-period mapping of operating profit to one or more IP assets via a configurable verdeelsleutel (Wet Vpb art. 12b, afpelmethode only). Three verdeelsleutels are supported: omzet-aandeel, r-en-d-uren, custom-formula. MUST NOT be populated when InnovatieboxElection.route = forfaitair._
**Primary spec:** bookkeeping-innovatiebox-administratie

> **Annotation (add-shillinq-innovatiebox-administratie, 2026-06-01):** FK to `IPAssetValuation` (many-to-one via ipAssetId) and to `FiscalPeriod` (via periodId). The `vpbImpact` is a declarative calculation: toegerekendeWinst × IPAssetValuation.applicableTariff.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ipAssetId | string | Yes | FK to IPAssetValuation |
| periodId | string | Yes | FK to FiscalPeriod |
| toegerekendeWinst | number | Yes | Profit attributed to the IP asset in euros for this period |
| verdeelsleutel | enum | Yes | One of omzet-aandeel, r-en-d-uren, custom-formula |
| parameters | object | No | Verdeelsleutel-specific parameters (e.g. omzetAandeel: 0.30) |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- → IPAssetValuation (many-to-one, via ipAssetId)
- → FiscalPeriod (many-to-one, via periodId)

### WOZAssessment
**Schema.org:** `schema:Assessment`
_Property tax valuation assessment (Waardering Onroerende Zaken) with automated model generation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentYear | string | Yes | Tax year |
| assessedValue | number | Yes |  |
| valuationMethod | string | No |  |
| assessmentDate | datetime | Yes |  |
| status | string | Yes | draft, finalized, appealed, approved |
| notificationSentDate | datetime | No | Date owner notification was sent |

**Relations:**
- → Property (many-to-one)

### XBRLInstance
**Schema.org:** `schema:DigitalDocument`
_Structured XBRL instance document for taxonomies (NTA7, SBR-NT). Contains facts, contexts, and dimensions for standardized digital reporting to Dutch authorities._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyVersion | string | Yes | e.g. NTA7-2025, SBR-NT-2025 |
| instanceID | string | Yes | Unique document identifier |
| reportingPeriod | string | Yes | ISO date range (e.g. 2025-01-01/2025-12-31) |
| factCount | integer | No | Number of XBRL facts in instance |
| encodingFormat | enum | Yes | application/xbrl+xml or application/xbrl+json |
| validationStatus | enum | Yes | valid, invalid, warned, unvalidated |

**Relations:**
- → TaxDeclaration (many-to-one)

### XbrlInstance
**Schema.org:** `schema:DigitalDocument`
_An SBR/XBRL annual filing instance (jaarrekening, VPB, IB, kredietrapportage, SBR-Wonen) generated as a **declarative transformation** on top of the T3 `FinancialStatement`. The XBRL instance document consumes the already-balanced `FinancialStatement` and maps each line to the configured NL-taxonomie concept via an OpenRegister `Mapping` record (one per entry point + taxonomy version). This is explicitly NOT a re-aggregation of underlying `GLLine` entries — the T3 aggregation is the single source of truth; the XBRL filing must match the statement the operator already signed off. Digipoort submission routes through openconnector by source slug (ADR-022); no SOAP/WS-Security client exists in shillinq. The lifecycle (draft → validated → submitted → accepted / rejected) is declared as `x-openregister-lifecycle` per ADR-031 — no PHP `XbrlReportService`. This entry supersedes the earlier `XBRLInstance` entry (primary spec: tax-levy-management) for SBR annual reporting purposes; the `XBRLInstance` entry is retained for legacy tax-levy-management usage._
**Primary spec:** bookkeeping-sbr-xbrl-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| instanceNumber | string | Yes | Sequential reference unique per administration + reporting period |
| entryPoint | enum | Yes | One of kvk-jaarrekening, belastingdienst-vpb, belastingdienst-ib, sbr-banken-kredietrapportage, sbr-wonen |
| taxonomyVersion | string | Yes | NL-taxonomie version pinned at generation (e.g. nt17, nt18); immutable after creation |
| reportingPeriodStart | date | Yes | Start of the period covered |
| reportingPeriodEnd | date | Yes | End of the period covered |
| sourceStatementId | string | Yes | FK to FinancialStatement this instance derives from (transformation source, not re-aggregation) |
| mappingId | string | Yes | FK to Mapping record that defines FinancialStatement line → NL-taxonomie concept |
| instanceXml | string | Yes | Generated XBRL instance document as UTF-8 string |
| instanceHash | string | Yes | SHA-256 of canonicalised XML for tamper-evidence |
| state | enum | Yes | One of draft, validated, submitted, accepted, rejected |
| digipoortReceiptId | string | No | Receipt ID from Digipoort; required for accepted state |
| administrationId | string | Yes | FK to Administration (per-administration scope per design D4) |

**Relations:**
- → FinancialStatement (many-to-one, via sourceStatementId — transformation source)
- → Mapping (many-to-one, via mappingId — NL-taxonomie line→concept map)
- → Administration (many-to-one)

> **Reconciliation note (add-shillinq-sbr-xbrl-reporting, 2026-06-03):** `XbrlInstance` is the T4 bookkeeping-tier SBR/XBRL annual filing schema registered in `lib/Settings/shillinq_register.json`. It is distinct from the earlier `XBRLInstance` entry (primary spec: tax-levy-management) which covers tax-levy XBRL documents for NTA7/SBR-NT. New SBR annual reporting declarations MUST use `XbrlInstance`. The `XBRLInstance` entry is retained for legacy tax-levy usage. Key design decisions: (D1) XBRL is a transformation over `FinancialStatement`, not a re-aggregation — single source of truth; (D2) Digipoort submission routes through openconnector Source slug — no embedded SOAP client; (D3) lifecycle declared as `x-openregister-lifecycle` — no PHP `XbrlReportService`; (D4) Mapping records are per-administration so operators may override NL-taxonomie seeds with company-specific extension concepts.

### XBRLTaxonomy
**Schema.org:** `schema:CreativeWork`
_XBRL (eXtensible Business Reporting Language) taxonomy definitions for structured tax reporting, compliance, and regulatory filing. Versionable register of official XBRL GL (General Ledger) taxonomy versions published annually by Belastingdienst; multiple versions coexist so historical and corrective filings retain their original mapping context. Reconciled 2026-06-09 alongside the T3 `bookkeeping-sbr-xbrl-reporting` capability (see SBRDocumentType + XBRLMapping below)._
**Primary spec:** bookkeeping-sbr-xbrl-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyId | string | Yes | Unique taxonomy identifier (e.g. xbrl-gl-2026) |
| name | string | Yes | Human-readable name (e.g. "XBRL GL 2026 — Belastingdienst Official") |
| taxonomyVersion | string | Yes | Official version code (e.g. 2026-01); referenced by XBRLMapping.taxonomyVersion |
| publicationDate | date | Yes | Date Belastingdienst published this taxonomy version |
| effectiveDate | date | Yes | Date taxonomy becomes effective for filings |
| expiryDate | date | No | Date taxonomy is superseded (null = active indefinitely) |
| status | enum | Yes | One of active, archived, deprecated |
| description | string | No | Regulatory reference (e.g. Handboek voor het Financieel Jaarverslag chapter) |

**Relations:**
- → XBRLMapping (one-to-many, via taxonomyVersion)
- → SBRDocumentType (one-to-many, via taxonomyVersion)

### SBRDocumentType
**Schema.org:** `schema:GovernmentService`
_SBR (Standard Business Reporting) filing type per REQ-SBR-003 (jaarverslag, belastingaangifte, VAT declaration). Declares applicable entity types, regulatory filing deadline, mandatory fields, and the Belastingdienst / DNB submission contract (endpoint URL + authentication scheme). Carries the declarative draft → validated → submitted → approved / rejected lifecycle per REQ-SBR-005 with REQ-SBR-006 pre-filing validation guards (GL completeness, mapping coverage, GL balance). T3 declares the contract; T4 implements outbound XBRL generation + Digipoort submission via the openconnector source slug — no app-local PHP service ships._
**Primary spec:** bookkeeping-sbr-xbrl-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Human-readable filing name (e.g. "Jaarverslag") |
| code | string | Yes | Unique filing code (e.g. JAARVERSLAG, BELASTINGAANGIFTE) |
| description | string | No | Filing description and regulatory reference |
| applicableEntityTypes | array | Yes | Entity types requiring this filing (e.g. ["BV", "NV", "Eenmanszaak"]) |
| filingDeadline | date | Yes | Regulatory deadline; notification fires 30 days prior per REQ-SBR-008 |
| requiredFields | array | No | Mandatory GL accounts or data elements (REQ-SBR-006 mandatory-field check) |
| submissionEndpoint | string | Yes | Belastingdienst / DNB endpoint URL (T4 outbound consumer) |
| authMethod | enum | Yes | One of oauth2, mutual-tls, pki-cert, api-key |
| status | enum | Yes | One of draft, validated, submitted, approved, rejected, active, archived |
| administrationId | string | Yes | FK to the Administration this filing applies to |
| taxonomyVersion | string | No | FK to the XBRLTaxonomy.taxonomyVersion selected for this filing |
| fiscalYearStart | date | No | Fiscal year start for the filing period |
| fiscalYearEnd | date | No | Fiscal year end for the filing period |
| validationErrors | array | No | Recorded validation errors blocking the validate transition (REQ-SBR-006) |
| submittedAt | datetime | No | Timestamp when filing was submitted |
| approvedAt | datetime | No | Timestamp when Belastingdienst approved the filing |
| rejectionReason | string | No | Belastingdienst-supplied rejection reason for audit |
| filingId | string | No | Belastingdienst-supplied filing identifier returned on acceptance |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → XBRLTaxonomy (many-to-one, via taxonomyVersion)

### XBRLMapping
**Schema.org:** `schema:DefinedTerm`
_Account-to-XBRL-GL-concept mapping per REQ-SBR-004. Source = Shillinq `Account.id`; target = XBRL GL concept URI from the active `XBRLTaxonomy`. Mappings are version-specific: a given account MAY have different XBRL concept targets across taxonomy versions to track regulatory evolution. The SBRDocumentType pre-filing validation aggregations (`unmappedAccountCount` / `unmappedAccountList` / `mappingCoveragePercent`) query this table to gate the `validate` lifecycle transition per REQ-SBR-006 + REQ-SBR-007._
**Primary spec:** bookkeeping-sbr-xbrl-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sourceAccountId | string | Yes | FK to Account.id |
| targetXBRLConcept | string | Yes | XBRL GL concept URI (e.g. http://xbrl.gl/concept/CurrentAssets) |
| taxonomyVersion | string | Yes | FK to XBRLTaxonomy.taxonomyVersion — mappings are version-scoped |
| mappingDate | date | Yes | Date this mapping was established |
| status | enum | Yes | One of active, archived, pending-review |
| notes | string | No | Mapping rationale, special cases or operator override justification |
| administrationId | string | No | Optional FK to Administration when a mapping is administration-specific (default: global) |

**Relations:**
- → Account (many-to-one, via sourceAccountId)
- → XBRLTaxonomy (many-to-one, via taxonomyVersion)

## Audit Trail Requirements (bookkeeping-rekenkamer-audit-pack)

Per REQ-RAP-001 and ADR-022, **every bookkeeping and procurement register MUST carry
`x-openregister-audit: true`** in its schema declaration in `lib/Settings/shillinq_register.json`.

This rule applies to all T1, T2, T3, and future-tier registers declared by
`bookkeeping-chart-of-accounts`, `accounts-payable-receivable`, `procurement-compliance`,
and any subsequent specs. The `tests/validate-registers.js` CI check enforces this rule
mechanically — any PR adding a new bookkeeping/procurement register without the audit flag
will fail CI.

### Five Audit Surfaces (REQ-RAP-002 through REQ-RAP-006)

| Surface | Navigation entry | OR UI filter |
|---------|-----------------|--------------|
| Signing Audit Trail | Bookkeeping > Signing Audit Trail | `action=signing` on bookkeeping schemas |
| Destruction Report | Bookkeeping > Destruction Report | `lifecycleStatus=marked-for-destruction,destruction-completed` |
| Change History | Bookkeeping > Change History | All mutations on bookkeeping schemas |
| Compliance Export | Bookkeeping > Compliance Export | PII-excluded export; RBAC: auditor group |
| Activity Feed | Bookkeeping > Activity Feed | Nextcloud Activity app |

### Destruction Schedule Lifecycle (REQ-RAP-008, Archiefwet Article 7)

Financial records follow this state machine for Archiefwet-compliant disposal:

```
status: active → status: marked-for-destruction → status: destruction-completed
```

- Records are **never physically deleted** — destruction is a state transition.
- Each transition is hash-chain certified in OR's audit trail.
- Only users with the `compliance-officer` role may trigger destruction transitions.
- Retention period: **7 years** (`selectielijst:5.1.1`, Archiefwet Article 7).

### Anti-Pattern Forbiddance (ADR-022)

The following patterns are REVIEW-BLOCKING in shillinq:

- `lib/Db/Audit*.php` — home-grown audit mapper
- `lib/Service/Audit*.php` — home-grown audit service
- `lib/Db/EventLog*.php` or `lib/Db/ChangeLog*.php` — parallel event tables
- Any app-local audit-event deletion logic

All audit functionality flows through OpenRegister's `audit-trail-immutable` abstraction.

### BBVProgramma
**Schema.org:** `schema:DefinedTerm`
_A BBV programma-indeling entry grouping GL postings by taakveld (gemeente/provincie) or kostentoedeling (waterschap). The `programmaStructure` discriminator controls which classification hierarchy is used per REQ-WSB-002. Declared alongside `WaterschapHeffingPosting` in this change as the T3 `bookkeeping-bbv-compliance` spec shares the same `bbvVariant` overlay. Cross-referencing spec: `bookkeeping-waterschappen-bbv-variant` (add-shillinq-waterschappen-bbv-variant, 2026-06-01)._
**Primary spec:** bookkeeping-waterschappen-bbv-variant

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique programma code within the administration (e.g. 'watersysteembeheer') |
| naam | string | Yes | Human-readable Dutch programma name |
| beschrijving | string | No | Operator-authored description of this programma |
| administrationId | string | Yes | FK to the Administration owning this programma |
| programmaStructure | enum | Yes | One of taakveld, kostentoedeling — discriminator controlling aggregation hierarchy |
| bbvVariant | enum | No | One of gemeente, waterschap, provincie — default gemeente |
| parentCode | string | No | FK to parent BBVProgramma.code for hierarchical navigation |

**Relations:**
- self → BBVProgramma (many-to-one, via parentCode → code; hoofdprogramma hierarchy)
- → GLLine (one-to-many, via postingsByProgramma aggregation honouring programmaStructure discriminator)

### WaterschapHeffingPosting
**Schema.org:** `schema:Invoice`
_Sector-specific belasting posting for the three waterschapsbelastingen (watersysteemheffing, zuiveringsheffing, verontreinigingsheffing). On transition to 'posted', materialises a balanced 2-line GLTransaction per T1 REQ-GL-001 with `sourceReference` pointing back to this posting. Does NOT carry its own ledger lines (D3 from design.md). The `emuExclusionRule` field controls EMU-saldo inclusion per the EMU-bijlage waterschappen handleiding 2026 and is read by the `bookkeeping-emu-reporting` sibling spec. Lifecycle is declarative via `x-openregister-lifecycle` — no PHP service class. Cross-referencing spec: `bookkeeping-waterschappen-bbv-variant` (add-shillinq-waterschappen-bbv-variant, 2026-06-01)._
**Primary spec:** bookkeeping-waterschappen-bbv-variant

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| heffingType | enum | Yes | One of watersysteemheffing, zuiveringsheffing, verontreinigingsheffing |
| aanslagJaar | integer | Yes | Belastingjaar of this aanslag |
| tariefGrondslag | string | Yes | Canonical grondslag for tarief computation (e.g. 'vervuilingseenheden') |
| tarief | number | Yes | Applied tarief per grondslag-eenheid in EUR; minimum 0 |
| aanslagBedrag | number | Yes | Total aanslag amount in EUR; materialised into balanced GLTransaction on post |
| journalEntryId | string | No | FK to the materialised GLTransaction.id; set by lifecycle engine on 'posted' |
| emuExclusionRule | enum | No | One of included, excluded, partial — default included; controls EMU-saldo contribution |
| administrationId | string | Yes | FK to the waterschap Administration owning this posting |
| debitAccountNumber | string | No | Account to debit in materialised GLTransaction |
| creditAccountNumber | string | No | Account to credit in materialised GLTransaction |
| state | enum | Yes | One of draft, posted, reversed |
| description | string | No | Operator-authored description or reference |

**Relations:**
- → GLTransaction (one-to-one, via journalEntryId; materialised on 'posted' transition)
- → Account (many-to-one, via debitAccountNumber → Account.accountNumber)
- → Account (many-to-one, via creditAccountNumber → Account.accountNumber)

### ProvincialeFondsPosting
**Schema.org:** `schema:Invoice`
_Sector-specific posting for provinciale fondsen: provinciefonds, algemene uitkering, decentralisatie-uitkering, and integratie-uitkering. On transition to 'posted', materialises a balanced 2-line GLTransaction per T1 REQ-GL-001 with `sourceReference` pointing back to this posting. Does NOT carry its own ledger lines (design D3). The `fondsType` enum covers the four categories of provinciale uitkeringen from the Rijksoverheid. Lifecycle is declarative via `x-openregister-lifecycle` — no PHP service class. Manifest navigation behind `featureFlags.gov-provincie`. Cross-referencing spec: `bookkeeping-provincies-bbv-variant` (add-shillinq-provincies-bbv-variant, 2026-06-03)._
**Primary spec:** bookkeeping-provincies-bbv-variant

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| fondsType | enum | Yes | One of provinciefonds, algemene-uitkering, decentralisatie-uitkering, integratie-uitkering |
| uitkeringJaar | integer | Yes | Fiscal year for this uitkering |
| uitkeringBedrag | number | Yes | Total uitkering amount in EUR; minimum 0; materialised into balanced GLTransaction on post |
| uitkeringBeschikking | string | No | Beschikkingnummer (official reference number from the relevant ministry) |
| journalEntryId | string | No | FK to the materialised GLTransaction.id; set by lifecycle engine on 'posted' |
| administrationId | string | Yes | FK to the provincie Administration owning this fonds posting |
| debitAccountNumber | string | No | Account to debit in materialised GLTransaction |
| creditAccountNumber | string | No | Account to credit in materialised GLTransaction |
| state | enum | Yes | One of draft, posted, reversed |
| description | string | No | Operator-authored description or reference |

**Relations:**
- → GLTransaction (one-to-one, via journalEntryId; materialised on 'posted' transition)
- → Account (many-to-one, via debitAccountNumber → Account.accountNumber)
- → Account (many-to-one, via creditAccountNumber → Account.accountNumber)

### RetentionRule
**Schema.org:** `schema:DefinedTerm`
_Archiefwet 1995 + Selectielijst Gemeenten 2020 retention rule. A coded retention classifier declaring the statutory retention obligation (period, trigger, disposition) for a category of shillinq-managed records. Seeded from `selectielijst-gemeenten-2020.json`; operators MAY add administration-scoped overrides above the statutory minimum per the local archiefverordening._
**Primary spec:** bookkeeping-archiefwet-retention

> **Per-schema retention-rule reference pattern (add-shillinq-archiefwet-retention, 2026-06-01):**
> Every shillinq schema subject to Archiefwet retention MUST declare an
> `x-openregister-lifecycle.retention.rule` block referencing a `RetentionRule`
> record by `selectielijstCode`. The reference takes the form
> `rule: "selectielijst:<code>"` (e.g. `"selectielijst:5.1.2"`). OpenRegister's
> retention engine reads the rule from the `RetentionRule` register and enforces
> the retention period, disposition, and optional operator override — shillinq does
> NOT implement parallel retention logic per ADR-022 + ADR-031.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| selectielijstCode | string | Yes | Selectielijst classifier code (e.g. 5.1.2, 3.5.1, 1.1.1) |
| description | string | Yes | Plain-Dutch description of the record category |
| recordCategory | enum | Yes | financial, subsidie, personeel, algemeen-bestuur, verantwoording, correspondentie, archief |
| retentionYears | integer | No | Absolute retention in years from record creation date (mutually exclusive with retentionTrigger) |
| retentionTrigger | string | No | Relative retention (e.g. "10 years after vaststellingDate") |
| disposition | enum | Yes | destroy, archive, anonymise, keep_indefinite |
| legalBasis | string | Yes | Citation: Archiefwet article + Selectielijst paragraph |
| effectiveFrom | date | Yes | Date from which this rule is valid |
| effectiveTo | date | No | Date until which this rule is valid (absent = no end date) |
| customRetentionYears | integer | No | Operator extension above statutory minimum (MUST be >= retentionYears; never shorter) |
| administrationId | string | No | Administration scope for per-organisation override rules (absent = applies to all) |
| daysUntilRetention | integer (derived) | No | Days until rule expires per x-openregister-calculations (null for keep_indefinite) |

### SalarisFeed
**Schema.org:** `schema:DataFeed`
_Raw salarisbureau import batch materialised before mapping to balanced JournalEntry records. Decouples "what came in" from "what got booked" and provides an audit trail for reconciliation when a batch fails halfway. Incoming batches arrive via one of four openconnector source rows (ADP / Loket / Visma / Nmbrs). The declarative `x-openregister-mappings` block converts each SalarisFeed record into a balanced `JournalEntry` of subtype `loonkosten` (loonkosten DR = nettoloon CR + sociale-premies CR + loonheffing CR + pensioen CR) without a PHP mapper service per ADR-031. Personnel records inherit the 7-year retention class from the T3 bookkeeping-archiefwet-retention spec (Selectielijst Gemeenten 2020 § 5.1.2)._
**Primary spec:** bookkeeping-detachering-payroll-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| salarisbureauSlug | enum | Yes | FK to the openconnector source slug; one of adp-salaris-nl, loket-salaris-nl, visma-salaris-nl, nmbrs-salaris-nl |
| loontijdvak | string | Yes | Payroll period (YYYY-MM format) |
| employeeId | string | Yes | External employee identifier from the salarisbureau |
| employeeName | string | No | Employee display name for reconciliation reference |
| loonkosten | number | No | Total gross wage cost for this employee in this loontijdvak (DR side) |
| nettoloon | number | No | Net salary (CR side) |
| socialePremies | number | No | Employer social premiums WW/ZW/WAO (CR side) |
| loonheffing | number | No | Wage tax withheld (CR side) |
| pensioen | number | No | Employer pension contribution (CR side) |
| rawPayload | object | Yes | Raw feed payload as received; stored verbatim for audit |
| importState | enum | Yes | One of received, mapped, failed |
| journalEntryId | string | No | Back-reference to the materialised JournalEntry |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- → JournalEntry (many-to-one, via journalEntryId — back-reference after mapping)

### OpdrachtgeversVerklaring
**Schema.org:** `schema:DigitalDocument`
_Wet DBA (Deregulering Beoordeling Arbeidsrelaties) position record per ZZP assignment. Records the opdrachtgever–opdrachtnemer relationship, the risicobeoordeling, and the reference to the applicable Belastingdienst model overeenkomst. The lifecycle (`concept → overeengekomen → beëindigd`) triggers the docudesk template render of the standaard opdrachtgeversverklaring on the `overeenkomen` transition — the generated document URI is written back to `verklaringDocumentUri`. No PHP DBA service per ADR-031. Personnel records inherit the 7-year retention class from the T3 bookkeeping-archiefwet-retention spec._
**Primary spec:** bookkeeping-detachering-payroll-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zzpId | string | Yes | External identifier for the ZZP-er |
| zzpNaam | string | Yes | Full name of the ZZP-er |
| opdrachtBeschrijving | string | Yes | Description of the assignment this verklaring covers |
| looptijdStart | date | Yes | Assignment start date |
| looptijdEind | date | Yes | Assignment end date |
| verklaringStatus | enum | Yes | One of concept, overeengekomen, beëindigd |
| modelOvereenkomst | string | No | URI to the Belastingdienst model overeenkomst used |
| verklaringDocumentUri | string | No | docudesk attachment URI of the generated document |
| risicoBeoordeling | enum | Yes | One of geen, laag, midden, hoog |
| administrationId | string | Yes | FK to the Administration |

### IB47Record
**Schema.org:** `schema:TaxForm`
_Annual IB47 form payload per recipient for submission to the Belastingdienst. The `ontvangerBSN` field is stored encrypted at-rest (`x-openregister-encryption`) and RBAC-restricted: only the `payroll-officer` role may read or write it; every read access is logged to audit-trail-immutable per ADR-022 (AVG + Wet op de loonbelasting requirement). Aggregation over a tax year is declarative via `x-openregister-aggregations` grouping by `(belastingjaar, opdrachtgeverId)`. The reconciliation invariant — final yearly batch totals MUST equal the sum of 12 monthly dry-runs (€0 tolerance) — is declared as an `x-openregister-aggregations.ib47ReconciliationCheck` block. Batch submission flows to the Belastingdienst via the `belastingdienst-ib47-nl` openconnector source referenced from the IB47 docudesk template output-channel per ADR-019. Personnel records inherit the 7-year retention class from the T3 bookkeeping-archiefwet-retention spec (Selectielijst Gemeenten 2020 § 5.1.2)._
**Primary spec:** bookkeeping-detachering-payroll-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| belastingjaar | integer | Yes | Tax year this IB47 record covers |
| opdrachtgeverId | string | Yes | FK to the Administration (opdrachtgever) that made the betalingen |
| ontvangerNaam | string | Yes | Full name of the payment recipient |
| ontvangerBSN | string | Yes | BSN of the recipient — encrypted at-rest; RBAC-read restricted to payroll-officer; every read logged to audit-trail-immutable |
| ontvangerAdres | string | Yes | Full postal address of the recipient |
| betalingenTotaal | number | Yes | Total payments to this recipient in the belastingjaar (EUR ≥ 0) |
| betalingTypeCode | enum | Yes | Belastingdienst IB47 payment type code (1–9 per IB47 schema 2026) |
| isDryRun | boolean | No | True = monthly dry-run; false = final yearly batch record (default false) |
| dryRunMonth | integer | No | Month number (1–12) this dry-run covers; only meaningful when isDryRun = true |
| administrationId | string | Yes | FK to the Administration |

> **Retention note (add-shillinq-detachering-payroll-administratie, 2026-06-03):** `SalarisFeed`, `OpdrachtgeversVerklaring`, and `IB47Record` are personnel-records schemas and inherit the 7-year retention class from the T3 `bookkeeping-archiefwet-retention` spec (Selectielijst Gemeenten 2020 § 5.1.2 — dagelijkse financiële verantwoording). Each schema declares `x-openregister-lifecycle.retention.rule: "selectielijst:5.1.2"` in its register fragment. The `IB47Record.ontvangerBSN` field additionally inherits AVG (GDPR) constraints: encryption at-rest, RBAC read-restriction to `payroll-officer`, and immutable audit-trail logging on every read, consistent with `bookkeeping-archiefwet-retention` REQ-ARC-003 (AVG-stelregels).

### InnovatieboxTariff
**Schema.org:** `schema:DefinedTerm`
_Seeded historic innovatiebox tariff schedule and forfaitair parameters per Wet Vpb art. 12b/12bg. Loaded from `lib/Settings/seeds/innovatiebox-tariefen.json` via `ConfigurationService::importFromApp()`. A future statutory tariff change ships as a new seed file without code changes (REQ-IBA-007). No tariffs are hard-coded in schema enums per ADR-031._
**Primary spec:** bookkeeping-innovatiebox-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| effectiveFrom | integer | Yes | First fiscal year this tariff applies to (inclusive) |
| effectiveTo | integer | No | Last fiscal year this tariff applies to (inclusive); null = open-ended |
| applicableTariff | number | Yes | Statutory tariff as decimal (e.g. 0.09 for 9%) |
| forfaitairPercentage | number | No | Forfaitair profit percentage (0.25 from 2018 per Wet Vpb art. 12bg) |
| forfaitairCapBedrag | number | No | Forfaitair statutory cap in EUR (25000 from 2018 per Wet Vpb art. 12bg) |
| description | string | No | Human-readable label for this tariff period |

### InnovatieboxElection
**Schema.org:** `schema:Event`
_Per-fiscal-year route election for the innovatiebox: forfaitair (Wet Vpb art. 12bg — 25% of operating profit capped at EUR 25 000) or afpelmethode (Wet Vpb art. 12b — explicit per-IP-asset valuation + winsttoerekening). Exactly one election per `(administrationId, fiscalYear)` is enforced by the `electionsPerAdministrationYear` aggregation. The `innovatieboxAdministratie` aggregation computes innovation-attributed profit and Vpb impact per REQ-IBA-003. No PHP method-selector per ADR-031._
**Primary spec:** bookkeeping-innovatiebox-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration |
| fiscalYear | integer | Yes | Fiscal year the election covers |
| route | enum | Yes | `forfaitair \| afpelmethode` — mutually exclusive per (administrationId, fiscalYear) |
| applicableTariff | number | Yes | Innovatiebox tariff for the fiscal year; default 0.09 (2026 statutory per Wet Vpb art. 12b) |
| forfaitairCapBedrag | number | No | Statutory cap EUR 25 000 (required for forfaitair; default 25000 per seed) |
| forfaitairPercentage | number | No | 25% profit attribution (required for forfaitair; default 0.25 per seed) |
| operatingProfit | number | No | Fiscal-year operating profit consumed by the forfaitair aggregation |
| vpbAangifteId | string | No | Optional FK to the Vpb-aangifte this election is attached to |

**Relations:**
- → Administration (many-to-one)
- → IPAssetValuation (one-to-many, afpelmethode only; via administrationId)

> **Annotation (add-shillinq-innovatiebox-administratie, 2026-06-03):** `InnovatieboxElection` is the T4-specialized per-fiscal-year election register for the innovatiebox administratie. The mutual-exclusion invariant (one election per `administrationId + fiscalYear`) is enforced declaratively via the `electionsPerAdministrationYear` aggregation. Cap-application and tariff-application events are recorded in the immutable audit trail via `x-openregister-audit-trail`. The `innovatieboxAdministratie` aggregation branches on `route`: forfaitair computes `min(forfaitairPercentage × operatingProfit, forfaitairCapBedrag)`; afpelmethode sums `WinstToerekening.toegerekendeWinst × applicableTariff` per asset. See `openspec/changes/add-shillinq-innovatiebox-administratie/design.md`.

### IPAssetValuation
**Schema.org:** `schema:Intangible`
_Immaterieel activum qualifying for the innovatiebox under the afpelmethode (Wet Vpb art. 12b). Applies to the afpelmethode route ONLY — forfaitair taxpayers do NOT register per-asset valuations (REQ-IBA-001). Carries `wbsoVerklaringNummer` FK when `assetType: s-en-o-certificaat` (cross-reference to `add-shillinq-wbso-sno-administratie`) and `vpbBalansLinkId` FK to the Vpb-balans (cross-reference to `add-shillinq-vpb-corporate-tax`). No PHP IP-service per ADR-031._
**Primary spec:** bookkeeping-innovatiebox-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetNaam | string | Yes | Human-readable name of the IP asset |
| assetType | enum | Yes | `s-en-o-certificaat \| octrooi \| kwekersrecht \| softwareprogrammatuur \| model-tekening` |
| wbsoVerklaringNummer | string | No | FK to WBSO S&O-verklaring (required when assetType is s-en-o-certificaat) |
| octrooiNummer | string | No | Patent registration number (required when assetType is octrooi) |
| valuationBedrag | number | Yes | Capitalised valuation in euros (≥ 0) |
| valuationDate | date | Yes | Effective valuation date |
| applicableTariff | number | Yes | Innovatiebox tariff at valuationDate; default 0.09 (from InnovatieboxTariff seed) |
| vpbBalansLinkId | string | Yes | FK to VpbBalansLink (REQ-VPB-002 from bookkeeping-vpb-corporate-tax) |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → WinstToerekening (one-to-many, via ipAssetId)
- → Administration (many-to-one)

### WinstToerekening
**Schema.org:** `schema:QuantitativeValue`
_Per-period profit attribution from operating profit to one or more IP assets via a configurable verdeelsleutel. Used by the afpelmethode route of the innovatiebox aggregation (REQ-IBA-004). MUST NOT be populated when `InnovatieboxElection.route` is `forfaitair`. The `verdeelsleutelRatio` calculation is declarative per ADR-031. Three verdeelsleutel methods: `omzet-aandeel` (revenue share), `r-en-d-uren` (R&D hours), `custom-formula` (arbitrary JSON parameters)._
**Primary spec:** bookkeeping-innovatiebox-administratie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ipAssetId | string | Yes | FK to IPAssetValuation.id |
| periodId | string | Yes | FK to FiscalPeriod |
| toegerekendeWinst | number | Yes | Profit attributed to the IP asset for this period in euros (≥ 0) |
| verdeelsleutel | enum | Yes | `omzet-aandeel \| r-en-d-uren \| custom-formula` |
| parameters | object | No | Verdeelsleutel parameters: `{totalRevenue, ipRevenue}` for omzet-aandeel; `{totalHours, ipHours}` for r-en-d-uren; arbitrary JSON for custom-formula |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → IPAssetValuation (many-to-one, via ipAssetId)

### VendorMaster
**Schema.org:** `schema:Organization`
_Vendor party record for accounts payable. Holds bank IBAN, payment terms, tax registration, and dunning-policy reference for a vendor within a single administration. Per REQ-AP-002._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorNumber | string | Yes | Stable vendor identifier unique per administration |
| name | string | Yes | Legal name of the vendor |
| tradingName | string | No | Alternate or DBA trading name |
| kvkNumber | string | No | Dutch KvK number (8 digits) |
| btwNumber | string | No | Dutch BTW / EU VAT number |
| iban | string | No | Default IBAN for outgoing payments to this vendor |
| bic | string | No | BIC/SWIFT code matching the IBAN bank |
| paymentTermDays | integer | Yes (default 30) | Default payment term in days; auto-sets APInvoice.dueDate |
| defaultExpenseAccountNumber | string | No | FK to Account.accountNumber for default expense coding |
| address | object | No | Street/number/postcode/city/country |
| email | string | No | Primary contact email for invoice queries |
| phone | string | No | Primary contact phone |
| dunningPolicyId | string | No | FK to OR dunning-workflow policy record per ADR-022 |
| contactRef | string | No | FK to OR contact abstraction if stable per ADR-022; else null |
| administrationId | string | Yes | FK to the administration owning this vendor record |
| lifecycleState | enum | Yes | One of active, blocked, archived |

**Relations:**
- → APInvoice (one-to-many, open invoices from this vendor)
- → Administration (many-to-one)

> **Reconciliation note (add-shillinq-accounts-payable-core, 2026-06-03):** No earlier `Vendor` or `VendorMaster` entry existed in this ADR. `VendorMaster` is the new T2 canonical vendor party register declared in `lib/Settings/shillinq_register.json`. Fields `purchaseOrderRef`/`goodsReceiptRef` on `APInvoice` are declared as FK stubs for future T4 procurement attachment; no PO/GR register exists yet. Per ADR-022, approval routing for AP invoices comes from OR's approval-workflow, not from an app-local approver table.

### APInvoice
**Schema.org:** `schema:Invoice`
_Accounts payable sub-ledger invoice recording vendor billing and payment obligation. Posting materialises a balanced GLTransaction per T1 REQ-JE-007. Lifecycle: draft → pending → approved → posted → paid with disputed/voided branches. Approval routing consumes OR approval-workflow per ADR-022 (no app-local approval table). Per REQ-AP-003 and REQ-AP-004._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Shillinq-side reference (auto-generated per administration) |
| vendorInvoiceRef | string | Yes | The vendor's own invoice number as it appears on the document |
| vendorId | string | Yes | FK to VendorMaster UUID |
| invoiceDate | date | Yes | Date on the vendor's invoice |
| dueDate | date | Yes | Auto-calculated from invoiceDate + vendor.paymentTermDays; overrideable |
| currency | string (ISO 4217) | Yes | T2: base currency only; T5 adds multi-currency |
| totalAmount | number ≥ 0 | Yes | Total amount including tax |
| taxAmount | number | No | VAT/BTW amount (T3 adds posting automation; T2 carries the field) |
| lines | array | Yes | {description, accountNumber, amount, taxCode, quantity, unitPrice} rows |
| sourceDocumentUri | string | No | docudesk FK URI per bookkeeping-document-attachment-integration |
| purchaseOrderRef | string | No | FK to PO register (future T4 procurement; nullable in T2) |
| goodsReceiptRef | string | No | FK to Goods Receipt register (future T4; nullable in T2) |
| approvalState | enum | Yes | One of not-required, pending, approved, rejected |
| state | enum | Yes | One of draft, pending, approved, posted, paid, disputed, voided |
| glTransactionId | string | No | Back-reference to materialised GLTransaction once posted |
| idealLink | string | No | Per-invoice iDEAL payment link (x-openregister-calculations output) |
| periodId | string | No | FK to FiscalPeriod (resolved on post transition) |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → VendorMaster (many-to-one, via vendorId)
- → GLTransaction (many-to-one, via glTransactionId — materialised on post)
- → Administration (many-to-one)

> **Reconciliation note (add-shillinq-accounts-payable-core, 2026-06-03):** The existing `APTransaction` entry (primary spec: accounts-payable-receivable) is a generic AP/AR transaction schema. `APInvoice` is the shillinq bookkeeping-tier AP sub-ledger invoice with full lifecycle, GL materialisation, 3-way match guard, and SEPA/iDEAL calculation fields. The `APTransaction` entry is retained for generic accounts-payable-receivable usage; new AP bookkeeping register declarations in shillinq MUST use `APInvoice`. `GLLine.subLedgerType: "ap"` + `subLedgerRef: <APInvoice UUID>` (T1 REQ-GL-009 stub) now resolves to this register.

### PaymentRun
**Schema.org:** `schema:PaymentService`
_Operator-curated batch of selected APInvoice UUIDs producing SEPA pain.001.001.03 XML and iDEAL payment links as x-openregister-calculations outputs. No PaymentRunService, SepaXmlBuilder, or IdealLinkBuilder PHP classes per ADR-031. Live PSD2 bank initiation is T4. Per REQ-AP-007._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| runNumber | string | Yes | Sequential identifier per administration |
| runDate | date | Yes | Scheduled execution date |
| invoiceRefs | array of string | Yes | List of APInvoice UUIDs to include |
| totalAmount | number | Yes (calculated) | Sum of selected invoices' totalAmount (x-openregister-calculations) |
| paymentMethod | enum | Yes | One of sepa-pain001, ideal |
| sepaXml | string | Yes (calculated) | pain.001.001.03 XML (x-openregister-calculations; populated when paymentMethod=sepa-pain001) |
| idealLinks | array of object | Yes (calculated) | {invoiceRef, url, amount, expiresAt} per invoice (x-openregister-calculations; when paymentMethod=ideal) |
| state | enum | Yes | One of draft, ready, submitted, executed, failed |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → APInvoice (many-to-many, via invoiceRefs array)

- → Administration (many-to-one)

### ExpenseClaimEntry
**Schema.org:** `schema:Invoice`
_Shillinq bookkeeping-tier expense claim grouping N receipts, mileage journeys, and per-diem records into a single approval-and-reimbursement batch. Lifecycle: draft → submitted → approved → posted → reimbursed with disputed/voided branches. Approval routing consumes OR approval-workflow per ADR-022 (no app-local approval table). Posting materialises a balanced GLTransaction per T1 REQ-JE-007 pattern._
**Primary spec:** expense-capture-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| claimNumber | string | Yes | Shillinq-side sequential ID per administration (e.g. EXP-2026-0001) |
| employeeId | string | Yes | FK to Person (employee submitting the claim) |
| submittedDate | datetime | No | Timestamp when claim was formally submitted; set by submit lifecycle transition |
| fromDate | date | Yes | Start date of expense period covered |
| toDate | date | Yes | End date of expense period covered |
| totalAmount | number | Yes | Auto-aggregated sum: Receipt.amountInBaseCurrency + MileageEntry.totalAmount + PerDiem.allowanceAmount (always EUR in T2) |
| currency | string | Yes | Always EUR (base currency) in T2; T5 adds multi-currency claims |
| description | string | No | Claim summary or business purpose |
| receiptIds | array | No | FKs to linked Receipt records |
| mileageIds | array | No | FKs to linked MileageEntry records |
| perDiemIds | array | No | FKs to linked PerDiem records |
| approvalState | enum | Yes | One of not-required, pending, approved, rejected — managed by OR approval-workflow engine |
| state | enum | Yes | One of draft, submitted, approved, posted, reimbursed, disputed, voided |
| glTransactionId | string | No | Back-reference to materialised GLTransaction UUID once posted |
| costCentreAllocations | object | No | Claim-wide cost centre allocation map ({costCentreCode: percentage}); per-line codes take precedence |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → Receipt (one-to-many, via receiptIds)
- → MileageEntry (one-to-many, via mileageIds)
- → PerDiem (one-to-many, via perDiemIds)
- → GLTransaction (many-to-one, via glTransactionId — materialised on post)
- → Administration (many-to-one)

> **Reconciliation note (expense-capture-core, 2026-06-03):** The earlier `ExpenseClaim` entry (primary spec: approval-workflow-management) is a generic claim schema. `ExpenseClaimEntry` is the canonical bookkeeping-tier entity with full lifecycle, OR approval-workflow integration, GL materialisation, and cost-centre allocation. New expense-capture implementations MUST use `ExpenseClaimEntry`.

### MileageEntry
**Schema.org:** `schema:Thing`
_Journey log capturing distance travelled, vehicle type, and applicable reimbursement rate per kilometre. totalAmount is auto-calculated as distance × ratePerKm from the MileageRate master table. Linked to a parent ExpenseClaimEntry for approval and reimbursement._
**Primary spec:** expense-capture-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| mileageNumber | string | Yes | Shillinq-side sequential ID per administration (e.g. MLG-2026-0001) |
| journeyDate | date | Yes | Date the journey occurred |
| fromLocation | string | Yes | Starting address or city |
| toLocation | string | Yes | Ending address or city |
| distance | number | Yes | Distance in kilometres (manual entry in T2; optional geocoding in T3); must be positive |
| vehicleType | enum | Yes | One of: car, motorcycle, van, bicycle |
| ratePerKm | number | Yes | Rate in EUR/km looked up from MileageRate master table (fiscalYear, vehicleType, country); locked at claim submission |
| totalAmount | number | Yes | Auto-calculated: distance × ratePerKm |
| purpose | string | No | Reason for travel |
| claimId | string | No | FK to parent ExpenseClaimEntry.id; null until added to a claim |
| costCentreCode | string | No | Cost centre for GL line allocation |
| administrationId | string | Yes | FK to administration |

**Relations:**
- → ExpenseClaimEntry (many-to-one, via claimId)
- → MileageRate (many-to-one, rate lookup by fiscalYear, vehicleType, country)
- → Administration (many-to-one)

### MileageRate
**Schema.org:** `schema:PriceSpecification`
_Master data table of reimbursement rates per kilometre by fiscal year, vehicle type, and tax jurisdiction. Seeded with NL 2026 (car €0.21/km, motorcycle €0.16/km) and FI 2026 (car €0.42/km) rates. Operators maintain rates per fiscal year; MileageEntry.ratePerKm is locked at claim submission._
**Primary spec:** expense-capture-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| fiscalYear | integer | Yes | Fiscal year this rate applies to |
| country | string | Yes | ISO 3166-1 alpha-2 country code |
| vehicleType | enum | Yes | One of: car, motorcycle, van, bicycle |
| ratePerKm | number | Yes | Rate per kilometre in EUR |
| notes | string | No | Source or regulatory reference |

### PerDiemRate
**Schema.org:** `schema:PriceSpecification`
_Master data table of official daily travel allowance rates per country and calendar year. Seeded with NL 2026 (€125/day), FI 2026 (€45/day), and US 2026 (€150/day) rates. Operators maintain rates per calendar year; PerDiem.dailyRate is locked at claim submission._
**Primary spec:** expense-capture-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| calendarYear | integer | Yes | Calendar year this rate applies to |
| country | string | Yes | ISO 3166-1 alpha-2 country code |
| dailyRate | number | Yes | Official daily allowance rate in the stated currency |
| currency | string | Yes | ISO 4217 currency code (almost always EUR for NL/FI) |
| source | string | No | Regulatory source for this rate |

### AnnualReport / BalanceSheet / IncomeStatement / CashFlowStatement / Note / DirectorReport / ReviewWorkflow
**Schema.org:** `schema:Report` (AnnualReport, DirectorReport), `schema:MonetaryAmount` (BalanceSheet, IncomeStatement, CashFlowStatement), `schema:Comment` (Note), `schema:Action` (ReviewWorkflow)
_Titel 9 Boek 2 BW jaarrekening (annual financial statement) data model introduced by the `bookkeeping-titel-9-jaarrekening` change (T3). `AnnualReport` is the root per administration + boekjaar: it carries the computed groottecategorie (art. 2:395a–398 BW), the rapportagegrondslag, and the concept → opgemaakt → in-review → vastgesteld → gedeponeerd lifecycle with its wettelijke termijnen (art. 2:391 BW). `BalanceSheet` (art. 2:373 BW rubrieken) and `IncomeStatement` (art. 2:377 BW model A/E) are generated from GL aggregations; the GL-account → wettelijke-rubriek mapping is configuration (`lib/Settings/seeds/balans-rubriek-mapping.json`, `vw-model-rubrieken.json`), not code. `CashFlowStatement` (RJ 350, indirect method) is mandatory for middelgroot+. `Note` is a single toelichting-paragraaf keyed to a RJ guideline (`lib/Settings/seeds/toelichting-templates.json`). `DirectorReport` (bestuursverslag, art. 2:391 BW) is a separate entity for independent signing. `ReviewWorkflow` orchestrates the accountant-review progression with an immutable comment/handover log. All seven are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-titel-9-jaarrekening.json`); no parallel service classes ship (ADR-022/ADR-031). The opmaken (balans must balance) and vaststellen (accountantsverklaring required for middelgroot+) preconditions are the only PHP exception-path code — `lib/Lifecycle/AnnualReportGuard.php` per ADR-031. This module integrates additively with T1 `bookkeeping-financial-statements`/`bookkeeping-grootboek` (GL/rubriek data sources) and hands the final snapshot to T3 `bookkeeping-sbr-xbrl-reporting` for SBR-XBRL conversion and KVK Digipoort filing (REQ-T9-008)._
**Primary spec:** bookkeeping-titel-9-jaarrekening

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| AnnualReport | schema:Report | administrationId, boekjaarStart/Eind, groottecategorie, rapportageGrondslag, status | → BalanceSheet/IncomeStatement/CashFlowStatement/DirectorReport/ReviewWorkflow (1:1), → Note (1:N) |
| BalanceSheet | schema:MonetaryAmount | reportId, balansDate, rubrieken[] (art. 2:373 BW codes), totalActiva/Passiva | → AnnualReport (N:1) |
| IncomeStatement | schema:MonetaryAmount | reportId, model (A/E art. 2:377 BW), rubrieken[], nettoresultaat | → AnnualReport (N:1) |
| CashFlowStatement | schema:MonetaryAmount | reportId, method (indirect/direct, RJ 350), three kasstroom-categorieën | → AnnualReport (N:1) |
| Note | schema:Comment | reportId, code, titel, wettelijkeBasis, contentJSONB | → AnnualReport (N:1) |
| DirectorReport | schema:Report | reportId, secties[] (art. 2:391 BW), ondertekenaars[] | → AnnualReport (1:1) |
| ReviewWorkflow | schema:Action | reportId, huidigStap, stappenArray[] (immutable comments), overdrachtenLog[] | → AnnualReport (1:1) |

### AccountingFramework / ChartOfAccountsMapping / DualTransaction / ReconciliationBridge / StandardSpecificCalculation / FrameworkElection
**Schema.org:** `schema:DefinedTerm` (AccountingFramework), `schema:PropertyValue` (ChartOfAccountsMapping), `schema:MonetaryAmount` (DualTransaction, ReconciliationBridge), `schema:Quantity` (StandardSpecificCalculation), `schema:Action` (FrameworkElection)
_Dual GAAP reporting (IFRS naast Nederlandse Richtlijnen voor de Jaarverslaggeving) data model introduced by the `bookkeeping-ifrs-rj-dual-gaap` change (T3). `AccountingFramework` is master data: a reporting framework (IFRS-EU, NL-GAAP-RJ, US-GAAP) with version, effective date, jurisdictions, regulator and base currency — the basis for parallel-ledger materialisation. `ChartOfAccountsMapping` maps a source RJ account to one-or-more target IFRS accounts with an allocation rule (percentage/formula/ratio-driver); activation is blocked until a test-data reconciliation reaches ≥95% coverage or an exception is documented (REQ-DGAAP-002). `DualTransaction` links a base GL transaction to its parallel RJ and IFRS journal entries with the divergence amount, a standard-specific reason code (LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, REVENUE_IFRS15, IMPAIRMENT_IAS36, BORROWING_COST_IAS23, DEFERRED_TAX_IAS12, BUSINESS_COMBINATION_IFRS3) and a permanence classification (permanent/temporary/reclassification) that drives IAS 12 deferred tax (REQ-DGAAP-003/006); its lifecycle is open → classified → reconciled. `ReconciliationBridge` is the RJ-to-IFRS conversion for one period and metric (equity / net result): opening RJ balance, adjustments per standard, per-jurisdiction tax effect and closing IFRS balance — a declarative aggregation over the period's reconciled DualTransactions (REQ-DGAAP-005). `StandardSpecificCalculation` stores the supporting calculation behind each divergence (IFRS-16 IBR, IAS-19 PUC, IFRS-9 ECL staging, IFRS-15 revenue) with inputs, outputs, revaluation frequency, actuary signoff and an audit-evidence URI (REQ-DGAAP-004/008). `FrameworkElection` records a legal entity's primary-framework election with comply-or-explain motivation, RJ variant (RJ-onverkort/RJk/IFRS-volledig), measured size criteria and AVA-besluit reference; lifecycle draft → active → superseded (REQ-DGAAP-010). All six are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-ifrs-rj-dual-gaap.json`); the only PHP exception-path code is `lib/Lifecycle/DualGaapGuard.php` (ADR-031) — a DualTransaction may only be reconciled with a reason code plus, for temporary differences, a deferred-tax effect; a FrameworkElection may only activate with motivation + AVA reference + a variant consistent with the measured size. The reconciliation bridge is drill-down navigable bridge-line → StandardSpecificCalculation → GL entries → audit-trail via OR relation FKs (REQ-DGAAP-008). This module extends T1 GL materialisation to dual-post per framework and feeds T3 `bookkeeping-consolidation` (multi-entity framework conversion) and T4 `bookkeeping-financial-statements` (side-by-side RJ + IFRS statements with reconciliation toelichting)._
**Primary spec:** bookkeeping-ifrs-rj-dual-gaap

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| AccountingFramework | schema:DefinedTerm | identifier, version, effectiveDate, jurisdictions[], regulator, baseCurrencyDefault | → administration (N:1) |
| ChartOfAccountsMapping | schema:PropertyValue | sourceAccount, targetAccounts[], mappingType, allocationRule, coveragePercent | → Account (source + targets) |
| DualTransaction | schema:MonetaryAmount | baseTransactionId, rj/ifrsJournalEntries[], divergenceAmount, divergenceReasonCode, divergenceClassification, deferredTaxEffect, state | → GLTransaction (N:1), → StandardSpecificCalculation (1:N) |
| ReconciliationBridge | schema:MonetaryAmount | period, from/toFramework, metric, openingBalanceRj, adjustments[], taxEffect[], closingBalanceIfrs | → StandardSpecificCalculation (1:N via adjustments) |
| StandardSpecificCalculation | schema:Quantity | standardCode, calculationMethod, inputs, outputs, revaluationFrequency, actuarySignoff, auditEvidenceUri | → DualTransaction (N:1) |
| FrameworkElection | schema:Action | legalEntityId, primaryFramework, rjVariant, sizeCriteria*, avaBesluitReference, state | → Entity (N:1) |

### Order / Invoice / InvoiceLine / CreditNote / DepositPayment (booking deposit-to-invoice)
**Schema.org:** `schema:Order` (Order), `schema:Invoice` (Invoice, CreditNote), `schema:TradeLineItem` (InvoiceLine), `schema:PaymentChargeSpecification` (DepositPayment)
_Booking deposit-to-invoice data model introduced by the `bookings-deposit-to-invoice` change (T2). When a booking `Order` transitions `confirmed → completed` (operator confirms fulfilment), the declarative lifecycle materialises exactly one final `Invoice` in Shillinq with `InvoiceLine` children: a positive service line at the order VAT rate plus, when a deposit was authorised, a negative 0%-VAT deposit-credit line (REQ-DI-003/004). The customer owes the net gross (service-with-VAT minus the already-paid deposit). Bidirectional traceability is preserved: `Order.invoiceId → Invoice`, `Invoice.sourceDocumentUri = urn:nextcloud:booking:order:{orderId}`, `Invoice.depositPaymentId → DepositPayment` (REQ-DI-001). `sourceDocumentUri` doubles as the invoice idempotency key, so retried completions never duplicate an invoice (REQ-DI-002). Cancelling an already-invoiced order (`completed → cancelled`) materialises one reversing `CreditNote` for the full invoice gross while preserving the original invoice for audit (REQ-DI-006). `Order` and `DepositPayment` are owned by the booking module / `bookings-deposits` (T1) and declared here as the dependency surface; `Invoice`, `InvoiceLine`, and `CreditNote` are the AR entities this change drives. All five are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookings-deposit-to-invoice.json`); the only PHP exception-path code is the two ADR-031 lifecycle guards — `lib/Lifecycle/InvoiceFromBookingGuard.php` (completion precondition + deposit-credit / line / VAT / due-date composition) and `lib/Lifecycle/BookingCancellationGuard.php` (cancellation precondition + reversing credit-note composition). No `InvoiceService.php` orchestration layer ships (ADR-031). VAT is charged on the service line only; the deposit credit carries 0% VAT because it was already taxed at collection, matching Dutch invoicing practice (D4)._
**Primary spec:** bookings-deposit-to-invoice

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Order | schema:Order | administrationId, orderId, customerId, state (draft→confirmed→completed→cancelled), depositPaymentId, invoiceId, completedAt, paymentTermsDays | → Invoice (1:1), → DepositPayment (N:1) |
| Invoice | schema:Invoice | administrationId, invoiceId, invoiceNumber, customerId, sourceDocumentUri, depositPaymentId, netAmount/vatAmount/grossAmount, state | → Order (N:1), → DepositPayment (N:1) |
| InvoiceLine | schema:TradeLineItem | administrationId, invoiceId, lineNumber, lineType (service/deposit_credit), lineAmount, taxRate, taxAmount, grossAmount | → Invoice (N:1) |
| CreditNote | schema:Invoice | administrationId, creditNoteId, creditNoteNumber, linkedInvoiceId, customerId, creditDate, grossAmount, state | → Invoice (N:1) |
| DepositPayment | schema:PaymentChargeSpecification | administrationId, depositPaymentId, orderId, amount, refundPolicy, state (authorized/captured/refunded) | → Order (N:1) |

### Administration / AdministrationMembership / IntercompanyJournalEntry / ConsolidationMapping / AdministrationMigration (multi-administratie)
**Schema.org:** `schema:Organization` (Administration), join-record (AdministrationMembership), `schema:Action` (IntercompanyJournalEntry, AdministrationMigration), `schema:PropertyValue` (ConsolidationMapping)
_Foundational multi-administratie (multi-tenant) data model introduced by the `bookkeeping-multi-administratie` change (T1 foundational refactor). `Administration` becomes the **first-class isolation boundary**: it is the juridisch-onafhankelijke boekhouding entity, and the `administrationId` FK that the existing financial schemas already carry (GLTransaction, GLLine, Account, BalanceSheet, FixedAsset, TrialBalanceLine, Order, Invoice, …) now points at it as a real register record rather than a bare string. Each `Administration` owns its own chart-of-accounts (`chartOfAccountsId`), fiscal-year cycle (`fiscalYearStartMonth` + `nonCalendarFiscalYear`), presentation/functional currency, VAT regime + filing frequency, holding linkage (`parentAdministrationId` / `childAdministrationIds` / `consolidateIntoId`), fiscal-unit references (`fiscalUnitVpbId` / `fiscalUnitVatId` — data only; VPB/BTW consolidation logic is delegated to bookkeeping-tax-filing / bookkeeping-vat-return), and a per-administratie backup + retention + archival lifecycle (`status` actief → in_liquidatie → gearchiveerd/opgeheven; archived administrations are read-only for the wettelijke bewaartermijn). `AdministrationMembership` is the **user-administratie-role join** — a Nextcloud uid plus a role (eigenaar/controller/boekhouder/inkijker/accountant_extern/…) and posting/closing rights — so one user may hold a different role per administration; a contact/person is a Nextcloud entity, no person schema is invented. `IntercompanyJournalEntry` links the **two mirrored, self-contained GLTransactions** of an intercompany flow across two administrations (Dutch GAAP keeps each entity's journaal separate): it tracks the `intercompanyNumber`, reconciliation `varianceAmount`, the `eliminateOnConsolidation` flag and the status concept → gekoppeld → bevestigd_beide → eliminatie_geboekt. `ConsolidationMapping` maps a dochter's chart-of-accounts onto the moeder's plus the intercompany elimination account and currency-translation method (pre-positioned for the future `bookkeeping-consolidatie` spec; no consolidation rendering ships here). `AdministrationMigration` is the **asset/contract/employee transfer audit record** between two administrations (boekwaarde vs marktwaarde, juridische grondslag, fiscale behandeling, paired journal-entry FKs, reversible status voorbereid → uitgevoerd → geboekt_beide → teruggedraaid). All five are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-multi-administratie.json`) plus declarative manifest-v2 index/detail navigation (ADR-037 fragment `src/manifest.d/bookkeeping-multi-administratie.json`); object CRUD runs through OpenRegister's own ObjectService API (this app exposes no per-schema PHP controllers — `administrationId` isolation is applied by the read services via `findAll(['filters' => ['administrationId' => …]])`, e.g. `TrialBalanceService`). The administratie-aware RBAC/isolation layer ships as `lib/Service/AdministrationContextService.php` (resolves the user's `AdministrationMembership` records, builds the session context, and provides the IDOR `canAccess()` guard — masked-404 never 403 — and the default-secure `canPostJournalEntry()` check). The intercompany mirroring/reconciliation logic ships as the pure, unit-tested `lib/Service/IntercompanyJournalService.php` (cents-based mirror, reconciliation variance, and the concept → gekoppeld → bevestigd_beide → eliminatie_geboekt status machine). The context/switcher/export-scope API ships as `lib/Controller/AdministrationController.php` (`GET /api/administrations/context`, `POST /api/administrations/switch`, `GET /api/administrations/{id}/export-scope`, all `#[NoAdminRequired]` and scoped to the user's memberships, ADR-005). The in-session switcher Vue component, the per-administratie backup-scheduler side-effect, the archival write-block guard, the XAF byte stream, the migration dual-post flow and the cross-administratie audit viewer are deferred to a follow-up cycle against a live OpenRegister instance (tracked in this change's tasks.md "Deferred" section). The existing `ConsolidationGroup` / `ConsolidatedReport` schemas are unchanged and complementary. The default `Administration` (ADM-001) is seeded idempotently on fresh install (`SettingsService::seedDefaultAdministration()` via the `InitializeSettings` repair step, deduped on `administrationCode`) so single-administratie installs have a valid FK target._
**Primary spec:** bookkeeping-multi-administratie

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Administration | schema:Organization | administrationCode, name, legalForm, kvkNumber, vatNumber, parentAdministrationId, childAdministrationIds[], chartOfAccountsId, fiscalYearStartMonth, vatRegime, status, backupSchedule, dataRetentionYears | → Administration (parent, N:1), → ConsolidationMapping (N:1) |
| AdministrationMembership | join-record | userId, administrationId, role, mayPostJournalEntries, mayCloseFiscalYear, validFrom/validUntil, grantedBy | → Administration (N:1), → NC user (uid) |
| IntercompanyJournalEntry | schema:Action | intercompanyNumber, date, kind, source/destinationAdministrationId, source/destinationJournalEntryId, amount, vatTreatment, eliminateOnConsolidation, status, varianceAmount | → Administration (source + destination), → GLTransaction (both sides) |
| ConsolidationMapping | schema:PropertyValue | name, source/destinationAdministrationId, rules[] (sourceAccount→destinationAccount), intercompanyEliminationAccount, currencyTranslationMethod | → Administration (source + destination) |
| AdministrationMigration | schema:Action | migrationNumber, date, source/destinationAdministrationId, kind, objectIds[], bookValue/marketValueTransferred, fiscalTreatment, legalBasis, source/destinationJournalEntryId, status | → Administration (source + destination), → GLTransaction (both sides) |

### Resource
**Schema.org:** `schema:Thing`
_Bookable resource (staff member, room, equipment, furniture, or other) tracked by the per-resource calendar layer. The `bookings-resource-calendar` change extends the initial `Resource` declaration introduced by `bookings-create-appointment` (REQ-BCA-002) with an explicit `type` classifier so calendar consumers can render type-aware iconography and filter by resource type. The `openingTime` / `closingTime` / `allowOverlap` operational-hours fields stay on `Resource` for backwards compatibility; richer per-day templates live on the new `Calendar` entity. Multi-tenant isolation is enforced via `administrationId` per ADR-005. Status follows the standard active → inactive → archived OR lifecycle._
**Primary spec:** bookings-resource-calendar

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| resourceId | string | Yes | Operator-assigned unique resource identifier within the administration |
| type | enum | Yes | One of staff, room, equipment, furniture, other (REQ-001 of bookings-resource-calendar) |
| name | string | Yes | Human-readable resource name (e.g. "Jan Peeters", "Vergaderruimte A") |
| openingTime | string | No | Legacy daily operational start time HH:MM UTC (carried from bookings-create-appointment). Null = no restriction. |
| closingTime | string | No | Legacy daily operational end time HH:MM UTC. Null = no restriction. |
| allowOverlap | boolean | No | When true, double-booking is permitted for this resource (e.g. group trainer). Defaults to false. |
| status | enum | Yes | One of active, inactive, archived |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Calendar (one-to-many, via Calendar.resource)
- → Booking (one-to-many, via Booking.resource — denormalised for query efficiency)

### Calendar
**Schema.org:** `schema:Schedule`
_Per-resource calendar carrying time-zone and weekly working-hours configuration. Introduced by `bookings-resource-calendar`. Each Calendar is bound to exactly one Resource via the `resource` FK (multi-resource calendars are deferred to Tier-2). The `timeZone` is an IANA identifier (default `Europe/Amsterdam` for Dutch context); the API/UI converts UTC booking times into the calendar's zone for display. `workingHours` is an optional JSON template keyed by weekday — null entries mean the resource is closed that day; missing keys mean unrestricted availability. The OR-built-in lifecycle (`status`: active → inactive → archived) is used as a soft-delete signal so historical bookings stay queryable._
**Primary spec:** bookings-resource-calendar

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| calendarId | string | Yes | Operator-assigned unique calendar identifier within the administration |
| resource | string | Yes | FK to Resource.resourceId — the resource this calendar represents |
| timeZone | string | Yes | IANA time zone identifier (default "Europe/Amsterdam") |
| workingHours | object | No | JSON template `{monday: "09:00-17:00", ..., saturday: null, sunday: null}`. Null per-day = closed; missing = 24/7. |
| status | enum | Yes | One of active, inactive, archived |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Resource (many-to-one, via resource)
- → Booking (one-to-many, via Booking.calendar)

### Booking
**Schema.org:** `schema:Reservation`
_A scheduled appointment on a resource calendar. Introduced by `bookings-resource-calendar`. Distinct from the existing `Appointment` entity (bookings-create-appointment): `Appointment` is the customer/service-driven product (booking → invoice flow with FK to Service and to a customer Contact), whereas `Booking` is the lower-level calendar-cell record used by the per-resource calendar UI and the conflict-detection engine. The two co-exist; the conflict-detection service (REQ-004) reads both during overlap checks so the calendar view never double-books a resource regardless of the entry channel. All times are stored in UTC (ISO 8601, REQ-008). The `resource` FK is denormalised from `calendar.resource` for query efficiency (design D4); the API enforces the invariant on write. Status: `pending` is the conflicting / unconfirmed state used by the UI for red-highlighting; `confirmed` is the live state that blocks the slot; `cancelled` releases the slot without deleting the audit record._
**Primary spec:** bookings-resource-calendar

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| bookingId | string | Yes | Operator-assigned unique booking identifier within the administration |
| calendar | string | Yes | FK to Calendar.calendarId |
| resource | string | Yes | FK to Resource.resourceId — denormalised from calendar.resource for direct query (design D4) |
| title | string | Yes | Booking title (e.g. "Klant: Anna de Wit") |
| startTime | datetime | Yes | ISO 8601 UTC appointment start (REQ-008) |
| endTime | datetime | Yes | ISO 8601 UTC appointment end. Duration must be ≥15 minutes (REQ-007). |
| attendee | string | Yes | Attendee display name or external reference (free-text or Contact UID) |
| status | enum | Yes | One of pending, confirmed, cancelled |
| externalId | string | No | Optional external calendar event id (Google/Outlook), for future Tier-3 sync |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Calendar (many-to-one, via calendar)
- → Resource (many-to-one, via resource — denormalised, must equal Calendar.resource)

### AvailabilityRule
**Schema.org:** `schema:OpeningHoursSpecification`
_Per-resource availability rule header introduced by `bookings-availability-rules` (REQ-BAR-001). Owns the resource FK, a three-state status lifecycle (`draft → active → archived` with a `reactivate` reverse edge from `archived → draft`), and the effective-date window. Together with its child `ResourceBreak` and `BookingConstraint` records it expresses the declarative answer to "when can this resource be booked?". The three-schema split (rule header + recurring break pattern + booking-policy constraint) was deliberately chosen over a flat single-schema model so breaks and constraints can vary in cardinality, mirroring competitor evidence (Cal.com, Cogsworth, Easy-Appointments, Salonized, Resy). Effective-date semantics: a rule that is `active` but whose `effectiveFrom` is in the future is queryable yet does not yet constrain bookings; an `effectiveUntil` in the past stops constraining bookings even while the lifecycle status remains `active` (the operator-driven `archive` transition is independent and audit-friendly). Multi-tenant isolation via `administrationId` per ADR-005. Lifecycle is fully declarative per ADR-031; no app-side state machine ships at Tier 1._
**Primary spec:** bookings-availability-rules

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| availabilityRuleId | string | Yes | Operator-assigned unique rule identifier within the administration |
| resource | string | Yes | FK to Resource.resourceId (declared by bookings-resource-calendar) |
| status | enum | Yes | One of draft, active, archived; default draft |
| effectiveFrom | date | No | Date the rule begins to apply; null = applies immediately on activation |
| effectiveUntil | date | No | Date the rule stops applying; null = permanent until archived |
| description | string | No | Administrator notes (e.g. "Standaard beschikbaarheid", "Zomervakantie") |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → Resource (many-to-one, via resource)
- → ResourceBreak (one-to-many, via ResourceBreak.availabilityRule)
- → BookingConstraint (one-to-many, via BookingConstraint.availabilityRule)

### ResourceBreak
**Schema.org:** `schema:Action`
_Recurring break window inside an AvailabilityRule introduced by `bookings-availability-rules` (REQ-BAR-002). Captures the day-of-week plus start/end time in the resource's calendar zone plus a break classifier (`lunch`, `coffee`, `prep`, `other`). `isRecurring` defaults to true (weekly repetition); future tiers may add one-off blocks by flipping the flag. `endTime` MUST be strictly greater than `startTime` (declared via JSON-Schema pattern plus a cross-field `x-openregister-calculations` validator). Lifecycle is declarative per ADR-031: `active → archived`, no other transitions. Per REQ-BAR-006 the `availabilityRule` FK powers the rule detail view's `Pauzes` section without any app-side join code._
**Primary spec:** bookings-availability-rules

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| resourceBreakId | string | Yes | Operator-assigned unique break identifier within the administration |
| availabilityRule | string | Yes | FK to AvailabilityRule.availabilityRuleId |
| breakType | enum | Yes | One of lunch, coffee, prep, other |
| dayOfWeek | enum | Yes | One of monday, tuesday, wednesday, thursday, friday, saturday, sunday, daily |
| startTime | string (HH:MM, 24h) | Yes | Break start time in the resource's calendar zone |
| endTime | string (HH:MM, 24h) | Yes | Break end time; MUST be strictly greater than startTime |
| isRecurring | boolean | No | True when the break repeats weekly (default true) |
| status | enum | Yes | One of active, archived; default active |
| description | string | No | Free-text notes |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → AvailabilityRule (many-to-one, via availabilityRule)

### BookingConstraint
**Schema.org:** `schema:Restriction`
_Booking-policy constraint attached to an AvailabilityRule introduced by `bookings-availability-rules` (REQ-BAR-003). Carries the advance-notice window (`minAdvanceNoticeDays` / `maxAdvanceNoticeDays`), the per-booking buffer minutes (`preBufferMinutes` / `postBufferMinutes`), the `cancellationDeadlineHours` window, and a `blackoutDates` array of `{startDate, endDate, reason}` ranges expressing holidays, vacation, or maintenance. Discrete fields were chosen over a DSL/rules-engine because all 17/21 competitors surveyed (Cal.com, Cogsworth, Easy-Appointments, Salonized, Resy …) ship the same flat shape; reading `minAdvanceNoticeDays: 5` is self-explanatory to an SMB operator. `maxAdvanceNoticeDays` MUST be >= `minAdvanceNoticeDays` when set (cross-field validator). Blackout dates are inlined as an array rather than promoted to a separate `ResourceBlackout` schema because SMB cardinality is low (10–20 spans/year) and a single query "show me all unavailable spans for resource X" stays trivial; bulk-import for large holiday calendars is a Tier-2 concern. Lifecycle is declarative per ADR-031: `active → archived`._
**Primary spec:** bookings-availability-rules

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration for tenant isolation (ADR-005) |
| bookingConstraintId | string | Yes | Operator-assigned unique constraint identifier within the administration |
| availabilityRule | string | Yes | FK to AvailabilityRule.availabilityRuleId |
| minAdvanceNoticeDays | integer (≥0) | No | Minimum days in advance a booking may be made; default 0 |
| maxAdvanceNoticeDays | integer (≥0) | No | Maximum days in advance; null = unlimited |
| preBufferMinutes | integer (≥0) | No | Prep time required before each booking (minutes) |
| postBufferMinutes | integer (≥0) | No | Cleanup time required after each booking (minutes) |
| cancellationDeadlineHours | integer (≥0) | No | Minimum hours before the booking start that a cancellation is still allowed without late fee |
| blackoutDates | array | No | Array of `{startDate, endDate, reason}` ranges during which no booking is allowed |
| status | enum | Yes | One of active, archived; default active |

**Relations:**
- → Administration (many-to-one, via administrationId)
- → AvailabilityRule (many-to-one, via availabilityRule)

> **Reconciliation note (bookings-availability-rules, 2026-06-07):** The three-schema model (`AvailabilityRule` + `ResourceBreak` + `BookingConstraint`) is the canonical availability surface for the Tier-1 bookings foundation. It complements but does not replace the simpler legacy fields already on the bookings-resource-calendar `Resource` entity (`openingTime` / `closingTime` / `allowOverlap`) which remain for backwards compatibility with the early bookings-create-appointment flow. The richer per-day templates and breaks live on `Calendar.workingHours` and `ResourceBreak` respectively; advance-notice and buffer policies live on `BookingConstraint`. The availability-query layer (implementation cycle) is responsible for merging these layers — `Resource` operational hours first, then `Calendar.workingHours` overrides, then `ResourceBreak` carve-outs, then `BookingConstraint` advance-notice / blackout guards — and emitting a single "is this slot bookable?" verdict. Cancellation policies remain owned by the `bookings-cancellation-rules` change; this change carries only the no-fee `cancellationDeadlineHours` advisory hint, not the late-fee bracket logic.
### BookingNotificationTrigger
**Schema.org:** `schema:Action`
_Configurable trigger for booking lifecycle notifications. Introduced by `bookings-notification-triggers`. Selects an event type (`booking.created`, `booking.changed`, `booking.cancelled`, `booking.reminder`), an ordered list of recipient rules (role + channels + optional condition), a fallback channel order, rate-limit + dedupe bounds and an opt-out gate. Dispatched through OpenRegister's notification engine (ADR-022); template selection delegates to the existing `BookingConfirmationTemplate` / `BookingReminderTemplate` / `BookingCancellationTemplate` schemas (`bookings-email-templates`) — no template duplication. Lifecycle: `enabled` → `disabled` → `archived` (REQ-BNT-007/008). The trigger fragment declares its full engine binding in `x-openregister-notifications` (ADR-031 schema-declarative): `appliesWhen` filters by status, `scopeBy` honours per-booking overrides via `appliesToBookingSlug`, `selectTemplate.byType` maps each event type onto a template schema, `skipWhen` composes opt-out / rate-limit (per-booking-hour + per-organizer-day) / dedupe gates, and `audit.schema=NotificationDelivery` writes one immutable record per attempt. No PHP listener class — pure-logic gates (rate-limit, dedupe, condition, opt-out, template render) live in `OCA\Shillinq\Service\Notification\*` for unit-test coverage; live dispatch stays in OR's engine._
**Primary spec:** bookings-notification-triggers

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Human-readable trigger name shown in the admin list |
| status | enum | Yes | enabled / disabled / archived lifecycle state |
| triggerType | enum | Yes | booking.created / booking.changed / booking.cancelled / booking.reminder |
| reminderHoursBeforeStart | integer | No | Only used when triggerType=booking.reminder; engine schedules dispatch at bookingStart − this value (±15 min tolerance) |
| channels | array | Yes | Channel priority order for fallback (email/sms/chat/teams/slack) |
| recipients | array | Yes | Ordered list of {role, channels, condition} rules; role ∈ customer/organizer/admin_group; condition is a single comparison expression evaluated against the booking payload |
| rateLimitPerBookingPerHour | integer | No | Default 10; engine returns queued on cap-hit |
| rateLimitPerOrganizerPerDay | integer | No | Default 100 |
| deduplicationWindowMinutes | integer | No | Default 5; dedupe key = (recipient, triggerType, bookingId) |
| respectOptOut | boolean | No | Default true; transactional triggers may opt out (AVG / GDPR) |
| templateOverrideSlug | string | No | Optional per-trigger template slug override |
| appliesToBookingSlug | string | No | When set, the trigger only fires for that single Booking — modal-driven override pattern |
| lastDispatchedAt | datetime | No | Most-recent successful dispatch timestamp; surfaced in the admin monitor |
| activatedAt | datetime | No | First-enable timestamp (lifecycle stamp) |

**Relations:**
- → BookingConfirmationTemplate / BookingReminderTemplate / BookingCancellationTemplate (selectTemplate.byType, declarative)
- → Booking (scopeBy.appliesToBookingSlug → Booking.slug; nullMeansGlobal)
- → NotificationDelivery (writes one record per dispatch attempt via x-openregister-notifications.audit)

**Cites:** ADR-022 (notification engine), ADR-031 (schema-declarative), ADR-004 (modal isolation for the per-booking override UI), ADR-037 (modular register fragment).

### NotificationDelivery
**Schema.org:** `schema:DigitalDocument`
_Immutable audit-trail record of a notification dispatch attempt (REQ-BNT-005, ADR-022). One record per (trigger, recipient, channel) attempt — fallback retries produce additional records linked by the same `dispatchGroupId`. Tamper-evidence is delegated to OpenRegister's audit-trail-immutable contract (`x-openregister-audit.immutable=true`, `tamperEvident=true`, `retentionDays=365`); the schema only declares the fields and the write-once lifecycle. Surfaces in the admin notification monitor (REQ-BNT-008) and supports operator drill-down per booking. The `recipient` field contains PII (email / E.164 phone / chat id); non-auditor list views render the `maskedRecipient` calculation (e.g. `a***@example.com`)._
**Primary spec:** bookings-notification-triggers

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| triggerName | string | Yes | Denormalised from BookingNotificationTrigger.name for audit immutability |
| triggerType | enum | Yes | booking.created / changed / cancelled / reminder |
| bookingId | string | Yes | UUID / slug of the Booking |
| recipient | string | Yes | Address (email / E.164 phone / chat id / group:admin). PII — masked in non-auditor views |
| channel | enum | Yes | email / sms / chat / teams / slack |
| templateName | string | No | Denormalised template name; null when status=queued/skipped pre-template |
| status | enum | Yes | sent / failed / skipped / queued |
| skipReason | enum | No | Machine-readable reason: opt-out / deduplicated / rate-limit-booking / rate-limit-organizer / condition-false / no-recipient-address / all-channels-failed / template-render-error / adapter-unavailable |
| failureReason | string | No | Human-readable error detail when status=failed |
| retryCount | integer | No | Number of fallback / retry attempts before this outcome (0 = first attempt) |
| dispatchGroupId | string | No | UUID linking fallback / retry attempts for the same logical dispatch |
| sentAt | datetime | Yes | ISO-8601 UTC timestamp of the attempt. Write-once |

**Relations:**
- → BookingNotificationTrigger (logical, via triggerName)
- → Booking (logical, via bookingId)

**Cites:** ADR-022 (audit-trail-immutable), ADR-031 (schema-declarative), ADR-004 (modal isolation).

### VpbBalansLink (add-shillinq-vpb-corporate-tax, REQ-VPB-002)

Overlay register declaring per-ondernemingsactiviteit clusters of Vpb-pligtige
accounts, per Wet modernisering Vpb-plicht (2016). One record per
`(costCenterId, vpbPligtigVanaf)` tuple — `costCenterId` MUST reference a
`CostCenter` with `ondernemingsActiviteit: true`, and each entry in
`accountNumbers` MUST reference an `Account` with `vpbPligtig: true`. Per
ADR-031 this is schema metadata — there is **no PHP Vpb-balans service**.

| Field | Type | Required | Description |
|---|---|---|---|
| costCenterId | string | Yes | FK → CostCenter (must have `ondernemingsActiviteit: true`). |
| accountNumbers | string[] | Yes | List of `Account.accountNumber` strings (each with `vpbPligtig: true`). |
| vpbPligtigVanaf | date | Yes | Start date of Vpb-pligtigheid for this cluster (REQ-VPB-002). |
| vpbPligtigTotEnMet | date | No | Optional end date (null = ongoing). |
| toelichting | string | No | Bookkeeper note on the Vpb-pligt grond. |
| administrationId | string | Yes | FK to the administration; reads are administration-scoped. |

**Aggregations (declarative, ADR-031):**
- `vpbBalansFiltered` — filters `GLLine` on
  (`accountNumber IN VpbBalansLink.accountNumbers` AND
  `periodId IN FiscalPeriod.fiscalYearPeriods`), groups per `costCenterId`,
  emits `activaTotal`, `passivaTotal`, `resultaatTotal` per
  ondernemingsactiviteit (output dataset `VpbBalansFiltered`,
  `schema:Dataset`). Honours T1 REQ-GL-005 balance invariant per
  cost-center; surfaces `unbalancedOndernemingsactiviteit` warning when
  `activaTotal - passivaTotal - resultaatTotal != 0` (REQ-VPB-003).
- `orphanedVpbPligtigAccounts` — flags `Account` records where
  `vpbPligtig = true` but no `VpbBalansLink.accountNumbers` references the
  account; rendered as warning in the Vpb menu detail view (Task 11).

**Account / CostCenter additive flags (ADR-037 `x-openspec-extend`):**
- `Account.vpbPligtig: boolean` (default `false`) — per REQ-VPB-001;
  postings against flagged accounts contribute to the Vpb-balans
  aggregation when an active `VpbBalansLink` references them.
- `CostCenter.ondernemingsActiviteit: boolean` (default `false`) — owned at
  proposal-time by sibling change `add-shillinq-market-government-separation`
  and made visible to the Vpb-balans grouping here.

**Vpb-aangifte voorbereiding (REQ-VPB-004):** docudesk template
`vpb-aangifte-voorbereiding` is registered in
`lib/Settings/docudesk-templates.json`; the SBR payload binding declares
the Belastingdienst Vpb XSD and the T4-base
`bookkeeping-sbr-xbrl-reporting` SBR endpoint (Digipoort) for
transmission. No new SBR client per ADR-019. The Vpb-aangifte XSD version
follows the Belastingdienst publication per fiscal year; multiple
template versions may coexist (Risk 1 in design.md).

**Manifest navigation (REQ-VPB-005):** the
`Bookkeeping > Vennootschapsbelasting` menu group sits behind
`featureFlags.mkb-vpb` in
`src/manifest.d/bookkeeping-vpb-corporate-tax-balans.json` with `type:
index` pages for Vpb-pligtige accounts + VpbBalansLink records and a
`type: detail` page for the Vpb-balans + aangifte voorbereiding per
ondernemingsactiviteit.

**Cites:** ADR-031 (schema-declarative), ADR-037 (modular register
fragments), ADR-019 (no new SBR client), ADR-024 (Tier-4 manifest pages).

## Wet Markt en Overheid Compliance entities (add-shillinq-market-government-separation, REQ-MGS-001..005 / REQ-WMO-001..007)

> **Source:** `openspec/changes/bookkeeping-market-government-separation/`
> (T3 sibling delivered on `development`, register.d + manifest.d
> fragments) plus the T2 umbrella
> `openspec/changes/add-shillinq-market-government-separation/`
> (proposal + design + abstract REQ-MGS-NNN scope). Per Wet Markt en
> Overheid (Mededingingswet hoofdstuk 4b) gemeenten / provincies /
> waterschappen running ondernemingsactiviteiten MUST (a) identify
> ondernemingsactiviteiten as distinct clusters on `CostCenter`,
> (b) compute the integrale kostprijs (direct costs + allocated
> overhead + equity compensation art. 25i), and (c) maintain a
> transparantieadministratie showing the ondernemingsactiviteit is
> not cross-subsidised. The owning fragment is
> `lib/Settings/register.d/bookkeeping-market-government-separation.json`
> (8 schemas: `CommercialActivity`, `IntegralCostPrice`,
> `ActivityCostAllocation`, `AlgemeenBelangBesluit`, `ACMReport`,
> `AlertLog`, `WMOAuditLog`, `MarketBenchmark`). The manifest entry
> sits at `src/manifest.d/bookkeeping-market-government-separation.json`
> behind the `WMO Compliance` menu group. The
> `CostCenter.ondernemingsActiviteit` additive flag is layered via
> the VPB-balans fragment (see preceding section) since both T4
> capabilities consume the same flag.

**CostCenter additive flag (ADR-037 `x-openspec-extend`):**
- `CostCenter.ondernemingsActiviteit: boolean` (default `false`) —
  the Wet Markt en Overheid trigger flag per REQ-MGS-001. When
  `true`, the cost-center is subject to the integrale-kostprijs
  requirement (REQ-MGS-002 / REQ-WMO-002) and surfaces in the WMO
  Compliance manifest pages (REQ-MGS-005 / REQ-WMO-001..004). Per
  ADR-031 the flag is schema metadata — there is no parallel
  `OndernemingsActiviteit` register. The flag is defined in the
  VPB-balans register.d fragment for ordering reasons (VPB-balans
  consumes the flag in REQ-VPB-002); the WMO fragment references
  the same flag without redeclaring it. Ondernemingsactiviteit
  views carry `schema:Service` schema.org type per REQ-MGS-001.

**AlgemeenBelangBesluit overlay (REQ-MGS-004 / REQ-WMO-005):**
declared as a register fragment with `besluitNummer` (mapped to
`kenmerk`), `besluitDatum` (mapped to `vaststellingsdatum`),
`geldigheidsperiode` (derived from the 10-state lifecycle
`raadsbesluit → publicatie → acm-notified → geldig → evaluatie-due
→ herziening → intrekking → ingetrokken → archived` plus
`volgendeEvaluatie`), `motivering` (docudesk attachment URI), and
`betreftActiviteiten[]` (the WMO equivalent of `getrokkenBedrag`
linking activities exempted by the besluit). The integrale-kostprijs
warning (REQ-MGS-003 / REQ-WMO-004) is suppressed declaratively
when a valid `AlgemeenBelangBesluit` covers the period: the
`CommercialActivity.isExempted` flag plus the FK to
`AlgemeenBelangBesluit.id` short-circuits the under-cost-recovery
warning and an informational banner cites the besluit `kenmerk`.
Both the suppression and the banner event log to `WMOAuditLog` per
ADR-022 (immutable audit, 7-year retention).

**Integrale kostprijs (REQ-MGS-002 / REQ-WMO-002):** declared on
`IntegralCostPrice` per `(commercialActivityId, periode)`,
time-versioned with `status: voorlopig` (monthly) → `definitief`
(31 March of the following year, accountant digital signature
locks the record). The componenten block sums direct costs +
allocated overhead via the BBV taakveld 0.4 `OverheadDistributionRule`
sleutel (inherited from `bookkeeping-cost-centers-dimensions`,
no shadow schema) + a vermogenscompensatie (WACC, default 4.5%,
configurable per activity per period). Tarieven-vs-kostprijs
comparison surfaces an `unrecovered cost` warning when realised
revenue (GL revenue-sum) < integrale kostprijs (REQ-MGS-003 /
REQ-WMO-004 §compliant).

**Transparantieadministratie navigation (REQ-MGS-005 / REQ-WMO-001
manifest entries):** the `Bookkeeping > WMO Compliance` menu
group in `src/manifest.d/bookkeeping-market-government-separation.json`
carries `type: index` + `type: detail` pages bound to
`CommercialActivity` / `IntegralCostPrice` /
`ActivityCostAllocation` so the per-activity transparantie view
(direct costs / overhead / equity comp / integrale kostprijs /
revenue / margin / compliance status / besluit reference) renders
without bespoke Vue components per ADR-024.

**Cites:** ADR-022 (audit-trail-immutable), ADR-024 (Tier-4
manifest pages), ADR-031 (schema-declarative, no bespoke kostprijs
service), ADR-032 (`kind: config` change), ADR-037 (modular
register.d / manifest.d fragments). See sibling spec
`openspec/specs/bookkeeping-market-government-separation/spec.md`
for the full REQ-WMO-NNN catalogue (8 schemas, lifecycle blocks,
RBAC roles, ACM-handhavings-pakket export) and the parent T4
envelope spec `openspec/specs/bookkeeping-bbv-compliance/spec.md`
for BBV taakveld 0.4 sleutel inheritance.

## SEPA Direct Debit (Incasso) entities

> **Source:** `openspec/changes/bookkeeping-sepa-direct-debit/`. Five
> new registers + two AR-core overlays (`CustomerMaster.defaultMandateId`,
> `ARInvoice.paymentMethod` / `directDebitMandateId` /
> `directDebitPreNotificationInvoiceId`). All registers carry
> `x-openregister-audit: true` semantics via T2 audit-trail-immutable;
> pain.008 / pain.002 / camt.054 payloads are archived 7+ years
> (bewaarplicht, design D11). Lifecycle guards live under
> `lib/Lifecycle/` per ADR-031 exception. Owning fragment:
> `lib/Settings/register.d/bookkeeping-sepa-direct-debit.json`.

### SepaMandate
**Schema.org:** `schema:FinancialProduct`
_A SEPA Direct Debit mandate (incassomachtiging) authorising the creditor to collect from a debtor account under the SDD CORE or B2B rulebook. Source of truth for incasso eligibility; collections may only be scheduled against an `active` mandate (design D1)._
**Primary spec:** bookkeeping-sepa-direct-debit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| mandateReference | string | Yes | Unique mandate reference per creditor, max 35 chars, immutable once issued (REQ-SDD-001) |
| creditorIdentifier | string | Yes | Dutch creditor identifier NL{check}ZZZ{KvK} (REQ-SDD-001) |
| scheme | string | Yes | SDD rulebook: `CORE` (consumer) or `B2B` (business) (REQ-SDD-001) |
| type | string | Yes | `recurring` (RCUR-capable) or `oneoff` (OOFF, single collection only) (REQ-SDD-002) |
| status | string | Yes | Lifecycle: `pending` / `active` / `cancelled` / `expired` / `suspended` (REQ-SDD-001, REQ-SDD-008) |
| signedAt | date | Yes | Date debtor signed the mandate (REQ-SDD-001) |
| signedBy | string | Yes | Debtor name as on the mandate document (REQ-SDD-001) |
| debtorIban | string | Yes | Debtor IBAN; mod-97 validated at batch generation (REQ-SDD-005) |
| debtorBic | string | No | Mandatory for non-EEA destinations (REQ-SDD-001) |
| debtorName | string | Yes | Debtor legal or trading name (REQ-SDD-001) |
| debtorAddress | object | No | Optional postal address (REQ-SDD-001) |
| debtorAccountType | string | Yes | `consumer` (CORE only) or `business` (B2B only); scheme mismatch rejected `sdd.mandate.scheme.mismatch` |
| firstCollectionDate | date | No | First permissible collection date (REQ-SDD-001) |
| lastCollectionDate | date | No | Last known or planned collection date (REQ-SDD-001) |
| lastUsedAt | date | No | Drives 36-month dormancy expiry (REQ-SDD-008) |
| mandateDocument | string | No | OR file reference to scanned signature / digital-signing evidence (REQ-SDD-001) |
| cancellationReason | string | No | Free-text reason recorded on cancellation (REQ-SDD-008) |
| preNotificationDays | integer | No | Lead time in calendar days; default 14 (REQ-SDD-003) |
| reviewFlag | boolean | No | Set true on consumer refund (MD06); high refund rate risks scheme exclusion (REQ-SDD-007) |
| administrationId | string | Yes | Tenant FK (REQ-SDD-001) |

**Relations:**
- → DirectDebitCollection (one-to-many, via collection.mandateId)
- → CustomerMaster (many-to-one inverse, via customer.defaultMandateId; one default mandate per customer)
- → ARInvoice (one-to-many inverse, via invoice.directDebitMandateId)

### DirectDebitCollection
**Schema.org:** `schema:PaymentMethod`
_A single SEPA Direct Debit collection against a mandate. `sequenceType` is auto-derived from mandate history by `SequenceTypeGuard::deriveSequenceType`; operator input is rejected (design D2 / ADR-031 exception)._
**Primary spec:** bookkeeping-sepa-direct-debit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| mandateId | string | Yes | FK to SepaMandate (REQ-SDD-002) |
| invoiceId | string | No | FK to ARInvoice (nullable for ad-hoc collections) |
| amount | number | Yes | Collection amount EUR, two decimals, min 0.01 (REQ-SDD-002) |
| currency | string | Yes | Always `EUR` for SEPA (REQ-SDD-002) |
| sequenceType | string | Yes | `FRST` / `RCUR` / `OOFF` / `FNAL` — auto-derived (REQ-SDD-002, design D2) |
| requestedCollectionDate | date | Yes | Date funds should hit creditor account (REQ-SDD-002) |
| endToEndId | string | Yes | Unique end-to-end identifier per creditor, max 35 chars |
| status | string | Yes | Lifecycle: `scheduled` → `submitted` → `accepted_by_bank` → `succeeded` / `rejected` / `refunded` |
| submittedInBatchId | string | No | FK to DirectDebitBatch (REQ-SDD-005) |
| pain002ReasonCode | string | No | ISO 20022 reason code if rejected (REQ-SDD-006) |
| camt054ReferenceId | string | No | Reference ID from the camt.054 that closed the collection (REQ-SDD-007) |
| repostedAsCollectionId | string | No | FK to new collection created on repost (REQ-SDD-009) |
| administrationId | string | Yes | Tenant FK (REQ-SDD-002) |

**Relations:**
- → SepaMandate (many-to-one, via mandateId)
- → DirectDebitBatch (many-to-one, via submittedInBatchId)
- → ARInvoice (many-to-one, via invoiceId)
- → RTransaction (one-to-many, via rtransaction.collectionId)
- → PreNotification (one-to-many, via prenotification.collectionId)

### DirectDebitBatch
**Schema.org:** `schema:Invoice`
_A pain.008.001.02 batch aggregating homo-sequence collections for submission. `pain008Xml` / `pain002Xml` are archived 7+ years (bewaarplicht, design D11). Marked `isArchived: true` by default; explicit retention-override required to delete._
**Primary spec:** bookkeeping-sepa-direct-debit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| messageId | string | Yes | Globally unique message id per creditor per file, max 35 chars |
| creationDateTime | datetime | Yes | Timestamp the batch was generated (REQ-SDD-005) |
| requestedCollectionDate | date | Yes | Earliest collection date in the batch (REQ-SDD-005) |
| scheme | string | Yes | `CORE` or `B2B` (REQ-SDD-005) |
| sequenceType | string | Yes | Homo-sequence: `FRST` / `RCUR` / `OOFF` / `FNAL` (REQ-SDD-005) |
| collectionCount | integer | Yes | Equals pain.008 NbOfTxs (REQ-SDD-005) |
| controlSum | number | Yes | Equals pain.008 CtrlSum, in EUR (REQ-SDD-005) |
| status | string | Yes | Lifecycle: `draft` / `generated` / `submitted` / `accepted_by_bank` / `partially_rejected` / `fully_rejected` |
| pain008Xml | string | No | Full ISO 20022 pain.008.001.02 payload; archived non-deletable (REQ-SDD-005, REQ-SDD-010) |
| pain002Xml | string | No | Incoming pain.002 status report; archived (REQ-SDD-006, REQ-SDD-010) |
| submittedAt | datetime | No | When the batch was submitted via bank connector (REQ-SDD-005) |
| isArchived | boolean | No | Retention flag (default true); pain files must not auto-delete (REQ-SDD-010, design D11) |
| administrationId | string | Yes | Tenant FK (REQ-SDD-005) |

**Relations:**
- → DirectDebitCollection (one-to-many inverse, via collection.submittedInBatchId)

### RTransaction
**Schema.org:** `schema:MoneyTransfer`
_A bank-side R-transaction (reject / return / refund / reversal / revocation / request-for-cancellation) parsed from pain.002 or camt.054. Captured separately for audit and reposting decisions (design D7). Non-deletable per REQ-SDD-010; RBAC denies `delete`._
**Primary spec:** bookkeeping-sepa-direct-debit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| collectionId | string | Yes | FK to DirectDebitCollection (REQ-SDD-007) |
| type | string | Yes | `reject` / `return` / `refund` / `reversal` / `revocation` / `request_for_cancellation` |
| reasonCode | string | Yes | ISO 20022 ExternalReturnReason code (REQ-SDD-007) |
| reasonText | string | No | Human-readable reason from the bank |
| originatorBic | string | No | BIC of the initiating institution |
| transactionAmount | number | Yes | Amount of the R-transaction (REQ-SDD-007) |
| valueDate | date | Yes | Date the debtor account was re-credited (REQ-SDD-007) |
| notifiedAt | datetime | Yes | When shillinq received the R-transaction notification |
| administrationId | string | Yes | Tenant FK (REQ-SDD-007) |

**Relations:**
- → DirectDebitCollection (many-to-one, via collectionId)

### PreNotification
**Schema.org:** `schema:Message`
_A vooraankondiging for a collection. A collection MUST NOT enter a pain.008 batch unless its pre-notification is sent or carried on the invoice line (design D3 / REQ-SDD-003)._
**Primary spec:** bookkeeping-sepa-direct-debit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| collectionId | string | Yes | FK to DirectDebitCollection (REQ-SDD-003) |
| sentAt | datetime | No | When the notification was actually sent; null = not yet sent |
| channel | string | No | Dispatch channel: `email` / `letter` / `invoice_line` |
| noticeDays | integer | Yes | Calendar days between notification and collection date; default 14 |
| recipientAddress | string | No | Email, postal address, or invoice reference per channel |
| administrationId | string | Yes | Tenant FK (REQ-SDD-003) |

**Relations:**
- → DirectDebitCollection (many-to-one, via collectionId)

### CustomerMaster (SDD overlay)
Adds `defaultMandateId` (nullable FK to SepaMandate) to the AR-core
`CustomerMaster` entity owned by `add-shillinq-bookkeeping-compliance.json`.
When set, the AR billing flow proposes direct-debit collection by default
(REQ-SDD-002). The shillinq `CustomerMaster` is the AR-core analogue of the
company-wide `Counterparty` entity referenced in design D1.

### ARInvoice (SDD overlay)
Adds the direct-debit payment-method linkage to the AR-core `ARInvoice`
entity owned by `add-shillinq-bookkeeping-compliance.json`:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentMethod | string | No | `bank_transfer` / `direct_debit` / `cash` / `card` / `other`; default `bank_transfer` (REQ-SDD-002) |
| directDebitMandateId | string | No | FK to SepaMandate; required when `paymentMethod = direct_debit` (REQ-SDD-002) |
| directDebitPreNotificationInvoiceId | string | No | Self-FK to the invoice whose line item carried the SEPA pre-notification (REQ-SDD-003, design D3) |
## Continuous Close & Flux Analysis Entities (REQ-CLS-001..010)

Eleven new entities landed by `bookkeeping-soft-close-flux` for the
`bookkeeping-continuous-close` capability. All carry
`x-openregister-audit-trail.enabled = true` per ADR-022 / REQ-AT-001. Money
fields are integer cents per the fleet money convention.

### PeriodStatus
**Schema.org:** `schema:AccountingPeriod`
_Continuous-close lifecycle wrapper around a FiscalPeriod (REQ-CLS-001). Five-stage lifecycle (open → soft-closed → hard-closed → audited → locked) with stage-change history, owner-per-stage, and posting-restriction flags. Sibling to FiscalPeriod (T1 period-close)._
**Primary spec:** bookkeeping-continuous-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to Administration |
| periodYear | integer | Yes | Fiscal year |
| periodMonth | integer | Yes | Calendar month 1..12 |
| stage | enum | Yes | `open` / `soft-closed` / `hard-closed` / `audited` / `locked` |
| stageChangeHistory | array | No | Append-only `[{fromStage, toStage, actor, timestamp, reason}, …]` |
| ownerPerStage | object | No | Stage→role map |
| postingRestrictionsPerStage | object | No | Per-stage `{allow, requireOverride}` config |
| softClosedAt / hardClosedAt / auditedAt / lockedAt | datetime | No | Stage timestamps |

### AutoAccrualRule
_Declarative auto-accrual rule (REQ-CLS-003) executed nightly by `SoftCloseExecutor`._
**Primary spec:** bookkeeping-continuous-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleName | string | Yes | Human-readable name |
| targetGLAccount / contraGLAccount | string | Yes | GL accounts |
| calculationMethod | enum | Yes | `fixed-amount` / `percentage-of-revenue` / `straight-line-from-contract` / `days-elapsed-of-period` / `external-lookup` |
| calculationParameters | object | Yes | Method-specific bag (e.g. `{amountCents}`) |
| reversalPattern | enum | Yes | `first-of-next-month` / `on-receipt-of-invoice` / `on-settlement` / `none` |
| frequency | enum | Yes | `daily` / `weekly` / `monthly` |
| administrationId | string | Yes | FK to Administration |
| lifecycleState | enum | Yes | `active` / `disabled` / `archived` |
| ruleVersion | integer | No | Monotonic version stamped onto each AutoAccrualPosting |

### AutoAccrualPosting
_Append-only audit record per rule execution (REQ-CLS-010); lifecycle `posted → reversed`._
**Primary spec:** bookkeeping-continuous-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId / ruleVersion | string / integer | Yes | FK + version snapshot |
| periodId | string | Yes | FK to FiscalPeriod.periodId |
| amountCents | integer | Yes | Posted amount in cents |
| journalEntryId | string | Yes | FK to JournalEntry written by the accrual |
| postedAt | datetime | Yes | Posting timestamp |
| postedBy | string | Yes | `SYSTEM:SoftCloseExecutor` or specific service id |
| reversalId | string | No | FK to the reversal AutoAccrualPosting |
| reversalState | enum | Yes | `posted` / `reversed` |

### CloseChecklistTemplate
_Reusable close-task list per administration type (REQ-CLS-004)._
**Primary spec:** bookkeeping-continuous-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| templateName | string | Yes | Template name |
| administrationTypeId | string | Yes | `BV` / `NV` / `eenmanszaak` |
| tasks | array | Yes | `[{taskId, taskName, taskOwner, dueBefore, dependsOn[], evidenceRequired}, …]` |

### CloseChecklistInstance
_Per-period instantiation of a template; lifecycle `pending → in-progress → completed` (REQ-CLS-004)._

### FluxRun
_A single variance-analysis execution (REQ-CLS-005)._
**Primary spec:** bookkeeping-continuous-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId / periodId | string | Yes | Scope |
| scope | enum | Yes | `administratie` / `segment` / `cost-centre` / `consolidated` |
| comparisonBasis | enum | Yes | `budget` / `forecast` / `prior-period` / `prior-year` |
| materialityAbsoluteCents / materialityPercentage | integer / number | No | Threshold snapshot |
| runTimestamp | datetime | Yes | Run start |
| status | enum | Yes | `running` / `completed` / `failed` |
| resultSummary | object | No | `{materialCount, autoExplainedCount, escalatedCount, totalVarianceCents}` |

### FluxItem
_Per-GL-account variance row of a FluxRun; status `open` / `auto-explained` / `owner-explained` / `escalated` / `accepted` (REQ-CLS-005, REQ-CLS-006)._

### FluxAttribution
_Driver decomposition of a FluxItem variance: `volume` / `price` / `mix` / `fx` / `one-off` with quantified contribution in cents (REQ-CLS-006)._

### MaterialityPolicy
_Per-administratie + per-account-group materiality thresholds (REQ-CLS-005). Special-rule overrides for cash / tax / revenue._

### ContinuousCloseAlert
_Alert raised by `SoftCloseExecutor` or the flux engine on soft-close failure or SLA breach (REQ-CLS-002, REQ-CLS-006)._

### CloseMetrics
_Close-quality KPI snapshot per administratie + period with rolling 12-month trend (REQ-CLS-009)._

**Reconciliation with the T1 `FiscalPeriod` entity:** `PeriodStatus` is a
sibling to the T1 `FiscalPeriod` (declared by `bookkeeping-period-close`).
`FiscalPeriod` is the canonical period entity (start/end dates, fiscal
year, close history, audit-lock); `PeriodStatus` extends it with the
continuous-close stage model and posting-restriction enforcement via
`OCA\Shillinq\Lifecycle\PeriodStatusGuard::postingAllowed` added
additively to `GLTransaction.post.preconditions` alongside the existing
`PeriodCloseGuard::periodOpen` precondition. Both schemas coexist; no
existing data-model entry is rewritten.

**Cites:** ADR-022 (audit-trail immutable on every register), ADR-031
(orchestration-exception path for `SoftCloseExecutor`), ADR-037 (modular
register fragments).

## dba-compliance-marker (T2 — Wet DBA + VBAR)

Six declarative registers operationalize Wet DBA compliance per opdracht
(REQ-DBA-000..018). Spec lives in
`openspec/specs/dba-compliance-marker/spec.md`; register fragment in
`lib/Settings/register.d/dba-compliance-marker.json`.

### DBAOpdracht
_Per-engagement carrier (REQ-DBA-001). Holds ondernemingId, klantId, opdrachtNaam,
start/eind dates, intakeStatus lifecycle (DRAFT → INTAKE_VEREIST →
INTAKE_VOLTOOID → ACTIEF → BEEINDIGD), actueleRisicoscore (0-100), risicoNiveau
band, openFlags count, modelOvereenkomstId, evidenceDossierId,
wbaBeoordelingResultaat + wbaGeldigTot, intermediairMode, perspectief
(OPDRACHTNEMER/OPDRACHTGEVER), retentieDeadline (AWR art. 52). Save-precondition
`OCA\Shillinq\Guard\DBAOpdrachtGuard::validateOnSave` enforces the
intake-before-actief + retention-on-beeindigd invariants._

### DBAIntake
_Vragenlijst-antwoorden (REQ-DBA-003) for the three Wet DBA pillars
(gezagsverhouding, persoonlijkeArbeid, financieelRisico, 0-20 each) plus
Deliveroo-criteria (0-40, HR 24-3-2023). totaalScore is declarative
(`x-openregister-calculations`) with `OCA\Shillinq\Guard\DBAScoreCalculator::computeTotal`
as ADR-031 exception for the conditional boosters (exclusief + langjarig;
vervangbaarheid theoretisch). interpretatie maps onto LAAG / LAAG_MIDDEN /
MIDDEN_HOOG / HOOG bands._

### DBAModelovereenkomst
_Reference register (REQ-DBA-002) of Belastingdienst-approved templates
(tussenkomstvrij v3-2024, leverancier-zelfstandig v2-2023, tussenkomst v1-2024)
plus operator-uploaded variants. Carries publicatieURL, goedkeuringDatum, geldigTot,
essentieleBepalingen[], versie, actueleVersie, fileRef + sha256. Seeded by
`OCA\Shillinq\Repair\Load_DBA_Seeds_Step`._

### DBARisicoflag
_Append-only audit record (REQ-DBA-004/005/006/014/015/016) generated by
`DBAFlagGenerationJob` (daily). Types: FACTUURFREQUENTIE_LIJKT_OP_LOON,
CONCENTRATIE_WAARSCHUWING, LANGJARIGE_HOOFDRELATIE, VBAR_GRENS_ONDERSCHREDEN,
VERVANGBAARHEID_THEORETISCH, MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN,
ICT_INTEGRATIE_IN_TEAM, MODELOVEREENKOMST_VERLOPEN, HERBEOORDELING_OVERDUE,
WBA_VERLOPEN. Lifecycle: OPEN → BEKEKEN → AFGEHANDELD / VERVALLEN.
Immutable fields: opdrachtId, type, detectieMoment, ernst, details, fiscaleBron._

### DBAPortfolioRisico
_Monthly aggregate (REQ-DBA-005/006) produced by `DBAPortfolioAggregationJob`.
Holds peilDatum, actieveOpdrachten count, concentratie (grootsteKlant +
aandeelOmzet12mnd, drempelHoog, status VEILIG/WAARSCHUWING/KRITIEK),
langjarigeRelaties[], exclusieveRelaties count,
multipleEngagementConcern[], overallRisico LAAG/MIDDEN/HOOG._

### DBAEvidenceDossier
_Per-opdracht stukkenlijst (REQ-DBA-007) for Belastingdienst-audit: stukken[]
with type enum (GETEKENDE_OVEREENKOMST, FACTUUR_EERSTE, FACTUUR_LAATSTE,
FACTUUR_TUSSENTIJDS, URENSTAAT_KWARTAAL, EMAIL_ARCHIVE, WBA_UITKOMST,
CORRECTIE_BRIEF, INTERN_MEMO, OVERIG), fileRef, datum, sha256.
compleetheidScore is declarative (0-1). emailArchiveOptIn + ConsentRecord-FK
implement AVG-compliant archiving (REQ-DBA-012). bewaarTermijn = 7 jaar
per AWR art. 52._

**Cites:** ADR-022 (audit-trail immutable), ADR-031 (declarative-first +
single-method exception for DBAScoreCalculator), ADR-037 (modular register
fragments). VBAR threshold and compliance-mode are stored as app-config
under prefix `dba.` (mutable per administration).

## SBR/XBRL Reporting (XbrlInstance)

> **Source:** `openspec/changes/add-shillinq-sbr-xbrl-reporting/`.
> Tier-4 umbrella spec `bookkeeping-sbr-xbrl-reporting` (REQ-SBR-001..007).
> Owning fragment: `lib/Settings/register.d/add-shillinq-sbr-xbrl-reporting.json`.
> Mapping seed templates under `lib/Settings/seeds/sbr-mappings/`
> (`kvk-jaarrekening-nt17/nt18`, `belastingdienst-vpb-nt17/nt18`,
> `belastingdienst-ib-nt17`, `sbr-banken-kredietrapportage-nt17`,
> `sbr-wonen-nt17`).

### XbrlInstance

_A single NL-taxonomie XBRL instance document derived from a posted T3
`FinancialStatement` (REQ-SBR-001/002). One record per filing event per
administration + reporting period + entry point. Carries the
canonicalised XML payload (`instanceXml`), its SHA-256
tamper-evidence hash (`instanceHash`), the resolved Mapping FK
(`mappingId` → OR `Mapping` reference), the openconnector source slug
for Digipoort submission (`digipoortSourceSlug`, default
`digipoort-prod`), the Digipoort acknowledgement receipt id
(`digipoortReceiptId`) and the declarative `draft → validated →
submitted → accepted/rejected` lifecycle (REQ-SBR-003 / ADR-031). The
five canonical SBR entry points (kvk-jaarrekening, belastingdienst-vpb,
belastingdienst-ib, sbr-banken-kredietrapportage, sbr-wonen) are enum on
`entryPoint` (REQ-SBR-005)._

**Reconciliation with the T3 `FinancialStatement` entity:** `XbrlInstance`
is a **transformation, not a re-aggregation** on top of the T3
`FinancialStatement` declared by sibling change
`add-shillinq-financial-statements` (Decision D1 in
`openspec/changes/add-shillinq-sbr-xbrl-reporting/design.md`). The
already-balanced statement object is the single source of truth; the
XBRL instance maps each statement line to a NL-taxonomie concept via an
OpenRegister `Mapping` record (consumed by FK on
`XbrlInstance.mappingId`, per REQ-SBR-006). No PHP `XbrlReportService`
re-aggregates ledger lines per XBRL concept — that would duplicate the
T3 aggregation and create drift between the filing and the
operator-visible statement. Sibling T3 changes that own entry-point
specific submission lifecycles (`bookkeeping-vpb-mkb`,
`bookkeeping-bcf-vat-compensation`, `bookkeeping-emu-reporting`,
`bookkeeping-icp-opgaaf`, `bookkeeping-ib-aangifte-zzp`) continue to
carry their own `x-openregister-lifecycle` for their domain object;
`XbrlInstance` is the umbrella **payload store** that captures the
canonicalised XML, the Digipoort receipt, and the tamper-evidence hash
for each filing event. Existing `IcpOpgaaf.xmlPayload` /
`VpbAangifte`-side SBR fields remain in place for backward
compatibility; the cross-cutting `XbrlInstance` is the long-term
canonical surface and is introduced **additively** — no existing
data-model entry is rewritten.

**Cites:** ADR-022 (Digipoort consumed from openconnector by source
slug; no embedded SOAP/WS-Security client in shillinq), ADR-024 (Tier-4
manifest navigation: `Bookkeeping > SBR/XBRL Filings` with `type: index`
+ `type: detail` pages rendered by `CnIndexPage` / `CnDetailPage`),
ADR-031 (declarative state machine on the schema; no `XbrlReportService`),
ADR-037 (modular register fragment).

## bookkeeping-rekenkamer-audit-pack — audit-flag-on-every-register + destruction-schedule lifecycle

_The Rekenkamer / Accountantscontrole audit pack (capability
`bookkeeping-rekenkamer-audit-pack`, Tier T2/T3) imposes two
cross-cutting rules on the shillinq data model. First, every register
declared by T1 + T2 + T3 + every future bookkeeping or procurement
tier — `Account`, `GLTransaction`, `GLLine`, `JournalEntry`,
`APInvoice`, `ARInvoice`, `PurchaseOrder`, `Tender`, `Bid`,
`AwardDecision`, `Payment`, `Receipt`, `ApprovalRequest`,
`ApprovalTask`, `SigningAuthority` and every other in-scope schema —
MUST carry `"x-openregister-audit-trail": { "enabled": true, "description":
"..." }` in its schema metadata (REQ-RAP-001). This switches on OR's
`audit-trail-immutable` channel so every create / update / lifecycle
transition is recorded with actor + before/after + hash-chained
timestamp. The CI gate `tests/validate-registers.js` mechanically
enforces the rule on every PR (the schemas in `NON_BOOKKEEPING` —
inventory, bookings, notification-delivery, the scaffolding `example`
— are the only legitimate opt-outs; procurement schemas like
`PurchaseOrder`, `Tender`, `Bid`, `AwardDecision` stay OUT of
NON_BOOKKEEPING per REQ-RAP-001 and ARE asserted). Per ADR-022
anti-pattern enumeration (REQ-RAP-010), `lib/Db/Audit*`,
`lib/Service/Audit*`, `lib/Db/EventLog*`, `lib/Db/ChangeLog*`,
`AuditLogger`, `EventLogger`, `ChangeTracker` and app-local audit
deletion logic are REVIEW-BLOCKING — every audit event MUST flow
through OR. The existing `AuditExportService` (Slice 11 of
`bookkeeping-purchase-order-3way`) and `ComplianceExportService`
(REQ-RAP-005 / REQ-RAP-009) both READ OR's audit-trail; neither
stores audit events._

_Second, destruction-eligible records (any record subject to
Archiefwet retention) follow the state machine `active →
marked-for-destruction → destruction-completed`. `destruction-completed`
is TERMINAL and immutable — Archiefwet requires proof of destruction,
so the record itself is preserved as a state-change marker, not truly
deleted. `lib/Lifecycle/DestructionScheduleGuard` enforces the
preconditions: `active → marked-for-destruction` needs a
`compliance-officer` role + the record older than `RETENTION_FLOOR_YEARS
= 7` (Archiefwet article 7); `marked-for-destruction →
destruction-completed` needs a compliance-officer or the
`shillinq-destruction-runner` system actor; `marked-for-destruction →
active` is permitted for reversal until the destruction order is
executed (REQ-RAP-008). Every transition emits an audit event with
`action=lifecycle:{from}→{to}`, `selectielijstCode` (default `5.1.2`),
`legalBasis` (default `Archiefwet Article 7`), `actor`, and the OR
hash-chain `previousHash` + `eventHash` per ADR-022. Cross-references
to the five Bookkeeping audit-surface manifest entries —
`BookkeepingSigningTrail` (REQ-RAP-002), `BookkeepingDestructionReport`
(REQ-RAP-003), `BookkeepingChangeHistory` (REQ-RAP-004),
`BookkeepingComplianceExport` (REQ-RAP-005) and `BookkeepingActivityFeed`
(REQ-RAP-006) — are documented in
`openspec/changes/bookkeeping-rekenkamer-audit-pack/specs/bookkeeping-rekenkamer-audit-pack/spec.md`._

**Cites:** ADR-022 (audit-trail-immutable consumed from OR; anti-pattern
enumeration for `lib/Db/Audit*` / `lib/Service/Audit*`), ADR-031
(`DestructionScheduleGuard` as ADR-031 exception path; replace with
declarative DSL when engine supports age + role + terminal-state
primitives), ADR-037 (modular register fragments).
## bookkeeping-bbv-compliance (T3, 2026-06-10)

The `bookkeeping-bbv-compliance` change declares the data model for
the Besluit Begroting en Verantwoording (BBV) regime that gemeenten,
provincies and waterschappen must follow. Per ADR-012 dedup, three
sibling-owned schemas (`Account`, `GLLine`, `Iv3Export`) are
**extended/referenced, not redeclared**; the eleven entities below
are net-new and unique to this change. `BBVProgramma` from the
sibling `bookkeeping-waterschappen-bbv-variant` (declared above in
this document) coexists with the new `Programma` schema — the
waterschappen variant retains the `programmaStructure` discriminator
and `kostentoedeling` aggregation, while the gemeente/provincie
`Programma` follows the BBV art. 8 doelstelling / beleidsindicator
shape. Cross-resolution at runtime is by
`(administrationType, bbvVariant)`.

### Taakveld
**Primary spec:** bookkeeping-bbv-compliance
**Schema.org:** `schema:DefinedTerm`
_Statutorily-fixed activity classification per Iv3-informatievoorschrift.
53 gemeente-codes, 14 provinciale codes, 10–12 waterschap-codes.
Doubles as the enum source for `Account.taakveld` and `GLLine.taakveld`.
Composite uniqueness key `(code, overheidslaag)`; the same numeric
code (e.g. `0.10`) recurs across overheidslagen with different naam._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Taakveld code (e.g. `0.10`, `2.1`) |
| naam | string | Yes | Taakveld naam |
| hoofdfunctie | int | Yes | Hoofdfunctie code (0-8) |
| hoofdfunctieNaam | string | Yes | Hoofdfunctie naam |
| omschrijvingIv3 | text | No | Iv3 omschrijving |
| overheidslaag | enum | Yes | gemeente / provincie / waterschap |
| verplichteEconomischeCategorieen | array[ref[EconomischeCategorie]] | No | Restricted set, enforced by REQ-BBV-002 |
| geldigVanaf | date | Yes | First date this code is valid |
| geldigTot | date | No | Last date this code is valid (nullable) |

**Uniqueness:** `(code, overheidslaag)`.

### EconomischeCategorie
**Primary spec:** bookkeeping-bbv-compliance
**Schema.org:** `schema:DefinedTerm`
_Iv3 economische categorie (kostensoort), hoofdgroepen 1-8.
Hierarchical via `parentCode`. Required next to `taakveld` on every
exploitatie posting (REQ-BBV-002)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Categorie code (e.g. `1.1`) |
| naam | string | Yes | Categorie naam |
| niveau | int | Yes | 1, 2 or 3 |
| parentCode | string | No | FK to EconomischeCategorie.code |
| batenOfLasten | enum | Yes | baten / lasten / balans |
| iv3Verplicht | boolean | No | Whether iv3-export rolls this up |

**Uniqueness:** `code`.

### RgsDecentraalRekening
**Primary spec:** bookkeeping-bbv-compliance
_Shared (cross-tenant) catalogue mapping each RGS-account to its
RGS-decentraal code and default BBV-categorisering. Seeded from
`lib/Settings/seeds/rgs-decentraal-2025.json`. In the live register
this catalogue surfaces as `BbvAccountMapping` (the name that
`Iv3Export.buckets` already references); per ADR-012 the schema is
not redeclared here — the field shape lives on `BbvAccountMapping`
in the section above._

### Programma
**Primary spec:** bookkeeping-bbv-compliance
**Schema.org:** `schema:DefinedTerm`
_Council-approved BBV programma per BBV art. 8. Distinct from
`BBVProgramma` (waterschappen-variant); coexists by
`(administrationType, bbvVariant)`. One row per
`(administrationId, nummer, boekjaar)`._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| nummer | int | Yes | 0–99 |
| naam | string | Yes | Programma naam |
| omschrijving | text | No | Operator narrative |
| portefeuillehouder | string | No | Bestuurder |
| taakvelden | array[ref[Taakveld]] | Yes | Covered taakvelden |
| doelstellingen | array[object{wat, wanneer, kpi}] | No | Doelstelling rows |
| beleidsindicatoren | array[ref[BeleidsIndicator]] | No | Linked indicators |
| boekjaar | int | Yes | Cyclus jaar |
| versie | enum | Yes | begroting / jaarrekening / burap-1 / burap-2 / marap / tussenrapportage |
| raadsbesluitNummer | string | No | Sluitend-override anchor (REQ-BBV-003) |
| raadsbesluitDatum | date | No | Sluitend-override anchor |
| administrationId | string | Yes | FK to Administration |

**Uniqueness:** `(administrationId, nummer, boekjaar)`.

### BeleidsIndicator
**Primary spec:** bookkeeping-bbv-compliance
_Vaste of administratie-eigen beleidsindicator gekoppeld aan één
Programma. De 39 wettelijke indicatoren worden geseed; operators
mogen lokale indicatoren toevoegen._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Indicator code |
| naam | string | Yes | Indicator naam |
| eenheid | string | Yes | Meeteenheid (bijv. %, aantal) |
| bron | string | Yes | Bron (bijv. CBS, gemeente) |
| waarde | number | No | Vastgestelde waarde |
| programma | ref[Programma] | Yes | Linked programma |

**Uniqueness:** `(administrationId, code, programma)`.

### MeerjarenBudget
**Primary spec:** bookkeeping-bbv-compliance
_Vierjaars budget per programma × taakveld × economische_categorie
per horizon (T..T+3). All amounts integer-cent. Drives REQ-BBV-003
sluitend-check op `Programma.publish`._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| programma | ref[Programma] | Yes | FK |
| taakveld | ref[Taakveld] | Yes | FK |
| economischeCategorie | ref[EconomischeCategorie] | Yes | FK |
| boekjaar | int | Yes | Cyclus T |
| bedragBaten | decimal(15,2) | Yes | Cents |
| bedragLasten | decimal(15,2) | Yes | Cents |
| versie | enum | Yes | primitief / na-wijziging / realisatie |
| begrotingswijziging | ref[Begrotingswijziging] | No | Source of mutation |
| meerjarenHorizon | int | Yes | 0..3 |
| toelichting | text | No | Per-row narrative |
| stelselwijziging | boolean | No | Default false |

**Uniqueness:** `(administrationId, programma, taakveld, economischeCategorie, boekjaar, meerjarenHorizon, versie)`.

### Reserve
**Primary spec:** bookkeeping-bbv-compliance
_Eigen-vermogen earmark. Mutations routed via taakveld 0.10
(REQ-BBV-004). `algemene` OR `bestemming`._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string | Yes | Reserve naam |
| soort | enum | Yes | algemeen / bestemming |
| doel | text | Conditional | Required when soort = bestemming |
| raadsbesluitInstelling | string | Yes | Instellingsbesluit reference |
| plafond | decimal(15,2) | No | Cents |
| bodem | decimal(15,2) | No | Cents |
| looptijdEinde | date | No | End date |
| programma | ref[Programma] | No | Linked programma (optional) |
| rentetoerekening | boolean | No | Default false |
| saldoBeginJaar | decimal(15,2) | Yes | Cents |
| saldoEindJaar | decimal(15,2) | No | Computed, cents |

**Uniqueness:** `(administrationId, naam)`.

### Voorziening
**Primary spec:** bookkeeping-bbv-compliance
_Verplichting earmark per BBV art. 44 (categorieën a/b/c/d).
Linked to een gekoppeld taakveld dat alle mutaties moet dragen
(REQ-BBV-004)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string | Yes | Voorziening naam |
| bbvArtikel44Categorie | enum | Yes | a / b / c / d |
| onderbouwingDocument | ref[Document] | Yes | Onderbouwingsdocument |
| actualisatieFrequentieJaar | int | Yes | Frequentie van actualisering |
| volgendeActualisatie | date | Yes | Volgende actualisatie-datum |
| taakveld | ref[Taakveld] | Yes | Gekoppelde taakveld |
| saldoBeginJaar | decimal(15,2) | Yes | Cents |
| dotatiesJaar | decimal(15,2) | Yes | Cents |
| vrijvallenJaar | decimal(15,2) | Yes | Cents |
| saldoEindJaar | decimal(15,2) | No | Computed, cents |

**Uniqueness:** `(administrationId, naam)`.

### MaterieleVasteActiva
**Primary spec:** bookkeeping-bbv-compliance
_Capitalised investment with depreciation schedule. Drives the
onderhoud-kapitaalgoederen paragraaf (REQ-BBV-007 D-3) and the
activeringsgrens guard (REQ-BBV-005)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| omschrijving | string | Yes | Asset naam |
| mvaCategorie | enum | Yes | economisch-nut / economisch-nut-heffing / maatschappelijk-nut |
| aanschafwaarde | decimal(15,2) | Yes | Cents |
| ingebruiknameDatum | date | Yes | Activerings-datum |
| afschrijvingsmethode | enum | Yes | lineair / annuitair |
| afschrijvingstermijnJaar | int | Yes | Termijn |
| restwaarde | decimal(15,2) | Yes | Cents |
| renteOmslagPercentage | decimal(5,3) | No | Percentage |
| taakveld | ref[Taakveld] | Yes | Programma-link |
| kredietbesluit | string | Yes | Raadsbesluit reference |
| componentenMethode | boolean | No | Default false |
| subsidieVanDerden | decimal(15,2) | No | Cents, deducted from base |
| boekwaardeBeginJaar | decimal(15,2) | Yes | Cents |
| afschrijvingJaar | decimal(15,2) | No | Computed, cents |

**Uniqueness:** `(administrationId, omschrijving, ingebruiknameDatum)`.

### Subsidie
**Primary spec:** bookkeeping-bbv-compliance
_Subsidie-record gevoed door de SiSa-bijlage (verstrekt of
ontvangen). Re-uses the existing `Subsidie` schema declared above
in this document; the BBV change adds `sisaIndicator` as the SiSa
joining field (additive)._

### Begrotingswijziging
**Primary spec:** bookkeeping-bbv-compliance
_Amendment op een vastgestelde meerjarenraming. Eén row per
raadsbesluit; status concept → vastgesteld → verwerkt drijft de
`BegrotingswijzigingStacker` die nieuwe MeerjarenBudget rows
genereert met versie `na-wijziging`._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| nummer | string | Yes | PK per administratie |
| programma | ref[Programma] | Yes | FK |
| taakveld | ref[Taakveld] | Yes | FK |
| economischeCategorie | ref[EconomischeCategorie] | Yes | FK |
| bedragOorspronkelijk | decimal(15,2) | Yes | Cents |
| bedragWijziging | decimal(15,2) | Yes | Cents |
| bedragNieuw | decimal(15,2) | Yes | Cents |
| reden | text | Yes | Toelichting |
| raadsbesluitNummer | string | Yes | Anchor |
| raadsbesluitDatum | date | Yes | Anchor |
| status | enum | Yes | concept / vastgesteld / verwerkt |
| effectievedatum | date | Yes | Geldig vanaf |

**Uniqueness:** `(administrationId, nummer)`.

### Paragraaf
**Primary spec:** bookkeeping-bbv-compliance
_Eén van de zeven verplichte paragrafen in de jaarrekening
(BBV art. 9). Een Jaarrekening kan niet worden vastgesteld
zonder alle zeven (REQ-BBV-007 paragraaf-completeness gate)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| type | enum | Yes | lokale-heffingen / weerstandsvermogen / onderhoud-kapitaalgoederen / financiering / bedrijfsvoering / verbonden-partijen / grondbeleid |
| boekjaar | int | Yes | Cyclus jaar |
| status | enum | Yes | draft / concept / vastgesteld |
| voltooiingPercentage | int | No | 0–100, computed |
| autoVelden | array[object{naam, waarde}] | No | Engine-populated |
| narratief | text | No | Operator narrative |
| administrationId | string | Yes | FK |

**Uniqueness:** `(administrationId, type, boekjaar)`.

### Schema extensions

- **Account** (T1 / bookkeeping-operations): four additive optional
  fields — `rgsDecentraalCode` (ref[BbvAccountMapping]),
  `taakveld` (ref[Taakveld]), `economischeCategorie`
  (ref[EconomischeCategorie]) and `bbvClassificatie` (enum:
  exploitatie / investering / reserve / voorziening /
  balans-overig). Required-on-write only for BBV-administrations
  (`administrationType ∈ {gemeente, provincie, waterschap}`); non-BBV
  tenants bypass via `BbvComplianceGuard`.
- **JournalEntry** (T1 / bookkeeping-operations): one additive
  optional field — `rechtmatigheidStatus` (enum: compliant /
  afwijking_within_tolerance / afwijking_outside_tolerance, default
  `compliant`). Driven by the per-posting stamp logic described in
  REQ-BBV-009 / `bbv-rechtmatigheid.md`.

**Cites:** ADR-012 (no-duplicate schemas), ADR-022 (audit-trail
immutable on every register), ADR-031 (PHP guards remain a
legitimate seam — `BbvComplianceGuard` is the single thin seam),
ADR-032 (T3 spec sizing), ADR-037 (modular register fragments).

### CommercialActivity, IntegralCostPrice, ActivityCostAllocation, AlgemeenBelangBesluit, ACMReport, AlertLog, WMOAuditLog, MarketBenchmark

> **Reconciliation note (bookkeeping-market-government-separation, 2026-06-10):** WMO compliance (Wet Markt en Overheid, Mededingingswet ch. 4b) is fully declarative per ADR-031 / ADR-037. The eight schemas land as a single register fragment at `lib/Settings/register.d/bookkeeping-market-government-separation.json`, additively merged into `shillinq_register.json` at load by `SettingsService::deepMergeConfig`. `CommercialActivity` is the central register record (not a free-form GL tag) carrying kostprijsMethode, kostenplaats/kostendrager FKs, ACM melding, ABB FK, and marktcontext; this beats a tag because it allows the cross-subsidy detector, ACM rapportage, and jaarrekening-bijlage to query structured fields. `IntegralCostPrice` is time-versioned per (commercialActivityId, periode) with `status: voorlopig` (monthly) or `definitief` (year-end accountant-signed lock on 31 March of following year); restatements emit a new `-restatement` periode rather than mutating prior records, preserving the audit trail. `ActivityCostAllocation` is a reversible split record applied to JournalEntry posts via the geldende `OverheadDistributionRule` (BBV taakveld 0.4 sleutel from `bookkeeping-cost-centers-dimensions` — no duplicate schema). Handmatige overrides require 4-ogen-akkoord (exactly 2 distinct approver user-ids), a motivering, and mark the original as `status: overridden`. `AlgemeenBelangBesluit` (ABB) carries a 10-state lifecycle (concept → raadsvoorstel → raadsbesluit → publicatie → acm-notified → bezwaar → geldig → evaluatie-due → herziening/intrekking) with precondition validation declared as `x-openregister-lifecycle-actions.beforeTransition` and automatic task generation declared as `afterTransition` (publish-gemeenteblad +14d, notify-acm +7d, review-bezwaarschriften +42d, evaluate-abb at volgendeEvaluatie). `ACMReport` is write-once after the concerncontroller signs (ondertekenaar + signatureFingerprint), submission flips status to `verzonden` and starts the 7-year Mededingingswet bewaartermijn countdown. `AlertLog` is emitted by `CrossSubsidyDetector` monthly for 6 risk scenarios plus the Phase-3 bevoordeling-risk (Art. 25j) scenario; weekly escalation walker reassigns 4-week-old open alerts to gemeentesecretaris. `WMOAuditLog` is append-only with ms-precision timestamps and 7-year retention. `MarketBenchmark` (Phase 3) feeds bevoordeling-risk detection through `CrossSubsidyDetector::detectBevoordelingRisk` declared as `x-openregister-lifecycle-actions.afterCreate`.

**Engine-side helpers (Phase 1+2 implementation, ADR-031 pure-logic fallback for arithmetic the OR aggregation/calculation engine cannot yet express):**
- `IntegralCostPriceCalculator` — REQ-WMO-002 monthly composition of the 6 component groups + BBV-sleutel overhead distribution + WACC vermogenskosten + winstopslag, integer-cent arithmetic.
- `IntegralCostPriceLockService` — REQ-WMO-002 §year-end lock; aggregates voorlopig records into one definitief YTD record with accountant digital signature.
- `ActivityCostAllocationSplitter` — REQ-WMO-003 split composer + handmatige-override + materialise-mode emitter; resolves geldende OverheadDistributionRule by posting date.
- `AbbLifecycleService` — REQ-WMO-005 state-machine precondition validator + automatic-task generator + dependent-activity flagging on intrekking/herziening.
- `DropApiVerificationService` — REQ-WMO-005 §automatic publication verification; composes the openconnector OC-source lookup request and parses the SPARQL response into a `dropVerification` envelope (fail-soft).
- `AcmReportGenerator` — REQ-WMO-006 templated ACM-standaardformulier-mo-2024 export with JSON + minimal SBR/XBRL-style XML serialisations, digital-signature lock, submission state-change.
- `CrossSubsidyDetector` — REQ-WMO-007 + REQ-WMO-012 detector for the 7 scenarios + alert composition + escalation + resolution.
- `WmoAuditLogService` — REQ-WMO-010 audit-entry composer + CSV export + ACM-handhavings-pakket manifest assembler + 7-year retention boundary detector.
- `WmoJaarrekeningBijlageService` — REQ-WMO-004 jaarrekening-bijlage composer with per-activity compliance color (groen/rood), prior-year comparison, Markdown + XBRL-style XML rendering; wired into the `bookkeeping-financial-reporting` T4 jaarrekening-generator via the `ACMReport.jaarrekeningWiring` `x-openregister-lifecycle-actions.afterCreate` hook.
- `CommercialActivityReviewService` — REQ-WMO-001 §c stale-activity review-task generator, scheduled daily at 06:00 UTC.

**Scheduled workflows (declared on the schema fragments, executed by the OR ScheduledWorkflow engine per ADR-031 — no shillinq-side BackgroundJob extends TimedJob for any of these):**
- `wmo-annual-review-detector` — daily 06:00 UTC; emits review tasks for stale CommercialActivity records.
- `wmo-ikp-monthly-calculation` — 1st of month 03:00 UTC; runs IKP composition for each active activity.
- `wmo-ikp-year-end-lock` — 31 March 04:00 UTC; locks the prior-FY IKP-YTD as `status: definitief`.
- `wmo-cross-subsidy-detector` — 1st of month 02:00 UTC; walks all activities, emits AlertLogs.
- `wmo-alert-escalation` — Mondays 05:00 UTC; walks open AlertLogs and escalates the 4-week-old ones.
- `wmo-audit-log-retention-archival` — daily 04:30 UTC; archives entries past the 7-year retention boundary.

**Manifest navigation (REQ-WMO-001/002/003/005/006/007):** the `Bookkeeping > WMO Compliance` menu group (Tier-4 manifest fragment at `src/manifest.d/bookkeeping-market-government-separation.json`) lists 8 register entries (Commercial Activities, Integral Cost Prices, Activity Cost Allocations, Public Interest Decisions, ACM Reports, Cross-Subsidy Alerts, WMO Audit Log, Market Benchmarks) as generic `CnIndexPage` / `CnDetailPage` pages — no bespoke Vue components.

**Phase 3 deferral (REQ-WMO-008/009/011 + parts of REQ-WMO-012):** activity-transition openingsbalans generation, raads-voorstel governance coupling, multi-deelnemer ODRA cost splits, benchmark-sourcing integrations (BDO/COELO). MarketBenchmark schema + bevoordeling-risk detector have already shipped in Phase 1+2 to avoid a second register migration; the remaining Phase 3 tasks design surface lives in `openspec/changes/bookkeeping-market-government-separation/design.md` decisions D9+.

**Cites:** ADR-031 (schema-declarative), ADR-037 (modular register fragments), ADR-024 (Tier-4 manifest pages), ADR-008 (event bus for the JournalEntry-post listener — wired declaratively via `x-openregister-event-listeners` on ActivityCostAllocation).

### CostCenter, Project, AnalyticalDimension — segment P&L extensibility (REQ-CD-002 / REQ-CD-004 / REQ-CD-006 / REQ-CD-007)

> **Reconciliation note (bookkeeping-cost-centers-dimensions, 2026-06-11):** Segment P&L roll-up is fully declarative per ADR-031 / ADR-037. `CostCenter` and `Project` schemas are owned by `lib/Settings/shillinq_register.json` (originally landed via the prior `consultancy-project-accounting` build); both carry `code` / `name` / `parentCode` (self-relation for hierarchical roll-up per REQ-CD-007) / `responsibleUser` / `lifecycleState` / `administrationId`, with the Project schema also carrying the consultancy fields (projectNumber, customerId, recognitionMethod, etc.). `AnalyticalDimension` is the round-2 addition declared in `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json`; it is the operator-extensible register that enables REQ-CD-006 custom dimensions (Region, Product Line, Department, …) without PHP/Vue edits. Each AnalyticalDimension carries a `dataType` (string|integer|decimal|date|reference), an `isHierarchical` flag (parent-child tree roll-up), and optional `referenceRegister`/`referenceSchema` so reference-typed dimensions validate values via the OR relations engine. `GLLine` (in `shillinq_register.json`) carries `costCenterCode`, `kostenDragerCode`, `projectCode`, and a free-form `dimensions` JSON map for the analytical-dimension values; the round-2 fragment overlay layers four `x-openregister-aggregations` on top — `byCostCenter`, `byCostCenterHierarchy`, `byProject`, and `byAnalyticalDimension` (wildcard `dimensions.*` groupBy). All four sum the GLLine amount field and filter by `administrationId`. The wildcard aggregation is what makes the surface operator-extensible — declaring a new AnalyticalDimension is enough to contribute a group to the segment P&L without any code change. Manifest navigation (Bookkeeping > Cost Centers / Projects / Analytical Dimensions) carries index+detail pages for each schema and the fragment ships seed objects (three Dutch cost centers, two projects, and the REGION + PRODUCT_LINE example dimensions). No bespoke PHP `SegmentReportService` or `DimensionService` class ships — the OR aggregation engine consumes the declarations at runtime.

**Cites:** ADR-031 (schema-declarative segment P&L), ADR-037 (modular register fragments + manifest fragments), ADR-022 (registers as the single source, no parallel `lib/Db/CostCenter` Mapper), `bookkeeping-cost-centers-dimensions/specs/spec.md` REQ-CD-002..008.

### SalesOrder
**Schema.org:** `schema:Order`
_The booking term for recognized recurring revenue. The order is the actual booking term; the contract is the legal signer (referenced by `contractId` as a plain string, NOT a modeled Contract entity). Recognized recurring revenue is computed per reporting period by the chained `order-revenue-recognition-engine` service (ADR-031 exception) over the order's `SalesOrderLine`s. The schema is fully declarative `config` in `lib/Settings/shillinq_register.json`._
**Primary spec:** recurring-revenue-recognition

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderId | string | Yes | Unique identifier (business key) |
| ondernemingId | string | Yes | FK to the selling Corporation |
| administrationId | string | Yes | FK to the Administration tenant |
| klantId | string | Yes | FK to the customer |
| orderDate | date | Yes | Date the order was booked |
| termStart | date | Yes | Start of the booking term |
| termEnd | date | No | End of the booking term; `null` = indefinite (open-ended) |
| status | enum | Yes | One of active, ended (lifecycle of the booking) |
| currency | string | Yes | ISO 4217 currency code; default EUR |
| contractId | string | No | Plain string reference to the legal agreement — NOT a modeled entity; no referential-integrity resolution |

**Relations:**
- → Corporation (many-to-one, via ondernemingId)
- → CustomerMaster (many-to-one, via klantId)
- → Administration (many-to-one, via administrationId)
- ← SalesOrderLine (one-to-many, via orderId)

> **Note (order-revenue-recognition, 2026-06-20):** `contractId` is deliberately an unmodeled plain-string legal reference — there is NO `Contract` entity (the full IFRS 15 `Contract` / `PerformanceObligation` model is the separate `bookkeeping-ifrs15-revenue` capability). Carries `x-openregister-lifecycle` (active → ended), `x-openregister-audit-trail.enabled=true`, and `administrationId`-scoped RBAC consistent with the other bookkeeping registers.

### SalesOrderLine
**Schema.org:** `schema:OrderItem`
_A line on a `SalesOrder`, tagged `RECURRING` or `ONE_OFF`, declaring the recognition method the chained engine uses. `RECURRING` lines carry a `frequentie` normalized to a monthly rate; `ONE_OFF` lines carry a `recognitionDate` and are recognized point-in-time, never as recurring revenue. Null `termStart`/`termEnd` inherit the parent order's term. Recognized recurring revenue for a period = Σ over RECURRING lines of `monthlyRate × overlapMonths(termOf(line), period)` — computed by the chained `order-revenue-recognition-engine` service (ADR-031 exception), not declaratively (the OR aggregation grammar cannot express runtime-period interval-overlap proration)._
**Primary spec:** recurring-revenue-recognition

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineId | string | Yes | Unique identifier |
| orderId | string | Yes | FK to the parent SalesOrder |
| administrationId | string | Yes | FK to the Administration tenant |
| nature | enum | Yes | One of RECURRING, ONE_OFF |
| label | string | Yes | Human-readable line label |
| amount | number | Yes | Per-interval amount for RECURRING; total for ONE_OFF (EUR, multipleOf 0.01) |
| frequentie | enum | No | One of MAANDELIJKS, KWARTAALS, JAARLIJKS, WEKELIJKS, TWEEWEKELIJKS — required for RECURRING, null for ONE_OFF |
| recognitionMethod | enum | Yes | One of OVER_TIME, POINT_IN_TIME |
| recognitionDate | date | No | Required for POINT_IN_TIME lines; the date the obligation is satisfied |
| termStart | date | No | Line term start; inherits the order's termStart when null |
| termEnd | date | No | Line term end; inherits the order's termEnd when null |
| accountNumber | string | No | GL account code (FK to Account.accountNumber) |

**Relations:**
- → SalesOrder (many-to-one, via orderId)
- → Administration (many-to-one, via administrationId)
- → Account (many-to-one, via accountNumber)

> **Note (order-revenue-recognition, 2026-06-20):** Validation rules — a RECURRING line carries a non-null `frequentie`; a POINT_IN_TIME line carries a non-null `recognitionDate`; a line with null `termStart`/`termEnd` is evaluated against the parent order's term. Carries `x-openregister-audit-trail.enabled=true`, `administrationId`-scoped RBAC, and an `x-openregister-relations` entry to its parent SalesOrder. The recognition arithmetic is the chained `-engine` (kind: code) change per ADR-032; this head change ships only the declarative schema + seed.

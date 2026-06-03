# ADR: Data Model — Shillinq

**Status:** accepted
**Entities:** 235

## Context

All data entities are OpenRegister schemas. This ADR is the single source of truth
for the app's data model. Individual specs REFERENCE these entities but do not redefine them.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

## Entities

### APTransaction
**Schema.org:** `schema:Order`
_Financial transaction representing an invoice, credit note, or debit note in accounts payable/receivable flow._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Unique invoice or transaction identifier |
| transactionType | enum | Yes | Type of transaction |
| transactionDate | date | Yes | Date invoice or transaction issued |
| dueDate | date | Yes | Payment due date |
| amount | MonetaryAmount | Yes | Total transaction amount including tax |
| paymentTerms | string | No | Payment conditions (e.g., net 30, 2/10 net 30) |
| description | string | No | Invoice line items or transaction details |

**Relations:**
- → Payee (many-to-one)
- → Receipt (one-to-many)
- → Payment (one-to-many)
- → DunningNotice (one-to-many)

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

> **Vpb-pligtig annotation (add-shillinq-vpb-corporate-tax, 2026-06-03):** The
> optional `vpbPligtig: boolean` field (default `false`) added by
> `add-shillinq-vpb-corporate-tax` marks an account as Vpb-pligtig per Wet
> modernisering Vpb-plicht (2016). Postings against accounts with `vpbPligtig: true`
> contribute to the Vpb-balans aggregation declared on `VpbBalansLink`
> (`vpbBalansFiltered`, REQ-VPB-003). The flag is a schema metadata field per ADR-031
> — no parallel `VpbAccount` register exists. Accounts with `vpbPligtig: true` that
> are not referenced by any `VpbBalansLink.accountNumbers` in their administration are
> surfaced as orphaned Vpb-pligt warnings in the Vpb menu detail view (REQ-VPB-002).
> See `openspec/changes/add-shillinq-vpb-corporate-tax/design.md` §D1 for the
> declarative-vs-parallel-register decision.

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

### BankAccount
**Schema.org:** `schema:BankAccount`
_Schema.org BankAccount — standard vocabulary for bankaccount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountName | string | Yes | Account display name |
| iban | string | Yes | IBAN number |
| bic | string | No | BIC/SWIFT code |
| bankName | string | No | Name of the bank |
| currency | string | Yes | Account currency |
| balance | number | No | Current balance |

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
_A CAMT.053 bank statement generated by the `shillinq-bank-transaction-polling` ScheduledWorkflow from aggregator data. The generated XML is attached via docudesk. New-statement notifications are declared as `x-openregister-notifications` — no BankNotificationService._
**Primary spec:** bookkeeping-bank-connectors

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bankConnectionId | string | Yes | FK to the BankConnection that sourced this statement |
| statementFormat | enum | Yes | camt.053.001.08 |
| statementDate | date-time | Yes | Statement generation timestamp |
| statementAttachmentUri | string | No | docudesk attachment URI for the CAMT.053 XML |
| transactionCount | integer | No | Number of transactions in the statement |
| administrationId | string | Yes | FK to the Administration owning this statement |

**Relations:**
- → BankConnection (many-to-one, via bankConnectionId)

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
| parentCode | string | No | FK to parent CostCenter.code for hierarchy via self-relation |
| responsibleUser | string | No | NC user id of the cost-center owner |
| lifecycleState | enum | Yes | One of active, blocked, archived (mirrors Account lifecycle per REQ-CoA-005) |
| administrationId | string | Yes | FK to the Administration |

**Relations:**
- self → CostCenter (many-to-one, via parentCode → code; hierarchy navigation)
- → GLLine (one-to-many, via costCenterCode FK, additive dimension field per REQ-CC-003)

> **Reconciliation note (add-shillinq-cost-centers-dimensions, 2026-06-03):** The earlier `CostCenter` entry (primary spec: cost-accounting-allocation) described a generic cost center with `description/status/budget/createdDate`. This entry supersedes it for the shillinq bookkeeping tier with the T4 dimensional accounting shape per REQ-CC-002: `parentCode` self-relation for hierarchy, `lifecycleState` enum mirroring Account, and `administrationId` FK. The OR-managed register pattern (ADR-022) replaces any parallel database table. No new PHP classes — this is a schema-only declaration per ADR-031.

> **Ondernemingsactiviteit annotation (add-shillinq-vpb-corporate-tax, 2026-06-03):**
> The optional `ondernemingsActiviteit: boolean` field (default `false`) marks a
> cost-center as an ondernemingsactiviteit per Wet modernisering Vpb-plicht (2016)
> and bookkeeping-market-government-separation. Cost-centers with
> `ondernemingsActiviteit: true` may be referenced by a `VpbBalansLink.costCenterId`
> (REQ-VPB-002). See `openspec/changes/add-shillinq-vpb-corporate-tax/design.md` §D2
> for the design decision (overlay over parallel register).

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
_Project or cost object for tracking time, materials, and costs on a project basis with budget monitoring and multi-dimensional reporting_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique project cost code |
| name | string | Yes | Project name |
| description | string | No | Project description and scope |
| budget | number | No | Total project budget |
| totalCost | number | No | Total costs incurred to date |
| startDate | datetime | Yes | Project start date |
| endDate | datetime | No | Project completion or planned end date |
| status | string | Yes | Status: active, closed, or archived |

**Relations:**
- → Organization (many-to-one)
- → CostCenter (many-to-one)

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
_Multi-currency balance tracking per account for foreign currency management_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| balanceId | string | Yes | Unique balance record identifier |
| currency | string | Yes | Currency code (ISO 4217) |
| balance | number | Yes | Current balance amount |
| previousBalance | number | No | Previous balance for variance tracking |
| lastUpdated | datetime | Yes | Last update timestamp |

**Relations:**
- → BankAccount (many-to-one)

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
_A detailed schedule defining depreciation method, rate, and yearly calculations for a fixed asset with automated computation_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scheduleNumber | string | Yes | Unique identifier for the depreciation schedule |
| name | string | Yes | Name or description of the depreciation schedule |
| startDate | datetime | Yes | Start date of the depreciation period |
| endDate | datetime | Yes | End date of the depreciation period |
| depreciationMethod | string | Yes | Method used: linear, declining-balance, units-of-production |
| annualRate | number | Yes | Annual depreciation rate as a percentage or amount |
| totalDepreciationAmount | number | No | Total depreciation amount over the schedule period |
| status | string | Yes | Current status: planned, active, completed |

**Relations:**
- → FixedAsset (many-to-one)

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
**Schema.org:** `schema:Event`
_Follow-up notice for overdue unpaid transactions, escalating through dunning levels toward legal action._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeDate | date | Yes | Date when dunning notice was issued |
| dueDate | date | Yes | New payment deadline in the notice |
| reminderLevel | enum | Yes | Escalation level of dunning process |
| amount | MonetaryAmount | Yes | Outstanding amount due |
| eventStatus | enum | Yes | Status of the dunning notice |
| description | string | No | Custom message or legal terms included |

**Relations:**
- → APTransaction (many-to-one)
- → Payee (many-to-one)

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

### FiscalYear
**Schema.org:** `schema:Event`
_An accounting period representing a fiscal year for financial reporting and regulatory compliance._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| year | integer | Yes | The fiscal year number (e.g., 2024) |
| startDate | date | Yes | The first day of the fiscal period |
| endDate | date | Yes | The last day of the fiscal period |
| isClosed | boolean | No | Whether the fiscal year is closed for amendments |
| closingDate | date | No | Date when the fiscal year was officially closed |

**Relations:**
- → FinancialReport (one-to-many)
- → JournalEntry (one-to-many)

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
| subLedgerType | enum | No | One of ap, ar, project, none (T2 owns the sub-ledger registers) |
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

> **Reconciliation note (add-shillinq-cost-centers-dimensions, 2026-06-03):** The T1 `GLLine` schema is additively extended with four new optional fields (`costCenterCode`, `kostenDragerCode`, `projectCode`, `dimensions`) per REQ-CC-003. The existing `costCenter` field is retained as the backwards-compatible alias for `costCenterCode`. T1 single-dimension callers remain correct — the new fields are nullable and non-required. Segment P&L aggregations (`segmentPnlByCostCenter`, `segmentPnlByKostenDrager`, `segmentPnlByProject`) are declared on `GLLine` as `x-openregister-aggregations` per ADR-031 + REQ-CC-005; no PHP `SegmentReportService` is authored.

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

**Relations:**
- → Supplier (many-to-one)
- → ProcurementOrder (many-to-many)

### InventoryStock
**Schema.org:** `schema:Thing`
_Stock levels, inventory tracking, and reorder management by location_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sku | string | Yes | Stock Keeping Unit identifier |
| quantity | number | Yes | Current stock quantity |
| reorderLevel | number | No | Minimum quantity threshold for reorder trigger |
| reorderQuantity | number | No | Standard reorder quantity |
| location | string | No | Physical storage location or warehouse |
| unitCost | number | No | Cost per unit |
| lastRestockDate | datetime | No | Date of last stock replenishment |
| status | string | Yes | Inventory status (active, inactive, discontinued) |

**Relations:**
- → Product (many-to-one)
- → Organization (many-to-one)

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

**Relations:**
- → Product (many-to-one)
- → CostCenter (many-to-one)

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
_A line item detailing goods or services on an invoice_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | number | Yes | Sequential line number |
| description | string | Yes | Item description |
| quantity | number | Yes | Quantity of items |
| unitPrice | number | Yes | Price per unit |
| lineAmount | number | Yes | Total line amount before tax |
| tax | number | No | Tax on line item |
| unit | string | No | Unit of measurement |

**Relations:**
- → Invoice (many-to-one)
- → Product (many-to-one)

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
_A physical or geographic location for multi-site budget allocation and tracking_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Location name |
| code | string | No | Location code or identifier |
| address | string | No | Physical address |
| region | string | No | Geographic region |

**Relations:**
- → Organization (many-to-one)
- → Budget (one-to-many)

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
_Vendor (accounts payable) or customer (accounts receivable) party in financial transactions._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal registered business name |
| tradeName | string | No | Trade name or DBA if different from legal name |
| vatID | string | Yes | Dutch VAT identification number |
| kvkNumber | string | No | KvK (Chamber of Commerce) registration number |
| email | string | Yes | Contact email address |
| telephone | string | No | Contact telephone number |
| iban | string | No | International Bank Account Number for transfers |
| bic | string | No | BIC/SWIFT code for international transactions |

**Relations:**
- → APTransaction (one-to-many)
- → DunningNotice (one-to-many)

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

### VpbBalansLink
**Schema.org:** `schema:Dataset`
_Overlay register tying an ondernemingsactiviteit cost-center to its cluster of Vpb-pligtige accounts. Declared per REQ-VPB-002 from bookkeeping-vpb-corporate-tax. No PHP service — pure schema overlay per ADR-031. The `vpbBalansFiltered` aggregation declared on this schema filters GLLine rows per `accountNumbers` and `fiscalYearPeriods`, groups per `costCenterId`, and produces Activa / Passiva / Resultaat per ondernemingsactiviteit (REQ-VPB-003). The SBR payload binding maps the aggregation output to Belastingdienst Vpb XSD fields; actual transmission rides the T4-base `bookkeeping-sbr-xbrl-reporting` SBR endpoint (REQ-VPB-004, ADR-019). See `openspec/changes/add-shillinq-vpb-corporate-tax/design.md` §D2–D4._
**Primary spec:** bookkeeping-vpb-corporate-tax

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| costCenterId | string | Yes | FK to CostCenter with ondernemingsActiviteit: true |
| accountNumbers | array | Yes | Array of Account.accountNumber strings (vpbPligtig: true) |
| vpbPligtigVanaf | date | Yes | Start date of Vpb-pligtigheid for this cluster |
| administrationId | string | Yes | FK to the Administration |
| description | string | No | Operator description of the ondernemingsactiviteit cluster |

**Relations:**
- → CostCenter (many-to-one, via costCenterId → code; ondernemingsActiviteit must be true)
- → Account (many-to-many, via accountNumbers → accountNumber; vpbPligtig must be true on each)
- → GLLine (aggregation source for vpbBalansFiltered)

> **Annotation (add-shillinq-vpb-corporate-tax, 2026-06-03):** `VpbBalansLink`
> carries the `vpbBalansFiltered` aggregation (REQ-VPB-003) and the SBR payload
> binding for Vpb-aangifte voorbereiding (REQ-VPB-004). Orphaned-Vpb-pligt invariant:
> accounts with `vpbPligtig: true` not referenced by any `VpbBalansLink.accountNumbers`
> in their administration surface as warnings in the Vpb menu detail view (Task 11).
> The Vpb-aangifte voorbereiding docudesk template is registered in
> `lib/Settings/docudesk-templates.json` under `templateId: vpb-aangifte-voorbereiding`.

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

### XBRLTaxonomy
**Schema.org:** `schema:CreativeWork`
_XBRL (eXtensible Business Reporting Language) taxonomy definitions for structured tax reporting, compliance, and regulatory filing_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyId | string | Yes | Unique taxonomy identifier |
| version | string | Yes | Taxonomy version number |
| effectiveDate | datetime | Yes | Date when taxonomy becomes effective |
| namespace | string | Yes | XML namespace URI for the taxonomy |
| elements | array | No | List of XBRL element definitions and mappings |

**Relations:**
- → TaxReturn (one-to-many)

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

# ADR: Data Model — Shillinq

**Status:** accepted
**Entities:** 248
**Entities:** 253

## Context

All data entities are OpenRegister schemas. This ADR is the single source of truth
for the app's data model. Individual specs REFERENCE these entities but do not redefine them.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

## Entities

<!-- T2 bookkeeping-compliance entities (add-shillinq-bookkeeping-compliance, 2026-06-01) -->

### FiscalPeriod
**Schema.org:** `schema:DatedMoneySpecification`
_A monthly or quarterly fiscal period with an open→closing→closed→audit-locked lifecycle. Promotes T1's `GLLine.periodId` stub-string to a managed register._
**Primary spec:** bookkeeping-period-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | Unique period code (e.g. 2026-01, 2026-Q1) |
| name | string | Yes | Human-readable label (e.g. Januari 2026) |
| startDate | date | Yes | First day of the period |
| endDate | date | Yes | Last day of the period |
| fiscalYear | string | Yes | Fiscal year this period belongs to |
| administrationId | string | Yes | FK to the owning Administration |
| state | enum | Yes | One of open, closing, closed, audit-locked |
| closeReason | string | No | Operator-provided reason for closing |
| reopenedHistory | array | No | {reopenedAt, reopenedBy, reason} entries per reopen |

**Relations:**
- → GLLine (one-to-many, via GLLine.periodId)
- → Administration (many-to-one)

### VendorMaster
**Schema.org:** `schema:Organization`
_Vendor (crediteur) master carrying remittance and payment-terms data for accounts payable._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorId | string | Yes | Internal vendor code (e.g. CRD-0001) |
| legalName | string | Yes | Official registered business name |
| iban | string | Yes | Vendor's SEPA bank account IBAN |
| email | string | Yes | Contact email for remittance advice |
| paymentTermsDays | integer | No | Default payment terms (days net) |
| administrationId | string | Yes | FK to the owning Administration |
| lifecycleState | enum | Yes | One of active, blocked, archived |

**Relations:**
- → APInvoice (one-to-many)
- → Administration (many-to-one)

### APInvoice
**Schema.org:** `schema:Invoice`
_An incoming vendor invoice (inkoopfactuur) with a draft→received→matched→approved→posted→paid lifecycle. Materialises a balanced GLTransaction on posting._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorInvoiceRef | string | Yes | Vendor's own invoice number |
| vendorId | string | Yes | FK to VendorMaster.vendorId |
| invoiceDate | date | Yes | Date the vendor invoice was issued |
| dueDate | date | Yes | Payment due date |
| grossAmount | number | Yes | Total amount including VAT (EUR) |
| netAmount | number | Yes | Net amount excluding VAT |
| periodId | string | Yes | FK to FiscalPeriod.periodId |
| lifecycleState | enum | Yes | draft, received, matched, approved, posted, paid, voided |
| sourceDocumentUri | string | No | Docudesk FK URI (docudesk://attachments/...) |

**Relations:**
- → VendorMaster (many-to-one)
- → GLTransaction (many-to-one, via glTransactionId on posting)
- → PaymentRun (many-to-one, via paymentRunId)

### PaymentRun
**Schema.org:** `schema:MoneyTransfer`
_A batch of selected AP invoices paid together; emits SEPA pain.001 XML as a calculated field._
**Primary spec:** bookkeeping-accounts-payable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| runId | string | Yes | Unique payment run code (e.g. PAY-2026-001) |
| runDate | date | Yes | Intended value date for bank processing |
| totalAmount | number | Yes | Sum of selected invoice amounts |
| selectedInvoiceIds | array | Yes | APInvoice UUIDs included in this run |
| sepaXml | string | No | Calculated SEPA pain.001.001.03 XML |
| lifecycleState | enum | Yes | draft, approved, exported, settled |

**Relations:**
- → APInvoice (one-to-many, via selectedInvoiceIds)
- → Administration (many-to-one)

### CustomerMaster
**Schema.org:** `schema:Organization`
_Customer (debiteur) master carrying credit-limit and dunning-policy data for accounts receivable._
**Primary spec:** bookkeeping-accounts-receivable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| customerId | string | Yes | Internal customer code (e.g. DEB-0001) |
| legalName | string | Yes | Official registered business name |
| email | string | Yes | Contact email for invoice delivery |
| creditLimit | number | No | Maximum outstanding AR balance allowed (EUR) |
| dunningPolicyRef | string | No | FK to OR dunning-policy record |
| administrationId | string | Yes | FK to the owning Administration |
| lifecycleState | enum | Yes | One of active, blocked, archived |

**Relations:**
- → ARInvoice (one-to-many)
- → Administration (many-to-one)

### ARInvoice
**Schema.org:** `schema:Invoice`
_An outgoing customer invoice (verkoopfactuur) with a draft→issued→paid/overdue/disputed/written-off lifecycle. Carries forward the original Shillinq invoicing scope._
**Primary spec:** bookkeeping-accounts-receivable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Sequential invoice number (e.g. 2026-0042) |
| customerId | string | Yes | FK to CustomerMaster.customerId |
| invoiceDate | date | Yes | Date the invoice was issued |
| dueDate | date | Yes | Payment due date |
| grossAmount | number | Yes | Total amount including VAT |
| netAmount | number | Yes | Net amount excluding VAT |
| periodId | string | Yes | FK to FiscalPeriod.periodId |
| lifecycleState | enum | Yes | draft, issued, paid, overdue, disputed, written-off |
| sourceDocumentUri | string | No | Docudesk FK URI (PDF invoice) |
| matchedBankLineId | string | No | FK to BankStatementLine.lineId on payment match |

**Relations:**
- → CustomerMaster (many-to-one)
- → GLTransaction (many-to-one, via glTransactionId on issue)
- → DunningRecord (one-to-many)
- → BankStatementLine (many-to-one, via matchedBankLineId)

### DunningRecord
**Schema.org:** `schema:Message`
_A dunning (aanmaning) communication record at a given escalation level for an overdue AR invoice. The dunning workflow itself is consumed from OpenRegister per ADR-022._
**Primary spec:** bookkeeping-accounts-receivable-core

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| dunningId | string | Yes | Unique dunning record identifier |
| arInvoiceId | string | Yes | FK to ARInvoice.invoiceNumber |
| dunningLevel | enum | Yes | reminder1, reminder2, formal-notice, collection |
| issuedDate | date | Yes | Date the dunning communication was issued |
| dueDate | date | Yes | New payment deadline in the notice |
| amount | number | Yes | Outstanding amount stated in the notice |
| administrationId | string | Yes | FK to the owning Administration |
| status | enum | Yes | pending, sent, responded, escalated, withdrawn |

**Relations:**
- → ARInvoice (many-to-one)

> **Reconciliation note:** The earlier `DunningNotice` entry (Schema.org
> `schema:Message`, primary spec accounts-payable-receivable) is the legacy
> AP/AR-draft shape. T2's canonical dunning entity is `DunningRecord` registered
> in `lib/Settings/shillinq_register.json`. New register declarations MUST use
> `DunningRecord`; `DunningNotice` is retained for historical reference only.

### BankStatement
**Schema.org:** `schema:BankAccount`
_A bank statement header imported from CAMT.053, MT940, or manual CSV with an imported→in-progress→reconciled→audit-locked lifecycle._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| statementId | string | Yes | Unique statement identifier (e.g. BS-2026-001) |
| bankAccountIban | string | Yes | IBAN of the reconciled bank account |
| administrationId | string | Yes | FK to the owning Administration |
| periodStart | date | Yes | First date covered by the statement |
| periodEnd | date | Yes | Last date covered by the statement |
| openingBalance | number | Yes | Opening balance per the bank (EUR) |
| closingBalance | number | Yes | Closing balance per the bank (EUR) |
| importFormat | enum | Yes | camt053, mt940, csv, manual |
| fileChecksum | string | Yes | SHA-256 hash of imported file for deduplication |
| lifecycleState | enum | Yes | imported, in-progress, reconciled, audit-locked |

**Relations:**
- → BankStatementLine (one-to-many)
- → Administration (many-to-one)

### BankStatementLine
**Schema.org:** `schema:MoneyTransfer`
_A single transaction line on a bank statement, matchable against AR/AP invoices or routable to a suspense account._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineId | string | Yes | Unique line identifier within the statement |
| statementId | string | Yes | FK to BankStatement.statementId |
| valueDate | date | Yes | Value date (boekingsdatum) of the transaction |
| amount | number | Yes | Transaction amount (signed, EUR) |
| remittanceInfo | string | No | Payment reference / omschrijving |
| counterpartyIban | string | No | IBAN of counterparty |
| status | enum | Yes | unmatched, matched, routed-to-suspense |
| reconciliationMatchId | string | No | FK to ReconciliationMatch.matchId |

**Relations:**
- → BankStatement (many-to-one)
- → ReconciliationMatch (one-to-one, via reconciliationMatchId)

### MatchingRule
**Schema.org:** `schema:DefinedTerm`
_A predicate-based rule for matching bank statement lines against AR/AP invoices or journals. Predicates are declarative schema metadata per ADR-031._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique rule identifier |
| name | string | Yes | Human-readable rule name |
| administrationId | string | Yes | FK to the owning Administration |
| priority | integer | Yes | Lower number = higher priority |
| isActive | boolean | Yes | Whether the rule is active |
| predicates | array | Yes | {field, operator, value, matchTarget} predicate objects |

**Relations:**
- → Administration (many-to-one)

### ReconciliationMatch
**Schema.org:** `schema:Action`
_A candidate or confirmed match linking a bank statement line to an AR/AP invoice, journal, or suspense routing. Emitted by the matching aggregation; confirmed by an operator._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| matchId | string | Yes | Unique match identifier |
| bankStatementLineId | string | Yes | FK to BankStatementLine.lineId |
| matchType | enum | Yes | ar-invoice, ap-invoice, journal, suspense |
| matchedObjectId | string | Yes | UUID of the matched AR/AP invoice or journal |
| matchedAmount | number | Yes | Amount matched |
| confidence | enum | Yes | auto (rule-matched), manual (operator-created) |
| status | enum | Yes | pending, confirmed, rejected |

**Relations:**
- → BankStatementLine (many-to-one)

> **Reconciliation note:** The pre-existing `APTransaction` (Schema.org
> `schema:Order`) and `Payee` entries are the legacy accounts-payable-receivable
> draft model. T2 supersedes that single-entity AP/AR shape with the separate
> `APInvoice` + `VendorMaster` (payable) and `ARInvoice` + `CustomerMaster`
> (receivable) sub-ledgers per Decision D3 in
> `openspec/changes/add-shillinq-bookkeeping-compliance/design.md`. New register
> declarations MUST use the T2 entities above; `APTransaction`/`Payee` are
> retained for historical reference only.

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

> **Reconciliation note (expense-capture-core, 2026-06-03):** The existing `ExpenseClaim` entry (primary spec: approval-workflow-management) is a generic claim schema without cost-centre allocation, multi-currency support, or GL materialisation. `ExpenseClaimEntry` is the shillinq bookkeeping-tier expense claim with full lifecycle (draft → submitted → approved → posted → reimbursed), OR approval-workflow routing per ADR-022, GL materialisation per REQ-EC-012, multi-currency, and cost-centre allocation per line. New expense-capture implementations in shillinq MUST use `ExpenseClaimEntry`. The `ExpenseClaim` entry is retained for generic approval-workflow-management usage outside the bookkeeping tier.

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
- `open → closing` (beginClose): requires all T3 fiscal periods closed (FiscalYearGuard); emits retained-earnings transfer JournalEntry
- `closing → closed` (close): emits opening-balance JournalEntry for next year; fires dimensional rollover CloudEvents; sets `closedAt`, `closedBy`
- `closed → reopened` (reopen): admin-only (`requiresRole: admin`); requires non-empty `reopenReason`; emits two reversing JournalEntry records; sets `reopenedAt`, `reopenedBy`

> **Reconciliation note (add-shillinq-year-end-close, 2026-06-03):** The original `FiscalYear` entry (primary spec: `financial-reporting-accountability`) declared a minimal 5-field schema (`year`, `startDate`, `endDate`, `isClosed`, `closingDate`) without lifecycle machinery. This entry supersedes it with the full T4 year-end close lifecycle declared in `lib/Settings/shillinq_register.json`. The field `year` is renamed `yearNumber` (integer type, composite uniqueness with `administrationId`); `isClosed` and `closingDate` are replaced by the `state` enum and `closedAt`/`closedBy` pair. New `JournalEntry` records now reference `FiscalYear` via `fiscalYearId` for close/reopen journal pairing (previously the reference existed only in the generic `JournalEntry → FiscalYear` relation). Implementations using the old field names must migrate to the new schema shape.

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

> **Reconciliation note:** this legacy `corporations-enterprise` shape is superseded for consolidation/elimination work by the canonical `IntercompanyTransaction` declared under *Intercompany Elimination Engine* below (primary spec `bookkeeping-intercompany-elimination`). The elimination engine reads/writes that source-linked shape; this enterprise entry remains for non-consolidation transfer-pricing/netting use.

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

### InvestmentAsset
**Schema.org:** `schema:Thing`
_Capitalised asset evaluated against the four investeringsaftrek schemes (KIA/EIA/MIA/Vamil) per Wet IB 2001 art. 3.40–3.47. 1-to-1 overlay on FixedAsset that carries eligibility classification, RvO meldingstermijn tracking, and the disposal-window clock._
**Primary spec:** bookkeeping-investeringsaftrek

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | Owning administration (tenant scope) |
| fixedAssetId | string | No | 1-to-1 FK to the FixedAsset (nullable, back-tag safe) |
| omschrijving | string | Yes | Asset description |
| leverancier | string | No | Supplier |
| factuurnummer | string | No | Invoice number |
| aanschafdatum | date | No | Acquisition (invoice) date |
| opdrachtverleningDatum | date | No | Order placement date — authoritative for the RvO 3-month meldingstermijn |
| ingebruiknameDatum | date | No | Commissioning date |
| aanschafwaarde | integer | Yes | Acquisition value in EUR cents |
| valuta | string | No | Currency code (default EUR) |
| btwRegime | string | No | VAT regime affecting the deductible basis |
| categorie | string | No | Asset category |
| energielijstCode | string | No | Matched Energielijst code (EIA) |
| milieulijstCode | string | No | Matched Milieulijst code (MIA/Vamil) |
| kiaEligible | boolean | No | KIA classification (machine baseline, overridable) |
| eiaEligible | boolean | No | EIA classification |
| miaEligible | boolean | No | MIA classification |
| vamilEligible | boolean | No | Vamil classification |
| eligibilityOverride | string | No | Boekhouder override rationale (audited) |
| rvoMeldingStatus | enum | No | RvO notification status (ingediend / definitief / vervallen) |
| rvoMeldingDatum | date | No | Date the RvO melding was sent |
| rvoMeldingDeadline | date | No | Computed deadline = opdrachtverleningDatum + 3 months |
| rvoReferentie | string | No | RvO reference number |

**Relations:**
- → FixedAsset (one-to-one)
- → InvesteringsaftrekClaim (one-to-many)
- → VamilDepreciation (one-to-many)

### EnergielijstCode
**Schema.org:** `schema:DefinedTerm`
_Versioned RvO Energielijst reference code for EIA eligibility. Seeded per fiscal year (investeringsaftrek-energielijst-YYYY.json) and resolved against the opdrachtverleningDatum year, not the current year._
**Primary spec:** bookkeeping-investeringsaftrek

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Energielijst code (e.g. 251701) |
| jaartal | integer | Yes | Fiscal year the list applies to |
| categorie | string | No | Category grouping |
| omschrijving | string | Yes | Description (searchable) |
| deelpercentage | number | No | Eligible portion percentage |
| maxBedragPerEenheid | integer | No | Maximum eligible amount per unit, EUR cents |
| eenheid | string | No | Unit of measure |
| ingangsdatum | date | No | Effective from |
| vervaldatum | date | No | Expires on |

### MilieulijstCode
**Schema.org:** `schema:DefinedTerm`
_Versioned RvO Milieulijst reference code for MIA and Vamil eligibility, including the MIA percentage band and whether Vamil (willekeurige afschrijving) is permitted._
**Primary spec:** bookkeeping-investeringsaftrek

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Milieulijst code (e.g. G3110) |
| jaartal | integer | Yes | Fiscal year the list applies to |
| categorie | string | No | Category grouping |
| omschrijving | string | Yes | Description (searchable) |
| miaPercentage | number | No | MIA deduction percentage (27/36/45) |
| vamilToegestaan | boolean | No | Whether Vamil is permitted for this code |
| deelpercentage | number | No | Eligible portion percentage |
| maxBedrag | integer | No | Maximum eligible amount, EUR cents |
| ingangsdatum | date | No | Effective from |

### InvesteringsaftrekClaim
**Schema.org:** `schema:Thing`
_A per-scheme aftrek claim against an InvestmentAsset for a boekjaar, with the RvO beschikking lifecycle (ingediend → definitief) and a vrijwillige-verlaging audit trail._
**Primary spec:** bookkeeping-investeringsaftrek

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| investmentAssetId | string | Yes | FK to the InvestmentAsset |
| boekjaar | integer | Yes | Fiscal year of the claim |
| scheme | enum | Yes | KIA / EIA / MIA / Vamil |
| grondslag | integer | Yes | Deduction basis in EUR cents |
| percentage | number | No | Applied percentage |
| aftrekbedrag | integer | Yes | Computed deduction in EUR cents |
| status | enum | Yes | ingediend / definitief |
| ingediendInAangifte | boolean | No | Whether included in the filed aangifte |
| rvoBeschikking | object | No | Nested RvO decision (beschikkingsdatum, toegekendBedrag) |
| vrijwilligeVerlaging | integer | No | Voluntary reduction in EUR cents (>= 0, not carry-forwardable for EIA/MIA) |
| verlaginRationale | string | No | Mandatory rationale when a reduction is applied |

**Relations:**
- → InvestmentAsset (many-to-one)
- → Account (zero-to-one, GL posting on disposal)

### VamilDepreciation
**Schema.org:** `schema:Thing`
_Modified depreciation schedule for a MIA+Vamil asset: 75% direct in the ingebruikname year, 25% via the regular schedule over the useful life._
**Primary spec:** bookkeeping-investeringsaftrek

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| investmentAssetId | string | Yes | FK to the InvestmentAsset |
| boekjaar | integer | Yes | Fiscal year of commissioning |
| aanschafwaarde | integer | Yes | Acquisition value in EUR cents |
| directeAfschrijving | integer | Yes | 75% direct depreciation in EUR cents |
| gespreidDeel | integer | Yes | 25% spread portion in EUR cents |
| regulierAfschrijfschema | object | No | Nested schedule (methode, looptijdJaren, restwaarde, jaarlijkseAfschrijving) |

**Relations:**
- → InvestmentAsset (many-to-one)

### KIATier
**Schema.org:** `schema:DefinedTerm`
_The annually-indexed KIA tier table (Wet IB 2001 art. 3.41). KIA is aggregated at boekjaar level over the running kiaJaartotaal; tier 4 carries a tapering formula._
**Primary spec:** bookkeeping-investeringsaftrek

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| tier | integer | Yes | Tier ordinal (1–5) |
| jaartal | integer | Yes | Fiscal year |
| vanaf | integer | Yes | Lower bound of the band, EUR cents (inclusive) |
| tot | integer | No | Upper bound, EUR cents (exclusive; null = open) |
| percentage | number | No | Tier percentage (negative = taper rate) |
| vastBedrag | integer | No | Fixed deduction amount, EUR cents |
| regel | string | No | Human-readable rule description |

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

> **Settlement extension (expense-reimbursement-or-passthrough):** The expense-capture `Receipt` schema is extended **additively** (via `lib/Settings/register.d/expense-reimbursement-or-passthrough.json`, ADR-037) with dual-mode settlement fields: `settlementMode` (enum `reimbursable | pass-through`, optional — null = unclassified, kept optional so existing seed objects keep validating), `linkedCustomerId` (FK Organization, required for pass-through), `markupRuleId` (FK PassThroughMarkupRule), `markupRateApplied`, `markupAmountCalculated` (x-openregister-calculations, locked at submission per REQ-ERP-010), and `passthroughDebitAccountCode`. The spec authored these against schema names `Expense`/`ExpenseClaim`; those do not exist in the shillinq model — the real upstream schemas are `Receipt` (line item) and `ExpenseClaimEntry` (claim), corrected per ADR-022.

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

**Relations:**
- → ProjectAssignment (many-to-one, via projectAssignmentId)
- → Project (many-to-one, via projectId)
- → GLTransaction (many-to-one, via glTransactionId)

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

> **Settlement extension (expense-reimbursement-or-passthrough):** `ExpenseClaimEntry` is extended **additively** (ADR-037 fragment) with claim-level dual-path settlement fields: `settlementMode` (enum, optional; all classified child receipts must share it or be null — mixed-mode rejected at submit per REQ-ERP-003), `totalReimbursableAmount`, `totalPassThroughAmount`, `passThroughCustomerIds` (x-openregister-calculations aggregates over child receipts), `glReimbursableTransactionId`, and `glPassThroughTransactionId` (distinct from the existing `glTransactionId` so both legs of a dual-path post are referenceable). On post, the existing expense-capture post action handles the reimbursable GL leg (debit expense-payable); the new `x-openregister-settlement` contract adds the pass-through branch (one balanced GLTransaction per customer — debit customer AR, credit cost-centre lines + revenue-deferral for markup, REQ-ERP-007/009), a `ExpenseClaimReimbursementNotification` event for treasury (REQ-ERP-008), and GL reversal on a post-submission mode change (REQ-ERP-011). Cross-schema/immutability logic that the declarative DSL cannot express lives in `OCA\Shillinq\Lifecycle\SettlementGuard` (ADR-031 exception path).

### ReimbursementPolicy
**Schema.org:** `schema:Thing`
_Master data (expense-reimbursement-or-passthrough, REQ-ERP-004): per-administration settlement policy — auto-approval threshold, optional markup-approval threshold, and the default employee SEPA bank-account mapping used for the reimbursement notification._
**Primary spec:** expense-reimbursement-or-passthrough

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| policyId | string | Yes | Unique policy identifier per administration |
| name | string | Yes | Human-readable policy name |
| description | string | No | Policy description and scope |
| autoApproveThreshold | number | No | Reimbursable claims at or below this amount auto-approve |
| requiresMarkupApprovalThreshold | number | No | Pass-through markup at or above this triggers an extra approver gate (REQ-ERP-006) |
| employeeBankAccountMapping | string | No | Default SEPA account source for the reimbursement notification (REQ-ERP-008) |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → Administration (many-to-one)

### PassThroughMarkupRule
**Schema.org:** `schema:Offer`
_Master data (expense-reimbursement-or-passthrough, REQ-ERP-005): per-customer / per-category pass-through markup rate, matched at claim submission with priority (customer+category > customer-only > global default) and locked onto the receipt for audit (REQ-ERP-010)._
**Primary spec:** expense-reimbursement-or-passthrough

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique rule identifier |
| targetCustomerId | string | No | FK Organization (customer); null = global default |
| targetCategory | string | No | Expense category; null = all categories |
| markupType | enum | Yes | percentage or fixedAmount |
| markupValue | number | Yes | Fraction (0.15 = 15%) for percentage, or base-currency amount for fixedAmount |
| currency | string | Yes | ISO 4217 |
| effectiveFromYear | integer | Yes | Fiscal year the rule applies from |
| administrationId | string | Yes | FK to Administration |

**Relations:**
- → Administration (many-to-one)
- → Organization (many-to-one, via targetCustomerId)

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
_Foundational multi-administratie (multi-tenant) data model introduced by the `bookkeeping-multi-administratie` change (T1 foundational refactor). `Administration` becomes the **first-class isolation boundary**: it is the juridisch-onafhankelijke boekhouding entity, and the `administrationId` FK that the existing financial schemas already carry (GLTransaction, GLLine, Account, BalanceSheet, FixedAsset, TrialBalanceLine, Order, Invoice, …) now points at it as a real register record rather than a bare string. Each `Administration` owns its own chart-of-accounts (`chartOfAccountsId`), fiscal-year cycle (`fiscalYearStartMonth` + `nonCalendarFiscalYear`), presentation/functional currency, VAT regime + filing frequency, holding linkage (`parentAdministrationId` / `childAdministrationIds` / `consolidateIntoId`), fiscal-unit references (`fiscalUnitVpbId` / `fiscalUnitVatId` — data only; VPB/BTW consolidation logic is delegated to bookkeeping-tax-filing / bookkeeping-vat-return), and a per-administratie backup + retention + archival lifecycle (`status` actief → in_liquidatie → gearchiveerd/opgeheven; archived administrations are read-only for the wettelijke bewaartermijn). `AdministrationMembership` is the **user-administratie-role join** — a Nextcloud uid plus a role (eigenaar/controller/boekhouder/inkijker/accountant_extern/…) and posting/closing rights — so one user may hold a different role per administration; a contact/person is a Nextcloud entity, no person schema is invented. `IntercompanyJournalEntry` links the **two mirrored, self-contained GLTransactions** of an intercompany flow across two administrations (Dutch GAAP keeps each entity's journaal separate): it tracks the `intercompanyNumber`, reconciliation `varianceAmount`, the `eliminateOnConsolidation` flag and the status concept → gekoppeld → bevestigd_beide → eliminatie_geboekt. `ConsolidationMapping` maps een dochter's chart-of-accounts onto the moeder's plus the intercompany elimination account and currency-translation method (pre-positioned for the future `bookkeeping-consolidatie` spec; no consolidation rendering ships here). `AdministrationMigration` is the **asset/contract/employee transfer audit record** between two administrations (boekwaarde vs marktwaarde, juridische grondslag, fiscale behandeling, paired journal-entry FKs, reversible status voorbereid → uitgevoerd → geboekt_beide → teruggedraaid). All five are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-multi-administratie.json`) plus declarative manifest-v2 index/detail navigation (ADR-037 fragment `src/manifest.d/bookkeeping-multi-administratie.json`); object CRUD runs through OpenRegister's own ObjectService API (this app exposes no per-schema PHP controllers — `administrationId` isolation is applied by the read services via `findAll(['filters' => ['administrationId' => …]])`, e.g. `TrialBalanceService`). The administratie-aware RBAC/isolation layer ships as `lib/Service/AdministrationContextService.php` (resolves the user's `AdministrationMembership` records, builds the session context, and provides the IDOR `canAccess()` guard — masked-404 never 403 — and the default-secure `canPostJournalEntry()` check). The intercompany mirroring/reconciliation logic ships as the pure, unit-tested `lib/Service/IntercompanyJournalService.php` (cents-based mirror, reconciliation variance, and the concept → gekoppeld → bevestigd_beide → eliminatie_geboekt status machine). The context/switcher/export-scope API ships as `lib/Controller/AdministrationController.php` (`GET /api/administrations/context`, `POST /api/administrations/switch`, `GET /api/administrations/{id}/export-scope`, all `#[NoAdminRequired]` and scoped to the user's memberships, ADR-005). The in-session switcher Vue component, the per-administratie backup-scheduler side-effect, the archival write-block guard, the XAF byte stream, the migration dual-post flow and the cross-administratie audit viewer are deferred to a follow-up cycle against a live OpenRegister instance (tracked in this change's tasks.md "Deferred" section). The existing `ConsolidationGroup` / `ConsolidatedReport` schemas are unchanged and complementary. The default `Administration` (ADM-001) is seeded idempotently on fresh install (`SettingsService::seedDefaultAdministration()` via the `InitializeSettings` repair step, deduped on `administrationCode`) so single-administratie installs have a valid FK target._
**Primary spec:** bookkeeping-multi-administratie

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Administration | schema:Organization | administrationCode, name, legalForm, kvkNumber, vatNumber, parentAdministrationId, childAdministrationIds[], chartOfAccountsId, fiscalYearStartMonth, vatRegime, status, backupSchedule, dataRetentionYears | → Administration (parent, N:1), → ConsolidationMapping (N:1) |
| AdministrationMembership | join-record | userId, administrationId, role, mayPostJournalEntries, mayCloseFiscalYear, validFrom/validUntil, grantedBy | → Administration (N:1), → NC user (uid) |
| IntercompanyJournalEntry | schema:Action | intercompanyNumber, date, kind, source/destinationAdministrationId, source/destinationJournalEntryId, amount, vatTreatment, eliminateOnConsolidation, status, varianceAmount | → Administration (source + destination), → GLTransaction (both sides) |
| ConsolidationMapping | schema:PropertyValue | name, source/destinationAdministrationId, rules[] (sourceAccount→destinationAccount), intercompanyEliminationAccount, currencyTranslationMethod | → Administration (source + destination) |
| AdministrationMigration | schema:Action | migrationNumber, date, source/destinationAdministrationId, kind, objectIds[], bookValue/marketValueTransferred, fiscalTreatment, legalBasis, source/destinationJournalEntryId, status | → Administration (source + destination), → GLTransaction (both sides) |

### BcfClaim (BCF VAT compensation)
**Schema.org:** `schema:MonetaryAmount` (BcfClaim)
_Btw-compensatiefonds (BCF) claim data model introduced by the `bookkeeping-bcf-vat-compensation` change (T3). A `BcfClaim` is a quarterly claim through which a Dutch public body (gemeente, waterschap) recovers non-recoverable VAT, following a `draft → submitted → accepted → settled` lifecycle (REQ-BCF-001/003). `totalCompensableAmount` and `breakdown` are derived, read-only fields: `OCA\Shillinq\Service\BcfClaimService` computes them server-side from existing `GLLine` + `GLTransaction` + `BbvAccountMapping` data via the real OpenRegister ObjectService API (`findAll`), summing each compensable account's debit-side VAT weighted by `BbvAccountMapping.compensablePercentage / 100` (REQ-BCF-002); the `x-openregister-aggregations.compensableVatBreakdown` block on the schema documents the equivalent declarative shape. The `draft → submitted` transition is gated by the ADR-031 exception-path guard `OCA\Shillinq\Lifecycle\BcfClaimGuard::canSubmit`, which fails closed unless the server-recomputed total is strictly positive AND the claim quarter's `FiscalPeriod` is closed (REQ-BCF-003). BCF compensability is a property of the existing `BbvAccountMapping` classification — `bcfCompensable: boolean` (default false) + `compensablePercentage: int 0-100` (default 100) — not a parallel register (ADR-022, REQ-BCF-004). All money arithmetic runs in integer cents (`BcfCompensationCalculator`) to avoid float drift. Schema + lifecycle + aggregation + seed claims ship as the ADR-037 register fragment `register.d/bookkeeping-bcf-vat-compensation.json`; quarterly DigiKoppeling submission and settlement webhook handling are consumed via OpenConnector / OpenRegister (deferred — require a live instance + the `digikoppeling-bcf` source registration)._
**Primary spec:** bookkeeping-bcf-vat-compensation

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| BcfClaim | schema:MonetaryAmount | claimQuarter, administrationId, totalCompensableAmount (derived), breakdown (derived), state (draft→submitted→accepted→settled), submittedOn, acceptedOn, settledOn, settledAmount, attachmentUri, notes | → Administration (N:1), breakdown[].accountNumber → Account via BbvAccountMapping |

### IntercompanyRelation / IntercompanyTransaction / IntercompanyMatch / IntercompanyMismatch / ToleranceRule / CounterpartyBalance / EliminationJournal (Intercompany Elimination Engine)
**Schema.org:** `schema:FinancialProduct` (IntercompanyRelation, IntercompanyTransaction, CounterpartyBalance), `schema:Thing` (IntercompanyMatch, IntercompanyMismatch, ToleranceRule, EliminationJournal)
_T2 intercompany elimination engine introduced by the `bookkeeping-intercompany-elimination` change. A consolidation group registers stable `IntercompanyRelation` pairs (relation type + default GL accounts + tolerance parameters). Per period the engine auto-detects `IntercompanyTransaction` rows in the source administrations (account-based / label-based / explicitly-marked with a detection confidence), then aggregates each relation's A-side and B-side into an `IntercompanyMatch` (totals, mismatch amount/percentage, status: perfect-match / within-tolerance / outside-tolerance / one-sided-A / one-sided-B). Tolerance is evaluated against a `ToleranceRule` (absolute + relative thresholds, combination method, fallback account, auto-resolve) via an ADR-031 lifecycle guard (`IntercompanyToleranceGuard`). A perfect or within-tolerance match materialises a balanced `EliminationJournal` in the consolidation layer (never in the source administrations); the `EliminationBalanceGuard` rejects any journal whose debit/credit lines do not balance. Outside-tolerance mismatches raise an `IntercompanyMismatch` (cause classification + semi-automated resolution action) onto the exception queue. `CounterpartyBalance` is a read-only aggregation view per entity-pair per period (receivables/payables/net position/flows/mismatch count). The matching and counterparty roll-up are declared as `x-openregister-aggregations`; per ADR-031 the per-side conditional sums, FX conversion (REQ-ICE-009: translation differences post to CTA, not P&L) and cross-period roll-forward (REQ-ICE-008) that the declarative engine cannot yet express live in the bounded exception-path service `lib/Service/IntercompanyMatchingService.php` + pure helper `IntercompanyMatchingCalculator.php` (integer-cent arithmetic). All seven schemas are register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-intercompany-elimination.json`); five default `ToleranceRule` templates seed via `SettingsService::seedIntercompanyToleranceRules` (REQ-ICE-004). A contact/customer counterparty remains a Nextcloud entity — no bespoke contact schema is introduced._
**Primary spec:** bookkeeping-intercompany-elimination

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| IntercompanyRelation | schema:FinancialProduct | relationId, groupId, entityAId, entityBId, relationType, defaultAccountA, defaultAccountB, toleranceAbsolute, toleranceRelative, toleranceFallbackAccount, activeFrom, activeTo, administrationId | → ConsolidationGroup (N:1), → Entity ×2 |
| IntercompanyTransaction | schema:FinancialProduct | sourceAdministrationId, sourceJournalEntryId, sourceLineNumber, bookingDate, glAccount, debitAmount, creditAmount, currency, counterpartyEntityId, relationId, detectionMethod, detectionConfidence, isMatched, matchId, administrationId | → IntercompanyRelation (N:1), → IntercompanyMatch (N:1) |
| IntercompanyMatch | schema:Thing | matchId, periodId, relationId, entityATransactionIds[], entityBTransactionIds[], totalAmountA, totalAmountB, mismatchAmount, mismatchPercentage, matchStatus, generatedEliminationId, administrationId | → IntercompanyRelation (N:1), → EliminationJournal (1:1) |
| IntercompanyMismatch | schema:Thing | mismatchId, periodId, relationId, matchId, causeClassification, amount, currency, description, status, assigneeId, resolutionAction, resolutionNotes, administrationId | → IntercompanyMatch (N:1), → IntercompanyRelation (N:1) |
| ToleranceRule | schema:Thing | ruleId, groupId, relationTypeFilter, toleranceAbsolute, toleranceRelative, toleranceMethod, fallbackAccount, autoResolve, administrationId | → ConsolidationGroup (N:1) |
| CounterpartyBalance | schema:FinancialProduct | balanceId, groupId, entityAId, entityBId, periodId, totalReceivablesAonB, totalPayablesAtoB, netPositionAtoB, totalSalesAtoB, totalPurchasesAtoB, transactionCount, mismatchCount, lastUpdated, administrationId | → ConsolidationGroup (N:1), → Entity ×2 (aggregation view) |
| EliminationJournal | schema:Thing | eliminationId, consolidationPeriodId, matchId, bookingDate, description, lines[], totalDebit, totalCredit, generatedBy, approvedBy, approvedAt, administrationId | → IntercompanyMatch (N:1) |

### GroupEntityRegistry / CbcrJurisdictionSummary / Pillar2JurisdictionComputation / Pillar2SafeHarbour / QdmttReturn / GlobeInformationReturn / CbcrReturn / TaxTreatyOverview
**Schema.org:** `schema:Organization` (GroupEntityRegistry), `schema:Report` (CbcrJurisdictionSummary, Pillar2JurisdictionComputation, Pillar2SafeHarbour, GlobeInformationReturn, CbcrReturn), `schema:GovernmentService` (QdmttReturn), `schema:Legislation` (TaxTreatyOverview)
_Country-by-Country Reporting (CbCR, BEPS Action 13 / Wet Vpb art. 29b–29h) and OESO Pillar Two / GloBE (Wet minimumbelasting 2024) data model introduced by the `bookkeeping-cbcr-pillar2` change (T3) for multinational groups with consolidated revenue ≥ EUR 750M. `GroupEntityRegistry` is master data for each constituent entity (incorporation + tax-residency jurisdiction, consolidation method/percentage, CbCR/Pillar 2 scope flags, LEI/VAT); a responsible owner is a Nextcloud user FK, never an invented contact schema. `CbcrJurisdictionSummary` is the per-jurisdiction roll-up of the seven mandatory CbCR fields (REQ-CBC-002): totalRevenue is a declarative calculation and the 7-field aggregation joins the in-scope entities sharing a jurisdiction. `Pillar2JurisdictionComputation` carries the per-jurisdiction GloBE / Pillar 2 computation (REQ-CBC-003..006): GloBE income (commercial PBT + the ~35-item OESO correction audit trail), adjusted covered taxes, and — all declarative x-openregister-calculations (ADR-031, no GlobeIncomeCalculator.php) — the ETR (adjustedCoveredTaxes / globeIncome, floored at 0), the SBIE carve-out (phased payroll + tangible-asset percentages), excess profit, and the top-up tax; QDMTT/IIR/UTPR allocation is captured for the GIR. `Pillar2SafeHarbour` records the transitional CbCR safe-harbour test result per jurisdiction (de-minimis / simplified-ETR / routine-profits; pass replaces the full computation for FY2024–2026). `QdmttReturn` is the Dutch QDMTT filing record for an NL-resident entity (REQ-CBC-006, QDMTT levied before any foreign IIR). `GlobeInformationReturn` (GIR) references the jurisdiction computations and carries the IIR/UTPR/QDMTT-credit allocation (REQ-CBC-009). `CbcrReturn` is the OESO CbC report referencing the jurisdiction summaries + constituent-entity list, with the mandatory reconciliation to the consolidated financial statements (REQ-CBC-010, residual > EUR 1M flagged unreconciled via a declarative calculation). `TaxTreatyOverview` is DTA reference data for withholding-tax allocation. All eight are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-cbcr-pillar2.json`); the only PHP exception-path code is `lib/Lifecycle/CbcrPillar2Guard.php` (ADR-031) — the QDMTT-priority gate (NL-resident low-taxed income must carry a positive QDMTT allocation before IIR), the summary/CbCR reconciliation sign-offs, and the QDMTT submission completeness check. OESO CbC v2.0 / GIR XML export + SBR/Digipoort submission are openconnector-owned (T4) and DEFERRED. This module consumes T3 `bookkeeping-consolidation-commercial` (per-jurisdiction aggregation), `bookkeeping-deferred-tax` (adjusted covered taxes), `bookkeeping-vpb-mkb` (Vpb per entity), `bookkeeping-fixed-assets-depreciation` (tangible assets for SBIE) and optionally `hrmq` (payroll + FTE)._
**Primary spec:** bookkeeping-cbcr-pillar2

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| GroupEntityRegistry | schema:Organization | entityName, jurisdiction, taxResidency, consolidationMethod, consolidationPercentage, mainBusinessActivity, cbcrIncluded, pillar2Included, state | → GroupEntityRegistry (parent/UPE self), → NC user (responsibleOwner) |
| CbcrJurisdictionSummary | schema:Report | period, jurisdiction, unrelatedPartyRevenue, relatedPartyRevenue, totalRevenue (calc), profitBeforeTax, incomeTaxPaidCash/Accrued, numberOfEmployees, tangibleAssetsOtherThanCash, state | → GroupEntityRegistry (aggregation join) |
| Pillar2JurisdictionComputation | schema:Report | period, jurisdiction, globeIncome (calc), globeIncomeAdjustments[], adjustedCoveredTaxes, etrJurisdiction (calc), substanceBasedIncomeExclusion (calc), topUpTaxAmount (calc), qdmttAmount, iirAmount, utprAmount, state | → CbcrJurisdictionSummary (jurisdiction/period) |
| Pillar2SafeHarbour | schema:Report | period, jurisdiction, testApplied (de-minimis/simplified-etr/routine-profits), testResult, dataSource, supportingCalculations | → Pillar2JurisdictionComputation (jurisdiction/period) |
| QdmttReturn | schema:GovernmentService | period, entity, taxableGlobeIncome, qdmttPayable, filingDueDate, belastingdienstReference, state | → GroupEntityRegistry (NL-resident, N:1) |
| GlobeInformationReturn | schema:Report | period, ultimateParent, mneGroupSummary, jurisdictionalComputations[], topUpTaxAllocation, submissionDeadline, state | → GroupEntityRegistry (UPE, N:1), → Pillar2JurisdictionComputation (1:N) |
| CbcrReturn | schema:Report | period, reportingEntity, jurisdictionSummaries[], constituentEntityList[], reconciliationResidual, reconciliationUnreconciled (calc), reconciliationItems[], submissionDeadline, state | → CbcrJurisdictionSummary (1:N), → GroupEntityRegistry (1:N) |
| TaxTreatyOverview | schema:Legislation | countryA, countryB, treatyName, treatyDate, withholdingRates, mliApplicability | (reference data) |

### KORRegistration / KORAnnualTurnover / KORThresholdAlert / KORRevocation / KOREUTurnover (KOR — Kleine Ondernemersregeling)
**Schema.org:** `schema:DefinedTerm` (KORRegistration), `schema:MonetaryAmount` (KORAnnualTurnover, KOREUTurnover), `schema:Action` (KORThresholdAlert, KORRevocation)
_KOR (Kleine Ondernemersregeling) data model introduced by the `bookkeeping-kor-kleine-ondernemersregeling` change (T2, declarative kind: config). Formalises the full Dutch VAT small-business exemption lifecycle per Wet OB 1968 art. 25 (NL-KOR) & art. 25a–25d (KOR-EU, Richtlijn (EU) 2020/285 per 1-1-2025). `KORRegistration` is the top-level regime-decision entity (one per onderneming per regime): aanmeldgegevens, ingangsDatum, drie-jaars lockInEindDatum, vroegsteOpzegDatum, drempelJaar and a declarative `x-openregister-lifecycle` (draft → ACTIEF → GEEINDIGD_OVERSCHRIJDING | GEEINDIGD_VRIJWILLIG; transitions activate / revokeOverschrijding / optOut). `KORAnnualTurnover` tracks the running KOR-eligible omzet, drempel-benutting (lopendeOmzet / 20000), maandelijkse breakdown and the linear end-of-year prognose — computed post-invoice by `OCA\Shillinq\Service\KorMonitorService` via the real OpenRegister ObjectService API and documented declaratively in `x-openregister-aggregations.korTurnoverByYear` (ADR-031); vrijgestelde (art. 11 OB), intracommunautaire and onroerend-goed omzet are excluded. `KORThresholdAlert` records the 80 % (VROEG) / 90 % (KRITIEK) / 100 % (OVERSCHRIJDING) schijf crossings (each fires once) and dispatches per `kanaal` to notifications.dispatch. `KORRevocation` is the beëindiging entity: type OVERSCHRIJDING (gedwongen) or VRIJWILLIG_NA_LOCKOUT, with the critical rule that `revocatieDatum` is the leveringsdatum of the trigger invoice (not month/quarter/year-end), the computed `btwSuppletieBedrag` (Σ bedrag·0.21/1.21 over KOR-facturen between ingangsDatum and revocatieDatum), and `blokkadeHeraanmelding` = revocatieDatum + 3 jaar. `KOREUTurnover` (regime KOR_EU only) holds the EX-nummer, the EU-wide EUR 100.000 drempel and per-lidstaat omzet/drempel/benutting (drempels as data, not code) plus the kwartaalopgaaf (Q1–Q4) status. All five are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-kor-kleine-ondernemersregeling.json`); the only PHP is the read-only `KorThresholdCalculator` (pure fiscal arithmetic) + `KorMonitorService` (live AR-ledger reads) + `KorController` (GET /api/kor/monitor). The pre-existing lightweight `KorRegime` schema (YTD-revenue threshold, from add-shillinq-bookkeeping-operations) is retained and complementary. Cross-app: AR renders the no-btw KOR-factuur with the artikel 25-vermelding, AP zero-forces voorbelasting-aftrek during ACTIEF, VAT-filing marks aangiftes "niet van toepassing", all via kor.registration.activated / .revoked events._
**Primary spec:** bookkeeping-kor-kleine-ondernemersregeling

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| KORRegistration | schema:DefinedTerm | ondernemingId, regime (KOR_NL/KOR_EU), status (draft→ACTIEF→GEEINDIGD_*), ingangsDatum, lockInEindDatum, vroegsteOpzegDatum, drempelJaar, administrationId | → administration (N:1), → KORAnnualTurnover/Alert/Revocation (1:N) |
| KORAnnualTurnover | schema:MonetaryAmount | registrationId, jaar, lopendeOmzet, drempel, drempelBenutting, perMaand, uitgeslotenPosten, prognoseEindeJaar, prognoseStatus, administrationId | → KORRegistration (N:1) |
| KORThresholdAlert | schema:Action | registrationId, trigger (DREMPEL_80/90/100PCT), uitgeloostOp, omzetOpMoment, ernst (VROEG/KRITIEK/OVERSCHRIJDING), aanbeveling, kanaal[], administrationId | → KORRegistration (N:1) |
| KORRevocation | schema:Action | registrationId, type (OVERSCHRIJDING/VRIJWILLIG_NA_LOCKOUT), revocatieDatum (=leveringsdatum), triggerFactuurId, btwSuppletieBedrag, herrekeningRange, blokkadeHeraanmelding, administrationId | → KORRegistration (N:1), → ARInvoice (trigger) |
| KOREUTurnover | schema:MonetaryAmount | registrationId, exNummer, jaar, totaalEUOmzet, drempelEUBrut, perLidstaat, kwartaalopgaafStatus (Q1–Q4), administrationId | → KORRegistration (N:1, regime=KOR_EU) |

### TaxDeadline / TaxPaymentTracking / QuarterlyTaxStatement (Vpb corporate tax)
**Schema.org:** `schema:Event` (TaxDeadline), `schema:MonetaryAmount` (TaxPaymentTracking), `schema:Dataset` (QuarterlyTaxStatement)
_Vennootschapsbelasting (Vpb) corporate-tax administration introduced by the `bookkeeping-vpb-corporate-tax` change (T2). `TaxDeadline` is an operator-authored register tracking a Vpb filing/payment deadline (deadlineDate, deadlineType provisional-payment/final-return/extension-request, fiscalYear, quarter, status pending→submitted→filed→archived) scoped per administration (REQ-VPB-001). `TaxPaymentTracking` records a provisional/final/adjustment payment linked to a GL account by `linkedGLAccount` (REQ-VPB-002); GL is authoritative and the record indexes the payment for deadline tracking — reconciliation matches by account + amount + date (REQ-VPB-008, design D2). `QuarterlyTaxStatement` is a read-only computed schema (revenue, operatingExpenses, nonOperating, specialDeductions, netTaxableIncome, untaggedCount) materialised on demand by `lib/Service/TaxReportService.php` from existing `GLTransaction` + `GLLine` data via the real OpenRegister ObjectService API (find/findAll) — never authored by operators; the `x-openregister-aggregations` block documents the equivalent declarative roll-up (REQ-VPB-003/009, design D3). The existing `GLLine` schema is extended additively (ADR-037 key-union merge, no monolith edit) with a `taxTreatment` tag (normal/deductible/nonDeductible/special; default normal) classifying a posting's tax treatment (REQ-VPB-004, design D4). The three new schemas + the GLLine extension + seed objects ship in the ADR-037 fragment `register.d/bookkeeping-vpb-corporate-tax.json`. Deadline/payment CRUD reuse OpenRegister's generic object API (the shillinq frontend object store already targets `/apps/openregister/api/objects`); the only bespoke PHP is `TaxReportService` + `TaxReportController` (quarterly/annual statement), `TaxPaymentReconciliationService` + `TaxPaymentController` (reconcile), and the daily `TaxNotificationService` + `BackgroundJob/TaxDeadlineReminderJob` deadline reminders (7 + 1 day before, via OCP\Notification\IManager; design D5, REQ-VPB-013). The Vpb pages (deadline/payment index + detail, quarterly report, settings) are declarative manifest-v2 pages in the ADR-037 manifest fragment `src/manifest.d/bookkeeping-vpb-corporate-tax.json`. This module consumes T1 GL data (bookkeeping-general-ledger) and the chart of accounts (bookkeeping-chart-of-accounts) and feeds future docudesk Vpb-aangifte preparation and SBR-XBRL transmission._
**Primary spec:** bookkeeping-vpb-corporate-tax

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| TaxDeadline | schema:Event | administrationId, deadlineDate, deadlineType, fiscalYear, quarter, status, relatedPeriodId | → TaxPaymentTracking (1:N via relatedDeadlineId) |
| TaxPaymentTracking | schema:MonetaryAmount | administrationId, paymentDate, paymentType, amount, currency, status, linkedGLAccount, relatedDeadlineId | → TaxDeadline (N:1), → Account (N:1 via linkedGLAccount) |
| QuarterlyTaxStatement | schema:Dataset | administrationId, fiscalYear, quarter, periodId, revenue, operatingExpenses, nonOperating, specialDeductions, netTaxableIncome, untaggedCount | → GLLine (computed aggregation) |

### Belastingplichtige / VpbAangifte / FiscaleCorrectie / Innovatiebox / Deelneming / FiscaleEenheid / Voorvoegingsverlies / InvesteringsAftrek / VoorlopigeAanslag / DefinitieveAanslag / BezwaarBeroep / VpbTariefcatalogus (Vpb-aangifte BV/NV)
**Schema.org:** `schema:Organization` (Belastingplichtige, FiscaleEenheid), `schema:GovernmentService` (VpbAangifte), `schema:MonetaryAmount` (FiscaleCorrectie, Innovatiebox, Voorvoegingsverlies, InvesteringsAftrek), `schema:OwnershipInfo` (Deelneming), `schema:Invoice` (VoorlopigeAanslag, DefinitieveAanslag), `schema:LegalForceStatus` (BezwaarBeroep), `schema:PriceSpecification` (VpbTariefcatalogus)
_Reguliere vennootschapsbelasting (Vpb) data model for BV/NV introduced by the `bookkeeping-vpb-mkb` change (T3, regulatory + compliance). `Belastingplichtige` is the Vpb-plichtige onderneming (RSIN, KvK, rechtsvorm, boekjaar, eHerkenningsNiveau EH3+, digipoortCertificaat FK to the credential vault — never the secret itself). `VpbAangifte` is the annual return: unique per `(belastingplichtige, belastingjaar)` (REQ-VPB-001), with `commercieleWinst` a FK to a vastgestelde jaarrekening-version (AnnualReport, `bookkeeping-financial-statements`); its lifecycle concept → ingediend → aanslag-ontvangen → bezwaar → beroep → onherroepelijk is gated by `VpbAangifteGuard::canIndienen` (jaarrekening vastgesteld + eHerkenning EH3+ + Digipoort cert + S&O-verklaring on every innovatiebox claim) and `BezwaarTermijnGuard::canBezwaarMaken` (6-weken-termijn). `FiscaleCorrectie` is a line-by-line commercieel→fiscaal adjustment (NTP-classified `code`, `correctieBedrag` computed). `Innovatiebox` (forfaitair/werkelijke-winst, verplichte S&O-verklaring, REQ-VPB-004), `Deelneming` (>=5%-toets + drie cumulatieve deelnemingsvrijstelling-toetsen, REQ-VPB-005), `InvesteringsAftrek` (KIA/EIA/MIA/Vamil met cumulatiecontrole EIA+MIA verboden, REQ-VPB-008) en `FiscaleEenheid` (art. 15 voeging >=95% bezit + gelijke boekjaren + NL, per-dochter loss tracking, REQ-VPB-007) carry the faciliteiten. `Voorvoegingsverlies` tracks per-verliesjaar carryforward with a declarative `regime` (9jr ≤2018 / 6jr 2019-2021 / onbeperkt-50pct ≥2022) and computed `verjaartIn` (REQ-VPB-006). `VoorlopigeAanslag` and `DefinitieveAanslag` (computed `bezwaartermijnEinde` = dagtekening + 6 weken) model the assessments; `BezwaarBeroep` is the dispute state machine (bezwaar → uitspraak-inspecteur → beroep → hoger-beroep → cassatie, REQ-VPB-010). `VpbTariefcatalogus` parameterises the schijftarieven (2026: 19% / 25.8% over €245k), innovatieboxtarief (9%) en faciliteit-percentages per belastingjaar so navorderingen reproducibly herrekenen (REQ-VPB-003, design D5); the graduated-bracket berekening lives in `VpbAangifteGuard::berekenVerschuldigdeVpb`. All twelve are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-vpb-mkb.json`); the only PHP exception-path code is two ADR-031 lifecycle guards — `lib/Lifecycle/VpbAangifteGuard.php` (cross-schema indienings-preconditie, voeging-validatie, schijftarief + voorvoegingsverlies-regime/verjaring berekening) and `lib/Lifecycle/BezwaarTermijnGuard.php` (statutaire bezwaar/beroep-termijnen). No PHP tax-calculation service ships (ADR-022/ADR-031); SBR-XBRL generation + Digipoort transmission are delegated to `bookkeeping-sbr-xbrl-reporting`._
**Primary spec:** bookkeeping-vpb-mkb

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Belastingplichtige | schema:Organization | administrationId, rsin, kvkNummer, rechtsvorm, boekjaarStart/Eind, eHerkenningsNiveau, digipoortCertificaat, status | → Administration (N:1) |
| VpbAangifte | schema:GovernmentService | belastingplichtige, belastingjaar, status, commercieleWinst, fiscaleWinstBelastbaar, verschuldigdeVpb, teBetalen, digipoortReceiptId | → Belastingplichtige (N:1), → FiscaleCorrectie/Innovatiebox/InvesteringsAftrek (1:N), → DefinitieveAanslag (1:1), → AnnualReport (N:1) |
| FiscaleCorrectie | schema:MonetaryAmount | aangifte, code (NTP), commercieelBedrag, fiscaalBedrag, correctieBedrag, toelichting | → VpbAangifte (N:1) |
| Innovatiebox | schema:MonetaryAmount | aangifte, methodType, nexusFactor, innovatieboxWinst, effectiefTarief, soVerklaringReferentie | → VpbAangifte (N:1) |
| Deelneming | schema:OwnershipInfo | belastingplichtige, aandeelhouderschapPercentage, kwalificerendeDeelneming, oogmerk/onderworpenheids/bezittingentoets, deelnemingsvrijstellingVanToepassing | → Belastingplichtige (N:1) |
| FiscaleEenheid | schema:Organization | moedermaatschappij, dochters[], voegingsdatum, bezitPercentage, gelijkeBoekjaren, vestigingNederland, voorvoegingsverliezenPerDochter[] | → Belastingplichtige (N:1) |
| Voorvoegingsverlies | schema:MonetaryAmount | belastingplichtige, verliesjaar, oorspronkelijkBedrag, reedsVerrekend, restant, regime, verjaartIn | → Belastingplichtige (N:1) |
| InvesteringsAftrek | schema:MonetaryAmount | aangifte, type (KIA/EIA/MIA/Vamil), investeringsbedrag, aftrekPercentage, aftrekBedrag, gecombineerdMet[], cumulatieConflict | → VpbAangifte (N:1) |
| VoorlopigeAanslag | schema:Invoice | belastingplichtige, belastingjaar, dagtekening, voorlopigVerschuldigd, betalingsregeling, herzieningsverzoekIngesteld, herzieningsuitslag | → Belastingplichtige (N:1) |
| DefinitieveAanslag | schema:Invoice | aangifte, dagtekening, vastgesteldVerschuldigd, tePunten, bezwaartermijnEinde, bezwaarTermijnVerstreken, status | → VpbAangifte (1:1), → BezwaarBeroep (1:N) |
| BezwaarBeroep | schema:LegalForceStatus | aanslag, type (bezwaar/beroep/hoger-beroep/cassatie), ingediendOp, motivering, uitspraak, termijnEinde, status | → DefinitieveAanslag (N:1) |
| VpbTariefcatalogus | schema:PriceSpecification | belastingjaar, tarief1, tarief2, belastbaarBedragGrens, innovatieboxTarief, innovatieboxForfaitDrempel, facilityPercents | (parameterisation master data) |

### Treasurystatuut / KasgeldLimiet / RenteRisicoNorm / SchatkistbankierenSaldo / Lening / Derivaat / QuartaalrapportageFido / TreasuryParagraaf
**Schema.org:** `schema:Legislation` (Treasurystatuut), `schema:Quantity` (KasgeldLimiet, RenteRisicoNorm, SchatkistbankierenSaldo), `schema:LoanOrCredit` (Lening), `schema:FinancialProduct` (Derivaat), `schema:Report` (QuartaalrapportageFido), `schema:Comment` (TreasuryParagraaf)
_Wet Fido (Wet Financiering Decentrale Overheden) & Treasurystatuut compliance data model introduced by the `bookkeeping-wet-fido-treasury` change (T3, regulatory). `Treasurystatuut` is the versioned local treasury risk policy adopted per raad/staten/AB besluit: signing-mandate matrix (role × amount × instrument), permitted instruments, counterparty allowlist, risk appetite and reporting cadence; lifecycle draft → approved → adopted → superseded, with exactly one adopted version per organisation (REQ-FDO-001). `KasgeldLimiet` and `RenteRisicoNorm` are read-model limit records computed declaratively (`x-openregister-aggregations`): the kasgeldlimiet rolling 3-month average of net short-term debt against the per-organisation-type ceiling (8.5% gemeente, 7% provincie, 23% waterschap; REQ-FDO-002), and the rente-risiconorm 4-year forward herfinanciering + rate-reset projection against the 20% norm (REQ-FDO-003). `SchatkistbankierenSaldo` tracks the daily cash position and the automated sweep to the Agentschap above the drempelbedrag (max(0.75% begroting, €1M), capped €1bn; REQ-FDO-005). `Lening` records short- and long-term loans (kasgeld / rekening-courant / onderhandse-lening / obligatie / MTN / EMTN) with instrument, principal, rate, maturity, signing-mandate role and an optional override-rationale; lifecycle draft → recorded / recorded-with-override → locked. `Derivaat` records interest-rate swaps / caps / floors / collars restricted to RUDDO hedging-only: justification, hedge-link, notional ≤ hedged exposure and counterparty rating ≥ single-A; lifecycle draft → recorded → matured (REQ-FDO-004). `QuartaalrapportageFido` is the verplichte kwartaalrapportage auto-generated within 10 working days of quarter-end with limiet snapshots, mutation summaries and override log, dual-signed by treasurer + concerncontroller and transmitted to the toezichthouder with a digital receipt; lifecycle draft → signed → submitted → archived (REQ-FDO-006). `TreasuryParagraaf` is the jaarrekening treasury narrative & projection per BBV Article 13 (REQ-FDO-007). All eight are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-wet-fido-treasury.json`); the only PHP exception-path code is `lib/Lifecycle/FidoTreasuryGuard.php` (ADR-031) — a Lening may only be recorded inside the adopted signing-mandate matrix and, on a flagged limiet-breach, only with an override-rationale; a Derivaat may only be recorded once RUDDO hedging-only validation passes; a QuartaalrapportageFido may only be signed/submitted with both sign-offs present._
**Primary spec:** bookkeeping-wet-fido-treasury

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Treasurystatuut | schema:Legislation | version, organisationType, adoptionDecision, effectiveFrom/To, riskAppetite, signingMandates[], permittedInstruments[], counterpartyAllowlist[], status | → organisation (N:1) |
| KasgeldLimiet | schema:Quantity | auditYear, baseBegroting, percentage, calculatedCeiling, currentExposure, headroom, status | → organisation (N:1) |
| RenteRisicoNorm | schema:Quantity | auditYear, baseVasteSchuld, percentage, calculatedCeiling, forwardLooking4Year, headroomPerYear, status | → organisation (N:1) |
| SchatkistbankierenSaldo | schema:Quantity | drempelbedrag, currentRekeningCourant, daysAboveDrempel, parkedAtSchatkist, lastSweepAt, lastSweepStatus | → organisation (N:1) |
| Lening | schema:LoanOrCredit | counterparty, type, principal, rateType, maturityDate, signingMandateRole, treasurystatuutId, limietBreach, overrideRationale, status | → Treasurystatuut (N:1) |
| Derivaat | schema:FinancialProduct | type, notional, hedgedExposureId, hedgedExposureAmount, RUDDOJustification, counterpartyRating, status | → Lening (N:1, hedge-link) |
| QuartaalrapportageFido | schema:Report | auditYear, kwartaal, kasgeldStatus, renteRisicoStatus, schatkistStatus, overridesApplied[], signOffTreasurer, signOffConcerncontroller, submissionReceipt, status | → organisation (N:1) |
| TreasuryParagraaf | schema:Comment | auditYear, begrotingVersion, narrativeAuto, narrativeManual, kasgeldProjectie, renteRisicoProjectie, liquiditeitsplanning, status | → AnnualReport (N:1) |

### FiscalPeriod
**Schema.org:** `schema:Duration`
_A monthly/quarterly accounting period with an `open → closing → closed → audit-locked` lifecycle. Promotes T1's `GLLine.periodId` stub-string to a real OpenRegister register; postings against a closed period are rejected by the `PeriodCloseGuard` lifecycle precondition. `audit-locked` is terminal. Year-end close (opening-balance journal generation, retained-earnings rollover) is deferred to T3._

### PeriodClose
**Schema.org:** `schema:Event`
_Accounting period with a guided-close lifecycle and audit trail, introduced by the `bookkeeping-period-close` change (T2). Carries the `open → closing → closed → audit-locked` lifecycle (ADR-031 / `register.d/bookkeeping-period-close.json`): `closed` is reversible by a `period-closer` with an audit-trailed close reason (appended to `reopenedHistory`), `audit-locked` is irreversible (auditor only). The change additively augments T1's `GLTransaction.post` transition with a declarative precondition (`OCA\Shillinq\Lifecycle\PeriodCloseGuard::periodOpen`) that rejects any posting whose `periodId` resolves to a `closed`/`audit-locked` PeriodClose — backdating prevention matching Exact/AFAS/Twinfield. The AI close assistant (`OCA\Shillinq\Service\PeriodCloseAssistantService`) detects open AP/AR (draft GLTransactions with `subLedgerType` ap/ar), unreconciled bank statements, and pending expense claims, surfacing them as non-blocking warning flags. Distinct from `FiscalYear` (the year master) and `BudgetPeriod`: PeriodClose tracks the close workflow state of a single month/quarter. Year-end close (opening-balance journal, retained-earnings rollover) is deferred to T3._
**Primary spec:** bookkeeping-period-close

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodId | string | Yes | Unique period code (e.g. 2026-01, 2026-Q1) |
| name | string | Yes | Human-readable label (e.g. Januari 2026) |
| startDate | date | Yes | First day of the period |
| endDate | date | Yes | Last day of the period |
| fiscalYear | string | Yes | Fiscal year this period belongs to |
| administrationId | string | Yes | FK to the owning Administration |
| state | enum | Yes | One of open, closing, closed, audit-locked |
| closedAt | datetime | No | Timestamp when state transitioned to closed |
| closedBy | string | No | UUID of the user who closed the period |
| auditLockedAt | datetime | No | Timestamp when state transitioned to audit-locked |
| auditLockedBy | string | No | UUID of the auditor who locked the period |
| closeReason | string | No | Operator-provided reason for closing |
| reopenedHistory | array | No | Array of {reopenedAt, reopenedBy, reason} tracking each reopen |

### BankStatementLine
**Schema.org:** `schema:MonetaryAmount`
_A single transaction line within a `BankStatement`, parsed from CAMT.053, MT940, or manual CSV import by the `StatementParser`. Matched against AR/AP via `MatchingRule` predicates; unmatched lines route to a designated suspense account._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineId | string | Yes | Unique line identifier within the statement |
| statementId | string | Yes | FK to BankStatement.statementId |
| valueDate | date | Yes | Value date (boekingsdatum) of the transaction |
| transactionDate | date | No | Transaction date if different from value date |
| amount | number | Yes | Transaction amount (positive = credit, negative = debit, EUR) |
| currency | string | Yes | ISO 4217 currency code |
| remittanceInfo | string | No | Payment reference / omschrijving |
| counterpartyName | string | No | Name of counterparty |
| counterpartyIban | string | No | IBAN of counterparty |
| endToEndRef | string | No | SEPA end-to-end reference |
| status | enum | Yes | One of unmatched, matched, routed-to-suspense |
| reconciliationMatchId | string | No | FK to ReconciliationMatch.matchId once matched |

### MatchingRule
**Schema.org:** `schema:Action`
_A predicate-based rule that matches bank statement lines against AR/AP invoices or journals. Predicates are declared as schema metadata (ADR-031); an `x-openregister-aggregations` query consumes them to emit `ReconciliationMatch` candidates — no PHP rule-engine._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique rule identifier |
| name | string | Yes | Human-readable rule name (e.g. Exacte factuurreferentie) |
| administrationId | string | Yes | FK to the owning Administration |
| priority | integer | Yes | Lower number = higher priority (evaluated in order) |
| isActive | boolean | Yes | Whether the rule is active |
| predicates | array | Yes | Array of {field, operator, value, matchTarget} predicate objects |

### ReconciliationMatch
**Schema.org:** `schema:Action`
_A candidate or confirmed match between a bank statement line and an AR/AP invoice, journal, or suspense routing. Emitted by the matching aggregation (`confidence=auto`) or operator-created (`confidence=manual`); the operator confirms or rejects._
**Primary spec:** bookkeeping-bank-reconciliation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| matchId | string | Yes | Unique match identifier |
| bankStatementLineId | string | Yes | FK to BankStatementLine.lineId |
| matchType | enum | Yes | One of ar-invoice, ap-invoice, journal, suspense |
| matchedObjectId | string | Yes | UUID of the matched AR/AP invoice or journal |
| matchedAmount | number | Yes | Amount matched |
| confidence | enum | Yes | One of auto (rule-matched), manual (operator-created) |
| status | enum | Yes | One of pending, confirmed, rejected |
| confirmedBy | string | No | UUID of confirming operator |
| confirmedAt | datetime | No | Confirmation timestamp |

> **T2 reconciliation note (`add-shillinq-bookkeeping-compliance`):** The pre-existing
> `APTransaction`, `DunningNotice`, and `Payee` entries above are legacy/early-draft
> shapes. The canonical T2 accounts-payable, dunning, and reconciliation models are
> `VendorMaster`/`APInvoice`/`PaymentRun` (already shipped), `ARInvoice`/`DunningRecord`,
> and `BankStatement`/`BankStatementLine`/`MatchingRule`/`ReconciliationMatch`
> respectively. `BankStatement` is a single register: its bank-feed fields
> (`bankConnectionId`, `statementFormat`, `statementDate`) and the reconciliation
> fields (`statementId`, `openingBalance`, `closingBalance`, `importFormat`,
> `fileChecksum`, lifecycle) coexist via the ADR-037 register-fragment merge.

### EMUReport
**Schema.org:** `schema:GovernmentService`
_Ingediende of in-progress EMU-aangifte voor een periode (kwartaal of jaar) per REQ-EMU-001. Atomic versioned submission unit carrying the computed EMU-saldo, bruto EMU-schuld ultimo, BBV-aansluiting and CBS-bevestiging. Declarative lifecycle concept → ingediend → herzien (per Wet Hof art. 10) with an `EmuSubmissionGuard::requireApproval` gate on the indienen transition (REQ-EMU-006). `emuSaldoAfwijking` and `emuSchuldRuimte` are derived via `x-openregister-calculations`; `bbvTotaleAdjustments` via aggregation over EMUAdjustment (REQ-EMU-009). 10-year retention per Archiefwet 1995 (REQ-EMU-012). Shipped by the `bookkeeping-emu-reporting` change in `lib/Settings/register.d/bookkeeping-emu-reporting.json`._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationId | string | Yes | FK to the rapporterende administration |
| rapporterendeOrganisatie | object | No | RSIN, gemeentecode, naam, soort (gemeente/provincie/waterschap/GR) |
| periodeJaar | integer | Yes | Calendar year of the period |
| periodeKwartaal | integer | No | Quarter 1-4; required for periodeType=kwartaal-emu-saldo |
| periodeType | enum | Yes | kwartaal-emu-saldo / jaar-emu-saldo / jaar-emu-schuld |
| status | enum | Yes | concept / ingediend / herzien (lifecycle) |
| emuSaldoBerekend | number | No | Computed EMU cash saldo (EUR; negative = tekort) |
| emuSaldoBegroot | number | No | Budgeted EMU-saldo from the meerjarenraming |
| emuSaldoAfwijking | number | No | berekend − begroot (derived) |
| emuSaldoAfwijkingPercentage | number | No | (afwijking / abs(begroot)) × 100 (derived) |
| emuSchuldBruto | number | No | Bruto nominale EMU-schuld ultimo (AF.2+AF.3+AF.4) |
| emuSchuldWettelijkeNorm | number | No | Individuele EMU-referentiewaarde |
| emuSchuldRuimte | number | No | wettelijkeNorm − bruto (derived) |
| bbvSaldoBatenLasten | number | No | BBV jaarrekening saldo baten/lasten |
| bbvTotaleAdjustments | number | No | Sum of EMUAdjustment.bedrag (derived) |
| bbvAansluitingscontrole | enum | No | geslaagd / mislukt / niet-uitgevoerd |
| cbsBevestigingsnummer | string | No | CBS confirmation reference on indiening |
| toelichting | string | No | Concerncontroller toelichting, auto-seeded with top-3 contributors |

**Relations:** → EMUAdjustment / CashFlowItem / DebtPosition (one-to-many via reportId)

### EMUAdjustment
**Schema.org:** `schema:MonetaryAmount`
_Individuele accrual→kas correctie gekoppeld aan een grootboekmutatie of macroregel per Wet Hof art. 3 (REQ-EMU-002). The eight `type` values map to the Wet Hof art. 3 macro-rules; `richting` carries the sign. `EmuReportingService::classifyAdjustment` derives the type from the GL account prefix (ADR-031 exception). Concerncontroller may override `bedrag` (audit-trail logged via `overridden`)._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to EMUReport |
| type | enum | Yes | eliminatie-afschrijving / eliminatie-voorzieningdotatie / eliminatie-onttrekking-reserve / toevoeging-bruto-investering / toevoeging-aflossing / eliminatie-boekwinst-desinvestering / correctie-transactiemoment / intercompany-eliminatie |
| richting | enum | Yes | saldo-verhogend / saldo-verlagend / saldo-neutraal |
| bedrag | number | Yes | Adjustment amount (EUR, non-negative; richting carries the sign) |
| bron | object | No | grootboekrekening, omschrijving, taakveld, taakveldNaam, programma |
| regel | string | Yes | Legal basis citation (e.g. Wet Hof art. 3 lid 2) |
| toelichting | string | No | Free-text business context |
| overridden | boolean | No | True when a concerncontroller overrode the auto-computed bedrag |
| consolidatieEMU | enum | No | extern / intern-S1313 / internal-entity (REQ-EMU-005) |

**Relations:** → EMUReport (many-to-one via reportId)

### CashFlowItem
**Schema.org:** `schema:MonetaryAmount`
_Kasstroomregel geclassificeerd naar IV3 (hoofdstuk-functie-categorie) per REQ-EMU-010. Shared classified dataset feeding both the IV3-kwartaalaangifte and the EMU-aangifte; EMU filters on kas-basis (kasOfTransactiebasis=kas). A differing factuurmoment vs betaalmoment drives a `correctie-transactiemoment` adjustment (REQ-EMU-002)._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to EMUReport |
| datum | date | Yes | Transaction (kas) date |
| bedrag | number | Yes | Cash amount (EUR; negative = uitstroom) |
| iv3 | object | No | hoofdstuk, hoofdstukNaam, functie, functieNaam, categorie, categorieNaam |
| taakveld | string | No | BBV taakveld code |
| tegenrekening | object | No | soort, naam, nummer (factuurnummer/IBAN) |
| kasOfTransactiebasis | enum | Yes | kas / transactie |
| betaalmoment | datetime | No | Actual cash transaction timestamp |
| factuurmoment | datetime | No | Invoice date when different from cash date |

**Relations:** → EMUReport (many-to-one via reportId)

### DebtPosition
**Schema.org:** `schema:MonetaryAmount`
_Uitstaande schuld per instrument per peildatum per REQ-EMU-004. Bruto nominaal bedrag classified per Eurostat ESA2010 (AF.2/AF.3/AF.4 tellen mee; AF.7 derivaten niet — `teltMeeInEmuSchuld`). `EmuReportingService::computeBrutoSchuld` sums the qualifying categories. Can be sourced from the schatkistbankieren daily sync (REQ-EMU-011) or entered manually._
**Primary spec:** bookkeeping-emu-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to EMUReport |
| peildatum | date | Yes | Measurement date (kwartaal- of jaar-ultimo) |
| instrument | enum | Yes | vaste-geldlening / obligatie / kasgeldlening / schatkistbankieren-rekeningcourant / crediteurensaldo-1j+ / derivaten-passief / voorziening-juridisch |
| tegenpartij | object | No | naam, soort, consolidatieEMU |
| hoofdsomOorspronkelijk | number | No | Original principal (EUR) |
| uitstaandeSchuld | number | Yes | Outstanding nominal balance (EUR) |
| rentevoet | number | No | Annual interest rate (%) |
| rentevorm | enum | No | vast / variabel |
| looptijdJaren | integer | No | Original term in years |
| einddatum | date | No | Maturity date |
| teltMeeInEmuSchuld | boolean | Yes | AF.2/AF.3/AF.4 = true, AF.7 = false |
| categorieEurostat | enum | Yes | AF.2-deposits / AF.3-securities / AF.4-loans / AF.7-derivatives / overig |

**Relations:** → EMUReport (many-to-one via reportId)

## Innovatiebox Administratie — five-register model (bookkeeping-innovatiebox-administratie, 2026-06-05)

> **Annotation (bookkeeping-innovatiebox-administratie, 2026-06-05):** This change **supersedes** the earlier `InnovatieboxElection` / `IPAssetValuation` / `WinstToerekening` / `InnovatieboxTariff` model annotated above (add-shillinq-innovatiebox-administratie, 2026-06-01/03). Those schemas were retired from `shillinq_register.json`; the orphaned manifest pages, docudesk template and tariff seed are reconciled to the new model in this change. The innovatiebox administratieve keten is now five interconnected registers declared as an ADR-037 fragment (`lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json`). Per REQ-IBA-010 the statutory tariff `0.09` is **hard-coded** (in the schema defaults and the calculation services), not seed-configurable — the `InnovatieboxTariff` seed register is removed.

### QualifyingAsset

_Kwalificerend immaterieel activum (IP asset) with a validated `toegangsticket` per Wet Vpb art. 12ba (REQ-IBA-001). Three routes: octrooi, S&O-verklaring, and combinatie (both). `QualifyingAssetValidator` derives `status` (valid | invalid_access_ticket | awaiting_renewal | expired); only `status: valid` assets enter the innovatiebox aggregations. The root anchor for the other four registers (FK `qualifying_asset_id`)._
**Primary spec:** bookkeeping-innovatiebox-administratie

- → NexusCalculation (one-to-many, via qualifying_asset_id)
- → IBProfitAttribution (one-to-one per boekjaar, via qualifying_asset_id)
- → IBExpenseAllocation (one-to-many, via qualifying_asset_id)
- → CarryForwardLoss (one-to-many, via qualifying_asset_id)

### NexusCalculation

_OECD BEPS Action 5 modified-nexus calculation per asset per boekjaar (REQ-IBA-002, Wet Vpb art. 12bc): `nexusbreuk = min(1; 1.3 × (eigen + uitbesteed_derden) / totaal)`. Related-party R&D (`rd_kosten_uitbesteed_verbonden`) only enlarges the noemer. `NexusCalculationService` computes the breakdown; the record is immutable after creation (`x-openregister.immutable`)._
**Primary spec:** bookkeeping-innovatiebox-administratie

- → QualifyingAsset (many-to-one, via qualifying_asset_id)

### IBProfitAttribution

_Per-asset winsttoerekening per boekjaar using one of three methods (REQ-IBA-003, Wet Vpb art. 12bd): `per_asset_afpelmethode`, `forfaitair_25pct` (25% capped at EUR 25 000, no nexus), `cost_plus`. Exactly one record per `(qualifying_asset_id, boekjaar)`. `ProfitAttributionService` computes the qualifying profit and Vpb impact; the `innovatieboxAdministratie` aggregation rolls the per-asset rows up to Vpb-aangifte regel 23. `vso_locked` makes the record read-only once the year is signed off in a VSO (REQ-IBA-008)._
**Primary spec:** bookkeeping-innovatiebox-administratie

- → QualifyingAsset (many-to-one, via qualifying_asset_id)
- → NexusCalculation (many-to-one, via nexus_calculation_id)

### IBExpenseAllocation

_Kostentoerekening per asset per periode with the doorsnijdingsverbod flag `exclusief_in_winstbepaling` (REQ-IBA-004, Wet Vpb art. 12bd lid 2). When true, the same `(grootboekrekening, kostenplaats)` pair MUST NOT appear in the regular GL deduction feed; `DoorsnijdingsVerbodValidator` cross-checks both feeds and blocks the year-end close on a duplicate._
**Primary spec:** bookkeeping-innovatiebox-administratie

- → QualifyingAsset (many-to-one, via qualifying_asset_id)

### CarryForwardLoss

_Asset-specific voortwenteling verlies per Wet Vpb art. 12be (REQ-IBA-005, REQ-IBA-007): a loss from asset A can only offset future profit on asset A. `CarryForwardLossService` recovers the open loss FIRST at the full statutory tariff (not nexus-reduced), then taxes the residual at 0.09 × nexus. Immutable after creation; the `verrekend_boekjaar` array records each offset application._
**Primary spec:** bookkeeping-innovatiebox-administratie

- → QualifyingAsset (many-to-one, via qualifying_asset_id)

### Contract (IFRS 15) / PerformanceObligation / TransactionPrice / PriceAllocation / RevenueRecognitionEvent / ContractAsset / ContractLiability / ContractModification (IFRS 15) / VariableConsiderationAdjustment / ContractCostAsset / RevenueWaterfall
**Schema.org:** `schema:CreativeWork` (Contract), `schema:Service` (PerformanceObligation), `schema:PriceSpecification` (TransactionPrice), `schema:MonetaryAmount` (PriceAllocation, ContractAsset, ContractLiability, VariableConsiderationAdjustment, ContractCostAsset, RevenueWaterfall), `schema:Event` (RevenueRecognitionEvent), `schema:UpdateAction` (ContractModification)
_IFRS 15 / ASC 606 five-step revenue-recognition data model introduced by the `bookkeeping-ifrs15-revenue` change (T2, compliance + operations). Implements the five steps as first-class registers: (1) `Contract` identifies the customer contract (note: this is the revenue contract under IFRS 15 — distinct from the procurement `Contract`/`ContractModification` of the not-yet-shipped `contract-lifecycle-management` spec; the IFRS 15 names are the canonical register-declared schemas today); (2) `PerformanceObligation` is each distinct promised good/service with its satisfaction pattern (point-in-time | over-time) and output/input method (cost-to-cost sources cost from the project-accounting module); (3) `TransactionPrice` decomposes the price into fixed + variable (with the IFRS 15.56 constraint) + financing + non-cash − payable-to-customer; (4) `PriceAllocation` stores the per-PO relative-SSP (or residual) allocation; (5) `RevenueRecognitionEvent` records satisfaction and materialises a balanced GL posting (debit accrued-revenue / credit revenue). `ContractAsset` and `ContractLiability` are read-only, derived nightly per IFRS 15.116-119 (asset = recognised > billed; liability = billed > recognised). `ContractModification` records amendments classified per IFRS 15.18-21 (new-contract | not-distinct-cumulative | prospective). `VariableConsiderationAdjustment` logs periodic re-estimation of variable consideration with the constraint reason and the compensating GL delta. `ContractCostAsset` capitalises costs to obtain/fulfil per IFRS 15.91-104 and tracks amortisation + impairment. `RevenueWaterfall` is the read-only per-contract, per-period time-series of allocated price → recognised → remaining (IFRS 15.120 RPO, 60+ months), aggregatable by customer/segment and by `contractGroupId` for combination-of-contracts (IFRS 15.17). All eleven are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-ifrs15-revenue.json`) with `x-openregister-lifecycle` on Contract, `x-openregister-materialisations` on RevenueRecognitionEvent, and `x-openregister-aggregations` on RevenueWaterfall. Per ADR-031 the only PHP exception-path code is `lib/Service/RevenueCutoffService.php` + `RevenueRecognitionCalculator.php` (the cross-schema recognised-vs-billed reconciliation, relative-SSP allocation, cost-to-cost % complete, and asset/liability split the declarative aggregation engine cannot yet express) reachable via the read-only `GET /api/revenue-cutoff` endpoint. The module depends on T1 `bookkeeping-general-ledger` (deferred/accrued control accounts), T2 `bookkeeping-quote-order-invoice` (contract originates from the sales order), and T2 `bookkeeping-consultancy-project-accounting` (input-method cost sourcing); it enables T4 segment reporting + consolidated IFRS 15.110-129 disclosure._
**Primary spec:** bookkeeping-ifrs15-revenue

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Contract | schema:CreativeWork | contractNumber, customerId, signedAt, startDate/endDate, fixedConsideration, currency, contractGroupId, lifecycleState, administrationId | → PerformanceObligation (1:N), → TransactionPrice (1:N), → ContractModification (1:N), → customer contact (N:1) |
| PerformanceObligation | schema:Service | contractId, description, satisfactionPattern, output/inputMethod, sspAmount, allocatedPrice, percentageComplete, statusAtPeriodEnd | → Contract (N:1), → PriceAllocation (1:1), → RevenueRecognitionEvent (1:N) |
| TransactionPrice | schema:PriceSpecification | contractId, fixedConsideration, variableConsideration, constraintAmount/Reason, significantFinancingComponent, effectiveDate | → Contract (N:1) |
| PriceAllocation | schema:MonetaryAmount | contractId, poId, allocatedAmount, allocationMethod, effectiveDate | → Contract (N:1), → PerformanceObligation (N:1) |
| RevenueRecognitionEvent | schema:Event | poId, contractId, periodStart/End, recognisedAmount, basisDescription, evidenceReference, glTransactionId | → PerformanceObligation (N:1), → GLTransaction (1:1 materialised) |
| ContractAsset | schema:MonetaryAmount | contractId, periodStart/End, assetAmount, currentPeriodMovement, accrualAmount (read-only, derived nightly) | → Contract (N:1), → GLTransaction (accrued-revenue) |
| ContractLiability | schema:MonetaryAmount | contractId, periodStart/End, liabilityAmount, currentPeriodMovement, deferredRevenueAmount (read-only, derived nightly) | → Contract (N:1), → GLTransaction (deferred-revenue) |
| ContractModification | schema:UpdateAction | parentContractId, modificationDate, modificationType, classificationSource/Reason, newTransactionPrice, status, before/afterSnapshot, newContractId | → Contract (N:1), → Person (N:1) |
| VariableConsiderationAdjustment | schema:MonetaryAmount | contractId, adjustmentDate, priorEstimate, newEstimate, constraintReason, deltaAmount, glTransactionId, operatorId | → Contract (N:1), → GLTransaction (1:1), → Person (N:1) |
| ContractCostAsset | schema:MonetaryAmount | contractId, costType, initialCapitalisation, amortisationSchedule, poSatisfactionPattern, amortisedToDate, carriedAmount, impairmentTestDate | → Contract (N:1), → PerformanceObligation (N:1) |
| RevenueWaterfall | schema:MonetaryAmount | contractId, contractGroupId, segment*, periodStart/End, transactionPriceAllocated, cumulativeRecognised, remainingAmount, remainingMonths, deferredLiability, accrualAsset (read-only) | → Contract (N:1) |

### Appointment
**Schema.org:** `schema:Reservation`
_A bookable appointment between a customer (a Nextcloud contact — never an app-local person schema) and a service provider. Self-booked appointments start in `pending_confirmation` and must be confirmed via a ConfirmationToken before `confirmationDeadline`, after which the CancelUnconfirmedAppointments job auto-cancels them. Admin-created appointments start `confirmed` and skip the confirmation flow (REQ-BCF-010). Declared in the `register.d/bookings-confirm-flow.json` fragment per ADR-037._
**Primary spec:** bookings-confirm-flow

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| appointmentNumber | string | Yes | Human-readable unique reference |
| contactId | string | No | Customer as a Nextcloud contact (addressbook-synced) |
| customerEmail | string | No | Email for confirmation delivery |
| customerTimezone | string | No | IANA timezone for ICS TZID/VTIMEZONE + email local time |
| serviceName | string | Yes | Service name (ICS SUMMARY) |
| providerName | string | No | Provider name (ICS ORGANIZER) |
| location | string | No | Location (ICS LOCATION) |
| notes | string | No | Notes (ICS DESCRIPTION) |
| startTime | datetime | Yes | Start (ISO 8601 UTC) |
| endTime | datetime | Yes | End (ISO 8601 UTC) |
| status | enum | Yes | pending_confirmation, confirmed, completed, cancelled |
| confirmationDeadline | datetime | No | CHANGED: latest confirm time before auto-cancel (REQ-BCF-005) |
| confirmedAt | datetime | No | CHANGED: timestamp moved to confirmed (REQ-BCF-004) |
| confirmationTokenId | string | No | CHANGED: current valid ConfirmationToken reference |
| cancelledReason | string | No | Reason recorded on cancellation |
| administrationId | string | Yes | Tenant administration scope (IDOR-safety) |

**Lifecycle (`x-openregister-lifecycle`, field `status`):** pending_confirmation → confirmed (token-guarded) | pending_confirmation → cancelled (deadline) | confirmed → completed | confirmed → cancelled.

**Relations:**
- → ConfirmationToken (one-to-many across resends, via confirmationTokenId / ConfirmationToken.appointmentId)

### ConfirmationToken
**Schema.org:** `schema:Token`
_A single-use, time-limited token authorising a customer to confirm a pending Appointment via an email link or web portal (REQ-BCF-001/002). The raw 32-char URL-safe token is delivered once and never persisted — only its bcrypt hash is stored, and `tokenString` is masked on API reads. Resends revoke the prior token and issue a fresh one (REQ-BCF-006). Declared in the `register.d/bookings-confirm-flow.json` fragment per ADR-037._
**Primary spec:** bookings-confirm-flow

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| tokenId | string | No | Unique token identifier |
| appointmentId | string | Yes | FK to the Appointment being confirmed |
| tokenString | string | Yes | Bcrypt hash of the raw token (masked on reads) |
| expiresAt | datetime | Yes | ISO 8601 UTC expiry (typically +7 days) |
| status | enum | Yes | active, redeemed, expired, revoked |
| redeemedAt | datetime | No | Timestamp the token was redeemed |
| createdAt | datetime | Yes (auto) | Creation timestamp |
| createdBy | string | No | Triggering user ('system' for auto-generated) |
| administrationId | string | No | Tenant administration scope (IDOR-safety) |

**Lifecycle (`x-openregister-lifecycle`, field `status`):** active → redeemed (confirm) | active → revoked (resend) | active → expired (expiry).

**Relations:**
- → Appointment (many-to-one, via appointmentId)

### ViesValidation / IcpSupply / IcpOpgaaf / PeriodicitySwitch
**Schema.org:** `schema:VerificationEvent` (ViesValidation), `schema:Transaction` (IcpSupply), `schema:Report` (IcpOpgaaf), `schema:Event` (PeriodicitySwitch)
_Intra-community supplies (ICP) filing data model introduced by the `bookkeeping-icp-opgaaf` change (T3). Dutch VAT law (Articles 262-271 Directive 2006/112/EC, art. 37a Wet OB 1968) requires a separate quarterly (monthly above EUR 50,000 goods) recapitulative statement of supplies to VAT-registered EU buyers. `ViesValidation` is the **immutable** record of one VIES VAT-ID verification — it carries the VIES `requestId` as Belastingdienst audit proof (Implementing Regulation 282/2011 art. 18, the good-faith defence) and an `outage` flag distinguishing a transient VIES outage from a definitive rejection (REQ-ICP-009). `IcpSupply` is the first-class, invoice-derived supply record (one per supply, with supplyType L goods / S services / T triangulation, negative amounts for credit notes, REQ-ICP-003/006). `IcpOpgaaf` is the line-bearing recapitulative statement with a draft → finalized → submitted → accepted/rejected/corrected lifecycle; finalize is gated by a reconciliation check against the BTW-aangifte rubriek 3b within EUR 1 tolerance (REQ-ICP-004) and SBR/NT18 schema validation (REQ-ICP-005), submit consumes the OpenRegister approval-workflow (ADR-022). `PeriodicitySwitch` logs each quarterly↔monthly transition at the EUR 50,000 goods threshold (REQ-ICP-002). All four are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-icp-opgaaf.json`); the only PHP exception-path code is `lib/Lifecycle/IcpFinalizeGuard.php` (cross-schema reconciliation join, ADR-031) plus the read-only `IcpService` / `IcpCalculator` engine-side fallback for the period-window selection, aggregation, periodicity threshold and SBR-XBRL composition the declarative engine cannot yet express. The richer line-bearing `IcpOpgaaf` is the companion of the pre-existing minimal `IcpStatement` (period metadata only, from `add-shillinq-bookkeeping-operations`); the latter is left untouched. The planned `ARInvoice.icpContext` (treatAsIcp / supplyType / viesValidationId) and `CounterpartyMaster.vatId*` (vatId, vatIdValidatedAt, vatIdValidUntil, vatIdValidationStatus) extension fields are additive overlays that land when bookkeeping-accounts-receivable-core declares those register schemas (deferred — this app has no Invoice/Counterparty register schema yet). Zero-rated RGS accounts 8190 (Omzet ICP goederen, L), 8195 (Omzet ICP diensten, S) and 8196 (Omzet ICP driehoekstransacties, T) are seeded by the fragment; supplyType routes L→8190, S→8195, T→8196._
**Primary spec:** bookkeeping-icp-opgaaf

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| ViesValidation | schema:VerificationEvent | vatId, validationTimestamp, validUntil, valid, requestId, outage, administrationId | ← IcpSupply.viesValidationId (1:N), immutable evidence |
| IcpSupply | schema:Transaction | invoiceId, supplyDate, buyerVatId, buyerCountry, supplyType (L/S/T), amountExclVat, viesValidationId, reportedInOpgaafId, administrationId | → ViesValidation (N:1), → IcpOpgaaf (N:1 via reportedInOpgaafId), → ARInvoice (N:1, deferred) |
| IcpOpgaaf | schema:Report | period, periodicity (quarterly/monthly), status, type (initial/correction), correctsPeriod, lines[], total/totalGoods/totalServices/totalTriangulation, belastingdienstKenmerk, xmlPayload, auditBundle, administrationId | ← IcpSupply (1:N), reconciles ↔ VatReturn.rubriek3b (REQ-ICP-004) |
| PeriodicitySwitch | schema:Event | administrationId, switchFrom/switchTo, triggerDate, triggerAmount, triggerQuarter, effectiveDate, status (active/reversed) | → Administration (N:1) |

### Programmabegroting / Programma / Taakveld / Indicator / Investering / Reserve / Voorziening / Paragraaf / Meerjarenraming / Begrotingswijziging
**Schema.org:** `schema:Plan` (Programmabegroting, Programma), `schema:DefinedTerm` (Taakveld), `schema:PropertyValue` (Indicator), `schema:MonetaryAmount` (Investering, Reserve, Voorziening, Meerjarenraming), `schema:Report` (Paragraaf), `schema:Action` (Begrotingswijziging)
_BBV programmabegroting (municipal/provincial/waterschap budget code) data model introduced by the `bookkeeping-programmabegroting` change (T2). `Programmabegroting` is the root per administration + begrotingsjaar: it carries the draft → in-behandeling → vastgesteld → superseded lifecycle and the computed sluitendStructureel / sluitendReëel / toezichtRegime flags. The **Taakveld is the canonical brondata** (BBV-mandated indeling, comparable across organisaties); `Programma` (locally-chosen political structure) carries roll-up totals computed from its child Taakvelden — both are parallel views over the same data with no rounding drift (design D1). `Meerjarenraming` holds the 4-year outlook per jaar with the sluitend-criterium; `Paragraaf` holds the seven verplichte BBV-paragrafen (auto-created on draft, narrative required on vaststelling). `Begrotingswijziging` is an event-sourced delta document: a vastgestelde begroting is immutable and the current stand = vastgestelde basis + Σ(vastgestelde wijzigingen). `Investering`/`Reserve`/`Voorziening` round out the BBV art. 44 balansposten. All ten are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-programmabegroting.json`); the computations (sluitend-flags, programma roll-up, kapitaallastenschedule, wijziging delta-stacking, iv3/EMU/JSON exports) are pure integer-cent calculators in `lib/Service` (SluitendCalculator, ProgrammaAggregator, KapitaallastenCalculator, BegrotingswijzigingStacker, ProgrammabegrotingExporter), and the lifecycle preconditions (paragraaf-completeness on behandeling/vaststelling, raadsbesluit on wijziging, GL budget-overrun) are the only ADR-031 exception-path PHP guards (`lib/Lifecycle/ProgrammabegrotingGuard.php`, `BegrotingswijzigingGuard.php`, `BudgetOverrunGuard.php`). This module is distinct from any T1/T2 generic Budget/Program data model: it specifically operationalises the BBV begrotingsproces and consumes the `bookkeeping-bbv-compliance` taakveldcatalogus and `bookkeeping-budget-forecast` projecties._
**Primary spec:** bookkeeping-programmabegroting

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| Programmabegroting | schema:Plan | organisationType, begrotingsjaar, status, sluitendStructureel/Reëel, toezichtRegime | → Programma/Paragraaf/Meerjarenraming/Reserve/Voorziening (1:N), → Begrotingswijziging (1:N) |
| Programma | schema:Plan | begrotingId, nummer, naam, batenTotaal/lastenTotaal (computed), saldoNaMutaties | → Programmabegroting (N:1), → Taakveld/Indicator/Investering (1:N) |
| Taakveld | schema:DefinedTerm | begrotingId, programmaId, taakveldCode (BBV catalogue), baten, lasten | → Programma (N:1) |
| Indicator | schema:PropertyValue | programmaId, code, eenheid, nulwaarde/streefwaarde/realisatie | → Programma (N:1) |
| Investering | schema:MonetaryAmount | programmaId, bruto, dekking, afschrijvingstermijn, kapitaallastenSchedule (computed) | → Programma (N:1) |
| Reserve | schema:MonetaryAmount | begrotingId, type, beginsaldo/toevoegingen/onttrekkingen/eindsaldo | → Programmabegroting (N:1) |
| Voorziening | schema:MonetaryAmount | begrotingId, grondslag (BBV art. 44), beginsaldo/dotaties/vrijval/aanwendingen/eindsaldo | → Programmabegroting (N:1) |
| Paragraaf | schema:Report | begrotingId, type (7 verplichte), narrative, kerncijfers | → Programmabegroting (N:1) |
| Meerjarenraming | schema:MonetaryAmount | begrotingId, jaar, batenStructureel/lastenStructureel, saldoReëel, sluitend | → Programmabegroting (N:1) |
| Begrotingswijziging | schema:Action | begrotingId, wijzigingsnummer, mutaties[] (delta), raadsbesluit, status | → Programmabegroting (N:1) |

| periodId | string | Yes | Unique period identifier within an administration (e.g. "2026-01") |
| administrationId | string | Yes | FK to Administration; scopes reads to the caller's administration |
| startDate | date | Yes | First day of the period |
| endDate | date | Yes | Last day of the period |
| fiscalYear | integer | Yes | Fiscal year the period falls in |
| state | enum | Yes | One of: open, closing, closed, audit-locked |
| closedAt / closedBy | datetime / string | No | Close stamp (set on close, cleared on reopen) |
| auditLockedAt / auditLockedBy | datetime / string | No | Audit-lock stamp (irreversible) |
| closeReason | text | No | Audit-trailed reason captured on reopen |
| reopenedHistory | array | No | Append-only {closedAt, closedBy, reopenedAt, reopenedBy, closeReason} |
| taskChecklistItems | array | No | Pre-close checklist {id, category, description, resolved, resolvedAt, resolvedBy} |
| aiFlags | array | No | Close-assistant warnings {id, severity, message, category, detectedAt} |

**Relations:** → GLTransaction (1:N via periodId), → Administration (N:1), → FiscalYear (N:1 via fiscalYear).

### RetainerPool / RetainerDrawdown / RetainerRollover / RetainerTrueUp
**Schema.org:** `schema:Invoice` (RetainerPool, RetainerTrueUp)
_Retainer billing engine introduced by the `retainer-billing-engine` change (T2). `RetainerPool` is a monthly retainer allocation per (clientId, projectId) with an effective-period window, a configured `retainerRate`, and a pool-level `rolloverPolicy` (carryover cap in amount or hours, or reset). `RetainerDrawdown` is the immutable record of a single TimeEntry's consumption of a pool — `drawdownAmount = hoursOrAmount × the pool's retainerRate` (NOT the timesheet rate), so pool consumption is decoupled from invoice billing. `RetainerRollover` records the unused-balance carryover at period-end after applying the source pool's policy. `RetainerTrueUp` is the period-end reconciliation (actual vs. allocated): it computes overage and converts it to a billing amount at the standard rate resolved from `rate-card-engine` (RateCard/RateRecord), with a role-gated approval (`retainer:approve-true-up`, ADR-023) and optional adjustment-invoice generation. All four are schema-declared register-fragment metadata (ADR-037 fragment `register.d/retainer-billing-engine.json`); `RetainerPool.actualDrawdown` rolls up declaratively via `x-openregister-aggregations` over RetainerDrawdown (ADR-022) — no `RetainerService.php`. The only PHP exception-path code is `lib/Lifecycle/RetainerGuard.php` (ADR-031): non-overlapping-period enforcement on pool activation, drawdown rate-immutability on materialization, and approver-present on true-up approval. clientId / projectId / timeEntryId / invoiceId / administrationId are entity references (a client/time-entry/administration is an NC/cross-app entity, never an invented schema)._
**Primary spec:** retainer-billing-management

| Entity | Schema.org | Key fields | Primary relations |
|--------|-----------|-----------|-------------------|
| RetainerPool | schema:Invoice | poolId, clientId, projectId, periodStart/End, poolAmount, retainerRate, rolloverPolicy, status | → RetainerDrawdown (1:N), → RetainerTrueUp (1:1), → Organization/administration (N:1), self-FK sourcePoolId (rollover chain) |
| RetainerDrawdown | schema:Action | drawdownId, poolId, timeEntryId, drawdownDate, hoursOrAmount, drawdownRate, drawdownAmount, status | → RetainerPool (N:1), → TimeEntry (N:1, cross-app), self-FK reversalOf |
| RetainerRollover | schema:Action | rolloverId, sourcePeriodPoolId, targetPeriodPoolId, carryoverAmount, carryoverHours, carryoverCapApplied, resetBalance, status | → RetainerPool source/target (N:1) |
| RetainerTrueUp | schema:Invoice | trueUpId, poolId, actualDrawdown, poolAmount, overageAmount, overageRate, overageInvoiceAmount, approvedBy, invoiceId, status | → RetainerPool (1:1), → Invoice (1:1, cross-app), → Person approver (N:1), depends on rate-card-engine for overage rate, self-FK reversalOf |
### Resource
**Schema.org:** `schema:Thing`
_A bookable resource — a staff member, a room, a piece of equipment, or furniture. Foundation entity for the booking module; every Calendar is bound to exactly one Resource. Owned by an organization and carries a lifecycle status._
**Primary spec:** bookings-resource-calendar
| type | enum | Yes | One of: staff, room, equipment, furniture, other |
| name | string | Yes | Human-readable resource name (e.g. "Jan Peeters", "Vergaderruimte A") |
| organization | string | Yes | FK to the organization that owns the resource |
| status | enum | Yes | One of: active, inactive, archived (default: active) |
- → Calendar (one-to-many, a resource may have multiple calendars)
- → Booking (one-to-many, via the denormalized resource FK)
- → Organization (many-to-one)
### Calendar
**Schema.org:** `schema:Schedule`
_A per-resource calendar carrying time-zone and working-hours configuration. Bound to exactly one Resource. All booking times are stored in UTC; the timeZone field drives display conversion on the client (UTC+2 during CEST for Europe/Amsterdam)._
**Primary spec:** bookings-resource-calendar
| resource | string | Yes | FK to the Resource this calendar is bound to |
| timeZone | string | Yes | IANA time zone identifier (default: Europe/Amsterdam). Storage is always UTC |
| workingHours | object | No | Optional weekday-keyed template (e.g. {"monday": "09:00-17:00"}); null means 24/7 |
| organization | string | Yes | FK to the organization that owns the calendar |
| status | enum | Yes | One of: active, inactive, archived (default: active) |
- → Resource (many-to-one, via resource)
- → Booking (one-to-many, via calendar)
- → Organization (many-to-one)
### Booking
_A scheduled appointment on a resource's calendar. Times are stored in UTC (ISO 8601). The resource FK is denormalized from the calendar for efficient per-resource conflict queries. Conflict detection prevents double-booking the same resource (overlapping intervals)._
**Primary spec:** bookings-resource-calendar
| calendar | string | Yes | FK to the Calendar this booking belongs to |
| resource | string | Yes | Denormalized FK to the Resource (mirrors Calendar.resource) for conflict queries |
| title | string | Yes | Short booking title (e.g. "Klant: Anna de Wit") |
| startTime | datetime | Yes | Appointment start time in UTC (ISO 8601) |
| endTime | datetime | Yes | Appointment end time in UTC (ISO 8601); must be after startTime |
| attendee | string | Yes | Attendee name or free-text reference |
| status | enum | Yes | One of: pending, confirmed, cancelled (default: pending) |
| externalId | string | No | Optional external calendar event ID for future Tier-3 sync (Google/Outlook) |
- → Calendar (many-to-one, via calendar)
- → Resource (many-to-one, via resource — denormalized per design Decision 4)
- → Organization (many-to-one, via the parent Calendar)
### ConsolidationGroup / GroupEntity / IntercompanyRelation / ConsolidationPeriod / EliminationEntry / TranslationAdjustment / MinorityInterest / Goodwill / ConsolidatedBalance / ConsolidatedIncomeStatement
**Schema.org:** `schema:Organization` (ConsolidationGroup, GroupEntity), `schema:Action` (IntercompanyRelation, ConsolidationPeriod, EliminationEntry), `schema:MonetaryAmount` (TranslationAdjustment, MinorityInterest, Goodwill), `schema:Report` (ConsolidatedBalance, ConsolidatedIncomeStatement)
_Commercial consolidation (RJ 217 / IAS 27 / IFRS 10) data model introduced by the `bookkeeping-consolidation-commercial` change (T3). Enables Dutch MKB holding companies to consolidate multi-entity financial statements per the consolidatieplicht (BW 2:406). `ConsolidationGroup` defines the consolidation circle (reporting framework RJ217/IFRS10, reporting currency, uniform fiscal-year end, default method); `GroupEntity` maps an Administration into the group with its eigendomspercentage and consolidation method (integral/proportional/equity, RJ 217 §4-7); `IntercompanyRelation` is the matching wegwijzer for elimination; `ConsolidationPeriod` runs a measurement (open → eliminationPhase → review → closed → archived) with an exception queue for out-of-tolerance mismatches; `EliminationEntry` carries balanced debit/credit lines + the accountant-review audit trail; `TranslationAdjustment` isolates the current-rate-method CTA (RJ 122 / IAS 21) to OCI; `MinorityInterest` rolls the aandeel-derden balance (RJ 217 §8); `Goodwill` recognises acquisition goodwill/badwill with framework-gated amortisation (RJ 216 / IFRS 3); `ConsolidatedBalance` + `ConsolidatedIncomeStatement` are the read-only outputs with comparative figures and the parent/minority profit split. All ten are schema-declared register-fragment metadata (ADR-037 fragment `register.d/bookkeeping-consolidation-commercial.json`); quantitative logic is declarative (x-openregister-aggregations/-calculations) per ADR-031 — no ConsolidationCalculator/EliminationMatcher service. The status-transition, accountant-review-presence, balance-sheet-equation and net-profit-split preconditions are the only PHP exception-path code — `lib/Lifecycle/ConsolidationGuard.php` per ADR-031. A person (executor/reviewer) is an NC user-directory entity and the existing Administration register is reused for per-entity GL (ADR-022)._
**Primary spec:** bookkeeping-consolidation-commercial
> **Note (bookkeeping-consolidation-commercial, 2026-06-05):** This module's
> `ConsolidationGroup` is the commercial-consolidation register (RJ 217 / IFRS 10
> circle definition with per-entity GroupEntity membership, intercompany
> elimination and goodwill/CTA/minority tracking). It complements — and does not
> supersede — the lighter `ConsolidationGroup`/`ConsolidatedReport` entries above
> (primary spec bookkeeping-financial-statements), which model a read-only
> multi-administratie aggregate. The commercial module's `GroupEntity` carries the
> per-entiteit ownership/method that the financial-statements `administrationIds`
> array does not; consumers needing full RJ 217 eliminations use this module.
| ConsolidationGroup | schema:Organization | groupName, parentAdministrationId, reportingFramework (RJ217/IFRS10), reportingCurrency, fiscalYearEnd, defaultConsolidationMethod, state | → GroupEntity (1:N), → ConsolidationPeriod (1:N) |
| GroupEntity | schema:Organization | consolidationGroupId, administrationId, entityType, ownershipPercentage, consolidationMethod, firstConsolidationDate, functionalCurrency, state | → ConsolidationGroup (N:1), → Administration (N:1) |
| IntercompanyRelation | schema:Action | consolidationGroupId, debtorEntityId, creditorEntityId, transactionType, matchingTolerance | → ConsolidationGroup (N:1), → GroupEntity (N:1 ×2) |
| ConsolidationPeriod | schema:Action | consolidationGroupId, periodStart/End, executor, status, totalEliminationCount/Amount (agg), mismatches[] | → ConsolidationGroup (N:1), → EliminationEntry (1:N) |
| EliminationEntry | schema:Action | consolidationPeriodId, eliminationType, lines[] (debit/credit), sourceEntities/Transactions, autoGenerated, reviewStatus, reviewedBy/Comment | → ConsolidationPeriod (N:1) |
| TranslationAdjustment | schema:MonetaryAmount | consolidationPeriodId, entityId, currencyPair, translationMethod, ctaComponent | → ConsolidationPeriod (N:1), → GroupEntity (N:1) |
| MinorityInterest | schema:MonetaryAmount | consolidationGroupId, entityId, thirdPartyPercentage, openingBalance, periodResultShare, closingBalance (calc) | → ConsolidationGroup (N:1), → GroupEntity (N:1) |
| Goodwill | schema:MonetaryAmount | consolidationGroupId, subsidiaryEntityId, acquisitionDate, purchasePrice, fairValueNetAssetsAcquired, goodwillAmount (calc), amortizationMethod | → ConsolidationGroup (N:1), → GroupEntity (N:1) |
| ConsolidatedBalance | schema:Report | consolidationGroupId, consolidationPeriodId, reportDate, lines[] (RGS), totalAssets/Liabilities/Equity, state | → ConsolidationGroup (N:1), → ConsolidationPeriod (N:1) |
| ConsolidatedIncomeStatement | schema:Report | consolidationGroupId, consolidationPeriodId, reportDate, lines[] (RGS), netProfitTotal/AttributedToParent/Minority, state | → ConsolidationGroup (N:1), → ConsolidationPeriod (N:1) |
### SepaMandate
**Schema.org:** `schema:FinancialProduct`
_A SEPA Direct Debit mandate (incassomachtiging). Source of truth for incasso eligibility: a collection may only be scheduled against an `active` mandate. Lifecycle `pending → active → cancelled | expired | suspended`. Referenced by Counterparty.defaultMandateId and Invoice.directDebitMandateId once accounts-receivable-core merges._
**Primary spec:** bookkeeping-sepa-direct-debit
| mandateReference | string | Yes | Unique per creditor, max 35 chars, immutable once issued |
| creditorIdentifier | string | Yes | Dutch NL{check}ZZZ{KvK} creditor identifier |
| scheme | enum | Yes | CORE (consumer) or B2B (business) |
| type | enum | Yes | recurring or oneoff |
| status | enum | Yes | pending, active, cancelled, expired, suspended |
| signedAt | date | Yes | Date the mandate was signed |
| debtorIban | string | Yes | Debtor IBAN (mod-97 validated at batch generation) |
| debtorAccountType | enum | Yes | consumer (CORE) or business (B2B) |
| lastUsedAt | date | No | Last successful collection date (drives 36-month dormancy) |
| mandateDocument | file | No | Scanned signature / digital-signing evidence |
| cancellationReason | string | No | Recorded on cancellation |
| administrationId | string | Yes | FK to Administration |
### DirectDebitCollection
**Schema.org:** `schema:PaymentMethod`
_A single SEPA Direct Debit collection against a mandate. sequenceType (FRST/RCUR/OOFF/FNAL) is derived from mandate history, never operator-supplied. Lifecycle `scheduled → submitted → accepted_by_bank → succeeded | rejected | refunded`. FK: mandateId → SepaMandate, invoiceId → Invoice, submittedInBatchId → DirectDebitBatch._
**Primary spec:** bookkeeping-sepa-direct-debit
| mandateId | string | Yes | FK to SepaMandate UUID |
| invoiceId | string | No | FK to Invoice UUID (nullable for ad-hoc collections) |
| amount | number | Yes | Collection amount in EUR (2 decimals) |
| sequenceType | enum | Yes | Auto-derived: FRST, RCUR, OOFF, FNAL |
| requestedCollectionDate | date | Yes | Date funds should hit the creditor account |
| endToEndId | string | Yes | Unique per creditor, max 35 chars |
| status | enum | Yes | scheduled, submitted, accepted_by_bank, presented, succeeded, rejected, refunded |
| pain002ReasonCode | string | No | ISO 20022 reason code if rejected |
| repostedAsCollectionId | string | No | FK to the new collection if reposted |
| administrationId | string | Yes | FK to Administration |
### DirectDebitBatch
**Schema.org:** `schema:Invoice`
_A pain.008.001.02 batch aggregating homo-sequence collections. pain008Xml/pain002Xml are archived 7+ years (bewaarplicht). Lifecycle `draft → generated → submitted → accepted_by_bank | partially_rejected | fully_rejected`._
**Primary spec:** bookkeeping-sepa-direct-debit
| messageId | string | Yes | Globally unique per creditor per file, max 35 chars |
| requestedCollectionDate | date | Yes | Earliest collection date in the batch |
| scheme | enum | Yes | CORE or B2B |
| sequenceType | enum | Yes | FRST, RCUR, OOFF, FNAL (homo-sequence batch) |
| collectionCount | integer | Yes | pain.008 NbOfTxs |
| controlSum | number | Yes | pain.008 CtrlSum (EUR, 2 decimals) |
| status | enum | Yes | draft, generated, submitted, accepted_by_bank, partially_rejected, fully_rejected |
| pain008Xml | string | No | Archived ISO 20022 pain.008 payload (non-deletable) |
| pain002Xml | string | No | Archived incoming pain.002 status report |
| administrationId | string | Yes | FK to Administration |
### RTransaction
**Schema.org:** `schema:MoneyTransfer`
_A bank-side R-transaction (reject/return/refund/reversal/revocation) parsed from pain.002 or camt.054. Captured separately for audit and reposting decisions. Non-deletable. FK: collectionId → DirectDebitCollection._
**Primary spec:** bookkeeping-sepa-direct-debit
| collectionId | string | Yes | FK to DirectDebitCollection UUID |
| type | enum | Yes | reject, return, refund, reversal, revocation, request_for_cancellation |
| reasonCode | string | Yes | ISO 20022 ExternalReturnReason code |
| transactionAmount | number | Yes | R-transaction amount |
| valueDate | date | Yes | Date the debtor account was re-credited |
| notifiedAt | datetime | Yes | When shillinq received the notification |
| administrationId | string | Yes | FK to Administration |
### PreNotification
**Schema.org:** `schema:Message`
_A vooraankondiging for a collection. A collection MUST NOT enter a pain.008 batch unless its pre-notification is sent or carried on the invoice line (14-day default lead). FK: collectionId → DirectDebitCollection._
**Primary spec:** bookkeeping-sepa-direct-debit
| collectionId | string | Yes | FK to DirectDebitCollection UUID |
| sentAt | datetime | No | When the notification was sent (null = not yet sent) |
| channel | enum | No | email, letter, invoice_line |
| noticeDays | integer | Yes | Calendar days between notification and collection date (default 14) |
| recipientAddress | string | No | Email, postal address, or invoice reference |
| administrationId | string | Yes | FK to Administration |
### CashflowForecastHorizon
_The 13-week rolling cashflow window for one administration. Rolls forward every Monday 02:00 UTC: week-1 archived, week-13 appended. Categorisation follows IAS 7 / RJ 360._
**Primary spec:** bookkeeping-cashflow-13wk
| horizonId | string | Yes | Unique identifier (UUID) |
| ondernemingId | string | Yes | FK to the onderneming/corporation |
| horizonStart | date | Yes | First day of the 13-week window (a Monday) |
| horizonEind | date | Yes | Last day of the window (Sunday, +90 days) |
| rolledOp | datetime | Yes | Timestamp of the last weekly roll |
| openingSaldo | object | Yes | Opening balance breakdown (zakelijkeRekening + three spaardoel buckets + totaal) |
| modelVersie | string | No | Forecast model version string |
| kalibratieScore | number | No | Prior-month forecast accuracy (0-1, MAPE-weighted) |
| crisisModeActief | boolean | No | True when a negative saldo is predicted within 4 weeks |
| administrationId | string | Yes | FK to the administration (tenant scope) |
| lifecycleState | enum | Yes | One of active, rolling, archived |
### CashflowWeek
_One weekly slot in the 13-week horizon with inflows/outflows by category and computed ending saldo._
**Primary spec:** bookkeeping-cashflow-13wk
| weekId | string | Yes | Unique identifier (UUID) |
| horizonId | string | Yes | FK to CashflowForecastHorizon |
| weeknummer | integer | Yes | ISO 8601 week number |
| weekStart | date | Yes | Monday of the week |
| weekEind | date | Yes | Sunday of the week |
| openingSaldo | number | Yes | Opening balance for the week |
| inflows_ar_geprognosticeerd | number | Yes | Projected AR receipts (betalingsgedrag-based) |
| inflows_totaal | number | Yes | Sum of all inflows |
| outflows_ap_geprognosticeerd | number | Yes | Projected AP payments (due-date scheduled) |
| outflows_totaal | number | Yes | Sum of all outflows |
| nettoMutatie | number | Yes | inflows_totaal - outflows_totaal |
| eindSaldo | number | Yes | openingSaldo + nettoMutatie |
| bufferStatus | enum | No | One of BOVEN_BUFFER, VOORALARM, CRISIS |
| alerts | array | No | Alert records raised for this week |
| administrationId | string | Yes | FK to the administration (tenant scope) |
### CashflowARProjection
_One record per open AR invoice projecting the expected receipt date from customer-specific betalingsgedrag history rather than the contractual due date._
**Primary spec:** bookkeeping-cashflow-13wk
| projId | string | Yes | Unique identifier (UUID) |
| horizonId | string | Yes | FK to CashflowForecastHorizon |
| arInvoiceId | string | Yes | FK to the AR invoice (read-only) |
| klantId | string | No | Denormalised customer identifier |
| factuurDatum | date | Yes | Invoice issuance date |
| vervalDatum | date | Yes | Contractual due date |
| openstaandBedrag | number | Yes | Outstanding amount in EUR |
| verwachtOntvangstDatum | date | Yes | Projected receipt date |
| betrouwbaarheidScore | number | No | Confidence score (0-1) |
| administrationId | string | Yes | FK to the administration (tenant scope) |
### CashflowAPSchedule
_One record per scheduled AP outflow within the horizon, derived from open AP invoices' due dates._
**Primary spec:** bookkeeping-cashflow-13wk
| schedId | string | Yes | Unique identifier (UUID) |
| horizonId | string | Yes | FK to CashflowForecastHorizon |
| apTransactionId | string | Yes | FK to the AP invoice/transaction (read-only) |
| leverancierNaam | string | No | Supplier/creditor name (denormalised) |
| vervalDatum | date | Yes | AP invoice due date |
| geplandeBetaalDatum | date | Yes | Planned payment date |
| bedrag | number | Yes | Amount to pay in EUR |
| categorie | enum | No | Outflow category |
| betalingsmethode | enum | No | Payment method |
| administrationId | string | Yes | FK to the administration (tenant scope) |
### CashflowRecurring
_A declarative recurring inflow/outflow stream auto-expanded into the 13-week horizon by frequency, activation window and optional CPI indexing._
**Primary spec:** bookkeeping-cashflow-13wk
| recurId | string | Yes | Unique identifier (UUID) |
| ondernemingId | string | Yes | FK to the onderneming/corporation |
| label | string | Yes | Human-readable name |
| categorie | enum | Yes | RECURRING_HUUR..RECURRING_OVERIG |
| richting | enum | Yes | IN or OUT |
| frequentie | enum | Yes | WEKELIJKS, TWEEWEKELIJKS, MAANDELIJKS, KWARTAALS, JAARLIJKS |
| dagVanMaand | integer | No | Day of month for monthly recurrence |
| maandVanJaar | integer | No | Month for annual recurrence |
| standaardBedrag | number | Yes | Base amount in EUR |
| indexatieRegel | enum | No | FIXED or CPI_AFGELOPEN_JAAR |
| geldigVan | date | Yes | Effective start date |
| geldigTot | date | No | Expiration date (null if indefinite) |
| accountNumberExpense | string | No | GL account code (FK to Account.accountNumber) |
| administrationId | string | Yes | FK to the administration (tenant scope) |
### CashflowBufferPolicy
_Operator-configured minimum cash reserve with two-tier alerts: vooralarm at 150% (yellow) and ondergrens at 50% (red) of the calculated buffer._
**Primary spec:** bookkeeping-cashflow-13wk
| policyId | string | Yes | Unique identifier (UUID) |
| ondernemingId | string | Yes | FK to the onderneming/corporation |
| policy | enum | Yes | MIN_FIXED_AMOUNT, MIN_MONTHS_VASTE_KOSTEN, CUSTOM_FORMULA |
| berekendeBuffer | number | Yes | Calculated buffer threshold in EUR |
| alertOndergrens | number | Yes | Red critical threshold (= buffer x 0.50) |
| alertVooralarm | number | Yes | Yellow pre-alert threshold (= buffer x 1.50) |
| administrationId | string | Yes | FK to the administration (tenant scope) |
### CashflowScenario
_An immutable what-if snapshot of a horizon with one or more adjustments, re-computed for comparison against the baseline. Scenarios are data, not stored logic._
**Primary spec:** bookkeeping-cashflow-13wk
| scenarioId | string | Yes | Unique identifier (UUID) |
| horizonId | string | Yes | FK to the parent CashflowForecastHorizon |
| naam | string | Yes | Scenario name |
| description | string | No | Scenario description |
| aanpassingen | array | Yes | Adjustment rules (AR_PROJECTION_OVERRIDE, RECURRING_COST_ADJUSTMENT, NEW_REVENUE, BUFFER_POLICY_OVERRIDE) |
| resultaat | object | No | Computed forecast results |
| createdAt | datetime | Yes | Scenario creation timestamp |
| administrationId | string | Yes | FK to the administration (tenant scope) |
### CashflowCalibrationReport
**Schema.org:** `schema:Report`
_Post-month-end forecast accuracy report comparing actual vs forecast by category (MAPE), used to recalibrate betalingsgedrag and pipeline-conversion models._
**Primary spec:** bookkeeping-cashflow-13wk
| reportId | string | Yes | Unique identifier (UUID) |
| horizonId | string | Yes | FK to CashflowForecastHorizon |
| calibrationPeriod | string | Yes | Period evaluated, YYYY-MM |
| generatedAt | datetime | Yes | Timestamp of the calibration run |
| ar_mape | number | Yes | MAPE for AR projections (%) |
| ap_mape | number | Yes | MAPE for AP projections (%) |
| recurring_mape | number | Yes | MAPE for recurring costs (%) |
| tax_mape | number | Yes | MAPE for tax projections (%) |
| betalingsgedragUpdates | array | No | Customers with re-calculated offsets |
| pipelineConversionUpdates | array | No | Deals with re-calibrated probability |
| administrationId | string | Yes | FK to the administration (tenant scope) |

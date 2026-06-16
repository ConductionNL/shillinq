# Specs — Payroll + Detachering Bridge

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** bookkeeping-general-ledger, bookkeeping-accounts-payable-core-t2

---

## REQ-PAY-001: Employee Master with Contract Classification

MUST support employee records with legal name, BSN, contract type (employee/freelancer/detached), tax classification, and contact details per Dutch employment law and detachering regulations.

#### Scenario: Create and store a detached (temporary) employee

GIVEN a staffing agency is setting up a detached worker placement
WHEN the payroll administrator creates an `Employee` record with:
- `contractType: "detached"`
- `taxClassification: "detached-worker"`
- `placementAgencyId` (FK to supplier master)
- `onboardingDate: "2026-05-01"`
- `salaryScale: "scale-b"`

THEN the Employee record persists with all fields, audit trail captures creation, and the employee is immediately available for payroll assignment

#### Scenario: Validate BSN format and uniqueness per administration

GIVEN an Employee record is being created with a BSN
WHEN the BSN is provided as `123456789`
THEN the system validates the BSN passes 11-proef (Dutch checksum algorithm) and is unique within the administration; duplicate BSN records are rejected with a clear error message

#### Scenario: Track employee lifecycle (onboarding, exit)

GIVEN an employee has `onboardingDate` and optional `exitDate`
WHEN the payroll administrator marks an employee as exited (`exitDate: "2026-12-31"`)
THEN the employee record is not deleted but marked as inactive; payroll records before exit date remain queryable, payroll records after exit date are rejected on creation with "employee inactive" message

---

## REQ-PAY-002: Payroll Period Record with Gross and Deductions

MUST support payroll records capturing gross salary/wage input and aggregated deduction totals per employee per period, with lifecycle state management.

#### Scenario: Create a payroll record for a monthly period

GIVEN an employee is onboarded and the accounting period is May 2026
WHEN the payroll administrator creates a `Payroll` record with:
- `employeeId` (FK to Employee)
- `period: "2026-05"`
- `periodStartDate: "2026-05-01"`
- `periodEndDate: "2026-05-31"`
- `grossAmount: 3500.00` (EUR, employee salary per contract)
- `payDate: "2026-06-15"`

THEN the record is saved in `draft` status, audit trail captures creation, and the record is immediately available for deduction assignment

#### Scenario: Compute net amount from gross minus all deductions

GIVEN a Payroll record exists in `draft` status with `grossAmount: 3500.00` and deduction line items
- Loonbelasting: 420.00
- SV bijdrage werknemer: 430.00
- Pension: 150.00
- Total deductions: 1000.00

WHEN the deductions are aggregated (via `x-openregister-aggregations` query)
THEN the system computes `netAmount = grossAmount - totalDeductions = 2500.00` and stores it on the Payroll record; no PHP service required, pure aggregation

#### Scenario: Support detachering placement-fee posting

GIVEN a Payroll record for a detached employee (`Employee.contractType: "detached"`) with:
- `grossAmount: 2800.00`
- `placementFeeAmount: 350.00` (fee owed to staffing agency)
- `placementAgencyId` (FK to Supplier)

WHEN payroll transitions to `issued` status
THEN an AP transaction (vendor invoice) is materialised with:
- `payee: <placement agency Supplier>`
- `amount: 350.00`
- `sourceDocument: <Payroll record URI>`
- Net salary paid to employee is `grossAmount - deductions`, separate from placement fee

---

## REQ-PAY-003: Deduction Line Items with Type, Amount, and Rate

MUST support granular deduction records per payroll period with deduction type, statutory rate reference, and audit trail for tax/social-security compliance.

#### Scenario: Record income tax (loonbelasting) deduction per statutory rate

GIVEN a Payroll record exists for 2026 and the statutory income tax rate for that year is 12.0%
WHEN a `Deduction` record is created with:
- `payrollId` (FK to Payroll)
- `deductionType: "income-tax"`
- `deductionName: "Loonbelasting"`
- `amount: 420.00` (computed by external payroll software)
- `rate: 12.0`
- `rateSource: "statutory-2026"`
- `taxYear: 2026`

THEN the deduction is recorded and the annual total (sum of all `income-tax` deductions for the employee in 2026) is available via aggregation for tax reporting

#### Scenario: Record employee and employer social-security contributions

GIVEN a Payroll record for an employee in 2026
WHEN two Deduction records are created:
1. Employee SV contribution (`deductionType: "social-security-employee"`, `rate: 12.3`, `amount: 430.00`)
2. Employer SV contribution (`deductionType: "social-security-employer"`, `rate: 8.0`, materialised as GL expense posting, NOT deducted from net salary)

THEN both are recorded separately, enabling annual SV reporting and GL reconciliation; employer contributions appear in GL expense but not in payroll net calculation

#### Scenario: Support garnishment deductions (court-ordered)

GIVEN an employee has a court-ordered garnishment
WHEN a Deduction record is created with:
- `deductionType: "garnishment"`
- `deductionName: "Inhouding hoofdsom schuld"`
- `amount: 100.00`
- `rateSource: "court-order-2025-12345"`

THEN the garnishment is recorded and audit trail captures the court-order reference; payroll administrators can generate garnishment-payment statements for submission to court

---

## REQ-PAY-004: Payroll Lifecycle (draft → calculated → issued → paid)

MUST support state transitions for payroll records with immutability after issuance, GL posting on issue, and payment confirmation.

#### Scenario: Transition payroll from draft to calculated

GIVEN a Payroll record exists in `draft` status with all deduction line items assigned
WHEN the payroll administrator or external payroll software triggers the `calculated` transition
THEN:
- The record transitions to `calculated` status (immutable flag: edits forbidden after this point)
- Audit trail records `calculated` timestamp and actor
- Tax/SV deduction aggregations are validated (see REQ-PAY-005)
- System is ready for issuance (determination letter generation, GL posting)

#### Scenario: Issue payroll and materialise GL transaction

GIVEN a Payroll record is in `calculated` status
WHEN the payroll administrator transitions to `issued` status
THEN:
- The record transitions to `issued` status (immutable)
- A balanced GL transaction is materialised with:
  - Debit: `Salary Expense` (GL account per `Employee.contractType`)
  - Credit: `Payroll Liability` (accrual) or `Bank Account` (if immediate disbursement)
  - Reference: `Payroll.payrollNumber`
- Audit trail records issuance timestamp, actor, GL transaction ID
- Determination letter (loonstrookje) is generated (PDF, attached to `DeterminationLetter` record)
- Webhooks are published: `payroll-issued` event to external payroll software

#### Scenario: Mark payroll as paid after bank reconciliation

GIVEN a Payroll record is in `issued` status with a pending bank transfer
WHEN the bank-reconciliation process matches the payroll amount against a bank line item
THEN:
- The payroll transitions to `paid` status
- GL transaction is marked as settled
- Audit trail records payment confirmation timestamp and bank-rec reference
- Webhook published: `payroll-paid` event

#### Scenario: Reject a calculated payroll if deductions exceed statutory limits

GIVEN a Payroll record is in `calculated` status for 2026
WHEN an aggregation validates the annual deduction totals against statutory limits and finds:
- Annual income tax deductions exceed the maximum taxable income
- OR annual SV contributions exceed the statutory ceiling

THEN:
- The transition to `issued` is blocked with error: "Deduction validation failed: annual income-tax exceeds limit"
- Audit trail records the validation failure
- Payroll remains in `calculated` status; administrator must correct deduction records and retry

---

## REQ-PAY-005: Tax/SV Aggregation Preconditions and Annual Totals

MUST validate per-employee annual deduction totals against statutory tax and social-security limits per ADR-031 aggregations pattern.

#### Scenario: Aggregate annual income-tax deductions and validate against statutory maximum

GIVEN an employee has multiple Payroll records in 2026 (Jan–May) with income-tax deductions
WHEN the system computes the annual total via aggregation: `SUM(Deduction.amount WHERE deductionType='income-tax' AND taxYear=2026 AND employeeId=X)`
THEN the result is compared against the statutory maximum (e.g., 50% of gross annual income) and:
- If within limit: transition to `issued` is allowed
- If exceeds limit: transition to `issued` is blocked with clear error message citing the limit and overage amount

#### Scenario: Generate annual social-security report from aggregated deductions

GIVEN an administration manages multiple employees for 2026 fiscal year
WHEN the reporting system queries aggregations for all employees:
- `SUM(Deduction.amount WHERE deductionType='social-security-employee' AND taxYear=2026 GROUP BY employeeId)`
- `SUM(Deduction.amount WHERE deductionType='social-security-employer' AND taxYear=2026 GROUP BY employeeId)`

THEN the aggregations return per-employee annual totals suitable for UVA (Uitkeringinstelling Sociale Zekerheid) filing; no SQL queries required, no custom report service

---

## REQ-PAY-006: Determination Letter (Werkgeversverklaring / Loonstrookje)

MUST support generation and archival of payroll determination letters (salary certificates, pay stubs) with PDF attachment for employee records.

#### Scenario: Generate a loonstrookje (pay stub) on payroll issuance

GIVEN a Payroll record transitions to `issued` status
WHEN the GL posting completes
THEN:
- A `DeterminationLetter` record is created with:
  - `payrollId` (FK to Payroll)
  - `letterType: "loonstrookje"` (pay stub)
  - `generatedDate: <today>`
  - `content` (human-readable summary: gross, deductions, net, tax details)
- PDF is generated (via external rendering service or OR template engine, not in Shillinq)
- PDF is attached to the `DeterminationLetter` via OR `files` relation
- Record is marked as archival (7-year retention per Archiefwet)

#### Scenario: Generate a werkgeversverklaring (salary certificate) on employee request

GIVEN an employee requests a salary certificate (werkgeversverklaring) for a specified year
WHEN the payroll administrator triggers generation for 2026
THEN:
- A `DeterminationLetter` record is created with:
  - `letterType: "werkgeversverklaring"`
  - `year: 2026`
  - `content` (summary of annual gross, taxes withheld, SV contributions)
- PDF is generated and attached
- Record is immutable (audit trail captures generation and any subsequent access)

#### Scenario: Archive determination letters for 7 years (Dutch Archiefwet)

GIVEN DeterminationLetter records exist for 2019
WHEN the system's data-archival task runs in 2027 (7 years after payroll issuance)
THEN:
- Records are transitioned to `archived` status (searchable but read-only)
- PDF attachments remain accessible
- After 7-year period, records are eligible for destruction per configured retention policy

---

## REQ-PAY-007: Detachering Employee Onboarding/Exit Processing

MUST support detachering-specific workflows for temporary worker lifecycle, placement fees, and agency reconciliation.

#### Scenario: Onboard a detached worker via placement agency

GIVEN a staffing agency (Supplier) is the placement coordinator
WHEN the payroll administrator creates an Employee record with:
- `contractType: "detached"`
- `onboardingDate: "2026-05-01"`
- `placementAgencyId` (FK to Supplier: staffing agency)
- `placementFee` (monthly or per-assignment fee)

THEN:
- Employee is marked as active
- Future payroll records for this employee automatically link to the placement agency
- On each payroll issuance, a placement-fee AP transaction is materialised to reconcile with the agency invoice

#### Scenario: Exit a detached worker and close associated records

GIVEN a detached employee's assignment ends
WHEN the payroll administrator sets:
- `exitDate: "2026-12-31"`
- Payroll records are created for Jan–Dec 2026

THEN:
- Employee is marked as inactive (no new payroll records after exit date)
- Final determination letter (Loonstrookje jaarrekening) is generated
- Placement-fee AP reconciliation is completed for all 12 months

---

## REQ-PAY-008: GL Materialisation for Salary Expense and Payroll Liabilities

MUST materialise balanced GL transactions on payroll issuance per T1 JournalEntry pattern, with account selection by contract type.

#### Scenario: Materialise salary-expense GL transaction on payroll issue

GIVEN a Payroll record for an `employee` contract type transitions to `issued` status
WHEN the lifecycle action fires
THEN a balanced GL transaction is created:
- **Debit** (expense): GL Account per `Employee.contractType` mapping
  - `employee` → `4100` (Salary Expense – Employees)
  - `detached` → `4110` (Salary Expense – Detached Workers)
  - `freelancer` → `4120` (Freelancer Contracts)
- **Credit** (liability/bank): `Payroll Liability` or `Bank Account` (configurable per administration)
- Amount: `Payroll.grossAmount`
- Reference: `Payroll.payrollNumber`
- Audit trail: captures GL posting with timestamp, actor, GL transaction ID

#### Scenario: Materialise deduction GL postings for employer SV contributions

GIVEN a Payroll record with employer SV deduction (not employee-deducted)
WHEN the payroll transitions to `issued`
THEN:
- GL transaction includes debit `Social Security Expense (Employer)` and credit `SV Liability`
- Amount: `Deduction.amount` for employer SV deductions
- Employer SV is NOT deducted from employee net salary (kept separate)

#### Scenario: Materialise placement-fee GL posting for detached workers

GIVEN a Payroll record for a detached employee transitions to `issued` with `placementFeeAmount: 350.00`
WHEN the lifecycle action fires
THEN:
- A second GL transaction is materialised for the placement fee:
  - **Debit**: `Freelancer Contract Fees` or `Placement Agency Fees` GL account
  - **Credit**: Accounts Payable (AP) to the placement agency
  - Amount: `Payroll.placementFeeAmount`
- An AP transaction (vendor invoice) is created for the placement agency, available for reconciliation against their invoice

---

## REQ-PAY-009: External Payroll Software Integration via REST API + Webhooks

MUST expose Payroll, Deduction, and Employee schemas via OpenRegister REST API for external payroll software integration, and publish CloudEvents webhooks on changes.

#### Scenario: External payroll software reads employee roster

GIVEN external payroll software is authorized to read Employee schema
WHEN the software calls `GET /index.php/apps/openregister/api/objects?register=shillinq&schema=Employee&_limit=100`
THEN:
- Response includes paginated Employee records with all fields (name, BSN, contractType, etc.)
- BSN is included in response but flagged for PII masking (ADR-005)
- Links to associated Payroll records (per-period) are provided via relations

#### Scenario: External software publishes payroll changes via webhook

GIVEN an external payroll platform (SalaryBox, Nmbrs, etc.) calculates Payroll and Deduction records
WHEN the external platform POSTs to the Shillinq webhook endpoint:
```json
{
  "specversion": "1.0",
  "type": "nl.conduction.payroll.calculated",
  "source": "external-payroll-system",
  "id": "ext-payroll-2026-05-001",
  "time": "2026-05-10T14:30:00Z",
  "datacontenttype": "application/json",
  "data": {
    "payrollId": "payroll-2026-05-emp-001",
    "deductions": [
      { "deductionType": "income-tax", "amount": 420.00, "rate": 12.0 },
      { "deductionType": "social-security-employee", "amount": 430.00, "rate": 12.3 }
    ]
  }
}
```

THEN:
- Shillinq validates the webhook signature (HMAC-SHA256)
- Deduction records are created/updated per the payload
- Payroll status transitions to `calculated` (if not already)
- Audit trail records the webhook source and payload

#### Scenario: Shillinq publishes payroll lifecycle events to external subscribers

GIVEN a Payroll record transitions to `issued` status
WHEN the lifecycle action completes
THEN Shillinq publishes a CloudEvent:
```json
{
  "specversion": "1.0",
  "type": "nl.conduction.payroll.issued",
  "source": "shillinq/payroll",
  "id": "pay-2026-05-001-issued",
  "time": "2026-05-15T10:00:00Z",
  "datacontenttype": "application/json",
  "data": {
    "payrollId": "payroll-2026-05-emp-001",
    "payrollNumber": "PAY-2026-05-001",
    "employeeId": "employee-001-john-smith",
    "grossAmount": 3500.00,
    "netAmount": 2500.00,
    "payDate": "2026-06-15",
    "glTransactionId": "gl-2026-05-payroll-001"
  }
}
```

- External subscribers (CRM, tax software, HR systems) consume the event
- Webhook endpoint is configured per administration (URL + auth token stored in IAppConfig)

---

## REQ-PAY-010: UBL Peppol BIS 30 Field Shape for Payroll Disbursement (T4)

MUST declare UBL Peppol BIS 30 field shape on Payroll schema for T4 payroll-disbursement outbound (NOT computed in T2).

#### Scenario: Declare UBL field shape on Payroll register for T4 attachment

GIVEN T4 payroll-disbursement (e-payroll outbound) is planned
WHEN the schema is declared, the Payroll register includes fields aligned to UBL Invoice format:
- `invoiceNumber` (aka `payrollNumber`, Peppol: `cbc:ID`)
- `invoiceDate` (aka `generatedDate`)
- `buyerPartyId` (employer identifier, Peppol: `cac:BuyerParty/cac:PartyIdentification`)
- `sellerPartyId` (employee identifier, Peppol: `cac:SellerParty/cac:PartyIdentification`)
- `invoiceLinesArray` (array of deduction line items, Peppol: `cac:InvoiceLine[]`)
- Per line: `quantity`, `unitPrice`, `lineAmount`, `taxCategory`, `taxPercent`

THEN T4 reads these fields and emits valid UBL 2.1 / Peppol BIS 30 `Invoice` XML without schema changes required

#### Scenario: Ensure field naming matches UBL canonical names for T4 passthrough

GIVEN a Deduction record with `deductionType: "income-tax"` and `amount: 420.00`
WHEN T4 outbound processes the Payroll schema
THEN:
- Field names follow UBL standard (e.g., `cac:InvoiceLine/cbc:LineID`, `cbc:LineExtensionAmount`)
- T4 can construct valid UBL XML without field renaming or mapping
- Peppol compliance (tax category codes, party identifiers) is achievable additively in T4

---

## REQ-PAY-011: Manifest Navigation and Index/Detail Pages

MUST provide manifest entries for 6 payroll-related UI surfaces (Employees, Payroll, Payroll Calendar, Deductions, Determination Letters, Tax/SV Reports) with standard `type: index` and `type: detail` pages per ADR-031 manifest pattern.

#### Scenario: Navigation entry for Employee master index page

GIVEN the app is loaded
WHEN the user selects "Employees" from the main menu
THEN:
- `CnIndexPage` component loads with:
  - `useListView(entity='Employee', { sidebarState, objectStore })`
  - Search, sort, filter, pagination over all Employee records
  - Add button creates new Employee via `CnFormDialog` (schema-driven)
  - Row click → Employee detail page
- Manifest entry: `type: index`, route: `/employees`

#### Scenario: Navigation entry for Payroll calendar view (monthly grid)

GIVEN the payroll administrator needs to review all payroll records by month
WHEN the user selects "Payroll Calendar" from the main menu
THEN:
- Custom dashboard or calendar-grid widget displays Payroll records grouped by period (month/week)
- Status color-coding: draft (gray), calculated (yellow), issued (green), paid (blue)
- Click on a cell → Payroll detail page or inline edit
- Manifest entry: `type: index`, route: `/payroll/calendar`

#### Scenario: Payroll detail page with related Deductions and Determination Letter sections

GIVEN the user clicks on a Payroll record in the index
WHEN the detail page loads
THEN:
- `CnDetailPage` displays Payroll header (employee, period, gross, net, status)
- Sections:
  1. **Deductions** — `CnDetailCard` with table of all `Deduction` records linked to this Payroll
  2. **GL Posting** — `CnDetailCard` showing materialised GL transaction (Debit/Credit, amount, account codes)
  3. **Determination Letter** — `CnDetailCard` with generated PDF link and download
  4. **Payment Confirmation** — status and bank-reconciliation reference (if paid)
- Manifest entry: `type: detail`, route: `/payroll/:id`

---

## REQ-PAY-012: Audit Trail and PII Masking on BSN

MUST capture full audit trail per ADR-005, with special handling for BSN (PII) — audit logs use `employeeId` FK only, NOT raw BSN values.

#### Scenario: Audit trail records Employee creation without exposing BSN

GIVEN an Employee record is created with BSN `123456789`
WHEN the record is saved
THEN audit trail entry captures:
- `action: "created"`
- `actor: "admin-user-id"`
- `timestamp: "2026-05-01T10:00:00Z"`
- `changes: { "employeeNumber": "EMP-001", "legalName": "John Smith", "contractType": "employee" }`
- BSN is NOT included in audit log (per ADR-005 PII rule)

#### Scenario: Display mask option for BSN on UI

GIVEN a user views an Employee detail page
WHEN the page loads
THEN:
- BSN field is displayed as `***56789` (last 5 digits visible) by default
- Unmask button available for admin users with explicit permission
- Audit trail records each unmask action with actor and timestamp

---

## Verification

`openspec validate` must exit clean on the change folder. Payroll-administrator persona peer review (e.g., via SMB payroll scenario) confirms the payroll flow matches Dutch SMB + staffing-agency practice (employee intake → payroll calculation → deductions → determination letter generation → GL posting → external sync → bank reconciliation). Architecture reviewer confirms ADR-022 + ADR-031 + ADR-005 compliance (no app-local payroll-calculation table; lifecycle declarative or ADR-031-exception-annotated guard; PII masking on BSN; manifest carries the navigation). No source code changes outside `openspec/changes/bookkeeping-detachering-payroll-administratie/`.


# Spec: Invoice from Time + Expense

**Status:** proposed
**Scope:** shillinq-bookkeeping
**Tier:** T2
**Depends on:** `obligation-financial-administration` (approved), `rate-card-engine` (approved), `retainer-billing-engine` (approved)

## Preamble

This specification defines invoice generation from approved time entries and expense records, supporting multiple billing models (T&M, fixed-fee, milestone, retainer, mixed). It extends the `Invoice` and `InvoiceLine` registers with time/expense tracking, rate card integration, and GL posting. All requirements use RFC 2119 language (MUST, SHOULD, MAY).

---

## ADDED Requirements

### Requirement: Invoice Schema Extensions

The `Invoice` register (from `obligation-financial-administration`) MUST be extended with the following fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| billingModel | enum | Yes | One of: t_and_m, fixed_fee, milestone, retainer, mixed |
| timeEntryIds | array of string | No | References to TimeEntry IDs included in this invoice |
| expenseIds | array of string | No | References to Expense IDs included in this invoice |
| rateCardId | string | No | Reference to RateCard applied (if T&M model) |
| retainerScheduleId | string | No | Reference to RetainerSchedule applied (if retainer/mixed model) |
| lineItemsByModel | json | No | Metadata: count and total per source type (time_entry, expense, retainer_charge, fixed_fee) |
| summary | json | No | Summary object: {netAmount, vatAmount, grossAmount, currency} |
| posted | boolean | No | Whether invoice has been posted to AR (default: false) |

**Schema Type**: Extension of existing `Invoice` register; backward-compatible

#### Scenario: Create Invoice for T&M Project

- **GIVEN** a TimeEntry list with approved entries (dates 2026-05-01 to 2026-05-20, total 60 hours, mixed rates)
- **WHEN** operator invokes InvoiceGenerationService with billingModel=t_and_m, rateCardId=rate-consulting, timeEntryIds=[], expenseIds=[]
- **THEN** service queries RateCard to resolve hourly rates (e.g., €150/hr for Senior, €100/hr for Junior)
- **AND** service generates Invoice with billingModel=t_and_m, rateCardId=rate-consulting, timeEntryIds populated
- **AND** invoice is persisted with status=draft

#### Scenario: Create Invoice for Fixed-Fee Project

- **GIVEN** a fixed-fee engagement for €50,000
- **WHEN** operator invokes InvoiceGenerationService with billingModel=fixed_fee, timeEntryIds=[list of 200 hours], expenseIds=[]
- **THEN** service generates Invoice with billingModel=fixed_fee, single line item for €50,000 (time entries are not shown as line items, only in metadata)
- **AND** invoice.lineItemsByModel shows time_entry count but zero cost (hours logged for audit only)

---

### Requirement: InvoiceLine Schema Extensions

The `InvoiceLine` register MUST be extended with the following fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sourceType | enum | Yes | One of: time_entry, expense, retainer_charge, fixed_fee, manual |
| rateApplied | json | No | {rate (in cents), currency, rateCardVersion, effectiveDate} for time entries |
| billableUnits | number | No | Hours (for time) or count (for expenses) before multipliers |
| markup | number | No | Markup percentage applied (0-100); default 0 |
| modelSpecificFields | json | No | {milestoneId, milestoneCompletedAt} for milestone model; {retainerMonth} for retainer; {} for others |
| costAmount | number | Yes | Final line item cost in cents after rate × units × (1 + markup) |

**Schema Type**: Extension of existing `InvoiceLine` register; backward-compatible

#### Scenario: Time Entry Line Item with Rate Snapshot

- **GIVEN** a TimeEntry with 40 hours, resource=Senior Consultant, hourlyRate=€150/hr (from RateCard)
- **WHEN** invoice is generated
- **THEN** InvoiceLine is created with sourceType=time_entry, billableUnits=40, rateApplied={15000 cents, EUR, v1, 2026-05-21}, costAmount=600000
- **AND** rateApplied is snapshot'd; if RateCard changes later, this line item retains the original rate

#### Scenario: Expense Line Item

- **GIVEN** an Expense with amount €150, category=Travel
- **WHEN** invoice is generated
- **THEN** InvoiceLine is created with sourceType=expense, billableUnits=1, costAmount=15000
- **AND** rateApplied is null (expenses are not rate-based)

#### Scenario: Retainer Charge Line Item

- **GIVEN** a RetainerSchedule for €3,000/month, invoice month=May 2026
- **WHEN** invoice is generated with billingModel=retainer
- **THEN** InvoiceLine is created with sourceType=retainer_charge, billableUnits=1, costAmount=300000, modelSpecificFields={retainerMonth: "2026-05"}
- **AND** this line item is mandatory for retainer model

---

### Requirement: Invoice Generation Service

A new `InvoiceGenerationService.php` MUST provide the following methods:

- `draftInvoice(InvoiceGenerationRequest $request): Invoice` — drafts invoice from time entries + expenses, applies rates, calculates totals
- `validateInvoice(Invoice $invoice): ValidationResult` — checks for duplicate source IDs, validates billing model consistency
- `postInvoice(Invoice $invoice): Obligation` — marks invoice as posted, creates AR Obligation, posts GL entries
- `calculateNetAmount(array $lineItems): int` — sums line item costs in cents

#### Scenario: Draft T&M Invoice with Rate Lookup

- **GIVEN** InvoiceGenerationRequest with billingModel=t_and_m, rateCardId=rate-consulting, timeEntryIds=[time-001, time-002], expenseIds=[exp-001]
- **WHEN** draftInvoice() is called
- **THEN** service queries RateCard for hourly rates matching time entry resource types
- **AND** service creates InvoiceLines: line 1 (time-001, 40 hrs × €150), line 2 (time-002, 20 hrs × €100), line 3 (exp-001, €150)
- **AND** totalCost = 40×150 + 20×100 + 150 = €8,150 (before VAT)
- **AND** invoice is saved with status=draft

#### Scenario: Validate Prevents Double-Invoicing

- **GIVEN** an existing posted Invoice with timeEntryIds=[time-001, time-002]
- **AND** a draft InvoiceGenerationRequest with timeEntryIds=[time-002, time-003] (overlap: time-002)
- **WHEN** validateInvoice() is called
- **THEN** validation fails with error "Source ID time-002 is already in posted invoice 2026-001"
- **AND** draftInvoice() returns error; invoice is not created

#### Scenario: Post Invoice Creates Obligation

- **GIVEN** a draft Invoice with status=draft, grossAmount=10000, creditor=ClientBV, recipient=MyBV
- **WHEN** postInvoice() is called
- **THEN** invoice.status is set to posted, invoice.posted=true
- **AND** an Obligation is created: obligationNumber=generated, amount=10000, creditor=ClientBV, dueDate=invoiceDate+30days
- **AND** GL entries are posted: Debit AR (accounts receivable increase), Credit Revenue (revenue recognition by model type)

---

### Requirement: Billing Model Logic (T&M, Fixed-Fee, Milestone, Retainer, Mixed)

A new `BillingModelEngine.php` MUST implement billing model-specific calculations:

#### T&M (Time & Materials)

**Formula**: `invoiceAmount = Σ(time_hours × hourlyRate) + Σ(expenses) + markup%`

#### Scenario: T&M with Mixed Resources

- **GIVEN** TimeEntries: 40 hrs @ €150 (Senior), 20 hrs @ €100 (Junior), Expenses: €200
- **WHEN** BillingModelEngine.calculateT_AND_M() is called
- **THEN** result = (40×150) + (20×100) + 200 = €8,200
- **AND** line items are grouped by (rate, resource, date) for clarity

#### Fixed-Fee

**Formula**: `invoiceAmount = flatFee + Σ(expenses)` (time entries are ignored for billing; shown only in audit trail)

#### Scenario: Fixed-Fee Hides Time Detail

- **GIVEN** billingModel=fixed_fee, flatFee=€50,000, timeEntryIds with 200 hours, expenseIds=[€1,000 travel]
- **WHEN** invoice is generated
- **THEN** line items show: [flatFee €50,000, expenses €1,000]; time entries do NOT appear as separate line items
- **AND** invoice.lineItemsByModel shows time_entry count (200 hrs) but zero cost
- **AND** invoice total = €51,000

#### Milestone

**Formula**: `invoiceAmount = milestoneAmount + Σ(expenses)` (milestone must be marked complete; time entries shown in metadata)

#### Scenario: Milestone Invoice on Completion

- **GIVEN** a Milestone with name="Design Phase", budgetAmount=€25,000, status=completed, completionDate=2026-05-20
- **AND** timeEntryIds logged against this milestone (e.g., 100 hours design work)
- **WHEN** invoice is generated for this milestone
- **THEN** invoice.billingModel=milestone, lineItems=[{milestoneAmount €25,000, description "Design Phase"}]
- **AND** invoice.lineItemsByModel shows time_entry count (100 hrs) in metadata but not as cost
- **AND** invoice total = €25,000

#### Retainer

**Formula**: `invoiceAmount = monthlyRetainer + Σ(timeEntryOverage × hourlyRate) + Σ(expenses)`

#### Scenario: Retainer with T&M Overage

- **GIVEN** RetainerSchedule amount=€3,000/month, hourlyRate=€100 for overage (threshold is 30 hrs/month)
- **AND** TimeEntries for May 2026: 50 hours (20 hours over threshold)
- **AND** Expenses: €500
- **WHEN** invoice is generated
- **THEN** line items: [retainer €3,000, overage (20 hrs × €100) €2,000, expenses €500]
- **AND** invoice total = €5,500

#### Mixed

**Formula**: `invoiceAmount = monthlyRetainer + Σ(timeEntryOverage) + flatFeeAmount + Σ(expenses)` (combines retainer base, T&M overage, optional fixed fee, and expenses)

#### Scenario: Mixed Model (Retainer + Setup Fee + Overage)

- **GIVEN** billingModel=mixed with: retainer €2,000, setupFee €1,000 (one-time), hourlyRate for overage €120
- **AND** TimeEntries for May 2026: 60 hours (assume 40 hrs included in retainer, 20 hrs overage)
- **AND** Expenses: €300
- **WHEN** invoice is generated
- **THEN** line items: [retainer €2,000, setup €1,000, overage (20 hrs × €120) €2,400, expenses €300]
- **AND** invoice total = €5,700

---

### Requirement: GL Posting on Invoice Posting

When an Invoice is posted (moved from draft to posted), GL entries MUST be created:

- **Debit** Account: 1130 (Accounts Receivable) | Amount: grossAmount | Description: invoice reference
- **Credit** Account: Revenue (determined by model type) | Amount: netAmount | Description: invoice reference
- **Credit** Account: 1150 (VAT Payable) | Amount: vatAmount | Description: invoice reference

#### Scenario: GL Posting for T&M Invoice

- **GIVEN** Invoice with billingModel=t_and_m, netAmount=€8,000, vatAmount=€1,680, grossAmount=€9,680
- **WHEN** postInvoice() is called
- **THEN** GL entries are posted:
  - Debit 1130 (AR) €9,680
  - Credit 4100 (T&M Revenue) €8,000
  - Credit 1150 (VAT Payable) €1,680
- **AND** Journal Entry is created with isBalanced=true, audit trail logs GL posting

#### Scenario: GL Posting for Fixed-Fee Invoice

- **GIVEN** Invoice with billingModel=fixed_fee, netAmount=€40,000, vatAmount=€8,400, grossAmount=€48,400
- **WHEN** postInvoice() is called
- **THEN** GL entries are posted:
  - Debit 1130 (AR) €48,400
  - Credit 4200 (Fixed-Fee Revenue) €40,000
  - Credit 1150 (VAT Payable) €8,400

---

### Requirement: Admin Invoice Interface

An admin Vue component `InvoiceGenerator.vue` MUST provide:

1. **Billing Model Selection** — dropdown: t_and_m, fixed_fee, milestone, retainer, mixed
2. **Date Range Filter** — from/to dates to select time entries and expenses
3. **Rate Card Selector** — dropdown for T&M model (optional for other models)
4. **Retainer Schedule Selector** — dropdown for retainer/mixed models (optional for other models)
5. **Line Item Review** — table showing all line items with: sourceType, description, units, rate, cost, VAT
6. **Total Summary** — net amount, VAT, gross amount; currency (EUR)
7. **Actions** — "Save as Draft", "Preview PDF", "Post to AR"

#### Scenario: Admin Generates T&M Invoice

- **GIVEN** admin on Invoice Generator page
- **WHEN** they select billingModel=t_and_m, dateRange=May 1-31, rateCard=rate-consulting
- **THEN** component queries time entries and expenses for date range
- **AND** displays line items table with rates resolved from rate card
- **AND** shows total: €8,200 (T&M) + €200 (expenses) = €8,400; with VAT €1,764, total €10,164
- **AND** admin can "Save as Draft" to create invoice, or "Preview PDF" to see formatted invoice

#### Scenario: Admin Generates Fixed-Fee Invoice

- **GIVEN** admin selecting billingModel=fixed_fee
- **WHEN** they input flatFee=€50,000, select timeEntries (for audit), select expenses €1,000
- **THEN** component shows line items: [fixed-fee €50,000, expenses €1,000]; time entries are not shown as costs
- **AND** total = €51,000 + VAT €10,710 = €61,710

---

### Requirement: Invoice PDF Generation

Invoice PDF MUST be generated using a template with the following sections:

1. **Header** — creditor name, VAT, address; recipient name, VAT, address; invoiceNumber, invoiceDate, dueDate
2. **Line Items Table** — line number, description, quantity, unitPrice, amount, VAT rate
3. **Totals** — net, VAT (itemized by rate: 21%, 9%, 6%, 0%), gross
4. **Payment Terms** — payment conditions, IBAN, BIC
5. **Footer** — notes, legal terms, generated date

#### Scenario: Export Invoice to PDF

- **GIVEN** a posted Invoice with invoiceNumber=2026-001
- **WHEN** admin clicks "Export PDF"
- **THEN** PDF is generated with: creditor/recipient details, line items, VAT breakdown (21% on most, 0% on certain items if applicable), total €9,680
- **AND** PDF is named invoice-2026-001.pdf and downloadable

---

### Requirement: Deduplication and Conflict Detection

Before generating invoice, system MUST check for duplicate source IDs:

- `deduplicateSourceIds(timeEntryIds, expenseIds): ConflictReport` — returns list of timeEntryIds/expenseIds already referenced in posted/draft invoices in overlapping date ranges
- If conflicts found, draftInvoice() returns error with conflict details; no invoice is created

#### Scenario: Prevent Duplicate Time Entry

- **GIVEN** posted Invoice 2026-001 with timeEntryIds=[time-001, time-002]
- **AND** draft InvoiceGenerationRequest with timeEntryIds=[time-002, time-003]
- **WHEN** draftInvoice() is called
- **THEN** validation fails: "Conflict: time-002 is already invoiced in 2026-001"
- **AND** user must resolve conflict (cancel old invoice, or exclude time-002 from new invoice)

---

### Requirement: Retainer Schedule Integration

For retainer/mixed billing models, system MUST query RetainerSchedule and apply monthly retainer amount:

- `RetainerSchedule.monthlyAmount` — deducted from T&M overage (if applicable)
- `RetainerSchedule.effectiveDate` — determines which retainer amount applies (if schedule changes mid-project)
- Retainer month is stored in InvoiceLine.modelSpecificFields for audit trail

#### Scenario: Apply Monthly Retainer from Schedule

- **GIVEN** RetainerSchedule with monthlyAmount=€3,000, effectiveDate=2026-01-01
- **AND** invoice month=May 2026 (within effective date)
- **WHEN** postInvoice() is called with billingModel=retainer
- **THEN** line item is created: retainer €3,000 (month=May 2026)
- **AND** if retainer schedule changes (e.g., increases to €4,000), this historical invoice retains €3,000

---

### Requirement: VAT/BTW Compliance (Dutch)

Invoices MUST comply with Dutch VAT law:

- `Invoice.vatRate` is one of: 21% (standard), 9% (reduced), 6% (super-reduced), 0% (exempt)
- `Invoice.grossAmount = netAmount + (netAmount × vatRate)`
- Invoice displays VAT as "BTW" (Dutch term)
- Invoice includes creditor VAT number and recipient VAT number
- PDF invoice shows VAT breakdown per rate

#### Scenario: Invoice with Mixed VAT Rates

- **GIVEN** Invoice with line items:
  - Senior Consultant (21% VAT): €400 net → €484 gross
  - Books (6% VAT): €100 net → €106 gross
- **WHEN** invoice is totaled
- **THEN** total net = €500, total VAT = €84+€6 = €90, total gross = €590
- **AND** PDF shows VAT breakdown: 21% VAT €84, 6% VAT €6

---

## Cross-References

- **ADR-000**: Data model; references Invoice, InvoiceLine, TimeEntry, Expense, RateCard, RetainerSchedule, Obligation
- **obligation-financial-administration** (T2): provides Invoice and Obligation base schemas
- **rate-card-engine** (T2): provides RateCard register and rate lookup
- **retainer-billing-engine** (T2): provides RetainerSchedule register and retainer deduction logic

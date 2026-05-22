# Specs: Accounts Receivable (Core)

## REQ-001: Aged Receivables Report with Customer-Level Overdue Tracking

**Demand:** 167 (49 tender mentions) | **Category:** analytics

### REQ-001-001: Display AR aging by customer

GIVEN an administration with CustomerMaster records and ARInvoice records in various states (issued, overdue, paid)  
WHEN the finance officer opens the AR Aging report (`/apps/shillinq/ar-aging`)  
THEN the page displays a table grouped by customer name, with columns:
- Customer (legalName)
- 0–30 days: sum of outstanding invoices due within 30 days (EUR)
- 31–60 days: sum of outstanding invoices due 31–60 days ago (EUR)
- 61–90 days: sum of outstanding invoices due 61–90 days ago (EUR)
- 90+ days: sum of outstanding invoices due > 90 days ago (EUR)
- Total Outstanding (EUR)

ARInvoice records with `status` in [paid, written-off] are excluded from all calculations.

### REQ-001-002: Sort AR aging report by overdue amount (descending)

GIVEN the AR aging report is displayed  
WHEN the page loads  
THEN rows are sorted by total outstanding (90+ days column) in descending order, then by customer name (ascending).

### REQ-001-003: Filter AR aging by customer name

GIVEN the AR aging report is displayed  
WHEN the operator enters a customer name in the search/filter box  
THEN the table updates to show only customers matching the entered text (case-insensitive substring match on legalName), preserving sort order.

### REQ-001-004: Drill down from aging summary to customer invoice detail

GIVEN the AR aging report with customer rows  
WHEN the operator clicks on a customer name  
THEN the page navigates to the Accounts Receivable list page filtered to that customer (`/apps/shillinq/invoices?customer={{customerId}}`).

### REQ-001-005: Export AR aging report as CSV

GIVEN the AR aging report is displayed  
WHEN the operator clicks the "Export" button  
THEN a CSV file is downloaded with columns:
- Customer
- 0–30, 31–60, 61–90, 90+, Total Outstanding (EUR)
- Last updated (timestamp)

One row per customer shown on the report. File named `ar-aging-{{date}}.csv`.

## REQ-002: Accounts Aging Report Showing Overdue Invoices by Time Period

**Demand:** 125 (39 tender mentions) | **Category:** analytics

### REQ-002-001: Display invoices grouped by aging bucket

GIVEN ARInvoice records in various aging periods  
WHEN the finance officer views the Accounts Receivable list with aging column visible  
THEN invoices are colour-coded by aging bucket:
- Green: 0–30 days
- Yellow: 31–60 days
- Orange: 61–90 days
- Red: 90+ days

### REQ-002-002: Show invoice count and amount per aging bucket

GIVEN the Accounts Receivable list  
WHEN the operator opens the filter sidebar  
THEN a "Aging Bucket" facet shows:
- 0–30 days (count)
- 31–60 days (count)
- 61–90 days (count)
- 90+ days (count)

Clicking a bucket filters the list to that bucket.

### REQ-002-003: Sort invoices by due date (overdue first)

GIVEN the Accounts Receivable list  
WHEN the page loads  
THEN invoices are sorted by dueDate ascending (oldest/most overdue first).

## REQ-003: Accounts Receivable Aging Report with Customer-Level Detail

**Demand:** 102 (26 tender mentions) | **Category:** analytics

### REQ-003-001: View all outstanding invoices for a customer

GIVEN the Accounts Receivable list filtered to a specific customer  
WHEN the operator opens the page  
THEN a detail sidebar shows:
- Customer legalName, email, credit limit, payment terms
- List of outstanding invoices (status != paid, written-off) with invoice number, amount, due date, days overdue

### REQ-003-002: Highlight overdue invoices in detail view

GIVEN the customer detail sidebar is displayed  
WHEN invoices are listed  
THEN overdue invoices (dueDate < today) are highlighted in red with a "OVERDUE" badge.

## REQ-004: Outstanding Invoices Overview with Total Receivables

**Demand:** 90 (28 tender mentions) | **Category:** other

### REQ-004-001: Dashboard KPI card for total outstanding receivables

GIVEN a user with finance officer or admin role  
WHEN the user opens the Shillinq dashboard (`/apps/shillinq/`)  
THEN a KPI card is visible showing:
- **Total Outstanding:** sum of all ARInvoice.totalAmount where status != [paid, written-off]
- **Trend:** month-over-month percentage change (if data exists for previous month)
- **Overdue Count:** count of invoices where dueDate < today and status != [paid, written-off]

Card is colour-coded: green if < 20% overdue, yellow if 20–50% overdue, red if > 50% overdue.

### REQ-004-002: List outstanding invoices sorted by due date

GIVEN the Accounts Receivable list page  
WHEN the operator opens it without filters  
THEN all invoices with status != [paid, written-off] are displayed, sorted by dueDate (earliest first), with columns:
- Invoice #
- Customer
- Amount (EUR)
- Due Date
- Days Overdue
- Status

### REQ-004-003: Mark invoice as paid manually

GIVEN an ARInvoice in status [issued, overdue, disputed]  
WHEN the operator opens the invoice detail page and clicks "Mark as Paid"  
THEN a dialog appears asking for:
- Paid amount (default: totalAmount)
- Paid date (default: today)

Clicking "Confirm" transitions the invoice to status "paid", sets paidAmount and paidDate, and creates a GL posting for cash receipt.

### REQ-004-004: Bulk mark invoices as paid

GIVEN the Accounts Receivable list with multiple invoices selected  
WHEN the operator clicks "Mark as Paid" in the bulk actions bar  
THEN a dialog appears. On confirm, all selected invoices transition to paid with today's date.

## REQ-005: Aged Receivables Report with Customer-Level Aging Buckets

**Demand:** 90 (26 tender mentions) | **Category:** analytics

### REQ-005-001: Hover tooltip with aging bucket summary

GIVEN the AR aging report is displayed  
WHEN the operator hovers over a cell in the 0–30, 31–60, 61–90, or 90+ column  
THEN a tooltip appears showing:
- Number of invoices in that bucket
- Total amount in that bucket (EUR)
- List of invoice numbers (up to 5, with "...+N more" if > 5)

### REQ-005-002: Expand aging bucket to inline invoice list

GIVEN the AR aging report is displayed  
WHEN the operator clicks the expand icon (▶) on a customer row  
THEN the row expands to show all invoices in each aging bucket, with:
- Invoice #, Amount, Due Date, Days Overdue, Status (inline)

Clicking again collapses the row.

### REQ-005-003: Recalculate aging buckets nightly

GIVEN the aging report is generated  
WHEN a scheduled task runs at midnight UTC  
THEN the aging bucket calculations are refreshed (daysOverdue recomputed against today's date).

## REQ-006: Customer Master CRUD

**Domain requirement (not demand-driven, but essential foundation).**

### REQ-006-001: Create new customer

GIVEN a finance officer with edit permissions  
WHEN they open the Customers page and click "Add Customer"  
THEN a form dialog appears with fields:
- Customer Number (required)
- Legal Name (required)
- Tax ID (optional)
- Contact Person (optional)
- Email (optional, email format validation)
- Billing Address (optional)
- Payment Terms (optional, e.g., "Net 30")
- Credit Limit (optional, number ≥ 0)
- Status (required, default: "active")
- IBAN (optional, IBAN format validation)
- Notes (optional, text)

Clicking "Save" creates a CustomerMaster record with a generated UUID.

### REQ-006-002: Edit existing customer

GIVEN a customer detail page is open  
WHEN the operator clicks "Edit"  
THEN the form opens with all fields pre-filled. Changes are saved to the CustomerMaster record on submit.

### REQ-006-003: Delete customer (with validation)

GIVEN a customer detail page is open  
WHEN the operator clicks "Delete"  
THEN a confirmation dialog appears with text:
"Delete this customer? All linked invoices and dunning records will remain; customer will be marked inactive."

Clicking "Delete" marks the customer status as "inactive" (soft delete).

## REQ-007: AR Invoice Lifecycle & Transitions

**Domain requirement (declarative via x-openregister-lifecycle).**

### REQ-007-001: Create invoice in draft status

GIVEN a customer detail page  
WHEN the operator clicks "Add Invoice"  
THEN an ARInvoice is created with:
- status: "draft"
- invoiceNumber: auto-generated (e.g., "INV-2026-0042")
- invoiceDate: today
- dueDate: invoiceDate + customer.paymentTerms (e.g., +30 days)
- customerId: the selected customer

### REQ-007-002: Issue invoice (draft → issued)

GIVEN an ARInvoice in status "draft"  
WHEN the operator clicks "Issue"  
THEN a dialog asks for:
- sourceDocumentUri (optional, file upload or docudesk reference)

Clicking "Confirm":
- Transitions status to "issued"
- Creates a GL posting (Debit AR account, Credit Revenue account)
- Status now appears in lists and aging reports

Prerequisite: outstanding amount (including this new invoice) must not exceed customer credit limit.

### REQ-007-003: Mark invoice as overdue (automatic or manual)

GIVEN an ARInvoice with status "issued" and dueDate < today  
WHEN a scheduled task runs (or operator manually triggers)  
THEN status transitions to "overdue" if not already paid.

Display: invoice appears in red in lists; dunning escalation can begin.

### REQ-007-004: Mark invoice as paid

GIVEN an ARInvoice in status [issued, overdue]  
WHEN the operator clicks "Mark as Paid" and confirms  
THEN:
- status transitions to "paid"
- paidAmount and paidDate are recorded
- A GL posting is created (Debit Bank account, Credit AR account)

### REQ-007-005: Dispute invoice (issued/overdue → disputed)

GIVEN an ARInvoice in status [issued, overdue]  
WHEN the operator clicks "Mark as Disputed"  
THEN:
- status transitions to "disputed"
- A note field appears for customer dispute reason
- Dunning is paused (no further escalation)

### REQ-007-006: Resolve dispute (disputed → issued or written-off)

GIVEN a disputed ARInvoice  
WHEN the operator resolves the dispute by:
- Accepting the invoice → status back to "issued", resume dunning
- Writing off → status "written-off", create compensating GL posting

### REQ-007-007: Write off invoice

GIVEN an ARInvoice in any status except "paid" or "written-off"  
WHEN the operator clicks "Write Off"  
THEN a dialog asks for:
- Write-off reason (required, free text)

Clicking "Confirm":
- status transitions to "written-off"
- A GL posting is created (Debit Bad Debt Expense account, Credit AR account)
- Dunning stops; customer is notified (if configured)

## REQ-008: Dunning Workflow Integration

**Domain requirement (consumes OpenRegister dunning-workflow extension).**

### REQ-008-001: Create dunning record on invoice overdue

GIVEN an ARInvoice transitions to status "overdue"  
WHEN the dunning policy is configured for the customer  
THEN a DunningRecord is automatically created with:
- level: 1 (first reminder)
- status: "pending"
- sentDate: today
- dueDate: today + 7 days (or per policy)

### REQ-008-002: View dunning escalation history

GIVEN a Dunning Log list page  
WHEN the operator filters by a specific invoice  
THEN all DunningRecord entries for that invoice are displayed, ordered by level (ascending).

Each row shows: Dunning #, Level, Sent Date, Status, Notes.

### REQ-008-003: Escalate dunning level

GIVEN a DunningRecord in status "pending" and dueDate has passed  
WHEN a scheduled task runs (or operator manually escalates)  
THEN:
- A new DunningRecord is created with level = current level + 1
- Previous record status transitions to "escalated"
- New record inherits customer dunning policy settings for dueDate and message

### REQ-008-004: Mark dunning as acknowledged

GIVEN a DunningRecord in status "pending"  
WHEN the operator clicks "Mark Acknowledged"  
THEN status transitions to "acknowledged", pausing further escalation.

### REQ-008-005: Cancel dunning (on invoice paid or written-off)

GIVEN one or more DunningRecord entries for an invoice  
WHEN the invoice transitions to status [paid, written-off]  
THEN all linked DunningRecord entries with status != [paid, cancelled] transition to status "cancelled".

## REQ-009: Payment Matching (Bank Reconciliation Integration)

**Depends on: bookkeeping-bank-reconciliation spec.**

### REQ-009-001: Accept payment match suggestion

GIVEN the bank reconciliation module emits a candidate match (ARInvoice + bank statement line with matching amount and approximate date)  
WHEN the operator opens the reconciliation UI and reviews the suggestion  
THEN clicking "Accept" performs:
- ARInvoice status transitions to "paid"
- paidDate set to bank statement line date
- paidAmount set to statement line amount
- A GL posting is created (Debit Bank, Credit AR)
- Any linked DunningRecord entries are cancelled

### REQ-009-002: Reject payment match

GIVEN a payment match suggestion is displayed  
WHEN the operator clicks "Reject"  
THEN the suggestion is discarded; ARInvoice remains in status [issued, overdue].

The reconciliation system continues to search for a different match.

### REQ-009-003: Partial payment matching

GIVEN an ARInvoice with totalAmount = €1,000 and a bank statement line for €500  
WHEN the operator accepts the partial match  
THEN:
- paidAmount is recorded as €500
- status remains "overdue" (invoice not fully paid)
- A GL posting is created (Debit Bank €500, Credit AR €500)
- Outstanding balance = €500, displayed in aging reports as owed

## REQ-010: Search & Filters

**Foundation (leverages OpenRegister IndexService).**

### REQ-010-001: Full-text search on Accounts Receivable list

GIVEN the Accounts Receivable list  
WHEN the operator enters text in the search box  
THEN results include invoices matching:
- Invoice number (partial match)
- Customer name (partial match)
- Notes (partial match)

Results are weighted: exact match > prefix match > substring match.

### REQ-010-002: Filter by status

GIVEN the filter sidebar  
WHEN the operator clicks the "Status" facet  
THEN options appear: draft, issued, paid, overdue, disputed, written-off.
Selecting one or more filters the list to invoices with those statuses.

### REQ-010-003: Filter by customer

GIVEN the filter sidebar  
WHEN the operator clicks the "Customer" facet  
THEN a list of all CustomerMaster records appears. Selecting one filters invoices to that customer.

### REQ-010-004: Filter by amount range

GIVEN the filter sidebar  
WHEN the operator clicks the "Amount" facet  
THEN sliders appear for min/max. Filtering is applied on ARInvoice.totalAmount.

### REQ-010-005: Filter by date range

GIVEN the filter sidebar  
WHEN the operator clicks the "Due Date" facet  
THEN date pickers appear for start/end date. Filtering is applied on ARInvoice.dueDate.

## REQ-011: Permissions & Authorization

**Foundation (leverages OpenRegister RBAC).**

### REQ-011-001: Finance Officer role can issue/mark-paid/write-off

GIVEN a user with Finance Officer role  
WHEN they access an ARInvoice or CustomerMaster  
THEN they can:
- Create, edit, delete customers
- Create, issue, mark-paid, dispute, write-off invoices
- Escalate dunning, acknowledge dunning
- Export AR aging / outstanding invoices

### REQ-011-002: Accounting Manager role can configure

GIVEN a user with Accounting Manager (admin) role  
WHEN they access Settings → AR Configuration  
THEN they can:
- Define dunning policies (levels, delays, messages)
- Configure aging bucket thresholds
- Set default payment terms
- Configure GL account mappings (AR → Bank → Expense)

### REQ-011-003: Read-only Viewer role

GIVEN a user with Viewer role  
WHEN they access the app  
THEN they can:
- View all lists and reports (AR Aging, Outstanding, Dunning Log)
- View customer and invoice details
- Export reports
- They cannot: create, edit, delete, or transition invoices

## REQ-012: Integrations

### REQ-012-001: GL account mapping

GIVEN an ARInvoice is issued or marked paid  
WHEN a GL posting is created  
THEN the posting uses configured GL account mappings:
- Debit AR account (configured, default: 1300 per RGS)
- Credit Revenue account (configured, default: 4000 per RGS) on issue
- Debit Bank account (configured, e.g., 1100) on payment

### REQ-012-002: Document attachment via docudesk

GIVEN an ARInvoice detail page  
WHEN the operator clicks "Attach Invoice PDF"  
THEN a file picker opens; selecting a file stores the docudesk URI in ARInvoice.sourceDocumentUri.

The PDF is downloadable from the invoice detail page.

## REQ-013: Reporting & Export

### REQ-013-001: Export Accounts Receivable list as CSV

GIVEN the Accounts Receivable list (filtered or unfiltered)  
WHEN the operator clicks "Export" → "CSV"  
THEN a CSV file is downloaded with columns:
- Invoice #, Customer, Amount, Due Date, Days Overdue, Status, Paid Date

### REQ-013-002: Export AR Aging report as Excel

GIVEN the AR Aging report  
WHEN the operator clicks "Export" → "Excel"  
THEN an XLSX file is downloaded with:
- One sheet: AR Aging (customer × aging bucket grid)
- One sheet: Invoice Detail (all invoices included in aging calculation)
- Metadata: generated date, administration name

### REQ-013-003: Email AR Aging to stakeholders

GIVEN the AR Aging report  
WHEN the operator clicks "Email Report"  
THEN a dialog appears asking for:
- Email recipient(s) (required)
- Include period (e.g., "Weekly on Monday")

Clicking "Send" queues a background job to generate and email the report on schedule.

## REQ-014: Performance & Scalability

### REQ-014-001: Aging report with 5,000+ invoices

GIVEN an administration with 5,000+ outstanding invoices  
WHEN the AR Aging report is loaded  
THEN the page displays in ≤ 2 seconds (via indexed aggregation query).

### REQ-014-002: Pagination on Accounts Receivable list

GIVEN > 100 invoices  
WHEN the list is loaded  
THEN:
- Default page size: 50 invoices
- Pagination control at bottom with prev/next/goto-page
- User can change page size (25, 50, 100, 250)

### REQ-014-003: Lazy-load invoice details

GIVEN an ARInvoice detail page  
WHEN the page is accessed  
THEN related objects (customer, dunning records, GL postings) load asynchronously via separate API calls, showing loading spinners.

## REQ-015: Audit & Compliance

### REQ-015-001: Audit trail on invoice state transitions

GIVEN an ARInvoice undergoing a state change (draft → issued, issued → paid, etc.)  
WHEN the transition occurs  
THEN an audit trail entry is automatically created with:
- timestamp
- user ID (from IUserSession, not frontend)
- action (e.g., "issued", "marked-paid")
- old status, new status
- changes to financial fields (amount, dueDate)

### REQ-015-002: Audit trail on write-off

GIVEN an ARInvoice transitioning to "written-off"  
WHEN the operator provides a write-off reason in the dialog  
THEN the audit trail entry includes:
- reason field (operator-provided)
- GL posting reference (for compensating entry)

Audit trail is read-only and not editable by any role.

### REQ-015-003: GDPR data retention

GIVEN an ARInvoice marked "written-off" or "paid" for > 7 years  
WHEN a retention policy task runs  
THEN the invoice may be scheduled for deletion (per GDPR/BTW retention rules).

Deletion is subject to legal hold (if active) and must be logged for compliance.

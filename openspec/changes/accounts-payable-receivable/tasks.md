# Tasks: accounts-payable-receivable

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `account` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `account` MUST be registered with all properties from the data model
    - AND `accountNumber`, `name`, `accountType`, `isActive`, `normalBalance` MUST be marked required
    - AND `accountType` MUST have enum `["asset","liability","equity","revenue","expense"]`
    - AND `normalBalance` MUST have enum `["debit","credit"]`
    - AND `isActive` MUST be type boolean with default `true`
    - AND `isBankAccount` MUST be type boolean with default `false`
    - AND `x-schema-org` annotation MUST be `schema:FinancialProduct`

- [ ] 1.2 Add `contact` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-002`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `contact` MUST exist with `displayName` (required), `contactType` (required), `isCompany` (required)
    - AND `contactType` MUST have enum `["customer","supplier","both","employee","other"]`
    - AND `isCompany` MUST be type boolean with default `false`
    - AND `defaultPaymentTermDays` MUST be type integer with default `30`
    - AND `country` MUST have default `"NL"`
    - AND `x-schema-org` MUST be `schema:Person`

- [ ] 1.3 Add `invoice` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-003`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `invoice` MUST exist with `invoiceNumber`, `invoiceType`, `invoiceDate`, `dueDate`, `subtotal`, `taxAmount`, `totalAmount`, `currency`, `paymentStatus` (all required)
    - AND `invoiceType` MUST have enum `["sales","purchase","credit","proforma"]`
    - AND `paymentStatus` MUST have enum `["draft","sent","viewed","partial","paid","overdue","void"]` with default `"draft"`
    - AND `matchStatus` MUST have enum `["unmatched","two-way-matched","three-way-matched","mismatch","partial"]` with default `"unmatched"`
    - AND `currency` MUST have default `"EUR"`
    - AND `exchangeRate` MUST be type number with default `1.0`
    - AND `matchTolerance` MUST be type number with default `2.0`
    - AND `ublFormat` MUST be type boolean with default `false`
    - AND `x-schema-org` MUST be `schema:Invoice`

- [ ] 1.4 Add `invoiceLine` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `invoiceLine` MUST exist with `lineNumber` (required), `description` (required), `quantity` (required), `unitPrice` (required), `lineTotal` (required)
    - AND `taxRate` MUST be type number with default `21.0`
    - AND `x-schema-org` MUST be `schema:OrderItem`

- [ ] 1.5 Add `journalEntry` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `journalEntry` MUST exist with `entryNumber`, `entryDate`, `description`, `entryType`, `totalDebit`, `totalCredit`, `posted`, `fiscalYear`, `period` (all required)
    - AND `entryType` MUST have enum `["manual","auto","reversal","closing"]`
    - AND `posted` MUST be type boolean with default `false`
    - AND `x-schema-org` MUST be `schema:Action`

- [ ] 1.6 Add `journalLine` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-006`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `journalLine` MUST exist with `accountCode` (required)
    - AND `debitAmount` MUST be type number with default `0`
    - AND `creditAmount` MUST be type number with default `0`
    - AND `x-schema-org` MUST be `schema:MonetaryAmount`

- [ ] 1.7 Add `payment` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `payment` MUST exist with `paymentRequestId`, `amount`, `currency`, `status`, `createdAt` (all required)
    - AND `status` MUST have enum `["pending","approved","rejected","completed"]` with default `"pending"`
    - AND `currency` MUST have default `"EUR"`
    - AND `x-schema-org` MUST be `schema:Order`

- [ ] 1.8 Add `paymentTerm` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-008`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `paymentTerm` MUST exist with `name` (required), `daysDue` (required)
    - AND `isDefault` MUST be type boolean with default `false`
    - AND `daysDue` MUST be type number with minimum `0`

- [ ] 1.9 Add `receipt` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-009`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `receipt` MUST exist with `amount` (required), `date` (required), `vendor` (required), `status` (required)
    - AND `status` MUST have enum `["pending","verified","rejected"]` with default `"pending"`
    - AND `receiptType` MUST have enum `["goods","services"]` with default `"goods"`
    - AND `uploadMethod` MUST have enum `["mobile","web","email"]` with default `"web"`
    - AND `filePath` MUST be type file
    - AND `x-schema-org` MUST be `schema:Receipt`

## 2. PHP Backend — Services

- [ ] 2.1 Implement `UblIngestionService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-010`
  - **files**: `lib/Service/UblIngestionService.php`
  - **acceptance_criteria**:
    - GIVEN a valid UBL 2.1 XML document is passed to `ingest(string $xml)`
    - THEN an `Invoice` object is created via `ObjectService` with header fields extracted from `cbc:ID`, `cbc:IssueDate`, `cac:AccountingSupplierParty`, `cac:LegalMonetaryTotal`
    - AND for each `cac:InvoiceLine` element an `InvoiceLine` object is created
    - AND if `cac:OrderReference/cbc:ID` is present `Invoice.poReference` is set and `InvoiceMatchingService::twoWayMatch()` is called immediately
    - GIVEN the XML fails DOMDocument schema validation
    - THEN the document is stored as a `Document` object with `status: quarantine` and `failureReason` set
    - AND the AP clerk group receives a Nextcloud notification with the validation error message
    - AND no `Invoice` object is created

- [ ] 2.2 Implement `InvoiceMatchingService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-011`
  - **files**: `lib/Service/InvoiceMatchingService.php`
  - **acceptance_criteria**:
    - GIVEN `twoWayMatch(string $invoiceId)` is called and a `PurchaseOrder` with matching `poNumber`, `supplierId`, and `totalAmount` within `matchTolerance` exists
    - THEN `Invoice.matchStatus` is set to `two-way-matched`
    - GIVEN the amount exceeds tolerance
    - THEN `Invoice.matchStatus` is set to `mismatch` and the invoice is assigned to the AP clerk queue with reason code `AMOUNT_MISMATCH`
    - GIVEN the PO reference does not exist
    - THEN `Invoice.matchStatus` is set to `unmatched` with reason code `PO_NOT_FOUND`
    - GIVEN `threeWayMatch(string $invoiceId)` is called after two-way match succeeds
    - THEN `Receipt` objects linked to the PO are fetched and total verified quantity/amount compared to invoiced amount
    - AND on full match `Invoice.matchStatus` is set to `three-way-matched` and invoice is added to payment queue
    - AND on partial match `Invoice.matchStatus` is set to `partial` and remainder is held

- [ ] 2.3 Implement `CreditNoteService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-012`
  - **files**: `lib/Service/CreditNoteService.php`
  - **acceptance_criteria**:
    - GIVEN `createCreditNote(string $originalInvoiceId)` is called on a `posted` or `sent` invoice
    - THEN a new `Invoice` is created with `invoiceType: credit`, `creditedInvoiceId: {originalInvoiceId}`, and all `InvoiceLine.unitPrice` values negated
    - AND a reversal `JournalEntry` is created with `entryType: reversal` where original debit lines become credit lines and vice versa
    - AND if the credit note covers the full original invoice amount `Invoice.paymentStatus` is set to `void` on the original
    - GIVEN the original invoice is in `draft` state
    - THEN the API returns HTTP 422 "Cannot create credit note for a draft invoice"

- [ ] 2.4 Implement `ApprovalRoutingService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-013`
  - **files**: `lib/Service/ApprovalRoutingService.php`
  - **acceptance_criteria**:
    - GIVEN `routeForApproval(string $invoiceId)` is called and `Invoice.totalAmount > clerkApprovalLimit` from `AppSettings`
    - THEN an `ApprovalWorkflow` entry is created for the `Budget.ownerId` of the coded cost centre
    - AND a Nextcloud notification is sent to the budget holder with invoice details, PO reference, and current budget position
    - GIVEN `ApprovalEscalationJob` runs and finds an `ApprovalWorkflow` older than 5 working days with `status: pending`
    - THEN a Nextcloud notification is sent to `Budget.lineManagerId` with subject "Escalatie: factuurgoedkeuring wacht al 5 werkdagen"
    - AND the escalation event is appended to `ApprovalWorkflow.auditLog`

- [ ] 2.5 Implement `PaymentTermService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-008`
  - **files**: `lib/Service/PaymentTermService.php`
  - **acceptance_criteria**:
    - GIVEN `calculateDueDate(string $invoiceDate, string $paymentTermId)` is called
    - THEN the `PaymentTerm.daysDue` is fetched and `dueDate = invoiceDate + daysDue` is returned as ISO 8601 datetime
    - GIVEN the `Contact` linked to the invoice has `defaultPaymentTermDays` set and no explicit `paymentTermId` is passed
    - THEN `daysDue = Contact.defaultPaymentTermDays` is used as fallback

- [ ] 2.6 Implement `R2PService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-014`
  - **files**: `lib/Service/R2PService.php`
  - **acceptance_criteria**:
    - GIVEN `initiate(string $invoiceId)` is called on an approved invoice
    - THEN `Invoice.contactId` is resolved, `Contact.iban` is fetched, and an R2P payload is constructed
    - AND a `Payment` object is created with `status: pending` and `paymentRequestId` from the open banking response
    - AND the Nextcloud notification confirms the R2P has been sent with expiry date
    - GIVEN the open banking webhook receives a completion event
    - THEN `Payment.status` is set to `completed` and `Invoice.paymentStatus` is set to `paid`

- [ ] 2.7 Implement `TimesheetVerificationService`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-015`
  - **files**: `lib/Service/TimesheetVerificationService.php`
  - **acceptance_criteria**:
    - GIVEN `verify(string $receiptId)` is called on a `Receipt` with `receiptType: services`
    - THEN the timesheet referenced by `Receipt.timesheetId` is fetched
    - AND `verifiedHours × verifiedRate` is compared to `Invoice.totalAmount` within `Invoice.matchTolerance`
    - AND on match `Receipt.status` is set to `verified`
    - AND on mismatch `Receipt.status` is set to `rejected` and the AP clerk receives a notification with the hour/rate discrepancy

## 3. PHP Backend — Controllers

- [ ] 3.1 Implement `InvoiceController`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-003`
  - **files**: `lib/Controller/InvoiceController.php`
  - **acceptance_criteria**:
    - `POST /api/v1/invoices/{id}/post` — posts a draft invoice: validates lines total = header total, creates `JournalEntry`, sets `paymentStatus: sent`
    - `POST /api/v1/invoices/{id}/credit-note` — calls `CreditNoteService::createCreditNote()`; returns new credit note invoice object
    - `POST /api/v1/invoices/{id}/reject` — sets `paymentStatus: void`, stores `rejectionReason`, sends notification to linked supplier `Contact`
    - `POST /api/v1/invoices/{id}/approve` — budget holder approves: updates `ApprovalWorkflow.status: approved`, moves invoice to payment queue
    - All endpoints return HTTP 403 if the caller does not have the `invoicing` role

- [ ] 3.2 Implement `MatchingController`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-011`
  - **files**: `lib/Controller/MatchingController.php`
  - **acceptance_criteria**:
    - `POST /api/v1/invoices/{id}/match/two-way` — triggers `InvoiceMatchingService::twoWayMatch()`; returns updated `Invoice` object
    - `POST /api/v1/invoices/{id}/match/three-way` — triggers `InvoiceMatchingService::threeWayMatch()`; returns updated `Invoice` object
    - Both endpoints require `invoicing` role

- [ ] 3.3 Implement `UblController`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-010`
  - **files**: `lib/Controller/UblController.php`
  - **acceptance_criteria**:
    - `POST /api/v1/ubl/ingest` — accepts raw UBL XML body, validates `X-Shillinq-Token` header, calls `UblIngestionService::ingest()`
    - Returns HTTP 201 with created `Invoice` object on success
    - Returns HTTP 422 with validation error on schema failure
    - Returns HTTP 401 if token is missing or invalid

- [ ] 3.4 Implement `PaymentController`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-014`
  - **files**: `lib/Controller/PaymentController.php`
  - **acceptance_criteria**:
    - `POST /api/v1/payments/initiate` with `{invoiceId}` — calls `R2PService::initiate()`; returns `Payment` object
    - `POST /api/v1/payments/webhook` — receives open banking status callback, calls `R2PService::handleWebhook()`
    - Webhook endpoint validates HMAC signature from `AppSettings.r2pWebhookSecret`

- [ ] 3.5 Implement `AccountContextController`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-001`
  - **files**: `lib/Controller/AccountContextController.php`
  - **acceptance_criteria**:
    - `GET /api/v1/account-context` — returns list of `Account` objects accessible to the current user (max 10)
    - `POST /api/v1/account-context/switch` with `{accountId}` — validates the user has `AccessControl` for the account, stores active account in user session, logs switch to `AuditTrail`
    - Returns HTTP 403 if the user is not authorised for the requested account

## 4. Background Jobs

- [ ] 4.1 Implement `InvoiceOverdueJob`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-003`
  - **files**: `lib/BackgroundJob/InvoiceOverdueJob.php`
  - **acceptance_criteria**:
    - GIVEN the job runs daily
    - THEN all `Invoice` objects where `dueDate < today` and `paymentStatus` IN `["sent","partial"]` are fetched
    - AND each invoice's `paymentStatus` is set to `overdue`
    - AND a Nextcloud notification is sent to the assigned AP clerk: "Factuur {invoiceNumber} is vervallen per {dueDate}"

- [ ] 4.2 Implement `ApprovalEscalationJob`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-013`
  - **files**: `lib/BackgroundJob/ApprovalEscalationJob.php`
  - **acceptance_criteria**:
    - GIVEN the job runs daily
    - THEN `ApprovalWorkflow` objects where `entityType: invoice`, `status: pending`, `createdAt < today minus 5 working days` are fetched
    - AND `ApprovalRoutingService::escalate()` is called for each
    - AND the escalation is recorded in the workflow `auditLog` with timestamp

- [ ] 4.3 Implement `PeppolIngestJob`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-010`
  - **files**: `lib/BackgroundJob/PeppolIngestJob.php`
  - **acceptance_criteria**:
    - GIVEN the job runs every 15 minutes
    - THEN the configured Peppol access point inbox URL (from `AppSettings.peppolInboxUrl`) is polled
    - AND each new UBL message is passed to `UblIngestionService::ingest()`
    - AND `AppSettings.peppolLastPolledAt` is updated to the current timestamp after each run
    - AND HTTP errors from the Peppol endpoint are logged and do not crash the job

## 5. Frontend — Pinia Stores

- [ ] 5.1 Create `src/store/modules/account.js`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-001`
  - **files**: `src/store/modules/account.js`
  - **acceptance_criteria**:
    - Uses `createObjectStore('account', { ... })` from `@conduction/nextcloud-vue`
    - Exposes `list`, `current`, `fetch`, `create`, `update`, `delete` actions
    - `fetch` scopes to active account context from `accountContext` store

- [ ] 5.2 Create `src/store/modules/accountContext.js`
  - **spec_ref**: `specs/accounts-payable-receivable/spec.md#REQ-APR-001`
  - **files**: `src/store/modules/accountContext.js`
  - **acceptance_criteria**:
    - Stores `activeAccountId` (string) and `availableAccounts` (array, max 10)
    - `switchAccount(accountId)` calls `AccountContextController` and updates `activeAccountId`
    - All entity stores import and use `activeAccountId` as a query filter

- [ ] 5.3 Create `src/store/modules/contact.js`
  - **files**: `src/store/modules/contact.js`
  - **acceptance_criteria**:
    - Uses `createObjectStore('contact', { ... })`
    - Supports filter by `contactType` (customer / supplier)

- [ ] 5.4 Create `src/store/modules/invoice.js`
  - **files**: `src/store/modules/invoice.js`
  - **acceptance_criteria**:
    - Uses `createObjectStore('invoice', { ... })`
    - Exposes `postInvoice(id)`, `createCreditNote(id)`, `rejectInvoice(id, reason)`, `approveInvoice(id)` actions calling respective OCS endpoints

- [ ] 5.5 Create `src/store/modules/invoiceLine.js`
  - **files**: `src/store/modules/invoiceLine.js`

- [ ] 5.6 Create `src/store/modules/journalEntry.js`
  - **files**: `src/store/modules/journalEntry.js`

- [ ] 5.7 Create `src/store/modules/journalLine.js`
  - **files**: `src/store/modules/journalLine.js`

- [ ] 5.8 Create `src/store/modules/payment.js`
  - **files**: `src/store/modules/payment.js`
  - **acceptance_criteria**:
    - Exposes `initiateR2P(invoiceId)` action calling `PaymentController@initiate`

- [ ] 5.9 Create `src/store/modules/paymentTerm.js`
  - **files**: `src/store/modules/paymentTerm.js`

- [ ] 5.10 Create `src/store/modules/receipt.js`
  - **files**: `src/store/modules/receipt.js`

## 6. Frontend — Views

- [ ] 6.1 Create Account views (Index, Detail, Form)
  - **files**: `src/views/account/AccountIndex.vue`, `src/views/account/AccountDetail.vue`, `src/views/account/AccountForm.vue`
  - **acceptance_criteria**:
    - `AccountIndex.vue` uses `CnIndexPage` with `columnsFromSchema('account')`
    - `AccountDetail.vue` uses `CnDetailPage` with fields and related objects panel
    - `AccountForm.vue` uses `CnFormDialog` with `fieldsFromSchema('account')`

- [ ] 6.2 Create Contact views (Index, Detail, Form)
  - **files**: `src/views/contact/ContactIndex.vue`, `src/views/contact/ContactDetail.vue`, `src/views/contact/ContactForm.vue`
  - **acceptance_criteria**:
    - Index includes filter tabs for Customers / Suppliers / All using `filtersFromSchema('contact')`
    - Detail shows linked invoices and payment terms

- [ ] 6.3 Create Invoice views (Index, Detail, Form)
  - **files**: `src/views/invoice/InvoiceIndex.vue`, `src/views/invoice/InvoiceDetail.vue`, `src/views/invoice/InvoiceForm.vue`
  - **acceptance_criteria**:
    - Index shows `paymentStatus` badge and `matchStatus` badge per invoice
    - Detail embeds `InvoiceMatchPanel` showing PO, receipt, and match result
    - Detail shows `CreditNoteButton` if `invoiceType: sales` and `paymentStatus: sent` or `posted`
    - Detail shows `R2PInitiateButton` if invoice is approved and `paymentStatus` is not `paid`
    - Form calculates `dueDate` automatically when `paymentTermId` is selected

- [ ] 6.4 Create InvoiceLine views (Index, Detail, Form)
  - **files**: `src/views/invoiceLine/InvoiceLineIndex.vue`, `src/views/invoiceLine/InvoiceLineDetail.vue`, `src/views/invoiceLine/InvoiceLineForm.vue`
  - **acceptance_criteria**:
    - Form shows `BudgetWarningBanner` if coded amount causes cost centre overspend

- [ ] 6.5 Create JournalEntry views (Index, Detail, Form)
  - **files**: `src/views/journalEntry/JournalEntryIndex.vue`, `src/views/journalEntry/JournalEntryDetail.vue`, `src/views/journalEntry/JournalEntryForm.vue`
  - **acceptance_criteria**:
    - Detail shows `totalDebit` vs `totalCredit` balance check; red badge if unbalanced

- [ ] 6.6 Create JournalLine views (Index, Detail, Form)
  - **files**: `src/views/journalLine/JournalLineIndex.vue`, `src/views/journalLine/JournalLineDetail.vue`, `src/views/journalLine/JournalLineForm.vue`

- [ ] 6.7 Create Payment views (Index, Detail, Form)
  - **files**: `src/views/payment/PaymentIndex.vue`, `src/views/payment/PaymentDetail.vue`, `src/views/payment/PaymentForm.vue`
  - **acceptance_criteria**:
    - Index shows R2P status badge (pending / approved / completed) per payment request

- [ ] 6.8 Create PaymentTerm views (Index, Detail, Form)
  - **files**: `src/views/paymentTerm/PaymentTermIndex.vue`, `src/views/paymentTerm/PaymentTermDetail.vue`, `src/views/paymentTerm/PaymentTermForm.vue`
  - **acceptance_criteria**:
    - Index shows `isDefault` badge
    - Seed data includes Net 14, Net 30, Net 60, Net 90, Direct terms

- [ ] 6.9 Create Receipt views (Index, Detail, Form)
  - **files**: `src/views/receipt/ReceiptIndex.vue`, `src/views/receipt/ReceiptDetail.vue`, `src/views/receipt/ReceiptForm.vue`
  - **acceptance_criteria**:
    - Form shows `timesheetId`, `verifiedHours`, `verifiedRate` fields only when `receiptType: services`
    - Detail shows verification status badge

## 7. Frontend — Shared Components

- [ ] 7.1 Implement `InvoiceMatchPanel`
  - **files**: `src/components/InvoiceMatchPanel.vue`
  - **acceptance_criteria**:
    - Shows `matchStatus` badge, PO details, receipt details, tolerance value, mismatch reason if applicable
    - "Run Two-Way Match" and "Run Three-Way Match" buttons trigger respective store actions
    - Disabled if invoice is `void` or `paid`

- [ ] 7.2 Implement `CreditNoteButton`
  - **files**: `src/components/CreditNoteButton.vue`
  - **acceptance_criteria**:
    - Single button that opens `InvoiceForm.vue` pre-populated with negated lines and `invoiceType: credit`
    - Shows confirmation dialog before creating credit note
    - Disabled if original invoice is not in `sent` or `posted` state

- [ ] 7.3 Implement `AccountSwitcher`
  - **files**: `src/components/AccountSwitcher.vue`
  - **acceptance_criteria**:
    - `NcSelect` populated from `accountContext.availableAccounts` (max 10)
    - On selection calls `accountContext.switchAccount()` and refreshes all active entity lists
    - Displays active account name in top navigation bar

- [ ] 7.4 Implement `R2PInitiateButton`
  - **files**: `src/components/R2PInitiateButton.vue`
  - **acceptance_criteria**:
    - Visible only on approved invoices with `paymentStatus` not `paid`
    - Calls `payment.initiateR2P(invoiceId)` and shows success/failure toast
    - Displays pending R2P status badge after initiation

- [ ] 7.5 Implement `BudgetWarningBanner`
  - **files**: `src/components/BudgetWarningBanner.vue`
  - **acceptance_criteria**:
    - Shown in `InvoiceLineForm.vue` when `lineTotal + existingCostCentreSpend > budgetApprovedAmount`
    - Non-blocking warning: user can still save with confirmation
    - Override requires `budget-manager` role (shows additional override confirmation if clerk)

## 8. Navigation & Router

- [ ] 8.1 Register entity routes in Vue Router
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - Routes registered for all 9 entity index and detail views
    - Route names: `accounts`, `contacts`, `invoices`, `invoice-lines`, `journal-entries`, `journal-lines`, `payments`, `payment-terms`, `receipts`

- [ ] 8.2 Add navigation items to sidebar
  - **files**: `src/components/Navigation.vue`
  - **acceptance_criteria**:
    - Navigation section "Crediteuren & Debiteuren" added with links to Invoices, Contacts, Payments, Receipts
    - Navigation section "Grootboek" added with links to Journal Entries
    - Navigation section "Instellingen" includes PaymentTerms and Accounts

## 9. Admin Settings

- [ ] 9.1 Add AP/AR settings to admin panel
  - **files**: `lib/Settings/Admin.php`, `src/views/settings/AdminSettings.vue`
  - **acceptance_criteria**:
    - `clerkApprovalLimit` — number field (default 5000); invoices above this require budget holder approval
    - `matchTolerance` — number field in % (default 2.0); used as global default for invoice matching
    - `peppolInboxUrl` — string field for Peppol access point inbox endpoint
    - `peppolLastPolledAt` — read-only datetime showing last successful Peppol poll
    - `r2pWebhookSecret` — password field for validating open banking webhook HMAC signatures
    - `r2pEndpointUrl` — string field for the open banking R2P API endpoint

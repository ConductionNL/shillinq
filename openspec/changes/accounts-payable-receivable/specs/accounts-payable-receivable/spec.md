---
status: proposed
---

# Accounts Payable & Receivable — Shillinq

## Purpose

Defines functional requirements for Shillinq's accounts payable and receivable capabilities: multi-entity account management, customer and supplier contact management with payment terms, full invoice lifecycle (sales, purchase, credit, proforma), automated two-way and three-way invoice matching, UBL/Peppol e-invoice ingestion, invoice line coding to GL account and cost centre, budget-holder approval routing with escalation, credit note creation linked to original invoices, supplier credit note processing, Request to Pay (R2P) open banking payment initiation, service receipt confirmation with timesheet-based verification, and payment term configuration (Net 14/30/60/90).

Stakeholders: Accounts Payable Clerk, Municipal Supplier.

User stories addressed: Match invoice to purchase order automatically, Perform three-way match with goods receipt, Code invoice lines to cost centre and general ledger account, Route invoice to budget holder for approval, Process incoming UBL/PEPPOL e-invoice.

## Requirements

### REQ-APR-001: Multi-Account Context with Up to 10 Business Accounts [must]

The app MUST support up to 10 `Account` objects per Nextcloud user, controlled via `AccessControl` assignments. A global account context store MUST track the active account and scope all entity queries to it. An `AccountSwitcher` component in the top navigation MUST allow the user to switch the active account without logging out. All account context switches MUST be logged to `AuditTrail`.

**Scenarios:**

1. **GIVEN** a user has 3 `Account` objects assigned via `AccessControl` **WHEN** they open the account switcher **THEN** all 3 accounts are listed and the currently active account is highlighted.

2. **GIVEN** the user selects a different account from the switcher **WHEN** `AccountContextController@switch` is called **THEN** the session active account is updated, all entity lists are re-fetched with the new account filter, and a `{userId, fromAccountId, toAccountId, timestamp}` entry is written to `AuditTrail`.

3. **GIVEN** the user has 10 accounts and an administrator attempts to assign an 11th **WHEN** the assignment is saved **THEN** the API returns HTTP 422 "Maximum of 10 business accounts per user".

4. **GIVEN** the user switches to account B **WHEN** they navigate to the Invoices list **THEN** only invoices belonging to account B are shown; invoices from account A are not visible.

5. **GIVEN** a user attempts to switch to an account they are not assigned to via `POST /api/v1/account-context/switch` **WHEN** the request is processed **THEN** the API returns HTTP 403 "Not authorised for this account".

### REQ-APR-002: Contact Management with Payment Term Assignment [must]

The app MUST register the `Contact` schema (`schema:Person`) for customer and supplier master data. Each contact MUST support payment term assignment via `defaultPaymentTermDays`. The contact detail view MUST show outstanding invoices and total open balance. `kvkNumber`, `vatNumber`, and `iban` MUST be stored as contact properties.

**Scenarios:**

1. **GIVEN** a new supplier contact is created with `contactType: supplier`, `kvkNumber: 12345678`, `vatNumber: NL123456789B01`, and `defaultPaymentTermDays: 30` **WHEN** the contact is saved **THEN** it appears in the supplier list and its `defaultPaymentTermDays` is used when creating purchase invoices linked to this contact.

2. **GIVEN** a contact has 5 open invoices with a combined total of EUR 45 000 **WHEN** the contact detail page is opened **THEN** the open balance panel shows EUR 45 000 and lists the 5 invoices with their due dates.

3. **GIVEN** a contact's `creditLimit` is EUR 50 000 and open invoices total EUR 48 000 **WHEN** a new sales invoice of EUR 5 000 is created for this contact **THEN** the system shows a warning "Kredietlimiet overschreden: huidig saldo EUR 48 000, limiet EUR 50 000" as a non-blocking advisory.

4. **GIVEN** a supplier contact has `iban: NL91ABNA0417164300` **WHEN** an R2P payment request is initiated for an invoice linked to this contact **THEN** `R2PService` uses this IBAN as the destination account.

### REQ-APR-003: Invoice Lifecycle Management [must]

The app MUST register the `Invoice` schema (`schema:Invoice`) supporting sales, purchase, credit, and proforma types. The full lifecycle MUST be: draft → sent → viewed → partial → paid → overdue → void. `InvoiceOverdueJob` MUST run daily and mark invoices overdue. The `dueDate` MUST be automatically calculated from the linked `PaymentTerm` or `Contact.defaultPaymentTermDays` when the invoice is created.

**Scenarios:**

1. **GIVEN** an invoice is created with `invoiceType: sales`, linked to a contact with `defaultPaymentTermDays: 30`, and `invoiceDate: 2026-04-01` **WHEN** the invoice is saved **THEN** `dueDate` is automatically set to `2026-05-01`.

2. **GIVEN** an invoice has `paymentStatus: sent` and `dueDate: 2026-04-01` **WHEN** `InvoiceOverdueJob` runs on 2026-04-02 **THEN** `paymentStatus` changes to `overdue` and the AP clerk receives a Nextcloud notification "Factuur INK-2026-00142 is vervallen per 2026-04-01".

3. **GIVEN** an invoice has `paymentStatus: sent` and a partial payment is registered **WHEN** the paid amount is saved as less than `totalAmount` **THEN** `paymentStatus` changes to `partial` and `paidAmount` reflects the payment received.

4. **GIVEN** a draft invoice has line items totalling EUR 1 000 but the invoice header shows `subtotal: 900` **WHEN** `POST /api/v1/invoices/{id}/post` is called **THEN** the API returns HTTP 422 "Regelbedragen komen niet overeen met het factuurtotaal".

5. **GIVEN** the AP clerk rejects an invoice via `POST /api/v1/invoices/{id}/reject` with `rejectionReason: "Onjuiste BTW berekening"` **WHEN** the request is processed **THEN** `paymentStatus` is set to `void`, `rejectionReason` is stored, and a Nextcloud notification is sent to the linked supplier contact.

### REQ-APR-004: Invoice Line Coding to Cost Centre and GL Account [must]

The app MUST allow each `InvoiceLine` to be coded to a GL account (`accountCode`) and cost centre (`costCenterCode`). When the coded cost centre + GL combination is selected, the system MUST filter available GL accounts to those permitted for that cost centre. A `BudgetWarningBanner` MUST be shown if the coded amount would cause an overspend against the approved `Budget` for the cost centre. Dutch municipal taakveld coding MUST be supported as an optional field.

**Scenarios:**

1. **GIVEN** the AP clerk opens the invoice line form and selects cost centre `CC-2100` **WHEN** they tab to the GL account field **THEN** the type-ahead list shows only GL accounts permitted for `CC-2100`.

2. **GIVEN** the cost centre `CC-2100` has an approved `Budget` of EUR 80 000 and existing committed spend of EUR 78 000 **WHEN** a new invoice line of EUR 5 000 is coded to `CC-2100` **THEN** `BudgetWarningBanner` appears with "Budgetoverschrijding: huidig saldo EUR 78 000, goedgekeurd budget EUR 80 000, nieuw totaal EUR 83 000".

3. **GIVEN** coding is complete **WHEN** the clerk saves the invoice line **THEN** `accountCode`, `costCenterCode`, and optionally `taakveld` are stored on the `InvoiceLine` object.

4. **GIVEN** a user with the `budget-manager` role acknowledges the overspend warning **WHEN** the override is confirmed **THEN** the invoice line is saved without further restriction and the override is logged to `AuditTrail`.

### REQ-APR-005: Route Invoice to Budget Holder for Approval [must]

The app MUST route invoices where `totalAmount` exceeds the `clerkApprovalLimit` (configured in admin settings, default EUR 5 000) to the budget holder for the coded cost centre. Non-response within 5 working days MUST trigger escalation to the budget holder's line manager. Approval events MUST be logged with timestamp and user.

**Scenarios:**

1. **GIVEN** an invoice of EUR 12 000 is submitted and `clerkApprovalLimit` is EUR 5 000 **WHEN** the clerk submits the invoice **THEN** an `ApprovalWorkflow` entry is created for the `Budget.ownerId` of the coded cost centre and the budget holder receives a Nextcloud notification with invoice image, PO details, receipt information, and current budget position.

2. **GIVEN** the budget holder opens the approval request **WHEN** they approve via `POST /api/v1/invoices/{id}/approve` **THEN** `ApprovalWorkflow.status` is set to `approved`, the approval is logged with `{timestamp, userId}`, and the invoice moves to the payment queue.

3. **GIVEN** `ApprovalEscalationJob` runs and finds an `ApprovalWorkflow` older than 5 working days with `status: pending` **WHEN** the job processes it **THEN** a Nextcloud notification is sent to `Budget.lineManagerId` with subject "Escalatie: factuurgoedkeuring wacht al 5 werkdagen" and the escalation is appended to the workflow audit log.

4. **GIVEN** the budget holder rejects the invoice **WHEN** they submit rejection via `POST /api/v1/invoices/{id}/reject` **THEN** `paymentStatus` is set to `void` and the AP clerk receives a notification with the budget holder's rejection reason.

### REQ-APR-006: Automatic Two-Way Invoice Matching [must]

The app MUST implement a two-way matching engine (`InvoiceMatchingService`) that compares incoming invoices to `PurchaseOrder` objects on PO number, supplier identity, and total amount within a configurable tolerance (default 2%). Auto-matched invoices MUST be queued for payment. Mismatches MUST be flagged with reason codes for manual clerk review.

**Scenarios:**

1. **GIVEN** an invoice is received with `poReference: PO-2026-0089`, linked to supplier "Bouwbedrijf De Vries B.V.", with `totalAmount: EUR 30 250` **WHEN** the matching engine runs and finds a `PurchaseOrder` with matching PO number, same supplier, and `totalAmount: EUR 30 000` (within 2% tolerance) **THEN** `Invoice.matchStatus` is set to `two-way-matched` and the invoice is queued for payment.

2. **GIVEN** an invoice amount exceeds the PO total by more than 2% **WHEN** matching runs **THEN** `Invoice.matchStatus` is set to `mismatch` with reason code `AMOUNT_MISMATCH` and the invoice is assigned to the AP clerk queue.

3. **GIVEN** an invoice references `poReference: PO-2026-9999` which does not exist **WHEN** matching runs **THEN** `Invoice.matchStatus` is set to `unmatched` with reason code `PO_NOT_FOUND` and the invoice is added to the unmatched queue.

4. **GIVEN** the AP clerk views the unmatched invoice queue **WHEN** they open an unmatched invoice **THEN** the `InvoiceMatchPanel` shows the reason code and allows them to manually link a PO or approve without a PO.

### REQ-APR-007: Three-Way Match with Goods/Service Receipt [must]

After a successful two-way match, the app MUST perform a three-way match by verifying that a `Receipt` exists for the PO and that the received quantity/amount matches the invoiced amount within tolerance. For services, `TimesheetVerificationService` MUST verify invoiced hours × rate against the approved timesheet. Partial matches MUST hold the unmatched remainder.

**Scenarios:**

1. **GIVEN** an invoice is two-way matched to PO-2026-0089 **WHEN** the three-way match runs and finds a `Receipt` with `status: verified` and `amount: EUR 30 000` (within 2% of invoice EUR 30 250) **THEN** `Invoice.matchStatus` is set to `three-way-matched` and `Invoice.paymentStatus` is set to `sent` for payment processing.

2. **GIVEN** the goods receipt quantity is 400 of 500 invoiced units **WHEN** the three-way match runs **THEN** `Invoice.matchStatus` is set to `partial`, a partial approval is created for 400 units, and the remaining 100 units are held pending a further receipt or credit note.

3. **GIVEN** a services invoice references a timesheet with 80 approved hours at EUR 155/hr (EUR 12 400) **WHEN** `TimesheetVerificationService.verify()` is called **THEN** `verifiedHours × verifiedRate = EUR 12 400` matches the invoiced EUR 12 400 and `Receipt.status` is set to `verified`.

4. **GIVEN** the timesheet shows 70 hours but the invoice claims 80 hours **WHEN** `TimesheetVerificationService.verify()` is called **THEN** `Receipt.status` is set to `rejected` and the AP clerk receives a notification "Urenafwijking: factuur claimt 80 uur, goedgekeurde uren 70".

### REQ-APR-008: Payment Terms Configuration (Net 14/30/60/90) [must]

The app MUST register the `PaymentTerm` schema and provide a management interface for creating named payment term templates. Terms MUST be assignable to `Contact` objects as a default and overridable per `Invoice`. `PaymentTermService` MUST calculate `dueDate` automatically from `invoiceDate + daysDue`.

**Scenarios:**

1. **GIVEN** payment terms Net 14, Net 30, Net 60, Net 90, and Direct are seeded **WHEN** a user creates an invoice for a contact with `defaultPaymentTermDays: 30` and `invoiceDate: 2026-04-01` **THEN** `dueDate` is automatically calculated as `2026-05-01`.

2. **GIVEN** the user overrides the payment term on the invoice to Net 60 **WHEN** the invoice is saved **THEN** `dueDate` is recalculated to `2026-06-01`.

3. **GIVEN** an administrator marks "Net 30" as `isDefault: true` **WHEN** a new invoice is created for a contact with no `defaultPaymentTermDays` set **THEN** Net 30 (30 days) is used as the fallback.

4. **GIVEN** the PaymentTerm index is opened **WHEN** the list renders **THEN** the default term is marked with a badge and the list shows `name`, `daysDue`, and `isDefault` columns.

### REQ-APR-009: UBL/Peppol E-Invoice Ingestion [must]

The app MUST implement a `UblIngestionService` that parses UBL 2.1 e-invoices received via the configured Peppol access point. Header and line data MUST be extracted without manual keying. The `PeppolIngestJob` MUST poll the inbox every 15 minutes. Invalid documents MUST be quarantined with a notification to the AP clerk group.

**Scenarios:**

1. **GIVEN** a UBL e-invoice arrives at the Peppol inbox **WHEN** `PeppolIngestJob` runs **THEN** `UblIngestionService.ingest()` extracts `invoiceNumber`, supplier VAT number, `invoiceDate`, `totalAmount`, BTW amount, and line data and creates an `Invoice` object with all fields populated.

2. **GIVEN** the UBL invoice contains `cac:OrderReference/cbc:ID: PO-2026-0089` **WHEN** ingestion completes **THEN** `Invoice.poReference` is set and `InvoiceMatchingService.twoWayMatch()` is called automatically without clerk intervention.

3. **GIVEN** the UBL XML fails DOMDocument schema validation **WHEN** ingestion is attempted **THEN** the document is stored as a `Document` object with `status: quarantine` and `failureReason` set to the validation error, and the AP clerk group receives a Nextcloud notification "UBL-factuur kon niet worden verwerkt: {validationError}".

4. **GIVEN** the Peppol access point returns an HTTP 503 **WHEN** `PeppolIngestJob` runs **THEN** the error is logged to the Nextcloud server log, `AppSettings.peppolLastPolledAt` is NOT updated, and no exception is thrown that would stop subsequent job runs.

### REQ-APR-010: Credit Note Creation Linked to Original Invoice [must]

The app MUST allow AP clerks to create a credit note linked to an original sales or purchase invoice. `CreditNoteService` MUST generate a reversal `JournalEntry` automatically. The original invoice MUST be set to `void` if fully credited. Supplier credit notes received via UBL MUST be matched to the original purchase invoice.

**Scenarios:**

1. **GIVEN** a sales invoice VRK-2026-00060 is in `sent` state with `totalAmount: EUR 4 235` **WHEN** the clerk clicks "Maak creditnota" in the invoice detail view **THEN** a new `Invoice` is created with `invoiceType: credit`, `creditedInvoiceId: {VRK-2026-00060 ID}`, all line amounts negated, and a reversal `JournalEntry` with debits and credits swapped.

2. **GIVEN** the credit note covers the full original invoice amount **WHEN** the credit note is posted **THEN** `Invoice.paymentStatus` on the original invoice is set to `void`.

3. **GIVEN** the original invoice is in `draft` state **WHEN** `POST /api/v1/invoices/{id}/credit-note` is called **THEN** the API returns HTTP 422 "Kan geen creditnota aanmaken voor een conceptfactuur".

4. **GIVEN** a UBL credit note is received from a supplier referencing invoice INK-2026-00142 **WHEN** `UblIngestionService` processes it **THEN** the credit note is matched to the original purchase invoice and `Invoice.paidAmount` is reduced by the credit amount.

5. **GIVEN** the credit note detail view is opened **THEN** it shows a "Gerelateerde factuur" link back to the original invoice.

### REQ-APR-011: Reject Invoice and Request Credit Note from Supplier [must]

The app MUST allow the AP clerk to reject a purchase invoice and automatically generate a structured credit note request sent to the supplier contact via Nextcloud notification or email.

**Scenarios:**

1. **GIVEN** a purchase invoice has an error (e.g. incorrect BTW rate) **WHEN** the clerk rejects it via `POST /api/v1/invoices/{id}/reject` with `rejectionReason: "Onjuist BTW-tarief toegepast"` **THEN** `Invoice.paymentStatus` is set to `void`, `rejectionReason` is stored, and the linked supplier `Contact` receives a notification "Uw factuur INK-2026-00142 is afgewezen: Onjuist BTW-tarief toegepast. Wij verzoeken u een creditnota te sturen."

2. **GIVEN** the invoice is already `paid` **WHEN** reject is attempted **THEN** the API returns HTTP 422 "Betaalde facturen kunnen niet worden afgewezen".

3. **GIVEN** the supplier responds with a UBL credit note **WHEN** `UblIngestionService` processes it **THEN** the credit note is matched to the rejected invoice by `poReference` or `invoiceNumber` and `Invoice.matchStatus` is updated accordingly.

### REQ-APR-012: Request to Pay (R2P) Open Banking Payment Initiation [must]

The app MUST implement `R2PService` to initiate Request to Pay open banking payment requests from approved invoices. A `Payment` object MUST be created for each request. A webhook receiver MUST update `Payment.status` and `Invoice.paymentStatus` on completion. The open banking endpoint URL and webhook secret MUST be configurable in admin settings.

**Scenarios:**

1. **GIVEN** an invoice of EUR 30 250 is approved and the supplier `Contact` has `iban: NL91ABNA0417164300` **WHEN** the clerk clicks the R2P button **THEN** `R2PService.initiate()` constructs an R2P payload, posts it to the configured open banking endpoint, and creates a `Payment` object with `status: pending` and the returned `paymentRequestId`.

2. **GIVEN** the open banking system sends a completion webhook **WHEN** `POST /api/v1/payments/webhook` receives it with a valid HMAC signature **THEN** `Payment.status` is set to `completed` and `Invoice.paymentStatus` is set to `paid`.

3. **GIVEN** the webhook has an invalid HMAC signature **WHEN** it is received **THEN** the API returns HTTP 401 and the event is logged to the security log.

4. **GIVEN** the R2P request expires without payment **WHEN** the expiry webhook is received **THEN** `Payment.status` is set to `rejected` and the AP clerk receives a notification "R2P-verzoek verlopen voor factuur {invoiceNumber}".

### REQ-APR-013: Service Receipt Confirmation with Timesheet Verification [must]

The app MUST support `Receipt` objects with `receiptType: services` that link to an approved timesheet. `TimesheetVerificationService` MUST verify `verifiedHours × verifiedRate` against the invoiced amount within the configured tolerance. The three-way match engine MUST use the verified service receipt to approve payment.

**Scenarios:**

1. **GIVEN** a service receipt is created with `receiptType: services`, `timesheetId: TS-2026-0042`, `verifiedHours: 80`, `verifiedRate: 155.00` **WHEN** `TimesheetVerificationService.verify()` is called **THEN** `80 × 155 = EUR 12 400` is compared to the invoice total and if within tolerance `Receipt.status` is set to `verified`.

2. **GIVEN** the invoice claims 85 hours but the timesheet has only 80 approved hours **WHEN** verification runs **THEN** `Receipt.status` is set to `rejected` and the AP clerk receives a notification "Urenafwijking: factuur claimt 85 uur, goedgekeurde uren 80 — verschil buiten tolerantie".

3. **GIVEN** the service receipt is verified **WHEN** the three-way match runs **THEN** the service invoice is treated identically to a goods receipt match and released to the payment queue on full match.

4. **GIVEN** the `InvoiceLineForm` is opened for a services invoice line **WHEN** the `receiptType: services` is selected **THEN** the `timesheetId`, `verifiedHours`, and `verifiedRate` fields are shown; for goods receipts these fields are hidden.

### REQ-APR-014: Double-Entry Journal Entry Generation [must]

The app MUST automatically generate `JournalEntry` and `JournalLine` objects when an invoice is posted. Credit notes MUST generate reversal entries. The total of debit lines MUST equal the total of credit lines; the system MUST enforce this constraint server-side. Journal entries MUST store `fiscalYear` and `period` for financial period reporting.

**Scenarios:**

1. **GIVEN** a purchase invoice of EUR 30 250 (EUR 25 000 subtotal + EUR 5 250 BTW) is posted **WHEN** `InvoiceController@post` is called **THEN** a `JournalEntry` is created with `entryType: auto`, `totalDebit: 30 250`, `totalCredit: 30 250`, and two `JournalLine` objects: `debit 30 250 on account 1600 (Crediteuren)` and `credit 25 000 on account 4000 (Inkoopkosten)` + `credit 5 250 on account 1510 (BTW Inkoop)`.

2. **GIVEN** a `JournalEntry` is saved where `totalDebit ≠ totalCredit` **WHEN** the save is attempted **THEN** the API returns HTTP 422 "Boeking is niet in evenwicht: debet EUR X ≠ credit EUR Y".

3. **GIVEN** a credit note is created for a fully credited invoice **WHEN** `CreditNoteService.createCreditNote()` runs **THEN** a `JournalEntry` with `entryType: reversal` is created with all debit and credit lines from the original entry swapped.

4. **GIVEN** the journal entry detail view is opened **WHEN** `totalDebit ≠ totalCredit` (should not happen, but as a display guard) **THEN** a red "Niet in evenwicht" badge is shown.

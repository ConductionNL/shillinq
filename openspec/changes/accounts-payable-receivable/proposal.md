---
status: proposed
source: specter
features: [credit-note-creation-linked-to-original-sales-invoice, invoice-automation, up-to-10-business-accounts-under-one-login, service-receipt-confirmation-timesheet-verification, request-to-pay-r2p-open-banking, customer-payment-terms-configuration, reject-invoice-request-credit-note-supplier, process-supplier-credit-note-original-invoice, three-way-match-goods-receipt, match-invoice-purchase-order-automatically]
---

# Accounts Payable & Receivable — Shillinq

## Summary

Implements the core accounts payable and receivable workflows for Shillinq: automated invoice matching to purchase orders (two-way and three-way), UBL/Peppol e-invoice ingestion, invoice line coding to cost centre and GL account, budget-holder approval routing with escalation, credit note creation linked to original sales invoices, supplier credit note processing, payment terms configuration (Net 15/30/60/90), Request to Pay (R2P) open banking payment initiation, service receipt confirmation with timesheet-based verification, and multi-account support (up to ten business accounts under one login). These capabilities address the ten highest-demand accounts payable and receivable features identified in the Specter intelligence model and build on the core, access-control-authorisation, collaboration, document-management, supplier-management, and catalog-purchase-management infrastructure changes.

## Demand Evidence

Top features by market demand score:

- **Credit note creation linked to original sales invoice** (demand: 1610) — the top-ranked AP/AR feature. Finance teams must be able to create a credit note that is traceable back to the originating invoice, with automated reversal journal entries ensuring the general ledger remains balanced without manual correction.
- **Invoice automation** (demand: 1602) — high-volume invoice environments require automated capture, matching, and routing. The system must parse incoming UBL/Peppol e-invoices without manual keying, perform two-way matching against POs, and route exceptions rather than every invoice.
- **Up to 10 business accounts under one login** (demand: 1061) — sole proprietors, accountants, and SMB owners frequently manage multiple legal entities. A single Nextcloud user must be able to switch between up to ten distinct `Account` contexts without logging out.
- **Service receipt confirmation with timesheet-based verification** (demand: 1048) — for professional services invoices, a goods receipt note is not applicable; instead, the system must match the invoiced hours to approved timesheet data before releasing the invoice to payment.
- **Request to Pay (R2P) open banking payment requests** (demand: 449) — creditors want to initiate payment requests directly from within Shillinq using the open banking R2P standard, reducing manual bank transfers and providing real-time payment status.
- **Customer payment terms configuration (Net 15/30/60/90)** (demand: 250) — finance managers must configure named payment term templates and assign them to contacts, with automatic due date calculation on invoice creation.
- **Reject invoice and request credit note from supplier** (demand: 234) — when a supplier invoice is incorrect, the AP clerk must be able to formally reject it and generate a structured credit note request sent to the supplier.
- **Process supplier credit note against original invoice** (demand: 230) — when a supplier issues a credit note, the system must match it to the original purchase invoice and post the offsetting journal entries automatically.
- **Perform three-way match with goods receipt** (demand: must) — purchasing controls require that goods receipt quantity and value are verified against both the PO and the supplier invoice before payment is authorised.
- **Match invoice to purchase order automatically** (demand: must) — the system must perform two-way matching on PO number, supplier, and amount and flag mismatches for manual clerk review.

Key stakeholder pain points addressed:

- **Accounts Payable Clerk**: manual invoice keying from PDFs, no automated PO matching, chasing approvers with no visibility, coding decisions not guided by GL/cost centre rules — addressed by UBL ingestion, two-way and three-way matching, approval routing with escalation, and smart coding with budget validation.
- **Municipal Supplier**: no visibility into invoice status, uncertainty about payment timing, incorrect PO references causing payment delays — addressed by the supplier portal view, R2P payment requests, and automated remittance advice on payment.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, document-management, supplier-management, and catalog-purchase-management changes:

- OpenRegister schemas for `PurchaseOrder`, `Budget`, `Supplier`, `ApprovalWorkflow`, `AccessControl`, `Comment`, `Document`, `BankAccount`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- Approval workflow engine with role-based guards

### What Is Missing

- No `Account` schema for multi-entity business account contexts
- No `Contact` schema for customer/supplier contact management with payment term assignment
- No `Invoice` schema for sales/purchase invoice lifecycle management
- No `InvoiceLine` schema for line-level GL coding and cost centre allocation
- No `JournalEntry` / `JournalLine` schemas for double-entry bookkeeping
- No `Payment` schema for R2P open banking payment requests
- No `PaymentTerm` schema for Net 15/30/60/90 configuration
- No `Receipt` schema for goods/service receipt confirmation
- No UBL/Peppol ingestion pipeline
- No two-way or three-way matching engine
- No credit note creation workflow
- No service receipt timesheet verification
- No budget overspend validation on invoice coding
- No approval escalation for unresponsive budget holders
- No multi-account switching for up to 10 business entities

## Scope

### In Scope

1. **Account Schema** — OpenRegister `Account` schema (`schema:FinancialProduct`) representing a distinct business entity (GL account group). Supports up to 10 accounts per Nextcloud user via `AccessControl` assignment. A header switcher component allows the active account context to be changed without logout. Views at `src/views/account/`; store at `src/store/modules/account.js`.

2. **Contact Schema** — OpenRegister `Contact` schema (`schema:Person`) representing customers and suppliers with payment term assignment, IBAN, VAT number, and KvK number. Used as the debtors/creditors master. Views at `src/views/contact/`; store at `src/store/modules/contact.js`.

3. **Invoice Schema** — OpenRegister `Invoice` schema (`schema:Invoice`) supporting sales, purchase, credit, and proforma invoice types. Full lifecycle: draft → sent → viewed → partial → paid → overdue → void. Automatic due date calculation from `PaymentTerm`. Linked to `JournalEntry` on posting. Views at `src/views/invoice/`; store at `src/store/modules/invoice.js`.

4. **InvoiceLine Schema** — OpenRegister `InvoiceLine` schema (`schema:OrderItem`) for line-level coding to GL account and cost centre. Budget overspend warning computed on save. Views at `src/views/invoiceLine/`; store at `src/store/modules/invoiceLine.js`.

5. **JournalEntry / JournalLine Schemas** — OpenRegister `JournalEntry` (`schema:Action`) and `JournalLine` (`schema:MonetaryAmount`) schemas for double-entry bookkeeping. Auto-generated on invoice posting and credit note creation. Debit/credit balance enforced server-side. Views at `src/views/journalEntry/` and `src/views/journalLine/`; stores at `src/store/modules/journalEntry.js` and `src/store/modules/journalLine.js`.

6. **Payment Schema (R2P)** — OpenRegister `Payment` schema (`schema:Order`) for Request to Pay open banking payment requests. Links to `Invoice` and `Contact`. Status tracked: pending / approved / rejected / completed. Views at `src/views/payment/`; store at `src/store/modules/payment.js`.

7. **PaymentTerm Schema** — OpenRegister `PaymentTerm` schema for Net 15, Net 30, Net 60, Net 90 templates. Assigned to `Contact` as default and overridable per `Invoice`. Views at `src/views/paymentTerm/`; store at `src/store/modules/paymentTerm.js`.

8. **Receipt Schema** — OpenRegister `Receipt` schema (`schema:Receipt`) for goods receipt notes and service receipt confirmations. Service receipts link to timesheet data for hour-based verification. Three-way match engine consults `Receipt` before approving payment. Views at `src/views/receipt/`; store at `src/store/modules/receipt.js`.

9. **UBL/Peppol Ingestion** — A PHP `UblIngestionService` parses incoming UBL 2.1 e-invoices received via Peppol, extracts header and line data, creates `Invoice` and `InvoiceLine` objects, and immediately attempts two-way matching if a PO reference is present. Invalid documents are quarantined with a validation error notification to the AP clerk.

10. **Two-Way Matching Engine** — A PHP `InvoiceMatchingService` compares incoming invoices against `PurchaseOrder` objects on PO number, supplier, and amount within a configurable tolerance (default 2%). Auto-matched invoices are queued for payment; mismatches and unknown PO references are flagged for manual clerk review.

11. **Three-Way Matching Engine** — Extends `InvoiceMatchingService` to verify the `Receipt` quantity and amount. Partial matches hold the unmatched remainder; full matches release the invoice to the payment queue.

12. **Credit Note Workflow** — AP clerks can create a credit note linked to an original `Invoice`. The system automatically generates reversal `JournalEntry` objects and updates `paymentStatus` on the original invoice. Supplier credit notes received via UBL are matched to the original purchase invoice.

13. **Approval Routing with Escalation** — Invoices above the clerk approval threshold are routed to the budget holder for the coded cost centre via `ApprovalWorkflow`. Non-response within 5 working days triggers escalation to the budget holder's line manager via Nextcloud notification.

14. **Service Receipt Timesheet Verification** — For professional services invoices, the `Receipt` references an approved timesheet (hours × rate). The three-way match engine compares invoiced hours and rate against the timesheet total before releasing to payment.

15. **Multi-Account Switching** — A global account context store allows a Nextcloud user to switch between up to 10 `Account` objects assigned to them. All entity queries are scoped to the active account context. The active account is displayed in the top navigation bar.

### Out of Scope

- Payroll integration (Nmbrs) — deferred to a dedicated payroll change
- Bank statement import and automatic reconciliation — deferred to bank-reconciliation change
- VAT/BTW return filing — deferred to tax-reporting change
- Multi-currency exchange rate feeds — deferred to multi-currency change
- Annual accounts sign-off workflow — deferred to annual-accounts change

## Architecture

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Account, Contact, Invoice, InvoiceLine,
    │                          JournalEntry, JournalLine, Payment,
    │                          PaymentTerm, Receipt CRUD)
    │
    └─ Shillinq OCS API
            ├─ InvoiceController        (post, credit-note, reject, approve)
            ├─ MatchingController       (two-way match, three-way match trigger)
            ├─ UblController            (Peppol ingest endpoint)
            ├─ PaymentController        (R2P initiate, status webhook)
            └─ AccountContextController (active account switch)
                    │
                    └─ PHP Services
                            ├─ UblIngestionService      (UBL 2.1 parse → Invoice + InvoiceLine objects)
                            ├─ InvoiceMatchingService   (two-way and three-way match logic)
                            ├─ CreditNoteService        (reversal journal entry generation)
                            ├─ ApprovalRoutingService   (threshold check, budget holder lookup, escalation)
                            ├─ PaymentTermService       (due date calculation from term template)
                            ├─ R2PService               (open banking payment request initiation)
                            └─ TimesheetVerificationService (service receipt hour/rate validation)
                    │
                    └─ Background Jobs
                            ├─ InvoiceOverdueJob        (daily — marks overdue, sends reminders)
                            ├─ ApprovalEscalationJob    (daily — escalates after 5 working days)
                            └─ PeppolIngestJob          (every 15 min — polls Peppol inbox)
```

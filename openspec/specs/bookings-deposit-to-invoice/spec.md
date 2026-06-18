---
status: done
---

# Capability Spec: Booking Deposit-to-Invoice Flow

**Status:** proposed  
**Scope:** booking module + shillinq integration  
**Tier:** T2 (customer-facing invoicing feature)  
**Primary Spec:** This document  
**Depends on:** `bookings-deposits` (DepositPayment register), `add-shillinq-accounts-receivable-core` (Invoice, CreditNote entities)

---

## Purpose

This spec defines the capability to automatically consume authorized deposit amounts when a booking transitions to invoicing (at completion or checkout). When a booking moves from `confirmed` to `completed`, a final `Invoice` is created in Shillinq with a negative line item (credit) for the deposit amount, reducing the customer's outstanding balance. Deposit-to-invoice reconciliation is bidirectional and auditable through Shillinq's AR module.

Per ADR-031, all deposit-to-invoice logic is declarative: `x-openregister-lifecycle` triggers and `x-openregister-calculations` metadata.

---

## Business Context

**Market evidence:** 18 of 21 booking-software competitors automatically apply deposits as credits on final invoices, providing customers with clarity on net payment due.

**SMB pain points:**
- Manual deposit-to-invoice tracking creates reconciliation errors
- Customers expect to see deposit credit on final invoice
- AR aging is complex if deposit and invoice are not linked
- Tax reporting requires clear deposit–invoice traceability

**Solution:** Automatic invoice creation with embedded deposit credit, eliminating manual entry and reconciliation overhead.

---

## Entities & Relationships

@e2e exclude pure backend: deposit-to-invoice conversion — not browser-testable


### 1. Order (existing, extended)

State machine extended to include `completed`:

```
confirmed 
  → completed (operator confirms fulfillment/checkout)
    → [Invoice created in Shillinq, linked back to Order]
    → cancelled (if order cancelled after invoicing, triggers CreditNote)
```

New fields on Order:
- `invoiceId` (string, FK to Invoice): Reference to the created final invoice (optional, null until completed).
- `completedAt` (datetime): Timestamp when the booking was fulfilled/completed.

### 2. Invoice (existing, reused from Shillinq)

The invoice created by this feature includes:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `invoiceId` | string | Yes | Unique identifier |
| `invoiceNumber` | string | Yes | Human-readable invoice number (Dutch: factuurnummer) |
| `customerId` | string (FK to Order.customerId) | Yes | Customer who made the booking |
| `invoiceDate` | datetime | Yes | Date the invoice was issued (typically completion date) |
| `dueDate` | datetime | Yes | Payment due date (calculated from payment terms) |
| `sourceDocumentUri` | string | Yes | Reference to the Order: `urn:nextcloud:booking:order:{orderId}` |
| `depositPaymentId` | string (FK to DepositPayment) | No | Link to the deposit that was credited on this invoice |
| `lineItems` | array | Yes | Invoice line items including service + deposit credit |
| `netAmount` | number | Yes | Total amount excluding VAT |
| `vatAmount` | number | Yes | Total VAT amount (21% standard rate for EUR) |
| `grossAmount` | number | Yes | Total amount after deposit credit applied |
| `state` | enum | Yes | "issued" (awaiting payment) |
| `paymentTerms` | string | Yes | Payment conditions (e.g., "Net 14 days") |

**Relations:**
- → Order (many-to-one): Each booking can generate one final invoice
- → DepositPayment (many-to-one): References the deposit that was credited
- → Payment (one-to-many): Payments received against this invoice (AR workflow)

### 3. InvoiceLine (existing, reused from Shillinq)

Invoice line items include:
- **Service line(s)**: Description, quantity, unit price, amount, tax rate
- **Deposit credit line** (negative): Description (e.g., "Deposit Credit Applied"), amount (negative), tax rate 0%

### 4. CreditNote (existing, reused from Shillinq)

Created when a booking with an issued invoice is cancelled:

| Property | Type | Description |
|----------|------|-------------|
| `creditNoteId` | string | Unique identifier |
| `creditNoteNumber` | string | Human-readable credit note number |
| `linkedInvoiceId` | string (FK to Invoice) | Reference to the invoice being reversed |
| `customerId` | string | Customer |
| `creditDate` | datetime | Date the credit note was issued |
| `reason` | string | Reason for credit (e.g., "Booking cancelled") |
| `grossAmount` | number | Total credit amount (reverses the invoice) |
| `state` | enum | "issued" |

---

## Requirements

### REQ-DI-001: Bidirectional Linkage & Audit Trail

**Requirement:** Invoice and DepositPayment MUST be bidirectionally linked; the audit trail MUST preserve full traceability from Order → DepositPayment → DepositInvoice and Order → FinalInvoice → DepositPayment.

#### Scenario: Bidirectional linkage on completion

- **GIVEN** a booking with Order.orderId=ord-123, DepositPayment.depositPaymentId=dp-456
- **WHEN** the booking completes and an Invoice is created with Invoice.invoiceId=inv-789
- **THEN** Order.invoiceId = inv-789
- **AND** Invoice.sourceDocumentUri = "urn:nextcloud:booking:order:ord-123"
- **AND** Invoice.depositPaymentId = dp-456
- **AND** DepositPayment can be traced backward from Invoice
- **AND** Audit log records the linkage (REQ-DI-011)

### REQ-DI-002: Automatic Invoice Creation on Booking Completion

**Requirement:** When an Order transitions to `completed` state, an `Invoice` MUST be automatically created in Shillinq with correct line items and deposit credit applied.

#### Scenario: Invoice auto-created on completion

- **GIVEN** a booking with Order.orderId = ord-1001, Order.state = confirmed, Order.estimatedTotal = 15000 EUR cents (€150.00), DepositPayment.amount = 7500 EUR cents (€75.00), DepositPayment.state = authorized, Booking type: "Studio Portrait Session"
- **WHEN** Order transitions to completed (operator confirms fulfillment)
- **THEN** Invoice is created in Shillinq with Invoice.invoiceNumber = auto-generated (e.g., INV-2026-0567), Invoice.invoiceDate = today, Invoice.sourceDocumentUri = "urn:nextcloud:booking:order:ord-1001", Invoice.depositPaymentId = dp-5001, Invoice state = "issued"
- **AND** Order.invoiceId = inv-2001
- **AND** Order.state remains "completed"

### REQ-DI-003: Deposit Credit as Negative Line Item

**Requirement:** The DepositPayment amount MUST appear on the invoice as a negative `InvoiceLine` (credit), reducing the gross amount due.

#### Scenario: Deposit credit reduces gross amount

- **GIVEN** an Invoice with Line 1: Studio Portrait Session (2h), €150.00, 21% VAT → €178.50 gross, and Line 2: Deposit Credit Applied, -€75.00, 0% VAT → -€75.00 gross
- **THEN** Invoice.netAmount = €150.00 (service net only)
- **AND** Invoice.vatAmount = €31.50 (21% on service)
- **AND** Invoice.grossAmount = €103.50 (178.50 - 75.00)
- **AND** Customer sees €103.50 due (deposit already paid)

### REQ-DI-004: Tax Calculation & Compliance

**Requirement:** VAT MUST be calculated on the service amount only; the deposit credit is applied with 0% tax (deposit was already taxed at collection time).

#### Scenario: VAT only on service amount

- **GIVEN** Service gross (with 21% VAT): €178.50, Deposit collected and taxed: €75.00 (included €15.75 VAT), Due date for final invoice: event completion date
- **WHEN** invoice is created
- **THEN** Line 1 (service): amount €150.00, VAT €31.50 (21%)
- **AND** Line 2 (credit): amount -€75.00, VAT €0.00 (no reversal; already paid)
- **AND** Total VAT on invoice: €31.50 (same as on service alone)
- **AND** Gross due: €103.50 (service with VAT, minus deposit)

### REQ-DI-005: Due Date Calculation

**Requirement:** Invoice due date MUST be calculated from the invoice date plus configured payment terms (default: 14 days for bookings).

#### Scenario: Due date from payment terms

- **GIVEN** a completed booking with Invoice.invoiceDate = 2026-06-15 (completion date) and Payment terms = "Net 14 days"
- **THEN** Invoice.dueDate = 2026-06-29 (14 days later)

### REQ-DI-006: Cancellation After Invoicing

**Requirement:** If a booking is cancelled AFTER a final invoice is issued, a `CreditNote` MUST be created in Shillinq to reverse the invoice. The original invoice remains in AR for audit.

#### Scenario: Credit note on cancellation after invoicing

- **GIVEN** Order.state = completed, Invoice.invoiceId = inv-2001 (issued), Invoice.grossAmount = €103.50, and the Order is cancelled by customer
- **WHEN** Order state transitions to cancelled
- **THEN** CreditNote is created in Shillinq with CreditNote.linkedInvoiceId = inv-2001, CreditNote.grossAmount = -€103.50 (full reversal), CreditNote.creditDate = today, CreditNote.reason = "Booking cancelled"
- **AND** Invoice.state remains "issued" (record preserved)
- **AND** DepositPayment.state may transition to "refunded" (if refundPolicy=automatic_on_cancellation)

### REQ-DI-007: Invoice Links & Traceability

**Requirement:** Invoice MUST provide links back to the Order and DepositPayment for traceability in Shillinq and booking modules.

#### Scenario: Invoice links back to source records

- **GIVEN** an Invoice in Shillinq with Invoice.invoiceNumber = INV-2026-0567, Invoice.sourceDocumentUri = "urn:nextcloud:booking:order:ord-1001", Invoice.depositPaymentId = "dp-5001"
- **THEN** Shillinq can navigate to the source Order
- **AND** Shillinq can look up the DepositPayment that was credited
- **AND** Both systems maintain a shared reference for reconciliation

### REQ-DI-008: Invoice for Bookings Without Deposits

**Requirement:** If a booking has no deposit rule, a final invoice MUST still be created with the full service amount (no credit line).

#### Scenario: Invoice without deposit credit

- **GIVEN** a booking-type with no depositRule, Order.estimatedTotal = 15000 EUR cents, DepositRequired = false
- **WHEN** Order completes
- **THEN** Invoice is created with Line 1: Service €150.00 (21% VAT → €178.50 gross), no deposit credit line, Invoice.grossAmount = €178.50 (full amount due)

### REQ-DI-009: Invoice Reference & AR Aging

**Requirement:** The created invoice MUST be integrated into Shillinq's AR aging, payment matching, and reporting workflows with no special handling required.

#### Scenario: Invoice flows through standard AR workflows

- **GIVEN** an issued Invoice from a completed booking with Invoice.state = issued, Invoice.grossAmount = €103.50, Invoice.dueDate = 2026-06-29
- **THEN** Invoice appears in Shillinq's AR aging report (outstanding > 0 days)
- **AND** Payment module can match payments to invoice
- **AND** Invoice can be paid in full or partially per AR workflows
- **AND** No booking-specific logic required in Shillinq

### REQ-DI-010: User-Facing Visibility

**Requirement:** The created invoice MUST be visible to the operator in the booking module (as a link) and to the customer in their booking confirmation/receipt.

#### Scenario: Invoice visible to operator and customer

- **GIVEN** a completed booking with Invoice.invoiceId = inv-2001
- **WHEN** operator views the booking detail page
- **THEN** a widget shows "Invoice: INV-2026-0567", a link to open the invoice in Shillinq, and invoice amount, due date, payment status
- **WHEN** customer views their booking confirmation (email/portal)
- **THEN** the confirmation includes invoice number and amount due, due date, link to invoice (PDF or Shillinq portal), and deposit credit applied (e.g., "€75.00 deposit applied, €103.50 due")

### REQ-DI-011: Error Handling & Logging

**Requirement:** If invoice creation fails, the error MUST be logged and the Order MUST remain in `completed` state (not orphaned). A retry mechanism or manual intervention path MUST be available.

#### Scenario: Invoice creation failure handling

- **GIVEN** Order completes, triggering invoice creation
- **WHEN** Shillinq API is unavailable
- **THEN** Lifecycle action logs error (code, message, timestamp)
- **AND** Order remains in "completed" state
- **AND** Background job retries invoice creation (T4+ async worker)
- **AND** Operator receives alert in booking module UI
- **AND** Manual "Create Invoice" button available for operator retry

---

## State Diagrams

### Order State Machine (Booking with Deposit)

```
draft 
  ↓
pending_payment (awaiting deposit authorization)
  ├─ [deposit authorized] → confirmed → completed → [Invoice created] → cancelled
  └─ [deposit failed/expired] → cancelled
```

### Invoice Lifecycle

```
created (by lifecycle action on Order.completed)
  ↓
issued (awaiting customer payment)
  ├─ [payment received] → partially_paid / fully_paid
  ├─ [credit note created] → reversed (if booking cancelled)
  └─ [aged] → overdue
```

---

## Dependencies & Integration Points

| Component | Role | Notes |
|-----------|------|-------|
| `bookings-deposits` (T1) | Provides DepositPayment state & amount | Required: deposit must be authorized before invoice creation |
| `Order` (booking module) | Triggers invoice on completion | Lifecycle action extends Order state machine |
| `Invoice` (Shillinq AR) | Invoice creation & management | Created via API; full state lifecycle in AR |
| `InvoiceLine` (Shillinq AR) | Line item creation | Service + tax + deposit credit |
| `CreditNote` (Shillinq AR) | Reversal on cancellation | Created if Order cancelled after invoicing |
| OpenConnector (T4+) | Payment initiation for final invoice | Future: send payment reminder/link to customer |

---

## Non-Functional Requirements

### Performance

- Invoice creation must complete in <2 seconds (synchronous or async with notification).
- Order.state transition must not be blocked by Shillinq API latency.

### Scalability

- Support 1000+ concurrent booking completions per day.
- Background job retry (polling fallback) must handle failed invoice creations.

### Reliability

- Invoice creation MUST be idempotent (no duplicate invoices if webhook is retried).
- Shillinq API failures MUST trigger retry loop (exponential backoff, max 3 retries + manual override).

### Auditability

- All invoice creation events MUST be logged with timestamp, operator, Order ID, Invoice ID.
- Invoice linking (Order → Invoice → DepositPayment) MUST be traceable in audit trail.

### Compliance

- Invoice MUST comply with Dutch invoicing law (number, date, parties, amounts, VAT).
- VAT calculation MUST follow Dutch standard rates (21%, 9%, 6%, 0% categories).
- CreditNote MUST reverse invoice without creating negative VAT entries.

---

## Testing Scenarios

### Scenario 1: Happy Path — Booking with Deposit Completes

1. Create booking with deposit rule (50%, €75 deposit).
2. Authorize deposit payment (DepositPayment.state = authorized).
3. Confirm booking (Order.state = confirmed).
4. Complete booking (Order.state = completed).
   - **Expected**: Invoice created in Shillinq with deposit credit, Order.invoiceId populated.

### Scenario 2: Booking Without Deposit Completes

1. Create booking without deposit rule.
2. Confirm booking (Order.state = confirmed).
3. Complete booking (Order.state = completed).
   - **Expected**: Invoice created without credit line, full service amount due.

### Scenario 3: Cancellation After Invoicing

1. Complete booking (Invoice created, state = issued).
2. Cancel booking (Order.state = cancelled).
   - **Expected**: CreditNote created, reversing the invoice. Original invoice preserved in AR.

### Scenario 4: Invoice Creation Fails (Shillinq Unavailable)

1. Complete booking → invoice creation fails (Shillinq API down).
2. Order.state = completed (not orphaned).
3. Error logged, operator notified.
4. Shillinq comes back online → background job retries.
   - **Expected**: Invoice created within 5 minutes, Order reconciled.

### Scenario 5: Deposit Refund After Invoicing

1. Complete booking (Invoice issued, deposit credited).
2. Cancel booking → CreditNote created.
3. If refundPolicy = automatic_on_cancellation → DepositPayment.state = refunded, refund initiated.
   - **Expected**: Full refund flow: CreditNote + DepositPayment refund + customer notification.

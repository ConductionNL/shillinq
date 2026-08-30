# Design — Booking Deposit-to-Invoice Flow

## Context

When bookings with deposits transition to invoicing (at checkout or completion), the previously authorized deposit amount must be applied as a credit on the final customer invoice. This reduces the amount the customer owes and reconciles the booking's payment history with the invoicing module's AR workflow.

Per ADR-031 (no app-local business logic), all deposit-to-invoice calculations and lifecycle actions are declared as `x-openregister-lifecycle` preconditions and calculations; no `InvoiceService.php` is authored. Shillinq's AR module handles the final invoice state, aging, and payment settlement.

## Goals

- Automatically create a final `Invoice` in Shillinq when a booking transitions to `completed`.
- Calculate invoice line items correctly: service amount + tax – deposit credit.
- Apply the authorized `DepositPayment` as a **negative line item** (credit) on the invoice, reducing customer's outstanding balance.
- Maintain **bidirectional traceability**: `Order` → `Invoice`, `Invoice` → `DepositPayment`, enabling full audit and reconciliation.
- Integrate **transparently with Shillinq AR**: invoice lifecycle (issuance, payment, aging, cancellation) is managed by AR; booking module only triggers creation.

## Non-Goals

- Partial invoicing or milestone-based invoicing (T5+ enhancement).
- Multi-currency invoicing (all in EUR per customer context).
- Automatic payment capture from the original deposit payment method (deposit already captured; final payment is separate).
- Invoice amendment after issuance (compliance with Dutch tax law).

## Decisions

### D1 — Final Invoice Created at Booking Completion

When an `Order` transitions to `completed` state (fulfillment or checkout workflow), a lifecycle action triggers creation of an `Invoice` in Shillinq with:
- `customerId` from Order.customerId
- `invoiceDate` = today
- `dueDate` = configured payment terms from booking-type or default 14 days
- Line items: service + tax – deposit credit
- `sourceDocumentUri` pointing to the Order (booking)
- `state`: "issued"

**Alternative considered**: Invoice created at quote time (when booking is made). Rejected — invoice is a commitment to deliver; it should reflect actual fulfillment, not anticipated pricing. Invoicing at completion is the standard booking-software pattern.

### D2 — Deposit Credit as a Negative Line Item

The `DepositPayment` amount appears on the invoice as a **negative `InvoiceLine`** (or credit line):
```
Line 1: Studio Portrait Session (2h)    €150.00    21% VAT    €178.50
Line 2: Deposit Credit (applied)        -€75.00    0% VAT      -€75.00
─────────────────────────────────────────────────────────
Total Due: €103.50
```

The invoice's `grossAmount` is the sum of all lines (including the negative credit). This is visible to the customer, providing transparency and enabling manual reconciliation if needed.

**Alternative considered**: Hide the deposit as a GL adjustment (not a visible line item). Rejected — SMBs need transparency for customer communications and manual audit. A visible negative line item is clearer and more defensible for tax purposes.

### D3 — Deposit Reconciliation: DepositPayment.arInvoiceId vs. Invoice.sourceDocumentUri

The relationship works as follows:
1. `DepositPayment.arInvoiceId` points to the **deposit invoice** created in T1 (bookings-deposits), which is the AR record for the deposit collection.
2. `Invoice.sourceDocumentUri` points to the **Order (booking)** that originated the invoice.
3. A new field `Invoice.depositPaymentId` (or line-item metadata) explicitly links the final invoice to the deposit that was credited.

This allows tracing:
- Order → DepositPayment (authorized deposit) → Deposit AR Invoice (T1)
- Order → Final Invoice (T2, this change) → Deposit credit line → DepositPayment

Shillinq AR treats these as separate invoices (one for deposit, one for final balance), which is correct for tax and aging workflows.

**Alternative considered**: Merge deposit and final invoices into one record. Rejected — deposits and final invoices are financially distinct (different dates, different payment methods, different aging); keeping them separate is clearer for AR reconciliation.

### D4 — Tax Calculation on Deposit Credit

The deposit was collected and taxed at deposit time (21% VAT on the deposit amount). The final invoice shows:
- Service line: gross amount (including 21% VAT)
- Deposit credit line: negative amount with 0% VAT applied (because tax was already paid on the deposit)

**Example:**
- Service: €100 net + €21 VAT = €121 gross
- Deposit authorized: €60 (at time of booking)
- Final invoice:
  - Line 1: Service €121 (21% VAT)
  - Line 2: Deposit Credit -€60 (0% VAT, no tax reversal because deposit was already taxed)
  - Total due: €61

This matches Dutch tax practice: VAT is paid when the invoice is issued (deposit time), not when payment is made (completion time).

**Alternative considered**: Show deposit credit with reverse VAT (reduce VAT line by deposit's VAT). Rejected — this complicates the invoice and the AR aging model; the deposit's VAT was already reported and paid.

### D5 — Booking State Machine: confirmed → completed

The Order (booking) state machine is extended:
```
draft → pending_payment (if deposit rule) → confirmed
                          ↓
                    (no deposit rule)
                    ↓
pending_payment → confirmed → completed → invoiced (AR state, not Order state)
                                     ↓
                            [Invoice created in Shillinq]
                                     ↓
                                  cancelled (refund via CreditNote)
```

Transition to `completed` is triggered by the fulfillment workflow (booking slot occupied, service delivered, operator confirms completion). Invoicing happens automatically on this transition.

**Alternative considered**: User manually triggers invoicing. Rejected — SMBs expect automatic invoicing for efficiency; manual triggering adds friction.

### D6 — Cancellation After Invoicing

If a booking is cancelled **after** the final invoice is issued:
1. `Order.state` → `cancelled`
2. Lifecycle action: create a `CreditNote` in Shillinq that reverses the invoice
3. CreditNote amount = original invoice gross (full reversal, including deposit credit)
4. DepositPayment state may transition to `refunded` (if refund policy is automatic) or remain `captured` (if operator approval needed)

This ensures the booking's financial record is clean: cancelled bookings have a matching CreditNote in AR.

**Alternative considered**: Delete the invoice instead of creating a CreditNote. Rejected — deletion breaks audit trail; CreditNotes preserve history per Dutch law and tax compliance.

## Reuse Analysis

| Entity | Reused From | Design Note |
|--------|------------|------------|
| `Order` | booking module (existing) | State machine extended to include `completed` state; new field `invoiceId` links to created Invoice. |
| `Invoice` | shillinq-accounts-receivable | Automatically created by Order lifecycle action on completion; `sourceDocumentUri` references the Order. |
| `InvoiceLine` | shillinq-accounts-receivable | Line items include: service description + amount, tax, and a negative line for deposit credit. |
| `DepositPayment` | bookings-deposits (T1) | Linked via `Invoice.depositPaymentId`; used to calculate credit amount and due date. |
| `CreditNote` | shillinq-accounts-receivable | Created on booking cancellation; reverses the issued invoice. |

## Seed Data (Examples)

### Order (Booking): Confirmed, Ready for Invoicing

```json
{
  "orderId": "ord-1001",
  "bookingTypeId": "bt-001",
  "customerId": "cust-5432",
  "eventDate": "2026-06-15",
  "eventTime": "14:00",
  "state": "confirmed",
  "basePrice": 15000,  // EUR cents (€150.00)
  "estimatedTotal": 15000,
  "depositRequired": true,
  "depositAmount": 7500,  // 50% (€75.00)
  "depositPaymentId": "dp-5001",
  "invoiceId": null,  // Will be filled after completion
  "createdAt": "2026-05-21T10:00:00Z",
  "completedAt": null
}
```

### Order: After Completion, Transitioned to Invoicing

```json
{
  "orderId": "ord-1001",
  "state": "completed",
  "invoiceId": "inv-final-2001",  // Created by lifecycle action
  "completedAt": "2026-06-15T16:30:00Z"
}
```

### Invoice: Final Invoice with Deposit Credit

```json
{
  "invoiceId": "inv-final-2001",
  "invoiceNumber": "INV-2026-0567",
  "customerId": "cust-5432",
  "invoiceDate": "2026-06-15",
  "dueDate": "2026-06-29",  // 14 days from invoice date
  "sourceDocumentUri": "urn:nextcloud:booking:order:ord-1001",
  "depositPaymentId": "dp-5001",
  "lineItems": [
    {
      "lineNumber": 1,
      "description": "Studio Portrait Session (2-hour session)",
      "quantity": 1,
      "unitPrice": 15000,  // EUR cents
      "lineAmount": 15000,
      "taxRate": 21,
      "taxAmount": 3150,
      "grossAmount": 18150
    },
    {
      "lineNumber": 2,
      "description": "Deposit Credit Applied",
      "quantity": 1,
      "unitPrice": -7500,  // Negative: credit
      "lineAmount": -7500,
      "taxRate": 0,  // No tax on credit (deposit already taxed)
      "taxAmount": 0,
      "grossAmount": -7500
    }
  ],
  "netAmount": 15000,      // Service net (150 EUR)
  "vatAmount": 3150,       // Tax on service only (21%)
  "grossAmount": 10650,    // 18150 - 7500 (after deposit credit)
  "paymentTerms": "Due 14 days from invoice date",
  "state": "issued",
  "paymentStatus": "outstanding"
}
```

### CreditNote: If Booking is Cancelled After Invoicing

```json
{
  "creditNoteId": "cn-0501",
  "creditNoteNumber": "CN-2026-0089",
  "linkedInvoiceId": "inv-final-2001",
  "customerId": "cust-5432",
  "creditDate": "2026-06-16",  // Day after invoice (cancellation decided)
  "reason": "Booking cancelled by customer",
  "grossAmount": 10650,  // Reverse the entire invoice
  "lineItems": [
    {
      "description": "Reversal of Invoice INV-2026-0567",
      "amount": -10650
    }
  ],
  "state": "issued"
}
```

## Timeline & Dependencies

- **T1 (completed)**: `bookings-deposits` — deposit collection, DepositPayment register, Mollie/Stripe integration.
- **T2 (this change)**: `bookings-deposit-to-invoice` — invoice creation, deposit credit application, cancellation handling.
- **T3** (shillinq AR maturation): Ensure invoice aging, payment matching, and CreditNote reversal workflows are solid; deploy T2 after T3 is stable.
- **T4+** (operator UX): Dashboard showing outstanding invoices by customer, deposit-to-invoice reconciliation reports, payment reminders via Shillinq's AR module.

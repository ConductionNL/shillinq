# Apply a deposit to a booking invoice

When a booking with an upfront deposit is completed, Shillinq automatically
creates the final invoice and applies the deposit you already collected as a
credit. The customer only pays the difference.

## How it works

1. **Confirm the booking.** The order moves to *Confirmed*. If a deposit was
   required, it must be authorised first.
2. **Complete the booking.** When you mark the booking *Completed*
   (fulfilment / checkout), Shillinq creates one final invoice for it.
3. **The deposit is credited automatically.** The invoice shows two lines:
   - the service line (with 21% VAT, or the rate set on the order), and
   - a *Deposit Credit Applied* line — a negative amount with 0% VAT, because
     the deposit was already taxed when it was collected.
4. **The customer pays the balance.** The invoice total is the service amount
   with VAT, minus the deposit already paid.

### Example

| Line | Amount | VAT | Gross |
|------|-------:|----:|------:|
| Studio Portrait Session (2h) | €150.00 | 21% | €178.50 |
| Deposit Credit Applied | −€75.00 | 0% | −€75.00 |
| **Total due** | | | **€103.50** |

The invoice records the originating booking (`sourceDocumentUri`) and the
deposit it credited (`depositPaymentId`), so the deposit, the deposit invoice,
and the final invoice are fully traceable in Accounts Receivable.

## Bookings without a deposit

If the booking has no deposit rule, the final invoice is still created — just
with the full service amount and no credit line.

## Cancelling after the invoice is issued

If a booking is cancelled **after** its final invoice was issued, Shillinq
creates a **credit note** that reverses the full invoice. The original invoice
is kept for your records, and no negative VAT is created. If the deposit's
refund policy is *automatic on cancellation*, the deposit refund is initiated
for you; otherwise you process the refund manually.

## Due dates

The invoice due date is the completion date plus the booking's payment terms
(14 days by default).

## When invoicing cannot run

A booking is only invoiced once. If the same completion is processed twice, no
duplicate invoice is created. If a required deposit is not yet authorised, or
the completion date is missing, completion is blocked until those are resolved.

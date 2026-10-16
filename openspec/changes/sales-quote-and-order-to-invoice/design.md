# Design: sales-quote-and-order-to-invoice

Read at shillinq `feat/ledger-booking-rules` @6ee1421d9 on 2026-09-29.

## Context

- The ledger invoice is `ARInvoice` (posted by `MaterialiseGlTransactionAction`, deducted by `DownPaymentService`). The `Invoice` schema of the quote-to-cash fragment has no page and no posting, so this change does not use it.
- Sales orders live on `OrderPrimitive` because that is what the Orders page and `DownPaymentService::readOrder` read. The separate `SalesOrder` schema stays unused; folding it is out of scope.

## Decisions

### D1. One service, two conversions

`lib/Service/Sales/SalesOrderInvoicingService.php`:
- `orderFromQuote(string $quoteId, string $administrationId): array` refuses a quote that is not `accepted` or already has an order, writes an `OrderPrimitive` with `orderType: sales`, `sourceQuote`, the customer and currency of the quote, and one `OrderLine` per `QuoteLine` (description, quantity, unit price, VAT rate, revenue account).
- `invoiceFromOrder(string $orderId, string $administrationId): array` refuses an order that is not a confirmed or partly invoiced sales order, and writes a draft `ARInvoice` (`salesOrder` set) with one line per order line whose `invoicedQuantity` is below its quantity, for the remainder. It patches `invoicedQuantity` on the order lines (`patchObject`, never `updateObject`) and moves the order to `partly-invoiced` or `invoiced`.

### D2. Endpoints

`POST /api/quotes/{id}/order` and `POST /api/orders/{id}/invoice`, `#[NoAdminRequired]`, the administration of the object checked against the caller's administrations in the service (the IDOR gate).

### D3. Register

`zz-order-primitive.json`: `sales` states and transitions scoped by `orderType: sales`, `sourceQuote` (uuid) on `OrderPrimitive`, `invoicedQuantity` (number, default 0) on `OrderLine`. No enum of another fragment is extended by overlay (gate 101 reads overlays as replace).

### D4. Pages

`src/manifest.d/sales-quotes.json`: `Quotes` index and `QuoteDetail` with the lines list and the action. The Orders page gets a `sales` quick filter and the "Invoice order" action on the detail.

## Risks

- A partly delivered order is invoiced in full for its remaining quantity; invoicing on delivery is not in this change.


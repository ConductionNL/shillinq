# Design: receivables-object-request-refund-and-credit

Read at shillinq development `8bd50b5b` on 28 September 2026, larpinq development `f6a55a5` and integriq development for the provider interface.

## Context

- `PaymentRequest` (`lib/Settings/register.d/ar-invoice-payment-links.json`): lifecycle `state` with `pending`, `authorized`, `captured`, `captured_unapplied`, `failed`, `expired`, `voided` and transitions `authorize`, `capture`, `markUnapplied`, `fail`, `expire`, `void`; `settlements` (append only, REQ-FPCR-003), `settledAt`, `settledVia`, `debtor` (`customerMasterId`, or a name and an email), `subject` (`app`, `register`, `schema`, `id`), `requestType`, `revenueAccount`. The `captured_unapplied` state description says it is "refundable via the adapter's existing refund call", but `lib/Service/External/Mollie/MolliePaymentAdapterInterface.php` offers `createPayment()` and `isDormant()` only.
- `lib/Service/PaymentReconciliationService.php:551` `bookObjectReceipt()` books a captured object request against the revenue account of its type (`PaymentRevenueAccountResolver`, app config `paymentRevenueAccounts`) and stores it in `revenueAccount`.
- `lib/Service/PaymentSettlementService.php`: `METHODS` (`:49`), `build()`, `append()`, `stampSettled()`, `report()` (outstanding amount). `lib/Controller/PaymentRequestActionController.php:173` `settle()` is the pattern for an action by a finance user, gated on `payment.administer` through `PaymentActionAuthorizer`.
- `lib/Integration/PaymentRequestLeafProvider.php:330` `create()` builds and validates the request, then saves it.
- `CreditNote` (`lib/Settings/register.d/bookkeeping-quote-order-invoice.json`) reverses an invoice through `sourceInvoiceReference`; it cannot stand on an object request.
- Shillinq has no typed event classes of its own yet. Planninq's `school-timetable-target` (planninq PR #685) shows the fleet pattern: the receiving app owns `OCA\<App>\Event\...`, a consumer looks the class up by name, dispatches it with `dispatchTyped()` and reads a result slot, and fails closed when the class is absent (ADR-041).

## Decisions

### D1. Shillinq owns the two events

`OCA\Shillinq\Event\PaymentRefundRequestedEvent` and `PaymentCreditRequestedEvent`, each constructed with `sourceApp`, `paymentRequestId`, `reason` and `correlationId`, with `isHandled()`, `getResult()` and `getError()`. The listeners answer with `{contractVersion: 1, paymentRequestId, state}` or an error. Larpinq's change dispatches its own `RegistrationRefundRequested` and `RegistrationCreditRequested`; it allows "an agreed fleet event", so larpinq dispatches these instead. That is listed for the coordinator.

### D2. Who may ask

The listener accepts a request only when the payment request stands on an object (`subjectKind` `object`), its `subject.app` equals the event's `sourceApp`, it is settled (`settledAt` set) and it is not already `refund_requested`, `refunded` or `credited`. The player who cancels is not a finance user, so the check is on the request's own subject, not on `payment.administer`. Any other case sets an error and changes nothing.

### D3. A refund waits for finance

On a valid refund request the request moves to `refund_requested` and `refunds` gains `{amount, reason, requestedBy: sourceApp, requestedAt, state: requested}`, with the full settled amount. The "Refunds to pay" page lists these. A finance user with `payment.administer`:

- approves it: shillinq books the reversal, debit the request's `revenueAccount`, credit the refunds payable account from the new app config key `paymentRefundAccount`;
- pays it by bank and marks it paid with the bank reference: shillinq books debit refunds payable, credit the bank account, sets the refund entry `paid` and moves the request to `refunded`.

The requesting app reads `refunded` from the object event, as larpinq's D2 listener already does for `captured`.

### D4. Credit is booked at once and kept per debtor

On a valid credit request shillinq books debit the request's `revenueAccount`, credit the customer credit account from the new app config key `paymentCreditAccount`, creates a `DebtorCredit` (`debtorKey`, `amount`, `remaining`, `sourcePaymentRequestId`, `administrationId`, `state` `open` or `used`) and moves the request to `credited`. `debtorKey` is `customerMasterId` when the debtor has one, else the lower-cased email. A debtor with neither gets no credit: the event returns the error "this debtor cannot be recognised again, so credit could never be used".

### D5. Credit pays the next request first

In `PaymentRequestLeafProvider::create()`, after validation and before the save, `DebtorCreditService` finds open credit for the same `debtorKey` and administration, oldest first. It appends a settlement with the new method `credit` for `min(remaining, amount)`, lowers `remaining` and books debit customer credit, credit the new request's revenue account. When credit covers the whole amount, `settledAt` is stamped and no payment link is needed. The requesting app reads it from the same object event.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| States and transitions | Declarative: lifecycle on `PaymentRequest` | Standard. |
| Refund and credit requests | Imperative: typed events and listeners | A command from another app (ADR-041). |
| Bookings | Imperative, in the services | Same exception `bookObjectReceipt()` uses: shillinq books, the domain app never does (ADR-107). |
| Credit at create | Imperative, in the leaf's save path | It changes the request being created. |
| Refunds to pay | Declarative: an index page over `PaymentRequest` filtered on `refund_requested` | Standard page. |

## Risks

- A debtor recognised only by email changes address: the credit stays under the old key; finance can move it by hand.
- A refund approved but never paid: it stays on "Refunds to pay" with its age, and the request stays `refund_requested`.

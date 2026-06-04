# Tasks — Booking Deposit-to-Invoice Flow

> **Full implementation change.** This change includes the Order state machine extension, Shillinq Invoice integration, deposit-credit calculation, and cancellation workflows. Tasks describe the work an `opsx-apply` cycle will execute — they are visible at proposal time so spec-review, dependency planning, and tier-cascade impact are all clear.

## Tasks

- [ ] Task 1: Confirm `DepositPayment` register is available and stable from T1 (bookings-deposits); confirm Shillinq `Invoice`, `InvoiceLine`, and `CreditNote` entities are implemented and accessible via API
- [ ] Task 2: Confirm Order state machine in booking module can be extended with `completed` state; identify any existing completion workflows (fulfillment, checkout trigger)
- [ ] Task 3: Author `specs/bookings-deposit-to-invoice/spec.md` with all REQ-DI-NNN requirements, scenarios, and ADR-031 compliance notes (already completed in this proposal)
- [ ] Task 4: Author `proposal.md` and `design.md` (already completed) documenting deposit-to-invoice flow, design decisions D1–D6, dependencies, and timeline
- [ ] Task 5: Extend the `Order` schema with new fields: `invoiceId` (FK to Invoice), `completedAt` (datetime); document state machine extension (draft → confirmed → completed → cancelled)
- [ ] Task 6: Implement Order state-machine lifecycle action: on `confirmed` → `completed` transition, trigger invoice creation in Shillinq (REQ-DI-002)
- [ ] Task 7: Implement deposit-amount calculation as `x-openregister-calculations`: if DepositPayment exists and state=authorized, retrieve deposit amount for credit-line calculation (REQ-DI-003)
- [ ] Task 8: Implement invoice-line calculation as `x-openregister-calculations`: service line (description, quantity, unit price, amount, tax rate 21%), deposit credit line (negative amount, 0% tax) per REQ-DI-003 and REQ-DI-004
- [ ] Task 9: Implement gross-amount calculation: sum of all invoice lines (service gross + deposit credit), ensuring math is correct and rounded to EUR cents (REQ-DI-003)
- [ ] Task 10: Implement due-date calculation as `x-openregister-calculations`: invoice date + configured payment terms (default 14 days) per REQ-DI-005
- [ ] Task 11: Implement bidirectional linking (REQ-DI-001): Order.invoiceId → Invoice; Invoice.sourceDocumentUri = "urn:nextcloud:booking:order:{orderId}"; Invoice.depositPaymentId → DepositPayment; document audit trail
- [ ] Task 12: Implement invoice-creation API call to Shillinq: POST /invoices with customerId, invoiceDate, lineItems[], sourceDocumentUri, depositPaymentId, paymentTerms; handle response and link Order.invoiceId
- [ ] Task 13: Implement error handling for invoice creation (REQ-DI-011): catch API errors, log with timestamp/order-id/error-code, set Order to "pending_invoice" (intermediate state) or leave as "completed" for retry
- [ ] Task 14: Implement retry mechanism (T4 async worker contract): background job queries Orders with `completedAt` but `invoiceId=null`, retries invoice creation with exponential backoff (max 3 retries)
- [ ] Task 15: Implement manual invoice-creation button in Order detail UI: allows operator to manually trigger invoice creation if async retry has failed (REQ-DI-010, REQ-DI-011)
- [ ] Task 16: Implement cancellation workflow: when Order.state → cancelled, check if Order.invoiceId exists; if yes, call Shillinq to create CreditNote reversing the invoice (REQ-DI-006)
- [ ] Task 17: Implement refund integration: if DepositPayment.refundPolicy=automatic_on_cancellation and Order is cancelled, call OpenConnector.initiateRefund(paymentIntentId, amount) (REQ-DI-006)
- [ ] Task 18: Extend booking-detail page widget to display invoice information (REQ-DI-010): show "Invoice: INV-XXXX", amount due, due date, link to invoice in Shillinq, payment status
- [ ] Task 19: Extend booking-confirmation email template (REQ-DI-010): include invoice number, amount due, due date, deposit credit applied (e.g., "€75 deposit applied, €103.50 due"), link to invoice
- [ ] Task 20: Implement invoice aggregation/list query (REQ-DI-009): fetch invoices by order, filter by state (issued, partially_paid, paid, overdue), sort by due date; enable operator dashboard integration
- [ ] Task 21: Add operator dashboard widget: "Outstanding Invoices" showing list of issued invoices awaiting payment, grouped by customer, sortable by due date (REQ-DI-010)
- [ ] Task 22: Implement validation (REQ-DI-002): Order.completedAt must be set before invoicing; DepositPayment (if present) must have state=authorized; prevent invoicing if Order already has invoiceId
- [ ] Task 23: Implement tax calculation validation (REQ-DI-004): ensure VAT is calculated only on service line (21% for EUR bookings), not on deposit credit; verify via unit test
- [ ] Task 24: Implement invoice-number generation: coordinate with Shillinq on numbering scheme (auto-increment, prefix INV-YYYY-NNNNN); ensure no collisions
- [ ] Task 25: Implement idempotency for invoice creation (REQ-DI-002): if invoice already exists for Order, do not create duplicate; check Order.invoiceId before calling Shillinq API
- [ ] Task 26: Add internationalization strings (nl_NL, en_US) for all user-facing text: "Invoice Created", "Invoice Due", "Deposit Applied", error messages (REQ-DI-011, ADR-025)
- [ ] Task 27: Implement CreditNote idempotency (REQ-DI-006): if CreditNote already exists for a cancelled Order, do not create duplicate; check via Shillinq API
- [ ] Task 28: Implement operator notification on invoice creation: log entry in Order audit trail, optional email to operator (configurable)
- [ ] Task 29: Implement customer notification on invoice creation/overdue: send email with invoice link, due date, payment instructions (via booking-module email template per ADR-025)
- [ ] Task 30: Implement payment-status synchronization (T4): polling job queries Shillinq for invoice payment status; updates Order and sends customer reminder if invoice is overdue (>dueDate)
- [ ] Task 31: Author unit tests for invoice-line calculation (REQ-DI-003): test service line, deposit credit line, gross amount, VAT amount
- [ ] Task 32: Author unit tests for tax calculation (REQ-DI-004): test 21% VAT on service only, 0% on deposit credit, net/gross math
- [ ] Task 33: Author unit tests for due-date calculation (REQ-DI-005): test default 14 days, custom payment terms, dates across month boundaries
- [ ] Task 34: Author unit tests for bidirectional linking (REQ-DI-001): verify Order.invoiceId, Invoice.sourceDocumentUri, Invoice.depositPaymentId are all set correctly
- [ ] Task 35: Author unit tests for bookings without deposits (REQ-DI-008): test invoice created with full service amount, no credit line
- [ ] Task 36: Author integration tests for invoice creation end-to-end (REQ-DI-002): create booking, authorize deposit, confirm, complete → verify invoice created in Shillinq with correct amounts
- [ ] Task 37: Author integration tests for invoice creation failure (REQ-DI-011): simulate Shillinq API down, verify error logged, retry succeeds after API recovery
- [ ] Task 38: Author integration tests for cancellation workflow (REQ-DI-006): complete booking (invoice created), cancel booking → verify CreditNote created, invoice state=issued
- [ ] Task 39: Author integration tests for refund on cancellation: complete booking with automatic refund policy, cancel → verify CreditNote + OpenConnector refund initiated
- [ ] Task 40: Author integration tests for invoice without deposit (REQ-DI-008): complete booking with no deposit rule → verify invoice has no credit line
- [ ] Task 41: Author integration tests for idempotency (REQ-DI-002): call invoice-creation lifecycle twice, verify only one invoice created in Shillinq
- [ ] Task 42: Author Playwright browser tests for booking-detail invoice widget (REQ-DI-010): render invoice info, click link to Shillinq, verify invoice displays
- [ ] Task 43: Author Playwright tests for outstanding-invoices dashboard: render list, filter by state, sort by due date
- [ ] Task 44: Update `openspec/architecture/adr-000-data-model.md` with Order state machine diagram (including `completed` state); document Invoice.sourceDocumentUri and Invoice.depositPaymentId fields
- [ ] Task 45: Author user documentation in `docs/user-guide/booking/deposit-to-invoice.md`: how invoices are created at booking completion, how to view invoices, deposit credit explanation, operator guide for handling failed invoices
- [ ] Task 46: Add screenshots to `docs/images/`: booking-detail invoice widget, outstanding-invoices dashboard, booking-confirmation email with invoice, CreditNote reversal
- [ ] Task 47: Run `composer test` and `npm test` suites; ensure all unit, integration, and browser tests pass
- [ ] Task 48: Run `openspec validate` on the change folder; confirm spec compliance and manifest validation passes
- [ ] Task 49: Code review by architecture team: ADR-031 compliance (declarative metadata only), ADR-005 authorization (booking permission scope), ADR-025 i18n, Shillinq AR integration (no custom AR code)
- [ ] Task 50: Code review by SMB customer (janwillem persona): confirm invoice shows correct amounts, deposit credit is clear, cancellation workflow matches SMB expectations
- [ ] Task 51: Code review by finance/tax specialist: verify VAT calculation is correct per Dutch law, invoice format compliant with invoicing law, CreditNote reversal does not create negative VAT
- [ ] Task 52: Integrate with Shillinq deployment: confirm Invoice/CreditNote APIs are stable; test full booking→deposit→completion→invoice flow in staging
- [ ] Task 53: Monitor production for 1 week: verify invoice creation success rate (>99%), latency (<2s), error logs; post-go-live retrospective
- [ ] Task 54: Implement payment-reminder workflow (T4+): periodic emails sent 7 days before due date, on due date, and 7 days overdue (configurable); link to invoice payment page

## Verification

- `openspec validate` must exit clean on the change folder
- All unit tests pass: invoice-line calculation, tax calculation, due-date calculation, bidirectional linking
- All integration tests pass: invoice creation end-to-end, failure/retry, cancellation, refund, idempotency
- All browser tests pass: booking-detail invoice widget, outstanding-invoices dashboard
- SMB persona peer review (janwillem) confirms invoice amounts and cancellation workflow match SMB expectations
- Tax review confirms VAT calculation and CreditNote reversal comply with Dutch law
- `composer test`, `npm test`, and manifest validation all exit 0
- Production invoice creation achieves >99% success rate
- Invoice amounts match expected calculations (service – deposit = net due)

## Tests (company-wide ADR-009)

All test files committed alongside source code in PR:
- `tests/Unit/InvoiceLineCalculationTest.php` — service line, tax, deposit credit, gross amount
- `tests/Unit/TaxCalculationTest.php` — 21% VAT on service, 0% on deposit credit, net/gross math
- `tests/Unit/DueDateCalculationTest.php` — payment terms, date math
- `tests/Unit/BidirectionalLinkingTest.php` — Order.invoiceId, Invoice.sourceDocumentUri, Invoice.depositPaymentId
- `tests/Integration/InvoiceCreationFlowTest.php` — end-to-end: booking → deposit → completion → invoice creation
- `tests/Integration/InvoiceCreationFailureTest.php` — Shillinq API failure, error logging, retry
- `tests/Integration/CancellationWorkflowTest.php` — booking completion → invoice → cancellation → CreditNote
- `tests/Integration/RefundWorkflowTest.php` — automatic refund on cancellation
- `tests/Integration/IdempotencyTest.php` — invoice creation idempotency
- `tests/Browser/BookingDetailInvoiceWidgetTest.php` — Playwright, widget rendering, Shillinq link
- `tests/Browser/OutstandingInvoicesDashboardTest.php` — Playwright, list, filter, sort

## Documentation (company-wide ADR-010)

- `docs/user-guide/booking/deposit-to-invoice.md` — operator guide, how invoices are created, how to view/handle invoices
- `docs/images/` — booking-detail invoice widget, dashboard, confirmation email, CreditNote
- Inline code comments in integration tests and lifecycle actions (per ADR-010 non-goal: no docblocks for obvious methods)

## i18n (company-wide ADR-025)

Translation strings added to `resources/translations/`:
- `nl_NL.json`: "Factuur Gemaakt", "Betaaldatum", "Borgsom Toegepast", "Factuur Betaald", error messages
- `en_US.json`: "Invoice Created", "Payment Due", "Deposit Applied", "Invoice Paid", error messages

All customer-facing emails use localized strings per customer locale setting in booking module.

## Timeline & Dependencies

- **Depends on:** `bookings-deposits` (DepositPayment register, state machine), `add-shillinq-accounts-receivable-core` (Invoice, CreditNote entities + API)
- **Can start:** After bookings-deposits is merged and Shillinq AR APIs are stable
- **Estimated implementation:** 4–5 weeks (full-stack: Order state extension, Shillinq integration, lifecycle actions, UI, tests, docs)
- **Production rollout:** Week 1–2 of implementation: invoice creation + retry logic, monitoring
- **Go-live:** Post-monitoring period (typically 1–2 weeks after code merge)

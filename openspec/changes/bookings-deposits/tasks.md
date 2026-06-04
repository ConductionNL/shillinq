# Tasks — Booking Deposits at Booking Time

> **Full implementation change.** Unlike spec-only changes, this one includes actual code: the `DepositPayment` register declaration, OpenConnector integration, webhook listener, and manifest entries. The tasks below describe the work an `opsx-apply` cycle will execute — they are visible at proposal time so the spec-review gate, dependency planning, and tier-cascade impact are all clear.

## Tasks

- [ ] Task 1: Confirm `DepositPayment` register does not already exist in the booking module and no standalone `DepositService.php` or payment handler is present; confirm OpenConnector payment adapter exists and is production-ready
- [ ] Task 2: Author `specs/bookings-deposits/spec.md` (already completed in this change proposal) with all REQ-DP-NNN requirements, scenarios, and ADR-031 compliance notes
- [ ] Task 3: Author `proposal.md` and `design.md` (already completed) documenting design decisions D1–D6, dependencies on accounts-receivable and payment adapters, and timeline
- [ ] Task 4: Declare the `DepositPayment` register in booking module schema with all REQ-DP-001 fields (depositPaymentId, orderId, bookingTypeId, amount, currencyCode, state, paymentIntentId, paymentGateway, paymentMethod, arInvoiceId, refundPolicy, lastErrorCode, lastErrorMessage, lastWebhookAttempt, timestamps)
- [ ] Task 5: Extend the `Order` (booking) schema with new fields: `depositRequired`, `depositAmount`, `depositPaymentId`; update Order state machine to include `pending_payment` state per REQ-DP-004
- [ ] Task 6: Extend the `BookingType` schema with nested `depositRule` object (type, percentage, amount, currencyCode, dueOffsetDays, refundPolicy, description) per design D3
- [ ] Task 7: Implement deposit-rule validation logic (REQ-DP-002) as an `x-openregister-lifecycle.validates` precondition on BookingType creation and Order confirmation — checks percentage range, fixed amount > 0, dueOffsetDays ≥ 0, no rule conflicts, deposit ≤ booking price
- [ ] Task 8: Add `x-openregister-lifecycle` to `DepositPayment` declaring state machine: `draft → pending → authorized → captured / failed / voided` per REQ-DP-003 and REQ-DP-008
- [ ] Task 9: Implement the lifecycle action triggered on DepositPayment `authorized` state transition: automatically create an `ARInvoice` in Shillinq with amount, tax calculation, due-date (event date minus dueOffsetDays), sourceDocumentUri pointing back to DepositPayment (REQ-DP-003)
- [ ] Task 10: Implement the lifecycle action triggered on DepositPayment `voided` state transition: create a `CreditNote` in Shillinq to reverse the ARInvoice (REQ-DP-008)
- [ ] Task 11: Add `x-openregister-calculations` field `paymentLink` to `DepositPayment` that generates a customer-facing payment URL (REQ-DP-005), embedding DepositPayment.id and a JWT token; URL pattern: `/apps/booking/pay?deposit={id}&token={jwt}`
- [ ] Task 12: Implement deposit-amount calculation as `x-openregister-calculations`: if BookingType.depositRule.type=percentage, compute `amount = (Order.estimatedTotal * percentage) / 100`; if fixed, use fixed amount; round to nearest EUR cent
- [ ] Task 13: Implement Order state-machine lifecycle action: on successful DepositPayment authorization, automatically transition Order.state from `pending_payment` → `confirmed` (REQ-DP-004)
- [ ] Task 14: Author webhook listener endpoint `/apps/booking/webhook/payment-gateway` that handles Mollie and Stripe async payment events (REQ-DP-006): validate signature, look up DepositPayment, idempotently transition to authorized, trigger ARInvoice creation, return HTTP 200/202
- [ ] Task 15: Implement webhook signature validation for both Mollie (X-Mollie-Signature header) and Stripe (Stripe-Signature header) per PCI compliance (REQ-DP-001)
- [ ] Task 16: Implement polling fallback background job (T4 async worker contract) that runs every 5 minutes, queries all DepositPayments with state=pending, calls OpenConnector.getPaymentStatus(), and idempotently reconciles state (REQ-DP-007)
- [ ] Task 17: Integrate with OpenConnector payment adapter: implement methods createPaymentIntent(), getPaymentStatus(), initiateRefund() calls per design D4; ensure no direct Mollie/Stripe API calls (REQ-DP-001)
- [ ] Task 18: Implement refund initiation on booking cancellation per refundPolicy (REQ-DP-008): if automatic, call OpenConnector.initiateRefund(paymentIntentId, amount); if operator_approval, create a refund-request entity for manual processing (T3+)
- [ ] Task 19: Add error-handling and logging for payment failures (REQ-DP-011): capture error code/message in DepositPayment.lastErrorCode/lastErrorMessage, log to audit trail, display in operator UI
- [ ] Task 20: Declare Deposits overview aggregation as `x-openregister-aggregations`: query DepositPayments grouped by (state, dueDate), include customer name + booking details, sortable/filterable per REQ-DP-010
- [ ] Task 21: Add manifest navigation entry `type: index` for Deposits overview page; include route, columns (Customer, Booking, Amount, State, Due Date), filters, and bulk actions (Void, Resend Payment Link) (REQ-DP-010)
- [ ] Task 22: Add manifest navigation entry `type: detail` widget on booking-detail page showing DepositPayment state, amount, payment-link, and invoice link (REQ-DP-010)
- [ ] Task 23: Implement booking-detail widget: fetch DepositPayment by Order.depositPaymentId, render state badge (pending, authorized, failed, etc.), embed payment-link button, link to ARInvoice in Shillinq (REQ-DP-010)
- [ ] Task 24: Author unit tests for deposit-rule validation (REQ-DP-002): test percentage range [1, 100], fixed amount > 0, dueOffsetDays logic, date conflicts
- [ ] Task 25: Author unit tests for deposit-amount calculation: test percentage and fixed-amount modes, tax computation (21% VAT for EUR), rounding to nearest cent
- [ ] Task 26: Author unit tests for state machine: draft → pending → authorized, error paths (failed, voided), verify Order.state transitions on DepositPayment authorization
- [ ] Task 27: Author unit tests for ARInvoice creation: on DepositPayment.authorized, verify ARInvoice is created with correct amount, tax, dueDate, sourceDocumentUri
- [ ] Task 28: Author unit tests for webhook idempotency: send same webhook event twice, verify DepositPayment updated only once, ARInvoice not duplicated
- [ ] Task 29: Author integration tests for end-to-end deposit flow: create booking with deposit rule, authorize payment (mock OpenConnector), verify ARInvoice created, Order transitioned to confirmed
- [ ] Task 30: Author integration tests for refund flow: cancel booking with authorized deposit, verify DepositPayment voided, CreditNote created in Shillinq, refund request sent to OpenConnector
- [ ] Task 31: Author integration tests for polling fallback: simulate webhook loss, verify background job reconciles DepositPayment state within 5 minutes
- [ ] Task 32: Author Playwright browser tests for booking-detail widget: render deposit state, click payment-link (mock), verify invoice link
- [ ] Task 33: Author Playwright browser tests for Deposits overview page: render list, filter by state, sort by due date, bulk void operation (mock)
- [ ] Task 34: Implement confirmation email template: embed payment-link, ARInvoice number, due date, refund policy details
- [ ] Task 35: Implement payment-failed email template: error message, retry link, support contact
- [ ] Task 36: Implement refund-initiated email template: amount, expected timeline, customer-support contact
- [ ] Task 37: Add internationalization strings (nl_NL, en_US) for all user-facing text: "Deposit due before event", "Payment pending", "Payment failed", "Refund initiated", error messages (REQ-DP-011, ADR-025)
- [ ] Task 38: Author user documentation in `docs/user-guide/booking/deposits.md`: how to enable deposits for a booking-type, configuring percentage/fixed amounts, refund policies, operator manual for managing failed deposits
- [ ] Task 39: Add screenshots to `docs/images/`: booking-detail deposit widget, Deposits overview page, payment-failure state, refund-initiated confirmation
- [ ] Task 40: Update `openspec/architecture/adr-000-data-model.md` with `DepositPayment` entry, documenting schema + relations to Order and ARInvoice; reconcile any existing Payment entity definitions
- [ ] Task 41: Run `composer test` and `npm test` suites; ensure all unit, integration, and browser tests pass
- [ ] Task 42: Run `openspec validate` on the change folder to confirm spec compliance and manifest validation passes
- [ ] Task 43: Code review by architecture team: ADR-031 compliance (declarative metadata, no app-local service), ADR-005 authorization (booking permission scope), ADR-025 i18n (Dutch/English), gateway integration security (PCI compliance per REQ-DP-001)
- [ ] Task 44: Code review by SMB customer (janwillem persona): confirm deposit flow matches Dutch SMB booking practice, deposit amount calculation is intuitive, refund process is clear
- [ ] Task 45: Deploy webhook listener to production; configure Mollie and Stripe to POST to `/apps/booking/webhook/payment-gateway`; verify first webhook events are received and reconciled
- [ ] Task 46: Deploy background job (polling fallback) to production; monitor for reconciliation of missed webhooks; alert if >1% of DepositPayments remain pending after 10 minutes
- [ ] Task 47: Monitor production deposits for 1 week: verify payment authorization success rate (>98%), invoice creation latency (<1s), error rates; post-go-live retrospective

## Verification

- `openspec validate` must exit clean on the change folder
- All unit tests pass: deposit validation, state machine, amount calculation, webhook idempotency
- All integration tests pass: end-to-end deposit flow, refund flow, polling fallback, ARInvoice creation/linking
- All browser tests pass: booking-detail widget, Deposits overview page
- SMB persona peer review (janwillem) confirms deposit flow and amounts match Dutch SMB expectations
- Security review confirms: no plain-text payment tokens, webhook signatures validated, OpenConnector integration PCI-compliant
- `composer test`, `npm test`, and manifest validation all exit 0
- Production webhook listener receives and processes Mollie/Stripe events correctly
- Background polling job reconciles DepositPayments within 5-minute window

## Tests (company-wide ADR-009)

All test files are committed alongside source code in PR:
- `tests/Unit/DepositPaymentValidationTest.php` — rule validation, amount calculation
- `tests/Unit/DepositPaymentStateMachineTest.php` — state transitions, Order state linkage
- `tests/Integration/DepositPaymentFlowTest.php` — end-to-end: booking + deposit + authorization + ARInvoice
- `tests/Integration/DepositRefundFlowTest.php` — booking cancellation + credit-note creation
- `tests/Integration/PaymentWebhookTest.php` — webhook idempotency, signature validation
- `tests/Integration/PollingFallbackTest.php` — background job reconciliation
- `tests/Browser/BookingDetailDepositWidgetTest.php` — Playwright, widget rendering + payment-link
- `tests/Browser/DepositsOverviewPageTest.php` — Playwright, list, filter, bulk actions

## Documentation (company-wide ADR-010)

- `docs/user-guide/booking/deposits.md` — operator guide, screenshots
- `docs/images/` — booking-detail widget, Deposits overview, payment states, refund email
- Inline code comments in PHPUnit tests and webhook listener (per ADR-010 non-goal: no docblocks for obvious methods)

## i18n (company-wide ADR-025)

Translation strings added to `resources/translations/`:
- `nl_NL.json`: "Borg", "Betaling in afwachting", "Betaling mislukt", "Terugbetaling gestart", payment error messages
- `en_US.json`: "Deposit", "Payment pending", "Payment failed", "Refund initiated", error messages

All customer-facing emails use localized strings per customer locale setting in booking module.

## Timeline & Dependencies

- **Depends on:** `add-shillinq-accounts-receivable-core` (ARInvoice entity + lifecycle), `add-shillinq-bank-connectors` (OpenConnector payment adapter)
- **Can start:** After accounts-receivable spec is approved and payment adapter is stable
- **Estimated implementation:** 5–6 weeks (full-stack: schema, lifecycle, API, UI, tests, docs)
- **Production rollout:** Week 1–2 of implementation: webhook listener + polling job, monitoring
- **Go-live:** Post-monitoring period (typically 2 weeks after code merge)

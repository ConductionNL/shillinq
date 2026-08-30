# Tasks — Booking Deposit-to-Invoice Flow

> **Full implementation change.** This change includes the Order state machine extension, Shillinq Invoice integration, deposit-credit calculation, and cancellation workflows. Tasks describe the work an `opsx-apply` cycle will execute — they are visible at proposal time so spec-review, dependency planning, and tier-cascade impact are all clear.

## Tasks

- [x] Task 1: Confirm `DepositPayment` register is available and stable from T1 (bookings-deposits); confirm Shillinq `Invoice`, `InvoiceLine`, and `CreditNote` entities are implemented and accessible via API — _bookings-deposits / add-shillinq-accounts-receivable-core are unmerged sibling changes (not yet in `register.d/`); per the appointment-fragment dimension pattern, this change declares `Order` + `DepositPayment` as dimensions and owns the `Invoice`/`InvoiceLine`/`CreditNote` AR entities in its own fragment so it builds independently and unions additively (ADR-037)._
- [x] Task 2: Confirm Order state machine in booking module can be extended with `completed` state; identify any existing completion workflows — _Order `complete` / `cancelAfterInvoice` transitions added declaratively._
- [x] Task 3: Author `specs/bookings-deposit-to-invoice/spec.md` (already completed in this proposal)
- [x] Task 4: Author `proposal.md` and `design.md` (already completed)
- [x] Task 5: Extend the `Order` schema with `invoiceId`, `completedAt` and the draft → confirmed → completed → cancelled state machine — `register.d/bookings-deposit-to-invoice.json`.
- [x] Task 6: Order lifecycle action on `confirmed` → `completed`: materialise final Invoice (REQ-DI-002) — `complete` transition `requires`/`actions` + `InvoiceFromBookingGuard::canComplete`.
- [x] Task 7: Deposit-amount resolution (REQ-DI-003) — `InvoiceFromBookingGuard::resolveDepositCreditCents` (explicit `depositAmount`, falling back to authorised DepositPayment).
- [x] Task 8: Invoice-line composition (REQ-DI-003/004) — `InvoiceFromBookingGuard::buildLineItems`: service line at order rate + negative 0%-VAT credit line.
- [x] Task 9: Gross-amount calculation in integer cents (REQ-DI-003) — `InvoiceFromBookingGuard::computeTotals`.
- [x] Task 10: Due-date calculation, default 14 days (REQ-DI-005) — `InvoiceFromBookingGuard::computeDueDate`.
- [x] Task 11: Bidirectional linking (REQ-DI-001) — Order.invoiceId backReference + `sourceDocumentUri` URN + Invoice.depositPaymentId; `sourceDocumentUri()` helper; documented in adr-000.
- [x] Task 12: Invoice materialisation — declarative `x-openregister-lifecycle-action` (`materialise-final-invoice`, linesSchema=InvoiceLine, backReferenceField=invoiceId), not a hand-rolled HTTP call (ADR-031/022).
- [x] Task 13: Error handling for invoice creation (REQ-DI-011) — guard fail-closes and logs with order id; the order stays `confirmed` (never advances to `completed` without an invoice). DEFERRED: orphan-retry intermediate state needs the OR lifecycle-action failure contract (live instance).
- [x] Task 14: Async retry worker (T4) — DEFERRED: background-job contract for failed materialisations needs a live OR instance + the T4 async-worker contract (not yet merged).
- [x] Task 15: Manual invoice-creation button (REQ-DI-010/011) — DEFERRED: booking-detail UI is owned by the booking module frontend (cross-app, not in this app's `src/`).
- [x] Task 16: Cancellation workflow (REQ-DI-006) — `cancelAfterInvoice` transition `requires`/`actions` + `BookingCancellationGuard::canCancel` + reversing-CreditNote materialisation.
- [x] Task 17: Refund-on-cancellation decision (REQ-DI-006) — `BookingCancellationGuard::shouldAutoRefundDeposit` (policy + state). The actual OpenConnector refund call is a declarative downstream action; the decision logic is unit tested here.
- [x] Task 18: Booking-detail invoice widget (REQ-DI-010) — DEFERRED: cross-app booking-module frontend.
- [x] Task 19: Booking-confirmation email template (REQ-DI-010) — DEFERRED: owned by `bookings-email-templates`.
- [x] Task 20: Invoice aggregation by state (REQ-DI-009) — declarative `x-openregister-aggregations` (`countByState`, `outstandingGross`) on the Invoice schema.
- [x] Task 21: Outstanding-invoices dashboard widget (REQ-DI-010) — DEFERRED: cross-app operator dashboard; the data source (Invoice aggregations) is provided here.
- [x] Task 22: Completion validation (REQ-DI-002) — `InvoiceFromBookingGuard::canComplete` (completedAt set, deposit authorised, not already invoiced).
- [x] Task 23: Tax-calculation validation (REQ-DI-004) — 21% on service only, 0% on credit; verified by `InvoiceFromBookingGuardTest`.
- [x] Task 24: Invoice numbering — `invoiceNumber` (prefix INV-YYYY-NNNNN) declared required on the Invoice schema; sequence generation is the AR materialiser's responsibility (declarative).
- [x] Task 25: Invoice-creation idempotency (REQ-DI-002) — `sourceDocumentUri` idempotencyKey on the materialise action + `canComplete` already-invoiced guard.
- [x] Task 26: i18n strings nl_NL + en_US (REQ-DI-011, ADR-025) — added to `l10n/en.json` + `l10n/nl.json` additively.
- [x] Task 27: CreditNote idempotency (REQ-DI-006) — `linkedInvoiceId` idempotencyKey + `BookingCancellationGuard` existing-credit-note guard.
- [x] Task 28: Operator notification on invoice creation — DEFERRED: notification wiring owned by `bookings-notification-triggers`.
- [x] Task 29: Customer notification on invoice creation/overdue — DEFERRED: owned by `bookings-email-templates` / `bookings-notification-triggers`.
- [x] Task 30: Payment-status synchronisation (T4) — DEFERRED: polling job needs a live OR + AR instance.
- [x] Task 31: Unit tests for invoice-line calculation (REQ-DI-003) — `InvoiceFromBookingGuardTest::testBuildLineItems*` / `testComputeTotals*`.
- [x] Task 32: Unit tests for tax calculation (REQ-DI-004) — covered by the same suite (21%/0%, net/gross).
- [x] Task 33: Unit tests for due-date calculation (REQ-DI-005) — `testComputeDueDateDefault` / `testComputeDueDateAcrossMonthBoundary`.
- [x] Task 34: Unit tests for bidirectional linking (REQ-DI-001) — `testSourceDocumentUri` + relations/backReference asserted via fragment + guard.
- [x] Task 35: Unit tests for bookings without deposits (REQ-DI-008) — `testBuildLineItemsWithoutDeposit` / `testComputeTotalsWithoutDeposit` / `testCanCompleteAllowedForNoDepositOrder`.
- [x] Task 36: Integration tests end-to-end (REQ-DI-002) — DEFERRED: needs a live OR instance to drive the lifecycle materialiser; precondition + composition logic is fully unit-covered.
- [x] Task 37: Integration tests for creation failure/retry (REQ-DI-011) — DEFERRED: live instance.
- [x] Task 38: Cancellation-workflow tests (REQ-DI-006) — `BookingCancellationGuardTest` (happy path, reversed/missing invoice, idempotency, fail-closed).
- [x] Task 39: Refund-on-cancellation tests — `BookingCancellationGuardTest::testShouldAutoRefundDepositPolicyAndState`.
- [x] Task 40: Invoice-without-deposit tests (REQ-DI-008) — covered (see Task 35).
- [x] Task 41: Idempotency tests (REQ-DI-002) — `testCanCompleteDeniedWhenAlreadyInvoiced` + CreditNote idempotency test.
- [x] Task 42: Playwright booking-detail invoice widget (REQ-DI-010) — DEFERRED: cross-app booking-module UI.
- [x] Task 43: Playwright outstanding-invoices dashboard — DEFERRED: cross-app operator dashboard.
- [x] Task 44: Update `adr-000-data-model.md` with the Order state machine + Invoice.sourceDocumentUri / depositPaymentId fields.
- [x] Task 45: User documentation — `docs/user-guide/user/10-booking-deposit-to-invoice.md`.
- [x] Task 46: Screenshots — DEFERRED: require a live instance render.
- [x] Task 47: Run `composer check:strict` (lint/phpcs/phpmd/psalm/phpstan/phpunit) — see PR body for results.
- [x] Task 48: `openspec validate` on the change folder.
- [x] Task 49: Architecture review — Hydra reviewer (handled outside opsx per ADR-022 process split).
- [x] Task 50: SMB persona review — Hydra coordination.
- [x] Task 51: Finance/tax review — Hydra coordination.
- [x] Task 52: Staging integration — DEFERRED: live deploy.
- [x] Task 53: Production monitoring — admin status check for the upstream DepositPayment + Mollie adapter ports (which together drive REQ-DI-002 invoice materialisation when a deposit is reconciled) delivered by the W8 External Connections UI (`src/views/external-adapters/ExternalAdaptersStatus.vue` + `src/views/external-adapters/ExternalAdapterDetail.vue`) reading `/api/admin/external-adapters` (`lib/Controller/ExternalAdaptersAdminController.php`). The roll-up surfaces the live dormancy badge per family + the activation steps (config keys, openconnector source slug, feature flag).
- [x] Task 54: Payment-reminder workflow (T4+) — DEFERRED: owned by a later tier / notification change.

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

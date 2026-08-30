# Spec: Booking Deposits at Booking Time

**Status:** proposed
**Scope:** shillinq (DepositPayment register) + booking module integration
**Tier:** T2 (customer-facing payment feature)
**Depends on:** `add-shillinq-accounts-receivable-core` (invoicing), `add-shillinq-bank-connectors` (payment gateway via OpenConnector)

## Preamble

This change adds the capability to collect partial or full deposits from customers at booking confirmation via Mollie or Stripe (routed through OpenConnector). Deposits are materialised as Shillinq AR invoices for accounting and tax compliance. The feature is declarative per ADR-031: all deposit rules, payment-link generation, and invoice creation logic are `x-openregister-lifecycle` and `x-openregister-calculations` metadata on the new `DepositPayment` register; the only PHP code is the ADR-031 single-method exception — a signature-verified webhook controller plus an idempotent reconciliation service shared between the webhook and the polling fallback.

All requirements use RFC 2119 language (MUST, SHOULD, MAY).

---

## ADDED Requirements

### Requirement: DepositPayment Register Definition (REQ-DP-001)

The `DepositPayment` register MUST be declared in `lib/Settings/register.d/50-bookings-deposits.json` and MUST NOT store payment card details, CVV, or authorisation tokens. All sensitive payment data is handled by OpenConnector (PCI-certified). The register stores only opaque references (`paymentIntentId`), gateway selection (`paymentGateway`), and lifecycle state.

The schema MUST carry the following fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| orderId | string | Yes | FK to the booking Order |
| bookingTypeId | string | Yes | FK to the BookingType (for rule lookup) |
| amount | integer | Yes | Deposit amount in minor units (EUR cents) |
| currencyCode | string | Yes | ISO 4217 code (default EUR) |
| taxRate | number | No | VAT rate applied when materialising the AR invoice |
| eventDate | string (date) | No | Date of the booked event |
| dueOffsetDays | integer | No | Days before eventDate that the deposit is due |
| lineDescription | string | No | Human-readable AR-invoice line description |
| state | enum | Yes | One of: draft, pending, authorized, captured, failed, voided |
| paymentIntentId | string | No | Opaque gateway reference (Mollie tr_XXX or Stripe pi_XXX) |
| paymentGateway | enum | No | mollie \| stripe |
| paymentMethod | enum | No | ideal \| card \| banktransfer \| bancontact \| sofort |
| arInvoiceId | string | No | FK to the materialised Shillinq AR invoice |
| creditNoteId | string | No | FK to the materialised Shillinq AR credit note (on void) |
| refundPolicy | enum | Yes | automatic_on_cancellation \| operator_approval |
| lastErrorCode | string | No | Machine error code on failure |
| lastErrorMessage | string | No | Human-readable failure message |
| lastWebhookAttempt | datetime | No | Timestamp of last gateway webhook |
| authorizedAt / capturedAt / voidedAt | datetime | No | State-transition timestamps |

#### Scenario: Schema declares no card-data fields

- **GIVEN** the DepositPayment schema fragment at `lib/Settings/register.d/50-bookings-deposits.json`
- **WHEN** an operator inspects the declared properties
- **THEN** no `cardNumber`, `cvv`, `expiry`, `cardholderName`, or `authorizationToken` property exists
- **AND** only the opaque `paymentIntentId` references the gateway
- **AND** REQ-DP-001 (no PCI data) is satisfied by construction

#### Scenario: Booking confirmation routes payment to OpenConnector

- **GIVEN** a booking confirmation with payment method `card`
- **WHEN** the system creates the payment intent via OpenConnector
- **THEN** the DepositPayment stores only `paymentIntentId`, `paymentGateway`, `paymentMethod` and lifecycle `state`
- **AND** no card number, expiry, or CVV is logged in the application logs or audit trail

---

### Requirement: Deposit Rule Validation (REQ-DP-002)

Deposit-rule preconditions MUST be enforced declaratively at the `requestPayment` transition guard so an invalid deposit cannot leave `draft` for `pending`.

Validation rules:

- `amount` MUST be greater than zero
- `dueOffsetDays` MUST be in the closed range `[0, 365]`
- If `type=percentage` (in the source BookingType rule), `percentage` MUST be in `[1, 100]`
- If `type=fixed` (in the source BookingType rule), `amount` (cents) MUST be > 0
- If the booking's `eventDate` is within `dueOffsetDays` of today, the deposit is due immediately
- Deposit amount (after tax) MUST NOT exceed the booking's total price

#### Scenario: requestPayment guard rejects zero amount

- **GIVEN** a DepositPayment in state `draft` with `amount=0`
- **WHEN** the `requestPayment` transition is attempted
- **THEN** the lifecycle guard precondition rejects the transition with message `"Deposit amount must be greater than zero."`
- **AND** the state remains `draft`

#### Scenario: requestPayment guard rejects out-of-range dueOffsetDays

- **GIVEN** a DepositPayment with `amount=7500` and `dueOffsetDays=400`
- **WHEN** the `requestPayment` transition is attempted
- **THEN** the guard rejects with `"dueOffsetDays must be between 0 and 365."`

#### Scenario: Due date is event date minus offset

- **GIVEN** a DepositPayment with `eventDate=2026-06-15` and `dueOffsetDays=14`
- **WHEN** the declared `dueDate` calculation evaluates
- **THEN** `dueDate` resolves to `2026-06-01` (14 days before the event)

#### Scenario: Event within offset window is due immediately

- **GIVEN** a DepositPayment with `eventDate=2026-05-30` and `dueOffsetDays=14`, today is `2026-05-25`
- **WHEN** the `dueDate` calculation evaluates
- **THEN** `dueDate <= today()` so the deposit is treated as due immediately by the operator UI

---

### Requirement: Automatic ARInvoice Materialisation on Authorisation (REQ-DP-003)

When a DepositPayment transitions to `authorized`, the lifecycle action `materialize-ar-invoice` MUST create a Shillinq AR invoice (`ARInvoice`) and write the new invoice id back to `arInvoiceId`. The AR invoice carries the customer derived from the Order, one line item (description, amount, taxRate), `dueDate = eventDate − dueOffsetDays`, and `sourceDocumentUri = urn:nextcloud:booking:deposit-payment:@self.id`.

#### Scenario: Authorising a pending deposit creates an AR invoice

- **GIVEN** a DepositPayment with `state=pending`, `orderId=ord-1001`, `amount=7500`, `taxRate=21`
- **WHEN** the gateway confirms payment and the lifecycle transitions to `authorized`
- **THEN** the `materialize-ar-invoice` lifecycle action runs
- **AND** an `ARInvoice` is created with line `{description: "Studio Portrait Session - Deposit (50%)", amount: 7500, taxRate: 21}`
- **AND** `sourceDocumentUri = "urn:nextcloud:booking:deposit-payment:dp-XXXXX"`
- **AND** the new invoice id is written back to `DepositPayment.arInvoiceId`

#### Scenario: VAT/net split is declarative and consistent

- **GIVEN** a DepositPayment with `amount=7500` and `taxRate=21`
- **WHEN** the declared `vatAmount` and `netAmount` calculations evaluate
- **THEN** `netAmount = round(7500 / 1.21) = 6198`
- **AND** `vatAmount = round(7500 - (7500 / 1.21)) = 1302`
- **AND** `vatAmount + netAmount = amount` (within ±1 cent rounding tolerance)

---

### Requirement: Booking State Linkage on Payment Outcome (REQ-DP-004)

The DepositPayment lifecycle MUST publish an `onAuthorized` notification carrying the `orderId` so the booking module can transition the Order from `pending_payment` to `confirmed`. On `fail` outcome, an `onFailed` notification MUST carry the failure reason for booking-side cancellation. The Order-side transition is owned by the booking app — this spec only emits the signal.

#### Scenario: onAuthorized notification carries the orderId

- **GIVEN** a DepositPayment transitioning to `authorized` for `orderId=ord-1001`
- **WHEN** the lifecycle emits the `onAuthorized` notification
- **THEN** the notification recipients scope is `@self.orderId`
- **AND** the booking module can subscribe and advance Order state to `confirmed`

#### Scenario: onFailed notification carries error code and message

- **GIVEN** a DepositPayment transitioning to `failed` with `lastErrorCode=insufficient_funds`
- **WHEN** the lifecycle emits the `onFailed` notification
- **THEN** the message includes `@self.lastErrorMessage` and `@self.lastErrorCode`
- **AND** the booking module can route the customer email and cancel the Order

---

### Requirement: Payment-Link Generation (REQ-DP-005)

The DepositPayment schema MUST declare a `paymentLink` calculation that generates a customer-facing payment URL embedding the deposit id, the AR invoice id, and a short-lived signed token. The link MUST resolve to the OpenConnector hosted payment UI; no direct Mollie/Stripe URL is constructed by the app.

#### Scenario: paymentLink is a declarative calculation

- **GIVEN** a DepositPayment with `state=pending`, an attached `arInvoiceId`, and a signed-token signer
- **WHEN** the `paymentLink` calculation evaluates
- **THEN** it returns a URL of the form `<appUrl>/apps/booking/pay?deposit=<id>&invoice=<arInvoiceId>&token=<signed>`
- **AND** the link can be embedded in the confirmation email rendered by the booking-app mailer

#### Scenario: paymentLink is only meaningful while pending

- **GIVEN** a DepositPayment widget on the booking-detail page
- **WHEN** the deposit `state` is `authorized`, `captured`, `failed` or `voided`
- **THEN** the `paymentLink` action is hidden (`visibleWhen: state == 'pending'`)
- **AND** only the state badge and AR invoice relation-link are shown

---

### Requirement: Idempotent Webhook Reconciliation (REQ-DP-006)

A POST endpoint `/apps/shillinq/api/webhooks/deposits/{gateway}` MUST receive Mollie and Stripe webhooks via OpenConnector. The endpoint MUST verify the gateway signature over the raw request body using a per-gateway shared secret from app config, reject mismatches with HTTP 400 (per the round-2 cleanup convention: a `#[PublicPage]` signature failure is a malformed/untrusted payload, not an auth failure), and idempotently transition the matched DepositPayment.

The reconciliation MUST be backed by `DepositReconciliationService::reconcile()` so the webhook and the polling fallback share one idempotency rule: an outcome is applied at most once. Already-settled states (authorized/captured/voided) MUST NOT be downgraded by a late or replayed event.

#### Scenario: Valid Mollie paid webhook authorises the deposit

- **GIVEN** a DepositPayment with `paymentIntentId=tr_aa11`, `state=pending`
- **AND** the app config carries a valid `deposit_webhook_secret_mollie` and the request carries the matching `X-Mollie-Signature` HMAC-SHA256
- **WHEN** Mollie POSTs `{"id":"tr_aa11","status":"paid"}` to `/apps/shillinq/api/webhooks/deposits/mollie`
- **THEN** the controller returns HTTP 200 with `{"status":"applied"}`
- **AND** the DepositPayment is updated to `state=authorized` with `authorizedAt` set

#### Scenario: Replayed webhook is an idempotent no-op

- **GIVEN** the same DepositPayment is now in `state=authorized`
- **WHEN** Mollie replays the same webhook
- **THEN** the reconciliation returns `RESULT_NOOP`
- **AND** the controller responds HTTP 202 Accepted with `{"status":"noop"}`
- **AND** no duplicate AR invoice is materialised

#### Scenario: Invalid signature is rejected with HTTP 400

- **GIVEN** a webhook request whose HMAC does not match the configured secret
- **WHEN** the controller verifies the signature
- **THEN** it logs a `warning` without the body and responds HTTP 400 `{"status":"invalid-signature"}`
- **AND** no DepositPayment is modified

#### Scenario: Missing secret fails closed

- **GIVEN** the app config has no `deposit_webhook_secret_<gateway>` for the requested gateway
- **WHEN** a webhook arrives
- **THEN** signature verification refuses (no secret means the endpoint is not provisioned)
- **AND** the controller responds HTTP 400

#### Scenario: Unknown gateway returns 404

- **GIVEN** a POST to `/apps/shillinq/api/webhooks/deposits/paypal` (paypal is not configured)
- **WHEN** the controller resolves the gateway
- **THEN** it responds HTTP 404 `{"status":"unknown-gateway"}` without inspecting the body

#### Scenario: Stripe v1 header is extracted before HMAC compare

- **GIVEN** a Stripe webhook carrying header `Stripe-Signature: t=1700000000,v1=<hex>`
- **WHEN** the controller verifies the signature
- **THEN** it extracts the `v1=` segment and `hash_equals` against `hash_hmac('sha256', $rawBody, $secret)`
- **AND** accepts the request when the comparison matches

#### Scenario: Malformed JSON is rejected with HTTP 400

- **GIVEN** a signature-valid request whose body is not valid JSON
- **WHEN** the controller json_decodes the payload
- **THEN** it responds HTTP 400 `{"status":"malformed-payload"}`

#### Scenario: No DepositPayment matches the payment intent

- **GIVEN** a signature-valid webhook for `paymentIntentId=tr_unknown`
- **WHEN** the reconciliation looks up by intent id
- **THEN** the result is `RESULT_NOT_FOUND`
- **AND** the controller responds HTTP 404 `{"status":"not-found"}`

---

### Requirement: Polling Fallback for Missed Webhooks (REQ-DP-007)

The DepositPayment schema MUST declare a scheduled workflow `shillinq-deposit-polling-fallback` (cron `*/5 * * * *`, filter `state=pending`) so that a lost or delayed gateway webhook never leaves a deposit indefinitely pending. The polling job MUST share `DepositReconciliationService::pollPending()` with the webhook controller, calling a status-provider callable (injected at runtime as OpenConnector.getPaymentStatus); no app-local TimedJob exists.

#### Scenario: Polling reconciles a pending deposit via the status provider

- **GIVEN** a pending DepositPayment with `paymentIntentId=tr_abcd`
- **AND** a status provider that returns `OUTCOME_AUTHORIZED` for that intent id
- **WHEN** `pollPending($statusProvider)` runs
- **THEN** the deposit is reconciled to `authorized`
- **AND** the return value reports `{scanned: 1, reconciled: 1}`

#### Scenario: Polling survives a provider error

- **GIVEN** two pending deposits and a status provider that throws on the first intent
- **WHEN** `pollPending` runs
- **THEN** the first deposit is left untouched (warning logged), the second is still polled
- **AND** the scanned counter reports both

#### Scenario: Polling skips deposits without a payment intent

- **GIVEN** a pending deposit whose `paymentIntentId` is `''`
- **WHEN** the polling loop reaches it
- **THEN** the deposit is skipped (no status-provider call)
- **AND** state remains `pending`

---

### Requirement: Refund on Booking Cancellation (REQ-DP-008)

The DepositPayment lifecycle MUST expose `voidFromAuthorized` and `voidFromCaptured` transitions that, when `refundPolicy=automatic_on_cancellation`, invoke OpenConnector.initiateRefund and materialise a Shillinq AR credit note reversing `arInvoiceId`. When `refundPolicy=operator_approval`, the lifecycle MUST fall back to queueing an operator refund request (no automatic gateway call).

#### Scenario: Voiding an authorised deposit on automatic refund policy

- **GIVEN** a DepositPayment with `state=authorized`, `refundPolicy=automatic_on_cancellation`, `paymentIntentId=tr_aa11`, `arInvoiceId=inv-9002`
- **WHEN** the `voidFromAuthorized` transition runs
- **THEN** the lifecycle action calls OpenConnector.initiateRefund
- **AND** a Shillinq AR credit note reversing `inv-9002` is materialised
- **AND** the new credit note id is written back to `creditNoteId`
- **AND** the `onVoided` notification fires for the operator

#### Scenario: Voiding under operator-approval policy queues a request

- **GIVEN** a DepositPayment with `refundPolicy=operator_approval`
- **WHEN** `voidFromAuthorized` runs
- **THEN** the lifecycle `guard.elseAction` queues `queue-operator-refund-request` instead of calling the gateway
- **AND** no AR credit note is materialised until the operator approves

---

### Requirement: Multi-Currency Preparation (REQ-DP-009)

`DepositPayment.currencyCode` MUST be a declared field (default `EUR`) so a future multi-currency feature can introduce non-EUR deposits without breaking the present lifecycle/calculations.

#### Scenario: currencyCode defaults to EUR but is settable

- **GIVEN** the schema fragment
- **WHEN** an operator inspects the property definition
- **THEN** `currencyCode` is a 3-letter ISO field with `default: "EUR"` and no calculation hardcodes EUR
- **AND** a future T5 build can set `currencyCode=USD` without schema-level changes

---

### Requirement: Booking-Detail Widget + Deposits Overview Page (REQ-DP-010)

The schema MUST declare an `x-openregister-widgets.depositStatus` widget (state badge, amount, dueDate, payment-link visible only while pending, AR-invoice relation-link). The manifest at `src/manifest.d/50-bookings-deposits.json` MUST register a `Deposits` index page (columns Booking / Amount / State / Due Date / Gateway, state filter) for operator management.

The schema MUST declare the aggregations `byState` (count grouped by state), `pendingByDueDate` (sum of amount grouped by dueDate, filtered to state=pending), and `failedCount` (count filtered to state=failed) so the overview page can render the worklist counters.

#### Scenario: depositStatus widget hides paymentLink when not pending

- **GIVEN** a DepositPayment in state `authorized`
- **WHEN** the booking-detail widget renders
- **THEN** the `paymentLink` action is hidden (`visibleWhen: state == 'pending'`)
- **AND** the state badge, amount and AR-invoice relation-link are shown

#### Scenario: Deposits index page filters by state

- **GIVEN** the Deposits index page registered in the shillinq manifest
- **WHEN** an operator opens the State filter
- **THEN** options are `draft, pending, authorized, captured, failed, voided`
- **AND** selecting `failed` lists only deposits requiring follow-up (driven by `failedCount` aggregation)

---

### Requirement: Failure Reason Captured and Surfaced (REQ-DP-011)

On the `fail` outcome the reconciliation service MUST persist `lastErrorCode` and `lastErrorMessage` from the gateway response on the DepositPayment record. The booking-detail widget and the Deposits overview MUST surface these so the operator can take manual follow-up. The raw card data MUST NOT be persisted — only the gateway-provided code and message.

#### Scenario: Failure persists gateway error code/message

- **GIVEN** a webhook reports `{outcome: "failed", errorCode: "insufficient_funds", errorMessage: "Insufficient funds."}` for a pending deposit
- **WHEN** the reconciliation applies the outcome
- **THEN** the DepositPayment is updated to `state=failed`
- **AND** `lastErrorCode = "insufficient_funds"` and `lastErrorMessage = "Insufficient funds."`
- **AND** the `onFailed` notification carries both fields for the operator

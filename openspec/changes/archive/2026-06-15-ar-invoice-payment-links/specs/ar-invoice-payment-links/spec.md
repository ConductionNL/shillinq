# Spec: ar-invoice-payment-links

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations)
**Depends on:**
- `bookings-deposits` (CHAINED: shared OpenConnector payment adapter usage, webhook surface, reconciliation service, polling fallback — REQ-DP-005/006/007 patterns)
- `bookkeeping-accounts-receivable-core` (`ARInvoice` schema + `paid` lifecycle transition; merged spec)
- `add-shillinq-bank-connectors` (gateway configuration via OpenConnector)
- `bookkeeping-bank-reconciliation` (payout auto-matching; merged spec)
- `bookkeeping-credit-control-dunning` (CHAINED: link embedding in reminders)
- `shillinq-notifications` (x-openregister-notifications rule conventions)

## ADDED Requirements

### Requirement: REQ-APL-001 — The system SHALL track invoice payment attempts as an OpenRegister-managed PaymentRequest schema that stores no PCI data

The `PaymentRequest` schema MUST be declared in the ADR-037 register
fragment `lib/Settings/register.d/ar-invoice-payment-links.json` with
`x-openregister-audit: true`. It MUST NOT store card details, CVV, bank
credentials, or authorization tokens — all sensitive payment data is
handled by OpenConnector (PCI-certified); the register stores only opaque
references, mirroring `bookings-deposits` REQ-DP-001.

| Property | Type | Required | Purpose |
|---|---|---|---|
| `arInvoiceId` | FK ARInvoice | Yes | The invoice being paid |
| `amount` | decimal | Yes | Outstanding amount at request creation |
| `currency` | string (ISO 4217) | Yes | Mirrors the invoice currency |
| `paymentGateway` | enum | Yes | mollie, stripe |
| `paymentIntentId` | string | No | Opaque gateway reference from OpenConnector |
| `state` | enum | Yes | pending, authorized, captured, captured_unapplied, failed, expired, voided |
| `paymentLink` | string | Computed | Declarative calculation (REQ-APL-002) |
| `expiresAt` | datetime | Yes | TTL boundary (per-administration config) |
| `failureReason` | string | No | Last gateway error, operator-readable |
| `settlementReference` | string | No | Gateway payout/batch reference (REQ-APL-006) |
| `capturedAt` | datetime | No | Capture timestamp |

#### Scenario: Reviewer confirms no PCI fields

- **GIVEN** the `PaymentRequest` schema declaration and the implementing code
- **WHEN** scanned for card number, CVV, IBAN-credential, or token storage
- **THEN** none MUST exist; only `paymentIntentId` and `settlementReference` opaque references are stored

#### Scenario: A payment request mirrors the invoice's outstanding amount

- **GIVEN** an issued `ARInvoice` with outstanding EUR 1,210.00
- **WHEN** a payment request is created for it
- **THEN** the request MUST carry `amount = 1210.00` and the invoice's currency, in state `pending`

### Requirement: REQ-APL-002 — The payment link SHALL be a declarative calculation resolving to the OpenConnector hosted payment UI, valid only while the request is pending and the invoice unpaid

Same construction as `bookings-deposits` REQ-DP-005: the schema MUST
declare a `paymentLink` calculation generating a customer-facing URL that
embeds the payment-request id, the invoice id, and a short-lived signed
token, resolving to the OpenConnector hosted payment UI. The app MUST NOT
construct a direct Mollie/Stripe URL anywhere. The link MUST only be
rendered/valid while `state = pending` AND the invoice is not settled
(`visibleWhen` declaration); following a link for a non-pending request
MUST show a friendly already-paid/expired page from the hosted UI, never a
second charge.

#### Scenario: paymentLink is a declarative calculation

- **GIVEN** a pending payment request
- **WHEN** the `paymentLink` calculation evaluates
- **THEN** it MUST produce the OpenConnector hosted payment URL with a signed token, and no app PHP/JS code MUST concatenate gateway URLs

#### Scenario: Link is hidden once the invoice is paid

- **GIVEN** a payment request whose invoice has transitioned to `paid`
- **WHEN** the invoice detail payment panel renders
- **THEN** the `paymentLink` action MUST be hidden and the request MUST no longer be `pending`

### Requirement: REQ-APL-003 — The payment link SHALL be embedded in the invoice email, the invoice PDF/UBL payment-means block, and dunning reminders

- The invoice email template MUST include the payment link (button + plain
  URL) when a pending request exists.
- The invoice PDF MUST carry the link in its payment-means block, and
  SHOULD carry a scannable QR code for it; the UBL payment-means
  coordination follows `bookkeeping-quote-order-invoice` REQ-QOI-008 where
  that change owns UBL generation.
- Dunning reminders MUST embed the latest valid payment link, regenerating
  an expired one on demand [CHAINED: bookkeeping-credit-control-dunning —
  this clause activates when that change lands; reminders MUST NOT embed
  stale links].
- All embedding points MUST consume the `paymentLink` calculation — no
  link-construction logic at call sites.

#### Scenario: Invoice email carries the pay button

- **GIVEN** an issued invoice with a pending payment request
- **WHEN** the invoice email is sent
- **THEN** the email MUST contain the payment link resolving to the hosted payment UI

#### Scenario: Dunning reminder never embeds a stale link

- **GIVEN** an overdue invoice whose previous payment request expired
- **WHEN** a dunning reminder is generated (once `bookkeeping-credit-control-dunning` is live)
- **THEN** a fresh pending request MUST be created and its link embedded; the expired link MUST NOT appear

### Requirement: REQ-APL-004 — Webhook handling, reconciliation, and polling SHALL be ONE shared surface for deposits and invoice payment requests (never a fork; CHAINED bookings-deposits)

There MUST be exactly one gateway webhook surface
(`/apps/shillinq/api/webhooks/payments/{gateway}`), one
`PaymentReconciliationService`, and one polling fallback job, shared
between `DepositPayment` and `PaymentRequest`:

- The webhook controller MUST verify the gateway signature over the raw
  request body using the per-gateway shared secret, rejecting mismatches
  with HTTP 400 (malformed/untrusted payload, not an auth failure — the
  deposits convention).
- The reconciliation service MUST resolve the record by `paymentIntentId`
  across both schemas and apply state transitions **idempotently** (a
  replayed webhook is a no-op).
- The polling fallback (scheduled workflow, `*/5`, filter `state=pending`)
  MUST cover both record types through the same shared service; no second
  TimedJob/cron exists.
- Merge-order rule: whichever of `bookings-deposits` / this change lands
  second MUST refactor onto the shared surface; duplicated webhook routes,
  verification code, or polling jobs MUST NOT ship.

#### Scenario: Replayed webhook is idempotent

- **GIVEN** a captured payment request
- **WHEN** the gateway redelivers the same capture webhook
- **THEN** the request state MUST remain `captured`, no second invoice transition MUST fire, and the response MUST be successful (gateway stops retrying)

#### Scenario: Invalid signature is rejected without side effects

- **GIVEN** a webhook POST whose signature does not verify over the raw body
- **WHEN** the shared controller processes it
- **THEN** it MUST respond HTTP 400 and no record in either schema MUST change

#### Scenario: Reviewer confirms a single plumbing stack

- **GIVEN** the shillinq codebase after both changes have merged
- **WHEN** scanned for webhook routes, signature-verification implementations, and payment polling jobs
- **THEN** exactly one of each MUST exist, serving both `DepositPayment` and `PaymentRequest`

#### Scenario: Lost webhook is recovered by polling

- **GIVEN** a pending payment request whose gateway webhook was never delivered
- **WHEN** the shared polling fallback runs and the gateway reports the intent as paid
- **THEN** the request MUST transition to `captured` through the same reconciliation path as the webhook

### Requirement: REQ-APL-005 — A captured payment SHALL settle the invoice through the existing AR lifecycle, with failures surfaced as captured_unapplied and never silently dropped

On `captured`, the reconciliation service MUST trigger the `ARInvoice`'s
existing lifecycle transition to `paid` (AR core REQ-AR-004 — that
transition owns GL settlement posting, dunning stop, and ageing; this
change posts nothing to the GL). The payment request MUST be linked to the
invoice as payment evidence. If the invoice transition is impossible
(already credited/written-off/paid), the request MUST enter
`captured_unapplied`, be surfaced prominently on the payment-requests
overview, and offer refund via the adapter's existing refund call.

#### Scenario: Capture marks the invoice paid through AR machinery

- **GIVEN** an `issued` invoice with a pending request for its full outstanding amount
- **WHEN** the capture webhook reconciles
- **THEN** the invoice MUST transition to `paid` via its existing lifecycle (GL settlement posted by AR core, not by this change) and the request MUST be `captured` with `capturedAt` set

#### Scenario: Capture against an already-settled invoice becomes an exception, not a silent drop

- **GIVEN** an invoice manually matched to a bank transfer moments before the link was paid
- **WHEN** the capture webhook reconciles
- **THEN** the request MUST enter `captured_unapplied`, appear in the overview's exception filter, and offer a refund action through OpenConnector

### Requirement: REQ-APL-006 — Settlement of the invoice by any other means SHALL void open payment requests, and captured requests SHALL carry the gateway payout reference for bank-reconciliation auto-matching

- Any `ARInvoice` settlement transition (paid via bank match, credit note
  covering the balance, write-off) MUST immediately void all `pending`
  payment requests for that invoice; a partial credit changing the
  outstanding amount MUST void the pending request (a new one may be
  created for the new amount).
- On capture (or via the polling job's enrichment), the gateway
  payout/batch reference MUST be recorded as `settlementReference`, and
  the captured amount + reference MUST be available to
  `bookkeeping-bank-reconciliation` auto-matching (merged spec
  REQ-BBR-002) so the gateway payout statement line matches the batch of
  captured requests; the gateway fee (gross − net) MUST be surfaced on the
  match for operator posting (automatic fee journals are out of scope).

#### Scenario: Bank-transfer settlement voids the open link

- **GIVEN** an invoice with a pending payment request
- **WHEN** the invoice is settled by a matched manual bank transfer
- **THEN** the payment request MUST transition to `voided` and its link MUST stop resolving to a payable session

#### Scenario: Gateway payout auto-matches in bank reconciliation

- **GIVEN** three captured requests sharing payout reference `po_2026_0613` totalling EUR 2,420 gross / EUR 2,395.80 net
- **WHEN** the bank statement line for the EUR 2,395.80 payout is auto-matched
- **THEN** the match MUST link the payout line to the three captured requests via the settlement reference and surface the EUR 24.20 fee for posting

### Requirement: REQ-APL-007 — Payment outcomes SHALL be notified via the x-openregister-notifications dialect — never imperative dispatch

The fragment MUST declare `updated`-trigger rules with field-change
conditions on `PaymentRequest.state` per ADR-031 and the
`shillinq-notifications` conventions:

1. **Payment received** — condition `state equals captured`; recipients:
   the invoice owner (`{"kind":"field"}` resolution via the invoice) plus
   the `shillinq-finance` group.
2. **Payment failed** — condition `state equals failed`; recipient: the
   invoice owner.
3. **Captured but unapplied** — condition
   `state equals captured_unapplied`; recipients: the `shillinq-finance`
   group plus `{"kind":"object-acl","permission":"manage"}`.

Subjects MUST be available in both `nl` and `en` and be metadata-only
(invoice number, state — no amounts in subjects). No app-local dispatch
code, listeners, or reminder jobs (gate-18).

#### Scenario: Finance is notified on payment received

- **GIVEN** a payment request transitions to `captured` for invoice `2026-0042`
- **WHEN** the OR notification engine evaluates the rules
- **THEN** the invoice owner and the `shillinq-finance` group MUST receive a notification whose subject names the invoice number, in both `nl` and `en`, without amounts

#### Scenario: No imperative dispatch code exists

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for app-local notification dispatch or legacy notification dialect introduced by this change
- **THEN** none MUST exist (gate-18)

### Requirement: REQ-APL-008 — The UI SHALL ship as ADR-037 manifest pages with ENGLISH i18n source keys

The frontend MUST ship as the ADR-037 manifest fragment
`src/manifest.d/ar-invoice-payment-links.json`:

- **Invoice payment panel** (on the AR invoice detail): request state
  badge, the payment link with copy action and QR rendering, expiry,
  regenerate action (creates a new request; history preserved), failure
  reason when failed.
- **Payment requests overview**: columns invoice / amount / gateway /
  state / created / captured, state filter including an exceptions filter
  (`failed`, `captured_unapplied`), per-administration scope.

Modals/dialogs in their own files under `src/modals/` / `src/dialogs/`;
every `NcSelect` carries `inputLabel` (ADR-004 gates). All new strings use
ENGLISH source keys with Dutch translations in the same change (e.g.
`t('shillinq', 'Payment link copied')` → nl `'Betaallink gekopieerd'`);
notification subjects declared in both `nl` and `en`.

#### Scenario: Operator regenerates an expired link from the panel

- **GIVEN** an invoice whose payment request is `expired`
- **WHEN** the operator clicks regenerate in the payment panel
- **THEN** a new `pending` request MUST be created with the current outstanding amount, the panel MUST show its link, and the expired request MUST remain visible in history

#### Scenario: Dutch UI renders translated strings from English keys

- **GIVEN** a user with locale `nl`
- **WHEN** the payment panel renders
- **THEN** labels MUST appear in Dutch, resolved from English source keys present in `l10n/en.json` and `l10n/nl.json`, and no Dutch source keys MUST exist in `t('shillinq', …)` calls

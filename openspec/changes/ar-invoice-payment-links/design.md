# Design — AR Invoice Payment Links

## Context

`bookings-deposits` introduces a complete, well-shaped payment stack for
booking deposits: a `DepositPayment` register, a declarative `paymentLink`
calculation resolving to OpenConnector's hosted payment UI (REQ-DP-005), a
signature-verified webhook controller with idempotent reconciliation
(REQ-DP-006), and a `*/5` polling fallback (REQ-DP-007). OpenConnector owns
PCI, tokenization, and gateway routing (Mollie/Stripe).

Regular AR invoices — the dominant payment volume — have none of this: they
are paid by manual bank transfer and matched by hand in bank
reconciliation. Every NL competitor ships "betaallink op de factuur". The
2026-06-11 re-evaluation flags this as a low-effort/high-adoption gap
precisely because the plumbing exists; the design job is to generalize it
without forking it.

## Goals

- A payment link on every AR invoice: email, PDF payment-means block, and
  dunning reminders.
- Captured payment → invoice `paid` automatically, through the AR
  lifecycle that already owns settlement posting.
- Gateway payouts auto-match in bank reconciliation via the settlement
  reference.
- **One** payment plumbing stack: webhook surface, reconciliation service,
  and polling fallback shared between deposits and invoice payment
  requests.
- Zero PCI surface in Shillinq, identical to the deposits construction.

## Non-Goals

- No partial payments / installments in v1 (full outstanding amount only).
- No gateways beyond what the OpenConnector adapter offers (Mollie,
  Stripe).
- No customer payment portal; the hosted payment UI is OpenConnector's.
- No SEPA direct debit (different rails — `bookkeeping-sepa-direct-debit`).
- No changes to booking-deposit semantics; deposits keep their rules.
- No GL posting logic here: invoice settlement posting belongs to AR core's
  `paid` transition (REQ-AR-004); fees are surfaced, not auto-posted, in v1.

## Reuse Analysis

| Need | Reused surface | What this change adds |
|---|---|---|
| Gateway routing, tokenization, hosted pay UI, refunds | OpenConnector payment adapter (built for `bookings-deposits`) | Invoice-context payment intents (`createPaymentIntent` with invoice metadata) |
| Payment-link generation pattern | `bookings-deposits` REQ-DP-005 (`paymentLink` calculation, signed token, `visibleWhen: pending`) | Same calculation declared on `PaymentRequest` |
| Webhook verification + idempotent reconciliation | `bookings-deposits` REQ-DP-006 controller/service | Generalized to resolve BOTH `DepositPayment` and `PaymentRequest` by intent id [CHAINED] |
| Missed-webhook recovery | REQ-DP-007 polling fallback (`*/5`, `state=pending`) | Same job covers both record types — no second job |
| Invoice financial record + paid transition + GL settlement | `ARInvoice` (AR core, merged spec) | `paid` transition triggered with payment evidence; nothing re-posted here |
| Statement/payout matching | `bookkeeping-bank-reconciliation` auto-match (merged spec) | `settlementReference`/payout id recorded on capture for match input |
| Reminder embedding | `bookkeeping-credit-control-dunning` templates | Link merge-field in dunning reminders [CHAINED] |
| Payment received/failed alerts | OR notification engine (ADR-031 dialect) | Two `updated`-trigger rules |
| Audit, RBAC | OR audit + RBAC | `x-openregister-audit: true`; no app-local permission code |

## Decisions

### D1 — A separate `PaymentRequest` schema, not fields on `ARInvoice`

An invoice can legitimately have several payment requests over its life
(link expired and regenerated, failed attempt then a new link, link voided
after partial credit). Payment attempts have their own lifecycle, gateway
identity, and audit needs. Folding state onto `ARInvoice` would corrupt the
invoice lifecycle with gateway mechanics and lose attempt history. The
schema mirrors `DepositPayment` (proven shape) minus booking fields, FK'd
to the invoice.

### D2 — Generalize, never fork: one webhook surface, one reconciliation service, one polling job

Hard rule. The webhook controller verifies the gateway signature over the
raw body (HTTP 400 on mismatch — malformed/untrusted payload, not an auth
failure, per the deposits convention) and hands off to ONE
`PaymentReconciliationService` that resolves the record by
`paymentIntentId` across both schemas and applies the idempotent
transition. The `*/5` polling fallback filters `state=pending` across both.
Merge-order coordination [CHAINED: bookings-deposits]: whichever change
lands second refactors onto the shared surface — the reviewer gate is "no
second webhook route, no second polling job, no copy-pasted verification".

### D3 — The link is a declarative calculation resolving to OpenConnector's hosted UI

Same as REQ-DP-005: `paymentLink` is an `x-openregister-calculations`
field embedding the payment-request id, the invoice id, and a short-lived
signed token; it resolves to the OpenConnector hosted payment UI. No
Mollie/Stripe URL is ever constructed in Shillinq, and the link is only
rendered while the request is `pending` and the invoice unpaid
(`visibleWhen`). Email, PDF, and dunning templates consume the calculation
— zero link logic at call sites.

### D4 — Capture drives the existing AR `paid` transition; AR owns the books

On `captured`, the reconciliation service triggers the invoice's existing
lifecycle transition to `paid` (AR core REQ-AR-004), passing the payment
request as evidence. GL settlement posting, dunning stop, and ageing all
follow from AR core — this change posts nothing. If the invoice transition
fails (e.g., concurrently credited), the payment request enters
`captured_unapplied`, an exception state surfaced on the overview — never
silently dropped, and refundable via the adapter's existing
`initiateRefund`.

### D5 — Double-payment prevention is lifecycle-coupled

Any invoice settlement by other means (bank transfer match, credit note,
write-off) MUST void open (`pending`) payment requests immediately — the
voiding hook rides the invoice lifecycle transitions, declaratively. A
true race (customer pays the link in the same minute as the manual match)
lands in `captured_unapplied` → operator refunds. Amount integrity: the
request stores the outstanding amount at creation; if the outstanding
changes (partial credit), the pending request is voided and must be
regenerated.

### D6 — Payout matching feeds bank reconciliation, fees are surfaced not auto-posted

The gateway pays out net, batched, with a payout reference. Capture
records `settlementReference` (payout/batch id where the gateway provides
it; enriched by the polling job otherwise). Bank reconciliation's
auto-match (merged spec REQ-BBR-002) gets the payout reference + captured
amounts as match input, so the statement line matches the batch of
captured requests; the gateway fee (gross − net) is shown on the match for
the operator to post per existing practice. Automatic fee journals are a
follow-up, not v1.

### D7 — Expiry and regeneration

A pending request expires at min(configured TTL, invoice settlement). The
scheduled machinery flips `pending → expired` (no app cron — the shared
polling job already wakes for pending requests). Regeneration creates a
NEW request (history preserved) and re-renders the link surfaces. Dunning
reminders always embed the latest pending link, regenerating on demand if
the previous expired [CHAINED: dunning].

### D8 — Notifications are declarative and metadata-only

Two `updated`-trigger rules with field-change conditions on
`PaymentRequest.state`: `captured` ("Payment received for invoice …") to
the invoice owner + `shillinq-finance` group; `failed` to the invoice
owner. Subjects in `nl` + `en`, metadata-only (invoice number, state — no
amounts in subjects), per the `shillinq-notifications` conventions
(gate-18).

### D9 — i18n with ENGLISH source keys

`t('shillinq', 'Payment link copied')` → nl `'Betaallink gekopieerd'`;
all new strings keyed in English with `nl` translations in the same
commit.

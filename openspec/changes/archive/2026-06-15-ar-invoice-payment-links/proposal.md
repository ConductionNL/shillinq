# Proposal: ar-invoice-payment-links

`kind: config` per ADR-032/ADR-037 — one new register schema
(`PaymentRequest`) with lifecycle, calculations, and notification rules in
an ADR-037 register fragment. The gateway integration is **the
bookings-deposits OpenConnector payment adapter, generalized** — not a
second adapter: the webhook controller and idempotent reconciliation
service that `bookings-deposits` introduces are promoted to a shared,
invoice-capable surface [CHAINED: bookings-deposits].

## Summary

Put an **online payment link (iDEAL via Mollie, cards via Stripe) on every
AR invoice**: "pay this invoice" in the invoice email, on the PDF, and in
every dunning reminder — with the payment result reconciled back
automatically (invoice → `paid`, settlement matched in bank
reconciliation).

This closes EXPECTED-GAP 2 of the 2026-06-11 feature re-evaluation. The
capability already exists in Shillinq *for bookings deposits only*:
`bookings-deposits` builds the OpenConnector payment adapter, the
`paymentLink` calculation, the signature-verified webhook controller, and
the idempotent reconciliation service — scoped to `DepositPayment`.
Generalizing that plumbing to ordinary `ARInvoice` records is a small step
and a baseline expectation of every NL invoicing package (Moneybird,
e-Boekhouden, Exact, SnelStart all ship "betaallink op de factuur"):
invoices with an iDEAL link are paid dramatically faster, and
auto-reconciliation removes the single most repetitive bookkeeping chore.

**Depends on:**
- `bookings-deposits` (CHAINED: owns the OpenConnector payment adapter
  routing, the webhook controller, and the reconciliation service that
  this change generalizes — REQ-DP-005/006/007 patterns)
- `bookkeeping-accounts-receivable-core` (the `ARInvoice` schema and
  lifecycle this change attaches to; merged spec)
- `add-shillinq-bank-connectors` (payment gateway configuration via
  OpenConnector)
- `bookkeeping-bank-reconciliation` (settlement/payout matching; merged
  spec)
- `bookkeeping-credit-control-dunning` (CHAINED: payment link embedded in
  dunning reminders)
- `shillinq-notifications` (ADR-031 notification rule conventions)

## Motivation

An invoice that can be paid in two taps is paid in days; an invoice that
requires a manual bank transfer is paid in weeks. For ZZP'ers and SMBs,
payment links are the highest-leverage cash-flow feature an invoicing
package can offer, and their absence is read as "not a real invoicing
product" in 2026. Every named competitor ships it.

Shillinq has already paid the integration cost: OpenConnector handles
PCI-compliant tokenization, gateway routing (Mollie/Stripe), hosted payment
UI, webhooks, and refunds for booking deposits. Leaving that adapter scoped
to deposits while regular invoices — the much larger volume — are paid by
manual transfer is leaving the feature's value on the table.

## Affected Projects

- [x] Project: shillinq — new `PaymentRequest` register in an ADR-037
  fragment; `paymentLink` surface on AR invoices; webhook route + shared
  reconciliation service generalization (coordinated with
  `bookings-deposits`); manifest pages; dunning/email embedding.
- [x] Project: openconnector — consumer of the existing payment adapter
  (createPaymentIntent, getPaymentStatus, initiateRefund, hosted payment
  UI); no new adapter work expected beyond invoice-context metadata.
- [ ] Project: openregister — consumer only (lifecycle, calculations,
  notifications, scheduled machinery); no OR changes required.

## Scope

### In Scope

- **`PaymentRequest` schema** in the ADR-037 fragment
  `lib/Settings/register.d/ar-invoice-payment-links.json`: AR invoice FK,
  amount + currency (mirroring the invoice's outstanding amount at creation
  time), `paymentGateway` (mollie / stripe), opaque `paymentIntentId`,
  lifecycle state (pending / authorized / captured / failed / expired /
  voided), expiry, failure reason, settlement reference, timestamps. **No
  PCI data** — same storage rules as `bookings-deposits` REQ-DP-001.
- **Payment-link generation** as a declarative calculation (`paymentLink`),
  resolving to the OpenConnector hosted payment UI with a short-lived
  signed token — same pattern as REQ-DP-005; no direct Mollie/Stripe URL
  construction in the app. Link visible/valid only while `pending`.
- **Embedding**: the link on the invoice email, the invoice PDF/UBL
  (payment means block), and dunning reminders [CHAINED:
  bookkeeping-credit-control-dunning].
- **Webhook + reconciliation generalization** [CHAINED: bookings-deposits]:
  one shared signature-verified webhook surface and one shared idempotent
  `PaymentReconciliationService` handling BOTH `DepositPayment` and
  `PaymentRequest` (single code path, two record types), plus the shared
  polling fallback for missed webhooks.
- **Invoice settlement**: on `captured`, the AR invoice transitions to
  `paid` through its existing lifecycle (AR core REQ-AR-004 machinery —
  GL settlement posting is AR's, not this change's), with the payment
  request linked as the payment evidence.
- **Payout/settlement matching**: the gateway payout reference recorded on
  the captured `PaymentRequest` feeds `bookkeeping-bank-reconciliation`
  auto-matching, so the Mollie/Stripe payout on the bank statement matches
  without manual work; gateway fees surfaced for posting.
- **Expiry + regeneration**: links expire with the configured TTL or when
  the invoice is paid/credited by other means; an operator can regenerate.
- **Notifications** (ADR-031 dialect): payment received, payment failed —
  metadata-only, `nl` + `en`.
- **Frontend** (ADR-037 manifest fragment): payment panel on the invoice
  detail (state, link, copy/QR, regenerate), payment-requests overview.
- **i18n**: ENGLISH source keys, `nl` + `en` catalogs.

### Out of Scope

- **Partial payments / installment plans** — v1 links carry the invoice's
  full outstanding amount; partial-payment links are a follow-up.
- **New gateways** beyond Mollie/Stripe — gateway set is owned by the
  OpenConnector adapter.
- **SEPA direct debit** — owned by `bookkeeping-sepa-direct-debit` (a pull
  instrument, different rails).
- **Customer payment portal** ("all my open invoices") — the hosted
  payment UI is OpenConnector's; a portal is a future capability.
- **Chargeback/dispute handling** — same exclusion as `bookings-deposits`.
- **Booking deposit behavior** — `bookings-deposits` semantics are
  unchanged; this change only generalizes the shared plumbing.

## Approach

1. `PaymentRequest` mirrors the proven `DepositPayment` shape minus
   booking-specific fields, FK'd to `ARInvoice` instead of a booking
   `Order`. Lifecycle and `paymentLink` calculation are declared in the
   fragment (ADR-031).
2. The webhook controller and reconciliation service that
   `bookings-deposits` introduces are landed/refactored as **shared**:
   route `/apps/shillinq/api/webhooks/payments/{gateway}` (signature
   verified over the raw body, HTTP 400 on mismatch, idempotent
   transitions), `PaymentReconciliationService` resolving the record by
   payment-intent id across both schemas, and the `*/5` polling fallback
   filtered on `state=pending`. If `bookings-deposits` has not merged yet,
   the shared surface lands here and that change consumes it — coordinated,
   one implementation either way [CHAINED].
3. On `captured`: invoice → `paid` via the existing AR lifecycle transition
   (which owns the GL posting), `settlementReference` recorded for bank
   reconciliation, notification fired declaratively.
4. Embedding points (email template, PDF payment-means block, dunning
   reminder) read the `paymentLink` calculation — no link-construction code
   at the call sites.

Specs: one spec file `ar-invoice-payment-links` with REQ-APL-001 …
REQ-APL-008.

## New Dependencies

None. OpenConnector (existing dependency) owns the gateway SDKs and PCI
surface; no Mollie/Stripe SDK enters Shillinq.

## Impact

- `lib/Settings/register.d/ar-invoice-payment-links.json` — NEW ADR-037
  register fragment: `PaymentRequest` schema, lifecycle, `paymentLink`
  calculation, notification rules.
- `lib/Controller/PaymentWebhookController.php` +
  `lib/Service/PaymentReconciliationService.php` — SHARED with
  `bookings-deposits` (one webhook surface, one idempotent reconciliation
  path for both record types) [CHAINED coordination].
- `src/manifest.d/ar-invoice-payment-links.json` — NEW ADR-037 manifest
  fragment: invoice payment panel + payment-requests overview page.
- Invoice email template + PDF/UBL payment-means block + dunning template —
  gain the payment link [dunning part CHAINED].
- `l10n/en.json`, `l10n/nl.json` — new keys (ENGLISH source strings).
- `tests/Unit/` — fragment shape, reconciliation idempotency, signature
  verification, expiry; `tests/e2e/` — payment panel UI specs (gate-19);
  Newman — webhook + object surface.

## Cross-Project Dependencies

- **bookings-deposits** — CHAINED. One shared webhook controller +
  reconciliation service + polling fallback for `DepositPayment` and
  `PaymentRequest`; whichever change merges second refactors onto the
  shared surface. No duplicate adapters, routes, or polling jobs.
- **bookkeeping-accounts-receivable-core** — attachment point: the
  `paid` transition and its GL settlement posting are AR core's; this
  change only triggers them with payment evidence.
- **bookkeeping-bank-reconciliation** — consumer: payout/settlement
  references from captured payment requests feed statement auto-matching.
- **bookkeeping-credit-control-dunning** — CHAINED: dunning reminders embed
  the live payment link once that change lands.
- **bookkeeping-quote-order-invoice** — soft: invoices issued through Q2C
  are `ARInvoice` records and get links with zero extra work; the UBL
  payment-means block coordinates with REQ-QOI-008.
- **recurring-invoicing** — soft synergy: generated periodic invoices carry
  payment links automatically.

## Risks

### Risk 1: Two payment plumbing stacks drift apart (deposits vs invoices)

**Severity**: High
**Mitigation**: hard spec rule (REQ-APL-004): ONE webhook surface, ONE
reconciliation service, ONE polling fallback, shared by both record types.
The chained coordination note makes merge order explicit; the reviewer
gate is "no second webhook route, no second polling job".

### Risk 2: Payment captured but invoice not marked paid (reconciliation gap)

**Severity**: High
**Mitigation**: idempotent reconciliation shared with the proven
deposits design (signature-verified webhook + `*/5` polling fallback —
REQ-DP-006/007 pattern); the captured→paid linkage is a lifecycle
transition with the payment request as evidence, and a captured request
whose invoice transition fails is surfaced as an exception state, never
silently dropped.

### Risk 3: Link paid after the invoice was settled by bank transfer (double payment)

**Severity**: Medium
**Mitigation**: links are valid only while the request is `pending` AND the
invoice is unpaid; any invoice transition to `paid`/`written-off`/credited
voids open payment requests immediately (REQ-APL-006). A race that still
results in double capture is surfaced as an over-payment exception for
refund via the adapter's existing refund call.

### Risk 4: PCI/data hygiene regression

**Severity**: Medium
**Mitigation**: same construction as deposits — the register stores only
opaque references; signature verification over the raw body; no
Mollie/Stripe URL or token construction in app code; spec scenario scans
for PCI fields.

## Rollback Strategy

**During implementation (before merge):** revert the implementing PR.

**Post-merge, before adoption:** fragment + manifest fragment are
self-contained; removing them removes the capability. The shared webhook
surface stays if `bookings-deposits` is live (deposits keep working).

**Production:** disable link generation (config) — existing pending links
expire by TTL; captured history remains as payment evidence on the
invoices. No bookkeeping data is lost.

## Open Questions

1. **Default TTL for payment links** — gateway defaults (Mollie 15 min for
   iDEAL sessions, but payment *links* live longer) vs invoice due date?
   Spec assumes link valid until invoice settles, with per-administration
   TTL config; confirm with ops.
2. **Gateway fees posting** — auto-post fee journals on payout match, or
   leave to the bank-reconciliation operator flow? V1 surfaces the fee on
   the payout match; auto-posting is a follow-up.
3. **QR code (EPC/iDEAL QR) on the PDF** — cheap addition to the payment
   panel and PDF; included as a SHOULD in the spec, confirm scope at design
   review.

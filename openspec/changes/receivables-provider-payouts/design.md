# Design: receivables-provider-payouts

Read at shillinq development `79f438f33` and integriq development on
2026-09-27.

## Context

**Nothing handles a payout.** The matrix search holds: `DepositReconciliationService`
and `NoShowFeeCaptureService` handle single payments, and no schema or service
names a settlement or payout batch. integriq's `live-payment-providers` creates
payments and reports their status (`PaymentIntentService`, integriq
`lib/Service/PaymentIntentService.php`), and has no settlement fetch.

**Where captured money sits.** `PaymentReconciliationService::reconcile()`
(`lib/Service/PaymentReconciliationService.php:249`) treats two kinds of request
differently. For a request on an object it calls `bookObjectReceipt()` (:339,
:551), which writes one posted `GLTransaction`: debit the clearing account
(`paymentRevenueAccounts` key `clearing`, :581), credit the revenue account of
the request type. For a request on an invoice it calls `settleLinkedInvoice()`
(:406), which moves the `ARInvoice` to `paid` and books nothing, saying "AR core
owns the GL posting" (:469); no code in the AR core books it. So a paid invoice
leaves debtors standing in the ledger and the clearing account never receives
the money a payout will take out of it.

**The bank side.** `BankStatementLine` (declared in `lib/Settings/shillinq_register.json:16039`
and `register.d/add-shillinq-bookkeeping-compliance.json:991`) is matched
through `ReconciliationMatch` (`shillinq_register.json:16429`,
`add-shillinq-bookkeeping-compliance.json:1372`) with `targetRefs` and a type,
on `BankReconciliation` (`src/manifest.json:3291`) and its detail.
`BankfeedMatcher::matchTransaction()` (`lib/Service/BankfeedMatcher.php:66`)
scores a bank line against candidate invoices. A payout line from the provider
names a settlement reference, not an invoice, so it never scores.

**Provider payment ids.** After `receivables-payment-links`, every
`PaymentRequest` stores `providerPaymentId`; `DepositPayment` stores
`paymentIntentId` (`register.d/50-bookings-deposits.json:4`); a web shop
invoice keeps `webshop.paymentReference` (`sales-webshop-orders`).

**Point of sale.** No point-of-sale flow exists in shillinq. The payment port
takes an amount, a description and a method; integriq's providers pass the
method to the provider.

## Goals / Non-Goals

**Goals**

- A payout arrives as one record that names every payment, refund, chargeback and fee in it.
- The bank line of the payout is reconciled by one match, and the ledger shows the fees.
- A payment taken on the spot on a phone ends as a paid invoice without typing.

**Non-Goals**

- Fetching settlements and the phone app.
- Hardware terminals.

## Decisions

### D1. integriq reports a settlement as a CloudEvent, shillinq keeps the payout

integriq emits `nl.conduction.payment.settlement` per settlement with data
`{settlementReference, settledAt, currency, amountGross, amountFees,
amountRefunds, amountChargebacks, amountNet, transactions: [{providerPaymentId,
type, amount, fee}]}`. The integriq CloudEvent listener (added by
`sales-einvoice-exchange` or `receivables-payment-links`, whichever lands first)
writes a `ProviderPayout` with those values, idempotent on the settlement
reference.

Alternative considered: integriq writes `ProviderPayout` directly into
shillinq's register. Rejected here for consistency with the payment status,
which already travels as a CloudEvent; either works, and the schema is the same.

### D2. Match lines by provider payment id

`PayoutMatchingService` resolves each transaction line to the `PaymentRequest`
(`providerPaymentId`), the `DepositPayment` (`paymentIntentId`) or the web shop
`ARInvoice` (`webshop.paymentReference`), stores the reference on the line and
marks the line `matched` or `unmatched`. A refund or chargeback line resolves to
the invoice of its original payment.

### D3. Book the capture first, then the payout

Two postings, both written as `bookObjectReceipt()` writes one:

1. On capture of an invoice-backed request, `settleLinkedInvoice()` also books debit clearing, credit the debtors control account for the invoice, so the clearing account holds what the provider holds.
2. On payout: debit the bank account of the payout (net), debit the fee account (fees, with input VAT when the provider invoices VAT on its fees), credit clearing (gross). Refunds and chargebacks debit debtors and credit clearing for their line. Unmatched lines stay on clearing and are listed.

The fee account and the bank account are posting settings per administration
and provider (seeded to 4720 "Bankkosten" from the RGS seed and the administration's
main bank account).

Alternative considered: book only at payout, crediting debtors per matched
line. Rejected: between capture and payout the invoice is paid but debtors
would still show it open.

### D4. The bank line matches the payout

A bank line whose amount equals a booked payout's net and whose remittance
contains its settlement reference is matched to the payout with a
`ReconciliationMatch` of target type `provider-payout`, confidence high. The
payout's posting already booked the line, so the match books nothing further.

### D5. Take payment now through the point-of-sale method

A user registers their phone once as a `PaymentDevice` (the provider's device or
terminal id, entered from the provider's app). On `ARInvoiceDetail` of an open
invoice, Take payment now asks, through the payment command of
`receivables-payment-links`, for a payment with the point-of-sale method on the
user's device for the open amount. The provider pushes it to the phone's
payment app; the capture arrives as a payment status and settles the invoice as
any other. When integriq answers that the method is not available, the page
shows the checkout as a QR code for the customer to scan instead.

### D6. A counter sale is a simplified invoice issued on capture

`CounterSale` (a page sized for a phone) takes one or more lines from the
product catalogue or free text, VAT per line, and an optional customer. It
creates a draft `ARInvoice` flagged `simplifiedInvoice` and runs D5; on capture
the invoice is issued and paid, on failure it is cancelled. A sale without a
named customer goes to the administration's counter-sales debtor.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Payout state (`received`, `matched`, `booked`, `reconciled`) | Declarative: `x-openregister-lifecycle` on `ProviderPayout` | The audit trail of each payout. |
| Unmatched lines reaching the bookkeeper | Declarative: `x-openregister-notifications` when a payout enters `booked` with unmatched lines | A state-change notification. |
| Matching and booking | Imperative, `PayoutMatchingService` and `PayoutPostingRule` | Cross-schema lookups and a balanced posting. |
| Take payment now and counter sale | Imperative, `CounterSaleService` over the payment command | A cross-app command and an invoice lifecycle on capture. |

## Seed Data

`ProviderPayout` is added (`provider`, `settlementReference`, `settledAt`,
`currency`, the five amounts, `lines`, `state`, `bankStatementLineId`,
`glTransactionId`, `administrationId`) and `PaymentDevice` (`userId`,
`provider`, `deviceId`, `label`).

Seed objects for the administration "Theehandel Van Dijk":

- `ProviderPayout` st_example0001 of 2026-10-02: 14 payments totalling EUR 612.40, one refund of EUR 22.85, fees EUR 4.06, net EUR 585.49, 14 lines matched and one unmatched.
- The matching bank line "MOLLIE B.V. st_example0001" of EUR 585.49 on the ING account, reconciled to the payout.
- `PaymentDevice` "Telefoon winkel" for user anna with a placeholder device id term_example0001.

## Risks / Trade-offs

- [A payout arrives before the captures] → lines match on provider payment id whenever the capture arrives; the payout stays `matched` with pending lines until then.
- [Fees with and without VAT differ per provider and country] → the fee posting takes the VAT treatment from the provider setting; the default is the Dutch treatment of Mollie's invoices, stated in the release note.
- [The phone app cannot receive a pushed payment] → the QR fallback of D5 keeps the flow working.

## Migration Plan

No data migration. Invoices paid through a provider before this change have no
capture booking; a one-off `occ shillinq:payments:book-captures` lists them and,
when confirmed, books them with their capture date. Rollback is reverting the
PR.

## Open Questions

- Which settlement fields integriq can fill depends on the provider's settlement report; D1 names what shillinq needs.

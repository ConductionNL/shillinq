# Design: sales-webshop-orders

Read at shillinq development `79f438f33` and integriq development on
2026-09-27.

## Context

**Nothing receives an order.** The matrix search holds: no schema, service or
page in shillinq names a web shop, and integriq's development tree has no shop
source or synchronization either. The nearest intake in shillinq is
`time-expense-invoice-intake` (`lib/Settings/register.d/time-expense-invoice-intake.json`),
where pipelinq posts time batches to an authenticated shillinq route; ADR-091
would put that kind of credentialed endpoint in integriq, so this change does
not copy it.

**The invoice it should become.** `ARInvoice`
(`register.d/add-shillinq-bookkeeping-compliance.json:395`, lifecycle `draft`
to `issued` to `paid`) with EN 16931 lines and a VAT breakdown
(`register.d/add-shillinq-invoice-lines.json:5`) and a type discriminator
`standard`, `credit-note`, `debit-note` (`register.d/abstract-arinvoice-types.json:11`).
`buyerName` exists for a name on the invoice that differs from the debtor
(`register.d/checks-invoicing.json:5`).

**Cross-border VAT.** `OssInvoiceRouter::route(customerType, destinationCountry,
vatValidationStatus)` (`lib/Service/OssInvoiceRouter.php:70`) decides domestic,
OSS or ICP for a sale, and `OssRateResolver` gives the destination rate. The
router has no caller in `lib/` today.

**Payment clearing.** `PaymentReconciliationService` books object receipts
against a clearing account it resolves through `PaymentRevenueAccountResolver`
under the key `clearing` (`lib/Service/PaymentReconciliationService.php:581`).
A shop payment sits at the shop's payment provider until the payout, so that
clearing account is where a paid shop invoice is settled.

**Listening to new objects.** Shillinq listeners on `ObjectCreatedEvent` must
resolve the entity's numeric register and schema ids to slugs through
`lib/Service/ListenerSchemaResolver.php`; a literal slug comparison never
matches.

**Menu.** Sales pages hang under the Sales group of `src/menu-layout.json`;
settings pages under the settings gear (`settingsSection`).

## Goals / Non-Goals

**Goals**

- An order integriq hands over becomes an issued, correctly taxed sales invoice without anyone typing it.
- A refund becomes a credit note.
- An order that cannot be invoiced is visible with the reason.

**Non-Goals**

- The shop connection and its credentials.
- Inventory for shop sales.
- Payout matching.

## Decisions

### D1. The hand-off is an object in shillinq's register, not an endpoint

integriq writes one `WebshopOrder` per shop order into register `shillinq`
through its synchronization, the same way it writes any target object. The
schema is the contract and is documented in the spec. Shillinq exposes no new
route.

Alternatives considered: a shillinq REST intake like the time intake
(rejected: a credentialed machine endpoint belongs in integriq under ADR-091);
an ADR-041 typed event defined by shillinq (rejected for now: integriq's
synchronization engine writes objects, and an event would need a flow node in
integriq for every shop platform).

### D2. `WebshopOrder` carries the order as the shop sent it

Fields: `channelId`, `shopOrderId`, `orderedAt`, `buyer` (`type` business or
consumer, `name`, `email`, `companyName`, `vatId`, `kvkNumber`, `address`,
`countryCode`), `currency`, `pricesIncludeVat`, `lines` (`sku`, `description`,
`quantity`, `unitPrice`, `vatRate`), `shipping`, `discountTotal`, `totals`
(`net`, `vat`, `gross`), `payment` (`status` paid, pending, refunded or
cancelled, `method`, `providerPaymentId`), `administrationId`, and shillinq's
own `intakeState` (`received`, `invoiced`, `refused`, `duplicate`,
`credited`), `arInvoiceId`, `creditNoteId`, `refusalReason`.

### D3. `WebshopChannel` holds what the shop does not know

One record per connected shop: `name`, `platform`, `administrationId`,
`consumerDebtorId` (a `CustomerMaster` such as "Webwinkel particulieren"),
`revenueAccount`, `shippingAccount`, `clearingAccount`, `issueMode`
(`auto-issue` or `draft-for-review`). An order whose channel is unknown is
refused.

### D4. Debtor: match a business, collect the consumers

A business buyer is matched to a `CustomerMaster` by VAT id, then KvK number,
then invoice email, within the channel's administration; without a match one
is created with `createdBy` the channel. A consumer is booked on the channel's
collective debtor with `buyerName` and the address on the invoice, the usual
verzameldebiteur practice, so the customer list does not grow by one per
purchase.

Alternative considered: a `CustomerMaster` per consumer (Moneybird creates a
contact per buyer). Rejected: shillinq's customer master is a debtor for
credit control and dunning, and a paid consumer order is never dunned.

### D5. VAT per line through the existing router

For each line the invoicer derives the net price (dividing out the rate when
`pricesIncludeVat`), then routes the order: domestic keeps the line's rate; a
consumer in another EU member state goes through `OssInvoiceRouter` and
`OssRateResolver` for the destination rate and the OSS context; a business in
another member state with a VAT id VIES validated goes to reverse charge with
the mention "Btw verlegd". An order the router refuses is refused, never
invoiced at the Dutch rate.

### D6. Paid means settled on the clearing account

An order with `payment.status = paid` is issued and then settled: the invoice
moves to `paid` with the channel's clearing account and `providerPaymentId` as
the evidence, and `ARInvoice.webshop.paymentReference` keeps the id for the
payout match. A pending order is issued and stays open.

### D7. A refund or cancellation writes a credit note

An `ObjectUpdatedEvent` on an invoiced order whose `payment.status` becomes
`refunded` or `cancelled` writes an `ARInvoice` of type `credit-note` for the
full order, referencing the original through `precedingInvoiceReferences`, and
sets `intakeState = credited`. Partial refunds are credited for the refunded
lines the shop names.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Receiving an order | Declarative: the `WebshopOrder` schema is the contract integriq writes to | No endpoint, no code on the way in. |
| Turning an order into an invoice | Imperative, `WebshopOrderInvoicer` behind `WebshopOrderListener` | Debtor matching, VAT routing and totals checks have no declarative form. |
| Intake state | Declarative: `x-openregister-lifecycle` on `WebshopOrder` (`received` to `invoiced`, `refused`, `duplicate`; `invoiced` to `credited`) | The transitions are the audit trail of each order. |
| Refused orders reaching the bookkeeper | Declarative: `x-openregister-notifications` on the transition to `refused` | A state-change notification. |

## Seed Data

Adds `WebshopOrder` and `WebshopChannel` with the fields in D2 and D3, and the
`webshop` group on `ARInvoice`.

Seed objects for the administration "Theehandel Van Dijk":

- `WebshopChannel` "theehandelvandijk.nl", platform woocommerce, consumer debtor "Webwinkel particulieren", revenue account 8000, shipping account 8010, clearing account 1105 "Tussenrekening Mollie", issue mode auto-issue.
- `WebshopOrder` 100231 from consumer J. Bakker, Utrecht: 2 x "Earl Grey los 250 g" at EUR 8.95 and shipping EUR 4.95, prices including 9 and 21 percent VAT, paid by iDEAL (payment tr_example0001), state invoiced.
- `WebshopOrder` 100232 from a consumer in Antwerpen, Belgium, routed through OSS at the Belgian rate, state invoiced.
- `WebshopOrder` 100233 whose line totals are EUR 0.40 off the order total, state refused with both totals in the reason.

## Risks / Trade-offs

- [The collective debtor hides who bought] → the buyer's name, address and email stay on the invoice and on the intake record.
- [A connector writes an order twice under a new id] → the idempotency key is the shop's order number within the channel, not the intake record id.
- [Tea is taxed at 9 percent and shipping follows the main supply] → the line rate comes from the shop, and the shipping charge takes the rate of the goods it ships, split pro rata over the rates on a mixed order.

## Migration Plan

New schemas only. No data migration. Rollback is reverting the PR.

## Open Questions

- Whether partial refunds always name their lines depends on the shop platform; integriq's mapping decides what the intake receives.

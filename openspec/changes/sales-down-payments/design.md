# Design: sales-down-payments

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**What exists.** A down payment exists only for bookings: `DepositPayment`
(`lib/Settings/register.d/50-bookings-deposits.json:4`) is collected at booking
time and later turned into an invoice line by `bookings-deposit-to-invoice`.
Nothing on the sales side creates a down-payment invoice. The RGS seed carries
the right account, 2310 "Vooruitontvangen bedragen", described as "Ontvangen
aanbetalingen en vooruitfacturering" (`lib/Settings/seeds/rgs-3.5-mkb.json:541-549`).
`ARInvoice` has VAT-check fields `prepaymentAmount` and `prepaymentReceiptDate`
(`register.d/checks-remaining-vat.json:101`), read only by the tax-point rule
`vatdir-art65-tax-point-prepayment`
(`lib/Standards/Checks/RemainingVatChecks.php:152`).

**Invoice fields that fit.** `ARInvoice.invoiceTypeCode` (BT-3, UNTDID 1001,
`register.d/add-shillinq-invoice-lines.json:8`) can carry 386, the prepayment
invoice code. `precedingInvoiceReferences` (BG-3,
`register.d/checks-invoicing-extra.json:174`) can name the down-payment invoice
on the final invoice. `ARInvoice.invoiceType` (`standard`, `credit-note`,
`debit-note`, `register.d/abstract-arinvoice-types.json:11`) is a document-kind
discriminator and stays as it is.

**Where the order lives.** There is no single order. `OrderPrimitive`
(`register.d/zz-order-primitive.json:11`) reserves `orderType = sales` but its
migration populates only purchase, subsidy and engagement; `SalesOrder`
(`register.d/bookkeeping-quote-order-invoice.json:314`) has no page; quotes are
moving to pipelinq (`shillinq-product-vendor-to-pipelinq`). ADR-048 lets a
property reference an object by semantic type, so the invoice can name the
order without knowing which app holds it.

**Posting.** `ARInvoice.issue` (`register.d/add-shillinq-bookkeeping-compliance.json:395`)
is described as "materialises a balanced GLTransaction and writes
glTransactionId", but it declares no action; the only `requires` on it is
`RuleComplianceGuard::validateInvoice` (`register.d/add-shillinq-rule-compliance-guard.json:11`),
and no PHP listener or service writes a `GLTransaction` for an issued
`ARInvoice`. `ledger-posting-path` (this OpenSpec pass) introduces the
`materialise-gl-transaction` handler with one mapper per source schema, and its
inventory lists the transitions that declare the action; `ARInvoice.issue` is
not among them because it declares nothing.

**Pages.** `AccountsReceivable` (`src/manifest.json:7825`) and `ARInvoiceDetail`
(the full copy in `src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json:12`,
`actionsComponent: AREInvoiceActions`).

## Goals / Non-Goals

**Goals**

- A bookkeeper invoices a percentage or an amount of an order up front, and it is booked as an advance received.
- The final invoice shows the whole order and takes every down payment off, per VAT rate, once.

**Non-Goals**

- An order screen, and deposits for bookings.

## Decisions

### D1. The down payment is an `ARInvoice` with a `downPayment` group

`ARInvoice.downPayment`: `kind` (`down-payment` or `final`), `orderReference`
(an object id with `referenceSemanticType: https://schema.org/Order`, plus a
label), `orderNetTotal` and `orderVatBreakdown` it was computed from,
`percentage` or `amount`, and on a down payment `deductedOnInvoiceId`; on a
final invoice `deductions` (`invoiceId`, `invoiceNumber`, per rate `net` and
`vat`). A down payment carries `invoiceTypeCode` 386.

Alternative considered: a separate `DownPayment` schema that produces an
invoice. Rejected: the down payment is a real invoice to the customer, dunned
and paid like one, and a second schema would need its own everything.

### D2. The order total comes from the order, or from the user

When the reference resolves to shillinq's `OrderPrimitive`, the service reads
`totalAmount` and the lines' VAT rates. When it resolves elsewhere or not at
all (ADR-048: null-safe), the dialog asks for the order's net total per VAT
rate. Either way the values are stored on the invoice, so a later change to
the order does not change an issued down payment.

### D3. VAT is split over the order's rates in proportion

A down payment of 30 percent on an order with 15,000 net at 21 percent is one
line of 4,500 net at 21 percent. On a mixed order each rate gets its share of
the net. VAT is charged on the down-payment invoice, as article 65 of the VAT
Directive makes it chargeable on receipt of a payment on account, and the
invoice fills `prepaymentAmount`.

### D4. Posting: advances in, advances out

The `ARInvoice` mapper of `materialise-gl-transaction` books a down payment as
debit debtors, credit the administration's advances account (default 2310)
and credit VAT payable; a final invoice books the full revenue and VAT, and each
deduction line debits the advances account and VAT payable and credits
debtors. The advances account is a posting setting per administration, seeded
to 2310. If, when this change is built, no `ARInvoice` mapper exists, this
change declares the action on `ARInvoice.issue` and adds the mapper for every
invoice kind.

Alternative considered: book the down payment as revenue and reverse it on
the final invoice. Rejected: revenue is recognised on delivery, and the
balance sheet must show the advance as a liability until then.

### D5. The deduction panel on the final invoice

On a draft `ARInvoiceDetail` whose customer has issued down payments for an
order without `deductedOnInvoiceId`, a panel lists them with their paid state.
Choosing the order writes `downPayment.kind = final`, the order reference, one
negative line per down payment and rate, and the `precedingInvoiceReferences`.
Issuing stamps `deductedOnInvoiceId` on each down payment in the same step, and
refuses when one was already deducted elsewhere or when the deductions exceed
the invoice total.

### D6. UBL

`ArInvoiceUblMapper` writes `InvoiceTypeCode` 386 for a down payment. A final
invoice carries the deduction lines as negative invoice lines at their VAT
category and rate, and a `BillingReference` per down-payment invoice, so the
customer's software can match them.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Down-payment fields and the order reference | Declarative: the `downPayment` group with `referenceSemanticType` | Data, and the reference resolves through ADR-048. |
| Computing and writing the down-payment and deduction lines | Imperative, `DownPaymentService` | Proportional VAT split and a cross-invoice lookup. |
| Refusing a double deduction on issue | Declarative guard on `ARInvoice.issue`: `DownPaymentGuard::requireOpenDeductions` | A precondition on an existing transition. |
| Posting to the advances account | Imperative, the `ARInvoice` mapper of the lifecycle action handler | The handler is the executor of the declared action. |

## Seed Data

No schema is added; `ARInvoice` gains the `downPayment` group and the posting
settings gain `advancesAccount`.

Seed objects for the administration "Keukenstudio Van Leeuwen":

- A down-payment invoice to customer "Familie De Boer" for order "Keuken Eiland 2026-117" (net total EUR 15,000 at 21 percent): 30 percent, one line EUR 4,500 plus EUR 945 VAT, total EUR 5,445, type code 386, paid.
- A draft final invoice for the same order: kitchen EUR 15,000 plus EUR 3,150 VAT, a deduction line of minus EUR 4,500 and minus EUR 945 VAT referencing the down payment, amount due EUR 12,705.

## Risks / Trade-offs

- [An order changes after the down payment] → the down payment keeps the totals it was computed from; the final invoice shows the order as it is invoiced then, and the deduction stays the amount actually charged.
- [A customer pays the down payment late] → the down payment is an ordinary invoice and follows the dunning ladder.
- [Posting the down payment needs the `ARInvoice` mapper] → see D4.

## Migration Plan

No data migration. Rollback is reverting the PR.

## Open Questions

- Should the advances account differ per VAT rate or per product group? The RGS seed has one account, and one is enough for every quoted competitor.

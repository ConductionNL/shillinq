---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: sales-down-payments

## Summary

A business that asks a customer to pay part of an order up front has no way to
invoice that part in shillinq and take it off the final invoice. This change
adds a down-payment invoice on an order, booked as an advance received rather
than as revenue, and a final invoice that lists the order's full price and
deducts every down payment at the VAT rates it was invoiced at.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). The OpenSpec pass
of 2026-09-27 decided `build` (`openspec/parity/gap-decisions.json`).

**`sal-down-payment`**, "Invoice a down payment on an order and deduct it on
the final invoice." Rated no, built state none. The matrix evidence: "grep over
lib/ and src/ for down payment, aanbetaling and prepayment finds only the
bookings deposit schema (lib/Settings/register.d/50-bookings-deposits.json:9,
deposit collected at booking time) and two RGS seed accounts
(lib/Settings/seeds/rgs-3.5-mkb.json:216, :549); no sales order or sales
invoice path creates a down-payment invoice or deducts one on the final
invoice." Note: "Only booking deposits exist (see bok-deposit, pending on a
declared lifecycle); a down payment on a sales order is not built." Demand:
changelog https://www.odoo.com/odoo-19-release-notes. Two competitors rate it
yes:

- moneybird: https://helpcenter.moneybird.nl/nl/articles/207189-factureren-van-een-offerte, "Factureer een offerte in één keer, of in delen met een deelfactuur en een eindfactuur"; https://helpcenter.moneybird.nl/nl/articles/207923-aanbetalen-van-facturen, "Kies dan op de offerte voor Meer factuuropties > Deelfactuur maken".
- odoo: odoo/odoo@19.0 `addons/sale/wizard/sale_make_invoice_advance.py:16` "Down payment (percentage)" and `has_down_payments` (:29) deducted on the final invoice; the 19 release notes move the down payment account to the accounting settings.

This change covers the row.

## Affected Projects

- [ ] Project: `shillinq`: a down-payment kind on `ARInvoice`, an order reference, the deduction on the final invoice, the posting profile for both, and the actions on the AR invoice pages.

## Scope

### In Scope

- A down-payment invoice for an order: a percentage of the order total or a fixed amount, split over the order's VAT rates in proportion.
- The order named by a semantic reference (ADR-048) to whatever holds it: shillinq's `OrderPrimitive`, or a quote or order in pipelinq.
- Booking a down-payment invoice to the advances-received account (2310 Vooruitontvangen bedragen in the RGS seed), with VAT due on it.
- A final invoice for the same order that shows the full order and deducts each down payment as negative lines at its own VAT rates, referencing the down-payment invoice.
- A down payment deducted once only, and never more than the final invoice's total.
- The order's down-payment position on the invoice page: invoiced, paid, deducted.
- The UBL of both invoices: type code 386 for the down payment, deduction lines and preceding-invoice references on the final invoice.

### Out of Scope

- A sales order screen. `sal-orders` is its own matrix row; this change takes the order as a reference.
- Booking deposits, which have their own `DepositPayment` path (`bok-deposit`).
- Instalment plans on an overdue invoice (`receivables-payment-plans`).

## Approach

`ARInvoice` gains a `downPayment` group: the kind (`down-payment` or
`final`), the order reference, the order total it was computed from, the
percentage or amount, and on a final invoice the list of deductions. A
`DownPaymentService` builds the down-payment invoice and, on a draft final
invoice for the same order and customer, offers the open down payments and
writes the deduction lines. Posting goes through the
`materialise-gl-transaction` handler that `ledger-posting-path` introduces: a
down-payment invoice credits the advances account instead of revenue, and a
deduction line debits it. `ARInvoice.issue` is not in that change's inventory
today, so this change declares the action on `ARInvoice.issue` and adds the
`ARInvoice` mapper if `ledger-posting-path` has not by then. Details are in
design.md.

## New Dependencies

None.

## Impact

- Schemas: `ARInvoice` gains `downPayment` (additive); the RGS seed's 2310 is marked as the default advances account in the posting settings.
- Code: new `DownPaymentService` and `DownPaymentController`; the `ARInvoice` mapper of the `materialise-gl-transaction` handler gains the down-payment rule (and is added if absent); `ArInvoiceUblMapper` maps type 386 and deduction lines.
- Manifest: "New down-payment invoice" on `AccountsReceivable`, a deductions panel on a draft `ARInvoiceDetail`.

## Cross-Project Dependencies

- pipelinq: a pipelinq quote or order can be the referenced order when its schema advertises `https://schema.org/Order` (ADR-048). No pipelinq change is needed for shillinq to accept the reference; pipelinq asking shillinq for a down payment from its own quote page would be pipelinq's change.

## Risks

### Risk 1: A down payment is deducted twice or not at all
**Severity:** High. **Mitigation:** each down-payment invoice records the final invoice that deducted it; the deduction panel offers only down payments without one, and issuing a final invoice with a deduction already applied elsewhere is refused.

### Risk 2: VAT on the down payment and the final invoice do not add up
**Severity:** High. **Mitigation:** deductions are written per VAT rate at the down payment's own net and VAT, so the final invoice's VAT is the order's VAT minus what was already charged, to the cent.

### Risk 3: An issued AR invoice does not reach the ledger at all
**Severity:** High. **Mitigation:** `ARInvoice.issue` declares no posting action today and no PHP poster books it (design.md). This change depends on `ledger-posting-path` for the handler, and its first task checks whether an `ARInvoice` mapper exists; if not, the change adds the declaration and the mapper for every issued sales invoice, since the gap is wider than down payments.

## Rollback Strategy

Revert the PR. The schema change is additive; down-payment invoices already
issued remain ordinary issued invoices with a `downPayment` group nothing
reads.

## Open Questions

- Should a final invoice be refused while a down-payment invoice of the same order is still unpaid? This change allows it and shows the unpaid down payment on the deductions panel.

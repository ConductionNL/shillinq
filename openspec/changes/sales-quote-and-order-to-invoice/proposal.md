---
kind: code
depends_on: []
---

# Proposal: sales-quote-and-order-to-invoice

## Summary

A customer order in shillinq can be recorded but not invoiced, and an accepted quote goes nowhere. This change gives a sales order its own states on the Orders page, lets an accepted quote become a sales order with its lines, and lets a confirmed sales order become a draft sales invoice with the lines not yet invoiced. Nobody retypes a line.

## Motivation

The build-all pass of 2026-09-29 decided `build` for these `building` rows of
the shillinq capability matrix (`openspec/parity/capabilities.json`), because
no change covered their missing half. Each row keeps `built.state: building`
with `built.change` naming this change until it ships.

**`sal-quote-to-invoice`**, "Turn an accepted quote into an invoice without retyping the lines." Shillinq rated no, built state `building`. The matrix evidence for the built half: "Quote, QuoteLine, SalesOrder, Invoice, CreditNote schemas in lib/Settings/register.d/bookkeeping-quote-order-invoice.json plus lib/Lifecycle/QuoteOrderInvoiceGuard.php guards, but no manifest page uses schema Quote/SalesOrder/Invoice and no service copies quote lines to an invoice (grep 'quote' in lib/Service, src/views: none)"

- exact-online (yes): https://www.exact.com/nl/producten/boekhouden/features-en-prijzen: "Met Exact Online kun je offertes opstellen en goedkeuren. Het is zelfs mogelijk om offertes direct om te zetten in een factuur"
- moneybird (yes): https://helpcenter.moneybird.nl/nl/articles/207189-factureren-van-een-offerte : 'Je opent de geaccepteerde offerte en klikt bovenaan op Factureer offerte. Moneybird maakt dan een kopie van je offerte als conceptfactuur'; also in parts (deelfactuur, eindfactuur)
- snelstart (yes): https://kennisplein.snelstart.nl/snelstartpolaris/een-offerte-maken : 'De offerte zet je uiteindelijk om naar een factuur. Dit kun je met één druk op de knop doen. Klik bovenin op de knop Omzetten > Factuur'
- odoo (yes): odoo/odoo@19.0 addons/sale/models/sale_order.py:1552 _create_invoices builds invoice lines from the order lines

**`sal-orders`**, "Record a customer order and follow it through to the invoice." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "Orders page on OrderPrimitive (src/manifest.d/order-workspace.json) with OrderLine and Payment related lists; orderType enum has 'sales' but lib/Settings/register.d/zz-order-primitive.json says only purchase/subsidy/engagement are populated and the quick filters omit sales; SalesOrder schema (bookkeeping-quote-order-invoice.json) has no page; no code turns an order into an ARInvoice (the 'invoice' transition belongs to the purchase flow)"

- exact-online (yes): https://support.exactonline.com/community/s/article/All-All-HNO-Task-sales-orders-slsord-invoicet?language=en_GB: "After you have made a sale and created the sales order, you can start invoicing. This can be done before or after you deliver it" Note: Exact Online Handel or Productie.
- snelstart (yes): https://kennisplein.snelstart.nl/snelstartpolaris/een-offerte-maken : 'Een offerte is een order. Deze kun je omzetten tot factuur of andere order ... Werkbon, Pakbon of Bevestiging'; package table: 'Pakbonnen, bevestigingen en werkbonnen' and 'Verkooporderbeheer' from inZicht
- odoo (yes): odoo/odoo@19.0 addons/sale/models/sale_order.py:1552 _create_invoices from a confirmed sale order; order and invoice status tracked on the order

## What is already built

- The `Quote`, `QuoteLine`, `SalesOrder`, `SalesOrderLine`, `Invoice` and `InvoiceLine` schemas with their lifecycles in `lib/Settings/register.d/bookkeeping-quote-order-invoice.json`, and the guards in `lib/Lifecycle/QuoteOrderInvoiceGuard.php` (a quote needs a customer and a line before it is sent; an accepted quote records its acceptance channel).
- The Orders page on `OrderPrimitive` (`src/manifest.d/order-workspace.json`) with `OrderLine` and `Payment` related lists. `orderType` already lists `sales` as a reserved value (`lib/Settings/register.d/zz-order-primitive.json`).
- Down payments on an `OrderPrimitive` with lines (`lib/Service/Sales/DownPaymentService.php`, archived change `sales-down-payments`), which the final invoice deducts.

## What this change adds

- A quote page (`Quotes`, under Sales) on the `Quote` schema with its `QuoteLine` related list, and an action "Create sales order" on an accepted quote.
- `orderType: sales` populated on `OrderPrimitive`: its own states (draft, confirmed, partly invoiced, invoiced, cancelled), a quick filter on the Orders page, and the source quote on the order.
- An action "Invoice order" on a confirmed sales order that writes a draft `ARInvoice` with one line per order line still to invoice, and records the invoiced quantity on each order line, so a second invoice only takes what is left.

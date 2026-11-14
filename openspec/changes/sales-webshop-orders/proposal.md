---
kind: code
depends_on: []
---

# Proposal: sales-webshop-orders

## Summary

A business that sells through a web shop has to retype every order as an
invoice, because shillinq has no way to receive one. The shop connection
belongs in integriq (ADR-091); this change is shillinq's half: a published
order intake that integriq's shop connector writes into, and the service that
turns each received order into an issued sales invoice with the right debtor,
VAT and payment state, a credit note when the shop refunds, and a visible
refusal when an order does not add up.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26). The OpenSpec pass
of 2026-09-27 decided `build` (`openspec/parity/gap-decisions.json`).

**`sal-webshop-sync`**, "Turn web shop orders into sales invoices
automatically." Rated no, built state none. The matrix evidence: "grep -i
'webshop|woocommerce|shopify|lightspeed|magento|e-?commerce' in lib and src
hits only rule catalogue text (lib/Standards/rules/vat.json,
ComplianceCatalogue.php) and a KOR fragment; no importer, connector or page".
Reached on: "nothing reaches it: no web shop source or order import". No
demand row. Three competitors rate it yes:

- moneybird: https://www.moneybird.nl/product/koppelingen/webshops/, "Koppel je webshop aan Moneybird en laat bestellingen, facturen en betalingen automatisch in je administratie belanden ... van WooCommerce en Shopify tot CCV Shop en Magento" (through partner connectors).
- snelstart: https://www.snelstart.nl/ondernemer/inhandel, "Met SnelStart inHandel worden je orders automatisch opgehaald en verwerkt en staan ze direct beschikbaar in je boekhouding"; https://www.snelstart.nl/koppelingen lists WooCommerce, Shopify and Webwinkelfacturen connectors.
- odoo: odoo/odoo@19.0 `addons/sale/models/payment_transaction.py:184`, with `sale.automatic_invoice` "the paid web shop order is invoiced automatically".

The competitors split the same way this change does: the shop link is a
connector (Moneybird and SnelStart through partners), the invoice is the
bookkeeping app's. This change covers the row.

## Affected Projects

- [ ] Project: `shillinq`: a `WebshopOrder` intake schema and a `WebshopChannel` setting, the invoicer that listens for new orders, and two pages.

## Scope

### In Scope

- `WebshopOrder`, the intake record integriq's shop connector writes, as a documented contract.
- `WebshopChannel`, one per connected shop: administration, collective consumer debtor, revenue account and payment clearing account.
- Turning a received order into one issued `ARInvoice`, once per shop and shop order number.
- Debtor resolution: a business buyer matched or created as `CustomerMaster`, a consumer booked on the shop's collective debtor with the buyer's name on the invoice.
- VAT per line, with EU consumer orders routed through the existing `OssInvoiceRouter` and a business buyer with a valid VAT id through reverse charge.
- A paid order recorded as paid against the payment clearing account with the provider's payment reference.
- A credit note for a refunded or cancelled order.
- A refusal with a reason for an order whose totals do not add up or which lacks what an invoice needs.
- A `WebshopOrders` page listing received orders with their state and invoice.

### Out of Scope

- Connecting to WooCommerce, Shopify, Lightspeed, CCV Shop or Magento. That is integriq's connector (ADR-091, ADR-067).
- Stock. Decrementing inventory for a shop sale is the POS path's concern (`inventory-pos-decrement`).
- Matching the provider's payout to these invoices. That is `receivables-provider-payouts` in this OpenSpec pass.
- Pushing invoices back to the shop.

## Approach

integriq's synchronization maps a shop order onto `WebshopOrder` in shillinq's
register, as it maps any source onto a target schema. A listener on
OpenRegister's `ObjectCreatedEvent` (slug-resolved through
`ListenerSchemaResolver`) hands the order to `WebshopOrderInvoicer`, which
checks it, resolves the debtor and the VAT route, writes and issues the
`ARInvoice`, records the payment, and stamps the invoice id and state on the
order. An `ObjectUpdatedEvent` with a refund or cancellation writes the credit
note. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `WebshopOrder` and `WebshopChannel` added; `ARInvoice` gains a `webshop` group (`channelId`, `shopOrderId`, `paymentReference`) (additive).
- Code: new `WebshopOrderListener`, `WebshopOrderInvoicer`; `OssInvoiceRouter` gets its first caller.
- Manifest: `WebshopOrders` (index and detail) under Sales, `WebshopChannels` under the settings gear.

## Cross-Project Dependencies

- integriq: a web shop connector (source type, credentials through its broker, and a synchronization per shop platform) whose mapping targets shillinq's `WebshopOrder` schema. integriq ships no shop connector on development today (its tree has no WooCommerce, Shopify, Lightspeed or Magento source). This change publishes the target contract; the connector is integriq's change.

## Risks

### Risk 1: One order becomes two invoices
**Severity:** High. **Mitigation:** the invoicer is idempotent on (`channelId`, `shopOrderId`); a second intake record for the same order is marked duplicate and writes nothing.

### Risk 2: Consumer VAT for another EU country booked at the Dutch rate
**Severity:** High. **Mitigation:** every consumer order outside the Netherlands goes through `OssInvoiceRouter` and `OssRateResolver`; an order the router cannot place is refused, not invoiced at 21 percent.

### Risk 3: Shop totals and shillinq's rounding disagree
**Severity:** Medium. **Mitigation:** the invoicer accepts a difference of at most one cent per line and refuses the order beyond that with both totals named.

## Rollback Strategy

Revert the PR. Intake records and invoices already written stay; integriq's
synchronization then fails to write into a schema that no longer exists, which
integriq reports as a failed synchronization.

## Open Questions

- Should a business buyer be created as a new `CustomerMaster` automatically, or parked for a bookkeeper to confirm? This change creates it and flags it as created by the shop.

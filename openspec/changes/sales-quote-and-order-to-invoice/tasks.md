# Tasks: sales-quote-and-order-to-invoice

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Register and service

- [x] 1.1 `sales` states on `OrderPrimitive`, `sourceQuote`, `OrderLine.invoicedQuantity` (REQ-QTI-002). Verify: the register test validates a sales order and its lines with the real merged register (`RegisterSchema::errors`).
- [x] 1.2 `SalesOrderInvoicingService::orderFromQuote` (REQ-QTI-001). Verify: PHPUnit red first; refusal for a quote not accepted and for a quote that already has an order.
- [x] 1.3 `SalesOrderInvoicingService::invoiceFromOrder` with remaining quantities (REQ-QTI-003). Verify: PHPUnit red first; the written `ARInvoice` validated against the real fragment; a second call invoices only the rest.
- [x] 1.4 Endpoints with route auth and the administration check. Verify: controller test with a caller from another administration gets a refusal; route-auth and IDOR gates.

## 2. Pages

- [x] 2.1 `Quotes` and `QuoteDetail` pages with "Create sales order"; Orders `sales` filter and "Invoice order" (REQ-QTI-001, REQ-QTI-003). Verify: `npm run check:manifest`; vitest on the action wiring.
      - Done (2026-11-14): `src/manifest.d/sales-quotes.json` (Quotes index under Sales, QuoteDetail with its Quote lines and "Create sales order" shown on an accepted quote); Orders gets a `Sales` quick filter and "Invoice order" on a sales order. Both actions are `src/utils/salesOrderActions.js` (post to the endpoints, show the server's refusal, open the written order or draft invoice). `tests/vitest/salesQuoteOrderInvoice.spec.js` (10).

## 3. Strings and end to end

- [x] 3.1 Dutch and English strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
      - Done: the page and action strings in `l10n/en.json` and `l10n/nl.json`, `.js` rebuilt. `test:l10n`, `test:l10n-parity` and `check:l10n-js` pass. `check:schema-l10n` is over its baseline on `development` itself (12179 vs 12169) and no higher on this branch.
- [ ] 3.2 Playwright `tests/e2e/sales-quote-and-order-to-invoice.spec.ts`: accept a quote, create the order, invoice it. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

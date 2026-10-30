# Tasks: sales-webshop-orders

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Contract

- [ ] 1.1 Add `WebshopOrder` (with its intake lifecycle and the refused notification), `WebshopChannel` and the `ARInvoice.webshop` group in `lib/Settings/register.d/sales-webshop-orders.json` (REQ-SWO-001, REQ-SWO-006). Verify: `npm run check:registers` and a PHPUnit case on the merged schemas.
- [ ] 1.2 Write the contract page `docs/integrations/webshop-order-intake.md` that integriq's mapping follows: field list, idempotency key, payment status values. Verify: the page is linked from the integriq issue for the connector, recorded in the PR body.

## 2. Invoicer

- [ ] 2.1 Add `WebshopOrderListener` on `ObjectCreatedEvent` and `ObjectUpdatedEvent` with `ListenerSchemaResolver`, and `WebshopOrderInvoicer` writing and issuing the invoice once per channel and order number (REQ-SWO-001). Verify: PHPUnit with a real `ObjectCreatedEvent` carrying an id-stamped entity, and a repeated order.
- [ ] 2.2 Add debtor resolution: VAT id, KvK, email, create for a business, collective debtor for a consumer (REQ-SWO-002). Verify: PHPUnit per match route and for a consumer.
- [ ] 2.3 Add the VAT route through `OssInvoiceRouter` and `OssRateResolver`, net price derivation for prices including VAT, and the refusal when no route applies (REQ-SWO-003). Verify: PHPUnit for a domestic, a Belgian consumer and an EU business order.
- [ ] 2.4 Settle a paid order on the channel's clearing account and keep the provider payment id (REQ-SWO-004). Verify: PHPUnit for paid and pending.
- [ ] 2.5 Write the credit note on refund or cancellation, including partial refunds by line (REQ-SWO-005). Verify: PHPUnit for a full and a partial refund.

## 3. Pages

- [ ] 3.1 Add `WebshopOrders` (index with intake-state quick filters and detail with the invoice link) under Sales, and `WebshopChannels` under the settings gear (REQ-SWO-001, REQ-SWO-006). Verify: `npm run check:manifest`, `npm run check:nav-reachability`, and Playwright on the seeded orders.

## 4. Docs

- [ ] 4.1 User guide page for connecting a shop through integriq and reading refused orders, and a release note. Verify: the page is in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-webshop-orders/tasks.md#task-N` on every new method, English source strings with Dutch translations.

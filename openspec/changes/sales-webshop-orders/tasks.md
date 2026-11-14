# Tasks: sales-webshop-orders

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Contract

- [x] 1.1 Add `WebshopOrder` (with its intake lifecycle and the refused notification), `WebshopChannel` and the `ARInvoice.webshop` group in `lib/Settings/register.d/sales-webshop-orders.json` (REQ-SWO-001, REQ-SWO-006). Verify: `npm run check:registers` and a PHPUnit case on the merged schemas.
- [x] 1.2 Write the contract page `docs/integrations/webshop-order-intake.md` that integriq's mapping follows: field list, idempotency key, payment status values. Verify: the page is linked from the integriq issue for the connector, recorded in the PR body. (Done: `docs/integrations/webshop-order-intake.md`, drawn from the schemas in `lib/Settings/register.d/sales-webshop-orders.json`. Linking it from the integriq connector issue is left to the maintainers: workers open no issues.)

## 2. Invoicer

- [x] 2.1 Add `WebshopOrderListener` on `ObjectCreatedEvent` and `ObjectUpdatedEvent` with `ListenerSchemaResolver`, and `WebshopOrderInvoicer` writing and issuing the invoice once per channel and order number (REQ-SWO-001). Verify: PHPUnit with a real `ObjectCreatedEvent` carrying an id-stamped entity, and a repeated order.
  - Done: `lib/Listener/WebshopOrderListener.php` (registered filtered on `WebshopOrder`) and `lib/Service/Webshop/WebshopOrderInvoicer.php`: a line per order line plus shipping (at the order's highest rate), net derived when prices include VAT so the total is what the buyer paid, issued on `auto-issue` and draft otherwise, `arInvoiceId` saved and the `invoice` transition run; a second order of the same channel and number runs `markDuplicate`; unknown channel, no lines, another administration, totals that do not add up and a buyer outside NL (until 2.3) run `refuse` with `refusalReason`. PHPUnit: `tests/Unit/Service/Webshop/WebshopOrderInvoicerTest.php`.
- [x] 2.2 Add debtor resolution: VAT id, KvK, email, create for a business, collective debtor for a consumer (REQ-SWO-002). Verify: PHPUnit per match route and for a consumer.
  - Done: consumer → the channel's collective debtor with `buyerName`; business → matched by VAT id, then KvK, then email within the channel's administration (spaces, dots and case ignored), else created with `createdByWebshopChannelId` (CustomerMaster 0.4.0) so the bookkeeper can find customers the shop made. PHPUnit: `testABusinessBuyerIsMatchedByVatIdFirst`, `testABusinessBuyerWithoutAVatIdIsMatchedByKvkNumber`, `testABusinessBuyerWithOnlyAnEmailIsMatchedByIt`, `testANewBusinessBuyerIsCreated` and the consumer case in `testATeaOrderBecomesAnIssuedInvoice`.
- [ ] 2.3 Add the VAT route through `OssInvoiceRouter` and `OssRateResolver`, net price derivation for prices including VAT, and the refusal when no route applies (REQ-SWO-003). Verify: PHPUnit for a domestic, a Belgian consumer and an EU business order.
- [x] 2.4 Settle a paid order on the channel's clearing account and keep the provider payment id (REQ-SWO-004). Verify: PHPUnit for paid and pending.
  - Done: `WebshopOrderInvoicer::settle()`, run right after invoicing and by the listener when a later update of an invoiced order arrives. A paid order with an issued invoice gets one `JournalEntry` (debit the channel's `clearingAccount`, credit the receivables control account) posted through `postDirect`. The invoice keeps `webshop.paymentReference` and is marked paid (`mark-paid`), and the order keeps `settlementJournalId` (WebshopOrder 0.2.0), so it is settled once. A pending order only passes its payment id to the invoice. PHPUnit: `testAPaidOrderIsSettledOnTheClearingAccount`, `testAPendingOrderKeepsItsPaymentIdAndIsNotSettled`, `testOnlyAnIssuedInvoiceIsSettledAndOnlyOnce`, `testTheListenerSettlesAnUpdatedPaidOrder`.
- [ ] 2.5 Write the credit note on refund or cancellation, including partial refunds by line (REQ-SWO-005). Verify: PHPUnit for a full and a partial refund.

## 3. Pages

- [ ] 3.1 Add `WebshopOrders` (index with intake-state quick filters and detail with the invoice link) under Sales, and `WebshopChannels` under the settings gear (REQ-SWO-001, REQ-SWO-006). Verify: `npm run check:manifest`, `npm run check:nav-reachability`, and Playwright on the seeded orders.

## 4. Docs

- [ ] 4.1 User guide page for connecting a shop through integriq and reading refused orders, and a release note. Verify: the page is in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/sales-webshop-orders/tasks.md#task-N` on every new method, English source strings with Dutch translations.

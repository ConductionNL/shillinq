# Tasks: receivables-provider-payouts

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

## 1. Contract

- [ ] 1.1 Open the integriq issues for the settlement CloudEvent (data of design.md D1) and for a point-of-sale payment on a registered device; link them in the PR body (REQ-RPPO-001, REQ-RPPO-005). Verify: both issue links in the PR body.
- [ ] 1.2 Add `ProviderPayout` (with its lifecycle and unmatched notification), `PaymentDevice`, the `provider-payout` match target and the fee and bank posting settings in `lib/Settings/register.d/receivables-provider-payouts.json` (REQ-RPPO-001, REQ-RPPO-004). Verify: `npm run check:registers`.

## 2. Payouts

- [ ] 2.1 Add the settlement branch to the integriq CloudEvent listener, writing a `ProviderPayout` once per settlement reference (REQ-RPPO-001). Verify: PHPUnit with a real `ObjectCreatedEvent` and a repeated settlement.
- [ ] 2.2 Add `PayoutMatchingService` for requests, deposits and web shop invoices, including refunds and chargebacks (REQ-RPPO-002). Verify: PHPUnit per source kind and for an unmatched line.
- [ ] 2.3 Book the capture of an invoice-backed request in `settleLinkedInvoice()`, and add `occ shillinq:payments:book-captures` for invoices paid before this change (REQ-RPPO-003). Verify: PHPUnit asserting a balanced clearing-to-debtors transaction, and the command's listing.
- [ ] 2.4 Add `PayoutPostingRule` booking net, fees and refunds against clearing (REQ-RPPO-003). Verify: PHPUnit for the st_example0001 seed, balanced to the cent.
- [ ] 2.5 Add the payout candidate to bank matching and the reconciled display (REQ-RPPO-004). Verify: PHPUnit that the matching bank line gets one `provider-payout` match and no posting; Playwright on `BankReconciliationDetail`.
- [ ] 2.6 Add `ProviderPayouts` index and detail under Banking (REQ-RPPO-001, REQ-RPPO-002). Verify: `npm run check:manifest` and `npm run check:nav-reachability`.

## 3. On the spot

- [ ] 3.1 Add Take payment now on `ARInvoiceDetail` through the payment command with the point-of-sale method and the user's device, with the QR fallback (REQ-RPPO-005). Verify: PHPUnit for the device path, the fallback and no device registered; a live check on integriq's test source recorded in the PR body.
- [ ] 3.2 Add `CounterSaleService` and the phone-sized `CounterSale` page, issuing on capture and cancelling on failure (REQ-RPPO-006). Verify: PHPUnit for capture and abandonment; Playwright at a 390 px viewport.

## 4. Docs

- [ ] 4.1 User guide pages on payouts and on taking payments on the spot, and a release note naming the fee account default and the capture booking command. Verify: the pages in `docs/` and the note in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/receivables-provider-payouts/tasks.md#task-N` on every new method, English source strings with Dutch translations, and coordinate the match target with `banking-manual-match` in this OpenSpec pass.

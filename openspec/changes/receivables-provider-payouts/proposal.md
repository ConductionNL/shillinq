---
kind: code
depends_on: [receivables-payment-links]
---

# Proposal: receivables-provider-payouts

## Summary

Money paid through a payment provider reaches the bank as one payout for many
payments, less the provider's fees, and shillinq cannot take that payout apart:
the bank line either stays unmatched or a bookkeeper splits it by hand. Nor can
a business take a card payment on the spot with its own phone and have it
booked. This change records each payout integriq reports with the payments,
refunds, chargebacks and fees inside it, matches every payment to the invoice,
request or deposit it paid, books the fees and the net amount, and matches the
bank line to the payout. It also adds a take-payment-now action that asks the
provider, through integriq, for a point-of-sale payment on the user's
registered phone, and books it as soon as it is captured.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) both rest on the
provider reporting what it did with the money. The OpenSpec pass of
2026-09-27 decided `build` for both (`openspec/parity/gap-decisions.json`).

**`rec-psp-payouts`**, "Have payment provider payouts matched to the invoices
they settle." Rated no, built state none. The matrix evidence: "grep
payout/psp in lib/Service finds deposit and no-show fee code
(DepositReconciliationService.php, NoShowFeeCaptureService.php) and payment
session types; no service matches a provider payout batch to the ARInvoices it
settles". Reached on: "nothing reaches it". No demand row. Two competitors
rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Concept-sales-invoices-slsinv-mtchmolliepayc?language=en_GB, "If you have enabled automatic import of Mollie statements ... match your imported statements right away ... The Mollie file contains the payments from your customers and the transaction fees".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/208040-uitbetalingen-van-mollie-automatisch-verwerken-in-je-administratie, "Moneybird koppelt de juiste facturen aan elke uitbetaling en verwerkt ook de transactiekosten, chargebacks en refunds".

**`sal-tap-to-pay`**, "Take a card payment on the spot with your own phone and
have it booked in the administration." Rated no, built state none. Matrix
note: "No point-of-sale or tap-to-pay flow; card payments exist only as online
payment links through a payment provider port." Demand: changelog
https://www.moneybird.nl/changelog/laat-je-klant-contactloos-betalen/. Two
competitors rate it yes:

- moneybird: https://helpcenter.moneybird.nl/nl/articles/345450-tap-to-pay, "Met Tap to Pay kunnen klanten bij jou pinnen via de Moneybird-app. Je hebt geen losse pinautomaat of apart abonnement meer nodig".
- odoo: odoo/odoo@19.0 `addons/point_of_sale/models/res_config_settings.py:35` `module_pos_viva_com`, "processed by Viva.com on terminal or tap on phone".

This change covers both rows. The provider connection and the phone's payment
app are integriq's and the provider's (ADR-091, ADR-067); shillinq's half is
turning what the provider reports into matched invoices and booked money.

## Affected Projects

- [ ] Project: `shillinq`: a `ProviderPayout` record, payout matching and booking, a bank match to the payout, a take-payment-now action with a quick counter sale, and a per-user device setting.
- [ ] Project: `integriq`: reports settlements as CloudEvents and offers a point-of-sale payment on a registered device. No code in this repo.

## Scope

### In Scope

- Recording a payout from integriq's report: date, reference, gross, fees, refunds, chargebacks, net, and one line per underlying transaction.
- Matching each payment line to the `PaymentRequest`, `ARInvoice` or `DepositPayment` with that provider payment id, and listing what does not match.
- Booking the payout: net to the bank, fees to a fee account, refunds and chargebacks against the debtors they concern, all against the provider clearing account.
- Matching the bank statement line of the payout to the payout record instead of to invoices.
- Take payment now on an open invoice, and a quick counter sale that issues a simplified invoice, both paid on the user's phone through the provider's point-of-sale method and booked on capture.

### Out of Scope

- Fetching settlements from the provider, and the phone's payment app. integriq and the provider own them.
- Card terminals other than a phone registered with the provider.
- Stock for counter sales (`inventory-pos-decrement` covers POS stock).

## Approach

integriq reports each settlement as a CloudEvent in its event register; the
integriq CloudEvent listener this OpenSpec pass introduces writes a
`ProviderPayout`, and `PayoutMatchingService` resolves its lines by provider
payment id, which `receivables-payment-links` stores on every request. One
balanced ledger transaction per payout, written the way
`PaymentReconciliationService::bookObjectReceipt()` writes a receipt, books the
payout against the clearing account the capture booked to. Bank matching gains a
payout target. Take payment now reuses the payment command of
`receivables-payment-links` with the point-of-sale method and the user's
registered device. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `ProviderPayout` added; `ReconciliationMatch.targetType` gains `provider-payout`; a per-user `PaymentDevice` setting added (additive).
- Code: new `PayoutMatchingService`, `PayoutPostingRule`, `CounterSaleService`; a payout branch in the integriq CloudEvent listener; a payout candidate in bank matching.
- Manifest: `ProviderPayouts` index and detail under Banking, a Take payment now action on `ARInvoiceDetail`, a `CounterSale` page sized for a phone.

## Cross-Project Dependencies

- integriq `live-payment-providers`: fetch settlements from the provider (for Mollie, its settlements API) and emit one CloudEvent per settlement with its transactions; and a point-of-sale payment addressed to a device the user registered with the provider. Neither exists on integriq development today.
- The provider: whether its tap-to-pay app on a phone accepts a payment pushed to it (for Mollie, the point-of-sale method with a terminal id) is for integriq to confirm; if it does not, take payment now falls back to showing the checkout as a QR code the customer scans.

## Risks

### Risk 1: A payout booked twice, once by hand and once by the match
**Severity:** High. **Mitigation:** the bank line is matched to the payout record, and the payout's posting is the only booking of that line; a bank line already booked by hand keeps the payout at matched-not-booked with a warning.

### Risk 2: A payment in the payout that shillinq never saw
**Severity:** Medium. **Mitigation:** unmatched lines are listed on the payout with their provider payment id and amount, and the payout books them to the clearing account as unapplied until a bookkeeper assigns them.

### Risk 3: A counter sale taken but never captured
**Severity:** Medium. **Mitigation:** the simplified invoice is issued only on capture; a failed or abandoned payment leaves a cancelled draft and nothing booked.

## Rollback Strategy

Revert the PR. Payout records stay as history; bank lines matched to payouts
return to unmatched on the next reconciliation run.

## Open Questions

- Should fees be booked per transaction (as Mollie reports them) or once per payout? This change books them once per payout on one fee account, with the per-transaction fee kept on the payout line.

---
kind: code
depends_on: [case-payment-requests]
---

# Proposal: receivables-object-request-settlement

## Why

Larpinq asks shillinq for money on a registration and needs four things from shillinq that do not exist yet. They come from larpinq's merged change `registration-payments-through-shillinq` (larpinq development `f6a55a5`, merged in larpinq PR #749). Its "Cross-Project Dependencies" section says:

> Assumed and not yet specified in shillinq, reported for the coordinator: matching a bank line to an object payment request by its reference; a confirmation (receipt) mail to the debtor on capture of an object request; an invoice made on request from an object payment request; and a `requestType` suited to event fees (today `leges`, `dwangsom`, `deposit`, `other`), so this change uses `other` until then.

Larpinq's design depends on each of them:

- D1 creates the request through the `shillinq-payment-requests` leaf with a transfer reference such as `WC26-0042`, and passes `invoiceRequested` from the registration.
- D4: "a transfer that quotes it can be matched by a shillinq matching rule ... Matching an object payment request by reference is assumed in shillinq and reported as a sibling half; when shillinq marks the request captured, D2 sets the registration paid."
- Its scope leaves "invoices, receipts and bank statement matching" to shillinq.

The larpinq rows behind it are `reg-online-payment`, `reg-payment-status`, `reg-invoices`, `reg-payment-reminders` and `reg-bank-statement-match` in larpinq's `openspec/parity/capabilities.json`, each with LarpManager and pretix rated yes. For example `reg-bank-statement-match`: pretix "src/pretix/plugins/banktransfer/views.py:68,424 imports CSV, MT940 and SEPA CAMT statements ... and matches them to orders"; `reg-invoices`: LarpManager "larpmanager/fixtures/feature.yaml:957 receipts feature generating a PDF receipt per payment".

These halves were handed to shillinq after shillinq's own OpenSpec-pass lane had finished (owner-moves pass, 28 September 2026). Decision: build, because a merged change of another product depends on them.

## What changes

- `requestType` gains `event-fee`, with its own revenue account in the `paymentRevenueAccounts` mapping.
- A request on an object can carry a `paymentReference`, unique among open requests, which the leaf accepts on `create`.
- A bank line whose reference or remittance text quotes an open request's `paymentReference`, for the amount still open, is matched to that request. A single exact match settles the request as a bank transfer.
- When a request on an object is settled, by the provider, by hand or by the bank, the debtor gets a receipt by mail when an email address is known.
- A request created with `invoiceRequested` gets an issued `ARInvoice` behind it before it is paid, so the payment settles the invoice and the debtor has an invoice document.

## Scope

### In scope

- The `event-fee` request type and its revenue account.
- `paymentReference` and `invoiceRequested` on `PaymentRequest` and on the leaf's `create` payload.
- The bank line match for object requests and the settlement it triggers.
- The receipt mail on settlement.
- The invoice made at create when it is asked for.

### Out of scope

- Refunds and credit: `receivables-object-request-refund-and-credit`.
- An invoice asked for after the request is paid. The receipt mail is the proof of payment then.
- A PDF receipt. The receipt is the mail and the `confirmationSummary` the portal already shows.

## Impact

- `lib/Settings/register.d/ar-invoice-payment-links.json`: `requestType` enum, `paymentReference`, `invoiceRequested`, `receiptSentAt`.
- App config: `event-fee` in `paymentRevenueAccounts`, and a new `paymentRequestVatRates` mapping for invoices made from a request.
- `lib/Settings/shillinq_register.json`: `ReconciliationMatch.targetType` gains `payment-request`.
- `lib/Service/ObjectPaymentRequestValidator.php`, `lib/Integration/PaymentRequestLeafProvider.php`, `lib/Service/PaymentRevenueAccountResolver.php` (docs only).
- New: `lib/Service/ObjectRequestBankMatcher.php`, `lib/Listener/BankLineObjectRequestListener.php`, `lib/Listener/ObjectRequestSettledListener.php`, `lib/Service/ObjectRequestReceiptMailer.php`, `lib/Service/ObjectRequestInvoiceService.php`.

## Rows and halves

| requesting repo | requesting change | half |
|---|---|---|
| larpinq | registration-payments-through-shillinq | event-fee requestType |
| larpinq | registration-payments-through-shillinq | bank line match by reference |
| larpinq | registration-payments-through-shillinq | receipt on capture |
| larpinq | registration-payments-through-shillinq | invoice on request |

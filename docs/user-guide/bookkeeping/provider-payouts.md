---
sidebar_position: 10
title: Provider payouts
description: How Shillinq takes apart a payout of your payment provider, matches it to the invoices it paid, books the fees and reconciles the bank line.
---

# Provider payouts

A payment provider such as Mollie or Stripe does not send every payment to your bank one by one. It collects them and pays them out together, minus its fees and minus any refunds or chargebacks. Shillinq keeps each payout as a **provider payout** and takes it apart for you.

Open it from **Bookkeeping → Provider payouts**.

## What happens when a payout arrives

1. **Recorded.** Integriq reports the settlement and Shillinq keeps one provider payout for it, with its date, reference, gross amount, fees, refunds, chargebacks, the net amount paid out and one line per transaction. A settlement reported twice is kept once.
2. **Matched.** Each payment line is matched by the provider's payment id to the payment request, booking deposit or web shop invoice it paid. A refund or chargeback line is matched to the invoice of the payment it returns.
3. **Booked.** One balanced journal entry books the payout: the net amount to the bank, the fees to the fee account, refunds and chargebacks against debtors, and the gross amount out of the provider clearing account.
4. **Reconciled.** When the bank statement line of the payout comes in, with the same amount and the settlement reference in its description, it is matched to the payout. It needs no further action and is not booked a second time.

## Lines that match nothing

A payment the provider paid out but that Shillinq never asked for, for example one taken outside Shillinq, stays **unmatched**. The payout page shows it with its provider payment id and amount, the payout's *Open lines* counts it, and the bookkeeper gets a notification. Its amount stays on the clearing account until someone assigns it.

## Payments booked on capture

When a customer pays an invoice through the provider, Shillinq books the capture straight away: debit the clearing account, credit debtors. The invoice is paid in the ledger the moment the money is captured, and the payout later takes the same amount out of the clearing account.

Invoices that were paid through a provider before captures were booked have no such booking. An administrator lists them with:

```
occ shillinq:payments:book-captures
```

and books each at its capture date with:

```
occ shillinq:payments:book-captures --book
```

## Accounts

| Booking | Default account | Setting |
|---------|-----------------|---------|
| Net amount | 1020 | `payoutPostingAccounts`, key `bank`, per provider |
| Fees | 4720 Bankkosten | `payoutPostingAccounts`, key `fees`, per provider |
| Refunds and chargebacks | 1100 Debiteuren | `payoutPostingAccounts`, key `debtors`, per provider |
| Clearing | the `clearing` entry of `paymentRevenueAccounts` | |

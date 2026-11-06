---
kind: code
depends_on: [receivables-provider-payouts]
---

# Proposal: receivables-payouts-tab

## Summary

Board ShDebiteurenbeheer has a fourth tab, "Uitbetalingen provider": the
payouts a payment provider (Mollie, Stripe) made to the bank account, with
the fees it kept and how many of its payments are matched. The change
`receivables-provider-payouts` designs the `ProviderPayout` record (design.md
D1 and Seed Data), but nothing has built it yet, so the tab was left out. This
change builds that record as designed, with seeds, and draws the tab.

## Motivation

Approved by Ruben on 16 Oct 2026 as one of four shillinq data model items. The
rest of `receivables-provider-payouts` (the settlement listener, matching,
booking, bank match and take payment now) builds on this record, and the board
can show payouts now.

## Affected Projects

- [x] Project: `shillinq`: the `ProviderPayout` schema with seeds, declared in the register, and the tab on the receivables page.

## Scope

### In Scope

- `ProviderPayout` with the fields of design.md Seed Data: administration, provider, settlement reference, the day it was paid out, currency, the five amounts, the lines, state, bank statement line and journal entry. In addition it carries `matchedLineCount` and `unmatchedLineCount`, because the tab lists the counts and OpenRegister cannot count inside an array.
- Three seeds in Gemeente Voorbeeld (adm-gov-1), matching the board.
- The Provider payouts tab in `receivables-tabs`, newest first, counted from its list.

### Out of Scope

Everything else in `receivables-provider-payouts`, which keeps those tasks:
- the lifecycle and the unmatched notification;
- `PaymentDevice`;
- the posting settings;
- writing payouts from integriq's settlement event;
- matching and booking.

The board's latest-payout card beside the payment requests card, with its "Zelf koppelen" action, needs that matching first.

## Approach

The register change moves `<version>` in `appinfo/info.xml` and records the
new schema in `tests/fixtures/register-versions.lock.json`, so the
InitializeSettings repair step imports it on the next upgrade.

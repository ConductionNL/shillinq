---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: banking-fx-at-booking

## Summary

Shillinq keeps exchange rates and uses them when a foreign-currency invoice
is settled, but nothing applies a rate when the invoice or entry is booked:
a USD invoice lands in the ledger with whatever amount was typed. This
change looks up the rate of the booking date at posting, writes the base
amount and the rate snapshot on every foreign-currency ledger line, refuses
to post when no usable rate exists, and lets a user type a rate that then
wins. The rate feed itself is integriq's.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`). The OpenSpec pass of 2026-09-27
decided `build`. This change covers it.

**`bnk-fx-rates`**, "Book foreign-currency transactions at daily exchange
rates." Rated partial, built. The matrix evidence:
"lib/BackgroundJob/FxRateImportJob.php:130 skips when TreasuryRateAdapter is
dormant (connections.json treasury-rates reportedOnly), manual FxRate entry
via FxRatesAdmin; GLLine carries fxRate/fxRateSource fields
(add-shillinq-multi-currency-t4.json:155) but no posting code looks up
FxRate at booking time (only RealisedFxSettlementService.php:485 at
settlement)". The note: "Rates can be kept (by hand) and are used for
realised FX on settlement; the rate at booking is not applied
automatically." No demand row. Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Kies zelf of je met vaste of variabele [koersen werkt]", and the `financial/ExchangeRates` API resource (https://start.exactonline.nl/docs/HlpRestAPIResources.aspx).
- moneybird: https://www.moneybird.nl/changelog/facturen-in-vreemde-valuta-vaker-automatisch-gekoppeld/, "Moneybird haalt automatisch de wisselkoers op".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/valuta-s-3041130, currencies and "Koersen" maintained and imported.
- odoo: odoo/odoo@19.0 `odoo/addons/base/models/res_currency.py:273` `_get_conversion_rate` by date, and https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/multi_currency.html.

## Affected Projects

- [ ] Project: `shillinq`: a rate resolver used by the ledger posting path, a manual rate on journal lines, and a feed status on the FX rates pages.

## Scope

### In Scope

- Resolving the rate for a foreign-currency line from `FxRate` on or before the booking date, at posting.
- Writing `transactionAmount`, `transactionCurrency`, `baseCurrencyAmount`, `baseCurrency`, `fxRate`, `fxRateSource` and `fxRateDate` on each such `GLLine`.
- Refusing the post when there is no rate, or only one older than the allowed age.
- A rate typed on a journal line, recorded as source manual with a reason.
- Showing on the FX rates pages whether rates arrive from the feed or only by hand, and the date of the newest rate per currency.

### Out of Scope

- Fetching rates from the ECB or a market data provider. That source belongs in integriq (ADR-091); see Cross-Project Dependencies.
- Period-end revaluation and realised FX on settlement. Both exist (`bookkeeping-multi-currency` REQ-MC-006 and REQ-MC-010).
- Foreign-currency bank accounts and their statements.

## Approach

One small resolver, called from the one posting path `ledger-posting-path`
builds, so every posted line in a foreign currency gets the same treatment.
The manual rate is a field on the journal line. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/Treasury/FxAtBookingResolver.php` (new).
- `lib/Lifecycle/Action/MaterialiseGlTransactionAction.php` (added by `ledger-posting-path`): calls the resolver per line.
- `lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json`: an optional `fxRate` and `fxRateReason` on `JournalEntry.lines[]`.
- `src/manifest.json` `FXRates` and the `FxRatesAdmin` page: a feed status line.

## Cross-Project Dependencies

- integriq: a rates source serving ECB reference rates, which the `treasury-rates` connection and `TreasuryRateAdapterInterface` would then bind to instead of `LogTreasuryRateAdapter`. integriq has no such source on development (no ECB or exchange-rate source in its tree on 2026-09-27). Until it exists, rates are kept by hand and this change works on them.
- `ledger-posting-path` (open change in this repo): the `materialise-gl-transaction` handler is where lines are written.

## Risks

### Risk 1: A posting that used to succeed is now refused
**Severity:** Medium. **Mitigation:** only lines whose currency differs from the administration's base currency are affected, and the refusal names the currency, the date and where to enter a rate.

### Risk 2: A rate from a weekend or holiday
**Severity:** Low. **Mitigation:** the resolver takes the newest rate on or before the date, within a configurable age of five calendar days by default, which covers weekends and ECB holidays.

## Rollback Strategy

Remove the resolver call from the handler. Foreign-currency lines post
without a snapshot again, as today. Snapshots already written stay on the
lines and remain correct.

## Open Questions

None.

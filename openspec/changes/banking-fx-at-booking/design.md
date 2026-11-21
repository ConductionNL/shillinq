# Design: banking-fx-at-booking

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**Rates.** `FxRate` is declared in
`lib/Settings/register.d/add-shillinq-multi-currency-t4.json:11` (and
earlier in `lib/Settings/shillinq_register.json:12517`) with
`transactionCurrency`, `baseCurrency`, `date`, `source` (ecb, manual,
bank-feed), `rate` (transaction to base), `inverseRate`,
`manualOverrideReason` (required when source is manual) and
`administrationId`. They are kept on `FXRates`
(`/bookkeeping/multi-currency/fx-rates`) and `FxRatesAdmin`
(`/bookkeeping/multi-currency/fx-rates/admin`). The same fragment declares
a scheduled workflow `shillinq-fx-ecb-daily-ingest` against
`openconnector://ecb-eurofxref-daily`.

**The feed is dormant.** `lib/BackgroundJob/FxRateImportJob.php:128`
returns early when `TreasuryRateAdapterInterface::isDormant()`, and
`lib/AppInfo/Application.php:559` binds that interface to
`LogTreasuryRateAdapter`. `lib/Settings/connections.json` key
`treasury-rates` is `reportedOnly`. So every rate on an instance today was
typed.

**Lines.** `GLLine` in the same fragment (line 124) carries
`transactionAmount`, `transactionCurrency`, `baseCurrencyAmount` (integer
cents, "Equals `transactionAmount × fxRate`"), `baseCurrency`, `fxRate`
(line 151), `fxRateSource` and `fxRateDate`, described as the snapshot
"used at posting time". No code writes them: `grep` for `fxRateSource` or
`baseCurrencyAmount` in `lib/` finds only readers
(`lib/Reporting/Generator/BalanceSheetReportGenerator.php`,
`ProfitLossReportGenerator.php`) and
`lib/Service/Treasury/RealisedFxSettlementService.php`, whose
`resolveRate()` (line 476) reads `FxRate` at settlement.

**Where lines are written.** `ledger-posting-path` adds
`MaterialiseGlTransactionAction`, which writes one `GLLine` per source line
for `JournalEntry`, `APInvoice`, `ExpenseClaimEntry` and
`InventoryValuation` postings. That is the single place a booking-date rate
can be applied for all of them.

## Goals / Non-Goals

**Goals**

- Every posted foreign-currency line carries the rate it was booked at, where the rate came from, and the base amount.
- No foreign-currency line posts without a rate someone can trace.
- A user who agreed a rate can book at it.

**Non-Goals**

- Fetching rates (integriq).
- Changing revaluation or settlement.

## Decisions

### D1. Resolve in one service, call it from the posting handler

`FxAtBookingResolver::resolve(transactionCurrency, baseCurrency, date,
administrationId)` returns the newest `FxRate` on or before the date,
preferring an administration-scoped rate over a shared one and `ecb` over
`bank-feed` over `manual` on the same date, and refuses when the newest is
older than `fx_max_rate_age_days` (app config, default 5). The materialise
handler calls it for each line whose currency differs from the
administration's base currency and fills the snapshot fields; `amount`
becomes the base amount so the balance guard keeps comparing base amounts.

Alternative considered: a declarative calculation on `GLLine`
(`baseCurrencyAmount = transactionAmount × fxRate`). Rejected for the
lookup: choosing which `FxRate` applies is a query with precedence and an
age limit, which a field calculation cannot express. The multiplication
itself stays in the resolver so the rounding rule lives in one place.

### D2. A typed rate wins and says so

`JournalEntry.lines[]` gets optional `fxRate` and `fxRateReason`. When a
line carries a rate, the resolver is not called; the line is booked at
that rate with `fxRateSource` manual, and the reason is required, as
`FxRate.manualOverrideReason` already is for manual rates.

Alternative considered: make the user create an `FxRate` record first.
Rejected: an agreed rate belongs to one transaction, not to the day.

### D3. Refuse, do not guess

With no usable rate the handler throws, the transition aborts, and the
message names the currency pair, the date and the FX rates page.

Alternative considered: fall back to rate 1 or the newest rate of any age.
Rejected: a wrong base amount in a closed period is the error this row is
about.

### D4. Say where rates come from

The `FXRates` index gets a status line: "Rates arrive from the feed" when
the bound adapter is not dormant, otherwise "Rates are entered by hand",
with the newest rate date per currency pair from an aggregation on
`FxRate`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Choosing the rate for a line | Imperative, `FxAtBookingResolver` | Precedence and an age limit across records. |
| Writing the snapshot | Imperative, inside the existing materialise handler | That handler is the one writer of posted lines. |
| Manual rate on a journal line | Declarative, two optional fields | Plain data with a required reason. |
| Newest rate per pair | Declarative `x-openregister-aggregations` on `FxRate` | A grouped max over one schema. |

## Seed Data

Two optional fields are added to `JournalEntry.lines[]`. Seed `FxRate`
records for tests: USD to EUR 0.9150 on 2026-09-25 (source manual, reason
"ECB-koers overgenomen"), GBP to EUR 1.1830 on 2026-09-18. A journal entry
dated 2026-09-27 (a Sunday) for USD 1,000.00 books at 0.9150 as EUR 915.00;
a GBP entry on 2026-09-27 is refused because the newest GBP rate is nine
days old.

## Risks / Trade-offs

- [Rounding] → base amounts are rounded half-up to whole cents per line; the handler then checks the entry still balances in base currency and refuses if rounding broke it, naming the difference.
- [Two `FxRate` declarations] → the resolver reads the `register.d` shape; the older declaration is named for the schema consolidation work.

## Migration Plan

No data migration. Lines posted before this change keep empty snapshots
and are read as base currency, as today.

## Open Questions

None.

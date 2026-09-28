---
kind: code
depends_on: [banking-manual-match]
---

# Proposal: banking-connected-accounts

## Summary

Shillinq keeps several bank accounts but cannot connect them, and the group
liquidity dashboard shows a hard-coded zero as the cash position. The bank
connection itself is integriq's. This change is shillinq's half: a bank
account knows its connection and its ledger account, transactions integriq
pulls arrive as bank statement lines through the same intake as a file
import, a payment that matches one open invoice exactly is booked the moment
it arrives, and the cash position is summed per account and in total.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the bank account and its feed.
The OpenSpec pass of 2026-09-27 decided `build` for both. This change covers
both, for shillinq's half.

**`bnk-multi-account`**, "Connect several bank accounts and see the combined
cash position." Rated partial, built. The matrix evidence: "BankAccounts
index (bookkeeping-multi-currency.json) keeps several accounts;
lib/Service/FinancialSeriesCalculator.php:572-603 sums liquid GL accounts
into cashPosition ... 'connect' has no working feed (connections.json bunq
available:false); GroupLiquidityDashboard 'Group cash position' is a static
count 0". The note: "Accounts are kept and a combined GL cash position is
shown; accounts cannot be connected." No demand row. Four competitors rate
it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Bankkoppelingen: onbeperkt" and a cockpit with "banksaldi en openstaande posten".
- moneybird: https://www.moneybird.nl/prijzen/, "Externe rekening koppelen", with balances per account at https://helpcenter.moneybird.nl/nl/articles/207201-eindsaldo-en-het-rekeningsaldo-in-statistieken.
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/de-nieuwe-generatie-bankkoppeling-via-psd2, bank links for twelve Dutch banks per bank account.
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/bank/bank_synchronization.html, "Multi-account support" across institutions.

**`bnk-integrated-account`**, "Open a business payment account inside the
bookkeeping app so every payment is booked as it happens." Rated no, built
state none. The note: "Shillinq offers no payment account of its own; bank
data arrives by statement import (bnk-file-import)." Demand from the
moneybird changelog
https://www.moneybird.nl/changelog/ideal-voor-de-moneybird-betaalrekening/.
Two competitors rate it yes:

- moneybird: https://www.moneybird.nl/prijzen/, "De Moneybird Betaalrekening ... Realtime gesynchroniseerd met je administratie".
- snelstart: https://www.snelstart.nl/ondernemer/inbalans, "Betalingen, ontvangsten en reserveringen lopen automatisch en realtime mee in je administratie".

Shillinq will not hold money or become a bank. What both competitors sell
under this row, seen from the books, is an account whose payments are in the
administration as they happen. That is shillinq's half here: a connected
account whose transactions are booked on arrival. The account and the pull
are integriq's, rated under shillinq matrix row `bnk-psd2` (rated no, built
state building, owner ConductionNL/integriq).

## Affected Projects

- [ ] Project: `shillinq`: two fields on `BankAccount`, a listener for integriq's bank feed event, a shared statement intake used by the file import and the feed, an automatic booking step for exact matches, and a cash position per account on two dashboards.

## Scope

### In Scope

- `BankAccount.ledgerAccountNumber` and `BankAccount.bankConnectionId`, shown and edited on the bank account pages.
- A listener for `nl.conduction.bankfeed.transactions.synced` that turns integriq's batch into `BankStatement` and `BankStatementLine` records, once per batch.
- One statement intake service shared by the file import and the feed.
- Booking a feed line automatically when it matches exactly one open invoice on amount and payment reference; everything else waits in the unmatched worklist.
- A cash position per bank account (ledger balance and last known bank balance) and the combined total, on the group liquidity dashboard and the bank accounts page.

### Out of Scope

- The PSD2 connection, consent, account discovery and the transaction pull. integriq `psd2-ais-bank-feed-connector`.
- A payment account held by or through Conduction. No banking licence, no partner bank.
- Matching lines by hand. That is `banking-manual-match`.
- Initiating payments through the connection. That is `fees-payments-and-the-contract-register` REQ-FPCR-004.

## Approach

Reuse what exists: the file import already writes statements and lines,
the matcher already scores a line against invoices, the calculator already
knows which ledger accounts are liquid. The change moves the import's write
path into a service both channels call, adds the listener, lets an exact
match confirm itself, and groups the liquid balance by account. Details in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-multi-currency.json`: two fields on `BankAccount`.
- `lib/Service/Bank/StatementIntakeService.php` (new), called by `BankStatementImportController` and the new `lib/Listener/BankfeedSyncedListener.php`.
- `lib/BackgroundJob/BankfeedReconciliationJob.php`: retired; its matching moves into the intake step.
- `lib/Service/FinancialSeriesCalculator.php`: a per-account cash position beside the existing total.
- `src/manifest.d/30-treasury-ihb.json` and `src/manifest.d/bookkeeping-multi-currency.json`: the dashboard widget and the bank account columns.

## Cross-Project Dependencies

- integriq `psd2-ais-bank-feed-connector` (spec on integriq development): emits `nl.conduction.bankfeed.transactions.synced` with `{connectionId, accountIban, since, until, transactionCount, batchUri}` and persists the `bankfeed_batch` the `batchUri` points at. Shillinq consumes both and changes neither. This is the row `bnk-psd2`, owner ConductionNL/integriq, built state building.
- integriq, requested: a closing balance per account in the batch. Without it the last known bank balance comes from the most recent statement file (see Open Questions).
- OpenRegister: `ObjectService` and the lifecycle engine as they stand. No change needed there.
- `banking-manual-match` (open change in this repo): its settlement step moves an invoice to paid when a match is confirmed; the automatic booking here relies on it.

## Risks

### Risk 1: A payment is booked against the wrong invoice
**Severity:** High. **Mitigation:** automatic booking needs an exact amount, an exact payment reference or invoice number, and exactly one open candidate. Anything less stays a candidate a person confirms. Every automatic confirmation is audit-trailed as made by the system with the rule that allowed it.

### Risk 2: The same batch arrives twice
**Severity:** Medium. **Mitigation:** the statement records the `batchUri`; a second event for the same batch writes nothing, and lines are unique per account on their end-to-end reference.

### Risk 3: A ledger balance and a bank balance disagree
**Severity:** Low. **Mitigation:** that is information, not an error. Both are shown with their dates, and the difference is what the reconciliation worklist explains.

## Rollback Strategy

Unregister the listener; statements stop arriving from the feed and the file
import keeps working. The dashboard widget can return to its previous config.
Objects already written stay valid records.

## Open Questions

- Does integriq's `bankfeed_batch` carry a closing balance per account? If yes, the last known bank balance comes from the feed; if not, from the latest imported statement. The requirement is written to work either way.

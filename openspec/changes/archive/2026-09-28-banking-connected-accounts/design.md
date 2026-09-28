# Design: banking-connected-accounts

Read at shillinq development `79f438f33` and integriq development on
2026-09-27.

## Context

**Accounts.** `BankAccount` is declared twice: in
`lib/Settings/shillinq_register.json:18258` and in
`lib/Settings/register.d/bookkeeping-multi-currency.json:11`, the second
with `accountName`, `iban`, `bic`, `bankName`, `primaryCurrency`,
`administrationId`, `lifecycleState` and `notes`. Neither links the account
to a ledger account or to a connection. The pages are `BankAccounts`
(`/bookkeeping/bank-accounts`) and `BankAccountDetail` in
`src/manifest.d/bookkeeping-multi-currency.json`. `BankConnection`
(`shillinq_register.json:18`) holds `aggregator`, `aggregatorSourceSlug`,
`bankAccountIban`, `consentReference`, `consentExpiresAt`, `lastSyncAt`,
`lifecycleState` and `administrationId`, on pages `BankConnections` and
`BankConnectionDetail` (`src/manifest.json`).

**Statements.** The file import is `BankStatementImportController::import()`
(`lib/Controller/BankStatementImportController.php:120`,
`POST /api/v1/bank-statements/import`, `appinfo/routes.php:715`). It parses
with `StatementParser`, saves one `BankStatement` (line 159) and one
`BankStatementLine` per parsed line (line 186) with `matchState` unmatched
(line 245). The parse-and-save sits in the controller.

**The feed.** No working pull exists in shillinq (`connections.json` key
`bunq` is `available: false`). `lib/BackgroundJob/BankfeedReconciliationJob.php`
(registered in `appinfo/info.xml:101`) says at line 137 that "The PSD2 fetch
itself is performed by openconnector", reads existing `BankStatement`
records per active connection, scores each statement (not each line)
against `CashflowARProjection` records with `BankfeedMatcher`, counts, logs,
and writes nothing. Nothing in `lib/`, `src/` or `appinfo/` listens for
`nl.conduction.bankfeed.transactions.synced`. integriq's
`psd2-ais-bank-feed-connector` spec (REQ-004) emits exactly that event with
`{connectionId, accountIban, since, until, transactionCount, batchUri}` and
persists a `bankfeed_batch` object behind the `batchUri`.

**Matching.** `ReconciliationMatch` (`shillinq_register.json`, lifecycle
candidate to confirmed or rejected) records a pairing; its confirmation is
what `bookkeeping-bank-reconciliation` REQ-BR-006 says AR and AP consume to
move an invoice to paid. Nothing consumes it that way today: the only
listener on a confirmed match, `lib/Listener/ReconciliationMatchToReportListener.php`,
stamps report fields. `banking-manual-match` adds the settlement step, and
this change depends on it. `lib/Service/BankfeedMatcher.php:66`
`matchTransaction()` returns a confidence and an `arInvoiceId`.

**Cash position.** `FinancialSeriesCalculator::computeKpis()`
(`lib/Service/FinancialSeriesCalculator.php:572` to `603`) sums every posted
line on a liquid ledger account into one `cashPosition`, served by
`GET /api/dashboard/financial-summary` to the `Dashboard` page. The
`GroupLiquidityDashboard` page (`src/manifest.d/30-treasury-ihb.json:305`,
route `/treasury/group-liquidity`) has a stats-block "Group cash position"
with a literal `props.count` of 0. `bookkeeping-treasury-ihb` REQ-IHB-007
describes a nightly group cash position net of cash pool sweeps; it was
never built.

## Goals / Non-Goals

**Goals**

- A bank account shows whether it is connected, when it last synced, its ledger balance and its last known bank balance.
- A transaction integriq pulls is a statement line in shillinq within one event, through the same code as a file import.
- A payment that can only mean one open invoice is booked on arrival, and the invoice shows paid.
- The combined cash position is a real number, per administration and in total.

**Non-Goals**

- The connection, consent and pull (integriq).
- Cash pool sweeps and the nightly snapshot of REQ-IHB-007.
- Fuzzy auto-booking. Only exact matches book themselves.

## Decisions

### D1. Link the account, do not merge the schemas

Add `ledgerAccountNumber` (the liquid ledger account this bank account
books to) and `bankConnectionId` to `BankAccount` in
`bookkeeping-multi-currency.json`. The connection is found by
`BankConnection.bankAccountIban` equal to `BankAccount.iban` when the user
presses "Connect" on the bank account page, which opens the existing
REQ-BC-006 hand-off to integriq's consent flow.

Alternative considered: fold `BankConnection` into `BankAccount`. Rejected
because one consent can cover several accounts (integriq REQ-003 discovers
the account set per connection) and the connection's lifecycle is its own.

### D2. One intake for both channels

Move the save loop of `BankStatementImportController::import()` into
`StatementIntakeService::ingest(administrationId, iban, lines, source)`.
The controller calls it with source `file`; `BankfeedSyncedListener` calls
it with source `feed` and the `batchUri`. The service writes the
statement with `bankConnectionId`, `sourceBatchUri` and the balance when
known, skips a batch it has already written, and skips a line whose
end-to-end reference already exists for that account.

Alternative considered: have integriq write shillinq's `BankStatement`
directly. Rejected because the statement is shillinq's record (ADR-107
decision 1) and a second writer would need to know shillinq's matching and
duplicate rules.

### D3. Exact matches book themselves; everything else waits

After intake, each new line is scored. When exactly one open `ARInvoice`
or `APTransaction` has the same amount and a payment reference or invoice
number equal to the line's remittance, the service writes a
`ReconciliationMatch` and confirms it, with `confirmedBy` set to
`system:bankfeed` and the rule that allowed it. The settlement step of
`banking-manual-match` (REQ-BMM-003) then moves the invoice to paid, the
same way a person's confirmation does. Lines with no candidate, several, or a
partial score stay unmatched for `banking-manual-match`. This replaces
`BankfeedReconciliationJob`, which is unregistered.

Alternative considered: book every line above a confidence threshold, as
the job's 0.8 cut-off suggests. Rejected: a fuzzy score that books money
is the risk the automatic path must not carry.

### D4. The cash position is grouped by the ledger account the bank account names

`FinancialSeriesCalculator` gets `cashPositionByAccount()`, reusing its
liquid classification and signed amounts, returning per `BankAccount` the
ledger balance of its `ledgerAccountNumber`, the last known bank balance
with its date, and the total. It is served by
`GET /api/v1/cash-position?administrationId=` and read by the
`group-cash-position` widget and a column on `BankAccounts`. Liquid ledger
accounts no bank account names are shown on one line "Other liquid
accounts", so the total still equals today's `cashPosition`.

Alternative considered: a declarative `x-openregister-aggregations` sum on
`GLLine` grouped by account. Rejected for now because the liquid
classification and sign rules already live in the calculator; a second
path would produce a second number that can drift from the dashboard's.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Account to ledger and connection link | Declarative, two schema fields | Plain references. |
| Turning a batch into statements | Imperative, a listener and one intake service | An event with a remote payload has no declarative consumer; the service is shared, not parallel. |
| Confirming an exact match | Imperative inside the intake, then the declarative `confirm` transition | The transition and its consumers already exist. |
| Cash position per account | Imperative, the existing calculator | One source for the number the dashboard already shows. |
| The dashboard widget | Declarative manifest widget with a data source | Replaces a literal zero. |

## Seed Data

Two fields are added to `BankAccount`; the seed objects of the fragment
gain them. For "Gemeente Voorbeeld":

- "Betaalrekening ING", NL20INGB0001234567, ledger account 1100, connected.
- "Spaarrekening Rabobank", NL44RABO0123456789, ledger account 1110, not connected, last statement closing balance EUR 250,000.00 on 2026-09-26.

An invoice VF-2026-0877 of EUR 1,210.00 open, and a feed line of EUR
1,210.00 with remittance VF-2026-0877, cover the automatic booking; a line
of EUR 99.95 with remittance "abonnement" covers a line that waits.

## Risks / Trade-offs

- [A reference that looks exact is wrong] → the rule requires exactly one open candidate; a second invoice with the same amount and reference sends the line to the worklist.
- [Two `BankAccount` declarations] → the fields go on the `register.d` fragment that the pages read; the older declaration in `shillinq_register.json` is named in the PR for the schema consolidation work.
- [A feed without balances] → the bank balance falls back to the latest statement, with its date shown.

## Migration Plan

No data migration. Existing accounts show "not connected" and ledger
account empty until a user sets it; the total cash position is unchanged
because unassigned liquid accounts stay in the total.

## Built at development `fc519718d` (28 Sep 2026): where the design moved

- **The feed event (D2).** integriq does not dispatch a Nextcloud event per CloudEvent: `EventService::emitCloudEvent()` saves it as an `integriq` / `event` object, so `BankfeedSyncedListener` listens for `ObjectCreatedEvent` and reads the type, as `IntegriqCloudEventListener` does. `BankfeedIntakeService` reads the batch from `integriq` / `bankfeed_batch` by the uuid at the end of `batchUri`, finds the bank account by IBAN, and normalises the Berlin Group rows the aggregators return.
- **The file import was writing records the register refuses.** The old inline loop sent no `lineId` or `status` (both required by the merged `BankStatementLine`), the literal `manual-import` as a uuid-format `bankConnectionId`, a plain date for the date-time `statementDate`, `camt053` where `statementFormat` only allowed `camt.053.001.08`, and a `glAccountId` `BankStatement` did not declare. `StatementIntakeService` writes every required field; a file import's `bankConnectionId` is the nil uuid; `statementFormat` admits only `camt.053.001.08`, so the intake writes that and `importFormat` (which gains `feed`) carries the real format; `statementSource`, `sourceBatchUri` and `glAccountId` are declared (`BankStatement` 0.2.0).
- **Exact match (D3).** `ExactMatchBooker` confirms through `ManualMatchService::matchInvoices` with actor `system:bankfeed` and the rule in the reason, so the settlement listener of `banking-manual-match` pays the invoice. The reference must appear as a whole word, so VF-2026-087 never matches VF-2026-0877.
- **Retired.** `BankfeedReconciliationJob` and its only caller-less helper `BankfeedMatcher` are deleted with their tests.
- **Cash position (D4).** The per-account figures are a "Cash per bank account" table on the group liquidity dashboard (an index page cannot join an endpoint column); the bank accounts page shows the ledger account and the last sync. The endpoint takes the active administration when no `administrationId` is passed, because the dashboard has no administration token. A ledger account a bank account names counts as liquid.
- **Connect.** The hand-off is a "Connect a bank" header action on the bank accounts page that opens integriq's connections (the existing `openIntegriqConnections` handler); `BankAccount` gains `lastSyncAt`, set by each pull (`BankAccount` 0.3.0).

## Open Questions

- Whether integriq's batch carries a closing balance (proposal, Open Questions).

# Spec: bookkeeping-multi-currency

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-general-ledger (T1)

## ADDED Requirements

### REQ-MC-001: Each `GLLine` SHALL carry both a transaction-currency amount and a base-currency presentation amount

The T1 `GLLine` schema (per `bookkeeping-general-ledger`) MUST be
extended additively with the following fields so foreign-currency
postings are first-class without breaking single-currency callers:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `transactionAmount` | number ≥ 0 | Yes | The amount in the foreign currency the line was posted in (replaces the T1 single-currency `amount` semantically, but the field name MUST remain `amount` for backwards compat — see scenario below) |
| `transactionCurrency` | string (ISO 4217) | Yes | The foreign currency of the line (defaults to the administration's base currency for single-currency postings) |
| `baseCurrencyAmount` | number ≥ 0 | Yes | The amount converted to the administration's base currency at `fxRate` |
| `baseCurrency` | string (ISO 4217) | Yes | The administration's base currency at posting time |
| `fxRate` | number > 0 | Yes | The rate `transactionAmount × fxRate = baseCurrencyAmount` |
| `fxRateSource` | enum | Yes | One of `ecb`, `manual`, `internal-policy` |
| `fxRateDate` | date | Yes | The date the rate applies to (typically the posting date or the day before) |

For backwards compatibility, the T1 `amount` field is reinterpreted
as the `transactionAmount` (i.e. always in the line's transaction
currency); the new `baseCurrencyAmount` field MUST be present on
every line so the trial balance and statements always have a
single-currency view to aggregate.

#### Scenario: A single-currency line in the base currency posts with `fxRate = 1`

- **GIVEN** administration `adm-1` with base currency `EUR`
- **WHEN** a `GLLine` is posted with `amount: 1000`, `transactionCurrency: EUR`
- **THEN** the line MUST persist with `baseCurrencyAmount: 1000`,
  `baseCurrency: EUR`, `fxRate: 1.0`, `fxRateSource: internal-policy`.

#### Scenario: A USD line in a EUR administration converts at the day's ECB rate

- **GIVEN** administration `adm-1` with `EUR` base, an `FxRate`
  record `EUR/USD = 1.08` for `2026-07-15`
- **WHEN** a `GLLine` is posted with `amount: 1000`,
  `transactionCurrency: USD`, posting date `2026-07-15`
- **THEN** the line MUST persist with `baseCurrencyAmount: 925.93`
  (1000 / 1.08, rounded to 2 dp), `fxRate: 0.9259`,
  `fxRateSource: ecb`.

### REQ-MC-002: The system SHALL store FX rates as an OpenRegister-managed `FxRate` register

FX rates MUST be declared as a register in
`lib/Settings/shillinq_register.json` with the `FxRate` schema. The
register holds one record per (`baseCurrency`, `quoteCurrency`,
`date`, `source`) tuple. No custom PHP model, no custom database
table, no parallel link table (per ADR-022 anti-pattern list).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `baseCurrency` | string (ISO 4217) | Yes | The "from" currency of the quote |
| `quoteCurrency` | string (ISO 4217) | Yes | The "to" currency of the quote |
| `date` | date | Yes | The day the rate applies to |
| `rate` | number > 0 | Yes | The rate `1 baseCurrency = rate × quoteCurrency` |
| `source` | enum | Yes | One of `ecb`, `manual`, `internal-policy` |
| `manualOverrideReason` | string | No | Required when `source = manual` |
| `administrationId` | string | No | Optional — when set, this override applies only to one administration; when unset, the rate is global |

#### Scenario: A duplicate rate row is rejected

- **GIVEN** an `FxRate` record exists for `EUR/USD on 2026-07-15
  from ecb`
- **WHEN** another record with the same tuple is saved
- **THEN** the save MUST fail with a uniqueness-violation error.

#### Scenario: A manual rate without a reason is rejected

- **GIVEN** the schema
- **WHEN** an `FxRate` with `source: manual` and no
  `manualOverrideReason` is saved
- **THEN** the save MUST fail with a "manualOverrideReason required"
  error.

### REQ-MC-003: ECB daily FX rate ingestion SHALL be driven by an OpenRegister scheduled workflow

Per ADR-031 §"Background jobs" path 2, the daily import of ECB
reference rates MUST be implemented as an OpenRegister
`ScheduledWorkflow` calling an n8n workflow that fetches the ECB
XML feed, upserts `FxRate` records with `source: ecb`, and emits
a CloudEvent on completion. shillinq MUST NOT author a
`FxRateImportJob extends TimedJob` PHP class. The ECB HTTP fetch
itself routes through openconnector per ADR-022.

#### Scenario: Reviewer confirms no per-app TimedJob

- **GIVEN** the shillinq codebase
- **WHEN** scanned for classes extending `OCP\BackgroundJob\TimedJob`
  in `lib/BackgroundJob/` whose name matches `*Fx*` / `*ExchangeRate*`
- **THEN** no such classes SHALL exist; rate ingestion MUST be
  driven by a `ScheduledWorkflow` record.

#### Scenario: A manual rate overrides the ECB rate for the same day

- **GIVEN** an ECB rate `EUR/USD = 1.08` exists for `2026-07-15`
  and an operator records a manual rate `EUR/USD = 1.10` for the
  same day with reason "internal hedge contract"
- **WHEN** a GL line in `USD` is posted on `2026-07-15`
- **THEN** the manual rate MUST take precedence; **AND** the
  resulting line MUST record `fxRateSource: manual`.

### REQ-MC-004: Unrealised and realised FX gain/loss revaluation SHALL be a declarative scheduled OR workflow, not a service

Period-end revaluation of foreign-currency balances (open AR/AP
items, foreign-cash balances) MUST be implemented as a
`ScheduledWorkflow` triggered on period close (per T3
`bookkeeping-year-end-close` / period close): the workflow reads
each open foreign-currency position, computes the difference
between book-rate and period-end rate, and emits one balanced
`GLTransaction` per position posting unrealised gain or loss to
the configured account.

Realised gain/loss on settlement MUST be computed declaratively at
settlement time via an `x-openregister-lifecycle` action on the
sub-ledger record (per T2 sub-ledgers) that compares book-rate to
settlement-rate. No PHP `FxRevaluationService` orchestrates either.

#### Scenario: Unrealised gain is posted on period close

- **GIVEN** an open AR of `USD 1000` booked at `EUR/USD = 1.08`
  (`EUR 925.93`) and a period-end ECB rate of `EUR/USD = 1.10`
  (`EUR 909.09`)
- **WHEN** the period-end revaluation workflow runs
- **THEN** one `GLTransaction` MUST be emitted debiting "Ongerealiseerd
  koersverlies" `EUR 16.84` and crediting the AR control account
  the same; **AND** the audit trail MUST link the posting to the
  source open AR.

#### Scenario: Realised gain is posted on settlement

- **GIVEN** the open AR above is paid in full on a day when the
  rate is `EUR/USD = 1.05` (`EUR 952.38`)
- **WHEN** the cash receipt is matched
- **THEN** the settlement `GLTransaction` MUST post the
  difference between booked rate and settlement rate as "Gerealiseerde
  koerswinst" `EUR 26.45`.

### REQ-MC-005: Foreign-subsidiary consolidation SHALL translate per IAS 21 functional currency rules

For groups consolidating foreign subsidiaries (per T5
intercompany / consolidation), per-subsidiary statements MUST be
translated to the parent's presentation currency using IAS 21
rules: monetary assets/liabilities at closing rate, equity at
historical rate, P&L at average rate for the period, and the
resulting cumulative translation adjustment (CTA) MUST post to a
dedicated equity account on the consolidated balance sheet.

The translation MUST be declared as a `Mapping` consumed via the
OR Mappings abstraction (per ADR-022) referencing the rate
register; no PHP `ConsolidationTranslationService`.

#### Scenario: A USD subsidiary translates to a EUR group with CTA

- **GIVEN** a USD subsidiary with `Net assets: USD 100,000` at
  opening rate `EUR/USD = 1.10`, closing rate `EUR/USD = 1.05`,
  and average P&L rate `EUR/USD = 1.08`
- **WHEN** the consolidation run materialises the translated
  statements
- **THEN** the translated net assets MUST equal `EUR 95,238.10`,
  **AND** the CTA equity account MUST carry the difference
  between the translated balance-sheet equity and translated P&L,
  per IAS 21.

### REQ-MC-006: FX rates SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry
(`Bookkeeping > FX Rates`) with a `type: index` page binding to
the `FxRate` register and a `type: detail` page for individual
rate rows. Both pages MUST be rendered by the generic
`@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage`
components driven by manifest config — no bespoke Vue files (per
ADR-024 Tier-4).

#### Scenario: The index page filters rates by currency pair and source

- **GIVEN** the manifest declares the FX Rates pages
- **WHEN** an operator opens
  `/index.php/apps/shillinq/fx-rates`
- **THEN** the page MUST render via `CnIndexPage` with filter
  chips for `baseCurrency`, `quoteCurrency`, and `source`.

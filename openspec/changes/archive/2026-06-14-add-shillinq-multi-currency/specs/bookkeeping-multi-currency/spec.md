# Spec: bookkeeping-multi-currency

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-general-ledger (T1)

## MODIFIED Requirements

This capability is bound by **ADR-022** (consume OpenRegister abstractions; no
parallel app-local model layer) and **ADR-031** (declarative scheduled
workflows over imperative PHP services / TimedJobs). Every requirement below
restates one of those ADRs applied to the multi-currency slice.

### Requirement: REQ-GL-003 — The `GLLine` schema SHALL declare a fixed minimum field set, encode sign in `side`, and carry both a transaction-currency amount and a base-currency presentation amount

The `GLLine` schema MUST declare the multi-currency field set below, MUST encode debit/credit sign in the `side` enum, and MUST carry both a transaction-currency amount and a base-currency presentation amount. This supersedes T1 `bookkeeping-general-ledger` REQ-GL-003 (which declared single-currency `currency` + `amount` fields).

(Previously, per T1 `bookkeeping-general-ledger` REQ-GL-003: the
schema declared a single `currency` field that MUST equal the parent
transaction's `currency`, and a single `amount` field expressed in
that currency. The T1 wording explicitly noted "T5 will revisit when
multi-currency lands" — this spec is that revisit.)

When the multi-currency extension is installed, T1's REQ-GL-003 is
superseded by the field set below. The semantic shift is twofold:

1. T1's single `amount` field is **reinterpreted** as
   `transactionAmount` (the amount in the foreign currency the line
   was posted in). A new mandatory `baseCurrencyAmount` field carries
   the same value translated to the administration's base currency.
2. T1's single `currency` field is **renamed** (see the `## RENAMED
   Requirements` section below) to `transactionCurrency`, and a new
   mandatory `baseCurrency` field is added. The T1 invariant
   "`GLLine.currency` MUST equal the parent's `currency`" is
   **dropped** under multi-currency — the line's
   `transactionCurrency` MAY differ from the parent transaction's
   currency.

The full multi-currency field set for `GLLine`:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `transactionId` | string | Yes | (Unchanged from T1) FK to the parent `GLTransaction.id` |
| `lineNumber` | integer | Yes | (Unchanged from T1) Stable ordering within the transaction (1-based) |
| `accountNumber` | string | Yes | (Unchanged from T1) FK to `Account.accountNumber` |
| `side` | enum | Yes | (Unchanged from T1) `debit` or `credit` |
| `transactionAmount` | number ≥ 0 | Yes | The amount in the foreign currency the line was posted in. **Semantically replaces T1's `amount` field; the JSON property name `amount` remains for backwards compat** (i.e. on the wire and in storage the field is still serialised as `amount`, but its documented meaning is "transaction-currency amount"). |
| `transactionCurrency` | string (ISO 4217) | Yes | The foreign currency of the line. **Renamed from T1's `currency` field** (see RENAMED section). Defaults to the administration's `baseCurrency` for same-currency postings. |
| `baseCurrencyAmount` | number ≥ 0 | Yes | The amount converted to the administration's base currency at `fxRate`. Always present so the trial balance and statements have a single-currency view to aggregate. |
| `baseCurrency` | string (ISO 4217) | Yes | The administration's base currency at posting time. |
| `fxRate` | number > 0 | Yes | The rate `transactionAmount × fxRate = baseCurrencyAmount`. Orientation is "1 transactionCurrency = fxRate × baseCurrency" (see Fix-orientation note below and REQ-MC-002). |
| `fxRateSource` | enum | Yes | One of `ecb`, `manual`, `internal-policy`. |
| `fxRateDate` | date | Yes | The date the rate applies to (typically the posting date or the day before). |
| `periodId` | string | Yes | (Unchanged from T1) Resolved per `bookkeeping-general-ledger` REQ-GL-006. |
| `subLedgerType` | enum | No | (Unchanged from T1) `ap`, `ar`, `project`, `none`. |
| `subLedgerRef` | string | No | (Unchanged from T1) FK identifier into the sub-ledger. |
| `costCenter` | string | No | (Unchanged from T1) Cost-center / department code. |
| `description` | string | No | (Unchanged from T1) Line-level description. |

**FX orientation contract (single, consistent direction across the
spec).** `fxRate` on a `GLLine` is the rate that converts ONE unit
of `transactionCurrency` into base-currency units. Equivalently:
`baseCurrencyAmount = transactionAmount × fxRate`. So a USD line in
a EUR administration on a day when 1 USD ≈ 0.9259 EUR stores
`fxRate: 0.9259`. The `FxRate` register (REQ-MC-002) uses the same
orientation so `GLLine.fxRate` and the looked-up `FxRate.rate` join
directly with no reciprocation.

`transactionAmount` (the on-the-wire `amount`) MUST be non-negative;
the debit/credit polarity MUST live in the `side` enum (T1 rule
preserved per design.md Decision D2). The balance invariant per
REQ-GL-005 MUST be computed in `baseCurrencyAmount` (not
`transactionAmount`), because that is the only field guaranteed to
share a unit across all lines of a multi-currency transaction.

#### Scenario: A single-currency line in the base currency posts with `fxRate = 1`

- **GIVEN** administration `adm-1` with base currency `EUR`
- **WHEN** a `GLLine` is posted with `amount: 1000`,
  `transactionCurrency: EUR`
- **THEN** the line MUST persist with `baseCurrencyAmount: 1000`,
  `baseCurrency: EUR`, `fxRate: 1.0`, `fxRateSource: internal-policy`.

#### Scenario: A USD line in a EUR administration converts at the day's ECB rate (consistent orientation)

- **GIVEN** administration `adm-1` with `EUR` base, an `FxRate`
  record `transactionCurrency: USD, baseCurrency: EUR, rate: 0.9259`
  for `2026-07-15` (meaning 1 USD = 0.9259 EUR)
- **WHEN** a `GLLine` is posted with `amount: 1000`,
  `transactionCurrency: USD`, posting date `2026-07-15`
- **THEN** the line MUST persist with `transactionAmount: 1000`
  (serialised as `amount`), `baseCurrencyAmount: 925.93` (1000 ×
  0.9259, rounded to 2 dp), `fxRate: 0.9259` (the **same** value
  written on the `FxRate` row — no reciprocation), `baseCurrency:
  EUR`, `fxRateSource: ecb`.

#### Scenario: Join GLLine.fxRate to FxRate.rate produces baseCurrencyAmount

- **GIVEN** the `GLLine` above with `transactionCurrency: USD`,
  `baseCurrency: EUR`, `fxRateDate: 2026-07-15`
- **WHEN** a reconciliation job joins the line back to the `FxRate`
  register on `(transactionCurrency, baseCurrency, fxRateDate,
  fxRateSource)` and reads `FxRate.rate`
- **THEN** the looked-up `FxRate.rate` MUST equal `GLLine.fxRate`
  (both `0.9259`) and `transactionAmount × FxRate.rate` MUST equal
  `baseCurrencyAmount` to the cent — no reciprocation, no implicit
  inversion.

## RENAMED Requirements

### Requirement: REQ-GL-003 — `GLLine.transactionCurrency` (renamed from `GLLine.currency`)
FROM: `currency` (T1 `bookkeeping-general-ledger` REQ-GL-003)
TO: `transactionCurrency` (this spec REQ-GL-003 above)

The T1 field `GLLine.currency` is renamed to `transactionCurrency`
under the multi-currency extension so the line's foreign-currency
designation is unambiguous against the newly-added `baseCurrency`
field. The rename is a pure terminological clarification — the field
still holds the ISO 4217 code of the currency the line was posted
in. Migration: callers reading or writing `GLLine.currency` MUST be
updated to use `transactionCurrency`; the implementing cycle MAY
provide a deprecation alias for one release (per design.md). The T1
single-currency invariant "MUST equal the parent's `currency`" does
NOT carry over — see the MODIFIED REQ-GL-003 above for the new
multi-currency rule.

## ADDED Requirements

### Requirement: REQ-MC-001 — Every `GLLine` SHALL carry a transaction-currency amount, a base-currency presentation amount, and the FX rate that links them — orientation consistent with `FxRate.rate`

Every `GLLine` MUST carry the multi-currency field set declared in MODIFIED REQ-GL-003 above. The detailed multi-currency field set, semantic shift of T1's
`amount` to `transactionAmount`, the new mandatory
`baseCurrencyAmount` / `baseCurrency` / `fxRate` / `fxRateSource` /
`fxRateDate` fields, and the FX-orientation contract are defined
in the `## MODIFIED Requirements` REQ-GL-003 above (because they
supersede a T1 requirement, they belong in the MODIFIED section per
hydra `openspec/config.yaml rules.specs`).

This REQ-MC-001 entry preserves the requirement ID and acts as the
addition-side anchor for tasks.md / cross-spec references:
"REQ-MC-001 introduces multi-currency on `GLLine`" — implementation
work tagged at this ID MUST honour the MODIFIED REQ-GL-003 contract
(field set, balance computed in `baseCurrencyAmount`, single FX
orientation matching REQ-MC-002 with no reciprocation between
`GLLine.fxRate` and `FxRate.rate`).

#### Scenario: Cross-reference resolves to MODIFIED REQ-GL-003

- **GIVEN** a tasks.md entry of the form
  `bookkeeping-multi-currency#REQ-MC-001 — Add multi-currency
  fields to GLLine`
- **WHEN** a reviewer follows the reference to verify scenarios
- **THEN** the authoritative behavioural contract (field set,
  scenarios, orientation) MUST be the MODIFIED REQ-GL-003 in this
  spec — REQ-MC-001 is the anchor, REQ-GL-003 carries the content.

### Requirement: REQ-MC-002 — The system SHALL store FX rates as an OpenRegister-managed `FxRate` register

FX rates MUST be declared as a register in
`lib/Settings/shillinq_register.json` with the `FxRate` schema. The
register holds one record per (`transactionCurrency`,
`baseCurrency`, `date`, `source`) tuple. No custom PHP model, no
custom database table, no parallel link table (per ADR-022
anti-pattern list).

The `FxRate` schema MUST carry the Schema.org annotation
`schema:ExchangeRateSpecification` (per shillinq config.yaml
`rules.specs` and the cross-app convention in
`bookkeeping-chart-of-accounts` REQ-CoA-004): an `FxRate` row
records a quoted exchange-rate for a (pair, date, source) tuple and
maps cleanly to that vocabulary term.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `transactionCurrency` | string (ISO 4217) | Yes | The currency being converted **FROM** (the "quote" / foreign currency on a GL line — named to match `GLLine.transactionCurrency` for a clean join) |
| `baseCurrency` | string (ISO 4217) | Yes | The currency being converted **TO** (the administration's base currency) |
| `date` | date | Yes | The day the rate applies to |
| `rate` | number > 0 | Yes | The rate `1 transactionCurrency = rate × baseCurrency`. Same orientation as `GLLine.fxRate` (see REQ-GL-003 MODIFIED above) — a USD/EUR row for "1 USD = 0.9259 EUR" stores `rate: 0.9259`. |
| `source` | enum | Yes | One of `ecb`, `manual`, `internal-policy` |
| `manualOverrideReason` | string | No | Required when `source = manual` |
| `administrationId` | string | No | Optional — when set, this override applies only to one administration; when unset, the rate is global |

**Orientation note.** The `FxRate.rate` orientation is intentionally
"how much base currency is one unit of transaction currency worth"
— this is the more intuitive direction for the bookkeeper ("what
does this foreign-currency amount equal in the books?") and lets
`GLLine.fxRate` and `FxRate.rate` carry the same numeric value
without reciprocation. The ECB reference XML feed is published as
"1 EUR = N foreign" (i.e. inverted relative to our storage); the
ingestion workflow per REQ-MC-003 MUST invert the published rate
when storing (`our rate = 1 / ECB rate`) and MUST round to at least
6 decimal places to preserve precision on the round-trip.

#### Scenario: A duplicate rate row is rejected

- **GIVEN** an `FxRate` record exists for `transactionCurrency: USD,
  baseCurrency: EUR, date: 2026-07-15, source: ecb`
- **WHEN** another record with the same tuple is saved
- **THEN** the save MUST fail with a uniqueness-violation error.

#### Scenario: A manual rate without a reason is rejected

- **GIVEN** the schema
- **WHEN** an `FxRate` with `source: manual` and no
  `manualOverrideReason` is saved
- **THEN** the save MUST fail with a "manualOverrideReason required"
  error.

#### Scenario: ECB feed inversion on ingest

- **GIVEN** the ECB XML feed publishes `EUR/USD = 1.08` on
  `2026-07-15` (meaning 1 EUR = 1.08 USD)
- **WHEN** the ingestion workflow per REQ-MC-003 stores the rate in
  `FxRate` with `transactionCurrency: USD, baseCurrency: EUR`
- **THEN** the stored `rate` MUST be `0.925926` (≈ 1 / 1.08, rounded
  to 6 dp), preserving the "1 USD = 0.9259 EUR" orientation that
  matches `GLLine.fxRate`.

### Requirement: REQ-MC-003 — ECB daily FX rate ingestion SHALL be driven by an OpenRegister scheduled workflow

ECB daily FX rate ingestion MUST be driven by an OpenRegister `ScheduledWorkflow` record, NOT by an app-local PHP `TimedJob`. Per ADR-031 §"Background jobs" path 2, the daily import of ECB
reference rates MUST be implemented as an OpenRegister
`ScheduledWorkflow` calling an n8n workflow that fetches the ECB
XML feed, **inverts each published rate** per the orientation
contract in REQ-MC-002, upserts `FxRate` records with
`source: ecb`, and emits a CloudEvent on completion. shillinq MUST
NOT author a `FxRateImportJob extends TimedJob` PHP class. The ECB
HTTP fetch itself routes through openconnector per ADR-022.

#### Scenario: Reviewer confirms no per-app TimedJob

- **GIVEN** the shillinq codebase
- **WHEN** scanned for classes extending `OCP\BackgroundJob\TimedJob`
  in `lib/BackgroundJob/` whose name matches `*Fx*` / `*ExchangeRate*`
- **THEN** no such classes SHALL exist; rate ingestion MUST be
  driven by a `ScheduledWorkflow` record.

#### Scenario: A manual rate overrides the ECB rate for the same day

- **GIVEN** an ECB rate for `transactionCurrency: USD, baseCurrency:
  EUR` of `0.9259` exists for `2026-07-15` and an operator records
  a manual rate of `0.9091` for the same day with reason "internal
  hedge contract"
- **WHEN** a GL line in `USD` is posted on `2026-07-15`
- **THEN** the manual rate MUST take precedence; **AND** the
  resulting line MUST record `fxRateSource: manual` and `fxRate:
  0.9091`.

### Requirement: REQ-MC-004 — Unrealised and realised FX gain/loss revaluation SHALL be a declarative scheduled OR workflow, not a service

Unrealised and realised FX gain/loss revaluation MUST be expressed declaratively (an OR `ScheduledWorkflow` for period-end revaluation; an `x-openregister-lifecycle` action for realised gain/loss on settlement). Period-end revaluation of foreign-currency balances (open AR/AP
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

#### Scenario: Unrealised loss is posted on period close

- **GIVEN** an open AR of `USD 1000` booked at `fxRate: 0.9259`
  (`EUR 925.93`) and a period-end ECB rate of `fxRate: 0.9091`
  (`EUR 909.09`)
- **WHEN** the period-end revaluation workflow runs
- **THEN** one `GLTransaction` MUST be emitted debiting "Ongerealiseerd
  koersverlies" `EUR 16.84` and crediting the AR control account the
  same; **AND** the audit trail MUST link the posting to the source
  open AR.

#### Scenario: Realised gain is posted on settlement

- **GIVEN** the open AR above is paid in full on a day when the
  rate is `fxRate: 0.9524` (`EUR 952.38`)
- **WHEN** the cash receipt is matched
- **THEN** the settlement `GLTransaction` MUST post the
  difference between booked rate and settlement rate as "Gerealiseerde
  koerswinst" `EUR 26.45`.

### Requirement: REQ-MC-005 — Foreign-subsidiary consolidation SHALL translate per IAS 21 functional currency rules

Foreign-subsidiary consolidation MUST translate per-subsidiary statements to the parent's presentation currency using IAS 21 functional-currency rules, declared as an OR `Mapping` and never as a PHP service. For groups consolidating foreign subsidiaries (per T5
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
  opening rate `fxRate: 0.9091`, closing rate `fxRate: 0.9524`,
  and average P&L rate `fxRate: 0.9259`
- **WHEN** the consolidation run materialises the translated
  statements
- **THEN** the translated net assets MUST equal `EUR 95,240`
  (100,000 × 0.9524, rounded), **AND** the CTA equity account MUST
  carry the difference between the translated balance-sheet equity
  and translated P&L, per IAS 21.

### Requirement: REQ-MC-006 — FX rates SHALL be reachable through the shillinq manifest navigation

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
  chips for `transactionCurrency`, `baseCurrency`, and `source`.

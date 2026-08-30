---
status: done
---

# Spec: bookkeeping-multi-currency

**Status:** done
**Scope:** shillinq
**Tier:** T3 (treasury & cash management)
**Depends on:** `../add-shillinq-chart-of-accounts/spec.md` (T1 BankAccount foundation)
**OpenSpec changes:**
- `fx-period-end-revaluation` — adds period-end unrealised FX revaluation of
  open `FXPosition` balances, closing-rate resolution with manual-entry
  fallback, and auditable `FxRevaluationPosting` records
  (REQ-MC-006, REQ-MC-007, REQ-MC-008).
- `ar-billing-completeness` — adds settlement-time realised FX gain/loss
  posting for foreign-currency AR invoices, with balanced `GLTransaction` and
  auditable `RealisedFxPosting` records (REQ-MC-010).

## Purpose

This specification defines the requirements for bookkeeping multi currency in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.
## Requirements

@e2e exclude pending playwright spec: currency balances index/detail pages are declared in src/manifest.d/bookkeeping-multi-currency.json (REQ-MC-005) but no Playwright spec ships in this change; covered by a follow-up tests/e2e/bookkeeping-multi-currency.spec.ts.

### REQ-MC-001: Multi-currency bank account management SHALL be declared as `BankAccount.primaryCurrency` extension + `CurrencyBalance` register

Multi-currency cash management MUST be expressed as:

- `BankAccount` schema extension with optional `primaryCurrency` field
  (ISO 4217 code; null or default EUR).
- `CurrencyBalance` register — snapshots of per-currency balances per
  (account, currency) pair.

This capability **enables cash managers to track separate currency
balances without segregating bank accounts**, bridging the gap between
Shillinq's T1 chart-of-accounts (single base currency per administration)
and T3 liquidity reporting (per-currency cash position).

#### Scenario: Single-currency account without primaryCurrency declared

- **GIVEN** an existing Shillinq deployment with a EUR BankAccount
  `accountName: "Operationeel"` without `primaryCurrency` field
- **WHEN** the account is queried
- **THEN** the system MUST assume `primaryCurrency: "EUR"` as default;
  backward compatibility preserved.

#### Scenario: Multi-currency account with explicit primaryCurrency

- **GIVEN** a BankAccount with `{accountName:"Valuta-rekening", iban:"NL...", primaryCurrency:"USD", bankName:"ABN AMRO", lifecycleState:"active"}`
- **WHEN** the account is inspected
- **THEN** validation MUST pass; `primaryCurrency` MUST be a valid ISO
  4217 code; the account is declared ready for multi-currency balance
  tracking.

### REQ-MC-002: The `BankAccount` schema extension SHALL declare `primaryCurrency` and support multi-currency operations

The system SHALL satisfy this requirement: The `BankAccount` schema extension SHALL declare `primaryCurrency` and support multi-currency operations.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `primaryCurrency` | string | No | ISO 4217 currency code (e.g., EUR, USD, GBP); default EUR if null |

The `BankAccount` schema (existing T1 entity per `add-shillinq-chart-of-accounts`)
MUST extend with the above field. No destruction of existing records;
all existing `BankAccount` objects implicitly carry `primaryCurrency: EUR`.

Schema.org annotation: existing `schema:BankAccount`.

#### Scenario: Validate primaryCurrency is ISO 4217 compliant

- **GIVEN** the BankAccount schema with `primaryCurrency` extension
- **WHEN** `{accountName:"Test", primaryCurrency:"INVALID"}` is attempted
- **THEN** validation MUST fail with "Invalid ISO 4217 code".

#### Scenario: Allow null primaryCurrency for backward compat

- **GIVEN** the schema
- **WHEN** `{accountName:"Legacy", primaryCurrency:null}` is saved
- **THEN** validation MUST pass; system treats as EUR on read.

### REQ-MC-003: The `CurrencyBalance` schema SHALL track per-currency balance snapshots per account

The system SHALL satisfy this requirement: The `CurrencyBalance` schema SHALL track per-currency balance snapshots per account.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `balanceId` | string | Yes | Unique balance record identifier (e.g., bal-usd-001) |
| `accountId` | string | Yes | FK to BankAccount.id |
| `currency` | string | Yes | ISO 4217 currency code (e.g., EUR, USD, GBP) |
| `balance` | number | Yes | Current balance amount in the specified currency |
| `previousBalance` | number | No | Previous balance (for variance tracking, trend analysis) |
| `lastUpdated` | datetime | Yes | Timestamp of the most recent balance update |

`CurrencyBalance` MUST be a full register in `lib/Settings/shillinq_register.json`.
Each record represents a point-in-time balance for one (account,
currency) pair. Uniqueness constraint: (accountId, currency) —
one latest record per pair.

Schema.org annotation: `schema:Thing` (monetary amount holder).

#### Scenario: Create a currency balance snapshot

- **GIVEN** BankAccount with id `bank-usd-001`
- **WHEN** a `CurrencyBalance` record `{balanceId:"bal-usd-2026-05-21", accountId:"bank-usd-001", currency:"USD", balance:18750.00, lastUpdated:"2026-05-21T09:00:00Z"}` is saved
- **THEN** validation MUST pass; the record MUST be queryable by
  (accountId, currency) pair; `previousBalance` is optional.

#### Scenario: Update balance preserves previous value

- **GIVEN** an existing CurrencyBalance with `balance: 18750.00,
  previousBalance: 20000.00`
- **WHEN** the balance is updated to `20500.00`
- **THEN** `previousBalance` MUST be set to the old balance `18750.00`;
  `lastUpdated` MUST be current timestamp; audit trail MUST record
  the change.

#### Scenario: Prevent duplicate (accountId, currency) records

- **GIVEN** an existing CurrencyBalance for (accountId="bank-usd-001",
  currency="USD")
- **WHEN** another record with the same pair is attempted to be saved
  with a different `balanceId`
- **THEN** the system MUST either reject (enforce uniqueness) or
  silently update the existing record (upsert). Spec defines: upsert
  behavior — latest timestamp wins.

### REQ-MC-004: Per-currency balance queries SHALL filter and aggregate without custom PHP

Cash managers MUST be able to query:

1. All `CurrencyBalance` records for one account (all currencies).
2. All `CurrencyBalance` records for one currency (all accounts).
3. Aggregate balance by currency across multiple accounts.

All queries MUST be expressible via OpenRegister filtering + relations
(no custom PHP query builder; `ObjectService.findAll()` + filter
parameters suffice).

#### Scenario: Query all currencies for one account

- **GIVEN** BankAccount `bank-eur-001` with associated CurrencyBalance
  records for EUR, USD, GBP
- **WHEN** queried with filter `accountId="bank-eur-001"`
- **THEN** the system MUST return 3 CurrencyBalance records, one per
  currency, all with `lastUpdated` timestamps.

#### Scenario: Query aggregate balance by currency across accounts

- **GIVEN** three BankAccounts each with one CurrencyBalance (EUR amounts:
  45230.50, 12000.00, 8500.00)
- **WHEN** aggregated by filter `currency="EUR"`
- **THEN** the system MUST be able to sum balances to report total EUR
  position (65730.50) — via aggregation precondition or dashboard
  widget, not custom PHP.

#### Scenario: Filter by balance range

- **GIVEN** multiple CurrencyBalance records with varying balance
  amounts
- **WHEN** filtered by `balance > 20000 AND balance < 50000`
- **THEN** the system MUST return matching records; filter MUST be
  expressible in manifest UI or API query string (e.g.,
  `_filter=balance[gte]=20000&balance[lte]=50000`).

### REQ-MC-005: Manifest navigation SHALL expose Currency Balances index + detail pages

Shillinq's manifest MUST declare:

1. **Index page** (`type: index`) — table of all `CurrencyBalance`
   records with columns: account name, currency, balance, previous
   balance, last updated. Sortable by account / currency / balance /
   date. Filterable by account, currency, risk-level (implied from
   balance < 5000 = "low liquidity").
2. **Detail page** (`type: detail`) — single `CurrencyBalance` record
   with: account + currency metadata, balance, previous balance,
   change (balance - previous), % change, `lastUpdated` timestamp,
   audit trail link (for compliance).
3. **Navigation entry** — "Cash & Bank > Currency Balances" (or
   equivalent) in app menu.

No custom Vue components required; `CnIndexPage` + `CnDetailPage`
render the schema generically.

#### Scenario: Currency Balances index loads and displays multi-account view

- **GIVEN** an active Shillinq deployment with 5 bank accounts across
  3 currencies (EUR, USD, GBP)
- **WHEN** an operator navigates to Cash & Bank > Currency Balances
- **THEN** the index page MUST load; all 5 accounts' CurrencyBalance
  records MUST display (rows for EUR, USD, GBP variants per account);
  table MUST be sortable and filterable (at minimum by account,
  currency).

#### Scenario: Detail page shows balance history and audit trail

- **GIVEN** a CurrencyBalance record with `lastUpdated: 2026-05-21T09:00:00Z`
  and prior audit entries (e.g., balance changed from 18000 to 18750
  on 2026-05-20)
- **WHEN** the detail page is opened
- **THEN** the page MUST display: current & previous balance, change
  percentage, timestamp, and (via `CnObjectSidebar`) audit trail
  entries showing who updated the balance and when.

---

### Requirement: REQ-MC-006 — Period-end FX revaluation SHALL mark every open `FXPosition` to the administratie's functional-currency closing rate

The system MUST implement `FxRevaluationService::reval(string $administrationId, string $periodId): array` so that, for every `FXPosition`
record belonging to `$administrationId` whose `foreignCurrency` differs from
the administratie's `Administration.functionalCurrency` and whose `position`
is non-zero:

1. Resolve the period-end date from `$periodId` (`"yyyy-mm"` → last calendar
   day of that month).
2. Resolve a closing rate per REQ-MC-007.
3. Compute the incremental unrealised movement since the position's last
   recorded `spotRate`: `delta = position × (closingRate − priorSpotRate)`.
4. Update `FXPosition.spotRate`, `.fairValue` (`position × closingRate`),
   `.unrealisedPL` (`priorUnrealisedPL + delta`), and `.lastUpdated`.
5. When `priorSpotRate` was previously unset (no baseline exists yet), only
   establish the baseline (`spotRate`, `fairValue`, `unrealisedPL: 0`) —
   MUST NOT post a movement for a position with no prior mark.
6. When `|delta|` is material (≥ one cent in the functional currency), post
   an `FxRevaluationPosting` audit record (REQ-MC-008); when immaterial or
   zero, update the `FXPosition` mark only, without posting.

The method MUST return `array{postingCount: int, positionsEvaluated: int,
functionalCurrency: string, periodId: string}` — `postingCount` is exactly
the count of `FxRevaluationPosting` records created during the call. This is
the exact return shape `SoftCloseExecutor::delegateFxRevaluation()`
(`lib/Service/SoftCloseExecutor.php`) already reads via `$result['postingCount']`.

#### Scenario: Open USD position revalues at period-end and posts a gain

- **GIVEN** administratie `adm-holding-nl` (`functionalCurrency: "EUR"`) has
  an `FXPosition` `{foreignCurrency:"USD", position:100000, spotRate:0.90,
  fairValue:90000, unrealisedPL:0}`
- **WHEN** `reval("adm-holding-nl", "2026-03")` runs and the closing rate
  resolves to `0.93`
- **THEN** the position's `fairValue` MUST become `93000`, `unrealisedPL`
  MUST become `3000`, `spotRate` MUST become `0.93`
- **AND** exactly one `FxRevaluationPosting` MUST be created with
  `unrealisedDeltaCents: 300000`, `direction: "gain"`
- **AND** the returned `postingCount` MUST be `1`

#### Scenario: New position with no prior mark establishes baseline, posts nothing

- **GIVEN** an `FXPosition` with `spotRate: null`, `position: 50000`,
  `foreignCurrency: "GBP"`
- **WHEN** `reval()` runs and a closing rate resolves
- **THEN** `spotRate` and `fairValue` MUST be set to the closing rate's mark
  and `position × closingRate`
- **AND** no `FxRevaluationPosting` MUST be created for this position
- **AND** this position MUST NOT contribute to the returned `postingCount`

#### Scenario: Immaterial movement updates the mark but does not post

- **GIVEN** an `FXPosition` whose recomputed `delta` is `0.004` (functional
  currency, below one-cent materiality)
- **WHEN** `reval()` runs
- **THEN** `FXPosition.spotRate`/`fairValue` MUST still refresh to the new
  rate
- **AND** no `FxRevaluationPosting` MUST be created
- **AND** `postingCount` MUST NOT include this position

### Requirement: REQ-MC-007 — Closing-rate resolution SHALL prefer a live rate snapshot and fall back to the position's manually-maintained `spotRate`, never fabricating a rate

`FxRevaluationService` MUST resolve the closing rate for one `FXPosition` in
this order:

1. `TreasuryRateService::getFxSpot(foreignCurrency, functionalCurrency,
   periodEndDate)` — used when `TreasuryRateSnapshot::isLive()` is `true`.
2. `FXPosition.spotRate` (the group-treasurer's own manually-maintained
   value) — used when the snapshot is dormant AND `FXPosition.spotRate` is
   set and greater than zero. This is the exact fallback
   `LogTreasuryRateAdapter`'s own docblock documents ("FXPosition.spotRate
   manual-entry path carries the v1 value").
3. Neither available — the position MUST be skipped for this run (no
   posting, no `FXPosition` mutation, an info-level log entry) and MUST NOT
   cause `reval()` to throw or halt processing of the remaining positions.

#### Scenario: Dormant rate adapter falls back to the manually-maintained spotRate

- **GIVEN** the default `LogTreasuryRateAdapter` is bound (dormant,
  `isDormant(): true`) and an `FXPosition` has `spotRate: 0.86` set by a
  group-treasurer
- **WHEN** `reval()` runs
- **THEN** the closing rate used MUST be `0.86` and the resulting
  `FxRevaluationPosting.rateSource` MUST be `"manual-fallback"`

#### Scenario: No live rate and no manual spotRate skips the position without failing the run

- **GIVEN** the rate adapter is dormant and an `FXPosition` has `spotRate:
  null`
- **WHEN** `reval()` runs for an administratie with two other revaluable
  positions
- **THEN** the unrevaluable position MUST be skipped (no exception raised)
- **AND** the other two positions MUST still be processed and counted
  normally

### Requirement: REQ-MC-008 — Every `FxRevaluationPosting` SHALL be auditable to source position, rate, and posting attribution, and SHALL make `SoftCloseExecutor.fxPostings` observably non-zero

The `FxRevaluationPosting` register (declarative, `x-openregister-audit-trail: true`) MUST carry, per record: `administrationId`, `periodId`, `positionId`
(FK to the source `FXPosition`), `foreignCurrency`, `functionalCurrency`,
`netPosition`, `priorRate` (nullable), `closingRate`, `rateSource` (`live` |
`manual-fallback`), `unrealisedDeltaCents`, `direction` (`gain` | `loss`),
`targetGLAccount`, `contraGLAccount`, `journalEntryId`, `postedAt`,
`postedBy`, `reversalId` (nullable), `reversalState` (`posted` | `reversed`).

`SoftCloseExecutor::execute()`'s `fxPostings` report field, which was
unconditionally `0` before this change (the delegate class did not exist),
MUST be `> 0` for any administratie/period where at least one `FXPosition`
produces a material revaluation movement per REQ-MC-006.

#### Scenario: SoftCloseExecutor reports non-zero fxPostings when a revaluation posts

- **GIVEN** `FxRevaluationService` is bound in the DI container and an
  administratie has one `FXPosition` with a material period-end movement
- **WHEN** `SoftCloseExecutor::execute($administrationId, $periodId, $asOf)`
  runs
- **THEN** the returned report's `fxPostings` MUST be `≥ 1` and MUST equal
  the delegate's `postingCount`
- **AND** `postingCount` (the run total) MUST include `fxPostings`

#### Scenario: A controller can trace a posting back to its source position and rate

- **GIVEN** an `FxRevaluationPosting` created by a prior soft-close run
- **WHEN** an auditor inspects it
- **THEN** `positionId` MUST resolve to the exact `FXPosition` revalued,
  `priorRate`/`closingRate`/`rateSource` MUST explain the computed delta,
  and `postedBy` MUST be `FxRevaluationService::SYSTEM_ACTOR`
  (`"SYSTEM:FxRevaluationService"` — REQ-CLS-010 permits either the
  orchestrator or "specific service" as the posting actor; this change
  attributes to the specific service that computed the revaluation)

@e2e exclude pure backend: FX revaluation posting is nightly soft-close orchestration logic with no operator-facing UI in this change — not browser-testable; covered by PHPUnit (`tests/Unit/Service/Treasury/FxRevaluationServiceTest.php`, `tests/Unit/Service/SoftCloseExecutorTest.php`).

### Requirement: REQ-MC-010: Settlement of a foreign-currency AR invoice MUST post the realised FX gain/loss as a balanced GL entry

When an `ARInvoice` whose `currency` differs from the administration's
`Administration.functionalCurrency` is settled,
`OCA\Shillinq\Service\Treasury\RealisedFxSettlementService` MUST compute the
realised difference `foreignAmount x (paymentRate - invoiceRate)` in
functional-currency cents and, when non-zero, post a self-balancing two-line
`GLTransaction`: a realised GAIN debits the AR-control clearing account and
credits the realised-gain account (default `8022`); a realised LOSS debits the
realised-loss account (default `8023`) and credits AR-control (default `1130`).
In both directions `debit == credit == |difference|` and `isBalanced` is true.
The invoice-date rate is the invoice's booked `fxRate` when present, else the
`FxRate` register at the invoice date; the payment-date rate is the
gateway-reported rate when present, else the `FxRate` register at the
settlement date. A parallel append-only `RealisedFxPosting` audit record MUST
be written. Resolution gaps (same currency, missing rate, zero movement) post
nothing and MUST NOT block or un-settle the payment (fail-open). The realised
accounts are distinct from the unrealised `8020`/`8021` pair so the two FX legs
never conflate.

#### Scenario: Foreign-currency invoice collected at a stronger rate posts a realised gain
- **GIVEN** a USD invoice for 100000 booked at invoice-date rate 0.90 in a EUR-functional administration
- **WHEN** it settles at payment-date rate 0.93
- **THEN** a balanced `GLTransaction` debits AR-control `1130` €3000.00 and credits realised-gain `8022` €3000.00 (debit == credit == 300000 cents), and a `RealisedFxPosting` with `direction: "gain"`, `realisedDeltaCents: 300000` is written

#### Scenario: Foreign-currency invoice collected at a weaker rate posts a realised loss
- **GIVEN** the same USD invoice booked at invoice-date rate 0.93
- **WHEN** it settles at payment-date rate 0.90
- **THEN** a balanced `GLTransaction` debits realised-loss `8023` €3000.00 and credits AR-control `1130` €3000.00 (debit == credit == 300000 cents), and a `RealisedFxPosting` with `direction: "loss"`, `realisedDeltaCents: -300000` is written

#### Scenario: Missing rate or same-currency settlement posts nothing and never blocks the payment
- **GIVEN** a functional-currency (EUR) invoice, or a foreign-currency invoice for which no rate resolves
- **WHEN** it settles
- **THEN** no `GLTransaction` and no `RealisedFxPosting` are written, the settlement still succeeds, and the reason is reported

@e2e exclude pure backend/ledger: realised-FX settlement posting is schema + service + balanced GL behaviour exercised by PHPUnit (`tests/Unit/Service/Treasury/RealisedFxSettlementServiceTest.php`) — not browser-testable.

## Summary

This spec formalizes multi-currency bank account management as a core
Shillinq T3 capability, enabling Dutch SMBs and enterprises with
foreign operations to track EUR, USD, GBP, and other currency balances
natively without manual spreadsheets or account segregation. Foreign-currency
AR invoices are revalued at period-end (unrealised, REQ-MC-006/007/008) and
post their realised FX gain/loss on settlement (REQ-MC-010).

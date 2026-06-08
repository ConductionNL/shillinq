# Spec: bookkeeping-multi-currency

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (treasury & cash management)
**Depends on:** `../add-shillinq-chart-of-accounts/spec.md` (T1 BankAccount foundation)

## ADDED Requirements

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

## Summary

This spec formalizes multi-currency bank account management as a core
Shillinq T3 capability, enabling Dutch SMBs and enterprises with
foreign operations to track EUR, USD, GBP, and other currency balances
natively without manual spreadsheets or account segregation.

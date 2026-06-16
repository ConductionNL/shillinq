# Design — Multi-Currency Bank Account Management

**status:** pr-created

## Context

Dutch SMBs managing international trade hold bank accounts in multiple
currencies. Shillinq's T3 cash management layer (treasury & liquidity)
needs native multi-currency visibility — per-account balance tracking
by currency without manual spreadsheet workarounds or duplicate bank
accounts.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains *why*
the shape is what it is.

## Goals

- Express multi-currency bank account management as **declarative
  metadata** — schema extensions + `CurrencyBalance` register —
  per ADR-031.
- Consume OR's relation + filtering abstractions — per ADR-022. Zero
  app-local balance calculation.
- Make the spec a **cash manager readable contract** — bank account
  setup, currency declaration, balance snapshot semantics unambiguous.
- Support per-currency cash-flow reporting without custom PHP queries.
- Preserve backward compatibility — `primaryCurrency` is optional;
  single-currency accounts unaffected.

## Non-Goals

- No FX revaluation logic (GL-level unrealised/realised; T4's job).
- No automated bank API polling (T4 bank connectors handle sync).
- No multi-currency P&L consolidation (T5's job).
- No AI-driven account-to-currency matching.

## Decisions

### D1 — BankAccount declares primaryCurrency, CurrencyBalance tracks per-currency snapshots

`BankAccount` schema extends with optional `primaryCurrency` (ISO 4217
code; default EUR). `CurrencyBalance` is a separate register storing
one record per (account, currency) pair, containing the latest known
balance for that pair. This separation allows:
- Single bank account to hold EUR and USD (separate CurrencyBalance records).
- T3 cash reporting queries CurrencyBalance with currency filter
  without touching GL layer.
- Backward compatibility: existing single-currency accounts have one
  implicit CurrencyBalance (EUR) per account.

### D2 — CurrencyBalance is snapshot-based, timestamped

Every `CurrencyBalance` record carries `lastUpdated` datetime. This is a
point-in-time snapshot, not a transactional log. T3 reports consume
the latest snapshot per (account, currency) for cash-position reports.
T4 bank connectors (later) refresh snapshots on sync; no continuous
polling assumed by this spec.

**Alternative considered**: Stream-based balance ledger (every
transaction updates balance). Rejected — adds complexity; most SMB
workflows are end-of-day snapshots sufficient. Stream deferred to T5.

### D3 — BankAccount primaryCurrency is declaration, not enforcement

`primaryCurrency` is the account's native currency per bank statement.
Operators may manually record transactions in other currencies on the
same account; `CurrencyBalance` tracks them separately. No validation
rule "enforces only USD on USD account" — flexibility for edge cases
(e.g., multi-currency clearing accounts).

### D4 — No balance calculation in app layer

Balance values (`CurrencyBalance.balance`, `previousBalance`) are
inputs — either from bank API, operator entry, or imported statements.
Shillinq NEVER auto-calculates balance by summing GL postings on a
currency-filtered account (that's T4 aggregation's job). `CurrencyBalance`
is pure data storage.

### D5 — Manifest navigation shows Currency Balances as a T3 reporting surface

Two pages: (1) **Currency Balances Index** — grid of all (account,
currency) pairs, sortable by account/currency/balance, with filters
(account, currency, risk-level); (2) **Currency Balances Detail** —
single pair, with balance history sparkline (6–12 month timeline),
date of last update, links to associated GL transactions (T4 reporting
integration).

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Multi-currency account declaration | T1 `Account` + `BankAccount` with `currency` field | Extend `BankAccount` with `primaryCurrency`; T1 GL account currency is base currency |
| Per-currency balance tracking | New `CurrencyBalance` register | Snapshot register: (accountId, currency, balance, lastUpdated) |
| Balance snapshots | OR object storage + timestamps | CurrencyBalance leverages `createdAt`/`updatedAt` built-in fields; `lastUpdated` is semantic marker |
| Per-currency queries | OR filtering + relations | `CurrencyBalance.accountId` FK to BankAccount; filter by currency code + balance range |
| Manifest navigation | T1 manifest pattern | 2 pages (Index + Detail) per treasury-cash-management |
| Audit trail | OR audit-trail-immutable | Automatic on all CurrencyBalance changes |
| Reporting aggregation | T3 `treasury-reporting-pack` (deferred) | CurrencyBalance queries feed aggregations (multi-account by currency) |

**Net new code in implementation cycle**: 1 schema extension
(`BankAccount.primaryCurrency`) + 1 schema declaration (`CurrencyBalance`)
+ 1 manifest entry pair. No new PHP service.

## Seed Data

Multi-currency scenarios for Dutch SMB context:

### BankAccount (with primaryCurrency)

| accountName | iban | primaryCurrency | bankName | lifecycleState |
|---|---|---|---|---|
| EUR-operationeel | NL23ABNA0123456789 | EUR | ABN AMRO | active |
| USD-export | NL45RABO9876543210 | USD | Rabobank | active |
| GBP-trade | NL67BUNQ1234567890 | GBP | Bunq | active |

### CurrencyBalance (example snapshots)

| balanceId | accountId | currency | balance | previousBalance | lastUpdated |
|---|---|---|---|---|---|
| bal-eur-001 | bank-eur-op | EUR | 45230.50 | 42150.00 | 2026-05-21T09:00:00Z |
| bal-usd-001 | bank-usd-exp | USD | 18750.00 | 20000.00 | 2026-05-21T09:00:00Z |
| bal-gbp-001 | bank-gbp-trd | GBP | 12340.75 | 11500.00 | 2026-05-21T08:30:00Z |

Each record represents the latest known balance for that bank account
& currency pair, suitable for T3 cash-position dashboards and
end-of-period reconciliation.

## Architectural Alignment

- **ADR-031 (Declarative Business Logic)**: Multi-currency is pure
  schema + data storage; no PHP balance calculator.
- **ADR-022 (Consume OR Abstractions)**: CurrencyBalance leverages OR
  registers, relations, filtering; no app-local storage.
- **ADR-024 (Register Declarations)**: `CurrencyBalance` is a full
  register, not a config table.
- **ADR-001 (Data Layer)**: `BankAccount` → `CurrencyBalance` via
  relation (accountId FK), adhering to cross-entity reference pattern.

## Migration Path

For existing Shillinq deployments with `BankAccount` records:
1. All existing accounts assume `primaryCurrency: EUR` (implicit on
   load; schema default).
2. Operators MAY explicitly set `primaryCurrency` for non-EUR accounts
   via admin panel.
3. No destructive changes; `primaryCurrency` is nullable.
4. `CurrencyBalance` starts empty; populated on first bank sync (T4)
   or manual operator entry.

## Rollback Path

If multi-currency requirements change (e.g., simplification back to
single-currency), rollback is dataless:
1. Revert the spec commit; registers remain (no destructive changes).
2. `primaryCurrency` field is optional; single-currency deployments
   ignore it.
3. Audit trail preserves all balance history.

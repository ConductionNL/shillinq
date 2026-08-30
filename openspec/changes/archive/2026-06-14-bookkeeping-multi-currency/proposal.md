# Proposal: bookkeeping-multi-currency

`kind: config` per ADR-032 — the centre of mass is declarative
schema extensions + manifest entries for multi-currency cash account
management. No PHP currency conversion service or balance-tracking
logic is authored.

## Summary

Introduce the **foreign currency bank account management** capability
for Shillinq, enabling separate currency balance tracking per bank
account (e.g., EUR and USD balances on the same business entity) as
part of Treasury & Cash Management (T3 financial statement & liquidity
tier per `adr-001-bookkeeping-tier-roadmap.md`). This change extends
the `BankAccount` schema additively to support multi-currency
declarations, introduces the `CurrencyBalance` register for
per-currency balance snapshots, and declares manifest navigation for
currency balance visibility. No PHP service, no bespoke balance
calculation — all balance tracking via OpenRegister abstraction.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Dutch SMBs and enterprises with foreign suppliers / customers hold
bank accounts denominated in multiple currencies (EUR, USD, GBP).
Without dedicated multi-currency tracking, operators must manually
segregate balances by currency or use separate bank accounts, adding
operational friction. T1's chart-of-accounts layer defaults EUR; T3's
cash management needs per-currency visibility. This change provides
first-class multi-currency account management — each `BankAccount`
declares its native currency; T3 ledger balancing tracks separate
balance streams via `CurrencyBalance` snapshots for each account &
currency pair.

This proposal is extracted from the bundled `add-shillinq-bookkeeping-advanced`
proposal to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds multi-currency visibility to
  `BankAccount` schema, declares the `CurrencyBalance` register,
  adds 1 manifest navigation entry (Bank Accounts > Currency Balances),
  adds 1 index/detail page pair.
- [ ] Project: openregister — no source changes; this change consumes
  `@self` envelope seed data + relation navigation.

## Scope

### In Scope

- One new capability spec (`bookkeeping-multi-currency`) — see the
  `specs/` folder.
- `BankAccount` schema extension with optional `primaryCurrency` field
  (ISO 4217 code; default EUR) and support for multi-currency operations.
- `CurrencyBalance` register declaration — tracks balance per account &
  currency pair (balanceId, currency, balance, previousBalance, lastUpdated).
  Snapshot-based (point-in-time) for cash-flow reporting.
- Per-currency balance queries via OpenRegister's relation + filtering
  engine (no custom PHP balance calculator).
- Manifest navigation entry (Cash & Bank > Currency Balances) using
  `type: index` / `type: detail` renderers.
- Seed data: 3–5 example `BankAccount` + `CurrencyBalance` records
  demonstrating EUR/USD multi-currency scenarios.

### Out of Scope

- **FX revaluation** — GL-level unrealised/realised gain/loss; deferred
  to T4 `add-shillinq-multi-currency` (GL-focused FX).
- **Automated balance synchronization** — bank connectors (T4 advanced)
  handle API polling; this spec is shape-only.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` render generically.
- **Period-end consolidation** — T5's job. T3 manages per-account
  per-currency snapshot.

## Approach

One delta with two sections:

1. **`## ADDED Requirements`** — declares `REQ-MC-001` (anchor),
   `REQ-MC-002` (BankAccount multi-currency declaration),
   `REQ-MC-003` (CurrencyBalance register),
   `REQ-MC-004` (per-currency balance query),
   `REQ-MC-005` (manifest navigation).

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each new requirement is prefixed `REQ-MC-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions
(register declaration, relation navigation, filtering).

## Impact

- `lib/Settings/shillinq_register.json` — extends `BankAccount` with
  optional `primaryCurrency` field; adds 1 schema (`CurrencyBalance`).
- `src/manifest.json` — adds 1 navigation entry (Currency Balances) with
  index + detail pages.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **T1 `bookkeeping-chart-of-accounts`** — BankAccount is a T1 entity;
  multi-currency extension is additive (backward compatible).
- **T3 `treasury-cash-management`** — CurrencyBalance fits the cash
  reporting tier.

## Risks

### Risk 1: Missing `primaryCurrency` field defaults to EUR ambiguously

**Severity**: Low
**Mitigation**: Default `primaryCurrency` to EUR explicitly in schema;
deposit accounts default to the account's declared currency. Migration:
existing accounts assume EUR primary (no breaking change; nullable with
sensible default).

### Risk 2: Per-currency balance snapshot timing creates stale data

**Severity**: Low–Medium
**Mitigation**: CurrencyBalance timestamps via `lastUpdated` (audit
trail preserved). Operators check timestamp before relying on balance
for reconciliation. T4 bank-connector automation refreshes snapshots
on connection sync.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR. The additive `primaryCurrency`
field on `BankAccount` is nullable (backward compatible).

## Open Questions

1. **Single vs. multi-snapshot per currency per day** — current spec:
   one latest `CurrencyBalance` per (account, currency) pair; should
   we track intraday snapshots? Recommend one-per-day per fiscal
   close needs; multi-snapshot deferred to future enhancement.
2. **Automatic balance capture from bank API** — deferred to T4
   bank-connectors; this change is passive schema-only.
3. **Reporting aggregation** — group balances by currency across
   multiple accounts. Recommend T3 reporting layer queries on
   `CurrencyBalance` with aggregations; deferred to `treasury-reporting-pack`.

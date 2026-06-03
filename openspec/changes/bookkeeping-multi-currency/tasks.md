# Tasks — Multi-Currency Bank Account Management

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-multi-currency` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [x] Task 1: Confirm no `CurrencyBalance` schema exists and no
  multi-currency capability is declared in `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, or `adr-000-data-model.md`; note that this
  capability "enables multi-currency cash tracking for Dutch SMBs with
  foreign operations" and is aligned with T3 treasury management tier
  — Confirmed: no CurrencyBalance schema in register; no primaryCurrency on BankAccount; ADR-000 had CurrencyBalance stub (missing accountId) — both addressed.

- [x] Task 2: Confirm `BankAccount` schema from T1
  `add-shillinq-chart-of-accounts` is available and does NOT already
  carry a `primaryCurrency` or `currency` field that conflicts (if
  conflict exists, file blocker and defer Task 3+)
  — Confirmed: BankAccount exists in ADR-000 with `currency` field (ISO 4217 base currency). No `primaryCurrency`. No conflict — `primaryCurrency` is additive.

- [x] Task 3: Author `specs/bookkeeping-multi-currency/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (treasury & cash
  management)` / `Depends on: add-shillinq-chart-of-accounts (T1)` header
  — preserving the `## ADDED Requirements` block (`REQ-MC-001` through
  `REQ-MC-005`) with RFC 2119 keywords and `#### Scenario:` blocks with
  GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
  — Copied from /spec into openspec/changes/bookkeeping-multi-currency/spec.md.

- [x] Task 4: Author `proposal.md` referencing the shared
  `nextcloud-app` spec and including Affected Projects / Scope / Risks
  (stale balance snapshots, missing `primaryCurrency` ambiguity, balance
  timing for reconciliation) / Rollback / Open Questions (single vs.
  multi-snapshot per day, auto-capture cadence, aggregation layer) per
  shillinq config.yaml `rules.proposal`
  — Copied from /spec into openspec/changes/bookkeeping-multi-currency/proposal.md.

- [x] Task 5: Author `design.md` with Decisions (BankAccount
  `primaryCurrency` declaration, snapshot-based CurrencyBalance,
  optional field for backward compat, no balance calculation in app,
  manifest navigation pattern) and Reuse Analysis table per hydra
  `rules.design`; include baseline seed data for EUR/USD/GBP scenarios
  — Copied from /spec into openspec/changes/bookkeeping-multi-currency/design.md.

- [x] Task 6: Extend the `BankAccount` schema in
  `lib/Settings/shillinq_register.json` with optional `primaryCurrency`
  field (ISO 4217 string; null or default EUR) per REQ-MC-002; ensure
  existing `BankAccount` records implicitly carry `primaryCurrency: EUR`
  on read (backward compat); add validation: `primaryCurrency` MUST be
  valid ISO 4217 code if present
  — BankAccount schema added to register with primaryCurrency (nullable, pattern ^[A-Z]{3}$, default EUR).

- [x] Task 7: Declare the `CurrencyBalance` schema in
  `lib/Settings/shillinq_register.json` with all REQ-MC-003 fields
  (`balanceId`, `accountId` FK to BankAccount, `currency`, `balance`,
  `previousBalance`, `lastUpdated`); enforce uniqueness constraint on
  (accountId, currency) pair; upsert behavior on duplicate: latest
  timestamp wins
  — CurrencyBalance schema declared with all required fields + x-openregister-relations FK to BankAccount.

- [x] Task 8: Add OpenRegister filters to `CurrencyBalance` per REQ-MC-004
  — support filtering by accountId, currency (enum), balance range (gte/lte);
  test that queries like `?accountId=bank-001` and `?currency=USD&balance[gte]=5000`
  work via `ObjectService.findAll()` without custom PHP
  — x-openregister-filters declared for accountId, currency, balance[gte], balance[lte] + aggregations for totalByCurrency and lowLiquidityAccounts.

- [x] Task 9: Seed 3–5 example BankAccount + CurrencyBalance records in
  `lib/Settings/shillinq_register.json` per design.md Seed Data section
  (EUR-operationeel, USD-export, GBP-trade accounts with corresponding
  CurrencyBalance snapshots); use `@self` envelope; ensure seed data is
  idempotent per ADR-001 deduplication pattern (slug-based matching)
  — 3 BankAccount + 3 CurrencyBalance objects seeded in objects[] array with slug-based idempotency.

- [x] Task 10: Add manifest navigation entries (`Cash & Bank > Currency Balances`)
  to `src/manifest.json` — two pages: (1) `type: index` binding to
  `CurrencyBalance` schema, filtering by account + currency, sortable by
  balance + date; (2) `type: detail` page showing single record with
  account metadata, balance, previous, % change, audit trail; `node tests/validate-manifest.js`
  exits 0
  — Cash & Bank menu section added with BankAccounts + CurrencyBalances (index + detail pages each).

- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with
  `CurrencyBalance` entry; note the additive extension on `BankAccount.primaryCurrency`;
  declare relation: BankAccount → CurrencyBalance (one-to-many); cite
  the ADDED REQ-MC-NNN in `bookkeeping-multi-currency/spec.md` as the
  authoritative contract
  — BankAccount updated with primaryCurrency + lifecycleState + relation; CurrencyBalance updated with accountId + full description + authoritative spec ref.

- [x] Task 12: Add Dutch (`nl_NL`) + English (`en_US`) i18n strings per
  ADR-007: "Currency Balances", "Wisselkoersen-saldi", "Account",
  "Currency", "Balance", "Previous Balance", "Last Updated", "Change",
  "Liquidity Low Warning" (for balance < 5000 scenario), "EUR", "USD",
  "GBP", "Primary Currency", "Multi-currency Account"
  — All strings added to l10n/en.json and l10n/nl.json.

## Verification

`openspec validate` must exit clean on the change folder.

Cash manager / finance operations peer review (via OpenSpec gate)
confirms that per-currency balance snapshots satisfy cash-position
reporting needs for multi-currency SMBs (EUR main account + USD
supplier account use case, GBP subsidiary account scenario).

Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance
(CurrencyBalance is a full register; BankAccount.primaryCurrency is
optional additive extension; filtering via OpenRegister (no custom PHP);
manifest carries navigation; no balance-calculation PHP service). No
destructive schema changes; backward compatible.

Operator walkthrough: add a USD bank account (set `primaryCurrency: USD`)
→ manually enter or sync a CurrencyBalance record (e.g., balance: 18750
USD) → navigate to Currency Balances index → filter by currency="USD" →
verify record appears → click detail → verify account metadata + balance
+ audit trail display.

No source code changes outside `openspec/changes/bookkeeping-multi-currency/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests:**
  - BankAccount with null `primaryCurrency` defaults to EUR on read
  - BankAccount with explicit `primaryCurrency` (USD, GBP, etc.) saves
    and validates
  - CurrencyBalance creation with all fields + audit trail
  - CurrencyBalance upsert on duplicate (accountId, currency) pair —
    latest timestamp wins
  - Filter queries: by accountId, by currency, by balance range
  - Uniqueness constraint: duplicate (accountId, currency) rejected or
    upserted per spec

- **Playwright MCP browser tests:**
  - Currency Balances index page loads, displays all (account, currency)
    pairs
  - Index page sort: by account, currency, balance, date
  - Index page filter: by account dropdown, currency dropdown
  - Detail page opens, displays balance + previous + change + timestamp
  - Detail page audit trail sidebar: shows prior balance changes
  - Manifest navigation: "Cash & Bank > Currency Balances" menu item
    visible and clickable

- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors:

- `docs/user-guide/bookkeeping/multi-currency-accounts.md` — multi-currency
  account setup, currency declaration, balance snapshots, reconciliation workflow,
  multi-currency reporting use cases (SMB with EUR + USD + GBP accounts).
- `docs/guides/cash-position-multi-currency.md` — how to view and
  interpret multi-currency cash position; how to reconcile per-currency
  balances with bank statements; variance tracking via previousBalance.
- Screenshots: Currency Balances index (multi-account view), detail page
  (single pair with audit trail).

Per ADR-030 journeydoc convention.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings
(see Task 12 for full list).

## Data Migration

For existing Shillinq deployments with `BankAccount` records:

- No destructive changes: `primaryCurrency` is optional; defaults to EUR
  on read (backward compatible).
- Operator action: optionally set `primaryCurrency` for non-EUR accounts
  via admin panel or manifest detail page.
- `CurrencyBalance` starts empty; populated via bank API sync (T4) or
  manual operator entry.
- Audit trail preserved for all balance updates.

## Seed Data Generation Task

Seed data task per ADR-001 requirement: generate 3–5 realistic
BankAccount + CurrencyBalance records representing:
1. EUR main operating account (ABN AMRO / ING / Rabobank) with EUR balance
2. USD export account (Bunq / Wise) with USD balance
3. GBP trade account (optional, if multi-currency demand warrants) with GBP balance

Each pair includes realistic Dutch business context (e.g., EUR 45,230.50
main account, USD 18,750.00 export account). CurrencyBalance records
timestamped to current date (2026-05-21 or later). Records use valid
IBANs and bank names. Slug-based for idempotent re-import.

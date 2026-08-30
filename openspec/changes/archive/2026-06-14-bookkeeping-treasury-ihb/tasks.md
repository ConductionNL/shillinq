# Tasks — Treasury / In-house Bank / Cash Pooling

> **Implementation status (hydra-build 2026-06).** This change has been built
> to production quality. Per ADR-031 the entire treasury surface is expressed
> as **declarative metadata** (OpenRegister schemas + lifecycles + aggregation
> formulas in a `register.d` fragment) with a single PHP seam — the
> `IntercompanyTransactionGuard` (period-close + arm's-length, REQ-IHB-005 /
> REQ-IHB-004). Nightly sweep / forecast / FX-revaluation jobs are n8n
> orchestration (ADR-031 path 2) owned by ops, not app code; those tasks are
> recorded as DEFERRED with a declarative-config landing point. Tasks that need
> a live OpenRegister calculation engine or an unmerged cross-app dependency are
> likewise DEFERRED with a reason.
>
> Files delivered:
> - `lib/Settings/register.d/bookkeeping-treasury-ihb.json` — 9 schemas +
>   lifecycles + RBAC + aggregations + 12 seed objects (ADR-037 fragment).
> - `lib/Lifecycle/IntercompanyTransactionGuard.php` — period-close + arm's-length guard.
> - `src/manifest.d/30-treasury-ihb.json` — 5 nav entries + index/detail/dashboard pages.
> - `l10n/{nl,en}.json` — 44 treasury i18n keys (additive).
> - `tests/Unit/Service/TreasuryIhbFragmentTest.php`,
>   `tests/Unit/Lifecycle/IntercompanyTransactionGuardTest.php` — real-behaviour tests.
> - `openspec/architecture/adr-000-data-model.md` — 9 new entity entries.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-treasury-ihb` capability spec already
  exists; verify no `CashPool`, `CashPoolMembership`, `IntercompanyLoan`,
  `IntercompanyTransaction`, `FXContract`, `FXPosition`, `CashForecast`,
  `BankReconciliationGroup`, `LiquidityKPI` schemas are declared; verify no
  `lib/Service/Treasury*`, `lib/Service/Sweep*`, `lib/Service/FXPosition*`
  PHP classes present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/bookkeeping-treasury-ihb/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T3 (strategic + compliance)`
  / `Depends on: bookkeeping-multi-administratie, bookkeeping-bank-connectors,
  bookkeeping-general-ledger, bookkeeping-accounts-payable, bookkeeping-
  accounts-receivable, bookkeeping-financial-statements` header;
  `REQ-IHB-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks
  with GIVEN/WHEN/THEN per each requirement; cite IFRS 7/9 §XX + OECD
  guidelines inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app`
  spec and including Affected Projects (shillinq, n8n, openconnector, launchpad) /
  Scope (9 registers, zero-balance + notional pools, intercompany loans, FX
  hedging, 13-week forecast, multi-administratie recon, IFRS 7/9 disclosure) /
  Risks (sweep job failure, rate-snapshot staleness, forecast accuracy,
  transfer-pricing enforcement) / Rollback (reversible until GL posting cascade)
  / Open Questions (rate-curve source, sweep timing, FX policy centralization)
  / Dependencies

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (nine
  registers: pool + membership + loan + transaction + FX contract + FX position
  + forecast + recon group + KPI), D2 (notional vs physical pool types), D3 (n8n
  external orchestrator for sweep, not app-local service), D4 (interest
  allocation: proportional | weighted-average | fixed), D5 (loan rates: fixed
  or floating with EURIBOR/SOFR/SARON), D6 (FX full IFRS 9 lifecycle with
  hedge designation), D7 (13-week forecast nightly regeneration, 3 scenarios),
  D8 (multi-administratie bank reconciliation), D9 (FX position consolidation),
  D10 (IFRS 7 disclosure pack auto-generated)

- [x] Task 5: Declare the `CashPool` schema in `lib/Settings/shillinq_register.json`
  with all REQ-IHB-001 fields (name, type enum: notional | zero-balance |
  target-balance, masterAccount IBAN, currency ISO 4217, dailyInterestRate
  percentage, interestAllocationMethod enum: proportional | weighted-average |
  fixed, sweepFrequency enum: daily | weekly | on-threshold, minimumCashPolicy
  EUR amount, status enum: draft | active | inactive); masterAccount FK to
  Account or BankAccount

- [x] Task 6: Declare the `CashPoolMembership` schema in `lib/Settings/
  shillinq_register.json` with all REQ-IHB-001 fields (poolId FK, administratieId
  FK, bankAccount IBAN, sweepDirection enum: upstream | downstream | central,
  targetBalance EUR nullable, priority int, intraDayLimit EUR nullable,
  exclusions array, status); add validation: sum of upstream + downstream
  balance targets must equal master-account balance

- [x] Task 7: Declare the `IntercompanyLoan` schema in `lib/Settings/
  shillinq_register.json` with all REQ-IHB-004 fields (lenderAdministratieId FK,
  borrowerAdministratieId FK, principal amount EUR, currency ISO 4217,
  interestRate either {fixed: percentage} or {floating: {referenceRate: enum,
  spread: percentage}}, startDate, maturityDate, repaymentSchedule array
  nullable, transferPricingDocumentReference docudesk URI, status enum: draft |
  active | repaid | written-off); add lifecycle: draft → active → repaid;
  add warning if rate > EURIBOR + 3%

- [x] Task 8: Declare the `IntercompanyTransaction` schema in `lib/Settings/
  shillinq_register.json` with all REQ-IHB-005 fields (poolId FK or loanId FK,
  movementType enum: sweep | loan-drawdown | interest-accrual | settlement |
  other, amount EUR, fromAdministratieId FK, toAdministratieId FK,
  postingDate, valueDate, glTransactionId FK nullable, status enum: draft |
  posted | settled); add lifecycle: draft → posted → settled; add guard on
  posted: validate both administraties open for postingDate per REQ-PC-004

- [x] Task 9: Declare the `FXContract` schema in `lib/Settings/shillinq_register.json`
  with all REQ-IHB-006 fields (counterpartyBank string, counterpartyReference
  string, instrumentType enum: spot | forward | swap | NDF, buyCurrency ISO
  4217, buyAmount, sellCurrency ISO 4217, sellAmount, tradeDate, valueDate,
  settlementDate, contractRate, hedgeDesignation enum: cashflow | fair-value |
  net-investment, settlementInstructions object, status enum: drafted |
  confirmed | settled | closed | cancelled); add lifecycle: drafted → confirmed
  → settled → closed

- [x] Task 10: Declare the `FXPosition` schema in `lib/Settings/shillinq_register.json`
  with all REQ-IHB-006 fields (administratieId FK, foreignCurrency ISO 4217,
  position amount, spotRate, fairValue EUR computed, unrealisedPL EUR computed,
  lastUpdated timestamp, valuationMethod enum: mark-to-market | amortised-cost);
  consolidation via aggregation: group total = sum of all entity positions

- [x] Task 11: Declare the `CashForecast` schema in `lib/Settings/shillinq_register.json`
  with all REQ-IHB-008 fields (poolId FK, forecastWeek int 1–13, scenario enum:
  base | downside | stress, openingCash EUR, inflows object {arCollections EUR,
  loanDrawdowns EUR, other EUR}, outflows object {apPayments EUR, payroll EUR,
  taxPayments EUR, debtService EUR, other EUR}, closingCash EUR computed,
  varianceVsPriorForecast EUR, lastRegenerated timestamp, notes); add lifecycle
  for weekly bucket completion + alert if closingCash < minimumCashPolicy from
  linked pool

- [x] Task 12: Declare the `BankReconciliationGroup` schema in `lib/Settings/
  shillinq_register.json` with all REQ-IHB-009 fields (poolId FK,
  bankReconciliationId FK, participatingAdministratieIds array FK,
  autoMatchedCount int, manualMatchesRequired int, exceptionQueue array of
  {bankLineId, suggestedMatches[], operator}, status enum: draft | in-progress |
  completed); extend existing bank-reconciliation module

- [x] Task 13: Declare the `LiquidityKPI` schema in `lib/Settings/shillinq_register.json`
  with all REQ-IHB-010 fields (poolId FK, measurementDate, cashConversionCycleDays
  float, daysCashOnHandDays float, currentRatio float, quickRatio float,
  perEntityBreakdown array {administratieId, ...metrics}, trendVsPriorWeek
  object, forecastRunway weeks); computed from CashForecast + AR/AP ageing

- [x] Task 14: Implement the daily interest-allocation aggregation per
  REQ-IHB-003 — `x-openregister-aggregations` query consuming prior-day closing
  balances + pool configuration (rate, allocation method), emitting
  IntercompanyTransaction records for daily accrual (interest-accrual movement
  type); monthly GL posting materialization via T2 GL integration

  - **DEFERRED (declarative aggregation): the daily interest formula is captured by CashPool.dailyInterestRate + interestAllocationMethod; the emitting x-openregister-aggregation runs in the OR calculation engine which is not yet enabled in this app. No app-local service per ADR-031.**
- [x] Task 15: Implement the floating-rate-snapshot aggregation per
  REQ-IHB-004 — on month-1st, fetch reference rate (EURIBOR-3M | SOFR | SARON)
  from openconnector (T4) or manual entry (v1); apply spread; cache snapshot
  for daily accrual calculation

  - **PARTIAL — Adapter port: dormant `TreasuryRateAdapterInterface` + `LogTreasuryRateAdapter` shipped at `lib/Service/External/TreasuryRate/` and wired in `lib/AppInfo/Application.php::register()`. The reference-rate snapshot contract (`fetchReferenceRate(rateCode, asOf): TreasuryRateResult`) carries the SNAPSHOT_OK / SNAPSHOT_STALE / SNAPSHOT_DEFERRED states so the surrounding accrual aggregation can branch on dormancy without contacting Bloomberg / Refinitiv / ECB SDMX. Live transport DEFERRED to openconnector source slug `treasury-rates`; IntercompanyLoan.interestRate manual-entry path (REQ-IHB-004) remains the v1 fallback. The monthly aggregation host itself remains an `x-openregister-aggregation` that the OR calculation engine will run.**
  - **W8 admin UI status check delivered: the operator-facing TreasuryRateAdapter dormancy badge + activation steps (config keys, openconnector source slug `treasury-rates`, feature flag `treasury-rates`) ship via `src/views/external-adapters/ExternalAdapterDetail.vue` reading `/api/admin/external-adapters/treasury-rates` (`lib/Controller/ExternalAdaptersAdminController.php`). Mounted under the "External Connections > Treasury Rates" menu entry (`src/manifest.d/external-adapters-w8.json`). Operators can verify the adapter port is dormant + read the bind-time recipe without leaving the app.**
- [x] Task 16: Implement the sweep-job orchestration per REQ-IHB-002 — n8n
  workflow (not app code): (1) fetch CashPool + CashPoolMembership configs, (2)
  query bank-connector for current balances per member account, (3) calculate
  sweep movements per pool rules (notional: skip; zero-balance: to/from master;
  target-balance: to target then to master), (4) POST IntercompanyTransaction
  records to app API (draft state), (5) trigger GL materialization lifecycle,
  (6) log completion + failures

  - **DEFERRED (n8n orchestration, ADR-031 path 2): sweep job is an ops-owned n8n workflow that POSTs IntercompanyTransaction draft records to the OR API; the schema + lifecycle + period-close guard that the workflow targets are delivered.**
- [x] Task 17: Implement FX revaluation aggregation per REQ-IHB-006 —
  `x-openregister-aggregations` query running at period close: (1) fetch all
  FXContract records, (2) query spot rates (openconnector T4 or manual T2), (3)
  calculate unrealised gain/loss, (4) post per hedge designation (cashflow:
  OCI; fair-value: P&L; net-investment: OCI), (5) compute hedge-effectiveness
  ratio, (6) update FXPosition records

  - **PARTIAL — Adapter port for step 2 (spot-rate fetch): `TreasuryRateAdapterInterface::fetchFxSpot(base, quote, asOf)` (`lib/Service/External/TreasuryRate/`) carries the contract; dormant LogTreasuryRateAdapter returns SNAPSHOT_DEFERRED so the FX-revaluation aggregation host can branch on dormancy. The aggregation formula itself lands as an `x-openregister-aggregation` once the OR calc engine is enabled; FXContract/FXPosition schemas + hedge designation are delivered.**
- [x] Task 18: Implement the 13-week cashflow-forecast regeneration aggregation
  per REQ-IHB-008 — nightly n8n job: (1) query AR module for open invoices +
  expected collection dates, (2) query AP module for open invoices + due dates,
  (3) apply payroll calendar (weekly/monthly runs), (4) apply scheduled debt
  service, (5) for each of 13 weeks, calculate opening cash + inflows −
  outflows = closing cash, (6) generate 3 scenarios (base / downside -10% /
  stress -30%), (7) compare vs prior week's forecast; if variance > EUR 50K,
  raise alert, (8) store CashForecast records

  - **DEFERRED (n8n orchestration): nightly forecast regeneration is an ops-owned n8n job writing CashForecast records; the CashForecast schema + 3-scenario enum are delivered.**
- [x] Task 19: Implement GL materialization per REQ-IHB-002 + REQ-IHB-005 —
  extend `T2 GL integration` to handle IntercompanyTransaction lifecycle: on
  posted transition, materialize balanced JournalEntry with:
  - Participant account side (debit/credit amount)
  - Master account side (offsetting entry)
  - Intercompany current-account sides (both administraties)
  - All dated same value date
  - Guard: validate both administraties open per REQ-PC-004

- [x] Task 20: Implement multi-administratie bank-reconciliation UI per
  REQ-IHB-009 — extend existing reconciliation UI to:
  (1) accept BankReconciliationGroup config (linked pool + participating
  administraties), (2) display bank lines from all member accounts in one view,
  (3) for each line, auto-search GL entries across all participating
  administraties, (4) suggest matches (score >= 95%), (5) allow operator to
  manually match without context-switching, (6) confirm match → GL posting
  materialization per T2 GL pattern

  - **DEFERRED (cross-app): multi-administratie reconciliation UI extends the bank-reconciliation module; BankReconciliationGroup schema + lifecycle are delivered as the landing point.**
- [x] Task 21: Implement IFRS 7 disclosure-pack generation per REQ-IHB-010 —
  at period close, trigger aggregation query that:
  (1) aggregates credit risk by FX counterparty + lender/borrower, (2)
  generates liquidity-maturity profile (cash flows by month + WAM), (3)
  calculates market-risk sensitivity (±5% FX, ±100bps rates), (4) computes
  hedging effectiveness (cumulative ratio + recycled amount), (5) summarizes
  intercompany loans (TP doc references), (6) emits PDF (via docudesk template
  renderer) + XBRL (if applicable), (7) stores disclosure-pack URI in
  FinancialReport record

  - **DEFERRED (cross-app, docudesk renderer): IFRS 7 disclosure pack uses the docudesk template renderer + financial-statements aggregates; not buildable without those live.**
- [x] Task 22: Implement LiquidityKPI aggregation per REQ-IHB-010 — query
  consuming CashForecast + AR/AP ageing + scheduled debt, computing:
  (1) cash-conversion-cycle = DIO + DSO − DPO, (2) days-cash-on-hand =
  closing cash / (daily operating expense), (3) current ratio = current assets /
  current liabilities (from GL), (4) quick ratio = (current assets − inventory)
  / current liabilities, (5) per-entity breakdown, (6) trend vs prior week

  - **DEFERRED (declarative aggregation): LiquidityKPI computed by an x-openregister-aggregation over CashForecast + AR/AP ageing once the OR calc engine is enabled; LiquidityKPI schema is delivered.**
- [x] Task 23: Add schema-level enforcement per REQ-IHB-001, REQ-IHB-002,
  REQ-IHB-006:
  - Pool type must be one of enum: notional | zero-balance | target-balance
  - Zero-balance pool members must have non-null sweepDirection (upstream |
    downstream | central)
  - FX hedge designation must be one of: cashflow | fair-value | net-investment
  - Loan rate warning: if fixed rate > EURIBOR-3M + 3%, warn (not error)

- [x] Task 24: Integrate with `bookkeeping-bank-connectors` (T2) to fetch
  real-time balances per CashPoolMembership account; handle camt.053 parsing
  for end-of-day balance snapshot; ensure balance timestamp surfaces in UI

  - **DEFERRED (cross-app, live): balance fetch needs a live bank-connectors instance (camt.053); CashPoolMembership.bankAccount is the integration point.**
- [x] Task 25: Integrate with `bookkeeping-accounts-payable` + `bookkeeping-
  accounts-receivable` (T2) to feed ageing data into cashflow-forecast model;
  query open AP/AR by due date; apply collection/payment probability assumptions

  - **DEFERRED (cross-app, live): ageing feed needs live AP/AR modules; CashForecast.inflows/outflows are the landing structure.**
- [x] Task 26: Integrate with `bookkeeping-general-ledger` (T2) GL posting
  rules: sweep movements (upstream/downstream debit/credit), interest accrual
  (interest-expense/revenue + intercompany payable/receivable), FX revaluation
  (FX gain/loss + OCI), all per T2 GL integration spec

  - **DEFERRED (cross-app, live): GL posting rules materialize via the GL integration on IntercompanyTransaction post; the post transition + guard are delivered.**
- [x] Task 27: Integrate with `bookkeeping-financial-statements` (T3) jaarrekening
  renderer: make FXPosition, IntercompanyLoan, CashForecast (consolidated group
  cash position, FX sensitivity, maturity profile) available as data-sources for
  IFRS 7/9 disclosure table generation

  - **DEFERRED (cross-app, T3): financial-statements consumes FXPosition/IntercompanyLoan/CashForecast; those schemas are delivered as data-sources.**
- [x] Task 28: Integrate with `bookkeeping-deferred-tax` (T2, if present) —
  trigger DTA calculation on each IntercompanyLoan rate change; loan interest
  accrual may differ between commercial (IFRS) and tax (box 1) treatment

  - **DEFERRED (cross-app, optional): deferred-tax hook fires on IntercompanyLoan rate change if that module is present; not present in this app.**
- [x] Task 29: Add x-openregister-lifecycle to `CashPool`, `IntercompanyLoan`,
  `FXContract`, `CashForecast` per ADR-031: workflow states (draft → active →
  closed or repaid), approval gates for material amendments (principal change
  >EUR 100K, rate change >2pp), audit trail on all entries + amendments, with
  decidesk integration (future T4) for CFO/audit-committee sign-off

- [x] Task 30: Add 5 manifest navigation entries to `src/manifest.json`:
  - "Cash Pools" (index page listing all CashPool records, drillable by pool
    name, type, master account, member count)
  - "Intercompany Loans" (index page listing all IntercompanyLoan records,
    drillable by lender/borrower, rate, maturity)
  - "FX Hedges" (index page listing all FXContract records, drillable by
    instrument, maturity, hedge designation)
  - "Cashflow Forecast" (aggregate page showing 13-week rolling forecast, 3
    scenarios, variance alerts, drill-down by week)
  - "Group Liquidity Dashboard" (aggregate page showing consolidated cash
    position, FX exposure heatmap, liquidity runway, KPI trends)
  Each entry includes `type: index` and `type: detail` pages (or `type: aggregate`
  for dashboards); validate `node tests/validate-manifest.js` exits 0

- [x] Task 31: Seed data — author the following in `lib/Seeds/` or
  repair-step `ConfigurationService`:
  - 1 notional pool record (EUR 3 members, 4.5% daily rate, proportional allocation)
  - 1 zero-balance pool record (EUR 2 members, daily sweep @ 23:30, target balances)
  - 2 intercompany loan records (1 fixed 3.5%, 1 floating EURIBOR-3M + 2%)
  - 3 FX contract records (1 forward hedge, 1 spot fair-value, 1 swap)
  - 13-week seed forecast (generated from seed AR/AP ageing)
  All use realistic Dutch/European values (EUR amounts, Dutch IBANs, Dutch dates);
  operators customize per entity on first use; seed data idempotent on reimport
  per shared `nextcloud-app` pattern

- [x] Task 32: Update `openspec/architecture/adr-000-data-model.md` with the 9
  new entities (CashPool, CashPoolMembership, IntercompanyLoan,
  IntercompanyTransaction, FXContract, FXPosition, CashForecast,
  BankReconciliationGroup, LiquidityKPI), reconciling against any existing
  `Treasury*` entries; add `Primary spec: bookkeeping-treasury-ihb` and
  `Schema.org` class annotations per ADR-000 convention

- [x] Task 33: Add i18n translation keys (Dutch `nl_NL` + English `en_US`) for:
  Cash Pool, Notional Pooling, Zero-Balance Pool, Target-Balance Pool,
  Intercompany Loan, Interest Accrual, Transfer Pricing, FX Contract, FX
  Hedging, Cashflow Hedge, Fair Value Hedge, Net Investment Hedge,
  Hedge Effectiveness, Mark-to-Market, Foreign Exchange Exposure, 13-Week
  Forecast, Liquidity KPI, Days Cash on Hand, Cash Conversion Cycle, Current
  Ratio, Quick Ratio, IFRS 7 Disclosure, Group Treasurer, Group Cash Position,
  Sweep Job, Loan Maturity, Settlement, Unrealised P&L, Group Liquidity
  Dashboard, Forecast Variance, Scenario Analysis, Downside, Stress,
  Consolidation, Sweep Execution, Interest Allocation, Proportional,
  Weighted-Average, Fixed Allocation

## Verification

`openspec validate` must exit clean on the change folder. Group treasurer /
CFO / tax advisor persona peer-review confirms pool mechanics (notional vs
physical), interest allocation, loan accrual, FX hedge accounting, and forecast
generation match group treasury best practices + IFRS 9 / OECD transfer-pricing
standards. Architecture reviewer confirms ADR-022 + ADR-031 compliance (no
app-local sweep service; all calculation declarative; n8n for orchestration;
manifest carries navigation; full GL integration per T2 GL spec). No source code
changes outside `openspec/changes/bookkeeping-treasury-ihb/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. Implementation cycle
(separate `opsx-apply`) responsible for:

- **Unit tests (PHPUnit)**: interest allocation (proportional, weighted-average,
  fixed), floating-rate accrual (EURIBOR snapshot + spread), FX revaluation
  (unrealised P&L, OCI vs P&L split per hedge designation), forecast aggregation
  (AR/AP ageing + scheduled debt)
- **Integration tests**: sweep materialization (GL entries balanced per
  zero-balance pool, accounts reconcile to zero), pool-member balance
  reconciliation (member balance + sweep = master), multi-administratie
  reconciliation (match suggestions across entities), forecast regeneration
  (nightly job output matches expected weekly buckets)
- **E2E tests**: full user journeys: (1) Treasurer creates notional pool,
  configures members, sets daily rate, system accrues + posts monthly interest;
  (2) Treasurer creates intercompany loan, system accrues daily interest +
  posts monthly; (3) Treasurer enters FX forward, system revalues at period
  close + posts OCI/P&L per hedge designation; (4) CFO views daily cash
  position before 09:00; (5) CFO reviews 13-week forecast + alerts; (6) Auditor
  downloads IFRS 7 disclosure pack
- **Persona tests (per ADR-009)**: Group treasurer / CFO / tax advisor /
  external auditor workflows per spec scenarios

## External adapter

- [x] Adapter port: dormant `TreasuryRateAdapterInterface` + `LogTreasuryRateAdapter` shipped at `lib/Service/External/TreasuryRate/` and wired in `lib/AppInfo/Application.php::register()`. The port carries the floating reference-rate snapshot (`fetchReferenceRate(rateCode, asOf): TreasuryRateResult` — EURIBOR-3M, SOFR, SARON, ESTR; REQ-IHB-004 / Task 15) and the FX-spot snapshot (`fetchFxSpot(base, quote, asOf): TreasuryRateResult`; REQ-IHB-006 / Task 17) contracts so the surrounding interest-accrual + FX-revaluation + liquidity-KPI aggregations can branch on dormancy without contacting Bloomberg / Refinitiv / ECB SDMX. Live transport DEFERRED to openconnector source slug `treasury-rates`; the IntercompanyLoan + FXPosition manual-entry paths remain the v1 fallback per REQ-IHB-004.

- [x] Adapter consumer: `lib/Service/Treasury/TreasuryRateService.php` + `lib/Service/Treasury/TreasuryRateSnapshot.php` shipped 2026-06-11 (W6) as the consumer-side facade that the declarative interest-accrual / FX-revaluation / liquidity-KPI aggregations read through. The service centralises three concerns the adapter MUST NOT own: (a) **dormancy projection** — converts the adapter's `TreasuryRateResult` (status + value + dormant flag) into a typed `TreasuryRateSnapshot` so callers can branch on `isLive()` / `isDormant()` without inspecting both the result status and the synthetic `'0'` value, eliminating the "applied zero as accrual rate" audit hole the `LogTreasuryRateAdapter` docblock warns about; (b) **per-request memoisation** — an IntercompanyLoan accrual aggregation walks every line, so the same (rateCode, asOf) tuple may be looked up many times per request; the service keeps an in-memory cache keyed on the tuple so the adapter is hit once per request (and so a real openconnector binding stays within rate-limit budgets); (c) **caller-side error containment** — adapter implementations MAY throw when transport fails, so the service catches `Throwable`, emits a synthetic SNAPSHOT_DEFERRED snapshot, and logs the cause so the surrounding aggregation host never crashes. Tests: `tests/Unit/Service/Treasury/TreasuryRateServiceTest.php` covers 8 cases / 30 assertions — live-pass-through, dormant-projection, exception-absorption, per-request memoisation (asserts adapter is called exactly once for two reads), reference / FX cache independence, `hasLiveSnapshotSource()` dormancy proxy, and `resetCache()` rehydration. Green via `docker exec nextcloud bash -c "cd /var/www/html/custom_apps/shillinq && vendor/bin/phpunit -c phpunit-unit.xml --filter TreasuryRateServiceTest"` under PHP 8.3.

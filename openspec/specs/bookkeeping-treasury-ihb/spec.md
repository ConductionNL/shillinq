---
status: done
---

# Spec: bookkeeping-treasury-ihb

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (strategic + compliance)
**Depends on:** `../add-shillinq-multi-administratie/specs/bookkeeping-multi-administratie/spec.md` (T1 administratie master),
`../add-shillinq-bank-connectors/specs/bookkeeping-bank-connectors/spec.md` (T2 bank statements),
`../add-shillinq-general-ledger/specs/bookkeeping-general-ledger/spec.md` (T2 GL),
`../bookkeeping-accounts-payable-core/spec.md` (T2 AP ageing),
`../add-shillinq-accounts-receivable-core/spec.md` (T2 AR ageing),
`../add-shillinq-financial-statements/specs/bookkeeping-financial-statements/spec.md` (T3 IFRS disclosure)

## Purpose

This specification defines the requirements for bookkeeping treasury ihb in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: treasury IHB entries page not yet implemented


### REQ-IHB-001: Cash pools SHALL be declared as `CashPool` + `CashPoolMembership` registers with type discriminator (notional | zero-balance | target-balance)

CashPool MUST be expressed as two new registers in `lib/Settings/shillinq_register.json`:

- **CashPool** — pool header (name, type enum: notional | zero-balance | target-balance,
  master account, base currency, daily interest rate, interest allocation method enum:
  proportional | weighted-average | fixed, sweep frequency, minimum cash policy EUR amount).
- **CashPoolMembership** — per-administratie per-bank-account link (pool reference,
  administratieId, bank account IBAN, sweep direction: upstream | downstream | central,
  target balance EUR, priority int, intra-day limit EUR, exclusions array).

Schema.org annotations: `CashPool: schema:FinancialProduct`, `CashPoolMembership: schema:AggregateOffer`.

#### Scenario: Reviewer confirms no parallel pool table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `cash_pool`, `pool_membership`, or `treasury_pool_*`
- **THEN** no such classes SHALL exist.

#### Scenario: Notional pool records interest calculation without cash movement

- **GIVEN** CashPool with `type: notional`, daily rate 4.5%, members A (EUR 100K) and B (EUR 50K)
- **WHEN** daily interest aggregates on the EUR 150K balance (EUR 150000 × 4.5% / 365 = EUR 18.49)
- **THEN** interest MUST be allocated per `interestAllocationMethod` (proportional: A gets EUR 12.33, B gets EUR 6.16) and posted monthly to GL **without moving cash**.

#### Scenario: Zero-balance pool executes end-of-day sweep

- **GIVEN** zero-balance pool with master account M and members A (ending at +EUR 50K), B (at -EUR 30K), C (at +EUR 10K)
- **WHEN** sweep job runs at 23:30
- **THEN** sweep MUST credit A by EUR 50K to M, debit B by EUR 30K from M, credit C by EUR 10K to M, leaving A, B, C at zero and M at +EUR 30K, all with corresponding intercompany current-account entries, same value date.

### REQ-IHB-002: Zero-balance sweep SHALL generate end-of-day sweep transactions per pool rules, posting one side to participant ledger and offsetting side to master ledger plus intercompany current account on both sides

For pools with `type: zero-balance`, the system MUST execute nightly sweep job:
1. Fetch current balances from bank connectors (camt.053) per participating account
2. Calculate sweep movements per CashPoolMembership (upstream/downstream) per `CashPool.sweepFrequency`
3. Create `IntercompanyTransaction` records (one per participant movement: sweep amount, direction)
4. Trigger GL lifecycle to materialize balanced `JournalEntry` per T2 GL pattern:
   - Participant account debit/credit sweep amount
   - Master account offsetting debit/credit
   - Intercompany current-account payable/receivable on both sides
   - All dated same value date (T+0)

No partial sweeps; all-or-nothing per pool execution.

#### Scenario: Sweep materializes balanced GL entries

- **GIVEN** zero-balance pool with master account NL91ABNA0417164300 and member A account NL12INGD1234567890 (EUR 50K balance)
- **WHEN** sweep job runs and creates IntercompanyTransaction (amount: EUR 50000, direction: upstream, status: draft)
- **THEN** transaction lifecycle MUST materialize balanced JournalEntry:
  - Participant A: debit cash EUR 50000, credit intercompany payable EUR 50000
  - Master: debit intercompany receivable EUR 50000, credit cash EUR 50000
  - All posted to T1 GL per REQ-JE-007 pattern

### REQ-IHB-003: Notional pool interest calculation SHALL be performed daily on aggregate balance and allocated per configured method (proportional | weighted-average | fixed)

For pools with `type: notional`, system MUST:
1. Calculate daily interest on aggregate balance = sum of all member closing balances × daily rate / 365
2. Allocate interest per `CashPool.interestAllocationMethod`:
   - **Proportional**: each member's share = (member balance / aggregate balance) × total interest
   - **Weighted-average**: each member's share per configured weight vector
   - **Fixed**: each member's share per configured EUR amount or percentage
3. Post monthly to GL: expense account (personeelslasten | financiële lasten depending on rate type), credited to intercompany interest-payable account
4. No cash movement; interest accrues as GL entries only

#### Scenario: Interest allocated proportionally, posted monthly

- **GIVEN** notional pool (daily rate 4.5%, members A: EUR 100K balance, B: EUR 50K balance)
- **WHEN** month-end close runs with calculated daily interest EUR 553.42
- **THEN** interest MUST allocate to A: EUR 368.95 (66.7%), B: EUR 184.47 (33.3%), **AND** GL entries MUST post:
  - Debit interest-expense account EUR 553.42
  - Credit intercompany-interest-payable EUR 553.42
  - No cash transfer

### REQ-IHB-004: Inter-company loans MUST support fixed and floating rates with reference-rate look-up (EURIBOR, SOFR, SARON), accrue interest daily, and post monthly to both lender and borrower ledgers

`IntercompanyLoan` register MUST declare:
- Lender administratieId (FK to Administration)
- Borrower administratieId
- Principal EUR amount
- Currency (T2: EUR; T5 adds multi-currency)
- Interest rate: Fixed percentage OR floating `{referenceRate: "EURIBOR-3M" | "SOFR" | "SARON", spread: +/-X.XX}`
- Repayment schedule (maturity date, optional amortization installments)
- Transfer-pricing documentation reference (FK to docudesk URI per REQ-IHB-004 scenario below)
- Status enum: draft | active | repaid | written-off
- Lifecycle: draft → active → repaid (or written-off)

System MUST:
1. Accrue interest daily = (principal × rate / 365) using month-start reference rate for floating loans
2. Post monthly to GL:
   - Lender: debit intercompany interest-receivable, credit interest-revenue
   - Borrower: debit interest-expense, credit intercompany interest-payable
3. Enforce OECD arm's-length principle: rate MUST be documented and <= market-comparable rate (warning if rate > EURIBOR + 3%)

#### Scenario: Floating-rate loan accrues daily, reference rate snapshot at month-start

- **GIVEN** IntercompanyLoan (principal EUR 250000, rate EURIBOR-3M + 2.0%, lender: adm-001, borrower: adm-002)
- **WHEN** month runs with EURIBOR-3M snapshot 4.50% on month-1st
- **THEN** daily interest accrual MUST be EUR 250000 × (4.50% + 2.0%) / 365 = EUR 177.05/day, **AND** month-end GL MUST post EUR 5 311.44 (31 days) to both ledgers

#### Scenario: Transfer pricing documentation stored in docudesk

- **GIVEN** IntercompanyLoan with transfer-pricing rate negotiated at 4.5%
- **WHEN** operator enters `transferPricingDocumentReference: "docudesk://<UUID>"` linking to signed OECD-compliant memo
- **THEN** audit trail MUST record document reference, and external auditor MUST be able to access without redaction

### REQ-IHB-005: Inter-company transactions MUST produce balanced double-entry postings in both administraties simultaneously and prevent posting if offsetting administratie is closed for the period

The system SHALL satisfy this requirement: Inter-company transactions MUST produce balanced double-entry postings in both administraties simultaneously and prevent posting if offsetting administratie is closed for the period.

`IntercompanyTransaction` register (sweep, loan drawdown, interest accrual, settlement movements):
- Pool or loan reference (FK)
- Movement type: sweep | loan-drawdown | interest-accrual | settlement | other
- Amount EUR
- From administratieId, to administratieId
- Posting date, value date
- Lifecycle: draft → posted → settled

System MUST:
1. Validate both administraties are open for the posting date (per REQ-PC-004 from period-close spec)
2. Materialize balanced double-sided GL entries **simultaneously** on both ledgers:
   - From ledger: debit/credit based on movement type
   - To ledger: offsetting debit/credit
   - Intercompany current-account entries on both sides
3. Prevent posting if either administratie is closed; raise user-facing error with remediation path (reopen period or post to future open period)

#### Scenario: Posting blocked if receiving administratie period is closed

- **GIVEN** IntercompanyTransaction (amount EUR 50000, from adm-001 to adm-002, posting date 2026-04-30)
- **WHEN** adm-002 has closed period April 2026 per REQ-PC-004
- **THEN** posting MUST be rejected with message "Cannot post to closed period in receiving administratie adm-002. Reopen period or post to May 2026."

### REQ-IHB-006: FX contracts MUST be recorded with full lifecycle (trade, confirmation, settlement), revalued at each period close using closing-rate FX, with unrealised gains/losses posted to OCI or P&L depending on hedge designation per IFRS 9

`FXContract` register MUST declare:
- Counterparty bank name + reference number
- Instrument type: spot | forward | swap | NDF
- Buy currency + amount (ISO 4217)
- Sell currency + amount
- Trade date, value date, settlement date
- Contract rate (buy currency per sell currency)
- Hedge designation: cashflow | fair-value | net-investment (enum per IFRS 9 §6.4)
- Settlement instructions (nostro account, correspondent, etc.)
- Status: drafted | confirmed | settled | closed | cancelled
- Lifecycle: drafted → confirmed → settled → closed

System MUST:
1. At period close, revalue at spot rate (current rate for buy/sell pair)
2. Calculate unrealised gain/loss = (contract rate − spot rate) × buy amount
3. Post per IFRS 9 hedge designation:
   - **Cashflow hedge**: effective portion → OCI; ineffective portion → P&L
   - **Fair value hedge**: entire gain/loss → P&L
   - **Net investment hedge**: gain/loss → OCI
4. Maintain cumulative hedge-effectiveness ratio = (cumulative effective portion / total hedge value); warn if <80% (hedge ineffective)

#### Scenario: Forward contract revalued at period close, effective portion to OCI

- **GIVEN** EUR-entity with USD payable 100K due 60 days, hedged with forward (buy USD at 1.08)
- **WHEN** period closes at month 1 with spot rate USD/EUR 1.05
- **THEN** unrealised loss EUR (100000 × (1.08 − 1.05)) = EUR 3000 MUST post:
  - Debit FX loss account EUR 3000
  - Credit OCI (Other Comprehensive Income) EUR 3000
  - **AND** disclosure MUST show hedge-effectiveness ratio = 100% (forward fully hedged the payable)

### REQ-IHB-007: Daily group cash position MUST be available before 09:00 local time, aggregating balances from all bank connectors across all participating administraties in their reporting currency

System MUST generate nightly job (23:00 – 08:00) that:
1. Fetches latest balance for each CashPoolMembership account from bank connectors (camt.053)
2. Converts each balance to reporting currency (EUR) using end-of-day FX rate
3. Aggregates: total = Σ(member balance in EUR), net of pending sweeps (subtract draft IntercompanyTransaction movements)
4. Stores result in a CashPosition snapshot (timestamp, aggregated balance, per-member breakdown)
5. Makes available on CFO dashboard before 09:00 local time

System MUST flag if:
- Latest balance older than 24 hours (warn: bank feed stale)
- Aggregation job failed to complete (error: contact ops)

#### Scenario: CFO views consolidated cash position before 09:00

- **GIVEN** 3 participating administraties with balances EUR 150K, USD 50K (= EUR 52K @ 1.04 FX), GBP 20K (= EUR 22.5K @ 1.125 FX), plus pending sweep EUR 80K
- **WHEN** CFO opens dashboard at 08:45
- **THEN** CFO MUST see "Group Cash Position: EUR 144.5K (net of pending sweeps)" with per-member breakdown and timestamp showing 08:00 feed completion

### REQ-IHB-008: 13-week rolling cashflow forecast MUST be regenerated nightly using actual AR/AP ageing, scheduled debt service, payroll calendar, and seasonality model, with explainable variance vs prior forecast

System MUST generate nightly forecast-regeneration job (01:00 – 05:00) that:
1. Fetches open AR invoices + expected collection dates from AP module
2. Fetches open AP invoices + due dates from AP module
3. Applies payroll calendar (weekly/monthly runs) for expected outflows
4. Applies scheduled debt service (loan maturity dates, interest payments)
5. Generates 13 weekly buckets: Week 1 (today + 0–7 days), Week 2 (+7–14 days), ..., Week 13 (+84–91 days)
6. For each bucket, calculates:
   - Opening cash (prior week closing)
   - Inflows: AR collections (by due date) + loan drawdowns
   - Outflows: AP payments (by due date) + payroll + debt service + taxes
   - Closing cash = opening + inflows − outflows
7. Generates three scenarios:
   - **Base**: high probability (default assumptions)
   - **Downside**: 10% variance on AR collections (assume 90% collection rate) + 10% variance on AP/payroll (assume 110% payables)
   - **Stress**: 30% variance on AR + 30% variance on AP
8. Calculates variance vs prior week's forecast (same week); flags if > EUR 50K variance
9. Stores in `CashForecast` records (one per week per scenario)

System MUST surface forecast and variance on CFO dashboard.

#### Scenario: Forecast regenerated nightly, alerts on variance

- **GIVEN** prior week's forecast showed Week 7 closing cash EUR 450K
- **WHEN** nightly job regenerates with new AR/AP ageing + actual collections/payments to date
- **THEN** new Week 7 forecast shows EUR 380K, **AND** system MUST raise alert "Week 7 cash forecast variance: EUR 70K below prior estimate. Review AR collections and AP timing."

### REQ-IHB-009: Bank reconciliation MUST work across all pool participants in one workflow, allowing operator to match single bank line to journal entries from multiple administraties (typical for shared-service-centre payments)

The system SHALL satisfy this requirement: Bank reconciliation MUST work across all pool participants in one workflow, allowing operator to match single bank line to journal entries from multiple administraties (typical for shared-service-centre payments).

`BankReconciliationGroup` register (extends existing bank-reconciliation module):
- Multiple participating administraties (array of FK to Administration)
- Reconciliation run reference (FK to BankReconciliation master record)
- Exception queue (matching candidates with score < 95%)
- Auto-match applied (score >= 95%)
- Manual match by operator

System MUST:
1. When operator loads reconciliation for a CashPool, present all bank lines from all member accounts in one view
2. Auto-match candidates: for each bank line, search GL entries across all member administraties for (amount, date, counterparty) match
3. Suggest matches with score >= 95%
4. Present lower-confidence matches for operator review
5. Allow operator to manually match without context-switching between administraties

#### Scenario: Shared-service-centre payment matched across multiple entities

- **GIVEN** bank line: outgoing CHF 100K from master account, payment description "Supplier invoice distribution"
- **WHEN** operator reviews reconciliation and searches for matching GL entries across 3 member administraties
- **THEN** system MUST find and auto-match (score 99%): adm-001 expense EUR 50K (50% of 100K @ CHF/EUR), adm-002 expense EUR 35K (35%), adm-003 expense EUR 15K (15%), all dated same value date

### REQ-IHB-010: System MUST produce IFRS 7 disclosure pack covering credit risk concentration, liquidity maturity profile, market risk sensitivity (FX, interest rate), hedging effectiveness, exportable to PDF and XBRL

At period close, system MUST generate `IFRS-7-disclosure-pack.pdf` (or XBRL) containing:

**1. Credit Risk Concentration** (IFRS 7 §34–36):
- Counterparty risk by FX contract counterparty (top 5 banks, total exposure EUR X per bank)
- Loan counterparty risk (lender/borrower receivable/payable EUR X)

**2. Liquidity Maturity Profile** (IFRS 7 §39):
- Undiscounted cash flows for next 1 year: sweep repayments, loan maturities, interest payments (by month)
- Weighted average maturity in years

**3. Market Risk Sensitivity** (IFRS 7 §40–42):
- FX sensitivity: "If EUR/USD rises 5%, unrealised P&L impact EUR X" (per major currency pair)
- Interest rate sensitivity: "If rates rise 100bps, floating-rate loan cost impact EUR X"
- Simulation results: base case + ±5% FX + ±100bps rates

**4. Hedging Effectiveness** (IFRS 9 §6.4–6.5):
- For each FX contract designated as hedge:
  - Cumulative hedge-effectiveness ratio
  - Cumulative amount recycled from OCI to P&L (if any)
  - Ineffective portion (if any) posted to P&L

**5. Intercompany Loan Summary** (IFRS 7 Appendix A):
- Lender/borrower, principal, rate (fixed/floating), remaining term, amortised cost, transfer-pricing documentation reference

Disclosure pack MUST be:
- Suitable for external auditor review without redaction
- Exportable as PDF for regulatory filing
- Exportable as XBRL (per applicable reporting standard, e.g., ESEF for listed companies)

#### Scenario: IFRS 7 disclosure pack generated at period close

- **GIVEN** closed month with 3 FX contracts (2 hedges @ 100% effective, 1 fair value @ 99% effective) and 2 intercompany loans
- **WHEN** period-close process runs disclosure-pack generation
- **THEN** PDF MUST include:
  - Credit risk: Top 3 counterparties (ING EUR 500K, Rabobank EUR 300K, ABN AMRO EUR 150K)
  - Liquidity: Year-1 cash flows EUR 2M (monthly breakdown)
  - Sensitivity: EUR/USD +5% impact EUR 15K loss, rates +100bps impact EUR 80K loss
  - Hedging: Cumulative effectiveness 99.7%, recycled to P&L EUR 2.5K
  - Loans: Loan-A (adm-001 to adm-002, EUR 250K @ 6.5% fixed, maturity 2026-12-31, TP doc docudesk://<UUID>)

### REQ-IHB-011: Manifest navigation SHALL include five entries for treasury workflow

The `src/manifest.json` navigation MUST declare five entries:

1. **Cash Pools** (`type: index`) — list all `CashPool` records with add/search/detail; detail shows members, interest allocation, status
2. **Intercompany Loans** (`type: index`) — list all `IntercompanyLoan` records with add/search/detail; detail shows lender/borrower, rate, schedule, TP doc link
3. **FX Hedges** (`type: index`) — list all `FXContract` records with add/search/detail; detail shows instrument, maturity, hedge designation, effectiveness
4. **Cashflow Forecast** (`type: aggregate`) — display rolling 13-week forecast (3 scenarios: base/downside/stress) with drill-down by week and scenario
5. **Group Liquidity Dashboard** (`type: aggregate`) — consolidated cash position (EUR total, per-member breakdown), FX exposure heatmap (by currency, by entity), liquidity runway (weeks of cash at burn rate)

Each entry MUST have corresponding detail pages (list pages auto-generated by `CnIndexPage`; detail pages per manifest pattern).

#### Scenario: Treasury entries accessible from main navigation

- **GIVEN** app installed with this spec
- **WHEN** user opens app
- **THEN** left sidebar MUST show "Cash Pools", "Intercompany Loans", "FX Hedges", "Cashflow Forecast", "Group Liquidity Dashboard" as clickable entries

### REQ-IHB-012: Seed data SHALL include 1 notional pool, 1 zero-balance pool, 2 intercompany loans, 3 FX contracts, 13-week forecast (generated from seed AR/AP), with realistic Dutch/European values

Seed objects MUST include:

**Cash Pools (2):**
- "Groepmaatschappij EUR Netto Pool" (notional, 3 members: adm-001, adm-002, adm-003)
  - Master account NL91ABNA0417164300 (ING)
  - Daily rate 4.5% p.a.
  - Interest allocation: proportional
  - Min cash policy EUR 500K
- "Filiaalbedrijven EUR Zero Pool" (zero-balance, 2 members: adm-002, adm-003)
  - Master account NL34DEUTDE87654321 (Deutsche Bank)
  - Sweep frequency: daily at 23:30
  - Target balances: adm-002 EUR 50K, adm-003 EUR 25K

**Intercompany Loans (2):**
- "Interim Financing 2026 (adm-001 → adm-002)"
  - Principal EUR 250000
  - Rate 3.5% fixed
  - Maturity 2026-12-31
  - TP doc: "docudesk://<UUID>" (placeholder)
- "Working Capital Line (adm-001 → adm-003)"
  - Principal EUR 150000
  - Rate EURIBOR-3M + 2.0%
  - Maturity 2027-06-30

**FX Contracts (3):**
- Forward (EUR → USD, buy USD 100K @ 1.08, settlement 2026-06-30, cashflow hedge)
- Spot (EUR → GBP, buy GBP 50K @ 0.86, settlement today, fair-value)
- Swap (EUR ↔ CHF, notional EUR 200K ↔ CHF 220K, hedge designation: net-investment)

**Seed Data Format:**
- All seed objects MUST use `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`
- Seed data idempotent on reimport via `ConfigurationService::importFromApp(force: false)`
- Dutch street names, valid Dutch bank accounts (IBAN format), realistic EUR amounts

#### Scenario: Seed data loads on app install

- **GIVEN** fresh Nextcloud instance with shillinq + bookkeeping-treasury-ihb installed
- **WHEN** repair step runs `ConfigurationService::importFromApp('shillinq', ...)`
- **THEN** 2 pools, 2 loans, 3 FX contracts MUST be created with realistic Dutch values; re-running MUST NOT create duplicates

## Verification

`openspec validate` must exit clean on the change folder. CFO/group treasurer persona
peer-review confirms pool definitions, interest calculations, loan accrual,
FX hedge accounting, and cashflow forecast regeneration match group treasury
best practices + IFRS 9 / OECD transfer-pricing standards. Architecture reviewer
confirms ADR-022 + ADR-031 compliance (no app-local sweep service; all
calculation declarative; manifest carries navigation). No source code changes
outside `openspec/changes/bookkeeping-treasury-ihb/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. Implementation cycle
(separate `opsx-apply`) responsible for:

- **Unit tests (PHPUnit)**: interest allocation formulas (proportional, weighted-
  average, fixed), FX revaluation (unrealised P&L, OCI vs P&L split per hedge
  designation), floating-rate accrual (EURIBOR snapshot, spread application)
- **Integration tests**: sweep materialization (GL entries balanced, zero-balance
  verification), multi-administratie reconciliation (match candidates across
  entities), forecast aggregation (AR/AP ageing + scheduled debt feeds forecast)
- **E2E tests**: full pool creation → member setup → sweep execution → GL posting
  → monthly interest posting pipeline; notional vs physical pools both tested
- **Persona tests (per ADR-009)**: Group treasurer creates pool and triggers
  sweep; CFO views daily cash position + 13-week forecast; tax advisor reviews
  TP documentation

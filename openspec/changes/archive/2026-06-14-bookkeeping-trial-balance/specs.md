# Specifications — Trial Balance (Tier 2)

> **Implementation note (hydra apply).** The monolith already ships a period
> *snapshot* `TrialBalance` schema (totals + `isBalanced`, from
> `add-shillinq-bookkeeping-operations`, REQ-FS-005). To satisfy this Tier-2
> spec without rebuilding it (ADR-022), the per-account opening/movement/closing
> rows below are realised as a new read-only schema named **`TrialBalanceLine`**
> (not a second `TrialBalance`), declared in an ADR-037 `register.d` fragment
> rather than by editing the monolith. The live GL field is `periodId` (not
> `period_id`); the implementation uses the real field names throughout.

## Requirement: Trial Balance Aggregation Schema Declaration

**REQ-TB-001**  
Declare the `TrialBalance` read-only aggregation schema in `lib/Settings/shillinq_register.json` with fields: period_id, accountNumber, accountName, accountType, openingBalance, debitMovement, creditMovement, closingBalance, currency, parentAccountNumber.

### Acceptance Criteria

**GIVEN** shillinq has a GL with `GLTransaction` + `GLLine` tables seeded with T1 data  
**WHEN** the TrialBalance schema is declared with aggregation rules `GROUP BY (period_id, accountNumber)` summing debits and credits  
**THEN** the schema is readable in `lib/Settings/shillinq_register.json` with all fields marked required/optional as per design.md and `x-openregister-aggregations` block specifying the join to GLLine and aggregation functions

---

## Requirement: Opening Balance Calculation from Prior Period

**REQ-TB-002**  
The opening balance for period N is derived from the closing balance of period N-1. If no prior period exists (first period), opening balance defaults to zero.

### Acceptance Criteria

**GIVEN** a TrialBalance query for period 2026-Q1 on account 1000 (Assets)  
**WHEN** the aggregation computes opening balance by looking up TrialBalance(2025-Q4) for the same account  
**THEN** opening balance equals prior period's closingBalance; if 2025-Q4 does not exist, opening = 0

---

## Requirement: Closing Balance Computed from Opening + Period Net Activity

**REQ-TB-003**  
Closing balance = opening balance + (debit movements - credit movements) for the period.

### Acceptance Criteria

**GIVEN** account 1000 with opening=50000, debitMovement=10000, creditMovement=5000  
**WHEN** the aggregation computes closing balance per REQ-TB-003 formula  
**THEN** closingBalance = 50000 + (10000 - 5000) = 55000

---

## Requirement: Period-Scoped Trial Balance Query

**REQ-TB-004**  
The trial balance aggregation accepts a single `period_id` parameter (fiscal period identifier). Querying returns all accounts (leaf and parent) for that period.

### Acceptance Criteria

**GIVEN** a query for trial balance with `?period_id=2026-Q1`  
**WHEN** the TrialBalanceService filters GL activity by period_id = '2026-Q1'  
**THEN** the response includes every account in the chart with its opening, debit, credit, closing for that single period only

---

## Requirement: Hierarchy Roll-Up — Parent Accounts Include Child Balances

**REQ-TB-005**  
Parent accounts (those with children in the Account hierarchy) automatically roll up closing balances from descendants. The aggregation traverses Account.parentAccountNumber relationships.

### Acceptance Criteria

**GIVEN** account 1000 (Assets) has children 1100 (Current Assets), 1200 (Fixed Assets), and 1100 has child 1110 (Cash)  
**WHEN** the trial balance aggregates closingBalance for account 1000  
**THEN** closing balance = sum of 1100 + 1200 = (sum of 1110 + 1120 + ...) + (sum of 1210 + 1220 + ...), i.e., all descendants roll up

---

## Requirement: Account Type Inherited in Trial Balance

**REQ-TB-006**  
Every trial balance record carries `accountType` (assets, liabilities, equity, revenue, expenses) inherited from the Account record. This enables filtering and validation.

### Acceptance Criteria

**GIVEN** account 1000 has accountType='assets' in the Account schema  
**WHEN** the trial balance query includes account 1000  
**THEN** the returned TrialBalance record carries accountType='assets'

---

## Requirement: Read-Only Aggregation — No Create/Update/Delete

**REQ-TB-007**  
The TrialBalance schema is marked read-only (`readonly: true` in `x-openregister-aggregations`). POST/PUT/DELETE operations on TrialBalance records MUST be rejected with 405 Method Not Allowed.

### Acceptance Criteria

**GIVEN** a client attempts to `POST /api/trial-balance` with a new record  
**WHEN** the request reaches the API handler  
**THEN** the server responds with HTTP 405 and message "Trial balance is read-only; edit GL entries instead"

---

## Requirement: Backend Trial Balance Computation Service

**REQ-TB-008**  
Implement `TrialBalanceService::compute(string $administrationId, string $periodId, array $filters = []): array` returning trial balance data. If OpenRegister aggregation is unavailable, compute in PHP by fetching GL + Account data and summing.

### Acceptance Criteria

**GIVEN** `TrialBalanceService::compute('admin-2026', '2026-Q1')`  
**WHEN** the service queries GL transactions with period_id='2026-Q1' and aggregates by accountNumber  
**THEN** result is array of [ { period_id, accountNumber, accountName, openingBalance, debit, credit, closing, ... }, ... ] sorted by accountNumber and correctly summed

---

## Requirement: Trial Balance API Endpoint

**REQ-TB-009**  
Implement `GET /index.php/apps/shillinq/api/trial-balance?period_id=<period>&administration_id=<admin>` endpoint in TrialBalanceController returning JSON trial balance.

### Acceptance Criteria

**GIVEN** a GET request to `/api/trial-balance?period_id=2026-Q1&administration_id=admin-2026`  
**WHEN** the controller calls `TrialBalanceService::compute()` and serializes the result  
**THEN** response is HTTP 200 with JSON array of trial balance records, total count, and totals object { totalDebit, totalCredit, totalAssets, ... }

---

## Requirement: Manifest Navigation Entry

**REQ-TB-010**  
Add a navigation menu entry in `src/manifest.json` under Bookkeeping > Trial Balance, binding to a `type: detail` page rendering the trial balance report.

### Acceptance Criteria

**GIVEN** `src/manifest.json` with Bookkeeping section  
**WHEN** a new page entry is added with id='trial-balance', type='detail', binding to TrialBalance register, menu.route='trial-balance'  
**THEN** the left navigation renders Trial Balance under Bookkeeping, and clicking navigates to the detail page; `npm run validate-manifest` exits 0

---

## Requirement: Trial Balance Detail Page Component

**REQ-TB-011**  
Implement `src/views/TrialBalanceDetail.vue` or equivalent rendering the trial balance via `CnDetailCard` + `CnDataTable` from @conduction/nextcloud-vue. Include period selector, KPI cards (Total Assets, Liabilities, Equity), and sortable table.

### Acceptance Criteria

**GIVEN** the trial-balance detail page is rendered  
**WHEN** a user opens the page and selects a period from the dropdown  
**THEN** the page fetches `/api/trial-balance?period_id=<selected>` and displays:
- 3 KPI cards showing totals per account type
- Table with columns: Account #, Account Name, Opening, Debits, Credits, Closing (sortable, paginated)
- Summary message: "Trial balance is balanced" if debits = credits, or warning if unbalanced

---

## Requirement: Currency Support in Trial Balance

**REQ-TB-012**  
The trial balance aggregation respects the account's currency field. For EUR accounts (default), amounts are in EUR. Multi-currency trial balance (cross-currency aggregation) is deferred to T4.

### Acceptance Criteria

**GIVEN** account 1000 has currency='EUR', account 1500 has currency='USD'  
**WHEN** trial balance is queried for the period  
**THEN** each record's `currency` field reflects the account's currency; totals are not cross-currency summed

---

## Requirement: Seed Data — 5 Example Trial Balances

**REQ-TB-013**  
Provide 5 realistic trial balance snapshots (design.md) for SMB, ZZP, Government entity, mid-year close, and post-reconciliation scenarios. Seed data is loaded via repair step idempotently.

### Acceptance Criteria

**GIVEN** the repair step processes seed data for TrialBalance  
**WHEN** `lib/Settings/seeds/trial-balance-examples.json` is imported via `ConfigurationService::importFromApp()`  
**THEN** 5 trial balance records are created (or skipped if already exist via slug matching), representing realistic scenarios with correct account hierarchies and balanced totals

---

## Requirement: Performance — Trial Balance Query < 2 Seconds

**REQ-TB-014**  
For typical administrations (10K accounts, 100K GL lines per period), the trial balance query completes in < 2 seconds.

### Acceptance Criteria

**GIVEN** an administration with 10K accounts and 100K GL postings in the period  
**WHEN** `TrialBalanceService::compute()` is called  
**THEN** query execution time is < 2 seconds (measured on dev hardware); if OpenRegister aggregation is used, times come from OR's aggregation engine; if PHP fallback, times from ObjectService queries + in-memory aggregation

---

## Requirement: Error Handling — Invalid Period

**REQ-TB-015**  
If `period_id` parameter is missing or invalid, the API returns HTTP 400 Bad Request with message "period_id is required and must be a valid period identifier".

### Acceptance Criteria

**GIVEN** a GET request to `/api/trial-balance` (missing period_id) or `?period_id=invalid-period`  
**WHEN** the controller validates the parameter  
**THEN** response is HTTP 400 with descriptive error message

---

## Requirement: Authorization — User Must Have Read Access to Bookkeeping

**REQ-TB-016**  
Reading trial balance requires the user to have the 'read:bookkeeping' or equivalent permission on the administration. If user lacks permission, API returns HTTP 403 Forbidden.

### Acceptance Criteria

**GIVEN** a user with no 'read:bookkeeping' permission for admin-2026  
**WHEN** they query `/api/trial-balance?period_id=2026-Q1&administration_id=admin-2026`  
**THEN** response is HTTP 403 Forbidden with message "Insufficient permissions"

---

## Non-Functional Requirement: Multi-Tenancy Support

**REQ-TB-017**  
The trial balance service respects multi-tenancy: queries are automatically scoped to the user's organization context via `administration_id` parameter and OpenRegister's built-in tenant isolation.

### Acceptance Criteria

**GIVEN** two administrations: admin-org-A and admin-org-B  
**WHEN** user-A queries trial balance for admin-org-A and user-B queries for admin-org-B  
**THEN** each receives only their respective trial balance data; no cross-organization data leakage

---

## Requirement: Fallback to PHP Computation if Aggregation Engine Unavailable

**REQ-TB-018**  
If OpenRegister's aggregation engine is unavailable or does not support cross-table joins, `TrialBalanceService::compute()` falls back to fetching raw GL + Account data and computing sums in PHP.

### Acceptance Criteria

**GIVEN** OR's `x-openregister-aggregations` is unavailable  
**WHEN** TrialBalanceService is invoked  
**THEN** service queries `GLLine` records with `period_id` filter, fetches `Account` hierarchy, computes sums for each (period_id, accountNumber) group in-memory, returns aggregated result within 2 seconds for typical load

---

## Requirement: No Custom GL Posting Rules in Trial Balance

**REQ-TB-019**  
The trial balance aggregation sums **existing** GL postings without triggering automated adjustments (depreciation, accruals, rounding). Trial balance is **read-only on the GL**.

### Acceptance Criteria

**GIVEN** a trial balance query is executed  
**WHEN** the query fetches GL data and aggregates  
**THEN** no new GL entries are created, no automatic adjustments are posted; trial balance reflects GL state as-is

---

## Requirement: Account Parent Relationship Inherited in Query

**REQ-TB-020**  
Each trial balance record includes `parentAccountNumber` from the Account record, enabling client-side hierarchy rendering (parent/child expansion in UI).

### Acceptance Criteria

**GIVEN** account 1000 (parent) has child 1100  
**WHEN** trial balance is queried  
**THEN** the returned record for 1100 includes `parentAccountNumber: '1000'`; client UI can use this to render hierarchy tree

---

## Requirement: Documentation — Trial Balance User Guide

**REQ-TB-021**  
User documentation (docs/user-guide/bookkeeping/trial-balance.md) explains trial balance purpose, how to read the report, and how to interpret opening/closing/movement columns. Includes screenshot of the UI.

### Acceptance Criteria

**GIVEN** documentation is authored  
**WHEN** a user reads the guide  
**THEN** they understand: what trial balance is, what each column means, why debits must equal credits, and how to use it to validate period close

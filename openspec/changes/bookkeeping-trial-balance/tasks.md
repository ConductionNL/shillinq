# Tasks — Trial Balance (Tier 2)

## 0. Deduplication Check

- [ ] Task 0.1: Confirm no trial balance schema or capability already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/`, and related bookkeeping specs; catalogue existing reporting patterns in T1/T3; confirm TrialBalance is new

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-trial-balance/specs.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (reporting)` / `Depends on: bookkeeping-foundation` header, `REQ-TB-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (exactly 4 hashtags per scenario header)
- [x] Task 1.2: Author `proposal.md` + `design.md` for the change envelope; `proposal.md` references the shared `nextcloud-app` spec, includes Affected Projects / Scope / Risks / Rollback / Open Questions; `design.md` includes Reuse Analysis table, Declarative-vs-imperative decision table, and Seed Data section
- [x] Task 1.3: Author `tasks.md` (this file) cataloguing all downstream implementation tasks

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [ ] Task 2.1: Declare the `TrialBalance` read-only aggregation schema — fields per REQ-TB-001 (period_id, accountNumber, accountName, accountType, openingBalance, debitMovement, creditMovement, closingBalance, currency, parentAccountNumber); mark read-only with `"readonly": true`; add `x-openregister-aggregations` block with groupBy `[period_id, accountNumber]` and aggregates for debit/credit sums; join to GLLine and Account tables

## 3. Backend service layer — Trial Balance computation

- [ ] Task 3.1: Author `lib/Service/TrialBalanceService.php` with method `compute(string $administrationId, string $periodId, array $filters = []): array` per REQ-TB-008; fetches GL + Account data via ObjectService; groups by (period_id, accountNumber); sums debits and credits; computes closing balance = opening + (debit - credit); returns sorted array; falls back to PHP if OR aggregation unavailable
- [ ] Task 3.2: Author `lib/Service/TrialBalanceCalculator.php` (helper) — private logic for computing opening balance from prior period (REQ-TB-002), rolling up parent-account balances via Account.parentAccountNumber hierarchy traversal, and validating balance (debits = credits)
- [ ] Task 3.3: Add unit tests for TrialBalanceService in `tests/Unit/Service/TrialBalanceServiceTest.php` — test cases: compute() with mock GL data, verify opening-balance precedence, verify sum correctness, verify parent roll-up, test first-period (opening=0), test missing-period error handling

## 4. Backend API controller

- [ ] Task 4.1: Author `lib/Controller/TrialBalanceController.php` — `GET /api/trial-balance?period_id=<period>&administration_id=<admin>` endpoint per REQ-TB-009; calls TrialBalanceService::compute(); validates period_id parameter (REQ-TB-015); checks authorization (REQ-TB-016); returns HTTP 200 with JSON { data: [...], total: N, totals: { debit, credit, assets, liabilities, equity } }; returns HTTP 400 / 403 on validation/auth failure
- [ ] Task 4.2: Add integration tests for TrialBalanceController in `tests/Integration/Controller/TrialBalanceControllerTest.php` — test happy path (valid period, auth), invalid period, missing auth, verify response structure and HTTP status codes

## 5. Manifest navigation — `src/manifest.json`

- [ ] Task 5.1: Add Trial Balance navigation entry in `src/manifest.json` under Bookkeeping menu — entry: { route: 'trial-balance', name: 'Trial Balance' }; add page binding { id: 'trial-balance', type: 'detail', register: 'TrialBalance' } per REQ-TB-010; verify `npm run validate-manifest` exits 0

## 6. Frontend — Trial Balance detail view

- [ ] Task 6.1: Author `src/views/TrialBalanceDetail.vue` or `src/pages/TrialBalanceDetail.vue` (per app structure) — rendering trial balance report per REQ-TB-011:
  - Period selector (NcSelect dropdown, defaults to current period)
  - 3 KPI cards: Total Assets, Total Liabilities, Total Equity (using `CnStatsBlock`)
  - Balance status message: "Trial balance is balanced (debits = credits)" or warning
  - CnDataTable with columns: Account #, Account Name, Account Type, Opening Balance, Debits, Credits, Closing Balance (sortable, paginated)
  - Fetch data from `GET /api/trial-balance?period_id=<selected>` on period change
  - Handle loading/error states with `NcLoadingIcon` and `NcEmptyContent`

- [ ] Task 6.2: Author `src/store/modules/trialBalanceStore.js` (Pinia store) — manages trial balance state: period selection, fetched data, loading/error flags; action `fetchTrialBalance(administrationId, periodId)` calling the API; getter `trialBalance()` and `isBalanced()`

## 7. Frontend — Translation strings

- [ ] Task 7.1: Add translation strings in `l10n/en.json` and `l10n/nl.json`:
  - 'Trial Balance' (Proefbalans)
  - 'Period' (Periode)
  - 'Account Number' (Rekeningnummer)
  - 'Account Name' (Rekeningnaam)
  - 'Account Type' (Rekeningtype)
  - 'Opening Balance' (Openingsbalans)
  - 'Debits' (Debetbedrag)
  - 'Credits' (Creditbedrag)
  - 'Closing Balance' (Sluitingsbalans)
  - 'Total Assets' (Totaal Activa)
  - 'Total Liabilities' (Totaal Passiva)
  - 'Total Equity' (Totaal Eigen Vermogen)
  - 'Trial balance is balanced' / 'Proefbalans is in balans'
  - 'Trial balance is not balanced' / 'Proefbalans is niet in balans' (warning)
  - Per ADR-005 i18n standards

## 8. Seed data — Example trial balances

- [ ] Task 8.1: Create `lib/Settings/seeds/trial-balance-examples.json` with 5 realistic trial balance snapshots per design.md, design.md Seed Data section; JSON array of objects with @self envelope; each record includes period_id, accountNumber, accountName, accountType, openingBalance, debitMovement, creditMovement, closingBalance, currency, parentAccountNumber; import via ConfigurationService::importFromApp() in repair step

## 9. Repair step — Seed data import

- [ ] Task 9.1: Extend repair step under `lib/Migration/` to import trial-balance-examples.json idempotently via `ConfigurationService::importFromApp()`; ensure re-running repair does not duplicate seed records (match by slug); log success/skip per record

## 10. Build and linting

- [ ] Task 10.1: Ensure frontend builds without errors: `npm run build` completes cleanly; ESLint (`npm run lint`) reports no new issues on modified files
- [ ] Task 10.2: Ensure backend code meets standards: `composer check:strict` passes (static analysis); PHPStan level 9

## 11. API response format

- [ ] Task 11.1: Verify TrialBalanceController returns JSON response matching contract:
  ```json
  {
    "data": [
      {
        "period_id": "2026-Q1",
        "accountNumber": "1000",
        "accountName": "Assets",
        "accountType": "assets",
        "openingBalance": 50000,
        "debitMovement": 10000,
        "creditMovement": 5000,
        "closingBalance": 55000,
        "currency": "EUR",
        "parentAccountNumber": null
      }
    ],
    "total": 120,
    "totals": {
      "totalDebit": 500000,
      "totalCredit": 500000,
      "totalAssets": 250000,
      "totalLiabilities": 100000,
      "totalEquity": 150000
    }
  }
  ```

## 12. Documentation

- [ ] Task 12.1: Author `docs/user-guide/bookkeeping/trial-balance.md` per REQ-TB-021:
  - What is a trial balance? (explain purpose)
  - How to read the report (column meanings)
  - Opening vs. closing balance logic
  - Why debits must equal credits
  - How to use trial balance for period close validation
  - Screenshot of the UI (capture from running app)
  - Troubleshooting: common issues (unbalanced GL, missing periods)

- [ ] Task 12.2: Add architecture documentation to `docs/architecture/trial-balance-design.md`:
  - Schema declaration pattern (aggregation vs. PHP fallback)
  - OpenRegister aggregation syntax (if used)
  - Fallback logic (when aggregation unavailable)
  - Performance characteristics (expected query times)
  - Data flow diagram (GL → TrialBalance → UI)

## 13. Testing — Comprehensive

- [ ] Task 13.1: Author browser tests (Playwright / MCP) in `tests/Acceptance/TrialBalanceTest.php` or equivalent:
  - Load trial balance page
  - Select different periods from dropdown
  - Verify table renders with correct data
  - Verify KPI cards show correct totals
  - Verify "balanced" message appears when GL is balanced
  - Test error handling (invalid period, missing auth)
  - Smoke test: navigate to Trial Balance from menu

- [ ] Task 13.2: Performance test — `tests/Performance/TrialBalancePerformanceTest.php` — query trial balance with 10K+ accounts, measure execution time, assert < 2 seconds per REQ-TB-014

- [ ] Task 13.3: Multi-tenancy test — verify trial balance isolation: user-A queries admin-org-A, user-B queries admin-org-B, no cross-org data leakage (REQ-TB-017)

## 14. Authorization and RBAC

- [ ] Task 14.1: Ensure TrialBalanceController respects authorization per REQ-TB-016 — check user has 'read:bookkeeping' permission on administration_id before returning data; return HTTP 403 if unauthorized; leverage OpenRegister's AuthorizationService

## 15. Error handling

- [ ] Task 15.1: Implement error responses per REQ-TB-015, REQ-TB-016:
  - Missing period_id → HTTP 400 "period_id is required"
  - Invalid period_id → HTTP 400 "period_id must be a valid period identifier"
  - Unauthorized access → HTTP 403 "Insufficient permissions"
  - GL fetch failure → HTTP 500 "Failed to fetch GL data"
  - Each error includes descriptive message and HTTP status

## 16. Read-only enforcement

- [ ] Task 16.1: Ensure trial balance is truly read-only per REQ-TB-007 — register is declared `readonly: true`; POST/PUT/DELETE on TrialBalance return HTTP 405 Method Not Allowed with message "Trial balance is read-only; edit GL entries instead"; verify via integration test

## 17. ADR compliance

- [ ] Task 17.1: Verify ADR-031 compliance (declarative aggregation, no imperative service) — aggregation declared in schema metadata; no state machine logic in PHP; design.md documents any PHP fallback justified
- [ ] Task 17.2: Verify ADR-022 compliance (reuse OpenRegister abstractions) — no custom audit logging, no custom RBAC, no custom data-layer wiring
- [ ] Task 17.3: Verify ADR-004 + ADR-005 frontend compliance — all UI strings translated, CSS uses only Nextcloud variables, no hardcoded colors, WCAG AA navigation

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a Dutch bookkeeper persona confirms trial balance structure and opening-balance logic are correct
- [ ] Architecture reviewer confirms ADR-031 (aggregation), ADR-022 (reuse), and ADR-024 (manifest) compliance
- [ ] No source code changes outside `openspec/changes/bookkeeping-trial-balance/` and implementation-phase app changes
- [ ] All tests pass: `composer test` and `npm run test`

## Tests (company-wide ADR-008)

- [ ] Unit tests for TrialBalanceService + TrialBalanceCalculator (Task 3.3)
- [ ] Integration tests for TrialBalanceController (Task 4.2)
- [ ] Acceptance/browser tests for UI (Task 13.1)
- [ ] Performance test (Task 13.2)
- [ ] All tests pass (`composer test && npm run test`)

## Documentation (company-wide ADR-009)

- [ ] User guide (Task 12.1)
- [ ] Architecture guide (Task 12.2)
- [ ] Screenshots captured and committed to `docs/images/` (trial-balance-index, trial-balance-detail)

## i18n (company-wide ADR-007)

- [ ] Dutch + English translation strings (Task 7.1)
- [ ] All UI text marked for translation via `t()` function in Vue, `translate('key')` in PHP

## Deployment

- [ ] Repair step runs on upgrade; seed data loaded idempotently (Task 9.1)
- [ ] No breaking schema changes; TrialBalance is read-only aggregation (non-destructive)
- [ ] Rollback: revert commit, delete change folder, no GL data affected

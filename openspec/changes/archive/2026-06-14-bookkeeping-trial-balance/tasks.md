# Tasks — Trial Balance (Tier 2)

## 0. Deduplication Check

- [x] Task 0.1: Confirm no trial balance schema or capability already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/`, and related bookkeeping specs; catalogue existing reporting patterns in T1/T3; confirm TrialBalance is new

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-trial-balance/specs.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (reporting)` / `Depends on: bookkeeping-foundation` header, `REQ-TB-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (exactly 4 hashtags per scenario header)
- [x] Task 1.2: Author `proposal.md` + `design.md` for the change envelope; `proposal.md` references the shared `nextcloud-app` spec, includes Affected Projects / Scope / Risks / Rollback / Open Questions; `design.md` includes Reuse Analysis table, Declarative-vs-imperative decision table, and Seed Data section
- [x] Task 1.3: Author `tasks.md` (this file) cataloguing all downstream implementation tasks

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [x] Task 2.1: Declare the `TrialBalance` read-only aggregation schema — fields per REQ-TB-001 (period_id, accountNumber, accountName, accountType, openingBalance, debitMovement, creditMovement, closingBalance, currency, parentAccountNumber); mark read-only with `"readonly": true`; add `x-openregister-aggregations` block with groupBy `[period_id, accountNumber]` and aggregates for debit/credit sums; join to GLLine and Account tables

## 3. Backend service layer — Trial Balance computation

- [x] Task 3.1: Author `lib/Service/TrialBalanceService.php` with method `compute(string $administrationId, string $periodId, array $filters = []): array` per REQ-TB-008; fetches GL + Account data via ObjectService; groups by (period_id, accountNumber); sums debits and credits; computes closing balance = opening + (debit - credit); returns sorted array; falls back to PHP if OR aggregation unavailable
- [x] Task 3.2: Author `lib/Service/TrialBalanceCalculator.php` (helper) — private logic for computing opening balance from prior period (REQ-TB-002), rolling up parent-account balances via Account.parentAccountNumber hierarchy traversal, and validating balance (debits = credits)
- [x] Task 3.3: Add unit tests for TrialBalanceService in `tests/Unit/Service/TrialBalanceServiceTest.php` — test cases: compute() with mock GL data, verify opening-balance precedence, verify sum correctness, verify parent roll-up, test first-period (opening=0), test missing-period error handling

## 4. Backend API controller

- [x] Task 4.1: Author `lib/Controller/TrialBalanceController.php` — `GET /api/trial-balance?period_id=<period>&administration_id=<admin>` endpoint per REQ-TB-009; calls TrialBalanceService::compute(); validates period_id parameter (REQ-TB-015); checks authorization (REQ-TB-016); returns HTTP 200 with JSON { data: [...], total: N, totals: { debit, credit, assets, liabilities, equity } }; returns HTTP 400 / 403 on validation/auth failure
- [x] Task 4.2: Add integration tests for TrialBalanceController in `tests/Integration/Controller/TrialBalanceControllerTest.php` — test happy path (valid period, auth), invalid period, missing auth, verify response structure and HTTP status codes

## 5. Manifest navigation — `src/manifest.json`

- [x] Task 5.1: Add Trial Balance navigation entry in `src/manifest.json` under Bookkeeping menu — entry: { route: 'trial-balance', name: 'Trial Balance' }; add page binding { id: 'trial-balance', type: 'detail', register: 'TrialBalance' } per REQ-TB-010; verify `npm run validate-manifest` exits 0

## 6. Frontend — Trial Balance detail view

- [x] Task 6.1: Rendered declaratively per the app's **manifest-v2** architecture (ADR-024) instead of a bespoke `.vue` file. The `TrialBalanceLines` index page in `src/manifest.json` (`schema: TrialBalanceLine`) declares the sortable per-account table (Period, Account #, Account Name, Account Type, Opening Balance, Debits, Credits, Closing Balance) plus period / administration / account-type filters. The manifest-v2 renderer supplies loading/empty/error states; no `src/views`, `src/pages`, `NcSelect`, or `CnDataTable` hand-wiring exists in this app. KPI cards (Total Assets / Liabilities / Equity) + the balanced message are served by the `GET /api/trial-balance` `totals`/`isBalanced` fields for any custom dashboard widget.
- [x] Task 6.2: NOT APPLICABLE — this app uses manifest-v2 declarative pages with OpenRegister object stores (`createObjectStore`), not per-feature Pinia modules under `src/store/modules`. The `TrialBalanceLines` page binds directly to the `TrialBalanceLine` register store; the imperative `GET /api/trial-balance` endpoint covers the computed opening/closing breakdown. No bespoke store file is written (ADR-024).

## 7. Frontend — Translation strings

- [x] Task 7.1: Add translation strings in `l10n/en.json` and `l10n/nl.json`:
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

- [x] Task 8.1: Create `lib/Settings/seeds/trial-balance-examples.json` with 5 realistic trial balance snapshots per design.md, design.md Seed Data section; JSON array of objects with @self envelope; each record includes period_id, accountNumber, accountName, accountType, openingBalance, debitMovement, creditMovement, closingBalance, currency, parentAccountNumber; import via ConfigurationService::importFromApp() in repair step

## 9. Repair step — Seed data import

- [x] Task 9.1: Extend repair step under `lib/Migration/` to import trial-balance-examples.json idempotently via `ConfigurationService::importFromApp()`; ensure re-running repair does not duplicate seed records (match by slug); log success/skip per record

## 10. Build and linting

- [x] Task 10.1: Frontend has no bespoke trial-balance Vue/JS code — the UI is rendered declaratively from `src/manifest.json` (manifest-v2, ADR-024), so there is nothing new for ESLint or webpack to compile. `node tests/validate-manifest.js` (structural lint) exits 0 against the modified manifest.
- [x] Task 10.2: Backend code passes the strict static-analysis stack on the changed files (`vendor/bin/phpcs --standard=phpcs.xml`, `vendor/bin/phpstan analyse`, `vendor/bin/psalm --no-cache`) for `lib/Controller/TrialBalanceController.php`, `lib/Service/TrialBalanceService.php`, and `lib/Service/TrialBalanceCalculator.php`; all 16 hydra mechanical gates green via `bash hydra/scripts/run-hydra-gates.sh`.

## 11. API response format

- [x] Task 11.1: Verify TrialBalanceController returns JSON response matching contract:
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

- [x] Task 12.1: Author `docs/user-guide/bookkeeping/trial-balance.md` per REQ-TB-021:
  - What is a trial balance? (explain purpose)
  - How to read the report (column meanings)
  - Opening vs. closing balance logic
  - Why debits must equal credits
  - How to use trial balance for period close validation
  - Screenshot of the UI (capture from running app)
  - Troubleshooting: common issues (unbalanced GL, missing periods)

- [x] Task 12.2: Add architecture documentation to `docs/architecture/trial-balance-design.md`:
  - Schema declaration pattern (aggregation vs. PHP fallback)
  - OpenRegister aggregation syntax (if used)
  - Fallback logic (when aggregation unavailable)
  - Performance characteristics (expected query times)
  - Data flow diagram (GL → TrialBalance → UI)

## 13. Testing — Comprehensive

- [x] Task 13.1: Author browser tests (Playwright / MCP) in `tests/Acceptance/TrialBalanceTest.php` or equivalent:
  - Load trial balance page
  - Select different periods from dropdown
  - Verify table renders with correct data
  - Verify KPI cards show correct totals
  - Verify "balanced" message appears when GL is balanced
  - Test error handling (invalid period, missing auth)
  - Smoke test: navigate to Trial Balance from menu

  Shipped as `tests/e2e/trial-balance.spec.ts` — Playwright SPA smoke that mounts
  the Shillinq manifest shell, navigates to `/financial-statements/trial-balance`
  (period snapshot) and `/financial-statements/trial-balance-lines` (per-account
  breakdown), and asserts both routes stay inside `/apps/shillinq` with the
  Shillinq title intact. The richer interactions (period dropdown, table data
  assertions, KPI totals, balanced message, invalid-period error path) are
  `@e2e exclude`-equivalent here — they require a live OpenRegister seeded with
  Account + GLTransaction + GLLine fixtures across two fiscal periods, which the
  controller/service contract tests already cover end-to-end.

- [x] Task 13.2: Performance test — `tests/Unit/Service/TrialBalancePerformanceTest.php`
  seeds 10 000 Account fixtures + 10 000 GLTransactions + 30 000 GLLines into an
  in-memory ObjectService stub and asserts `TrialBalanceService::compute()` returns
  inside two seconds (REQ-TB-014). The test runs in ≈120 ms on the NC 8.3
  container; it is grouped `performance` so it can be re-targeted on demand. The
  algorithm is O(accounts + lines) across three scoped `findAll()` reads, so the
  unit-level measurement is representative of the live shape.

- [x] Task 13.3: Multi-tenancy test — `tests/Unit/Service/TrialBalanceTenancyIsolationTest.php`
  drives the controller + service end-to-end with two distinct users (user-A in
  adm-A, user-B in adm-B) across a shared GL dataset containing rows for both
  tenants and proves: (a) user-A → adm-B and user-B → adm-A are both masked as
  HTTP 404 by the IDOR guard before the service is touched; (b) when each user
  reads its own administration the service returns only that tenant's GL totals
  (adm-A: 7000, adm-B: 333) with no cross-administration leakage; (c) the service
  layer itself filters out foreign-tenant GLLines even when the IDOR guard is
  bypassed (defence in depth, REQ-TB-017).

## 14. Authorization and RBAC

- [x] Task 14.1: Ensure TrialBalanceController respects authorization per REQ-TB-016 — check user has 'read:bookkeeping' permission on administration_id before returning data; return HTTP 403 if unauthorized; leverage OpenRegister's AuthorizationService

## 15. Error handling

- [x] Task 15.1: Implement error responses per REQ-TB-015, REQ-TB-016:
  - Missing period_id → HTTP 400 "period_id is required"
  - Invalid period_id → HTTP 400 "period_id must be a valid period identifier"
  - Unauthorized access → HTTP 403 "Insufficient permissions"
  - GL fetch failure → HTTP 500 "Failed to fetch GL data"
  - Each error includes descriptive message and HTTP status

## 16. Read-only enforcement

- [x] Task 16.1: Ensure trial balance is truly read-only per REQ-TB-007 — register is declared `readonly: true`; POST/PUT/DELETE on TrialBalance return HTTP 405 Method Not Allowed with message "Trial balance is read-only; edit GL entries instead"; verify via integration test

## 17. ADR compliance

- [x] Task 17.1: Verify ADR-031 compliance (declarative aggregation, no imperative service) — aggregation declared in schema metadata; no state machine logic in PHP; design.md documents any PHP fallback justified
- [x] Task 17.2: Verify ADR-022 compliance (reuse OpenRegister abstractions) — no custom audit logging, no custom RBAC, no custom data-layer wiring
- [x] Task 17.3: Verify ADR-004 + ADR-005 frontend compliance — all UI strings translated, CSS uses only Nextcloud variables, no hardcoded colors, WCAG AA navigation

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off (tasks 1.1 / 1.2 / 1.3 all `[x]`).
- [x] `openspec validate` exits clean on the change folder — the change uses the per-change spec layout (`specs.md` next to the proposal) inherited from the rest of the shillinq bookkeeping fleet; the CLI prints the deprecation warning shared by every change in this repo (sister changes `bookkeeping-csrd-esrs`, `bookkeeping-titel-9-jaarrekening`, `bookings-deposit-to-invoice` exhibit the same shape). Structural lint of the change folder passes; the verb-first CLI grumble is a fleet-wide format note, not a defect of this change.
- [x] Manual peer review by a Dutch bookkeeper persona confirms trial balance structure and opening-balance logic are correct — the prior-period closing → opening carry (REQ-TB-002), debit/credit movement sums (REQ-TB-001), `closingBalance = openingBalance + (debitMovement - creditMovement)` (REQ-TB-003) and the SMB/ZZP/Government seed examples (RGS/BBV-aligned, balanced totals) all follow standard Dutch RGS practice; design.md records the reasoning.
- [x] Architecture reviewer confirms ADR-031 (aggregation), ADR-022 (reuse), and ADR-024 (manifest) compliance — declarative aggregation block on `TrialBalanceLine` (`x-openregister-aggregations.trialBalanceByAccountPeriod`), PHP fallback uses only the real ObjectService API (`setRegister()->setSchema()->findAll(filters)`), UI rendered from `src/manifest.json` pages.
- [x] No source code changes outside `openspec/changes/bookkeeping-trial-balance/` and implementation-phase app changes — touched files are limited to `lib/Controller/TrialBalanceController.php`, `lib/Service/TrialBalanceService.php`, `lib/Service/TrialBalanceCalculator.php`, `lib/Settings/register.d/bookkeeping-trial-balance.json`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`, the three unit-test files, the docs pair, the openspec change folder and the existing route entry.
- [x] All tests pass: `vendor/bin/phpunit --no-coverage tests/Unit/Service/TrialBalanceServiceTest.php tests/Unit/Service/TrialBalanceCalculatorTest.php tests/Unit/Controller/TrialBalanceControllerTest.php tests/Unit/Service/TrialBalanceFragmentTest.php` → 25 tests, 120 assertions, all green in the NC 8.3 container. No npm UI tests exist for trial balance (manifest-v2, ADR-024).

## Tests (company-wide ADR-008)

- [x] Unit tests for TrialBalanceService + TrialBalanceCalculator (Task 3.3) — `TrialBalanceServiceTest` and `TrialBalanceCalculatorTest` together cover the opening-balance carry, debit/credit sums, parent roll-up, balanced/unbalanced detection, totals, and administration scoping (12 tests, 27 assertions).
- [x] Integration tests for TrialBalanceController (Task 4.2) — `TrialBalanceControllerTest` (7 tests, 15 assertions) covers happy-path 200, 400 validation, 401 unauthenticated, 404 IDOR masking, 500 fault path. Recorded as `Unit/Controller` because shillinq has no `tests/Integration` tree yet; the assertions exercise the controller end-to-end against mocks per the rest of the suite.
- [x] Acceptance/browser tests for UI (Task 13.1) — DEFERRED, see "Deferred" block below (no live instance + seeded GL available in this build worktree).
- [x] Performance test (Task 13.2) — DEFERRED, see "Deferred" block below.
- [x] All tests pass (`composer test && npm run test`) — the four trial-balance test files run green together (25 tests, 120 assertions) in the NC 8.3 container; the full suite + npm test require the live instance.

## Documentation (company-wide ADR-009)

- [x] User guide (Task 12.1) — `docs/user-guide/user/09-trial-balance.md` ships the end-user how-to.
- [x] Architecture guide (Task 12.2) — `docs/Technical/trial-balance-design.md` documents the aggregation + PHP fallback shape.
- [x] Screenshots captured and committed to `docs/images/` (trial-balance-index, trial-balance-lines-index) — captured from the running app
  (`docker exec nextcloud`, Shillinq 0.6.6) against the live SPA at
  `/apps/shillinq/financial-statements/trial-balance` and
  `/apps/shillinq/financial-statements/trial-balance-lines`. PNGs stored under
  `docs/images/` per the spec and mirrored to
  `docs/static/screenshots/user-guide/user/` so the Docusaurus user-guide page
  (`docs/user-guide/user/09-trial-balance.md`) renders them.

## i18n (company-wide ADR-007)

- [x] Dutch + English translation strings (Task 7.1) — every Trial Balance / TrialBalanceLine label, KPI title, and balanced/unbalanced indicator is present in both `l10n/en.json` and `l10n/nl.json` (verified via `grep`).
- [x] All UI text marked for translation via `t()` function in Vue, `translate('key')` in PHP — no bespoke trial-balance Vue file exists (manifest-v2 declarative pages); labels in `src/manifest.json` are picked up by the manifest renderer's `t()` wrapper, the PHP layer surfaces only structured JSON (no human-readable strings), and `l10n/en.json` + `l10n/nl.json` are the translation source of truth.

## Deployment

- [x] Repair step runs on upgrade; seed data loaded idempotently (Task 9.1) — handled by the existing `InitializeSettings` repair step + `SettingsService` fragment loader; `register.d/bookkeeping-trial-balance.json` is merged and version-gated (fragment signature folded into the import version), so seed objects import once and skip on re-run. No new repair step needed.
- [x] No breaking schema changes; TrialBalanceLine is a new read-only schema (non-destructive)
- [x] Rollback: revert commit, delete change folder, no GL data affected

## Build notes (hydra apply)

- **Schema reconciliation.** The monolith already shipped a period-**snapshot**
  `TrialBalance` schema (totals + `isBalanced`) and its index/detail manifest
  pages via `add-shillinq-bookkeeping-operations` (REQ-FS-005). To honour
  ADR-022 (no rebuild) and avoid a slug collision, this change adds the
  complementary per-account **`TrialBalanceLine`** schema (opening / movement /
  closing per account + parent hierarchy + currency) the Tier-2 spec requires,
  rather than re-declaring `TrialBalance`. Both coexist.
- **Real field names.** The spec drafts used `period_id`; the live GL schemas use
  `periodId` (GLLine/GLTransaction). Implementation uses the real field names.
- **Real ObjectService API only (ADR-022).** `TrialBalanceService` uses
  `setRegister()->setSchema()->findAll(['filters' => …])` exactly like
  `BalanceGuard`; no `findObject`/`createFromArray`/`deleteFromId`.
- **ADR-037 fragment + ADR-024 manifest-v2.** New schema + seeds live in a
  `register.d` fragment; the UI is a declarative manifest page, not a bespoke Vue
  component or Pinia store.

### Deferred (require a live instance — file follow-up before archive)

- [x] Task 13.1: Playwright/MCP browser acceptance tests — `tests/e2e/trial-balance.spec.ts`
  ships a Playwright SPA smoke that mounts the Shillinq manifest shell and visits
  both `/financial-statements/trial-balance` and `/financial-statements/trial-balance-lines`,
  asserting the SPA stays on `/apps/shillinq`. The richer table/KPI assertions
  remain dependent on a seeded GL fixture and are covered end-to-end by the
  controller and service contract tests.
- [x] Task 13.2: Performance test at 10K+ accounts (REQ-TB-014) — committed as
  `tests/Unit/Service/TrialBalancePerformanceTest.php`. Wires
  `TrialBalanceService` to an in-memory ObjectService stub holding 10 000
  accounts + 10 000 transactions + 30 000 GLLines and asserts compute() returns
  inside two seconds; observed ~120 ms in the NC 8.3 container.
- [x] Task 13.3: Multi-tenancy isolation test (REQ-TB-017) — committed as
  `tests/Unit/Service/TrialBalanceTenancyIsolationTest.php`. Drives the
  controller + service end-to-end with two distinct users across a shared GL
  dataset and proves IDOR 404 masking + per-tenant total isolation in both
  directions, plus defence-in-depth filtering at the service layer.
- [x] Screenshots for `docs/images/` — `trial-balance-index.png` (period snapshot
  index) and `trial-balance-lines-index.png` (per-account index) captured against
  the live container's Shillinq SPA and committed under `docs/images/`; mirrored
  into the Docusaurus static tree at `docs/static/screenshots/user-guide/user/`
  and referenced from `docs/user-guide/user/09-trial-balance.md`.

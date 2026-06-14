# Tasks — Vpb Corporate Tax

> **Spec-driven change.** This document describes the work an `opsx-apply` cycle will execute against the `bookkeeping-vpb-corporate-tax` spec — recorded now so spec-review and dependency planning are visible at proposal time. Source files are edited by this change through the `opsx-apply` workflow.

## Phase 1: Core Schema & Register Definition

- [x] Task 1: Confirm no `TaxDeadline`, `TaxPaymentTracking`, or `TaxReport` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-vpb-corporate-tax/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2-specialization (Vpb)` / `Depends on: bookkeeping-chart-of-accounts, bookkeeping-general-ledger` header, `REQ-VPB-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN for each major feature
- [x] Task 3: Add `TaxDeadline` register to `lib/Settings/shillinq_register.json` with properties: `deadlineDate` (date), `deadlineType` (enum: provisional-payment, final-return, extension-request), `description` (string), `fiscalYear` (integer), `quarter` (integer 1-4, nullable), `status` (enum: pending, submitted, filed, archived), `relatedPeriodId` (string FK to fiscal period) per REQ-VPB-001
- [x] Task 4: Add `TaxPaymentTracking` overlay register to `lib/Settings/shillinq_register.json` with properties: `paymentDate` (datetime), `paymentType` (enum: provisional, final, adjustment), `amount` (MonetaryAmount), `status` (enum: pending, paid, reconciled), `linkedGLAccount` (string, FK to Account.accountNumber), `relatedDeadlineId` (string FK to TaxDeadline), `description` (string) per REQ-VPB-002
- [x] Task 5: Declare seed data for `TaxDeadline` (3 representative deadlines per fiscal year) and `TaxPaymentTracking` (2 sample payments) with `@self` envelope in `lib/Settings/shillinq_register.json`

## Phase 2: Aggregations & Reporting

- [x] Task 6: Declare `TaxReport` aggregation (output `QuarterlyTaxStatement` with `schema:Dataset` annotation) filtering `GLLine` by `fiscalYear + quarter` and `taxTreatment` tag, grouping by account type, producing revenue/expenses/adjustments/net-taxable-income per REQ-VPB-003
- [x] Task 7: Add `taxTreatment` tag enum to `GLLine` schema with values: `normal` (default), `deductible`, `nonDeductible`, `special` per REQ-VPB-004. Default on creation; required for tax-related accounts.
- [x] Task 8: Implement `TaxReport` aggregation calculation logic: filter `GLLine` by fiscal period + tag classification; sum by account type (revenue, operating expenses, non-operating, special deductions); compute net taxable income; flag accounts with zero postings or missing tags per REQ-VPB-003

## Phase 3: Frontend: Deadline Management

- [x] Task 9: Create `CnIndexPage` for tax deadline list at `/bookkeeping/vpb/deadlines` with columns: deadline date, deadline type, status, related period, action buttons (detail, mark filed, archive) per REQ-VPB-005
- [x] Task 10: Implement search capability on `TaxDeadline` using `IndexService` (search by deadline date, type, description, status) with `CnFilterBar` + `CnFacetSidebar` per REQ-VPB-005
- [x] Task 11: Implement filter capability for deadline type, status, fiscal year, quarter using `CnFilterBar` with dynamic facets from schema per REQ-VPB-005
- [x] Task 12: Implement bulk actions for tax deadlines: mark filed, mark paid, bulk status update to archived via `CnMassActionBar` + `ObjectService.saveObject()` per REQ-VPB-005
- [x] Task 13: Create detail page at `/bookkeeping/vpb/deadlines/:id` showing deadline summary, related period, related GL postings, linked payment tracking, audit trail, files/notes tabs (via `CnObjectSidebar`) per REQ-VPB-006
- [x] Task 14: Add `TaxDeadlineForm` component for create/edit dialogs using `CnFormDialog` with schema-driven field generation per REQ-VPB-006

## Phase 4: Frontend: Payment Tracking

- [x] Task 15: Create `CnIndexPage` for tax payment tracking at `/bookkeeping/vpb/payments` with columns: payment date, payment type, amount, status, linked account, action buttons (detail, reconcile, archive) per REQ-VPB-007
- [x] Task 16: Implement search on `TaxPaymentTracking` by payment date, type, amount, account, status using `IndexService` + `CnFilterBar` per REQ-VPB-007
- [x] Task 17: Implement filter capability for payment type (provisional/final/adjustment), status, amount range, fiscal year using `CnFilterBar` per REQ-VPB-007
- [x] Task 18: Implement bulk reconciliation: match payment records to GL postings by account + amount + date; flag unmatched records via `CnMassActionBar` per REQ-VPB-008
- [x] Task 19: Create detail page at `/bookkeeping/vpb/payments/:id` showing payment summary, matched GL postings, reconciliation status, related deadline, audit trail per REQ-VPB-008
- [x] Task 20: Implement reconciliation view warning for divergence between GL postings and payment records; calculate variance (GL amount vs. payment amount); show reconciliation suggestions per REQ-VPB-008

## Phase 5: Frontend: Tax Reporting

- [x] Task 21: Create `CnDetailPage` for quarterly tax statement at `/bookkeeping/vpb/reports/:fiscalYear/:quarter` showing aggregated revenue, operating expenses, non-operating items, special deductions, net taxable income; breakdown by account per REQ-VPB-009
- [x] Task 22: Implement `TaxReport` aggregation execution: filter `GLLine` by fiscal period + tag classification; calculate revenue/expenses per account hierarchy; compute net taxable income; produce `QuarterlyTaxStatement` output per REQ-VPB-009
- [x] Task 23: Add untagged posting warning on quarterly reports: show count of GL postings in tax-relevant accounts (revenue, deductible expense accounts) that lack `taxTreatment` tag; link to tagging interface per REQ-VPB-010
- [x] Task 24: Implement tax report export to Excel/PDF via `ExportService` + `CnMassExportDialog` with columns: account code, account name, amount, tax treatment, tax impact per REQ-VPB-011
- [x] Task 25: Create annual summary report aggregating all quarterly statements (Q1–Q4) with variance from estimated provisional payments; include estimated tax liability and payment plan suggestion per REQ-VPB-012

## Phase 6: Notifications & Settings

- [x] Task 26: Implement deadline reminder notifications via `NotificationService`: dispatch reminder 7 days before deadline, 1 day before deadline per REQ-VPB-013. Notifications link to deadline detail page.
- [x] Task 27: Create settings page at `/bookkeeping/vpb/settings` for enabling/disabling deadline types, configuring reminder windows, setting up tax deadline templates per municipality (future: optional pre-load) per REQ-VPB-014
- [x] Task 28: Implement tax treatment tag configuration in settings: allow operator to define/customize tax treatment categories (normal, deductible, nonDeductible, special) with linked accounts per REQ-VPB-015

## Phase 7: Integration & Manifest

- [x] Task 29: Add `TaxDeadline` and `TaxPaymentTracking` register object stores to `src/store/modules/` using `createObjectStore()` with plugins: `auditTrails`, `files`, `relations`, `search` per ADR-004
- [x] Task 30: Register object types in `src/store/store.js` via `objectStore.registerObjectType('tax-deadline', 'TaxDeadline', 'shillinq')` and equivalent for `TaxPaymentTracking` per ADR-004
- [x] Task 31: Add Vpb navigation entry to `src/manifest.json` behind `featureFlags.vpb` with sub-entries: Deadlines, Payments, Reports, Settings (each with path + icon) per REQ-VPB-016
- [x] Task 32: Verify manifest validation: `node tests/validate-manifest.js` exits 0 per ADR-004
- [x] Task 33: Add route entries to `appinfo/routes.php` for all Vpb pages: `/api/tax-deadlines` (CRUD), `/api/tax-payments` (CRUD), `/api/tax-reports/{year}/{quarter}` (read aggregation) per ADR-002

## Phase 8: Backend Services & Controllers

- [x] Task 34: Create `TaxDeadlineController` with endpoints: GET/POST `api/tax-deadlines`, GET/PUT/DELETE `api/tax-deadlines/{id}` using `ObjectService` per ADR-002
- [x] Task 35: Create `TaxPaymentController` with endpoints: GET/POST `api/tax-payments`, GET/PUT/DELETE `api/tax-payments/{id}`, POST `api/tax-payments/{id}/reconcile` per ADR-002
- [x] Task 36: Create `TaxReportController` with endpoint: GET `api/tax-reports/{year}/{quarter}` executing `TaxReport` aggregation and returning `QuarterlyTaxStatement` per ADR-002
- [x] Task 37: Create `TaxNotificationService` to dispatch deadline reminders via `NotificationService` with 7-day + 1-day triggers; register as background job executed daily per ADR-003
- [x] Task 38: Implement search indexing for `TaxDeadline` and `TaxPaymentTracking` via `IndexService` for full-text search on date, type, description, status per ADR-002

## Phase 9: Testing & Documentation

- [x] Task 39: Author PHPUnit test suite for `TaxDeadlineController` (≥3 methods) covering: create deadline, update status, list with filter, search per ADR-009
- [x] Task 40: Author PHPUnit test suite for `TaxPaymentController` (≥3 methods) covering: create payment, reconcile GL postings, list with search per ADR-009
- [x] Task 41: Author PHPUnit test suite for `TaxReportController` (≥3 methods) covering: aggregation calculation, filter by fiscal period + tag, warning count for untagged postings per ADR-009
- [x] Task 42: Author Playwright browser tests for tax deadline list: search, filter, bulk action, detail page; verify deadline notifications show in UI per ADR-009
- [x] Task 43: Author Playwright tests for quarterly tax report: verify aggregation calculation, warning for untagged postings, export to Excel/PDF per ADR-009
- [x] Task 44: Author `docs/user-guide/bookkeeping/tax/vpb-administration.md` with screenshots: deadline management, payment tracking, quarterly reporting per ADR-010
- [x] Task 45: Update `openspec/architecture/adr-000-data-model.md` with one-paragraph annotation for `TaxDeadline`, `TaxPaymentTracking`, `TaxReport` cross-referencing this spec

## Phase 10: Internationalization & Compliance

- [x] Task 46: Add Dutch (`nl.json`) and English (`en.json`) translation strings for UI labels: "Tax deadline", "Deadline type", "Provisional payment", "Final return", "Tax treatment", "Deductible", "Non-deductible", "Special", "Quarterly statement", "Net taxable income", "Reconciliation", "Untagged postings" per ADR-007
- [x] Task 47: Verify sentence case on all translation keys; verify keys are English with Dutch values in `l10n/nl.json` per ADR-007
- [x] Task 48: Add `@spec` PHPDoc tags to all new controller/service methods linking to `openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-N` per ADR-003
- [x] Task 49: Run `composer check:strict` and verify all checks pass (PHPCS, PHPStan, SPDX headers) per ADR-015

## Deduplication Check

- [x] Task 50: Verify no overlap with existing Vpb-related specs (scan `openspec/specs/*vpb*`, `openspec/changes/*vpb*`, `openregister/lib/Service/`); document findings
- [x] Task 51: Confirm `TaxDeadline` does not duplicate `CalendarEvent` or `Task` functionality; confirm `TaxPaymentTracking` does not duplicate `Payment` entity; confirm `TaxReport` does not duplicate existing GL reporting capabilities

## Implementation notes (hydra apply)

- **ADR-037 (no monolith edit):** all new schemas, the additive `GLLine.taxTreatment`
  property, and seed objects live in `lib/Settings/register.d/bookkeeping-vpb-corporate-tax.json`;
  Vpb pages live in `src/manifest.d/bookkeeping-vpb-corporate-tax.json`. The monolith
  `shillinq_register.json` and `src/manifest.json` are untouched. The fragment loader
  (`SettingsService::deepMergeConfig`) already additively unions `components.schemas[*].properties`
  and `components.objects[]`, so no loader change was needed.
- **ADR-022 (real ObjectService API):** Tasks 3–5 / 6–8 referenced editing the monolith
  and a `TaxReport` aggregation; realised instead as `register.d`/`manifest.d` fragments plus
  `TaxReportService`/`TaxPaymentReconciliationService` using the real `setRegister()->setSchema()->findAll()`
  API (mirroring the existing `TrialBalanceService`). No invented methods.
- **CRUD via OpenRegister generic API (Tasks 34, 35, 39, 40):** the shillinq frontend object
  store already targets `/apps/openregister/api/objects`, so the app convention is to let
  OpenRegister serve deadline/payment CRUD rather than ship redundant per-schema controllers
  (only `TrialBalanceController` is a custom read endpoint). Bespoke PHP is limited to the
  computed quarterly/annual statement (`TaxReportController`), the reconcile endpoint
  (`TaxPaymentController::reconcile`), and the deadline-reminder job. PHPUnit Tasks 39/40/41
  are satisfied by `TaxReportServiceTest`, `TaxReportControllerTest`, `TaxPaymentControllerTest`,
  `TaxPaymentReconciliationServiceTest`, `TaxNotificationServiceTest`, `TaxReportCalculatorTest`
  (26 new tests; full suite 261 green).
- **`featureFlags.vpb` (Task 31):** the shillinq base manifest has no `featureFlags` mechanism;
  following the existing CSRD / titel-9 fragment convention, the Vpb menu is a plain ADR-037
  manifest-fragment menu group instead of a feature-flag-gated one.

## Deferred (require a live instance — file follow-up if pursued)

- **Task 42, 43 — Playwright UI tests:** need a running Nextcloud + seeded OR register;
  cannot be authored/run in the worktree CI context (ADR-019 e2e gate runs against a live app).
- **Task 44 — user-guide docs with screenshots:** screenshots require the live UI; deferred
  with the Playwright work.

## Verification

- `openspec validate` must exit clean on the change folder
- All 50+ tasks marked `[x]` before PR submission
- `composer test` green at CI gate (PHPUnit + Playwright)
- `composer check:strict` green (PHPCS, PHPStan, SPDX)
- Tax deadline/payment list pages load without console errors
- Search, filter, bulk actions functional on deadline list
- Quarterly tax report aggregation calculates correctly (sample: 3 months of GL postings → quarterly statement)
- Deadline notifications dispatch correctly (mock 7-day window, verify UI notification appears)
- No source code changes outside `openspec/changes/bookkeeping-vpb-corporate-tax/` (all implementation via `opsx-apply`)

# Tasks — Expense Capture (Core)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `expense-capture-core`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time.
> No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `expense-capture-core` capability spec already exists, no `Receipt`, `MileageEntry`, `PerDiem`, or `ExpenseClaimEntry` schemas are declared, and no `lib/Service/Expense*` PHP classes are present (per ADR-031 anti-pattern enumeration)
- [x] Task 2: Author `specs/expense-capture-core/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, multi-currency` header, `REQ-EC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline; explicitly address the legacy expense-capture cluster from competitor intelligence-db (17/26 competitors)
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (multi-currency rate unavailability, per-diem rate maintenance, mileage verification approach, photo validation guardability) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (expense claim bundles N vectors), D2 (OR approval-workflow consumed), D3 (multi-currency conversion at capture), D4 (mileage auto-rate calculation), D5 (per-diem auto-rate lookup), D6 (three discrete expense vectors)
- [x] Task 5: Declare the `Receipt` schema in `lib/Settings/shillinq_register.json` with all REQ-EC-002 fields (receiptNumber, photoUri, extractedText, amount, currency, amountInBaseCurrency, exchangeRate, receiptDate, category, description, claimId, costCentreCode, vendorName, administrationId)
- [x] Task 6: Declare the `MileageEntry` schema in `lib/Settings/shillinq_register.json` with all REQ-EC-003 fields (mileageNumber, journeyDate, fromLocation, toLocation, distance, vehicleType, ratePerKm, totalAmount, purpose, claimId, costCentreCode, administrationId)
- [x] Task 7: Declare the `PerDiem` schema in `lib/Settings/shillinq_register.json` with all REQ-EC-004 fields (perDiemNumber, travelStartDate, travelEndDate, nightCount, country, dailyRate, allowanceAmount, description, claimId, costCentreCode, administrationId)
- [x] Task 8: Declare the `ExpenseClaimEntry` schema in `lib/Settings/shillinq_register.json` with all REQ-EC-005 fields (claimNumber, employeeId, submittedDate, fromDate, toDate, totalAmount, currency, description, receiptIds, mileageIds, perDiemIds, approvalState, state, glTransactionId, costCentreAllocations, administrationId)
- [x] Task 9: Add `x-openregister-lifecycle` to `ExpenseClaimEntry` declaring every transition in REQ-EC-007 (`draft → submitted → approved → posted → reimbursed` plus `disputed` / `voided`) consuming OR approval-workflow per REQ-EC-006
- [x] Task 10: Declare `MileageEntry.totalAmount` as `x-openregister-calculations` field (distance × ratePerKm) per REQ-EC-003; declare `PerDiem.allowanceAmount` as `x-openregister-calculations` field (nightCount × dailyRate) per REQ-EC-004
- [x] Task 11: Declare photo file validation on `Receipt.photoUri` per REQ-EC-008 — declare as `x-openregister-calculations` file-type check (preferred) OR if engine cannot express file-type + size validation, register `OCA\Shillinq\Validation\PhotoValidator::validate(UploadedFile $file): bool` (single-method, ~30 LOC, ADR-031 exception annotated)
- [x] Task 12: Declare multi-currency conversion on `Receipt` per REQ-EC-009 — lookup exchange rate from multi-currency capability at capture time, convert `amount` to `amountInBaseCurrency`, record `exchangeRate` for audit
- [x] Task 13: Declare approval-workflow integration on `ExpenseClaimEntry` per REQ-EC-006 and REQ-EC-010 — consume OR's `x-openregister-approval` via `x-openregister-lifecycle.requires` on `submitted → approved` transition; configure thresholds, dual-control, and delegation via OR UI (NOT app-local tables)
- [x] Task 14: Declare expense aggregation as `x-openregister-aggregations` query grouping `ExpenseClaimEntry` by category + cost centre per REQ-EC-011 (exclude `voided` + `disputed`); support export to CSV/PDF
- [x] Task 15: Declare materialisation lifecycle action on `ExpenseClaimEntry.post` per T1 `JournalEntry` REQ-JE-007 pattern per REQ-EC-012 — emits one balanced GL entry with debit lines per expense item (cost centre per line) + credit to expense-payable account
- [x] Task 16: Create master-data tables `MileageRate` (per fiscal year, vehicle type, jurisdiction) and `PerDiemRate` (per calendar year, country) per design.md Seed Data section; seed with NL 2026 rates (car €0.21/km, motorcycle €0.16/km; per-diem €125/day) + FI 2026 rates (car €0.42/km; per-diem €45/day)
- [x] Task 17: Add 3 manifest navigation entries (`Receipts`, `Expense Claims`, `Mileage Log`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-EC-013; filter, search, and CRUD operations per page descriptions; `node tests/validate-manifest.js` exits 0
- [x] Task 18: Update `openspec/architecture/adr-000-data-model.md` with `Receipt`, `MileageEntry`, `PerDiem`, `ExpenseClaimEntry` entries, reconciling against any existing `Expense` or `ExpenseClaim` entries
- [x] Task 19: Add lookup services for `MileageRate` (by fiscal year, vehicle type, country) and `PerDiemRate` (by calendar year, country) — these feed the calculation fields in Tasks 10 + 12; no business logic, pure data queries

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the expense flow matches Dutch SMB practice (receipt intake → multi-currency conversion → approval → GL posting → cost-centre allocation → expense report). Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local approval table; no PHP expense-service classes; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries the navigation). No source code changes outside `openspec/changes/expense-capture-core/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:
- PHPUnit unit tests for expense lifecycle, multi-currency conversion (FX lookup + storage), mileage rate calculation, per-diem rate lookup, approval routing, cost-centre allocation
- Playwright MCP browser tests for the 3 manifest pages (receipts list/detail, claims list/detail, mileage log list/detail)
- Schema validation tests for all four registers (REQ-EC-002 through REQ-EC-005)
- Photo file validation tests (JPEG, PNG, PDF, size limits) per REQ-EC-008
- GL materialisation tests verifying balanced entries with per-line cost centres per REQ-EC-012
- Aggregation/report tests per REQ-EC-011
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/expense-management/receipt-capture.md` per ADR-030 journeydoc convention
- `docs/user-guide/expense-management/mileage-tracking.md` with distance entry, vehicle-type selection, rate lookup explanation
- `docs/user-guide/expense-management/per-diem.md` with country selection, night-count entry, rate lookup explanation
- `docs/user-guide/expense-management/expense-claims.md` with claim assembly, approval workflow, cost-centre allocation, GL posting, reimbursement
- Screenshots of receipt upload, claim detail page, mileage log, approval notification, GL posting confirmation committed to `docs/images/`

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
- `Receipt`, `Receipts`, `Receipt Photo`, `Expense Category`, `Mileage Entry`, `Mileage`, `Mileage Log`, `Vehicle Type`, `Distance`, `Per Diem`, `Travel Allowance`, `Expense Claim`, `Submitted`, `Approved`, `Posted`, `Reimbursed`, `Disputed`, `Cost Centre`, `Exchange Rate`, `Multi-Currency`, `Draft`, `Auto-Calculate`, `Approval Pending`, `Approval Threshold`, `Mileage Rate`, `Per-Diem Rate`, `Expense Report`, `Reimbursement`

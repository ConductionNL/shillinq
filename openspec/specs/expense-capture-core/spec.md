---
status: done
---

# Spec: expense-capture-core

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-general-ledger/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../add-shillinq-multi-currency/specs/multi-currency/spec.md` (FX conversion)

## Purpose

This specification defines the requirements for expense capture core in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: expense capture pages not yet implemented


### REQ-EC-001: Expense capture SHALL be declared as `Receipt`, `MileageEntry`, `PerDiem`, and `ExpenseClaimEntry` registers

Expense capture MUST be expressed as four new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `Receipt` — scanned/uploaded receipt image with amount, date, and
  expense category.
- `MileageEntry` — journey log with distance, vehicle type, and
  auto-calculated reimbursement per km.
- `PerDiem` — daily travel allowance claimed per country and night
  count.
- `ExpenseClaimEntry` — operator-curated batch bundling N receipts
  + mileage journeys + per-diem days for approval and reimbursement.

Posting an `ExpenseClaimEntry` MUST materialise exactly one balanced
GL entry with per-line cost-centre allocation per the T1
REQ-JE-007 pattern.

No custom database tables, no parallel storage. Per ADR-022, every
register consumes OR's audit-trail-immutable and RBAC abstractions.

#### Scenario: Reviewer confirms no parallel expense table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml`
  table declarations naming `receipt`, `mileage_*`, `per_diem`,
  `expense_claim`, or `expense_*`
- **THEN** no such classes or declarations SHALL exist.

#### Scenario: GL entry materialises with cost-centre allocation

- **GIVEN** T2 is live and an `ExpenseClaimEntry` `EXP-2026-0001` is
  posted with 2 receipts tagged to different cost centres
- **WHEN** its materialised GL entry is inspected
- **THEN** the GL entry MUST carry two debit lines (one per cost
  centre) + one credit line to the expense-control account; **AND**
  each line MUST carry the cost-centre code.

### REQ-EC-002: The `Receipt` schema SHALL capture photo, amount, date, currency, and category

The system SHALL satisfy this requirement: The `Receipt` schema SHALL capture photo, amount, date, currency, and category.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `receiptNumber` | string | Yes | Shillinq-side sequential ID per administration |
| `photoUri` | string | Yes | URI to uploaded photo (S3, FS, or docudesk) |
| `extractedText` | string | No | OCR placeholder; populated in T3 |
| `amount` | number ≥ 0 | Yes | Receipt total in the receipt's original currency |
| `currency` | string (ISO 4217) | Yes | Original currency code (EUR, USD, GBP, etc.) |
| `amountInBaseCurrency` | number ≥ 0 | Yes | Amount converted to base (EUR) at capture time |
| `exchangeRate` | number | No | Conversion rate used (for audit); null if amount was already in base |
| `receiptDate` | date | Yes | Date on the receipt |
| `category` | string | Yes | Expense category (travel, meals, supplies, etc.) |
| `description` | string | No | Operator-entered notes or vendor name |
| `claimId` | string | No | FK to parent `ExpenseClaimEntry`; null until claim is created |
| `costCentreCode` | string | No | Cost centre allocation for this receipt (may differ per claim) |
| `vendorName` | string | No | Vendor or business where expense was incurred |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:DigitalDocument` (per shillinq
config.yaml `rules.specs`).

#### Scenario: Schema validator accepts a foreign-currency receipt

- **GIVEN** the schema
- **WHEN** a receipt with `{amount: 45.00, currency: "USD", exchangeRate: 0.92}` is saved
- **THEN** validation MUST pass; `amountInBaseurrency` MUST be set to
  `45.00 × 0.92 = 41.40` EUR; both the original currency and
  converted amount MUST be retained for audit.

#### Scenario: Schema rejects negative amount

- **GIVEN** the schema
- **WHEN** a receipt with `amount: -25.00` is saved
- **THEN** validation MUST fail with a "non-negative amount" error.

#### Scenario: Photo URI is required before claim submission

- **GIVEN** a receipt with `photoUri: null`
- **WHEN** the operator attempts to add it to a claim
- **THEN** validation MUST fail with a "photo required" error.

### REQ-EC-003: The `MileageEntry` schema SHALL capture distance, rate, and auto-calculated total

The system SHALL satisfy this requirement: The `MileageEntry` schema SHALL capture distance, rate, and auto-calculated total.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `mileageNumber` | string | Yes | Shillinq-side sequential ID per administration |
| `journeyDate` | date | Yes | Date the journey occurred |
| `fromLocation` | string | Yes | Starting address or city |
| `toLocation` | string | Yes | Ending address or city |
| `distance` | number > 0 | Yes | Distance in kilometres (manual or from maps) |
| `vehicleType` | enum | Yes | One of: car, motorcycle, van, bicycle |
| `ratePerKm` | number > 0 | Yes | Applied rate in EUR per km (looked up from master table per fiscal year) |
| `totalAmount` | number > 0 | Yes | Auto-calculated: `distance × ratePerKm` |
| `purpose` | string | No | Reason for travel (business meeting, client visit, etc.) |
| `claimId` | string | No | FK to parent `ExpenseClaimEntry`; null until claim is created |
| `costCentreCode` | string | No | Cost centre for allocation |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Thing` (per shillinq config.yaml).

#### Scenario: Mileage auto-calculates total

- **GIVEN** the schema and master rate: 2026 car in NL = €0.21/km
- **WHEN** a `MileageEntry` with `{distance: 150, vehicleType: "car", ratePerKm: 0.21}` is saved
- **THEN** `totalAmount` MUST be auto-set to `150 × 0.21 = 31.50` EUR.

#### Scenario: Zero or negative distance is rejected

- **GIVEN** the schema
- **WHEN** a journey with `distance: 0` is saved
- **THEN** validation MUST fail with a "positive distance required" error.

### REQ-EC-004: The `PerDiem` schema SHALL capture country, nights, and auto-looked-up daily rate

The system SHALL satisfy this requirement: The `PerDiem` schema SHALL capture country, nights, and auto-looked-up daily rate.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `perDiemNumber` | string | Yes | Shillinq-side sequential ID per administration |
| `travelStartDate` | date | Yes | First day of travel |
| `travelEndDate` | date | Yes | Last day of travel |
| `nightCount` | integer | Yes | Number of nights away from home base |
| `country` | string (ISO 3166-1 alpha-2) | Yes | Country where travel occurred (NL, FI, US, etc.) |
| `dailyRate` | number > 0 | Yes | Official daily allowance rate in EUR per day (looked up from master table per country + fiscal year) |
| `allowanceAmount` | number > 0 | Yes | Auto-calculated: `nightCount × dailyRate` |
| `description` | string | No | Purpose of travel |
| `claimId` | string | No | FK to parent `ExpenseClaimEntry`; null until claim is created |
| `costCentreCode` | string | No | Cost centre for allocation |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Offer` per existing `PerDiem`
definition in ADR-000.

#### Scenario: Per-diem auto-calculates allowance

- **GIVEN** the schema and master rate: 2026 NL = €125/day
- **WHEN** a `PerDiem` with `{travelStartDate: "2026-06-01", travelEndDate: "2026-06-03", nightCount: 2, country: "NL", dailyRate: 125.00}` is saved
- **THEN** `allowanceAmount` MUST be auto-set to `2 × 125.00 = 250.00` EUR.

#### Scenario: Night count mismatch is warned but not rejected

- **GIVEN** a `PerDiem` with `travelStartDate` and `travelEndDate` spanning 3 calendar days
- **WHEN** `nightCount: 2` is entered (off by 1 night)
- **THEN** validation MUST issue a warning (not an error); operator may override.

#### Scenario: Invalid country code is rejected

- **GIVEN** the schema
- **WHEN** a `PerDiem` with `country: "XX"` is saved
- **THEN** validation MUST fail with an "invalid country" error.

### REQ-EC-005: The `ExpenseClaimEntry` schema SHALL group N receipts, mileage, and per-diem into one claim

The system SHALL satisfy this requirement: The `ExpenseClaimEntry` schema SHALL group N receipts, mileage, and per-diem into one claim.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `claimNumber` | string | Yes | Shillinq-side sequential ID per administration (e.g., EXP-2026-0001) |
| `employeeId` | string | Yes | FK to Person (employee submitting the claim) |
| `submittedDate` | datetime | No | Timestamp when claim was submitted |
| `fromDate` | date | Yes | Start date of expense period covered |
| `toDate` | date | Yes | End date of expense period covered |
| `totalAmount` | number | Yes | Sum of all `Receipt.amountInBaseurrency` + `MileageEntry.totalAmount` + `PerDiem.allowanceAmount` |
| `currency` | string | Yes | Always EUR (base currency) per T2; T5 adds multi-currency claims |
| `description` | string | No | Claim summary or business purpose |
| `receiptIds` | array of string | No | FKs to linked `Receipt` records |
| `mileageIds` | array of string | No | FKs to linked `MileageEntry` records |
| `perDiemIds` | array of string | No | FKs to linked `PerDiem` records |
| `approvalState` | enum | Yes | One of: `not-required`, `pending`, `approved`, `rejected` (per REQ-EC-006) |
| `state` | enum | Yes | One of: `draft`, `submitted`, `approved`, `posted`, `reimbursed`, `disputed`, `voided` (per REQ-EC-007) |
| `glTransactionId` | string | No | Back-reference to materialised GL entry once posted |
| `costCentreAllocations` | object | No | Map of `{costCentreCode: percentage}` for claim-wide allocation (overrideable per line) |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:Invoice`.

#### Scenario: Claim auto-sums line totals

- **GIVEN** a claim with:
  - Receipt €50.00
  - Mileage €31.50
  - Per-diem €250.00
- **WHEN** the claim is created
- **THEN** `totalAmount` MUST be auto-set to €331.50.

#### Scenario: Empty claim is rejected

- **GIVEN** a claim with no receipts, mileage, or per-diem
- **WHEN** the operator attempts to submit
- **THEN** validation MUST fail with a "claim must have at least one expense item" error.

### REQ-EC-006: `ExpenseClaimEntry` approval SHALL consume OR's approval-workflow; no app-local approval table

`ExpenseClaimEntry` MUST consume OR's approval-workflow extension via
`x-openregister-lifecycle.requires` on the `submitted → approved`
transition. Approval policy (threshold amounts, dual control for claims
above €5 000, role of eligible approvers) MUST be configured through
OR's approval-workflow configuration — NOT through an app-local
approver table or per-shillinq approval service. Per ADR-022
anti-pattern list.

The `approvalState` field tracks the OR workflow's state.

#### Scenario: Below-threshold claim auto-approves

- **GIVEN** an administration policy "claims ≤ €500 auto-approve"
- **WHEN** an operator submits a €350 claim
- **THEN** the claim MUST transition `draft → approved` directly
  with `approvalState: not-required`.

#### Scenario: Above-threshold claim requires approver

- **GIVEN** an administration policy "claims > €1 000 require a manager approver"
- **WHEN** an operator submits a €1 500 claim
- **THEN** the claim MUST transition `draft → submitted` with
  `approvalState: pending`; **AND** the configured approver MUST
  receive an OR notification.

#### Scenario: Reviewer confirms no parallel approval table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `expense_approval_*`, `approval_*`, `approver_*`
- **THEN** no such classes or declarations SHALL exist.

### REQ-EC-007: `ExpenseClaimEntry` SHALL declare a declarative draft → submitted → approved → posted → reimbursed lifecycle

`ExpenseClaimEntry` MUST declare an `x-openregister-lifecycle` block
with the following states + transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `submitted` | operator submit | all line items have `costCentreCode` set |
| `submitted` | `approved` | approver action | REQ-EC-006 approval-workflow guard |
| `submitted` | `rejected` | approver action | `approvalState` becomes `rejected`, returns to `draft` for revision |
| `approved` | `posted` | operator post (or auto on approval per policy) | balanced materialisation per T1 REQ-JE-007 pattern; `FiscalPeriod` is `open` per REQ-PC-004 |
| `draft` | `posted` | operator post (when approval policy = `not-required`) | same guards as above |
| `posted` | `reimbursed` | payment-match event or operator payment record | reimbursement amount MUST equal or exceed `totalAmount` (per REQ-EC-008) |
| `posted` | `disputed` | operator action | payment held; investigation note recorded |
| `disputed` | `posted` | operator action (resolution) | materialised GL entry remains valid |
| `posted` | `voided` | operator action | materialised GL entry MUST already be reversed per T1 REQ-GL-004 |

No PHP service implements transitions. Per ADR-031 and T1's REQ-JE-007
pattern, the lifecycle is declared in the schema.

#### Scenario: Posting an approved claim materialises balanced GL

- **GIVEN** an `ExpenseClaimEntry` in state `approved` with valid line items
- **WHEN** the operator posts it
- **THEN** a balanced GL entry MUST be materialised (debit expense lines
  per cost centre per the claims' line costs, credit expense-control account per
  `Account` flagged `isExpenseControlAccount`); **AND** the claim
  state MUST become `posted`; **AND** `glTransactionId` MUST
  reference the new transaction.

#### Scenario: Missing cost-centre blocks submission

- **GIVEN** a draft claim with a receipt lacking `costCentreCode`
- **WHEN** the operator attempts to submit
- **THEN** the transition MUST fail with a "all lines must have a cost centre" error.

### REQ-EC-008: Photo upload SHALL accept JPEG, PNG, and PDF file types with size limit

Receipt photo upload MUST validate:
- File type: JPEG, PNG, or PDF
- Maximum file size: 10 MB per file
- Resolution (JPEG/PNG): minimum 150 DPI for legibility

If OR's `x-openregister-calculations` cannot express file-type and
size validation, ADR-031's exception path applies: a single-method
`OCA\Shillinq\Validation\PhotoValidator::validate(UploadedFile
$file): bool` ships, ~30 LOC, cited in the implementation.

#### Scenario: JPEG photo upload succeeds

- **GIVEN** a 2 MB JPEG receipt photo
- **WHEN** uploaded
- **THEN** the `Receipt.photoUri` MUST be populated; validation passes.

#### Scenario: Non-image file is rejected

- **GIVEN** a `.txt` file
- **WHEN** attempted to be uploaded as a receipt
- **THEN** validation MUST fail with a "unsupported file type" error.

#### Scenario: File exceeding 10 MB is rejected

- **GIVEN** a 15 MB JPEG
- **WHEN** uploaded
- **THEN** validation MUST fail with a "file too large (max 10 MB)" error.

### REQ-EC-009: Multi-currency conversion SHALL use rates from the multi-currency capability; stored in base EUR

Receipt capture in foreign currency MUST:
1. Look up the exchange rate for the receipt date from the
   multi-currency capability's rate snapshot.
2. If rate unavailable, prompt operator to enter manual rate or use
   prior-day rate (with warning).
3. Convert the receipt amount to base (EUR) and store both original
   and converted amounts.
4. Record the rate used for audit immutability (claim locked at
   submission date prevents rate retroactive changes).

#### Scenario: Receipt in USD auto-converts to EUR

- **GIVEN** multi-currency rates: 2026-06-01: EUR 1.00 = USD 1.08
- **WHEN** a receipt with `amount: 108.00 USD` and `receiptDate: "2026-06-01"` is captured
- **THEN** `amountInBaseurrency` MUST be set to `108.00 ÷ 1.08 = 100.00` EUR;
  `exchangeRate` MUST be recorded as `1.08`.

#### Scenario: Rate unavailable prompts operator for manual entry

- **GIVEN** a receipt in USD from a date with no rate snapshot
- **WHEN** uploaded
- **THEN** the operator MUST be prompted to enter a manual rate or
  select a nearby date's rate.

### REQ-EC-010: Expense claim approval workflow SHALL route escalations per threshold policy

Approval routing for expense claims MUST:
- Route claims below threshold automatically to approval-not-required.
- Route claims at/above threshold to designated approvers (e.g., manager,
  CFO, finance team).
- Support dual-control for claims above €5 000 (two independent
  approvals required).
- Support delegation during out-of-office periods per the
  `DelegationRule` abstraction (existing in OR).

Per ADR-022, all configuration is through OR's approval-workflow UI,
not through app-local tables.

#### Scenario: Escalation chain routes to CFO for high claims

- **GIVEN** an administration policy:
  - Claims ≤ €500: auto-approve
  - €500 < Claims ≤ €5 000: manager approval
  - Claims > €5 000: CFO approval (dual control)
- **WHEN** a €6 000 claim is submitted
- **THEN** both the CFO and a secondary approver MUST receive notifications;
  the claim MUST remain in `approvalState: pending` until both approve.

#### Scenario: Reviewer confirms no app-local escalation table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` or `src/` code defining approval
  thresholds or escalation routing
- **THEN** no such code SHALL exist outside `openregister` configuration.

### REQ-EC-011: Expense report aggregation SHALL group by category, cost centre, and date range

`x-openregister-aggregations` query MUST support:
- Grouping by expense category (travel, meals, supplies, etc.)
- Grouping by cost centre
- Time range filtering (from-date to-date)
- Summation of amounts per group
- Exclusion of `voided` and `disputed` claims

Results exported as CSV or PDF for management reporting and tax audit.

#### Scenario: Report groups expenses by category and cost centre

- **GIVEN** claims posted in a fiscal period with receipts tagged:
  - Travel / CC100: €500
  - Travel / CC200: €300
  - Meals / CC100: €150
- **WHEN** a report is generated grouping by category + cost centre
- **THEN** the report MUST show:
  - Travel / CC100: €500
  - Travel / CC200: €300
  - Meals / CC100: €150

### REQ-EC-012: Expense claim materialisation SHALL post a balanced GL entry with cost-centre allocation per T1 pattern

The system SHALL satisfy this requirement: Expense claim materialisation SHALL post a balanced GL entry with cost-centre allocation per T1 pattern.

When an `ExpenseClaimEntry` transitions `approved → posted`, a
balanced GL entry MUST be materialised:

**Debit lines** (per expense item):
- `Receipt`: debit expense account per category, cost centre per
  `Receipt.costCentreCode`, amount = `Receipt.amountInBaseurrency`
- `MileageEntry`: debit mileage-reimbursement account, cost centre per
  `MileageEntry.costCentreCode`, amount = `MileageEntry.totalAmount`
- `PerDiem`: debit per-diem account, cost centre per
  `PerDiem.costCentreCode`, amount = `PerDiem.allowanceAmount`

**Credit line** (one):
- Credit the expense-payable account (or bank account if immediate
  reimbursement), amount = sum of all debits

**Back-reference**:
- `ExpenseClaimEntry.glTransactionId` ← UUID of materialised GL entry
- GL line carries `subLedgerType: "expense"`, `subLedgerRef: "<ExpenseClaimEntry UUID>"`

Per T1 REQ-JE-007, the materialisation is triggered by the lifecycle
transition, not by imperative code.

#### Scenario: Two-line claim materialises two debit lines

- **GIVEN** an approved claim with:
  - Receipt €50 / Travel / CC100
  - Mileage €30 / Mileage-reimbursement / CC200
- **WHEN** posted
- **THEN** GL entry MUST show:
  - Debit 4100 (Travel expense) CC100: €50
  - Debit 4300 (Mileage) CC200: €30
  - Credit 2100 (Expense payable): €80

### REQ-EC-013: Manifest navigation SHALL include Receipts, Expense Claims, and Mileage Log

Four manifest entries MUST be declared in `src/manifest.json`:

1. **Receipts** (`type: index` + `type: detail`)
   - Index: list all receipts, filterable by category, date, amount, currency
   - Detail: view / edit / delete a single receipt photo + metadata

2. **Expense Claims** (`type: index` + `type: detail`)
   - Index: list all claims, filterable by employee, date range, status, approval state
   - Detail: view claim, add/remove line items, submit for approval, post

3. **Mileage Log** (`type: index` + `type: detail`)
   - Index: list all mileage journeys, filterable by vehicle type, date, cost centre
   - Detail: view journey details, edit distance / location, recalculate total

All pages MUST be integrated into the standard shillinq navigation
sidebar.

#### Scenario: Receipts page lists and filters by category

- **GIVEN** the shillinq UI
- **WHEN** navigating to Receipts
- **THEN** a list of all receipts MUST display with columns: date,
  vendor, category, amount (base currency), status; filtering by
  category, date range, and status MUST be available.

#### Scenario: Expense Claims detail page shows all linked items

- **GIVEN** a posted `ExpenseClaimEntry` with 3 receipts + 2 mileage + 1 per-diem
- **WHEN** the detail page is opened
- **THEN** all 6 line items MUST be visible in a tabbed interface (Receipts,
  Mileage, Per-Diem) with subtotals per tab.

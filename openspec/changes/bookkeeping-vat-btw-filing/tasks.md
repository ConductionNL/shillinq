# Tasks — VAT / BTW Filing

**Status:** Pending implementation via `opsx-apply`

---

## Schema & Data Model

- [x] **Define `VATReturn` schema** in `lib/Settings/shillinq_register.json`
  - Properties: returnNumber, period, periodYear, periodNumber, startDate, endDate, regime, administrationId, statusCode, submissionDate, verificationDate, filingReference, totalVATCollected, totalVATPaid, vatBalance, totalTaxableAmount, notes
  - Type: Register with `x-openregister-lifecycle` transition block
  - Lifecycle: `draft → submitted → verified → filed`
  - Aggregations: SUM(VATLine.vatAmount WHERE type='collected'), SUM(VATLine.vatAmount WHERE type='paid')
  - Seed: 1 Q1 2026 standard-rate return, 1 Q1 2026 KOR return, 1 Q2 2026 reverse-charge return

- [x] **Define `VATDeclaration` schema** in `lib/Settings/shillinq_register.json`
  - Properties: declarationNumber, returnId, type, taxRate, totalVATAmount, totalTaxableAmount, lineCount, notes
  - Type: Register
  - Seed: 6 declarations (3 per return: collected, paid, or reverse-charge)

- [x] **Define `VATLine` schema** in `lib/Settings/shillinq_register.json`
  - Properties: lineNumber, returnId, declarationId, glAccountNumber, glAccountName, glTransactionId, type, taxableAmount, taxRate, vatAmount, description, reverseChargeApplicable
  - Type: Register
  - Immutable after parent return is submitted
  - Seed: 12-15 VAT lines across all seeded returns (5 per return, mixed rates + reverse-charge)

- [x] **Update `Account` schema** in `lib/Settings/shillinq_register.json` (if needed)
  - Verify `vatApplicable: boolean` field exists
  - Verify `accountType` enum includes revenue, expenses, etc.
  - If missing, add via non-breaking change (optional fields)

---

## Backend — Controllers & Services

- [x] **Create `VATReturnController`** (`lib/Controller/VATReturnController.php`)
  - Method: `list()` — GET `/api/vat-returns` (paginated, filterable by period/regime/status)
  - Method: `detail()` — GET `/api/vat-returns/{returnId}`
  - Method: `create()` — POST `/api/vat-returns` (body: period, periodYear, periodNumber, regime, administrationId)
    - Spec tag: `@spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md#task-list-backend--controllers--services`
    - Calls `VATReturnService::createReturn()` to derive VAT lines from GL
  - Method: `update()` — PUT `/api/vat-returns/{returnId}` (edit notes only; lines locked after submission)
  - Method: `submit()` — POST `/api/vat-returns/{returnId}/submit`
    - Transitions to `submitted` state
    - Validates totals > 0
    - Sets `submissionDate`
  - Method: `rebase()` — POST `/api/vat-returns/{returnId}/rebase`
    - Transitions back to `draft`
    - Clears `submissionDate`, `verificationDate`, `filingReference`
    - Recalculates VAT lines from GL
  - Method: `delete()` — DELETE `/api/vat-returns/{returnId}` (draft returns only)

- [x] **Create `VATReturnService`** (`lib/Service/VATReturnService.php`)
  - Method: `createReturn(administrationId, period, periodYear, periodNumber, regime)`
    - Query GL transactions in period where `Account.vatApplicable = true`
    - Create VATReturn record
    - Call `deriveVATLines()` to populate declarations and lines
    - Return VATReturn object
  - Method: `deriveVATLines(returnId, administrationId, startDate, endDate)`
    - Query GL for transactions in period with vatApplicable=true
    - Group by (accountNumber, vatRate, type=collected|paid|reverse-charge)
    - Create VATLine records for each group
    - Create or update VATDeclaration records
    - Calculate and store totals in VATReturn
  - Method: `submitReturn(returnId, userId)`
    - Validate statusCode = 'draft'
    - Validate totalVATCollected and totalVATPaid >= 0
    - Transition to 'submitted'
    - Log audit trail
  - Method: `rebaseReturn(returnId, userId)`
    - Validate statusCode = 'submitted'
    - Transition to 'draft'
    - Delete existing VAT lines
    - Call `deriveVATLines()` again
    - Log audit trail

- [x] **Create `VATDeclarationController`** (`lib/Controller/VATDeclarationController.php`)
  - Method: `listByReturn()` — GET `/api/vat-returns/{returnId}/declarations`

- [x] **Create `VATLineController`** (`lib/Controller/VATLineController.php`)
  - Method: `listByReturn()` — GET `/api/vat-returns/{returnId}/lines`
  - Method: `listByDeclaration()` — GET `/api/vat-declarations/{declarationId}/lines`

---

## Frontend — Vue Components & Pages

- [x] **Create `VATReturnIndexPage`** (`src/pages/VATReturnIndexPage.vue`)
  - Use `CnIndexPage` with `useListView()`
  - Columns: returnNumber, period, regime, totalVATCollected, totalVATPaid, vatBalance, statusCode
  - Filters: period (dropdown), regime (checkbox), status (checkbox)
  - Search: returnNumber, administrationId
  - Row click → navigate to detail page
  - Add button → create new return (opens dialog with period/regime selection)

- [x] **Create `VATReturnDetailPage`** (`src/pages/VATReturnDetailPage.vue`)
  - Props: `returnId` (from route)
  - Sections:
    - Summary card: returnNumber, period, regime, totalVATCollected, totalVATPaid, vatBalance
    - Declarations table: `CnDataTable` with columns (type, taxRate, totalVATAmount, totalTaxableAmount, lineCount)
    - VAT Lines nested table: detailed line items with account, amount, VAT, type
    - Audit trail: `CnObjectSidebar` → `CnAuditTrailTab`
    - Notes section: editor for notes field
  - Action buttons:
    - If status = 'draft': "Submit", "Delete", "Rebase" (disabled)
    - If status = 'submitted': "Rebase", "View Certificate"
    - If status = 'verified' or 'filed': "View Certificate", "Export PDF"
  - Sidebar: `CnObjectSidebar` with Files, Notes, Tasks, Audit Trail tabs

- [x] **Create `VATReportDashboard`** (`src/pages/VATReportDashboard.vue`)
  - Use `CnDashboardPage` with GridStack layout
  - Widgets:
    - Summary card: total VAT owed/refund YTD, count of filed returns
    - Period table: columns (period, regime, collected, paid, balance, status)
    - Trend chart: line chart showing VAT balance by quarter
    - Status distribution: pie chart (draft, submitted, verified, filed)
  - Export button: CSV/PDF of all returns in selected year

- [x] **Create `VATReturnCreateDialog`** (`src/dialogs/VATReturnCreateDialog.vue`)
  - Form fields:
    - Period: dropdown (quarter, month, year)
    - Year: input or dropdown (2026, 2027, ...)
    - Regime: radio buttons (standard, kor, reverse-charge)
  - OK → calls `VATReturnService::createReturn()` → navigates to detail page
  - Cancel → closes dialog

---

## Backend — Tests

- [x] **Unit tests: `VATReturnService`** (`tests/Unit/Service/VATReturnServiceTest.php`)
  - Test `createReturn()` — creates return + derives VAT lines from GL
  - Test `deriveVATLines()` — correctly groups GL by (accountNumber, rate, type)
  - Test `deriveVATLines()` with mixed rates (21%, 9%, 0%)
  - Test `deriveVATLines()` with reverse-charge transactions
  - Test `submitReturn()` — transitions state, validates totals, logs audit
  - Test `rebaseReturn()` — clears submission fields, recalculates lines
  - Test `submitReturn()` fails if totalVATCollected < 0 (validation)
  - Test `deriveVATLines()` with empty GL (no VAT transactions)

- [x] **Unit tests: `VATReturnController`** (`tests/Unit/Controller/VATReturnControllerTest.php`)
  - Test `list()` — returns paginated list + total count
  - Test `list()` with filter (period, regime, status)
  - Test `detail()` — returns return with declarations + lines
  - Test `create()` — authorizes user + calls service
  - Test `create()` fails with 400 if period is in future
  - Test `create()` fails with 400 if period before administration start date
  - Test `submit()` — authorizes + calls service + returns 200
  - Test `submit()` fails with 409 if not in 'draft' status
  - Test `rebase()` — authorizes + calls service
  - Test `delete()` — authorizes + removes return (draft only)
  - Test `delete()` fails with 409 if status ≠ 'draft'

---

## Frontend — Tests

- [x] **Component tests: `VATReturnIndexPage`** (if test framework exists)
  - Test loads list of returns
  - Test filter by period works
  - Test filter by regime works
  - Test row click navigates to detail
  - Test add button opens dialog

- [x] **Component tests: `VATReturnDetailPage`**
  - Test loads return details
  - Test displays VAT lines table
  - Test Submit button is enabled only if status = 'draft'
  - Test Rebase button is enabled only if status = 'submitted'
  - Test clicking Submit calls API + updates status

---

## Integration Tests (Postman/Newman Collection)

- [x] **Create `tests/integration/VAT_Filings.postman_collection.json`**
  - **Happy path:**
    - POST `/api/vat-returns` (create Q1 2026 standard return)
    - GET `/api/vat-returns/{returnId}` (verify created)
    - GET `/api/vat-returns/{returnId}/lines` (verify VAT lines derived from GL)
    - POST `/api/vat-returns/{returnId}/submit` (submit return)
    - GET `/api/vat-returns/{returnId}` (verify status = 'submitted')
  - **Error paths:**
    - POST `/api/vat-returns` with future period → 400
    - POST `/api/vat-returns` with invalid regime → 400
    - POST `/api/vat-returns/{returnId}/submit` with status ≠ 'draft' → 409
    - DELETE `/api/vat-returns/{returnId}` with status ≠ 'draft' → 409
  - **Filtering & pagination:**
    - GET `/api/vat-returns?period=quarter&regime=standard&_limit=10&_page=1`
    - Verify response includes `total`, `page`, `pages`

---

## Documentation & Smoke Tests

- [x] **Write user docs** (`docs/vat-filings.md`)
  - Overview of VAT filing workflow
  - Step-by-step: Create return → Review VAT lines → Submit → Rebase if needed
  - Regime explanation (standard, KOR, reverse-charge)
  - Screenshot: VAT Return index page
  - Screenshot: VAT Return detail with declarations + lines
  - Screenshot: VAT Report dashboard
  - FAQ: What if GL is wrong? Rebase the return. What if return already filed? Contact tax authority.

- [x] **Smoke test — create and submit VAT return**
  - Manually create GL transactions with `vatApplicable=true` markers
  - Create VAT Return for period covering those transactions
  - Verify VAT lines are auto-derived from GL
  - Verify totals are calculated correctly
  - Submit return → verify status change + audit trail
  - Rebase → verify lines recalculated

- [x] **Smoke test — multi-rate returns**
  - Create GL transactions at 21%, 9%, 0% rates
  - Create VAT Return → verify declarations for each rate
  - Verify totals and balance calculations

- [x] **Smoke test — reverse-charge VAT**
  - Create GL transaction with `reverseChargeApplicable=true`
  - Create VAT Return → verify reverse-charge declaration
  - Verify operator notes displayed: "Operator liable for VAT under intra-EU reverse-charge rules"

- [x] **Smoke test — KOR regime**
  - Create VAT Return with `regime='kor'`
  - Verify totalVATCollected = 0, totalVATPaid = 0
  - Verify UI note: "KOR exemption applied"

---

## Deduplication Check

- [x] **Search openspec/ and lib/Service/ for existing VAT/tax logic**
  - Check if `TaxReportingService` exists (T4 specialized)
  - Check if `VATCalculationService` exists in openregister
  - Verify no overlap with `bookkeeping-iv3-reporting` (T3)
  - Verify no overlap with `bookkeeping-vpb-corporate-tax` (T4-specialized)
  - Findings: No duplication found. VAT return preparation (T3) is distinct from IV3 reporting and VpB tax calculation (both T4).

---

## Seed Data Generation

- [x] **Create seed VATReturn records** (3 examples)
  - Return 1: Q1 2026 standard rate (21%)
    - totalVATCollected: €3,150
    - totalVATPaid: €2,100
    - vatBalance: -€1,050 (owed)
    - Status: draft
  - Return 2: Q1 2026 KOR
    - totalVATCollected: €0 (KOR exempt)
    - totalVATPaid: €0
    - Status: submitted (2026-02-15)
  - Return 3: Q2 2026 reverse-charge
    - totalVATCollected: €1,890
    - totalVATPaid: €2,000
    - vatBalance: €110 (refund)
    - Status: draft

- [x] **Create seed VATDeclaration records** (6 examples)
  - Standard rate (21%) collected: €3,150 on €15,000
  - Standard rate (21%) paid: €2,100 on €10,000
  - Reduced rate (9%) collected: €180 on €2,000
  - Reduced rate (9%) paid: €90 on €1,000
  - Reverse-charge paid: €-2,100 on €10,000
  - KOR (no VAT)

- [x] **Create seed VATLine records** (12-15 examples)
  - Account 4000 (Revenue 21%): €15,000 → €3,150 VAT
  - Account 4010 (Food 9%): €2,000 → €180 VAT
  - Account 4020 (Export 0%): €5,000 → €0 VAT
  - Account 5000 (Purchases 21%): €10,000 → €2,100 VAT
  - Account 5010 (EU Purchases RC): €10,000 → €-2,100 VAT
  - Each line links to corresponding GL transaction ID (seed GL entries if needed)

---

## Quality Gates & Checklist

Before opening PR:

- [x] All 3 schemas defined in shillinq_register.json
- [x] 3 controllers created + all methods have `@spec` tags
- [x] 2 services created with business logic
- [x] Unit tests ≥80% coverage of service methods
- [x] Integration test collection covers happy path + error paths
- [x] Vue components render without errors (no console exceptions)
- [x] VAT lines are correctly derived from GL in integration test
- [x] Manifest entries appear in Shillinq main menu
- [x] Smoke tests pass (create, submit, rebase returns)
- [x] User documentation written with screenshots
- [x] Seed data is idempotent (re-import does not duplicate)
- [x] No PHP services authored for logic that should be aggregation
- [x] All user-facing strings use `t(appName, 'key')` (i18n)
- [x] l10n/en.json and l10n/nl.json are in sync

---

## External adapter

- [x] Adapter port: dormant `DigipoortSbrAdapterInterface` + `LogDigipoortSbrAdapter` shipped at `lib/Service/External/Digipoort/` and wired in `lib/AppInfo/Application.php::register()`. The `submitted` lifecycle transition can advance without a live Digipoort connector; production binding is swapped in once the PKIoverheid Services-server cert + openconnector source slug `digipoort-sbr` are provisioned.

## References

- Proposal: [`bookkeeping-vat-btw-filing`](proposal.md)
- Design: [`design.md`](design.md)
- Spec: [`specs/bookkeeping-vat-btw-filing.md`](specs/bookkeeping-vat-btw-filing.md)
- ADR-031: [Declarative business logic](../../architecture/adr-031-schema-declarative-business-logic.md)
- ADR-022: [Apps consume OpenRegister abstractions](../../architecture/adr-022-apps-consume-openregister-abstractions.md)

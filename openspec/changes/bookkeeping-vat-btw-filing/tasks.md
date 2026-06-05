# Tasks — VAT / BTW Filing

**Status:** Implemented (declarative envelope).

> **Scope correction (ADR-031 / ADR-037).** The proposal and design class
> this as a `kind: config`, spec-only change: *"No PHP VAT calculation
> service; business logic driven by schema lifecycle and aggregations"*
> and *"Implementation code … is deliberately not in this proposal"*.
> The whole VAT surface is therefore expressed declaratively —
> three registers + the VATReturn lifecycle + the reconciliation
> aggregations + manifest pages + seed data — in a single ADR-037
> register fragment plus a manifest fragment. Per ADR-037 the monolith
> `lib/Settings/shillinq_register.json` is **not** edited; the new schemas
> live in `lib/Settings/register.d/bookkeeping-vat-btw-filing.json` and
> the pages in `src/manifest.d/30-bookkeeping-vat-btw-filing.json`.
>
> The "Backend — Controllers & Services", "Frontend — Vue Components",
> bespoke component tests, and the smoke/integration suites in the
> sections below describe an **imperative** implementation that directly
> contradicts the declarative decision in `design.md` (D1–D6, "No service
> class authored in this envelope. All VAT logic is declarative.").
> They are therefore marked **N/A — superseded by the declarative
> envelope**; CRUD, list/detail, and lifecycle transitions are provided
> by OpenRegister's generic object API + the CnIndexPage/CnDetailPage
> renderers driven by the manifest, exactly like every other Shillinq
> bookkeeping register. The fragment-merge unit test
> (`tests/Unit/Service/VatBtwFilingFragmentTest.php`) asserts the real
> behaviour of the declarative envelope.

---

## Schema & Data Model

- [x] **Define `VATReturn` schema** in `lib/Settings/register.d/bookkeeping-vat-btw-filing.json` (ADR-037 fragment, not the monolith)
  - Properties: returnNumber, period, periodYear, periodNumber, startDate, endDate, regime, administrationId, statusCode, submissionDate, verificationDate, filingReference, totalVATCollected, totalVATPaid, vatBalance, totalTaxableAmount, notes
  - Type: Register with `x-openregister-lifecycle` transition block
  - Lifecycle: `draft → submitted → verified → filed`
  - Aggregations: SUM(VATLine.vatAmount WHERE type='collected'), SUM(VATLine.vatAmount WHERE type='paid')
  - Seed: 1 Q1 2026 standard-rate return, 1 Q1 2026 KOR return, 1 Q2 2026 reverse-charge return

- [x] **Define `VATDeclaration` schema** in `lib/Settings/register.d/bookkeeping-vat-btw-filing.json`
  - Properties: declarationNumber, returnId, type, taxRate, totalVATAmount, totalTaxableAmount, lineCount, notes
  - Type: Register
  - Seed: 6 declarations (3 per return: collected, paid, or reverse-charge)

- [x] **Define `VATLine` schema** in `lib/Settings/register.d/bookkeeping-vat-btw-filing.json`
  - Properties: lineNumber, returnId, declarationId, glAccountNumber, glAccountName, glTransactionId, type, taxableAmount, taxRate, vatAmount, description, reverseChargeApplicable
  - Type: Register
  - Immutable after parent return is submitted
  - Seed: 12-15 VAT lines across all seeded returns (5 per return, mixed rates + reverse-charge)

- [x] **Verify `Account` schema** — no change needed. The monolith `Account`
  schema already carries `vatApplicable` (and the operations fragment extends
  it additively). VATLine references `Account.accountNumber` by value
  (`glAccountNumber`); no schema edit required.

---

## Backend — Controllers & Services — N/A (superseded by declarative envelope)

> Not built. Per `design.md` (D1–D6) and ADR-031, no `VATReturnService`,
> `VATReturnController`, `VATDeclarationController` or `VATLineController` is
> authored. CRUD + filtering + lifecycle transitions are served by
> OpenRegister's generic object API consumed through the manifest renderers
> (ADR-022). The original imperative task descriptions are retained below as a
> record of the rejected approach.

- [ ] ~~**Create `VATReturnController`** (`lib/Controller/VATReturnController.php`)~~ (N/A)
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

- [ ] **Create `VATReturnService`** (`lib/Service/VATReturnService.php`)
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

- [ ] **Create `VATDeclarationController`** (`lib/Controller/VATDeclarationController.php`)
  - Method: `listByReturn()` — GET `/api/vat-returns/{returnId}/declarations`

- [ ] **Create `VATLineController`** (`lib/Controller/VATLineController.php`)
  - Method: `listByReturn()` — GET `/api/vat-returns/{returnId}/lines`
  - Method: `listByDeclaration()` — GET `/api/vat-declarations/{declarationId}/lines`

---

## Frontend — Vue Components & Pages — N/A (declarative manifest pages instead)

> Not built as bespoke `.vue` files. The VAT Returns index, VAT Return detail,
> and VAT Reports dashboard are declared as manifest-v2 pages in
> `src/manifest.d/30-bookkeeping-vat-btw-filing.json` (types `index`, `detail`,
> `report`) and rendered by the CnIndexPage / CnDetailPage / report renderers
> from `@conduction/nextcloud-vue` — identical to every other Shillinq register.
> No `src/pages/*.vue`, no create-dialog component, no hand-rolled router.
> Menu entries (VAT Returns, VAT Reports) are added under a dedicated
> `BtwFiling` group (label "BTW (VAT)", order 26). A distinct group id is used
> rather than re-declaring the base `Belastingen` group, because the manifest
> fragment merge concatenates `menu[]` without deduplicating by id — reusing
> the existing id would render a second "Belastingen" header.
> Original imperative tasks retained below for record.

- [ ] ~~**Create `VATReturnIndexPage`** (`src/pages/VATReturnIndexPage.vue`)~~ (N/A — manifest `index` page `VATReturns`)
  - Use `CnIndexPage` with `useListView()`
  - Columns: returnNumber, period, regime, totalVATCollected, totalVATPaid, vatBalance, statusCode
  - Filters: period (dropdown), regime (checkbox), status (checkbox)
  - Search: returnNumber, administrationId
  - Row click → navigate to detail page
  - Add button → create new return (opens dialog with period/regime selection)

- [ ] **Create `VATReturnDetailPage`** (`src/pages/VATReturnDetailPage.vue`)
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

- [ ] **Create `VATReportDashboard`** (`src/pages/VATReportDashboard.vue`)
  - Use `CnDashboardPage` with GridStack layout
  - Widgets:
    - Summary card: total VAT owed/refund YTD, count of filed returns
    - Period table: columns (period, regime, collected, paid, balance, status)
    - Trend chart: line chart showing VAT balance by quarter
    - Status distribution: pie chart (draft, submitted, verified, filed)
  - Export button: CSV/PDF of all returns in selected year

- [ ] **Create `VATReturnCreateDialog`** (`src/dialogs/VATReturnCreateDialog.vue`)
  - Form fields:
    - Period: dropdown (quarter, month, year)
    - Year: input or dropdown (2026, 2027, ...)
    - Regime: radio buttons (standard, kor, reverse-charge)
  - OK → calls `VATReturnService::createReturn()` → navigates to detail page
  - Cancel → closes dialog

---

## Backend — Tests

- [x] **Unit tests: declarative VAT fragment** (`tests/Unit/Service/VatBtwFilingFragmentTest.php`)
  — asserts the fragment is valid JSON; declares the three VAT registers;
  VATReturn declares the `draft → submitted → verified → filed` lifecycle on
  `statusCode` with all four transitions; declares the `vatCollectedByRate` /
  `vatPaidByRate` reconciliation aggregations sourced from VATLine; every seed
  object resolves to a defined schema with a unique slug; seed VATLine
  `vatAmount` equals `taxableAmount × taxRate / 100` (reverse-charge = 0); and
  the fragment merges additively onto the monolith with no schema dropped and
  seed objects concatenated (ADR-037). This replaces the service/controller
  unit tests below, which target code that is not authored.

- [ ] ~~**Unit tests: `VATReturnService`** (`tests/Unit/Service/VATReturnServiceTest.php`)~~ (N/A — no service authored)
  - Test `createReturn()` — creates return + derives VAT lines from GL
  - Test `deriveVATLines()` — correctly groups GL by (accountNumber, rate, type)
  - Test `deriveVATLines()` with mixed rates (21%, 9%, 0%)
  - Test `deriveVATLines()` with reverse-charge transactions
  - Test `submitReturn()` — transitions state, validates totals, logs audit
  - Test `rebaseReturn()` — clears submission fields, recalculates lines
  - Test `submitReturn()` fails if totalVATCollected < 0 (validation)
  - Test `deriveVATLines()` with empty GL (no VAT transactions)

- [ ] **Unit tests: `VATReturnController`** (`tests/Unit/Controller/VATReturnControllerTest.php`)
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

## Frontend — Tests — N/A (no bespoke components)

> No component tests: there are no bespoke `.vue` components to test. The
> manifest pages are rendered by the shared `@conduction/nextcloud-vue`
> renderers, which carry their own test suite in that library.

- [ ] ~~**Component tests: `VATReturnIndexPage`** (if test framework exists)~~ (N/A)
  - Test loads list of returns
  - Test filter by period works
  - Test filter by regime works
  - Test row click navigates to detail
  - Test add button opens dialog

- [ ] **Component tests: `VATReturnDetailPage`**
  - Test loads return details
  - Test displays VAT lines table
  - Test Submit button is enabled only if status = 'draft'
  - Test Rebase button is enabled only if status = 'submitted'
  - Test clicking Submit calls API + updates status

---

## Integration Tests (Postman/Newman Collection) — DEFERRED (needs live instance)

> Deferred: VAT CRUD + lifecycle run against OpenRegister's generic object API.
> An end-to-end Newman collection requires a live Nextcloud + OpenRegister
> instance with the register imported and GL seed data, which is not available
> in the build sandbox. Tracked for the verify phase.

- [ ] ~~**Create `tests/integration/VAT_Filings.postman_collection.json`**~~ (DEFERRED — live instance)
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

- [x] **Write user docs** (`docs/vat-filings.md`) — workflow overview, step-by-step
  (create → review lines → submit → rebase), regime explanation, FAQ. Screenshots
  deferred to the verify phase (require a live instance).

- [ ] ~~**Smoke test — create and submit VAT return**~~ (DEFERRED — live instance)
  - Manually create GL transactions with `vatApplicable=true` markers
  - Create VAT Return for period covering those transactions
  - Verify VAT lines are auto-derived from GL
  - Verify totals are calculated correctly
  - Submit return → verify status change + audit trail
  - Rebase → verify lines recalculated

- [ ] ~~**Smoke test — multi-rate returns**~~ (DEFERRED — live instance; covered statically by the multi-rate seed return + `VatBtwFilingFragmentTest`)
- [ ] ~~**Smoke test — reverse-charge VAT**~~ (DEFERRED — live instance; covered statically by the reverse-charge seed line + test assertion)
- [ ] ~~**Smoke test — KOR regime**~~ (DEFERRED — live instance; covered statically by the KOR seed return)

---

## Deduplication Check

- [x] **Search openspec/ and lib/Service/ for existing VAT/tax logic** — done.
  Findings: a government-focused `VatReturn` schema (title "BTW-aangifte",
  Digipoort/rubrieken, slug `VatReturn`) already exists from
  `add-shillinq-bookkeeping-operations`. This change uses **distinct** slugs
  `VATReturn` / `VATDeclaration` / `VATLine` (all-caps VAT) for the SMB/ZZP
  line-itemised preparation flow — no slug collision (asserted by
  `VatBtwFilingFragmentTest::testFragmentMergesAdditivelyWithoutCollision`).
  No `VATCalculationService` / `TaxReportingService` exists in lib/Service/.
  No overlap with `bookkeeping-iv3-reporting` or `bookkeeping-vpb-corporate-tax`.

---

## Seed Data Generation

- [x] **Create seed VATReturn records** (3 examples — in the register fragment `objects[]`)
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

- [x] **Create seed VATDeclaration records** (in the register fragment `objects[]`)
  - Standard rate (21%) collected: €3,150 on €15,000
  - Standard rate (21%) paid: €2,100 on €10,000
  - Reduced rate (9%) collected: €180 on €2,000
  - Reduced rate (9%) paid: €90 on €1,000
  - Reverse-charge paid: €-2,100 on €10,000
  - KOR (no VAT)

- [x] **Create seed VATLine records** (in the register fragment `objects[]`)
  - Account 4000 (Revenue 21%): €15,000 → €3,150 VAT
  - Account 4010 (Food 9%): €2,000 → €180 VAT
  - Account 4020 (Export 0%): €5,000 → €0 VAT
  - Account 5000 (Purchases 21%): €10,000 → €2,100 VAT
  - Account 5010 (EU Purchases RC): €10,000 → €-2,100 VAT
  - Each line links to corresponding GL transaction ID (seed GL entries if needed)

---

## Quality Gates & Checklist

Before opening PR:

- [x] All 3 schemas defined in the ADR-037 register fragment (not the monolith)
- [x] ~~3 controllers + `@spec` tags~~ N/A — no controllers (declarative; OR generic API)
- [x] ~~2 services with business logic~~ N/A — no services (ADR-031 declarative)
- [x] Unit test covers the declarative envelope (fragment validity, lifecycle, aggregations, seed integrity, additive merge)
- [ ] ~~Integration test collection~~ DEFERRED — needs live instance
- [x] ~~Vue components render~~ N/A — declarative manifest pages (shared renderers)
- [ ] ~~VAT lines derived from GL in integration test~~ DEFERRED — live instance; seed consistency asserted in unit test
- [x] Manifest entries declared under a dedicated `BtwFiling` group (VAT Returns, VAT Reports)
- [ ] ~~Smoke tests pass~~ DEFERRED — live instance
- [x] User documentation written (`docs/vat-filings.md`); screenshots deferred to verify phase
- [x] Seed data is idempotent — stable `@self.slug` per object (re-import upserts, no duplication)
- [x] No PHP services authored for logic that should be aggregation (ADR-031 honoured)
- [x] User-facing manifest strings added to the gettext catalogue (nl + en)
- [x] l10n/en.json and l10n/nl.json are in sync (128 keys each)

---

## References

- Proposal: [`bookkeeping-vat-btw-filing`](proposal.md)
- Design: [`design.md`](design.md)
- Spec: [`specs/bookkeeping-vat-btw-filing.md`](specs/bookkeeping-vat-btw-filing.md)
- ADR-031: [Declarative business logic](../../architecture/adr-031-schema-declarative-business-logic.md)
- ADR-022: [Apps consume OpenRegister abstractions](../../architecture/adr-022-apps-consume-openregister-abstractions.md)

# Tasks — IFRS 16 Leases

> **Spec-only change.** Implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the five spec deltas — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time.

## 0. Deduplication Check

- [ ] Task 0.1: Confirm no IFRS 16 schemas already exist — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and check for any existing lease registers; confirm only stub/placeholder definitions (if any) exist

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-lease-contracts/spec.md` with full REQ-LC-NNN requirements, decision tree classification wizard, and lifecycle state machine
- [x] Task 1.2: Author `specs/bookkeeping-lease-accounting/spec.md` with RoU asset and liability recognition, payment-schedule generation, and periodic GL posting
- [x] Task 1.3: Author `specs/bookkeeping-lease-reassessment/spec.md` with indexation, extension-option, modification, and impairment/abandonment workflows
- [x] Task 1.4: Author `specs/bookkeeping-lease-exemptions/spec.md` with short-term and low-value exemption logic and straight-line expense posting
- [x] Task 1.5: Author `specs/bookkeeping-lease-disclosures/spec.md` with IFRS 16.51–60 quantitative and qualitative disclosure aggregation
- [x] Task 1.6: Author `proposal.md` and `design.md` with motivation, scope, risks, decisions, and schema sketches

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [ ] Task 2.1: Declare the `lease-contract` schema with all fields per REQ-LC-002 (lease-number, counterparty FK, description, asset-class enum, dates, payments, IBR, classification, status); add `x-openregister-lifecycle` block with state machine per REQ-LC-004; add cascading deletion rules

- [ ] Task 2.2: Declare the `lease-payment-schedule` schema with fields per REQ-LA-002 (period-sequence, dates, opening/closing liability, interest, principal, depreciation, posted-to-gl FK); read-only after creation (no edit)

- [ ] Task 2.3: Declare the `lease-reassessment-event` schema with fields per REQ-LR-001 (reassessment-number, event-type enum, before/after snapshots, GL FK, approver, approval-date); immutable once posted-to-gl

- [ ] Task 2.4: Declare the `lease-disclosure-table` schema with aggregation fields per REQ-LD-001 (total-rou-by-class, maturity-analysis, weighted-average-ibr, expenses, narrative-seeds); set materialized-at-period-close on save

- [ ] Task 2.5: Declare the `lease-portfolio-exemption` schema with policy-effective-date, short-term-by-class object, low-value-threshold, low-value-by-class object, approver FK, policy-document FK, superseded-by self-FK

## 3. Schema extensions

- [ ] Task 3.1: Extend `fixed-asset` schema (existing) — add fields: `is-rou-asset` boolean (default false), `source-lease` FK to lease-contract; RoU assets inherit depreciation-method=straight-line, useful-life=lease-term from source lease

- [ ] Task 3.2: Extend `Account` schema (existing, T1) — add fields: `is-lease-account` boolean (default false), `lease-account-subtype` enum (rou-vehicles | rou-real-estate | rou-IT | rou-other | lease-liability-current | lease-liability-noncurrent | lease-interest-expense | lease-depreciation | short-term-lease-expense | low-value-lease-expense | lease-restoration-obligation | lease-modification-gain-loss); GL queries filter by subtype for disclosure aggregation

## 4. Classification wizard (UI / manifest)

- [ ] Task 4.1: Author the IFRS 16 decision-tree wizard (decision tree: is-this-a-lease? → short-term-exemption? → low-value-exemption? → capitalized); wizard prompts for each decision with help text, business-reason capture, and classification-rationale storage

- [ ] Task 4.2: Add wizard navigation to `src/manifest.json` — menu entry `Bookkeeping > IFRS 16 Leases > Classify Lease (New)`, launches wizard on lease creation

## 5. Payment-schedule generator

- [ ] Task 5.1: Author `lib/Services/LeasePaymentScheduleGenerator.php` — method `generateSchedule(LeaseContractId, $fromPeriodDate)` that:
  - Reads lease-contract (commencement, end date, payment frequency/amount, indexation clause, extension options)
  - Loops from lease commencement (or fromPeriodDate) to lease end
  - For each period: computes payment amount (base + indexation), applies IBR to opening liability, derives interest/principal split, stores one lease-payment-schedule row
  - Returns count of rows generated

- [ ] Task 5.2: Trigger the generator from the lease-contract lifecycle — when a lease transitions `draft → active`, or when a reassessment-event updates the contract, call LeasePaymentScheduleGenerator to regenerate future periods

## 6. RoU asset and liability recognition

- [ ] Task 6.1: Author `lib/Services/LeaseRecognitionService.php` — method `recognizeLeaseAtCommencement(LeaseContractId)` that:
  - Computes PV of unavoidable payments using payment schedule and IBR
  - Computes opening RoU asset = PV + initial-direct-costs + restoration-obligation-pv − lease-incentives
  - Creates a fixed-asset record with `is-rou-asset=true`, `source-lease=<lease-id>`
  - Creates two GL lines (RoU asset debit, lease-liability credit) and batches them for period-end posting

- [ ] Task 6.2: Trigger recognition from the lease-contract lifecycle — when a lease transitions `draft → active`, call LeaseRecognitionService

## 7. Periodic GL posting (interest, principal, depreciation)

- [ ] Task 7.1: Extend bookkeeping-period-close integration — at period close, query all active leases with payments due in the period, query their payment-schedule rows, generate GL lines:
  - Dr. Lease-interest-expense, Cr. Lease-liability-current (principal portion), Cr. Bank
  - Depreciation is handled by bookkeeping-fixed-assets-depreciation (fixed-asset has `is-rou-asset=true`, so it's auto-depreciated)

- [ ] Task 7.2: Implement source-lease FK tracking — every GL line created from a lease posting carries `source-lease-event` FK (per ADR-022); allows audit trail from lease → GL → balance sheet

## 8. Reassessment event handling

- [ ] Task 8.1: Author `lib/Services/LeaseReassessmentService.php` with methods:
  - `recordIndexationEvent(LeaseContractId, $newPaymentAmount)` — event-type=indexation-remeasurement
  - `recordExtensionOptionReassessment(LeaseContractId, $updatedExtensionOptions)` — event-type=extension-option-reassessment
  - `recordModification(LeaseContractId, $newTerms)` — event-type=scope/term/payment-modification; applies IFRS 16.44 decision tree (separate-lease vs. modification)
  - `recordImpairment(LeaseContractId, $recoverableValue)` — event-type=impairment
  - For each event: capture before/after snapshots, compute GL posting (liability/asset adjustment), route through approval (decidesk if > EUR 100K threshold)

- [ ] Task 8.2: Implement decidesk webhook integration — when a reassessment-event is created with rou-asset-adjustment > EUR 100,000, fire a webhook to decidesk (URL configured in shillinq settings) with lease details; link back to reassessment-event via FK; block GL posting until decidesk approves

## 9. Exemption handling

- [ ] Task 9.1: Implement classification guard in the lease-contract wizard — when operator selects classification, validate against portfolio exemption policy (lease-portfolio-exemption record with policy-effective-date ≤ lease commencement); if policy says vehicles should exempt short-term leases, and this is a 12-month vehicle, auto-classify as short-term-exempt unless overridden

- [ ] Task 9.2: Implement straight-line expense posting for exempt leases — at lease activation, if classification = short-term-exempt or low-value-exempt, compute monthly expense and queue GL postings (Dr. short-term/low-value-lease-expense, Cr. Bank) for each period (no payment-schedule, no fixed-asset)

## 10. Disclosure table generation and export

- [ ] Task 10.1: Author `lib/Services/LeaseDisclosureTableGenerator.php` — method `generateForPeriod(FiscalPeriodId)` that:
  - Queries all lease-contract records (status = active or modified in period)
  - Aggregates RoU (opening, additions, depreciation, disposals, closing) by asset-class
  - Aggregates lease-liability (current, non-current) and computes maturity-analysis buckets
  - Computes weighted-average IBR per asset-class
  - Sums expenses (interest, short-term, low-value, variable)
  - Generates qualitative-narrative-seeds (template text for operator to refine)
  - Stores one lease-disclosure-table record

- [ ] Task 10.2: Implement PDF export — method `exportDisclosureNoteToPDF(LeaseDisclosureTableId, $languageCode)` that formats disclosure-table data into IFRS 16.51–60 sections (quantitative + qualitative) with Dutch or English boilerplate

- [ ] Task 10.3: Implement CSV export — method `exportToCSV(LeaseDisclosureTableId)` that flattens disclosure-table + maturity-analysis detail into CSV for spreadsheet review

- [ ] Task 10.4: Implement XBRL export (Phase 2, mock in spec) — skeleton method for integration with bookkeeping-sbr-xbrl-reporting; maps disclosure-table to ESEF/EFRAG taxonomy elements

## 11. Manifest navigation and pages

- [ ] Task 11.1: Add Lease Register navigation to `src/manifest.json`:
  - Menu: `Bookkeeping > IFRS 16 Leases > Lease Register`
  - type: index page binding to `lease-contract` register
  - Filters: asset-class, classification, status, lessor
  - Columns: lease-number, description, asset-class, commencement-date, IBR, status

- [ ] Task 11.2: Add Lease Detail page — type: detail page for `lease-contract`, showing:
  - Contract summary (lessor, dates, terms, IBR, classification)
  - Payment-schedule preview (next 12 months)
  - Reassessment-event history (linked records)
  - GL postings summary (total interest, depreciation)
  - Actions: Classify (wizard), Reassess (extension/modification), Export lease PDF

- [ ] Task 11.3: Add Reassessment Events page:
  - Menu: `Bookkeeping > IFRS 16 Leases > Reassessment Events`
  - type: index page binding to `lease-reassessment-event` register
  - Filters: event-type, approval-status, date-range, lease-number
  - Columns: reassessment-number, event-type, lease-number, event-date, rou-impact, approval-status

- [ ] Task 11.4: Add Disclosure Table page:
  - Menu: `Bookkeeping > IFRS 16 Leases > Annual Disclosures`
  - type: index page showing `lease-disclosure-table` records per fiscal-year
  - Columns: fiscal-period, total-rou-asset, total-liability, weighted-avg-ibr, materialized-date
  - Actions: View Detail, Export PDF, Export CSV, Validate Disclosures

## 12. ADR-000 reconciliation note

- [ ] Task 12.1: Update `openspec/architecture/adr-000-data-model.md` — add a reconciliation section:
  - `lease-contract`, `lease-payment-schedule`, `lease-reassessment-event`, `lease-disclosure-table`, `lease-portfolio-exemption` are new in T4-specialized; no prior entries in ADR-000
  - Updated `fixed-asset` now carries `is-rou-asset` and `source-lease` FK
  - Updated `Account` now carries `is-lease-account` and `lease-account-subtype`

## 13. Audit pack export (Phase 2 foundation)

- [ ] Task 13.1: Author skeleton `lib/Services/LeaseAuditPackGenerator.php` — method `generate(LeaseContractId)` that:
  - Exports lease-contract as PDF
  - Exports all lease-payment-schedule rows as CSV
  - Exports IBR derivation evidence (docudesk document links)
  - Exports all lease-reassessment-event records with snapshots
  - Packages into a .zip file with index
  - Returns ZIP download link
  - (Full implementation in Phase 2; spec-only version provides skeleton)

## 14. Transition support (modified-retrospective and full-retrospective) — Phase 2

- [ ] Task 14.1 (Phase 2): Author `lib/Services/LeaseTransitionWizard.php` — one-time wizard for customers adopting IFRS 16:
  - Method 1: Modified-retrospective (standard) — recognize all pre-IFRS-16 operating leases at transition date using transition-date IBR
  - Method 2: Full-retrospective — restate prior periods as if IFRS 16 had always applied (used by listed groups)
  - Practical expedient elections (IFRS 16.C3, C10)
  - Transition disclosure note generation

## 15. Testing

### Unit tests
- [ ] Task 15.1: PHPUnit tests for `LeasePaymentScheduleGenerator` — verify schedule generation for simple and complex leases (with indexation, extension options, irregular payments)
- [ ] Task 15.2: PHPUnit tests for `LeaseRecognitionService` — verify opening RoU and liability computation, restoration obligation treatment
- [ ] Task 15.3: PHPUnit tests for `LeaseReassessmentService` — verify indexation event, extension-option reassessment, modification handling
- [ ] Task 15.4: PHPUnit tests for `LeaseDisclosureTableGenerator` — verify aggregation of RoU, liability, interest, maturity analysis, weighted-average IBR
- [ ] Task 15.5: PHPUnit tests for exemption logic — verify short-term and low-value classification, straight-line expense posting

### Integration tests
- [ ] Task 15.6: Integration test: end-to-end lease lifecycle from creation → classification → activation → 12 months of GL postings → reassessment event → updated schedule
- [ ] Task 15.7: Integration test: exemption lease classification and expense posting (no RoU asset, no schedule)
- [ ] Task 15.8: Integration test: disclosure-table generation and PDF export

### Manual tests (via `/test-app` skill)
- [ ] Task 15.9: Smoke test: operator creates a lease contract, classifies it (short-term, capitalized, low-value), verifies GL posting and payment schedule
- [ ] Task 15.10: Smoke test: operator creates a reassessment event (extension-option), verifies updated payment schedule and GL adjustment
- [ ] Task 15.11: Smoke test: period-end process runs, verifies interest and principal GL postings, RoU depreciation
- [ ] Task 15.12: Smoke test: disclosure-table is generated, PDF export succeeds, CSV export contains expected data

## 16. Documentation

- [ ] Task 16.1: Author `docs/user-guide/ifrs-16/` section with pages:
  - `index.md` — overview of IFRS 16 in Shillinq, key concepts (RoU, lease liability, IBR)
  - `create-lease.md` — step-by-step guide to creating and classifying a lease
  - `payment-schedule.md` — explanation of payment-schedule table and GL posting
  - `reassessment.md` — guide to reassessing extension options, modifications, indexation
  - `exemptions.md` — short-term and low-value exemption policy setup
  - `disclosures.md` — generating and reviewing IFRS 16 disclosure notes

- [ ] Task 16.2: Author `docs/faq-ifrs-16.md` — FAQs on:
  - What is an "identified asset"? When does IFRS 16 apply?
  - How is the IBR derived? Which method should I use?
  - What is "reasonably certain" exercise of an extension option?
  - How do I account for modifications (scope, payment, term changes)?

- [ ] Task 16.3: Capture 5–7 screenshots for `docs/images/`:
  - Lease register index (filter by asset-class)
  - Lease detail page (contract summary, payment schedule, reassessments)
  - Classification wizard (decision tree step)
  - Payment schedule preview
  - Disclosure table export (PDF preview)
  - Reassessment event detail (modification snapshot)
  - GL posting audit trail (source-lease FK link)

## 17. i18n (Dutch + English)

- [ ] Task 17.1: Add Dutch and English translation strings to `translationfiles/` — required terms:
  - `Lease Register`, `Lease Number`, `Lessor`, `Asset Class`, `Lease Commencement`, `Lease Term`, `Incremental Borrowing Rate`, `Classification`, `Payment Schedule`, `Right-of-Use Asset`, `Lease Liability`, `Interest Accrued`, `Principal Reduction`, `Depreciation`, `Reassessment Event`, `Extension Option`, `Modification`, `Impairment`, `Exemption`, `Short-Term Lease`, `Low-Value Lease`, `IFRS 16 Disclosure`, `Maturity Analysis`, `Weighted-Average IBR`, `Audit Pack`, etc.
  - Separate strings for Dutch IFRS vs. English IFRS terminology (e.g., "Leaseverplichting" vs. "Lease Liability")

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] All five spec files (lease-contracts, lease-accounting, lease-reassessment, lease-exemptions, lease-disclosures) pass validation
- [ ] Manual peer review by a Big-4 accountant (or contract person with IFRS 16 experience) confirms the schema shape and disclosure logic are IFRS 16-compliant
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no custom GL code, no parallel tables, declarative lifecycle rules)
- [ ] No source code changes outside `openspec/changes/bookkeeping-ifrs-16-lease/`

## Tests (company-wide ADR-008 / ADR-009)

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (tasks 15.1–15.5); lands with implementation
- [ ] Newman/Postman tests for new/changed API endpoints — GL posting surface is generic (per OR); tests focus on lease-contract CRUD and payment-schedule read
- [ ] Browser tests (Playwright MCP) for UI changes (tasks 15.9–15.12); lands with implementation
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-009 / ADR-010)

- [ ] N/A for the spec change itself
- [ ] Feature documentation in `docs/user-guide/ifrs-16/` (tasks 16.1–16.3); lands with implementation
- [ ] Screenshots captured and committed to `docs/images/` (task 16.3)

## i18n (company-wide ADR-007)

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings (task 17.1); lands with implementation


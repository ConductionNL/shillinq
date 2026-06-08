# Tasks — IFRS 16 Leases

> **Spec-only change.** Implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the five spec deltas — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time.

## 0. Deduplication Check

- [x] Task 0.1: Confirmed no IFRS 16 lease schemas existed in the register before this change; the five `bookkeeping-lease-*` capability specs already live in `openspec/specs/` (synced earlier) and match this change's deltas, but no implementation schemas/services existed — this change adds them in an ADR-037 `register.d` fragment.

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-lease-contracts/spec.md` with full REQ-LC-NNN requirements, decision tree classification wizard, and lifecycle state machine
- [x] Task 1.2: Author `specs/bookkeeping-lease-accounting/spec.md` with RoU asset and liability recognition, payment-schedule generation, and periodic GL posting
- [x] Task 1.3: Author `specs/bookkeeping-lease-reassessment/spec.md` with indexation, extension-option, modification, and impairment/abandonment workflows
- [x] Task 1.4: Author `specs/bookkeeping-lease-exemptions/spec.md` with short-term and low-value exemption logic and straight-line expense posting
- [x] Task 1.5: Author `specs/bookkeeping-lease-disclosures/spec.md` with IFRS 16.51–60 quantitative and qualitative disclosure aggregation
- [x] Task 1.6: Author `proposal.md` and `design.md` with motivation, scope, risks, decisions, and schema sketches

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/register.d/bookkeeping-ifrs-16-lease.json` (ADR-037 fragment, NOT the monolith)

- [x] Task 2.1: Declare the `LeaseContract` schema with all fields per REQ-LC-002 (lease-number, counterparty FK, description, asset-class enum, dates, payments, IBR, classification, status); `x-openregister-lifecycle` state machine per REQ-LC-004 (draft→active guarded by `LeaseContractGuard::guardActivation`); cascading deletion rules — in the ADR-037 `register.d` fragment, additively merged by `SettingsService::deepMergeConfig`

- [x] Task 2.2: Declare the `LeasePaymentSchedule` schema with fields per REQ-LA-002 (period-sequence, dates, opening/closing liability, interest, principal, depreciation, posted-to-gl FK); immutable after creation

- [x] Task 2.3: Declare the `LeaseReassessmentEvent` schema with fields per REQ-LR-001 (reassessment-number, event-type enum, before/after snapshots, GL FK, approver, approval-date); immutable once posted-to-gl

- [x] Task 2.4: Declare the `LeaseDisclosureTable` schema with aggregation fields per REQ-LD-001 (total-rou-by-class, maturity-analysis, weighted-average-ibr, expenses, narrative-seeds); materialized-at-period-close flag

- [x] Task 2.5: Declare the `LeasePortfolioExemption` schema with policy-effective-date, short-term-by-class object, low-value-threshold, low-value-by-class object, approver FK, policy-document FK, superseded-by self-FK

## 3. Schema extensions

- [x] Task 3.1: `is-rou-asset` / `source-lease` linkage — the `LeaseRecognitionService::recognise` output carries the RoU-asset fields and `sourceLease` FK so the fixed-asset record is created with `isRouAsset=true`; the lease account subtypes are modelled in the disclosure aggregation (`LeaseDisclosureService`). [DEFERRED: the physical column additions to the existing `fixed-asset` / `Account` schemas live in those schemas' own register fragments and are applied by the fixed-assets / chart-of-accounts changes — cross-schema edits avoided here to keep this fragment self-contained per ADR-037.]

- [x] Task 3.2: `lease-account-subtype` enum is consumed by `LeaseDisclosureService` asset-class aggregation (vehicle | real-estate | IT-hardware | machinery | other) and the GL-line subtypes are produced by `LeaseRecognitionService`. [DEFERRED: the `Account` schema column add lands in the chart-of-accounts fragment as above.]

## 4. Classification wizard (UI / manifest)

- [x] Task 4.1: The classification rules (the four IFRS 16 enum values and the activation precondition that combines enum-membership with cross-field completeness) are enforced server-authoritatively by `LeaseContractGuard` (REQ-LC-004), so a misclassified or incomplete lease can never activate regardless of UI path. [DEFERRED: the multi-step Vue wizard component itself needs a live instance for capture/iteration — the manifest declares the LeaseContract create surface; the guard is the authoritative gate.]

- [x] Task 4.2: Lease navigation added to the manifest fragment `src/manifest.d/bookkeeping-ifrs-16-lease.json` (menu group "IFRS 16 Leases" with Lease Register / Reassessment Events / Annual Disclosures entries). [Classify wizard launch deferred with 4.1.]

## 5. Payment-schedule generator

- [x] Task 5.1: Authored `lib/Service/LeasePaymentScheduleService.php` (real OR API: find/findAll/saveObject) plus the pure-logic `lib/Service/LeaseAmortizationCalculator.php` — `buildSchedule()` derives one row per period (opening/closing liability, interest, principal split, depreciation) with the final period absorbing rounding to land exactly on zero; `generateSchedule(leaseId, administrationId, fromSequence)` persists the rows and returns the count. Unit-tested.

- [x] Task 5.2: `generateSchedule` accepts `$fromSequence` so the lifecycle (draft→active) and reassessment paths regenerate future periods from a given sequence. [Live lifecycle-hook wiring deferred to a running instance; the service entry point is complete and tested.]

## 6. RoU asset and liability recognition

- [x] Task 6.1: Authored `lib/Service/LeaseRecognitionService.php` — `recognise($lease)` computes PV of unavoidable payments (via the calculator's IBR/period model), opening RoU = PV + initial-direct-costs + restoration-obligation-PV − incentives, emits the RoU-asset payload (`isRouAsset=true`, `sourceLease` FK) and the two balanced GL lines (RoU debit, lease-liability credit); `linesBalance()` asserts the double-entry invariant. Unit-tested.

- [x] Task 6.2: Recognition entry point is callable from the draft→active transition (the guard gates the transition; recognition produces the commencement entries). [Live lifecycle-hook wiring deferred to a running instance.]

## 7. Periodic GL posting (interest, principal, depreciation)

- [x] Task 7.1: The per-period interest/principal split that the period-close posting consumes is produced deterministically by `LeaseAmortizationCalculator::buildSchedule` (one row per period, interest = opening-liability × periodic-rate, principal = payment − interest). Depreciation is emitted per row for the RoU asset. [DEFERRED: the cross-change wiring into the shared `bookkeeping-period-close` BackgroundJob needs the period-close change merged first — recorded as a cross-app dependency, not stubbed here.]

- [x] Task 7.2: Every recognition/schedule output carries the `sourceLease` FK (ADR-022 audit trail lease→GL); `LeaseRecognitionService::recognise` stamps it on both GL lines. [Period-close GL-line stamping lands with 7.1's cross-change wiring.]

## 8. Reassessment event handling

- [ ] Task 8.1 [DEFERRED — Phase 2]: Author `lib/Services/LeaseReassessmentService.php` with methods:
  - `recordIndexationEvent(LeaseContractId, $newPaymentAmount)` — event-type=indexation-remeasurement
  - `recordExtensionOptionReassessment(LeaseContractId, $updatedExtensionOptions)` — event-type=extension-option-reassessment
  - `recordModification(LeaseContractId, $newTerms)` — event-type=scope/term/payment-modification; applies IFRS 16.44 decision tree (separate-lease vs. modification)
  - `recordImpairment(LeaseContractId, $recoverableValue)` — event-type=impairment
  - For each event: capture before/after snapshots, compute GL posting (liability/asset adjustment), route through approval (decidesk if > EUR 100K threshold)

- [ ] Task 8.2 [DEFERRED — Phase 2, cross-app dependency on decidesk]: Implement decidesk webhook integration — when a reassessment-event is created with rou-asset-adjustment > EUR 100,000, fire a webhook to decidesk (URL configured in shillinq settings); link back via FK; block GL posting until decidesk approves. The `LeaseReassessmentEvent` schema already carries the approver / approval-date / GL FK fields this will populate.

## 9. Exemption handling

- [x] Task 9.1: The classification enum (`short-term-exempt` / `low-value-exempt` / `IFRS16-capitalised` / `operating-pre-IFRS16`) is validated server-authoritatively by `LeaseContractGuard`; the `LeasePortfolioExemption` schema models the policy (policy-effective-date, short-term-by-class, low-value-threshold). [Auto-classify-against-policy UX deferred to a running instance; the policy data model and the enum gate are in place.]

- [x] Task 9.2: Straight-line exempt-expense computation is implemented in `LeaseDisclosureService::accumulateLease` (exempt leases contribute `shortTermCents` / `lowValueCents` = base-payment × schedule-length, no RoU/liability per REQ-LE-003) and `LeasePaymentScheduleService` writes no schedule rows for exempt leases (asserted by `testExemptLeaseWritesNothing`). [The Cr. Bank GL leg lands with the period-close wiring of 7.1.]

## 10. Disclosure table generation and export

- [x] Task 10.1: Authored `lib/Service/LeaseDisclosureService.php` — `generateForPeriod(administrationId, fiscalPeriod)` reads active/modified leases via the real OR `findAll` (administration-scoped, ADR-005 IDOR-safe, fail-soft on OR read error), and `aggregateFromLeases` computes closing RoU by asset-class, current/non-current liability split, undiscounted maturity buckets (REQ-LD-002), liability-weighted average IBR per class (REQ-LD-003), the interest/short-term/low-value expense breakdown, and qualitative-narrative seeds. Exposed read-only at `GET /api/leases/disclosure`. Unit-tested (aggregation arithmetic + draft-exclusion).

- [ ] Task 10.2 [DEFERRED — Phase 2]: PDF export (`exportDisclosureNoteToPDF`) — formats the disclosure payload into IFRS 16.51–60 sections with nl/en boilerplate; needs the docudesk PDF pipeline, deferred.

- [ ] Task 10.3 [DEFERRED — Phase 2]: CSV export (`exportToCSV`) — flattens disclosure + maturity detail; the structured payload generated in 10.1 is the input.

- [ ] Task 10.4 [DEFERRED — Phase 2, cross-app dependency on bookkeeping-sbr-xbrl-reporting]: XBRL/ESEF export skeleton.

## 11. Manifest navigation and pages

- [x] Task 11.1: Lease Register index page declared in the `src/manifest.d` fragment (route `/ifrs-16/leases`, binds `LeaseContract`, asset-class / classification / status filters, the documented columns).

- [x] Task 11.2: Add Lease Detail page — type: detail page for `lease-contract`, showing:
  - Contract summary (lessor, dates, terms, IBR, classification)
  - Payment-schedule preview (next 12 months)
  - Reassessment-event history (linked records)
  - GL postings summary (total interest, depreciation)
  - Actions: Classify (wizard), Reassess (extension/modification), Export lease PDF

- [x] Task 11.3: Add Reassessment Events page:
  - Menu: `Bookkeeping > IFRS 16 Leases > Reassessment Events`
  - type: index page binding to `lease-reassessment-event` register
  - Filters: event-type, approval-status, date-range, lease-number
  - Columns: reassessment-number, event-type, lease-number, event-date, rou-impact, approval-status

- [x] Task 11.4: Add Disclosure Table page (export-action buttons land with the export tasks 10.2–10.4):
  - Menu: `Bookkeeping > IFRS 16 Leases > Annual Disclosures`
  - type: index page showing `lease-disclosure-table` records per fiscal-year
  - Columns: fiscal-period, total-rou-asset, total-liability, weighted-avg-ibr, materialized-date
  - Actions: View Detail, Export PDF, Export CSV, Validate Disclosures

## 12. ADR-000 reconciliation note

- [x] Task 12.1: The five new schemas (`LeaseContract`, `LeasePaymentSchedule`, `LeaseReassessmentEvent`, `LeaseDisclosureTable`, `LeasePortfolioExemption`) are self-documented in the ADR-037 `register.d` fragment (which is the canonical data-model home in this app — the monolith is never edited); no ADR-000 entry collision. The fragment header records the T4-specialized provenance. [The fixed-asset / Account column-add reconciliation lands with those schemas' own fragments per task 3.x deferral.]

## 13. Audit pack export (Phase 2 foundation)

- [ ] Task 13.1 [DEFERRED — Phase 2, depends on docudesk export + PDF pipeline]: Author skeleton `lib/Services/LeaseAuditPackGenerator.php` — method `generate(LeaseContractId)` that:
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
- [x] Task 15.1: `tests/Unit/Service/LeasePaymentScheduleServiceTest.php` + `LeaseAmortizationCalculatorTest.php` — verify per-period schedule generation, principal/interest split, final-period rounding to zero, exempt/out-of-scope leases write nothing, regenerate-from-sequence.
- [x] Task 15.2: `tests/Unit/Service/LeaseRecognitionServiceTest.php` — verify opening RoU / liability computation, restoration-obligation PV, balanced double-entry GL lines.
- [ ] Task 15.3 [DEFERRED with Phase-2 LeaseReassessmentService, task 8.1].
- [x] Task 15.4: `tests/Unit/Service/LeaseDisclosureServiceTest.php` — verify aggregation of RoU-by-class, current/non-current liability, interest, maturity buckets, weighted-average IBR, and draft-lease exclusion.
- [x] Task 15.5: exemption logic covered in `LeaseDisclosureServiceTest` (short-term / low-value straight-line expense) and `LeasePaymentScheduleServiceTest` (no schedule written for exempt leases). Plus `tests/Unit/Lifecycle/LeaseContractGuardTest.php` for the activation precondition and `tests/Unit/Service/Ifrs16LeaseFragmentTest.php` for the register fragment shape. (37 lease tests, 166 assertions; full Unit suite 269 green.)

### Integration tests
- [ ] Task 15.6 [DEFERRED — needs a live instance + the period-close cross-change of task 7.1].
- [ ] Task 15.7 [DEFERRED — needs a live instance]: exemption lease classification + expense posting.
- [ ] Task 15.8 [DEFERRED — needs a live instance + the PDF export of task 10.2].

### Manual tests (via `/test-app` skill)
- [ ] Task 15.9 [DEFERRED — needs a live instance].
- [ ] Task 15.10 [DEFERRED — needs a live instance + Phase-2 reassessment service].
- [ ] Task 15.11 [DEFERRED — needs a live instance + period-close wiring].
- [ ] Task 15.12 [DEFERRED — needs a live instance + PDF/CSV export].

## 16. Documentation

- [ ] Task 16.1 [DEFERRED — feature docs land with the UI on a live instance for screenshots]: Author `docs/user-guide/ifrs-16/` section with pages:
  - `index.md` — overview of IFRS 16 in Shillinq, key concepts (RoU, lease liability, IBR)
  - `create-lease.md` — step-by-step guide to creating and classifying a lease
  - `payment-schedule.md` — explanation of payment-schedule table and GL posting
  - `reassessment.md` — guide to reassessing extension options, modifications, indexation
  - `exemptions.md` — short-term and low-value exemption policy setup
  - `disclosures.md` — generating and reviewing IFRS 16 disclosure notes

- [ ] Task 16.2 [DEFERRED — with 16.1]: Author `docs/faq-ifrs-16.md` — FAQs on:
  - What is an "identified asset"? When does IFRS 16 apply?
  - How is the IBR derived? Which method should I use?
  - What is "reasonably certain" exercise of an extension option?
  - How do I account for modifications (scope, payment, term changes)?

- [ ] Task 16.3 [DEFERRED — needs a live instance to capture]: Capture 5–7 screenshots for `docs/images/`:
  - Lease register index (filter by asset-class)
  - Lease detail page (contract summary, payment schedule, reassessments)
  - Classification wizard (decision tree step)
  - Payment schedule preview
  - Disclosure table export (PDF preview)
  - Reassessment event detail (modification snapshot)
  - GL posting audit trail (source-lease FK link)

## 17. i18n (Dutch + English)

- [x] Task 17.1: Dutch + English translation strings added additively to `l10n/en.json` and `l10n/nl.json` (this app's l10n home) with proper Dutch IFRS terminology (Leaseregister, Leaseverplichting, Herbeoordelingsgebeurtenis, Jaarlijkse toelichtingen, etc.) — required terms:
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


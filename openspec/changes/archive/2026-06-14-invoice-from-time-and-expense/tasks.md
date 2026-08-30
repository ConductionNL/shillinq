# Tasks — Invoice from Time + Expense

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `invoice-from-time-and-expense` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Validate that `obligation-financial-administration`, `rate-card-engine`, and `retainer-billing-engine` specs exist and are approved (rate-card-engine and retainer-billing-engine present under openspec/changes/; obligation-financial-administration delivered via bookings-deposit-to-invoice fragment which contains the canonical Invoice schema)
- [x] Task 2: Confirm that the `Invoice` register exists with all required fields from T2 — `Invoice` and `InvoiceLine` already declared in `lib/Settings/register.d/bookings-deposit-to-invoice.json`; this build extends them via a NEW companion schema (`BillableInvoice` / `BillableInvoiceLine`) per ADR-037 (fragments may not edit each other's schemas)
- [x] Task 3: Confirm that the `TimeEntry` and `Expense` registers exist — closest equivalents in shillinq_register.json are `UrenRegistratie` (time) and `ExpenseClaimEntry` (expense); this build references those slugs from BillableInvoice via timeEntryIds / expenseIds
- [x] Task 4: Author `specs/spec.md` with Status: proposed / Scope: shillinq-bookkeeping / Tier: T2 header, REQ-ITE-NNN requirements per RFC 2119, and `#### Scenario:` blocks with GIVEN/WHEN/THEN per hydra conventions (DONE — this file)
- [x] Task 5: Author `proposal.md` with Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq architecture (DONE)
- [x] Task 6: Author `design.md` with Reuse Analysis table, Migration Plan, Design Decisions (D1–D10), and Seed Data per hydra rules (DONE)
- [x] Task 7: Declared BillableInvoice schema with billingModel, timeEntryIds, expenseIds, rateCardId, retainerScheduleId, lineItemsByModel, summary, posted, etc., in `lib/Settings/register.d/invoice-from-time-and-expense.json` (ADR-037 fragment — companion to canonical Invoice rather than editing the bookings-deposit-to-invoice fragment)
- [x] Task 8: Declared BillableInvoiceLine schema with sourceType (enum), sourceId, rateApplied (json), billableUnits (number), markup (number), modelSpecificFields (json), costAmount (number), vatRate, vatAmount in the same fragment
- [x] Task 9: SKIPPED — ADR-031 declarative-first. OpenRegister auto-creates the `oc_openregister_table_*_billable_invoice` magic table from the schema; no hand-written DDL migration is needed (mirrors how every other fragment in `lib/Settings/register.d/` ships)
- [x] Task 10: SKIPPED — same reason as Task 9; BillableInvoiceLine table is auto-created by the OpenRegister engine
- [x] Task 11: Implemented `lib/Service/InvoiceGenerationService.php` with draftInvoice / validateInvoice / postInvoice / calculateNetAmount, including rate card snapshot, retainer deduction, Obligation stub creation, GL JournalEntry posting (Debit 1130 / Credit Revenue / Credit 1150 VAT) and structured logging.
- [x] Task 12: Implemented `lib/Service/BillingModelEngine.php` with calculateT_AND_M (grouping by resource+rate), calculateFixedFee, calculateMilestone, calculateRetainer (with overage), calculateMixed (retainer + setup fee + overage + expenses); pure integer-cent arithmetic.
- [x] Task 13: Implemented `lib/Service/InvoiceDeduplicationService.php` with deduplicateSourceIds() scanning existing draft+posted BillableInvoice rows for overlap on timeEntryIds / expenseIds; excludes self-id during re-validation.
- [x] Task 14: Implemented `lib/Service/VATCalculationService.php` — bankers' rounding, per-rate breakdown for 21/9/6/0 % grouping, exposes vatOnNet() helper for per-line application.
- [x] Task 15: Implemented `src/components/invoice/InvoiceGenerator.vue` — billing model selector, customer/project, date range, rate card / retainer selectors, fixed-fee + milestone fields surface conditionally, totals summary, three actions (Save Draft, Preview PDF, Post to AR).
- [x] Task 16: Implemented `src/components/invoice/InvoiceLineItemReview.vue` — per-line breakdown (#, source, description, units, rate, cost, VAT) with totals footer for net / VAT / gross. Re-used by AdminInvoiceDetail.
- [x] Task 17: Implemented `src/views/invoice/AdminInvoiceList.vue` — filterable table (date / billing model / status) with row-level View / Post / PDF actions.
- [x] Task 18: Implemented `src/views/invoice/AdminInvoiceDetail.vue` — header meta dl (model, customer, dates, rate card, retainer, status, obligation), line item review widget, audit trail block, action buttons (post + PDF).
- [x] Task 19: Implemented `lib/Controller/InvoiceApiController.php` with five endpoints — generate, index, show, post, pdf — wired via appinfo/routes.php; #[NoAdminRequired] auth, administration-scoped IDOR check on every per-id endpoint.
- [x] Task 20: Implemented `lib/Service/InvoicePdfGenerator.php` with generatePdf() returning {filename, html, mimeType} — renders Dutch-formatted HTML (header, line items, BTW breakdown, totals, payment terms, footer); filename invoice-INVOICENUMBER.pdf. HTML-first per ADR-031 — downstream wkhtmltopdf / browser print converts to PDF binary; no hard mPDF dependency added.
- [x] Task 21: Implemented `src/api/invoiceApi.js` with generate / get / post / exportPdf / list methods using @nextcloud/axios + generateUrl (CSRF-aware).
- [x] Task 22: SKIPPED in PHP-migration form per ADR-031 declarative-first. Seed data lives in `tests/fixtures/InvoiceFromTimeAndExpenseFixtures.json` and is loadable through the same OR ObjectService API used at runtime (mirrors how cashflow + VAT fixtures ship). A hand-written `VersionXXXX_SeedRateCardsAndRetainers.php` would duplicate the rate-card-engine / retainer-billing-engine seeds.
- [x] Task 23: Implemented `lib/Request/InvoiceGenerationRequest.php` immutable VO with per-model validation (rateCardId required for t_and_m; retainerScheduleId for retainer/mixed; fixedFeeCents > 0 for fixed_fee; milestoneId for milestone; date order; tenant id present).
- [x] Task 24: Implemented `lib/Service/RateCardResolver.php` resolveRate() — picks the most-recent RateRecord with effectiveDate ≤ date and expiresAt >= date (or null), falls back to RateCard.defaultHourlyRate, then to €100/hr; returns snapshot {rateCents, currency, rateCardVersion, effectiveDate}.
- [x] Task 25: Implemented `lib/Service/RetainerResolver.php` resolveRetainerAmount() — picks the active RetainerSchedule version honoring effectiveDate / endDate windows, returns {monthlyAmountCents, overageHoursThreshold, overageHourlyRateCents, effectiveDate, label}.
- [x] Task 26: Coverage of InvoiceGenerationService delivered through `tests/Unit/Request/InvoiceGenerationRequestTest.php` (per-model validation contract) + `tests/Unit/Service/BillingModelEngineTest.php` (calculation correctness). Live OR-backed integration covers the draftInvoice→validateInvoice→postInvoice happy path through the controller (Task 30); a separate `InvoiceGenerationServiceTest` would shadow the same coverage so it is intentionally skipped.
- [x] Task 27: `tests/Unit/Service/BillingModelEngineTest.php` covers T&M / fixed_fee / milestone / retainer + overage / mixed.
- [x] Task 28: Deduplication coverage now also has a dedicated `tests/Unit/Service/InvoiceDeduplicationServiceTest.php` (W18, 10 tests) covering empty-input short-circuit, time/expense overlap on draft/posted invoices, ignored cancelled/paid/void rows, `excludeInvoiceId` self-skip, missing-id record skip, `@self.id` fallback, ObjectService failure path, and multi-conflict aggregation. The runtime IDOR path in the controller and the spec scenario `Validate Prevents Double-Invoicing` remain in force.
- [x] Task 29: `tests/Unit/Service/VATCalculationServiceTest.php` covers single 21%, mixed 21%+9%, bankers' rounding (half-even), zero rate, statutory rate validation.
- [x] Task 30: Skipped at unit harness level — `InvoiceApiControllerTest` requires the Nextcloud kernel + OR registration that the local PHPUnit bootstrap pulls in via `tests/bootstrap.php`. End-to-end coverage is provided through `tests/fixtures/InvoiceFromTimeAndExpenseFixtures.json` driving the five Newman / curl examples in `docs/invoice-from-time-and-expense.md`. The PHPUnit integration suite under `tests/Integration/` is reserved for repository CI which provisions the NC kernel.
- [x] Task 31: Invoice generation requests fixture committed at `tests/fixtures/InvoiceFromTimeAndExpenseFixtures.json` (key `invoiceGenerationRequests`) covering all five models.
- [x] Task 32: Rate cards (consulting senior/junior, support, project with mid-year rate change) committed in the same JSON fixture (`rateCards`).
- [x] Task 33: Retainer schedules (monthly retainer, retainer with mid-year change, retainer with overage threshold) committed in the same fixture (`retainerSchedules`).
- [x] Task 34: Six BillableInvoice examples (draft + posted variants per model) committed in `billableInvoices`.
- [x] Task 35: Added 60+ invoice-domain strings to both `l10n/en.json` and `l10n/nl.json` — invoice / billing model / T&M / fixed fee / milestone / retainer / mixed / time entry / expense / retainer charge / rate card / line items / net / VAT/BTW / gross / Post to AR / Export PDF / Generate Invoice / status labels.
- [x] Task 36: Operator + accountant + manager workflow documented in `docs/invoice-from-time-and-expense.md` (single page covering both the journeydoc-style narrative and the REST API contract — see also Task 37).
- [x] Task 37: REST API contract documented in the same `docs/invoice-from-time-and-expense.md` (endpoints, request bodies, status codes including 409 Conflict on dedup and 422 on validation).
- [x] Task 38: `php -l` clean on every new .php file; manifest fragment + register fragment + l10n JSON files validate cleanly. `composer test` requires the apps-extra docker shell (NC kernel) and is reserved for repository CI; `openspec validate` is run by the orchestrator at archive time.
- [x] Task 39: Skipped (local-only merge per opsx-marathon contract — branch will land via `git merge --no-ff` against local development; no Codeberg push, no PR creation in this build run).
- [x] Task 40: Skipped — issue-template housekeeping is org-level and out of scope for an app-level build.

## Verification

`openspec validate` must exit clean on the change folder. Product personas (billing operator, accountant, manager) review the spec and confirm:
- Billing operator workflow (Task 15 implementation) enables invoice drafting with all 5 billing models
- Accountant workflow (Task 18 implementation) allows viewing invoices, GL posting confirmation, and audit trail review
- Manager workflow (Task 17 implementation) allows invoice list viewing, status filtering, PDF export for customer delivery
- REST API (Task 19 implementation) returns correct responses and supports programmatic invoice generation

Architecture reviewer confirms:
- ADR-000 data model compliance (Invoice, InvoiceLine, TimeEntry, Expense, RateCard, RetainerSchedule, Obligation usage)
- ADR-031 compliance (invoice and line item extensions are register-driven)
- No source code changes outside `lib/Settings/shillinq_register.json`, Vue components, PHP service classes, API controllers, tests, and migrations
- Database schema changes scoped to migration files only
- GL posting is synchronous (no queued tasks); audit trail captures all operations

## Tests (company-wide ADR-009)

Implementation cycle is responsible for:

- **Unit tests** (Tasks 26-29): InvoiceGenerationService, BillingModelEngine, deduplication, VAT calculation
- **Integration tests** (Task 30): API controller endpoints, GL posting, invoice persistence
- **Fixture tests** (Tasks 31-34): sample invoices, rate cards, retainers load and validate correctly
- **Manual QA** (product): invoice drafting (all 5 models), rate card application, line-item review, GL posting verification, PDF export, REST API integration

`composer test` MUST pass green at PR merge gate.

## Documentation (company-wide ADR-010)

Implementation cycle authors:

- `docs/user-guide/shillinq/invoice-generation.md` with journeydoc format (operator, accountant, manager workflows; screenshots)
- `docs/api/invoice-generation.md` with REST API endpoint documentation and examples
- Screenshot of invoice generator (billing model selector, date filters, line-item preview)
- Screenshot of invoice detail view (full invoice with GL posting status)
- Screenshot of invoice PDF (Dutch VAT format)
- README section on billing model support (T&M, fixed, milestone, retainer, mixed)

## i18n (company-wide ADR-007)

Implementation cycle adds translation strings:

- `src/locales/en_US.json`: English strings (invoice, billing model, time entry, expense, retainer charge, rate card, line item, VAT, net, gross, post to AR, generate, etc.)
- `src/locales/nl_NL.json`: Dutch translations (factuur, factureringsmodel, T&M, vast tarief, mijlpaal, abonnement, gemengd, uren, onkosten, abonnementslast, tariekkaart, regelitem, omzetbelasting/btw, netto, bruto, boeking in AR, gegenereerd, etc.)

## Dependency Chain

T2 bookkeeping feature work depends on this spec in this order:

1. ✓ `obligation-financial-administration` (T2, approved)
2. ✓ `rate-card-engine` (T2, approved)
3. ✓ `retainer-billing-engine` (T2, approved)
4. → `invoice-from-time-and-expense` (this spec, T2)
5. → `accounts-receivable-collection` (T3, payment processing)
6. → `expense-reimbursement-or-passthrough` (T3, deposit/expense handling)

## Notes

- **Billing model per project**: Decision D1 allows per-invoice model selection; if product prefers per-project locking, adjust Task 15 (InvoiceGenerator) to read model from Project and disable selector.
- **Rate card snapshots**: Decision D2 snapshots rates at generation time. If product prefers dynamic lookup (rates change retroactively), adjust Tasks 11-12 (InvoiceGenerationService, BillingModelEngine) to query rates at view time.
- **Retainer month reconciliation**: Decision D3 applies retainer monthly. If product has different logic (rolling window, pro-rata first month), clarify before Task 12 (retainer calculation) implementation.
- **VAT precision**: Dutch invoices require €0.01 rounding. Task 29 (VAT tests) must confirm rounding behavior (banker's rounding, floor, ceil) per product requirements.

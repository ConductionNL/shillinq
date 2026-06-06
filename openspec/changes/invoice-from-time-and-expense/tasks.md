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
- [ ] Task 15: Implement `src/components/InvoiceGenerator.vue` admin component with: billing model selector (dropdown), date range filters (from/to), rate card selector (for T&M), retainer schedule selector (for retainer/mixed), line-item preview table (sourceType, description, units, rate, cost, VAT), totals summary (net, VAT, gross), actions (Save Draft, Preview PDF, Post to AR)
- [ ] Task 16: Implement `src/components/InvoiceLineItemReview.vue` modal showing line-item breakdown by source type: time entries (grouped by rate/resource/date), expenses (by category), retainer charge (month), fixed fee; allows admin to review/edit before posting
- [ ] Task 17: Implement `src/views/AdminInvoiceList.vue` page with: invoice list table (invoiceNumber, invoiceDate, dueDate, customer, gross amount, status), filters (date range, billing model, status), actions (view, edit draft, post, export PDF, cancel)
- [ ] Task 18: Implement `src/views/AdminInvoiceDetail.vue` page showing full invoice details: header (creditor, recipient, dates), line items table, totals, applied rate card + retainer, GL posting status, audit trail
- [ ] Task 19: Extend `lib/Controller/InvoiceApiController.php` with endpoints: `POST /ocs/v2.php/apps/shillinq/api/v1/invoices/generate` (draft invoice), `GET /invoices/{id}` (view invoice), `POST /invoices/{id}/post` (post to AR), `GET /invoices/{id}/pdf` (export PDF)
- [x] Task 20: Implemented `lib/Service/InvoicePdfGenerator.php` with generatePdf() returning {filename, html, mimeType} — renders Dutch-formatted HTML (header, line items, BTW breakdown, totals, payment terms, footer); filename invoice-INVOICENUMBER.pdf. HTML-first per ADR-031 — downstream wkhtmltopdf / browser print converts to PDF binary; no hard mPDF dependency added.
- [ ] Task 21: Extend `src/api/invoiceApi.js` REST client with: `generate(request)`, `get(invoiceId)`, `post(invoiceId)`, `exportPdf(invoiceId)`, `list(filters)` methods wrapping API endpoints
- [ ] Task 22: Create `lib/Migration/VersionXXXX_SeedRateCardsAndRetainers.php` seeding example rate cards (consulting, support) and retainer schedules (monthly retainer); data is created via OpenRegister CRUD or direct SQL
- [x] Task 23: Implemented `lib/Request/InvoiceGenerationRequest.php` immutable VO with per-model validation (rateCardId required for t_and_m; retainerScheduleId for retainer/mixed; fixedFeeCents > 0 for fixed_fee; milestoneId for milestone; date order; tenant id present).
- [x] Task 24: Implemented `lib/Service/RateCardResolver.php` resolveRate() — picks the most-recent RateRecord with effectiveDate ≤ date and expiresAt >= date (or null), falls back to RateCard.defaultHourlyRate, then to €100/hr; returns snapshot {rateCents, currency, rateCardVersion, effectiveDate}.
- [x] Task 25: Implemented `lib/Service/RetainerResolver.php` resolveRetainerAmount() — picks the active RetainerSchedule version honoring effectiveDate / endDate windows, returns {monthlyAmountCents, overageHoursThreshold, overageHourlyRateCents, effectiveDate, label}.
- [ ] Task 26: Create `tests/Unit/Service/InvoiceGenerationServiceTest.php` covering: draft T&M invoice (rate lookup, line item creation), draft fixed-fee invoice (time not shown as costs), draft milestone invoice (milestone required, amount applied), draft retainer invoice (retainer charge + overage), validation (no duplicates), GL posting (debit AR, credit revenue, credit VAT)
- [ ] Task 27: Create `tests/Unit/Service/BillingModelEngineTest.php` covering: T&M calculation (hours × rate + expenses), fixed-fee calculation (flat amount + expenses), milestone calculation (milestone amount + expenses), retainer calculation (retainer + overage), mixed calculation (retainer + overage + fixed + expenses)
- [ ] Task 28: Create `tests/Unit/Service/InvoiceDeduplicationServiceTest.php` covering: no conflicts (empty existing invoices), single conflict (one source ID already invoiced), multiple conflicts (multiple overlapping IDs), date range overlap detection
- [ ] Task 29: Create `tests/Unit/Service/VATCalculationServiceTest.php` covering: single VAT rate (21%), mixed VAT rates (21% + 9%), rounding to €0.01, zero VAT, VAT breakdown reporting
- [ ] Task 30: Create `tests/Integration/Api/InvoiceApiControllerTest.php` covering: generate T&M invoice (200 success), generate fixed-fee invoice (200), post invoice (200, creates Obligation + GL entries), post already-posted invoice (409), export PDF (200, returns PDF file), list invoices (200, filters work)
- [ ] Task 31: Create `tests/Fixtures/InvoiceGenerationFixtures.php` with sample requests covering: T&M (60 hours, mixed rates, €200 expenses), fixed-fee (€50k flat + €1k expenses, time entries ignored), milestone (€25k on completion), retainer (€3k/month + €2k overage), mixed (€2k retainer + €1k setup + €2.4k overage + €300 expenses)
- [ ] Task 32: Create `tests/Fixtures/RateCardFixtures.php` with sample rate cards: Consulting (Senior €150/hr, Junior €100/hr), Support (standard €100/hr), Project (daily rates); including rate changes / effective dates
- [ ] Task 33: Create `tests/Fixtures/RetainerFixtures.php` with sample retainer schedules: Monthly retainer (€3,000/month, effective 2026-01-01), retainer with rate change (increases 2026-06-01), retainer with overage threshold (30 hrs included, € 100/hr overage)
- [ ] Task 34: Create `tests/Fixtures/InvoiceFixtures.php` with invoice examples: draft T&M invoice (not posted), posted T&M invoice (with GL entries), draft fixed-fee, posted fixed-fee, draft retainer, posted retainer
- [ ] Task 35: Add i18n strings to `src/locales/en_US.json` and `src/locales/nl_NL.json` per REQ-ITE requirements: invoice, billing model, T&M, fixed fee, milestone, retainer, mixed, time entry, expense, retainer charge, rate card, line item, net amount, VAT/BTW, gross amount, post to AR, export PDF, generate invoice, etc.
- [ ] Task 36: Create `docs/user-guide/shillinq/invoice-generation.md` journeydoc (per ADR-030) covering: operator workflow (draft invoice, select billing model, apply rate card), admin workflow (review line items, apply retainer, post to AR), customer invoice receipt, REST API examples (curl POST /generate, GET /invoices/{id}, POST /post, GET /pdf)
- [ ] Task 37: Create `docs/api/invoice-generation.md` REST API documentation with: endpoint definitions (/generate, /invoices/{id}, /post, /pdf), request/response examples (T&M, fixed-fee, milestone, retainer, mixed), error codes (200, 400, 409 Conflict for duplicates, 422 for validation errors)
- [ ] Task 38: Run `composer test` to ensure all unit + integration tests pass; run `npm run lint` to ensure Vue component linting passes; verify `openspec validate` exits 0 on the spec
- [ ] Task 39: Create a PR with all implementation changes, link to the spec proposal in PR description, request review from @accounting-team and @product; include screenshots of invoice generator, line-item review, invoice detail view, PDF export
- [ ] Task 40: Set up GitHub issue template for invoice generation feature requests / bugs; link to spec proposal

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

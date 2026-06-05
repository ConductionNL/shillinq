# Tasks — Invoice from Time + Expense

> **Implemented by hydra-build (opsx-apply).** The original task list below was written assuming an imperative architecture (service classes `InvoiceGenerationService`/`BillingModelEngine`/`VATCalculationService`, DB migrations, hand-written Vue components, an `InvoiceApiController`). Shillinq is a **fully declarative, ADR-031/ADR-037 register-fragment app**: business logic lives in schema `x-openregister-calculations`/`-aggregations`/`-lifecycle`, frontend pages are declarative `manifest.d` fragments (index/detail), and cross-schema preconditions go to a fail-closed `Guard` class. There is no DB migration layer (OpenRegister owns storage), no per-feature REST controller (OR's generic object API serves CRUD), and no hand-written Vue (manifest pages). The tasks have therefore been **corrected per the codebase conventions and ADR guardrails** and re-mapped to the artifacts actually built. ADR-022 corrections: the spec's `Invoice`/`Obligation` "register from obligation-financial-administration" does not exist in this app — `Invoice`/`InvoiceLine` are created in this change's own register.d fragment (not an edit to the monolith), GL posting reuses the existing `JournalEntry` lifecycle action, time = `UrenRegistratie`, expense = `Receipt`, customer = `Project.customerId` contact reference (never an invented schema), rates = `RateCard`.

## Tasks

- [x] Task 1: Validate dependency registers. `rate-card-engine` → existing `RateCard`/`RateRecord`/`RateSchedule`/`RateCardVersion` schemas present in monolith; `retainer-billing-engine` → resolved as `RateSchedule` (retainer schedule). Time = `UrenRegistratie`, expense = `Receipt`/`ExpenseClaimEntry`. GL = `JournalEntry`/`GLTransaction`/`Account` (foundation fragment). All reuse targets confirmed present in `lib/Settings/`.
- [x] Task 2 (corrected per ADR-022): `Invoice`/`Obligation` register from `obligation-financial-administration` does NOT exist in shillinq. Created `Invoice` schema in the change's own `register.d/invoice-from-time-and-expense.json` fragment (ADR-037 — not an edit to the monolith). "Obligation" is satisfied by the materialised `JournalEntry` (AR booking) — no separate Obligation schema invented.
- [x] Task 3: `TimeEntry`→`UrenRegistratie` (hours, recognisedRate, personId, projectId) and `Expense`→`Receipt` (amount, category, receiptDate) confirmed accessible in the monolith register; referenced by id from `Invoice.timeEntryIds`/`expenseIds`.
- [x] Task 4: `specs/invoice-from-time-and-expense/spec.md` authored (proposal stage; moved into the capability subfolder so `openspec change validate --strict` parses the ADDED deltas).
- [x] Task 5: `proposal.md` authored (proposal stage).
- [x] Task 6: `design.md` authored (proposal stage).
- [x] Task 7 (corrected ADR-037): `Invoice` schema declared in `lib/Settings/register.d/invoice-from-time-and-expense.json` with billingModel (5-model enum), timeEntryIds, expenseIds, rateCardId, retainerScheduleId, customerId, projectId, lineItemsByModel, vatRate, glTransactionId/journalEntryId, administrationId, state. `summary`/`posted` replaced by declarative `x-openregister-aggregations` (net/vat/gross) and the `state` lifecycle (`posted` is a state, not a boolean) — the canonical pattern in this app.
- [x] Task 8 (corrected ADR-037): `InvoiceLine` schema declared in the same fragment: sourceType (enum incl. milestone), sourceId, rateApplied (json snapshot, design D2), billableUnits, markup, modelSpecificFields (json), costAmount (cents), vatRate.
- [x] Task 9–10 (N/A — corrected): No DB migration layer. OpenRegister owns object storage; the register fragment IS the schema definition (deep-merged + version-gated re-import via `SettingsService::loadRegisterConfigData`). Deduplication is enforced at the lifecycle precondition (`InvoiceGuard`), not a DB index.
- [x] Task 11 (corrected ADR-031/ADR-022): No `InvoiceGenerationService.php`. `draftInvoice` = OR object create; `calculateNetAmount` = declarative `Invoice.netAmount` aggregation; `postInvoice` = the declarative `post` lifecycle transition (materialise-journal-entry action) gated by `OCA\Shillinq\Lifecycle\InvoiceGuard::canPost`. GL posting reuses the existing `JournalEntry` lifecycle action (Debit 1300 AR / Credit revenue / Credit 1500 BTW).
- [x] Task 12 (corrected ADR-031): No `BillingModelEngine.php`. Per-line cost is authored on the `InvoiceLine.costAmount` (operator/UI computes from RateCard at draft); VAT/gross derive declaratively via `InvoiceLine` `x-openregister-calculations`; invoice roll-ups via `Invoice` `x-openregister-aggregations`. The five-model differences are captured by which `sourceType` lines exist (D5 fixed-fee hides time cost, D3 retainer adds retainer_charge, D6 milestone line) and validated by `InvoiceGuard`.
- [x] Task 13 (corrected ADR-031): `deduplicateSourceIds` is the `InvoiceGuard::sourceIdsAreUnique` cross-schema precondition (real OR ObjectService `findAll`), fail-closed, denying post when any time/expense id overlaps a non-cancelled invoice.
- [x] Task 14 (corrected ADR-031): No `VATCalculationService.php`. Dutch BTW (21/9/6/0) is the `vatRate` enum + declarative `lineVatAmount`/`lineGrossAmount` calculations and `Invoice.vatAmount`/`grossAmount` aggregations.
- [x] Task 15–18 (corrected manifest-v2): No hand-written Vue. `src/manifest.d/invoice-from-time-and-expense.json` declares the `Billing › Invoices` menu, an `index` page (invoiceNumber, dates, billingModel, customer, grossAmount, state) and a `detail` page (full header + net/vat/gross + applied rate card/retainer + state). Line-item review and PDF export are rendered by the OR detail relation view; bespoke generator/list/review/detail components are not part of this declarative shell.
- [x] Task 19 (corrected): No `InvoiceApiController.php`. CRUD + the `post`/`cancel` lifecycle transitions are served by OpenRegister's generic object API; the static-before-wildcard ordering in `appinfo/routes.php` is unchanged (no new app routes needed).
- [ ] Task 20 (DEFERRED): PDF generation via template (mPDF). Out of scope per `design.md` Non-Goals / proposal "PDF generation" is delivery-tooling; deferred to a follow-up that adds the docudesk template binding. See issue note below.
- [x] Task 21 (corrected): No bespoke `invoiceApi.js`; the declarative pages use OR's generic object store (`createObjectStore`), so no per-feature REST client is hand-written.
- [x] Task 22 (corrected): Seed data ships in the fragment `components.objects[]` (three worked invoices + their lines: T&M 2026-001, fixed-fee 2026-002, retainer 2026-003), matching `design.md` examples; loaded idempotently by the existing fragment importer. RateCard/RetainerSchedule seeds belong to their own engine changes (referenced by id).
- [x] Task 23 (corrected): No `InvoiceGenerationRequest.php` PHP class — the draft inputs are the `Invoice` object fields themselves (validated by the schema `required`/`enum`); the `InvoiceGuard` validates cross-object preconditions on post.
- [x] Task 24 (corrected, design D2): Rate snapshotting is the immutable `InvoiceLine.rateApplied` json ({rate, currency, rateCardVersion, effectiveDate}); resolution from RateCard happens at draft (mirrors `UrenRegistratie.recognisedRate`).
- [x] Task 25 (corrected): Retainer amount/effectiveDate is captured in the retainer_charge line's `modelSpecificFields.retainerMonth` + `costAmount` snapshot; historical invoices retain their amount when the schedule changes (design D2/risk 2).
- [x] Task 26–29 (re-mapped tests): `tests/Unit/Lifecycle/InvoiceGuardTest.php` covers post preconditions for all five models (T&M unique-source post, no-lines deny, retainer mandatory line, milestone completion gate, dedup deny/release, fixed-fee no-source post, cancel draft-only, fail-closed). `tests/Unit/Service/InvoiceFromTimeExpenseFragmentTest.php` covers schema declarations, the 5-model enum, declarative VAT/roll-up logic, guard references, additive merge, seed integrity and the design.md T&M €8,150 net arithmetic.
- [x] Task 30 (corrected): No `InvoiceApiControllerTest.php` (no controller). The post/dedup/conflict behaviour the integration test would assert is covered by `InvoiceGuardTest` against the real lifecycle precondition.
- [x] Task 31–34 (corrected): Fixtures are the fragment seed objects + the inline arrays in the unit tests (T&M mixed rates + expense, fixed-fee with zero-cost time bucket, retainer + overage). Separate PHP fixture classes are unnecessary for the declarative model.
- [x] Task 35 (corrected path): i18n strings added additively to `l10n/en.json` and `l10n/nl.json` (the app's real l10n location — not `src/locales`): Billing, Invoice(s), Invoice number/date, Due date, Billing model, Time & materials, Fixed fee, Milestone, Retainer, Mixed, Time entry, Expense, Retainer charge, Rate card, Line item, Net/BTW/Gross amount, Post to AR, Cancel draft, Generate invoice, Draft/Posted.
- [ ] Task 36 (DEFERRED): `docs/user-guide/shillinq/invoice-generation.md` journeydoc with screenshots — needs a live instance to capture screens (ADR-030 capture-driven); deferred to a verify/journeydoc pass.
- [ ] Task 37 (DEFERRED): `docs/api/invoice-generation.md` — the API surface is OR's generic object API; a thin reference page is deferred with Task 36.
- [x] Task 38: `composer check:strict` (phpcs/phpmd/psalm/phpstan + phpunit) run and green for the touched files; fragment/guard/l10n/manifest JSON validated. `openspec validate` exit-clean target tracked in Verification.
- [x] Task 39 (process — Hydra): PR opened by the hydra-build flow against `development`; not an opsx task.
- [ ] Task 40 (DEFERRED — process): GitHub/Codeberg issue template is a repo-governance task, tracked by Hydra, not part of the implementation.

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

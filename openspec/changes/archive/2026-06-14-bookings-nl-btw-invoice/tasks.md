# Tasks — Invoice VAT + Kassakoppeling Compliance

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-invoice-vat-kassakoppeling` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-invoice-vat-kassakoppeling` capability spec already exists, no `VATAuditRecord` schema is declared, and no `lib/Service/VAT*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "extends the original Shillinq invoicing scope with Dutch VAT compliance"

- [x] Task 2: Author `specs/bookkeeping-invoice-vat-kassakoppeling/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operational + NL regulatory)` / `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-receivable-core (T2)` header; cite all REQ-VAT-001 through REQ-VAT-010 using RFC 2119 keywords; include all `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects (shillinq + optional belastingdienst-gateway) / Scope (InvoiceLine extension, VATAuditRecord schema, VAT accrual lifecycle, manifest entries) / Risks (kassakoppeling detail level, reverse-charge VAT scope, payment-date vs issue-date accrual, rounding thresholds) / Open Questions (5 questions listed) / Rollback plan

- [x] Task 4: Author `design.md` with Decisions table (D1 through D6), Reuse Analysis table, Declarative-vs-Imperative decision table, Seed Data (4 standard NL VAT rates), Risks / Trade-offs, Migration Plan, Implementation Notes (GL account mapping, timezone, currency, precondition failure)

- [x] Task 5: Extend the `InvoiceLine` schema in `lib/Settings/shillinq_register.json` with three new fields per REQ-VAT-001:
  - `vatRate` (enum: 21, 9, 6, 0; default: administration's standard rate)
  - `vatAmount` (decimal; computed from `lineAmount × vatRate / 100` using banker's rounding)
  - `serviceCategory` (enum: "product", "service", "exempt"; default: "product")

- [x] Task 6: Declare new immutable `VATAuditRecord` schema in `lib/Settings/shillinq_register.json` per REQ-VAT-004 with all fields (invoiceNumber, invoiceDate, lineSequence, lineDescription, lineAmount, vatRate, vatAmount, serviceCategory, lifecycleEvent, eventDate, paymentDate, settlementPeriod FK, administrationId FK); mark as append-only (no update/delete operations)

- [x] Task 7: Add `x-openregister-lifecycle` to `ARInvoice` schema declaring VAT accrual materialisation on `issued` transition (REQ-VAT-003) — creates balanced GL transaction with debit to AR control and credits to VATPayable21/9/6/0 by rate bucket; materialisation template captures VAT bucket mapping

- [x] Task 8: Add precondition to `ARInvoice.issue` lifecycle transition validating service-category per REQ-VAT-002 (product permits 21/6/0; service permits 21/9/0; exempt permits only 0); precondition logs failure with guidance linking to admin settings or override mechanism; generate audit-trail entry if override is applied

- [x] Task 9: Declare service-category override mechanism in `lib/Settings/shillinq_register.json` as new `ServiceCategoryOverride` schema with fields (serviceCategory, vatRate, administrationId, reason, createdAt, createdBy) for exceptions; validation in REQ-VAT-002 checks overrides before rejection

- [x] Task 10: Declare VAT GL account configuration in `lib/Settings/shillinq_register.json` as `VATGLAccounts` schema with fields (administrationId, vat21Account, vat9Account, vat6Account, vat0Account, createdAt, updatedAt) per REQ-VAT-006; installer script sets defaults (2020, 2021, 2022, 2023); admin UI validates all four accounts exist and are unique

- [x] Task 11: Create seed data file `lib/Data/VAT/nl_vat_rates_2026.json` (4 standard Dutch rates: 21%, 9%, 6%, 0%) per design.md Seed Data; installer inserts into `TaxRate` register on first run; mark as read-only / version-locked

- [x] Task 12: Implement VAT-by-period aggregation query in OpenRegister materialization template per REQ-VAT-009 (returns totalNetAmount, totalVAT21/9/6/0, totalGrossAmount, invoiceCount, recordCount); query groups `VATAuditRecord` by `settlementPeriod` and aggregates `vatAmount` by `vatRate`

- [x] Task 13: Declare `TaxPeriod` reference binding on `VATAuditRecord` per REQ-VAT-005 — when VAT accrual materializes, settlementPeriod is populated based on invoice-issuance date + administration's filing-frequency setting (monthly/quarterly/annual); immutable once set; old records survive admin reconfiguration

- [x] Task 14: Add rounding helper function per REQ-VAT-007 (banker's rounding for per-line VAT: ROUND(amount × rate / 100, 2)) to be used in VAT-amount computation; validate that invoice total VAT = sum of line VAT amounts (no invoice-level rounding adjustment)

- [x] Task 15: Add 2 manifest navigation entries per REQ-VAT-010:
  - "VAT by Period" (type: index) — lists all tax periods with summary totals (net, VAT by rate, gross); links to detail page
  - "VAT Reconciliation" (detail page for a single period) — shows invoices, line-by-line audit records, GL account balances, "Ready for Filing" checklist

- [x] Task 16: Add error-handling and precondition-failure messages per REQ-VAT-008 (e.g., "Service category 'repair' does not permit 21% VAT. Check admin settings for overrides." with navigation link if possible); log full context for API clients

- [x] Task 17: Update `openspec/architecture/adr-000-data-model.md` with `InvoiceLine` (extended), `VATAuditRecord`, `ServiceCategoryOverride`, `VATGLAccounts` entries; reconcile against any existing `Invoice` / `LineItem` data-model entries; confirm no conflicts with T2 AR core

- [x] Task 18: Document VAT GL account mapping guidance in implementation notes — e.g., RGS account 2020 = Belastingdienst VAT payable 21%, 2021 = 9%, 2022 = 6%, 2023 = 0% / exempt; admin can override during setup

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g., `/test-persona-janwillem` for SMB) confirms the VAT flow matches Dutch SMB practice (invoice line VAT selection → rate validation → VAT accrual on issue → settlement period binding → VAT-by-period reporting for filing). Architecture reviewer confirms ADR-022 + ADR-031 compliance (immutable audit trail, lifecycle-materialised GL posting, no app-local VAT service). No source code changes outside `openspec/changes/bookings-nl-btw-invoice/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests**: InvoiceLine VAT amount computation (banker's rounding), service-category validation (all permitted / rejected combos), VAT GL posting materialisation (correct bucket sums), rounding no-discrepancy (invoice total = line sums), settlement-period binding (period persists after admin reconfig), precondition failure (blocking with guidance), VAT-by-period aggregation (SUM/GROUP BY accuracy)
- **Playwright MCP browser tests**: VAT rate selection on invoice line, service-category dropdown + validation feedback, admin VAT GL account configuration, VAT by Period index (summary totals), VAT Reconciliation detail (line audit records + GL balances), "Ready for Filing" checklist mark-off
- **Integration tests**: Full invoice lifecycle (draft → issue with VAT accrual → payment with audit record update → write-off with reverse accrual), multiple invoices per period with mixed rates, settlement period switching across month boundary
- `composer test` green at the implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/invoice-vat-kassakoppeling.md` per ADR-030 journeydoc convention:
  - Invoice creation with service/product selection
  - VAT rate assignment (auto-suggested per category)
  - VAT GL account configuration (admin panel)
  - VAT-by-period reporting + filing workflow
  - Example VAT filing (May 2026 sample)
  - Troubleshooting (invalid rate, overrides, settlement period mismatches)
- Commits screenshots to `docs/images/bookkeeping/` showing: invoice line VAT fields, service-category dropdown, VAT by Period summary, VAT Reconciliation detail, GL posting verification

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Bookkeeping` (menu parent)
- `VAT by Period` (menu entry)
- `VAT Reconciliation` (menu entry)
- `VAT Rate` (field label)
- `Service Category` (field label)
- `Product` (enum value)
- `Service` (enum value)
- `Exempt` (enum value)
- `Standard (21%)` (VAT rate label)
- `Reduced Services (9%)` (VAT rate label)
- `Books/Media (6%)` (VAT rate label)
- `Exempt / Export (0%)` (VAT rate label)
- `Service category 'X' does not permit Y% VAT` (error message)
- `Cannot issue invoice: VAT GL accounts not configured` (error message)
- `Check admin settings for service-category overrides` (guidance)
- `Ready for Filing` (checklist label)
- `VAT Audit Records` (table header)
- `Settlement Period` (field label)
- `Total Net Amount` (aggregate label)
- `Total VAT 21%` / `9%` / `6%` / `0%` (aggregate labels)
- `Total Gross Amount` (aggregate label)
- `Invoice Count` (aggregate label)
- `Record Count` (aggregate label)

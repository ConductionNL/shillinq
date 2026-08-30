# Proposal: Invoice from Time + Expense

`kind: feature` — generate invoices from approved billable time entries and expense records with support for multiple billing models (T&M, fixed-fee, milestone, retainer, mixed).

## Summary

Introduce the **invoice generation from time and expense** capability for Shillinq, enabling project managers and billing operators to draft professional invoices from approved time tracking and expense pool data. This change builds on `rate-card-engine` and `retainer-billing-engine` to support multiple billing models:

1. **Time & Materials (T&M)** — hourly rate × hours tracked, plus expenses
2. **Fixed-fee** — flat project fee, expenses as add-ons
3. **Milestone** — payment on milestone completion, expenses as add-ons
4. **Retainer** — monthly recurring fee, expenses tracked separately
5. **Mixed** — combine multiple models in a single invoice (e.g., retainer base + T&M overage)

This change introduces the `Invoice` register (if not present in prior tiers), extends it with time/expense line-item tracking, and exposes invoice operations via:

1. Admin interface (draft invoice from time+expense, apply rate cards, preview)
2. REST API (generate invoice, list invoices, post to accounts payable)
3. Accounting integration (GL posting via T2 commitment)

This change conforms to the shared Shillinq bookkeeping model and follows ADR-000 entity definitions.

## Motivation

Competitor analysis (16/26 benchmarks) shows that invoice generation from tracked time and expenses is table-stakes:

- **Akaunting, Harvest, Clockify, Everhour, Kimai, Kantata** — all auto-generate invoices from approved time + expenses; support multiple billing models
- **Operators need** — speed invoicing, accurate billing, mix billing models per engagement, reduce manual entry
- **Customers need** — transparent invoice matching approved time, itemized expenses, clear T&M vs. fixed-fee charges

The Shillinq app currently has time tracking and expense capture (via T1/T2) but no invoice generation capability. Operators must manually draft invoices in external tools, losing accuracy, creating reconciliation delays, and preventing on-time payment processing.

Until invoice generation is in place, the app cannot serve production invoicing workflows where speed, accuracy, and model flexibility matter.

## Affected Projects

- [x] **Project: shillinq** — adds Invoice register extensions, admin UI for invoice drafting, invoice preview/PDF generation, GL posting logic
- [x] **Project: openregister** — consumes existing OR abstractions (CRUD, validation, relations); no new OR features required
- [x] **Project: rate-card-engine** — provides hourly rates, daily rates, project rates; invoice generation looks up rates from this engine
- [x] **Project: retainer-billing-engine** — provides retainer schedules; invoice generation applies retainer deductions

## Scope

### In Scope

- **One new spec** (`obligation-invoicing-from-time-and-expense`) defining invoice generation workflow, line item types, billing model support
- **Invoice register extensions** — adds fields for time source (timeEntryIds), expense source (expenseIds), billing model (t_and_m, fixed_fee, milestone, retainer, mixed), rate card reference, retainer reference
- **InvoiceLine extensions** — adds fields for source type (time_entry, expense, retainer_charge, manual), rate/cost, billable hours/units, multiplier (for T&M markup), model-specific fields
- **Invoice generation service** — REST endpoint to draft invoice from time entries + expenses, apply rate card, calculate totals, support all 5 billing models
- **Rate card integration** — look up hourly/daily rates from rate-card-engine, apply markup/discount rules
- **Retainer integration** — apply monthly retainer charge, track T&M overage separately
- **Invoice preview** — show draft invoice before posting to AR, with line-item breakdown by billing model
- **Admin invoice interface** — draft invoice form, rate selection, line-item review, apply retainer, post to AR
- **GL posting** — post invoice to accounts payable (Obligation register), create AR account entries
- **PDF generation** — export invoice to PDF with Dutch VAT format (invoiceNumber, invoiceDate, dueDate, lineItems with VAT)

### Out of Scope

- **Payment processing** — invoice-to-payment transition is T3+ (accounts-receivable-collection)
- **Revenue recognition** — milestone-based revenue recognition logic is T3+
- **Multi-currency invoicing** — single-currency (EUR) in T2; multi-currency is T4-base
- **Subscription/recurring invoicing** — one-time invoices in T2; recurring invoices are T3+
- **Deposit/down-payment deduction** — handled in T3 deposit-to-invoice spec
- **Invoice approval workflow** — assumed available from T2 approval-workflow-management; this change uses existing approval chain

## Approach

One new spec (`obligation-invoicing-from-time-and-expense`) with ADDED requirements:

1. **InvoiceGenerationRequest** — workflow entity capturing billing model, rate card, retainer, time filter (date range), expense filter, customer
2. **Invoice schema extensions** — fields for time source, expense source, billing model, rate card reference, retainer reference, timeEntryIds, expenseIds
3. **InvoiceLine schema extensions** — source type, rate applied, billable units, markup, model-specific fields (milestone ID for milestone model, retainer month for retainer model)
4. **Invoice generation workflow** — REQ-ITE-NNN series covering: draft invoice, apply rate card, calculate totals, validate billing model, post to AR
5. **Billing model logic** — distinct fee calculation for each model (T&M = hours × rate + expenses; fixed = flatFee + expenses; milestone = milestoneAmount + expenses, etc.)
6. **Line-item grouping** — group time entries by day/rate/resource; group expenses by category; show retainer separately
7. **GL posting** — create AR Obligation from invoice, post GL entries for revenue recognition

The spec follows conduction-schema format (RFC 2119, `### REQ-ITE-NNN`, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

**Existing dependencies assumed available**:
- `rate-card-engine` — provides RateCard register with hourly/daily/project rates and markup rules
- `retainer-billing-engine` — provides RetainerSchedule register with monthly retainer amounts and T&M overage tracking
- `obligation-financial-administration` — provides Invoice and Obligation registers
- `accounts-payable-receivable` — provides Payee entity
- `bookkeeping-journal-entries` — provides GL posting mechanism

**New OpenRegister abstractions**:
- None; uses existing Invoice, Obligation, InvoiceLine, TimeEntry, Expense, RateCard, RetainerSchedule registers

## Impact

- `lib/Settings/shillinq_register.json` — extends Invoice schema with 8 fields (timeEntryIds, expenseIds, billingModel, rateCardReference, retainerReference, lineItemsByModel, summary, posted); extends InvoiceLine schema with 6 fields (sourceType, rateApplied, billableUnits, markup, modelSpecificFields, costAmount)
- `src/components/InvoiceGenerator.vue` — new file, invoice drafting form (billing model selection, rate card picker, retainer selector, date filters, preview)
- `src/components/InvoiceLineItemReview.vue` — new file, line-item breakdown by source (time entries, expenses, retainer charge)
- `src/views/AdminInvoiceList.vue` — new file, invoice list with status, draft/posted indicator, action buttons (view, edit draft, post, PDF)
- `src/api/invoiceGenerationApi.js` — new file, REST client for invoice generation endpoints
- `lib/Service/InvoiceGenerationService.php` — new file, invoice drafting, rate card lookup, retainer deduction, GL posting
- `lib/Service/BillingModelEngine.php` — new file, billing model-specific logic (T&M calculation, fixed-fee, milestone, retainer, mixed)
- Tests — 20+ unit + integration tests covering all 5 billing models, rate card application, retainer integration, GL posting
- `src/manifest.json` — adds 1 new admin section (Invoices) + invoice generation action

## Cross-Project Dependencies

- **rate-card-engine** — depends on: RateCard register (existing), rate lookup (existing), markup rules (existing)
- **retainer-billing-engine** — depends on: RetainerSchedule register (existing), retainer amount lookup (existing), overage tracking (existing)

## Risks

### Risk 1: Rate Card Change Does Not Affect Historical Invoices

**Severity**: Low
**Description**: If a rate card is updated, existing draft invoices with references to that rate card might pick up new rates when re-previewed, leading to unexpected cost changes.
**Mitigation**: Invoice stores applied rates as line-item details at invoice generation time; rates are snapshot'd, not dynamically looked up. If a rate card changes, existing invoices retain their original rates. Admin can regenerate invoice if needed.

### Risk 2: Retainer Month Boundary Confusion

**Severity**: Low
**Description**: If a retainer schedule changes mid-month (e.g., monthly fee increases), determining which month's charges apply to an invoice covering a period across the change date is ambiguous.
**Mitigation**: RetainerSchedule stores effective dates; invoice generation captures the retainer amount as of the invoice date. If retainer changes mid-month, the invoice uses the retainer amount effective on the invoice date. Admin can manually adjust if needed.

### Risk 3: Billing Model Mismatch with Time Entry Tags

**Severity**: Medium
**Description**: Admin selects "fixed-fee" billing model but the underlying time entries are tagged as "billable T&M"; the invoice ignores T&M tags, potentially confusing operators.
**Mitigation**: Invoice generation shows a warning if time entries have billable tags that don't match the selected billing model. Admin must confirm they want to override tags.

### Risk 4: Expense Categorization Missing

**Severity**: Low
**Description**: Expenses don't have categories or GL account mappings; posting to AR requires manual GL account assignment, slowing invoicing.
**Mitigation**: T2 expense-capture-core defines expense categories; invoice generation assumes categories exist and uses them for GL posting. If a category lacks GL mapping, generation fails with clear error.

### Risk 5: Double-Invoicing Risk

**Severity**: High
**Description**: Admin generates invoice for Jan 1-31, then accidentally generates another for Jan 15-31; the second invoice includes duplicate time entries + expenses, causing over-billing.
**Mitigation**: Invoice tracks sourceIds (timeEntryIds, expenseIds) in a deduplicated set. Before generation, system checks whether any sourceId is already referenced by a non-draft invoice in the same period. If conflicts detected, generation fails with list of conflicting invoices.

## Rollback Strategy

Spec-only change initially. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation:

1. Prevent new invoice generation (disable InvoiceGenerator button)
2. Mark all draft invoices as superseded
3. Keep posted invoices immutable for audit trail
4. Revert implementation PRs in standard order

No data migration risk at the spec stage.

## Open Questions

1. **Billing model per project vs. per engagement** — should billing model be set once per project/engagement, or per invoice? Current proposal assumes per-invoice flexibility; product decides if this is over-complex.
2. **Rounding and currency precision** — Dutch invoices require €0.01 precision; fractional calculations (hourly rate × 7.5 hours) are rounded how? Server-side banker's rounding or customer-facing rounding?
3. **Retainer partial months** — if a retainer starts mid-month, does the first invoice show a pro-rata charge or full monthly amount? When does the retainer cycle reset?
4. **Markup/discount per line item vs. per invoice** — should markup be applied per time entry (e.g., 10% markup on specific client engagement) or globally per invoice? Current proposal is per invoice; line-item markup is T3+.
5. **Invoice template and branding** — does invoice PDF generation use a fixed template or is there a template selector? Branding (logo, terms, footer) scope?
6. **Multi-project invoices** — can a single invoice span time entries from multiple projects? Current proposal is single-project per invoice; multi-project is T3+.

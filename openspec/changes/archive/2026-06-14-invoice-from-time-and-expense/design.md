# Design — Invoice from Time + Expense

**status: draft**

## Context

Shillinq is a full-stack bookkeeping app for Dutch SMBs. T1 foundational layers (chart of accounts, general ledger, journal entries) are in place; T2 compliance layer (accounts payable/receivable, bank reconciliation) is live. Time tracking and expense capture are available via T2 modules. Operators currently cannot generate invoices from tracked time and expenses; they must manually draft invoices, leading to errors, delays, and reconciliation issues.

This change defines invoice generation from time + expense with support for 5 billing models (T&M, fixed-fee, milestone, retainer, mixed), rate card integration, and GL posting. Operators can:

1. **Admin** — draft invoice, select billing model, apply rate card/retainer, review line items, post to AR
2. **System** — validate billing model, look up rates, calculate totals, post GL entries, prevent double-invoicing
3. **Integration** — expose REST API for invoice generation, enabling third-party billing tools

## Goals

- **Speed invoicing** — draft invoice from time+expense in seconds, not hours
- **Accuracy** — auto-apply rate cards, prevent duplicate line items, validate totals
- **Flexibility** — support all 5 billing models (T&M, fixed, milestone, retainer, mixed) per engagement
- **Audit trail** — every invoice generation logged with rates applied, GL posting, AR entry
- **Dutch compliance** — invoice format with VAT, invoiceNumber, invoiceDate, dueDate per NL law

## Non-Goals

- **Payment processing** — invoice-to-payment is T3+ (accounts-receivable-collection)
- **Revenue recognition** — milestone-based revenue recognition is T3+
- **Multi-currency** — single-currency (EUR) in T2; multi-currency is T4-base
- **Recurring invoicing** — one-time invoices in T2; recurring/retainer invoices are T3+
- **Deposit deduction** — handled in T3 deposit-to-invoice spec
- **Invoice approval workflow** — assumes T2 approval-workflow-management is available

## Decisions

### D1 — Billing Model as Invoice-Level Attribute

**Decision**: `Invoice.billingModel` is one of: `t_and_m`, `fixed_fee`, `milestone`, `retainer`, `mixed`. Set at invoice generation time, immutable after posting.

**Why**: Operators may choose different models per engagement (yoga classes on retainer, consulting on T&M). Model is set once per invoice to define how line items are calculated and grouped. Model is immutable (no retroactive changes post-posting) to preserve audit trail.

**Alternative**: Billing model is inherited from Project. Rejected — different engagements on same project may use different models (e.g., initial consulting phase on T&M, then retainer).

### D2 — Rate Card Snapshot at Invoice Time

**Decision**: When invoice is generated, applicable rates from RateCard are snapshot'd into InvoiceLine objects as `rateApplied` field (value, currency, effective date). Historical invoices preserve original rates even if RateCard changes.

**Why**: If a rate card is updated (e.g., hourly rate rises 5%), historical invoices should not be affected retroactively. Operator can manually regenerate invoice if they want new rates.

**Alternative**: Store only rateCardId; look up rates dynamically at invoice view time. Rejected — retroactively changes historical invoices, violating audit expectations.

### D3 — Retainer Deduction as Separate Line Item

**Decision**: For retainer billing model, invoice includes a mandatory line item `{sourceType: retainer_charge, description: "Retainer — January 2026", costAmount: 5000}`. T&M overage (if any) is shown separately as additional line items.

**Why**: Clear separation of fixed retainer from variable T&M. Operators can see month-by-month retainer charges. GL posting correctly allocates retainer revenue vs. T&M revenue.

**Alternative**: Retainer is implicitly applied; only T&M overage shows as line items. Rejected — doesn't show retainer amount on invoice, confusing to customers.

### D4 — Time Entry Grouping: By Rate + Resource

**Decision**: When generating T&M invoice, time entries are grouped as follows: for each unique (rate, resource, date) tuple, create one line item showing total hours and cost. Within a line item, break down by date or task (TBD by product).

**Why**: Reduces line-item clutter for long projects (1000 hours across 50 days shows as ~50 line items, not 1000). Operators can adjust grouping granularity.

**Alternative**: One line item per time entry. Rejected — invoices become huge, hard to scan. One line item per resource. Rejected — hides per-day rate variations.

### D5 — Fixed-Fee Invoices Treat Time as Informational

**Decision**: For fixed-fee model, time entries are captured in invoice.timeEntryIds but NOT shown as line items (they're for audit trail). Invoice shows single line item: `{sourceType: fixed_fee, description: "Project: Widget API — Q1 2026", costAmount: 50000}`. Actual hours worked are visible in invoice metadata (audit trail) but not on the invoice itself.

**Why**: Customers pay flat fee regardless of hours; showing hours on invoice invites negotiation ("why did it take 200 hours?"). Hours are retained for internal analysis.

**Alternative**: Show hours on invoice. Rejected — invites disputes, violates fixed-fee model semantics.

### D6 — Milestone Model Links to Milestone Completion

**Decision**: For milestone-based model, InvoiceLine includes `modelSpecificFields: {milestoneId, milestoneCompletedAt, milestoneName, milestoneBudget}`. Invoice is generated only after milestone is marked complete. Milestone completion triggers invoice draft creation (auto-generated or manual, TBD).

**Why**: Prevents invoicing incomplete work. Audit trail shows milestone completion date, clear accountability.

**Alternative**: Invoices can be generated against in-progress milestones. Rejected — risks invoicing incomplete work, customer disputes.

### D7 — Mixed Model Combines T&M + Retainer + Fixed

**Decision**: Mixed model allows multiple source types in single invoice: retainer charge (mandatory), T&M line items (if any hours logged above retainer), optional fixed line items (e.g., setup fee). Line items are clearly labeled by source type.

**Why**: Reflects real engagements (retainer base, plus overage, plus one-time fees). Customers understand what they're paying for.

**Alternative**: One model per invoice, no mixing. Rejected — doesn't support real billing scenarios (e.g., monthly retainer + overage).

### D8 — GL Posting: Obligation + Accounts Receivable

**Decision**: When invoice is posted (moved from draft to posted), system creates an Obligation entity and posts GL entries:
- **Debit** AR account (Accounts Receivable increase)
- **Credit** appropriate revenue accounts (T&M revenue, retainer revenue, fixed revenue, by source type)
GL posting is synchronous at post time; reversals are handled by credit notes (T3+).

**Why**: Invoice posting is the moment the obligation is created. GL entries reflect revenue recognition at invoice time (accrual accounting). Separate GL accounts per revenue type support reporting.

**Alternative**: GL posting is async (queued task). Rejected — operator needs immediate confirmation; queued posting adds latency and error handling complexity.

### D9 — Double-Invoice Prevention: Deduplication by Source ID

**Decision**: Before generating invoice, system checks whether any timeEntryId or expenseId is already referenced by a posted/draft invoice in an overlapping period (e.g., Jan 1-31). If conflicts found, generation fails with error listing conflicting invoices. Admin must resolve conflicts (e.g., cancel old draft, regenerate).

**Why**: Prevents accidental duplicate billing. Deduplication is done at source ID level (exact match).

**Alternative**: No checks; rely on operator diligence. Rejected — high risk of error, customer disputes.

### D10 — PDF Generation: Template-Based

**Decision**: Invoice PDF is generated from a template (stored in code or DB). Template includes placeholders for: invoiceNumber, invoiceDate, dueDate, creditor (name, VAT, IBAN), recipient (name, VAT), line items, totals, VAT breakdown, payment terms, notes. Template is Dutch-compliant (VAT label as "BTW", currency as "€").

**Why**: Standardized, professional appearance. Template is versioned; invoices generated on different dates may use different templates (important for regulatory compliance).

**Alternative**: PDF generation is manual (operator uses external tool). Rejected — doesn't meet "speed invoicing" goal. HTML rendering without template. Rejected — fragile, hard to maintain.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Invoice register CRUD | `Invoice` register (from obligation-financial-administration) | Invoice schema is extended with 8 fields (timeEntryIds, expenseIds, billingModel, etc.); existing CRUD endpoints used. |
| Rate card lookup | `RateCard` register (from rate-card-engine) | InvoiceGenerationService queries RateCard at generation time; rates are snapshot'd into InvoiceLine objects. |
| Retainer lookup | `RetainerSchedule` register (from retainer-billing-engine) | InvoiceGenerationService queries RetainerSchedule; retainer amount is applied as line item. |
| Time entry source | `TimeEntry` register (from time tracking) | Invoice.timeEntryIds store references; InvoiceGenerationService reads time entries to extract hours, resource, date. |
| Expense source | `Expense` register (from expense-capture-core) | Invoice.expenseIds store references; InvoiceGenerationService reads expenses to extract category, amount. |
| GL posting | Chart of Accounts, GL entry mechanism (from T1) | InvoiceGenerationService posts GL entries via existing journalEntry creation; uses Account codes for AR, revenue. |
| Obligation creation | `Obligation` register (from obligation-financial-administration) | Invoice posting creates Obligation entity; Invoice → Obligation is 1:1 relation. |
| Audit trail | OR audit-trail abstraction | Every invoice generation, rate change, GL posting is logged with actor, timestamp, amounts. |
| Admin UI | Vue admin dashboard boilerplate | Standard admin layout; InvoiceGenerator is a new component; invoice list gains actions (view, edit, post, PDF). |

## Seed Data

**Example 1: T&M Invoice (Consulting)**
```json
{
  "invoiceNumber": "2026-001",
  "invoiceDate": "2026-05-21",
  "dueDate": "2026-06-20",
  "billingModel": "t_and_m",
  "rateCardId": "rate-card-consulting",
  "retainerScheduleId": null,
  "creditor": {"legalName": "TechCorp BV", "vatID": "NL123456789", "iban": "NL91ABCD0123456789"},
  "recipient": {"legalName": "ClientCorp BV", "vatID": "NL987654321"},
  "timeEntryIds": ["time-entry-001", "time-entry-002", "time-entry-003"],
  "expenseIds": ["expense-001"],
  "lineItems": [
    {"lineNumber": 1, "sourceType": "time_entry", "description": "Senior Consultant — 40 hours @ €150/hr", "quantity": 40, "unitPrice": 15000, "lineAmount": 600000, "vatRate": 21},
    {"lineNumber": 2, "sourceType": "time_entry", "description": "Junior Consultant — 20 hours @ €100/hr", "quantity": 20, "unitPrice": 10000, "lineAmount": 200000, "vatRate": 21},
    {"lineNumber": 3, "sourceType": "expense", "description": "Travel costs — Amsterdam to Rotterdam", "quantity": 1, "unitPrice": 15000, "lineAmount": 15000, "vatRate": 21}
  ],
  "netAmount": 815000,
  "vatAmount": 171150,
  "grossAmount": 986150,
  "paymentTerms": "net 30",
  "status": "posted"
}
```

**Example 2: Fixed-Fee Invoice (Web Design)**
```json
{
  "invoiceNumber": "2026-002",
  "invoiceDate": "2026-05-21",
  "dueDate": "2026-06-20",
  "billingModel": "fixed_fee",
  "rateCardId": null,
  "retainerScheduleId": null,
  "creditor": {"legalName": "DesignStudio BV", "vatID": "NL234567890", "iban": "NL91BCDE0123456789"},
  "recipient": {"legalName": "StartupCorp BV", "vatID": "NL876543210"},
  "timeEntryIds": ["time-entry-004", "time-entry-005", "time-entry-006", "time-entry-007"],
  "expenseIds": [],
  "lineItems": [
    {"lineNumber": 1, "sourceType": "fixed_fee", "description": "Website redesign — Q2 2026 project", "quantity": 1, "unitPrice": 500000, "lineAmount": 500000, "vatRate": 21}
  ],
  "netAmount": 500000,
  "vatAmount": 105000,
  "grossAmount": 605000,
  "paymentTerms": "net 30",
  "status": "posted"
}
```

**Example 3: Retainer + T&M Invoice (Ongoing Support)**
```json
{
  "invoiceNumber": "2026-003",
  "invoiceDate": "2026-05-21",
  "dueDate": "2026-06-20",
  "billingModel": "retainer",
  "rateCardId": "rate-card-support",
  "retainerScheduleId": "retainer-support-monthly",
  "creditor": {"legalName": "SupportCorp BV", "vatID": "NL345678901", "iban": "NL91CDEF0123456789"},
  "recipient": {"legalName": "EnterpriseCorp BV", "vatID": "NL765432109"},
  "timeEntryIds": ["time-entry-008", "time-entry-009"],
  "expenseIds": [],
  "lineItems": [
    {"lineNumber": 1, "sourceType": "retainer_charge", "description": "Monthly retainer — May 2026", "quantity": 1, "unitPrice": 300000, "lineAmount": 300000, "vatRate": 21},
    {"lineNumber": 2, "sourceType": "time_entry", "description": "Overage — 10 hours @ €100/hr", "quantity": 10, "unitPrice": 10000, "lineAmount": 100000, "vatRate": 21}
  ],
  "netAmount": 400000,
  "vatAmount": 84000,
  "grossAmount": 484000,
  "paymentTerms": "net 30",
  "status": "posted"
}
```

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with Invoice extensions (8 new fields) and InvoiceLine extensions (6 new fields)
2. `src/components/InvoiceGenerator.vue` is added (invoice drafting form with billing model selector, rate card picker, date filters)
3. `src/components/InvoiceLineItemReview.vue` is added (line-item breakdown, rate review)
4. `src/views/AdminInvoiceList.vue` is added (invoice list with status, actions)
5. `lib/Service/InvoiceGenerationService.php` is added (invoice drafting, rate lookup, GL posting)
6. `lib/Service/BillingModelEngine.php` is added (model-specific fee calculation)
7. `src/api/invoiceGenerationApi.js` is added (REST client for invoice endpoints)
8. Database migration creates indexes on `(invoiceNumber, status)` and `(timeEntryIds, expenseIds)` for deduplication queries
9. `src/manifest.json` is patched with one new admin section (Invoices) + invoice generation action
10. Seed rate cards and retainer schedules are loaded via rate-card-engine and retainer-billing-engine migrations

Down-direction: drop Invoice section from manifest, mark all draft invoices as superseded, keep posted invoices immutable, revert implementation PRs.

## Open Questions

1. **Billing model flexibility** — can an engagement have different billing models across multiple invoices (first retainer, then T&M overage)? Or is model locked per engagement? Product decision needed.
2. **Rounding and VAT precision** — Dutch invoices require €0.01 precision. How are fractional amounts (hourly rate × 7.5 hours) rounded? Banker's rounding, floor, or ceil?
3. **Retainer reconciliation** — how are partial months handled? If retainer starts mid-month, is the first invoice pro-rata or full? Does the cycle reset monthly, or is it a rolling window?
4. **Markup per line item vs. per invoice** — should operators apply markup (e.g., 10% for specific clients) per line item or per invoice? Current proposal is per invoice; line-item granularity is T3+.
5. **Invoice approval** — does invoice generation auto-route to approval chain, or is it manual? Does approval status prevent posting?
6. **Multi-project invoices** — should a single invoice span multiple projects? Current proposal is single-project; multi-project is T3+.
7. **Invoice numbering** — is invoiceNumber auto-generated (e.g., sequential 2026-001, 2026-002) or operator-assigned? Batch numbering (invoice-gen-batch-123)?
8. **PDF template storage** — is the invoice PDF template stored in code (version-controlled) or in DB (updatable without deploy)? Regulatory compliance implications?

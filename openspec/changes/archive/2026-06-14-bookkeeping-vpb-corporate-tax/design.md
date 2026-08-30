# Design — Vpb Corporate Tax

## Context

Dutch government enterprises (municipal ondernemingsactiviteiten, state-owned entities) must comply with corporate income tax (vennootschapsbelasting / Vpb) filing and payment obligations. Deadlines vary by fiscal year and entity type. Provisional payments must be tracked against final returns. Quarterly income statements support tax planning and management reviews.

Without dedicated tax management primitives, bookkeepers maintain deadlines in external calendar systems, track payments manually, and prepare tax reports via spreadsheet extraction. This causes information fragmentation, missed deadlines, and reconciliation errors.

This change introduces **spec-only** tax deadline, payment tracking, and reporting capabilities. Implementation lands later through `opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Tax deadlines as a dedicated register, not calendar events

Per ADR-031, `TaxDeadline` is a standalone OpenRegister entity (not a `CalendarEvent` or `Task`). This enables:
- Deep linking to deadline details (payment tracking, related GL postings)
- Audit trail of deadline status changes (draft → submitted → filed → archived)
- Aggregation across multiple administrations for group reporting
- Custom deadline templates per municipality/fiscal year

The alternative (`CalendarEvent` with custom metadata) was rejected — tight coupling to the calendar app and loss of queryability.

### D2 — Tax payments linked to GL postings via account+amount+date

`TaxPaymentTracking` records (date, amount, type: provisional/final/adjustment) are matched to GL postings by `accountNumber + amount + date`. This enables:
- Reconciliation warnings when GL postings and payment records diverge
- Single source of truth (GL is authoritative; payment records are indexing)
- No duplication of payment amounts across systems

The alternative (payment records as the source of truth, GL as output) was rejected — GL is canonical in Shillinq.

### D3 — Tax reports as a declarative aggregation, not a materialised register

The `TaxReport` aggregation (output `QuarterlyTaxStatement` with `schema:Dataset` annotation) filters `GLLine` by:
- Fiscal year + quarter (`startDate` / `endDate`)
- Tax classification tag (`taxTreatment: normal | deductible | nonDeductible | special`)
- Account range (from chart-of-accounts hierarchy)

Produces: revenue, operating expenses, non-operating items, special deductions, net taxable income per quarter.

The alternative (a materialised `TaxReportSnapshot` table) was rejected — the aggregation is cheap, and materialisation introduces sync risk on GL changes.

### D4 — Transaction tagging for tax treatment classification

Postings are tagged with `taxTreatment` (enum: `normal` | `deductible` | `nonDeductible` | `special`) to classify tax treatment:
- **normal** — deductible business expenses (wages, rent, utilities)
- **deductible** — tax-benefit items (R&D credits, green energy investments)
- **nonDeductible** — disallowed expenses (gifts, entertainment, personal use)
- **special** — subject to special rules (dividend received, transfer pricing adjustments)

The alternative (rules-based auto-tagging by account number) was rejected — tax treatment depends on transaction context (why was this entertainment expense paid?), not just the account.

### D5 — Deadline notifications via NotificationService, not custom email

Deadline reminders are dispatched via `NotificationService` (7 days, 1 day before due date). This integrates with Nextcloud's notification UI and respects user notification preferences.

The alternative (custom email service) was rejected — tight coupling to SMTP, poor user control, duplicate functionality.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Deadline data storage | OpenRegister `TaxDeadline` register | Custom schema; leverages `ObjectService` CRUD |
| Payment tracking storage | OpenRegister `TaxPaymentTracking` overlay | Custom schema; relations to GL postings via account+amount+date |
| Tax report aggregation | OpenRegister `x-openregister-aggregations` | Filter `GLLine` by fiscal period + tax tag; group by quarter |
| Search/filter | `IndexService` + `CnFilterBar` | Full-text search on deadline type, status, related GL accounts |
| Bulk actions | `CnMassActionBar` + `ObjectService.saveObject()` | Bulk status update (mark filed, mark paid) |
| Notifications | `NotificationService` | 7-day and 1-day deadline reminders |
| Report export | `ExportService` + `CnMassExportDialog` | Excel/PDF export of quarterly tax statements |
| Audit trail | OR audit-trail-immutable (ADR-022) | Every deadline status change tracked automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 1 entry behind `featureFlags.vpb` |

**Net new code in implementation cycle**: 1 new register (`TaxDeadline`), 1 overlay register (`TaxPaymentTracking`), 1 aggregation (`TaxReport`), 3-5 Vue pages (deadline list, detail, payment tracking, quarterly reports, settings), notification dispatch logic. No new PHP service — all business logic is register configuration + aggregation declaratives.

## Seed Data

### TaxDeadline

Three representative deadlines for a fiscal year (2025):

```json
[
  {
    "@self": {
      "register": "shillinq",
      "schema": "TaxDeadline",
      "slug": "2025-provisional-q1-payment"
    },
    "deadlineDate": "2025-04-20",
    "deadlineType": "provisional-payment",
    "description": "First provisional Vpb payment for FY 2025",
    "fiscalYear": 2025,
    "quarter": 1,
    "status": "pending",
    "relatedPeriodId": "2025-q1"
  },
  {
    "@self": {
      "register": "shillinq",
      "schema": "TaxDeadline",
      "slug": "2025-provisional-q3-payment"
    },
    "deadlineDate": "2025-10-20",
    "deadlineType": "provisional-payment",
    "description": "Third provisional Vpb payment for FY 2025",
    "fiscalYear": 2025,
    "quarter": 3,
    "status": "pending",
    "relatedPeriodId": "2025-q3"
  },
  {
    "@self": {
      "register": "shillinq",
      "schema": "TaxDeadline",
      "slug": "2025-final-return-filing"
    },
    "deadlineDate": "2026-05-01",
    "deadlineType": "final-return",
    "description": "Final Vpb return filing deadline for FY 2025",
    "fiscalYear": 2025,
    "quarter": null,
    "status": "pending",
    "relatedPeriodId": "2025-full"
  }
]
```

### TaxPaymentTracking

Two sample payments (one provisional, one final):

```json
[
  {
    "@self": {
      "register": "shillinq",
      "schema": "TaxPaymentTracking",
      "slug": "2025-provisional-payment-001"
    },
    "paymentDate": "2025-04-20",
    "paymentType": "provisional",
    "amount": 15000.00,
    "currency": "EUR",
    "status": "paid",
    "linkedGLAccount": "1200",
    "description": "Provisional Vpb payment Q1 2025",
    "relatedDeadlineId": "2025-provisional-q1-payment"
  },
  {
    "@self": {
      "register": "shillinq",
      "schema": "TaxPaymentTracking",
      "slug": "2025-final-payment-001"
    },
    "paymentDate": "2026-06-15",
    "paymentType": "final",
    "amount": 18500.00,
    "currency": "EUR",
    "status": "pending",
    "linkedGLAccount": "1200",
    "description": "Final Vpb payment FY 2025",
    "relatedDeadlineId": "2025-final-return-filing"
  }
]
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Tax deadline templates are not available for all municipalities | Operator can create deadlines manually per entity; optional template system can be added in follow-up spec |
| Payment reconciliation requires manual GL account mapping | `TaxPaymentTracking.linkedGLAccount` must be maintained by bookkeeper; reconciliation view flags mismatches |
| Quarterly tax report accuracy depends on transaction tagging | Report aggregation counts untagged postings; configurable warning threshold |
| Provisional payment amounts are estimates and may not match final tax assessment | `TaxPaymentTracking.status` tracks provisional vs. final; adjustment records handle differences |
| Multi-entity consolidation scope is deferred | Supported by the register architecture (can filter `TaxDeadline` across administrations) but UI not included in Phase 1 |

## Future Enhancements

1. **Tax deadline templates** — pre-loaded `TaxDeadlineTemplate` register with standard deadlines per municipality (municipal code → KVK lookup)
2. **Payment plan suggestions** — estimated tax liability from quarterly report; suggest provisional payment schedule
3. **Vpb-aangifte integration** — render Vpb return via docudesk template; auto-populate from aggregated tax report
4. **Multi-entity consolidation** — tax report aggregation across multiple administrations for group filing
5. **Estimated vs. actual variance analysis** — compare provisional payment estimates to final assessment

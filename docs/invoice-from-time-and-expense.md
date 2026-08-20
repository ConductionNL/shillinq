# Invoice generation from time + expense

Shillinq drafts customer invoices from approved time entries and expense
records and supports five billing models per engagement:

| Model | Formula |
|---|---|
| **t_and_m** | `Σ(hours × rate)` + expenses + optional markup |
| **fixed_fee** | flat fee + expenses (hours are audit-only) |
| **milestone** | milestone amount + expenses (hours informational) |
| **retainer** | monthly retainer + overage T&M + expenses |
| **mixed** | retainer + overage + optional setup fee + expenses |

The feature lives behind `Invoices` in the navigation menu and exposes a
REST API under `/apps/shillinq/api/v1/invoices/`.

@spec openspec/changes/invoice-from-time-and-expense/specs/spec.md

## Concepts

| Schema | Purpose |
|---|---|
| `BillableInvoice` | Invoice materialised from time + expense; carries `billingModel`, `timeEntryIds`, `expenseIds`, `rateCardId`, `retainerScheduleId`, `lineItemsByModel`, `summary`. |
| `BillableInvoiceLine` | Per-line `sourceType` (time_entry, expense, retainer_charge, fixed_fee, manual), `rateApplied` snapshot, `billableUnits`, `costAmount`, `vatRate`. |
| `RetainerSchedule` | Monthly recurring fee + optional T&M overage threshold; effective-dated. |
| existing `RateCard` / `RateRecord` | Re-used for rate look-ups; `rateApplied` is **snapshot'd** at generation time (D2). |

## Billing operator workflow

1. **Generate invoice** — Invoices → Generate invoice.
2. Pick `Billing model`, `Customer`, optional `Project`, and a `From`/`To`
   date range covering the time entries + expenses you want to bill.
3. For T&M and Mixed, pick a `Rate card`; for Retainer and Mixed pick a
   `Retainer schedule`; for Fixed-fee enter the flat `Fixed fee (€)`; for
   Milestone enter the `Milestone ID`.
4. Paste comma-separated `Time entry IDs` and `Expense IDs`. The server
   refuses sets that overlap with an existing draft or posted invoice
   (HTTP 409 Conflict, D9).
5. **Save as Draft** to persist. The totals block fills in with `Net`,
   `VAT/BTW`, and `Gross`.
6. **Preview PDF** opens the invoice document in a new tab (Dutch BTW
   format with header, line table, per-rate breakdown, payment terms).
7. **Post to AR** transitions the invoice to `posted`, creates an
   Obligation, and posts the GL entries — Debit `1130` (AR), Credit the
   revenue account (4100 T&M / 4200 fixed/milestone / 4300 retainer /
   4400 mixed), Credit `1150` (VAT Payable).

## Accountant workflow

- **Invoices → All invoices** filters by date range, billing model, and
  status. Status badges: Draft / Posted / Cancelled.
- Open an invoice to inspect line items (`InvoiceLineItemReview`), GL
  posting status, and the audit trail.
- Cancellation is operator-driven; cancelled invoices cannot be posted.

## REST API

All endpoints are authenticated (`#[NoAdminRequired]`) and tenant-scoped
server-side (the controller never accepts a client-supplied
`administrationId`).

### `POST /api/v1/invoices/generate`

Drafts a new `BillableInvoice`.

```json
{
  "billingModel": "t_and_m",
  "customerId": "cust-techcorp",
  "fromDate": "2026-05-01",
  "toDate": "2026-05-31",
  "rateCardId": "rate-card-consulting",
  "timeEntryIds": ["uren-001", "uren-002"],
  "expenseIds": ["exp-001"]
}
```

Returns `200 OK` with the persisted invoice, or:

- `422` — invalid request shape (missing `rateCardId` for `t_and_m`, etc.).
- `409` — source ids overlap an existing draft / posted invoice.
- `400` — other runtime error.

### `GET /api/v1/invoices`

List invoices for the current administration. Filter via
`?billingModel=…&status=…` (date filters TBD in T3).

### `GET /api/v1/invoices/{invoiceId}`

Returns `{ invoice, lines, auditTrail }`. Cross-tenant access returns
`403`.

### `POST /api/v1/invoices/{invoiceId}/post`

Transitions a draft invoice to `posted`, creates an `Obligation` stub and
emits a balanced `GLTransaction`. Returns `200` with the patched invoice,
or `409` when the invoice is already posted.

### `GET /api/v1/invoices/{invoiceId}/pdf`

Returns the invoice as a Dutch-formatted HTML document
(`Content-Type: text/html`). A downstream renderer (`wkhtmltopdf`,
browser print) converts to PDF; the filename is
`invoice-{invoiceNumber}.pdf`.

## Cross-app billing intake (pipelinq handoff)

`POST /apps/shillinq/api/billing/time-intake` is a separate, authenticated
ingress endpoint (unversioned, `time-expense-invoice-intake`) that another
Conduction app — pipelinq, via its `time-billing-handoff-emit` change — POSTs
a batch of externally-approved time entries to. It materialises each entry
as a `UrenRegistratie` row (stamped `externalId`/`sourceApp: "pipelinq"`/
`sourceBatchId`) and delegates to the same `InvoiceGenerationService` above
to draft **one T&M invoice per batch**. It is idempotent — replaying the same
`batchId` returns the existing invoice with `duplicated: true`; a `batchId`
reused with a different payload is `409`; a time entry already billed under
another batch is `422`.

```json
{
  "batchId": "11111111-1111-1111-1111-111111111111",
  "organisationRef": "cust-5432",
  "billingModel": "t_and_m",
  "period": { "start": "2026-06-01", "end": "2026-06-30" },
  "entries": [
    { "externalId": "pl-time-1001", "date": "2026-06-03", "minutes": 120,
      "description": "API design review", "hourlyRate": 150.0 }
  ]
}
```

Returns `{invoiceId, invoiceNumber, status: "draft", lines, duplicated}`.
See `openspec/specs/time-expense-invoice-intake/spec.md` and the archived
change's `contract.md` for the full request/response contract and error
codes (`400`/`401`/`409`/`422`).

## Examples

```bash
# Draft a T&M invoice
curl -X POST -H 'Content-Type: application/json' \
  http://localhost:8080/apps/shillinq/api/v1/invoices/generate \
  -d @tests/fixtures/InvoiceFromTimeAndExpenseFixtures.json

# Inspect it
curl http://localhost:8080/apps/shillinq/api/v1/invoices/BIL-2026-0001

# Post to AR (creates Obligation + GL entries)
curl -X POST http://localhost:8080/apps/shillinq/api/v1/invoices/BIL-2026-0001/post

# Export PDF
curl -o invoice.html \
  http://localhost:8080/apps/shillinq/api/v1/invoices/BIL-2026-0001/pdf
```

## Design notes

- **Money** — every cent value is stored as `multipleOf 0.01` (Euro) and
  reduced to integer cents inside the services. VAT uses bankers'
  rounding.
- **Rate snapshot (D2)** — `rateApplied` carries `rateCents`, `currency`,
  `rateCardVersion`, `effectiveDate`. Historical invoices retain their
  original rate even if the underlying `RateCard` later changes.
- **Retainer charge (D3)** — always a separate line; overage T&M is a
  distinct second line so accountants see the split.
- **Deduplication (D9)** — source-id intersection check is done at draft
  time; the service refuses overlap with any draft or posted invoice in
  the same administration.

## See also

- `openspec/changes/invoice-from-time-and-expense/specs/spec.md` — RFC
  2119 requirements + scenarios.
- `openspec/changes/invoice-from-time-and-expense/design.md` — decisions
  D1–D10.
- `lib/Service/InvoiceGenerationService.php`,
  `lib/Service/BillingModelEngine.php`, and the three resolvers.

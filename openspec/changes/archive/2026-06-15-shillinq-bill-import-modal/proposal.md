# Proposal: shillinq-bill-import-modal

`kind: config` — a focused PDF upload + OCR extraction modal launched directly
from the Financial overview dashboard, backed by the existing
`SupplierInvoice` schema and the supplier-invoice ingestion API from
`bookkeeping-purchase-order-3way-05`.

## Summary

Today the **Import bill** shortcut on the Financial overview dashboard navigates
the user away from the dashboard to the supplier invoices list, where they must
then start a new bill from scratch or import via a separate upload page. This
breaks the user's context (they were reviewing the financial overview).

This change introduces a **BillImportModal** — a `NcDialog`-hosted two-step
wizard launched directly from the dashboard header action button:

1. **Step 1 — Upload**: drag-and-drop or file-picker for a PDF, UBL XML, or
   CSV supplier invoice. Calls the existing `POST /api/v1/supplier-invoices/import`
   endpoint.
2. **Step 2 — Review & confirm**: shows OCR-extracted values (supplier,
   invoice number, date, amount, VAT) alongside an editable form. The user
   can correct misreads before saving.

On save, the modal closes and the Financial overview dashboard refreshes its
open-payables widget without full-page navigation.

**Depends on:** `bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion`
(import endpoint), `shillinq-financial-dashboard-actions` (the Import bill
button that launches the modal, already implemented).

## Motivation

### User journey discovery

When documenting Shillinq's daily operations (see
`docs/guides/import-bills.md`), the user flow for "record a bill" was mapped
end-to-end:

1. User is on the Financial overview, sees the open-payables table growing.
2. User clicks **Import bill** — is taken to a completely different page.
3. User uploads a PDF, corrects OCR, saves.
4. User manually navigates back to the Financial overview.

Steps 2 and 4 are unnecessary context switches. The financial dashboard is the
natural home for the import action because the payables widget immediately
shows the new bill once saved.

### User demand signals

- Internal: bookkeepers in the Conduction pilot report they open the financial
  overview, notice a missing bill, and then lose the dashboard context while
  recording it.
- Competitive: Moneybird and Exact Online both offer an "Add bill" modal on
  their dashboard overview that preserves context.

## Requirements

### REQ-BIM-001 — Upload step

The modal accepts:
- PDF (any DIN A4 supplier invoice)
- UBL 2.1 XML (`application/xml` MIME type; parsed server-side)
- CSV (header row: `supplier,invoiceNumber,invoiceDate,amount,vatAmount`)

Multiple files may be dropped; each becomes a separate bill record processed
sequentially.

### REQ-BIM-002 — OCR extraction

For PDF uploads the server extracts:
- Supplier name (matched to Nextcloud Contacts by fuzzy name + IBAN)
- Invoice number
- Invoice date
- Due date (if present)
- Line totals by VAT rate (21 %, 9 %, 0 %)
- Grand total

Confidence score per field; fields below 0.7 confidence are highlighted for
manual review.

### REQ-BIM-003 — Review step

The review form pre-fills from the OCR result and is fully editable. Required
fields before save: `supplier`, `invoiceNumber`, `invoiceDate`, `glAccount`.

### REQ-BIM-004 — Save and refresh

On save:
1. `POST /api/v1/supplier-invoices` with the confirmed payload.
2. Modal closes.
3. Financial overview emits `cn:widget:refresh` for `widget-open-creditors` so
   the payables widget reloads without a full page navigation.

### REQ-BIM-005 — Duplicate detection

If `(supplier, invoiceNumber)` already exists, the server returns HTTP 409.
The modal shows an inline warning "This invoice number already exists for this
supplier" and stays open.

## Implementation approach

- `BillImportModal.vue` registered as `kind: 'modal'` in the shillinq
  registry.
- `FinancialDashboardActions.vue` uses `showBillImportModal: true` data flag
  (replaces the current `this.$router.push('SupplierInvoices')` call).
- No changes to the existing supplier-invoice list or import pages — the modal
  is additive.
- Server-side OCR uses the existing ingestion pipeline from
  `bookkeeping-purchase-order-3way-05`.

## Out of scope

- Bulk batch import from a folder (future: OpenConnector integration)
- Line-item OCR extraction (future: structured invoice OCR tier)
- Auto-posting without review step (future: trusted supplier whitelist)

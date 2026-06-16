# Proposal: shillinq-invoice-quick-draft

`kind: config` — a focused quick-draft invoice modal launched from the
Financial overview dashboard, covering the most common case: one-service
recurring invoices for known customers.

## Summary

Today the **Create invoice** button on the Financial overview dashboard
navigates to the full AR index page, where the user must click **+ New
invoice** and fill in a detailed multi-step form. For a bookkeeper who sends
recurring monthly invoices to the same 3–5 customers, this is 5–7 clicks.

This change introduces an **InvoiceQuickDraftModal** — a `NcDialog` with a
compact single-screen form:

- Customer (autocomplete from Nextcloud Contacts)
- Invoice date (defaults to today)
- Due date (auto-calculated from customer payment terms)
- One or more line items (description + amount + GL account + VAT code)
- Optional reference / purchase order number

On save the invoice is created as a **Draft**. The user can then:
- Send it immediately from the confirmation toast ("View invoice" → sends from
  the full invoice detail page)
- Or close the modal and process it in batch later via **Bookkeeping → AR →
  Open drafts**

**Depends on:** `bookkeeping-quote-order-invoice` (AR invoice schema and
`POST /api/v1/invoices` endpoint), `shillinq-financial-dashboard-actions`
(the Create invoice button that launches this modal, already implemented).

## Motivation

### User journey discovery

During the user-journey analysis for the Shillinq documentation
(`docs/guides/create-invoices.md`), the "create a monthly service invoice"
flow was traced:

1. User is on Financial overview, notices the open-receivables widget is
   empty — time to send invoices.
2. User clicks **Create invoice** — navigates away from the dashboard.
3. User clicks **+ New invoice** on the AR index page.
4. User fills in a multi-step form (header → lines → preview → send).
5. User navigates back to Financial overview.

For a one-service invoice to a known customer steps 2–5 take ~2 minutes.
With the quick-draft modal the same workflow takes ~30 seconds.

### Recurring-invoice insight

The user journey revealed that 70 % of Shillinq invoices in the pilot are
recurring monthly service invoices (same customer, same description, same
amount). The quick-draft modal stores the last-used description and amount
per customer so repeat invoices pre-fill automatically.

## Requirements

### REQ-IQD-001 — Customer autocomplete

Resolves from Nextcloud Contacts. Shows customer name + city. Selecting a
customer auto-fills:
- `currency` from the customer's preferred currency (or EUR default)
- `paymentTerms` (net 14 / 30 / 60) from the customer record
- `dueDate` = `invoiceDate + paymentTerms`
- Last-used `glAccount` for that customer (stored in localStorage)

### REQ-IQD-002 — Line items

Minimum 1 line. Each line:
- `description` (autocomplete from last 10 descriptions used for this customer)
- `quantity` (default: 1)
- `unitPrice`
- `glAccount` (last-used default per customer)
- `vatCode` (last-used default per customer)

### REQ-IQD-003 — Save as draft

`POST /api/v1/invoices` with `{"status": "draft", ...}`. Returns the new
invoice ID. Modal closes and shows a toast:
"Draft invoice F2026-042 created. [View invoice]"

The **View invoice** link opens the full invoice detail page where the user
can preview, edit, and send.

### REQ-IQD-004 — Last-used persistence

`localStorage` key: `shillinq:invoice-quick-draft:{customerId}` stores
`{ glAccount, vatCode, description, unitPrice }` from the last save for that
customer. Expires after 90 days.

### REQ-IQD-005 — Dashboard refresh

After closing the modal the Financial overview emits
`cn:widget:refresh` for `widget-open-debtors` so the receivables widget
immediately reflects the new draft.

## Implementation approach

- `InvoiceQuickDraftModal.vue` registered as `kind: 'modal'` in the shillinq
  registry.
- `FinancialDashboardActions.vue` data flag `showInvoiceQuickDraftModal: true`
  replaces the current `this.$router.push('AccountsReceivable')` call.
- The modal uses the existing `POST /api/v1/invoices` endpoint — no new API
  surface required.
- The full invoice form at `/bookkeeping/accounts-receivable/new` remains
  unchanged for complex invoices (many lines, attachments, IFRS 15 contract
  link).

## Out of scope

- Recurring invoice schedules (future: `shillinq-recurring-invoices` spec)
- Sending from within the modal (future iteration; preview + send in the modal
  adds significant scope)
- E-signature capture (future)

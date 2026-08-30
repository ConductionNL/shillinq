# Tasks: shillinq-invoice-quick-draft

## 1. Computation + persistence layer

- [x] 1.1 `src/modals/invoiceQuickDraft.js` — pure helpers:
  `defaultDraftLine`, `computeTotals` (net/VAT/gross), `paymentTermDays`,
  `dueDateFromTerms`, `buildInvoicePayload` (always `draft`), and the
  `localStorage` preference round-trip with 90-day TTL.

## 2. Modal

- [x] 2.1 `src/modals/InvoiceQuickDraftModal.vue` — NcDialog with a
  compact single-screen form: customer autocomplete (CustomerMaster),
  invoice/due dates, reference, line items, GL account picker
  (reused `GlAccountPicker`), live totals, save-as-draft.
- [x] 2.2 Save creates an `ARInvoice` via the OpenRegister object API
  (ADR-022). Disabled until a customer and one priced line are present.
- [x] 2.3 On save: emit `cn:widget:refresh` for the receivables widget
  and show a success toast naming the draft.

## 3. Wiring

- [x] 3.1 `FinancialDashboardActions.vue` — the **Create invoice**
  button opens the modal (`showInvoiceQuickDraftModal`) instead of
  navigating to the AR index. Modal hosted here and isolated under
  `src/modals/` (hydra gate-13).
- [x] 3.2 New UI strings added to `l10n/en.json` + `l10n/nl.json`
  (English source keys per i18n policy).

## 4. Tests

- [x] 4.1 Vitest `tests/vitest/invoiceQuickDraft.spec.js` — totals,
  due-date, payload shape, preference round-trip + TTL expiry +
  storage-unavailable safety.
- [x] 4.2 Playwright e2e `tests/e2e/invoice-quick-draft.spec.ts`
  covering the spec scenarios (gate-19 back-reference).

## 5. Verify

- [x] 5.1 `npm run build` clean.
- [x] 5.2 Hydra gates green on the diff.
- [~] 5.3 Live-instance verify at localhost:8080 (manual step; modal +
  endpoint exercised by unit + e2e specs).

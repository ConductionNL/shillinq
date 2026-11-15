---
kind: code
depends_on: []
---

# Proposal: sales-quick-draft-from-any-page

## Summary

A user can start a quick invoice draft from every page of shillinq, not only
from the Financial overview. The same quick-draft modal opens over the page the
user is on, and saving leaves them on that page.

## Motivation

Matrix row **`sal-quick-draft`**, "Draft an invoice in a few fields from
anywhere in the app." (`openspec/parity/capabilities.json`), rated partial.
The row's evidence: `src/modals/InvoiceQuickDraftModal.vue` saves an
`ARInvoice` draft, but its only trigger is the Dashboard header action
`create-invoice` in `src/manifest.json`. A bookkeeper on the purchase
invoices page, or on a customer's page, has to go back to the dashboard first.
The main spec `shillinq-invoice-quick-draft` covers the launch from the
Financial overview only.

## Affected Projects

- [ ] Project: `shillinq`: one app-wide Create invoice action that opens the
  existing quick-draft modal.

## Scope

### In Scope

- A Create invoice action that is reachable from every shillinq page.
- The modal opens over the current page and returns the user to it after save
  or cancel.
- On a customer's detail page the modal starts with that customer selected.

### Out of Scope

- Changes to the modal's fields, defaults or saving. Those stay as
  `shillinq-invoice-quick-draft` specifies them.
- Quick drafts for purchase invoices.

## Approach

Declare one app-level action in the manifest that hosts
`InvoiceQuickDraftModal`, next to the existing dashboard header action, and
pass the customer of the current detail page as the starting customer when
there is one.

## Impact

- Manifest: one app-level action; the dashboard action keeps working.
- Code: the modal accepts an optional starting customer.

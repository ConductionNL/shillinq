# Tasks: sales-quick-draft-from-any-page

## 1. Action

- [ ] 1.1 Declare an app-level Create invoice action in `src/manifest.json` that opens `InvoiceQuickDraftModal` over the current page (REQ-IQD-009). Verify: a Vitest test that mounts the action on a non-dashboard page and asserts the modal opens and the route is unchanged.
- [ ] 1.2 Pass the customer of a `CustomerDetail` page to the modal as its starting customer (REQ-IQD-009). Verify: a Vitest test that opens the action on a customer page and reads the selected customer.

## 2. End to end

- [ ] 2.1 Open the action on the purchase invoices page and on a customer page, save a draft and confirm the page did not change (REQ-IQD-009). Verify: Playwright test tagged with `@e2e` references to the scenarios.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `npm run lint` and `npm run format`.

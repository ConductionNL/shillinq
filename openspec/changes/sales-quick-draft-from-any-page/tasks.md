# Tasks: sales-quick-draft-from-any-page

## 1. Action

- [ ] 1.1 Declare an app-level Create invoice action in `src/manifest.json` that opens `InvoiceQuickDraftModal` over the current page (REQ-IQD-009). Verify: a Vitest test that mounts the action on a non-dashboard page and asserts the modal opens and the route is unchanged.
  - Not built: the manifest has no app-level slot that can open a modal. `nav.primaryAction` is the one app-wide action and already holds New Booking. A menu item's `action` is a closed enum (user-settings, admin-settings, replay-walkthrough). An `action` on the primary action dispatches through CnActionButtons without `cnDispatchAction`, which CnPageRenderer provides inside the router view, so `open-modal` cannot resolve the registry from CnAppNav (nextcloud-vue 2.181.0). This needs a nextcloud-vue change (an app-level action list in `nav`, or `open-modal` dispatch in CnAppNav), or a decision to add the header action to each of the 226 pages.
  - Dependency built (24 Nov 2026): ConductionNL/nextcloud-vue#2312 lets `nav.primaryAction.action` of type `open-modal` open a registered modal from the navigation (CnActionButtons outside a page now passes the registry and CnAppRoot's `cnOpenModal`). Left: once a release carries it, raise the dependency, declare the Create invoice action there and add the Vitest test.
- [x] 1.2 Pass the customer of a `CustomerDetail` page to the modal as its starting customer (REQ-IQD-009). Verify: a Vitest test that opens the action on a customer page and reads the selected customer.
  - `InvoiceQuickDraftModal` takes `from` and `objectId`. With `from: customer` it selects the customer named by `objectId`, else by the route's id (as AgreePaymentPlanModal does), once the list has loaded, through `onCustomerSelected`, so the GL account, preferences and due date follow. `startingCustomerOption()` in `invoiceQuickDraft.js` matches by object id, `@self` id or customer number. `CustomerDetail` has a Create invoice header action. Test: `tests/vitest/invoiceQuickDraftStartingCustomer.spec.js` (8).

## 2. End to end

- [ ] 2.1 Open the action on the purchase invoices page and on a customer page, save a draft and confirm the page did not change (REQ-IQD-009). Verify: Playwright test tagged with `@e2e` references to the scenarios.
  - Written: `tests/e2e/sales-quick-draft-from-any-page.spec.ts`, the three scenarios tagged. The customer page scenario runs today; the purchase invoices and bank reconciliation scenarios skip while those pages offer no Create invoice action (task 1.1). Not run here, no live instance; tick after a live run once 1.1 is built.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `npm run lint` and `npm run format`.
